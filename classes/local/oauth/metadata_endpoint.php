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

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\response;

/**
 * Serves the OAuth metadata documents below oauth.php.
 *
 * These public documents are the only responses of the plugin that any web origin may read.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class metadata_endpoint {
    /**
     * Handles a metadata request.
     *
     * @param request $request Request.
     * @param string $path Path after the script name, e.g. /.well-known/openid-configuration.
     * @return response
     */
    public static function handle(request $request, string $path): response {
        $documents = [
            '/.well-known/openid-configuration' => 'authorization_server',
            '/.well-known/oauth-authorization-server' => 'authorization_server',
            '/resource-metadata' => 'protected_resource',
        ];
        if (!isset($documents[$path])) {
            return response::oauth_error(404, 'not_found', 'Unknown metadata document.');
        }
        if ($request->method() !== 'GET' && $request->method() !== 'HEAD') {
            return response::oauth_error(405, 'invalid_request', 'Use GET.', ['Allow' => 'GET']);
        }
        $method = $documents[$path];
        return response::json(200, server_metadata::$method(), [
            'Access-Control-Allow-Origin' => '*',
            'Cache-Control' => 'public, max-age=300',
        ]);
    }
}
