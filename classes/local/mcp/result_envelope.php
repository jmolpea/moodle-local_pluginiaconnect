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

namespace local_pluginiaconnect\local\mcp;

/**
 * Builds the structured content of tool results and the MCP CallToolResult around it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class result_envelope {
    /**
     * Successful result.
     *
     * @param array $data Filtered data.
     * @param string $profile Profile of the token.
     * @param bool $truncated Whether anything was cut.
     * @param string|null $nextcursor Cursor of the next page.
     * @param string $requestid Request identifier.
     * @return array
     */
    public static function success(array $data, string $profile, bool $truncated, ?string $nextcursor, string $requestid): array {
        global $SITE;
        return [
            'status' => 'ok',
            'data' => $data ?: new \stdClass(),
            'meta' => [
                'site' => format_string($SITE->fullname, true, ['context' => \context_system::instance()]),
                'profile' => $profile,
                'truncated' => $truncated,
                'next_cursor' => $nextcursor,
                'request_id' => $requestid,
            ],
        ];
    }

    /**
     * Envelope of a write that has not been applied yet: it waits for a confirmation
     * token or for the user's approval on the site. It is not an error.
     *
     * @param string $status confirmation_required or approval_pending.
     * @param array $data Summary of the change and how to complete it.
     * @param string $profile Profile of the token.
     * @param string $requestid Request identifier.
     * @return array
     */
    public static function pending(string $status, array $data, string $profile, string $requestid): array {
        return [
            'status' => $status,
            'data' => $data,
            'meta' => ['profile' => $profile, 'request_id' => $requestid],
        ];
    }

    /**
     * Envelope of a tool error.
     *
     * @param string $code Error code of section 7.5.
     * @param string $message Message for the user.
     * @param string $profile Profile of the token.
     * @param string $requestid Request identifier.
     * @param int|null $retryafter Seconds to wait, for rate limits.
     * @return array
     */
    public static function error(
        string $code,
        string $message,
        string $profile,
        string $requestid,
        ?int $retryafter = null
    ): array {
        $error = ['code' => $code, 'message' => $message];
        if ($retryafter !== null) {
            $error['retry_after'] = $retryafter;
        }
        return [
            'status' => 'error',
            'error' => $error,
            'meta' => ['profile' => $profile, 'request_id' => $requestid],
        ];
    }

    /**
     * MCP CallToolResult: the structured content plus the same JSON as text.
     *
     * @param array $envelope Structured content.
     * @param bool $iserror Whether it is a tool error.
     * @return array
     */
    public static function call_result(array $envelope, bool $iserror): array {
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE;
        return [
            'content' => [['type' => 'text', 'text' => (string) json_encode($envelope, $flags)]],
            'structuredContent' => $envelope,
            'isError' => $iserror,
        ];
    }
}
