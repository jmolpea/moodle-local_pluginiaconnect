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

/**
 * MCP endpoint. Stateless: POST only, bearer token on every request, no session and no cookies.
 *
 * It is deliberately not a Moodle web service server: WS_SERVER is not defined
 * and Moodle web service tokens are never accepted here.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Same bootstrap as the core session-less scripts (webservice/pluginfile.php).
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

// From Moodle 5.1 this resolves to public/config.php, a core file that loads the real one.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

use local_pluginiaconnect\local\http\endpoint;
use local_pluginiaconnect\local\http\origin_guard;
use local_pluginiaconnect\local\mcp\server;

$request = endpoint::bootstrap();

$refused = origin_guard::check($request);
if ($refused !== null) {
    $refused->send();
}

server::handle($request, null, endpoint::request_id())->send();
