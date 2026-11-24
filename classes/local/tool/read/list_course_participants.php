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

namespace local_pluginiaconnect\local\tool\read;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_course_participants.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_course_participants extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_course_participants';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List course participants',
            'Returns the participants of one course that the signed-in user teaches, with their roles, groups and, when '
                . 'the site shows it to this user, their last access to the course and e-mail address. Use it to see '
                . 'who is in a course or to get the user identifiers that other tools take. In a course with separate '
                . 'groups only members of the user\'s own groups are listed. Returns at most 50 participants per call; '
                . 'use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['user_id', 'full_name', 'url'],
                            'properties' => people::schema() + [
                                'roles' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'groups' => ['type' => 'array', 'items' => ['type' => 'string']],
                                'last_course_access' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'participants.read', ['moodle/course:viewparticipants']);
    }

    /**
     * The tool lists other people.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return true;
    }

    /**
     * The tool acts on one course.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $course = self::course((int) $args['course_id']);
        $context = $call->context;
        $users = people::visible_enrolled($course, $context, $call->user);
        [$page, $next] = self::page($users, $args);

        $emails = people::may_see_emails($call);
        $lastaccess = people::can_see_last_access($context, $call->user) ? people::course_last_access((int) $course->id) : null;
        $restricted = people::restricted_groups($course, $context, $call->user);
        // Roles are named as the participants page names them: only those the caller may view or assign here.
        $shownroles = get_viewable_roles($context, (int) $call->user->id)
            + get_assignable_roles($context, ROLENAME_ALIAS, false, $call->user);
        // Group visibility is judged for the caller, not for the participant the groups belong to.
        $viewhiddengroups = has_capability('moodle/course:viewhiddengroups', $context, $call->user);
        $owngroups = $viewhiddengroups ? []
            : array_map('intval', array_keys(groups_get_all_groups($course->id, $call->user->id, 0, 'g.id')));
        $participants = [];
        $others = 0;
        foreach ($page as $user) {
            $roles = [];
            foreach (get_user_roles($context, $user->id, false) as $assignment) {
                if (!isset($shownroles[$assignment->roleid])) {
                    continue;
                }
                $roles[] = self::label(role_get_name((object) [
                    'id' => $assignment->roleid, 'name' => $assignment->name, 'shortname' => $assignment->shortname,
                ], $context));
            }
            $groups = [];
            foreach (groups_get_all_groups($course->id, $user->id, 0, 'g.id, g.name, g.visibility') as $group) {
                // With separate groups, groups the caller does not belong to are not named.
                if ($restricted !== null && !in_array((int) $group->id, $restricted, true)) {
                    continue;
                }
                if (!$viewhiddengroups && !self::group_shown($group, $owngroups, $user->id == $call->user->id)) {
                    continue;
                }
                $groups[] = self::label(format_string($group->name, true, ['context' => $context]));
            }
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'roles' => array_values(array_unique($roles)),
                'groups' => $groups,
                'last_course_access' => $lastaccess === null ? null : self::iso($lastaccess[(int) $user->id] ?? 0),
            ];
            $others += $user->id == $call->user->id ? 0 : 1;
        }
        $result = new tool_result(
            ['course_id' => (int) $course->id, 'participants' => $participants],
            count($participants),
            $next,
            'course',
            (int) $course->id
        );
        $result->thirdparty = $others;
        return $result;
    }

    /**
     * Whether a user without the capability to see hidden groups is shown that a participant belongs to a group.
     *
     * The rule of the group setting "Group membership visibility": a group visible to everybody is always
     * shown; one visible to its members, only to a caller who belongs to it too; one where each member
     * sees only their own membership, only when the participant is the caller; a hidden one, never.
     *
     * @param \stdClass $group Group record with id and visibility.
     * @param int[] $owngroups Groups the caller belongs to.
     * @param bool $self Whether the participant is the caller.
     * @return bool
     */
    private static function group_shown(\stdClass $group, array $owngroups, bool $self): bool {
        switch ((int) ($group->visibility ?? GROUPS_VISIBILITY_ALL)) {
            case GROUPS_VISIBILITY_ALL:
                return true;
            case GROUPS_VISIBILITY_MEMBERS:
                return in_array((int) $group->id, $owngroups, true);
            case GROUPS_VISIBILITY_OWN:
                return $self;
            default:
                return false;
        }
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::TEACHER)) {
            return ['course_id'];
        }
        return ['course_id', 'participants' => ['*' => people::fields($profile) + [
            100 => 'roles', 101 => 'groups', 102 => 'last_course_access',
        ]]];
    }
}
