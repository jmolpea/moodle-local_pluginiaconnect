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
 * Navigation callbacks. Everything else lives in classes.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Adds "My AI connections" to the user's own preferences.
 *
 * The link is shown while the connector is on, and also afterwards to users who
 * still have a connection, so that they can always see and remove it.
 *
 * @param navigation_node $navigation User account node of the settings navigation.
 * @param stdClass $user User whose preferences are shown.
 * @param context_user $usercontext Context of that user.
 * @param stdClass $course Current course.
 * @param context_course $coursecontext Context of the current course.
 * @return void
 */
function local_pluginiaconnect_extend_navigation_user_settings(
    navigation_node $navigation,
    stdClass $user,
    $usercontext,
    stdClass $course,
    $coursecontext
) {
    global $DB, $USER;
    if (!isloggedin() || isguestuser() || (int) $user->id !== (int) $USER->id) {
        return;
    }
    $enabled = !empty(get_config('local_pluginiaconnect', 'enabled'));
    if (!$enabled && !$DB->record_exists('local_pluginiaconnect_grant', ['userid' => $USER->id, 'status' => 1])) {
        return;
    }
    $navigation->add(
        get_string('myconnections', 'local_pluginiaconnect'),
        new moodle_url('/local/pluginiaconnect/connections.php'),
        navigation_node::TYPE_SETTING,
        null,
        'local_pluginiaconnect_connections'
    );
}
