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
 * OAuth metadata documents, addressed by path.
 *
 * Routes: /.well-known/openid-configuration, /.well-known/oauth-authorization-server
 * and /resource-metadata. Public and read-only.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// Session-less endpoint: same start-up pattern as webservice/pluginfile.php.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

// From Moodle 5.1 this path resolves to public/config.php, a core file that loads the real one.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../config.php');

use local_pluginiaconnect\local\http\endpoint;
use local_pluginiaconnect\local\oauth\metadata_endpoint;

$request = endpoint::bootstrap();
metadata_endpoint::handle($request, (string) get_file_argument())->send();
