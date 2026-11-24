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
 * A tool was called through the connector and allowed.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_called extends event_base {
    /** @var string CRUD letter of the event. */
    protected const CRUD = 'r';
    /** @var string|null Table of the object the event is about. */
    protected const OBJECTTABLE = 'local_pluginiaconnect_audit';
    /** @var string[] Keys that "other" must contain. */
    protected const REQUIRED_OTHER = ['tool', 'profile', 'outcome', 'reason', 'requestid'];
    /** @var string[] Keys that "other" may contain in addition to the required ones. */
    protected const OPTIONAL_OTHER = [];
    /** @var bool Whether the event must name the affected user. */
    protected const NEEDS_RELATEDUSER = true;

    /**
     * Non-localised description for the log.
     *
     * @return string
     */
    public function get_description() {
        return "The tool '" . $this->other_text('tool') . "' was called for the user with id '$this->relateduserid' with the " .
            "profile '" . $this->other_text('profile') . "'. Outcome: '" . $this->other_text('outcome') . "'. Request id '" .
            $this->other_text('requestid') . "'.";
    }
}
