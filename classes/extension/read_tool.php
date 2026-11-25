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

namespace local_pluginiaconnect\extension;

use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Base class of the read tools that a whitelisted Pluginia plugin declares (amendment 2, section 10B).
 *
 * Part of the stable contract of the extension point, version 1. A plugin puts its tools in
 * classes/pluginiaconnect/ and lists them in db/pluginiaconnect.php. The connector runs them through
 * the same chain as its own tools: site switches, tool matrix, profile, scope, context, capabilities,
 * limits, closed output fields and audit. The tool itself does none of that and cannot skip it.
 *
 * What the class adds: the plugin's own availability check (for instance its licence) before every
 * call, and a way to read configuration that only returns the columns the tool names.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class read_tool extends base_tool {
    /**
     * Runs the tool: the plugin's availability check first, then {@see self::run()}.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    final public function execute(array $args, call_context $call): tool_result {
        $problem = static::unavailable_reason();
        if ($problem !== null) {
            throw new tool_exception('tool_disabled', $problem);
        }
        return $this->run($args, $call);
    }

    /**
     * The operation itself, as the current user. Returns unfiltered data: the connector applies the
     * closed list of {@see output_fields()} afterwards.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    abstract protected function run(array $args, call_context $call): tool_result;

    /**
     * Why the plugin cannot answer right now (for instance, it needs an active licence), or null.
     * The text is shown to the user, so it says what to do.
     *
     * @return string|null
     */
    protected static function unavailable_reason(): ?string {
        return null;
    }

    /**
     * Reads one record with only the columns allowed by the tool. There is no way to read the whole record:
     * a column added to the table later never leaves the site until a tool names it (D26).
     *
     * @param string $table Table name without prefix.
     * @param array $conditions Conditions, column => value.
     * @param string[] $columns Columns to return.
     * @return \stdClass|null
     */
    final protected static function columns(string $table, array $conditions, array $columns): ?\stdClass {
        global $DB;
        foreach (array_merge([$table], $columns, array_keys($conditions)) as $name) {
            if (!is_string($name) || !preg_match('/^[a-z][a-z0-9_]*$/', $name)) {
                throw new \coding_exception('Invalid table or column name.');
            }
        }
        $record = $DB->get_record($table, $conditions, implode(', ', $columns));
        return $record ?: null;
    }
}
