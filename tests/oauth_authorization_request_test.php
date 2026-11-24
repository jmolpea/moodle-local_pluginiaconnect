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

use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\urls;
use local_pluginiaconnect\output\consent_page;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/fixtures/oauth_helper.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\authorization_request::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\oauth_exception::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\output\consent_page::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\scope::class)]
/**
 * Validation of authorization requests, granted scopes and consent page data.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers \local_pluginiaconnect\local\oauth\authorization_request
 * @covers \local_pluginiaconnect\local\oauth\oauth_exception
 * @covers \local_pluginiaconnect\output\consent_page
 * @covers \local_pluginiaconnect\local\policy\scope
 */
final class oauth_authorization_request_test extends \advanced_testcase {
    use oauth_helper;

    /**
     * Common set-up.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        $this->require_site_policy();
        $this->enable_direct_mode();
    }

    /**
     * Runs the validation and returns the exception it raises.
     *
     * @param array $params Request parameters.
     * @return oauth_exception
     */
    private function failure(array $params): oauth_exception {
        try {
            authorization_request::from_params($params);
        } catch (oauth_exception $e) {
            return $e;
        }
        $this->fail('The request should have been refused.');
    }

    /**
     * A valid request is accepted and keeps the values exactly as received.
     */
    public function test_valid_request(): void {
        [$client] = $this->make_client();
        $verifier = $this->make_verifier();
        $request = authorization_request::from_params($this->request_params($client, $verifier, [
            'scope' => 'courses.read unknown.scope grades.read courses.read',
            'state' => 'a b&c=d',
            'pc_actor' => 'Claude',
            'pc_actor_id' => 'claude',
        ]));
        $this->assertSame($client->id, $request->client->id);
        $this->assertSame(self::$claude, $request->redirecturi);
        $this->assertSame('a b&c=d', $request->state);
        $this->assertSame(['courses.read', 'grades.read'], $request->scopes);
        // Only the gateway may declare the assistant.
        $this->assertNull($request->actor);
        $this->assertNull($request->actorid);

        // Without a scope parameter the request carries no scope list.
        $request = authorization_request::from_params($this->request_params($client, $verifier));
        $this->assertNull($request->scopes);

        // The resource is compared in canonical form.
        $upper = str_replace('http', 'HTTP', urls::resource()) . '/';
        $this->assertNotNull(authorization_request::from_params($this->request_params($client, $verifier, ['resource' => $upper])));
    }

    /**
     * Client and redirect URI problems are never redirected.
     */
    public function test_client_and_redirect_errors_are_not_redirected(): void {
        global $DB;
        [$client] = $this->make_client();
        $verifier = $this->make_verifier();

        $cases = [
            'no client' => ['client_id' => null],
            'unknown client' => ['client_id' => 'pc_client_nobody'],
            'no redirect' => ['redirect_uri' => null],
            'foreign redirect' => ['redirect_uri' => 'https://evil.example/cb'],
            'redirect with extra path' => ['redirect_uri' => self::$claude . '/x'],
            'allowed but not registered' => ['redirect_uri' => 'https://chatgpt.com/connector_platform_oauth_redirect'],
        ];
        foreach ($cases as $name => $override) {
            $e = $this->failure($this->request_params($client, $verifier, $override));
            $this->assertFalse($e->is_redirectable(), $name);
        }
        // Client problems win even when everything else is wrong too.
        $e = $this->failure(['client_id' => 'nobody', 'redirect_uri' => 'https://evil.example/cb', 'response_type' => 'token']);
        $this->assertFalse($e->is_redirectable());
        $this->assertSame('invalid_client', $e->error);

        // A blocked client and a switched off mode are refused the same way.
        $DB->set_field('local_pluginiaconnect_client', 'status', 0, ['id' => $client->id]);
        $this->assertFalse($this->failure($this->request_params($client, $verifier))->is_redirectable());
        $DB->set_field('local_pluginiaconnect_client', 'status', 1, ['id' => $client->id]);
        set_config('directmode', 0, 'local_pluginiaconnect');
        $this->assertFalse($this->failure($this->request_params($client, $verifier))->is_redirectable());
    }

