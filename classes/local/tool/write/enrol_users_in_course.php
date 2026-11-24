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
 * Tool 28: enrols named users in a course with the manual enrolment method.
 *
 * The role must be one the user can assign in that course, and it can never be a
 * role of the manager archetype.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class enrol_users_in_course extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'enrol_users_in_course';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Enrol users in course',
            'Enrols up to 50 named users in one course with the manual enrolment method and one role, which is the '
                . 'student role unless another is given. Users already enrolled manually keep their enrolment and '
                . 'gain the role; a user whose manual enrolment is suspended or limited in time is refused, because '
                . 'enrolling again would reactivate it.' . self::two_step_text(false),
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course to enrol in.'],
                'user_ids' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => "Users to enrol, as returned by 'find_users'.",
                ],
                'role' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 100, 'default' => 'student',
                    'description' => 'Short name of the role to give, for example student or teacher.',
                ],
            ] + self::control_properties(),
            ['course_id', 'user_ids'],
            self::result_schema([
                'course_id' => ['type' => 'integer'],
                'role' => ['type' => 'string'],
                'enrolled' => ['type' => 'integer'],
                'user_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            false,
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
            profile::MANAGER,
            'enrol.write',
            ['enrol/manual:enrol'],
            true,
            tool_requirement::APPROVAL_CONFIRM
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
     * Checks the enrolment method, the role and the users.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: course, context, role (record), users (records).
     */
    private static function plan(array $args, call_context $call): array {
        global $DB;
        $course = self::course((int) $args['course_id']);
        $context = \context_course::instance($course->id);
        $instance = ['courseid' => $course->id, 'enrol' => 'manual', 'status' => ENROL_INSTANCE_ENABLED];
        if (!enrol_is_enabled('manual') || !$DB->record_exists('enrol', $instance)) {
            throw new tool_exception('validation_error', 'Manual enrolment is not available in this course.');
        }
        $ids = array_values(array_unique(array_map('intval', $args['user_ids'])));
        $max = config::int('maxbulkusers');
        if (count($ids) > $max) {
            throw new tool_exception('limit_exceeded', "A maximum of {$max} users can be enrolled per call.");
        }

        // Only a role the user may assign here, and never one of the manager archetype.
        $shortname = (string) ($args['role'] ?? 'student');
        $role = $DB->get_record('role', ['shortname' => $shortname]);
        $assignable = get_assignable_roles($context, ROLENAME_SHORT, false, $call->user);
        if (!$role || !isset($assignable[$role->id]) || $role->archetype === 'manager') {
            throw new tool_exception('validation_error', "'role' is not a role you can give in this course through the connector.");
        }

        [$insql, $params] = $DB->get_in_or_equal($ids, SQL_PARAMS_NAMED);
        $users = $DB->get_records_select('user', "id {$insql} AND deleted = 0 AND confirmed = 1", $params);
        foreach ($ids as $id) {
            if (!isset($users[$id]) || isguestuser($users[$id])) {
                throw new tool_exception('validation_error', 'One or more of the users were not found.');
            }
        }
        // The enrolment function of the site would silently reactivate a suspended manual enrolment and
        // remove the dates of a time-limited one. Those are different changes: they are made on the site.
        $existing = $DB->get_records_sql(
            "SELECT ue.userid, ue.status, ue.timestart, ue.timeend
               FROM {user_enrolments} ue
               JOIN {enrol} e ON e.id = ue.enrolid
              WHERE e.courseid = :courseid AND e.enrol = 'manual' AND ue.userid {$insql}",
            $params + ['courseid' => $course->id]
        );
        $altered = [];
        foreach ($ids as $id) {
            $enrolment = $existing[$id] ?? null;
            if ($enrolment && ($enrolment->status != ENROL_USER_ACTIVE || $enrolment->timestart || $enrolment->timeend)) {
                // By id only: a refusal is not a preview, and it must not be a way to look names up.
                $altered[] = "id {$id}";
            }
        }
        if ($altered) {
            throw new tool_exception(
                'validation_error',
                'These users (given by id) already have a manual enrolment that is suspended or limited in time, and '
                    . 'enrolling them again would reactivate it or remove its dates: ' . implode(', ', $altered)
                    . ". Use 'set_enrolment_status' to make a suspended enrolment active again, change the dates on "
                    . 'the site, or leave these users out.'
            );
        }
        return ['course' => $course, 'context' => $context, 'role' => $role, 'users' => $users, 'ids' => $ids];
    }

    /**
     * Description of the change, with every user by name.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args, $call);
        $names = [];
        foreach ($plan['ids'] as $id) {
            $names[] = people::name($plan['users'][$id], $plan['context']) . " (id {$id})";
        }
        $rolename = self::label(role_get_name($plan['role'], $plan['context']));
        return 'Enrol ' . count($names) . " user(s) with the role '{$rolename}' in the course "
            . self::label(format_string($plan['course']->fullname, true, ['context' => $plan['context']])) . ': '
            . implode(', ', $names) . '.';
    }

    /**
     * Applies the change through the manual enrolment function of the site.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        $enrolments = [];
        foreach ($plan['ids'] as $id) {
            $enrolments[] = ['roleid' => (int) $plan['role']->id, 'userid' => $id, 'courseid' => (int) $plan['course']->id];
        }
        self::call_core_function('enrol_manual_enrol_users', ['enrolments' => $enrolments]);
        $result = new tool_result([
            'course_id' => (int) $plan['course']->id,
            'role' => (string) $plan['role']->shortname,
            'enrolled' => count($plan['ids']),
            'user_ids' => $plan['ids'],
            'url' => (new \moodle_url('/user/index.php', ['id' => $plan['course']->id]))->out(false),
        ], count($plan['ids']), null, 'course', (int) $plan['course']->id);
        return $result;
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'role', 'enrolled', 'user_ids', 'url'];
    }
}
