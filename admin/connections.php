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
 * Every active connection of the site, with revocation by connection, by user and by client.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_pluginiaconnect\form\connections_filter_form;
use local_pluginiaconnect\local\connections;

admin_externalpage_setup('local_pluginiaconnect_connections');
require_capability('local/pluginiaconnect:manageconnections', context_system::instance());

$component = 'local_pluginiaconnect';
$perpage = 50;
$userid = optional_param('userid', 0, PARAM_INT);
$clientid = optional_param('clientid', 0, PARAM_INT);
$page = max(0, optional_param('page', 0, PARAM_INT));
$action = optional_param('action', '', PARAM_ALPHA);
$target = optional_param('target', 0, PARAM_INT);
$confirm = optional_param('confirm', 0, PARAM_BOOL);

$filterparams = array_filter(['userid' => $userid, 'clientid' => $clientid]);
$baseurl = new moodle_url('/local/pluginiaconnect/admin/connections.php', $filterparams);

if (in_array($action, ['revoke', 'revokeuser', 'revokeclient'], true) && $target > 0) {
    require_sesskey();
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    if ($confirm) {
        if ($action === 'revoke') {
            $count = connections::revoke_one($target) ? 1 : 0;
        } else if ($action === 'revokeuser') {
            $count = connections::revoke_user($target);
        } else {
            $count = connections::revoke_client($target);
        }
        redirect(
            $baseurl,
            get_string('adminconnections_revoked', $component, $count),
            null,
            \core\output\notification::NOTIFY_SUCCESS
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('adminconnections', $component));
    echo $OUTPUT->confirm(
        get_string('adminconnections_confirm_' . $action, $component, $target),
        new single_button(
            new moodle_url($baseurl, ['action' => $action, 'target' => $target, 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('adminconnections_revoke', $component),
            'post'
        ),
        new single_button($baseurl, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    die;
}

$form = new connections_filter_form(
    new moodle_url('/local/pluginiaconnect/admin/connections.php'),
    ['clients' => connections::clients_in_use()]
);
if ($data = $form->get_data()) {
    $params = empty($data->resetbutton) ? array_filter([
        'userid' => (int) $data->userid,
        'clientid' => (int) $data->clientid,
    ]) : [];
    redirect(new moodle_url('/local/pluginiaconnect/admin/connections.php', $params));
}
$form->set_data(['userid' => $userid ?: '', 'clientid' => $clientid]);

$total = connections::count($userid ?: null, $clientid ?: null);
$rows = connections::search($userid ?: null, $clientid ?: null, $page * $perpage, $perpage);
foreach ($rows as $index => $row) {
    $rows[$index]['actorlabel'] = get_string('connections_actor', $component, $row['actor']);
    $rows[$index]['userurl'] = (new moodle_url('/user/profile.php', ['id' => $row['userid']]))->out(false);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('adminconnections', $component));
$form->display();
echo $OUTPUT->render_from_template('local_pluginiaconnect/admin_connections', [
    'total' => get_string('adminconnections_total', $component, $total),
    'hasrows' => (bool) $rows,
    'rows' => $rows,
    'actionurl' => $baseurl->out(false),
    'sesskey' => sesskey(),
]);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
echo $OUTPUT->footer();
