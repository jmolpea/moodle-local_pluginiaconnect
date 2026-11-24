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
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\health;
use local_pluginiaconnect\local\http\origin_guard;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\server;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\cimd_fetcher;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\oauth\revoke_endpoint;
use local_pluginiaconnect\local\oauth\token_endpoint;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\policy\decision;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/oauth_helper.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_test_tools.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\decision::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\eligibility::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\token_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\revoke_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\token_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\client_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\cimd_fetcher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\rate_limiter::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\audit\logger::class)]
/**
 * Regression tests of the findings of the independent security review of phase 1.
 *
 * Each test is named after the finding it covers (M1 to M4, L1 to L8, I1, I2); the
 * sanitiser finding (M3) has its own file.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 * @covers     \local_pluginiaconnect\local\policy\decision
 * @covers     \local_pluginiaconnect\local\policy\eligibility
 * @covers     \local_pluginiaconnect\local\oauth\token_endpoint
 * @covers     \local_pluginiaconnect\local\oauth\revoke_endpoint
 * @covers     \local_pluginiaconnect\local\oauth\token_service
 * @covers     \local_pluginiaconnect\local\oauth\client_repository
 * @covers     \local_pluginiaconnect\local\oauth\cimd_fetcher
 * @covers     \local_pluginiaconnect\local\guardrail\rate_limiter
 * @covers     \local_pluginiaconnect\local\audit\logger
 */
final class security_review_test extends \advanced_testcase {
    use oauth_helper;

    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Student of the course. */
    private \stdClass $student;
    /** @var \stdClass Editing teacher of the course. */
    private \stdClass $teacher;

    /**
     * One course with a student and a teacher, the connector on and the probe tools registered.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        tool_registry::set_test_tools([
            'teacher_probe' => \local_pluginiaconnect_teacher_probe::class,
            'write_probe' => \local_pluginiaconnect_write_probe::class,
        ]);
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course();
        $this->student = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
    }

    /**
     * Restores every test hook.
     */
    protected function tearDown(): void {
        tool_registry::set_test_tools([]);
        logger::simulate_failure(false);
        rate_limiter::simulate_cache_failure(false);
        cimd_fetcher::set_test_hooks(null, null);
        parent::tearDown();
    }

    /**
     * Runs one tool call the way the server does.
     *
     * @param \local_pluginiaconnect\local\oauth\grant_context $grant Grant.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @return \local_pluginiaconnect\local\mcp\pipeline_result
     */
    private function run_call($grant, string $tool, array $args = []) {
        $user = user_session::load($grant);
        return call_pipeline::run($grant, $user, $tool, $args, \core\uuid::generate(), '192.0.2.7');
    }

    /**
     * Error code of a pipeline result, or null for a success.
     *
     * @param \local_pluginiaconnect\local\mcp\pipeline_result $result Result.
     * @return string|null
     */
    private function code_of($result): ?string {
        return $result->envelope['error']['code'] ?? null;
    }

