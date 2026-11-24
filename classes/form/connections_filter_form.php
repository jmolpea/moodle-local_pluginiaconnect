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
 * Filters of the administrator list of connections.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class connections_filter_form extends \moodleform {
    /**
     * Defines the form. Custom data: clients (record id => name).
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_pluginiaconnect';

        $mform->addElement('header', 'filters', get_string('connections_filters', $component));

        $mform->addElement('text', 'userid', get_string('connections_filter_userid', $component), ['size' => 10]);
        $mform->setType('userid', PARAM_INT);

        $clients = [0 => get_string('connections_filter_anyclient', $component)] + $this->_customdata['clients'];
        $mform->addElement('select', 'clientid', get_string('connections_filter_client', $component), $clients);
        $mform->setType('clientid', PARAM_INT);

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('connections_filter_apply', $component)),
            $mform->createElement('submit', 'resetbutton', get_string('connections_filter_reset', $component)),
        ];
        $mform->addGroup($buttons, 'buttons', '', ' ', false);
    }
}
