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

namespace local_pluginiaconnect\local\oauth;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\urls;

/**
 * Grants and tokens: issue, refresh, authenticate, revoke and clean up.
 *
 * Tokens are opaque random values. Only their SHA-256 hash is stored; the
 * plain value exists only in the HTTP response that delivers it and is never
 * written to logs, events or error messages.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class token_service {
    /** @var string Prefix of access tokens. Lets secret scanners recognise them. */
    public const PREFIX_ACCESS = 'pc_at_';
    /** @var string Prefix of refresh tokens. */
    public const PREFIX_REFRESH = 'pc_rt_';
    /** @var int Seconds during which a refresh token already used returns the same new pair. */
    public const GRACE_SECONDS = 30;
    /** @var int Days a revoked grant is kept before deletion. */
    public const REVOKED_RETENTION_DAYS = 30;

    /** @var string Grant table. */
    private const T_GRANT = 'local_pluginiaconnect_grant';
    /** @var string Token table. */
    private const T_TOKEN = 'local_pluginiaconnect_token';
    /** @var string Authorization code table. */
    private const T_CODE = 'local_pluginiaconnect_authcode';
    /** @var int Seconds between updates of the last used time. */
    private const TOUCH_INTERVAL = 60;
    /** @var int Seconds to wait for a lock. */
    private const LOCK_TIMEOUT = 5;
    /** @var string Domain separation label of the grace data key. */
    private const GRACE_INFO = 'local_pluginiaconnect/grace/v1';

    /**
     * New random secret with a prefix: 32 random bytes in base64url.
     *
     * @param string $prefix Prefix.
     * @return string
     */
    public static function random_token(string $prefix): string {
        return $prefix . rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    /**
     * Storage hash of a secret.
     *
     * @param string $value Plain value.
     * @return string 64 hexadecimal characters.
     */
    public static function hash(string $value): string {
        return hash('sha256', $value);
    }

    /**
     * Runs a callback while holding a named lock.
     *
     * @param string $key Lock name.
     * @param callable $callback Code to run.
     * @return mixed Whatever the callback returns.
     * @throws oauth_exception When the lock cannot be obtained in time.
     */
    public static function with_lock(string $key, callable $callback) {
        $factory = \core\lock\lock_config::get_lock_factory('local_pluginiaconnect_oauth');
        $lock = $factory->get_lock($key, self::LOCK_TIMEOUT);
        if (!$lock) {
            throw new oauth_exception('temporarily_unavailable', 'The server is busy. Try again.', 503, ['Retry-After' => '2']);
        }
        try {
            return $callback();
        } finally {
            $lock->release();
        }
    }

    /**
     * Creates the grant for a redeemed authorization code and issues its first tokens.
     *
     * The user is checked again: still active, still allowed to connect and still eligible for the profile.
     *
     * @param \stdClass $code Authorization code record, already validated and marked as used.
     * @param client $client Client that redeemed it.
     * @return array Token response.
     * @throws oauth_exception When the user is no longer allowed.
     */
    public static function issue_for_code(\stdClass $code, client $client): array {
        global $DB;
        $user = $DB->get_record('user', ['id' => $code->userid]);
        if ($user) {
            eligibility::invalidate((int) $user->id);
        }
        if (!$user || eligibility::connect_denial($user) !== null || !eligibility::is_eligible((int) $user->id, $code->profile)) {
            throw new oauth_exception('invalid_grant', 'The user can no longer use this connection.');
        }
        $now = time();
        $transaction = $DB->start_delegated_transaction();
        $grant = (object) [
            'userid' => (int) $code->userid,
            'clientid' => $client->id,
            'profile' => $code->profile,
            'scopes' => $code->scopes,
            'resource' => $code->resource,
            'actor' => $code->actor,
            'actorid' => $code->actorid,
            'status' => 1,
            'revokedby' => null,
            'timecreated' => $now,
            'timelastused' => $now,
            'timerevoked' => null,
            'timeabsoluteexpiry' => $now + config::int('grantabsolutettl'),
        ];
        $grant->id = $DB->insert_record(self::T_GRANT, $grant);
        $DB->set_field(self::T_CODE, 'grantid', $grant->id, ['id' => $code->id]);
        $pair = self::issue_pair($grant, null);
        $transaction->allow_commit();

        client_repository::touch($client);
        self::notify('connection_granted', (int) $grant->id, (int) $grant->userid, [
            'clientid' => $client->id,
            'profile' => $grant->profile,
            'scopes' => $grant->scopes,
        ]);
        return self::token_response($grant, $pair);
    }

    /**
     * Exchanges a refresh token for a new pair, rotating it.
     *
     * A refresh token that is presented a second time within the grace window
     * gets the same pair again (lost responses and races). After the window the
     * whole grant is revoked, because a replay means the token leaked.
     *
     * @param string $refreshtoken Refresh token received.
     * @param client $client Authenticated client.
     * @param string|null $resource Resource parameter, when the client sent one.
     * @return array Token response.
     * @throws oauth_exception On any failure; always invalid_grant unless the resource is wrong.
     */
    public static function refresh(string $refreshtoken, client $client, ?string $resource = null): array {
        global $DB;
        $invalid = new oauth_exception('invalid_grant', 'The refresh token is invalid, expired or revoked.');
        if (!str_starts_with($refreshtoken, self::PREFIX_REFRESH)) {
            throw $invalid;
        }
        $token = $DB->get_record(self::T_TOKEN, ['tokenhash' => self::hash($refreshtoken), 'type' => 'refresh']);
        $grant = $token ? $DB->get_record(self::T_GRANT, ['id' => $token->grantid]) : null;
        if (!$token || !$grant || (int) $grant->clientid !== $client->id) {
            throw $invalid;
        }
        if ($resource !== null && urls::canonical($resource) !== urls::canonical($grant->resource)) {
            throw new oauth_exception('invalid_target', 'The resource does not match the one this connection was issued for.');
        }

        return self::with_lock('rt' . $token->id, static function () use ($token, $refreshtoken, $invalid) {
            global $DB;
            $now = time();
            // Read again inside the lock: a concurrent request may have rotated the token meanwhile.
            $token = $DB->get_record(self::T_TOKEN, ['id' => $token->id], '*', MUST_EXIST);
            $grant = $DB->get_record(self::T_GRANT, ['id' => $token->grantid], '*', MUST_EXIST);

            if (!empty($token->timeused)) {
                if (!empty($grant->status) && $now - (int) $token->timeused <= self::GRACE_SECONDS) {
                    $pair = self::read_grace($token, $refreshtoken);
                    if ($pair !== null) {
                        return self::token_response($grant, $pair);
                    }
                }
                self::revoke_grant((int) $grant->id, 'reuse');
                self::notify('token_reuse_detected', (int) $grant->id, (int) $grant->userid, ['kind' => 'refresh']);
                throw $invalid;
            }
            if (!empty($token->timerevoked) || (int) $token->timeexpires <= $now || empty($grant->status)) {
                throw $invalid;
            }
            if ((int) $grant->timeabsoluteexpiry <= $now || !self::user_may_connect((int) $grant->userid)) {
                self::revoke_grant((int) $grant->id, 'system');
                throw $invalid;
            }
            // Ciphertext of earlier rotations of this grant is no longer needed.
            $DB->set_field_select(
                self::T_TOKEN,
                'gracedata',
                null,
                'grantid = :grantid AND gracedata IS NOT NULL AND timeused < :cutoff',
                ['grantid' => $grant->id, 'cutoff' => $now - self::GRACE_SECONDS]
            );

            $transaction = $DB->start_delegated_transaction();
            $DB->set_field_select(
                self::T_TOKEN,
                'timerevoked',
                $now,
                "grantid = :grantid AND type = 'access' AND timerevoked IS NULL",
                ['grantid' => $grant->id]
            );
            $pair = self::issue_pair($grant, (int) $token->id);
            $token->timeused = $now;
            $token->gracedata = self::seal_grace($pair, $refreshtoken);
            $DB->update_record(self::T_TOKEN, $token);
            $transaction->allow_commit();
            return self::token_response($grant, $pair);
        });
    }

    /**
     * Resolves an access token to the grant it stands for.
     *
     * It does not check the resource: the MCP endpoint compares it with its own URL.
     *
     * @param string $accesstoken Bearer token received.
     * @param string|null $ip Remote address, stored only when the site logs addresses.
     * @return grant_context|null Null when the token is unknown, expired or revoked, or its grant or client is.
     */
    public static function authenticate(string $accesstoken, ?string $ip = null): ?grant_context {
        global $DB;
        if (!str_starts_with($accesstoken, self::PREFIX_ACCESS) || strlen($accesstoken) > 128) {
            return null;
        }
        $sql = "SELECT t.id AS tokenid, t.timeexpires, t.timerevoked, t.timelastused AS tokenlastused,
                       g.id AS grantid, g.userid, g.clientid, g.profile, g.scopes, g.resource, g.actor, g.actorid,
                       g.status, g.timeabsoluteexpiry, g.timelastused AS grantlastused,
                       c.type AS clienttype, c.status AS clientstatus
                  FROM {local_pluginiaconnect_token} t
                  JOIN {local_pluginiaconnect_grant} g ON g.id = t.grantid
                  JOIN {local_pluginiaconnect_client} c ON c.id = g.clientid
                 WHERE t.tokenhash = :hash AND t.type = :type";
        $row = $DB->get_record_sql($sql, ['hash' => self::hash($accesstoken), 'type' => 'access']);
        $now = time();
        if (
            !$row || !empty($row->timerevoked) || (int) $row->timeexpires <= $now || empty($row->status)
            || (int) $row->timeabsoluteexpiry <= $now || empty($row->clientstatus)
        ) {
            return null;
        }
        if ((int) $row->tokenlastused < $now - self::TOUCH_INTERVAL) {
            $update = (object) ['id' => $row->tokenid, 'timelastused' => $now];
            if ($ip !== null && config::bool('logip')) {
                $update->lastip = \core_text::substr($ip, 0, 45);
            }
            $DB->update_record(self::T_TOKEN, $update);
        }
        if ((int) $row->grantlastused < $now - self::TOUCH_INTERVAL) {
            $DB->set_field(self::T_GRANT, 'timelastused', $now, ['id' => $row->grantid]);
        }
        return new grant_context(
            (int) $row->grantid,
            (int) $row->tokenid,
            (int) $row->userid,
            (int) $row->clientid,
            (string) $row->clienttype,
            (string) $row->profile,
            scope::parse((string) $row->scopes),
            (string) $row->resource,
            $row->actor === null ? null : (string) $row->actor,
            $row->actorid === null ? null : (string) $row->actorid
        );
    }

    /**
     * Revokes a grant and, at once, every token issued under it. Idempotent.
     *
     * @param int $grantid Grant id.
     * @param string $by Who revoked it: user, admin, system, reuse or client.
     * @return void
     */
    public static function revoke_grant(int $grantid, string $by): void {
        global $DB;
        $grant = $DB->get_record(self::T_GRANT, ['id' => $grantid]);
        if (!$grant) {
            return;
        }
        $now = time();
        // Tokens are revoked even if the grant already was, so that nothing can be left alive.
        $DB->set_field_select(self::T_TOKEN, 'timerevoked', $now, 'grantid = :grantid AND timerevoked IS NULL', [
            'grantid' => $grantid,
        ]);
        $DB->set_field_select(self::T_TOKEN, 'gracedata', null, 'grantid = :grantid AND gracedata IS NOT NULL', [
            'grantid' => $grantid,
        ]);
        if (empty($grant->status)) {
            return;
        }
        $DB->update_record(self::T_GRANT, (object) [
            'id' => $grantid,
            'status' => 0,
            'revokedby' => \core_text::substr($by, 0, 10),
            'timerevoked' => $now,
        ]);
        self::notify('connection_revoked', $grantid, (int) $grant->userid, ['revokedby' => \core_text::substr($by, 0, 10)]);
    }

    /**
     * Revokes the grant a token belongs to, if the token was issued to the given client (RFC 7009).
     *
     * @param string $tokenvalue Access or refresh token.
     * @param client $client Authenticated client.
     * @return bool Whether a grant was revoked.
     */
    public static function revoke_by_token(string $tokenvalue, client $client): bool {
        global $DB;
        $token = $DB->get_record(self::T_TOKEN, ['tokenhash' => self::hash($tokenvalue)], 'id, grantid');
        if (!$token) {
            return false;
        }
        $grant = $DB->get_record(self::T_GRANT, ['id' => $token->grantid], 'id, clientid');
        if (!$grant || (int) $grant->clientid !== $client->id) {
            return false;
        }
        self::revoke_grant((int) $grant->id, 'client');
        return true;
    }

    /**
     * Revokes every active grant of a user.
     *
     * @param int $userid User id.
     * @param string $by Who revoked them.
     * @return int Number of grants revoked.
     */
    public static function revoke_user_grants(int $userid, string $by): int {
        return self::revoke_where(['userid' => $userid, 'status' => 1], $by);
    }

    /**
     * Revokes every active grant of a client.
     *
     * @param int $clientdbid Client record id.
     * @param string $by Who revoked them.
     * @return int Number of grants revoked.
     */
    public static function revoke_client_grants(int $clientdbid, string $by): int {
        return self::revoke_where(['clientid' => $clientdbid, 'status' => 1], $by);
    }

    /**
     * Revokes every active grant of the site.
     *
     * @param string $by Who revoked them.
     * @return int Number of grants revoked.
     */
    public static function revoke_all(string $by): int {
        return self::revoke_where(['status' => 1], $by);
    }

    /**
     * Deletes every grant, token and authorization code of a user. Used by the privacy provider.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function delete_user_data(int $userid): void {
        global $DB;
        $DB->delete_records_select(
            self::T_TOKEN,
            'grantid IN (SELECT g.id FROM {local_pluginiaconnect_grant} g WHERE g.userid = :userid)',
            ['userid' => $userid]
        );
        $DB->delete_records(self::T_CODE, ['userid' => $userid]);
        $DB->delete_records(self::T_GRANT, ['userid' => $userid]);
        eligibility::invalidate($userid);
    }

    /**
     * Housekeeping, run by the scheduled task.
     *
     * @return void
     */
    public static function cleanup_expired(): void {
        global $DB;
        $now = time();
        // Grants past their absolute lifetime need a new consent.
        $expired = $DB->get_fieldset_select(self::T_GRANT, 'id', 'status = 1 AND timeabsoluteexpiry <= :now', ['now' => $now]);
        foreach ($expired as $grantid) {
            self::revoke_grant((int) $grantid, 'system');
        }
        // Token pairs kept for the grace window.
        $DB->set_field_select(self::T_TOKEN, 'gracedata', null, 'gracedata IS NOT NULL AND timeused < :cutoff', [
            'cutoff' => $now - self::GRACE_SECONDS,
        ]);
        // Used codes are kept for an hour so that a replay is still recognised and revokes the grant.
        $DB->delete_records_select(self::T_CODE, 'timeexpires < :cutoff', ['cutoff' => $now - HOURSECS]);
        $DB->delete_records_select(self::T_TOKEN, 'timeexpires < :now', ['now' => $now]);
        $DB->delete_records_select(self::T_TOKEN, "type = 'access' AND timerevoked IS NOT NULL AND timerevoked < :cutoff", [
            'cutoff' => $now - DAYSECS,
        ]);
        // Revoked grants are kept for a while as a record, then removed with what hangs from them.
        $cutoff = $now - self::REVOKED_RETENTION_DAYS * DAYSECS;
        $old = $DB->get_fieldset_select(self::T_GRANT, 'id', 'status = 0 AND timerevoked < :cutoff', ['cutoff' => $cutoff]);
        foreach (array_chunk($old, 500) as $chunk) {
            $DB->delete_records_list(self::T_TOKEN, 'grantid', $chunk);
            $DB->delete_records_list(self::T_CODE, 'grantid', $chunk);
            $DB->delete_records_list(self::T_GRANT, 'id', $chunk);
        }
        client_repository::delete_stale_dcr($now - 30 * DAYSECS);
        client_repository::delete_stale_cimd($now - 30 * DAYSECS);
    }

    /**
     * Clears token pairs kept for the grace window once the window is over.
     *
     * Called on every token endpoint request, but it only touches the database
     * once every thirty seconds site-wide, so the ciphertext never outlives its
     * purpose by more than a minute while the endpoint is in use. The hourly
     * cleanup task covers idle sites.
     *
     * @return void
     */
    public static function sweep_grace_data(): void {
        global $DB;
        try {
            $cache = \cache::make(config::COMPONENT, 'ratelimit');
            $slot = 'gracesweep' . intdiv(time(), self::GRACE_SECONDS);
            if ($cache->get($slot)) {
                return;
            }
            $cache->set($slot, 1);
            $DB->set_field_select(self::T_TOKEN, 'gracedata', null, 'gracedata IS NOT NULL AND timeused < :cutoff', [
                'cutoff' => time() - self::GRACE_SECONDS,
            ]);
        } catch (\Throwable $e) {
            // Housekeeping never blocks a token request; the cleanup task will do it.
            return;
        }
    }

    /**
     * Whether the user behind a grant is active and may still use the connector.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_may_connect(int $userid): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid]);
        return $user && eligibility::access_denial($user) === null;
    }

    /**
     * Pseudonymous identifier of a user for one client. It is not the Moodle user id.
     *
     * @param int $userid User id.
     * @param int $clientdbid Client record id.
     * @return string
     */
    public static function subject(int $userid, int $clientdbid): string {
        return hash_hmac('sha256', $userid . ':' . $clientdbid, config::subject_secret());
    }

    /**
     * Triggers one of the plugin events, if its class is installed. Never receives token values.
     *
     * @param string $name Event class name without namespace.
     * @param int $grantid Grant id.
     * @param int $userid User the grant belongs to.
     * @param array $other Extra data.
     * @return void
     */
    public static function notify(string $name, int $grantid, int $userid, array $other): void {
        $class = '\\local_pluginiaconnect\\event\\' . $name;
        if (!class_exists($class)) {
            return;
        }
        try {
            $class::create([
                'context' => \context_system::instance(),
                'objectid' => $grantid,
                'relateduserid' => $userid,
                'other' => $other,
            ])->trigger();
        } catch (\Throwable $e) {
            debugging('local_pluginiaconnect: event ' . $name . ' could not be triggered.', DEBUG_DEVELOPER);
        }
    }

    /**
     * Revokes the grants that match some conditions.
     *
     * @param array $conditions Field conditions on the grant table.
     * @param string $by Who revoked them.
     * @return int Number of grants revoked.
     */
    private static function revoke_where(array $conditions, string $by): int {
        global $DB;
        $ids = $DB->get_fieldset_select(
            self::T_GRANT,
            'id',
            implode(' AND ', array_map(static fn($field) => $field . ' = :' . $field, array_keys($conditions))),
            $conditions
        );
        foreach ($ids as $id) {
            self::revoke_grant((int) $id, $by);
        }
        return count($ids);
    }

    /**
     * Whether a user account can still be used.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function user_is_active(int $userid): bool {
        global $DB;
        $user = $DB->get_record('user', ['id' => $userid], 'id, deleted, suspended, confirmed, auth');
        return $user && empty($user->deleted) && empty($user->suspended) && !empty($user->confirmed)
            && $user->auth !== 'nologin';
    }

    /**
     * Creates the token rows of a new pair.
     *
     * A refresh token is issued only when the grant has the offline_access scope.
     * No token outlives the absolute lifetime of the grant.
     *
     * @param \stdClass $grant Grant record.
     * @param int|null $parentid Refresh token this pair was rotated from.
     * @return array ['access' => string, 'refresh' => string|null, 'accessexpires' => int].
     */
    private static function issue_pair(\stdClass $grant, ?int $parentid): array {
        global $DB;
        $now = time();
        $limit = (int) $grant->timeabsoluteexpiry;
        $access = self::random_token(self::PREFIX_ACCESS);
        $accessexpires = min($now + config::int('accesstokenttl'), $limit);
        $DB->insert_record(self::T_TOKEN, (object) [
            'grantid' => $grant->id,
            'type' => 'access',
            'tokenhash' => self::hash($access),
            'parentid' => $parentid,
            'timeexpires' => $accessexpires,
            'timecreated' => $now,
            'timelastused' => 0,
        ]);
        $refresh = null;
        if (in_array(scope::OFFLINE, scope::parse((string) $grant->scopes), true)) {
            $refresh = self::random_token(self::PREFIX_REFRESH);
            $DB->insert_record(self::T_TOKEN, (object) [
                'grantid' => $grant->id,
                'type' => 'refresh',
                'tokenhash' => self::hash($refresh),
                'parentid' => $parentid,
                'timeexpires' => min($now + config::int('refreshidlettl'), $limit),
                'timecreated' => $now,
                'timelastused' => 0,
            ]);
        }
        return ['access' => $access, 'refresh' => $refresh, 'accessexpires' => $accessexpires];
    }

    /**
     * Builds the token endpoint response.
     *
     * @param \stdClass $grant Grant record.
     * @param array $pair Result of {@see self::issue_pair()}.
     * @return array
     */
    private static function token_response(\stdClass $grant, array $pair): array {
        $response = [
            'access_token' => $pair['access'],
            'token_type' => 'Bearer',
            'expires_in' => max(1, (int) $pair['accessexpires'] - time()),
        ];
        if (!empty($pair['refresh'])) {
            $response['refresh_token'] = $pair['refresh'];
        }
        $response['scope'] = (string) $grant->scopes;
        $response['pc_profile'] = (string) $grant->profile;
        $response['pc_sub'] = self::subject((int) $grant->userid, (int) $grant->clientid);
        if (isset($grant->actorid) && $grant->actorid !== '') {
            // Only connections made through the gateway carry it: the gateway checks it against its own record.
            $response['pc_actor_id'] = (string) $grant->actorid;
        }
        return $response;
    }

    /**
     * Key that protects the grace data of a refresh token: derived from the token itself,
     * so the database alone can never reveal the stored pair.
     *
     * @param string $refreshtoken Plain refresh token.
     * @return string 32 bytes.
     */
    private static function grace_key(string $refreshtoken): string {
        return hash_hkdf('sha256', $refreshtoken, SODIUM_CRYPTO_SECRETBOX_KEYBYTES, self::GRACE_INFO);
    }

    /**
     * Encrypts a freshly issued pair for the grace window.
     *
     * @param array $pair Result of {@see self::issue_pair()}.
     * @param string $refreshtoken Plain refresh token that was just used.
     * @return string|null Base64 text, or null when encryption is unavailable (then there is no grace window).
     */
    private static function seal_grace(array $pair, string $refreshtoken): ?string {
        if (!function_exists('sodium_crypto_secretbox')) {
            return null;
        }
        $nonce = random_bytes(SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $box = sodium_crypto_secretbox(json_encode($pair), $nonce, self::grace_key($refreshtoken));
        return base64_encode($nonce . $box);
    }

    /**
     * Decrypts the pair stored for the grace window.
     *
     * @param \stdClass $token Refresh token record.
     * @param string $refreshtoken Plain refresh token presented.
     * @return array|null The pair, or null when there is none or it cannot be read.
     */
    private static function read_grace(\stdClass $token, string $refreshtoken): ?array {
        if (empty($token->gracedata) || !function_exists('sodium_crypto_secretbox_open')) {
            return null;
        }
        $raw = base64_decode((string) $token->gracedata, true);
        if ($raw === false || strlen($raw) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES) {
            return null;
        }
        $nonce = substr($raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES);
        $plain = sodium_crypto_secretbox_open(
            substr($raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES),
            $nonce,
            self::grace_key($refreshtoken)
        );
        $pair = $plain === false ? null : json_decode($plain, true);
        return is_array($pair) && !empty($pair['access']) ? $pair : null;
    }
}
