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
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\code_service;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\oauth\revoke_endpoint;
use local_pluginiaconnect\local\oauth\token_endpoint;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\urls;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/oauth_helper.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\code_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\token_service::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\token_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\revoke_endpoint::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\grant_context::class)]
/**
 * Authorization codes, tokens, refresh rotation, revocation and the token and revocation endpoints.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_pluginiaconnect\local\oauth\code_service
 * @covers \local_pluginiaconnect\local\oauth\token_service
 * @covers \local_pluginiaconnect\local\oauth\token_endpoint
 * @covers \local_pluginiaconnect\local\oauth\revoke_endpoint
 * @covers \local_pluginiaconnect\local\oauth\grant_context
 */
final class oauth_token_test extends \advanced_testcase {
    use oauth_helper;

    /** @var client Public client used by most tests. */
    private client $client;
    /** @var \stdClass Teacher who connects. */
    private \stdClass $user;

    /**
     * Common set-up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();
        [$this->client] = $this->make_client();
        $this->user = $this->make_teacher();
    }

    /**
     * Expects a call to fail with invalid_grant.
     *
     * @param callable $call Code to run.
     * @param string $error Expected error code.
     * @return void
     */
    private function assert_fails(callable $call, string $error = 'invalid_grant'): void {
        try {
            $call();
            $this->fail('The call should have failed with ' . $error . '.');
        } catch (oauth_exception $e) {
            $this->assertSame($error, $e->error);
        }
    }

    /**
     * Redeems a code with the given overrides of the correct values.
     *
     * @param string $code Code.
     * @param string $verifier Verifier.
     * @param array $override Any of client, redirect, resource, verifier.
     * @return array Token response.
     */
    private function exchange(string $code, string $verifier, array $override = []): array {
        return code_service::exchange(
            $code,
            $override['client'] ?? $this->client,
            $override['redirect'] ?? self::$claude,
            $override['resource'] ?? urls::resource(),
            $override['verifier'] ?? $verifier
        );
    }

    /**
     * A correct exchange issues tokens, creates the grant and stores nothing in clear.
     */
    public function test_code_exchange_success(): void {
        global $DB;
        $sink = $this->redirectEvents();
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $this->assertStringStartsWith('pc_ac_', $code);
        $record = $DB->get_record('local_pluginiaconnect_authcode', []);
        $this->assertSame(hash('sha256', $code), $record->codehash);
        $this->assertEqualsWithDelta(time() + 60, (int) $record->timeexpires, 2);

        $tokens = $this->exchange($code, $verifier);
        $this->assertStringStartsWith('pc_at_', $tokens['access_token']);
        $this->assertStringStartsWith('pc_rt_', $tokens['refresh_token']);
        $this->assertSame('Bearer', $tokens['token_type']);
        $this->assertEqualsWithDelta(3600, $tokens['expires_in'], 2);
        $this->assertSame('profile courses.read offline_access', $tokens['scope']);
        $this->assertSame('teacher', $tokens['pc_profile']);
        $expected = hash_hmac('sha256', $this->user->id . ':' . $this->client->id, local\config::subject_secret());
        $this->assertSame($expected, $tokens['pc_sub']);
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $tokens['pc_sub']);

        $grant = $DB->get_record('local_pluginiaconnect_grant', [], '*', MUST_EXIST);
        $this->assertEquals($this->user->id, $grant->userid);
        $this->assertEquals(1, $grant->status);
        $this->assertSame(urls::resource(), $grant->resource);
        $this->assertEqualsWithDelta(time() + 15552000, (int) $grant->timeabsoluteexpiry, 2);
        $this->assertEquals($grant->id, $DB->get_field('local_pluginiaconnect_authcode', 'grantid', ['id' => $record->id]));

