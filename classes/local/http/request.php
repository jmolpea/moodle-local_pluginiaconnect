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
 * Incoming HTTP request of the session-less endpoints.
 *
 * This is the only class of the plugin that reads PHP superglobals and the raw
 * request body. Session-less endpoints receive JSON bodies, bearer tokens and
 * client credentials that Moodle's parameter helpers cannot deliver, so the
 * access is centralised here and every value is treated as untrusted by callers.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class request {
    /** @var int Maximum accepted body size in bytes. */
    public const MAX_BODY = 262144;

    /** @var string HTTP method in upper case. */
    private string $method;
    /** @var array Header values keyed by lower-case name. */
    private array $headers;
    /** @var string Raw body, already limited in size. */
    private string $body;
    /** @var bool Whether the body exceeded the size limit. */
    private bool $bodytoolarge;
    /** @var array Query string parameters. */
    private array $query;
    /** @var bool Whether the request arrived over HTTPS. */
    private bool $https;
    /** @var string Remote address as seen by Moodle. */
    private string $ip;

    /**
     * Constructor. Use {@see self::from_globals()} in entry scripts and this in tests.
     *
     * @param string $method HTTP method.
     * @param array $headers Header name => value.
     * @param string $body Raw body.
     * @param array $query Query parameters.
     * @param bool $https Whether the request is HTTPS.
     * @param string $ip Remote address.
     */
    public function __construct(
        string $method,
        array $headers = [],
        string $body = '',
        array $query = [],
        bool $https = true,
        string $ip = '0.0.0.0'
    ) {
        $this->method = strtoupper($method);
        $this->headers = [];
        foreach ($headers as $name => $value) {
            if (is_string($value)) {
                $this->headers[strtolower($name)] = $value;
            }
        }
        $this->bodytoolarge = strlen($body) > self::MAX_BODY;
        $this->body = $this->bodytoolarge ? '' : $body;
        $this->query = $query;
        $this->https = $https;
        $this->ip = $ip;
    }

    /**
     * Builds the request from the PHP environment.
     *
     * @return self
     */
    public static function from_globals(): self {
        // phpcs:disable moodle.Security.Superglobals, moodle.Files.RequireLogin
        $headers = [];
        foreach ($_SERVER as $key => $value) {
            if (is_string($value) && str_starts_with($key, 'HTTP_')) {
                $headers[str_replace('_', '-', strtolower(substr($key, 5)))] = $value;
            }
        }
        foreach (['CONTENT_TYPE' => 'content-type', 'CONTENT_LENGTH' => 'content-length'] as $key => $name) {
            if (isset($_SERVER[$key]) && is_string($_SERVER[$key])) {
                $headers[$name] = $_SERVER[$key];
            }
        }
        // Some PHP-FPM and CGI set-ups drop the Authorization header; look where they may have left it.
        if (!isset($headers['authorization'])) {
            if (isset($_SERVER['REDIRECT_HTTP_AUTHORIZATION']) && is_string($_SERVER['REDIRECT_HTTP_AUTHORIZATION'])) {
                $headers['authorization'] = $_SERVER['REDIRECT_HTTP_AUTHORIZATION'];
            } else if (function_exists('apache_request_headers')) {
                foreach ((array) apache_request_headers() as $name => $value) {
                    if (strtolower($name) === 'authorization' && is_string($value)) {
                        $headers['authorization'] = $value;
                    }
                }
            }
        }
        $method = isset($_SERVER['REQUEST_METHOD']) && is_string($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';
        $length = isset($headers['content-length']) ? (int) $headers['content-length'] : 0;
        $body = '';
        if ($length > self::MAX_BODY) {
            // Do not read an oversized body at all: mark it with one byte over the limit.
            $body = str_repeat(' ', self::MAX_BODY + 1);
        } else if (in_array(strtoupper($method), ['POST', 'PUT', 'PATCH'], true)) {
            $body = (string) file_get_contents('php://input', false, null, 0, self::MAX_BODY + 1);
        }
        $query = [];
        foreach ($_GET as $name => $value) {
            if (is_string($name) && is_string($value)) {
                $query[$name] = $value;
            }
        }
        // phpcs:enable
        return new self($method, $headers, $body, $query, is_https(), (string) getremoteaddr());
    }

    /**
     * HTTP method in upper case.
     *
     * @return string
     */
    public function method(): string {
        return $this->method;
    }

    /**
     * Header value, or null.
     *
     * @param string $name Header name, any case.
     * @return string|null
     */
    public function header(string $name): ?string {
        return $this->headers[strtolower($name)] ?? null;
    }

    /**
     * Media type of the body, lower case and without parameters.
     *
     * @return string
     */
    public function content_type(): string {
        $value = $this->header('content-type') ?? '';
        return strtolower(trim(explode(';', $value)[0]));
    }

    /**
     * Bearer token of the Authorization header, or null. Tokens are never taken from the URL.
     *
     * @return string|null
     */
    public function bearer_token(): ?string {
        $value = $this->header('authorization') ?? '';
        if (preg_match('/^Bearer\s+([A-Za-z0-9._~+\/-]+=*)$/i', trim($value), $matches)) {
            return $matches[1];
        }
        return null;
    }

    /**
     * Credentials of an HTTP Basic Authorization header, or null.
     *
     * @return array|null [client id, client secret], both form-url-decoded as RFC 6749 section 2.3.1 requires.
     */
    public function basic_credentials(): ?array {
        $value = $this->header('authorization') ?? '';
        if (!preg_match('/^Basic\s+([A-Za-z0-9+\/]+=*)$/i', trim($value), $matches)) {
            return null;
        }
        $decoded = base64_decode($matches[1], true);
        if ($decoded === false || !str_contains($decoded, ':')) {
            return null;
        }
        [$id, $secret] = explode(':', $decoded, 2);
        return [urldecode($id), urldecode($secret)];
    }

    /**
     * Whether the body exceeded the size limit.
     *
     * @return bool
     */
    public function body_too_large(): bool {
        return $this->bodytoolarge;
    }

    /**
     * Raw body.
     *
     * @return string
     */
    public function body(): string {
        return $this->body;
    }

    /**
     * Body decoded as JSON, or null when it is not valid UTF-8 JSON.
     *
     * @return mixed Decoded value (objects as associative arrays), or null.
     */
    public function json() {
        if ($this->body === '' || !mb_check_encoding($this->body, 'UTF-8')) {
            return null;
        }
        try {
            return json_decode($this->body, true, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return null;
        }
    }

    /**
     * Body decoded as application/x-www-form-urlencoded. Array values are dropped.
     *
     * @return array Name => string value.
     */
    public function form(): array {
        $parsed = [];
        parse_str($this->body, $parsed);
        $form = [];
        foreach ($parsed as $name => $value) {
            if (is_string($name) && is_string($value) && mb_check_encoding($value, 'UTF-8')) {
                $form[$name] = $value;
            }
        }
        return $form;
    }

    /**
     * Query string parameter, or null.
     *
     * @param string $name Parameter name.
     * @return string|null
     */
    public function query(string $name): ?string {
        return $this->query[$name] ?? null;
    }

    /**
     * Whether the request arrived over HTTPS.
     *
     * @return bool
     */
    public function is_https(): bool {
        return $this->https;
    }

    /**
     * Remote address.
     *
     * @return string
     */
    public function ip(): string {
        return $this->ip;
    }
}
