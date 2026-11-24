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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 25: sends one message to named participants of one course.
 *
 * Recipients are always listed one by one and must be participants of the course
 * that the sender can see there. There is no "everyone" target. Besides the limit
 * per call, a connection can reach a fixed number of recipients per day.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class send_message_to_participants extends write_tool {
    /** @var int Recipients a connection may reach per day. */
    public const DAILY_RECIPIENTS = 200;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'send_message_to_participants';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Send message to participants',
            'Sends one plain text message, through the messaging of the site, to up to 50 named participants of one '
                . 'course. A sent message cannot be withdrawn, and each connection can reach a limited number of '
                . 'recipients per day.' . self::two_step_text(true),
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course the recipients belong to.'],
                'user_ids' => [
                    'type' => 'array', 'minItems' => 1, 'maxItems' => 50, 'uniqueItems' => true,
                    'items' => ['type' => 'integer', 'minimum' => 1],
                    'description' => "Recipients, as returned by 'list_course_participants'.",
                ],
                'message' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 4000,
                    'description' => 'Text of the message, as plain text.',
                ],
            ] + self::control_properties(),
            ['course_id', 'user_ids', 'message'],
            self::result_schema([
                'course_id' => ['type' => 'integer'],
                'sent' => ['type' => 'integer'],
                'failed' => ['type' => 'integer'],
                'failed_user_ids' => ['type' => 'array', 'items' => ['type' => 'integer']],
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
            'messages.write',
            ['moodle/site:sendmessage', 'moodle/course:bulkmessaging'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
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
     * Checks recipients, text and limits.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return array Plan: course, context, recipients (user records), message.
     */
    private static function plan(array $args, call_context $call): array {
        global $CFG;
        if (empty($CFG->messaging)) {
            throw new tool_exception('tool_disabled', 'Messaging is switched off on this site.');
        }
        $course = self::course((int) $args['course_id']);
        $context = \context_course::instance($course->id);
        $ids = array_values(array_unique(array_map('intval', $args['user_ids'])));
        $max = config::int('maxrecipients');
        if (count($ids) > $max) {
            throw new tool_exception('limit_exceeded', "A maximum of {$max} recipients can be messaged per call.");
        }
        // Only participants of this course that the sender can see there, in their own groups when groups are separate.
        $visible = people::visible_enrolled($course, $context, $call->user);
        $recipients = [];
        foreach ($ids as $id) {
            if (!isset($visible[$id]) || $id === (int) $call->user->id) {
                throw new tool_exception(
                    'validation_error',
                    'One or more recipients are not participants of this course that you can message.'
                );
            }
            $recipients[$id] = $visible[$id];
        }
        $used = rate_limiter::current('message_recipients', (string) $call->grant->grantid, DAYSECS);
        if ($used + count($recipients) > self::DAILY_RECIPIENTS) {
            throw new tool_exception(
                'limit_exceeded',
                'This connection can message ' . self::DAILY_RECIPIENTS . ' recipients per day and has '
                    . max(0, self::DAILY_RECIPIENTS - $used) . ' left today.'
            );
        }
        $message = self::caller_text((string) $args['message'], 4000);
        if ($message === '') {
            throw new tool_exception('validation_error', "'message' must contain visible text.");
        }
        return ['course' => $course, 'context' => $context, 'recipients' => $recipients, 'message' => $message];
    }

    /**
     * Description of the change, with every recipient and the full text.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args, $call);
        $names = [];
        foreach ($plan['recipients'] as $user) {
            $names[] = people::name($user, $plan['context']) . ' (id ' . (int) $user->id . ')';
        }
        return 'Send a message to ' . count($names) . ' participant(s) of the course '
            . self::label(format_string($plan['course']->fullname, true, ['context' => $plan['context']])) . ': '
            . implode(', ', $names) . ". It cannot be withdrawn. Text: {$plan['message']}";
    }

    /**
     * Sends the message through the messaging function of the site, which applies each recipient's preferences.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $plan = self::plan($args, $call);
        // The daily allowance is used up before sending, under a lock, and refused if it cannot be counted.
        $subject = (string) $call->grant->grantid;
        foreach ($plan['recipients'] as $unused) {
            if (!rate_limiter::hit('message_recipients', $subject, self::DAILY_RECIPIENTS, DAYSECS, true)) {
                throw new tool_exception('limit_exceeded', 'This connection has reached its daily number of message recipients.');
            }
        }
        $messages = [];
        foreach (array_keys($plan['recipients']) as $userid) {
            $messages[] = ['touserid' => $userid, 'text' => $plan['message'], 'textformat' => FORMAT_PLAIN];
        }
        $results = self::call_core_function('core_message_send_instant_messages', ['messages' => $messages]);
        $ids = array_keys($plan['recipients']);
        $failed = [];
        foreach (array_values($results) as $index => $result) {
            if ((int) ($result['msgid'] ?? -1) < 0) {
                $failed[] = (int) $ids[$index];
            }
        }
        $result = new tool_result([
            'course_id' => (int) $plan['course']->id,
            'sent' => count($ids) - count($failed),
            'failed' => count($failed),
            'failed_user_ids' => $failed,
        ], count($ids), null, 'course', (int) $plan['course']->id);
        return $result;
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'sent', 'failed', 'failed_user_ids'];
    }
}
