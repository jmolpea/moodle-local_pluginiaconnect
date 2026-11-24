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

use local_pluginiaconnect\local\guardrail\exposure_budget;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\schema_validator;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->libdir . '/gradelib.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\people::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\assign_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\exposure_budget::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
/**
 * The read tools of phase 2, run through the whole pipeline with real users, roles, groups and courses.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\tool\people
 * @covers     \local_pluginiaconnect\local\tool\assign_helper
 * @covers     \local_pluginiaconnect\local\guardrail\exposure_budget
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 */
final class mcp_read_tools_test extends \advanced_testcase {
    /** @var string[] Every read scope. */
    private const SCOPES = [
        'profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read', 'users.read', 'site.read',
    ];
    /** @var string Hidden text that must never leave the site. */
    private const HIDDEN = '<span style="display:none">HIDDEN-INSTRUCTION</span>';

    /** @var \stdClass Category of the main course. */
    private \core_course_category $category;
    /** @var \stdClass Main course. */
    private \stdClass $course;
    /** @var \stdClass Course with separate groups. */
    private \stdClass $grouped;
    /** @var \stdClass First student of the main course. */
    private \stdClass $student;
    /** @var \stdClass Second student of the main course. */
    private \stdClass $other;
    /** @var \stdClass Editing teacher of both courses. */
    private \stdClass $teacher;
    /** @var \stdClass Non-editing teacher of the grouped course, member of group one only. */
    private \stdClass $groupteacher;
    /** @var \stdClass Student of group one. */
    private \stdClass $groupone;
    /** @var \stdClass Student of group two. */
    private \stdClass $grouptwo;
    /** @var \stdClass Assignment of the main course. */
    private \stdClass $assign;
    /** @var \stdClass Assignment with anonymous marking. */
    private \stdClass $blind;
    /** @var \stdClass Assignment of the grouped course. */
    private \stdClass $groupassign;

    /**
     * Builds two courses, their people and their work.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $assigngenerator = $generator->get_plugin_generator('mod_assign');

        $this->category = $generator->create_category(['name' => 'Science']);
        $this->course = $generator->create_course([
            'fullname' => 'Biology 101', 'shortname' => 'BIO101', 'category' => $this->category->id, 'enablecompletion' => 1,
            'summary' => 'About cells.',
        ]);
        $this->student = $generator->create_and_enrol($this->course, 'student', [
            'firstname' => 'Ana', 'lastname' => 'Alvarez', 'idnumber' => 'USER-IDNUMBER', 'email' => 'ana.alvarez@example.com',
        ]);
        $this->other = $generator->create_and_enrol($this->course, 'student', [
            'firstname' => 'Bruno', 'lastname' => 'Bermejo', 'email' => 'bruno.bermejo@example.com',
        ]);
        $this->teacher = $generator->create_and_enrol(
            $this->course,
            'editingteacher',
            ['firstname' => 'Tina', 'lastname' => 'Teach']
        );

        $submissions = ['submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 1,
            'assignsubmission_file_maxfiles' => 2, 'assignsubmission_file_maxsizebytes' => 1024 * 1024];
        $this->assign = $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Essay 2', 'duedate' => time() - DAYSECS,
            'intro' => 'Write the essay.' . self::HIDDEN, 'grade' => 100, 'completion' => COMPLETION_TRACKING_MANUAL,
        ] + $submissions);
        $this->blind = $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Anonymous essay', 'blindmarking' => 1, 'grade' => 100,
        ] + $submissions);
        foreach ([$this->assign, $this->blind] as $assign) {
            $assigngenerator->create_submission([
                'userid' => $this->student->id, 'cmid' => $assign->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'onlinetext' => '<p>My answer.</p>' . self::HIDDEN, 'file' => 'mod/assign/tests/fixtures/submissionsample01.txt',
            ]);
        }
        $this->setAdminUser();
        $generator->create_module('page', [
            'course' => $this->course->id, 'name' => 'Reading', 'completion' => COMPLETION_TRACKING_MANUAL,
        ]);

        // News forum with one announcement, and an ordinary forum that must not be read.
        $news = $generator->create_module('forum', ['course' => $this->course->id, 'type' => 'news', 'name' => 'Announcements']);
        $general = $generator->create_module('forum', ['course' => $this->course->id, 'type' => 'general', 'name' => 'Chat']);
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $forumgenerator->create_discussion([
            'course' => $this->course->id, 'forum' => $news->id, 'userid' => $this->teacher->id, 'name' => 'Exam moved',
            'message' => '<p>The exam is on Friday.</p>',
        ]);
        $forumgenerator->create_discussion([
            'course' => $this->course->id, 'forum' => $general->id, 'userid' => $this->other->id, 'name' => 'PRIVATE-CHAT',
        ]);

        $item = \grade_item::fetch(['id' => $generator->create_grade_item([
            'courseid' => $this->course->id, 'itemname' => 'Quiz one', 'grademax' => 100,
        ])->id]);
        $item->update_final_grade($this->student->id, 80, 'test');
        $item->update_final_grade($this->other->id, 55, 'test');
        grade_regrade_final_grades($this->course->id);

        // Course with separate groups: the non-editing teacher belongs to group one only.
        $this->grouped = $generator->create_course(['fullname' => 'Chemistry 201', 'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1, 'enablecompletion' => 1]);
        $this->groupteacher = $generator->create_and_enrol($this->grouped, 'teacher');
        $this->groupone = $generator->create_and_enrol($this->grouped, 'student', ['firstname' => 'Gala', 'lastname' => 'Uno']);
        $this->grouptwo = $generator->create_and_enrol($this->grouped, 'student', ['firstname' => 'Hugo', 'lastname' => 'Dos']);
        $generator->enrol_user($this->teacher->id, $this->grouped->id, 'editingteacher');
        $one = $generator->create_group(['courseid' => $this->grouped->id, 'name' => 'Group one']);
        $two = $generator->create_group(['courseid' => $this->grouped->id, 'name' => 'Group two']);
        groups_add_member($one->id, $this->groupteacher->id);
        groups_add_member($one->id, $this->groupone->id);
        groups_add_member($two->id, $this->grouptwo->id);
        $this->groupassign = $generator->create_module('assign', ['course' => $this->grouped->id, 'name' => 'Lab report',
            'grade' => 100] + $submissions);
        foreach ([$this->groupone, $this->grouptwo] as $user) {
            $assigngenerator->create_submission([
                'userid' => $user->id, 'cmid' => $this->groupassign->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'onlinetext' => 'Report of ' . $user->firstname,
            ]);
        }
        $this->setAdminUser();
        $groupitem = \grade_item::fetch(['id' => $generator->create_grade_item([
            'courseid' => $this->grouped->id, 'itemname' => 'Lab mark', 'grademax' => 10,
        ])->id]);
        $groupitem->update_final_grade($this->groupone->id, 7, 'test');
        $groupitem->update_final_grade($this->grouptwo->id, 3, 'test');
        grade_regrade_final_grades($this->grouped->id);
        $DB->insert_record('user_lastaccess', ['userid' => $this->groupone->id, 'courseid' => $this->grouped->id,
            'timeaccess' => time() - 40 * DAYSECS]);

        \local_pluginiaconnect_mcp_fixture::enable();
        $this->setUser(null);
    }

    /**
     * Runs a tool through the pipeline as a user with a fresh connection.
     *
     * @param \stdClass $user User the call runs as.
     * @param string $tool Tool name.
     * @param mixed $args Arguments.
     * @param string $profile Profile of the connection.
     * @param array $scopes Scopes of the connection.
     * @param mixed $grant Reuse this grant instead of creating one.
     * @return \local_pluginiaconnect\local\mcp\pipeline_result
     */
    private function run_tool(
        \stdClass $user,
        string $tool,
        $args = [],
        string $profile = 'teacher',
        array $scopes = self::SCOPES,
        $grant = null
    ) {
        if ($grant === null) {
            [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, $scopes);
        }
        $loaded = user_session::load($grant);
        $this->assertNotNull($loaded);
        $result = call_pipeline::run($grant, $loaded, $tool, $args, \core\uuid::generate(), '192.0.2.7');
        // What the tool returned, whatever it was, matches the output schema the gateway enforces.
        \local_pluginiaconnect_output_schema_check::assert_valid($tool, $result->envelope);
        if (!$result->iserror) {
            $json = json_encode($result->envelope);
            foreach (['USER-IDNUMBER', 'HIDDEN-INSTRUCTION', 'sesskey', 'token=', 'pluginfile.php', 'PRIVATE-CHAT'] as $leak) {
                $this->assertStringNotContainsString($leak, $json, "{$tool} leaked {$leak}");
            }
        }
        return $result;
    }

