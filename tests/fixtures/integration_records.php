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

namespace local_pluginiaconnect\fixtures;

/**
 * Creates rows of the plugin tables directly, for tests of the site integration
 * (observers, tasks, privacy). It does not go through the OAuth services on
 * purpose, so that those tests do not depend on how a connection is created.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class integration_records {
    /**
     * Creates a client.
     *
     * @return int Client id.
     */
    public static function client(): int {
        global $DB;
        $clientid = 'test-client-' . random_string(12);
        return (int) $DB->insert_record('local_pluginiaconnect_client', (object) [
            'clientidhash' => hash('sha256', $clientid),
            'clientid' => $clientid,
            'type' => 'dcr',
            'name' => 'Test assistant',
            'redirecturis' => json_encode(['https://claude.ai/api/mcp/auth_callback']),
            'authmethod' => 'none',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'timelastused' => 0,
        ]);
    }

    /**
     * Creates an active grant with one access token and one refresh token.
     *
     * @param int $userid Owner.
     * @param int|null $clientid Client; created when null.
     * @return int Grant id.
     */
    public static function grant(int $userid, ?int $clientid = null): int {
        global $DB;
        $grantid = (int) $DB->insert_record('local_pluginiaconnect_grant', (object) [
            'userid' => $userid,
            'clientid' => $clientid ?? self::client(),
            'profile' => 'student',
            'scopes' => 'profile courses.read offline_access',
            'resource' => 'https://www.example.com/moodle/local/pluginiaconnect/mcp.php',
            'status' => 1,
            'timecreated' => time(),
            'timelastused' => time(),
            'timeabsoluteexpiry' => time() + 180 * DAYSECS,
        ]);
        foreach (['access' => HOURSECS, 'refresh' => 30 * DAYSECS] as $type => $lifetime) {
            $DB->insert_record('local_pluginiaconnect_token', (object) [
                'grantid' => $grantid,
                'type' => $type,
                'tokenhash' => hash('sha256', random_string(40)),
                'timeexpires' => time() + $lifetime,
                'timecreated' => time(),
                'timelastused' => 0,
                'lastip' => '192.0.2.10',
            ]);
        }
        return $grantid;
    }

    /**
     * Creates an authorization code.
     *
     * @param int $userid Owner.
     * @return int Code record id.
     */
    public static function authcode(int $userid): int {
        global $DB;
        return (int) $DB->insert_record('local_pluginiaconnect_authcode', (object) [
            'codehash' => hash('sha256', random_string(40)),
            'clientid' => self::client(),
            'userid' => $userid,
            'profile' => 'student',
            'scopes' => 'profile',
            'redirecturi' => 'https://claude.ai/api/mcp/auth_callback',
            'codechallenge' => str_repeat('a', 43),
            'resource' => 'https://www.example.com/moodle/local/pluginiaconnect/mcp.php',
            'timeexpires' => time() + 60,
            'timecreated' => time(),
        ]);
    }

    /**
     * Creates an audit record.
     *
     * @param int $userid User the call was made for.
     * @param int $grantid Grant.
     * @param int|null $timecreated Time of the call; now when null.
     * @return int Audit record id.
     */
    public static function audit(int $userid, int $grantid, ?int $timecreated = null): int {
        global $DB;
        return (int) $DB->insert_record('local_pluginiaconnect_audit', (object) [
            'requestid' => \core\uuid::generate(),
            'userid' => $userid,
            'grantid' => $grantid,
            'clientid' => 1,
            'tool' => 'list_my_courses',
            'profile' => 'student',
            'iswrite' => 0,
            'argshash' => hash('sha256', '{}'),
            'outcome' => 'ok',
            'durationms' => 12,
            'ip' => '192.0.2.10',
            'timecreated' => $timecreated ?? time(),
        ]);
    }

    /**
     * Creates a pending or resolved action.
     *
     * @param int $userid Owner.
     * @param int $grantid Grant.
     * @param string $status Action status.
     * @param int|null $timeexpires Expiry; five minutes from now when null.
     * @param int|null $timeresolved Resolution time.
     * @return int Action id.
     */
    public static function action(
        int $userid,
        int $grantid,
        string $status = 'pending',
        ?int $timeexpires = null,
        ?int $timeresolved = null
    ): int {
        global $DB;
        return (int) $DB->insert_record('local_pluginiaconnect_action', (object) [
            'userid' => $userid,
            'grantid' => $grantid,
            'tool' => 'set_activity_visibility',
            'mode' => 'confirm',
            'tokenhash' => hash('sha256', random_string(40)),
            'args' => '{"activity_id":1,"visible":false}',
            'argshash' => hash('sha256', '{"activity_id":1,"visible":false}'),
            'summary' => 'Hide the activity.',
            'status' => $status,
            'result' => $status === 'pending' ? null : '{"status":"ok"}',
            'timeexpires' => $timeexpires ?? time() + 300,
            'timecreated' => time(),
            'timeresolved' => $timeresolved,
        ]);
    }

    /**
     * Creates an idempotency key.
     *
     * @param int $grantid Grant.
     * @param int|null $timeexpires Expiry; one day from now when null.
     * @return int Record id.
     */
    public static function idem(int $grantid, ?int $timeexpires = null): int {
        global $DB;
        return (int) $DB->insert_record('local_pluginiaconnect_idem', (object) [
            'grantid' => $grantid,
            'tool' => 'set_activity_visibility',
            'keyhash' => hash('sha256', random_string(20)),
            'argshash' => hash('sha256', '{}'),
            'result' => '{"status":"ok"}',
            'timeexpires' => $timeexpires ?? time() + DAYSECS,
        ]);
    }
}
