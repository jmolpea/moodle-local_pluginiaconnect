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

use local_pluginiaconnect\admin\setting_list;
use local_pluginiaconnect\local\guardrail\action_store;
use local_pluginiaconnect\local\guardrail\confirmation_service;
use local_pluginiaconnect\local\guardrail\exposure_budget;
use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\code_service;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\oauth\redirect_policy;
use local_pluginiaconnect\local\oauth\token_endpoint;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\urls;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/oauth_helper.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Regression tests of the second security review (write path, read tools, phase 2 pages).
 * One test per finding, named after it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\oauth\code_service
 * @covers     \local_pluginiaconnect\local\tool\assign_helper
 * @covers     \local_pluginiaconnect\local\tool\write\save_assignment_grade
 * @covers     \local_pluginiaconnect\local\tool\write\enrol_users_in_course
 * @covers     \local_pluginiaconnect\local\tool\write\update_assignment_dates
 * @covers     \local_pluginiaconnect\local\tool\read\search_courses
 * @covers     \local_pluginiaconnect\local\tool\read\get_my_course_progress
 * @covers     \local_pluginiaconnect\local\guardrail\text_sanitizer
 * @covers     \local_pluginiaconnect\local\guardrail\action_store
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 * @covers     \local_pluginiaconnect\local\oauth\redirect_policy
 * @covers     \local_pluginiaconnect\local\policy\decision
 * @covers     \local_pluginiaconnect\local\policy\site_policy
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\code_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\assign_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\write\save_assignment_grade::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\write\enrol_users_in_course::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\write\update_assignment_dates::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\search_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_my_course_progress::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\text_sanitizer::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\action_store::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\redirect_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\decision::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\site_policy::class)]
final class security_review2_test extends \advanced_testcase {
    use oauth_helper;

    /** @var string[] Names of the write tools. */
    private const WRITE_TOOLS = ['save_assignment_grade', 'post_course_announcement', 'send_message_to_participants',
        'set_activity_visibility', 'update_assignment_dates', 'enrol_users_in_course', 'set_enrolment_status',
        'create_course', 'set_user_suspension'];

    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var \stdClass Another student. */
    private \stdClass $other;
    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;
    /** @var \stdClass Manager at site level. */
    private \stdClass $manager;

    /**
     * A course with two students and an editing teacher, a site manager and every write tool switched on.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['fullname' => 'Biology 101', 'shortname' => 'BIO101',
            'enablecompletion' => 1]);
        $this->student = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ana', 'lastname' => 'Alvarez']);
        $this->other = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Bruno', 'lastname' => 'Bermejo']);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tina']);
        $this->manager = $generator->create_user(['firstname' => 'Mar', 'lastname' => 'Manager']);
        $managerroles = get_archetype_roles('manager');
        role_assign(reset($managerroles)->id, $this->manager->id, \context_system::instance()->id);

        \local_pluginiaconnect_mcp_fixture::enable(['gatewayenabled' => 1]);
        foreach (self::WRITE_TOOLS as $tool) {
            foreach (profile::all() as $candidate) {
                set_config("tool_{$tool}_{$candidate}", 1, 'local_pluginiaconnect');
            }
        }
        $this->setUser(null);
    }

    /**
     * Runs a tool through the pipeline.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile of the connection.
     * @param mixed $grant Existing grant to reuse.
     * @return array [pipeline result, grant]
     */
    private function run_tool(\stdClass $user, string $tool, array $args, string $profile = 'teacher', $grant = null): array {
        if ($grant === null) {
            [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, scope::max_for_profile($profile));
        }
        $loaded = user_session::load($grant);
        $this->assertNotNull($loaded);
        $result = call_pipeline::run($grant, $loaded, $tool, $args, \core\uuid::generate(), '192.0.2.7');
        \local_pluginiaconnect_output_schema_check::assert_valid($tool, $result->envelope);
        return [$result, $grant];
    }

    /**
     * Data of a call that must succeed.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile.
     * @return array
     */
    private function data(\stdClass $user, string $tool, array $args, string $profile = 'teacher'): array {
        [$result] = $this->run_tool($user, $tool, $args, $profile);
        $this->assertFalse($result->iserror, "{$tool}: " . json_encode($result->envelope['error'] ?? null));
        return $result->envelope['data'];
    }

