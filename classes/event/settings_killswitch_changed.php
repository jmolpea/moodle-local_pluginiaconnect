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

namespace local_pluginiaconnect\event;

use local_pluginiaconnect\local\event_base;

/**
 * One of the switches that turn the connector, or a part of it, on and off was changed.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class settings_killswitch_changed extends event_base {
    /** @var string CRUD letter of the event. */
    protected const CRUD = 'u';
    /** @var string|null Table of the object the event is about. */
    protected const OBJECTTABLE = null;
    /** @var string[] Keys that "other" must contain. */
    protected const REQUIRED_OTHER = ['setting', 'value'];
    /** @var string[] Keys that "other" may contain in addition to the required ones. */
    protected const OPTIONAL_OTHER = [];
    /** @var bool Whether the event must name the affected user. */
    protected const NEEDS_RELATEDUSER = false;

    /**
     * Non-localised description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The user with id '$this->userid' set the connector switch '" . $this->other_text('setting') . "' to '" .
            $this->other_text('value') . "'.";
    }
}
