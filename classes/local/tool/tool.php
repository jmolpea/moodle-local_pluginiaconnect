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

namespace local_pluginiaconnect\local\tool;

/**
 * Contract of a catalogue tool.
 *
 * A tool declares what it needs and performs one operation as the current user.
 * It does not check profiles, scopes or limits and does not format the final
 * response: the call pipeline does that.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
interface tool {
    /**
     * Stable tool name, e.g. 'list_my_courses'.
     *
     * @return string
     */
    public static function name(): string;

    /**
     * Title, description, input and output schema and annotations. Static data only.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition;

    /**
     * Minimum profile, scope, Moodle capabilities, minimum approval mode.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement;

    /**
     * Moodle context affected by these (already validated) arguments.
     *
     * @param array $args Validated arguments.
     * @return \context
     * @throws tool_exception With code 'not_found' when the target does not exist.
     */
    public function resolve_context(array $args): \context;

    /**
     * Write tools only: server-built description of the exact change. No side effects.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string;

    /**
     * Runs the operation as the current user and returns unfiltered data.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result;

    /**
     * Closed list of output fields allowed for the given profile.
     *
     * Format: a list of scalar field names; a field holding an object maps its
     * name to a nested list; a field holding a list of objects maps its name to
     * ['*' => nested list].
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array;
}
