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

namespace local_pluginiaconnect\admin;

use local_pluginiaconnect\local\config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Whole number setting that refuses values outside the hard limits of the plugin.
 *
 * The limits come from {@see config::LIMITS}; they are never repeated here.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_bounded_int extends \admin_setting_configtext {
    /** @var string Short setting name, the key of the limits table. */
    private string $shortname;

    /**
     * Constructor.
     *
     * @param string $shortname Setting name without the component prefix.
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     */
    public function __construct(string $shortname, string $visiblename, string $description) {
        $this->shortname = $shortname;
        parent::__construct(
            config::COMPONENT . '/' . $shortname,
            $visiblename,
            $description,
            config::DEFAULTS[$shortname],
            PARAM_INT,
            10
        );
    }

    /**
     * Validates that the value is a whole number inside the limits.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, otherwise the error message.
     */
    public function validate($data) {
        $data = trim((string) $data);
        if ($data === '' || !ctype_digit($data)) {
            return get_string('validateerror', 'admin');
        }
        if (isset(config::LIMITS[$this->shortname])) {
            [$min, $max] = config::LIMITS[$this->shortname];
            if ((int) $data < $min || (int) $data > $max) {
                return get_string('error_outofrange', config::COMPONENT, (object) ['min' => $min, 'max' => $max]);
            }
        }
        return true;
    }
}
