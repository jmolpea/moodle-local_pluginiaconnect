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

use local_pluginiaconnect\local\policy\context_filter;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: list_categories.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_categories extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_categories';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List course categories',
            'Returns the course categories the signed-in user manages, with their full path, parent and number of '
                . 'courses. Use it to find the category identifier that other tools take. Categories the site has '
                . 'excluded from the connector and categories the user does not manage are not listed. Returns at most '
                . '50 categories per call; use \'cursor\' to continue.',
            self::paging_properties(),
            [],
            [
                'type' => 'object',
                'properties' => [
                    'categories' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'path', 'url'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'path' => ['type' => 'string'],
                                'parent_id' => ['type' => ['integer', 'null']],
                                'visible' => ['type' => 'boolean'],
                                'course_count' => ['type' => 'integer'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                        ],
                    ],
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
        return new tool_requirement(profile::MANAGER, 'manage.read');
    }

    /**
     * The tool looks at the whole category tree.
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
        // Only categories where the user holds the manager capability, and that the user can see.
        $paths = \core_course_category::make_categories_list(eligibility::capability(profile::MANAGER));
        $paths = array_filter(
            $paths,
            static fn($id) => context_filter::is_category_allowed((int) $id),
            ARRAY_FILTER_USE_KEY
        );
        $rows = [];
        foreach ($paths as $id => $path) {
            $rows[] = ['id' => (int) $id, 'path' => $path];
        }
        [$page, $next] = self::page($rows, $args);
        $categories = [];
        foreach ($page as $row) {
            $category = \core_course_category::get($row['id'], IGNORE_MISSING);
            if (!$category) {
                continue;
            }
            $categories[] = [
                'id' => $row['id'],
                'path' => self::label($row['path']),
                'parent_id' => $category->parent ? (int) $category->parent : null,
                'visible' => !empty($category->visible),
                'course_count' => (int) $category->get_courses_count(),
                'url' => (new \moodle_url('/course/index.php', ['categoryid' => $row['id']]))->out(false),
            ];
        }
        return new tool_result(['categories' => $categories], count($categories), $next);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::MANAGER)) {
            return [];
        }
        return ['categories' => ['*' => ['id', 'path', 'parent_id', 'visible', 'course_count', 'url']]];
    }
}
