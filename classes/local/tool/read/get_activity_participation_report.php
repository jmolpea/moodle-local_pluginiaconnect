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
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool 59: who viewed or posted in one activity in a period, as the participation report counts it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_activity_participation_report extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_activity_participation_report';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get activity participation report',
            'Returns, for one activity of a course the signed-in user teaches and a period of at most 31 days, each '
                . 'learner with the number of times they viewed it and the number of times they posted or submitted in '
                . 'it, including those who did neither. Use it to see who has not opened an activity yet. Counts come from '
                . 'the standard log, without anonymous events, and in a course with separate groups only members of the '
                . 'user\'s own groups are listed. Returns at most 50 learners per call; use \'cursor\' to continue.',
            [
                'activity_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Course module identifier of the activity, from get_course_outline.',
                ],
            ] + report_helper::interval_properties() + [
                'only_without_activity' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'List only learners who neither viewed nor posted in the period.',
                ],
            ] + self::paging_properties(),
            ['activity_id', 'from', 'to'],
            [
                'type' => 'object',
                'properties' => [
                    'activity_id' => ['type' => 'integer'],
                    'activity_name' => ['type' => 'string'],
                    'from' => ['type' => 'string', 'format' => 'date-time'],
                    'to' => ['type' => 'string', 'format' => 'date-time'],
                    'learners_total' => ['type' => 'integer'],
                    'learners_with_activity' => ['type' => 'integer'],
                    'before_retention' => ['type' => 'boolean'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'views' => ['type' => 'integer'],
                                'posts' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                ],
            ]
        );
    }

    /**
     * Requirements. The capability of the participation report is a course capability, inherited by the activity.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'reports.read', ['report/participation:view']);
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
     * The tool acts on one activity.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        $cm = get_coursemodule_from_id('', (int) $args['activity_id']);
        if (!$cm || (int) $cm->course === SITEID) {
            throw self::activity_not_found('Activity', (int) $args['activity_id']);
        }
        return \context_module::instance($cm->id);
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
        $cmrecord = get_coursemodule_from_id('', (int) $args['activity_id'], 0, false, MUST_EXIST);
        $course = self::course((int) $cmrecord->course);
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Activity');
        $coursecontext = \context_course::instance($course->id);
        [$from, $to] = report_helper::interval($args);
        $table = report_helper::log_table();

        $groups = report_helper::activity_groups($cm, $call->user);
        $learners = $groups === [] ? [] : report_helper::course_learners($course, $coursecontext, $call->user);
        if ($groups) {
            $members = [];
            foreach ($groups as $groupid) {
                $members += groups_get_members($groupid, 'u.id');
            }
            $learners = array_intersect_key($learners, $members);
        }

        $counts = [];
        if ($learners) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($learners), SQL_PARAMS_NAMED, 'uid');
            $params += ['cmid' => $cm->id, 'modlevel' => CONTEXT_MODULE, 'tfrom' => $from, 'tto' => $to,
                'edulevel' => \core\event\base::LEVEL_PARTICIPATING];
            // The participation report counts web and web service events of the participating level only.
            $rows = $DB->get_records_sql(
                "SELECT userid,
                        SUM(CASE WHEN crud = 'r' THEN 1 ELSE 0 END) AS views,
                        SUM(CASE WHEN crud <> 'r' THEN 1 ELSE 0 END) AS posts
                   FROM {" . $table . "}
                  WHERE userid {$insql}
                    AND contextlevel = :modlevel AND contextinstanceid = :cmid
                    AND edulevel = :edulevel AND anonymous = 0
                    AND timecreated >= :tfrom AND timecreated < :tto
                    AND origin IN ('web', 'ws')
               GROUP BY userid",
                $params
            );
            foreach ($rows as $row) {
                $counts[(int) $row->userid] = [(int) $row->views, (int) $row->posts];
            }
        }
        $active = count(array_filter($counts, static fn($pair) => $pair[0] + $pair[1] > 0));
        $listed = !empty($args['only_without_activity'])
            ? array_filter($learners, static fn($user) => array_sum($counts[(int) $user->id] ?? [0, 0]) === 0)
            : $learners;
        [$page, $next] = self::page($listed, $args);

        $emails = people::may_see_emails($call);
        $participants = [];
        foreach ($page as $user) {
            [$views, $posts] = $counts[(int) $user->id] ?? [0, 0];
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'views' => $views,
                'posts' => $posts,
            ];
        }
        $result = new tool_result([
            'activity_id' => (int) $cm->id,
            'activity_name' => self::label($cm->get_formatted_name()),
            'from' => self::iso($from),
            'to' => self::iso($to),
            'learners_total' => count($learners),
            'learners_with_activity' => $active,
            'before_retention' => report_helper::before_retention($from),
            'participants' => $participants,
            'url' => (new \moodle_url('/report/participation/index.php', ['id' => $course->id, 'instanceid' => $cm->id]))
                ->out(false),
        ], count($participants), $next, 'course_module', (int) $cm->id);
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
            return ['activity_id'];
        }
        return ['activity_id', 'activity_name', 'from', 'to', 'learners_total', 'learners_with_activity', 'before_retention',
            'url', 'participants' => ['*' => array_merge(people::fields($profile), ['views', 'posts'])]];
    }
}
