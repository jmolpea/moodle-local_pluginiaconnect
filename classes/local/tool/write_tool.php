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

namespace local_pluginiaconnect\local\tool;

use local_pluginiaconnect\local\guardrail\text_sanitizer;

/**
 * Base class of the tools that change something on the site.
 *
 * A write tool never runs on the first call. The call pipeline asks it for a
 * preview — a description of the exact change, built from the site's own data —
 * and only runs it after a confirmation token or the user's approval. Preview
 * and execution share one plan, so what is described is what is applied.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class write_tool extends base_tool {
    /** @var string[] Arguments that steer the call and are not part of the change. */
    public const CONTROL_ARGUMENTS = ['confirm_token', 'idempotency_key'];

    /**
     * Arguments of the change itself, without the control arguments.
     *
     * @param array $args Validated arguments of the call.
     * @return array
     */
    public static function change_args(array $args): array {
        return array_diff_key($args, array_flip(self::CONTROL_ARGUMENTS));
    }

    /**
     * Number of other people the summary of a change names. It is charged to the hourly
     * budget of records about other people, like the result of a read tool.
     *
     * @param array $args Arguments of the change.
     * @return int
     */
    public static function preview_exposure(array $args): int {
        if (isset($args['user_ids']) && is_array($args['user_ids'])) {
            return count(array_unique($args['user_ids']));
        }
        return isset($args['user_id']) || isset($args['submission_id']) ? 1 : 0;
    }

    /**
     * Schema of the control arguments every write tool accepts.
     *
     * @param bool $confirm Whether the tool can be confirmed with a token. Tools that always need
     *                      approval on the site do not take one.
     * @return array
     */
    protected static function control_properties(bool $confirm = true): array {
        $properties = [
            'idempotency_key' => [
                'type' => 'string', 'minLength' => 8, 'maxLength' => 64,
                'description' => 'Optional key chosen by the caller. Repeating a call with the same key and arguments '
                    . 'returns the stored result instead of requesting the change again.',
            ],
        ];
        if ($confirm) {
            $properties = [
                'confirm_token' => [
                    'type' => 'string', 'maxLength' => 100,
                    'description' => 'Token returned by the first call, sent back with the same arguments to apply the change.',
                ],
            ] + $properties;
        }
        return $properties;
    }

    /**
     * The three sentences that end the description of a tool confirmed with a token.
     *
     * @param bool $duplicates Whether a new call without a key is a new, separate change.
     * @return string
     */
    protected static function two_step_text(bool $duplicates): string {
        return " Called without 'confirm_token', it changes nothing and returns a summary of the change plus a token "
            . 'valid for 5 minutes; called again with the same arguments and that token, it applies the change. Where '
            . "approval is required it returns 'approval_pending' with a link to a page on the site where the signed-in "
            . 'user approves or rejects the change. '
            . ($duplicates
                ? "Repeating a confirmed call returns the stored result, while a new call without 'idempotency_key' "
                    . 'is a new, separate change.'
                : 'Repeating a confirmed call returns the stored result and does not apply the change twice.');
    }

    /**
     * The sentences that end the description of a tool that always needs approval on the site.
     *
     * @return string
     */
    protected static function approval_text(): string {
        return ' It changes nothing by itself: it creates a pending action and returns a link to a page on the site '
            . "where the signed-in user reviews the change and approves or rejects it. The tool 'get_pending_action_status' "
            . 'returns the outcome.';
    }

    /**
     * Schema of the data a write tool returns when it applied a change. Every write tool
     * returns its own fields; pending answers carry a summary instead.
     *
     * @param array $properties Properties of the applied result.
     * @return array
     */
    protected static function result_schema(array $properties): array {
        return [
            'type' => 'object',
            'properties' => $properties + [
                'summary' => ['type' => 'string'],
                'confirm_token' => ['type' => 'string'],
                'expires_in' => ['type' => 'integer'],
                'action_id' => ['type' => 'integer'],
                'approval_url' => ['type' => 'string', 'format' => 'uri'],
            ],
        ];
    }

    /**
     * Text written by the caller, made safe to store and to show in a summary: plain text,
     * no control or invisible characters.
     *
     * @param string $text Text sent by the caller.
     * @param int $max Maximum length.
     * @return string
     */
    protected static function caller_text(string $text, int $max): string {
        return text_sanitizer::clean_plain($text, $max);
    }

    /**
     * Timestamp of a strict ISO 8601 date-time argument.
     *
     * @param string $value Value sent by the caller.
     * @param string $name Argument name, for the error message.
     * @return int
     * @throws tool_exception validation_error when the value is not a date-time with a time zone.
     */
    protected static function timestamp(string $value, string $name): int {
        $pattern = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';
        $date = preg_match($pattern, $value) ? date_create_immutable($value) : false;
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            throw new tool_exception(
                'validation_error',
                "'{$name}' must be an ISO 8601 date-time with a time zone, for example 2027-03-01T23:59:00+01:00."
            );
        }
        $timestamp = $date->getTimestamp();
        if ($timestamp < 946684800 || $timestamp > 4102444800) {
            throw new tool_exception('validation_error', "'{$name}' is outside the accepted range of dates.");
        }
        return $timestamp;
    }

    /**
     * Date shown in summaries, in the time zone of the current user.
     *
     * @param int $timestamp Timestamp; 0 means not set.
     * @return string
     */
    protected static function shown_date(int $timestamp): string {
        return $timestamp ? userdate($timestamp, get_string('strftimedatetimeshort', 'langconfig')) : 'not set';
    }
}
