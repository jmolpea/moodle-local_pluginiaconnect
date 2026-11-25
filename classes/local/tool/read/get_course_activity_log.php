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
use local_pluginiaconnect\local\tool\report_helper;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool 58: events of the standard log of one course in a period.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_activity_log extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_activity_log';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course activity log',
            'Returns the events of the standard log of one course the signed-in user teaches in a period of at most '
                . '31 days, newest first: when, who, the event, the activity and where it came from, optionally for one '
                . 'participant or one activity. Use it to see what happened in a course or what one learner did. '
                . 'Anonymous events, network addresses and the internal data of events are never returned, and in a course '
                . 'with separate groups only members of the user\'s own groups appear. Returns at most 50 events per call; '
                . 'use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + report_helper::interval_properties() + [
                'user_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Only events of this participant.',
                ],
                'activity_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Only events of this activity (course module identifier from get_course_outline).',
                ],
            ] + self::paging_properties(),
            ['course_id', 'from', 'to'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'from' => ['type' => 'string', 'format' => 'date-time'],
                    'to' => ['type' => 'string', 'format' => 'date-time'],
                    'total' => ['type' => 'integer'],
                    'before_retention' => ['type' => 'boolean'],
                    'events' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'time' => ['type' => 'string', 'format' => 'date-time'],
                                'user_id' => ['type' => 'integer'],
                                'full_name' => ['type' => 'string'],
                                'related_user_id' => ['type' => ['integer', 'null']],
                                'event' => ['type' => 'string'],
                                'event_name' => ['type' => 'string'],
                                'component' => ['type' => 'string'],
                                'action' => ['type' => 'string'],
                                'target' => ['type' => 'string'],
                                'crud' => ['type' => 'string', 'enum' => ['c', 'r', 'u', 'd']],
                                'activity_id' => ['type' => ['integer', 'null']],
                                'activity_name' => ['type' => ['string', 'null']],
                                'origin' => ['type' => 'string'],
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
        return new tool_requirement(profile::TEACHER, 'reports.read', ['report/log:view']);
    }

    /**
     * Each event names a person.
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
        global $DB;
        $course = self::course((int) $args['course_id']);
        [$from, $to] = report_helper::interval($args);
        $table = report_helper::log_table();

        $where = 'courseid = :courseid AND anonymous = 0 AND timecreated >= :tfrom AND timecreated < :tto';
        $params = ['courseid' => $course->id, 'tfrom' => $from, 'tto' => $to];

        // Separate groups, as the log report applies them: only members of the caller's groups (D25).
        $groups = people::restricted_groups($course, $call->context, $call->user);
        $visible = null;
        if ($groups !== null) {
            $visible = array_map('intval', array_keys(people::visible_enrolled($course, $call->context, $call->user)));
            $visible[] = (int) $call->user->id;
        }
        if (isset($args['user_id'])) {
            $userid = (int) $args['user_id'];
            if ($visible !== null && !in_array($userid, $visible, true)) {
                throw new tool_exception('not_found', self::ITEM_NOT_FOUND);
            }
            $visible = [$userid];
        }
        if ($visible !== null) {
            [$insql, $inparams] = $DB->get_in_or_equal(array_values(array_unique($visible)), SQL_PARAMS_NAMED, 'uid');
            $where .= " AND userid {$insql}";
            $params += $inparams;
        }
        $cms = get_fast_modinfo($course)->get_cms();
        if (isset($args['activity_id'])) {
            $cmid = (int) $args['activity_id'];
            if (!isset($cms[$cmid]) || !$cms[$cmid]->uservisible) {
                throw new tool_exception('not_found', self::ITEM_NOT_FOUND);
            }
            $where .= ' AND contextlevel = :modlevel AND contextinstanceid = :cmid';
            $params += ['modlevel' => CONTEXT_MODULE, 'cmid' => $cmid];
        }

        $limit = self::limit($args);
        $offset = self::position($args);
        $total = $DB->count_records_select($table, $where, $params);
        $rows = $DB->get_records_select(
            $table,
            $where,
            $params,
            'timecreated DESC, id DESC',
            'id, eventname, component, action, target, crud, contextlevel, contextinstanceid, userid, relateduserid, '
                . 'timecreated, origin',
            $offset,
            $limit
        );
        $users = report_helper::users(array_map(static fn($row) => (int) $row->userid, $rows));
        $events = [];
        foreach ($rows as $row) {
            $user = $users[(int) $row->userid] ?? null;
            $cmid = (int) $row->contextlevel === CONTEXT_MODULE ? (int) $row->contextinstanceid : null;
            $cm = $cmid !== null && isset($cms[$cmid]) ? $cms[$cmid] : null;
            $events[] = [
                'time' => self::iso((int) $row->timecreated),
                'user_id' => (int) $row->userid,
                'full_name' => $user ? people::name($user, $call->context) : '',
                'related_user_id' => $row->relateduserid ? (int) $row->relateduserid : null,
                'event' => (string) $row->eventname,
                'event_name' => self::event_name((string) $row->eventname),
                'component' => (string) $row->component,
                'action' => (string) $row->action,
                'target' => (string) $row->target,
                'crud' => in_array($row->crud, ['c', 'r', 'u', 'd'], true) ? (string) $row->crud : 'r',
                'activity_id' => $cm ? $cmid : null,
                'activity_name' => $cm ? self::label($cm->get_formatted_name()) : null,
                'origin' => (string) $row->origin,
            ];
        }
        $next = $offset + $limit < $total ? self::cursor($offset + $limit) : null;
        $result = new tool_result([
            'course_id' => (int) $course->id,
            'from' => self::iso($from),
            'to' => self::iso($to),
            'total' => $total,
            'before_retention' => report_helper::before_retention($from),
            'events' => $events,
            'url' => (new \moodle_url('/report/log/index.php', ['id' => $course->id]))->out(false),
        ], count($events), $next, 'course', (int) $course->id);
        // Every row about another person counts for the hourly budget.
        $result->thirdparty = count(array_filter($events, static fn($event) => $event['user_id'] !== (int) $call->user->id));
        return $result;
    }

    /**
     * Readable name of an event class, or the class name when it is not installed any more.
     *
     * @param string $eventname Event class name.
     * @return string
     */
    private static function event_name(string $eventname): string {
        static $names = [];
        if (!array_key_exists($eventname, $names)) {
            $name = '';
            try {
                if (class_exists($eventname) && is_subclass_of($eventname, \core\event\base::class)) {
                    $name = self::label($eventname::get_name());
                }
            } catch (\Throwable $e) {
                $name = '';
            }
            $names[$eventname] = $name !== '' ? $name : $eventname;
        }
        return $names[$eventname];
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
        return ['course_id', 'from', 'to', 'total', 'before_retention', 'url', 'events' => ['*' => [
            'time', 'user_id', 'full_name', 'related_user_id', 'event', 'event_name', 'component', 'action', 'target',
            'crud', 'activity_id', 'activity_name', 'origin',
        ]]];
    }
}
