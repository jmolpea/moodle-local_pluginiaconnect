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
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_course_outline.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_outline extends base_tool {
    /** @var int Default number of sections per page. */
    protected const DEFAULT_LIMIT = 20;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_outline';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course outline',
            'Returns the sections of one course and, for each section, the activities and resources the signed-in user '
                . 'can see, with their type, dates and a link. Use it to see how a course is organised or to find an '
                . 'activity. File contents are not returned. Fields whose name ends in \'_user_content\' hold text '
                . 'written by course participants or teachers and are returned as written. Returns at most 50 sections '
                . 'per call; use \'cursor\' to continue.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ] + self::paging_properties(),
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'course_name' => ['type' => 'string'],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                    'sections' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'required' => ['number', 'name', 'url'],
                            'properties' => [
                                'number' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'summary_user_content' => ['type' => 'string'],
                                'visible' => ['type' => 'boolean'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
                                'activities' => [
                                    'type' => 'array',
                                    'items' => [
                                        'type' => 'object',
                                        'required' => ['id', 'name', 'type', 'url'],
                                        'properties' => [
                                            'id' => ['type' => 'integer'],
                                            'name' => ['type' => 'string'],
                                            'type' => ['type' => 'string'],
                                            'visible' => ['type' => 'boolean'],
                                            'description_user_content' => ['type' => 'string'],
                                            'url' => ['type' => 'string', 'format' => 'uri'],
                                            'dates' => [
                                                'type' => 'array',
                                                'items' => [
                                                    'type' => 'object',
                                                    'properties' => [
                                                        'label' => ['type' => 'string'],
                                                        'at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                                    ],
                                                ],
                                            ],
                                        ],
                                    ],
                                ],
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
     * The tool acts on one course.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        $course = self::course((int) $args['course_id']);
        $courseurl = new \moodle_url('/course/view.php', ['id' => $course->id]);
        $rows = self::call_core_function('core_course_get_contents', [
            'courseid' => (int) $course->id,
            'options' => [['name' => 'excludecontents', 'value' => '1']],
        ]);
        $rows = array_filter($rows, static fn($row) => !array_key_exists('uservisible', $row) || $row['uservisible']);
        // The whole course page is one document for the browser: a style sheet in one summary or description
        // can hide text of any other. When any of them carries one, no author text of the page is returned.
        $tainted = false;
        foreach ($rows as $row) {
            $tainted = $tainted || text_sanitizer::has_stylesheet((string) ($row['summary'] ?? ''));
            foreach ($row['modules'] ?? [] as $module) {
                $tainted = $tainted || text_sanitizer::has_stylesheet((string) ($module['description'] ?? ''));
            }
        }
        $text = static function (?string $html) use ($tainted): string {
            if ($tainted) {
                return trim((string) $html) === '' ? '' : text_sanitizer::STYLESHEET_NOTE;
            }
            return self::plain($html);
        };
        [$page, $next] = self::page($rows, $args);
        // Activities that are available but not shown on the course page stay out, as they do on the page.
        $seesstealth = has_capability('moodle/course:viewhiddenactivities', $call->context, $call->user);

        $sections = [];
        $count = 0;
        foreach ($page as $row) {
            $sectionurl = new \moodle_url($courseurl, ['section' => (int) ($row['section'] ?? 0)]);
            $activities = [];
            foreach ($row['modules'] ?? [] as $module) {
                if (array_key_exists('uservisible', $module) && !$module['uservisible']) {
                    continue;
                }
                if (!$seesstealth && array_key_exists('visibleoncoursepage', $module) && !$module['visibleoncoursepage']) {
                    continue;
                }
                $dates = [];
                foreach ($module['dates'] ?? [] as $date) {
                    $dates[] = [
                        'label' => self::label(rtrim((string) ($date['label'] ?? ''), ': ')),
                        'at' => self::iso((int) ($date['timestamp'] ?? 0)),
                    ];
                }
                $activities[] = [
                    'id' => (int) $module['id'],
                    'name' => self::label((string) $module['name']),
                    'type' => (string) $module['modname'],
                    'visible' => !empty($module['visible']),
                    'description_user_content' => $text($module['description'] ?? ''),
                    'url' => self::own_url((string) ($module['url'] ?? ''), $sectionurl),
                    'dates' => $dates,
                ];
                $count++;
            }
            $sections[] = [
                'number' => (int) ($row['section'] ?? 0),
                'name' => self::label((string) $row['name']),
                'summary_user_content' => $text($row['summary'] ?? ''),
                'visible' => !isset($row['visible']) || !empty($row['visible']),
                'url' => $sectionurl->out(false),
                'activities' => $activities,
            ];
        }
        return new tool_result([
            'course_id' => (int) $course->id,
            'course_name' => self::label(format_string($course->fullname, true, ['context' => $call->context])),
            'url' => $courseurl->out(false),
            'sections' => $sections,
        ], $count, $next, 'course', (int) $course->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return [
            'course_id', 'course_name', 'url',
            'sections' => ['*' => [
                'number', 'name', 'summary_user_content', 'visible', 'url',
                'activities' => ['*' => [
                    'id', 'name', 'type', 'visible', 'description_user_content', 'url',
                    'dates' => ['*' => ['label', 'at']],
                ]],
            ]],
        ];
    }
}