    /**
     * Data of a successful call.
     *
     * @param \stdClass $user User the call runs as.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile of the connection.
     * @return array
     */
    private function data(\stdClass $user, string $tool, array $args = [], string $profile = 'teacher'): array {
        $result = $this->run_tool($user, $tool, $args, $profile);
        $this->assertFalse($result->iserror, "{$tool}: " . json_encode($result->envelope['error'] ?? null));
        return $result->envelope['data'];
    }

    /**
     * Error code of a failing call.
     *
     * @param \stdClass $user User the call runs as.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile of the connection.
     * @return string
     */
    private function code(\stdClass $user, string $tool, array $args = [], string $profile = 'teacher'): string {
        $result = $this->run_tool($user, $tool, $args, $profile);
        $this->assertTrue($result->iserror, "{$tool} should have failed");
        return $result->error_code();
    }

    /**
     * Removes a capability from every role of a user in a context.
     *
     * @param string $capability Capability name.
     * @param \context $context Context.
     * @param \stdClass $user User.
     */
    private function prohibit(string $capability, \context $context, \stdClass $user): void {
        foreach (get_user_roles($context, $user->id) as $assignment) {
            assign_capability($capability, CAP_PROHIBIT, $assignment->roleid, $context->id, true);
        }
        accesslib_clear_all_caches_for_unit_testing();
    }

    public function test_search_courses(): void {
        $generator = $this->getDataGenerator();
        $this->setAdminUser();
        $generator->create_course(['fullname' => 'Biology hidden', 'visible' => 0]);
        $excluded = $generator->create_course(['fullname' => 'Biology excluded']);
        set_config('excludedcourses', $excluded->id, 'local_pluginiaconnect');
        $this->setUser(null);

        $courses = $this->data($this->student, 'search_courses', ['search_text' => 'Biology'], 'student')['courses'];
        $this->assertSame(['Biology 101'], array_column($courses, 'name'));
        $this->assertSame('Science', $courses[0]['category_name']);
        $this->assertSame('About cells.', $courses[0]['summary_user_content']);
        $this->assertSame('validation_error', $this->code($this->student, 'search_courses', ['search_text' => 'B'], 'student'));
        $this->assertSame([], $this->data($this->student, 'search_courses', ['search_text' => 'zzzz'], 'student')['courses']);
    }

