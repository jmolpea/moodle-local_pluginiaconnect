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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 29: suspends or reactivates the manual enrolment of named users in a course.
 *
 * Nothing is removed. Suspending changes the status of the enrolment and nothing else:
 * the enrolment, the roles, the groups, the grades and the submissions of the user stay,
 * and reactivating gives the access back. The connector has no tool that unenrols.
 *
 * It always needs the user's approval on the site. Enrolments made by any other method
 * are never touched, and the caller cannot change their own enrolment.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_enrolment_status extends write_tool {
    /** @var string[] Accepted values of the 'status' argument => status of the enrolment in the site. */
    private const STATUSES = ['suspended' => ENROL_USER_SUSPENDED, 'active' => ENROL_USER_ACTIVE];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'set_enrolment_status';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Set enrolment status',
            'Requests that the manual enrolment of up to 50 named users in one course be suspended or made active '
                . 'again. A suspended user cannot enter the course, and their enrolment, roles, groups, grades and '
                . 'submissions are all kept, so the change can be undone at any time. Enrolments created by other '
                . 'methods (self, cohort, payment) are not affected, and nobody is unenrolled.' . self::approval_text(),
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course of the enrolments.'],
                'user_ids' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => "Users whose enrolment changes, as returned by 'list_course_participants'.",
                ],
                'status' => [
                    'type' => 'string', 'enum' => array_keys(self::STATUSES),
                    'description' => "'suspended' to take the access to the course away, 'active' to give it back.",
                ],
            ] + self::control_properties(false),
            ['course_id', 'user_ids', 'status'],
            self::result_schema([
                'course_id' => ['type' => 'integer'],
                'enrolment_status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
                'changed' => ['type' => 'boolean'],
                'users' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'required' => ['user_id', 'previous_status', 'new_status'],
                        'properties' => [
                            'user_id' => ['type' => 'integer'],
                            'previous_status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
                            'new_status' => ['type' => 'string', 'enum' => array_keys(self::STATUSES)],
                        ],
                    ],
                ],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            true,
            true
        );
    }

    /**
     * Requirements: the capability the site asks for to edit the status of a manual enrolment.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::MANAGER,
            'enrol.write',
            ['enrol/manual:manage'],
            true,
            tool_requirement::APPROVAL_APPROVAL
        );
    }

    /**
     * The course the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    /**
     * Checks that every named user has a manual enrolment in the course and reads its status.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: course, context, plugin, status (name), users (records), ids, enrolments
     *         (user id => rows with the enrolment instance and the current status), elsewhere (user ids
     *         with an active enrolment by another method).
     */
    private static function plan(array $args, call_context $call): array {
        global $DB;
        $course = self::course((int) $args['course_id']);
        $context = \context_course::instance($course->id);
        $status = (string) $args['status'];
        if (!isset(self::STATUSES[$status])) {
            throw new tool_exception('validation_error', "'status' must be 'suspended' or 'active'.");
        }
        $ids = array_values(array_unique(array_map('intval', $args['user_ids'])));
        $max = config::int('maxbulkusers');
        if (count($ids) > $max) {
            throw new tool_exception('limit_exceeded', "The enrolment of a maximum of {$max} users can be changed per call.");
        }
        if (in_array((int) $call->user->id, $ids, true)) {
            throw new tool_exception('validation_error', 'You cannot change your own enrolment through the connector.');
        }
        $plugin = enrol_is_enabled('manual') ? enrol_get_plugin('manual') : null;
        if (!$plugin) {
            throw new tool_exception('validation_error', 'Manual enrolment is not available on this site.');
        }

        [$insql, $inparams] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', "id {$insql} AND deleted = 0", $inparams);
        $params = $inparams + ['courseid' => $course->id];
        $rows = $DB->get_records_sql(
            "SELECT ue.id, ue.userid, ue.status, ue.enrolid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.enrol = 'manual' AND ue.userid {$insql}
           ORDER BY ue.id",
            $params
        );
        $instances = $DB->get_records('enrol', ['courseid' => $course->id, 'enrol' => 'manual']);
        $enrolments = [];
        foreach ($rows as $row) {
            // The site only lets the status of an enrolment be edited where its method allows it.
            if (isset($instances[$row->enrolid]) && $plugin->allow_manage($instances[$row->enrolid])) {
                $row->instance = $instances[$row->enrolid];
                $enrolments[(int) $row->userid][] = $row;
            }
        }
        foreach ($ids as $id) {
            if (!isset($users[$id]) || empty($enrolments[$id])) {
                throw new tool_exception(
                    'validation_error',
                    'One or more of the users have no manual enrolment in this course. Nothing was requested.'
                );
            }
        }
        // Who keeps another way into the course: the person who approves is told.
        $elsewhere = $DB->get_fieldset_sql(
            "SELECT DISTINCT ue.userid
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.enrol <> 'manual' AND e.status = :enabled AND ue.status = :active
                    AND ue.userid {$insql}",
            $params + ['enabled' => ENROL_INSTANCE_ENABLED, 'active' => ENROL_USER_ACTIVE]
        );
        return ['course' => $course, 'context' => $context, 'plugin' => $plugin, 'status' => $status, 'users' => $users,
            'ids' => $ids, 'enrolments' => $enrolments, 'elsewhere' => array_map('intval', $elsewhere)];
    }

    /**
     * Status of the manual enrolment of a user as the caller is told: suspended only when every
     * manual enrolment of the user in the course is suspended.
     *
     * @param \stdClass[] $rows Manual enrolments of the user in the course.
     * @return string 'active' or 'suspended'.
     */
    private static function current(array $rows): string {
        foreach ($rows as $row) {
            if ((int) $row->status === ENROL_USER_ACTIVE) {
                return 'active';
            }
        }
        return 'suspended';
    }

    /**
     * Description of the change: who, from which status and to which one.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args, $call);
        $names = [];
        $others = [];
        $changes = 0;
        foreach ($plan['ids'] as $id) {
            $name = people::name($plan['users'][$id], $plan['context']) . " (id {$id})";
            $current = self::current($plan['enrolments'][$id]);
            $changes += $current === $plan['status'] ? 0 : 1;
            $names[] = $name . ($current === $plan['status'] ? ", already {$current}" : ", now {$current}");
            if (in_array($id, $plan['elsewhere'], true)) {
                $others[] = $name;
            }
        }
        $course = self::label(format_string($plan['course']->fullname, true, ['context' => $plan['context']]));
        $text = ($plan['status'] === 'suspended' ? 'Suspend' : 'Reactivate') . ' the manual enrolment of '
            . count($names) . " user(s) in the course {$course}, setting it to '{$plan['status']}': "
            . implode('; ', $names) . '. ';
        if ($changes === 0) {
            $text .= 'Nothing will change. ';
        }
        if ($plan['status'] === 'suspended') {
            $text .= 'A suspended user cannot enter the course. Nothing is deleted: the enrolment, roles, groups, '
                . 'grades and submissions are kept, and the enrolment can be made active again.';
            if ($others) {
                $text .= ' These users are also enrolled by another method and will still be able to enter: '
                    . implode('; ', $others) . '.';
            }
        } else {
            $text .= 'An active user can enter the course again, with the roles, groups and grades they had.';
        }
        return $text;
    }

    /**
     * Applies the change with the function of the site that edits the status of an enrolment.
     * It updates the status and nothing else: no row is removed.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        $target = self::STATUSES[$plan['status']];
        $users = [];
        $changed = false;
        foreach ($plan['ids'] as $id) {
            $previous = self::current($plan['enrolments'][$id]);
            foreach ($plan['enrolments'][$id] as $row) {
                if ((int) $row->status !== $target) {
                    $plan['plugin']->update_user_enrol($row->instance, $id, $target);
                    $changed = true;
                }
            }
            $users[] = ['user_id' => $id, 'previous_status' => $previous, 'new_status' => $plan['status']];
        }
        return new tool_result([
            'course_id' => (int) $plan['course']->id,
            'enrolment_status' => $plan['status'],
            'changed' => $changed,
            'users' => $users,
            'url' => (new \moodle_url('/user/index.php', ['id' => $plan['course']->id]))->out(false),
        ], count($users), null, 'course', (int) $plan['course']->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'enrolment_status', 'changed', 'users' => ['*' => ['user_id', 'previous_status', 'new_status']],
            'url'];
    }
}