    /**
     * Every other problem goes back to the client with error, state and issuer.
     */
    public function test_other_errors_are_redirected_with_state_and_issuer(): void {
        [$client] = $this->make_client();
        $verifier = $this->make_verifier();
        $cases = [
            'implicit flow' => [['response_type' => 'token'], 'unsupported_response_type'],
            'no response type' => [['response_type' => null], 'unsupported_response_type'],
            'no challenge' => [['code_challenge' => null], 'invalid_request'],
            'plain method' => [['code_challenge_method' => 'plain'], 'invalid_request'],
            'no method' => [['code_challenge_method' => null], 'invalid_request'],
            'malformed challenge' => [['code_challenge' => 'short'], 'invalid_request'],
            'no resource' => [['resource' => null], 'invalid_target'],
            'foreign resource' => [['resource' => 'https://other.example/mcp'], 'invalid_target'],
            'resource of another path' => [['resource' => urls::base() . '/oauth.php'], 'invalid_target'],
            'only unknown scopes' => [['scope' => 'everything admin'], 'invalid_scope'],
        ];
        foreach ($cases as $name => [$override, $expected]) {
            $e = $this->failure($this->request_params($client, $verifier, $override));
            $this->assertTrue($e->is_redirectable(), $name);
            $this->assertSame($expected, $e->error, $name);
            $url = authorization_request::exception_url($e);
            $this->assertStringStartsWith(self::$claude . '?', $url, $name);
            parse_str(parse_url($url, PHP_URL_QUERY), $query);
            $this->assertSame($expected, $query['error'], $name);
            $this->assertSame('state-123', $query['state'], $name);
            $this->assertSame(urls::issuer(), $query['iss'], $name);
            $this->assertArrayNotHasKey('code', $query, $name);
        }
        // An oversized state is not echoed back.
        $e = $this->failure($this->request_params($client, $verifier, ['state' => str_repeat('s', 2001)]));
        $this->assertTrue($e->is_redirectable());
        $this->assertSame('', $e->state);
    }

    /**
     * Success and error responses always carry the issuer and return the state unmodified.
     */
    public function test_response_urls(): void {
        [$client] = $this->make_client();
        $request = authorization_request::from_params($this->request_params($client, $this->make_verifier(), [
            'state' => 'a b&c=d/é',
        ]));
        parse_str(parse_url($request->success_url('pc_ac_test'), PHP_URL_QUERY), $query);
        $this->assertSame(['code' => 'pc_ac_test', 'state' => 'a b&c=d/é', 'iss' => urls::issuer()], $query);
        parse_str(parse_url($request->error_url('access_denied', 'No.'), PHP_URL_QUERY), $query);
        $this->assertSame('access_denied', $query['error']);
        $this->assertSame('a b&c=d/é', $query['state']);
        $this->assertSame(urls::issuer(), $query['iss']);
    }

    /**
     * The gateway client declares the assistant; unknown identifiers are dropped and the name is cleaned.
     */
    public function test_actor_is_taken_only_from_the_gateway(): void {
        $gateway = client_repository::save_gateway_client('gateway-secret-0123456789abcdef0123');
        $verifier = $this->make_verifier();
        $params = $this->request_params($gateway, $verifier, [
            'pc_actor' => '  <b>Copilot</b> Cowork' . str_repeat('x', 300),
            'pc_actor_id' => 'copilot-cowork',
        ]);
        // The gateway mode is off: the gateway client is not accepted at all.
        $this->assertFalse($this->failure($params)->is_redirectable());

        set_config('gatewayenabled', 1, 'local_pluginiaconnect');
        $request = authorization_request::from_params($params);
        $this->assertSame(client::TYPE_GATEWAY, $request->client->type);
        $this->assertSame('copilot-cowork', $request->actorid);
        $this->assertStringNotContainsString('<', $request->actor);
        $this->assertSame(255, \core_text::strlen($request->actor));
        $this->assertTrue(consent_page::needs_site_approval($request));
        $this->assertSame('Microsoft (Microsoft 365 Copilot)', consent_page::provider_name($request));

        $request = authorization_request::from_params($this->request_params($gateway, $verifier, ['pc_actor_id' => 'root']));
        $this->assertNull($request->actorid);
        $this->assertNull($request->actor);
        $this->assertFalse(consent_page::needs_site_approval($request));
        $this->assertSame('', consent_page::provider_name($request));
    }

