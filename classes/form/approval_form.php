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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/formslib.php');

/**
 * Approve or reject a change an assistant requested.
 *
 * The form carries the identifier of the action and the hash of the summary the
 * user is looking at, so that what is approved is exactly what was shown. Moodle
 * forms add and check the session key.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class approval_form extends \moodleform {
    /**
     * Form definition.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $mform->addElement('hidden', 'id', $this->_customdata['id']);
        $mform->setType('id', PARAM_INT);
        $mform->addElement('hidden', 'summaryhash', $this->_customdata['summaryhash']);
        $mform->setType('summaryhash', PARAM_ALPHANUM);

        $buttons = [
            $mform->createElement('submit', 'approve', get_string('approval_approve', 'local_pluginiaconnect')),
            $mform->createElement('submit', 'reject', get_string('approval_reject', 'local_pluginiaconnect'), [], false),
        ];
        $mform->addGroup($buttons, 'buttons', '', [' '], false);
        $mform->closeHeaderBefore('buttons');
    }
}
