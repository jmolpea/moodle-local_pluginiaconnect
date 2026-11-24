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
 * Registration of the site with the Pluginia Connect gateway.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_pluginiaconnect\form\register_form;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\gateway\http_transport;
use local_pluginiaconnect\local\gateway\registration;
use local_pluginiaconnect\local\gateway\site_identity;

admin_externalpage_setup('local_pluginiaconnect_register');
require_capability('moodle/site:config', context_system::instance());

$component = 'local_pluginiaconnect';
$url = new moodle_url('/local/pluginiaconnect/admin/register.php');
$unregister = optional_param('unregister', 0, PARAM_BOOL);
$confirm = optional_param('confirm', 0, PARAM_BOOL);
$state = site_identity::state();

if ($unregister) {
    require_sesskey();
    if (!data_submitted()) {
        throw new moodle_exception('invalidrequest', 'error');
    }
    if ($confirm) {
        [$confirmed, $message, $revoked] = registration::unregister();
        redirect(
            $url,
            get_string($message, $component, $revoked),
            null,
            $confirmed ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_WARNING
        );
    }
    echo $OUTPUT->header();
    echo $OUTPUT->heading(get_string('register', $component));
    echo $OUTPUT->confirm(
        get_string('unregister_confirm', $component),
        new single_button(
            new moodle_url($url, ['unregister' => 1, 'confirm' => 1, 'sesskey' => sesskey()]),
            get_string('unregister_submit', $component),
            'post'
        ),
        new single_button($url, get_string('cancel'), 'get')
    );
    echo $OUTPUT->footer();
    die;
}

$blockers = registration::blockers();
$form = null;
if ($state === site_identity::STATE_NONE && !$blockers) {
    $form = new register_form($url, ['studentprofile' => config::bool('allowstudentprofile')]);
    if ($data = $form->get_data()) {
        [$done, $message] = registration::register((string) $data->licencekey, !empty($data->nounder13), !empty($data->terms));
        redirect(
            $url,
            get_string($message, $component),
            null,
            $done ? \core\output\notification::NOTIFY_SUCCESS : \core\output\notification::NOTIFY_ERROR
        );
    }
}

$blockertexts = [];
foreach ($blockers as $key) {
    $blockertexts[] = get_string($key, $component);
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('register', $component));
echo $OUTPUT->render_from_template('local_pluginiaconnect/register', [
    'intro' => get_string('register_intro', $component),
    'gateway' => http_transport::base_url(),
    'statetext' => get_string('register_state_' . $state, $component),
    'isregistered' => $state === site_identity::STATE_REGISTERED,
    'ispending' => $state === site_identity::STATE_UNREGISTER_PENDING,
    'canregister' => $form !== null,
    'siteid' => site_identity::site_id(),
    'registeredon' => site_identity::time_registered()
        ? userdate(site_identity::time_registered(), get_string('strftimedatetimeshort', 'langconfig'))
        : '',
    'hasblockers' => $state === site_identity::STATE_NONE && (bool) $blockertexts,
    'blockers' => $blockertexts,
    'data' => registration::preview(),
    'actionurl' => $url->out(false),
    'sesskey' => sesskey(),
]);
if ($form !== null) {
    $form->display();
}
echo $OUTPUT->footer();
