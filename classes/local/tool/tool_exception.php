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

/**
 * A tool call ended with one of the documented error codes.
 *
 * The message is returned to the assistant: it must say what happened and what
 * the user can do, in English, and must never contain internal detail.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class tool_exception extends \Exception {
    /** @var string[] Error codes a tool result may carry. */
    public const CODES = [
        'permission_denied', 'profile_not_allowed', 'scope_missing', 'tool_disabled', 'out_of_scope', 'not_found',
        'validation_error', 'limit_exceeded', 'rate_limited', 'user_action_required', 'site_unavailable', 'conflict',
        'internal_error',
    ];

    /** @var string Error code. */
    private string $errorcode;

    /**
     * Constructor.
     *
     * @param string $errorcode One of {@see self::CODES}.
     * @param string $message Message for the assistant.
     */
    public function __construct(string $errorcode, string $message) {
        parent::__construct($message);
        $this->errorcode = in_array($errorcode, self::CODES, true) ? $errorcode : 'internal_error';
    }

    /**
     * Error code.
     *
     * @return string
     */
    public function error_code(): string {
        return $this->errorcode;
    }
}
