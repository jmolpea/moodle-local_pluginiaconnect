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

use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\policy\decision;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_test_tools.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\decision::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\decision_result::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\site_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\audit\logger::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\user_session::class)]
/**
 * The call pipeline, the decision function and the site policy.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 * @covers     \local_pluginiaconnect\local\policy\decision
 * @covers     \local_pluginiaconnect\local\policy\decision_result
 * @covers     \local_pluginiaconnect\local\policy\site_policy
 * @covers     \local_pluginiaconnect\local\audit\logger
 * @covers     \local_pluginiaconnect\local\user_session
 */
final class mcp_pipeline_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Student of the course. */
    private \stdClass $student;
    /** @var \stdClass Editing teacher of the course. */
    private \stdClass $teacher;

    /**
     * One course with a student and a teacher, and the probe tools registered.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        tool_registry::set_test_tools([
            'teacher_probe' => \local_pluginiaconnect_teacher_probe::class,
            'write_probe' => \local_pluginiaconnect_write_probe::class,
            'boom_probe' => \local_pluginiaconnect_boom_probe::class,
            'denied_probe' => \local_pluginiaconnect_denied_probe::class,
        ]);
        \local_pluginiaconnect_teacher_probe::$executions = 0;
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Removes the probe tools.
     */
    protected function tearDown(): void {
        tool_registry::set_test_tools([]);
        parent::tearDown();
    }

    /**
     * Runs a call the way the server does and returns the result plus its audit row.
     *
     * @param \stdClass $user User.
     * @param string $profile Profile of the token.
     * @param string[] $scopes Scopes of the token.
     * @param string $tool Tool name.
     * @param mixed $args Arguments.
     * @param array $options Grant options.
     * @return array [pipeline_result, audit row, grant_context]
     */
    private function call(\stdClass $user, string $profile, array $scopes, string $tool, $args = [], array $options = []): array {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, $scopes, $options);
        $loaded = user_session::load($grant);
        $requestid = \core\uuid::generate();
        $result = call_pipeline::run($grant, $loaded, $tool, $args, $requestid, '192.0.2.7');
        $rows = $DB->get_records('local_pluginiaconnect_audit', ['requestid' => $requestid]);
        $this->assertCount(1, $rows, 'Exactly one audit row per call');
        return [$result, reset($rows), $grant];
    }

    /**
     * Calls the teacher probe as the teacher of the course with a teacher token.
     *
     * @param mixed $args Arguments.
     * @param array $options Grant options.
     * @return array [pipeline_result, audit row, grant_context]
     */
    private function probe($args, array $options = []): array {
        return $this->call($this->teacher, 'teacher', ['participants.read'], 'teacher_probe', $args, $options);
    }

    /**
     * Arguments that name the course of the test.
     *
     * @return array
     */
    private function here(): array {
        return ['course_id' => (int) $this->course->id];
    }

    /**
     * Asserts a denial and its audit row.
     *
     * @param array $outcome Return value of call().
     * @param string $code Expected error code.
     * @param string $auditoutcome Expected audit outcome.
     */
    private function assert_denied(array $outcome, string $code, string $auditoutcome = 'denied'): void {
        [$result, $audit] = $outcome;
        $this->assertTrue($result->iserror);
        $this->assertSame($code, $result->error_code());
        $this->assertSame('error', $result->envelope['status']);
        $this->assertNotSame('', $result->envelope['error']['message']);
        $this->assertSame($auditoutcome, $audit->outcome);
        $this->assertSame($code, $audit->reason);
        $this->assertSame(0, \local_pluginiaconnect_teacher_probe::$executions, 'A denied call must not execute the tool');
    }

    public function test_successful_call_is_filtered_and_audited(): void {
        global $PAGE;
        $page = $PAGE;
        $args = $this->here();
        [$result, $audit, $grant] = $this->probe($args);

        $this->assertFalse($result->iserror);
        $this->assertSame('ok', $result->envelope['status']);
        $this->assertSame(['visible' => 'yes', 'people' => [['name' => 'Ana']]], $result->envelope['data']);
        $this->assertStringNotContainsString('LEAK', json_encode($result->envelope));
        $meta = $result->envelope['meta'];
        $this->assertSame('teacher', $meta['profile']);
        $this->assertFalse($meta['truncated']);
        $this->assertNull($meta['next_cursor']);
        $this->assertSame($audit->requestid, $meta['request_id']);
        $this->assertNotSame('', $meta['site']);

        $this->assertSame('ok', $audit->outcome);
        $this->assertNull($audit->reason);
        $this->assertEquals($this->teacher->id, $audit->userid);
        $this->assertEquals($grant->grantid, $audit->grantid);
        $this->assertEquals($grant->clientid, $audit->clientid);
        $this->assertSame('teacher_probe', $audit->tool);
        $this->assertSame('teacher', $audit->profile);
        $this->assertEquals(0, $audit->iswrite);
        $this->assertEquals($this->course->id, $audit->courseid);
        $this->assertEquals(\context_course::instance($this->course->id)->id, $audit->contextid);
        $this->assertSame('course', $audit->objecttype);
        $this->assertEquals($this->course->id, $audit->objectid);
        $this->assertEquals(1, $audit->itemcount);
        $this->assertSame('192.0.2.7', $audit->ip);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $audit->argshash);
        $this->assertSame(\local_pluginiaconnect\local\audit\logger::audit_hash($args), $audit->argshash);
        $this->assertSame($page, $PAGE, 'The page of the caller is restored');
        $this->assertSame(1, \local_pluginiaconnect_teacher_probe::$executions);
    }

    public function test_audit_never_stores_arguments_and_respects_logip(): void {
        set_config('logip', 0, 'local_pluginiaconnect');
        [, $audit] = $this->call($this->student, 'student', ['profile'], 'boom_probe', ['course_id' => 987654321]);
        $this->assertNull($audit->ip);
        $this->assertStringNotContainsString('987654321', json_encode($audit));
        $this->assertSame(
            \local_pluginiaconnect\local\audit\logger::args_hash(['b' => 1, 'a' => ['y' => 2, 'x' => 1]]),
            \local_pluginiaconnect\local\audit\logger::args_hash(['a' => ['x' => 1, 'y' => 2], 'b' => 1])
        );
    }

    public function test_events_are_triggered_when_the_event_classes_exist(): void {
        if (!class_exists('\local_pluginiaconnect\event\tool_called')) {
            $this->markTestSkipped('Event classes are provided by another part of the plugin.');
        }
        $sink = $this->redirectEvents();
        $this->probe($this->here());
        $this->call($this->student, 'student', ['profile'], 'teacher_probe', $this->here());
        $names = array_map(static fn($event) => $event->eventname, $sink->get_events());
        $this->assertContains('\local_pluginiaconnect\event\tool_called', $names);
        $this->assertContains('\local_pluginiaconnect\event\tool_denied', $names);
    }

    public function test_step_1_site_switches(): void {
        $args = $this->here();
        set_config('directmode', 0, 'local_pluginiaconnect');
        $this->assert_denied($this->probe($args), 'site_unavailable');
        // A gateway client is governed by its own switch.
        $gateway = ['clienttype' => 'gateway'];
        $this->assert_denied(
            $this->probe($args, $gateway),
            'site_unavailable'
        );
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        [$result] = $this->probe($args, $gateway);
        $this->assertFalse($result->iserror);
        \local_pluginiaconnect_teacher_probe::$executions = 0;

        set_config('enabled', 0, 'local_pluginiaconnect');
        $this->assert_denied(
            $this->probe($args, $gateway),
            'site_unavailable'
        );
    }

    public function test_step_1_maintenance_mode(): void {
        global $CFG;
        $CFG->maintenance_enabled = 1;
        $args = $this->here();
        $this->assert_denied($this->probe($args), 'site_unavailable');
        $admin = get_admin();
        [$result] = $this->call($admin, 'admin', ['participants.read'], 'teacher_probe', $args);
        $this->assertFalse($result->iserror);
    }

    public function test_step_2_user_has_something_pending(): void {
        set_user_preference('auth_forcepasswordchange', 1, $this->teacher);
        $outcome = $this->probe($this->here());
        $this->assert_denied($outcome, 'user_action_required');
    }

    public function test_step_2_site_policy_not_accepted(): void {
        global $CFG;
        $CFG->sitepolicy = 'https://example.com/policy';
        $outcome = $this->probe($this->here());
        $this->assert_denied($outcome, 'user_action_required');
    }

    public function test_step_3_unknown_tool(): void {
        [$result, $audit] = $this->call($this->student, 'student', ['profile'], 'run_sql; drop', ['q' => 'x']);
        $this->assertTrue($result->unknowntool);
        $this->assertSame('denied', $audit->outcome);
        $this->assertSame('unknown_tool', $audit->reason);
        $this->assertSame('run_sqldrop', $audit->tool);
    }

    public function test_step_4_tool_disabled_by_the_administrator(): void {
        set_config('tool_teacher_probe_teacher', 0, 'local_pluginiaconnect');
        $outcome = $this->probe($this->here());
        $this->assert_denied($outcome, 'tool_disabled');
    }

    public function test_step_4_write_tools_are_off_until_enabled_and_never_run_at_once(): void {
        $args = $this->here();
        // A write tool nobody enabled is refused, even with the write scope.
        $outcome = $this->call($this->teacher, 'teacher', ['content.write'], 'write_probe', $args);
        $this->assert_denied($outcome, 'tool_disabled');
        $this->assertEquals(1, $outcome[1]->iswrite);
        // Enabled by the administrator, its first call still applies nothing: it asks for confirmation.
        set_config('tool_write_probe_teacher', 1, 'local_pluginiaconnect');
        [$result, $audit] = $this->call($this->teacher, 'teacher', ['content.write'], 'write_probe', $args);
        $this->assertFalse($result->iserror);
        $this->assertSame('confirmation_required', $result->envelope['status']);
        $this->assertSame('confirmation_required', $audit->outcome);
        $this->assertSame(0, \local_pluginiaconnect_teacher_probe::$executions);
        // Read-only mode switches every write off.
        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assert_denied($this->call($this->teacher, 'teacher', ['content.write'], 'write_probe', $args), 'tool_disabled');
        $this->assertArrayNotHasKey('write_probe', site_policy::usable_tools('admin', local\policy\scope::all()));
    }

    public function test_step_4_assistant_policy(): void {
        $args = $this->here();
        $cowork = ['actorid' => 'copilot-cowork', 'actor' => 'Microsoft 365 Copilot Cowork'];
        [$result] = $this->probe($args, $cowork);
        $this->assertFalse($result->iserror);
        \local_pluginiaconnect_teacher_probe::$executions = 0;
        set_config('actorpolicy_copilotcowork', 'blocked', 'local_pluginiaconnect');
        $outcome = $this->probe($args, $cowork);
        $this->assert_denied($outcome, 'tool_disabled');
        // Other assistants are not affected.
        [$result] = $this->probe($args, ['actorid' => 'claude']);
        $this->assertFalse($result->iserror);
    }

    public function test_step_5_profile_too_low(): void {
        $outcome = $this->call($this->teacher, 'student', ['participants.read'], 'teacher_probe', $this->here());
        $this->assert_denied($outcome, 'profile_not_allowed');
    }

    public function test_step_6_scope_missing(): void {
        $outcome = $this->call($this->teacher, 'teacher', ['profile', 'courses.read'], 'teacher_probe', $this->here());
        $this->assert_denied($outcome, 'scope_missing');
        $this->assertSame('participants.read', $outcome[0]->missingscope);
    }

    public function test_step_7_invalid_arguments(): void {
        foreach ([[], ['course_id' => '5'], ['course_id' => 1, 'extra' => true], 'text', [1, 2]] as $args) {
            $outcome = $this->probe($args);
            $this->assert_denied($outcome, 'validation_error');
        }
    }

    public function test_step_8_missing_object_and_site_scope(): void {
        $outcome = $this->probe(['course_id' => 99999999]);
        $this->assert_denied($outcome, 'not_found');
        $this->assertStringContainsString('99999999', $outcome[0]->envelope['error']['message']);
        $this->assert_denied(
            $this->probe(['course_id' => (int) SITEID]),
            'not_found'
        );

        set_config('excludedcourses', $this->course->id, 'local_pluginiaconnect');
        $args = $this->here();
        $this->assert_denied($this->probe($args), 'out_of_scope');
        set_config('excludedcourses', '', 'local_pluginiaconnect');

        set_config('excludedcategories', $this->course->category, 'local_pluginiaconnect');
        $this->assert_denied($this->probe($args), 'out_of_scope');
        set_config('excludedcategories', '', 'local_pluginiaconnect');

        $other = $this->getDataGenerator()->create_category();
        set_config('allowedcategories', $other->id, 'local_pluginiaconnect');
        $this->assert_denied($this->probe($args), 'out_of_scope');
    }

    public function test_step_8_hidden_course_is_out_of_scope_even_for_who_can_see_it(): void {
        global $DB;
        $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
        rebuild_course_cache($this->course->id, true);
        $args = $this->here();
        $this->assert_denied($this->probe($args), 'out_of_scope');
        set_config('includehiddencourses', 1, 'local_pluginiaconnect');
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
        [$result] = $this->probe($args);
        $this->assertFalse($result->iserror);
    }

    public function test_step_8_course_the_user_cannot_enter_is_reported_as_missing(): void {
        $othercourse = $this->getDataGenerator()->create_course();
        $missing = $this->probe(['course_id' => 99999999]);
        $hidden = $this->probe(['course_id' => (int) $othercourse->id]);
        $this->assert_denied($hidden, 'not_found');
        // Same wording as for a course that does not exist, so that its existence is not revealed.
        $this->assertSame(
            str_replace('99999999', 'N', $missing[0]->envelope['error']['message']),
            str_replace((string) $othercourse->id, 'N', $hidden[0]->envelope['error']['message'])
        );
        // Also when the site has excluded the course.
        set_config('excludedcourses', $othercourse->id, 'local_pluginiaconnect');
        $excluded = $this->probe(['course_id' => (int) $othercourse->id]);
        $this->assert_denied($excluded, 'not_found');
    }

    public function test_step_9_live_eligibility(): void {
        $args = $this->here();
        // A token with the teacher profile whose user is only a student: the role is gone.
        $outcome = $this->call($this->student, 'teacher', ['participants.read'], 'teacher_probe', $args);
        $this->assert_denied($outcome, 'profile_not_allowed');

        // A teacher elsewhere who is a student in this course.
        $othercourse = $this->getDataGenerator()->create_course();
        $mixed = $this->getDataGenerator()->create_and_enrol($othercourse, 'editingteacher');
        $this->getDataGenerator()->enrol_user($mixed->id, $this->course->id, 'student');
        $outcome = $this->call($mixed, 'teacher', ['participants.read'], 'teacher_probe', $args);
        $this->assert_denied($outcome, 'permission_denied');

        // The profile switched off by the administrator after the grant was made.
        set_config('allowteacherprofile', 0, 'local_pluginiaconnect');
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
        $outcome = $this->probe($args);
        $this->assert_denied($outcome, 'profile_not_allowed');
    }

    public function test_step_9_role_removed_takes_effect_on_the_next_call(): void {
        $args = $this->here();
        $othercourse = $this->getDataGenerator()->create_course();
        $this->getDataGenerator()->enrol_user($this->teacher->id, $othercourse->id, 'editingteacher');
        [$result] = $this->probe($args);
        $this->assertFalse($result->iserror);
        \local_pluginiaconnect_teacher_probe::$executions = 0;

        // Demoted to student in this course; still a teacher in the other one, and the cache is warm.
        $context = \context_course::instance($this->course->id);
        $roles = get_archetype_roles('editingteacher');
        role_unassign(reset($roles)->id, $this->teacher->id, $context->id);
        $students = get_archetype_roles('student');
        role_assign(reset($students)->id, $this->teacher->id, $context->id);
        $outcome = $this->probe($args);
        $this->assert_denied($outcome, 'permission_denied');
    }

    public function test_step_10_moodle_capability_missing(): void {
        $context = \context_course::instance($this->course->id);
        $roles = get_archetype_roles('editingteacher');
        assign_capability('moodle/course:viewparticipants', CAP_PROHIBIT, reset($roles)->id, $context->id, true);
        $outcome = $this->probe($this->here());
        $this->assert_denied($outcome, 'permission_denied');
    }

    public function test_step_11_rate_limit(): void {
        set_config('ratereadminute', 2, 'local_pluginiaconnect');
        // The counter belongs to a clock minute: the three calls must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['participants.read']);
        $user = user_session::load($grant);
        $args = $this->here();
        $this->assertFalse(call_pipeline::run($grant, $user, 'teacher_probe', $args, 'r1')->iserror);
        $this->assertFalse(call_pipeline::run($grant, $user, 'teacher_probe', $args, 'r2')->iserror);
        $third = call_pipeline::run($grant, $user, 'teacher_probe', $args, 'r3');
        $this->assertSame('rate_limited', $third->error_code());
        $this->assertGreaterThan(0, $third->envelope['error']['retry_after']);
        $this->assertLessThanOrEqual(60, $third->envelope['error']['retry_after']);
        global $DB;
        $audit = $DB->get_record('local_pluginiaconnect_audit', ['requestid' => 'r3'], '*', MUST_EXIST);
        $this->assertSame('rate_limited', $audit->outcome);
        $this->assertSame(2, \local_pluginiaconnect_teacher_probe::$executions);
        // Another grant has its own counter.
        [$result] = $this->probe($args);
        $this->assertFalse($result->iserror);
    }

    public function test_step_14_unexpected_failure_reveals_nothing(): void {
        [$result, $audit] = $this->call($this->student, 'student', ['profile'], 'boom_probe', ['course_id' => 1]);
        $this->assertSame('internal_error', $result->error_code());
        $text = json_encode($result->envelope);
        foreach (['SENSITIVE', 'config.php', 'hunter2', 'RuntimeException', '/var/www'] as $leak) {
            $this->assertStringNotContainsString($leak, $text);
        }
        $this->assertStringContainsString($audit->requestid, $result->envelope['error']['message']);
        $this->assertSame('error', $audit->outcome);
        $this->assertSame('internal_error', $audit->reason);
    }

    public function test_step_14_capability_refused_by_core_is_permission_denied(): void {
        [$result, $audit] = $this->call($this->student, 'student', ['profile'], 'denied_probe', ['course_id' => 1]);
        $this->assertSame('permission_denied', $result->error_code());
        $this->assertSame('denied', $audit->outcome);
    }

    public function test_decision_evaluate_combines_both_stages_and_fails_closed(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['participants.read']);
        $context = \context_course::instance($this->course->id);
        $this->assertTrue(decision::evaluate($grant, $this->teacher, 'teacher_probe', $context)->allowed);
        $this->assertSame('tool_disabled', decision::evaluate($grant, $this->teacher, 'nope', $context)->reason);
        $this->assertSame('tool_disabled', decision::in_context($grant, $this->teacher, 'nope', $context)->reason);

        [, $foreign] = \local_pluginiaconnect_mcp_fixture::grant(
            $this->teacher->id,
            'teacher',
            ['participants.read'],
            ['resource' => 'https://other.example/mcp']
        );
        $this->assertSame('permission_denied', decision::evaluate($foreign, $this->teacher, 'teacher_probe', $context)->reason);

        // A check that cannot be completed denies.
        $DB->delete_records('course', ['id' => $this->course->id]);
        \cache_helper::purge_all();
        $result = decision::evaluate($grant, $this->teacher, 'teacher_probe', $context);
        $this->assertFalse($result->allowed);
        $this->assertSame('permission_denied', $result->reason);
    }

    public function test_site_policy_matrix_scopes_and_titles(): void {
        tool_registry::set_test_tools([]);
        $this->assertTrue(site_policy::is_enabled());
        $this->assertTrue(site_policy::mode_active_for('cimd'));
        $this->assertFalse(site_policy::mode_active_for('gateway'));
        $this->assertTrue(site_policy::is_tool_enabled('list_my_courses', 'student'));
        $this->assertFalse(site_policy::is_tool_enabled('nope', 'student'));
        $this->assertFalse(site_policy::is_tool_enabled('list_my_courses', 'root'));

        $read = ['profile', 'courses.read', 'grades.read'];
        $this->assertEqualsCanonicalizing($read, site_policy::scopes_with_active_tools('student'));
        $all = ['profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read', 'users.read', 'site.read'];
        $this->assertEqualsCanonicalizing($all, site_policy::scopes_with_active_tools('admin'));
        $this->assertSame([], site_policy::scopes_with_active_tools('root'));
        $this->assertSame(
            ['Get connection info', 'Get pending action status'],
            site_policy::describe_tools('student', ['profile'])
        );
        $this->assertCount(13, site_policy::describe_tools('teacher', ['profile', 'courses.read', 'grades.read']));

        $coursetools = [
            'list_my_courses', 'search_courses', 'get_course_outline', 'list_upcoming_deadlines', 'get_assignment_details',
            'list_course_announcements', 'get_my_course_progress',
        ];
        foreach ($coursetools as $tool) {
            set_config("tool_{$tool}_student", 0, 'local_pluginiaconnect');
        }
        $this->assertNotContains('courses.read', site_policy::scopes_with_active_tools('student'));
        $this->assertContains('courses.read', site_policy::scopes_with_active_tools('teacher'));
        set_config('tool_list_my_courses_student', '', 'local_pluginiaconnect');
        $this->assertTrue(site_policy::is_tool_enabled('list_my_courses', 'student'));
    }

    public function test_site_policy_approval_modes(): void {
        $this->assertSame('approval', site_policy::approval_mode('nope', null));
        $this->assertSame('none', site_policy::approval_mode('teacher_probe', null));
        $this->assertSame('confirm', site_policy::approval_mode('write_probe', null));
        $this->assertSame('confirm', site_policy::approval_mode('write_probe', 'claude'));
        $this->assertSame('approval', site_policy::approval_mode('write_probe', 'copilot-cowork'));

        // The administrator can tighten but not loosen.
        set_config('approval_write_probe', 'none', 'local_pluginiaconnect');
        $this->assertSame('confirm', site_policy::approval_mode('write_probe', null));
        set_config('approval_write_probe', 'approval', 'local_pluginiaconnect');
        $this->assertSame('approval', site_policy::approval_mode('write_probe', null));
        set_config('approval_write_probe', 'garbage', 'local_pluginiaconnect');
        $this->assertSame('approval', site_policy::approval_mode('write_probe', null));

        // Copilot Cowork cannot be removed from the unattended assistants; others can be added.
        set_config('approval_write_probe', '', 'local_pluginiaconnect');
        set_config('unattendedactors', 'other-agent', 'local_pluginiaconnect');
        $this->assertSame('approval', site_policy::approval_mode('write_probe', 'copilot-cowork'));
        $this->assertSame('approval', site_policy::approval_mode('write_probe', 'other-agent'));

        $this->assertTrue(site_policy::actor_allows(null, true));
        $this->assertTrue(site_policy::actor_allows('copilot-cowork', true));
        set_config('actorpolicy_copilotcowork', 'readonly', 'local_pluginiaconnect');
        $this->assertFalse(site_policy::actor_allows('copilot-cowork', true));
        $this->assertTrue(site_policy::actor_allows('copilot-cowork', false));
        set_config('actorpolicy_copilotcowork', 'blocked', 'local_pluginiaconnect');
        $this->assertFalse(site_policy::actor_allows('copilot-cowork', false));
        $this->assertTrue(site_policy::actor_allows('chatgpt', true));
    }

    public function test_test_tools_cannot_leak_into_the_catalogue(): void {
        $names = array_column(tool_registry::catalog()['tools'], 'name');
        $this->assertNotContains('teacher_probe', $names);
        $this->assertCount(31, $names);
    }

    public function test_user_session_fallback_revocation_and_login_exceptions(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->student->id, 'student', ['profile']);
        user_session::revoke_grant($grant->grantid);
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $grant->grantid]));
        $this->assertNotEmpty($DB->get_field('local_pluginiaconnect_token', 'timerevoked', ['grantid' => $grant->grantid]));

        $this->assertNull(user_session::from_login_exception(new \RuntimeException('x')));
        foreach (['forcepasswordchangenotice', 'sitepolicynotagreed', 'usernotfullysetup'] as $code) {
            $mapped = user_session::from_login_exception(new \moodle_exception($code, 'error'));
            $this->assertSame('user_action_required', $mapped->error_code());
        }
        $guest = guest_user();
        [, $guestgrant] = \local_pluginiaconnect_mcp_fixture::grant($guest->id, 'student', ['profile']);
        $this->assertNull(user_session::load($guestgrant));
    }
}
