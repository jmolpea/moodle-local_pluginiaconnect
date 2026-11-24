<?php
// This file is part of Moodle - http://moodle.org/
//
// Moodle is free software: you can redistribute it and/or modify
// it under the terms of the GNU General Public License as published by
// the Free Software Foundation, either version 3 of the License, or
// (at your option) any later version.
//
// Moodle is distributed in the hope that it will be useful,
// but WITHOUT ANY WARRANTY; without even the implied warranty of
// MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
// GNU General Public License for more details.
//
// You should have received a copy of the GNU General Public License
// along with Moodle.  If not, see <http://www.gnu.org/licenses/>.

namespace local_pluginiaconnect;

use local_pluginiaconnect\local\audit\logger;
use local_pluginiaconnect\local\guardrail\action_store;
use local_pluginiaconnect\local\guardrail\approval_service;
use local_pluginiaconnect\local\guardrail\confirmation_service;
use local_pluginiaconnect\local\guardrail\idempotency_store;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_test_tools.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\action_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\confirmation_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\approval_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\idempotency_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
/**
 * Tests of the write guardrails: two-step confirmation, approval on the site, idempotency
 * keys, the cap of pending actions and the floor imposed on unattended assistants.
 *
 * They use a test-only write tool that counts how many times it is applied.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\guardrail\action_store
 * @covers     \local_pluginiaconnect\local\guardrail\confirmation_service
 * @covers     \local_pluginiaconnect\local\guardrail\approval_service
 * @covers     \local_pluginiaconnect\local\guardrail\idempotency_store
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 */
final class write_guardrails_test extends \advanced_testcase {
    /** @var \stdClass Course the probe acts on. */
    private \stdClass $course;
    /** @var \stdClass Editing teacher of the course. */
    private \stdClass $teacher;
    /** @var grant_context Connection of the teacher with the write scope. */
    private grant_context $grant;

    /**
     * A site with the connector on, the write probe enabled and a teacher connected with "read and changes".
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        tool_registry::set_test_tools(['write_probe' => \local_pluginiaconnect_write_probe::class]);
        set_config('tool_write_probe_teacher', 1, 'local_pluginiaconnect');
        \local_pluginiaconnect_teacher_probe::$executions = 0;
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $this->grant = $this->connect($this->teacher);
    }

    /**
     * Removes the test tools and any simulated failure.
     */
    protected function tearDown(): void {
        tool_registry::set_test_tools([]);
        logger::simulate_failure(false);
        parent::tearDown();
    }

