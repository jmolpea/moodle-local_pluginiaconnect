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

use local_pluginiaconnect\local\policy\site_policy;

/**
 * Common start-up of the session-less endpoints (MCP, token, registration, revocation, metadata).
 *
 * Entry scripts define NO_MOODLE_COOKIES, NO_DEBUG_DISPLAY and AJAX_SCRIPT, load
 * config.php and then call {@see self::bootstrap()}.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class endpoint {
    /** @var string|null Identifier of the current request. */
    private static ?string $requestid = null;

    /**
     * Identifier of the current request, for error messages and the audit trail.
     *
     * @return string UUID.
     */
    public static function request_id(): string {
        if (self::$requestid === null) {
            self::$requestid = \core\uuid::generate();
        }
        return self::$requestid;
    }

    /**
     * Prepares a session-less endpoint and returns the request. Ends the script
     * with a JSON error when the request cannot be served at all.
     *
     * @return request
     */
    public static function bootstrap(): request {
        set_exception_handler([self::class, 'handle_exception']);
        $request = request::from_globals();
        $refusal = self::precheck($request);
        if ($refusal !== null) {
            $refusal->send();
        }
        return $request;
    }

    /**
     * Checks that apply to every session-less request.
     *
     * @param request $request Request.
     * @return response|null Null when the request may continue.
     */
    public static function precheck(request $request): ?response {
        global $CFG;
        if (!$request->is_https() && !self::is_local_development()) {
            return response::json(400, [
                'error' => 'invalid_request',
                'error_description' => 'HTTPS is required.',
            ]);
        }
        if (!empty($CFG->maintenance_enabled)) {
            return response::json(503, [
                'error' => 'temporarily_unavailable',
                'error_description' => 'The site is in maintenance mode.',
            ], ['Retry-After' => '600']);
        }
        if (!site_policy::is_enabled()) {
            return response::json(503, [
                'error' => 'temporarily_unavailable',
                'error_description' => 'The connector is switched off on this site.',
            ], ['Retry-After' => '3600']);
        }
        if ($request->body_too_large()) {
            return response::json(413, [
                'error' => 'invalid_request',
                'error_description' => 'The request body is larger than 256 KB.',
            ]);
        }
        return null;
    }

    /**
     * Whether plain HTTP is tolerated: only a site addressed as http://localhost with developer debugging on.
     *
     * @return bool
     */
    public static function is_local_development(): bool {
        global $CFG;
        return preg_match('~^http://localhost(:\d+)?(/|$)~', $CFG->wwwroot) === 1
            && isset($CFG->debug) && (int) $CFG->debug === DEBUG_DEVELOPER;
    }

    /**
     * Last-resort exception handler: a JSON error with a request identifier and nothing else.
     * No message, trace or server path leaves the server.
     *
     * @param \Throwable $e Uncaught exception.
     * @return void
     */
    public static function handle_exception(\Throwable $e): void {
        $requestid = self::request_id();
        // The class name and location go to the server log only. Messages are left out: they may quote input.
        // phpcs:ignore moodle.PHP.ForbiddenFunctions.FoundWithAlternative
        error_log('local_pluginiaconnect request ' . $requestid . ': ' . get_class($e) . ' at '
            . basename($e->getFile()) . ':' . $e->getLine());
        response::json(500, [
            'error' => 'server_error',
            'error_description' => 'Unexpected error. Quote request ID ' . $requestid . ' to your administrator.',
        ])->send();
    }
}
