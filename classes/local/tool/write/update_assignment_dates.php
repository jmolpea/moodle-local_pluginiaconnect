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
 * Tool 27: changes the opening, due and cut-off dates of an assignment.
 *
 * Only the three date fields of the assignment are written, followed by the same
 * calendar and cache refresh the assignment settings form triggers. Dates can be
 * moved but not removed through this tool.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class update_assignment_dates extends write_tool {
    /** @var array Argument => [assignment field, label]. */
    private const FIELDS = [
        'open_date' => ['allowsubmissionsfromdate', 'Opens'],
        'due_date' => ['duedate', 'Due'],
        'cutoff_date' => ['cutoffdate', 'Cut-off'],
    ];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'update_assignment_dates';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        $date = static fn(string $what): array => [
            'type' => 'string', 'maxLength' => 40,
            'description' => "New {$what} as an ISO 8601 date-time with a time zone. Omit it to leave the date as it is.",
        ];
        return new tool_definition(
            self::name(),
            'Update assignment dates',
            'Changes one or more of the dates of one assignment: when submissions open, when they are due and the '
                . 'cut-off after which none are accepted, replacing the current values for every student. Dates that are '
                . 'not given stay as they are, and a date cannot be removed through this tool.' . self::two_step_text(false),
            [
                'assignment_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Identifier of the assignment activity, as returned by 'get_course_outline'.",
                ],
                'open_date' => $date('opening date'),
                'due_date' => $date('due date'),
                'cutoff_date' => $date('cut-off date'),
            ] + self::control_properties(),
            ['assignment_id'],
            self::result_schema([
                'assignment_id' => ['type' => 'integer'],
                'course_id' => ['type' => 'integer'],
                'open_date' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'due_date' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                'cutoff_date' => ['type' => ['string', 'null'], 'format' => 'date-time'],
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
            ['moodle/course:manageactivities', 'mod/assign:addinstance'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The assignment the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        return \context_module::instance($cm->id);
    }

    /**
     * Validates the new dates against the assignment.
     *
     * @param array $args Validated arguments.
     * @return array Plan: cm, course, instance (assign record), new (field => timestamp).
     */
    private static function plan(array $args): array {
        global $DB;
        [$cmrecord, $course] = self::activity('assign', (int) $args['assignment_id'], 'Assignment');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Assignment');
        $instance = $DB->get_record('assign', ['id' => $cm->instance], '*', MUST_EXIST);
        $new = [];
        foreach (self::FIELDS as $argument => [$field]) {
            if (array_key_exists($argument, $args)) {
                $new[$field] = self::timestamp((string) $args[$argument], $argument);
            }
        }
        if (!$new) {
            throw new tool_exception('validation_error', "Give at least one of 'open_date', 'due_date' and 'cutoff_date'.");
        }
        $result = $new + [
            'allowsubmissionsfromdate' => (int) $instance->allowsubmissionsfromdate,
            'duedate' => (int) $instance->duedate,
            'cutoffdate' => (int) $instance->cutoffdate,
        ];
        // Same order rules as the assignment settings form.
        $opens = $result['allowsubmissionsfromdate'];
        if ($result['duedate'] && $opens && $result['duedate'] <= $opens) {
            throw new tool_exception('validation_error', 'The due date must be after the opening date.');
        }
        if ($result['cutoffdate'] && $result['duedate'] && $result['cutoffdate'] < $result['duedate']) {
            throw new tool_exception('validation_error', 'The cut-off date cannot be before the due date.');
        }
        if (
            $result['cutoffdate'] && $result['allowsubmissionsfromdate']
                && $result['cutoffdate'] <= $result['allowsubmissionsfromdate']
        ) {
            throw new tool_exception('validation_error', 'The cut-off date must be after the opening date.');
        }
        // The "remind me to grade by" date is not changed by this tool, so the new dates must still fit it.
        $gradeby = (int) $instance->gradingduedate;
        if ($gradeby && (($opens && $opens > $gradeby) || ($result['duedate'] && $result['duedate'] > $gradeby))) {
            throw new tool_exception(
                'validation_error',
                "The opening and due dates cannot be after the assignment's 'Remind me to grade by' date ("
                    . self::shown_date($gradeby) . '). Change that date on the site first.'
            );
        }
        return ['cm' => $cm, 'course' => $course, 'instance' => $instance, 'new' => $new];
    }

    /**
     * Description of the change: each date that moves, from and to.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args);
        $changes = [];
        foreach (self::FIELDS as [$field, $label]) {
            if (isset($plan['new'][$field])) {
                $changes[] = "{$label}: " . self::shown_date((int) $plan['instance']->{$field}) . ' -> '
                    . self::shown_date($plan['new'][$field]);
            }
        }
        return "Change the dates of '" . self::label($plan['cm']->get_formatted_name()) . "' (Course: "
            . self::label(format_string($plan['course']->fullname, true, [
                'context' => \context_course::instance($plan['course']->id),
            ]))
            . ') for every student. ' . implode('. ', $changes) . '. Times are shown in your time zone.';
    }

    /**
     * Applies the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/assign/lib.php');
        require_once($CFG->dirroot . '/course/lib.php');
        $plan = self::plan($args);
        $cm = $plan['cm'];
        require_capability('moodle/course:manageactivities', $cm->context);

        // Core has no function that changes only the dates: the settings form rewrites every setting.
        // Only these three fields are written, then calendar events and the course cache are refreshed.
        $update = (object) (['id' => (int) $plan['instance']->id, 'timemodified' => time()] + $plan['new']);
        $DB->update_record('assign', $update);
        $instance = $DB->get_record('assign', ['id' => $plan['instance']->id], '*', MUST_EXIST);
        $cmrecord = get_coursemodule_from_id('assign', $cm->id, 0, false, MUST_EXIST);
        assign_refresh_events((int) $plan['course']->id, $instance, $cmrecord);
        rebuild_course_cache((int) $plan['course']->id, true);
        \core\event\course_module_updated::create_from_cm($cmrecord, $cm->context)->trigger();

        return new tool_result([
            'assignment_id' => (int) $cm->id,
            'course_id' => (int) $plan['course']->id,
            'open_date' => self::iso((int) $instance->allowsubmissionsfromdate),
            'due_date' => self::iso((int) $instance->duedate),
            'cutoff_date' => self::iso((int) $instance->cutoffdate),
            'url' => (new \moodle_url('/mod/assign/view.php', ['id' => $cm->id]))->out(false),
        ], 1, null, 'assign', (int) $instance->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['assignment_id', 'course_id', 'open_date', 'due_date', 'cutoff_date', 'url'];
    }
}
