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

namespace local_pluginiaconnect\local\tool\write;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\forum_helper;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 69: replies to a forum post, always as the signed-in user.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class reply_to_forum_post extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'reply_to_forum_post';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Reply to forum post',
            'Publishes one plain text reply to a post of a forum discussion as the signed-in user, never private and '
                . 'never with attachments, and the forum decides whether it is allowed (locked discussions, cut-off dates, '
                . 'groups); it does not edit or delete any post. Subscribers are e-mailed and a published reply cannot be '
                . 'withdrawn through this tool.' . self::two_step_text(true),
            [
                'post_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Identifier of the post to answer, from get_forum_discussion.',
                ],
                'message' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 4000,
                    'description' => 'Text of the reply, as plain text.',
                ],
                'subject' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 255,
                    'description' => 'Subject line. By default, the subject of the post answered with the usual prefix.',
                ],
            ] + self::control_properties(),
            ['post_id', 'message'],
            self::result_schema([
                'discussion_id' => ['type' => 'integer'],
                'post_id' => ['type' => 'integer'],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            true,
            false
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::TEACHER,
            'forums.write',
            ['mod/forum:replypost'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The forum of the post.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_module::instance(self::target((int) $args['post_id'])['cm']->id);
    }

    /**
     * Post, discussion and course module of a post id.
     *
     * @param int $postid Post id.
     * @return array
     */
    private static function target(int $postid): array {
        global $DB;
        $post = $DB->get_record('forum_posts', ['id' => $postid], 'id, discussion, subject, userid, privatereplyto, deleted');
        $discussion = $post ? $DB->get_record('forum_discussions', ['id' => $post->discussion], 'id, forum, course, name') : false;
        $cm = $discussion ? get_coursemodule_from_instance('forum', $discussion->forum, $discussion->course) : false;
        if (!$post || !$cm || !empty($post->deleted)) {
            throw self::activity_not_found('Post', $postid);
        }
        return ['post' => $post, 'discussion' => $discussion, 'cm' => $cm];
    }

    /**
     * Checks the post and the texts. Shared by preview and execution.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan.
     */
    private static function plan(array $args, call_context $call): array {
        global $CFG;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $target = self::target((int) $args['post_id']);
        $course = self::course((int) $target['cm']->course);
        $cm = self::visible_activity($course, (int) $target['cm']->id, 'Post');
        // The post must be one the user can read: a private reply to someone else is reported as missing.
        if (
            $target['post']->privatereplyto && (int) $target['post']->privatereplyto !== (int) $call->user->id
                && (int) $target['post']->userid !== (int) $call->user->id
                && !has_capability('mod/forum:readprivatereplies', \context_module::instance($cm->id), $call->user)
        ) {
            throw self::activity_not_found('Post', (int) $args['post_id']);
        }
        $parentsubject = self::label($target['post']->subject);
        // The prefix the forum used for replies; its string is deprecated in recent versions, so it is fixed here.
        $prefix = 'Re:';
        $default = str_starts_with($parentsubject, $prefix) ? $parentsubject : trim($prefix . ' ' . $parentsubject);
        [$subject, $message] = forum_helper::texts(
            isset($args['subject']) ? (string) $args['subject'] : \core_text::substr($default, 0, 255),
            (string) $args['message']
        );
        forum_helper::require_quota($call);
        return ['course' => $course, 'cm' => $cm, 'target' => $target, 'subject' => $subject, 'message' => $message];
    }

    /**
     * Description of the change, with the full text that will be published.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args, $call);
        $coursecontext = \context_course::instance($plan['course']->id);
        return "Reply in the discussion '" . self::label($plan['target']['discussion']->name) . "' of the forum '"
            . self::label($plan['cm']->get_formatted_name()) . "' (Course: "
            . self::label(format_string($plan['course']->fullname, true, ['context' => $coursecontext]))
            . "), to the post '" . self::label($plan['target']['post']->subject) . "', published as you. "
            . forum_helper::mail_warning() . " Subject: {$plan['subject']}. Text: {$plan['message']}";
    }

    /**
     * Publishes the reply through the forum's own function, which checks the forum's rules.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        forum_helper::count_post($call);
        try {
            $created = self::call_core_function('mod_forum_add_discussion_post', [
                'postid' => (int) $plan['target']['post']->id,
                'subject' => $plan['subject'],
                'message' => forum_helper::html($plan['message']),
                'options' => [],
                'messageformat' => FORMAT_HTML,
            ]);
        } catch (\moodle_exception $e) {
            if (in_array($e->errorcode, ['nopostforum', 'cannotreply', 'discussionlocked'], true)) {
                throw new tool_exception('permission_denied', 'This discussion does not accept your reply now.');
            }
            throw $e;
        }
        $postid = (int) ($created['postid'] ?? 0);
        if (!$postid) {
            throw new tool_exception('internal_error', 'The reply could not be published.');
        }
        $discussionid = (int) $plan['target']['discussion']->id;
        return new tool_result([
            'discussion_id' => $discussionid,
            'post_id' => $postid,
            'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid], 'p' . $postid))->out(false),
        ], 1, null, 'forum_post', $postid);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['discussion_id', 'post_id', 'url'];
    }
}
