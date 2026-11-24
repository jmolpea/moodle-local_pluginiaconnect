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
use local_pluginiaconnect\local\oauth\cimd_fetcher;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_authenticator;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\oauth\register_endpoint;
use local_pluginiaconnect\local\urls;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/oauth_helper.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\cimd_fetcher::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\client_repository::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\client::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\client_authenticator::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\register_endpoint::class)]
/**
 * Client identification: metadata documents, dynamic registration and client authentication.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_pluginiaconnect\local\oauth\cimd_fetcher
 * @covers \local_pluginiaconnect\local\oauth\client_repository
 * @covers \local_pluginiaconnect\local\oauth\client
 * @covers \local_pluginiaconnect\local\oauth\client_authenticator
 * @covers \local_pluginiaconnect\local\oauth\register_endpoint
 */
final class oauth_client_test extends \advanced_testcase {
    use oauth_helper;

    /** @var string URL of the metadata document used in the tests. */
    private const CIMD_URL = 'https://claude.ai/oauth/client-metadata.json';

    /** @var int Number of simulated downloads. */
    private int $downloads = 0;

    /**
     * Restores the network functions.
     */
    protected function tearDown(): void {
        cimd_fetcher::set_test_hooks(null, null);
        parent::tearDown();
    }

    /**
     * Replaces the network with a document and an address.
     *
     * @param array|string $document Document to serve (array is encoded as JSON).
     * @param string[] $ips Addresses the host resolves to.
     * @param int $status HTTP status.
     * @return void
     */
    private function fake_network($document, array $ips = ['160.79.104.10'], int $status = 200): void {
        $this->downloads = 0;
        $body = is_array($document) ? json_encode($document) : $document;
        cimd_fetcher::set_test_hooks(function (string $url, array $resolved) use ($body, $status): array {
            $this->downloads++;
            return [$status, $body];
        }, static fn(string $host): array => $ips);
    }

    /**
     * A valid document of a trusted domain.
     *
     * @param array $override Fields to replace.
     * @return array
     */
    private function document(array $override = []): array {
        return $override + [
            'client_id' => self::CIMD_URL,
            'client_name' => 'Claude',
            'redirect_uris' => [self::$claude],
            'token_endpoint_auth_method' => 'none',
        ];
    }

    /**
     * A valid document is accepted, stored as a public client and cached.
     */
    public function test_cimd_valid_document(): void {
        $this->resetAfterTest();
        $this->fake_network($this->document());

        $client = client_repository::find(self::CIMD_URL, true);
        $this->assertNotNull($client);
        $this->assertSame(client::TYPE_CIMD, $client->type);
        $this->assertSame('Claude', $client->name);
        $this->assertSame([self::$claude], $client->redirecturis);
        $this->assertFalse($client->is_confidential());
        $this->assertSame('claude.ai', $client->host());

        // Second resolution: served from cache, same record.
        $again = client_repository::find(self::CIMD_URL, true);
        $this->assertSame($client->id, $again->id);
        $this->assertSame(1, $this->downloads);
        $this->assertSame($client->id, client_repository::get_by_id($client->id)->id);

        // Without refresh the stored copy is used and the network is never touched.
        cimd_fetcher::set_test_hooks(static function (): array {
            throw new \coding_exception('The network must not be used.');
        }, static fn(): array => []);
        $this->assertSame($client->id, client_repository::find(self::CIMD_URL)->id);
    }

    /**
     * A metadata document client that was never validated is unknown to the token endpoint.
     */
    public function test_cimd_unknown_without_refresh(): void {
        $this->resetAfterTest();
        $this->fake_network($this->document());
        $this->assertNull(client_repository::find(self::CIMD_URL));
        $this->assertSame(0, $this->downloads);
    }