    /**
     * Error of a call that must fail.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile.
     * @return array [code, message]
     */
    private function error(\stdClass $user, string $tool, array $args, string $profile = 'teacher'): array {
        [$result] = $this->run_tool($user, $tool, $args, $profile);
        $this->assertTrue($result->iserror, "{$tool} should have failed: " . json_encode($result->envelope));
        return [(string) $result->error_code(), (string) $result->envelope['error']['message']];
    }

    /**
     * Requests and confirms a change; returns the summary.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile.
     * @return string
     */
    private function apply(\stdClass $user, string $tool, array $args, string $profile = 'teacher'): string {
        [$result, $grant] = $this->run_tool($user, $tool, $args, $profile);
        $this->assertSame('confirmation_required', $result->envelope['status'] ?? null, json_encode($result->envelope));
        $token = $result->envelope['data']['confirm_token'];
        [$applied] = $this->run_tool($user, $tool, $args + ['confirm_token' => $token], $profile, $grant);
        $this->assertSame('ok', $applied->envelope['status'] ?? null, json_encode($applied->envelope));
        return $result->envelope['data']['summary'];
    }

    /**
     * Enrols a new teacher with a role of their own: an editing teacher without one capability.
     *
     * @param string $capability Capability the role does not have.
     * @return \stdClass The user.
     */
    private function marker_without(string $capability): \stdClass {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $roleid = $generator->create_role(['shortname' => 'limitedmarker', 'archetype' => 'editingteacher']);
        assign_capability($capability, CAP_PROHIBIT, $roleid, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $marker = $generator->create_user(['firstname' => 'Gus', 'lastname' => 'Marker']);
        $generator->enrol_user($marker->id, $this->course->id, $roleid);
        $this->assertFalse(has_capability($capability, \context_course::instance($this->course->id), $marker));
        $this->assertTrue(has_capability('mod/assign:grade', \context_course::instance($this->course->id), $marker));
        return $marker;
    }

    /**
     * Creates an assignment with a submitted online text for each given student.
     *
     * @param array $settings Assignment settings.
     * @param \stdClass[] $students Students who submit.
     * @param \stdClass|null $course Course; the common one by default.
     * @return array [module record, submission ids keyed by user id]
     */
    private function assignment(array $settings, array $students, ?\stdClass $course = null): array {
        global $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $module = $generator->create_module('assign', $settings + [
            'course' => ($course ?? $this->course)->id, 'grade' => 100, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1, 'assignfeedback_comments_enabled' => 1,
        ]);
        $ids = [];
        foreach ($students as $student) {
            $generator->get_plugin_generator('mod_assign')->create_submission([
                'userid' => $student->id, 'cmid' => $module->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'onlinetext' => 'Answer of ' . $student->firstname,
            ]);
            $ids[$student->id] = (int) $DB->get_field('assign_submission', 'id', ['assignment' => $module->id,
                'userid' => $student->id, 'latest' => 1]);
        }
        $this->setAdminUser();
        $this->setUser(null);
        return [$module, $ids];
    }

    /**
     * Allocates a marker to a student, in the storage of the running Moodle version.
     *
     * @param \stdClass $module Assignment module record.
     * @param \stdClass $student Student.
     * @param \stdClass $marker Marker.
     */
    private function allocate(\stdClass $module, \stdClass $student, \stdClass $marker): void {
        global $DB;
        if ($DB->get_manager()->table_exists('assign_allocated_marker')) {
            $DB->insert_record('assign_allocated_marker', (object) [
                'student' => $student->id, 'assignment' => $module->id, 'marker' => $marker->id, 'optional' => 0,
            ]);
            return;
        }
        $flags = $DB->get_record('assign_user_flags', ['assignment' => $module->id, 'userid' => $student->id]);
        if ($flags) {
            $DB->set_field('assign_user_flags', 'allocatedmarker', $marker->id, ['id' => $flags->id]);
        } else {
            $DB->insert_record('assign_user_flags', (object) [
                'assignment' => $module->id, 'userid' => $student->id, 'allocatedmarker' => $marker->id,
                'locked' => 0, 'mailed' => 0, 'extensionduedate' => 0, 'workflowstate' => '',
            ]);
        }
    }

    /**
     * M1: the assistant named in the browser must be repeated by the gateway at the token endpoint.
     * A dropped or changed value never becomes a connection, and a blocked assistant is refused there too.
     */
    public function test_m1_assistant_is_bound_at_the_token_exchange(): void {
        global $DB;
        $secret = 'gateway-secret-0123456789abcdef0123';
        $gateway = client_repository::save_gateway_client($secret);
        $exchange = function (?string $stored, ?string $declared) use ($gateway): array {
            $verifier = $this->make_verifier();
            $request = authorization_request::from_params($this->request_params($gateway, $verifier, [
                'pc_actor_id' => $stored, 'pc_actor' => $stored === null ? null : 'Some assistant',
            ]));
            $code = code_service::create($request, (int) $this->teacher->id, 'teacher', ['profile', 'courses.read']);
            try {
                $tokens = code_service::exchange($code, $gateway, urls::GATEWAY_REDIRECT, urls::resource(), $verifier, $declared);
            } catch (oauth_exception $e) {
                $this->assertSame('invalid_grant', $e->error);
                $tokens = null;
            }
            return [$tokens, $code, $verifier];
        };

        // The value was stripped in the browser: the site stored none, the gateway states Copilot Cowork.
        $grants = $DB->count_records('local_pluginiaconnect_grant');
        [$tokens, $code, $verifier] = $exchange(null, 'copilot-cowork');
        $this->assertNull($tokens);
        // The code is spent, so the right value cannot be tried next.
        $this->expect_invalid_grant(fn() => code_service::exchange(
            $code,
            $gateway,
            urls::GATEWAY_REDIRECT,
            urls::resource(),
            $verifier,
            null
        ));
        // Changed in the browser, or not repeated by the gateway.
        $this->assertNull($exchange('claude', 'copilot-cowork')[0]);
        $this->assertNull($exchange('copilot-cowork', null)[0]);
        $this->assertNull($exchange('copilot-cowork', '')[0]);
        $this->assertNull($exchange('copilot-cowork', 'claude')[0]);
        $this->assertSame($grants, $DB->count_records('local_pluginiaconnect_grant'));

        // Matching values connect, and the response tells the gateway which assistant the site recorded.
        [$tokens] = $exchange('copilot-cowork', 'copilot-cowork');
        $this->assertSame('copilot-cowork', $tokens['pc_actor_id']);
        $this->assertSame('copilot-cowork', $DB->get_field('local_pluginiaconnect_grant', 'actorid', [], IGNORE_MULTIPLE));
        [$tokens] = $exchange(null, null);
        $this->assertArrayNotHasKey('pc_actor_id', $tokens);
        [$tokens] = $exchange(null, '');
        $this->assertNotNull($tokens);

        // A blocked assistant gets no connection even with a code issued before it was blocked.
        $verifier = $this->make_verifier();
        $request = authorization_request::from_params($this->request_params($gateway, $verifier, [
            'pc_actor_id' => 'copilot-cowork',
        ]));
        $code = code_service::create($request, (int) $this->teacher->id, 'teacher', ['profile', 'courses.read']);
        set_config('actorpolicy_copilotcowork', 'blocked', 'local_pluginiaconnect');
        $this->expect_invalid_grant(fn() => code_service::exchange(
            $code,
            $gateway,
            urls::GATEWAY_REDIRECT,
            urls::resource(),
            $verifier,
            'copilot-cowork'
        ));
        set_config('actorpolicy_copilotcowork', 'approval', 'local_pluginiaconnect');

        // The endpoint reads the form field.
        $verifier = $this->make_verifier();
        $request = authorization_request::from_params($this->request_params($gateway, $verifier, [
            'pc_actor_id' => 'copilot-cowork',
        ]));
        $form = [
            'grant_type' => 'authorization_code', 'redirect_uri' => urls::GATEWAY_REDIRECT, 'resource' => urls::resource(),
            'code_verifier' => $verifier, 'client_id' => urls::GATEWAY_CLIENT_ID, 'client_secret' => $secret,
        ];
        $code = code_service::create($request, (int) $this->teacher->id, 'teacher', ['profile', 'courses.read']);
        $response = token_endpoint::handle($this->form_request($form + ['code' => $code]));
        $this->assertSame(400, $response->status);
        $code = code_service::create($request, (int) $this->teacher->id, 'teacher', ['profile', 'courses.read']);
        $response = token_endpoint::handle($this->form_request($form + ['code' => $code, 'pc_actor_id' => 'copilot-cowork']));
        $this->assertSame(200, $response->status);
        $this->assertSame('copilot-cowork', json_decode($response->body, true)['pc_actor_id']);
    }

    /**
     * Asserts that a callback fails with invalid_grant.
     *
     * @param callable $callback Code to run.
     */
    private function expect_invalid_grant(callable $callback): void {
        try {
            $callback();
            $this->fail('invalid_grant expected');
        } catch (oauth_exception $e) {
            $this->assertSame('invalid_grant', $e->error);
        }
    }

    /**
     * M2: an assignment in separate groups limits a teacher to their groups even when the course has no group mode.
     */
    public function test_m2_group_mode_of_the_activity_is_honoured(): void {
        $marker = $this->marker_without('moodle/site:accessallgroups');
        $generator = $this->getDataGenerator();
        $one = $generator->create_group(['courseid' => $this->course->id]);
        $two = $generator->create_group(['courseid' => $this->course->id]);
        groups_add_member($one->id, $marker->id);
        groups_add_member($one->id, $this->student->id);
        groups_add_member($two->id, $this->other->id);
        [$module, $submissions] = $this->assignment(
            ['name' => 'Grouped essay', 'groupmode' => SEPARATEGROUPS],
            [$this->student, $this->other]
        );
        $this->assertEquals(NOGROUPS, $this->course->groupmode);

        $data = $this->data($marker, 'list_submissions_to_grade', ['course_id' => (int) $this->course->id]);
        $this->assertSame([$submissions[$this->student->id]], array_column($data['submissions'], 'submission_id'));
        $this->data($marker, 'get_submission', ['submission_id' => $submissions[$this->student->id]]);
        $this->assertSame('not_found', $this->error(
            $marker,
            'get_submission',
            ['submission_id' => $submissions[$this->other->id]]
        )[0]);
        $this->assertSame('not_found', $this->error($marker, 'save_assignment_grade', [
            'assignment_id' => (int) $module->cmid, 'user_id' => (int) $this->other->id, 'grade' => 50,
        ])[0]);
        // The teacher with access to all groups still sees both.
        $data = $this->data($this->teacher, 'list_submissions_to_grade', ['course_id' => (int) $this->course->id]);
        $this->assertCount(2, $data['submissions']);
    }

    /**
     * M3: with marker allocation, a marker reads and grades only the students allocated to them,
     * on the storage of either Moodle version.
     */
    public function test_m3_marker_allocation_limits_reading_and_grading(): void {
        $marker = $this->marker_without('mod/assign:manageallocations');
        [$module, $submissions] = $this->assignment(
            ['name' => 'Allocated essay', 'markingworkflow' => 1, 'markingallocation' => 1],
            [$this->student, $this->other]
        );
        $this->allocate($module, $this->student, $marker);
        $this->allocate($module, $this->other, $this->teacher);

        $data = $this->data($marker, 'list_submissions_to_grade', ['course_id' => (int) $this->course->id]);
        $this->assertSame([$submissions[$this->student->id]], array_column($data['submissions'], 'submission_id'));
        $this->assertSame('not_found', $this->error(
            $marker,
            'get_submission',
            ['submission_id' => $submissions[$this->other->id]]
        )[0]);
        $grade = ['assignment_id' => (int) $module->cmid, 'grade' => 50];
        $this->assertSame('not_found', $this->error(
            $marker,
            'save_assignment_grade',
            $grade + ['user_id' => (int) $this->other->id]
        )[0]);
        // The allocated student can be graded: the check works on this version of the assignment tables.
        [$result] = $this->run_tool($marker, 'save_assignment_grade', $grade + ['user_id' => (int) $this->student->id]);
        $this->assertSame('confirmation_required', $result->envelope['status'] ?? null, json_encode($result->envelope));
    }

    /**
     * M4 and M5: hidden text of a course summary does not come out of the search, and a text that
     * carries its own style sheet is not returned at all.
     */
    public function test_m4_m5_hidden_text_in_summaries_and_style_sheets(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $generator->create_course(['fullname' => 'Searchable alpha', 'summaryformat' => FORMAT_HTML,
            'summary' => '<p>Open to all.</p><span style="display:none">HIDDEN-INLINE</span>'
                . '<span style="position:absolute;left:-9999px">HIDDEN-OFFSCREEN</span>']);
        $generator->create_course(['fullname' => 'Searchable beta', 'summaryformat' => FORMAT_HTML,
            'summary' => '<style>.zz{display:none}</style><p>Shown</p><span class="zz">HIDDEN-BLOCK</span>']);
        $this->setUser(null);

        // Searched one by one: a style sheet in one summary withholds every summary listed with it.
        $data = $this->data($this->student, 'search_courses', ['search_text' => 'Searchable alpha'], 'student');
        $this->assertSame('Open to all.', $data['courses'][0]['summary_user_content']);
        $this->assertStringNotContainsString('HIDDEN', json_encode($data));
        $data = $this->data($this->student, 'search_courses', ['search_text' => 'Searchable beta'], 'student');
        $this->assertSame(text_sanitizer::STYLESHEET_NOTE, $data['courses'][0]['summary_user_content']);
        $this->assertStringNotContainsString('HIDDEN', json_encode($data));

        // A style sheet can hide by tag, attribute or position as well as by class: nothing is returned.
        foreach (
            [
                '<style>span{display:none}</style>Seen<span>HIDDEN</span>',
                'Seen<STYLE type="text/css">p > b{font-size:0}</STYLE><p><b>HIDDEN</b></p>',
                'Seen<link rel="stylesheet" href="https://example.com/x.css"><i id="a">HIDDEN</i>',
                'Seen<svg><style>*{opacity:0}</style></svg><u>HIDDEN</u>',
            ] as $html
        ) {
            $this->assertSame(text_sanitizer::STYLESHEET_NOTE, text_sanitizer::to_plain($html));
            $this->assertSame('', text_sanitizer::strip_hidden($html));
        }
        // Text that only talks about the element is not affected.
        $this->assertSame('Use the <style> element.', text_sanitizer::to_plain('<p>Use the &lt;style&gt; element.</p>'));
    }

    /**
     * M6, M7 and L5: grading never touches the marking workflow state, shows the feedback in full
     * and refuses a locked grade instead of reporting success.
     */
    public function test_m6_m7_l5_grading_keeps_the_workflow_and_shows_the_feedback(): void {
        global $DB;
        [$module] = $this->assignment(['name' => 'Workflow essay', 'markingworkflow' => 1], [$this->student]);
        $args = ['assignment_id' => (int) $module->cmid, 'user_id' => (int) $this->student->id];

        // Not released yet: the summary says the student will not see the grade.
        $summary = $this->apply($this->teacher, 'save_assignment_grade', $args + ['grade' => 50]);
        $this->assertStringContainsString('marking workflow', $summary);
        $flags = $DB->get_record('assign_user_flags', ['assignment' => $module->id, 'userid' => $this->student->id]);
        if ($flags) {
            $DB->set_field('assign_user_flags', 'workflowstate', ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, ['id' => $flags->id]);
        } else {
            $DB->insert_record('assign_user_flags', (object) [
                'assignment' => $module->id, 'userid' => $this->student->id, 'locked' => 0, 'mailed' => 0,
                'extensionduedate' => 0, 'workflowstate' => ASSIGN_MARKING_WORKFLOW_STATE_RELEASED,
            ]);
        }

        $feedback = 'Well argued. See my notes on page 2.';
        if (method_exists(\assign::class, 'grading_locked')) {
            // From Moodle 5.1 a released grade is read-only: core would skip it and report success. It is refused.
            $this->assertSame('conflict', $this->error($this->teacher, 'save_assignment_grade', $args + ['grade' => 60])[0]);
            $this->assertEquals(50, $DB->get_field('assign_grades', 'grade', ['assignment' => $module->id]));
            $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, $DB->get_field(
                'assign_user_flags',
                'workflowstate',
                ['assignment' => $module->id, 'userid' => $this->student->id]
            ));
            // Without marking workflow the feedback is shown in full and a locked grade is refused.
            [$module] = $this->assignment(['name' => 'Plain essay'], [$this->student]);
            $args = ['assignment_id' => (int) $module->cmid, 'user_id' => (int) $this->student->id];
            $summary = $this->apply($this->teacher, 'save_assignment_grade', $args + ['grade' => 60, 'feedback' => $feedback]);
            $this->assertStringContainsString($feedback, $summary);
            $this->lock_and_refuse($module, $args);
            return;
        }

        // Released: a new grade stays released and reaches the gradebook, and the feedback is in the summary.
        $summary = $this->apply($this->teacher, 'save_assignment_grade', $args + ['grade' => 60, 'feedback' => $feedback]);
        $this->assertStringContainsString($feedback, $summary);
        $this->assertStringNotContainsString('marking workflow', $summary);
        $this->assertSame(ASSIGN_MARKING_WORKFLOW_STATE_RELEASED, $DB->get_field(
            'assign_user_flags',
            'workflowstate',
            ['assignment' => $module->id, 'userid' => $this->student->id]
        ));
        $grades = grade_get_grades($this->course->id, 'mod', 'assign', $module->id, [$this->student->id]);
        $this->assertEquals(60, $grades->items[0]->grades[$this->student->id]->grade);

        $this->lock_and_refuse($module, $args);
    }