    /**
     * M1: junk requests that name a client do not lock that client out for everybody else.
     */
    public function test_m1_token_endpoint_cannot_be_used_to_lock_out_a_client(): void {
        $this->enable_direct_mode();
        [$client, $secret] = $this->make_client(\local_pluginiaconnect\local\oauth\client::AUTH_POST);
        $junk = [
            'grant_type' => 'refresh_token', 'refresh_token' => 'pc_rt_wrong', 'client_id' => $client->clientid,
            'client_secret' => 'guess',
        ];
        // The counter belongs to a clock minute: the requests must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        // An attacker hammers the endpoint from one address, well under the per-address limit.
        for ($i = 0; $i < token_endpoint::CLIENT_LIMIT + 5; $i++) {
            token_endpoint::handle($this->form_request($junk, [], '198.51.100.66'));
        }
        // The attacker is slowed down.
        $this->assertSame(429, token_endpoint::handle($this->form_request($junk, [], '198.51.100.66'))->status);
        $this->assertSame(429, revoke_endpoint::handle($this->form_request(
            ['token' => 'x', 'client_id' => $client->clientid, 'client_secret' => 'guess'],
            [],
            '198.51.100.66'
        ))->status);

        // The real user of the same client, from another address, is not.
        $user = $this->make_teacher();
        $tokens = $this->issue_tokens($client, $user);
        $response = token_endpoint::handle($this->form_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $client->clientid,
            'client_secret' => $secret,
        ], [], '203.0.113.20'));
        $this->assertSame(200, $response->status);
        $this->assertSame(200, revoke_endpoint::handle($this->form_request(
            ['token' => $response->decoded()['access_token'], 'client_id' => $client->clientid, 'client_secret' => $secret],
            [],
            '203.0.113.20'
        ))->status);
    }

    /**
     * M2: a user added to an excluded cohort loses the connection on the next call.
     */
    public function test_m2_excluded_cohort_is_enforced_on_every_call(): void {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/cohort/lib.php');
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['courses.read']);
        $this->assertNull($this->code_of($this->run_call($grant, 'list_my_courses')));

        $cohort = $this->getDataGenerator()->create_cohort();
        cohort_add_member($cohort->id, $this->teacher->id);
        set_config('excludedcohorts', $cohort->id, 'local_pluginiaconnect');

        $result = $this->run_call($grant, 'list_my_courses');
        $this->assertSame('permission_denied', $this->code_of($result));
        $row = $DB->get_record('local_pluginiaconnect_grant', ['id' => $grant->grantid], '*', MUST_EXIST);
        $this->assertEquals(0, $row->status);
        $this->assertSame('system', $row->revokedby);
        // The decision function refuses too, on its own.
        $this->assertFalse(decision::before_context($grant, $this->teacher, 'list_my_courses')->allowed);
    }

    /**
     * M2: losing the connect capability stops token refresh and revokes the grant.
     */
    public function test_m2_lost_connect_capability_stops_refresh(): void {
        global $DB;
        $this->enable_direct_mode();
        [$client] = $this->make_client();
        $user = $this->make_teacher();
        $tokens = $this->issue_tokens($client, $user);

        $roleid = $this->getDataGenerator()->create_role();
        assign_capability(eligibility::CAP_CONNECT, CAP_PROHIBIT, $roleid, \context_system::instance());
        role_assign($roleid, $user->id, \context_system::instance());

        try {
            token_service::refresh($tokens['refresh_token'], $client);
            $this->fail('The refresh should have been refused.');
        } catch (oauth_exception $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['userid' => $user->id]));
        $this->assertNull(token_service::authenticate($tokens['access_token']));
    }

    /**
     * M4: when the audit row cannot be written the call returns an error and no data.
     */
    public function test_m4_audit_failure_fails_closed(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['participants.read']);
        \local_pluginiaconnect_teacher_probe::$executions = 0;
        logger::simulate_failure(true);
        $result = $this->run_call($grant, 'teacher_probe', ['course_id' => (int) $this->course->id]);
        logger::simulate_failure(false);

        $this->assertTrue($result->iserror);
        $this->assertSame('internal_error', $this->code_of($result));
        $this->assertArrayNotHasKey('data', $result->envelope);
        $this->assertStringNotContainsString('Ana', json_encode($result->envelope));
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_audit'));

        // A denied call that cannot be audited is an error as well, never a silent denial.
        logger::simulate_failure(true);
        $denied = $this->run_call($grant, 'no_such_tool');
        logger::simulate_failure(false);
        $this->assertSame('internal_error', $this->code_of($denied));

        // With the audit trail working again the same call succeeds and is recorded.
        $ok = $this->run_call($grant, 'teacher_probe', ['course_id' => (int) $this->course->id]);
        $this->assertNull($this->code_of($ok));
        $this->assertSame(1, $DB->count_records('local_pluginiaconnect_audit', ['outcome' => 'ok']));
    }

    /**
     * L1: an activity that is available but not shown on the course page is not listed to students.
     */
    public function test_l1_stealth_activities_are_not_listed(): void {
        global $CFG;
        $CFG->allowstealth = 1;
        $generator = $this->getDataGenerator();
        $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Shown page']);
        $stealth = $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Stealth page']);
        set_coursemodule_visible($stealth->cmid, 1, 0);
        rebuild_course_cache($this->course->id, true);

        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->student->id, 'student', ['courses.read']);
        $json = json_encode($this->run_call($grant, 'get_course_outline', ['course_id' => (int) $this->course->id])->envelope);
        $this->assertStringContainsString('Shown page', $json);
        $this->assertStringNotContainsString('Stealth page', $json);

        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['courses.read']);
        $json = json_encode($this->run_call($grant, 'get_course_outline', ['course_id' => (int) $this->course->id])->envelope);
        $this->assertStringContainsString('Stealth page', $json);
    }

    /**
     * L2: a hidden grade gives away nothing, not even when it was graded.
     */
    public function test_l2_hidden_grade_has_no_graded_date(): void {
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        $generator = $this->getDataGenerator();
        $shown = $generator->create_module('assign', ['course' => $this->course->id, 'name' => 'Shown work']);
        $hidden = $generator->create_module('assign', ['course' => $this->course->id, 'name' => 'Hidden work']);
        foreach ([$shown, $hidden] as $assign) {
            $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $assign->id,
                'courseid' => $this->course->id]);
            $item->update_final_grade($this->student->id, 80);
            if ($assign === $hidden) {
                $item->set_hidden(1);
            }
        }
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->student->id, 'student', ['grades.read']);
        $result = $this->run_call($grant, 'get_my_grades', ['course_id' => (int) $this->course->id]);
        $this->assertNull($this->code_of($result));
        $items = array_column($result->envelope['data']['grade_items'], null, 'name');
        $this->assertNotNull($items['Shown work']['graded_at']);
        if (isset($items['Hidden work'])) {
            $this->assertNull($items['Hidden work']['graded_at']);
            $this->assertNull($items['Hidden work']['grade_raw']);
        }
    }

    /**
     * L3: denied calls use up the limits of the connection, and the endpoint has a limit per address.
     */
    public function test_l3_denied_calls_count_and_addresses_are_limited(): void {
        set_config('ratereadminute', 3, 'local_pluginiaconnect');
        // The counter belongs to a clock minute: the calls must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->student->id, 'student', ['courses.read']);
        // The student probes a teacher tool: every attempt is denied, and every attempt counts.
        for ($i = 0; $i < 3; $i++) {
            $this->assertSame('profile_not_allowed', $this->code_of(
                $this->run_call($grant, 'teacher_probe', ['course_id' => (int) $this->course->id])
            ));
        }
        $this->assertSame('rate_limited', $this->code_of(
            $this->run_call($grant, 'teacher_probe', ['course_id' => (int) $this->course->id])
        ));
        $this->assertSame('rate_limited', $this->code_of($this->run_call($grant, 'list_my_courses')));

        // Requests without a token are limited per address before authentication.
        $anonymous = new request('POST', ['Content-Type' => 'application/json'], '{}', [], true, '198.51.100.9');
        // The counter belongs to a clock minute: if the minute turned while counting, count again.
        do {
            $minute = intdiv(time(), MINSECS);
            for ($i = 0; $i < server::IP_LIMIT; $i++) {
                server::handle($anonymous);
            }
        } while (intdiv(time(), MINSECS) !== $minute);
        $limited = server::handle($anonymous);
        $this->assertSame(429, $limited->status);
        $this->assertArrayHasKey('Retry-After', $limited->headers);
        $other = new request('POST', ['Content-Type' => 'application/json'], '{}', [], true, '198.51.100.10');
        $this->assertSame(401, server::handle($other)->status);
    }

    /**
     * L4: without the counter cache every call is refused, reads included.
     */
    public function test_l4_rate_limiter_fails_closed(): void {
        rate_limiter::simulate_cache_failure(true);
        $this->assertFalse(rate_limiter::hit('unit', 'a', 100, 60));
        $this->assertFalse(rate_limiter::hit('unit', 'a', 100, 60, true));
        $this->assertSame(PHP_INT_MAX, rate_limiter::current('unit', 'a', 60));
        $this->assertDebuggingCalledCount(2);

        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', ['courses.read']);
        $this->assertSame('rate_limited', $this->code_of($this->run_call($grant, 'list_my_courses')));
        $this->assertDebuggingCalled();
        rate_limiter::simulate_cache_failure(false);
        $this->assertNull($this->code_of($this->run_call($grant, 'list_my_courses')));
    }

    /**
     * L5: failed metadata downloads are remembered and one address cannot cause many of them.
     */
    public function test_l5_metadata_downloads_are_limited(): void {
        set_config('cimdtrusteddomains', '', 'local_pluginiaconnect');
        $downloads = 0;
        cimd_fetcher::set_test_hooks(
            static function () use (&$downloads) {
                $downloads++;
                return [500, ''];
            },
            static fn() => ['93.184.216.34']
        );
        $url = 'https://apps.example.org/client.json';
        $this->assertNull(client_repository::find($url, true, '198.51.100.30'));
        $this->assertNull(client_repository::find($url, true, '198.51.100.31'));
        $this->assertSame(1, $downloads, 'A failed download is not repeated while it is remembered');

        // One address asking for many different documents is stopped.
        // The counter belongs to a clock minute: the requests must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        for ($i = 0; $i < cimd_fetcher::IP_LIMIT + 5; $i++) {
            client_repository::find("https://apps.example.org/c{$i}.json", true, '198.51.100.40');
        }
        $this->assertSame(1 + cimd_fetcher::IP_LIMIT, $downloads);
    }

    /**
     * L5: metadata document clients nobody uses any more are removed by the cleanup.
     */
    public function test_l5_stale_metadata_clients_are_cleaned(): void {
        global $DB;
        $old = time() - 40 * DAYSECS;
        $make = static function (string $url) use ($DB, $old): int {
            return (int) $DB->insert_record('local_pluginiaconnect_client', (object) [
                'clientidhash' => hash('sha256', $url), 'clientid' => $url, 'type' => client::TYPE_CIMD, 'name' => 'Old',
                'redirecturis' => json_encode(['https://claude.ai/api/mcp/auth_callback']), 'authmethod' => 'none',
                'status' => 1, 'timecreated' => $old, 'timemodified' => $old, 'timelastused' => $old,
            ]);
        };
        $unused = $make('https://claude.ai/unused.json');
        $inuse = $make('https://claude.ai/inuse.json');
        $DB->insert_record('local_pluginiaconnect_grant', (object) [
            'userid' => $this->teacher->id, 'clientid' => $inuse, 'profile' => 'teacher', 'scopes' => 'profile',
            'resource' => \local_pluginiaconnect\local\urls::resource(), 'status' => 1, 'timecreated' => $old,
            'timelastused' => $old, 'timeabsoluteexpiry' => time() + DAYSECS,
        ]);
        token_service::cleanup_expired();
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_client', ['id' => $unused]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_client', ['id' => $inuse]));
    }

    /**
     * Settings that withdraw the acceptance of a kind of client.
     *
     * @return array
     */
    public static function withdrawal_provider(): array {
        return [
            'registration switched off' => ['allowdcr', '0'],
            'redirect address removed' => ['allowedredirects', 'https://chatgpt.com/connector_platform_oauth_redirect'],
        ];
    }

    /**
     * L6: withdrawing acceptance of a kind of client stops its existing connections at once.
     *
     * @dataProvider withdrawal_provider
     * @param string $setting Setting to change.
     * @param string $value New value.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('withdrawal_provider')]
    public function test_l6_withdrawn_clients_stop_working(string $setting, string $value): void {
        $this->enable_direct_mode();
        [$client] = $this->make_client();
        $user = $this->make_teacher();
        $tokens = $this->issue_tokens($client, $user);
        $grant = token_service::authenticate($tokens['access_token']);
        $this->assertNotNull($grant);
        $this->assertTrue(decision::before_context($grant, $user, 'list_my_courses')->allowed);

        set_config($setting, $value, 'local_pluginiaconnect');

        $this->assertFalse(client_repository::is_still_accepted(client_repository::get_by_id($client->id)));
        $response = token_endpoint::handle($this->form_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $client->clientid,
        ]));
        $this->assertSame(401, $response->status);
        $this->assertSame('invalid_client', $response->decoded()['error']);
        $denied = decision::before_context($grant, $user, 'list_my_courses');
        $this->assertFalse($denied->allowed);
        $this->assertSame('permission_denied', $denied->reason);
        $this->assertSame('permission_denied', $this->code_of($this->run_call($grant, 'list_my_courses')));
    }

    /**
     * L6: a metadata document client stops working when its domain is no longer trusted or the method is off.
     */
    public function test_l6_untrusted_metadata_domain_stops_working(): void {
        global $DB;
        $now = time();
        $id = (int) $DB->insert_record('local_pluginiaconnect_client', (object) [
            'clientidhash' => hash('sha256', 'https://claude.ai/app.json'), 'clientid' => 'https://claude.ai/app.json',
            'type' => client::TYPE_CIMD, 'name' => 'App', 'authmethod' => 'none', 'status' => 1,
            'redirecturis' => json_encode(['https://claude.ai/api/mcp/auth_callback']),
            'timecreated' => $now, 'timemodified' => $now, 'timelastused' => $now,
        ]);
        $this->assertTrue(client_repository::is_still_accepted(client_repository::get_by_id($id)));
        set_config('cimdtrusteddomains', 'chatgpt.com', 'local_pluginiaconnect');
        $this->assertFalse(client_repository::is_still_accepted(client_repository::get_by_id($id)));
        set_config('cimdtrusteddomains', 'claude.ai', 'local_pluginiaconnect');
        set_config('allowcimd', 0, 'local_pluginiaconnect');
        $this->assertFalse(client_repository::is_still_accepted(client_repository::get_by_id($id)));
    }

    /**
     * L8: the encrypted pair kept for the retry window is cleared soon after the window ends.
     */
    public function test_l8_grace_data_is_cleared_promptly(): void {
        global $DB;
        $this->enable_direct_mode();
        [$client] = $this->make_client();
        $user = $this->make_teacher();
        $first = $this->issue_tokens($client, $user);
        $second = token_service::refresh($first['refresh_token'], $client);
        $used = $DB->get_record('local_pluginiaconnect_token', ['tokenhash' => token_service::hash($first['refresh_token'])]);
        $this->assertNotEmpty($used->gracedata);

        // The window is over.
        $DB->set_field('local_pluginiaconnect_token', 'timeused', time() - 120, ['id' => $used->id]);

        // Any token endpoint request sweeps it, even a failing one from somebody else.
        \cache::make('local_pluginiaconnect', 'ratelimit')->purge();
        token_endpoint::handle($this->form_request(['grant_type' => 'refresh_token', 'refresh_token' => 'pc_rt_x',
            'client_id' => $client->clientid]));
        $this->assertNull($DB->get_field('local_pluginiaconnect_token', 'gracedata', ['id' => $used->id]));

        // The next rotation of the same grant clears older ciphertext too.
        $secondhash = token_service::hash($second['refresh_token']);
        $usedsecond = $DB->get_record('local_pluginiaconnect_token', ['tokenhash' => $secondhash]);
        $third = token_service::refresh($second['refresh_token'], $client);
        $DB->set_field('local_pluginiaconnect_token', 'timeused', time() - 120, ['id' => $usedsecond->id]);
        token_service::refresh($third['refresh_token'], $client);
        $this->assertNull($DB->get_field('local_pluginiaconnect_token', 'gracedata', ['id' => $usedsecond->id]));
    }

    /**
     * I1: the profile that counts in a context is the one the user really holds there.
     */
    public function test_i1_effective_profile_follows_the_context(): void {
        $generator = $this->getDataGenerator();
        $other = $generator->create_course();
        $generator->enrol_user($this->teacher->id, $other->id, 'student');
        $here = \context_course::instance($this->course->id);
        $there = \context_course::instance($other->id);
        $userid = (int) $this->teacher->id;

        $this->assertSame(profile::TEACHER, eligibility::effective_profile($userid, profile::TEACHER, $here));
        $this->assertSame(profile::STUDENT, eligibility::effective_profile($userid, profile::TEACHER, $there));
        $this->assertSame(profile::STUDENT, eligibility::effective_profile($userid, profile::STUDENT, $here));
        $this->assertSame(profile::TEACHER, eligibility::effective_profile($userid, profile::MANAGER, $here));
        $this->assertSame(profile::TEACHER, eligibility::effective_profile(
            $userid,
            profile::TEACHER,
            \context_system::instance()
        ));
        $this->assertNull(eligibility::effective_profile($userid, 'root', $here));

        // A teacher connection gives no teacher tool where the user is only a student.
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($userid, 'teacher', ['participants.read', 'courses.read']);
        $this->assertTrue(decision::in_context($grant, $this->teacher, 'teacher_probe', $here)->allowed);
        $this->assertFalse(decision::in_context($grant, $this->teacher, 'teacher_probe', $there)->allowed);
        $this->assertTrue(decision::in_context($grant, $this->teacher, 'get_course_outline', $there)->allowed);

        // With the student profile switched off (the factory setting) the teacher keeps the basic tools
        // in the courses they teach and outside courses, and loses them where they are only a student.
        set_config('allowstudentprofile', 0, 'local_pluginiaconnect');
        eligibility::invalidate_all();
        $this->assertTrue(decision::in_context($grant, $this->teacher, 'get_course_outline', $here)->allowed);
        $this->assertTrue(decision::in_context($grant, $this->teacher, 'list_my_courses', \context_system::instance())->allowed);
        $this->assertFalse(decision::in_context($grant, $this->teacher, 'get_course_outline', $there)->allowed);
        $this->assertNull(eligibility::effective_profile($userid, profile::TEACHER, $there));
    }

    /**
     * I1: a write tool needs the write capability in the very context it changes.
     */
    public function test_i1_write_capability_is_checked_in_the_target_context(): void {
        $nonediting = $this->getDataGenerator()->create_and_enrol($this->course, 'teacher');
        $context = \context_course::instance($this->course->id);
        $scopes = ['content.write'];

        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', $scopes);
        $this->assertTrue(decision::in_context($grant, $this->teacher, 'write_probe', $context)->allowed);

        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($nonediting->id, 'teacher', $scopes);
        $denied = decision::in_context($grant, $nonediting, 'write_probe', $context);
        $this->assertFalse($denied->allowed);
        $this->assertSame('permission_denied', $denied->reason);
        // The same user may still read there.
        $this->assertTrue(decision::in_context($grant, $nonediting, 'teacher_probe', $context)->allowed);

        // An editing teacher elsewhere cannot write here.
        $elsewhere = $this->getDataGenerator()->create_course();
        $visitor = $this->getDataGenerator()->create_and_enrol($elsewhere, 'editingteacher');
        $this->getDataGenerator()->enrol_user($visitor->id, $this->course->id, 'teacher');
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($visitor->id, 'teacher', $scopes);
        $this->assertFalse(decision::in_context($grant, $visitor, 'write_probe', $context)->allowed);
        $this->assertTrue(decision::in_context(
            $grant,
            $visitor,
            'write_probe',
            \context_course::instance($elsewhere->id)
        )->allowed);
    }

    /**
     * I2: the origin check raises no PHP notice whether or not the site address has a port.
     */
    public function test_i2_origin_check_without_port(): void {
        global $CFG;
        foreach (
            ['https://campus.example.org' => 'https://campus.example.org',
                'https://campus.example.org:443/moodle' => 'https://campus.example.org',
                'http://localhost:8153' => 'http://localhost:8153'] as $wwwroot => $origin
        ) {
            $CFG->wwwroot = $wwwroot;
            $this->assertSame($origin, origin_guard::own_origin());
            $this->assertNull(origin_guard::check(new request('POST', ['Origin' => $origin])));
            $this->assertSame(403, origin_guard::check(new request('POST', ['Origin' => 'https://evil.example']))->status);
        }
    }

    /**
     * L7: the diagnostics page warns when the site proxy prevents metadata document clients.
     */
    public function test_l7_proxy_warning(): void {
        $this->assertSame(health::WARNING, health::check_proxy(true, true)['status']);
        $this->assertSame(health::OK, health::check_proxy(true, false)['status']);
        $this->assertSame(health::OK, health::check_proxy(false, true)['status']);
    }
}
