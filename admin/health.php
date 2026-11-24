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
 * Diagnostics page: checks of the server set-up the connector depends on.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_pluginiaconnect\local\health;

// Requires a signed-in user with moodle/site:config, as declared in settings.php.
admin_externalpage_setup('local_pluginiaconnect_health');

$badges = [
    health::OK => 'badge badge-success bg-success text-white',
    health::INFO => 'badge badge-info bg-info text-white',
    health::WARNING => 'badge badge-warning bg-warning text-dark',
    health::ERROR => 'badge badge-danger bg-danger text-white',
];

$checks = [];
foreach (health::run() as $result) {
    $checks[] = $result + [
        'statuslabel' => get_string('health_status_' . $result['status'], 'local_pluginiaconnect'),
        'badgeclass' => $badges[$result['status']],
        'hasremedy' => $result['remedy'] !== '',
    ];
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('health', 'local_pluginiaconnect'));
echo $OUTPUT->render_from_template('local_pluginiaconnect/health', [
    'intro' => get_string('health_intro', 'local_pluginiaconnect'),
    'checks' => $checks,
]);
echo $OUTPUT->single_button($PAGE->url, get_string('health_runagain', 'local_pluginiaconnect'), 'get');
echo $OUTPUT->footer();
