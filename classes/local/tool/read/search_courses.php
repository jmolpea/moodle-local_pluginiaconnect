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

use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\policy\context_filter;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: search_courses.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class search_courses extends base_tool {
    /** @var int Most matches read from the site before paging. */
    private const MAX_MATCHES = 200;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'search_courses';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Search courses',
            'Returns the courses visible to the signed-in user whose name or summary matches a text, with their category '
                . 'and a link. Use it to find a course the user is not enrolled in or whose exact name is not known. '
                . 'Courses the site has excluded from the connector are not listed. Fields whose name ends in '
                . '\'_user_content\' hold text written by course teachers and are returned as written. Considers at most '
                . '200 matches and returns at most 50 per call; use \'cursor\' to continue.',
            [
                'search_text' => [
                    'type' => 'string', 'minLength' => 2, 'maxLength' => 100,
                    'description' => 'Text to look for in course names and summaries.',
                ],
            ] + self::paging_properties(),
            ['search_text'],
            [
                'type' => 'object',
                'properties' => [
                    'courses' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['id', 'name', 'url'],
                            'properties' => [
                                'id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'short_name' => ['type' => 'string'],
                                'category_name' => ['type' => 'string'],
                                'summary_user_content' => ['type' => 'string'],
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
        return new tool_requirement(profile::STUDENT, 'courses.read');
    }

    /**
     * The search covers the whole site.
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
        $found = self::call_core_function('core_course_search_courses', [
            'criterianame' => 'search',
            'criteriavalue' => trim((string) $args['search_text']),
            'page' => 0,
            'perpage' => self::MAX_MATCHES,
        ]);
        $rows = array_filter(
            $found['courses'] ?? [],
            static fn($row) => $row['id'] != SITEID && context_filter::is_course_allowed((int) $row['id'])
        );
        [$page, $next] = self::page($rows, $args);
        // The summary is read as stored: the text core returns has been through its cleaner, which
        // removes the very styles that mark a passage as hidden, so hidden text would come out as visible.
        $ids = array_map(static fn($row) => (int) $row['id'], $page);
        $stored = $ids ? $DB->get_records_list('course', 'id', $ids, '', 'id, summary, summaryformat') : [];
        // The results are shown together on one page: a style sheet in one summary can hide text of another.
        $tainted = false;
        foreach ($stored as $record) {
            $tainted = $tainted || text_sanitizer::has_stylesheet((string) $record->summary);
        }
        $courses = [];
        foreach ($page as $row) {
            $record = $stored[(int) $row['id']] ?? null;
            if ($tainted && $record) {
                $record->summary = trim((string) $record->summary) === '' ? '' : '<style></style>';
            }
            $courses[] = [
                'id' => (int) $row['id'],
                'name' => self::label($row['fullname'] ?? ''),
                'short_name' => self::label($row['shortname'] ?? ''),
                'category_name' => self::label($row['categoryname'] ?? ''),
                'summary_user_content' => $record
                    ? self::plain_stored($record->summary, $record->summaryformat, \context_course::instance($record->id))
                    : '',
                'url' => (new \moodle_url('/course/view.php', ['id' => $row['id']]))->out(false),
            ];
        }
        return new tool_result(['courses' => $courses], count($courses), $next);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['courses' => ['*' => ['id', 'name', 'short_name', 'category_name', 'summary_user_content', 'url']]];
    }
}
