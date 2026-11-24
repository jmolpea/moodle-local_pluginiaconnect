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

use core_external\external_api;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\text_sanitizer;

/**
 * Shared helpers of the catalogue tools.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class base_tool implements tool {
    /** @var int Default page size. */
    protected const DEFAULT_LIMIT = 20;

    /**
     * Read tools have no preview.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        return null;
    }

    /**
     * Calls a core external function the way the core web service server does:
     * resolves it, validates the parameters, calls the implementation and cleans
     * the return value.
     *
     * external_api::call_external_function() cannot be used here: it refuses any
     * script that runs without session cookies. The function name must be a
     * literal of the calling tool, never data that came from the client.
     *
     * @param string $function External function name, e.g. 'core_enrol_get_users_courses'.
     * @param array $args Named parameters.
     * @return mixed Cleaned return value.
     */
    protected static function call_core_function(string $function, array $args) {
        global $PAGE, $COURSE, $SITE;

        $info = external_api::external_function_info($function);
        $params = external_api::validate_parameters($info->parameters_desc, $args);

        // External functions change the page context, so each call gets its own page.
        $savedpage = $PAGE;
        $savedcourse = $COURSE;
        try {
            $PAGE = new \moodle_page();
            $COURSE = clone($SITE);
            $result = call_user_func_array([$info->classname, $info->methodname], array_values($params));
            if ($info->returns_desc !== null) {
                $result = external_api::clean_returnvalue($info->returns_desc, $result);
            }
            return $result;
        } finally {
            $PAGE = $savedpage;
            $COURSE = $savedcourse;
        }
    }

    /**
     * Loads a course or fails with 'not_found'.
     *
     * @param int $courseid Course id.
     * @return \stdClass Course record.
     */
    protected static function course(int $courseid): \stdClass {
        global $DB;
        $course = $courseid == SITEID ? false : $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            throw self::course_not_found($courseid);
        }
        return $course;
    }

    /**
     * The error used both for a missing course and for one the user cannot see.
     *
     * @param int $courseid Course id.
     * @return tool_exception
     */
    public static function course_not_found(int $courseid): tool_exception {
        return new tool_exception('not_found', "Course {$courseid} was not found or is not visible to you.");
    }

    /**
     * ISO 8601 date-time in UTC, or null for an unset timestamp.
     *
     * @param int|null $timestamp Unix timestamp.
     * @return string|null
     */
    protected static function iso(?int $timestamp): ?string {
        return $timestamp ? gmdate('Y-m-d\TH:i:s\Z', $timestamp) : null;
    }

    /**
     * Plain text of user-written rich text, with hidden content removed.
     *
     * @param string|null $html Rich text.
     * @return string
     */
    protected static function plain(?string $html): string {
        return text_sanitizer::to_plain((string) $html);
    }

    /**
     * Plain text of rich text taken as it is stored, with hidden content removed.
     *
     * Core functions return rich text already cleaned, and cleaning drops the very
     * styles that reveal hidden text (display, visibility, position). Text written
     * by users is therefore read as stored, passed through the site filters without
     * cleaning (so multi-language content resolves) and only then sanitised: the
     * result is plain text, so nothing unsafe survives.
     *
     * @param string|null $stored Text as stored in the database.
     * @param int|string|null $format Format of the stored text.
     * @param \context $context Context the text belongs to.
     * @return string
     */
    protected static function plain_stored(?string $stored, $format, \context $context): string {
        if ($stored === null || $stored === '') {
            return '';
        }
        $html = format_text($stored, $format === null ? FORMAT_HTML : (int) $format, [
            'context' => $context, 'noclean' => true, 'para' => false, 'filter' => true,
        ]);
        return text_sanitizer::to_plain($html);
    }

    /**
     * Plain text of a name or title that Moodle formatted for HTML output.
     *
     * @param string|null $text Formatted name.
     * @return string
     */
    protected static function label(?string $text): string {
        $text = html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        return text_sanitizer::clean_plain(preg_replace('/\s+/u', ' ', $text), 500);
    }

    /**
     * A link taken from a core function, kept only when it is a page of this site.
     *
     * Every link in a result belongs to the site: the gateway refuses an answer with any other. Core
     * gives site addresses for activities and calendar events; if it ever gave an empty or a foreign
     * one, the fallback is returned instead.
     *
     * @param string|null $url Address given by core.
     * @param \moodle_url $fallback Page of the site to link to otherwise.
     * @return string
     */
    protected static function own_url(?string $url, \moodle_url $fallback): string {
        global $CFG;
        $url = (string) $url;
        $root = rtrim($CFG->wwwroot, '/') . '/';
        return $url !== '' && str_starts_with($url, $root) ? $url : $fallback->out(false);
    }

    /**
     * Page size asked by the caller, within the site maximum.
     *
     * @param array $args Validated arguments.
     * @return int
     */
    protected static function limit(array $args): int {
        $limit = (int) ($args['limit'] ?? static::DEFAULT_LIMIT);
        return max(1, min($limit, config::int('maxpagesize')));
    }

    /**
     * Position encoded in the cursor of the caller; 0 without cursor.
     *
     * @param array $args Validated arguments.
     * @return int
     */
    protected static function position(array $args): int {
        if (!isset($args['cursor']) || $args['cursor'] === '') {
            return 0;
        }
        $json = base64_decode(strtr($args['cursor'], '-_', '+/'), true);
        $data = $json === false ? null : json_decode($json, true);
        if (
            !is_array($data) || count($data) !== 2 || ($data[0] ?? null) !== static::name()
            || !is_int($data[1] ?? null) || $data[1] < 0
        ) {
            throw new tool_exception('validation_error', "'cursor' is not valid. Use the value returned as 'next_cursor'.");
        }
        return $data[1];
    }

    /**
     * Opaque cursor for a position.
     *
     * @param int $position Position of the next page.
     * @return string
     */
    protected static function cursor(int $position): string {
        return rtrim(strtr(base64_encode(json_encode([static::name(), $position])), '+/', '-_'), '=');
    }

    /**
     * Cuts one page out of a full list.
     *
     * @param array $items Full list.
     * @param array $args Validated arguments.
     * @return array [page items, next cursor or null]
     */
    protected static function page(array $items, array $args): array {
        $limit = self::limit($args);
        $offset = self::position($args);
        $page = array_slice(array_values($items), $offset, $limit);
        $next = $offset + $limit < count($items) ? self::cursor($offset + $limit) : null;
        return [$page, $next];
    }

    /**
     * Schema of the paging arguments shared by list tools.
     *
     * @return array
     */
    protected static function paging_properties(): array {
        return [
            'limit' => [
                'type' => 'integer', 'minimum' => 1, 'maximum' => 50, 'default' => static::DEFAULT_LIMIT,
                'description' => 'Maximum number of items to return.',
            ],
            'cursor' => [
                'type' => 'string', 'maxLength' => 200,
                'description' => "Value of 'next_cursor' from a previous call, to continue from there.",
            ],
        ];
    }

    /**
     * Whether the tool returns records about people other than the signed-in user.
     * Such tools are subject to the hourly exposure budget of the connection.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return false;
    }

    /**
     * Loads an activity of a given type by its course module id, or fails with 'not_found'.
     *
     * @param string $modname Module name, e.g. 'assign'.
     * @param int $cmid Course module id, as returned by get_course_outline.
     * @param string $label Word used in the error message, e.g. 'Assignment'.
     * @return array [course module record, course record]
     */
    protected static function activity(string $modname, int $cmid, string $label): array {
        $cm = get_coursemodule_from_id($modname, $cmid);
        if (!$cm) {
            throw self::activity_not_found($label, $cmid);
        }
        return [$cm, self::course((int) $cm->course)];
    }

    /** @var string Message for anything below a course that is missing or that the user cannot see. */
    public const ITEM_NOT_FOUND = 'The requested item was not found or is not visible to you.';

    /**
     * The error used for a missing activity, for one the user cannot see and for one in a course the
     * user cannot enter. The message is the same in the three cases, whatever the kind of activity and
     * the identifier, so that the answers cannot be told apart.
     *
     * @param string $label Kind of activity, e.g. 'Assignment'. Not used in the message.
     * @param int $id Identifier given by the caller. Not used in the message.
     * @return tool_exception
     */
    protected static function activity_not_found(string $label, int $id): tool_exception {
        return new tool_exception('not_found', self::ITEM_NOT_FOUND);
    }

    /**
     * Fails with 'not_found' when the signed-in user cannot see an activity on the course page.
     *
     * @param \stdClass $course Course record.
     * @param int $cmid Course module id.
     * @param string $label Word used in the error message.
     * @return \cm_info
     */
    protected static function visible_activity(\stdClass $course, int $cmid, string $label): \cm_info {
        $cms = get_fast_modinfo($course)->get_cms();
        if (!isset($cms[$cmid]) || !$cms[$cmid]->uservisible) {
            throw self::activity_not_found($label, $cmid);
        }
        return $cms[$cmid];
    }
}
