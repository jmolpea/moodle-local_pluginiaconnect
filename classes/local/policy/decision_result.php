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

namespace local_pluginiaconnect\local\policy;

/**
 * Outcome of the decision function.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decision_result {
    /** @var bool Whether the call may go on. */
    public readonly bool $allowed;
    /** @var string|null Error code when denied. */
    public readonly ?string $reason;
    /** @var string Message for the assistant when denied. */
    public readonly string $message;

    /**
     * Constructor.
     *
     * @param bool $allowed Whether the call may go on.
     * @param string|null $reason Error code when denied.
     * @param string $message Message for the assistant when denied.
     */
    private function __construct(bool $allowed, ?string $reason, string $message) {
        $this->allowed = $allowed;
        $this->reason = $reason;
        $this->message = $message;
    }

    /**
     * Allowed.
     *
     * @return self
     */
    public static function allow(): self {
        return new self(true, null, '');
    }

    /**
     * Denied.
     *
     * @param string $reason Error code.
     * @param string $message Message for the assistant.
     * @return self
     */
    public static function deny(string $reason, string $message): self {
        return new self(false, $reason, $message);
    }
}
