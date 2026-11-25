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
 * Tool 62: forums of a course.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_course_forums extends base_tool {
    /** @var string[] Forum types as stored. */
    public const TYPES = ['news', 'social', 'general', 'eachuser', 'single', 'qanda', 'blog'];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_course_forums';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List course forums',
            'Returns the forums of one course the signed-in user teaches that they can read: name, type, number of '
                . 'discussions, date of the last post and whether the user may start a discussion there. Use it to find '
                . 'the forum to read or post in; the forum identifier is the one the other forum tools take.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
            ],
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'forums' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'forum_id' => ['type' => 'integer'],
                                'name' => ['type' => 'string'],
                                'type' => ['type' => 'string', 'enum' => self::TYPES],
                                'discussions' => ['type' => 'integer'],
                                'last_post_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'can_start_discussion' => ['type' => 'boolean'],
                                'url' => ['type' => 'string', 'format' => 'uri'],
                            ],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Requirements. Reading each forum is checked forum by forum.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'forums.read');
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
        global $CFG, $DB;
        require_once($CFG->dirroot . '/mod/forum/lib.php');
        $course = self::course((int) $args['course_id']);
        $forums = [];
        foreach (get_fast_modinfo($course)->get_instances_of('forum') as $cm) {
            $context = \context_module::instance($cm->id);
            if (!$cm->uservisible || !has_capability('mod/forum:viewdiscussion', $context, $call->user)) {
                continue;
            }
            $forum = $DB->get_record('forum', ['id' => $cm->instance], '*', MUST_EXIST);
            $stats = $DB->get_record_sql(
                'SELECT COUNT(1) AS discussions, MAX(timemodified) AS lastpost FROM {forum_discussions} WHERE forum = :forum',
                ['forum' => $forum->id]
            );
            $forums[] = [
                'forum_id' => (int) $cm->id,
                'name' => self::label($cm->get_formatted_name()),
                'type' => in_array($forum->type, self::TYPES, true) ? (string) $forum->type : 'general',
                'discussions' => (int) $stats->discussions,
                'last_post_at' => self::iso($stats->lastpost ? (int) $stats->lastpost : null),
                'can_start_discussion' => $forum->type !== 'single'
                    && forum_user_can_post_discussion($forum, null, -1, $cm, $context),
                'url' => (new \moodle_url('/mod/forum/view.php', ['id' => $cm->id]))->out(false),
            ];
        }
        return new tool_result(
            ['course_id' => (int) $course->id, 'forums' => $forums],
            count($forums),
            null,
            'course',
            (int) $course->id
        );
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'forums' => ['*' => [
            'forum_id', 'name', 'type', 'discussions', 'last_post_at', 'can_start_discussion', 'url',
        ]]];
    }
}
