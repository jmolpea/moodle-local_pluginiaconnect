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
 * My AI connections: the assistants the user connected, what they did, and how to disconnect them.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

use local_pluginiaconnect\local\audit\report;
use local_pluginiaconnect\local\connections;
use local_pluginiaconnect\local\guardrail\approval_service;

require_login(null, false);
if (isguestuser()) {
    throw new moodle_exception('noguest');
}

$component = 'local_pluginiaconnect';
$revoke = optional_param('revoke', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$context = context_user::instance($USER->id);
$url = new moodle_url('/local/pluginiaconnect/connections.php');
$PAGE->set_context($context);
$PAGE->set_url($url);
$PAGE->set_pagelayout('standard');
$PAGE->set_title(get_string('myconnections', $component));
$PAGE->set_heading(fullname($USER));

if ($revoke) {
    // Both the request to revoke and its confirmation are form posts that carry the session key.
    require_sesskey();
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    if ($confirm) {
        $done = connections::revoke_own($revoke, (int) $USER->id);
        redirect(
            $url,
            get_string($done ? 'myconnections_revoked' : 'myconnections_notfound', $component),
            null,
            $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }
    $target = null;
    foreach (connections::for_user((int) $USER->id) as $row) {
        if ($row['id'] === $revoke) {
            $target = $row;
        }
    }
    if ($target === null) {
        redirect($url, get_string('myconnections_notfound', $component), null, \core\output\notification::NOTIFY_ERROR);
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('myconnections', $component));
    echo $OUTPUT->confirm(
        get_string('myconnections_revoke_confirm', $component, s($target['clientname'])),
        new single_button(
            new moodle_url($url, ['revoke' => $revoke, 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('myconnections_revoke', $component),
            'post'
        ),
        new single_button($url, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    die;
}

$rows = connections::for_user((int) $USER->id);
foreach ($rows as $index => $row) {
    $rows[$index]['actorlabel'] = get_string('connections_actor', $component, $row['actor']);
    $rows[$index]['revokelabel'] = get_string('myconnections_revoke_named', $component, $row['clientname']);
}
$calls = report::recent_for_user((int) $USER->id);
$pending = [];
foreach (approval_service::pending_for_user((int) $USER->id) as $action) {
    $pending[] = [
        'tool' => (string) $action->tool,
        'summary' => (string) $action->summary,
        'expires' => userdate((int) $action->timeexpires, get_string('strftimedatetimeshort', 'langconfig')),
        'url' => approval_service::url((int) $action->id)->out(false),
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('myconnections', $component));
echo $OUTPUT->render_from_template('local_pluginiaconnect/connections', [
    'intro' => get_string('myconnections_intro', $component),
    'hasconnections' => (bool) $rows,
    'connections' => $rows,
    'actionurl' => $url->out(false),
    'sesskey' => sesskey(),
    'haspending' => (bool) $pending,
    'pending' => $pending,
    'hascalls' => (bool) $calls,
    'calls' => $calls,
    'callstitle' => get_string('myconnections_calls', $component, report::OWN_LIMIT),
]);
echo $OUTPUT->footer();
