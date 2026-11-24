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

namespace local_pluginiaconnect\local;

use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\scope;

/**
 * Active connections (grants) as the user and administrator pages show and revoke them.
 *
 * Pages never touch the tables: listing and revoking go through this class, and
 * revoking always ends in {@see token_service}, which invalidates the tokens at once.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class connections {
    /** @var string Grant table. */
    private const T_GRANT = 'local_pluginiaconnect_grant';
    /** @var string Client table. */
    private const T_CLIENT = 'local_pluginiaconnect_client';

    /**
     * Active connections of one user, newest first.
     *
     * @param int $userid User id.
     * @return array[] Rows as {@see self::describe()} returns them.
     */
    public static function for_user(int $userid): array {
        return self::search($userid, null, 0, 0);
    }

    /**
     * Active connections of the site, newest first.
     *
     * @param int|null $userid Only this user.
     * @param int|null $clientid Only this client (record id).
     * @param int $limitfrom First row.
     * @param int $limitnum Number of rows; 0 for all.
     * @return array[] Rows as {@see self::describe()} returns them.
     */
    public static function search(?int $userid, ?int $clientid, int $limitfrom = 0, int $limitnum = 0): array {
        global $DB;
        [$where, $params] = self::where($userid, $clientid);
        $names = \core_user\fields::for_name()->get_sql('u', false, 'user', '', false)->selects;
        $sql = "SELECT g.id, g.userid, g.clientid, g.profile, g.scopes, g.actor, g.actorid, g.timecreated,
                       g.timelastused, g.timeabsoluteexpiry, c.name AS clientname, c.type AS clienttype,
                       c.clientid AS clientidentifier, $names
                  FROM {" . self::T_GRANT . "} g
                  JOIN {" . self::T_CLIENT . "} c ON c.id = g.clientid
                  JOIN {user} u ON u.id = g.userid
                 WHERE $where
              ORDER BY g.timecreated DESC, g.id DESC";
        $rows = [];
        foreach ($DB->get_records_sql($sql, $params, $limitfrom, $limitnum) as $record) {
            $rows[] = self::describe($record);
        }
        return $rows;
    }

    /**
     * Number of active connections matching the filters.
     *
     * @param int|null $userid Only this user.
     * @param int|null $clientid Only this client (record id).
     * @return int
     */
    public static function count(?int $userid, ?int $clientid): int {
        global $DB;
        [$where, $params] = self::where($userid, $clientid);
        return $DB->count_records_sql("SELECT COUNT(g.id) FROM {" . self::T_GRANT . "} g WHERE $where", $params);
    }

    /**
     * Clients that have at least one active connection, for the filter of the administrator page.
     *
     * @return string[] Client record id => name.
     */
    public static function clients_in_use(): array {
        global $DB;
        $sql = "SELECT c.id, c.name
                  FROM {" . self::T_CLIENT . "} c
                 WHERE EXISTS (SELECT 1 FROM {" . self::T_GRANT . "} g WHERE g.clientid = c.id AND g.status = 1)
              ORDER BY c.name, c.id";
        $clients = [];
        foreach ($DB->get_records_sql($sql) as $record) {
            $clients[(int) $record->id] = $record->name . ' (#' . $record->id . ')';
        }
        return $clients;
    }

    /**
     * Revokes a connection on behalf of its owner. Does nothing if it belongs to someone else.
     *
     * @param int $grantid Grant id.
     * @param int $userid User who asks.
     * @return bool Whether the connection was the user's and is now revoked.
     */
    public static function revoke_own(int $grantid, int $userid): bool {
        global $DB;
        if (!$DB->record_exists(self::T_GRANT, ['id' => $grantid, 'userid' => $userid])) {
            return false;
        }
        token_service::revoke_grant($grantid, 'user');
        return true;
    }

    /**
     * Revokes one connection on behalf of an administrator.
     *
     * @param int $grantid Grant id.
     * @return bool Whether the connection existed.
     */
    public static function revoke_one(int $grantid): bool {
        global $DB;
        if (!$DB->record_exists(self::T_GRANT, ['id' => $grantid])) {
            return false;
        }
        token_service::revoke_grant($grantid, 'admin');
        return true;
    }

    /**
     * Revokes every connection of a user on behalf of an administrator.
     *
     * @param int $userid User id.
     * @return int Number of connections revoked.
     */
    public static function revoke_user(int $userid): int {
        return token_service::revoke_user_grants($userid, 'admin');
    }

    /**
     * Revokes every connection of a client on behalf of an administrator.
     *
     * @param int $clientid Client record id.
     * @return int Number of connections revoked.
     */
    public static function revoke_client(int $clientid): int {
        return token_service::revoke_client_grants($clientid, 'admin');
    }

    /**
     * Turns a database row into what the pages show. Never includes tokens.
     *
     * @param \stdClass $record Row of the search query.
     * @return array
     */
    public static function describe(\stdClass $record): array {
        $scopes = scope::parse((string) $record->scopes);
        $write = (bool) array_filter($scopes, [scope::class, 'is_write']);
        $user = (object) ['id' => $record->userid];
        foreach (\core_user\fields::for_name()->get_required_fields() as $field) {
            $user->$field = $record->{'user' . $field} ?? '';
        }
        $profile = profile::is_valid((string) $record->profile) ? (string) $record->profile : 'unknown';
        return [
            'id' => (int) $record->id,
            'userid' => (int) $record->userid,
            'userfullname' => fullname($user),
            'clientid' => (int) $record->clientid,
            // Plain text: templates escape it.
            'clientname' => (string) $record->clientname,
            'clienthost' => (string) parse_url((string) $record->clientidentifier, PHP_URL_HOST),
            'isgateway' => $record->clienttype === 'gateway',
            'actor' => (string) ($record->actor ?? ''),
            'hasactor' => $record->actor !== null && $record->actor !== '',
            'profile' => $profile,
            'profilename' => get_string('profile_' . $profile, config::COMPONENT),
            'write' => $write,
            'levelname' => get_string($write ? 'level_write' : 'level_read', config::COMPONENT),
            'timecreated' => (int) $record->timecreated,
            'created' => userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'lastused' => empty($record->timelastused)
                ? get_string('never')
                : userdate($record->timelastused, get_string('strftimedatetimeshort', 'langconfig')),
            'expires' => userdate($record->timeabsoluteexpiry, get_string('strftimedate', 'langconfig')),
        ];
    }

    /**
     * WHERE clause of the searches.
     *
     * @param int|null $userid Only this user.
     * @param int|null $clientid Only this client.
     * @return array [sql, params]
     */
    private static function where(?int $userid, ?int $clientid): array {
        $where = 'g.status = 1';
        $params = [];
        if ($userid) {
            $where .= ' AND g.userid = :userid';
            $params['userid'] = $userid;
        }
        if ($clientid) {
            $where .= ' AND g.clientid = :clientid';
            $params['clientid'] = $clientid;
        }
        return [$where, $params];
    }
}
