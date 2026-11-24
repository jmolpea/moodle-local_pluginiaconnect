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
use local_pluginiaconnect\local\mcp\schema_validator;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->libdir . '/gradelib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\base_tool::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_connection_info::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\list_my_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_course_outline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\list_upcoming_deadlines::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_my_grades::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
/**
 * The five tools of phase 1, run through the whole pipeline with real users, roles and courses.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\tool\base_tool
 * @covers     \local_pluginiaconnect\local\tool\read\get_connection_info
 * @covers     \local_pluginiaconnect\local\tool\read\list_my_courses
 * @covers     \local_pluginiaconnect\local\tool\read\get_course_outline
 * @covers     \local_pluginiaconnect\local\tool\read\list_upcoming_deadlines
 * @covers     \local_pluginiaconnect\local\tool\read\get_my_grades
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 */
final class mcp_tools_test extends \advanced_testcase {
    /** @var string[] Scopes of a read-only student connection. */
    private const READ = ['profile', 'courses.read', 'grades.read'];

    /** @var \stdClass Main course. */
    private \stdClass $course;
    /** @var \stdClass Second course of the student. */
    private \stdClass $second;
    /** @var \stdClass Course the site excludes from the connector. */
    private \stdClass $excluded;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var \stdClass Another student of the main course. */
    private \stdClass $other;
    /** @var \stdClass Editing teacher of the main course. */
    private \stdClass $teacher;
    /** @var \stdClass Assignment with a due date in the main course. */
    private \stdClass $assign;

