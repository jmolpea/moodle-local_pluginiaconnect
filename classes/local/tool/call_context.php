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

namespace local_pluginiaconnect\local\tool;

use local_pluginiaconnect\local\oauth\grant_context;

/**
 * Data of one tool call handed to the tool.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class call_context {
    /** @var grant_context Grant behind the access token. */
    public readonly grant_context $grant;
    /** @var \stdClass User the call runs as. */
    public readonly \stdClass $user;
    /** @var \context Context resolved from the arguments. */
    public readonly \context $context;
    /** @var string Request identifier. */
    public readonly string $requestid;

    /**
     * Constructor.
     *
     * @param grant_context $grant Grant behind the access token.
     * @param \stdClass $user User the call runs as.
     * @param \context $context Context resolved from the arguments.
     * @param string $requestid Request identifier.
     */
    public function __construct(grant_context $grant, \stdClass $user, \context $context, string $requestid) {
        $this->grant = $grant;
        $this->user = $user;
        $this->context = $context;
        $this->requestid = $requestid;
    }
}
