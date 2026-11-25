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
 * Tool 60: completion of each tracked activity by each learner of a course (activity × participant).
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_activity_completion_report extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_activity_completion_report';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get activity completion report',
            'Returns, for one course the signed-in user teaches, the activities that track completion and, for each '
                . 'tracked participant, which of them they have completed, passed or failed and how many are still '
                . 'pending. Use it to see who is behind on which activity. Activities hidden from the user are left out, '
                . 'and in a course with separate groups only members of the user\'s own groups are listed. Returns at '
                . 'most 50 participants per call; use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'completion_enabled' => ['type' => 'boolean'],
                    'participants_total' => ['type' => 'integer'],
                    'activities' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'activity_id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string'],
                                'completed_by' => ['type' => 'integer'],
                            ],
                        ],
                    ],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'completed_count' => ['type' => 'integer'],
                                'pending_count' => ['type' => 'integer'],
                                'completed_activity_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
                                'failed_activity_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
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
        return new tool_requirement(profile::TEACHER, 'reports.read', ['report/progress:view']);
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
        global $CFG, $DB;
        require_once($CFG->libdir . '/completionlib.php');
        $course = self::course((int) $args['course_id']);
        $url = (new \moodle_url('/report/progress/index.php', ['course' => $course->id]))->out(false);
        $completion = new \completion_info($course);
        $data = ['course_id' => (int) $course->id, 'completion_enabled' => $completion->is_enabled() == COMPLETION_ENABLED,
            'participants_total' => 0, 'activities' => [], 'participants' => [], 'url' => $url];
        if (!$data['completion_enabled']) {
            return new tool_result($data, 0, null, 'course', (int) $course->id);
        }

        // Activities with completion that the caller sees on the course page.
        $modinfo = get_fast_modinfo($course);
        $activities = [];
        foreach ($completion->get_activities() as $cmid => $cm) {
            $info = $modinfo->get_cm((int) $cmid);
            if ($info->uservisible && empty($info->deletioninprogress)) {
                $activities[(int) $cmid] = $info;
            }
        }
        $users = array_filter(
            people::visible_enrolled($course, $call->context, $call->user),
            static fn($user) => $completion->is_tracked_user((int) $user->id) && (int) $user->id !== (int) $call->user->id
        );
        $data['participants_total'] = count($users);

        // Per activity: how many of the listed participants completed it.
        $done = array_fill_keys(array_keys($activities), 0);
        if ($activities && $users) {
            [$cmsql, $cmparams] = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED, 'cm');
            [$usql, $uparams] = $DB->get_in_or_equal(array_keys($users), SQL_PARAMS_NAMED, 'u');
            $rows = $DB->get_records_sql(
                "SELECT coursemoduleid, COUNT(1) AS done
                   FROM {course_modules_completion}
                  WHERE coursemoduleid {$cmsql} AND userid {$usql} AND completionstate IN (1, 2)
               GROUP BY coursemoduleid",
                $cmparams + $uparams
            );
            foreach ($rows as $row) {
                $done[(int) $row->coursemoduleid] = (int) $row->done;
            }
        }
        foreach ($activities as $cmid => $cm) {
            $data['activities'][] = [
                'activity_id' => $cmid,
                'name' => self::label($cm->get_formatted_name()),
                'type' => (string) $cm->modname,
                'completed_by' => $done[$cmid],
            ];
        }

        [$page, $next] = self::page($users, $args);
        $states = [];
        if ($page && $activities) {
            [$cmsql, $cmparams] = $DB->get_in_or_equal(array_keys($activities), SQL_PARAMS_NAMED, 'cm');
            [$usql, $uparams] = $DB->get_in_or_equal(array_map(static fn($u) => (int) $u->id, $page), SQL_PARAMS_NAMED, 'u');
            $records = $DB->get_recordset_select(
                'course_modules_completion',
                "coursemoduleid {$cmsql} AND userid {$usql}",
                $cmparams + $uparams,
                '',
                'id, coursemoduleid, userid, completionstate'
            );
            foreach ($records as $record) {
                $states[(int) $record->userid][(int) $record->coursemoduleid] = (int) $record->completionstate;
            }
            $records->close();
        }
        $emails = people::may_see_emails($call);
        foreach ($page as $user) {
            $own = $states[(int) $user->id] ?? [];
            $completed = array_keys(array_filter($own, static fn($state) => in_array($state, [1, 2], true)));
            $failed = array_keys(array_filter($own, static fn($state) => $state === 3));
            $data['participants'][] = people::describe($user, $call, (int) $course->id, $emails) + [
                'completed_count' => count($completed),
                'pending_count' => count($activities) - count($completed),
                'completed_activity_ids' => array_values($completed),
                'failed_activity_ids' => array_values($failed),
            ];
        }
        $result = new tool_result($data, count($data['participants']), $next, 'course', (int) $course->id);
        $result->thirdparty = count($data['participants']);
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
        return ['course_id', 'completion_enabled', 'participants_total', 'url',
            'activities' => ['*' => ['activity_id', 'name', 'type', 'completed_by']],
            'participants' => ['*' => array_merge(people::fields($profile), [
                'completed_count', 'pending_count', 'completed_activity_ids', 'failed_activity_ids',
            ])],
        ];
    }
}
