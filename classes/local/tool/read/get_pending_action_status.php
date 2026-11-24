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

namespace local_pluginiaconnect\local\tool\read;

use local_pluginiaconnect\local\guardrail\action_store;
use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_pending_action_status.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_pending_action_status extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_pending_action_status';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get pending action status',
            'Returns the state of one change requested earlier through this connection that needs the user\'s approval '
                . 'on the site: pending, executed, rejected, expired or failed, with the summary of the change and '
                . 'the page where the user approves or rejects it. Use it after a tool answered \'approval_pending\' '
                . 'to learn the outcome. It does not approve or change anything. Only actions created by this same '
                . 'connection can be read.',
            [
                'action_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Identifier of the pending action, as returned by the tool that requested it.',
                ],
            ],
            ['action_id'],
            [
                'type' => 'object',
                'properties' => [
                    'action_id' => ['type' => 'integer'],
                    'tool' => ['type' => 'string'],
                    'state' => ['type' => 'string'],
                    'summary' => ['type' => 'string'],
                    'requested_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'resolved_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'approval_url' => ['type' => ['string', 'null'], 'format' => 'uri'],
                    'result' => ['type' => ['object', 'null'], 'properties' => self::result_properties()],
                    'failure' => ['type' => ['string', 'null']],
                ],
            ]
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::STUDENT, 'profile');
    }

    /**
     * The action belongs to the user's own connection.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $DB;
        $actionid = (int) $args['action_id'];
        // An action of another user or of another connection does not exist for this caller.
        $action = $DB->get_record('local_pluginiaconnect_action', [
            'id' => $actionid,
            'userid' => (int) $call->user->id,
            'grantid' => $call->grant->grantid,
            'mode' => 'approval',
        ]);
        if (!$action) {
            throw new tool_exception('not_found', "Pending action {$actionid} was not found.");
        }
        $state = (string) $action->status;
        if ($state === 'pending' && $action->timeexpires < time()) {
            // Reported as expired without waiting for the clean-up task; nothing is written here.
            $state = 'expired';
        }
        // The outcome of an applied action is kept for 24 hours; a failed one says why in a few words.
        $stored = action_store::stored_result($action);
        return new tool_result([
            'action_id' => $actionid,
            'tool' => (string) $action->tool,
            'state' => $state,
            'summary' => text_sanitizer::clean_plain((string) $action->summary, 4000),
            'requested_at' => self::iso((int) $action->timecreated),
            'expires_at' => self::iso((int) $action->timeexpires),
            'resolved_at' => self::iso((int) $action->timeresolved),
            'approval_url' => $state === 'pending'
                ? (new \moodle_url('/local/pluginiaconnect/approve.php', ['id' => $actionid]))->out(false) : null,
            'result' => $state === 'executed' && isset($stored['data']) && is_array($stored['data']) ? $stored['data'] : null,
            'failure' => $state === 'failed' && isset($stored['error']['message'])
                ? text_sanitizer::clean_plain((string) $stored['error']['message'], 500) : null,
        ], 1, null, 'action', $actionid);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['action_id', 'tool', 'state', 'summary', 'requested_at', 'expires_at', 'resolved_at', 'approval_url',
            'result' => self::result_fields($profile), 'failure'];
    }

    /**
     * Schema properties the outcome of an applied action may carry: the union of the result properties
     * of the write tools. A property two tools declare with different types accepts the types of both.
     *
     * @return array Property name => schema.
     */
    private static function result_properties(): array {
        $properties = [];
        foreach (tool_registry::all() as $class) {
            if (!$class::requirement()->write) {
                continue;
            }
            foreach ($class::definition()->dataschema['properties'] ?? [] as $name => $schema) {
                if (!isset($properties[$name])) {
                    $properties[$name] = $schema;
                    continue;
                }
                $types = array_values(array_unique(array_merge(
                    (array) ($properties[$name]['type'] ?? []),
                    (array) ($schema['type'] ?? [])
                )));
                $properties[$name]['type'] = count($types) === 1 ? $types[0] : $types;
            }
        }
        return $properties;
    }

    /**
     * Fields the outcome of an applied action may carry: the union of the output fields of the
     * write tools. The list stays closed even though the outcome depends on the tool.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    private static function result_fields(string $profile): array {
        $fields = [];
        foreach (tool_registry::all() as $class) {
            if (!$class::requirement()->write) {
                continue;
            }
            foreach ($class::output_fields($profile) as $key => $value) {
                if (is_int($key)) {
                    if (!in_array($value, $fields, true)) {
                        $fields[] = $value;
                    }
                } else {
                    $fields[$key] = $value;
                }
            }
        }
        return $fields;
    }
}
