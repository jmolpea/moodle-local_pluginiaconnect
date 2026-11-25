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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\guardrail\text_sanitizer;

/**
 * Rules shared by the two tools that publish in forums (amendment 2, block C, tools 68 and 69).
 *
 * Posts are always published as the signed-in user (D28), as plain text, without attachments, pinning or
 * private replies, and a connection may publish at most the daily limit of the site. Subscribers receive each
 * post by e-mail once the editing time of the site has passed, and that e-mail cannot be withdrawn.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class forum_helper {
    /** @var string Bucket of the daily counter of posts per connection. */
    private const BUCKET = 'forumposts_day';

    /**
     * Subject and message sent by the caller, as plain text the forum accepts.
     *
     * @param string $subject Subject.
     * @param string $message Message.
     * @return string[] [subject, message]
     */
    public static function texts(string $subject, string $message): array {
        $subject = text_sanitizer::clean_plain($subject, 255);
        $message = text_sanitizer::clean_plain($message, 4000);
        if ($subject === '' || $message === '') {
            throw new tool_exception('validation_error', "'subject' and 'message' must contain visible text.");
        }
        if (clean_param($subject, PARAM_TEXT) !== $subject) {
            throw new tool_exception(
                'validation_error',
                "'subject' must be plain text: remove any HTML tags or markup from it and try again."
            );
        }
        return [$subject, $message];
    }

    /**
     * The caller's plain text as the HTML of a post: escaped, never interpreted.
     *
     * @param string $message Plain text.
     * @return string
     */
    public static function html(string $message): string {
        return text_to_html(s($message), false, true, true);
    }

    /**
     * Refuses the call when the connection has published its daily number of posts.
     *
     * @param call_context $call Call data.
     * @return void
     */
    public static function require_quota(call_context $call): void {
        $limit = config::int('maxforumpostsday');
        if (rate_limiter::current(self::BUCKET, (string) $call->grant->grantid, DAYSECS) >= $limit) {
            throw new tool_exception(
                'limit_exceeded',
                "This connection has published its {$limit} forum posts for today. Try again tomorrow."
            );
        }
    }

    /**
     * Counts one published post against the daily limit of the connection.
     *
     * @param call_context $call Call data.
     * @return void
     */
    public static function count_post(call_context $call): void {
        $limit = config::int('maxforumpostsday');
        if (!rate_limiter::hit(self::BUCKET, (string) $call->grant->grantid, $limit, DAYSECS, true)) {
            throw new tool_exception(
                'limit_exceeded',
                "This connection has published its {$limit} forum posts for today. Try again tomorrow."
            );
        }
    }

    /**
     * The warning every summary ends with: who gets an e-mail, and that it cannot be withdrawn.
     *
     * @return string
     */
    public static function mail_warning(): string {
        global $CFG;
        $minutes = max(0, (int) round(((int) ($CFG->maxeditingtime ?? 1800)) / 60));
        return "Subscribers of the forum will receive it by e-mail about {$minutes} minutes after it is published, "
            . 'and that e-mail cannot be withdrawn. The post cannot be edited or deleted through this connection.';
    }
}
