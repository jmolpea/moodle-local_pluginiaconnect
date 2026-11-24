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

namespace local_pluginiaconnect\local\audit;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\grant_context;

/**
 * Audit trail of tool calls.
 *
 * Records who called which tool on what and with which outcome. It never
 * records arguments (only a hash), returned content or tokens.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class logger {
    /** @var string Audit table. */
    public const TABLE = 'local_pluginiaconnect_audit';

    /** @var bool Test hook: make every write fail. */
    private static bool $simulatefailure = false;

    /**
     * SHA-256 of the canonical JSON of the arguments (keys sorted recursively).
     *
     * @param mixed $args Arguments of the call.
     * @return string
     */
    public static function args_hash($args): string {
        return hash('sha256', (string) json_encode(self::canonical($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Fingerprint of the arguments as the audit trail stores it: HMAC-SHA-256 of their canonical JSON
     * with a key derived from a secret of the site.
     *
     * The audit trail does not store arguments. A plain hash would still let anybody who can read it
     * test guesses ("was this surname searched for?", "was course 12 asked about?") by hashing them;
     * a keyed one does not. Equal arguments still give equal fingerprints on one site. Rows written
     * before this change keep the plain hash they were stored with: both are 64 hexadecimal characters
     * and nothing compares one with the other.
     *
     * @param mixed $args Arguments of the call.
     * @return string 64 hexadecimal characters.
     */
    public static function audit_hash($args): string {
        // A key of its own, derived from the site secret, so that this use and the subject identifiers
        // never share key material.
        $key = hash_hmac('sha256', 'audit-argshash-v1', config::subject_secret(), true);
        $json = (string) json_encode(self::canonical($args), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return hash_hmac('sha256', $json, $key);
    }

    /**
     * Writes one audit row and triggers the matching Moodle event.
     *
     * @param grant_context $grant Grant behind the token.
     * @param string $requestid Request identifier.
     * @param string $tool Tool name as requested.
     * @param bool $iswrite Whether the tool changes anything.
     * @param string $argshash Hash of the arguments.
     * @param string $outcome ok, denied, error, confirmation_required, approval_pending or rate_limited.
     * @param string|null $reason Error code when not ok.
     * @param \context|null $context Context the tool acted on.
     * @param array $details Optional: itemcount, objecttype, objectid, durationms, ip.
     * @return int Id of the audit row.
     * @throws audit_exception When the row cannot be written. The call must then return no data.
     */
    public static function log(
        grant_context $grant,
        string $requestid,
        string $tool,
        bool $iswrite,
        string $argshash,
        string $outcome,
        ?string $reason = null,
        ?\context $context = null,
        array $details = []
    ): int {
        global $DB;

        $coursecontext = $context ? $context->get_course_context(false) : null;
        $record = (object) [
            'requestid' => substr($requestid, 0, 36),
            'userid' => $grant->userid,
            'grantid' => $grant->grantid,
            'clientid' => $grant->clientid,
            'tool' => substr(preg_replace('/[^A-Za-z0-9_.-]/', '', $tool), 0, 64),
            'profile' => $grant->profile,
            'iswrite' => $iswrite ? 1 : 0,
            'contextid' => $context ? $context->id : null,
            'courseid' => $coursecontext ? $coursecontext->instanceid : null,
            'objecttype' => isset($details['objecttype']) ? substr((string) $details['objecttype'], 0, 30) : null,
            'objectid' => isset($details['objectid']) ? (int) $details['objectid'] : null,
            'argshash' => $argshash,
            'outcome' => substr($outcome, 0, 30),
            'reason' => $reason === null ? null : substr($reason, 0, 40),
            'itemcount' => isset($details['itemcount']) ? (int) $details['itemcount'] : null,
            'durationms' => max(0, (int) ($details['durationms'] ?? 0)),
            'ip' => config::bool('logip') && !empty($details['ip']) ? substr((string) $details['ip'], 0, 45) : null,
            'timecreated' => time(),
        ];
        if (self::$simulatefailure) {
            throw new audit_exception();
        }
        try {
            $record->id = (int) $DB->insert_record(self::TABLE, $record);
        } catch (\Throwable $e) {
            // A call that cannot be recorded must not return anything: the caller fails closed.
            throw new audit_exception();
        }
        self::trigger_event($record, $context);
        return $record->id;
    }

    /**
     * Makes every audit write fail. For unit tests only.
     *
     * @param bool $fail Whether writes must fail.
     * @return void
     */
    public static function simulate_failure(bool $fail): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('simulate_failure() is only available in unit tests.');
        }
        self::$simulatefailure = $fail;
    }

    /**
     * Triggers tool_called or tool_denied, if the event classes are installed.
     *
     * @param \stdClass $record Audit row.
     * @param \context|null $context Context the tool acted on.
     * @return void
     */
    private static function trigger_event(\stdClass $record, ?\context $context): void {
        $class = $record->outcome === 'ok' || $record->outcome === 'confirmation_required'
            || $record->outcome === 'approval_pending'
            ? '\\local_pluginiaconnect\\event\\tool_called'
            : '\\local_pluginiaconnect\\event\\tool_denied';
        if (!class_exists($class)) {
            return;
        }
        try {
            $class::create([
                'objectid' => $record->id,
                'relateduserid' => $record->userid,
                'context' => $context ?? \context_system::instance(),
                'other' => [
                    'tool' => $record->tool,
                    'profile' => $record->profile,
                    'outcome' => $record->outcome,
                    'reason' => $record->reason,
                    'requestid' => $record->requestid,
                ],
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('Pluginia Connect: the audit event could not be triggered.', DEBUG_DEVELOPER);
        }
    }

    /**
     * Sorts object keys recursively so that equal arguments always hash the same.
     *
     * @param mixed $value Value.
     * @return mixed
     */
    private static function canonical($value) {
        if (!is_array($value)) {
            return $value;
        }
        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }
        return array_map([self::class, 'canonical'], $value);
    }
}