    public function test_get_assignment_details(): void {
        $args = ['assignment_id' => (int) $this->assign->cmid];
        $data = $this->data($this->student, 'get_assignment_details', $args, 'student');
        $this->assertSame('Essay 2', $data['name']);
        $this->assertSame('Write the essay.', $data['instructions_user_content']);
        $this->assertSame('submitted', $data['my_submission_status']);
        $this->assertNotNull($data['my_submission_at']);
        $this->assertSame(100.0, (float) $data['maximum_grade']);

        // Another student sees their own state, never the first student's.
        $own = $this->data($this->other, 'get_assignment_details', $args, 'student');
        $this->assertSame('none', $own['my_submission_status']);
        $this->assertNull($own['my_submission_at']);

        // Not enrolled, hidden activity, an activity of another type and a missing one all look the same.
        $outsider = $this->getDataGenerator()->create_user();
        $this->assertSame('not_found', $this->code($outsider, 'get_assignment_details', $args, 'student'));
        $this->setAdminUser();
        $page = $this->getDataGenerator()->create_module('page', ['course' => $this->course->id]);
        set_coursemodule_visible($this->blind->cmid, 0);
        $this->setUser(null);
        foreach ([(int) $this->blind->cmid, (int) $page->cmid, 99999999] as $id) {
            $this->assertSame(
                'not_found',
                $this->code($this->student, 'get_assignment_details', ['assignment_id' => $id], 'student')
            );
        }
    }

    public function test_instructions_stay_hidden_until_the_opening_date(): void {
        global $DB;
        $DB->set_field('assign', 'allowsubmissionsfromdate', time() + DAYSECS, ['id' => $this->assign->id]);
        $DB->set_field('assign', 'alwaysshowdescription', 0, ['id' => $this->assign->id]);
        $args = ['assignment_id' => (int) $this->assign->cmid];
        $this->assertSame('', $this->data($this->student, 'get_assignment_details', $args, 'student')['instructions_user_content']);
        $this->assertSame(
            'Write the essay.',
            $this->data($this->teacher, 'get_assignment_details', $args)['instructions_user_content']
        );
    }

    public function test_list_course_announcements(): void {
        $args = ['course_id' => (int) $this->course->id];
        $data = $this->data($this->student, 'list_course_announcements', $args, 'student');
        $this->assertCount(1, $data['announcements']);
        $this->assertSame('Exam moved', $data['announcements'][0]['subject_user_content']);
        $this->assertSame('The exam is on Friday.', $data['announcements'][0]['message_user_content']);
        $this->assertSame(fullname($this->teacher), $data['announcements'][0]['author_name']);
        $this->assertStringContainsString('/mod/forum/discuss.php?d=', $data['announcements'][0]['url']);

        // A course without a news forum returns an empty list and does not create one.
        global $DB;
        $before = $DB->count_records('forum');
        $this->assertSame(
            [],
            $this->data($this->teacher, 'list_course_announcements', ['course_id' => (int) $this->grouped->id])['announcements']
        );
        $this->assertSame($before, $DB->count_records('forum'));
    }

    public function test_get_my_course_progress(): void {
        $this->setAdminUser();
        $completion = new \completion_info($this->course);
        $cm = get_fast_modinfo($this->course)->get_cm($this->assign->cmid);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->student->id);
        $completion->update_state($cm, COMPLETION_COMPLETE, $this->other->id);
        $this->setUser(null);

        $data = $this->data($this->student, 'get_my_course_progress', ['course_id' => (int) $this->course->id], 'student');
        $this->assertTrue($data['completion_enabled']);
        $states = array_column($data['activities'], 'state', 'name');
        $this->assertSame(['Essay 2' => 'complete', 'Reading' => 'incomplete'], $states);
        $this->assertSame(50.0, (float) $data['progress_percent']);
        $this->assertFalse($data['course_completed']);

