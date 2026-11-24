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

use local_pluginiaconnect\local\config;

/**
 * Protocol revisions the server speaks and the differences between them.
 *
 * Two eras share the endpoint. Legacy clients (2025-06-18 and 2025-11-25) open
 * with "initialize" and then send the MCP-Protocol-Version header. Clients of
 * revision 2026-07-28 send no "initialize": every request carries its version
 * and capabilities in "_meta" and is checked against the Mcp-* headers. Both
 * eras are served without any server-side session.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class protocol {
    /** @var string Revision served statelessly from per-request metadata. */
    public const MODERN = '2026-07-28';
    /** @var string[] Revisions served with legacy semantics, newest first. */
    public const LEGACY = ['2025-11-25', '2025-06-18'];

    /** @var string Request metadata key: protocol version. */
    public const META_VERSION = 'io.modelcontextprotocol/protocolVersion';
    /** @var string Request metadata key: client capabilities. */
    public const META_CAPABILITIES = 'io.modelcontextprotocol/clientCapabilities';
    /** @var string Result metadata key: server information. */
    public const META_SERVERINFO = 'io.modelcontextprotocol/serverInfo';

    /** @var int How long a client may cache the server description, in milliseconds. */
    public const DISCOVER_TTL_MS = 3600000;
    /** @var int How long a client may cache the tool list, in milliseconds. */
    public const TOOLS_TTL_MS = 300000;

    /**
     * Every supported revision, newest first.
     *
     * @return string[]
     */
    public static function supported(): array {
        return array_merge([self::MODERN], self::LEGACY);
    }

    /**
     * Name and version of this server.
     *
     * @return array
     */
    public static function server_info(): array {
        $info = \core_plugin_manager::instance()->get_plugin_info(config::COMPONENT);
        $version = $info && !empty($info->release) ? (string) $info->release : '0';
        return ['name' => 'pluginia-connect', 'version' => $version];
    }

    /**
     * Decodes the value of an Mcp-* header. Values that are not header-safe
     * arrive as "=?base64?<Base64 of UTF-8>?=".
     *
     * @param string $value Header value.
     * @return string|null Decoded value, or null when the encoding is malformed.
     */
    public static function decode_header(string $value): ?string {
        $value = trim($value);
        if (preg_match('/^=\?base64\?([A-Za-z0-9+\/]*=*)\?=$/', $value, $matches)) {
            $decoded = base64_decode($matches[1], true);
            return $decoded === false || !mb_check_encoding($decoded, 'UTF-8') ? null : $decoded;
        }
        return $value;
    }
}
