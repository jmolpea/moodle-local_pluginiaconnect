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

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\selfcheck;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\health::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\http\selfcheck::class)]
/**
 * Tests of the diagnostics checks and of the loop-back probe.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\health
 * @covers     \local_pluginiaconnect\local\http\selfcheck
 */
final class health_test extends \advanced_testcase {
    /**
     * Asserts the shape and the status of a result.
     *
     * @param string $status Expected status.
     * @param array $result Result of a check.
     * @param bool $remedy Whether a remedy is expected.
     */
    private function assert_result(string $status, array $result, bool $remedy): void {
        $this->assertSame(['id', 'status', 'name', 'message', 'remedy'], array_keys($result));
        $this->assertSame($status, $result['status'], $result['id']);
        $this->assertNotEmpty($result['name']);
        $this->assertNotEmpty($result['message']);
        $this->assertStringNotContainsString('[[', $result['name'] . $result['message'] . $result['remedy']);
        $this->assertSame($remedy, $result['remedy'] !== '', $result['id']);
    }

    /**
     * The connector state check follows the switches.
     */
    public function test_check_state(): void {
        $this->resetAfterTest();
        $this->assert_result(health::WARNING, health::check_state(), true);
        set_config('enabled', 1, 'local_pluginiaconnect');
        $this->assert_result(health::WARNING, health::check_state(), true);
        set_config('directmode', 1, 'local_pluginiaconnect');
        $this->assert_result(health::OK, health::check_state(), false);
    }

    /**
     * HTTPS is required, except on a local development site with developer debugging.
     */
    public function test_check_https(): void {
        $this->assert_result(health::OK, health::check_https('https://campus.example.org', DEBUG_NONE), false);
        $this->assert_result(health::ERROR, health::check_https('http://campus.example.org', DEBUG_DEVELOPER), true);
        $this->assert_result(health::ERROR, health::check_https('http://localhost:8080', DEBUG_NONE), true);
        $this->assert_result(health::WARNING, health::check_https('http://localhost:8080', DEBUG_DEVELOPER), true);
        $this->assert_result(health::ERROR, health::check_https('http://localhost.evil.example', DEBUG_DEVELOPER), true);
    }

    /**
     * Sodium, header and path checks.
     */
    public function test_simple_checks(): void {
        $this->assert_result(health::OK, health::check_sodium(true), false);
        $this->assert_result(health::ERROR, health::check_sodium(false), true);

        $this->assert_result(health::OK, health::check_authorization_header(['authorization' => true]), false);
        $this->assert_result(health::ERROR, health::check_authorization_header(['authorization' => false]), true);
        $this->assert_result(health::WARNING, health::check_authorization_header(null), true);

        $this->assert_result(health::OK, health::check_pathinfo(['pathinfo' => true]), false);
        $this->assert_result(health::ERROR, health::check_pathinfo([]), true);
        $this->assert_result(health::WARNING, health::check_pathinfo(null), true);

        $this->assert_result(health::OK, health::check_wellknown(true), false);
        $this->assert_result(health::INFO, health::check_wellknown(false), true);
        $this->assert_result(health::INFO, health::check_wellknown(null), true);
    }

    /**
     * A store local to one web server is a warning; a shared one is fine.
     */
    public function test_check_cache(): void {
        $this->assert_result(health::OK, health::check_cache(['cachestore_redis']), false);
        $this->assert_result(health::WARNING, health::check_cache(['cachestore_file']), true);
        $this->assert_result(health::WARNING, health::check_cache(['cachestore_redis', 'cachestore_static']), true);
        $this->assert_result(health::WARNING, health::check_cache([]), true);
    }

    /**
     * Cron must have run in the last ten minutes.
     */
    public function test_check_cron(): void {
        $now = 1800000000;
        $this->assert_result(health::ERROR, health::check_cron(0, $now), true);
        $this->assert_result(health::ERROR, health::check_cron($now - health::CRON_MAX_AGE - 1, $now), true);
        $this->assert_result(health::OK, health::check_cron($now - health::CRON_MAX_AGE, $now), false);
        $this->assert_result(health::OK, health::check_cron($now - 30, $now), false);
    }

    /**
     * A clock set before this version existed is an error.
     */
    public function test_check_clock(): void {
        $this->assert_result(health::ERROR, health::check_clock(1000000000), true);
        $this->assert_result(health::OK, health::check_clock(time()), false);
    }

    /**
     * The whole run returns one well formed result per check, whatever the environment.
     */
    public function test_run(): void {
        global $CFG;
        $this->resetAfterTest();
        require_once($CFG->libdir . '/filelib.php');
        // No network in tests: the two loop-back requests get canned answers (last queued is used first).
        \curl::mock_response(json_encode(['issuer' => 'https://other.example']));
        \curl::mock_response(json_encode(['authorization' => true, 'pathinfo' => true]));
        $results = health::run();
        $ids = array_column($results, 'id');
        $this->assertSame(
            ['state', 'https', 'sodium', 'authheader', 'pathinfo', 'wellknown', 'cache', 'cron', 'clock', 'proxy', 'gateway'],
            $ids
        );
        foreach ($results as $result) {
            $this->assertContains($result['status'], [health::OK, health::INFO, health::WARNING, health::ERROR]);
            $this->assertNotEmpty($result['message']);
        }
        // The one-time value never outlives the run.
        $this->assertFalse(get_config('local_pluginiaconnect', 'selfcheck'));
    }

    /**
     * The probe answers only to the one-time value, once, and reports what it saw.
     */
    public function test_selfcheck_probe(): void {
        $this->resetAfterTest();
        $build = static fn(array $headers): request => new request('GET', $headers);

        // Not armed: nothing to see.
        $response = selfcheck::handle($build([selfcheck::HEADER => 'anything']), selfcheck::PATH);
        $this->assertSame(404, $response->status);

        $value = selfcheck::arm();
        $this->assertStringNotContainsString($value, (string) get_config('local_pluginiaconnect', 'selfcheck'));

        // Wrong or missing value.
        $this->assertSame(404, selfcheck::handle($build([selfcheck::HEADER => 'wrong']), selfcheck::PATH)->status);
        $this->assertSame(404, selfcheck::handle($build([]), selfcheck::PATH)->status);

        // Right value, with the header and the path arriving.
        $response = selfcheck::handle($build([
            selfcheck::HEADER => $value,
            'Authorization' => 'Bearer ' . selfcheck::BEARER,
        ]), selfcheck::PATH);
        $this->assertSame(200, $response->status);
        $this->assertSame(['authorization' => true, 'pathinfo' => true], $response->decoded());

        // One use only.
        $this->assertSame(404, selfcheck::handle($build([selfcheck::HEADER => $value]), selfcheck::PATH)->status);

        // Right value, but the web server dropped the header and the path.
        $value = selfcheck::arm();
        $response = selfcheck::handle($build([selfcheck::HEADER => $value]), '');
        $this->assertSame(['authorization' => false, 'pathinfo' => false], $response->decoded());
    }

    /**
     * An expired one-time value is refused.
     */
    public function test_selfcheck_expiry(): void {
        $this->resetAfterTest();
        $value = selfcheck::arm();
        set_config('selfcheck', hash('sha256', $value) . ':' . (time() - 1), 'local_pluginiaconnect');
        $response = selfcheck::handle(new request('GET', [selfcheck::HEADER => $value]), selfcheck::PATH);
        $this->assertSame(404, $response->status);
    }
}
