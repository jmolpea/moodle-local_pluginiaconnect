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
 * Filters of the audit report.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class audit_filter_form extends \moodleform {
    /**
     * Defines the form. Custom data: tools and outcomes (value => label lists).
     *
     * @return void
     */
    protected function definition() {
        $mform = $this->_form;
        $component = 'local_pluginiaconnect';
        $any = ['' => get_string('audit_filter_any', $component)];

        $mform->addElement('header', 'filters', get_string('audit_filters', $component));

        $mform->addElement('text', 'userid', get_string('audit_filter_userid', $component), ['size' => 10]);
        $mform->setType('userid', PARAM_INT);

        $mform->addElement('select', 'tool', get_string('audit_filter_tool', $component), $any + $this->_customdata['tools']);
        $mform->setType('tool', PARAM_ALPHANUMEXT);

        $mform->addElement('text', 'courseid', get_string('audit_filter_courseid', $component), ['size' => 10]);
        $mform->setType('courseid', PARAM_INT);

        $mform->addElement(
            'select',
            'outcome',
            get_string('audit_filter_outcome', $component),
            $any + $this->_customdata['outcomes']
        );
        $mform->setType('outcome', PARAM_ALPHAEXT);

        $mform->addElement('date_time_selector', 'timefrom', get_string('audit_filter_from', $component), ['optional' => true]);
        $mform->addElement('date_time_selector', 'timeto', get_string('audit_filter_to', $component), ['optional' => true]);

        $buttons = [
            $mform->createElement('submit', 'submitbutton', get_string('audit_filter_apply', $component)),
            $mform->createElement('submit', 'resetbutton', get_string('audit_filter_reset', $component)),
        ];
        $mform->addGroup($buttons, 'buttons', '', ' ', false);
    }

    /**
     * Validation: the period must not be inverted.
     *
     * @param array $data Submitted data.
     * @param array $files Submitted files.
     * @return array Errors.
     */
    public function validation($data, $files) {
        $errors = parent::validation($data, $files);
        if (!empty($data['timefrom']) && !empty($data['timeto']) && $data['timefrom'] > $data['timeto']) {
            $errors['timeto'] = get_string('audit_filter_period_error', 'local_pluginiaconnect');
        }
        return $errors;
    }
}
