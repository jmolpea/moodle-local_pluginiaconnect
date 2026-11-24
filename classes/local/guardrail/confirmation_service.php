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

use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\tool\tool_exception;

/**
 * Two-step confirmation of write tools.
 *
 * The first call of a write tool changes nothing: it returns a summary built by
 * the server and a token. The token is 32 random bytes, lasts five minutes, is
 * single use and is bound to the connection, the tool and the exact arguments.
 * Only its SHA-256 is stored.
 *
 * A model can confirm by itself, so this is not a substitute for a person. Its
 * purpose is to force a verifiable summary, give the assistant the chance to show
 * it and leave a record.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class confirmation_service {
    /** @var string Prefix of confirmation tokens. */
    public const PREFIX = 'pc_ct_';

    /**
     * Records a change that waits for confirmation and issues its token.
     *
     * @param grant_context $grant Grant that asks for the change.
     * @param string $tool Tool name.
     * @param array $args Arguments of the change, without control arguments.
     * @param string $argshash Hash of the canonical arguments.
     * @param string $summary Server-built description of the change.
     * @return array [token, action record]. The token exists in clear only in this return value.
     * @throws tool_exception limit_exceeded when the connection holds too many pending changes.
     */
    public static function request(grant_context $grant, string $tool, array $args, string $argshash, string $summary): array {
        $token = self::PREFIX . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $action = action_store::create(
            $grant,
            $tool,
            action_store::MODE_CONFIRM,
            $args,
            $argshash,
            $summary,
            hash('sha256', $token)
        );
        return [$token, $action];
    }

    /**
     * Checks a confirmation token and takes the lock of its action.
     *
     * The caller must release the returned lock, after applying the change in the
     * same request. While the lock is held nobody else can redeem the token.
     *
     * @param string $token Token sent by the client.
     * @param grant_context $grant Grant that sends it.
     * @param string $tool Tool being called.
     * @param string $argshash Hash of the canonical arguments of this call.
     * @return array ['action' => record, 'lock' => lock, 'stored' => envelope or null]. When 'stored' is
     *               not null the change was already applied with this token and must not run again.
     * @throws tool_exception validation_error when the token does not fit this call or has expired.
     */
    public static function redeem(string $token, grant_context $grant, string $tool, string $argshash): array {
        global $DB;
        $mismatch = new tool_exception(
            'validation_error',
            "The confirmation token does not match this change or has expired. Call the tool again without "
                . "'confirm_token' to get a new summary and token."
        );
        if (!str_starts_with($token, self::PREFIX)) {
            throw $mismatch;
        }
        $action = $DB->get_record(action_store::TABLE, ['tokenhash' => hash('sha256', $token)]);
        if (
            !$action || (int) $action->grantid !== $grant->grantid || (int) $action->userid !== $grant->userid
            || $action->mode !== action_store::MODE_CONFIRM || !hash_equals((string) $action->tool, $tool)
            || !hash_equals((string) $action->argshash, $argshash)
        ) {
            throw $mismatch;
        }
        $lock = action_store::lock('action' . $action->id);
        try {
            // Read again under the lock: another request may have applied the change meanwhile.
            $action = $DB->get_record(action_store::TABLE, ['id' => $action->id], '*', MUST_EXIST);
            if ($action->status === 'executed') {
                $stored = action_store::stored_result($action);
                if ($stored === null) {
                    throw new tool_exception('conflict', 'This change was already applied with this token.');
                }
                return ['action' => $action, 'lock' => $lock, 'stored' => $stored];
            }
            if ($action->status !== 'pending' || $action->timeexpires < time()) {
                throw $mismatch;
            }
            return ['action' => $action, 'lock' => $lock, 'stored' => null];
        } catch (\Throwable $e) {
            $lock->release();
            throw $e;
        }
    }
}
