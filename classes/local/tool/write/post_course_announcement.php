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
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 24: posts an announcement in the news forum of a course.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class post_course_announcement extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'post_course_announcement';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Post course announcement',
            'Publishes one announcement, with a subject and a plain text message, in the announcements forum of a '
                . 'course, where its participants can read it and may be notified by the site. A published announcement '
                . 'cannot be withdrawn through this tool.' . self::two_step_text(true),
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course to post in.'],
                'subject' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 255, 'description' => 'Subject line.'],
                'message' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 4000,
                    'description' => 'Text of the announcement, as plain text.',
                ],
            ] + self::control_properties(),
            ['course_id', 'subject', 'message'],
            self::result_schema([
                'course_id' => ['type' => 'integer'],
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
     * Requirements. The forum capability is checked on the forum itself, in the plan.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'content.write', [], true, tool_requirement::APPROVAL_CONFIRM);
    }

    /**
     * The course the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    /**
     * Finds the announcements forum and checks the user may post there.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: course, forum, cm, subject, message.
     */
    private static function plan(array $args, call_context $call): array {
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');

        $course = self::course((int) $args['course_id']);
        $missing = new tool_exception('not_found', 'This course has no announcements forum you can post in.');
        // The forum is looked up, never created: forum_get_course_forum() would create a missing one.
        $forums = $DB->get_records('forum', ['course' => $course->id, 'type' => 'news'], 'id ASC', '*', 0, 1);
        if (!$forums) {
            throw $missing;
        }
        $forum = reset($forums);
        $cmrecord = get_coursemodule_from_instance('forum', $forum->id, $course->id);
        $cms = get_fast_modinfo($course)->get_cms();
        if (!$cmrecord || !isset($cms[$cmrecord->id]) || !$cms[$cmrecord->id]->uservisible) {
            throw $missing;
        }
        $cm = $cms[$cmrecord->id];
        $context = \context_module::instance($cm->id);
        // Announcements go to every participant: the user must be allowed to post to all of them.
        if (
            !has_capability('mod/forum:addnews', $context, $call->user)
                || !forum_user_can_post_discussion($forum, -1, -1, $cm, $context)
        ) {
            throw new tool_exception('permission_denied', 'You do not have permission to post announcements in this course.');
        }
        $subject = self::caller_text((string) $args['subject'], 255);
        $message = self::caller_text((string) $args['message'], 4000);
        if ($subject === '' || $message === '') {
            throw new tool_exception('validation_error', "'subject' and 'message' must contain visible text.");
        }
        // The forum takes the subject as plain text and refuses one that cleaning would change (markup,
        // for instance). The same rule is applied here, in the first step, so that the caller learns what
        // to correct before anything is summarised or confirmed.
        if (clean_param($subject, PARAM_TEXT) !== $subject) {
            throw new tool_exception(
                'validation_error',
                "'subject' must be plain text: remove any HTML tags or markup from it and try again."
            );
        }
        return ['course' => $course, 'forum' => $forum, 'cm' => $cm, 'subject' => $subject, 'message' => $message];
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
        $context = \context_course::instance($plan['course']->id);
        $readers = count_enrolled_users($context, '', 0, true);
        return "Publish an announcement in '" . self::label($plan['cm']->get_formatted_name()) . "' (Course: "
            . self::label(format_string($plan['course']->fullname, true, ['context' => $context]))
            . "), readable by its {$readers} active participants. It cannot be withdrawn through this connection. "
            . "Subject: {$plan['subject']}. Text: {$plan['message']}";
    }

    /**
     * Publishes the announcement through the forum's own function.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        $created = self::call_core_function('mod_forum_add_discussion', [
            'forumid' => (int) $plan['forum']->id,
            'subject' => $plan['subject'],
            // The text is the caller's plain text: it is escaped before it becomes the HTML of the post.
            'message' => text_to_html(s($plan['message']), false, true, true),
            'groupid' => -1,
            'options' => [],
        ]);
        $discussionid = (int) ($created['discussionid'] ?? 0);
        if (!$discussionid) {
            throw new tool_exception('internal_error', 'The announcement could not be published.');
        }
        return new tool_result([
            'course_id' => (int) $plan['course']->id,
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
        return ['course_id', 'discussion_id', 'subject', 'url'];
    }
}
