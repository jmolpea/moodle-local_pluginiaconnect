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

namespace local_pluginiaconnect\local\audit;

use local_pluginiaconnect\local\config;

/**
 * Read side of the audit trail: the administrator report and the user's own recent calls.
 *
 * The trail holds metadata only. The hash of the arguments is an integrity aid
 * and is never shown: it is not content and must not look like content.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class report {
    /** @var string Audit table. */
    private const TABLE = 'local_pluginiaconnect_audit';
    /** @var string[] Outcomes the summary counts as refusals. */
    public const REFUSALS = ['denied', 'rate_limited', 'error'];
    /** @var int Rows of the user's own list. */
    public const OWN_LIMIT = 50;

    /**
     * Cleans raw filter values.
     *
     * @param array $raw Values from the filter form or the page parameters.
     * @return array With keys userid, tool, courseid, outcome, timefrom, timeto; unset filters are absent.
     */
    public static function clean_filters(array $raw): array {
        $filters = [];
        foreach (['userid', 'courseid', 'timefrom', 'timeto'] as $name) {
            if (!empty($raw[$name]) && (int) $raw[$name] > 0) {
                $filters[$name] = (int) $raw[$name];
            }
        }
        foreach (['tool' => '/^[a-z0-9_.-]{1,64}$/', 'outcome' => '/^[a-z_]{1,30}$/'] as $name => $pattern) {
            if (isset($raw[$name]) && is_string($raw[$name]) && preg_match($pattern, $raw[$name])) {
                $filters[$name] = $raw[$name];
            }
        }
        return $filters;
    }

    /**
     * Rows of the report, newest first.
     *
     * @param array $filters Clean filters, see {@see self::clean_filters()}.
     * @param int $limitfrom First row.
     * @param int $limitnum Number of rows; 0 for all.
     * @return \moodle_recordset Records with the audit fields plus user names, course name and client name.
     */
    public static function rows(array $filters, int $limitfrom = 0, int $limitnum = 0): \moodle_recordset {
        global $DB;
        [$where, $params] = self::where($filters);
        $names = \core_user\fields::for_name()->get_sql('u', false, 'user', '', false)->selects;
        $sql = "SELECT a.id, a.requestid, a.userid, a.grantid, a.clientid, a.tool, a.profile, a.iswrite, a.courseid,
                       a.objecttype, a.objectid, a.outcome, a.reason, a.itemcount, a.durationms, a.ip, a.timecreated,
                       c.name AS clientname, co.fullname AS coursename, $names
                  FROM {" . self::TABLE . "} a
             LEFT JOIN {local_pluginiaconnect_client} c ON c.id = a.clientid
             LEFT JOIN {course} co ON co.id = a.courseid
             LEFT JOIN {user} u ON u.id = a.userid
                 WHERE $where
              ORDER BY a.timecreated DESC, a.id DESC";
        return $DB->get_recordset_sql($sql, $params, $limitfrom, $limitnum);
    }

    /**
     * Number of rows matching the filters.
     *
     * @param array $filters Clean filters.
     * @return int
     */
    public static function count(array $filters): int {
        global $DB;
        [$where, $params] = self::where($filters);
        return $DB->count_records_sql("SELECT COUNT(a.id) FROM {" . self::TABLE . "} a WHERE $where", $params);
    }

    /**
     * Refusals among the rows matching the filters, grouped by outcome and reason.
     *
     * @param array $filters Clean filters.
     * @return array[] Rows with outcome, reason and total, most frequent first.
     */
    public static function summary(array $filters): array {
        global $DB;
        [$where, $params] = self::where($filters);
        [$insql, $inparams] = $DB->get_in_or_equal(self::REFUSALS, SQL_PARAMS_NAMED, 'ref');
        $sql = "SELECT a.outcome, a.reason, COUNT(a.id) AS total
                  FROM {" . self::TABLE . "} a
                 WHERE $where AND a.outcome $insql
              GROUP BY a.outcome, a.reason
              ORDER BY COUNT(a.id) DESC, a.outcome, a.reason";
        $summary = [];
        $recordset = $DB->get_recordset_sql($sql, $params + $inparams);
        foreach ($recordset as $record) {
            $summary[] = [
                'outcome' => (string) $record->outcome,
                'reason' => (string) ($record->reason ?? ''),
                'total' => (int) $record->total,
            ];
        }
        $recordset->close();
        return $summary;
    }

    /**
     * Tool names present in the trail, for the filter.
     *
     * @return string[] Name => name.
     */
    public static function tools_seen(): array {
        global $DB;
        $names = $DB->get_fieldset_sql("SELECT DISTINCT tool FROM {" . self::TABLE . "} ORDER BY tool");
        return array_combine($names, $names);
    }

    /**
     * Outcomes present in the trail, for the filter.
     *
     * @return string[] Outcome => outcome.
     */
    public static function outcomes_seen(): array {
        global $DB;
        $names = $DB->get_fieldset_sql("SELECT DISTINCT outcome FROM {" . self::TABLE . "} ORDER BY outcome");
        return array_combine($names, $names);
    }

    /**
     * The last calls made in the name of one user, for their own page.
     *
     * @param int $userid User id.
     * @return array[] Rows as {@see self::describe()} returns them.
     */
    public static function recent_for_user(int $userid): array {
        $rows = [];
        $recordset = self::rows(['userid' => $userid], 0, self::OWN_LIMIT);
        foreach ($recordset as $record) {
            $rows[] = self::describe($record);
        }
        $recordset->close();
        return $rows;
    }

    /**
     * Turns a record into display values. The hash of the arguments is never included.
     *
     * @param \stdClass $record Record of {@see self::rows()}.
     * @return array
     */
    public static function describe(\stdClass $record): array {
        $user = (object) ['id' => $record->userid];
        foreach (\core_user\fields::for_name()->get_required_fields() as $field) {
            $user->$field = $record->{'user' . $field} ?? '';
        }
        $hasname = trim(implode('', array_diff_key((array) $user, ['id' => 0]))) !== '';
        return [
            'id' => (int) $record->id,
            'time' => userdate($record->timecreated, get_string('strftimedatetimeshort', 'langconfig')),
            'timecreated' => (int) $record->timecreated,
            'userid' => (int) $record->userid,
            'user' => $hasname ? fullname($user) : get_string('audit_userdeleted', config::COMPONENT, (int) $record->userid),
            'client' => $record->clientname === null
                ? get_string('audit_clientdeleted', config::COMPONENT, (int) $record->clientid)
                : (string) $record->clientname,
            'tool' => (string) $record->tool,
            'profile' => (string) $record->profile,
            'kind' => get_string(empty($record->iswrite) ? 'audit_kind_read' : 'audit_kind_write', config::COMPONENT),
            'courseid' => $record->courseid === null ? '' : (int) $record->courseid,
            'course' => $record->coursename === null ? '' : format_string($record->coursename, true, ['escape' => false]),
            'outcome' => (string) $record->outcome,
            'reason' => (string) ($record->reason ?? ''),
            'items' => $record->itemcount === null ? '' : (int) $record->itemcount,
            'duration' => (int) $record->durationms,
            'ip' => (string) ($record->ip ?? ''),
            'requestid' => (string) $record->requestid,
            'refused' => in_array($record->outcome, self::REFUSALS, true),
        ];
    }

    /**
     * Columns of the CSV export: key => heading.
     *
     * @return string[]
     */
    public static function export_columns(): array {
        $columns = [];
        foreach (
            ['time', 'userid', 'user', 'client', 'tool', 'profile', 'kind', 'courseid', 'course', 'outcome', 'reason',
                'items', 'duration', 'ip', 'requestid'] as $key
        ) {
            $columns[$key] = get_string('audit_col_' . $key, config::COMPONENT);
        }
        return $columns;
    }

    /**
     * One export row, in the order of {@see self::export_columns()}.
     *
     * Values a spreadsheet could read as a formula are neutralised.
     *
     * @param \stdClass $record Record of {@see self::rows()}.
     * @return array
     */
    public static function export_row(\stdClass $record): array {
        $described = self::describe($record);
        $row = [];
        foreach (array_keys(self::export_columns()) as $key) {
            $value = (string) $described[$key];
            if ($value !== '' && strpbrk($value[0], "=+-@\t\r") !== false) {
                $value = "'" . $value;
            }
            $row[$key] = $value;
        }
        return $row;
    }

    /**
     * WHERE clause of the report.
     *
     * @param array $filters Clean filters.
     * @return array [sql, params]
     */
    private static function where(array $filters): array {
        $where = ['1 = 1'];
        $params = [];
        foreach (['userid', 'courseid', 'tool', 'outcome'] as $name) {
            if (isset($filters[$name])) {
                $where[] = "a.$name = :$name";
                $params[$name] = $filters[$name];
            }
        }
        if (isset($filters['timefrom'])) {
            $where[] = 'a.timecreated >= :timefrom';
            $params['timefrom'] = $filters['timefrom'];
        }
        if (isset($filters['timeto'])) {
            $where[] = 'a.timecreated <= :timeto';
            $params['timeto'] = $filters['timeto'];
        }
        return [implode(' AND ', $where), $params];
    }
}
