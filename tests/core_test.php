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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\rate_limiter;
use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;
use local_pluginiaconnect\local\urls;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\config::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\urls::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\http\request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\http\response::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\guardrail\rate_limiter::class)]
/**
 * Tests of the shared building blocks: settings access, URLs, HTTP request and response, rate limiter.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\config
 * @covers     \local_pluginiaconnect\local\urls
 * @covers     \local_pluginiaconnect\local\http\request
 * @covers     \local_pluginiaconnect\local\http\response
 * @covers     \local_pluginiaconnect\local\guardrail\rate_limiter
 */
final class core_test extends \advanced_testcase {
    /**
     * Factory defaults are the secure ones and numeric settings cannot leave their bounds.
     */
    public function test_config_defaults_and_bounds(): void {
        $this->resetAfterTest();
        $off = ['enabled', 'directmode', 'gatewayenabled', 'allowstudentprofile', 'allowadminprofile', 'allowloopback'];
        foreach ($off as $name) {
            $this->assertFalse(config::bool($name), $name . ' must be off after install');
        }
        $this->assertSame(3600, config::int('accesstokenttl'));
        $this->assertSame(48000, config::int('maxresponsechars'));

        set_config('accesstokenttl', 999999, 'local_pluginiaconnect');
        $this->assertSame(3600, config::int('accesstokenttl'));
        set_config('accesstokenttl', 1, 'local_pluginiaconnect');
        $this->assertSame(300, config::int('accesstokenttl'));
        set_config('ratewriteminute', 5000, 'local_pluginiaconnect');
        $this->assertSame(10, config::int('ratewriteminute'));
        set_config('maxpagesize', 500, 'local_pluginiaconnect');
        $this->assertSame(50, config::int('maxpagesize'));

        foreach (array_keys(config::LIMITS) as $name) {
            $this->assertArrayHasKey($name, config::DEFAULTS);
            $this->assertGreaterThanOrEqual(config::LIMITS[$name][0], config::DEFAULTS[$name]);
            $this->assertLessThanOrEqual(config::LIMITS[$name][1], config::DEFAULTS[$name]);
        }

        set_config('excludedcourses', " 4, 5\n5\nabc\n-2\n0", 'local_pluginiaconnect');
        $this->assertSame([4, 5], config::ids('excludedcourses'));
        $this->assertSame(
            ['https://claude.ai/api/mcp/auth_callback', 'https://chatgpt.com/connector_platform_oauth_redirect'],
            config::lines('allowedredirects')
        );
        $this->assertNotContains('https://teams.microsoft.com/api/platform/v1.0/oAuthRedirect', config::lines('allowedredirects'));

        $secret = config::subject_secret();
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $secret);
        $this->assertSame($secret, config::subject_secret());
    }

    /**
     * Issuer and resource are exact, and resource comparison is canonical.
     */
    public function test_urls(): void {
        global $CFG;
        $this->assertSame($CFG->wwwroot . '/local/pluginiaconnect/oauth.php', urls::issuer());
        $this->assertSame($CFG->wwwroot . '/local/pluginiaconnect/mcp.php', urls::resource());
        $this->assertSame(urls::issuer() . '/resource-metadata', urls::resource_metadata());

        $this->assertTrue(urls::is_own_resource(urls::resource()));
        $this->assertTrue(urls::is_own_resource(urls::resource() . '/'));
        $this->assertFalse(urls::is_own_resource(urls::resource() . '/extra'));
        $this->assertFalse(urls::is_own_resource(urls::issuer()));
        $this->assertFalse(urls::is_own_resource(''));
        $this->assertFalse(urls::is_own_resource('javascript:alert(1)'));
        $this->assertFalse(urls::is_own_resource(str_replace('://', '://user:pass@', urls::resource())));

        $this->assertSame('https://example.com/a', urls::canonical('HTTPS://Example.COM:443/a/'));
        $this->assertSame('https://example.com:8443/a', urls::canonical('https://example.com:8443/a'));
        $this->assertSame('https://example.com/a', urls::canonical('https://example.com/a#frag'));
        $this->assertSame('', urls::canonical('ftp://example.com/a'));
        $this->assertSame('', urls::canonical('/relative'));
    }

    /**
     * Request parsing: headers, bearer token, basic credentials, bodies.
     */
    public function test_request(): void {
        $request = new request(
            'post',
            ['Authorization' => 'Bearer pc_at_abc-DEF_123', 'Content-Type' => 'Application/JSON; charset=utf-8'],
            '{"a":{"b":1}}',
            ['x' => 'y'],
            true,
            '203.0.113.5'
        );
        $this->assertSame('POST', $request->method());
        $this->assertSame('pc_at_abc-DEF_123', $request->bearer_token());
        $this->assertNull($request->basic_credentials());
        $this->assertSame('application/json', $request->content_type());
        $this->assertSame(['a' => ['b' => 1]], $request->json());
        $this->assertSame('y', $request->query('x'));
        $this->assertNull($request->query('missing'));
        $this->assertTrue($request->is_https());
        $this->assertSame('203.0.113.5', $request->ip());

        $basic = new request(
            'POST',
            ['authorization' => 'Basic ' . base64_encode('my%20client:s%3Acret')],
            'grant_type=refresh_token&a[]=1&b=%C3%A9'
        );
        $this->assertSame(['my client', 's:cret'], $basic->basic_credentials());
        $this->assertNull($basic->bearer_token());
        $this->assertSame(['grant_type' => 'refresh_token', 'b' => 'é'], $basic->form());

        $this->assertNull((new request('POST', ['authorization' => 'Bearer two words']))->bearer_token());
        $this->assertNull((new request('POST', ['authorization' => 'Basic !!!']))->basic_credentials());
        $this->assertNull((new request('POST', ['authorization' => 'Basic ' . base64_encode('nocolon')]))->basic_credentials());
        $this->assertNull((new request('POST', [], '{broken'))->json());
        $this->assertNull((new request('POST', [], "\xff\xfe"))->json());
        $this->assertNull((new request('POST', [], ''))->json());

        $big = new request('POST', [], str_repeat('a', request::MAX_BODY + 1));
        $this->assertTrue($big->body_too_large());
        $this->assertSame('', $big->body());
        $this->assertFalse((new request('POST', [], str_repeat('a', request::MAX_BODY)))->body_too_large());
    }

    /**
     * Responses are never cacheable and OAuth errors have the RFC 6749 shape.
     */
    public function test_response(): void {
        $response = response::json(200, ['url' => 'https://example.com/a', 'name' => 'Pérez']);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $this->assertStringContainsString('https://example.com/a', $response->body);
        $this->assertStringContainsString('Pérez', $response->body);

        $error = response::oauth_error(400, 'invalid_grant', 'The code expired.', ['X-Test' => '1']);
        $this->assertSame(400, $error->status);
        $this->assertSame(['error' => 'invalid_grant', 'error_description' => 'The code expired.'], $error->decoded());
        $this->assertSame('1', $error->headers['X-Test']);

        $empty = response::no_content(202);
        $this->assertNull($empty->body);
        $this->assertNull($empty->decoded());
    }

    /**
     * Counters allow up to the limit, per subject and per bucket, in both modes.
     */
    public function test_rate_limiter(): void {
        $this->resetAfterTest();
        foreach ([false, true] as $strict) {
            $subject = 'grant-' . ($strict ? 's' : 'r');
            for ($i = 1; $i <= 3; $i++) {
                $this->assertTrue(rate_limiter::hit('unit', $subject, 3, 3600, $strict), "hit $i");
            }
            $this->assertFalse(rate_limiter::hit('unit', $subject, 3, 3600, $strict));
            $this->assertSame(4, rate_limiter::current('unit', $subject, 3600));
            $this->assertTrue(rate_limiter::hit('unit', $subject . '-other', 3, 3600, $strict));
            $this->assertTrue(rate_limiter::hit('other', $subject, 3, 3600, $strict));
        }
        $retry = rate_limiter::retry_after(60);
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(60, $retry);
    }
}
