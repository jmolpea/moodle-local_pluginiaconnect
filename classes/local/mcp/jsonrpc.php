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

use local_pluginiaconnect\local\http\response;

/**
 * JSON-RPC 2.0 message helpers.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class jsonrpc {
    /** @var int Invalid JSON. */
    public const PARSE_ERROR = -32700;
    /** @var int Not a valid request object. */
    public const INVALID_REQUEST = -32600;
    /** @var int Unknown method. */
    public const METHOD_NOT_FOUND = -32601;
    /** @var int Invalid parameters, unknown tool. */
    public const INVALID_PARAMS = -32602;
    /** @var int A required header is missing or does not match the body (revision 2026-07-28). */
    public const HEADER_MISMATCH = -32020;
    /** @var int Unsupported protocol version (revision 2026-07-28). */
    public const UNSUPPORTED_VERSION = -32022;

    /**
     * Whether a value is acceptable as a request id.
     *
     * @param mixed $id Value of the "id" member.
     * @return bool
     */
    public static function is_valid_id($id): bool {
        return is_int($id) || (is_string($id) && strlen($id) <= 200);
    }

    /**
     * HTTP response carrying a JSON-RPC result.
     *
     * @param mixed $id Request id.
     * @param array|\stdClass $result Result member.
     * @param int $status HTTP status.
     * @param array $headers Extra headers.
     * @return response
     */
    public static function result($id, $result, int $status = 200, array $headers = []): response {
        return response::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result], $headers);
    }

    /**
     * HTTP response carrying a JSON-RPC error.
     *
     * @param mixed $id Request id, or null when it could not be read.
     * @param int $code Error code.
     * @param string $message Error message, without internal detail.
     * @param int $status HTTP status.
     * @param array|null $data Optional data member.
     * @return response
     */
    public static function error($id, int $code, string $message, int $status = 200, ?array $data = null): response {
        $error = ['code' => $code, 'message' => $message];
        if ($data !== null) {
            $error['data'] = $data;
        }
        return response::json($status, ['jsonrpc' => '2.0', 'id' => $id, 'error' => $error]);
    }
}