    /**
     * Locks the grade of the student in the gradebook and checks that a new grade is refused and changes nothing.
     *
     * @param \stdClass $module Assignment module record, whose student currently has 60.
     * @param array $args Arguments naming the assignment and the student.
     */
    private function lock_and_refuse(\stdClass $module, array $args): void {
        global $DB;
        $item = \grade_item::fetch(['itemtype' => 'mod', 'itemmodule' => 'assign', 'iteminstance' => $module->id,
            'courseid' => $this->course->id]);
        $locked = \grade_grade::fetch(['itemid' => $item->id, 'userid' => $this->student->id]);
        $locked->set_locked(1);
        $this->assertSame('conflict', $this->error($this->teacher, 'save_assignment_grade', $args + ['grade' => 70])[0]);
        $this->assertEquals(60, $DB->get_field('assign_grades', 'grade', ['assignment' => $module->id]));
    }

    /**
     * L1: enrolling again does not reactivate a suspended or time-limited manual enrolment, and summaries carry user ids.
     */
    public function test_l1_enrolment_is_not_silently_reactivated(): void {
        global $DB;
        $this->setAdminUser();
        $outsider = $this->getDataGenerator()->create_user(['firstname' => 'Olga', 'lastname' => 'Outside']);
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual = enrol_get_plugin('manual');
        $manual->update_user_enrol($instance, $this->other->id, ENROL_USER_SUSPENDED);
        $manual->update_user_enrol($instance, $this->student->id, ENROL_USER_ACTIVE, 0, time() + WEEKSECS);
        $this->setUser(null);

        $args = ['course_id' => (int) $this->course->id];
        foreach ([$this->other, $this->student] as $user) {
            [$code, $message] = $this->error(
                $this->manager,
                'enrol_users_in_course',
                $args + ['user_ids' => [(int) $outsider->id, (int) $user->id]],
                'manager'
            );
            $this->assertSame('validation_error', $code);
            $this->assertStringContainsString("id {$user->id}", $message);
            $this->assertStringNotContainsString($user->lastname, $message);
        }
        $this->assertEquals(ENROL_USER_SUSPENDED, $DB->get_field('user_enrolments', 'status', [
            'enrolid' => $instance->id, 'userid' => $this->other->id,
        ]));
        $args['user_ids'] = [(int) $outsider->id];
        [$result] = $this->run_tool($this->manager, 'enrol_users_in_course', $args, 'manager');
        $this->assertStringContainsString("Olga Outside (id {$outsider->id})", $result->envelope['data']['summary']);
    }

