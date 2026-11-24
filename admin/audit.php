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
 * Audit report: every tool call made through the connector, allowed or refused.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/adminlib.php');

use local_pluginiaconnect\form\audit_filter_form;
use local_pluginiaconnect\local\audit\report;

admin_externalpage_setup('local_pluginiaconnect_audit');
require_capability('local/pluginiaconnect:viewaudit', context_system::instance());

$component = 'local_pluginiaconnect';
$perpage = 100;
$page = max(0, optional_param('page', 0, PARAM_INT));
$download = optional_param('download', 0, PARAM_BOOL);
$pageurl = '/local/pluginiaconnect/admin/audit.php';

// The filter form posts its dates as arrays under the names the address uses for plain numbers, so a
// submitted form is handled before the address is read: it ends in a redirect or in its own errors.
$form = new audit_filter_form(new moodle_url($pageurl), [
    'tools' => report::tools_seen(),
    'outcomes' => report::outcomes_seen(),
]);
if ($data = $form->get_data()) {
    $submitted = empty($data->resetbutton) ? report::clean_filters((array) $data) : [];
    redirect(new moodle_url($pageurl, $submitted));
}
$filters = [];
if (!$form->is_submitted()) {
    $filters = report::clean_filters([
        'userid' => optional_param('userid', 0, PARAM_INT),
        'courseid' => optional_param('courseid', 0, PARAM_INT),
        'tool' => optional_param('tool', '', PARAM_ALPHANUMEXT),
        'outcome' => optional_param('outcome', '', PARAM_ALPHAEXT),
        'timefrom' => optional_param('timefrom', 0, PARAM_INT),
        'timeto' => optional_param('timeto', 0, PARAM_INT),
    ]);
}
$baseurl = new moodle_url($pageurl, $filters);

if ($download) {
    require_sesskey();
    $recordset = report::rows($filters);
    \core\dataformat::download_data(
        'pluginia-connect-audit-' . userdate(time(), '%Y%m%d-%H%M', 99, false),
        'csv',
        report::export_columns(),
        $recordset,
        static fn(\stdClass $record): array => report::export_row($record)
    );
    $recordset->close();
    die;
}

$form->set_data($filters);

$total = report::count($filters);
$rows = [];
$recordset = report::rows($filters, $page * $perpage, $perpage);
foreach ($recordset as $record) {
    $rows[] = report::describe($record);
}
$recordset->close();
$summary = report::summary($filters);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string('audit', $component));
$form->display();
echo $OUTPUT->render_from_template('local_pluginiaconnect/audit', [
    'intro' => get_string('audit_intro', $component),
    'total' => get_string('audit_total', $component, $total),
    'hassummary' => (bool) $summary,
    'summary' => $summary,
    'hasrows' => (bool) $rows,
    'rows' => $rows,
    'downloadurl' => (new moodle_url($baseurl, ['download' => 1, 'sesskey' => sesskey()]))->out(false),
]);
echo $OUTPUT->paging_bar($total, $page, $perpage, $baseurl);
echo $OUTPUT->footer();
