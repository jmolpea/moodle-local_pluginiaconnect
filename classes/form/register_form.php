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
 * Registration of the site with the Pluginia Connect gateway.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class register_form extends \moodleform {
    /**
     * Defines the form. Custom data: studentprofile (bool), whether the student profile is offered on this site.
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_pluginiaconnect';

        $mform->addElement('passwordunmask', 'licencekey', get_string('register_licencekey', $component), ['size' => 50]);
        $mform->setType('licencekey', PARAM_RAW_TRIMMED);
        $mform->addRule('licencekey', get_string('required'), 'required', null, 'client');
        $mform->addHelpButton('licencekey', 'register_licencekey', $component);

        $mform->addElement('advcheckbox', 'nounder13', get_string('register_nounder13', $component));
        $mform->addHelpButton('nounder13', 'register_nounder13', $component);

        $mform->addElement('advcheckbox', 'terms', get_string('register_terms', $component));

        $this->add_action_buttons(false, get_string('register_submit', $component));
    }

    /**
     * Validation of the declarations and of the shape of the licence key.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        $component = 'local_pluginiaconnect';
        $length = strlen(trim((string) ($data['licencekey'] ?? '')));
        if ($length < 8 || $length > 200) {
            $errors['licencekey'] = get_string('register_error_licence', $component);
        }
        if (empty($data['terms'])) {
            $errors['terms'] = get_string('register_error_terms', $component);
        }
        if (!empty($this->_customdata['studentprofile']) && empty($data['nounder13'])) {
            $errors['nounder13'] = get_string('register_error_minors', $component);
        }
        return $errors;
    }
}
