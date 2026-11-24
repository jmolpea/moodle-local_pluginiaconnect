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
 * Tool matrix: which tools each profile may use, and the approval mode of each write tool.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_pluginiaconnect\form\tools_form;
use local_pluginiaconnect\local\tools_matrix;

admin_externalpage_setup('local_pluginiaconnect_tools');
require_capability('moodle/site:config', context_system::instance());

$component = 'local_pluginiaconnect';
$url = new moodle_url('/local/pluginiaconnect/admin/tools.php');
$form = new tools_form($url, ['rows' => tools_matrix::rows()]);

if ($data = $form->get_data()) {
    [$cells, $approvals] = $form->to_matrix($data);
    $changed = tools_matrix::save($cells, $approvals);
    redirect(
        $url,
        get_string('tools_saved', $component, $changed),
        null,
        \core\output\notification::NOTIFY_SUCCESS
    );
}

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('tools', $component));
echo html_writer::tag('p', get_string('tools_intro', $component));
$form->display();
echo $OUTPUT->footer();