        // A teacher is not tracked: nothing about anybody else is returned instead.
        $teacher = $this->data($this->teacher, 'get_my_course_progress', ['course_id' => (int) $this->course->id]);
        $this->assertSame([], $teacher['activities']);
        $this->assertNull($teacher['progress_percent']);
    }

    public function test_list_course_participants_and_identity_rules(): void {
        global $CFG, $DB;
        $args = ['course_id' => (int) $this->course->id];
        $people = array_column($this->data($this->teacher, 'list_course_participants', $args)['participants'], null, 'full_name');
        $this->assertSame(['Ana Alvarez', 'Bruno Bermejo', 'Tina Teach'], array_keys($people));
        $ana = $people['Ana Alvarez'];
        $this->assertSame((int) $this->student->id, $ana['user_id']);
        $this->assertSame('ana.alvarez@example.com', $ana['email']);
        $this->assertSame(['Student'], $ana['roles']);
        $this->assertSame([], $ana['custom_fields']);
        $this->assertArrayNotHasKey('idnumber', $ana);
        $this->assertStringContainsString('/user/view.php', $ana['url']);

        // E-mail needs both the site setting of the plugin and what Moodle shows to this user.
        set_config('showemails', 'manager', 'local_pluginiaconnect');
        $this->assertNull($this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['email']);
        set_config('showemails', 'none', 'local_pluginiaconnect');
        $this->assertNull($this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['email']);
        set_config('showemails', 'teacher', 'local_pluginiaconnect');
        $CFG->showuseridentity = 'idnumber';
        $this->assertNull($this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['email']);
        $CFG->showuseridentity = 'email';
        $this->prohibit('moodle/site:viewuseridentity', \context_course::instance($this->course->id), $this->teacher);
        $this->assertNull($this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['email']);
    }

    public function test_custom_profile_fields_and_last_access(): void {
        global $CFG, $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $generator->create_custom_profile_field(['shortname' => 'dept', 'name' => 'Department', 'datatype' => 'text']);
        $generator->create_custom_profile_field(['shortname' => 'nif', 'name' => 'Tax number', 'datatype' => 'text']);
        profile_save_custom_fields($this->student->id, ['dept' => 'Botany', 'nif' => 'NIF-SECRET-1']);
        $DB->insert_record('user_lastaccess', ['userid' => $this->student->id, 'courseid' => $this->course->id,
            'timeaccess' => time() - 3 * DAYSECS]);
        $this->setUser(null);
        $args = ['course_id' => (int) $this->course->id];

        $first = $this->data($this->teacher, 'list_course_participants', $args)['participants'][0];
        $this->assertSame([], $first['custom_fields']);
        $this->assertNotNull($first['last_course_access']);

        set_config('profilefields', 'dept', 'local_pluginiaconnect');
        $result = $this->run_tool($this->teacher, 'list_course_participants', $args);
        $this->assertSame(
            [['name' => 'Department', 'value' => 'Botany']],
            $result->envelope['data']['participants'][0]['custom_fields']
        );
        $this->assertStringNotContainsString('NIF-SECRET', json_encode($result->envelope));

        // Last access is a hidden user field when the site says so and the user lacks the capability.
        $CFG->hiddenuserfields = 'lastaccess';
        $this->assertNotNull(
            $this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['last_course_access']
        );
        $this->prohibit('moodle/course:viewhiddenuserfields', \context_course::instance($this->course->id), $this->teacher);
        $this->assertNull($this->data($this->teacher, 'list_course_participants', $args)['participants'][0]['last_course_access']);
        $this->assertSame('permission_denied', $this->code($this->teacher, 'list_inactive_participants', $args));
    }

    public function test_suspended_enrolments_follow_the_participants_page(): void {
        global $DB;
        $this->setAdminUser();
        $enrol = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $DB->set_field('user_enrolments', 'status', ENROL_USER_SUSPENDED, ['enrolid' => $enrol->id, 'userid' => $this->other->id]);
        $this->setUser(null);
        $args = ['course_id' => (int) $this->course->id];
        $names = array_column($this->data($this->teacher, 'list_course_participants', $args)['participants'], 'full_name');
        $this->assertContains('Bruno Bermejo', $names);
        $this->prohibit('moodle/course:viewsuspendedusers', \context_course::instance($this->course->id), $this->teacher);
        $names = array_column($this->data($this->teacher, 'list_course_participants', $args)['participants'], 'full_name');
        $this->assertNotContains('Bruno Bermejo', $names);
    }

    public function test_separate_groups_limit_every_teacher_tool(): void {
        $args = ['course_id' => (int) $this->grouped->id];
        $hidden = fullname($this->grouptwo);
        foreach (
            ['list_course_participants' => 'participants', 'get_course_grade_summary' => 'participants',
                'get_course_completion_report' => 'participants', 'list_inactive_participants' => 'participants'] as $tool => $key
        ) {
            $names = array_column($this->data($this->groupteacher, $tool, $args)[$key], 'full_name');
            $this->assertContains(fullname($this->groupone), $names, $tool);
            $this->assertNotContains($hidden, $names, $tool);
            $all = array_column($this->data($this->teacher, $tool, $args)[$key], 'full_name');
            $this->assertContains($hidden, $all, "{$tool} for a teacher who may access all groups");
        }
        $groups = array_column($this->data($this->groupteacher, 'list_course_participants', $args)['participants'], 'groups');
        $this->assertNotContains(['Group two'], $groups);

        $mine = $this->data($this->groupteacher, 'list_submissions_to_grade', $args)['submissions'];
        $this->assertSame([fullname($this->groupone)], array_column(array_column($mine, 'student'), 'full_name'));
        $all = $this->data($this->teacher, 'list_submissions_to_grade', $args)['submissions'];
        $this->assertCount(2, $all);
        $foreign = null;
        foreach ($all as $row) {
            $foreign = $row['student']['full_name'] === $hidden ? $row['submission_id'] : $foreign;
        }
        $this->assertNotNull($foreign);
        $this->assertSame('not_found', $this->code($this->groupteacher, 'get_submission', ['submission_id' => $foreign]));
        $this->assertSame(
            $hidden,
            $this->data($this->teacher, 'get_submission', ['submission_id' => $foreign])['student']['full_name']
        );

        // A teacher who belongs to no group sees nobody.
        $loner = $this->getDataGenerator()->create_and_enrol($this->grouped, 'teacher');
        $this->assertSame([], $this->data($loner, 'list_course_participants', $args)['participants']);
        $this->assertSame([], $this->data($loner, 'list_submissions_to_grade', $args)['submissions']);
    }

    public function test_submissions_to_grade_and_get_submission(): void {
        $args = ['course_id' => (int) $this->course->id, 'assignment_id' => (int) $this->assign->cmid];
        $rows = $this->data($this->teacher, 'list_submissions_to_grade', $args)['submissions'];
        $this->assertCount(1, $rows);
        $this->assertSame('Essay 2', $rows[0]['assignment_name']);
        $this->assertSame('Ana Alvarez', $rows[0]['student']['full_name']);
        $this->assertFalse($rows[0]['student']['anonymous']);
        $this->assertTrue($rows[0]['late']);

        $result = $this->run_tool($this->teacher, 'get_submission', ['submission_id' => $rows[0]['submission_id']]);
        $data = $result->envelope['data'];
        $this->assertSame('My answer.', $data['online_text_user_content']);
        $this->assertSame('submitted', $data['status']);
        $this->assertCount(1, $data['files']);
        $this->assertSame(['name_user_content', 'size_bytes', 'type'], array_keys($data['files'][0]));
        $this->assertSame('submissionsample01.txt', $data['files'][0]['name_user_content']);
        $this->assertNull($data['current_grade']);
        $this->assertStringNotContainsString('draftfile', json_encode($result->envelope));

        // Once graded it leaves the list, and the grade shows in the submission.
        $this->setUser($this->teacher);
        $cm = get_fast_modinfo($this->course)->get_cm($this->assign->cmid);
        $assign = new \assign(\context_module::instance($cm->id), $cm, $this->course);
        // The submission was made a while ago: a grade given later than it settles it, as on the grading page.
        global $DB;
        $DB->set_field('assign_submission', 'timemodified', time() - HOURSECS, ['id' => $rows[0]['submission_id']]);
        $grade = $assign->get_user_grade($this->student->id, true);
        $grade->grade = 85;
        $assign->update_grade($grade);
        $this->setUser(null);
        $this->assertSame([], $this->data($this->teacher, 'list_submissions_to_grade', $args)['submissions']);
        $this->assertSame(
            85.0,
            (float) $this->data($this->teacher, 'get_submission', ['submission_id' => $rows[0]['submission_id']])['current_grade']
        );

        // Who cannot grade cannot read it: a student of the course and a teacher of another course.
        $id = ['submission_id' => $rows[0]['submission_id']];
        $this->assertSame('profile_not_allowed', $this->code($this->student, 'get_submission', $id, 'student'));
        $this->assertSame('not_found', $this->code($this->groupteacher, 'get_submission', $id));
        $this->assertSame('not_found', $this->code($this->teacher, 'get_submission', ['submission_id' => 99999999]));
        $this->assertSame('not_found', $this->code($this->teacher, 'list_submissions_to_grade', [
            'course_id' => (int) $this->course->id, 'assignment_id' => (int) $this->groupassign->cmid,
        ]));
    }

    public function test_anonymous_marking_hides_identities(): void {
        $args = ['course_id' => (int) $this->course->id, 'assignment_id' => (int) $this->blind->cmid];
        $list = $this->run_tool($this->teacher, 'list_submissions_to_grade', $args);
        $row = $list->envelope['data']['submissions'][0];
        $this->assertTrue($row['student']['anonymous']);
        $this->assertNull($row['student']['user_id']);
        $this->assertNull($row['student']['email']);
        $this->assertMatchesRegularExpression('/^Participant \d+$/', $row['student']['full_name']);
        $one = $this->run_tool($this->teacher, 'get_submission', ['submission_id' => $row['submission_id']]);
        $this->assertSame('My answer.', $one->envelope['data']['online_text_user_content']);
        foreach ([$list, $one] as $result) {
            $json = json_encode($result->envelope);
            $this->assertStringNotContainsString('Alvarez', $json);
            $this->assertStringNotContainsString('ana.alvarez', $json);
            $this->assertStringNotContainsString('"user_id":' . $this->student->id, $json);
        }

        // With the capability to see who is who, as the grading page allows, identities are shown.
        $this->setAdminUser();
        $roles = get_archetype_roles('editingteacher');
        assign_capability('mod/assign:viewblinddetails', CAP_ALLOW, reset($roles)->id, \context_system::instance()->id, true);
        accesslib_clear_all_caches_for_unit_testing();
        $this->setUser(null);
        $row = $this->data($this->teacher, 'list_submissions_to_grade', $args)['submissions'][0];
        $this->assertSame('Ana Alvarez', $row['student']['full_name']);
    }

    public function test_grade_summary_completion_and_inactivity(): void {
        $args = ['course_id' => (int) $this->course->id];
        $data = $this->data($this->teacher, 'get_course_grade_summary', $args);
        $this->assertContains('Quiz one', array_column($data['items'], 'name'));
        $quiz = array_search('Quiz one', array_column($data['items'], 'name', 'item_id'));
        $rows = array_column($data['participants'], null, 'full_name');
        $this->assertSame(['Ana Alvarez', 'Bruno Bermejo'], array_keys($rows), 'Only graded roles are listed');
        $grades = array_column($rows['Bruno Bermejo']['grades'], 'grade', 'item_id');
        $this->assertStringContainsString('55', $grades[$quiz]);
        $this->assertNotNull($rows['Ana Alvarez']['course_total']);

        $report = $this->data($this->teacher, 'get_course_completion_report', $args);
        $this->assertTrue($report['completion_enabled']);
        $this->assertSame(['Ana Alvarez', 'Bruno Bermejo'], array_column($report['participants'], 'full_name'));
        $this->assertFalse($report['participants'][0]['completed']);

        $inactive = $this->data(
            $this->teacher,
            'list_inactive_participants',
            ['course_id' => (int) $this->grouped->id, 'days' => 30]
        );
        $byname = array_column($inactive['participants'], null, 'full_name');
        $this->assertSame(40, $byname[fullname($this->groupone)]['days_inactive']);
        $this->assertNull($byname[fullname($this->grouptwo)]['days_inactive'], 'Never accessed');
        $this->assertArrayNotHasKey(fullname($this->teacher), $byname, 'The caller is not listed');
        $recent = $this->data(
            $this->teacher,
            'list_inactive_participants',
            ['course_id' => (int) $this->grouped->id, 'days' => 60]
        );
        $this->assertNotContains(fullname($this->groupone), array_column($recent['participants'], 'full_name'));

        // A teacher without the gradebook capability gets nothing, and a hidden item stays hidden without its capability.
        $this->prohibit('moodle/grade:viewall', \context_course::instance($this->course->id), $this->teacher);
        $this->assertSame('permission_denied', $this->code($this->teacher, 'get_course_grade_summary', $args));
    }

    public function test_manager_tools(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $this->setAdminUser();
        $elsewhere = $generator->create_category(['name' => 'Arts']);
        $managerroles = get_archetype_roles('manager');
        $catmanager = $generator->create_user();
        role_assign(reset($managerroles)->id, $catmanager->id, \context_coursecat::instance($this->category->id)->id);
        $sysmanager = $generator->create_user();
        role_assign(reset($managerroles)->id, $sysmanager->id, \context_system::instance()->id);
        $self = enrol_get_plugin('self');
        $self->add_instance(
            $this->course,
            ['status' => ENROL_INSTANCE_ENABLED, 'password' => 'ENROL-KEY-SECRET', 'name' => 'Self']
        );
        $generator->create_user(['firstname' => 'Zacarias', 'lastname' => 'Borrado', 'deleted' => 1]);
        $generator->create_user(['firstname' => 'Zacarias', 'lastname' => 'Activo', 'email' => 'unique.mail@example.com']);
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
        $this->setUser(null);

        // A category manager sees the managed category only; a site manager sees all in scope.
        $own = array_column($this->data($catmanager, 'list_categories', [], 'manager')['categories'], 'path');
        $this->assertSame(['Science'], $own);
        $all = array_column($this->data($sysmanager, 'list_categories', [], 'manager')['categories'], 'path');
        $this->assertContains('Arts', $all);
        set_config('excludedcategories', $elsewhere->id, 'local_pluginiaconnect');
        $this->assertNotContains(
            'Arts',
            array_column($this->data($sysmanager, 'list_categories', [], 'manager')['categories'], 'path')
        );

        $result = $this->run_tool($catmanager, 'get_course_enrolment_summary', ['course_id' => (int) $this->course->id], 'manager');
        $summary = $result->envelope['data'];
        $this->assertSame(3, $summary['enrolled_total']);
        $this->assertContains('self', array_column($summary['methods'], 'type'));
        $this->assertSame(2, array_column($summary['roles'], 'user_count', 'name')['Student']);
        $this->assertStringNotContainsString('ENROL-KEY-SECRET', json_encode($result->envelope));
        // The category manager manages nothing in the other course.
        $this->assertSame(
            'not_found',
            $this->code($catmanager, 'get_course_enrolment_summary', ['course_id' => (int) $this->grouped->id], 'manager')
        );

        // Tool find_users: site-wide user details are needed, deleted accounts never appear, short queries are refused.
        $this->assertSame('permission_denied', $this->code($catmanager, 'find_users', ['search_text' => 'Zacarias'], 'manager'));
        $this->assertSame('validation_error', $this->code($sysmanager, 'find_users', ['search_text' => 'Za'], 'manager'));
        $found = $this->data($sysmanager, 'find_users', ['search_text' => 'Zacarias'], 'manager');
        $this->assertSame(['Zacarias Activo'], array_column($found['users'], 'full_name'));
        $this->assertFalse($found['more_matches']);
        $this->assertSame('unique.mail@example.com', $found['users'][0]['email']);
        $this->assertCount(1, $this->data($sysmanager, 'find_users', ['search_text' => 'unique.mail'], 'manager')['users']);

        // Who cannot see e-mail addresses cannot search by them either.
        $this->prohibit('moodle/site:viewuseridentity', \context_system::instance(), $sysmanager);
        $this->assertSame([], $this->data($sysmanager, 'find_users', ['search_text' => 'unique.mail'], 'manager')['users']);
        $this->assertNull($this->data($sysmanager, 'find_users', ['search_text' => 'Zacarias'], 'manager')['users'][0]['email']);

        // Never more than 20, and the result says when there were more.
        $this->setAdminUser();
        for ($i = 0; $i < 25; $i++) {
            $generator->create_user(['firstname' => 'Clon', 'lastname' => 'Numero' . $i]);
        }
        $this->setUser(null);
        $many = $this->data($sysmanager, 'find_users', ['search_text' => 'Clon'], 'manager');
        $this->assertCount(20, $many['users']);
        $this->assertTrue($many['more_matches']);
        $this->assertTrue($DB->record_exists('user', ['lastname' => 'Borrado', 'deleted' => 1]));
    }

    public function test_admin_tools_return_no_configuration(): void {
        global $CFG;
        $admin = get_admin();
        set_config('smtppass', 'SMTP-SECRET-VALUE');
        $status = $this->run_tool($admin, 'get_site_status', [], 'admin');
        $data = $status->envelope['data'];
        $this->assertSame((string) moodle_major_version(), $data['major_version']);
        $this->assertFalse($data['maintenance_mode']);
        $this->assertGreaterThanOrEqual(6, $data['user_count']);
        $this->assertSame(2, $data['course_count']);

        $tasks = $this->run_tool($admin, 'list_scheduled_tasks', ['limit' => 50], 'admin');
        $this->assertCount(50, $tasks->envelope['data']['tasks']);
        $this->assertNotNull($tasks->envelope['meta']['next_cursor']);
        $failing = $this->data($admin, 'list_scheduled_tasks', ['only_failing' => true], 'admin');
        $this->assertSame([], $failing['tasks']);

        $plugins = $this->run_tool($admin, 'list_installed_plugins', [], 'admin');
        $components = array_column($plugins->envelope['data']['plugins'], 'component');
        $this->assertContains('local_pluginiaconnect', $components);
        $this->assertNotContains('mod_assign', $components);
        $standard = $this->data($admin, 'list_installed_plugins', ['include_standard' => true, 'limit' => 50], 'admin');
        $this->assertCount(50, $standard['plugins']);

        foreach ([$status, $tasks, $plugins] as $result) {
            // The site address is public: leave it out.
            $json = str_replace(trim(json_encode($CFG->wwwroot), '"'), '', json_encode($result->envelope));
            $secrets = [$CFG->dataroot, $CFG->dirroot, 'SMTP-SECRET-VALUE', $CFG->release, (string) $CFG->version];
            // The database name says nothing when it is "moodle" (as on CI): site addresses, plugin and task names carry it.
            if (strtolower($CFG->dbname) !== 'moodle') {
                $secrets[] = $CFG->dbname;
            }
            foreach ($secrets as $secret) {
                $this->assertStringNotContainsString(json_encode($secret), '"' . $json . '"');
                $this->assertStringNotContainsString(trim(json_encode((string) $secret), '"'), $json);
            }
        }
    }

    public function test_get_pending_action_status(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', self::SCOPES);
        $row = [
            'userid' => $this->teacher->id, 'grantid' => $grant->grantid, 'tool' => 'set_enrolment_status',
            'mode' => 'approval', 'args' => '{"secret":"ARGS-MUST-NOT-LEAK"}', 'argshash' => str_repeat('a', 64),
            'summary' => 'Suspend the enrolment of 2 users in Biology 101.', 'status' => 'pending', 'timeexpires' => time() + 600,
            'timecreated' => time(),
        ];
        $id = (int) $DB->insert_record('local_pluginiaconnect_action', (object) $row);
        $result = $this->run_tool(
            $this->teacher,
            'get_pending_action_status',
            ['action_id' => $id],
            'teacher',
            self::SCOPES,
            $grant
        );
        $data = $result->envelope['data'];
        $this->assertSame('pending', $data['state']);
        $this->assertSame('Suspend the enrolment of 2 users in Biology 101.', $data['summary']);
        $this->assertStringContainsString('/local/pluginiaconnect/approve.php?id=' . $id, $data['approval_url']);
        $this->assertStringNotContainsString('ARGS-MUST-NOT-LEAK', json_encode($result->envelope));

        // Past its expiry it reads as expired, and nothing is written by a read tool.
        $DB->set_field('local_pluginiaconnect_action', 'timeexpires', time() - 1, ['id' => $id]);
        $expired = $this->run_tool(
            $this->teacher,
            'get_pending_action_status',
            ['action_id' => $id],
            'teacher',
            self::SCOPES,
            $grant
        );
        $this->assertSame('expired', $expired->envelope['data']['state']);
        $this->assertNull($expired->envelope['data']['approval_url']);
        $this->assertSame('pending', $DB->get_field('local_pluginiaconnect_action', 'status', ['id' => $id]));

        // Another connection of the same user, another user, a confirmation token row and a missing id: all not found.
        $this->assertSame('not_found', $this->code($this->teacher, 'get_pending_action_status', ['action_id' => $id]));
        $this->assertSame('not_found', $this->code($this->student, 'get_pending_action_status', ['action_id' => $id], 'student'));
        $this->assertSame('not_found', $this->code($this->teacher, 'get_pending_action_status', ['action_id' => 99999999]));
        $confirm = (int) $DB->insert_record('local_pluginiaconnect_action', (object) (['mode' => 'confirm'] + $row));
        $again = $this->run_tool(
            $this->teacher,
            'get_pending_action_status',
            ['action_id' => $confirm],
            'teacher',
            self::SCOPES,
            $grant
        );
        $this->assertSame('not_found', $again->error_code());
    }

    public function test_exposure_budget(): void {
        set_config('exposurebudgethour', 3, 'local_pluginiaconnect');
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', self::SCOPES);
        $args = ['course_id' => (int) $this->course->id];
        $sink = $this->redirectEvents();

        // First call: two other people. Second call: two more, which reaches the budget of three.
        $first = $this->run_tool($this->teacher, 'list_course_participants', $args, 'teacher', self::SCOPES, $grant);
        $this->assertFalse($first->iserror);
        $this->assertSame(2, exposure_budget::used($grant->grantid));
        $second = $this->run_tool($this->teacher, 'get_course_grade_summary', $args, 'teacher', self::SCOPES, $grant);
        $this->assertFalse($second->iserror);
        $this->assertTrue(exposure_budget::exhausted($grant->grantid));

        $third = $this->run_tool($this->teacher, 'list_course_participants', $args, 'teacher', self::SCOPES, $grant);
        $this->assertSame('rate_limited', $third->error_code());
        $this->assertGreaterThan(0, $third->envelope['error']['retry_after']);
        $this->assertArrayNotHasKey('data', $third->envelope);

        // Tools about the user's own data keep working, and another connection has its own budget.
        $own = $this->run_tool($this->teacher, 'list_my_courses', [], 'teacher', self::SCOPES, $grant);
        $this->assertFalse($own->iserror);
        $this->assertFalse($this->run_tool($this->teacher, 'list_course_participants', $args)->iserror);

        $reached = array_filter($sink->get_events(), static fn($event) =>
            $event instanceof \local_pluginiaconnect\event\exposure_budget_exceeded);
        $sink->close();
        $this->assertCount(1, $reached, 'The event fires once, when the budget is reached');
        $event = reset($reached);
        $this->assertSame($grant->grantid, (int) $event->objectid);
        $this->assertSame('get_course_grade_summary', $event->other['tool']);

        global $DB;
        $this->assertSame(
            1,
            $DB->count_records('local_pluginiaconnect_audit', ['grantid' => $grant->grantid, 'outcome' => 'rate_limited'])
        );
    }

    public function test_exposure_budget_fails_closed(): void {
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', self::SCOPES);
        $this->assertFalse(exposure_budget::add($grant->grantid, 0));
        $this->assertFalse(exposure_budget::add($grant->grantid, 5));
        $this->assertSame(5, exposure_budget::used($grant->grantid));
        $this->assertGreaterThan(0, exposure_budget::retry_after());
        $this->assertLessThanOrEqual(HOURSECS, exposure_budget::retry_after());
        set_config('exposurebudgethour', 999999, 'local_pluginiaconnect');
        $this->assertFalse(exposure_budget::exhausted($grant->grantid), 'The setting cannot exceed its hard maximum');
        $this->assertTrue(exposure_budget::add($grant->grantid, 2000));
        $this->assertTrue(exposure_budget::exhausted($grant->grantid));
    }

    /**
     * Every phase 2 tool against every token profile and every real role. The expected outcome is
     * stated here from the rules of the specification, not derived from the code under test.
     */
    public function test_authorization_matrix(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $systemmanager = $generator->create_user();
        $managerroles = get_archetype_roles('manager');
        role_assign(reset($managerroles)->id, $systemmanager->id, \context_system::instance()->id);
        $users = [
            'student' => $this->student,
            'teacher' => $generator->create_and_enrol($this->course, 'teacher'),
            'editingteacher' => $this->teacher,
            'manager' => $systemmanager,
            'admin' => get_admin(),
            'notenrolled' => $generator->create_user(),
        ];
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
        $this->setUser(null);

        $rank = ['student' => 1, 'teacher' => 2, 'manager' => 3, 'admin' => 4];
        // Profiles each real role may hold on the site, and the highest one it holds inside the course.
        $eligible = [
            'student' => ['student'], 'teacher' => ['student', 'teacher'], 'editingteacher' => ['student', 'teacher'],
            'manager' => ['student', 'teacher', 'manager'], 'admin' => ['student', 'teacher', 'manager', 'admin'],
            'notenrolled' => ['student'],
        ];
        $submission = (int) $this->data($this->teacher, 'list_submissions_to_grade', [
            'course_id' => (int) $this->course->id, 'assignment_id' => (int) $this->assign->cmid,
        ])['submissions'][0]['submission_id'];
        $course = ['course_id' => (int) $this->course->id];
        // Tool => [minimum profile, arguments, acts inside the course, outcome when everything allows it].
        $tools = [
            'search_courses' => ['student', ['search_text' => 'Biology'], false, 'ok'],
            'get_assignment_details' => ['student', ['assignment_id' => (int) $this->assign->cmid], true, 'ok'],
            'list_course_announcements' => ['student', $course, true, 'ok'],
            'get_my_course_progress' => ['student', $course, true, 'ok'],
            'list_course_participants' => ['teacher', $course, true, 'ok'],
            'list_submissions_to_grade' => ['teacher', $course, true, 'ok'],
            'get_submission' => ['teacher', ['submission_id' => $submission], true, 'ok'],
            'get_course_grade_summary' => ['teacher', $course, true, 'ok'],
            'get_course_completion_report' => ['teacher', $course, true, 'ok'],
            'list_inactive_participants' => ['teacher', $course, true, 'ok'],
            'list_categories' => ['manager', [], false, 'ok'],
            'get_course_enrolment_summary' => ['manager', $course, true, 'ok'],
            'find_users' => ['manager', ['search_text' => 'Alvarez'], false, 'ok'],
            'get_site_status' => ['admin', [], false, 'ok'],
            'list_scheduled_tasks' => ['admin', [], false, 'ok'],
            'list_installed_plugins' => ['admin', [], false, 'ok'],
            'get_pending_action_status' => ['student', ['action_id' => 99999999], false, 'not_found'],
        ];
        $this->assertCount(17, $tools);

        $cases = 0;
        foreach ($tools as $tool => [$minimum, $args, $incourse, $success]) {
            $definition = tool_registry::get($tool)::definition();
            $this->assertNull(schema_validator::validate($args, $definition->properties, $definition->required), $tool);
            foreach (array_keys($rank) as $profile) {
                foreach ($users as $role => $user) {
                    // The highest profile, not above the one of the connection, that the role really holds here.
                    $held = array_filter($eligible[$role], static fn($p) => $rank[$p] <= $rank[$profile]);
                    $effective = $held ? max(array_map(static fn($p) => $rank[$p], $held)) : 0;
                    if ($rank[$profile] < $rank[$minimum]) {
                        $expected = 'profile_not_allowed';
                    } else if ($incourse && $role === 'notenrolled') {
                        $expected = 'not_found';
                    } else if (!in_array($profile, $eligible[$role], true)) {
                        $expected = 'profile_not_allowed';
                    } else if ($effective < $rank[$minimum]) {
                        $expected = 'permission_denied';
                    } else {
                        $expected = $success;
                    }
                    $result = $this->run_tool($user, $tool, $args, $profile);
                    $actual = $result->iserror ? $result->error_code() : 'ok';
                    $this->assertSame($expected, $actual, "{$tool} with a {$profile} token held by a real {$role}");
                    $cases++;
                }
            }
        }
        $this->assertSame(17 * 4 * 6, $cases, 'No cell of the matrix without a case');

        // Access level: a connection without the scope of the tool is refused before anything else is looked at.
        foreach ($tools as $tool => [$minimum, $args]) {
            foreach ($users as $role => $user) {
                $result = $this->run_tool($user, $tool, $args, 'admin', ['offline_access']);
                $this->assertSame('scope_missing', $result->error_code(), "{$tool} without scope for {$role}");
            }
        }
    }
}
