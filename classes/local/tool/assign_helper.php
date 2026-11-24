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

namespace local_pluginiaconnect\local\tool;

/**
 * What the grading tools need from the assignment module, decided as its grading pages decide it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class assign_helper {
    /**
     * Loads the assignment API object for an activity the signed-in user can see.
     *
     * @param \cm_info $cm Course module.
     * @param \stdClass $course Course record.
     * @return \assign
     */
    public static function load(\cm_info $cm, \stdClass $course): \assign {
        global $CFG;
        require_once($CFG->dirroot . '/mod/assign/locallib.php');
        return new \assign(\context_module::instance($cm->id), $cm, $course);
    }

    /**
     * Whether student identities are hidden from the caller in this assignment.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $caller User the call runs as.
     * @return bool
     */
    public static function identities_hidden(\assign $assign, \stdClass $caller): bool {
        return $assign->is_blind_marking() && !has_capability('mod/assign:viewblinddetails', $assign->get_context(), $caller);
    }

    /**
     * Groups the caller is limited to in an assignment, or null when there is no limit.
     *
     * It is the group mode of the activity that counts, as on the grading page: an assignment
     * can use separate groups in a course that does not.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $course Course record.
     * @param \stdClass $caller User the call runs as.
     * @return int[]|null
     */
    public static function restricted_groups(\assign $assign, \stdClass $course, \stdClass $caller): ?array {
        $cm = $assign->get_course_module();
        if (
            groups_get_activity_groupmode($cm, $course) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $assign->get_context(), $caller)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($course->id, $caller->id, $cm->groupingid, 'g.id')));
    }

    /**
     * Students allocated to the caller as marker, or null when marker allocation does not limit the caller.
     *
     * Up to Moodle 5.1 the allocation is a column of the user flags; later versions keep it in its own table.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $caller User the call runs as.
     * @return int[]|null
     */
    public static function allocated_students(\assign $assign, \stdClass $caller): ?array {
        global $DB;
        $instance = $assign->get_instance();
        if (
            empty($instance->markingworkflow) || empty($instance->markingallocation)
            || has_capability('mod/assign:manageallocations', $assign->get_context(), $caller)
        ) {
            return null;
        }
        $params = ['assignment' => $instance->id, 'marker' => $caller->id];
        $dbman = $DB->get_manager();
        if ($dbman->table_exists('assign_allocated_marker')) {
            // Optional markers arrived after the table: Moodle 5.2 has the table without them.
            $optional = $dbman->field_exists('assign_allocated_marker', 'optional') ? ' AND (optional = 0 OR enabled = 1)' : '';
            $ids = $DB->get_fieldset_select(
                'assign_allocated_marker',
                'student',
                'assignment = :assignment AND marker = :marker' . $optional,
                $params
            );
        } else {
            $ids = $DB->get_fieldset_select(
                'assign_user_flags',
                'userid',
                'assignment = :assignment AND allocatedmarker = :marker',
                $params
            );
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    /**
     * Users whose submissions the caller may see and grade in an assignment, keyed by id.
     *
     * Separate groups, marker allocation and suspended enrolments are honoured as on the grading
     * page. The order is by name, or by anonymous participant number while marking is blind, so
     * that the order never hints at who is who.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $course Course record.
     * @param \stdClass $caller User the call runs as.
     * @return \stdClass[]
     */
    public static function gradable_users(\assign $assign, \stdClass $course, \stdClass $caller): array {
        $context = $assign->get_context();
        $groups = self::restricted_groups($assign, $course, $caller);
        $allocated = self::allocated_students($assign, $caller);
        if ($groups === [] || $allocated === []) {
            return [];
        }
        $onlyactive = !has_capability('moodle/course:viewsuspendedusers', $context, $caller);
        $users = get_enrolled_users(
            $context,
            'mod/assign:submit',
            $groups ?? 0,
            'u.*',
            'u.lastname, u.firstname, u.id',
            0,
            0,
            $onlyactive
        );
        if ($allocated !== null) {
            $users = array_intersect_key($users, array_flip($allocated));
        }
        if (self::identities_hidden($assign, $caller)) {
            $order = [];
            // Participant numbers are handed out on first use: in random order, so that they never follow the names.
            $ids = array_keys($users);
            shuffle($ids);
            foreach ($ids as $id) {
                $order[$id] = (int) $assign->get_uniqueid_for_user((int) $id);
            }
            asort($order);
            $users = array_replace($order, $users);
        }
        return $users;
    }

    /**
     * Latest submission of a user, or of the user's group in a team assignment. Never creates one.
     *
     * @param \assign $assign Assignment.
     * @param int $userid User id.
     * @return \stdClass|null
     */
    public static function submission(\assign $assign, int $userid): ?\stdClass {
        $submission = $assign->get_instance()->teamsubmission
            ? $assign->get_group_submission($userid, 0, false)
            : $assign->get_user_submission($userid, false);
        return $submission ?: null;
    }

    /**
     * Whether a submission is waiting for a grade: submitted and either never graded or changed since.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass|null $submission Submission record.
     * @param int $userid User id.
     * @return bool
     */
    public static function needs_grading(\assign $assign, ?\stdClass $submission, int $userid): bool {
        if (!$submission || $submission->status !== ASSIGN_SUBMISSION_STATUS_SUBMITTED) {
            return false;
        }
        $grade = $assign->get_user_grade($userid, false);
        return !$grade || $grade->grade === null || $grade->grade < 0 || $submission->timemodified >= $grade->timemodified;
    }

    /**
     * Who made a submission, as the caller may know it: the usual user fields, or
     * only the anonymous participant number while marking is blind.
     *
     * @param \assign $assign Assignment.
     * @param \stdClass $user Student.
     * @param call_context $call Call data.
     * @param bool $emails Whether e-mail addresses may be returned.
     * @return array
     */
    public static function student(\assign $assign, \stdClass $user, call_context $call, bool $emails): array {
        if (self::identities_hidden($assign, $call->user)) {
            return [
                'user_id' => null,
                'full_name' => 'Participant ' . $assign->get_uniqueid_for_user((int) $user->id),
                'email' => null,
                'custom_fields' => [],
                'url' => (new \moodle_url('/mod/assign/view.php', [
                    'id' => $assign->get_course_module()->id, 'action' => 'grading',
                ]))->out(false),
                'anonymous' => true,
            ];
        }
        return people::describe($user, $call, (int) $assign->get_course()->id, $emails) + ['anonymous' => false];
    }

    /**
     * JSON Schema of {@see self::student()}.
     *
     * @return array
     */
    public static function student_schema(): array {
        $properties = people::schema();
        $properties['user_id'] = ['type' => ['integer', 'null']];
        $properties['anonymous'] = ['type' => 'boolean'];
        return ['type' => 'object', 'properties' => $properties];
    }

    /**
     * Output field list of {@see self::student()}.
     *
     * @param string $profile Profile the fields are for.
     * @return array
     */
    public static function student_fields(string $profile): array {
        return array_merge(people::fields($profile), ['anonymous']);
    }
}
