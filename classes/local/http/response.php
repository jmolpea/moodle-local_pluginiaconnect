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

namespace local_pluginiaconnect\local\http;

/**
 * HTTP response of the session-less endpoints.
 *
 * Handlers return a response object instead of printing, so that they can be
 * unit tested; the entry script calls {@see self::send()}.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class response {
    /** @var int HTTP status code. */
    public int $status;
    /** @var array Header name => value. */
    public array $headers;
    /** @var string|null Body, or null for an empty body. */
    public ?string $body;

    /**
     * Constructor.
     *
     * @param int $status HTTP status code.
     * @param array $headers Header name => value.
     * @param string|null $body Body.
     */
    public function __construct(int $status, array $headers = [], ?string $body = null) {
        $this->status = $status;
        $this->headers = $headers;
        $this->body = $body;
    }

    /**
     * JSON response. Never cacheable.
     *
     * @param int $status HTTP status code.
     * @param mixed $data Value to encode.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function json(int $status, $data, array $headers = []): self {
        $body = json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        return new self($status, $headers + [
            'Content-Type' => 'application/json; charset=utf-8',
            'Cache-Control' => 'no-store',
            'Pragma' => 'no-cache',
            'X-Content-Type-Options' => 'nosniff',
        ], $body === false ? '{}' : $body);
    }

    /**
     * Response without body.
     *
     * @param int $status HTTP status code.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function no_content(int $status, array $headers = []): self {
        return new self($status, $headers + ['Cache-Control' => 'no-store', 'X-Content-Type-Options' => 'nosniff']);
    }

    /**
     * OAuth error response as RFC 6749 section 5.2 defines it.
     *
     * @param int $status HTTP status code.
     * @param string $error Error code.
     * @param string $description Human readable description, without internal detail.
     * @param array $headers Extra headers.
     * @return self
     */
    public static function oauth_error(int $status, string $error, string $description, array $headers = []): self {
        return self::json($status, ['error' => $error, 'error_description' => $description], $headers);
    }

    /**
     * Decoded JSON body. For tests.
     *
     * @return mixed
     */
    public function decoded() {
        return $this->body === null ? null : json_decode($this->body, true);
    }

    /**
     * Sends the response and ends the script.
     *
     * @return never
     */
    public function send(): void {
        if (!headers_sent()) {
            foreach ($this->headers as $name => $value) {
                header($name . ': ' . str_replace(["\r", "\n"], '', (string) $value));
            }
            // The status goes last: PHP changes it to 401 when a WWW-Authenticate header is set, and an
            // insufficient scope is answered with that header and 403.
            http_response_code($this->status);
        }
        if ($this->body !== null) {
            echo $this->body;
        }
        die;
    }
}