    /**
     * Courses, users, an assignment with a due date and grades with feedback.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();

        $hidden = '<span style="display:none">HIDDEN-INSTRUCTION</span>';
        $this->course = $generator->create_course([
            'fullname' => 'History & Art 101', 'shortname' => 'HIS101', 'idnumber' => 'COURSE-IDNUMBER', 'numsections' => 3,
            'enablecompletion' => 1,
        ]);
        $this->second = $generator->create_course(['fullname' => 'Biology 101', 'shortname' => 'BIO101']);
        $this->excluded = $generator->create_course(['fullname' => 'Excluded course', 'shortname' => 'EXC']);
        $this->student = $generator->create_and_enrol($this->course, 'student', ['idnumber' => 'USER-IDNUMBER']);
        $this->other = $generator->create_and_enrol($this->course, 'student');
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher');
        $generator->enrol_user($this->student->id, $this->second->id, 'student');
        $generator->enrol_user($this->student->id, $this->excluded->id, 'student');

        $this->assign = $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Essay 2', 'duedate' => time() + 3 * DAYSECS, 'section' => 1,
            'intro' => 'Write the essay.' . $hidden, 'showdescription' => 1,
        ]);
        $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Teachers only draft', 'visible' => 0, 'section' => 1,
        ]);
        $generator->create_module('assign', [
            'course' => $this->excluded->id, 'name' => 'Excluded essay', 'duedate' => time() + 2 * DAYSECS,
        ]);
        global $DB;
        $section = $DB->get_record('course_sections', ['course' => $this->course->id, 'section' => 1], '*', MUST_EXIST);
        $DB->update_record('course_sections', (object) [
            'id' => $section->id, 'summary' => '<p>Week one.</p><!-- HIDDEN-COMMENT -->' . $hidden, 'summaryformat' => FORMAT_HTML,
        ]);
        rebuild_course_cache($this->course->id, true);

        $item = \grade_item::fetch(['id' => $generator->create_grade_item([
            'courseid' => $this->course->id, 'itemname' => 'Manual one', 'grademax' => 100,
        ])->id]);
        $item->update_final_grade($this->student->id, 80, 'test', '<p>Well done.</p>' . $hidden, FORMAT_HTML);
        $item->update_final_grade($this->other->id, 55, 'test', 'OTHER-FEEDBACK', FORMAT_HTML);
        $secret = \grade_item::fetch(['id' => $generator->create_grade_item([
            'courseid' => $this->course->id, 'itemname' => 'Hidden item', 'grademax' => 100, 'hidden' => 1,
        ])->id]);
        $secret->update_final_grade($this->student->id, 33.33, 'test');
        grade_regrade_final_grades($this->course->id);
        $plain = \grade_item::fetch(['id' => $generator->create_grade_item([
            'courseid' => $this->second->id, 'itemname' => 'Quiz', 'grademax' => 100,
        ])->id]);
        $plain->update_final_grade($this->student->id, 70, 'test');
        grade_regrade_final_grades($this->second->id);

        \local_pluginiaconnect_mcp_fixture::enable(['excludedcourses' => $this->excluded->id]);
        $this->setUser(null);
    }

    /**
     * Runs a tool as a user through the whole pipeline.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param mixed $args Arguments.
     * @param string $profile Profile of the token.
     * @param string[] $scopes Scopes of the token.
     * @return \local_pluginiaconnect\local\mcp\pipeline_result
     */
    private function run_tool(\stdClass $user, string $tool, $args = [], string $profile = 'student', array $scopes = self::READ) {
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, $scopes);
        $loaded = user_session::load($grant);
        $this->assertNotNull($loaded);
        $result = call_pipeline::run($grant, $loaded, $tool, $args, \core\uuid::generate(), '192.0.2.7');
        // What the tool returned, whatever it was, matches the output schema the gateway enforces.
        \local_pluginiaconnect_output_schema_check::assert_valid($tool, $result->envelope);
        if (!$result->iserror) {
            // Every successful result must honour the declared output schema basics and carry no known secret.
            $class = tool_registry::get($tool);
            $this->assertSame('ok', $result->envelope['status']);
            $json = json_encode($result->envelope);
            foreach (['USER-IDNUMBER', 'COURSE-IDNUMBER', 'HIDDEN-INSTRUCTION', 'HIDDEN-COMMENT', 'sesskey', 'token='] as $leak) {
                $this->assertStringNotContainsString($leak, $json, "{$tool} leaked {$leak}");
            }
            $this->assertIsArray($class::definition()->output_schema());
        }
        return $result;
    }

    public function test_get_connection_info(): void {
        $data = $this->run_tool($this->student, 'get_connection_info')->envelope['data'];
        $this->assertSame(fullname($this->student), $data['user_full_name']);
        $this->assertSame('student', $data['profile']);
        $this->assertSame('read', $data['access_level']);
        $this->assertSame(self::READ, $data['scopes']);
        $this->assertNull($data['assistant']);
        $this->assertMatchesRegularExpression('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $data['connection_expires_at']);
        $this->assertSame(60, $data['limits']['reads_per_minute']);
        $this->assertSame(50, $data['limits']['max_page_size']);
        $this->assertArrayNotHasKey('email', $data);
        $this->assertArrayNotHasKey('user_id', $data);

        $write = $this->run_tool($this->teacher, 'get_connection_info', [], 'teacher', ['profile', 'content.write']);
        $this->assertSame('read_write', $write->envelope['data']['access_level']);

        $this->assertSame('validation_error', $this->run_tool($this->student, 'get_connection_info', ['x' => 1])->error_code());
        $this->assertSame('scope_missing', $this->run_tool($this->student, 'get_connection_info', [], 'student', [])->error_code());
    }

    public function test_list_my_courses(): void {
        $result = $this->run_tool($this->student, 'list_my_courses');
        $courses = $result->envelope['data']['courses'];
        $this->assertSame(['Biology 101', 'History & Art 101'], array_column($courses, 'name'));
        $history = $courses[1];
        $this->assertSame((int) $this->course->id, $history['id']);
        $this->assertSame('HIS101', $history['short_name']);
        $this->assertSame(['Student'], $history['roles']);
        $this->assertTrue($history['visible']);
        $this->assertSame((new \moodle_url('/course/view.php', ['id' => $this->course->id]))->out(false), $history['url']);
        $this->assertSame(
            [
                'id', 'name', 'short_name', 'roles', 'start_date', 'end_date', 'progress_percent', 'completed', 'visible',
                'last_access', 'url',
            ],
            array_keys($history)
        );
        $this->assertNull($result->envelope['meta']['next_cursor']);

        // A teacher sees their own courses with their own role.
        $teaching = $this->run_tool($this->teacher, 'list_my_courses', [], 'teacher')->envelope['data']['courses'];
        $this->assertCount(1, $teaching);
        $this->assertSame(['Teacher'], $teaching[0]['roles']);

        // A user without enrolments gets an empty list, not somebody else's courses.
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], $this->run_tool($nobody, 'list_my_courses')->envelope['data']['courses']);
    }

    public function test_list_my_courses_hides_hidden_courses_unless_the_site_includes_them(): void {
        global $DB;
        $DB->set_field('course', 'visible', 0, ['id' => $this->course->id]);
        $names = array_column($this->run_tool($this->teacher, 'list_my_courses')->envelope['data']['courses'], 'name');
        $this->assertSame([], $names);
        // With their only course out of scope the teacher no longer qualifies for the teacher profile.
        $this->assertSame('profile_not_allowed', $this->run_tool($this->teacher, 'list_my_courses', [], 'teacher')->error_code());
        set_config('includehiddencourses', 1, 'local_pluginiaconnect');
        $names = array_column($this->run_tool($this->teacher, 'list_my_courses')->envelope['data']['courses'], 'name');
        $this->assertSame(['History & Art 101'], $names);
    }

    public function test_list_my_courses_pagination_and_arguments(): void {
        $first = $this->run_tool($this->student, 'list_my_courses', ['limit' => 1]);
        $this->assertSame(['Biology 101'], array_column($first->envelope['data']['courses'], 'name'));
        $cursor = $first->envelope['meta']['next_cursor'];
        $this->assertIsString($cursor);
        $second = $this->run_tool($this->student, 'list_my_courses', ['limit' => 1, 'cursor' => $cursor]);
        $this->assertSame(['History & Art 101'], array_column($second->envelope['data']['courses'], 'name'));
        $this->assertNull($second->envelope['meta']['next_cursor']);

        // The site maximum caps the page size.
        set_config('maxpagesize', 1, 'local_pluginiaconnect');
        $this->assertCount(1, $this->run_tool($this->student, 'list_my_courses', ['limit' => 50])->envelope['data']['courses']);

        foreach (
            [['limit' => 0], ['limit' => 51], ['limit' => '5'], ['cursor' => 'garbage'], ['user_id' => (int) $this->other->id],
                ['cursor' => rtrim(strtr(base64_encode(json_encode(['get_my_grades', 1])), '+/', '-_'), '=')],
                ['cursor' => rtrim(strtr(base64_encode(json_encode(['list_my_courses', -1])), '+/', '-_'), '=')]] as $args
        ) {
            $this->assertSame('validation_error', $this->run_tool($this->student, 'list_my_courses', $args)->error_code());
        }
    }

    public function test_get_course_outline(): void {
        $result = $this->run_tool($this->student, 'get_course_outline', ['course_id' => (int) $this->course->id]);
        $data = $result->envelope['data'];
        $this->assertSame('History & Art 101', $data['course_name']);
        $this->assertCount(4, $data['sections']);
        $section = $data['sections'][1];
        $this->assertSame(1, $section['number']);
        $this->assertSame('Week one.', $section['summary_user_content']);
        $this->assertSame(['Essay 2'], array_column($section['activities'], 'name'));
        $activity = $section['activities'][0];
        $this->assertSame('assign', $activity['type']);
        $this->assertSame((int) $this->assign->cmid, $activity['id']);
        $this->assertStringContainsString('/mod/assign/view.php?id=' . $this->assign->cmid, $activity['url']);
        $this->assertSame('Write the essay.', $activity['description_user_content']);
        $due = array_values(array_filter($activity['dates'], static fn($date) => str_contains($date['label'], 'Due')));
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->assign->duedate), $due[0]['at']);

        // The teacher also sees the activity hidden from students.
        $teacher = $this->run_tool($this->teacher, 'get_course_outline', ['course_id' => (int) $this->course->id], 'teacher');
        $names = array_column($teacher->envelope['data']['sections'][1]['activities'], 'name');
        $this->assertContains('Teachers only draft', $names);
        $this->assertStringNotContainsString('Teachers only draft', json_encode($result->envelope));
    }

    public function test_get_course_outline_access_arguments_and_pagination(): void {
        $nobody = $this->getDataGenerator()->create_user();
        $args = ['course_id' => (int) $this->course->id];
        $this->assertSame('not_found', $this->run_tool($nobody, 'get_course_outline', $args)->error_code());
        $missing = ['course_id' => 99999999];
        $this->assertSame('not_found', $this->run_tool($this->student, 'get_course_outline', $missing)->error_code());
        $this->assertSame(
            'out_of_scope',
            $this->run_tool($this->student, 'get_course_outline', ['course_id' => (int) $this->excluded->id])->error_code()
        );
        foreach ([[], ['course_id' => 0], ['course_id' => 'x'], $args + ['sql' => 'x']] as $bad) {
            $this->assertSame('validation_error', $this->run_tool($this->student, 'get_course_outline', $bad)->error_code());
        }

        $first = $this->run_tool($this->student, 'get_course_outline', $args + ['limit' => 3]);
        $this->assertSame([0, 1, 2], array_column($first->envelope['data']['sections'], 'number'));
        $cursor = $first->envelope['meta']['next_cursor'];
        $next = $this->run_tool($this->student, 'get_course_outline', $args + ['limit' => 3, 'cursor' => $cursor]);
        $this->assertSame([3], array_column($next->envelope['data']['sections'], 'number'));
        $this->assertNull($next->envelope['meta']['next_cursor']);
    }

    public function test_list_upcoming_deadlines(): void {
        $result = $this->run_tool($this->student, 'list_upcoming_deadlines');
        $deadlines = $result->envelope['data']['deadlines'];
        $this->assertSame(['Essay 2'], array_column($deadlines, 'title'), 'The deadline of the excluded course is not listed');
        $deadline = $deadlines[0];
        $this->assertSame('assign', $deadline['activity_type']);
        $this->assertSame((int) $this->course->id, $deadline['course_id']);
        $this->assertSame('History & Art 101', $deadline['course_name']);
        $this->assertSame(gmdate('Y-m-d\TH:i:s\Z', $this->assign->duedate), $deadline['due_at']);
        $this->assertFalse($deadline['overdue']);
        $this->assertStringContainsString('/mod/assign/view.php', $deadline['url']);
        $this->assertSame(
            ['title', 'activity_type', 'course_id', 'course_name', 'due_at', 'overdue', 'url'],
            array_keys($deadline)
        );

        $tomorrow = $this->run_tool($this->student, 'list_upcoming_deadlines', ['days_ahead' => 1]);
        $this->assertSame([], $tomorrow->envelope['data']['deadlines']);

        $bycourse = $this->run_tool($this->student, 'list_upcoming_deadlines', ['course_id' => (int) $this->course->id]);
        $this->assertSame(['Essay 2'], array_column($bycourse->envelope['data']['deadlines'], 'title'));
        $elsewhere = $this->run_tool($this->student, 'list_upcoming_deadlines', ['course_id' => (int) $this->second->id]);
        $this->assertSame([], $elsewhere->envelope['data']['deadlines']);

        // Somebody who is not in the course has no deadlines from it.
        $nobody = $this->getDataGenerator()->create_user();
        $this->assertSame([], $this->run_tool($nobody, 'list_upcoming_deadlines')->envelope['data']['deadlines']);
    }

    public function test_list_upcoming_deadlines_access_and_arguments(): void {
        $nobody = $this->getDataGenerator()->create_user();
        $args = ['course_id' => (int) $this->course->id];
        $this->assertSame('not_found', $this->run_tool($nobody, 'list_upcoming_deadlines', $args)->error_code());
        $missing = ['course_id' => 99999999];
        $this->assertSame('not_found', $this->run_tool($this->student, 'list_upcoming_deadlines', $missing)->error_code());
        $this->assertSame(
            'out_of_scope',
            $this->run_tool($this->student, 'list_upcoming_deadlines', ['course_id' => (int) $this->excluded->id])->error_code()
        );
        foreach (
            [['days_ahead' => 0], ['days_ahead' => 181], ['days_ahead' => 1.5], ['limit' => 51], ['user_id' => 2],
                ['cursor' => str_repeat('a', 201)]] as $bad
        ) {
            $this->assertSame('validation_error', $this->run_tool($this->student, 'list_upcoming_deadlines', $bad)->error_code());
        }
    }

    public function test_list_upcoming_deadlines_pagination(): void {
        $this->setAdminUser();
        for ($i = 1; $i <= 3; $i++) {
            $this->getDataGenerator()->create_module('assign', [
                'course' => $this->course->id, 'name' => "Extra {$i}", 'duedate' => time() + (3 + $i) * DAYSECS,
            ]);
        }
        $this->setUser(null);
        $titles = [];
        $cursor = null;
        $pages = 0;
        do {
            $args = ['limit' => 2] + ($cursor ? ['cursor' => $cursor] : []);
            $result = $this->run_tool($this->student, 'list_upcoming_deadlines', $args);
            $titles = array_merge($titles, array_column($result->envelope['data']['deadlines'], 'title'));
            $cursor = $result->envelope['meta']['next_cursor'];
            $pages++;
        } while ($cursor && $pages < 10);
        $this->assertSame(['Essay 2', 'Extra 1', 'Extra 2', 'Extra 3'], $titles);
        $this->assertLessThanOrEqual(3, $pages);
    }

    public function test_get_my_grades_overview(): void {
        $result = $this->run_tool($this->student, 'get_my_grades');
        $grades = $result->envelope['data']['course_grades'];
        $bycourse = array_column($grades, null, 'course_id');
        $this->assertArrayHasKey((int) $this->course->id, $bycourse);
        $this->assertArrayNotHasKey((int) $this->excluded->id, $bycourse, 'Grades of an excluded course are not listed');
        $entry = $bycourse[(int) $this->course->id];
        $this->assertSame('History & Art 101', $entry['course_name']);
        // Core hides a course total that includes a grade item hidden from the student.
        $this->assertNull($entry['grade']);
        $this->assertStringContainsString('70', (string) $bycourse[(int) $this->second->id]['grade']);
        $this->assertStringContainsString('/grade/report/user/index.php?id=' . $this->course->id, $entry['url']);
        $this->assertSame(['course_id', 'course_name', 'grade', 'url'], array_keys($entry));
        // Compare grade values only: course ids may contain any digits.
        $this->assertStringNotContainsString(
            '55',
            json_encode(array_column($grades, 'grade')),
            'No grade of another student'
        );
    }

    public function test_get_my_grades_of_one_course(): void {
        $result = $this->run_tool($this->student, 'get_my_grades', ['course_id' => (int) $this->course->id]);
        $data = $result->envelope['data'];
        $this->assertSame((int) $this->course->id, $data['course_id']);
        $items = array_column($data['grade_items'], null, 'name');
        $this->assertArrayHasKey('Manual one', $items);
        $manual = $items['Manual one'];
        $this->assertSame('80.00', $manual['grade']);
        $this->assertSame(80.0, (float) $manual['grade_raw']);
        $this->assertSame(100.0, (float) $manual['grade_max']);
        $this->assertSame('Well done.', $manual['feedback_user_content']);
        $this->assertArrayHasKey('Course total', $items);

        $json = json_encode($result->envelope);
        $this->assertStringNotContainsString('OTHER-FEEDBACK', $json);
        $this->assertStringNotContainsString('33.33', $json, 'A grade hidden from the student is not revealed');
        $this->assertStringNotContainsString(fullname($this->other), $json);

        // The other student sees their own grade and nothing of the first one.
        $theirs = json_encode($this->run_tool($this->other, 'get_my_grades', ['course_id' => (int) $this->course->id])->envelope);
        $this->assertStringContainsString('55.00', $theirs);
        $this->assertStringContainsString('OTHER-FEEDBACK', $theirs);
        $this->assertStringNotContainsString('Well done', $theirs);
    }

    public function test_get_my_grades_access_arguments_and_pagination(): void {
        global $DB;
        $nobody = $this->getDataGenerator()->create_user();
        $args = ['course_id' => (int) $this->course->id];
        $this->assertSame('not_found', $this->run_tool($nobody, 'get_my_grades', $args)->error_code());
        $this->assertSame('not_found', $this->run_tool($this->student, 'get_my_grades', ['course_id' => 99999999])->error_code());
        $this->assertSame(
            'out_of_scope',
            $this->run_tool($this->student, 'get_my_grades', ['course_id' => (int) $this->excluded->id])->error_code()
        );
        // There is no way to name another user.
        $otherid = (int) $this->other->id;
        foreach ([$args + ['user_id' => $otherid], $args + ['userid' => $otherid], ['course_id' => '1']] as $bad) {
            $this->assertSame('validation_error', $this->run_tool($this->student, 'get_my_grades', $bad)->error_code());
        }
        $noscope = $this->run_tool($this->student, 'get_my_grades', $args, 'student', ['profile']);
        $this->assertSame('scope_missing', $noscope->error_code());

        $first = $this->run_tool($this->student, 'get_my_grades', $args + ['limit' => 1]);
        $this->assertCount(1, $first->envelope['data']['grade_items']);
        $this->assertIsString($first->envelope['meta']['next_cursor']);

        // A student whose role may not see the grade report.
        $roles = get_archetype_roles('student');
        $contextid = \context_course::instance($this->course->id)->id;
        assign_capability('gradereport/user:view', CAP_PROHIBIT, reset($roles)->id, $contextid, true);
        $this->assertSame('permission_denied', $this->run_tool($this->student, 'get_my_grades', $args)->error_code());
        unassign_capability('gradereport/user:view', reset($roles)->id, $contextid);

        // A course that does not show grades to students returns none.
        $DB->set_field('course', 'showgrades', 0, ['id' => $this->course->id]);
        $none = $this->run_tool($this->student, 'get_my_grades', $args);
        $this->assertSame([], $none->envelope['data']['grade_items']);
    }

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
        $this->setUser(null);

        // Independent statement of the rules, not derived from the code under test.
        $eligible = [
            'student' => ['student', 'teacher', 'editingteacher', 'manager', 'admin', 'notenrolled'],
            'teacher' => ['teacher', 'editingteacher', 'manager', 'admin'],
            'manager' => ['manager', 'admin'],
            'admin' => ['admin'],
        ];
        $coursearg = ['course_id' => (int) $this->course->id];
        $tools = [
            'get_connection_info' => [],
            'list_my_courses' => [],
            'get_course_outline' => $coursearg,
            'list_upcoming_deadlines' => $coursearg,
            'get_my_grades' => $coursearg,
        ];
        $cases = 0;
        foreach ($tools as $tool => $args) {
            $this->assertNull(schema_validator::validate($args, tool_registry::get($tool)::definition()->properties, []));
            foreach ($eligible as $profile => $roles) {
                foreach ($users as $role => $user) {
                    if ($args && $role === 'notenrolled') {
                        // A course the user cannot enter does not exist for them, whatever the profile.
                        $expected = 'not_found';
                    } else if (!in_array($role, $roles, true)) {
                        $expected = 'profile_not_allowed';
                    } else {
                        $expected = 'ok';
                    }
                    $result = $this->run_tool($user, $tool, $args, $profile);
                    $actual = $result->iserror ? $result->error_code() : 'ok';
                    $this->assertSame($expected, $actual, "{$tool} with a {$profile} token held by a real {$role}");
                    $cases++;
                }
            }
        }
        $this->assertSame(count($tools) * 4 * 6, $cases, 'No cell of the matrix without a case');

        // The same matrix without the read scopes is refused everywhere before anything else is looked at.
        foreach (array_keys($tools) as $tool) {
            foreach ($users as $role => $user) {
                $result = $this->run_tool($user, $tool, $tools[$tool], 'student', ['offline_access']);
                $this->assertSame('scope_missing', $result->error_code(), "{$tool} without scope for {$role}");
            }
        }
    }
}
