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

namespace local_pluginiaconnect\local\oauth;

/**
 * OAuth protocol error, as RFC 6749 names them.
 *
 * The description is sent to the client, so it never carries internal detail.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class oauth_exception extends \Exception {
    /** @var string OAuth error code, e.g. invalid_grant. */
    public string $error;
    /** @var string Description safe to show to the client. */
    public string $description;
    /** @var int HTTP status for endpoints that answer directly. */
    public int $status;
    /** @var array Extra response headers (name => value). */
    public array $headers;
    /** @var string|null Validated redirect URI; set only when the error may be sent back to the client. */
    public ?string $redirecturi = null;
    /** @var string State to return with a redirected error. */
    public string $state = '';

    /**
     * Constructor.
     *
     * @param string $error OAuth error code.
     * @param string $description Description safe to show to the client.
     * @param int $status HTTP status.
     * @param array $headers Extra response headers.
     */
    public function __construct(string $error, string $description, int $status = 400, array $headers = []) {
        parent::__construct($error . ': ' . $description);
        $this->error = $error;
        $this->description = $description;
        $this->status = $status;
        $this->headers = $headers;
    }

    /**
     * Marks the error as one that is reported by redirecting to the client.
     *
     * @param string $redirecturi Redirect URI already validated for the client.
     * @param string $state State parameter received.
     * @return self
     */
    public function via_redirect(string $redirecturi, string $state): self {
        $this->redirecturi = $redirecturi;
        $this->state = $state;
        return $this;
    }

    /**
     * Whether the error is reported by redirecting to the client.
     *
     * @return bool
     */
    public function is_redirectable(): bool {
        return $this->redirecturi !== null;
    }
}
