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

namespace local_pluginiaconnect;

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\mcp\protocol;
use local_pluginiaconnect\local\mcp\server;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\server::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\protocol::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\jsonrpc::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\user_session::class)]
/**
 * MCP protocol and endpoint authentication.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\mcp\server
 * @covers     \local_pluginiaconnect\local\mcp\protocol
 * @covers     \local_pluginiaconnect\local\mcp\jsonrpc
 * @covers     \local_pluginiaconnect\local\user_session
 */
final class mcp_protocol_test extends \advanced_testcase {
    /** @var string Access token of the test user. */
    private string $token;
    /** @var \stdClass Test user. */
    private \stdClass $user;

    /**
     * A student with a read-only connection.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        $this->user = $this->getDataGenerator()->create_user();
        [$this->token] = \local_pluginiaconnect_mcp_fixture::grant(
            $this->user->id,
            'student',
            ['profile', 'courses.read', 'grades.read']
        );
    }

    /**
     * Sends a message to the server.
     *
     * @param array|string $message Message or raw body.
     * @param array $headers Extra headers.
     * @param string|null $token Token; the test user's token when not given.
     * @return \local_pluginiaconnect\local\http\response
     */
    private function send($message, array $headers = [], ?string $token = '') {
        $token = $token === '' ? $this->token : $token;
        $request = \local_pluginiaconnect_mcp_fixture::request($message, $token, $headers);
        return server::handle($request, \local_pluginiaconnect_mcp_fixture::authenticator(), 'req-1');
    }

    /**
     * Legacy headers.
     *
     * @param string $version Protocol version.
     * @return array
     */
    private function legacy(string $version = '2025-11-25'): array {
        return ['MCP-Protocol-Version' => $version];
    }

    public function test_get_and_delete_are_not_allowed(): void {
        foreach (['GET', 'DELETE'] as $method) {
            $response = server::handle(new request($method, ['Authorization' => 'Bearer ' . $this->token]));
            $this->assertSame(405, $response->status);
            $this->assertSame('POST', $response->headers['Allow']);
        }
    }

    public function test_disabled_site_answers_503(): void {
        set_config('enabled', 0, 'local_pluginiaconnect');
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping'], $this->legacy());
        $this->assertSame(503, $response->status);
    }

