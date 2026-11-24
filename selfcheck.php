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
 * Loop-back probe of the diagnostics page. See \local_pluginiaconnect\local\http\selfcheck.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// No session and no cookies: same start-up pattern as webservice/pluginfile.php.
define('NO_MOODLE_COOKIES', true);
define('NO_DEBUG_DISPLAY', true);
define('AJAX_SCRIPT', true);

// From Moodle 5.1 this resolves to public/config.php, a core file that loads the real one.
require(__DIR__ . '/../../config.php');

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\http\selfcheck;

selfcheck::handle(request::from_globals(), (string) get_file_argument())->send();