    /**
     * Documents that must be refused.
     *
     * @return array
     */
    public static function bad_documents_provider(): array {
        return [
            'client_id differs from the URL' => [['client_id' => 'https://claude.ai/oauth/other.json']],
            'client_id missing' => [['client_id' => null]],
            'no name' => [['client_name' => '']],
            'no redirect URIs' => [['redirect_uris' => []]],
            'redirect URIs not a list' => [['redirect_uris' => 'https://claude.ai/api/mcp/auth_callback']],
            'only malformed redirect URIs' => [['redirect_uris' => ['javascript:alert(1)', 'ftp://x/y']]],
        ];
    }

    /**
     * Invalid documents are refused.
     *
     * @dataProvider bad_documents_provider
     * @param array $override Fields that make the document invalid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('bad_documents_provider')]
    public function test_cimd_bad_document(array $override): void {
        $this->resetAfterTest();
        $this->fake_network($this->document($override));
        $this->assertNull(client_repository::find(self::CIMD_URL, true));
    }

    /**
     * Not JSON, too large, wrong status: refused.
     */
    public function test_cimd_bad_response(): void {
        $this->resetAfterTest();
        $this->fake_network('<html>not json</html>');
        $this->assertNull(client_repository::find(self::CIMD_URL, true));

        $padding = $this->document(['padding' => str_repeat('a', cimd_fetcher::MAX_BYTES)]);
        $this->fake_network($padding);
        $this->assertNull(client_repository::find(self::CIMD_URL, true));

        // A redirect (to another host or not) is never followed: the 3xx itself is a failure.
        $this->fake_network($this->document(), ['160.79.104.10'], 302);
        $this->assertNull(client_repository::find(self::CIMD_URL, true));
        $this->fake_network($this->document(), ['160.79.104.10'], 404);
        $this->assertNull(client_repository::find(self::CIMD_URL, true));
    }

    /**
     * Hosts that resolve to private, loopback, link-local or metadata addresses are refused before any download.
     */
    public function test_cimd_private_hosts(): void {
        $this->resetAfterTest();
        $addresses = [
            '10.0.0.5', '172.16.3.4', '192.168.1.10', '127.0.0.1', '169.254.169.254', '0.0.0.0', '100.64.0.1',
            '::1', 'fe80::1', 'fd00::1', '::ffff:10.0.0.1', '64:ff9b::a00:1', '2001:db8::1', 'not-an-ip',
        ];
        foreach ($addresses as $address) {
            $this->fake_network($this->document(), [$address]);
            $this->assertNull(client_repository::find(self::CIMD_URL, true), $address);
            $this->assertSame(0, $this->downloads, $address);
            $this->assertFalse(cimd_fetcher::is_public_ip($address), $address);
        }
        // One private address among public ones is enough to refuse.
        $this->fake_network($this->document(), ['160.79.104.10', '10.0.0.5']);
        $this->assertNull(client_repository::find(self::CIMD_URL, true));
        // No address at all.
        $this->fake_network($this->document(), []);
        $this->assertNull(client_repository::find(self::CIMD_URL, true));

        $this->assertTrue(cimd_fetcher::is_public_ip('160.79.104.10'));
        $this->assertTrue(cimd_fetcher::is_public_ip('2606:4700::6810:1'));
    }

    /**
     * Only trusted domains, HTTPS, port 443 and URL shaped identifiers with a path are considered.
     */
    public function test_cimd_untrusted_and_malformed(): void {
        $this->resetAfterTest();
        $refused = [
            'https://evil.example/oauth/client.json',
            'https://claude.ai.evil.example/oauth/client.json',
            'https://claude.ai:8443/oauth/client.json',
            'http://claude.ai/oauth/client.json',
            'https://claude.ai',
            'https://claude.ai/',
            'https://user:pass@claude.ai/oauth/client.json',
            'https://claude.ai/oauth/client.json#frag',
            'https://160.79.104.10/oauth/client.json',
            'https://[::1]/oauth/client.json',
            'https://localhost/oauth/client.json',
            'https://claude.ai/a/../client.json',
        ];
        foreach ($refused as $url) {
            $this->fake_network($this->document(['client_id' => $url]));
            $this->assertNull(client_repository::find($url, true), $url);
            $this->assertSame(0, $this->downloads, $url);
        }
        // Subdomains of a trusted domain are accepted.
        $url = 'https://auth.claude.ai/oauth/client.json';
        $this->fake_network($this->document(['client_id' => $url]));
        $this->assertNotNull(client_repository::find($url, true));

        // An empty trusted list accepts any HTTPS host.
        set_config('cimdtrusteddomains', '', 'local_pluginiaconnect');
        $url = 'https://tools.example.org/client.json';
        $this->fake_network($this->document(['client_id' => $url]));
        $this->assertNotNull(client_repository::find($url, true));

        // The setting switches the whole mechanism off.
        set_config('allowcimd', 0, 'local_pluginiaconnect');
        $url = 'https://other.example.org/client.json';
        $this->fake_network($this->document(['client_id' => $url]));
        $this->assertNull(client_repository::find($url, true));
        $this->expectException(oauth_exception::class);
        cimd_fetcher::fetch($url);
    }