    public function test_no_token_gets_the_oauth_challenge(): void {
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [], null);
        $this->assertSame(401, $response->status);
        $challenge = $response->headers['WWW-Authenticate'];
        $this->assertStringContainsString('resource_metadata="' . local\urls::resource_metadata() . '"', $challenge);
        // Every profile is allowed and every read action is on: the scopes of all of them (advertised_scopes_test).
        $this->assertStringContainsString(
            'scope="profile courses.read grades.read participants.read manage.read users.read site.read offline_access"',
            $challenge
        );
        $this->assertStringNotContainsString('invalid_token', $challenge);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
    }

    public function test_unknown_expired_and_revoked_tokens_are_rejected(): void {
        global $DB;
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [], 'pc_at_unknown');
        $this->assertSame(401, $response->status);
        $this->assertStringContainsString('error="invalid_token"', $response->headers['WWW-Authenticate']);

        [$expired] = \local_pluginiaconnect_mcp_fixture::grant($this->user->id, 'student', ['profile'], ['expires' => time() - 1]);
        $this->assertSame(401, $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [], $expired)->status);

        $DB->set_field('local_pluginiaconnect_grant', 'status', 0, ['userid' => $this->user->id]);
        $this->assertSame(401, $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'])->status);
    }

    public function test_token_issued_for_another_resource_is_rejected(): void {
        [$token] = \local_pluginiaconnect_mcp_fixture::grant(
            $this->user->id,
            'student',
            ['profile'],
            ['resource' => 'https://other.example/local/pluginiaconnect/mcp.php']
        );
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [], $token);
        $this->assertSame(401, $response->status);
    }

    public function test_moodle_web_service_tokens_are_never_accepted(): void {
        global $DB;
        // A real core web service token, sent as wstoken and as bearer.
        $service = $DB->get_record('external_services', ['shortname' => MOODLE_OFFICIAL_MOBILE_SERVICE], '*', MUST_EXIST);
        $wstoken = \core_external\util::generate_token(
            EXTERNAL_TOKEN_PERMANENT,
            $service,
            $this->user->id,
            \context_system::instance()
        );
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'];
        $asquery = \local_pluginiaconnect_mcp_fixture::request($message, null, [], ['wstoken' => $wstoken, 'token' => $wstoken]);
        $this->assertSame(401, server::handle($asquery, \local_pluginiaconnect_mcp_fixture::authenticator())->status);
        $this->assertSame(401, $this->send($message, [], $wstoken)->status);
        // And through the real token service, when it is installed.
        if (class_exists(\local_pluginiaconnect\local\oauth\token_service::class)) {
            $asbearer = \local_pluginiaconnect_mcp_fixture::request($message, $wstoken);
            $this->assertSame(401, server::handle($asbearer)->status);
        }
    }

    public function test_suspended_user_is_rejected_and_the_grant_revoked(): void {
        global $DB;
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize']);
        $this->assertSame(401, $response->status);
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['userid' => $this->user->id]));
    }

    public function test_deleted_unconfirmed_and_nologin_users_are_rejected(): void {
        global $DB;
        foreach ([['deleted', 1], ['confirmed', 0], ['auth', 'nologin']] as [$field, $value]) {
            $user = $this->getDataGenerator()->create_user();
            [$token] = \local_pluginiaconnect_mcp_fixture::grant($user->id, 'student', ['profile']);
            $DB->set_field('user', $field, $value, ['id' => $user->id]);
            $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], [], $token);
            $this->assertSame(401, $response->status, $field);
            $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['userid' => $user->id]), $field);
        }
    }

    public function test_invalid_json_batches_and_malformed_requests(): void {
        $response = $this->send('{not json');
        $this->assertSame(400, $response->status);
        $this->assertSame(-32700, $response->decoded()['error']['code']);

        $response = $this->send([['jsonrpc' => '2.0', 'id' => 1, 'method' => 'ping']], $this->legacy());
        $this->assertSame(400, $response->status);
        $this->assertSame(-32600, $response->decoded()['error']['code']);

        $response = $this->send(['id' => 1, 'method' => 'ping'], $this->legacy());
        $this->assertSame(-32600, $response->decoded()['error']['code']);

        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1.5, 'method' => 'ping'], $this->legacy());
        $this->assertSame(-32600, $response->decoded()['error']['code']);

        $request = \local_pluginiaconnect_mcp_fixture::request('a=b', $this->token, ['Content-Type' => 'text/plain']);
        $this->assertSame(415, server::handle($request, \local_pluginiaconnect_mcp_fixture::authenticator())->status);

        $big = \local_pluginiaconnect_mcp_fixture::request(str_repeat('x', request::MAX_BODY + 1), $this->token);
        $this->assertSame(413, server::handle($big, \local_pluginiaconnect_mcp_fixture::authenticator())->status);
    }

    public function test_legacy_initialize_negotiates_the_version(): void {
        $versions = [
            '2025-06-18' => '2025-06-18', '2025-11-25' => '2025-11-25', '2025-03-26' => '2025-11-25',
            '1999-01-01' => '2025-11-25',
        ];
        foreach ($versions as $requested => $expected) {
            $response = $this->send([
                'jsonrpc' => '2.0', 'id' => 'a', 'method' => 'initialize',
                'params' => [
                    'protocolVersion' => $requested,
                    'capabilities' => [],
                    'clientInfo' => ['name' => 't', 'version' => '1'],
                ],
            ]);
            $result = $response->decoded()['result'];
            $this->assertSame(200, $response->status);
            $this->assertSame($expected, $result['protocolVersion']);
            $this->assertSame('pluginia-connect', $result['serverInfo']['name']);
            $this->assertFalse($result['capabilities']['tools']['listChanged']);
            $this->assertArrayNotHasKey('instructions', $result);
            $this->assertSame('a', $response->decoded()['id']);
        }
    }

    public function test_legacy_requests_need_a_supported_version_header(): void {
        $message = ['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'];
        $this->assertSame(400, $this->send($message)->status);
        $this->assertSame(400, $this->send($message, $this->legacy('2025-03-26'))->status);
        $this->assertSame(400, $this->send($message, $this->legacy('2026-07-28'))->status);
        $this->assertSame(200, $this->send($message, $this->legacy('2025-06-18'))->status);
        $this->assertSame(200, $this->send($message, $this->legacy('2025-11-25'))->status);
    }

    public function test_legacy_notification_ping_list_and_unknown_method(): void {
        $response = $this->send(['jsonrpc' => '2.0', 'method' => 'notifications/initialized'], $this->legacy());
        $this->assertSame(202, $response->status);
        $this->assertNull($response->body);

        $response = $this->send(['jsonrpc' => '2.0', 'id' => 2, 'method' => 'ping'], $this->legacy());
        $this->assertSame('{}', json_encode(json_decode($response->body)->result));

        $response = $this->send(['jsonrpc' => '2.0', 'id' => 3, 'method' => 'tools/list'], $this->legacy());
        $result = $response->decoded()['result'];
        $this->assertSame(
            [
                'get_connection_info', 'list_my_courses', 'search_courses', 'get_course_outline',
                'list_upcoming_deadlines', 'get_assignment_details', 'list_course_announcements', 'get_my_grades',
                'get_my_course_progress', 'get_pending_action_status',
            ],
            array_column($result['tools'], 'name')
        );
        $this->assertArrayNotHasKey('resultType', $result);
        // Empty input schemas must stay JSON objects.
        $this->assertStringContainsString('"properties":{}', $response->body);

        foreach (['resources/list', 'prompts/list', 'server/discover', 'sampling/createMessage'] as $method) {
            $response = $this->send(['jsonrpc' => '2.0', 'id' => 4, 'method' => $method], $this->legacy());
            $this->assertSame(-32601, $response->decoded()['error']['code'], $method);
        }
    }

    public function test_tools_list_follows_scopes_profile_and_mode(): void {
        [$token] = \local_pluginiaconnect_mcp_fixture::grant($this->user->id, 'student', ['profile']);
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->legacy(), $token);
        $this->assertSame(
            ['get_connection_info', 'get_pending_action_status'],
            array_column($response->decoded()['result']['tools'], 'name')
        );

        set_config('tool_list_my_courses_student', 0, 'local_pluginiaconnect');
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->legacy());
        $this->assertNotContains('list_my_courses', array_column($response->decoded()['result']['tools'], 'name'));

        set_config('directmode', 0, 'local_pluginiaconnect');
        $response = $this->send(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/list'], $this->legacy());
        $this->assertSame([], $response->decoded()['result']['tools']);
    }

    public function test_legacy_tools_call_and_unknown_tool(): void {
        $response = $this->send([
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call',
            'params' => ['name' => 'get_connection_info', 'arguments' => new \stdClass()],
        ], $this->legacy());
        $result = $response->decoded()['result'];
        $this->assertSame(200, $response->status);
        $this->assertFalse($result['isError']);
        $this->assertSame('ok', $result['structuredContent']['status']);
        $this->assertSame('req-1', $result['structuredContent']['meta']['request_id']);
        $this->assertSame($result['structuredContent'], json_decode($result['content'][0]['text'], true));
        $this->assertArrayNotHasKey('resultType', $result);

        $response = $this->send([
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'run_sql'],
        ], $this->legacy());
        $this->assertSame(-32602, $response->decoded()['error']['code']);

        $response = $this->send(['jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => []], $this->legacy());
        $this->assertSame(-32602, $response->decoded()['error']['code']);
    }

    public function test_missing_scope_answers_403_with_challenge(): void {
        [$token] = \local_pluginiaconnect_mcp_fixture::grant($this->user->id, 'student', ['profile']);
        $response = $this->send([
            'jsonrpc' => '2.0', 'id' => 9, 'method' => 'tools/call', 'params' => ['name' => 'list_my_courses'],
        ], $this->legacy(), $token);
        $this->assertSame(403, $response->status);
        $this->assertStringContainsString('error="insufficient_scope"', $response->headers['WWW-Authenticate']);
        $this->assertStringContainsString('scope="courses.read"', $response->headers['WWW-Authenticate']);
        $result = $response->decoded()['result'];
        $this->assertTrue($result['isError']);
        $this->assertSame('scope_missing', $result['structuredContent']['error']['code']);
        $this->assertStringContainsString('insufficient_scope', $result['_meta']['mcp/www_authenticate']);
    }

    public function test_modern_discover_list_and_call(): void {
        [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern('server/discover');
        $response = $this->send($message, $headers);
        $result = $response->decoded()['result'];
        $this->assertSame(200, $response->status);
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame(protocol::supported(), $result['supportedVersions']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertIsInt($result['ttlMs']);
        $this->assertSame('pluginia-connect', $result['_meta'][protocol::META_SERVERINFO]['name']);
        $this->assertArrayNotHasKey('instructions', $result);
        $this->assertStringContainsString('"capabilities":{"tools":{}}', $response->body);

        [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern('tools/list');
        $result = $this->send($message, $headers)->decoded()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertSame('private', $result['cacheScope']);
        $this->assertCount(10, $result['tools']);

        [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern(
            'tools/call',
            ['name' => 'get_connection_info'],
            'get_connection_info'
        );
        $result = $this->send($message, $headers)->decoded()['result'];
        $this->assertSame('complete', $result['resultType']);
        $this->assertFalse($result['isError']);

        // The base64 form of the Mcp-Name header is decoded before comparing.
        $headers['Mcp-Name'] = '=?base64?' . base64_encode('get_connection_info') . '?=';
        $this->assertSame(200, $this->send($message, $headers)->status);
    }

    public function test_modern_header_and_version_errors(): void {
        [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern('tools/list');

        $wrong = $message;
        $wrong['params']['_meta'][protocol::META_VERSION] = '1900-01-01';
        $response = $this->send($wrong, $headers);
        $this->assertSame(400, $response->status);
        $error = $response->decoded()['error'];
        $this->assertSame(-32022, $error['code']);
        $this->assertSame(protocol::supported(), $error['data']['supported']);
        $this->assertSame('1900-01-01', $error['data']['requested']);

        $badheaders = [
            ['MCP-Protocol-Version', null], ['MCP-Protocol-Version', '2025-11-25'], ['Mcp-Method', null],
            ['Mcp-Method', 'tools/call'],
        ];
        foreach ($badheaders as [$name, $value]) {
            $bad = $headers;
            if ($value === null) {
                unset($bad[$name]);
            } else {
                $bad[$name] = $value;
            }
            $response = $this->send($message, $bad);
            $this->assertSame(400, $response->status, $name);
            $this->assertSame(-32020, $response->decoded()['error']['code'], $name);
        }

        $nocaps = $message;
        unset($nocaps['params']['_meta'][protocol::META_CAPABILITIES]);
        $response = $this->send($nocaps, $headers);
        $this->assertSame(400, $response->status);
        $this->assertSame(-32602, $response->decoded()['error']['code']);

        [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern(
            'tools/call',
            ['name' => 'get_my_grades'],
            'list_my_courses'
        );
        $response = $this->send($message, $headers);
        $this->assertSame(-32020, $response->decoded()['error']['code']);
        unset($headers['Mcp-Name']);
        $this->assertSame(-32020, $this->send($message, $headers)->decoded()['error']['code']);

        foreach (['initialize', 'ping', 'resources/list'] as $method) {
            [$message, $headers] = \local_pluginiaconnect_mcp_fixture::modern($method);
            $response = $this->send($message, $headers);
            $this->assertSame(404, $response->status, $method);
            $this->assertSame(-32601, $response->decoded()['error']['code'], $method);
        }
    }

    public function test_end_to_end_with_the_real_token_service(): void {
        $service = '\local_pluginiaconnect\local\oauth\token_service';
        if (!class_exists($service)) {
            $this->markTestSkipped('The token service is provided by another part of the plugin.');
        }
        global $DB;
        $message = [
            'jsonrpc' => '2.0', 'id' => 1, 'method' => 'tools/call',
            'params' => ['name' => 'get_connection_info'],
        ];
        $request = \local_pluginiaconnect_mcp_fixture::request($message, $this->token, $this->legacy());
        $response = server::handle($request);
        $this->assertSame(200, $response->status);
        $this->assertFalse($response->decoded()['result']['isError']);
        $this->assertSame('192.0.2.10', $DB->get_field('local_pluginiaconnect_token', 'lastip', []));

        $grantid = (int) $DB->get_field('local_pluginiaconnect_grant', 'id', ['userid' => $this->user->id]);
        $service::revoke_grant($grantid, 'user');
        $this->assertSame(401, server::handle($request)->status);
    }

    public function test_decode_header(): void {
        $this->assertSame('plain', protocol::decode_header(' plain '));
        $this->assertSame('ñandú', protocol::decode_header('=?base64?' . base64_encode('ñandú') . '?='));
        $this->assertNull(protocol::decode_header('=?base64?' . base64_encode("\xff\xfe") . '?='));
    }
}
