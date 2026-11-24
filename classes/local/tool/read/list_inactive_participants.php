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
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_inactive_participants.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_inactive_participants extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_inactive_participants';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List inactive participants',
            'Returns the participants of one course the signed-in user teaches who have not accessed the course for a '
                . 'given number of days, or have never accessed it, with their last access. Use it to find who may need '
                . 'a reminder. It is refused when the site hides last access from this user. In a course with separate '
                . 'groups only members of the user\'s own groups are listed. Returns at most 50 participants per call; '
                . 'use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
                'days' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 14,
                    'description' => 'Number of days without access to the course.',
                ],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'days' => ['type' => 'integer'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'last_course_access' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'days_inactive' => ['type' => ['integer', 'null']],
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
        if (!people::can_see_last_access($call->context, $call->user)) {
            throw new tool_exception('permission_denied', 'This site does not show you when participants last accessed.');
        }
        $days = (int) ($args['days'] ?? 14);
        $threshold = time() - $days * DAYSECS;
        $lastaccess = people::course_last_access((int) $course->id);
        $users = array_filter(
            people::visible_enrolled($course, $call->context, $call->user),
            static fn($user) => $user->id != $call->user->id && ($lastaccess[(int) $user->id] ?? 0) < $threshold
        );
        [$page, $next] = self::page($users, $args);

        $emails = people::may_see_emails($call);
        $participants = [];
        foreach ($page as $user) {
            $last = $lastaccess[(int) $user->id] ?? 0;
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'last_course_access' => self::iso($last),
                'days_inactive' => $last ? intdiv(time() - $last, DAYSECS) : null,
            ];
        }
        $result = new tool_result(
            ['course_id' => (int) $course->id, 'days' => $days, 'participants' => $participants],
            count($participants),
            $next,
            'course',
            (int) $course->id
        );
        $result->thirdparty = count($participants);
        return $result;
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
        return ['course_id', 'days', 'participants' => ['*' => array_merge(people::fields($profile), [
            'last_course_access', 'days_inactive',
        ])]];
    }
}
