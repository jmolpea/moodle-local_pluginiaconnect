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
 * Tool 65: per learner, discussions started and replies posted in one forum.
 *
 * Own query, like the summary report of the forum: it counts only posts the caller could see on the forum
 * pages (groups, timed discussions not yet visible, private replies).
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_forum_participation_summary extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_forum_participation_summary';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get forum participation summary',
            'Returns, for one forum of a course the signed-in user teaches, each learner with the number of '
                . 'discussions they started, the number of replies they posted and the date of their last post, including '
                . 'those who have not posted, optionally within a period. Use it to see who takes part in a forum. Only '
                . 'posts the user could see on the forum pages are counted, and with separate groups only members of the '
                . 'user\'s own groups are listed. Returns at most 50 learners per call; use \'cursor\' to continue.',
            [
                'forum_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Course module identifier of the forum, from list_course_forums.',
                ],
                'from' => [
                    'type' => 'string', 'format' => 'date-time', 'maxLength' => 40,
                    'description' => 'Count only posts from this moment, ISO 8601 with a time zone.',
                ],
                'to' => [
                    'type' => 'string', 'format' => 'date-time', 'maxLength' => 40,
                    'description' => 'Count only posts before this moment, ISO 8601 with a time zone.',
                ],
            ] + self::paging_properties(),
            ['forum_id'],
            [
                'type' => 'object',
                'properties' => [
                    'forum_id' => ['type' => 'integer'],
                    'forum_name' => ['type' => 'string'],
                    'learners_total' => ['type' => 'integer'],
                    'learners_who_posted' => ['type' => 'integer'],
                    'participants' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + [
                                'discussions_started' => ['type' => 'integer'],
                                'replies' => ['type' => 'integer'],
                                'last_post_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
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
        return new tool_requirement(profile::TEACHER, 'reports.read', ['forumreport/summary:viewall']);
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
     * The tool acts on one forum.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('forum', (int) $args['forum_id'], 'Forum');
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
        [$cmrecord, $course] = self::activity('forum', (int) $args['forum_id'], 'Forum');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Forum');
        $context = \context_module::instance($cm->id);
        $from = isset($args['from']) ? report_helper::timestamp((string) $args['from'], 'from') : 0;
        $to = isset($args['to']) ? report_helper::timestamp((string) $args['to'], 'to') : 0;

        // Learners: holders of a student role in the course who may read this forum, within the caller's groups.
        $learners = array_intersect_key(
            report_helper::activity_participants($cm, $call->user, 'mod/forum:viewdiscussion'),
            report_helper::course_learners($course, \context_course::instance($course->id), $call->user)
        );
        $counts = [];
        if ($learners) {
            [$insql, $params] = $DB->get_in_or_equal(array_keys($learners), SQL_PARAMS_NAMED, 'u');
            $params += ['forum' => $cm->instance];
            $where = "d.forum = :forum AND p.userid {$insql} AND p.deleted = 0";
            if ($from) {
                $where .= ' AND p.created >= :tfrom';
                $params['tfrom'] = $from;
            }
            if ($to) {
                $where .= ' AND p.created < :tto';
                $params['tto'] = $to;
            }
            if (!has_capability('mod/forum:viewhiddentimedposts', $context, $call->user)) {
                $where .= ' AND (d.timestart = 0 OR d.timestart <= :now1) AND (d.timeend = 0 OR d.timeend > :now2)';
                $params += ['now1' => time(), 'now2' => time()];
            }
            if (!has_capability('mod/forum:readprivatereplies', $context, $call->user)) {
                $where .= ' AND (p.privatereplyto = 0 OR p.privatereplyto = :me)';
                $params['me'] = $call->user->id;
            }
            $rows = $DB->get_records_sql(
                "SELECT p.userid,
                        SUM(CASE WHEN p.parent = 0 THEN 1 ELSE 0 END) AS started,
                        SUM(CASE WHEN p.parent <> 0 THEN 1 ELSE 0 END) AS replies,
                        MAX(p.created) AS lastpost
                   FROM {forum_posts} p
                   JOIN {forum_discussions} d ON d.id = p.discussion
                  WHERE {$where}
               GROUP BY p.userid",
                $params
            );
            foreach ($rows as $row) {
                $counts[(int) $row->userid] = [(int) $row->started, (int) $row->replies, (int) $row->lastpost];
            }
        }
        [$page, $next] = self::page($learners, $args);
        $emails = people::may_see_emails($call);
        $participants = [];
        foreach ($page as $user) {
            [$started, $replies, $last] = $counts[(int) $user->id] ?? [0, 0, 0];
            $participants[] = people::describe($user, $call, (int) $course->id, $emails) + [
                'discussions_started' => $started,
                'replies' => $replies,
                'last_post_at' => self::iso($last ?: null),
            ];
        }
        $result = new tool_result([
            'forum_id' => (int) $cm->id,
            'forum_name' => self::label($cm->get_formatted_name()),
            'learners_total' => count($learners),
            'learners_who_posted' => count($counts),
            'participants' => $participants,
            'url' => (new \moodle_url('/mod/forum/report/summary/index.php', ['courseid' => $course->id,
                'forumid' => $cm->instance]))->out(false),
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
            return ['forum_id'];
        }
        return ['forum_id', 'forum_name', 'learners_total', 'learners_who_posted', 'url',
            'participants' => ['*' => array_merge(people::fields($profile), ['discussions_started', 'replies', 'last_post_at'])]];
    }
}
