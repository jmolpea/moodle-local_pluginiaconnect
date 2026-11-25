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
 * Tool 68: starts a discussion in a forum, always as the signed-in user.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class post_forum_discussion extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'post_forum_discussion';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Post forum discussion',
            'Starts one discussion, with a subject and a plain text message and without attachments, in a forum of a '
                . 'course, published as the signed-in user; announcements go through post_course_announcement, and in a '
                . 'forum with groups the group is needed unless the user may post to all of them. Subscribers are e-mailed '
                . 'and a published post cannot be withdrawn through this tool.' . self::two_step_text(true),
            [
                'forum_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Course module identifier of the forum, from list_course_forums.',
                ],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Subject line.'],
                'message' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 4000,
                    'description' => 'Text of the post, as plain text.',
                ],
                'group_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Group the discussion is for, in a forum that uses groups.',
                ],
            ] + self::control_properties(),
            ['forum_id', 'subject', 'message'],
            self::result_schema([
                'forum_id' => ['type' => 'integer'],
                'discussion_id' => ['type' => 'integer'],
                'subject' => ['type' => 'string'],
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
            ['mod/forum:startdiscussion'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The forum the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        [$cm] = self::activity('forum', (int) $args['forum_id'], 'Forum');
        return \context_module::instance($cm->id);
    }

    /**
     * Checks the forum, the group and the texts. Shared by preview and execution.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: course, cm, forum, group id, group name, subject, message.
     */
    private static function plan(array $args, call_context $call): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        [$cmrecord, $course] = self::activity('forum', (int) $args['forum_id'], 'Forum');
        $cm = self::visible_activity($course, (int) $cmrecord->id, 'Forum');
        $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
        if ($forum->type === 'news') {
            throw new tool_exception('conflict', 'This is the announcements forum of the course: use post_course_announcement.');
        }
        if ($forum->type === 'single') {
            throw new tool_exception('conflict', 'This forum holds a single discussion: reply to it with reply_to_forum_post.');
        }
        $context = \context_module::instance($cm->id);
        $groupid = -1;
        $groupname = 'all participants';
        if (groups_get_activity_groupmode($cm, $course)) {
            if (isset($args['group_id'])) {
                $groupid = (int) $args['group_id'];
                $group = groups_get_group($groupid);
                if (!$group || (int) $group->courseid !== (int) $course->id) {
                    throw new tool_exception('not_found', self::ITEM_NOT_FOUND);
                }
                $groupname = "group '" . self::label(format_string($group->name, true, ['context' => $context])) . "'";
            } else if (!has_capability('moodle/site:accessallgroups', $context, $call->user)) {
                throw new tool_exception('validation_error', "This forum uses groups: give 'group_id' with one of your groups.");
            }
        } else if (isset($args['group_id'])) {
            throw new tool_exception('validation_error', "This forum does not use groups: leave 'group_id' out.");
        }
        if (!forum_user_can_post_discussion($forum, $groupid, -1, $cm, $context)) {
            throw new tool_exception('permission_denied', 'You cannot start a discussion in this forum for that group.');
        }
        [$subject, $message] = forum_helper::texts((string) $args['subject'], (string) $args['message']);
        forum_helper::require_quota($call);
        return ['course' => $course, 'cm' => $cm, 'forum' => $forum, 'groupid' => $groupid, 'group' => $groupname,
            'subject' => $subject, 'message' => $message];
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
        return "Start a discussion in the forum '" . self::label($plan['cm']->get_formatted_name()) . "' (Course: "
            . self::label(format_string($plan['course']->fullname, true, ['context' => $coursecontext]))
            . ") for {$plan['group']}, published as you. " . forum_helper::mail_warning()
            . " Subject: {$plan['subject']}. Text: {$plan['message']}";
    }

    /**
     * Publishes the discussion through the forum's own function.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        forum_helper::count_post($call);
        $created = self::call_core_function('mod_forum_add_discussion', [
            'forumid' => (int) $plan['forum']->id,
            'subject' => $plan['subject'],
            'message' => forum_helper::html($plan['message']),
            'groupid' => $plan['groupid'],
            'options' => [],
        ]);
        $discussionid = (int) ($created['discussionid'] ?? 0);
        if (!$discussionid) {
            throw new tool_exception('internal_error', 'The discussion could not be published.');
        }
        return new tool_result([
            'forum_id' => (int) $plan['cm']->id,
            'discussion_id' => $discussionid,
            'subject' => $plan['subject'],
            'url' => (new \moodle_url('/mod/forum/discuss.php', ['d' => $discussionid]))->out(false),
        ], 1, null, 'forum_discussion', $discussionid);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['forum_id', 'discussion_id', 'subject', 'url'];
    }
}
