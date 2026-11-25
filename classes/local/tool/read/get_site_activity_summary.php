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
use local_pluginiaconnect\local\tool\report_helper;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Site activity in a period, from the standard log, as counts only (amendment 2, block C, tool 67A).
 *
 * Answers "how many visits did the site have today": distinct users who signed in and number of sign-ins,
 * course views and active users, per day and in total, and the most viewed courses. No person is named or
 * identified; anonymous events and the guest account are left out.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_site_activity_summary extends base_tool {
    /** @var string Event of a sign-in. */
    private const LOGIN = '\\core\\event\\user_loggedin';

    /** @var string Event of a failed sign-in. */
    private const LOGIN_FAILED = '\\core\\event\\user_login_failed';

    /** @var string Event of a course view. */
    private const COURSE_VIEWED = '\\core\\event\\course_viewed';

    /** @var int Most viewed courses returned. */
    private const TOP = 10;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_site_activity_summary';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get site activity summary',
            'Returns, for the site administrator and a period of at most 31 days, how many distinct users signed in and '
                . 'how many sign-ins there were, failed sign-ins, course views, distinct active users, the same figures '
                . 'day by day in the user\'s time zone, and the ten most viewed courses. Use it to answer how many visits '
                . 'or accesses the site had today or this week. Only counts are returned, never who; anonymous events '
                . 'and the guest account are left out.',
            report_helper::interval_properties(),
            ['from', 'to'],
            [
                'type' => 'object',
                'properties' => [
                    'from' => ['type' => 'string', 'format' => 'date-time'],
                    'to' => ['type' => 'string', 'format' => 'date-time'],
                    'time_zone' => ['type' => 'string'],
                    'before_retention' => ['type' => 'boolean'],
                    'users_signed_in' => ['type' => 'integer'],
                    'sign_ins' => ['type' => 'integer'],
                    'failed_sign_ins' => ['type' => 'integer'],
                    'course_views' => ['type' => 'integer'],
                    'users_viewing_courses' => ['type' => 'integer'],
                    'active_users' => ['type' => 'integer'],
                    'days' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'date' => ['type' => 'string'],
                                'users_signed_in' => ['type' => 'integer'],
                                'sign_ins' => ['type' => 'integer'],
                                'course_views' => ['type' => 'integer'],
                                'users_viewing_courses' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'top_courses' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'course_id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'views' => ['type' => 'integer'],
                                'distinct_users' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                ],
            ]
        );
    }

    /**
     * Requirements: the capability of the site log report.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::ADMIN, 'reports.read', ['report/log:view']);
    }

    /**
     * The tool looks at the site.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
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
        global $CFG, $DB;
        [$from, $to] = report_helper::interval($args);
        $table = '{' . report_helper::log_table() . '}';
        $people = 'anonymous = 0 AND userid > 0 AND userid <> :guest AND timecreated >= :tfrom AND timecreated < :tto';
        $base = ['guest' => (int) ($CFG->siteguest ?? 0), 'tfrom' => $from, 'tto' => $to];

        $count = static function (string $event, bool $courses = false) use ($DB, $table, $people, $base): array {
            $where = "eventname = :event AND {$people}" . ($courses ? ' AND courseid <> :site' : '');
            $row = $DB->get_record_sql(
                "SELECT COUNT(1) AS total, COUNT(DISTINCT userid) AS users FROM {$table} WHERE {$where}",
                $base + ['event' => $event, 'site' => SITEID]
            );
            return [(int) $row->total, (int) $row->users];
        };
        [$signins, $signedin] = $count(self::LOGIN);
        [$views, $viewers] = $count(self::COURSE_VIEWED, true);
        $failed = (int) $DB->count_records_select(
            report_helper::log_table(),
            'eventname = :event AND anonymous = 0 AND timecreated >= :tfrom AND timecreated < :tto',
            ['event' => self::LOGIN_FAILED, 'tfrom' => $from, 'tto' => $to]
        );
        $active = (int) $DB->get_field_sql("SELECT COUNT(DISTINCT userid) FROM {$table} WHERE {$people}", $base);

        // Day by day in the time zone of the caller: one grouped query per figure.
        $bounds = report_helper::day_boundaries($from, $to);
        $case = 'CASE';
        $dayparams = [];
        for ($i = 1; $i < count($bounds) - 1; $i++) {
            $case .= " WHEN timecreated < :b{$i} THEN " . ($i - 1);
            $dayparams["b{$i}"] = $bounds[$i];
        }
        $case .= ' ELSE ' . (count($bounds) - 2) . ' END';
        $days = [];
        for ($i = 0; $i < count($bounds) - 1; $i++) {
            $days[$i] = ['date' => report_helper::local_date($bounds[$i]), 'users_signed_in' => 0, 'sign_ins' => 0,
                'course_views' => 0, 'users_viewing_courses' => 0];
        }
        foreach (
            [[self::LOGIN, 'sign_ins', 'users_signed_in', ''], [self::COURSE_VIEWED, 'course_views',
                'users_viewing_courses', ' AND courseid <> :site']] as [$event, $total, $users, $extra]
        ) {
            $rows = $DB->get_records_sql(
                "SELECT dayno, COUNT(1) AS total, COUNT(DISTINCT userid) AS users
                   FROM (SELECT {$case} AS dayno, userid FROM {$table} WHERE eventname = :event AND {$people}{$extra}) buckets
               GROUP BY dayno",
                $base + $dayparams + ['event' => $event, 'site' => SITEID]
            );
            foreach ($rows as $row) {
                if (isset($days[(int) $row->dayno])) {
                    $days[(int) $row->dayno][$total] = (int) $row->total;
                    $days[(int) $row->dayno][$users] = (int) $row->users;
                }
            }
        }

        $top = [];
        $rows = $DB->get_records_sql(
            "SELECT courseid, COUNT(1) AS views, COUNT(DISTINCT userid) AS users
               FROM {$table}
              WHERE eventname = :event AND courseid <> :site AND {$people}
           GROUP BY courseid
           ORDER BY COUNT(1) DESC, courseid ASC",
            $base + ['event' => self::COURSE_VIEWED, 'site' => SITEID],
            0,
            self::TOP
        );
        $names = $rows ? $DB->get_records_list('course', 'id', array_keys($rows), '', 'id, fullname') : [];
        foreach ($rows as $row) {
            $course = $names[(int) $row->courseid] ?? null;
            if (!$course) {
                continue;
            }
            $context = \context_course::instance($course->id);
            $top[] = [
                'course_id' => (int) $course->id,
                'name' => self::label(format_string($course->fullname, true, ['context' => $context])),
                'views' => (int) $row->views,
                'distinct_users' => (int) $row->users,
            ];
        }
        return new tool_result([
            'from' => self::iso($from),
            'to' => self::iso($to),
            'time_zone' => \core_date::get_user_timezone(),
            'before_retention' => report_helper::before_retention($from),
            'users_signed_in' => $signedin,
            'sign_ins' => $signins,
            'failed_sign_ins' => $failed,
            'course_views' => $views,
            'users_viewing_courses' => $viewers,
            'active_users' => $active,
            'days' => array_values($days),
            'top_courses' => $top,
            'url' => (new \moodle_url('/report/log/index.php', ['id' => SITEID]))->out(false),
        ], count($days));
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if ($profile !== profile::ADMIN) {
            return [];
        }
        return ['from', 'to', 'time_zone', 'before_retention', 'users_signed_in', 'sign_ins', 'failed_sign_ins',
            'course_views', 'users_viewing_courses', 'active_users', 'url',
            'days' => ['*' => ['date', 'users_signed_in', 'sign_ins', 'course_views', 'users_viewing_courses']],
            'top_courses' => ['*' => ['course_id', 'name', 'views', 'distinct_users']],
        ];
    }
}
