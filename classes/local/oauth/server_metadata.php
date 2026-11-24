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

namespace local_pluginiaconnect\local\oauth;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\urls;

/**
 * OAuth metadata documents of the site.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class server_metadata {
    /**
     * Authorization server metadata (RFC 8414 / OpenID Connect Discovery).
     *
     * @return array
     */
    public static function authorization_server(): array {
        $document = [
            'issuer' => urls::issuer(),
            'authorization_endpoint' => urls::authorize(),
            'token_endpoint' => urls::token(),
        ];
        if (config::bool('allowdcr')) {
            $document['registration_endpoint'] = urls::register();
        }
        $document += [
            'revocation_endpoint' => urls::revoke(),
            'response_types_supported' => ['code'],
            'response_modes_supported' => ['query'],
            'grant_types_supported' => ['authorization_code', 'refresh_token'],
            'code_challenge_methods_supported' => [pkce::METHOD],
            'token_endpoint_auth_methods_supported' => [client::AUTH_NONE, client::AUTH_BASIC, client::AUTH_POST],
        ];
        if (config::bool('allowcimd')) {
            $document['client_id_metadata_document_supported'] = true;
        }
        $document += [
            'authorization_response_iss_parameter_supported' => true,
            'scopes_supported' => scope::all(),
            'service_documentation' => urls::DOCUMENTATION,
        ];
        return $document;
    }

    /**
     * Protected resource metadata (RFC 9728). Lists only the minimal read scopes.
     *
     * @return array
     */
    public static function protected_resource(): array {
        return [
            'resource' => urls::resource(),
            'authorization_servers' => [urls::issuer()],
            'scopes_supported' => scope::BASELINE,
            'bearer_methods_supported' => ['header'],
            'resource_documentation' => urls::DOCUMENTATION,
        ];
    }
}
