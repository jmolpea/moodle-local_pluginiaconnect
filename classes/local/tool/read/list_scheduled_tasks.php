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

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_scheduled_tasks.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_scheduled_tasks extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_scheduled_tasks';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List scheduled tasks',
            'Returns the scheduled tasks of the site for its administrator, with the component each belongs to, when '
                . 'it last ran, when it runs next, whether it is disabled and whether it is failing. Use it to find '
                . 'tasks that do not run or keep failing. Set \'only_failing\' to list just those. Task output and '
                . 'logs are not returned. Returns at most 50 tasks per call; use \'cursor\' to continue.',
            [
                'only_failing' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'List only tasks whose last run failed.',
                ],
            ] + self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'component' => ['type' => 'string'],
                                'last_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'next_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'disabled' => ['type' => 'boolean'],
                                'failing' => ['type' => 'boolean'],
                            ],
                        ],
                    ],
                    'url' => ['type' => 'string', 'format' => 'uri'],
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
        return new tool_requirement(profile::ADMIN, 'site.read', ['moodle/site:config']);
    }

    /**
     * The tool looks at the site.
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
        $rows = [];
        foreach (\core\task\manager::get_all_scheduled_tasks() as $task) {
            $failing = $task->get_fail_delay() > 0;
            if (!empty($args['only_failing']) && !$failing) {
                continue;
            }
            $rows[] = [
                'name' => self::label($task->get_name()),
                'component' => (string) $task->get_component(),
                'last_run' => self::iso((int) $task->get_last_run_time()),
                'next_run' => $task->get_disabled() ? null : self::iso((int) $task->get_next_run_time()),
                'disabled' => (bool) $task->get_disabled(),
                'failing' => $failing,
            ];
        }
        usort($rows, static fn($a, $b) => [$a['component'], $a['name']] <=> [$b['component'], $b['name']]);
        [$page, $next] = self::page($rows, $args);
        return new tool_result([
            'tasks' => $page,
            'url' => (new \moodle_url('/admin/tool/task/scheduledtasks.php'))->out(false),
        ], count($page), $next);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if ($profile !== profile::ADMIN) {
            return [];
        }
        return ['url', 'tasks' => ['*' => ['name', 'component', 'last_run', 'next_run', 'disabled', 'failing']]];
    }
}
