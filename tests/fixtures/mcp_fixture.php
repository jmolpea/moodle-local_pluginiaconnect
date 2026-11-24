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

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\oauth\grant_context;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/output_schema_check.php');

/**
 * Test helper for the MCP server, pipeline and tool tests.
 *
 * It writes clients, grants and tokens straight into the plugin tables so that
 * these tests do not depend on the OAuth endpoints.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_pluginiaconnect_mcp_fixture {
    /** @var int Sequence for unique client identifiers. */
    private static int $sequence = 0;

    /**
     * Switches the connector on in direct mode with every profile allowed.
     *
     * @param array $settings Extra settings, name => value.
     * @return void
     */
    public static function enable(array $settings = []): void {
        $settings += [
            'enabled' => 1, 'directmode' => 1, 'allowstudentprofile' => 1, 'allowteacherprofile' => 1,
            'allowmanagerprofile' => 1, 'allowadminprofile' => 1,
        ];
        foreach ($settings as $name => $value) {
            set_config($name, $value, 'local_pluginiaconnect');
        }
        \cache::make('local_pluginiaconnect', 'eligibility')->purge();
    }

    /**
     * Creates a client record.
     *
     * @param string $type Client type.
     * @return int Client record id.
     */
    public static function client(string $type = 'dcr'): int {
        global $DB;
        $clientid = 'test-client-' . (++self::$sequence) . '-' . random_string(6);
        return (int) $DB->insert_record('local_pluginiaconnect_client', (object) [
            'clientidhash' => hash('sha256', $clientid),
            'clientid' => $clientid,
            'type' => $type,
            'name' => 'Test client',
            'redirecturis' => json_encode(['https://claude.ai/api/mcp/auth_callback']),
            'authmethod' => 'none',
            'status' => 1,
            'timecreated' => time(),
            'timemodified' => time(),
            'timelastused' => 0,
        ]);
    }

    /**
     * Creates a grant with one access token.
     *
     * @param int $userid User the grant is for.
     * @param string $profile Profile of the grant.
     * @param string[] $scopes Scopes of the grant.
     * @param array $options Optional: clienttype, resource, actorid, actor, expires (token expiry).
     * @return array [token value, grant_context]
     */
    public static function grant(int $userid, string $profile, array $scopes, array $options = []): array {
        global $DB;
        $clienttype = $options['clienttype'] ?? 'dcr';
        $clientid = self::client($clienttype);
        $resource = $options['resource'] ?? \local_pluginiaconnect\local\urls::resource();
        $grantid = (int) $DB->insert_record('local_pluginiaconnect_grant', (object) [
            'userid' => $userid,
            'clientid' => $clientid,
            'profile' => $profile,
            'scopes' => implode(' ', $scopes),
            'resource' => $resource,
            'actor' => $options['actor'] ?? null,
            'actorid' => $options['actorid'] ?? null,
            'status' => 1,
            'timecreated' => time(),
            'timelastused' => 0,
            'timeabsoluteexpiry' => time() + 180 * DAYSECS,
        ]);
        $token = 'pc_at_' . random_string(40);
        $tokenid = (int) $DB->insert_record('local_pluginiaconnect_token', (object) [
            'grantid' => $grantid,
            'type' => 'access',
            'tokenhash' => hash('sha256', $token),
            'timeexpires' => $options['expires'] ?? time() + HOURSECS,
            'timecreated' => time(),
            'timelastused' => 0,
        ]);
        $context = new grant_context(
            $grantid,
            $tokenid,
            $userid,
            $clientid,
            $clienttype,
            $profile,
            $scopes,
            $resource,
            $options['actor'] ?? null,
            $options['actorid'] ?? null
        );
        return [$token, $context];
    }

    /**
     * Authenticator with the contract of token_service::authenticate(), reading the tables directly.
     *
     * @return callable
     */
    public static function authenticator(): callable {
        return static function (string $token, ?string $ip): ?grant_context {
            global $DB;
            $row = $DB->get_record('local_pluginiaconnect_token', ['tokenhash' => hash('sha256', $token), 'type' => 'access']);
            if (!$row || $row->timerevoked || $row->timeexpires < time()) {
                return null;
            }
            $grant = $DB->get_record('local_pluginiaconnect_grant', ['id' => $row->grantid]);
            if (!$grant || !$grant->status) {
                return null;
            }
            $client = $DB->get_record('local_pluginiaconnect_client', ['id' => $grant->clientid], '*', MUST_EXIST);
            return new grant_context(
                (int) $grant->id,
                (int) $row->id,
                (int) $grant->userid,
                (int) $grant->clientid,
                $client->type,
                $grant->profile,
                explode(' ', $grant->scopes),
                $grant->resource,
                $grant->actor,
                $grant->actorid
            );
        };
    }

    /**
     * Builds a POST request to the MCP endpoint.
     *
     * @param array|string $message JSON-RPC message, or a raw body.
     * @param string|null $token Bearer token.
     * @param array $headers Extra headers.
     * @param array $query Query string parameters.
     * @return request
     */
    public static function request($message, ?string $token, array $headers = [], array $query = []): request {
        $headers += ['Content-Type' => 'application/json'];
        if ($token !== null) {
            $headers['Authorization'] = 'Bearer ' . $token;
        }
        $body = is_string($message) ? $message : json_encode($message);
        return new request('POST', $headers, $body, $query, true, '192.0.2.10');
    }

    /**
     * Headers and metadata of a request of protocol revision 2026-07-28.
     *
     * @param string $method JSON-RPC method.
     * @param array $params Parameters.
     * @param string|null $name Tool name for the Mcp-Name header.
     * @return array [message, headers]
     */
    public static function modern(string $method, array $params = [], ?string $name = null): array {
        $params['_meta'] = [
            'io.modelcontextprotocol/protocolVersion' => '2026-07-28',
            'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
        ];
        $headers = ['MCP-Protocol-Version' => '2026-07-28', 'Mcp-Method' => $method];
        if ($name !== null) {
            $headers['Mcp-Name'] = $name;
        }
        return [['jsonrpc' => '2.0', 'id' => 7, 'method' => $method, 'params' => $params], $headers];
    }
}