        // Nothing readable is stored: only hashes.
        foreach ($DB->get_records('local_pluginiaconnect_token') as $row) {
            $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $row->tokenhash);
            $this->assertNull($row->gracedata);
        }
        $accesshash = hash('sha256', $tokens['access_token']);
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_token', ['tokenhash' => $accesshash]));

        // The event, when its class is installed, never carries token values.
        foreach ($sink->get_events() as $event) {
            $this->assertStringNotContainsString($tokens['access_token'], json_encode($event->get_data()));
            $this->assertStringNotContainsString($tokens['refresh_token'], json_encode($event->get_data()));
        }
    }

    /**
     * Without the offline_access scope no refresh token is issued.
     */
    public function test_no_refresh_token_without_offline_access(): void {
        $tokens = $this->issue_tokens($this->client, $this->user, ['profile', 'courses.read']);
        $this->assertArrayNotHasKey('refresh_token', $tokens);
        $this->assertNotNull(token_service::authenticate($tokens['access_token']));
    }

    /**
     * Wrong client, redirect URI, resource or verifier: invalid_grant, and the code stays usable by its owner.
     */
    public function test_code_exchange_mismatches(): void {
        [$other] = $this->make_client();
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);

        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['client' => $other]));
        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['redirect' => self::$claude . '/']));
        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['resource' => 'https://other.example/mcp']));
        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['resource' => '']));
        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['verifier' => $this->make_verifier()]));
        $this->assert_fails(fn() => $this->exchange($code, $verifier, ['verifier' => '']));
        $this->assert_fails(fn() => $this->exchange('pc_ac_unknown', $verifier));
        $this->assert_fails(fn() => $this->exchange('not-a-code', $verifier));

        $this->assertArrayHasKey('access_token', $this->exchange($code, $verifier));
    }

    /**
     * An expired code is refused.
     */
    public function test_code_expired(): void {
        global $DB;
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $DB->set_field('local_pluginiaconnect_authcode', 'timeexpires', time() - 1, []);
        $this->assert_fails(fn() => $this->exchange($code, $verifier));
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_grant'));
    }

    /**
     * Using a code twice fails and revokes the grant created with it.
     */
    public function test_code_reuse_revokes_the_grant(): void {
        global $DB;
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $tokens = $this->exchange($code, $verifier);
        $this->assertNotNull(token_service::authenticate($tokens['access_token']));

        $this->assert_fails(fn() => $this->exchange($code, $verifier));
        $grant = $DB->get_record('local_pluginiaconnect_grant', [], '*', MUST_EXIST);
        $this->assertEquals(0, $grant->status);
        $this->assertSame('reuse', $grant->revokedby);
        $this->assertNull(token_service::authenticate($tokens['access_token']));
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $this->client));
    }

    /**
     * The user is checked again when the code is redeemed.
     */
    public function test_code_exchange_rechecks_the_user(): void {
        global $DB;
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $this->assert_fails(fn() => $this->exchange($code, $verifier));

        // A user who lost the role that made them eligible for the profile.
        $student = $this->getDataGenerator()->create_user();
        $code = $this->make_code($this->client, $student, $verifier);
        $this->assert_fails(fn() => $this->exchange($code, $verifier));
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_grant'));
    }

    /**
     * Refresh rotates both tokens and invalidates the previous access token.
     */
    public function test_refresh_rotation(): void {
        global $DB;
        $first = $this->issue_tokens($this->client, $this->user);
        $second = token_service::refresh($first['refresh_token'], $this->client, urls::resource());

        $this->assertNotSame($first['access_token'], $second['access_token']);
        $this->assertNotSame($first['refresh_token'], $second['refresh_token']);
        $this->assertSame($first['scope'], $second['scope']);
        $this->assertSame($first['pc_sub'], $second['pc_sub']);
        $this->assertNull(token_service::authenticate($first['access_token']));
        $this->assertNotNull(token_service::authenticate($second['access_token']));

        $old = $DB->get_record('local_pluginiaconnect_token', ['tokenhash' => hash('sha256', $first['refresh_token'])]);
        $this->assertNotEmpty($old->timeused);
        $this->assertNotEmpty($old->gracedata);
        // The grace data is not readable without the old token.
        $this->assertStringNotContainsString($second['access_token'], (string) $old->gracedata);
        $this->assertStringNotContainsString($second['access_token'], (string) base64_decode($old->gracedata));
        $new = $DB->get_record('local_pluginiaconnect_token', ['tokenhash' => hash('sha256', $second['refresh_token'])]);
        $this->assertEquals($old->id, $new->parentid);

        // The chain keeps working.
        $third = token_service::refresh($second['refresh_token'], $this->client);
        $this->assertNotNull(token_service::authenticate($third['access_token']));
    }

    /**
     * A refresh token presented again inside the grace window returns the same pair.
     */
    public function test_refresh_reuse_inside_grace_returns_same_pair(): void {
        global $DB;
        $first = $this->issue_tokens($this->client, $this->user);
        $second = token_service::refresh($first['refresh_token'], $this->client);
        $again = token_service::refresh($first['refresh_token'], $this->client);

        $this->assertSame($second['access_token'], $again['access_token']);
        $this->assertSame($second['refresh_token'], $again['refresh_token']);
        $this->assertEquals(1, $DB->get_field('local_pluginiaconnect_grant', 'status', []));
        $this->assertNotNull(token_service::authenticate($again['access_token']));
        // No extra tokens were created by the replay.
        $this->assertSame(4, $DB->count_records('local_pluginiaconnect_token'));

        // Another client cannot use the grace window.
        [$other] = $this->make_client();
        $this->assert_fails(fn() => token_service::refresh($first['refresh_token'], $other));
    }

    /**
     * A refresh token presented again after the grace window revokes the whole grant.
     */
    public function test_refresh_reuse_outside_grace_revokes_everything(): void {
        global $DB;
        $sink = $this->redirectEvents();
        $first = $this->issue_tokens($this->client, $this->user);
        $second = token_service::refresh($first['refresh_token'], $this->client);
        $DB->set_field(
            'local_pluginiaconnect_token',
            'timeused',
            time() - token_service::GRACE_SECONDS - 1,
            ['tokenhash' => hash('sha256', $first['refresh_token'])]
        );

        $this->assert_fails(fn() => token_service::refresh($first['refresh_token'], $this->client));
        $grant = $DB->get_record('local_pluginiaconnect_grant', [], '*', MUST_EXIST);
        $this->assertEquals(0, $grant->status);
        $this->assertSame('reuse', $grant->revokedby);
        $this->assertNull(token_service::authenticate($second['access_token']));
        $this->assert_fails(fn() => token_service::refresh($second['refresh_token'], $this->client));
        $this->assertSame(0, $DB->count_records_select('local_pluginiaconnect_token', 'gracedata IS NOT NULL'));

        if (class_exists('\local_pluginiaconnect\event\token_reuse_detected')) {
            $names = array_map(static fn($event) => get_class($event), $sink->get_events());
            $this->assertContains('local_pluginiaconnect\event\token_reuse_detected', $names);
        }
    }

    /**
     * Inside the window but with the grace data gone, a replay is treated as reuse.
     */
    public function test_refresh_reuse_without_grace_data_revokes(): void {
        global $DB;
        $first = $this->issue_tokens($this->client, $this->user);
        token_service::refresh($first['refresh_token'], $this->client);
        $DB->set_field('local_pluginiaconnect_token', 'gracedata', 'not-valid-data', [
            'tokenhash' => hash('sha256', $first['refresh_token']),
        ]);
        $this->assert_fails(fn() => token_service::refresh($first['refresh_token'], $this->client));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', []));
    }

    /**
     * Idle expiry, absolute expiry, wrong client, wrong resource and unknown tokens.
     */
    public function test_refresh_failures(): void {
        global $DB;
        [$other] = $this->make_client();

        $tokens = $this->issue_tokens($this->client, $this->user);
        $this->assert_fails(fn() => token_service::refresh('pc_rt_unknown', $this->client));
        $this->assert_fails(fn() => token_service::refresh($tokens['access_token'], $this->client));
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $other));
        $this->assert_fails(
            fn() => token_service::refresh($tokens['refresh_token'], $this->client, 'https://other.example/mcp'),
            'invalid_target'
        );
        // None of the failures above consumed the token.
        $this->assertEquals(1, $DB->get_field('local_pluginiaconnect_grant', 'status', []));

        // Idle expiry: the refresh token itself ran out.
        $hash = hash('sha256', $tokens['refresh_token']);
        $DB->set_field('local_pluginiaconnect_token', 'timeexpires', time() - 1, ['tokenhash' => $hash]);
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $this->client));

        // Absolute expiry: the grant needs a new consent.
        $DB->delete_records('local_pluginiaconnect_token');
        $DB->delete_records('local_pluginiaconnect_grant');
        $tokens = $this->issue_tokens($this->client, $this->user);
        $DB->set_field('local_pluginiaconnect_grant', 'timeabsoluteexpiry', time() - 1, []);
        $this->assertNull(token_service::authenticate($tokens['access_token']));
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $this->client));
        $grant = $DB->get_record('local_pluginiaconnect_grant', [], '*', MUST_EXIST);
        $this->assertEquals(0, $grant->status);
        $this->assertSame('system', $grant->revokedby);

        // A suspended user cannot refresh.
        $DB->delete_records('local_pluginiaconnect_token');
        $DB->delete_records('local_pluginiaconnect_grant');
        $tokens = $this->issue_tokens($this->client, $this->user);
        $DB->set_field('user', 'suspended', 1, ['id' => $this->user->id]);
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $this->client));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', []));
    }

    /**
     * No token outlives the grant: lifetimes are capped by the absolute expiry and by the settings.
     */
    public function test_token_lifetimes(): void {
        global $DB;
        set_config('accesstokenttl', 600, 'local_pluginiaconnect');
        $tokens = $this->issue_tokens($this->client, $this->user);
        $this->assertEqualsWithDelta(600, $tokens['expires_in'], 2);

        // The setting cannot raise the lifetime above one hour.
        set_config('accesstokenttl', 999999, 'local_pluginiaconnect');
        $DB->set_field('local_pluginiaconnect_grant', 'timeabsoluteexpiry', time() + 120, []);
        $next = token_service::refresh($tokens['refresh_token'], $this->client);
        $this->assertLessThanOrEqual(120, $next['expires_in']);
        $limit = (int) $DB->get_field('local_pluginiaconnect_grant', 'timeabsoluteexpiry', []);
        foreach ($DB->get_records('local_pluginiaconnect_token', ['timeused' => null, 'timerevoked' => null]) as $row) {
            $this->assertLessThanOrEqual($limit, (int) $row->timeexpires);
        }
    }

    /**
     * Access token authentication: context contents, expiry, revocation, blocked client, last used and address.
     */
    public function test_authenticate(): void {
        global $DB;
        $tokens = $this->issue_tokens($this->client, $this->user, ['profile', 'courses.read', 'grades.read', 'offline_access']);
        $context = token_service::authenticate($tokens['access_token'], '203.0.113.9');
        $this->assertNotNull($context);
        $this->assertEquals($this->user->id, $context->userid);
        $this->assertSame($this->client->id, $context->clientid);
        $this->assertSame(client::TYPE_DCR, $context->clienttype);
        $this->assertSame('teacher', $context->profile);
        $this->assertSame(['profile', 'courses.read', 'grades.read', 'offline_access'], $context->scopes);
        $this->assertTrue($context->has_scope('grades.read'));
        $this->assertFalse($context->has_scope('grades.write'));
        $this->assertSame(urls::resource(), $context->resource);
        $this->assertNull($context->actorid);

        $row = $DB->get_record('local_pluginiaconnect_token', ['id' => $context->tokenid]);
        $this->assertSame('203.0.113.9', $row->lastip);
        $this->assertEqualsWithDelta(time(), (int) $row->timelastused, 2);
        // A second use within a minute does not write again.
        $DB->set_field('local_pluginiaconnect_token', 'lastip', 'kept', ['id' => $context->tokenid]);
        token_service::authenticate($tokens['access_token'], '203.0.113.99');
        $this->assertSame('kept', $DB->get_field('local_pluginiaconnect_token', 'lastip', ['id' => $context->tokenid]));

        // Not an access token, unknown, or of the wrong kind.
        $this->assertNull(token_service::authenticate(''));
        $this->assertNull(token_service::authenticate('pc_at_unknown'));
        $this->assertNull(token_service::authenticate($tokens['refresh_token']));
        $this->assertNull(token_service::authenticate(str_replace('pc_at_', '', $tokens['access_token'])));
        $this->assertNull(token_service::authenticate($tokens['access_token'] . str_repeat('a', 200)));

        // Blocked client.
        $DB->set_field('local_pluginiaconnect_client', 'status', 0, ['id' => $this->client->id]);
        $this->assertNull(token_service::authenticate($tokens['access_token']));
        $DB->set_field('local_pluginiaconnect_client', 'status', 1, ['id' => $this->client->id]);
        $this->assertNotNull(token_service::authenticate($tokens['access_token']));

        // Expired token.
        $DB->set_field('local_pluginiaconnect_token', 'timeexpires', time() - 1, ['id' => $context->tokenid]);
        $this->assertNull(token_service::authenticate($tokens['access_token']));
    }

    /**
     * The address is not stored when the site does not log addresses.
     */
    public function test_authenticate_without_ip_logging(): void {
        global $DB;
        set_config('logip', 0, 'local_pluginiaconnect');
        $tokens = $this->issue_tokens($this->client, $this->user);
        $context = token_service::authenticate($tokens['access_token'], '203.0.113.9');
        $this->assertNull($DB->get_field('local_pluginiaconnect_token', 'lastip', ['id' => $context->tokenid]));
    }

    /**
     * Revocation is immediate and reaches every token; the bulk variants and the user data deletion work.
     */
    public function test_revocation(): void {
        global $DB;
        $sink = $this->redirectEvents();
        $otheruser = $this->make_teacher();
        [$otherclient] = $this->make_client();
        $a = $this->issue_tokens($this->client, $this->user);
        $b = $this->issue_tokens($otherclient, $this->user);
        $c = $this->issue_tokens($this->client, $otheruser);

        $grantid = token_service::authenticate($a['access_token'])->grantid;
        token_service::revoke_grant($grantid, 'user');
        token_service::revoke_grant($grantid, 'admin');
        token_service::revoke_grant(999999, 'admin');
        $this->assertNull(token_service::authenticate($a['access_token']));
        $this->assert_fails(fn() => token_service::refresh($a['refresh_token'], $this->client));
        $this->assertSame('user', $DB->get_field('local_pluginiaconnect_grant', 'revokedby', ['id' => $grantid]));
        $this->assertNotNull(token_service::authenticate($b['access_token']));

        if (class_exists('\local_pluginiaconnect\event\connection_revoked')) {
            $revoked = array_filter(
                $sink->get_events(),
                static fn($event) => $event instanceof \local_pluginiaconnect\event\connection_revoked
            );
            $this->assertCount(1, $revoked, 'Revoking twice triggers one event');
        }

        $this->assertSame(1, token_service::revoke_user_grants((int) $this->user->id, 'system'));
        $this->assertNull(token_service::authenticate($b['access_token']));
        $this->assertNotNull(token_service::authenticate($c['access_token']));

        $this->assertSame(1, token_service::revoke_client_grants($this->client->id, 'admin'));
        $this->assertNull(token_service::authenticate($c['access_token']));

        $d = $this->issue_tokens($this->client, $this->user);
        $e = $this->issue_tokens($otherclient, $otheruser);
        $this->assertSame(2, token_service::revoke_all('admin'));
        $this->assertNull(token_service::authenticate($d['access_token']));
        $this->assertNull(token_service::authenticate($e['access_token']));

        token_service::delete_user_data((int) $this->user->id);
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_grant', ['userid' => $this->user->id]));
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_authcode', ['userid' => $this->user->id]));
        $this->assertGreaterThan(0, $DB->count_records('local_pluginiaconnect_grant', ['userid' => $otheruser->id]));
        $remaining = $DB->get_fieldset_select('local_pluginiaconnect_grant', 'id', '1 = 1');
        foreach ($DB->get_records('local_pluginiaconnect_token') as $row) {
            $this->assertContains($row->grantid, $remaining);
        }
    }

    /**
     * Housekeeping removes what expired and keeps what is alive.
     */
    public function test_cleanup_expired(): void {
        global $DB;
        $alive = $this->issue_tokens($this->client, $this->user);
        $otheruser = $this->make_teacher();
        $expiring = $this->issue_tokens($this->client, $otheruser);
        $rotated = token_service::refresh($expiring['refresh_token'], $this->client);
        $expiringgrant = token_service::authenticate($rotated['access_token'])->grantid;

        // An old code, grace data past its window and a grant past its absolute lifetime.
        $this->make_code($this->client, $this->user, $this->make_verifier());
        $DB->set_field_select('local_pluginiaconnect_authcode', 'timeexpires', time() - 2 * HOURSECS, 'timeused IS NULL');
        $DB->set_field_select('local_pluginiaconnect_token', 'timeused', time() - 120, 'timeused IS NOT NULL');
        $DB->set_field('local_pluginiaconnect_grant', 'timeabsoluteexpiry', time() - 1, ['id' => $expiringgrant]);

        token_service::cleanup_expired();
        $this->assertSame(0, $DB->count_records_select('local_pluginiaconnect_authcode', 'timeused IS NULL'));
        $this->assertSame(0, $DB->count_records_select('local_pluginiaconnect_token', 'gracedata IS NOT NULL'));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $expiringgrant]));
        $this->assertSame('system', $DB->get_field('local_pluginiaconnect_grant', 'revokedby', ['id' => $expiringgrant]));
        $this->assertNotNull(token_service::authenticate($alive['access_token']));

        // Expired tokens go; revoked grants go after the retention period, with their tokens and codes.
        $DB->set_field('local_pluginiaconnect_grant', 'timerevoked', time() - 31 * DAYSECS, ['id' => $expiringgrant]);
        token_service::cleanup_expired();
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_grant', ['id' => $expiringgrant]));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_token', ['grantid' => $expiringgrant]));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_authcode', ['grantid' => $expiringgrant]));
        $this->assertNotNull(token_service::authenticate($alive['access_token']));

        $DB->set_field('local_pluginiaconnect_token', 'timeexpires', time() - 1, []);
        token_service::cleanup_expired();
        $this->assertSame(0, $DB->count_records('local_pluginiaconnect_token'));
    }

    /**
     * Token endpoint: the whole flow over the HTTP handler, with a public client.
     */
    public function test_token_endpoint_flow(): void {
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $response = token_endpoint::handle($this->form_request([
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::$claude,
            'client_id' => $this->client->clientid,
            'code_verifier' => $verifier,
            'resource' => urls::resource(),
        ]));
        $this->assertSame(200, $response->status);
        $this->assertSame('no-store', $response->headers['Cache-Control']);
        $this->assertSame('nosniff', $response->headers['X-Content-Type-Options']);
        $tokens = $response->decoded();
        $this->assertNotNull(token_service::authenticate($tokens['access_token']));

        $response = token_endpoint::handle($this->form_request([
            'grant_type' => 'refresh_token',
            'refresh_token' => $tokens['refresh_token'],
            'client_id' => $this->client->clientid,
            'resource' => urls::resource(),
        ]));
        $this->assertSame(200, $response->status);
        $this->assertNotSame($tokens['access_token'], $response->decoded()['access_token']);
    }

    /**
     * Token endpoint error codes follow RFC 6749 and reveal nothing internal.
     */
    public function test_token_endpoint_errors(): void {
        $verifier = $this->make_verifier();
        $code = $this->make_code($this->client, $this->user, $verifier);
        $base = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::$claude,
            'client_id' => $this->client->clientid,
            'code_verifier' => $verifier,
            'resource' => urls::resource(),
        ];
        $result = static function ($response): array {
            return [$response->status, $response->decoded()['error']];
        };
        $ip = 1;
        $send = fn(array $form) => token_endpoint::handle($this->form_request($form, [], '198.51.100.' . $ip++));

        $this->assertSame(405, token_endpoint::handle(new request('GET'))->status);
        $json = new request('POST', ['Content-Type' => 'application/json'], json_encode($base));
        $this->assertSame(415, token_endpoint::handle($json)->status);

        $this->assertSame([401, 'invalid_client'], $result($send(['client_id' => 'nobody'] + $base)));
        $this->assertSame([401, 'invalid_client'], $result($send(array_diff_key($base, ['client_id' => 1]))));
        $this->assertSame([400, 'unsupported_grant_type'], $result($send(['grant_type' => 'password'] + $base)));
        $this->assertSame([400, 'unsupported_grant_type'], $result($send(['grant_type' => 'client_credentials'] + $base)));
        $this->assertSame([400, 'unsupported_grant_type'], $result($send(array_diff_key($base, ['grant_type' => 1]))));
        foreach (['code', 'redirect_uri', 'code_verifier', 'resource'] as $missing) {
            $this->assertSame([400, 'invalid_request'], $result($send(array_diff_key($base, [$missing => 1]))), $missing);
        }
        $this->assertSame([400, 'invalid_grant'], $result($send(['code_verifier' => $this->make_verifier()] + $base)));
        $this->assertSame([400, 'invalid_grant'], $result($send(['resource' => 'https://other.example/mcp'] + $base)));
        $this->assertSame([400, 'invalid_grant'], $result($send(['code' => 'pc_ac_wrong'] + $base)));
        $refresh = ['grant_type' => 'refresh_token', 'client_id' => $this->client->clientid];
        $this->assertSame([400, 'invalid_request'], $result($send($refresh)));
        // An invalid refresh token must be invalid_grant, never invalid_request or a custom code.
        $response = $send($refresh + ['refresh_token' => 'pc_rt_wrong']);
        $this->assertSame([400, 'invalid_grant'], $result($response));
        $this->assertArrayHasKey('error_description', $response->decoded());
        $this->assertStringNotContainsString('local_pluginiaconnect', $response->body);
        $this->assertStringNotContainsString('.php', $response->body);

        // After all those failures the code still works for its owner.
        $this->assertSame(200, $send($base)->status);
    }

    /**
     * A confidential client must authenticate at the token endpoint.
     */
    public function test_token_endpoint_confidential_client(): void {
        [$client, $secret] = $this->make_client(client::AUTH_POST);
        $verifier = $this->make_verifier();
        $code = $this->make_code($client, $this->user, $verifier);
        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => self::$claude,
            'client_id' => $client->clientid,
            'code_verifier' => $verifier,
            'resource' => urls::resource(),
        ];
        $this->assertSame(401, token_endpoint::handle($this->form_request($form))->status);
        $this->assertSame(401, token_endpoint::handle($this->form_request($form + ['client_secret' => 'wrong']))->status);
        $this->assertSame(200, token_endpoint::handle($this->form_request($form + ['client_secret' => $secret]))->status);
    }

    /**
     * Failed client authentications are limited per client identifier and address; refused grants are not.
     */
    public function test_token_endpoint_rate_limit(): void {
        [$confidential, $secret] = $this->make_client(client::AUTH_POST);
        $form = [
            'grant_type' => 'refresh_token', 'refresh_token' => 'pc_rt_wrong', 'client_id' => $confidential->clientid,
            'client_secret' => 'wrong',
        ];
        // The counter belongs to a clock minute: the requests must not straddle two of them.
        if (time() % MINSECS >= 50) {
            sleep(MINSECS - time() % MINSECS);
        }
        // Failed authentications are counted per client and address.
        for ($i = 0; $i < token_endpoint::CLIENT_LIMIT; $i++) {
            $this->assertSame(401, token_endpoint::handle($this->form_request($form, [], '192.0.2.200'))->status);
        }
        $limited = token_endpoint::handle($this->form_request($form, [], '192.0.2.200'));
        $this->assertSame(429, $limited->status);
        $this->assertArrayHasKey('Retry-After', $limited->headers);
        // The same client from another address is not affected, and neither is another client from this one.
        $this->assertSame(401, token_endpoint::handle($this->form_request($form, [], '192.0.2.201'))->status);
        $form['client_id'] = $this->client->clientid;
        unset($form['client_secret']);
        $this->assertSame(400, token_endpoint::handle($this->form_request($form, [], '192.0.2.200'))->status);
    }

    /**
     * Third review, M1: grants refused to a client that authenticated do not lock it out. In gateway
     * mode the whole site shares one client and one address, and anybody can make the gateway present junk.
     */
    public function test_refused_grants_do_not_lock_an_authenticated_client_out(): void {
        [$confidential, $secret] = $this->make_client(client::AUTH_POST);
        $junk = [
            'grant_type' => 'authorization_code', 'code' => 'pc_ac_junk', 'redirect_uri' => self::$claude,
            'code_verifier' => str_repeat('a', 43), 'resource' => urls::resource(),
            'client_id' => $confidential->clientid, 'client_secret' => $secret,
        ];
        for ($i = 0; $i < token_endpoint::CLIENT_LIMIT + 5; $i++) {
            $response = token_endpoint::handle($this->form_request($junk, [], '192.0.2.210'));
            $this->assertSame(400, $response->status);
            $this->assertSame('invalid_grant', $response->decoded()['error']);
        }
        $refresh = ['grant_type' => 'refresh_token', 'refresh_token' => 'pc_rt_wrong'] + $junk;
        for ($i = 0; $i < token_endpoint::CLIENT_LIMIT + 5; $i++) {
            $this->assertSame(400, token_endpoint::handle($this->form_request($refresh, [], '192.0.2.210'))->status);
        }
        // A real exchange by the same client from the same address still works.
        $tokens = $this->issue_tokens($confidential, $this->user);
        $response = token_endpoint::handle($this->form_request([
            'grant_type' => 'refresh_token', 'refresh_token' => $tokens['refresh_token'],
            'client_id' => $confidential->clientid, 'client_secret' => $secret,
        ], [], '192.0.2.210'));
        $this->assertSame(200, $response->status);
        // The limit per address still applies to everything. It is counted per clock minute: if the
        // minute turned while the requests were sent, they are sent again.
        do {
            $minute = intdiv(time(), MINSECS);
            for ($i = 0; $i < token_endpoint::IP_LIMIT; $i++) {
                $last = token_endpoint::handle($this->form_request($refresh, [], '192.0.2.210'));
            }
        } while (intdiv(time(), MINSECS) !== $minute);
        $this->assertSame(429, $last->status);
    }

    /**
     * Revocation endpoint: ends the connection, answers 200 for unknown tokens, refuses other clients.
     */
    public function test_revoke_endpoint(): void {
        global $DB;
        [$other] = $this->make_client();
        $tokens = $this->issue_tokens($this->client, $this->user);

        $foreign = revoke_endpoint::handle(
            $this->form_request(['token' => $tokens['refresh_token'], 'client_id' => $other->clientid])
        );
        $this->assertSame(200, $foreign->status);
        $this->assertNotNull(token_service::authenticate($tokens['access_token']), 'Another client cannot revoke it');

        $unknown = revoke_endpoint::handle(
            $this->form_request(['token' => 'pc_rt_unknown', 'client_id' => $this->client->clientid])
        );
        $this->assertSame(200, $unknown->status);
        $this->assertNull($unknown->body);

        $this->assertSame(400, revoke_endpoint::handle($this->form_request(['client_id' => $this->client->clientid]))->status);
        $this->assertSame(401, revoke_endpoint::handle($this->form_request(['token' => 'x', 'client_id' => 'nobody']))->status);
        $this->assertSame(405, revoke_endpoint::handle(new request('GET'))->status);
        $this->assertSame(415, revoke_endpoint::handle(new request('POST', ['Content-Type' => 'application/json'], '{}'))->status);

        $done = revoke_endpoint::handle(
            $this->form_request(['token' => $tokens['access_token'], 'client_id' => $this->client->clientid])
        );
        $this->assertSame(200, $done->status);
        $this->assertNull(token_service::authenticate($tokens['access_token']));
        $this->assert_fails(fn() => token_service::refresh($tokens['refresh_token'], $this->client));
        $this->assertSame('client', $DB->get_field('local_pluginiaconnect_grant', 'revokedby', []));
    }
}
