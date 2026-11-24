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

use local_pluginiaconnect\fixtures\integration_records;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\gateway\http_transport;
use local_pluginiaconnect\local\gateway\registration;
use local_pluginiaconnect\local\gateway\site_identity;
use local_pluginiaconnect\local\gateway\transport;
use local_pluginiaconnect\local\gateway\verify_endpoint;
use local_pluginiaconnect\local\health;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\urls;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/integration_records.php');

/**
 * Tests of the plugin side of the gateway: registration, removal and the domain verification endpoint.
 *
 * The gateway is replaced by a fake transport that behaves like the real one:
 * it calls the verification endpoint of the site back while registering.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\gateway\registration
 * @covers     \local_pluginiaconnect\local\gateway\site_identity
 * @covers     \local_pluginiaconnect\local\gateway\verify_endpoint
 * @covers     \local_pluginiaconnect\local\gateway\http_transport
 */
#[\PHPUnit\Framework\Attributes\CoversClass(registration::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(site_identity::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(verify_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(http_transport::class)]
final class gateway_registration_test extends \advanced_testcase {
    /** @var string Site identifier the fake gateway hands out. */
    private const SITE_ID = '0f8fad5b-d9cb-469f-a165-70867728950e';

    /**
     * Switches the connector on: registering needs it.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        set_config('enabled', 1, 'local_pluginiaconnect');
    }

    /**
     * A fake gateway. It records what it receives and answers as configured.
     *
     * @param int $status Status of the registration answer.
     * @param array|null $body Body of the registration answer; the standard success body when null.
     * @param int $unregisterstatus Status of the removal answer.
     * @return transport Object with public arrays $posts and $verified.
     */
    private function gateway(int $status = 201, ?array $body = null, int $unregisterstatus = 200): transport {
        $body = $body ?? [
            'site_id' => self::SITE_ID,
            'client_id' => urls::GATEWAY_CLIENT_ID,
            'redirect_uri' => urls::GATEWAY_REDIRECT,
            'token_endpoint_auth_method' => 'client_secret_basic',
        ];
        return new class ($status, $body, $unregisterstatus) implements transport {
            /** @var array Requests received: [path, body]. */
            public array $posts = [];
            /** @var bool|null Whether the site proved control of its address during registration. */
            public ?bool $verified = null;

            /**
             * Constructor.
             *
             * @param int $status Status of the registration answer.
             * @param array $body Body of the registration answer.
             * @param int $unregisterstatus Status of the removal answer.
             */
            public function __construct(
                /** @var int Status of the registration answer. */
                private int $status,
                /** @var array Body of the registration answer. */
                private array $body,
                /** @var int Status of the removal answer. */
                private int $unregisterstatus
            ) {
            }

            /**
             * Receives a POST.
             *
             * @param string $path Path.
             * @param array $body Body.
             * @return array
             */
            public function post_json(string $path, array $body): array {
                $this->posts[] = [$path, $body];
                if ($path === '/api/v1/sites/unregister') {
                    return [$this->unregisterstatus, $this->unregisterstatus === 200 ? ['status' => 'unregistered'] : null];
                }
                // Like the real gateway: challenge the site and check the answer with the hash it sent.
                $challenge = bin2hex(random_bytes(32));
                $response = verify_endpoint::handle(new request('GET', [], '', ['challenge' => $challenge]));
                $expected = hash_hmac(
                    'sha256',
                    'pluginiaconnect-verify-v1:' . $challenge,
                    hex2bin($body['registration_hash'])
                );
                $this->verified = $response->status === 200 && hash_equals($expected, $response->decoded()['response']);
                return [$this->status, $this->body];
            }

            /**
             * Receives a GET.
             *
             * @param string $path Path.
             * @return array
             */
            public function get(string $path): array {
                return [200, ['status' => 'ok']];
            }
        };
    }