    /**
     * L2: tidying expired actions never rewrites an action that is no longer pending.
     */
    public function test_l2_tidy_leaves_resolved_actions_alone(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', scope::max_for_profile('teacher'));
        [, $applied] = confirmation_service::request($grant, 'set_activity_visibility', ['a' => 1], 'hash-a', 'Summary A');
        [, $waiting] = confirmation_service::request($grant, 'set_activity_visibility', ['a' => 2], 'hash-b', 'Summary B');
        // The first one was applied in its last second; both are now past their time.
        action_store::resolve((int) $applied->id, 'executed', ['status' => 'ok']);
        $DB->set_field(action_store::TABLE, 'timeexpires', time() - 10, []);
        action_store::tidy($grant->grantid);
        $applied = $DB->get_record(action_store::TABLE, ['id' => $applied->id]);
        $this->assertSame('executed', $applied->status);
        $this->assertNotNull($applied->tokenhash);
        $this->assertNotNull($applied->result);
        $waiting = $DB->get_record(action_store::TABLE, ['id' => $waiting->id]);
        $this->assertSame('expired', $waiting->status);
        $this->assertNull($waiting->args);
        $this->assertNull($waiting->tokenhash);
    }

    /**
     * L3: a token confirms the change that was summarised. If the data moved, nothing is applied
     * and the caller gets the current summary with a new token.
     */
    public function test_l3_confirmation_is_checked_against_a_fresh_summary(): void {
        global $DB;
        [$module] = $this->assignment(['name' => 'Moving essay'], [$this->student]);
        $args = ['assignment_id' => (int) $module->cmid, 'user_id' => (int) $this->student->id, 'grade' => 80];
        [$first, $grant] = $this->run_tool($this->teacher, 'save_assignment_grade', $args);
        $this->assertStringContainsString('Current grade: none', $first->envelope['data']['summary']);
        $oldtoken = $first->envelope['data']['confirm_token'];

        // Somebody grades the student on the site meanwhile.
        $this->apply($this->teacher, 'save_assignment_grade', ['grade' => 55] + $args);
        $this->assertEquals(55, $DB->get_field('assign_grades', 'grade', ['assignment' => $module->id]));

        $tool = 'save_assignment_grade';
        [$second] = $this->run_tool($this->teacher, $tool, $args + ['confirm_token' => $oldtoken], 'teacher', $grant);
        $this->assertSame('confirmation_required', $second->envelope['status'] ?? null, json_encode($second->envelope));
        $this->assertStringContainsString('Current grade: 55', $second->envelope['data']['summary']);
        $newtoken = $second->envelope['data']['confirm_token'];
        $this->assertNotSame($oldtoken, $newtoken);
        $this->assertEquals(55, $DB->get_field('assign_grades', 'grade', ['assignment' => $module->id]));

        // The old token is dead; the new one applies the change that was just described.
        [$dead] = $this->run_tool($this->teacher, $tool, $args + ['confirm_token' => $oldtoken], 'teacher', $grant);
        $this->assertSame('validation_error', $dead->error_code());
        [$done] = $this->run_tool($this->teacher, $tool, $args + ['confirm_token' => $newtoken], 'teacher', $grant);
        $this->assertSame('ok', $done->envelope['status'] ?? null, json_encode($done->envelope));
        $this->assertEquals(80, $DB->get_field('assign_grades', 'grade', ['assignment' => $module->id]));
    }

