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
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\urls;

/**
 * Authorization codes: single use, 60 seconds, bound to client, redirect URI, resource and PKCE challenge.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class code_service {
    /** @var string Prefix of authorization codes. */
    public const PREFIX = 'pc_ac_';
    /** @var int Lifetime in seconds. */
    public const LIFETIME = 60;
    /** @var string Table name. */
    private const TABLE = 'local_pluginiaconnect_authcode';

    /**
     * Creates a code after the user approved the consent page.
     *
     * @param authorization_request $request Validated authorization request.
     * @param int $userid User who consented.
     * @param string $profile Profile chosen.
     * @param string[] $scopes Scopes granted.
     * @return string The code. Only its hash is stored.
     */
    public static function create(authorization_request $request, int $userid, string $profile, array $scopes): string {
        global $DB;
        $code = token_service::random_token(self::PREFIX);
        $now = time();
        $DB->insert_record(self::TABLE, (object) [
            'codehash' => token_service::hash($code),
            'clientid' => $request->client->id,
            'userid' => $userid,
            'profile' => $profile,
            'scopes' => implode(' ', $scopes),
            'redirecturi' => $request->redirecturi,
            'codechallenge' => $request->codechallenge,
            'resource' => urls::resource(),
            'actor' => $request->actor,
            'actorid' => $request->actorid,
            'timeexpires' => $now + self::LIFETIME,
            'timeused' => null,
            'grantid' => null,
            'timecreated' => $now,
        ]);
        return $code;
    }

    /**
     * Redeems a code and issues the first tokens of the new grant.
     *
     * The whole exchange runs under a lock on the code, so two simultaneous
     * requests cannot both succeed. Presenting a code a second time revokes
     * the grant that was created with it.
     *
     * @param string $code Code received.
     * @param client $client Authenticated client.
     * @param string $redirecturi Redirect URI of the token request.
     * @param string $resource Resource of the token request.
     * @param string $verifier PKCE code verifier.
     * @param string|null $actorid Assistant identifier the gateway repeats in the token request.
     * @return array Token response.
     * @throws oauth_exception invalid_grant on any mismatch.
     */
    public static function exchange(
        string $code,
        client $client,
        string $redirecturi,
        string $resource,
        string $verifier,
        ?string $actorid = null
    ): array {
        if (!str_starts_with($code, self::PREFIX) || strlen($code) > 128) {
            throw self::invalid();
        }
        $hash = token_service::hash($code);
        return token_service::with_lock('ac' . $hash, static function () use (
            $hash,
            $client,
            $redirecturi,
            $resource,
            $verifier,
            $actorid
        ) {
            global $DB;
            $record = $DB->get_record(self::TABLE, ['codehash' => $hash]);
            if (!$record) {
                throw self::invalid();
            }
            if (!empty($record->timeused)) {
                if (!empty($record->grantid)) {
                    token_service::revoke_grant((int) $record->grantid, 'reuse');
                    token_service::notify('token_reuse_detected', (int) $record->grantid, (int) $record->userid, [
                        'kind' => 'code',
                    ]);
                }
                throw self::invalid();
            }
            if ((int) $record->timeexpires < time()) {
                throw self::invalid();
            }
            if (
                (int) $record->clientid !== $client->id
                || !hash_equals((string) $record->redirecturi, $redirecturi)
                || urls::canonical($resource) === ''
                || !hash_equals(urls::canonical((string) $record->resource), urls::canonical($resource))
                || !pkce::verify($verifier, (string) $record->codechallenge)
            ) {
                throw self::invalid();
            }
            $record->timeused = time();
            $DB->set_field(self::TABLE, 'timeused', $record->timeused, ['id' => $record->id]);
            if ($client->type === client::TYPE_GATEWAY && !self::actor_confirmed($record, $actorid)) {
                // The code is spent: a connection whose assistant cannot be established is never created.
                throw self::invalid();
            }
            return token_service::issue_for_code($record, $client);
        });
    }

    /**
     * Whether the gateway confirmed, over its authenticated channel, the assistant stored with a code.
     *
     * The authorization request travels through the browser, where the user or anything else can
     * drop or change the assistant identifier. The limits of an assistant (approval of every change,
     * read only, blocked) depend on it, so the gateway states it again when it redeems the code and
     * the two values must be the same. A blocked assistant is refused here as well.
     *
     * @param \stdClass $record Authorization code record.
     * @param string|null $declared Value of pc_actor_id in the token request.
     * @return bool
     */
    private static function actor_confirmed(\stdClass $record, ?string $declared): bool {
        $declared = $declared === null || $declared === '' ? null : $declared;
        $stored = $record->actorid === null || $record->actorid === '' ? null : (string) $record->actorid;
        if ($declared !== $stored) {
            return false;
        }
        if ($stored === site_policy::ACTOR_COWORK) {
            return in_array(config::string('actorpolicy_copilotcowork'), ['approval', 'readonly'], true);
        }
        return true;
    }

    /**
     * The single error every failed exchange produces, so that responses do not reveal which check failed.
     *
     * @return oauth_exception
     */
    private static function invalid(): oauth_exception {
        return new oauth_exception('invalid_grant', 'The authorization code is invalid, expired or does not match this request.');
    }
}
