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
 * Approval of a change that an AI assistant requested in the user's name.
 *
 * Nothing is changed by opening this page. The change is applied only when the
 * user it belongs to submits the form with their own session; it is then
 * authorised again from scratch and runs as that user.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_pluginiaconnect\form\approval_form;
use local_pluginiaconnect\local\guardrail\action_store;
use local_pluginiaconnect\local\guardrail\approval_service;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\tool\tool_exception;

$id = required_param('id', PARAM_INT);
$component = 'local_pluginiaconnect';

require_login(null, false);
if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$url = new moodle_url('/local/pluginiaconnect/approve.php', ['id' => $id]);
$back = new moodle_url('/local/pluginiaconnect/connections.php');
$PAGE->set_context(context_user::instance($USER->id));
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('approval_title', $component));
$PAGE->set_heading(fullname($USER));
// The page must never be framed and its content is never cached. The headers are set when the
// response starts, after the ones Moodle sends by default.
header_register_callback(static function (): void {
    header('X-Frame-Options: DENY');
    header("Content-Security-Policy: frame-ancestors 'none'");
    header('Referrer-Policy: no-referrer');
    header('Cache-Control: no-store');
});

$notice = static function (string $message, string $type) use ($OUTPUT, $back, $component): void {
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('approval_title', $component));
    echo $OUTPUT->notification($message, $type, false);
    echo $OUTPUT->single_button($back, get_string('myconnections', $component), 'get');
    echo $OUTPUT->footer();
    die;
};

// A change is approved by the person it belongs to, never by somebody signed in as them.
if (\core\session\manager::is_loggedinas()) {
    $notice(get_string('approval_loginas', $component), \core\output\notification::NOTIFY_ERROR);
}

// An action of another user does not exist for this user.
$action = approval_service::load($id, (int) $USER->id);
if ($action === null) {
    $notice(get_string('approval_notfound', $component), \core\output\notification::NOTIFY_ERROR);
}
action_store::tidy((int) $action->grantid);
$action = approval_service::load($id, (int) $USER->id);
$state = approval_service::state($action);
if ($state !== 'pending') {
    $failure = '';
    if ($state === 'failed') {
        $stored = action_store::stored_result($action);
        $failure = ' ' . s((string) ($stored['error']['message'] ?? ''));
    }
    $notice(
        get_string('approval_state_' . $state, $component) . $failure,
        $state === 'executed' ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_INFO
    );
}

// The summary shown is the change as it would be applied now, after every permission check.
try {
    $summary = approval_service::current_summary($action, $USER);
} catch (tool_exception $e) {
    $notice(
        get_string('approval_nolonger', $component) . ' ' . s($e->getMessage()),
        \core\output\notification::NOTIFY_ERROR
    );
}
$summaryhash = hash('sha256', $summary);

$form = new approval_form($url, ['id' => $id, 'summaryhash' => $summaryhash]);
$changed = false;
if ($data = $form->get_data()) {
    if (!empty($data->reject)) {
        approval_service::reject($id, $USER);
        redirect($back, get_string('approval_state_rejected', $component), null, \core\output\notification::NOTIFY_INFO);
    }
    if (!empty($data->approve)) {
        [$outcome, $message] = approval_service::approve(
            $id,
            $USER,
            (string) $data->summaryhash,
            \core\uuid::generate(),
            getremoteaddr()
        );
        if ($outcome === 'executed') {
            redirect($back, get_string('approval_state_executed', $component), null, \core\output\notification::NOTIFY_SUCCESS);
        }
        if ($outcome === 'changed') {
            // The data moved between showing and approving: show the current summary and ask again.
            $changed = true;
            $form = new approval_form($url, ['id' => $id, 'summaryhash' => $summaryhash]);
        } else {
            $text = $outcome === 'failed'
                ? get_string('approval_state_failed', $component) . ' ' . s($message)
                : get_string('approval_state_' . ($outcome === 'missing' ? 'expired' : $outcome), $component);
            redirect($back, $text, null, \core\output\notification::NOTIFY_ERROR);
        }
    }
}

$grant = approval_service::grant_of($action);
$client = $grant ? client_repository::get_by_id($grant->clientid) : null;
$requester = $client ? $client->name : get_string('message_connectioncreated_unknownclient', $component);
if ($grant && $grant->actor !== null && $grant->actor !== '') {
    $requester = get_string('approval_requester_actor', $component, (object) ['client' => $requester, 'actor' => $grant->actor]);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('approval_title', $component));
echo $OUTPUT->render_from_template('local_pluginiaconnect/approval', [
    'intro' => get_string('approval_intro', $component),
    'changed' => $changed,
    'summary' => $summary,
    'tool' => (string) $action->tool,
    'requester' => $requester,
    'requested' => userdate((int) $action->timecreated),
    'expires' => userdate((int) $action->timeexpires),
]);
$form->display();
echo $OUTPUT->footer();
