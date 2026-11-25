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
 * Generates catalog/tools.json from the tool definitions, or the catalogue of one
 * whitelisted Pluginia plugin with --component (its pluginiaconnect/tools.json).
 *
 * The files are the source of truth shared with the gateway. Continuous
 * integration runs this script with --check and fails when the committed file
 * differs from the definitions.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use local_pluginiaconnect\extension\loader;
use local_pluginiaconnect\local\mcp\tool_registry;

[$options, $unrecognised] = cli_get_params(
    ['check' => false, 'component' => '', 'help' => false],
    ['h' => 'help']
);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln('Writes catalog/tools.json from the tool definitions of the plugin.');
    cli_writeln('');
    cli_writeln('Options:');
    cli_writeln('  --component=NAME  Write instead the catalogue of a whitelisted Pluginia plugin, to its');
    cli_writeln('                    pluginiaconnect/tools.json. The plugin must be installed.');
    cli_writeln('  --check           Do not write; exit with status 1 when the file differs from the definitions.');
    cli_writeln('  -h, --help        Show this help.');
    exit(0);
}

$component = clean_param((string) $options['component'], PARAM_COMPONENT);
if ($component !== '') {
    if (!array_key_exists($component, loader::components())) {
        cli_error("{$component} is not on the whitelist of the extension point.");
    }
    $report = loader::report()[$component];
    if ($report['status'] !== 'loaded') {
        cli_error("{$component} declares no tool that the connector accepts: {$report['status']}.");
    }
    foreach ($report['rejected'] as $name => $reason) {
        cli_problem("Refused {$name}: {$reason}");
    }
    $file = core_component::get_component_directory($component) . '/pluginiaconnect/tools.json';
    $label = "{$component}/pluginiaconnect/tools.json";
    $expected = loader::catalog_json($component);
    $count = count($report['tools']);
    $command = "php local/pluginiaconnect/cli/export_catalog.php --component={$component}";
} else {
    $file = __DIR__ . '/../catalog/tools.json';
    $label = 'catalog/tools.json';
    $expected = tool_registry::catalog_json();
    $count = count(tool_registry::base());
    $command = 'php local/pluginiaconnect/cli/export_catalog.php';
}

if ($options['check']) {
    $current = is_readable($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : '';
    if ($current !== $expected) {
        cli_error("{$label} is out of date. Run: {$command}");
    }
    cli_writeln("{$label} is up to date.");
    exit(0);
}

if (!is_dir(dirname($file))) {
    mkdir(dirname($file), 0777, true);
}
if (file_put_contents($file, $expected) === false) {
    cli_error("{$label} could not be written.");
}
cli_writeln("{$label} written: {$count} tools.");
