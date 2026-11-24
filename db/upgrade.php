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
 * Upgrade steps.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

/**
 * Runs the upgrade steps from an older version of the plugin.
 *
 * @param int $oldversion Version the site is upgrading from.
 * @return bool
 */
function xmldb_local_pluginiaconnect_upgrade($oldversion) {
    if ($oldversion < 2026112500) {
        // New message provider (connection created) and observer of the plugin's own event:
        // both are loaded from db/messages.php and db/events.php when the version changes.
        upgrade_plugin_savepoint(true, 2026112500, 'local', 'pluginiaconnect');
    }

    if ($oldversion < 2026112600) {
        // Write tools, confirmation and approval. The nine write tools arrive switched off: their cells of
        // the tool matrix have no stored value, and an unset cell of a write tool means off. A new message
        // provider (change waiting for approval) is loaded from db/messages.php.
        upgrade_plugin_savepoint(true, 2026112600, 'local', 'pluginiaconnect');
    }

    if ($oldversion < 2026112601) {
        // New observers (capabilities of a role changed, role edited or deleted) that drop the cached
        // profiles: they are loaded from db/events.php when the version changes. Nothing stored changes.
        upgrade_plugin_savepoint(true, 2026112601, 'local', 'pluginiaconnect');
    }

    if ($oldversion < 2026112602) {
        global $DB;
        // The action that removed enrolments was replaced, before any release, by "set_enrolment_status",
        // which suspends or reactivates and removes nothing. What a development site stored under the old
        // name is cleared: its cells of the tool matrix and its approval mode. The new action arrives
        // switched off, like every action that changes something. A change still waiting under the old
        // name can no longer be applied, so it is closed as expired; the audit record is left as it is.
        $old = 'unenrol_users_from_course';
        foreach (['student', 'teacher', 'manager', 'admin'] as $profile) {
            unset_config("tool_{$old}_{$profile}", 'local_pluginiaconnect');
        }
        unset_config("approval_{$old}", 'local_pluginiaconnect');
        $DB->set_field_select(
            'local_pluginiaconnect_action',
            'timeresolved',
            time(),
            "tool = :tool AND status = :status",
            ['tool' => $old, 'status' => 'pending']
        );
        $DB->set_field('local_pluginiaconnect_action', 'status', 'expired', ['tool' => $old, 'status' => 'pending']);
        $DB->delete_records('local_pluginiaconnect_idem', ['tool' => $old]);
        upgrade_plugin_savepoint(true, 2026112602, 'local', 'pluginiaconnect');
    }

    return true;
}
