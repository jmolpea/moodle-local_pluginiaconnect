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
 * Generates catalog/tools.json from the tool definitions.
 *
 * The file is the source of truth shared with the gateway. Continuous
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

use local_pluginiaconnect\local\mcp\tool_registry;

[$options, $unrecognised] = cli_get_params(['check' => false, 'help' => false], ['h' => 'help']);

if ($unrecognised) {
    cli_error(get_string('cliunknowoption', 'admin', implode(PHP_EOL . '  ', $unrecognised)));
}

if ($options['help']) {
    cli_writeln('Writes catalog/tools.json from the tool definitions of the plugin.');
    cli_writeln('');
    cli_writeln('Options:');
    cli_writeln('  --check     Do not write; exit with status 1 when the file differs from the definitions.');
    cli_writeln('  -h, --help  Show this help.');
    exit(0);
}

$file = __DIR__ . '/../catalog/tools.json';
$expected = tool_registry::catalog_json();

if ($options['check']) {
    $current = is_readable($file) ? str_replace("\r\n", "\n", (string) file_get_contents($file)) : '';
    if ($current !== $expected) {
        cli_error('catalog/tools.json is out of date. Run: php local/pluginiaconnect/cli/export_catalog.php');
    }
    cli_writeln('catalog/tools.json is up to date.');
    exit(0);
}

if (file_put_contents($file, $expected) === false) {
    cli_error('catalog/tools.json could not be written.');
}
cli_writeln('catalog/tools.json written: ' . count(tool_registry::all()) . ' tools.');
