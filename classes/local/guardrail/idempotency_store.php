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

namespace local_pluginiaconnect\local\guardrail;

use local_pluginiaconnect\local\tool\tool_exception;

/**
 * Idempotency keys of write tools.
 *
 * The same connection, tool, key and arguments within 24 hours get the stored
 * result back instead of a second execution. The same key with other arguments
 * is a conflict. Only the SHA-256 of the key is stored.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class idempotency_store {
    /** @var string Table name. */
    public const TABLE = 'local_pluginiaconnect_idem';
    /** @var int How long a key is remembered, in seconds. */
    public const TTL = DAYSECS;

    /**
     * Looks a key up.
     *
     * @param int $grantid Grant id.
     * @param string $tool Tool name.
     * @param string $key Key sent by the client.
     * @param string $argshash Hash of the canonical arguments of this call.
     * @return array|null Stored envelope, or null when the key is new.
     * @throws tool_exception conflict when the key was used with different arguments.
     */
    public static function find(int $grantid, string $tool, string $key, string $argshash): ?array {
        global $DB;
        $record = $DB->get_record(self::TABLE, ['grantid' => $grantid, 'tool' => $tool, 'keyhash' => hash('sha256', $key)]);
        if (!$record) {
            return null;
        }
        if ($record->timeexpires < time()) {
            $DB->delete_records(self::TABLE, ['id' => $record->id]);
            return null;
        }
        if (!hash_equals((string) $record->argshash, $argshash)) {
            throw new tool_exception(
                'conflict',
                "This 'idempotency_key' was already used with different arguments. Use a new key for a different change."
            );
        }
        $result = json_decode((string) $record->result, true);
        return is_array($result) ? $result : null;
    }

    /**
     * Remembers the result of a call made with a key. An existing row of the same key is replaced.
     *
     * @param int $grantid Grant id.
     * @param string $tool Tool name.
     * @param string $key Key sent by the client.
     * @param string $argshash Hash of the canonical arguments.
     * @param array $envelope Envelope to return on repeats.
     * @return void
     */
    public static function save(int $grantid, string $tool, string $key, string $argshash, array $envelope): void {
        global $DB;
        $keyhash = hash('sha256', $key);
        $record = (object) [
            'grantid' => $grantid,
            'tool' => $tool,
            'keyhash' => $keyhash,
            'argshash' => $argshash,
            'result' => json_encode($envelope, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
            'timeexpires' => time() + self::TTL,
        ];
        $existing = $DB->get_field(self::TABLE, 'id', ['grantid' => $grantid, 'tool' => $tool, 'keyhash' => $keyhash]);
        if ($existing) {
            $record->id = $existing;
            $DB->update_record(self::TABLE, $record);
        } else {
            $DB->insert_record(self::TABLE, $record);
        }
    }
}
