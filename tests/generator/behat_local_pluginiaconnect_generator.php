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
 * Behat data generator of the connector.
 *
 * Usage: And the following "local_pluginiaconnect > connections" exist:
 *
 * @package    local_pluginiaconnect
 * @category   test
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class behat_local_pluginiaconnect_generator extends behat_generator_base {
    /**
     * Entities that can be created.
     *
     * @return array
     */
    protected function get_creatable_entities(): array {
        return [
            'clients' => [
                'singular' => 'client',
                'datagenerator' => 'client',
                'required' => ['name'],
            ],
            'connections' => [
                'singular' => 'connection',
                'datagenerator' => 'connection',
                'required' => ['user', 'client'],
                'switchids' => ['user' => 'userid', 'client' => 'clientid'],
            ],
            'pending changes' => [
                'singular' => 'pending change',
                'datagenerator' => 'pending_change',
                'required' => ['user', 'client', 'activity'],
                'switchids' => ['user' => 'userid', 'client' => 'clientid', 'activity' => 'activityid'],
            ],
            'calls' => [
                'singular' => 'call',
                'datagenerator' => 'call',
                'required' => ['user', 'client'],
                'switchids' => ['user' => 'userid', 'client' => 'clientid'],
            ],
        ];
    }

    /**
     * Course module id of an activity, from its ID number.
     *
     * @param string $idnumber ID number of the activity.
     * @return int
     */
    protected function get_activity_id(string $idnumber): int {
        global $DB;
        return (int) $DB->get_field('course_modules', 'id', ['idnumber' => $idnumber], MUST_EXIST);
    }

    /**
     * Client record id, from its name.
     *
     * @param string $name Client name.
     * @return int
     */
    protected function get_client_id(string $name): int {
        global $DB;
        return (int) $DB->get_field('local_pluginiaconnect_client', 'id', ['name' => $name], MUST_EXIST);
    }
}
