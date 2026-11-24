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

/**
 * How the plugin talks to the Pluginia Connect gateway. Replaced by a fake in unit tests.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface transport {
    /**
     * Sends a JSON document with POST.
     *
     * @param string $path Path on the gateway, starting with a slash.
     * @param array $body Document to send.
     * @return array [HTTP status (0 when the gateway could not be reached), decoded JSON body or null].
     */
    public function post_json(string $path, array $body): array;

    /**
     * Requests a path with GET.
     *
     * @param string $path Path on the gateway, starting with a slash.
     * @return array [HTTP status (0 when the gateway could not be reached), decoded JSON body or null].
     */
    public function get(string $path): array;
}
