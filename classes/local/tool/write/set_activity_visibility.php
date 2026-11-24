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

namespace local_pluginiaconnect\local\tool\write;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 26: shows or hides one activity of a course.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_activity_visibility extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'set_activity_visibility';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Set activity visibility',
            'Shows or hides one activity or resource of a course for its students, replacing its current visibility. '
                . 'Use it when a teacher asks to hide or show a named activity found with the course outline.'
                . self::two_step_text(false),
            [
                'activity_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Identifier of the activity, as returned by 'get_course_outline'.",
                ],
                'visible' => ['type' => 'boolean', 'description' => 'True to show the activity, false to hide it.'],
            ] + self::control_properties(),
            ['activity_id', 'visible'],
            self::result_schema([
                'activity_id' => ['type' => 'integer'],
                'activity_name' => ['type' => 'string'],
                'course_id' => ['type' => 'integer'],
                'visible' => ['type' => 'boolean'],
                'changed' => ['type' => 'boolean'],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            true,
            true
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::TEACHER,
            'content.write',
            ['moodle/course:activityvisibility'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The activity the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        $cm = get_coursemodule_from_id('', (int) $args['activity_id']);
        if (!$cm) {
            throw self::activity_not_found('Activity', (int) $args['activity_id']);
        }
        self::course((int) $cm->course);
        return \context_module::instance($cm->id);
    }

    /**
     * Loads the activity and checks the change can be made.
     *
     * @param array $args Validated arguments.
     * @return array [cm_info, course record]
     */
    private static function plan(array $args): array {
        $cmid = (int) $args['activity_id'];
        $cm = get_coursemodule_from_id('', $cmid);
        if (!$cm) {
            throw self::activity_not_found('Activity', $cmid);
        }
        $course = self::course((int) $cm->course);
        $info = self::visible_activity($course, $cmid, 'Activity');
        if (!empty($info->deletioninprogress)) {
            throw self::activity_not_found('Activity', $cmid);
        }
        return [$info, $course];
    }

    /**
     * Description of the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        [$info, $course] = self::plan($args);
        return ($args['visible'] ? 'Show' : 'Hide') . " '" . self::label($info->get_formatted_name()) . "' (Course: "
            . self::label(format_string($course->fullname, true, ['context' => \context_course::instance($course->id)]))
            . '). Currently ' . ($info->visible ? 'visible' : 'hidden') . ' to students.';
    }

    /**
     * Applies the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        [$info, $course] = self::plan($args);
        require_capability('moodle/course:activityvisibility', $info->context);
        $visible = $args['visible'] ? 1 : 0;
        $changed = (int) $info->visible !== $visible;
        if ($changed) {
            if (!set_coursemodule_visible($info->id, $visible)) {
                throw new tool_exception('internal_error', 'The visibility of the activity could not be changed.');
            }
            \core\event\course_module_updated::create_from_cm(
                get_coursemodule_from_id('', $info->id, 0, false, MUST_EXIST),
                $info->context
            )->trigger();
        }
        return new tool_result([
            'activity_id' => (int) $info->id,
            'activity_name' => self::label($info->get_formatted_name()),
            'course_id' => (int) $course->id,
            'visible' => (bool) $visible,
            'changed' => $changed,
            'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        ], 1, null, 'course_module', (int) $info->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['activity_id', 'activity_name', 'course_id', 'visible', 'changed', 'url'];
    }
}
