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
 * Tool: list_course_announcements.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_course_announcements extends base_tool {
    /** @var int Default number of announcements per page. */
    protected const DEFAULT_LIMIT = 10;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_course_announcements';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List course announcements',
            'Returns the most recent announcements of one course, newest first, with their subject, text, author name '
                . 'and date. Use it to see what the teachers have announced. Only the announcements forum of the '
                . 'course is read; other forums are not. Fields whose name ends in \'_user_content\' hold text written '
                . 'by course teachers and are returned as written. Returns at most 50 announcements per call; use '
                . '\'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'announcements' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['subject_user_content', 'url'],
                            'properties' => [
                                'subject_user_content' => ['type' => 'string'],
                                'message_user_content' => ['type' => 'string'],
                                'author_name' => ['type' => 'string'],
                                'posted_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'pinned' => ['type' => 'boolean'],
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
        $empty = new tool_result(['course_id' => (int) $course->id, 'announcements' => []], 0, null, 'course', (int) $course->id);

        // The news forum is looked up, never created: forum_get_course_forum() would create a missing one.
        $forums = $DB->get_records('forum', ['course' => $course->id, 'type' => 'news'], 'id ASC', 'id', 0, 1);
        if (!$forums) {
            return $empty;
        }
        $forumid = (int) reset($forums)->id;
        $cm = get_coursemodule_from_instance('forum', $forumid, $course->id);
        $cms = get_fast_modinfo($course)->get_cms();
        if (!$cm || !isset($cms[$cm->id]) || !$cms[$cm->id]->uservisible) {
            return $empty;
        }
        if (!has_capability('mod/forum:viewdiscussion', \context_module::instance($cm->id), $call->user)) {
            return $empty;
        }

        $limit = self::limit($args);
        $offset = self::position($args);
        // Pages are aligned to the page size, so the position is always a multiple of it.
        $found = self::call_core_function('mod_forum_get_forum_discussions', [
            'forumid' => $forumid,
            'sortorder' => -1,
            'page' => intdiv($offset, $limit),
            'perpage' => $limit,
        ]);
        $rows = $found['discussions'] ?? [];
        // A full page may be the last one; the next call then returns an empty list.
        $next = count($rows) >= $limit ? self::cursor((intdiv($offset, $limit) + 1) * $limit) : null;
        // The text is read as stored: core returns it cleaned, and cleaning removes the styles that mark hidden text.
        $postids = array_filter(array_map(static fn($row) => (int) ($row['id'] ?? 0), $rows));
        $stored = $postids ? $DB->get_records_list('forum_posts', 'id', $postids, '', 'id, message, messageformat') : [];
        $forumcontext = \context_module::instance($cm->id);
        $announcements = [];
        foreach (array_slice($rows, 0, $limit) as $row) {
            $post = $stored[(int) ($row['id'] ?? 0)] ?? null;
            $announcements[] = [
                'subject_user_content' => self::label($row['subject'] ?? $row['name'] ?? ''),
                'message_user_content' => $post
                    ? self::plain_stored($post->message, $post->messageformat, $forumcontext) : '',
                'author_name' => self::label($row['userfullname'] ?? ''),
                'posted_at' => self::iso((int) ($row['created'] ?? 0)),
                'pinned' => !empty($row['pinned']),
                'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => (int) $row['discussion']]))->out(false),
            ];
        }
        return new tool_result(
            ['course_id' => (int) $course->id, 'announcements' => $announcements],
            count($announcements),
            $next,
            'course',
            (int) $course->id
        );
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'announcements' => ['*' => [
            'subject_user_content', 'message_user_content', 'author_name', 'posted_at', 'pinned', 'url',
        ]]];
    }
}
