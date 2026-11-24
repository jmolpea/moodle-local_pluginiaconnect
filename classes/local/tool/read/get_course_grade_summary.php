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
 * Tool: get_course_grade_summary.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_grade_summary extends base_tool {
    /** @var int Most grade items returned. */
    private const MAX_ITEMS = 60;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_grade_summary';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course grade summary',
            'Returns the gradebook of one course the signed-in user teaches: the grade items and, for each graded '
                . 'participant, the grade in each item and the course total. Use it to review how a group is doing or '
                . 'to find who has not been graded. In a course with separate groups only members of the user\'s own '
                . 'groups are listed. At most 60 grade items are included. Returns at most 50 participants per call; '
                . 'use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'items' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'item_id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'maximum_grade' => ['type' => ['number', 'null']],
                            ],
                        ],
                    ],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'course_total' => ['type' => ['string', 'null']],
                                'grades' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'properties' => [
                                            'item_id' => ['type' => 'integer'],
                                            'grade' => ['type' => ['string', 'null']],
                                        ],
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'url' => ['type' => 'string', 'format' => 'uri'],
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
        return new tool_requirement(profile::TEACHER, 'grades.read', ['moodle/grade:viewall']);
    }

    /**
     * The tool lists other people's grades.
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
        global $CFG;
        require_once($CFG->libdir . '/gradelib.php');
        require_once($CFG->libdir . '/grade/constants.php');
        $course = self::course((int) $args['course_id']);
        $context = $call->context;
        $seeshidden = has_capability('moodle/grade:viewhidden', $context, $call->user);

        // Graded participants are the enrolled users who hold one of the gradebook roles, as in the grader report.
        $graded = [];
        $roles = array_filter(array_map('intval', explode(',', (string) $CFG->gradebookroles)));
        if ($roles) {
            foreach (get_role_users($roles, $context, false, 'ra.id AS raid, u.id AS userid', 'u.id') as $assignment) {
                $graded[(int) $assignment->userid] = true;
            }
        }
        $users = array_intersect_key(people::visible_enrolled($course, $context, $call->user), $graded);
        [$page, $next] = self::page($users, $args);
        $userids = array_map(static fn($user) => (int) $user->id, $page);

        $items = [];
        $courseitem = null;
        foreach (\grade_item::fetch_all(['courseid' => $course->id]) ?: [] as $item) {
            if (!$seeshidden && $item->is_hidden()) {
                continue;
            }
            if ($item->itemtype === 'course') {
                $courseitem = $item;
            } else if (count($items) < self::MAX_ITEMS) {
                $items[(int) $item->id] = $item;
            }
        }
        $values = [];
        foreach ($items + ($courseitem ? [(int) $courseitem->id => $courseitem] : []) as $itemid => $item) {
            foreach ($userids ? \grade_grade::fetch_users_grades($item, $userids, true) : [] as $userid => $grade) {
                $shown = $grade->finalgrade !== null && ($seeshidden || !$grade->is_hidden());
                $values[$itemid][(int) $userid] = $shown
                    ? self::label(grade_format_gradevalue($grade->finalgrade, $item, true))
                    : null;
            }
        }

        $emails = people::may_see_emails($call);
        $participants = [];
        $others = 0;
        foreach ($page as $user) {
            $grades = [];
            foreach ($items as $itemid => $item) {
                $grades[] = ['item_id' => $itemid, 'grade' => $values[$itemid][(int) $user->id] ?? null];
            }
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'course_total' => $courseitem ? ($values[(int) $courseitem->id][(int) $user->id] ?? null) : null,
                'grades' => $grades,
            ];
            $others += $user->id == $call->user->id ? 0 : 1;
        }
        $itemlist = [];
        foreach ($items as $itemid => $item) {
            $itemlist[] = [
                'item_id' => $itemid,
                'name' => self::label($item->get_name()),
                'type' => $item->itemtype === 'mod' ? (string) $item->itemmodule : (string) $item->itemtype,
                'maximum_grade' => $item->gradetype == GRADE_TYPE_VALUE ? (float) $item->grademax : null,
            ];
        }
        $result = new tool_result([
            'course_id' => (int) $course->id,
            'items' => $itemlist,
            'participants' => $participants,
            'url' => (new \moodle_url('/grade/report/grader/index.php', ['id' => $course->id]))->out(false),
        ], count($participants), $next, 'course', (int) $course->id);
        $result->thirdparty = $others;
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
        return [
            'course_id', 'url',
            'items' => ['*' => ['item_id', 'name', 'type', 'maximum_grade']],
            'participants' => ['*' => array_merge(people::fields($profile), [
                'course_total', 'grades' => ['*' => ['item_id', 'grade']],
            ])],
        ];
    }
}
