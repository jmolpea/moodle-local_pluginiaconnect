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
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool 63: discussions of one forum, through the forum's own external function (groups, timed discussions).
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_forum_discussions extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_forum_discussions';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List forum discussions',
            'Returns the discussions of one forum of a course the signed-in user teaches, most recent activity first: '
                . 'subject, who started it, number of replies, last activity and whether it is pinned or locked. Use it '
                . 'to find a discussion to read or answer. The forum applies its own rules on groups and timed '
                . 'discussions. Returns at most 50 discussions per call; use \'cursor\' to continue.',
            [
                'forum_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Course module identifier of the forum, from list_course_forums.',
                ],
            ] + self::paging_properties(),
            ['forum_id'],
            [
                'type' => 'object',
                'properties' => [
                    'forum_id' => ['type' => 'integer'],
                    'discussions' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'discussion_id' => ['type' => 'integer'],
                                'first_post_id' => ['type' => 'integer'],
                                'subject_user_content' => ['type' => 'string'],
                                'author_user_id' => ['type' => 'integer'],
                                'author_name' => ['type' => 'string'],
                                'group_id' => ['type' => 'integer'],
                                'replies' => ['type' => 'integer'],
                                'created_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'last_activity_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'pinned' => ['type' => 'boolean'],
                                'locked' => ['type' => 'boolean'],
                                'can_reply' => ['type' => 'boolean'],
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
        return new tool_requirement(profile::TEACHER, 'forums.read', ['mod/forum:viewdiscussion']);
    }

    /**
     * Each discussion names the person who started it.
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
        [$cmrecord, $course] = self::activity('forum', (int) $args['forum_id'], 'Forum');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Forum');
        $limit = self::limit($args);
        $offset = self::position($args);
        // Pages are aligned to the page size, so the position is always a multiple of it.
        $found = self::call_core_function('mod_forum_get_forum_discussions', [
            'forumid' => (int) $cm->instance,
            'sortorder' => -1,
            'page' => intdiv($offset, $limit),
            'perpage' => $limit,
        ]);
        $rows = array_slice($found['discussions'] ?? [], 0, $limit);
        $next = count($rows) >= $limit ? self::cursor((intdiv($offset, $limit) + 1) * $limit) : null;
        $discussions = [];
        $others = 0;
        foreach ($rows as $row) {
            $discussionid = (int) ($row['discussion'] ?? 0);
            $discussions[] = [
                'discussion_id' => $discussionid,
                'first_post_id' => (int) ($row['id'] ?? 0),
                'subject_user_content' => self::label($row['name'] ?? $row['subject'] ?? ''),
                'author_user_id' => (int) ($row['userid'] ?? 0),
                'author_name' => self::label($row['userfullname'] ?? ''),
                'group_id' => (int) ($row['groupid'] ?? -1),
                'replies' => (int) ($row['numreplies'] ?? 0),
                'created_at' => self::iso((int) ($row['created'] ?? 0)),
                'last_activity_at' => self::iso((int) ($row['timemodified'] ?? 0)),
                'pinned' => !empty($row['pinned']),
                'locked' => !empty($row['locked']),
                'can_reply' => !empty($row['canreply']),
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid]))->out(false),
            ];
            $others += (int) ($row['userid'] ?? 0) === (int) $call->user->id ? 0 : 1;
        }
        $result = new tool_result(
            ['forum_id' => (int) $cm->id, 'discussions' => $discussions],
            count($discussions),
            $next,
            'course_module',
            (int) $cm->id
        );
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
        return ['forum_id', 'discussions' => ['*' => [
            'discussion_id', 'first_post_id', 'subject_user_content', 'author_user_id', 'author_name', 'group_id',
            'replies', 'created_at', 'last_activity_at', 'pinned', 'locked', 'can_reply', 'url',
        ]]];
    }
}
