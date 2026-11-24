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
 * Emergency switch: revokes every connection of every user of this site.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_pluginiaconnect\local\oauth\token_service;

[$options, $unrecognised] = cli_get_params(
    ['help' => false, 'yes' => false, 'disable' => false],
    ['h' => 'help', 'y' => 'yes']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln(get_string('cli_revokeall_help', 'local_pluginiaconnect'));
    exit(0);
}

if (!$options['yes']) {
    $answer = cli_input(get_string('cli_revokeall_confirm', 'local_pluginiaconnect'), 'n', ['y', 'n']);
    if ($answer !== 'y') {
        cli_writeln(get_string('cli_revokeall_cancelled', 'local_pluginiaconnect'));
        exit(1);
    }
}

if ($options['disable']) {
    set_config('enabled', 0, 'local_pluginiaconnect');
    // Same reaction as the settings page: clear cached policy and record the kill-switch event.
    \local_pluginiaconnect\admin\callbacks::killswitch_changed('enabled');
    cli_writeln(get_string('cli_revokeall_disabled', 'local_pluginiaconnect'));
}

$count = token_service::revoke_all('admin');
cli_writeln(get_string('cli_revokeall_done', 'local_pluginiaconnect', $count));
exit(0);
