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

namespace local_pluginiaconnect\local\gateway;

use local_pluginiaconnect\admin\callbacks;
use local_pluginiaconnect\event\site_registered;
use local_pluginiaconnect\event\site_unregistered;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\urls;

/**
 * Registering this site with the Pluginia Connect gateway and removing it.
 *
 * The site stays the authority: registering only creates one confidential OAuth
 * client for the gateway and switches the gateway mode on. Removing the site is
 * done locally first, so it works even when the gateway cannot be reached.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class registration {
    /** @var string Registration path on the gateway. */
    private const PATH_REGISTER = '/api/v1/sites/register';
    /** @var string Removal path on the gateway. */
    private const PATH_UNREGISTER = '/api/v1/sites/unregister';

    /**
     * Exactly the data a registration sends to the gateway, for the administrator to read before agreeing.
     *
     * The licence key and the client secret are described, never printed.
     *
     * @return array[] Rows with keys name and value.
     */
    public static function preview(): array {
        $payload = self::payload('', '', '', false);
        $rows = [];
        foreach (
            [
                'wwwroot' => $payload['wwwroot'],
                'plugin_version' => $payload['plugin_version'],
                'moodle_version' => $payload['moodle_version'],
                'licence_key' => get_string('register_data_licence', config::COMPONENT),
                'registration_hash' => get_string('register_data_hash', config::COMPONENT),
                'client_secret' => get_string('register_data_secret', config::COMPONENT),
                'student_profile' => get_string($payload['declarations']['student_profile'] ? 'yes' : 'no'),
                'no_users_under_13' => get_string('register_data_declared', config::COMPONENT),
            ] as $key => $value
        ) {
            $rows[] = ['name' => get_string('register_field_' . $key, config::COMPONENT), 'value' => $value];
        }
        return $rows;
    }

    /**
     * Reasons why the site cannot register right now.
     *
     * @return string[] String keys; empty when the site can register.
     */
    public static function blockers(): array {
        global $CFG;
        $blockers = [];
        if (!str_starts_with($CFG->wwwroot, 'https://')) {
            $blockers[] = 'register_blocker_https';
        }
        if (!config::bool('enabled')) {
            // The gateway reads the OAuth metadata of the site, which is only served when the connector is on.
            $blockers[] = 'register_blocker_disabled';
        }
        if (!function_exists('sodium_crypto_secretbox')) {
            $blockers[] = 'register_blocker_sodium';
        }
        return $blockers;
    }

    /**
     * Registers the site.
     *
     * @param string $licencekey Licence key typed by the administrator.
     * @param bool $nounder13 Declaration: the site is not directed to children under 13.
     * @param bool $terms Declaration: terms and data processing agreement accepted.
     * @param transport|null $transport Transport to use; the HTTPS one by default.
     * @return array [bool success, string key of the message, mixed placeholder].
     */
    public static function register(string $licencekey, bool $nounder13, bool $terms, ?transport $transport = null): array {
        $transport = $transport ?? new http_transport();
        $licencekey = trim($licencekey);
        if (!$terms) {
            return [false, 'register_error_terms', null];
        }
        if (strlen($licencekey) < 8 || strlen($licencekey) > 200) {
            return [false, 'register_error_licence', null];
        }
        if (self::blockers()) {
            return [false, self::blockers()[0], null];
        }
        if (config::bool('allowstudentprofile') && !$nounder13) {
            return [false, 'register_error_minors', null];
        }

        // The secret exists in clear only in this request and, encrypted, in the site configuration.
        $secret = rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
        $hash = site_identity::open_window();
        [$status, $body] = $transport->post_json(self::PATH_REGISTER, self::payload($licencekey, $hash, $secret, $nounder13));
        // Whatever happened, the verification endpoint stops answering now.
        site_identity::close_window();

        if ($status !== 201 || !is_array($body)) {
            return [false, self::error_key($status, $body), null];
        }
        $siteid = $body['site_id'] ?? null;
        if (
            !is_string($siteid) || !preg_match('/^[0-9a-f-]{36}$/', $siteid)
            || ($body['client_id'] ?? null) !== urls::GATEWAY_CLIENT_ID
            || ($body['redirect_uri'] ?? null) !== urls::GATEWAY_REDIRECT
            || ($body['token_endpoint_auth_method'] ?? null) !== client::AUTH_BASIC
        ) {
            // The answer does not describe the client this plugin knows: do not create anything.
            return [false, 'register_error_unexpected', null];
        }

        $gatewayclient = client_repository::save_gateway_client($secret, client::AUTH_BASIC);
        // A new secret replaces the old registration: connections made under it end here.
        token_service::revoke_client_grants($gatewayclient->id, 'system');
        site_identity::remember($siteid, $secret);
        self::set_gateway_mode(true);
        site_registered::create(['context' => \context_system::instance(), 'other' => ['siteid' => $siteid]])->trigger();
        return [true, 'register_done', null];
    }

    /**
     * Removes the site from the gateway.
     *
     * Local steps come first and always happen: the gateway mode is switched off, every
     * connection made through the gateway is revoked and the gateway client is blocked.
     * Then the gateway is told. If it cannot be reached the site remembers it, so the
     * administrator can try again.
     *
     * @param transport|null $transport Transport to use; the HTTPS one by default.
     * @return array [bool gateway confirmed, string key of the message, int connections revoked].
     */
    public static function unregister(?transport $transport = null): array {
        global $DB;
        $transport = $transport ?? new http_transport();
        $siteid = site_identity::site_id();
        $secret = site_identity::secret();

        self::set_gateway_mode(false);
        $revoked = 0;
        $record = $DB->get_record('local_pluginiaconnect_client', [
            'clientidhash' => hash('sha256', urls::GATEWAY_CLIENT_ID),
        ]);
        if ($record) {
            $revoked = token_service::revoke_client_grants((int) $record->id, 'system');
            $DB->update_record('local_pluginiaconnect_client', (object) [
                'id' => $record->id,
                'status' => 0,
                'secrethash' => null,
                'timemodified' => time(),
            ]);
        }
        if (site_identity::state() === site_identity::STATE_REGISTERED) {
            site_unregistered::create([
                'context' => \context_system::instance(),
                'other' => ['siteid' => $siteid],
            ])->trigger();
        }

        if ($siteid === '' || $secret === null) {
            site_identity::forget();
            return [true, 'unregister_done_local', $revoked];
        }
        [$status] = $transport->post_json(self::PATH_UNREGISTER, ['site_id' => $siteid, 'client_secret' => $secret]);
        if ($status === 200) {
            site_identity::forget();
            return [true, 'unregister_done', $revoked];
        }
        site_identity::mark_unregister_pending();
        return [false, 'unregister_pending', $revoked];
    }

    /**
     * Document sent to the gateway. Field names and formats are the ones the gateway validates.
     *
     * @param string $licencekey Licence key.
     * @param string $hash SHA-256 of the registration value.
     * @param string $secret Client secret of this site.
     * @param bool $nounder13 Declaration about children under 13.
     * @return array
     */
    private static function payload(string $licencekey, string $hash, string $secret, bool $nounder13): array {
        global $CFG;
        $release = (string) get_config(config::COMPONENT, 'release');
        if (!preg_match('/^\d+\.\d+\.\d+$/', $release)) {
            $info = \core_plugin_manager::instance()->get_plugin_info(config::COMPONENT);
            $release = $info && preg_match('/^\d+\.\d+\.\d+/', (string) $info->release, $matches) ? $matches[0] : '0.0.0';
        }
        $moodle = preg_match('/^(\d+\.\d+)/', (string) $CFG->release, $matches) ? $matches[1] : '0.0';
        return [
            'wwwroot' => rtrim($CFG->wwwroot, '/'),
            'licence_key' => $licencekey,
            'plugin_version' => $release,
            'moodle_version' => $moodle,
            'registration_hash' => $hash,
            'client_secret' => $secret,
            'declarations' => [
                'no_users_under_13' => $nounder13,
                'student_profile' => config::bool('allowstudentprofile'),
                'terms_accepted' => true,
            ],
        ];
    }

    /**
     * Switches the gateway mode and records the change like the settings page does.
     *
     * @param bool $on New state.
     * @return void
     */
    private static function set_gateway_mode(bool $on): void {
        if (config::bool('gatewayenabled') === $on) {
            return;
        }
        set_config('gatewayenabled', $on ? 1 : 0, config::COMPONENT);
        callbacks::killswitch_changed('gatewayenabled');
    }

    /**
     * Message for a failed registration. Only the gateway's error code is used, never its text.
     *
     * @param int $status HTTP status; 0 when the gateway could not be reached.
     * @param array|null $body Decoded answer.
     * @return string String key.
     */
    private static function error_key(int $status, ?array $body): string {
        if ($status === 0) {
            return 'register_error_unreachable';
        }
        $code = is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : '';
        $known = [
            'invalid_licence' => 'register_error_licence',
            'licence_in_use' => 'register_error_licenceinuse',
            'verification_failed' => 'register_error_verification',
            'declaration_required' => 'register_error_minors',
            'invalid_site' => 'register_error_site',
            'rate_limited' => 'register_error_ratelimited',
        ];
        return $known[$code] ?? 'register_error_unexpected';
    }
}
