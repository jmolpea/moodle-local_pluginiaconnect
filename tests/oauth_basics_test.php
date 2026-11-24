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

use local_pluginiaconnect\local\http\endpoint;
use local_pluginiaconnect\local\http\origin_guard;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\metadata_endpoint;
use local_pluginiaconnect\local\oauth\pkce;
use local_pluginiaconnect\local\oauth\redirect_policy;
use local_pluginiaconnect\local\oauth\server_metadata;
use local_pluginiaconnect\local\urls;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/oauth_helper.php');

/**
 * PKCE, redirect policy, origin guard, metadata documents and endpoint start-up.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_pluginiaconnect\local\oauth\pkce
 * @covers \local_pluginiaconnect\local\oauth\redirect_policy
 * @covers \local_pluginiaconnect\local\oauth\server_metadata
 * @covers \local_pluginiaconnect\local\oauth\metadata_endpoint
 * @covers \local_pluginiaconnect\local\http\origin_guard
 * @covers \local_pluginiaconnect\local\http\endpoint
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\pkce::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\redirect_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\server_metadata::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\metadata_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\http\origin_guard::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\http\endpoint::class)]
final class oauth_basics_test extends \advanced_testcase {
    use oauth_helper;

    /**
     * A correct verifier matches; the test vector of RFC 7636 appendix B holds.
     */
    public function test_pkce_accepts_correct_verifier(): void {
        $verifier = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
        $challenge = 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM';
        $this->assertSame($challenge, pkce::challenge($verifier));
        $this->assertTrue(pkce::verify($verifier, $challenge));
    }

    /**
     * Wrong, missing or malformed verifiers and challenges never match.
     */
    public function test_pkce_rejects_everything_else(): void {
        $verifier = $this->make_verifier();
        $challenge = pkce::challenge($verifier);
        $this->assertFalse(pkce::verify($this->make_verifier(), $challenge));
        $this->assertFalse(pkce::verify('', $challenge));
        $this->assertFalse(pkce::verify('short', $challenge));
        $this->assertFalse(pkce::verify($verifier . '!', $challenge));
        $this->assertFalse(pkce::verify(str_repeat('a', 129), $challenge));
        // The "plain" method would send the verifier as the challenge: it must not verify.
        $this->assertFalse(pkce::verify($verifier, $verifier));
        $this->assertFalse(pkce::is_valid_challenge(''));
        $this->assertFalse(pkce::is_valid_challenge($challenge . '='));
        $this->assertFalse(pkce::is_valid_challenge(substr($challenge, 1)));
        $this->assertTrue(pkce::is_valid_challenge($challenge));
    }

    /**
     * Variants of an allowed redirect URI that must all be refused.
     *
     * @return array
     */
    public static function redirect_variants_provider(): array {
        return [
            'trailing slash' => ['https://claude.ai/api/mcp/auth_callback/'],
            'upper case path' => ['https://claude.ai/api/mcp/AUTH_CALLBACK'],
            'upper case host' => ['https://CLAUDE.ai/api/mcp/auth_callback'],
            'explicit port' => ['https://claude.ai:443/api/mcp/auth_callback'],
            'other port' => ['https://claude.ai:8443/api/mcp/auth_callback'],
            'fragment' => ['https://claude.ai/api/mcp/auth_callback#x'],
            'subdomain' => ['https://evil.claude.ai/api/mcp/auth_callback'],
            'suffix domain' => ['https://claude.ai.evil.example/api/mcp/auth_callback'],
            'extra path' => ['https://claude.ai/api/mcp/auth_callback/extra'],
            'query added' => ['https://claude.ai/api/mcp/auth_callback?x=1'],
            'http scheme' => ['http://claude.ai/api/mcp/auth_callback'],
            'credentials' => ['https://user@claude.ai/api/mcp/auth_callback'],
            'custom scheme' => ['claude://api/mcp/auth_callback'],
            'whitespace' => ['https://claude.ai/api/mcp/auth_callback '],
            'empty' => [''],
            'gateway redirect' => [urls::GATEWAY_REDIRECT],
        ];
    }

    /**
     * Only the exact string is accepted, both for registration and in a request.
     *
     * @dataProvider redirect_variants_provider
     * @param string $variant URI to refuse.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('redirect_variants_provider')]
    public function test_redirect_variants_are_refused(string $variant): void {
        $this->resetAfterTest();
        [$client] = $this->make_client();
        $this->assertFalse(redirect_policy::is_registrable($variant));
        $this->assertFalse(redirect_policy::is_allowed($variant, $client));
    }

    /**
     * The exact allowed URI is accepted, but only for a client that registered it.
     */
    public function test_redirect_exact_match(): void {
        $this->resetAfterTest();
        [$client] = $this->make_client();
        $this->assertTrue(redirect_policy::is_registrable(self::$claude));
        $this->assertTrue(redirect_policy::is_allowed(self::$claude, $client));

        $chatgpt = 'https://chatgpt.com/connector_platform_oauth_redirect';
        $this->assertTrue(redirect_policy::is_registrable($chatgpt));
        $this->assertFalse(redirect_policy::is_allowed($chatgpt, $client), 'Allowed by the site but not registered by the client');

        set_config('allowedredirects', $chatgpt, 'local_pluginiaconnect');
        $this->assertFalse(redirect_policy::is_allowed(self::$claude, $client), 'Registered by the client but no longer allowed');
    }

    /**
     * Loopback redirects are off by default; when on, the port is ignored and nothing else is.
     */
    public function test_redirect_loopback(): void {
        $this->resetAfterTest();
        $this->assertFalse(redirect_policy::is_registrable('http://localhost:3118/callback'));
        $this->assertTrue(redirect_policy::is_loopback('http://127.0.0.1/callback'));
        $this->assertFalse(redirect_policy::is_loopback('http://localhost.evil.example/callback'));
        $this->assertFalse(redirect_policy::is_loopback('https://localhost/callback'));
        $this->assertFalse(redirect_policy::is_loopback('http://[::1]/callback'));

        set_config('allowloopback', 1, 'local_pluginiaconnect');
        $this->assertTrue(redirect_policy::is_registrable('http://localhost:3118/callback'));
        [$client] = $this->make_client(client::AUTH_NONE, ['http://localhost/callback', 'http://127.0.0.1/callback']);
        $this->assertTrue(redirect_policy::is_allowed('http://localhost:3118/callback', $client));
        $this->assertTrue(redirect_policy::is_allowed('http://127.0.0.1:50001/callback', $client));
        $this->assertFalse(redirect_policy::is_allowed('http://localhost:3118/other', $client));
        $this->assertFalse(redirect_policy::is_allowed('http://localhost:3118/callback?x=1', $client));
        $this->assertFalse(redirect_policy::is_allowed('http://192.168.1.5:3118/callback', $client));

        set_config('allowloopback', 0, 'local_pluginiaconnect');
        $this->assertFalse(redirect_policy::is_allowed('http://localhost:3118/callback', $client));
    }

    /**
     * The gateway redirect belongs to the gateway client alone and needs the gateway switched on.
     */
    public function test_redirect_gateway_is_bound_to_its_client(): void {
        $this->resetAfterTest();
        $gateway = client_repository::save_gateway_client('secret-of-the-gateway-0123456789abcdef');
        $this->assertFalse(redirect_policy::is_allowed(urls::GATEWAY_REDIRECT, $gateway));
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $this->assertTrue(redirect_policy::is_allowed(urls::GATEWAY_REDIRECT, $gateway));
        $this->assertFalse(redirect_policy::is_allowed(self::$claude, $gateway));

        // Even if an administrator lists it, nobody else can register or use it.
        set_config('allowedredirects', urls::GATEWAY_REDIRECT, 'local_pluginiaconnect');
        $this->assertFalse(redirect_policy::is_registrable(urls::GATEWAY_REDIRECT));
    }

    /**
     * Response parameters are appended without disturbing an existing query string.
     */
    public function test_redirect_build(): void {
        $this->assertSame('https://a.example/cb?code=x%20y&state=s', redirect_policy::build('https://a.example/cb', [
            'code' => 'x y', 'state' => 's', 'empty' => '',
        ]));
        $this->assertSame('https://a.example/cb?k=1&code=x', redirect_policy::build('https://a.example/cb?k=1', ['code' => 'x']));
    }

    /**
     * The authorization server document has the fields the clients require, and follows the settings.
     */
    public function test_authorization_server_metadata(): void {
        $this->resetAfterTest();
        $document = server_metadata::authorization_server();
        $this->assertSame(urls::issuer(), $document['issuer']);
        $this->assertStringEndsWith('/local/pluginiaconnect/oauth.php', $document['issuer']);
        $this->assertSame(['S256'], $document['code_challenge_methods_supported']);
        $this->assertSame(['code'], $document['response_types_supported']);
        $this->assertSame(['authorization_code', 'refresh_token'], $document['grant_types_supported']);
        $this->assertContains('none', $document['token_endpoint_auth_methods_supported']);
        $this->assertTrue($document['authorization_response_iss_parameter_supported']);
        $this->assertTrue($document['client_id_metadata_document_supported']);
        $this->assertSame(urls::register(), $document['registration_endpoint']);
        $this->assertContains('offline_access', $document['scopes_supported']);

        set_config('allowdcr', 0, 'local_pluginiaconnect');
        set_config('allowcimd', 0, 'local_pluginiaconnect');
        $document = server_metadata::authorization_server();
        $this->assertArrayNotHasKey('registration_endpoint', $document);
        $this->assertArrayNotHasKey('client_id_metadata_document_supported', $document);
    }

    /**
     * The protected resource document names this endpoint, this issuer and only the baseline scopes.
     */
    public function test_protected_resource_metadata(): void {
        $document = server_metadata::protected_resource();
        $this->assertSame(urls::resource(), $document['resource']);
        $this->assertSame([urls::issuer()], $document['authorization_servers']);
        $this->assertSame(['profile', 'courses.read', 'grades.read', 'offline_access'], $document['scopes_supported']);
        $this->assertSame(['header'], $document['bearer_methods_supported']);
    }

    /**
     * Metadata routes: three paths, GET only, readable from any origin.
     */
    public function test_metadata_endpoint(): void {
        $get = new request('GET');
        foreach (['/.well-known/openid-configuration', '/.well-known/oauth-authorization-server'] as $path) {
            $response = metadata_endpoint::handle($get, $path);
            $this->assertSame(200, $response->status);
            $this->assertSame('*', $response->headers['Access-Control-Allow-Origin']);
            $this->assertSame(urls::issuer(), $response->decoded()['issuer']);
        }
        $response = metadata_endpoint::handle($get, '/resource-metadata');
        $this->assertSame(urls::resource(), $response->decoded()['resource']);

        $this->assertSame(404, metadata_endpoint::handle($get, '/unknown')->status);
        $this->assertSame(404, metadata_endpoint::handle($get, '')->status);
        $post = metadata_endpoint::handle(new request('POST'), '/resource-metadata');
        $this->assertSame(405, $post->status);
        $this->assertArrayNotHasKey('Access-Control-Allow-Origin', $post->headers);
    }

    /**
     * Requests without Origin pass; a foreign origin gets 403; loopback origins need the setting.
     */
    public function test_origin_guard(): void {
        global $CFG;
        $this->resetAfterTest();
        $own = origin_guard::own_origin();
        $this->assertStringStartsWith($own, $CFG->wwwroot);

        $this->assertNull(origin_guard::check(new request('POST')));
        $this->assertNull(origin_guard::check(new request('POST', ['Origin' => $own])));
        $foreign = origin_guard::check(new request('POST', ['Origin' => 'https://evil.example']));
        $this->assertSame(403, $foreign->status);
        $this->assertSame(403, origin_guard::check(new request('POST', ['Origin' => 'null']))->status);

        $loopback = new request('POST', ['Origin' => 'http://localhost:6274']);
        $this->assertSame(403, origin_guard::check($loopback)->status);
        $this->assertSame([], origin_guard::cors_headers($loopback));

        set_config('allowloopback', 1, 'local_pluginiaconnect');
        $this->assertNull(origin_guard::check($loopback));
        $this->assertNull(origin_guard::check(new request('POST', ['Origin' => 'http://127.0.0.1:6274'])));
        $this->assertSame('http://localhost:6274', origin_guard::cors_headers($loopback)['Access-Control-Allow-Origin']);
        $this->assertSame(403, origin_guard::check(new request('POST', ['Origin' => 'https://localhost:6274']))->status);
        $this->assertSame(403, origin_guard::check(new request('POST', ['Origin' => 'http://localhost.evil.example']))->status);
        $this->assertSame([], origin_guard::cors_headers(new request('POST', ['Origin' => $own])));
        $this->assertSame([], origin_guard::cors_headers(new request('POST')));
    }

    /**
     * Start-up refusals: switched off, maintenance, plain HTTP and oversized bodies.
     */
    public function test_endpoint_precheck(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->require_site_policy();
        $request = new request('POST');

        $this->assertSame(503, endpoint::precheck($request)->status, 'Disabled by default');
        set_config('enabled', 1, 'local_pluginiaconnect');
        $this->assertNull(endpoint::precheck($request));

        $CFG->maintenance_enabled = 1;
        $this->assertSame(503, endpoint::precheck($request)->status);
        $CFG->maintenance_enabled = 0;

        $plain = new request('POST', [], '', [], false);
        $CFG->wwwroot = 'https://campus.example';
        $this->assertSame(400, endpoint::precheck($plain)->status);
        $CFG->wwwroot = 'http://localhost:8153';
        $CFG->debug = DEBUG_NORMAL;
        $this->assertSame(400, endpoint::precheck($plain)->status);
        $CFG->debug = DEBUG_DEVELOPER;
        $this->assertNull(endpoint::precheck($plain));
        $CFG->wwwroot = 'http://localhost.evil.example';
        $this->assertSame(400, endpoint::precheck($plain)->status);

        $big = new request('POST', [], str_repeat('a', request::MAX_BODY + 1));
        $this->assertSame(413, endpoint::precheck($big)->status);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', endpoint::request_id());
        $this->assertSame(endpoint::request_id(), endpoint::request_id());
    }
}
