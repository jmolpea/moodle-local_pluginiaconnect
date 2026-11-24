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

namespace local_pluginiaconnect\local\policy;

use local_pluginiaconnect\local\config;

/**
 * Site scope filter: which categories and courses the connector may touch.
 *
 * Exclusion always wins over inclusion. A course in an excluded category, or
 * outside the allowed categories when that list is not empty, is out of scope.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class context_filter {
    /**
     * Whether a category is inside the scope allowed by the site.
     *
     * @param int $categoryid Course category id.
     * @return bool
     */
    public static function is_category_allowed(int $categoryid): bool {
        global $DB;
        $path = $DB->get_field('course_categories', 'path', ['id' => $categoryid]);
        if ($path === false) {
            return false;
        }
        $ancestors = array_map('intval', array_filter(explode('/', $path), static fn($part) => $part !== ''));
        if (array_intersect($ancestors, config::ids('excludedcategories'))) {
            return false;
        }
        $allowed = config::ids('allowedcategories');
        return !$allowed || (bool) array_intersect($ancestors, $allowed);
    }

    /**
     * Whether a course is inside the scope allowed by the site.
     *
     * @param int $courseid Course id.
     * @return bool
     */
    public static function is_course_allowed(int $courseid): bool {
        global $DB;
        if ($courseid == SITEID) {
            return true;
        }
        $course = $DB->get_record('course', ['id' => $courseid], 'id, category, visible');
        if (!$course || in_array((int) $course->id, config::ids('excludedcourses'), true)) {
            return false;
        }
        if (!$course->visible && !config::bool('includehiddencourses')) {
            return false;
        }
        return self::is_category_allowed((int) $course->category);
    }

    /**
     * Whether a Moodle context is inside the scope allowed by the site.
     *
     * System and user contexts are always in scope; what can be done there is
     * decided by profiles and capabilities.
     *
     * @param \context $context Context to check.
     * @return bool
     */
    public static function is_context_allowed(\context $context): bool {
        if ($context->contextlevel == CONTEXT_COURSECAT) {
            return self::is_category_allowed((int) $context->instanceid);
        }
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext) {
            return self::is_course_allowed((int) $coursecontext->instanceid);
        }
        return true;
    }

    /**
     * Removes from a list of course ids those that are out of scope.
     *
     * @param int[] $courseids Course ids.
     * @return int[]
     */
    public static function filter_courses(array $courseids): array {
        return array_values(array_filter(array_map('intval', $courseids), static fn($id) => self::is_course_allowed($id)));
    }
}
