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

namespace local_pluginiaconnect\local;

use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\tool\tool_exception;

/**
 * Loads the user behind an access token and makes them the current user of the request.
 *
 * It repeats every check the core web service server makes when it authenticates
 * a token, and adds the two that only require_login() makes (forced password
 * change and pending site policies). No cookie and no persistent session are used.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class user_session {
    /**
     * Loads and installs the user of a grant.
     *
     * A user who is deleted, suspended, unconfirmed, a guest or set to "no login"
     * can never use the connector again with this grant: the grant is revoked and
     * null is returned, which the endpoint answers with 401.
     *
     * @param grant_context $grant Grant behind the token.
     * @return \stdClass|null User record, or null when the grant is no longer usable.
     */
    public static function load(grant_context $grant): ?\stdClass {
        global $DB, $CFG;

        $user = $DB->get_record('user', ['id' => $grant->userid, 'deleted' => 0]);
        if (
            !$user || isguestuser($user) || empty($user->confirmed) || !empty($user->suspended)
            || $user->auth === 'nologin' || $user->mnethostid != $CFG->mnet_localhost_id
        ) {
            self::revoke_grant($grant->grantid);
            return null;
        }

        // Same sequence as webservice_base_server::authenticate_by_token().
        enrol_check_plugins($user, false);
        \core\session\manager::set_user($user);
        set_login_session_preferences();

        return $user;
    }

    /**
     * Reason why the current user must first do something on the site, if any.
     *
     * @param \stdClass $user User installed by {@see self::load()}.
     * @return tool_exception|null Error with code 'user_action_required', or null.
     */
    public static function pending_action(\stdClass $user): ?tool_exception {
        $auth = get_auth_plugin($user->auth);
        if (!empty($auth->config->expiration) && $auth->config->expiration == 1) {
            if ((int) $auth->password_expire($user->username) < 0) {
                return new tool_exception('user_action_required', 'Your password has expired. Sign in to the site and change it.');
            }
        }
        if (get_user_preferences('auth_forcepasswordchange', false, $user)) {
            return new tool_exception('user_action_required', 'Sign in to the site and change your password.');
        }
        if (empty($user->policyagreed) && !is_siteadmin($user)) {
            $manager = new \core_privacy\local\sitepolicy\manager();
            if ($manager->is_defined(false)) {
                return new tool_exception('user_action_required', 'Sign in to the site and accept the updated policies.');
            }
        }
        if (user_not_fully_set_up($user, true)) {
            return new tool_exception('user_action_required', 'Sign in to the site and complete your profile.');
        }
        return null;
    }

    /**
     * Maps an exception thrown by require_login() with redirects disabled.
     *
     * @param \Throwable $exception Exception thrown by require_login().
     * @return tool_exception|null 'user_action_required' when the user has to act on the site; null otherwise.
     */
    public static function from_login_exception(\Throwable $exception): ?tool_exception {
        $code = $exception instanceof \moodle_exception ? $exception->errorcode : '';
        if ($code === 'forcepasswordchangenotice') {
            return new tool_exception('user_action_required', 'Sign in to the site and change your password.');
        }
        if ($code === 'sitepolicynotagreed') {
            return new tool_exception('user_action_required', 'Sign in to the site and accept the updated policies.');
        }
        if ($code === 'usernotfullysetup') {
            return new tool_exception('user_action_required', 'Sign in to the site and complete your profile.');
        }
        return null;
    }

    /**
     * Revokes a grant through the token service, or directly when that class is not available.
     *
     * @param int $grantid Grant id.
     * @return void
     */
    public static function revoke_grant(int $grantid): void {
        global $DB;
        $service = '\\local_pluginiaconnect\\local\\oauth\\token_service';
        if (class_exists($service) && method_exists($service, 'revoke_grant')) {
            $service::revoke_grant($grantid, 'system');
            return;
        }
        $now = time();
        $DB->set_field_select(
            'local_pluginiaconnect_token',
            'timerevoked',
            $now,
            'grantid = :grantid AND timerevoked IS NULL',
            ['grantid' => $grantid]
        );
        $DB->update_record('local_pluginiaconnect_grant', (object) [
            'id' => $grantid, 'status' => 0, 'revokedby' => 'system', 'timerevoked' => $now,
        ]);
    }
}
