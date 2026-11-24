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

namespace local_pluginiaconnect\local\tool\write;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 30: creates an empty course in a category. The course is always created hidden.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class create_course extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'create_course';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Create course',
            'Creates one empty course, with the default settings of the site, in a course category the user manages. '
                . 'The course is always created hidden from students; making it visible is done on the site.'
                . self::two_step_text(true),
            [
                'category_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Category to create the course in, as returned by 'list_categories'.",
                ],
                'full_name' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 254, 'description' => 'Full name.'],
                'short_name' => [
                    'type' => 'string', 'minLength' => 1, 'maxLength' => 100,
                    'description' => 'Short name. It must not be in use by another course.',
                ],
            ] + self::control_properties(),
            ['category_id', 'full_name', 'short_name'],
            self::result_schema([
                'course_id' => ['type' => 'integer'],
                'full_name' => ['type' => 'string'],
                'short_name' => ['type' => 'string'],
                'category_id' => ['type' => 'integer'],
                'visible' => ['type' => 'boolean'],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            false,
            false
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::MANAGER,
            'manage.write',
            ['moodle/course:create'],
            true,
            tool_requirement::APPROVAL_CONFIRM
        );
    }

    /**
     * The category the change acts on.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        $context = \context_coursecat::instance((int) $args['category_id'], IGNORE_MISSING);
        if (!$context) {
            throw new tool_exception('not_found', "Category {$args['category_id']} was not found or is not visible to you.");
        }
        return $context;
    }

    /**
     * Checks the category and the names.
     *
     * @param array $args Validated arguments.
     * @return array Plan: category, fullname, shortname.
     */
    private static function plan(array $args): array {
        global $DB;
        $category = \core_course_category::get((int) $args['category_id'], IGNORE_MISSING);
        if (!$category) {
            throw new tool_exception('not_found', "Category {$args['category_id']} was not found or is not visible to you.");
        }
        $fullname = self::caller_text((string) $args['full_name'], 254);
        $shortname = self::caller_text((string) $args['short_name'], 100);
        if ($fullname === '' || $shortname === '') {
            throw new tool_exception('validation_error', "'full_name' and 'short_name' must contain visible text.");
        }
        if ($DB->record_exists('course', ['shortname' => $shortname])) {
            throw new tool_exception('conflict', "'short_name' is already in use by another course.");
        }
        return ['category' => $category, 'fullname' => $fullname, 'shortname' => $shortname];
    }

    /**
     * Description of the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $plan = self::plan($args);
        return "Create the course '{$plan['fullname']}' (short name: {$plan['shortname']}) in the category '"
            . self::label($plan['category']->get_formatted_name()) . "'. It will be empty and hidden from students.";
    }

    /**
     * Applies the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $CFG;
        require_once($CFG->dirroot . '/course/lib.php');
        $plan = self::plan($args);
        require_capability('moodle/course:create', \context_coursecat::instance($plan['category']->id));
        // The course is created hidden whatever the site default is: showing it is a decision made on the site.
        $course = create_course((object) [
            'fullname' => $plan['fullname'],
            'shortname' => $plan['shortname'],
            'category' => (int) $plan['category']->id,
            'visible' => 0,
        ]);
        return new tool_result([
            'course_id' => (int) $course->id,
            'full_name' => $plan['fullname'],
            'short_name' => $plan['shortname'],
            'category_id' => (int) $plan['category']->id,
            'visible' => false,
            'url' => (new \moodle_url('/course/view.php', ['id' => $course->id]))->out(false),
        ], 1, null, 'course', (int) $course->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['course_id', 'full_name', 'short_name', 'category_id', 'visible', 'url'];
    }
}