    /**
     * A registration sends exactly the agreed document, proves the address and sets the site up.
     */
    public function test_register_success(): void {
        global $CFG, $DB;
        $gateway = $this->gateway();
        $sink = $this->redirectEvents();
        [$done, $message] = registration::register('LICENCE-KEY-0001', true, true, $gateway);
        $events = $sink->get_events();
        $sink->close();

        $this->assertTrue($done, $message);
        $this->assertSame('register_done', $message);
        $this->assertTrue($gateway->verified, 'The site answered the challenge of the gateway');

        [$path, $payload] = $gateway->posts[0];
        $this->assertSame('/api/v1/sites/register', $path);
        $this->assertSame(
            ['wwwroot', 'licence_key', 'plugin_version', 'moodle_version', 'registration_hash', 'client_secret', 'declarations'],
            array_keys($payload)
        );
        $this->assertSame(rtrim($CFG->wwwroot, '/'), $payload['wwwroot']);
        $this->assertSame('LICENCE-KEY-0001', $payload['licence_key']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+\.\d+$/', $payload['plugin_version']);
        $this->assertMatchesRegularExpression('/^\d+\.\d+$/', $payload['moodle_version']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $payload['registration_hash']);
        $this->assertGreaterThanOrEqual(32, strlen($payload['client_secret']));
        $this->assertSame(
            ['no_users_under_13' => true, 'student_profile' => false, 'terms_accepted' => true],
            $payload['declarations']
        );

        // The gateway client exists, is confidential, uses HTTP Basic and holds only a hash of the secret.
        $record = $DB->get_record('local_pluginiaconnect_client', ['type' => client::TYPE_GATEWAY], '*', MUST_EXIST);
        $this->assertSame(urls::GATEWAY_CLIENT_ID, $record->clientid);
        $this->assertSame(client::AUTH_BASIC, $record->authmethod);
        $this->assertSame([urls::GATEWAY_REDIRECT], json_decode($record->redirecturis, true));
        $this->assertTrue(password_verify($payload['client_secret'], $record->secrethash));
        $this->assertEquals(1, $record->status);
        $this->assertNotNull(client_repository::find(urls::GATEWAY_CLIENT_ID));

        // Site state.
        $this->assertTrue(config::bool('gatewayenabled'));
        $this->assertSame(site_identity::STATE_REGISTERED, site_identity::state());
        $this->assertSame(self::SITE_ID, site_identity::site_id());
        $this->assertSame($payload['client_secret'], site_identity::secret());
        $this->assertFalse(site_identity::window_open(), 'The verification window closes with the registration');

        // The secret is nowhere in clear in the plugin configuration.
        foreach ((array) get_config('local_pluginiaconnect') as $value) {
            $this->assertStringNotContainsString($payload['client_secret'], (string) $value);
        }
        $this->assertStringNotContainsString('LICENCE-KEY-0001', json_encode(get_config('local_pluginiaconnect')));

        $names = array_map('get_class', $events);
        $this->assertContains(\local_pluginiaconnect\event\site_registered::class, $names);
        $this->assertContains(\local_pluginiaconnect\event\settings_killswitch_changed::class, $names);
    }

    /**
     * Failures of the gateway change nothing on the site.
     *
     * @dataProvider failure_provider
     * @param int $status Status of the gateway.
     * @param array|null $body Body of the gateway.
     * @param string $expected Expected message key.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('failure_provider')]
    public function test_register_failures(int $status, ?array $body, string $expected): void {
        global $DB;
        [$done, $message] = registration::register('LICENCE-KEY-0001', true, true, $this->gateway($status, $body));
        $this->assertFalse($done);
        $this->assertSame($expected, $message);
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_client', ['type' => client::TYPE_GATEWAY]));
        $this->assertFalse(config::bool('gatewayenabled'));
        $this->assertSame(site_identity::STATE_NONE, site_identity::state());
        $this->assertNull(site_identity::secret());
        $this->assertFalse(site_identity::window_open(), 'The verification window never stays open');
    }

    /**
     * Gateway answers that must not register the site.
     *
     * @return array
     */
    public static function failure_provider(): array {
        $good = [
            'site_id' => self::SITE_ID,
            'client_id' => urls::GATEWAY_CLIENT_ID,
            'redirect_uri' => urls::GATEWAY_REDIRECT,
            'token_endpoint_auth_method' => 'client_secret_basic',
        ];
        return [
            'unreachable' => [0, null, 'register_error_unreachable'],
            'invalid licence' => [403, ['error' => 'invalid_licence', 'error_description' => '<b>x</b>'], 'register_error_licence'],
            'licence in use' => [409, ['error' => 'licence_in_use'], 'register_error_licenceinuse'],
            'verification failed' => [403, ['error' => 'verification_failed'], 'register_error_verification'],
            'minors' => [400, ['error' => 'declaration_required'], 'register_error_minors'],
            'rate limited' => [429, ['error' => 'rate_limited'], 'register_error_ratelimited'],
            'unknown error' => [500, ['error' => 'whatever'], 'register_error_unexpected'],
            'success status without body' => [201, [], 'register_error_unexpected'],
            'another client id' => [201, ['client_id' => 'https://evil.example/client.json'] + $good, 'register_error_unexpected'],
            'another redirect' => [201, ['redirect_uri' => 'https://evil.example/cb'] + $good, 'register_error_unexpected'],
            'another auth method' => [201, ['token_endpoint_auth_method' => 'none'] + $good, 'register_error_unexpected'],
            'bad site id' => [201, ['site_id' => '../../etc'] + $good, 'register_error_unexpected'],
        ];
    }

    /**
     * Local preconditions are checked before anything is sent.
     */
    public function test_register_preconditions(): void {
        $gateway = $this->gateway();
        $this->assertSame('register_error_terms', registration::register('LICENCE-KEY-0001', true, false, $gateway)[1]);
        $this->assertSame('register_error_licence', registration::register('short', true, true, $gateway)[1]);

        set_config('allowstudentprofile', 1, 'local_pluginiaconnect');
        $this->assertSame('register_error_minors', registration::register('LICENCE-KEY-0001', false, true, $gateway)[1]);
        set_config('allowstudentprofile', 0, 'local_pluginiaconnect');

        set_config('enabled', 0, 'local_pluginiaconnect');
        $this->assertSame(['register_blocker_disabled'], registration::blockers());
        $this->assertSame('register_blocker_disabled', registration::register('LICENCE-KEY-0001', true, true, $gateway)[1]);

        $this->assertSame([], $gateway->posts, 'Nothing reached the gateway');
        $this->assertFalse(site_identity::window_open());
    }

    /**
     * The student profile is declared, and accepted with the declaration about children.
     */
    public function test_register_declares_student_profile(): void {
        set_config('allowstudentprofile', 1, 'local_pluginiaconnect');
        $gateway = $this->gateway();
        [$done] = registration::register('LICENCE-KEY-0001', true, true, $gateway);
        $this->assertTrue($done);
        $this->assertTrue($gateway->posts[0][1]['declarations']['student_profile']);
        $this->assertTrue($gateway->posts[0][1]['declarations']['no_users_under_13']);
    }

    /**
     * Registering again replaces the secret and ends the connections made under the old one.
     */
    public function test_register_again_revokes_gateway_connections(): void {
        global $DB;
        $this->assertTrue(registration::register('LICENCE-KEY-0001', true, true, $this->gateway())[0]);
        $clientid = (int) $DB->get_field('local_pluginiaconnect_client', 'id', ['type' => client::TYPE_GATEWAY]);
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id, $clientid);
        $firstsecret = site_identity::secret();

        // Removing first is not required: a second registration from the "not registered" state is the normal path,
        // but a repeated call must be safe too.
        $this->assertTrue(registration::register('LICENCE-KEY-0001', true, true, $this->gateway())[0]);
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $grantid]));
        $this->assertNotSame($firstsecret, site_identity::secret());
        $this->assertSame(1, $DB->count_records('local_pluginiaconnect_client', ['type' => client::TYPE_GATEWAY]));
    }

    /**
     * Removing the site: local steps always happen, the gateway is told, and nothing is left behind.
     */
    public function test_unregister(): void {
        global $DB;
        registration::register('LICENCE-KEY-0001', true, true, $this->gateway());
        $secret = site_identity::secret();
        $clientid = (int) $DB->get_field('local_pluginiaconnect_client', 'id', ['type' => client::TYPE_GATEWAY]);
        $user = $this->getDataGenerator()->create_user();
        $viagateway = integration_records::grant($user->id, $clientid);
        $direct = integration_records::grant($user->id);

        $gateway = $this->gateway();
        $sink = $this->redirectEvents();
        [$confirmed, $message, $revoked] = registration::unregister($gateway);
        $names = array_map('get_class', $sink->get_events());
        $sink->close();

        $this->assertTrue($confirmed);
        $this->assertSame('unregister_done', $message);
        $this->assertSame(1, $revoked);
        $this->assertSame(
            ['/api/v1/sites/unregister', ['site_id' => self::SITE_ID, 'client_secret' => $secret]],
            $gateway->posts[0]
        );
        $this->assertFalse(config::bool('gatewayenabled'));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $viagateway]));
        $this->assertEquals(1, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $direct]));
        $record = $DB->get_record('local_pluginiaconnect_client', ['id' => $clientid]);
        $this->assertEquals(0, $record->status);
        $this->assertNull($record->secrethash);
        $this->assertSame(site_identity::STATE_NONE, site_identity::state());
        $this->assertNull(site_identity::secret());
        $this->assertSame('', site_identity::site_id());
        $this->assertContains(\local_pluginiaconnect\event\site_unregistered::class, $names);
        $this->assertContains(\local_pluginiaconnect\event\connection_revoked::class, $names);
    }

    /**
     * If the gateway cannot be told, the site is already safe and remembers to try again.
     */
    public function test_unregister_when_gateway_is_down(): void {
        global $DB;
        registration::register('LICENCE-KEY-0001', true, true, $this->gateway());
        $clientid = (int) $DB->get_field('local_pluginiaconnect_client', 'id', ['type' => client::TYPE_GATEWAY]);

        [$confirmed, $message] = registration::unregister($this->gateway(201, null, 0));
        $this->assertFalse($confirmed);
        $this->assertSame('unregister_pending', $message);
        $this->assertFalse(config::bool('gatewayenabled'));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_client', 'status', ['id' => $clientid]));
        $this->assertSame(site_identity::STATE_UNREGISTER_PENDING, site_identity::state());
        $this->assertSame(health::WARNING, health::check_gateway(site_identity::state(), null)['status']);

        // Trying again fires no second event and finishes the job.
        $sink = $this->redirectEvents();
        [$confirmed, $message] = registration::unregister($this->gateway());
        $this->assertSame([], $sink->get_events());
        $sink->close();
        $this->assertTrue($confirmed);
        $this->assertSame('unregister_done', $message);
        $this->assertSame(site_identity::STATE_NONE, site_identity::state());
    }

    /**
     * Removing a site that never registered only switches the mode off.
     */
    public function test_unregister_without_registration(): void {
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $gateway = $this->gateway();
        [$confirmed, $message, $revoked] = registration::unregister($gateway);
        $this->assertTrue($confirmed);
        $this->assertSame('unregister_done_local', $message);
        $this->assertSame(0, $revoked);
        $this->assertSame([], $gateway->posts);
        $this->assertFalse(config::bool('gatewayenabled'));
    }

    /**
     * The verification endpoint is not a signing oracle: it answers only inside the window and only to well formed challenges.
     */
    public function test_verify_endpoint(): void {
        $challenge = str_repeat('ab', 32);
        $get = static fn(array $query): \local_pluginiaconnect\local\http\response
            => verify_endpoint::handle(new request('GET', [], '', $query, true, '198.51.100.' . random_int(1, 250)));

        $this->assertSame(404, $get(['challenge' => $challenge])->status, 'No registration in progress');

        $hash = site_identity::open_window();
        $response = $get(['challenge' => $challenge]);
        $this->assertSame(200, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame(
            ['response' => hash_hmac('sha256', site_identity::PREFIX . $challenge, hex2bin($hash))],
            $response->decoded()
        );

        $malformed = [
            [], ['challenge' => ''], ['challenge' => 'abc'], ['challenge' => str_repeat('AB', 32)],
            ['challenge' => str_repeat('zz', 32)], ['challenge' => $challenge . 'ab'],
        ];
        foreach ($malformed as $query) {
            $this->assertSame(404, $get($query)->status, json_encode($query));
        }
        $this->assertSame(405, verify_endpoint::handle(new request('POST', [], '', ['challenge' => $challenge]))->status);

        // The window lasts ten minutes and not a second more.
        // Measured from the stored start, not from the clock: a second may have passed since it opened.
        $now = (int) get_config('local_pluginiaconnect', 'gateway_regstarted');
        $this->assertNotNull(site_identity::answer($challenge, $now + site_identity::WINDOW - 1));
        $this->assertNull(site_identity::answer($challenge, $now + site_identity::WINDOW));
        $this->assertNull(site_identity::answer($challenge, $now - 5), 'A start time in the future is not trusted');

        site_identity::close_window();
        $this->assertSame(404, $get(['challenge' => $challenge])->status);
        $this->assertFalse(get_config('local_pluginiaconnect', 'gateway_regvalue'));
    }

    /**
     * The verification endpoint is limited per address.
     */
    public function test_verify_endpoint_rate_limit(): void {
        // The counter belongs to a clock minute: the requests must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        site_identity::open_window();
        $request = new request('GET', [], '', ['challenge' => str_repeat('ab', 32)], true, '198.51.100.7');
        for ($i = 0; $i < verify_endpoint::IP_LIMIT; $i++) {
            $this->assertSame(200, verify_endpoint::handle($request)->status);
        }
        $limited = verify_endpoint::handle($request);
        $this->assertSame(429, $limited->status);
        $this->assertArrayHasKey('Retry-After', $limited->headers);
    }

    /**
     * The gateway address is a constant that only config.php can replace.
     */
    public function test_gateway_address_cannot_be_changed_from_the_database(): void {
        global $CFG;
        $this->assertSame('https://connect.pluginia.es', http_transport::base_url());
        set_config('gatewayurl', 'https://evil.example', 'local_pluginiaconnect');
        $this->assertSame('https://connect.pluginia.es', http_transport::base_url());

        $CFG->forced_plugin_settings = ['local_pluginiaconnect' => ['gatewayurl' => 'http://localhost:8787']];
        $this->assertSame('http://localhost:8787', http_transport::base_url());
        $CFG->forced_plugin_settings = ['local_pluginiaconnect' => ['gatewayurl' => 'https://x.example/path?a=1']];
        $this->assertSame('https://connect.pluginia.es', http_transport::base_url(), 'Only scheme, host and port are accepted');
        $CFG->forced_plugin_settings = ['local_pluginiaconnect' => ['gatewayurl' => 'javascript:alert(1)']];
        $this->assertSame('https://connect.pluginia.es', http_transport::base_url());

        // A path that is not a plain path never leaves the server.
        $this->assertSame([0, null], (new http_transport())->get('//evil.example/x'));
        $this->assertSame([0, null], (new http_transport())->post_json('/a?b=1', []));
    }

    /**
     * What the page shows before registering names every field and never prints secrets.
     */
    public function test_preview(): void {
        global $CFG;
        $preview = registration::preview();
        $this->assertCount(8, $preview);
        $values = array_column($preview, 'value');
        $this->assertContains(rtrim($CFG->wwwroot, '/'), $values);
        foreach ($preview as $row) {
            $this->assertNotSame('', $row['name']);
            $this->assertStringNotContainsString('[[', $row['name']);
        }
        $this->assertFalse(site_identity::window_open(), 'Looking at the page does not open the verification window');
    }

    /**
     * Diagnostics of the connection with the gateway.
     */
    public function test_health_check_gateway(): void {
        $this->assertSame(health::INFO, health::check_gateway(site_identity::STATE_NONE, null)['status']);
        $this->assertSame(health::WARNING, health::check_gateway(site_identity::STATE_REGISTERED, true)['status']);
        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $this->assertSame(health::OK, health::check_gateway(site_identity::STATE_REGISTERED, true)['status']);
        $this->assertSame(health::ERROR, health::check_gateway(site_identity::STATE_REGISTERED, false)['status']);
        $this->assertSame(health::ERROR, health::check_gateway(site_identity::STATE_REGISTERED, null)['status']);
    }
}