    /**
     * Creates a connection with the write scope.
     *
     * @param \stdClass $user User.
     * @param array $options Grant options (actorid, clienttype...).
     * @return grant_context
     */
    private function connect(\stdClass $user, array $options = []): grant_context {
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, 'teacher', ['profile', 'content.write'], $options);
        return $grant;
    }

    /**
     * Calls the write probe.
     *
     * @param array $extra Arguments besides the course.
     * @param grant_context|null $grant Connection; the teacher's by default.
     * @return \local_pluginiaconnect\local\mcp\pipeline_result
     */
    private function call(array $extra = [], ?grant_context $grant = null) {
        $grant = $grant ?? $this->grant;
        $user = user_session::load($grant);
        $args = ['course_id' => (int) $this->course->id] + $extra;
        return call_pipeline::run($grant, $user, 'write_probe', $args, \core\uuid::generate(), '192.0.2.7');
    }

    /**
     * Number of times the probe was applied.
     *
     * @return int
     */
    private function applied(): int {
        return \local_pluginiaconnect_teacher_probe::$executions;
    }

    /**
     * Last audit row.
     *
     * @return \stdClass
     */
    private function last_audit(): \stdClass {
        global $DB;
        $rows = $DB->get_records('local_pluginiaconnect_audit', null, 'id DESC', '*', 0, 1);
        return reset($rows);
    }

    /**
     * The first call changes nothing: it returns a server-built summary and a token whose hash only is stored.
     */
    public function test_first_call_changes_nothing(): void {
        global $DB;
        $sink = $this->redirectEvents();
        $result = $this->call(['value' => 'a']);
        $events = array_filter($sink->get_events(), static fn($e) => $e instanceof event\action_confirmation_requested);
        $sink->close();

        $this->assertFalse($result->iserror);
        $this->assertSame('confirmation_required', $result->envelope['status']);
        $this->assertSame(0, $this->applied());
        $data = $result->envelope['data'];
        $this->assertSame('Probe change in course ' . $this->course->id . ' with value a.', $data['summary']);
        $this->assertSame(300, $data['expires_in']);
        $this->assertMatchesRegularExpression('/^pc_ct_[A-Za-z0-9_-]{43}$/', $data['confirm_token']);

        $action = $DB->get_record(action_store::TABLE, [], '*', MUST_EXIST);
        $this->assertSame('pending', $action->status);
        $this->assertSame('confirm', $action->mode);
        $this->assertSame(hash('sha256', $data['confirm_token']), $action->tokenhash);
        $this->assertStringNotContainsString($data['confirm_token'], json_encode($action));
        $this->assertEqualsWithDelta(time() + 300, $action->timeexpires, 3);
        $this->assertSame(['course_id' => (int) $this->course->id, 'value' => 'a'], json_decode($action->args, true));

        $audit = $this->last_audit();
        $this->assertSame('confirmation_required', $audit->outcome);
        $this->assertEquals(1, $audit->iswrite);
        $this->assertSame('action', $audit->objecttype);
        $this->assertCount(1, $events);
        $this->assertSame(['tool' => 'write_probe', 'mode' => 'confirm'], reset($events)->other);
    }

    /**
     * The token applies the change once; sending it again returns the stored result without applying anything.
     */
    public function test_token_applies_once_and_replays(): void {
        global $DB;
        $token = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];
        $second = $this->call(['value' => 'a', 'confirm_token' => $token]);
        $this->assertFalse($second->iserror);
        $this->assertSame('ok', $second->envelope['status']);
        $this->assertSame('applied-a', $second->envelope['data']['visible']);
        $this->assertSame(1, $this->applied());
        $this->assertNull($this->last_audit()->reason);

        $action = $DB->get_record(action_store::TABLE, [], '*', MUST_EXIST);
        $this->assertSame('executed', $action->status);
        $this->assertNull($action->args, 'Arguments are cleared when the action is resolved');
        $this->assertNotNull($action->timeresolved);

        $third = $this->call(['value' => 'a', 'confirm_token' => $token]);
        $this->assertSame($second->envelope, $third->envelope);
        $this->assertSame(1, $this->applied(), 'A replay does not apply the change again');
        $this->assertSame('replayed', $this->last_audit()->reason);

        // Once the stored result is gone, the token is spent and says so.
        $DB->set_field(action_store::TABLE, 'result', null, ['id' => $action->id]);
        $this->assertSame('conflict', $this->call(['value' => 'a', 'confirm_token' => $token])->error_code());
        $this->assertSame(1, $this->applied());
    }

    /**
     * A token is worthless for other arguments, another connection, another tool, or after it expires.
     */
    public function test_token_is_bound_and_expires(): void {
        global $DB;
        $token = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];

        $this->assertSame('validation_error', $this->call(['value' => 'b', 'confirm_token' => $token])->error_code());
        $this->assertSame('validation_error', $this->call(['confirm_token' => $token])->error_code());
        $other = $this->connect($this->teacher);
        $this->assertSame('validation_error', $this->call(['value' => 'a', 'confirm_token' => $token], $other)->error_code());
        $this->assertSame('validation_error', $this->call(['value' => 'a', 'confirm_token' => 'pc_ct_made-up'])->error_code());
        $this->assertSame('validation_error', $this->call(['value' => 'a', 'confirm_token' => 'wrong-prefix'])->error_code());
        $this->assertSame(0, $this->applied());

        $DB->set_field(action_store::TABLE, 'timeexpires', time() - 1, ['tokenhash' => hash('sha256', $token)]);
        $this->assertSame('validation_error', $this->call(['value' => 'a', 'confirm_token' => $token])->error_code());
        $this->assertSame(0, $this->applied());

        // A direct look at the service: the tool name is part of the binding too.
        $fresh = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];
        $hash = logger::args_hash(['course_id' => (int) $this->course->id, 'value' => 'a']);
        try {
            confirmation_service::redeem($fresh, $this->grant, 'another_tool', $hash);
            $this->fail('A token must not work for another tool.');
        } catch (\local_pluginiaconnect\local\tool\tool_exception $e) {
            $this->assertSame('validation_error', $e->error_code());
        }
    }

    /**
     * If another request applied the change while this one waited for the lock, this one returns the
     * stored result and applies nothing: the state is read again under the lock.
     */
    public function test_state_is_read_again_under_the_lock(): void {
        $token = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];
        $hash = logger::args_hash(['course_id' => (int) $this->course->id, 'value' => 'a']);

        $first = confirmation_service::redeem($token, $this->grant, 'write_probe', $hash);
        $this->assertNull($first['stored']);
        // The first request applies the change and records it before releasing the lock.
        action_store::resolve((int) $first['action']->id, 'executed', ['status' => 'ok', 'data' => ['visible' => 'first']]);
        $first['lock']->release();

        $late = $this->call(['value' => 'a', 'confirm_token' => $token]);
        $this->assertSame('first', $late->envelope['data']['visible']);
        $this->assertSame(0, $this->applied(), 'The late request must not apply the change');
    }

    /**
     * A change that fails when applied leaves nothing behind and its action stays pending.
     */
    public function test_failed_execution_is_not_recorded_as_applied(): void {
        global $DB;
        // The pipeline rolls its own transaction back, so this test cannot run inside the usual test transaction.
        $this->preventResetByRollback();
        $token = $this->call(['value' => 'fail'])->envelope['data']['confirm_token'];
        $result = $this->call(['value' => 'fail', 'confirm_token' => $token]);
        $this->assertSame('validation_error', $result->error_code());
        $this->assertSame('pending', $DB->get_field(action_store::TABLE, 'status', []));
        $this->assertSame('denied', $this->last_audit()->outcome);
    }

    /**
     * A write whose audit row cannot be written is rolled back and is not recorded as applied.
     */
    public function test_audit_failure_rolls_the_write_back(): void {
        global $DB;
        $this->preventResetByRollback();
        $token = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];
        logger::simulate_failure(true);
        $result = $this->call(['value' => 'a', 'confirm_token' => $token]);
        logger::simulate_failure(false);
        $this->assertTrue($result->iserror);
        $this->assertSame('internal_error', $result->error_code());
        $this->assertSame(
            'pending',
            $DB->get_field(action_store::TABLE, 'status', []),
            'The action was rolled back with the change'
        );
        $this->assertSame(0, $DB->count_records(idempotency_store::TABLE));
    }

    /**
     * Idempotency keys: same key and arguments return the stored result, other arguments are a conflict.
     */
    public function test_idempotency_keys(): void {
        global $DB;
        $key = 'key-12345678';
        $token = $this->call(['value' => 'a', 'idempotency_key' => $key])->envelope['data']['confirm_token'];
        $applied = $this->call(['value' => 'a', 'idempotency_key' => $key, 'confirm_token' => $token]);
        $this->assertSame('ok', $applied->envelope['status']);
        $this->assertSame(1, $this->applied());

        $row = $DB->get_record(idempotency_store::TABLE, [], '*', MUST_EXIST);
        $this->assertSame(hash('sha256', $key), $row->keyhash);
        $this->assertStringNotContainsString($key, json_encode($row));
        $this->assertEqualsWithDelta(time() + DAYSECS, $row->timeexpires, 3);

        // A retry without the token gets the same answer and applies nothing.
        $retry = $this->call(['value' => 'a', 'idempotency_key' => $key]);
        $this->assertSame($applied->envelope, $retry->envelope);
        $this->assertSame(1, $this->applied());
        $this->assertSame('replayed', $this->last_audit()->reason);

        $this->assertSame('conflict', $this->call(['value' => 'b', 'idempotency_key' => $key])->error_code());
        $this->assertSame('validation_error', $this->call(['value' => 'a', 'idempotency_key' => 'short'])->error_code());

        // Another connection may use the same key for its own change.
        $other = $this->connect($this->teacher);
        $this->assertSame(
            'confirmation_required',
            $this->call(['value' => 'a', 'idempotency_key' => $key], $other)->envelope['status']
        );

        // After 24 hours the key is forgotten.
        $DB->set_field(idempotency_store::TABLE, 'timeexpires', time() - 1, ['id' => $row->id]);
        $this->assertSame('confirmation_required', $this->call(['value' => 'a', 'idempotency_key' => $key])->envelope['status']);
        $this->assertSame(1, $this->applied());
    }

    /**
     * A connection holds at most five pending changes, and stale ones are tidied on access.
     */
    public function test_pending_cap_and_tidy(): void {
        global $DB;
        for ($i = 1; $i <= action_store::MAX_PENDING; $i++) {
            $this->assertSame('confirmation_required', $this->call(['value' => "v{$i}"])->envelope['status']);
        }
        $this->assertSame('limit_exceeded', $this->call(['value' => 'one-too-many'])->error_code());
        $this->assertSame(action_store::MAX_PENDING, action_store::pending_count($this->grant->grantid));
        // Another connection has its own allowance.
        $this->assertFalse($this->call(['value' => 'x'], $this->connect($this->teacher))->iserror);

        // Expired actions stop counting and lose their arguments as soon as the connection is used again.
        $DB->set_field(action_store::TABLE, 'timeexpires', time() - 1, ['grantid' => $this->grant->grantid]);
        $this->assertSame('confirmation_required', $this->call(['value' => 'again'])->envelope['status']);
        $expired = $DB->get_records(action_store::TABLE, ['grantid' => $this->grant->grantid, 'status' => 'expired']);
        $this->assertCount(action_store::MAX_PENDING, $expired);
        foreach ($expired as $row) {
            $this->assertNull($row->args);
            $this->assertNull($row->tokenhash);
        }

        // Stored results are dropped after 24 hours.
        $id = (int) reset($expired)->id;
        action_store::resolve($id, 'executed', ['status' => 'ok']);
        $DB->set_field(action_store::TABLE, 'timeresolved', time() - DAYSECS - 5, ['id' => $id]);
        action_store::tidy();
        $this->assertNull($DB->get_field(action_store::TABLE, 'result', ['id' => $id]));
    }

    /**
     * Approval mode: the call creates a pending action and a link, tells the user, and never applies anything.
     */
    public function test_approval_request(): void {
        global $DB;
        set_config('approval_write_probe', 'approval', 'local_pluginiaconnect');
        $this->preventResetByRollback();
        $messages = $this->redirectMessages();
        $events = $this->redirectEvents();
        $result = $this->call(['value' => 'a']);
        $sent = $messages->get_messages();
        $messages->close();
        $requested = array_filter($events->get_events(), static fn($e) => $e instanceof event\action_confirmation_requested);
        $events->close();

        $this->assertFalse($result->iserror);
        $this->assertSame('approval_pending', $result->envelope['status']);
        $data = $result->envelope['data'];
        $action = $DB->get_record(action_store::TABLE, ['id' => $data['action_id']], '*', MUST_EXIST);
        $this->assertSame('approval', $action->mode);
        $this->assertNull($action->tokenhash);
        $this->assertSame(900, $data['expires_in']);
        $this->assertStringContainsString('/local/pluginiaconnect/approve.php?id=' . $action->id, $data['approval_url']);
        $this->assertArrayNotHasKey('confirm_token', $data);
        $this->assertSame('approval_pending', $this->last_audit()->outcome);
        $this->assertSame(0, $this->applied());
        $this->assertCount(1, $requested);
        $this->assertSame('approval', reset($requested)->other['mode']);

        // The user is told, without the summary (it may name other people).
        $this->assertCount(1, $sent);
        $message = reset($sent);
        $this->assertEquals($this->teacher->id, $message->useridto);
        $this->assertSame('actionpending', $message->eventtype);
        $this->assertStringContainsString('approve.php?id=' . $action->id, $message->fullmessage);
        $this->assertStringNotContainsString('Probe change', $message->fullmessage);

        // A confirmation token is of no use in approval mode: every call asks for approval again.
        $again = $this->call(['value' => 'a', 'confirm_token' => 'pc_ct_whatever']);
        $this->assertSame('approval_pending', $again->envelope['status']);
        $this->assertSame(0, $this->applied());
        $this->assertCount(2, approval_service::pending_for_user((int) $this->teacher->id));
    }

    /**
     * Asks for a change in approval mode and returns the pending action.
     *
     * @param array $extra Arguments besides the course.
     * @param grant_context|null $grant Connection.
     * @return \stdClass
     */
    private function request_approval(array $extra = ['value' => 'a'], ?grant_context $grant = null): \stdClass {
        global $DB;
        set_config('approval_write_probe', 'approval', 'local_pluginiaconnect');
        $this->preventResetByRollback();
        $messages = $this->redirectMessages();
        $result = $this->call($extra, $grant);
        $messages->close();
        $this->assertSame('approval_pending', $result->envelope['status']);
        return $DB->get_record(action_store::TABLE, ['id' => $result->envelope['data']['action_id']], '*', MUST_EXIST);
    }

    /**
     * Approves an action as a user, the way approve.php does.
     *
     * @param \stdClass $action Action.
     * @param \stdClass $user Session user.
     * @param string|null $summaryhash Hash of the summary shown; the current one by default.
     * @return array [state, message]
     */
    private function approve(\stdClass $action, \stdClass $user, ?string $summaryhash = null): array {
        $this->setUser($user);
        if ($summaryhash === null) {
            try {
                $summaryhash = hash('sha256', approval_service::current_summary($action, $user));
            } catch (\Throwable $e) {
                $summaryhash = hash('sha256', (string) $action->summary);
            }
        }
        return approval_service::approve((int) $action->id, $user, $summaryhash, \core\uuid::generate(), '192.0.2.8');
    }

    /**
     * The owner approves: the change is applied exactly once, as that user, and recorded.
     */
    public function test_approval_applies_exactly_once(): void {
        global $DB;
        $action = $this->request_approval();
        $events = $this->redirectEvents();
        [$state] = $this->approve($action, $this->teacher);
        $approved = array_filter($events->get_events(), static fn($e) => $e instanceof event\action_approved);
        $events->close();

        $this->assertSame('executed', $state);
        $this->assertSame(1, $this->applied());
        $this->assertCount(1, $approved);
        $row = $DB->get_record(action_store::TABLE, ['id' => $action->id], '*', MUST_EXIST);
        $this->assertSame('executed', $row->status);
        $this->assertNull($row->args);
        $this->assertSame('applied-a', json_decode($row->result, true)['data']['visible']);
        $audit = $this->last_audit();
        $this->assertSame('ok', $audit->outcome);
        $this->assertSame('192.0.2.8', $audit->ip);

        // A second approval finds it already applied.
        [$state] = $this->approve($action, $this->teacher);
        $this->assertSame('executed', $state);
        $this->assertSame(1, $this->applied());
    }

    /**
     * Nobody else can approve: another user, a guest, or somebody signed in as the owner.
     */
    public function test_only_the_owner_approves_with_their_own_session(): void {
        global $DB;
        $action = $this->request_approval();
        $hash = hash('sha256', (string) $action->summary);

        $colleague = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        [$state] = $this->approve($action, $colleague, $hash);
        $this->assertSame('missing', $state);
        $this->assertNull(approval_service::load((int) $action->id, (int) $colleague->id));
        $this->setUser($colleague);
        $this->assertSame('missing', approval_service::reject((int) $action->id, $colleague));

        // The session user and the user passed in must be the same person.
        $this->setUser($colleague);
        [$state] = approval_service::approve((int) $action->id, $this->teacher, $hash, \core\uuid::generate(), null);
        $this->assertSame('missing', $state);

        // An administrator signed in as the owner cannot approve in their name.
        $this->setAdminUser();
        \core\session\manager::loginas($this->teacher->id, \context_system::instance());
        $this->assertTrue(\core\session\manager::is_loggedinas());
        global $USER;
        [$state] = approval_service::approve((int) $action->id, $USER, $hash, \core\uuid::generate(), null);
        $this->assertSame('missing', $state);

        $this->assertSame(0, $this->applied());
        $this->assertSame('pending', $DB->get_field(action_store::TABLE, 'status', ['id' => $action->id]));
    }

    /**
     * An approved action is authorised again from scratch: anything lost since the request stops it.
     */
    public function test_approval_is_authorised_again(): void {
        global $DB;
        // The administrator switched the tool off.
        $action = $this->request_approval(['value' => 'one']);
        set_config('tool_write_probe_teacher', 0, 'local_pluginiaconnect');
        [$state, $message] = $this->approve($action, $this->teacher);
        $this->assertSame('failed', $state);
        $this->assertNotSame('', $message);
        $row = $DB->get_record(action_store::TABLE, ['id' => $action->id]);
        $this->assertSame('failed', $row->status);
        $this->assertSame('tool_disabled', json_decode($row->result, true)['error']['code']);
        set_config('tool_write_probe_teacher', 1, 'local_pluginiaconnect');

        // The site became read-only.
        $action = $this->request_approval(['value' => 'two']);
        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assertSame('failed', $this->approve($action, $this->teacher)[0]);
        set_config('readonlymode', 0, 'local_pluginiaconnect');

        // The user lost the role that let them make changes.
        $action = $this->request_approval(['value' => 'three']);
        $context = \context_course::instance($this->course->id);
        $roleid = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        role_unassign($roleid, $this->teacher->id, $context->id);
        $this->getDataGenerator()->enrol_user($this->teacher->id, $this->course->id, 'student');
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
        $this->assertSame('failed', $this->approve($action, $this->teacher)[0]);

        // The connection was revoked.
        $other = $this->getDataGenerator()->create_and_enrol($this->course, 'editingteacher');
        $grant = $this->connect($other);
        $action = $this->request_approval(['value' => 'four'], $grant);
        $DB->set_field('local_pluginiaconnect_grant', 'status', 0, ['id' => $grant->grantid]);
        $this->assertSame('failed', $this->approve($action, $other)[0]);

        $this->assertSame(0, $this->applied());
    }

    /**
     * What is approved is what was shown; expired and rejected actions never run.
     */
    public function test_changed_summary_expiry_and_rejection(): void {
        global $DB;
        $action = $this->request_approval();
        [$state] = $this->approve($action, $this->teacher, hash('sha256', 'a summary the user never saw'));
        $this->assertSame('changed', $state);
        $this->assertSame('pending', $DB->get_field(action_store::TABLE, 'status', ['id' => $action->id]));

        $events = $this->redirectEvents();
        $this->assertSame('rejected', approval_service::reject((int) $action->id, $this->teacher));
        $rejected = array_filter($events->get_events(), static fn($e) => $e instanceof event\action_rejected);
        $events->close();
        $this->assertCount(1, $rejected);
        $this->assertSame('rejected', $this->approve($action, $this->teacher)[0]);
        $this->assertSame('rejected', approval_service::reject((int) $action->id, $this->teacher));
        $this->assertNull($DB->get_field(action_store::TABLE, 'args', ['id' => $action->id]));

        $action = $this->request_approval(['value' => 'late']);
        $DB->set_field(action_store::TABLE, 'timeexpires', time() - 1, ['id' => $action->id]);
        $this->assertSame('expired', approval_service::state($DB->get_record(action_store::TABLE, ['id' => $action->id])));
        $this->assertSame('expired', $this->approve($action, $this->teacher)[0]);
        $this->assertSame('expired', approval_service::reject((int) $action->id, $this->teacher));
        $this->assertSame(0, $this->applied());
        $this->assertSame([], approval_service::pending_for_user((int) $this->teacher->id));
    }

    /**
     * An unattended assistant always needs approval on the site, whatever the tool and the token say.
     */
    public function test_unattended_assistant_floor(): void {
        $this->preventResetByRollback();
        $messages = $this->redirectMessages();
        $cowork = $this->connect($this->teacher, ['actorid' => site_policy::ACTOR_COWORK, 'actor' => 'Copilot Cowork',
            'clienttype' => 'gateway']);
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $this->assertSame('approval', site_policy::approval_mode('write_probe', site_policy::ACTOR_COWORK));

        $first = $this->call(['value' => 'a'], $cowork);
        $this->assertSame('approval_pending', $first->envelope['status']);
        $this->assertArrayNotHasKey('confirm_token', $first->envelope['data']);

        // A token obtained through another connection of the same user does not help either.
        $token = $this->call(['value' => 'a'])->envelope['data']['confirm_token'];
        $second = $this->call(['value' => 'a', 'confirm_token' => $token], $cowork);
        $this->assertSame('approval_pending', $second->envelope['status']);
        $this->assertSame(0, $this->applied());

        // The site can leave the assistant read-only or block it.
        set_config('actorpolicy_copilotcowork', 'readonly', 'local_pluginiaconnect');
        $this->assertSame('tool_disabled', $this->call(['value' => 'a'], $cowork)->error_code());
        set_config('actorpolicy_copilotcowork', 'blocked', 'local_pluginiaconnect');
        $this->assertSame('tool_disabled', $this->call(['value' => 'a'], $cowork)->error_code());
        // Other connections are not affected.
        $this->assertSame('confirmation_required', $this->call(['value' => 'z'])->envelope['status']);
        $messages->close();
        $this->assertSame(0, $this->applied());
    }

    /**
     * Site switches for writes: read-only mode, a tool switched off, and a connection without the write scope.
     */
    public function test_site_switches(): void {
        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assertSame('tool_disabled', $this->call(['value' => 'a'])->error_code());
        $this->assertArrayNotHasKey('write_probe', site_policy::usable_tools('teacher', ['content.write']));
        set_config('readonlymode', 0, 'local_pluginiaconnect');
        $this->assertArrayHasKey('write_probe', site_policy::usable_tools('teacher', ['content.write']));

        // A write tool nobody enabled is off: that is how new tools arrive on existing sites.
        unset_config('tool_write_probe_teacher', 'local_pluginiaconnect');
        $this->assertFalse(site_policy::is_tool_enabled('write_probe', 'teacher'));
        $this->assertSame('tool_disabled', $this->call(['value' => 'a'])->error_code());
        set_config('tool_write_probe_teacher', 1, 'local_pluginiaconnect');

        [, $readonly] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['profile', 'courses.read']);
        $result = $this->call(['value' => 'a'], $readonly);
        $this->assertSame('scope_missing', $result->error_code());
        $this->assertSame('content.write', $result->missingscope);
        $this->assertSame(0, $this->applied());
    }

    /**
     * The write capability is checked where the change is made, at request time and again at approval time.
     */
    public function test_write_capability_in_the_target_context(): void {
        $generator = $this->getDataGenerator();
        $elsewhere = $generator->create_course();
        $generator->enrol_user($this->teacher->id, $elsewhere->id, 'teacher');
        $user = user_session::load($this->grant);
        $result = call_pipeline::run(
            $this->grant,
            $user,
            'write_probe',
            ['course_id' => (int) $elsewhere->id],
            \core\uuid::generate(),
            null
        );
        $this->assertSame('permission_denied', $result->error_code());
        $this->assertSame(0, $this->applied());
    }
}