    /**
     * L4: the redirect address shared by every Microsoft 365 Copilot plugin is refused in direct mode
     * whatever the administrator lists, and cannot be saved in the list.
     */
    public function test_l4_shared_teams_redirect_is_never_registrable(): void {
        $spellings = [
            urls::TEAMS_REDIRECT,
            'https://TEAMS.microsoft.com/api/platform/v1.0/oAuthRedirect',
            'https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect/',
            'https://teams.microsoft.com/api/platform/v1.0/oauthredirect?x=1',
            'https://teams.microsoft.com/api/platform/v1.0/oAuth%52edirect',
        ];
        set_config('allowedredirects', implode("\n", $spellings) . "\nhttps://example.org/callback", 'local_pluginiaconnect');
        foreach ($spellings as $uri) {
            $this->assertFalse(redirect_policy::is_registrable($uri), $uri);
            $this->assertFalse(setting_list::is_valid_entry(setting_list::KIND_REDIRECTS, $uri), $uri);
        }
        $this->assertTrue(redirect_policy::is_registrable('https://example.org/callback'));
        $this->assertTrue(setting_list::is_valid_entry(setting_list::KIND_REDIRECTS, 'https://teams.microsoft.com/other'));
    }

    /**
     * L6: an activity that is available but not shown on the course page is not listed in the progress of a student.
     */
    public function test_l6_progress_does_not_list_stealth_activities(): void {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $this->setAdminUser();
        set_config('allowstealth', 1);
        $generator = $this->getDataGenerator();
        $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Shown page',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $stealth = $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Stealth page',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        set_coursemodule_visible($stealth->cmid, 1, 0);
        rebuild_course_cache($this->course->id, true);
        $this->setUser(null);

        $data = $this->data($this->student, 'get_my_course_progress', ['course_id' => (int) $this->course->id], 'student');
        $this->assertSame(['Shown page'], array_column($data['activities'], 'name'));
    }

