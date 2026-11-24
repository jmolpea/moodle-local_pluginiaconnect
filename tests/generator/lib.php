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

/**
 * Data generator of the connector, for Behat and unit tests.
 *
 * @package    local_pluginiaconnect
 * @category   test
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_pluginiaconnect_generator extends component_generator_base {
    /** @var string Path of the page that stands in for the assistant's return address in Behat. */
    public const CALLBACK_PATH = '/local/pluginiaconnect/tests/behat/fixtures/callback.php';

    /**
     * Return addresses of a test client: the callback fixture on this site, with and without the port.
     *
     * @return string[]
     */
    public static function callback_uris(): array {
        global $CFG;
        $parts = parse_url($CFG->wwwroot);
        $path = rtrim($parts['path'] ?? '', '/') . self::CALLBACK_PATH;
        $uris = [$parts['scheme'] . '://' . $parts['host'] . $path];
        if (isset($parts['port'])) {
            $uris[] = $parts['scheme'] . '://' . $parts['host'] . ':' . $parts['port'] . $path;
        }
        return $uris;
    }

    /**
     * Creates a public OAuth client whose return address is the callback fixture of this site.
     *
     * @param array $data name (required).
     * @return stdClass Client record.
     */
    public function create_client(array $data): stdClass {
        global $DB;
        $now = time();
        $clientid = 'pc_client_' . random_string(24);
        $record = (object) [
            'clientidhash' => hash('sha256', $clientid),
            'clientid' => $clientid,
            'type' => 'dcr',
            'name' => $data['name'],
            'redirecturis' => json_encode(self::callback_uris(), JSON_UNESCAPED_SLASHES),
            'authmethod' => 'none',
            'secrethash' => null,
            'metadata' => null,
            'status' => 1,
            'timecreated' => $now,
            'timemodified' => $now,
            'timelastused' => 0,
        ];
        $record->id = $DB->insert_record('local_pluginiaconnect_client', $record);
        return $record;
    }

    /**
     * Creates an active connection of a user with a client.
     *
     * @param array $data userid and clientid (required); profile, scopes, actor (optional).
     * @return stdClass Grant record.
     */
    public function create_connection(array $data): stdClass {
        global $CFG, $DB;
        $now = time();
        $record = (object) [
            'userid' => $data['userid'],
            'clientid' => $data['clientid'],
            'profile' => $data['profile'] ?? 'student',
            'scopes' => $data['scopes'] ?? 'profile courses.read grades.read offline_access',
            'resource' => rtrim($CFG->wwwroot, '/') . '/local/pluginiaconnect/mcp.php',
            'actor' => $data['actor'] ?? null,
            'actorid' => null,
            'status' => 1,
            'timecreated' => $now,
            'timelastused' => 0,
            'timeabsoluteexpiry' => $now + 180 * DAYSECS,
        ];
        $record->id = $DB->insert_record('local_pluginiaconnect_grant', $record);
        $DB->insert_record('local_pluginiaconnect_token', (object) [
            'grantid' => $record->id,
            'type' => 'access',
            'tokenhash' => hash('sha256', random_string(40)),
            'timeexpires' => $now + HOURSECS,
            'timecreated' => $now,
            'timelastused' => 0,
        ]);
        return $record;
    }

    /**
     * Creates a change that waits for the user's approval: hiding or showing one activity.
     *
     * @param array $data userid, clientid, activityid (course module id) and optionally visible (0 or 1).
     * @return stdClass Action record.
     */
    public function create_pending_change(array $data): stdClass {
        global $DB;
        $grantid = $DB->get_field('local_pluginiaconnect_grant', 'id', [
            'userid' => $data['userid'],
            'clientid' => $data['clientid'],
        ], MUST_EXIST);
        $args = ['activity_id' => (int) $data['activityid'], 'visible' => !empty($data['visible'])];
        $now = time();
        $record = (object) [
            'userid' => $data['userid'],
            'grantid' => $grantid,
            'tool' => 'set_activity_visibility',
            'mode' => 'approval',
            'tokenhash' => null,
            'args' => json_encode($args),
            'argshash' => hash('sha256', json_encode($args)),
            'summary' => 'Change the visibility of an activity.',
            'status' => 'pending',
            'result' => null,
            'timeexpires' => $now + 15 * MINSECS,
            'timecreated' => $now,
            'timeresolved' => null,
        ];
        $record->id = $DB->insert_record('local_pluginiaconnect_action', $record);
        return $record;
    }

    /**
     * Creates an audit row of a tool call.
     *
     * @param array $data userid, clientid and optionally tool, profile, outcome, reason.
     * @return stdClass Audit record.
     */
    public function create_call(array $data): stdClass {
        global $DB;
        $grantid = $DB->get_field('local_pluginiaconnect_grant', 'id', [
            'userid' => $data['userid'],
            'clientid' => $data['clientid'],
        ], IGNORE_MULTIPLE);
        $record = (object) [
            'requestid' => \core\uuid::generate(),
            'userid' => $data['userid'],
            'grantid' => $grantid ?: 0,
            'clientid' => $data['clientid'],
            'tool' => $data['tool'] ?? 'list_my_courses',
            'profile' => $data['profile'] ?? 'student',
            'iswrite' => 0,
            'argshash' => hash('sha256', '{}'),
            'outcome' => $data['outcome'] ?? 'ok',
            'reason' => $data['reason'] ?? null,
            'durationms' => 12,
            'timecreated' => time(),
        ];
        $record->id = $DB->insert_record('local_pluginiaconnect_audit', $record);
        return $record;
    }
}