    /**
     * Scopes granted are the intersection of request, profile, access level and site state.
     */
    public function test_scopes_granted(): void {
        $teacher = $this->make_teacher();
        $student = $this->getDataGenerator()->create_user();
        $id = (int) $teacher->id;

        // Read only: no write scope, whatever was asked.
        $read = scope::granted(null, 'teacher', false, $id);
        $this->assertContains('participants.read', $read);
        $this->assertNotContains('grades.write', $read);
        $this->assertNotContains('manage.read', $read, 'Above the profile');
        $this->assertNotContains('site.read', $read);

        // Read and changes, for a user who holds the write capability somewhere.
        $write = scope::granted(null, 'teacher', true, $id);
        $this->assertContains('grades.write', $write);
        $this->assertNotContains('enrol.write', $write, 'Above the profile');

        // The request can only narrow.
        $this->assertSame(['courses.read'], scope::granted(['courses.read', 'site.read', 'users.write'], 'teacher', true, $id));
        $this->assertSame([], scope::granted(['site.read'], 'student', true, $id));

        // A user without the write capability gets no write scope even when asking for it.
        $this->assertNotContains('grades.write', scope::granted(null, 'teacher', true, (int) $student->id));

        // The site-wide read-only switch removes every write scope.
        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assertNotContains('grades.write', scope::granted(null, 'teacher', true, $id));
        set_config('readonlymode', 0, 'local_pluginiaconnect');

        // Scopes without an active tool are dropped; profile and offline_access stay.
        $narrow = scope::granted(null, 'teacher', true, $id, ['courses.read']);
        $this->assertSame(['profile', 'courses.read', 'offline_access'], $narrow);
    }

    /**
     * Consent page data: lowest profile preselected, write offered only when possible, client text kept raw for escaping.
     */
    public function test_consent_page_data(): void {
        global $PAGE;
        $teacher = $this->make_teacher();
        $this->setUser($teacher);
        set_config('allowstudentprofile', 1, 'local_pluginiaconnect');
        eligibility::invalidate((int) $teacher->id);
        [$client] = client_repository::register_dcr('<script>x</script>', [self::$claude], client::AUTH_NONE, []);
        $request = authorization_request::from_params($this->request_params($client, $this->make_verifier()));
        $profiles = eligibility::eligible_profiles((int) $teacher->id);
        $this->assertSame(['student', 'teacher'], $profiles);

        // The option to read and change is only offered while some action that changes data is switched on.
        set_config('tool_post_course_announcement_teacher', 1, 'local_pluginiaconnect');
        \cache::make('local_pluginiaconnect', 'toolconfig')->purge();

        $PAGE->set_url('/local/pluginiaconnect/oauth/authorize.php');
        $output = $PAGE->get_renderer('core');
        $action = new \moodle_url('/local/pluginiaconnect/oauth/authorize.php');
        $page = new consent_page($request, $teacher, $profiles, true, $action);
        $data = $page->export_for_template($output);
        $this->assertSame('student', $data['profiles'][0]['value']);
        $this->assertTrue($data['profiles'][0]['checked']);
        $this->assertFalse($data['profiles'][1]['checked']);
        $this->assertTrue($data['canwrite']);
        $this->assertFalse($data['siteapproval']);
        $this->assertFalse($data['isgateway']);
        $this->assertFalse($data['isloopback']);
        $this->assertSame('claude.ai', $data['redirecthost']);
        $this->assertSame('<script>x</script>', $data['clientname']);
        $this->assertSame(sesskey(), $data['sesskey']);
        $this->assertStringContainsString(fullname($teacher), $data['signedin']);

        // Rendered, the client name is escaped and there is no script and no inline handler.
        $html = $output->render_from_template('local_pluginiaconnect/consent', $data);
        $this->assertStringNotContainsString('<script>x</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
        $this->assertStringContainsString('name="sesskey"', $html);
        $this->assertStringContainsString('method="post"', $html);
        $this->assertDoesNotMatchRegularExpression('/\son[a-z]+=/i', $html);

        $readonly = new consent_page($request, $teacher, ['teacher'], false, new \moodle_url('/x'), 'Problem');
        $data = $readonly->export_for_template($output);
        $this->assertFalse($data['canwrite']);
        $this->assertSame('Problem', $data['error']);
        $this->assertTrue($data['profiles'][0]['checked']);
        $html = $output->render_from_template('local_pluginiaconnect/consent', $data);
        $this->assertStringNotContainsString('value="write"', $html);

        $error = $output->render_from_template('local_pluginiaconnect/oauth_error', ['message' => '<i>m</i>', 'loginurl' => '']);
        $this->assertStringContainsString('&lt;i&gt;m&lt;/i&gt;', $error);
    }
}