    /**
     * L7: under anonymous marking the rows follow the participant numbers, not the names.
     */
    public function test_l7_blind_marking_order_does_not_follow_names(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $students = [$this->student, $this->other];
        foreach (['Carla', 'Dario', 'Elsa', 'Fidel'] as $name) {
            $students[] = $generator->create_and_enrol($this->course, 'student', ['firstname' => $name, 'lastname' => $name]);
        }
        $this->assignment(['name' => 'Anonymous essay', 'blindmarking' => 1], $students);
        $data = $this->data($this->teacher, 'list_submissions_to_grade', ['course_id' => (int) $this->course->id]);
        $numbers = [];
        foreach ($data['submissions'] as $row) {
            $this->assertTrue($row['student']['anonymous']);
            $numbers[] = (int) preg_replace('/\D/', '', $row['student']['full_name']);
        }
        $this->assertCount(6, $numbers);
        $sorted = $numbers;
        sort($sorted);
        $this->assertSame($sorted, $numbers);
    }

    /**
     * L8: the people named in a summary count against the hourly budget of records about other people.
     */
    public function test_l8_previews_are_charged_to_the_exposure_budget(): void {
        $this->setAdminUser();
        $outsider = $this->getDataGenerator()->create_user();
        $this->setUser(null);
        [$result, $grant] = $this->run_tool($this->manager, 'enrol_users_in_course', [
            'course_id' => (int) $this->course->id, 'user_ids' => [(int) $outsider->id],
        ], 'manager');
        $this->assertSame('confirmation_required', $result->envelope['status'] ?? null);
        $this->assertSame(1, exposure_budget::used($grant->grantid));
        [$result] = $this->run_tool($this->manager, 'send_message_to_participants', [
            'course_id' => (int) $this->course->id, 'user_ids' => [(int) $this->student->id, (int) $this->other->id],
            'message' => 'Hello',
        ], 'manager', $grant);
        $this->assertSame(3, exposure_budget::used($grant->grantid));
    }

