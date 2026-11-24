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

use local_pluginiaconnect\local\policy\context_filter;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_upcoming_deadlines.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_upcoming_deadlines extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_upcoming_deadlines';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List upcoming deadlines',
            'Returns the signed-in user\'s upcoming course deadlines (assignments, quizzes and other activities with a '
                . 'due date) within a date range, ordered by date. Use it to answer questions about what is due and '
                . 'when. Returns at most 50 items per call; use \'cursor\' to continue.',
            [
                'days_ahead' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 180, 'default' => 14,
                    'description' => 'Number of days from today to include.',
                ],
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Limit results to one course.'],
            ] + self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'deadlines' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['title', 'course_name', 'due_at', 'url'],
                            'properties' => [
                                'title' => ['type' => 'string'],
                                'activity_type' => ['type' => 'string'],
                                'course_id' => ['type' => 'integer'],
                                'course_name' => ['type' => 'string'],
                                'due_at' => ['type' => 'string', 'format' => 'date-time'],
                                'overdue' => ['type' => 'boolean'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
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
        return new tool_requirement(profile::STUDENT, 'courses.read');
    }

    /**
     * One course when the caller names it; otherwise the user's own calendar.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        if (isset($args['course_id'])) {
            return \context_course::instance(self::course((int) $args['course_id'])->id);
        }
        return \context_system::instance();
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $limit = self::limit($args);
        $from = time();
        $params = [
            'timesortfrom' => $from,
            'timesortto' => $from + (int) ($args['days_ahead'] ?? 14) * DAYSECS,
            'aftereventid' => self::position($args),
            'limitnum' => $limit,
        ];
        if (isset($args['course_id'])) {
            // Deadlines belong to enrolled users. Somebody who can enter the course without being enrolled
            // (a manager, an administrator) has none, and the core function has no valid answer for them.
            if (!is_enrolled($call->context, $call->user, '', true)) {
                return new tool_result(['deadlines' => []], 0, null, 'course', (int) $args['course_id']);
            }
            $function = 'core_calendar_get_action_events_by_course';
            $params['courseid'] = (int) $args['course_id'];
        } else {
            $function = 'core_calendar_get_action_events_by_timesort';
            $params['limittononsuspendedevents'] = true;
        }
        $result = self::call_core_function($function, $params);
        $events = $result['events'] ?? [];

        $deadlines = [];
        $lastid = 0;
        foreach ($events as $event) {
            $lastid = (int) $event['id'];
            $courseid = (int) ($event['course']['id'] ?? 0);
            if (!$courseid || !context_filter::is_course_allowed($courseid)) {
                continue;
            }
            $deadlines[] = [
                'title' => self::label((string) (($event['activityname'] ?? '') !== '' ? $event['activityname'] : $event['name'])),
                'activity_type' => (string) ($event['modulename'] ?? ''),
                'course_id' => $courseid,
                'course_name' => self::label((string) ($event['course']['fullname'] ?? '')),
                'due_at' => self::iso((int) $event['timesort']),
                'overdue' => !empty($event['overdue']),
                'url' => self::own_url(
                    (string) ($event['action']['url'] ?? ($event['url'] ?? '')),
                    new \moodle_url('/course/view.php', ['id' => $courseid])
                ),
            ];
        }
        // The calendar pages by "after this event": a full page may have a successor.
        $next = count($events) >= $limit && $lastid ? self::cursor($lastid) : null;
        return new tool_result(['deadlines' => $deadlines], count($deadlines), $next);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['deadlines' => ['*' => [
            'title', 'activity_type', 'course_id', 'course_name', 'due_at', 'overdue', 'url',
        ]]];
    }
}
