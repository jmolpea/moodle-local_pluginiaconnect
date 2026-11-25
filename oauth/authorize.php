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

/**
 * OAuth authorization endpoint: sign-in and consent.
 *
 * Nothing about the request is kept in the session. The whole request lives in
 * the URL, so after signing in the user comes back to this same URL and every
 * check runs again from scratch.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// The login check comes after the request is validated, so that a bad client is never sent anywhere.
// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../../config.php');

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\code_service;
use local_pluginiaconnect\local\oauth\oauth_exception;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\output\consent_page;

// The page must never be framed, cached or leak its URL (it carries the request and, on return, the code).
header_register_callback(static function (): void {
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
});

$params = [];
foreach (authorization_request::PARAMS as $name) {
    $value = optional_param($name, null, PARAM_RAW);
    if ($value !== null) {
        $params[$name] = $value;
    }
}

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/pluginiaconnect/oauth/authorize.php', $params));
$PAGE->set_pagelayout('login');
$PAGE->set_title(get_string('consent_heading', 'local_pluginiaconnect'));
$PAGE->set_heading(get_string('pluginname', 'local_pluginiaconnect'));

// Shows an explanation page and ends the request. The user is not redirected to the client.
// Parameters: the text to show and whether to offer the sign-in page.
$explain = static function (string $message, bool $offerlogin = false): void {
    global $OUTPUT;
    echo $OUTPUT->header();
    echo $OUTPUT->render_from_template('local_pluginiaconnect/oauth_error', [
        'message' => $message,
        'loginurl' => $offerlogin ? get_login_url() : '',
    ]);
    echo $OUTPUT->footer();
    die;
};

// Sends the browser back to the client and ends the request.
// The URL is an authorization response built from a validated redirect URI.
$leave = static function (string $url): void {
    \core\session\manager::write_close();
    header('Location: ' . str_replace(["\r", "\n"], '', $url), true, 302);
    die;
};

if (!site_policy::is_enabled()) {
    $explain(get_string('consent_error_disabled', 'local_pluginiaconnect'));
}

// 1. Validate the request. Client and redirect URI problems are shown here; the rest goes back to the client.
try {
    $request = authorization_request::from_params($params, (string) getremoteaddr());
} catch (oauth_exception $e) {
    if ($e->is_redirectable()) {
        $leave(authorization_request::exception_url($e));
    }
    $explain(get_string('consent_error_client', 'local_pluginiaconnect'));
}

// 2. The user signs in the usual way of the site, multi-factor authentication included.
require_login(null, false);
if (isguestuser()) {
    $explain(get_string('consent_error_guest', 'local_pluginiaconnect'), true);
}
// A connection must be created by the account owner, never by someone logged in as them.
if (\core\session\manager::is_loggedinas()) {
    $explain(get_string('consent_error_loginas', 'local_pluginiaconnect'));
}

// 3. Who may connect, and with which profiles.
$denial = eligibility::connect_denial($USER);
if ($denial !== null) {
    $explain(get_string('consent_error_' . $denial, 'local_pluginiaconnect'));
}
$cowork = $request->actorid === authorization_request::ACTOR_COWORK;
$actorpolicy = $cowork ? config::string('actorpolicy_copilotcowork') : '';
if ($cowork && !in_array($actorpolicy, ['approval', 'readonly'], true)) {
    // Blocked, or an unknown value: fail closed.
    $explain(get_string('consent_error_actorblocked', 'local_pluginiaconnect'));
}
$profiles = eligibility::eligible_profiles((int) $USER->id);
$canwrite = !config::bool('readonlymode') && $actorpolicy !== 'readonly'
    && eligibility::can_write_somewhere((int) $USER->id)
    // And only when it would add something: with no action that changes data switched on for any of
    // the user's profiles, the option would give a read-only connection under another name.
    && consent_page::offers_changes($request, (int) $USER->id, $profiles);

// 4. Decision. Always explicit: there is no silent approval, even for a returning user.
$error = '';
if (data_submitted()) {
    // A missing key must be an ordinary failure: confirm_sesskey() would throw for an empty value.
    if (!confirm_sesskey(optional_param('sesskey', '-', PARAM_RAW) ?: '-')) {
        $error = get_string('consent_error_sesskey', 'local_pluginiaconnect');
    } else if (optional_param('decision', '', PARAM_ALPHA) !== 'allow') {
        $leave($request->error_url('access_denied', 'The user did not allow the connection.'));
    } else {
        $profile = optional_param('profile', '', PARAM_ALPHA);
        if (!in_array($profile, $profiles, true)) {
            $error = get_string('consent_error_profile', 'local_pluginiaconnect');
        } else {
            $write = $canwrite && optional_param('level', 'read', PARAM_ALPHA) === 'write';
            $scopes = scope::granted(
                $request->scopes,
                $profile,
                $write,
                (int) $USER->id,
                site_policy::scopes_with_active_tools($profile)
            );
            // The scopes of an area are granted only when the user ticked its box (amendment 2, D22).
            $areas = array_intersect(optional_param_array('areas', [], PARAM_ALPHA), array_keys(scope::AREAS));
            $scopes = scope::within_areas($scopes, array_values($areas));
            $leave($request->success_url(code_service::create($request, (int) $USER->id, $profile, $scopes)));
        }
    }
}

// 5. Consent page.
echo $OUTPUT->header();
$consent = new consent_page($request, $USER, $profiles, $canwrite, $PAGE->url, $error);
echo $OUTPUT->render_from_template('local_pluginiaconnect/consent', $consent->export_for_template($OUTPUT));
echo $OUTPUT->footer();
