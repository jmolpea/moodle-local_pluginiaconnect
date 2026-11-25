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

use local_pluginiaconnect\local\config;

/**
 * Rules shared by the report tools of amendment 2, block C (D25).
 *
 * Every report that reads the logs takes a compulsory date range of at most the site limit (31 days at
 * most), never returns anonymous events, never returns the address or the raw "other" data of an event,
 * and applies separate groups the way the report pages of Moodle do. This class holds those rules so that
 * every report applies them the same way.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report_helper {
    /** @var string Pattern of a strict ISO 8601 date-time with a time zone. */
    private const DATETIME = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

    /**
     * Schema of the two compulsory date arguments of a log report.
     *
     * @return array
     */
    public static function interval_properties(): array {
        return [
            'from' => [
                'type' => 'string', 'format' => 'date-time', 'maxLength' => 40,
                'description' => 'Start of the period, ISO 8601 with a time zone, for example 2027-03-01T00:00:00+01:00.',
            ],
            'to' => [
                'type' => 'string', 'format' => 'date-time', 'maxLength' => 40,
                'description' => 'End of the period, ISO 8601 with a time zone. At most 31 days after the start.',
            ],
        ];
    }

    /**
     * Timestamp of a strict ISO 8601 date-time argument.
     *
     * @param string $value Value sent by the caller.
     * @param string $name Argument name, for the error message.
     * @return int
     */
    public static function timestamp(string $value, string $name): int {
        $date = preg_match(self::DATETIME, $value) ? date_create_immutable($value) : false;
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors && ($errors['warning_count'] || $errors['error_count']))) {
            throw new tool_exception(
                'validation_error',
                "'{$name}' must be an ISO 8601 date-time with a time zone, for example 2027-03-01T00:00:00+01:00."
            );
        }
        $timestamp = $date->getTimestamp();
        if ($timestamp < 946684800 || $timestamp > 4102444800) {
            throw new tool_exception('validation_error', "'{$name}' is outside the accepted range of dates.");
        }
        return $timestamp;
    }

    /**
     * The compulsory period of a log report, checked against the site limit.
     *
     * @param array $args Validated arguments with 'from' and 'to'.
     * @return int[] [from, to] timestamps; 'to' is exclusive.
     */
    public static function interval(array $args): array {
        $from = self::timestamp((string) $args['from'], 'from');
        $to = self::timestamp((string) $args['to'], 'to');
        if ($to <= $from) {
            throw new tool_exception('validation_error', "'to' must be later than 'from'.");
        }
        $days = config::int('logwindowdays');
        if ($to - $from > $days * DAYSECS) {
            throw new tool_exception(
                'limit_exceeded',
                "The period may cover at most {$days} days on this site. Ask for a shorter period."
            );
        }
        return [$from, $to];
    }

    /**
     * Table of the standard log store, the only one the reports read.
     *
     * @return string Table name without prefix.
     * @throws tool_exception conflict when the standard log store is not enabled.
     */
    public static function log_table(): string {
        $readers = get_log_manager()->get_readers('\core\log\sql_internal_table_reader');
        $reader = $readers['logstore_standard'] ?? null;
        if (!$reader) {
            throw new tool_exception(
                'conflict',
                'The standard log of this site is switched off, so there is no activity to report.'
            );
        }
        return $reader->get_internal_log_table_name();
    }

    /**
     * Whether the period starts before the oldest log the site keeps.
     *
     * @param int $from Start of the period.
     * @return bool
     */
    public static function before_retention(int $from): bool {
        $days = (int) get_config('logstore_standard', 'loglifetime');
        return $days > 0 && $from < time() - $days * DAYSECS;
    }

    /**
     * Days of logs the site keeps, 0 for "forever".
     *
     * @return int
     */
    public static function retention_days(): int {
        return max(0, (int) get_config('logstore_standard', 'loglifetime'));
    }

    /**
     * Groups the caller is limited to in an activity, or null when there is no limit. Same rule as
     * {@see people::restricted_groups()} for the group mode of the activity.
     *
     * @param \cm_info $cm Activity.
     * @param \stdClass $caller User the call runs as.
     * @return int[]|null
     */
    public static function activity_groups(\cm_info $cm, \stdClass $caller): ?array {
        $context = \context_module::instance($cm->id);
        if (
            groups_get_activity_groupmode($cm) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context, $caller)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($cm->course, $caller->id, $cm->groupingid, 'g.id')));
    }

    /**
     * Enrolled users of an activity that the caller may see and that hold a capability there, sorted by name.
     *
     * @param \cm_info $cm Activity.
     * @param \stdClass $caller User the call runs as.
     * @param string $capability Capability the listed users hold in the activity, e.g. 'mod/quiz:attempt'.
     * @return \stdClass[] Keyed by user id.
     */
    public static function activity_participants(\cm_info $cm, \stdClass $caller, string $capability): array {
        $groups = self::activity_groups($cm, $caller);
        if ($groups === []) {
            return [];
        }
        $context = \context_module::instance($cm->id);
        $onlyactive = !has_capability('moodle/course:viewsuspendedusers', $context, $caller);
        $users = get_enrolled_users(
            $context,
            $capability,
            $groups ?? 0,
            'u.*',
            'u.lastname, u.firstname, u.id',
            0,
            0,
            $onlyactive
        );
        return array_filter($users, static fn($user) => empty($user->deleted) && !isguestuser($user)
            && (int) $user->id !== (int) $caller->id);
    }

    /**
     * Learners of a course that the caller may see: visible enrolled users who hold a student role there.
     *
     * @param \stdClass $course Course record.
     * @param \context $context Course context.
     * @param \stdClass $caller User the call runs as.
     * @return \stdClass[] Keyed by user id, sorted by name.
     */
    public static function course_learners(\stdClass $course, \context $context, \stdClass $caller): array {
        $visible = people::visible_enrolled($course, $context, $caller);
        if (!$visible) {
            return [];
        }
        $roles = array_keys(get_archetype_roles('student'));
        $learners = [];
        if ($roles) {
            $learners = get_role_users($roles, $context, true, 'ra.id, u.id AS userid', 'u.id', false);
        }
        $ids = [];
        foreach ($learners as $row) {
            $ids[(int) $row->userid] = true;
        }
        return array_filter($visible, static fn($user) => isset($ids[(int) $user->id])
            && (int) $user->id !== (int) $caller->id);
    }

    /**
     * Users of a page, by id, with the fields fullname() needs.
     *
     * @param int[] $ids User ids.
     * @return \stdClass[]
     */
    public static function users(array $ids): array {
        global $DB;
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) {
            return [];
        }
        $fields = \core_user\fields::for_name()->get_sql('', false, '', '', false)->selects;
        return $DB->get_records_list('user', 'id', $ids, '', 'id, deleted, ' . $fields);
    }

    /**
     * Start of each day of a period in the time zone of the caller, plus the end of the period.
     *
     * @param int $from Start of the period.
     * @param int $to End of the period (exclusive).
     * @return int[] Ascending boundaries; consecutive pairs are the days.
     */
    public static function day_boundaries(int $from, int $to): array {
        $zone = \core_date::get_user_timezone_object();
        $day = (new \DateTimeImmutable('@' . $from))->setTimezone($zone)->setTime(0, 0);
        $bounds = [$from];
        while (true) {
            $day = $day->modify('+1 day');
            $start = $day->getTimestamp();
            if ($start >= $to) {
                break;
            }
            $bounds[] = $start;
        }
        $bounds[] = $to;
        return $bounds;
    }

    /**
     * Date of a timestamp in the time zone of the caller, as YYYY-MM-DD.
     *
     * @param int $timestamp Timestamp.
     * @return string
     */
    public static function local_date(int $timestamp): string {
        return (new \DateTimeImmutable('@' . $timestamp))->setTimezone(\core_date::get_user_timezone_object())->format('Y-m-d');
    }
}