    /**
     * Builds a registration request.
     *
     * @param array $body JSON body.
     * @param string $ip Remote address.
     * @return request
     */
    private function registration(array $body, string $ip = '198.51.100.7'): request {
        return new request('POST', ['Content-Type' => 'application/json'], json_encode($body), [], true, $ip);
    }

    /**
     * Valid registrations: public and confidential clients.
     */
    public function test_dcr_valid(): void {
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();

        $response = register_endpoint::handle($this->registration([
            'client_name' => 'My <b>tool</b>',
            'redirect_uris' => [self::$claude],
            'token_endpoint_auth_method' => 'none',
        ]));
        $this->assertSame(201, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $body = $response->decoded();
        $this->assertStringStartsWith('pc_client_', $body['client_id']);
        $this->assertArrayNotHasKey('client_secret', $body);
        $this->assertSame('none', $body['token_endpoint_auth_method']);
        $client = client_repository::find($body['client_id']);
        $this->assertSame(client::TYPE_DCR, $client->type);
        $this->assertStringNotContainsString('<', $client->name);

        // The method defaults to client_secret_basic, as the RFC says; the secret is stored only as a hash.
        $response = register_endpoint::handle($this->registration(['redirect_uris' => [self::$claude]]));
        $body = $response->decoded();
        $this->assertSame('client_secret_basic', $body['token_endpoint_auth_method']);
        $this->assertStringStartsWith('pc_cs_', $body['client_secret']);
        $client = client_repository::find($body['client_id']);
        $this->assertTrue($client->is_confidential());
        $this->assertTrue(password_verify($body['client_secret'], $client->secrethash));
        global $DB;
        $this->assertFalse($DB->record_exists_select(
            'local_pluginiaconnect_client',
            $DB->sql_like('secrethash', ':secret'),
            ['secret' => '%' . $body['client_secret'] . '%']
        ));
    }

    /**
     * Registrations that must be refused.
     */
    public function test_dcr_refusals(): void {
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();
        $error = static fn($response) => [$response->status, $response->decoded()['error']];

        $cases = [
            'redirect outside policy' => [['redirect_uris' => ['https://evil.example/cb']], 'invalid_redirect_uri'],
            'one bad among good' => [['redirect_uris' => [self::$claude, 'https://evil.example/cb']], 'invalid_redirect_uri'],
            'gateway redirect' => [['redirect_uris' => [urls::GATEWAY_REDIRECT]], 'invalid_redirect_uri'],
            'loopback while off' => [['redirect_uris' => ['http://localhost/callback']], 'invalid_redirect_uri'],
            'no redirects' => [['client_name' => 'x'], 'invalid_redirect_uri'],
            'redirect not a string' => [['redirect_uris' => [['a' => 1]]], 'invalid_redirect_uri'],
            'unknown auth method' => [
                ['redirect_uris' => [self::$claude], 'token_endpoint_auth_method' => 'private_key_jwt'],
                'invalid_client_metadata',
            ],
            'implicit grant' => [
                ['redirect_uris' => [self::$claude], 'grant_types' => ['implicit']],
                'invalid_client_metadata',
            ],
            'client credentials' => [
                ['redirect_uris' => [self::$claude], 'grant_types' => ['authorization_code', 'client_credentials']],
                'invalid_client_metadata',
            ],
            'token response type' => [
                ['redirect_uris' => [self::$claude], 'response_types' => ['token']],
                'invalid_client_metadata',
            ],
        ];
        $ip = 1;
        foreach ($cases as $name => [$body, $expected]) {
            $response = register_endpoint::handle($this->registration($body, '198.51.100.' . $ip++));
            $this->assertSame([400, $expected], $error($response), $name);
        }

        $list = new request('POST', ['Content-Type' => 'application/json'], '[1,2]', [], true, '198.51.100.200');
        $this->assertSame([400, 'invalid_client_metadata'], $error(register_endpoint::handle($list)));
        $form = new request('POST', ['Content-Type' => 'text/plain'], '{}', [], true, '198.51.100.201');
        $this->assertSame(415, register_endpoint::handle($form)->status);
        $this->assertSame(405, register_endpoint::handle(new request('GET'))->status);

        global $DB;
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_client'));
    }

    /**
     * Registration is limited per address and can be switched off; it needs direct mode.
     */
    public function test_dcr_limit_and_switches(): void {
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();
        $body = ['redirect_uris' => [self::$claude], 'token_endpoint_auth_method' => 'none'];

        for ($i = 0; $i < register_endpoint::IP_LIMIT; $i++) {
            $this->assertSame(201, register_endpoint::handle($this->registration($body, '192.0.2.50'))->status);
        }
        $limited = register_endpoint::handle($this->registration($body, '192.0.2.50'));
        $this->assertSame(429, $limited->status);
        $this->assertArrayHasKey('Retry-After', $limited->headers);
        // Another address is not affected.
        $this->assertSame(201, register_endpoint::handle($this->registration($body, '192.0.2.51'))->status);

        set_config('allowdcr', 0, 'local_pluginiaconnect');
        $this->assertSame(404, register_endpoint::handle($this->registration($body, '192.0.2.52'))->status);
        set_config('allowdcr', 1, 'local_pluginiaconnect');
        set_config('directmode', 0, 'local_pluginiaconnect');
        $this->assertSame(404, register_endpoint::handle($this->registration($body, '192.0.2.53'))->status);
    }

    /**
     * Expects client authentication to fail with invalid_client.
     *
     * @param request $request Request.
     * @param array $form Form fields.
     * @param string $error Expected error code.
     * @return void
     */
    private function assert_auth_fails(request $request, array $form, string $error = 'invalid_client'): void {
        try {
            client_authenticator::authenticate($request, $form);
            $this->fail('Client authentication should have failed.');
        } catch (oauth_exception $e) {
            $this->assertSame($error, $e->error);
            $this->assertSame($error === 'invalid_client' ? 401 : 400, $e->status);
        }
    }

    /**
     * Public clients, both secret methods, wrong secrets, wrong method, blocked clients and switched off modes.
     */
    public function test_client_authentication(): void {
        global $DB;
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();
        $plain = new request('POST');
        $basic = static fn(string $id, string $secret): request => new request('POST', [
            'Authorization' => 'Basic ' . base64_encode(rawurlencode($id) . ':' . rawurlencode($secret)),
        ]);

        // Public client: identifier only.
        [$public] = $this->make_client();
        $this->assertSame($public->id, client_authenticator::authenticate($plain, ['client_id' => $public->clientid])->id);
        $this->assert_auth_fails($plain, ['client_id' => $public->clientid, 'client_secret' => 'anything']);
        $this->assert_auth_fails($plain, []);
        $this->assert_auth_fails($plain, ['client_id' => 'pc_client_unknown']);

        // Secret in the body.
        [$post, $postsecret] = $this->make_client(client::AUTH_POST);
        $form = ['client_id' => $post->clientid, 'client_secret' => $postsecret];
        $this->assertSame($post->id, client_authenticator::authenticate($plain, $form)->id);
        $this->assert_auth_fails($plain, ['client_id' => $post->clientid]);
        $this->assert_auth_fails($plain, ['client_id' => $post->clientid, 'client_secret' => $postsecret . 'x']);
        $this->assert_auth_fails($basic($post->clientid, $postsecret), [], 'invalid_client');

        // Secret in the Authorization header.
        [$header, $headersecret] = $this->make_client(client::AUTH_BASIC);
        $this->assertSame($header->id, client_authenticator::authenticate($basic($header->clientid, $headersecret), [])->id);
        $this->assert_auth_fails($basic($header->clientid, 'wrong'), []);
        $this->assert_auth_fails($plain, ['client_id' => $header->clientid, 'client_secret' => $headersecret]);
        $this->assert_auth_fails($basic($header->clientid, $headersecret), ['client_id' => $post->clientid]);
        // Both methods at once is a malformed request.
        $this->assert_auth_fails(
            $basic($header->clientid, $headersecret),
            ['client_secret' => $headersecret],
            'invalid_request'
        );
        try {
            client_authenticator::authenticate($basic($header->clientid, 'wrong'), []);
        } catch (oauth_exception $e) {
            $this->assertArrayHasKey('WWW-Authenticate', $e->headers);
        }
        $this->assertSame($header->clientid, client_authenticator::claimed_id($basic($header->clientid, 'x'), []));
        $this->assertSame('abc', client_authenticator::claimed_id($plain, ['client_id' => 'abc']));

        // A blocked client cannot authenticate.
        $DB->set_field('local_pluginiaconnect_client', 'status', 0, ['id' => $public->id]);
        $this->assert_auth_fails($plain, ['client_id' => $public->clientid]);

        // The gateway client needs the gateway mode; direct clients need direct mode.
        $gateway = client_repository::save_gateway_client('gateway-secret-0123456789abcdef0123');
        $form = ['client_id' => urls::GATEWAY_CLIENT_ID, 'client_secret' => 'gateway-secret-0123456789abcdef0123'];
        $this->assert_auth_fails($plain, $form);
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $this->assertSame($gateway->id, client_authenticator::authenticate($plain, $form)->id);
        set_config('directmode', 0, 'local_pluginiaconnect');
        $this->assert_auth_fails($plain, ['client_id' => $post->clientid, 'client_secret' => $postsecret]);
        $this->assertSame($gateway->id, client_authenticator::authenticate($plain, $form)->id);

        // Saving the gateway client again replaces its secret and keeps its record.
        $again = client_repository::save_gateway_client('another-secret-0123456789abcdef01234', client::AUTH_BASIC);
        $this->assertSame($gateway->id, $again->id);
        $this->assert_auth_fails($plain, $form);
    }

    /**
     * Stale dynamically registered clients are removed; used ones and ones with grants are kept.
     */
    public function test_delete_stale_dcr(): void {
        global $DB;
        $this->resetAfterTest();
        [$stale] = $this->make_client();
        [$recent] = $this->make_client();
        [$used] = $this->make_client();
        [$withgrant] = $this->make_client();
        $old = time() - 40 * DAYSECS;
        foreach ([$stale, $used, $withgrant] as $client) {
            $DB->set_field('local_pluginiaconnect_client', 'timecreated', $old, ['id' => $client->id]);
        }
        client_repository::touch($used);
        $DB->insert_record('local_pluginiaconnect_grant', (object) [
            'userid' => 2, 'clientid' => $withgrant->id, 'profile' => 'teacher', 'scopes' => 'profile',
            'resource' => urls::resource(), 'status' => 1, 'timecreated' => $old, 'timelastused' => $old,
            'timeabsoluteexpiry' => time() + DAYSECS,
        ]);

        client_repository::delete_stale_dcr(time() - 30 * DAYSECS);
        $this->assertNull(client_repository::get_by_id($stale->id));
        $this->assertNotNull(client_repository::get_by_id($recent->id));
        $this->assertNotNull(client_repository::get_by_id($used->id));
        $this->assertNotNull(client_repository::get_by_id($withgrant->id));
    }
}
