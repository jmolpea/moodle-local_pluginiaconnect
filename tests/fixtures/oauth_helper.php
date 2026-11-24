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
use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\code_service;
use local_pluginiaconnect\local\oauth\pkce;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\urls;

/**
 * Shared set-up of the OAuth tests. Uses real users, roles and courses.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
trait oauth_helper {
    /** @var string Redirect URI of the hosted Claude apps, allowed by default. */
    protected static string $claude = 'https://claude.ai/api/mcp/auth_callback';

    /**
     * Skips the test when the site policy class of the plugin is not installed yet.
     *
     * @return void
     */
    protected function require_site_policy(): void {
        if (!class_exists('\local_pluginiaconnect\local\policy\site_policy')) {
            $this->markTestSkipped('site_policy is not available yet.');
        }
    }

    /**
     * Switches the connector on in direct mode.
     *
     * @return void
     */
    protected function enable_direct_mode(): void {
        set_config('enabled', 1, 'local_pluginiaconnect');
        set_config('directmode', 1, 'local_pluginiaconnect');
    }

    /**
     * Registers a client.
     *
     * @param string $authmethod Token endpoint authentication method.
     * @param string[]|null $uris Redirect URIs; the Claude callback by default.
     * @return array [client, secret or null].
     */
    protected function make_client(string $authmethod = client::AUTH_NONE, ?array $uris = null): array {
        return client_repository::register_dcr('Test application', $uris ?? [self::$claude], $authmethod, []);
    }

    /**
     * Creates a user who teaches a course, so the teacher profile applies and writing is possible.
     *
     * @return \stdClass User record.
     */
    protected function make_teacher(): \stdClass {
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $generator->enrol_user($user->id, $course->id, 'editingteacher');
        eligibility::invalidate((int) $user->id);
        return $user;
    }

    /**
     * New PKCE verifier.
     *
     * @return string
     */
    protected function make_verifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * Parameters of a valid authorization request.
     *
     * @param client $client Client.
     * @param string $verifier PKCE verifier.
     * @param array $override Parameters to replace; a null value removes the parameter.
     * @return array
     */
    protected function request_params(client $client, string $verifier, array $override = []): array {
        $params = $override + [
            'response_type' => 'code',
            'client_id' => $client->clientid,
            'redirect_uri' => $client->redirecturis[0],
            'code_challenge' => pkce::challenge($verifier),
            'code_challenge_method' => 'S256',
            'resource' => urls::resource(),
            'state' => 'state-123',
        ];
        return array_filter($params, static fn($value) => $value !== null);
    }

    /**
     * Creates an authorization code as if the user had approved the consent page.
     *
     * @param client $client Client.
     * @param \stdClass $user User.
     * @param string $verifier PKCE verifier.
     * @param string[] $scopes Scopes granted.
     * @param string $profile Profile chosen.
     * @return string The code.
     */
    protected function make_code(
        client $client,
        \stdClass $user,
        string $verifier,
        array $scopes = ['profile', 'courses.read', 'offline_access'],
        string $profile = 'teacher'
    ): string {
        $request = authorization_request::from_params($this->request_params($client, $verifier));
        return code_service::create($request, (int) $user->id, $profile, $scopes);
    }

    /**
     * Runs the whole code flow and returns the token response.
     *
     * @param client $client Client.
     * @param \stdClass $user User.
     * @param string[] $scopes Scopes granted.
     * @return array Token response.
     */
    protected function issue_tokens(
        client $client,
        \stdClass $user,
        array $scopes = ['profile', 'courses.read', 'offline_access']
    ): array {
        $verifier = $this->make_verifier();
        $code = $this->make_code($client, $user, $verifier, $scopes);
        return code_service::exchange($code, $client, $client->redirecturis[0], urls::resource(), $verifier);
    }

    /**
     * Builds a form-encoded POST request.
     *
     * @param array $form Form fields.
     * @param array $headers Extra headers.
     * @param string $ip Remote address.
     * @return request
     */
    protected function form_request(array $form, array $headers = [], string $ip = '203.0.113.10'): request {
        return new request(
            'POST',
            $headers + ['Content-Type' => 'application/x-www-form-urlencoded'],
            http_build_query($form, '', '&'),
            [],
            true,
            $ip
        );
    }
}
