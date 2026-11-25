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
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tool\read\list_failed_tasks;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/output_schema_check.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');
require_once($CFG->libdir . '/completionlib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\report_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\forum_helper::class)]
/**
 * The tools of amendment 2, block C, and the three tools added for the questions of the product owner
 * (site activity, course progress and overdue work), through the whole pipeline.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\tool\report_helper
 * @covers     \local_pluginiaconnect\local\tool\forum_helper
 * @covers     \local_pluginiaconnect\local\tool\read\get_course_activity_log
 * @covers     \local_pluginiaconnect\local\tool\read\get_activity_participation_report
 * @covers     \local_pluginiaconnect\local\tool\read\get_activity_completion_report
 * @covers     \local_pluginiaconnect\local\tool\read\get_quiz_attempts_report
 * @covers     \local_pluginiaconnect\local\tool\read\get_course_progress_summary
 * @covers     \local_pluginiaconnect\local\tool\read\list_overdue_submissions
 * @covers     \local_pluginiaconnect\local\tool\read\list_course_forums
 * @covers     \local_pluginiaconnect\local\tool\read\list_forum_discussions
 * @covers     \local_pluginiaconnect\local\tool\read\get_forum_discussion
 * @covers     \local_pluginiaconnect\local\tool\read\get_forum_participation_summary
 * @covers     \local_pluginiaconnect\local\tool\read\get_site_activity_summary
 * @covers     \local_pluginiaconnect\local\tool\read\list_failed_tasks
 * @covers     \local_pluginiaconnect\local\tool\read\get_site_checks
 * @covers     \local_pluginiaconnect\local\tool\write\post_forum_discussion
 * @covers     \local_pluginiaconnect\local\tool\write\reply_to_forum_post
 */
final class mcp_block_c_test extends \advanced_testcase {
    /** @var string Hidden text that must never leave the site. */
    private const HIDDEN = '<span style="display:none">HIDDEN-INSTRUCTION</span>';

    /** @var string[] Tools of this block. */
    private const TOOLS = ['get_course_activity_log', 'get_activity_participation_report', 'get_activity_completion_report',
        'get_quiz_attempts_report', 'get_course_progress_summary', 'list_overdue_submissions', 'list_course_forums',
        'list_forum_discussions', 'get_forum_discussion', 'get_forum_participation_summary', 'get_site_activity_summary',
        'list_failed_tasks', 'get_site_checks', 'post_forum_discussion', 'reply_to_forum_post'];

    /** @var \stdClass Course with separate groups and completion. */
    private \stdClass $course;
    /** @var \stdClass Editing teacher, in every group. */
    private \stdClass $teacher;
    /** @var \stdClass Non-editing teacher of group one only. */
    private \stdClass $groupteacher;
    /** @var \stdClass Student of group one. */
    private \stdClass $one;
    /** @var \stdClass Student of group two. */
    private \stdClass $two;
    /** @var \stdClass Third student, of group one, with an extension. */
    private \stdClass $three;
    /** @var \stdClass Page with completion. */
    private \stdClass $page;
    /** @var \stdClass Assignment past its due date. */
    private \stdClass $assign;
    /** @var \stdClass Quiz. */
    private \stdClass $quiz;
    /** @var \stdClass General forum. */
    private \stdClass $forum;
    /** @var \stdClass News forum. */
    private \stdClass $news;
    /** @var \stdClass Discussion of the general forum. */
    private \stdClass $discussion;
    /** @var string Log table. */
    private string $log = 'logstore_standard_log';

    /**
     * A course with separate groups, three students, two teachers, a page, an assignment, a quiz and two forums;
     * the standard log on; every tool of the block switched on.
     */
    protected function setUp(): void {
        global $DB;
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        set_config('enabled_stores', 'logstore_standard', 'tool_log');
        set_config('buffersize', 0, 'logstore_standard');
        get_log_manager(true);
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['fullname' => 'Physics 1', 'groupmode' => SEPARATEGROUPS,
            'groupmodeforce' => 1, 'enablecompletion' => 1]);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tina']);
        $this->groupteacher = $generator->create_and_enrol($this->course, 'teacher', ['firstname' => 'Gus']);
        $this->one = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ana', 'lastname' => 'One']);
        $this->two = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Bea', 'lastname' => 'Two']);
        $this->three = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ciro', 'lastname' => 'Three']);
        $groupone = $generator->create_group(['courseid' => $this->course->id]);
        $grouptwo = $generator->create_group(['courseid' => $this->course->id]);
        foreach ([$this->one, $this->three, $this->groupteacher, $this->teacher] as $user) {
            $generator->create_group_member(['groupid' => $groupone->id, 'userid' => $user->id]);
        }
        foreach ([$this->two, $this->teacher] as $user) {
            $generator->create_group_member(['groupid' => $grouptwo->id, 'userid' => $user->id]);
        }

        $this->page = $generator->create_module('page', ['course' => $this->course->id, 'name' => 'Reading 1',
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->assign = $generator->create_module('assign', ['course' => $this->course->id, 'name' => 'Lab 1',
            'duedate' => time() - 3 * DAYSECS, 'assignsubmission_onlinetext_enabled' => 1, 'submissiondrafts' => 0,
            'completion' => COMPLETION_TRACKING_MANUAL]);
        $this->quiz = $generator->create_module('quiz', ['course' => $this->course->id, 'name' => 'Test 1', 'grade' => 10]);
        $this->forum = $generator->create_module('forum', ['course' => $this->course->id, 'name' => 'Questions',
            'type' => 'general']);
        $this->news = $generator->create_module('forum', ['course' => $this->course->id, 'name' => 'News', 'type' => 'news']);

        // Ana completed the page; Ciro has an extension; nobody submitted the assignment.
        $DB->insert_record('course_modules_completion', (object) ['coursemoduleid' => $this->page->cmid,
            'userid' => $this->one->id, 'completionstate' => COMPLETION_COMPLETE, 'timemodified' => time()]);
        $DB->insert_record('assign_user_flags', (object) ['userid' => $this->three->id, 'assignment' => $this->assign->id,
            'locked' => 0, 'mailed' => 0, 'extensionduedate' => time() + 5 * DAYSECS, 'workflowstate' => '',
            'allocatedmarker' => 0]);
        // Quiz: Ana finished one attempt and has a grade; a teacher preview is not counted.
        $DB->insert_record('quiz_attempts', (object) ['quiz' => $this->quiz->id, 'userid' => $this->one->id, 'attempt' => 1,
            'uniqueid' => 900001, 'layout' => '', 'currentpage' => 0, 'preview' => 0, 'state' => 'finished',
            'timestart' => time() - HOURSECS, 'timefinish' => time() - 1800, 'timemodified' => time(), 'sumgrades' => 7]);
        $DB->insert_record('quiz_attempts', (object) ['quiz' => $this->quiz->id, 'userid' => $this->teacher->id,
            'attempt' => 1, 'uniqueid' => 900002, 'layout' => '', 'currentpage' => 0, 'preview' => 1, 'state' => 'finished',
            'timestart' => time(), 'timefinish' => time(), 'timemodified' => time(), 'sumgrades' => 1]);
        $DB->insert_record('quiz_grades', (object) ['quiz' => $this->quiz->id, 'userid' => $this->one->id, 'grade' => 7.5,
            'timemodified' => time()]);
        // Forum: one discussion by Ana with hidden text, one reply by Bea.
        $forumgenerator = $generator->get_plugin_generator('mod_forum');
        $this->discussion = $forumgenerator->create_discussion(['course' => $this->course->id, 'forum' => $this->forum->id,
            'userid' => $this->one->id, 'groupid' => $groupone->id, 'name' => 'About the lab',
            'message' => '<p>When is the lab due?</p>' . self::HIDDEN]);
        $forumgenerator->create_post(['discussion' => $this->discussion->id, 'userid' => $this->two->id,
            'parent' => $this->discussion->firstpost, 'message' => '<p>Friday.</p>']);

        // Log rows: views of the page, a sign-in, an anonymous event and a course view, with an address.
        $this->log_event($this->one, 'r', $this->page->cmid, '\\mod_page\\event\\course_module_viewed', time() - HOURSECS);
        $this->log_event($this->one, 'r', $this->page->cmid, '\\mod_page\\event\\course_module_viewed', time() - 1800);
        $this->log_event($this->two, 'r', $this->page->cmid, '\\mod_page\\event\\course_module_viewed', time() - 1800);
        $this->log_event($this->two, 'c', $this->page->cmid, '\\mod_page\\event\\secret_anonymous', time() - 60, 1);
        $this->log_event($this->one, 'r', null, '\\core\\event\\course_viewed', time() - 120);
        $this->log_event($this->two, 'r', null, '\\core\\event\\course_viewed', time() - 120);
        $this->log_event($this->one, 'r', null, '\\core\\event\\user_loggedin', time() - 300, 0, SITEID);
        $this->log_event($this->one, 'r', null, '\\core\\event\\user_loggedin', time() - 200, 0, SITEID);
        $this->log_event($this->two, 'r', null, '\\core\\event\\user_loggedin', time() - 100, 0, SITEID);

        \local_pluginiaconnect_mcp_fixture::enable();
        foreach (self::TOOLS as $tool) {
            foreach (['teacher', 'manager', 'admin'] as $profile) {
                set_config("tool_{$tool}_{$profile}", 1, 'local_pluginiaconnect');
            }
        }
        $this->setUser(null);
    }

    /**
     * Adds one row to the standard log.
     *
     * @param \stdClass $user User.
     * @param string $crud CRUD letter.
     * @param int|null $cmid Course module, or null for a course event.
     * @param string $event Event class.
     * @param int $time Time.
     * @param int $anonymous Anonymous flag.
     * @param int|null $courseid Course, by default the test course.
     */
    private function log_event(
        \stdClass $user,
        string $crud,
        ?int $cmid,
        string $event,
        int $time,
        int $anonymous = 0,
        ?int $courseid = null
    ): void {
        global $DB;
        $courseid = $courseid ?? (int) $this->course->id;
        $context = $cmid ? \context_module::instance($cmid) : ($courseid === SITEID ? \context_system::instance()
            : \context_course::instance($courseid));
        $DB->insert_record($this->log, (object) [
            'eventname' => $event, 'component' => 'core', 'action' => 'viewed', 'target' => 'course_module', 'crud' => $crud,
            'edulevel' => 2, 'contextid' => $context->id, 'contextlevel' => $context->contextlevel,
            'contextinstanceid' => $context->instanceid, 'userid' => $user->id, 'courseid' => $courseid,
            'relateduserid' => null, 'anonymous' => $anonymous, 'other' => 'OTHER-DATA', 'timecreated' => $time,
            'origin' => 'web', 'ip' => '198.51.100.23', 'realuserid' => null,
        ]);
    }

    /**
     * Runs a tool through the pipeline on a fresh connection with every scope of the profile.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool.
     * @param array $args Arguments.
     * @param string $profile Profile.
     * @param array|null $scopes Scopes.
     * @return \local_pluginiaconnect\local\mcp\pipeline_result
     */
    private function run_tool(\stdClass $user, string $tool, array $args, string $profile = 'teacher', ?array $scopes = null) {
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, $scopes ?? scope::max_for_profile($profile));
        $loaded = user_session::load($grant);
        $result = call_pipeline::run($grant, $loaded, $tool, $args, \core\uuid::generate(), '192.0.2.7');
        \local_pluginiaconnect_output_schema_check::assert_valid($tool, $result->envelope);
        $json = json_encode($result->envelope);
        foreach (['HIDDEN-INSTRUCTION', 'OTHER-DATA', '198.51.100.23', 'secret_anonymous'] as $leak) {
            $this->assertStringNotContainsString($leak, $json, "{$tool} leaked {$leak}");
        }
        return $result;
    }

    /**
     * Data of a call that must succeed.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool.
     * @param array $args Arguments.
     * @param string $profile Profile.
     * @return array
     */
    private function data(\stdClass $user, string $tool, array $args, string $profile = 'teacher'): array {
        $result = $this->run_tool($user, $tool, $args, $profile);
        $this->assertFalse($result->iserror, "{$tool}: " . json_encode($result->envelope['error'] ?? null));
        return $result->envelope['data'];
    }

    /**
     * Period covering the rows of the test.
     *
     * @return array
     */
    private function today(): array {
        return ['from' => gmdate('Y-m-d\TH:i:s\Z', time() - DAYSECS), 'to' => gmdate('Y-m-d\TH:i:s\Z', time() + 60)];
    }

    public function test_every_tool_of_the_block_is_in_the_catalogue_and_starts_off(): void {
        foreach (self::TOOLS as $tool) {
            $class = tool_registry::get($tool);
            $this->assertNotNull($class, $tool);
            $this->assertTrue(tool_registry::is_base($tool));
            unset_config("tool_{$tool}_teacher", 'local_pluginiaconnect');
            $this->assertFalse(site_policy::is_tool_enabled($tool, 'teacher'), $tool);
            $this->assertContains($class::requirement()->scope, ['reports.read', 'forums.read', 'forums.write', 'site.read']);
        }
        // Switched off, the tool is refused before anything is read.
        $result = $this->run_tool($this->teacher, 'get_course_activity_log', ['course_id' => (int) $this->course->id]
            + $this->today());
        $this->assertSame('tool_disabled', $result->error_code());
    }

    public function test_activity_log_applies_the_period_anonymity_and_groups(): void {
        $args = ['course_id' => (int) $this->course->id] + $this->today();
        $data = $this->data($this->teacher, 'get_course_activity_log', $args);
        $this->assertSame(5, $data['total'], 'The anonymous event and the site events are not counted');
        $this->assertSame([], array_diff(array_keys($data['events'][0]), ['time', 'user_id', 'full_name', 'related_user_id',
            'event', 'event_name', 'component', 'action', 'target', 'crud', 'activity_id', 'activity_name', 'origin']));
        // The non-editing teacher of group one sees only group one.
        $data = $this->data($this->groupteacher, 'get_course_activity_log', $args);
        $this->assertSame([(int) $this->one->id], array_values(array_unique(array_column($data['events'], 'user_id'))));
        $this->assertSame('not_found', $this->run_tool(
            $this->groupteacher,
            'get_course_activity_log',
            $args + ['user_id' => (int) $this->two->id]
        )->error_code());
        // Filters, the compulsory period and its limit.
        $data = $this->data($this->teacher, 'get_course_activity_log', $args + ['activity_id' => (int) $this->page->cmid]);
        $this->assertSame(3, $data['total']);
        $this->assertSame('Reading 1', $data['events'][0]['activity_name']);
        $this->assertSame('validation_error', $this->run_tool(
            $this->teacher,
            'get_course_activity_log',
            ['course_id' => (int) $this->course->id]
        )->error_code());
        $this->assertSame('limit_exceeded', $this->run_tool(
            $this->teacher,
            'get_course_activity_log',
            ['course_id' => (int) $this->course->id, 'from' => '2026-01-01T00:00:00Z', 'to' => '2026-03-01T00:00:00Z']
        )
            ->error_code());
        set_config('logwindowdays', 1, 'local_pluginiaconnect');
        $this->assertSame('limit_exceeded', $this->run_tool(
            $this->teacher,
            'get_course_activity_log',
            ['course_id' => (int) $this->course->id, 'from' => gmdate('Y-m-d\TH:i:s\Z', time() - 2 * DAYSECS),
            'to' => gmdate(
                'Y-m-d\TH:i:s\Z',
                time()
            )]
        )->error_code());
        set_config('logwindowdays', 31, 'local_pluginiaconnect');
        // Without the standard log there is nothing to read, and the tool says so.
        set_config('enabled_stores', '', 'tool_log');
        get_log_manager(true);
        $this->assertSame('conflict', $this->run_tool($this->teacher, 'get_course_activity_log', $args)->error_code());
        // A student connection never reaches the reports, even with the cell of the matrix on.
        set_config('tool_get_course_activity_log_student', 1, 'local_pluginiaconnect');
        $this->assertSame('profile_not_allowed', $this->run_tool(
            $this->one,
            'get_course_activity_log',
            $args,
            'student',
            scope::max_for_profile('student')
        )->error_code());
    }

    public function test_participation_completion_and_quiz_reports(): void {
        $args = ['activity_id' => (int) $this->page->cmid] + $this->today();
        $data = $this->data($this->teacher, 'get_activity_participation_report', $args);
        $this->assertSame(3, $data['learners_total']);
        $views = array_column($data['participants'], 'views', 'full_name');
        $this->assertSame(['Ana One' => 2, 'Ciro Three' => 0, 'Bea Two' => 1], $views);
        $data = $this->data($this->teacher, 'get_activity_participation_report', $args + ['only_without_activity' => true]);
        $this->assertSame(['Ciro Three'], array_column($data['participants'], 'full_name'));
        $data = $this->data($this->groupteacher, 'get_activity_participation_report', $args);
        $this->assertEqualsCanonicalizing(['Ana One', 'Ciro Three'], array_column($data['participants'], 'full_name'));

        $data = $this->data($this->teacher, 'get_activity_completion_report', ['course_id' => (int) $this->course->id]);
        $this->assertTrue($data['completion_enabled']);
        $this->assertSame(3, $data['participants_total']);
        $ana = array_values(array_filter($data['participants'], fn($p) => $p['user_id'] === (int) $this->one->id))[0];
        $this->assertSame([(int) $this->page->cmid], $ana['completed_activity_ids']);
        $this->assertSame(1, $ana['pending_count']);

        $data = $this->data($this->teacher, 'get_quiz_attempts_report', ['quiz_id' => (int) $this->quiz->cmid]);
        $this->assertSame(1, $data['learners_finished']);
        $this->assertEquals(7.5, $data['average_best_grade']);
        $this->assertNotContains((int) $this->teacher->id, array_column($data['participants'], 'user_id'));
        $data = $this->data($this->teacher, 'get_quiz_attempts_report', ['quiz_id' => (int) $this->quiz->cmid,
            'only_without_attempts' => true]);
        $this->assertNotContains((int) $this->one->id, array_column($data['participants'], 'user_id'));
    }

    public function test_progress_summary_and_overdue_work_answer_who_is_behind(): void {
        $data = $this->data($this->teacher, 'get_course_progress_summary', ['course_id' => (int) $this->course->id]);
        $this->assertSame(3, $data['learners']);
        $this->assertSame(2, $data['tracked_activities']);
        $this->assertSame(['not_started' => 2, 'from_1_to_24' => 0, 'from_25_to_49' => 0, 'from_50_to_74' => 1,
            'from_75_to_99' => 0, 'all_done' => 0], $data['progress_ranges']);
        $this->assertEquals(16.7, $data['average_progress_percent']);
        // Ciro has an extension: only Ana and Bea are behind on the lab.
        $this->assertSame(2, $data['with_overdue_assignments']);
        $this->assertArrayNotHasKey('participants', $data, 'Only figures, no names');

        $data = $this->data($this->teacher, 'list_overdue_submissions', ['course_id' => (int) $this->course->id]);
        $this->assertSame(2, $data['learners_behind']);
        $this->assertEqualsCanonicalizing([(int) $this->one->id, (int) $this->two->id], array_column(
            $data['participants'],
            'user_id'
        ));
        $this->assertSame('Lab 1', $data['participants'][0]['overdue'][0]['name']);
        $this->assertGreaterThanOrEqual(2, $data['participants'][0]['overdue'][0]['days_overdue']);
        // With a submission, Ana is not behind any more.
        $this->getDataGenerator()->get_plugin_generator('mod_assign')->create_submission(['userid' => $this->one->id,
            'cmid' => $this->assign->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED, 'onlinetext' => 'Done']);
        $data = $this->data($this->teacher, 'list_overdue_submissions', ['course_id' => (int) $this->course->id]);
        $this->assertSame([(int) $this->two->id], array_column($data['participants'], 'user_id'));
        // A group teacher sees only their group; a group of the course can be asked for.
        $data = $this->data($this->groupteacher, 'get_course_progress_summary', ['course_id' => (int) $this->course->id]);
        $this->assertSame(2, $data['learners']);
    }

    public function test_forums_are_read_as_the_forum_allows(): void {
        $data = $this->data($this->teacher, 'list_course_forums', ['course_id' => (int) $this->course->id]);
        $this->assertEqualsCanonicalizing(['news', 'general'], array_column($data['forums'], 'type'));
        $data = $this->data($this->teacher, 'list_forum_discussions', ['forum_id' => (int) $this->forum->cmid]);
        $this->assertSame('About the lab', $data['discussions'][0]['subject_user_content']);
        $this->assertSame(1, $data['discussions'][0]['replies']);
        $data = $this->data($this->teacher, 'get_forum_discussion', ['discussion_id' => (int) $this->discussion->id]);
        $this->assertCount(2, $data['posts']);
        $this->assertStringContainsString('When is the lab due?', $data['posts'][0]['message_user_content']);
        $data = $this->data($this->teacher, 'get_forum_participation_summary', ['forum_id' => (int) $this->forum->cmid]);
        $byname = array_column($data['participants'], null, 'full_name');
        $this->assertSame(1, $byname['Ana One']['discussions_started']);
        $this->assertSame(1, $byname['Bea Two']['replies']);
        $this->assertSame(0, $byname['Ciro Three']['replies']);
        // The group teacher only sees the learners of group one.
        $data = $this->data($this->groupteacher, 'get_forum_participation_summary', ['forum_id' => (int) $this->forum->cmid]);
        $this->assertArrayNotHasKey('Bea Two', array_column($data['participants'], null, 'full_name'));
        // The forum's own functions ask for read tracking with a forum id and say so at developer level.
        $this->resetDebugging();
    }

    public function test_forum_writes_are_confirmed_published_as_the_user_and_limited(): void {
        global $DB;
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($this->teacher->id, 'teacher', scope::max_for_profile('teacher'));
        $loaded = user_session::load($grant);
        $run = fn(string $tool, array $args) => call_pipeline::run($grant, $loaded, $tool, $args, \core\uuid::generate());
        $groups = groups_get_all_groups($this->course->id);
        $group = (int) reset($groups)->id;
        $counts = fn() => [$DB->count_records('forum_discussions'), $DB->count_records('forum_posts'),
            $DB->count_records('course_modules'), $DB->count_records('user_enrolments')];
        $before = $counts();
        $args = ['forum_id' => (int) $this->forum->cmid, 'subject' => 'Exam', 'message' => 'Chapters 1 to 3.<b>x</b>',
            'group_id' => $group];
        $first = $run('post_forum_discussion', $args);
        $this->assertSame('confirmation_required', $first->envelope['status']);
        $this->assertStringContainsString('published as you', $first->envelope['data']['summary']);
        $this->assertStringContainsString('cannot be withdrawn', $first->envelope['data']['summary']);
        $this->assertSame($before, $counts(), 'Nothing is published on the first call');
        $second = $run('post_forum_discussion', $args + ['confirm_token' => $first->envelope['data']['confirm_token']]);
        $this->assertSame('ok', $second->envelope['status'], json_encode($second->envelope));
        $discussion = $DB->get_record('forum_discussions', ['id' => $second->envelope['data']['discussion_id']], '*', MUST_EXIST);
        // D28: the author is the user of the connection; the text is escaped, never markup.
        $this->assertSame((int) $this->teacher->id, (int) $discussion->userid);
        $post = $DB->get_record('forum_posts', ['id' => $discussion->firstpost], '*', MUST_EXIST);
        $this->assertSame((int) $this->teacher->id, (int) $post->userid);
        $this->assertStringNotContainsString('<b>', $post->message);
        // D29 (d): rows only grow.
        $after = $counts();
        $this->assertSame([$before[0] + 1, $before[1] + 1, $before[2], $before[3]], $after);

        $reply = ['post_id' => (int) $this->discussion->firstpost, 'message' => 'Friday at noon.'];
        $first = $run('reply_to_forum_post', $reply);
        $this->assertSame('confirmation_required', $first->envelope['status']);
        $second = $run('reply_to_forum_post', $reply + ['confirm_token' => $first->envelope['data']['confirm_token']]);
        $this->assertSame('ok', $second->envelope['status'], json_encode($second->envelope));
        $post = $DB->get_record('forum_posts', ['id' => $second->envelope['data']['post_id']], '*', MUST_EXIST);
        $this->assertSame((int) $this->teacher->id, (int) $post->userid);
        $this->assertSame((int) $this->discussion->firstpost, (int) $post->parent);
        $this->assertSame(0, (int) $post->privatereplyto);

        // The announcements forum is refused; without a group, a teacher who sees every group posts to all of them.
        $this->assertSame('conflict', $run('post_forum_discussion', ['forum_id' => (int) $this->news->cmid,
            'subject' => 'x', 'message' => 'y'])->error_code());
        $all = $run('post_forum_discussion', ['forum_id' => (int) $this->forum->cmid, 'subject' => 'x', 'message' => 'y']);
        $this->assertStringContainsString('for all participants', $all->envelope['data']['summary']);
        // The daily limit of the connection.
        set_config('maxforumpostsday', 2, 'local_pluginiaconnect');
        $this->assertSame('limit_exceeded', $run('reply_to_forum_post', $reply)->error_code());
    }

    public function test_site_activity_tasks_and_checks_for_the_administrator(): void {
        global $DB;
        $admin = get_admin();
        $data = $this->data($admin, 'get_site_activity_summary', $this->today(), 'admin');
        $this->assertSame(3, $data['sign_ins']);
        $this->assertSame(2, $data['users_signed_in']);
        $this->assertSame(2, $data['course_views']);
        $this->assertSame((int) $this->course->id, $data['top_courses'][0]['course_id']);
        $this->assertSame(2, $data['top_courses'][0]['distinct_users']);
        $this->assertSame(3, array_sum(array_column($data['days'], 'sign_ins')));
        $this->assertStringNotContainsString('Ana', json_encode($data), 'Only counts, never who');
        $this->assertSame('profile_not_allowed', $this->run_tool(
            $this->teacher,
            'get_site_activity_summary',
            $this->today()
        )->error_code());

        $DB->insert_record('task_log', (object) ['type' => 0, 'component' => 'core', 'classname' => '\\core\\task\\x_task',
            'userid' => 0, 'timestart' => time() - 10, 'timeend' => time() - 5, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 1,
            'output' => "Started\nError in /var/www/html/lib/foo.php for ana@example.com from 10.0.0.1", 'hostname' => 'h',
            'pid' => 1]);
        $DB->insert_record('task_log', (object) ['type' => 1, 'component' => 'core', 'classname' => '\\core\\task\\y_task',
            'userid' => 0, 'timestart' => time() - 4, 'timeend' => time() - 3, 'dbreads' => 0, 'dbwrites' => 0, 'result' => 1,
            'output' => 'Connecting with password=Hunter2 failed', 'hostname' => 'h', 'pid' => 1]);
        $data = $this->data($admin, 'list_failed_tasks', [], 'admin');
        $runs = array_column($data['recent_failed_runs'], null, 'classname');
        $this->assertNull($runs['core\\task\\y_task']['output_excerpt_user_content']);
        $this->assertStringContainsString('withheld', $runs['core\\task\\y_task']['output_note']);
        $excerpt = $runs['core\\task\\x_task']['output_excerpt_user_content'];
        $this->assertStringNotContainsString('/var/www', $excerpt);
        $this->assertStringNotContainsString('ana@example.com', $excerpt);
        $this->assertStringNotContainsString('10.0.0.1', $excerpt);
        $this->assertStringNotContainsString('Hunter2', json_encode($data));
        $this->assertSame([null, 'The run left no output.'], list_failed_tasks::excerpt(''));
        $long = list_failed_tasks::excerpt(str_repeat('x ', 3000) . 'END');
        $this->assertLessThanOrEqual(2003, \core_text::strlen($long[0]));
        $this->assertStringEndsWith('END', $long[0]);

        // The performance checks make no request to the site itself.
        $data = $this->data($admin, 'get_site_checks', ['type' => 'performance'], 'admin');
        $this->assertSame('performance', $data['type']);
        $this->assertGreaterThan(0, $data['checks_total']);
        foreach ($data['checks'] as $check) {
            $this->assertNotSame('', $check['id']);
        }
        $only = $this->data($admin, 'get_site_checks', ['type' => 'status', 'only_problems' => true], 'admin');
        foreach ($only['checks'] as $check) {
            $this->assertContains($check['status'], ['warning', 'error', 'critical', 'unknown', 'not_run']);
        }
    }
}
