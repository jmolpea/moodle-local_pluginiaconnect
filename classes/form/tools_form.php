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

namespace local_pluginiaconnect\form;

use local_pluginiaconnect\local\policy\profile;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * The tool × profile matrix and the approval mode of each write tool.
 *
 * Only the cells that exist are offered: a profile below the minimum of a tool
 * has no switch, and an approval mode below the catalogue minimum is not in the list.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tools_form extends \moodleform {
    /**
     * Defines the form. Custom data: rows, as {@see \local_pluginiaconnect\local\tools_matrix::rows()} returns them.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_pluginiaconnect';
        $rows = $this->_customdata['rows'];

        foreach ([false => 'tools_section_read', true => 'tools_section_write'] as $write => $heading) {
            $mform->addElement('header', $heading, get_string($heading, $component));
            $mform->setExpanded($heading, true);
            $section = array_filter($rows, static fn(array $row): bool => $row['write'] === (bool) $write);
            if (!$section) {
                $mform->addElement('static', $heading . '_empty', '', get_string('tools_section_empty', $component));
                continue;
            }
            foreach ($section as $row) {
                $this->add_tool($row);
            }
        }
        $this->add_action_buttons(false, get_string('savechanges'));
    }

    /**
     * Adds the elements of one tool.
     *
     * @param array $row Row of the matrix.
     * @return void
     */
    private function add_tool(array $row): void {
        $mform = $this->_form;
        $component = 'local_pluginiaconnect';
        $name = $row['name'];

        $capabilities = $row['capabilities'] ? implode(', ', $row['capabilities']) : get_string('tools_nocapabilities', $component);
        $details = get_string('tools_details', $component, (object) [
            'name' => s($name),
            'kind' => get_string($row['write'] ? 'audit_kind_write' : 'audit_kind_read', $component),
            'scope' => s($row['scope']),
            'capabilities' => s($capabilities),
        ]);

        $cells = [];
        foreach (array_keys($row['cells']) as $profile) {
            $cells[] = $mform->createElement(
                'advcheckbox',
                "cell_{$name}_{$profile}",
                '',
                get_string('profile_' . $profile, $component)
            );
        }
        $mform->addGroup($cells, "group_{$name}", s($row['title']), ' ', false);
        foreach ($row['cells'] as $profile => $enabled) {
            $mform->setDefault("cell_{$name}_{$profile}", $enabled ? 1 : 0);
        }
        $mform->addElement('static', "details_{$name}", '', $details);

        if ($row['write']) {
            $modes = [];
            foreach ($row['modes'] as $mode) {
                $modes[$mode] = get_string('approval_' . $mode, $component);
            }
            $mform->addElement(
                'select',
                "approval_{$name}",
                get_string('tools_approval', $component, s($row['title'])),
                $modes
            );
            $mform->setDefault("approval_{$name}", $row['approval']);
        }
    }

    /**
     * Submitted matrix in the shape {@see \local_pluginiaconnect\local\tools_matrix::save()} takes.
     *
     * @param \stdClass $data Submitted data.
     * @return array [cells, approvals]
     */
    public function to_matrix(\stdClass $data): array {
        $cells = [];
        $approvals = [];
        foreach ($this->_customdata['rows'] as $row) {
            foreach (profile::all() as $profile) {
                $key = "cell_{$row['name']}_{$profile}";
                $cells[$row['name']][$profile] = !empty($data->$key);
            }
            $key = "approval_{$row['name']}";
            if (isset($data->$key)) {
                $approvals[$row['name']] = (string) $data->$key;
            }
        }
        return [$cells, $approvals];
    }
}