    /**
     * L9: an activity or submission of a course the user cannot enter does not reveal which course holds it.
     */
    public function test_l9_errors_do_not_map_activities_to_courses(): void {
        $this->setAdminUser();
        $elsewhere = $this->getDataGenerator()->create_course();
        $student = $this->getDataGenerator()->create_and_enrol($elsewhere, 'student');
        [$module, $submissions] = $this->assignment(['name' => 'Private essay'], [$student], $elsewhere);

        [$code, $message] = $this->error($this->teacher, 'get_submission', ['submission_id' => $submissions[$student->id]]);
        $this->assertSame('not_found', $code);
        $this->assertStringNotContainsString((string) $elsewhere->id, $message);
        $this->assertStringNotContainsString('Course', $message);
        [$code, $message] = $this->error($this->teacher, 'save_assignment_grade', [
            'assignment_id' => (int) $module->cmid, 'user_id' => (int) $student->id, 'grade' => 5,
        ]);
        $this->assertSame('not_found', $code);
        $this->assertStringNotContainsString('Course', $message);
        // A call that named the course still gets it back.
        [$code, $message] = $this->error($this->teacher, 'get_course_outline', ['course_id' => (int) $elsewhere->id]);
        $this->assertSame('not_found', $code);
        $this->assertStringContainsString("Course {$elsewhere->id}", $message);
    }

