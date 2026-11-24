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
 * Tool: list_installed_plugins.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_installed_plugins extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_installed_plugins';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List installed plugins',
            'Returns the plugins installed on the site for its administrator, with their component name, display '
                . 'name, release, whether they are enabled and their installation state. Use it to check what is '
                . 'installed or to find plugins that need attention. By default only additional plugins are listed; '
                . 'set \'include_standard\' to list the ones that come with the site software too. Plugin settings '
                . 'are not returned. Returns at most 50 plugins per call; use \'cursor\' to continue.',
            [
                'include_standard' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'Also list the plugins that are part of the standard distribution.',
                ],
            ] + self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'plugins' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'component' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'release' => ['type' => ['string', 'null']],
                                'standard' => ['type' => 'boolean'],
                                'enabled' => ['type' => ['boolean', 'null']],
                                'state' => ['type' => 'string'],
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
        foreach (\core_plugin_manager::instance()->get_plugins() as $plugins) {
            foreach ($plugins as $plugin) {
                $standard = $plugin->is_standard();
                if ($standard && empty($args['include_standard'])) {
                    continue;
                }
                $enabled = $plugin->is_enabled();
                $rows[] = [
                    'component' => (string) $plugin->component,
                    'name' => self::label((string) $plugin->displayname),
                    'release' => $plugin->release !== null ? self::label((string) $plugin->release) : null,
                    'standard' => $standard,
                    'enabled' => $enabled === null ? null : (bool) $enabled,
                    'state' => (string) $plugin->get_status(),
                ];
            }
        }
        usort($rows, static fn($a, $b) => $a['component'] <=> $b['component']);
        [$page, $next] = self::page($rows, $args);
        return new tool_result([
            'plugins' => $page,
            'url' => (new \moodle_url('/admin/plugins.php'))->out(false),
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
        return ['url', 'plugins' => ['*' => ['component', 'name', 'release', 'standard', 'enabled', 'state']]];
    }
}
