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
 * Behat fixture: stands in for the return address of an assistant.
 *
 * It only says which OAuth response parameters arrived. It never prints the code.
 *
 * @package    local_pluginiaconnect
 * @category   test
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

// phpcs:ignore moodle.Files.RequireLogin.Missing
require(__DIR__ . '/../../../../../config.php');

defined('BEHAT_SITE_RUNNING') || die('Only available on a Behat test site.');

$PAGE->set_context(context_system::instance());
$PAGE->set_url(new moodle_url('/local/pluginiaconnect/tests/behat/fixtures/callback.php'));
$PAGE->set_pagelayout('embedded');

$code = optional_param('code', '', PARAM_RAW);
$error = optional_param('error', '', PARAM_ALPHANUMEXT);
$state = optional_param('state', '', PARAM_ALPHANUMEXT);
$iss = optional_param('iss', '', PARAM_URL);

echo $OUTPUT->header();
echo html_writer::tag('p', 'Assistant callback reached');
echo html_writer::tag('p', 'Code: ' . ($code !== '' ? 'present' : 'absent'));
echo html_writer::tag('p', 'Error: ' . ($error !== '' ? s($error) : 'none'));
echo html_writer::tag('p', 'State: ' . s($state));
echo html_writer::tag('p', 'Issuer: ' . ($iss !== '' ? 'present' : 'absent'));
echo $OUTPUT->footer();
