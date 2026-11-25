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
 * Tool 64: the posts of one forum discussion, as the signed-in user may read them.
 *
 * The forum's external function decides which posts the user sees (groups, private replies, question and
 * answer forums, timed discussions). The text of each post is read again as stored and sanitised from there,
 * as list_course_announcements does: core returns it already cleaned, and cleaning removes the styles that
 * reveal hidden text. Attachments are never returned.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_forum_discussion extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_forum_discussion';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get forum discussion',
            'Returns the posts of one forum discussion that the signed-in user can read, oldest first: author, date, '
                . 'subject, the post it answers and the text as plain text, without attachments. Use it to read a '
                . 'discussion before answering it; the post identifiers are the ones reply_to_forum_post takes. Hidden text '
                . 'is removed. Returns at most 50 posts per call; use \'cursor\' to continue.',
            [
                'discussion_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Identifier of the discussion, from list_forum_discussions.',
                ],
            ] + self::paging_properties(),
            ['discussion_id'],
            [
                'type' => 'object',
                'properties' => [
                    'discussion_id' => ['type' => 'integer'],
                    'forum_id' => ['type' => 'integer'],
                    'posts_total' => ['type' => 'integer'],
                    'posts' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'post_id' => ['type' => 'integer'],
                                'parent_post_id' => ['type' => ['integer', 'null']],
                                'author_user_id' => ['type' => ['integer', 'null']],
                                'author_name' => ['type' => 'string'],
                                'posted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'subject_user_content' => ['type' => 'string'],
                                'message_user_content' => ['type' => 'string'],
                                'private_reply' => ['type' => 'boolean'],
                                'has_attachments' => ['type' => 'boolean'],
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
        return new tool_requirement(profile::TEACHER, 'forums.read', ['mod/forum:viewdiscussion']);
    }

    /**
     * Posts are written by other people.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return true;
    }

    /**
     * The forum that holds the discussion.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_module::instance(self::forum_cm((int) $args['discussion_id'])->id);
    }

    /**
     * Course module of the forum of a discussion.
     *
     * @param int $discussionid Discussion id.
     * @return \stdClass
     */
    private static function forum_cm(int $discussionid): \stdClass {
        global $DB;
        $discussion = $DB->get_record('forum_discussions', ['id' => $discussionid], 'id, forum, course');
        $cm = $discussion ? get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course) : false;
        if (!$cm) {
            throw self::activity_not_found('Discussion', $discussionid);
        }
        return $cm;
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
        $discussionid = (int) $args['discussion_id'];
        $cmrecord = self::forum_cm($discussionid);
        $course = self::course((int) $cmrecord->course);
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Discussion');
        try {
            $found = self::call_core_function('mod_forum_get_discussion_posts', [
                'discussionid' => $discussionid,
                'sortby' => 'created',
                'sortdirection' => 'ASC',
            ]);
        } catch (\moodle_exception $e) {
            // A discussion the user may not read (another group, not yet started) is reported as missing.
            throw self::activity_not_found('Discussion', $discussionid);
        }
        $rows = array_values(array_filter($found['posts'] ?? [], static fn($post) => empty($post['isdeleted'])));
        [$page, $next] = self::page($rows, $args);
        $ids = array_filter(array_map(static fn($post) => (int) ($post['id'] ?? 0), $page));
        $stored = $ids ? $DB->get_records_list('forum_posts', 'id', $ids, '', 'id, message, messageformat, attachment') : [];
        $context = \context_module::instance($cm->id);
        $posts = [];
        $others = 0;
        foreach ($page as $post) {
            $record = $stored[(int) $post['id']] ?? null;
            $authorid = isset($post['author']['id']) ? (int) $post['author']['id'] : null;
            $posts[] = [
                'post_id' => (int) $post['id'],
                'parent_post_id' => isset($post['parentid']) ? (int) $post['parentid'] : null,
                'author_user_id' => $authorid,
                'author_name' => self::label($post['author']['fullname'] ?? ''),
                'posted_at' => self::iso(isset($post['timecreated']) ? (int) $post['timecreated'] : null),
                'subject_user_content' => self::label($post['subject'] ?? ''),
                'message_user_content' => $record ? self::plain_stored($record->message, $record->messageformat, $context) : '',
                'private_reply' => !empty($post['isprivatereply']),
                'has_attachments' => $record && !empty($record->attachment),
            ];
            $others += $authorid === (int) $call->user->id ? 0 : 1;
        }
        $result = new tool_result([
            'discussion_id' => $discussionid,
            'forum_id' => (int) $cm->id,
            'posts_total' => count($rows),
            'posts' => $posts,
            'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid]))->out(false),
        ], count($posts), $next, 'forum_discussion', $discussionid);
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
        return ['discussion_id', 'forum_id', 'posts_total', 'url', 'posts' => ['*' => [
            'post_id', 'parent_post_id', 'author_user_id', 'author_name', 'posted_at', 'subject_user_content',
            'message_user_content', 'private_reply', 'has_attachments',
        ]]];
    }
}