    /**
     * I1: every write tool declares how it is confirmed, and none can ever have the mode "none".
     */
    public function test_i1_every_write_tool_has_an_approval_mode(): void {
        $writes = 0;
        foreach (tool_registry::all() as $name => $class) {
            $requirement = $class::requirement();
            if (!$requirement->write) {
                continue;
            }
            $writes++;
            $this->assertNotSame(tool_requirement::APPROVAL_NONE, $requirement->approval, $name);
            foreach ([null, 'claude', site_policy::ACTOR_COWORK] as $actor) {
                $this->assertContains(
                    site_policy::approval_mode($name, $actor),
                    [tool_requirement::APPROVAL_CONFIRM, tool_requirement::APPROVAL_APPROVAL],
                    $name
                );
            }
        }
        $this->assertSame(9, $writes);
    }

    /**
     * I3: the opening and due dates cannot be moved past the "remind me to grade by" date, as in the settings form.
     */
    public function test_i3_dates_respect_the_grading_due_date(): void {
        global $DB;
        [$module] = $this->assignment([
            'name' => 'Dated essay', 'allowsubmissionsfromdate' => time() - DAYSECS, 'duedate' => time() + 5 * DAYSECS,
            'gradingduedate' => time() + 10 * DAYSECS, 'cutoffdate' => 0,
        ], []);
        $args = ['assignment_id' => (int) $module->cmid];
        [$code, $message] = $this->error($this->teacher, 'update_assignment_dates', $args + [
            'due_date' => gmdate('Y-m-d\TH:i:s\Z', time() + 20 * DAYSECS),
        ]);
        $this->assertSame('validation_error', $code);
        $this->assertStringContainsString('Remind me to grade by', $message);
        $this->apply($this->teacher, 'update_assignment_dates', $args + [
            'due_date' => gmdate('Y-m-d\TH:i:s\Z', time() + 8 * DAYSECS),
        ]);
        $this->assertGreaterThan(time() + 7 * DAYSECS, (int) $DB->get_field('assign', 'duedate', ['id' => $module->id]));
    }
}
