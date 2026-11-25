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
use local_pluginiaconnect\local\tool\assign_helper;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Learners with assignments past their due date and not submitted (amendment 2, block C, tool 67C).
 *
 * Answers "how many of my learners are behind": the due date of each learner is the one that applies to
 * them, with overrides and extensions.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_overdue_submissions extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_overdue_submissions';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List overdue submissions',
            'Returns the learners of one course the signed-in user grades who have assignments past their due date and '
                . 'not submitted, each with those assignments, the due date that applies to them (overrides and '
                . 'extensions included) and the days overdue. Use it to see who is behind and on what. Team assignments and '
                . 'assignments with blind marking are left out and counted, and with separate groups only members of the '
                . 'user\'s own groups are listed. Returns at most 50 learners per call; use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
                'assignment_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Only this assignment (course module identifier from get_course_outline).',
                ],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'assignments_checked' => ['type' => 'integer'],
                    'assignments_left_out' => ['type' => 'integer'],
                    'learners_behind' => ['type' => 'integer'],
                    'overdue_total' => ['type' => 'integer'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'overdue_count' => ['type' => 'integer'],
                                'overdue' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'assignment_id' => ['type' => 'integer'],
                                            'name' => ['type' => 'string'],
                                            'due_date' => ['type' => 'string', 'format' => 'date-time'],
                                            'days_overdue' => ['type' => 'integer'],
                                            'has_extension' => ['type' => 'boolean'],
                                            'closed' => ['type' => 'boolean'],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Requirements. Grading is checked assignment by assignment.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'reports.read', ['moodle/course:viewparticipants']);
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
        $only = isset($args['assignment_id']) ? (int) $args['assignment_id'] : null;
        if ($only !== null) {
            $cms = get_fast_modinfo($course)->get_cms();
            if (!isset($cms[$only]) || $cms[$only]->modname !== 'assign' || !$cms[$only]->uservisible) {
                throw new tool_exception('not_found', self::ITEM_NOT_FOUND);
            }
        }
        $found = assign_helper::overdue($course, $call->user, $only);
        $users = $found['users'];
        uasort($users, static fn($a, $b) => [$a->lastname, $a->firstname, $a->id] <=> [$b->lastname, $b->firstname, $b->id]);
        [$page, $next] = self::page($users, $args);
        $emails = people::may_see_emails($call);
        $now = time();
        $participants = [];
        foreach ($page as $user) {
            $items = [];
            foreach ($found['items'][(int) $user->id] as $item) {
                $effective = max($item['due'], $item['extension']);
                $items[] = [
                    'assignment_id' => $item['cmid'],
                    'name' => self::label($item['name']),
                    'due_date' => self::iso($effective),
                    'days_overdue' => intdiv($now - $effective, DAYSECS),
                    'has_extension' => $item['extension'] > 0,
                    'closed' => $item['cutoff'] > 0 && $now > max($item['cutoff'], $item['extension']),
                ];
            }
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'overdue_count' => count($items),
                'overdue' => $items,
            ];
        }
        $result = new tool_result([
            'course_id' => (int) $course->id,
            'assignments_checked' => $found['checked'],
            'assignments_left_out' => $found['skipped'],
            'learners_behind' => count($users),
            'overdue_total' => array_sum(array_map('count', $found['items'])),
            'participants' => $participants,
        ], count($participants), $next, 'course', (int) $course->id);
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
        return ['course_id', 'assignments_checked', 'assignments_left_out', 'learners_behind', 'overdue_total',
            'participants' => ['*' => array_merge(people::fields($profile), ['overdue_count', 'overdue' => ['*' => [
                'assignment_id', 'name', 'due_date', 'days_overdue', 'has_extension', 'closed',
            ]]])]];
    }
}
