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
use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/mod/assign/locallib.php');

/**
 * Regression tests for the verification pass of the second security review (N1 to N3 and the rest of L1).
 * The cases of the text sanitiser itself are in text_sanitizer_test.php; N5 is in privacy/provider_test.php.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\tool\read\get_course_outline
 * @covers     \local_pluginiaconnect\local\tool\read\get_assignment_details
 * @covers     \local_pluginiaconnect\local\tool\read\get_submission
 * @covers     \local_pluginiaconnect\local\tool\read\search_courses
 * @covers     \local_pluginiaconnect\local\tool\assign_helper
 * @covers     \local_pluginiaconnect\local\mcp\call_pipeline
 * @covers     \local_pluginiaconnect\local\tool\write\enrol_users_in_course
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_course_outline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_assignment_details::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\get_submission::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\read\search_courses::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\assign_helper::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\call_pipeline::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\tool\write\enrol_users_in_course::class)]
final class security_review2_verification_test extends \advanced_testcase {
    /** @var \stdClass Course. */
    private \stdClass $course;
    /** @var \stdClass Student. */
    private \stdClass $student;
    /** @var \stdClass Editing teacher. */
    private \stdClass $teacher;

    /**
     * A course with a student and an editing teacher.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $this->course = $generator->create_course(['fullname' => 'Chemistry 101', 'shortname' => 'CHEM101']);
        $this->student = $generator->create_and_enrol($this->course, 'student', ['firstname' => 'Ana', 'lastname' => 'Alvarez']);
        $this->teacher = $generator->create_and_enrol($this->course, 'editingteacher', ['firstname' => 'Tina']);
        \local_pluginiaconnect_mcp_fixture::enable();
        $this->setUser(null);
    }

    /**
     * Runs a tool through the pipeline.
     *
     * @param \stdClass $user User.
     * @param string $tool Tool name.
     * @param array $args Arguments.
     * @param string $profile Profile of the connection.
     * @return array [pipeline result, grant]
     */
    private function run_tool(\stdClass $user, string $tool, array $args, string $profile): array {
        [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, scope::max_for_profile($profile));
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
    private function data(\stdClass $user, string $tool, array $args, string $profile = 'student'): array {
        [$result] = $this->run_tool($user, $tool, $args, $profile);
        $this->assertFalse($result->iserror, "{$tool}: " . json_encode($result->envelope['error'] ?? null));
        return $result->envelope['data'];
    }

    /**
     * N1.1: a style sheet in one text of the course page withholds every author text of the page, because
     * it can hide parts of any of them.
     */
    public function test_n1_style_sheet_elsewhere_on_the_course_page(): void {
        global $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $generator->create_module('label', ['course' => $this->course->id,
            'intro' => '<style>#section-1 em{display:none}</style><p>Welcome</p>']);
        $DB->set_field('course_sections', 'summary', '<p>Read this.<em>HIDDEN-BY-OTHER-FRAGMENT</em></p>', [
            'course' => $this->course->id, 'section' => 1,
        ]);
        $DB->set_field('course_sections', 'summaryformat', FORMAT_HTML, ['course' => $this->course->id, 'section' => 1]);
        rebuild_course_cache($this->course->id, true);
        $this->setUser(null);

        $data = $this->data($this->student, 'get_course_outline', ['course_id' => (int) $this->course->id]);
        $json = json_encode($data);
        $this->assertStringNotContainsString('HIDDEN-BY-OTHER-FRAGMENT', $json);
        $this->assertStringNotContainsString('Read this', $json);
        $summaries = array_column($data['sections'], 'summary_user_content', 'number');
        $this->assertSame(text_sanitizer::STYLESHEET_NOTE, $summaries[1]);

        // The same holds for the summaries listed together by the search.
        $this->setAdminUser();
        $generator->create_course(['fullname' => 'Findable one', 'summaryformat' => FORMAT_HTML,
            'summary' => '<style>.coursebox em{display:none}</style>']);
        $generator->create_course(['fullname' => 'Findable two', 'summaryformat' => FORMAT_HTML,
            'summary' => '<p>Open.<em>HIDDEN-IN-THE-LIST</em></p>']);
        $this->setUser(null);
        $found = $this->data($this->student, 'search_courses', ['search_text' => 'Findable']);
        $this->assertCount(2, $found['courses']);
        $this->assertStringNotContainsString('HIDDEN-IN-THE-LIST', json_encode($found));
        // A search that does not list the course with the style sheet returns the other summary.
        $found = $this->data($this->student, 'search_courses', ['search_text' => 'Findable two']);
        $this->assertStringContainsString('HIDDEN-IN-THE-LIST', $found['courses'][0]['summary_user_content']);
    }

    /**
     * N1.8: with the multi-language filter on, only the block of the reader's language is returned.
     */
    public function test_n1_multilang_blocks_of_other_languages(): void {
        global $DB;
        $this->setAdminUser();
        filter_set_global_state('multilang', TEXTFILTER_ON);
        $generator = $this->getDataGenerator();
        $text = '<span lang="en" class="multilang">English text</span>'
            . '<span lang="xx" class="multilang">OTHER-LANGUAGE-BLOCK</span>';
        $module = $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Essay', 'intro' => $text, 'introformat' => FORMAT_HTML,
            'submissiondrafts' => 0, 'assignsubmission_onlinetext_enabled' => 1,
        ]);
        $generator->get_plugin_generator('mod_assign')->create_submission([
            'userid' => $this->student->id, 'cmid' => $module->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
            'onlinetext' => $text,
        ]);
        $submissionid = (int) $DB->get_field('assign_submission', 'id', ['assignment' => $module->id,
            'userid' => $this->student->id, 'latest' => 1]);
        $this->setAdminUser();
        $this->setUser(null);

        $details = $this->data($this->student, 'get_assignment_details', ['assignment_id' => (int) $module->cmid]);
        $this->assertSame('English text', $details['instructions_user_content']);
        $submission = $this->data($this->teacher, 'get_submission', ['submission_id' => $submissionid], 'teacher');
        $this->assertStringContainsString('English text', json_encode($submission));
        $this->assertStringNotContainsString('OTHER-LANGUAGE-BLOCK', json_encode($submission));
    }

    /**
     * N2: an activity hidden from the user, an activity of a course the user cannot enter and an
     * identifier that does not exist give exactly the same answer.
     */
    public function test_n2_hidden_and_missing_activities_cannot_be_told_apart(): void {
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $hidden = $generator->create_module('assign', ['course' => $this->course->id, 'name' => 'Hidden essay', 'visible' => 0]);
        $elsewhere = $generator->create_course();
        $foreign = $generator->create_module('assign', ['course' => $elsewhere->id, 'name' => 'Foreign essay']);
        $this->setUser(null);

        $answers = [];
        foreach ([(int) $hidden->cmid, (int) $foreign->cmid, 99999999] as $id) {
            [$result] = $this->run_tool($this->student, 'get_assignment_details', ['assignment_id' => $id], 'student');
            $this->assertTrue($result->iserror);
            $answers[] = [$result->error_code(), $result->envelope['error']['message']];
        }
        $this->assertSame(['not_found', base_tool::ITEM_NOT_FOUND], $answers[0]);
        $this->assertSame($answers[0], $answers[1]);
        $this->assertSame($answers[0], $answers[2]);
        $this->assertStringNotContainsString('Course', $answers[0][1]);
    }

    /**
     * N3: under anonymous marking the participant numbers are not handed out in name order.
     */
    public function test_n3_participant_numbers_do_not_follow_names(): void {
        global $DB;
        $this->setAdminUser();
        $generator = $this->getDataGenerator();
        $students = [];
        foreach (range('a', 'l') as $letter) {
            $students[] = $generator->create_and_enrol($this->course, 'student', [
                'firstname' => 'Name' . $letter, 'lastname' => 'Surname' . $letter,
            ]);
        }
        $module = $generator->create_module('assign', [
            'course' => $this->course->id, 'name' => 'Anonymous essay', 'blindmarking' => 1, 'submissiondrafts' => 0,
            'assignsubmission_onlinetext_enabled' => 1,
        ]);
        foreach ($students as $student) {
            $generator->get_plugin_generator('mod_assign')->create_submission([
                'userid' => $student->id, 'cmid' => $module->cmid, 'status' => ASSIGN_SUBMISSION_STATUS_SUBMITTED,
                'onlinetext' => 'Answer',
            ]);
        }
        // The submissions above did not hand out any number: the tool is the first to ask for them.
        $DB->delete_records('assign_user_mapping', ['assignment' => $module->id]);
        $this->setAdminUser();
        $this->setUser(null);

        $data = $this->data($this->teacher, 'list_submissions_to_grade', ['course_id' => (int) $this->course->id], 'teacher');
        $this->assertCount(12, $data['submissions']);
        $byname = [];
        foreach ($students as $student) {
            $byname[] = (int) $DB->get_field('assign_user_mapping', 'id', ['assignment' => $module->id,
                'userid' => $student->id], MUST_EXIST);
        }
        $sorted = $byname;
        sort($sorted);
        // Twelve students in a random order match the name order once in 479 million runs.
        $this->assertNotSame($sorted, $byname);
    }

    /**
     * Rest of L1: the refusal of an enrolment that would be reactivated gives identifiers, not names.
     */
    public function test_l1_refusal_does_not_name_users(): void {
        global $DB;
        $this->setAdminUser();
        $manager = $this->getDataGenerator()->create_user();
        $managerroles = get_archetype_roles('manager');
        role_assign(reset($managerroles)->id, $manager->id, \context_system::instance()->id);
        foreach (profile::all() as $candidate) {
            set_config("tool_enrol_users_in_course_{$candidate}", 1, 'local_pluginiaconnect');
        }
        $instance = $DB->get_record('enrol', ['courseid' => $this->course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        enrol_get_plugin('manual')->update_user_enrol($instance, $this->student->id, ENROL_USER_SUSPENDED);
        $this->setUser(null);

        [$result, $grant] = $this->run_tool($manager, 'enrol_users_in_course', [
            'course_id' => (int) $this->course->id, 'user_ids' => [(int) $this->student->id],
        ], 'manager');
        $this->assertTrue($result->iserror);
        $message = $result->envelope['error']['message'];
        $this->assertStringContainsString("id {$this->student->id}", $message);
        $this->assertStringNotContainsString('Alvarez', $message);
        $this->assertSame(0, exposure_budget::used($grant->grantid));
    }
}
