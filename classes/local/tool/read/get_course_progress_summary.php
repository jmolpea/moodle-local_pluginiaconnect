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
use local_pluginiaconnect\local\tool\assign_helper;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\report_helper;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Progress of the learners of a course as figures only (amendment 2, block C, tool 67B).
 *
 * Answers "how are my learners doing, what percentage of progress, how many are behind" without naming
 * anyone: average and median progress, a distribution by ranges, completions, learners who never entered,
 * inactive learners and learners with overdue work. The names behind each figure come from
 * get_course_completion_report, list_inactive_participants and list_overdue_submissions.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_course_progress_summary extends base_tool {
    /** @var array Ranges of the distribution: name => [lowest, highest] percentage. */
    private const RANGES = [
        'not_started' => [0, 0],
        'from_1_to_24' => [0.0001, 24.9999],
        'from_25_to_49' => [25, 49.9999],
        'from_50_to_74' => [50, 74.9999],
        'from_75_to_99' => [75, 99.9999],
        'all_done' => [100, 100],
    ];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_course_progress_summary';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get course progress summary',
            'Returns figures about the learners of one course the signed-in user teaches, without naming anyone: how '
                . 'many there are, average and median progress percentage, how many are in each range of progress, how '
                . 'many completed the course, never entered it, have not entered for a number of days, and have overdue '
                . 'assignments. Use it to answer how a course or a group is doing; the names come from the completion, '
                . 'inactivity and overdue tools. With separate groups only the user\'s own groups are counted.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Identifier of the course.'],
                'group_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => 'Count only the members of this group of the course.',
                ],
                'inactive_days' => [
                    'type' => 'integer', 'minimum' => 1, 'maximum' => 365, 'default' => 14,
                    'description' => 'Days without entering the course after which a learner counts as inactive.',
                ],
            ],
            ['course_id'],
            [
                'type' => 'object',
                'properties' => [
                    'course_id' => ['type' => 'integer'],
                    'group_id' => ['type' => ['integer', 'null']],
                    'learners' => ['type' => 'integer'],
                    'completion_enabled' => ['type' => 'boolean'],
                    'tracked_activities' => ['type' => 'integer'],
                    'average_progress_percent' => ['type' => ['number', 'null']],
                    'median_progress_percent' => ['type' => ['number', 'null']],
                    'progress_ranges' => [
                        'type' => 'object',
                        'properties' => array_map(static fn() => ['type' => 'integer'], self::RANGES),
                    ],
                    'course_completed' => ['type' => 'integer'],
                    'never_entered' => ['type' => ['integer', 'null']],
                    'inactive' => ['type' => ['integer', 'null']],
                    'inactive_days' => ['type' => 'integer'],
                    'with_overdue_assignments' => ['type' => 'integer'],
                    'overdue_assignments_total' => ['type' => 'integer'],
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
        return new tool_requirement(profile::TEACHER, 'reports.read', ['report/progress:view']);
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
        global $CFG;
        require_once($CFG->libdir . '/completionlib.php');
        $course = self::course((int) $args['course_id']);
        $learners = report_helper::course_learners($course, $call->context, $call->user);
        $groupid = isset($args['group_id']) ? (int) $args['group_id'] : null;
        if ($groupid !== null) {
            $allowed = people::restricted_groups($course, $call->context, $call->user);
            $group = groups_get_group($groupid);
            $outside = $allowed !== null && !in_array($groupid, $allowed, true);
            if (!$group || (int) $group->courseid !== (int) $course->id || $outside) {
                throw new tool_exception('not_found', self::ITEM_NOT_FOUND);
            }
            $learners = array_intersect_key($learners, groups_get_members($groupid, 'u.id'));
        }

        $completion = new \completion_info($course);
        $enabled = $completion->is_enabled() == COMPLETION_ENABLED;
        $percents = [];
        $completed = 0;
        if ($enabled) {
            foreach ($learners as $user) {
                if (!$completion->is_tracked_user((int) $user->id)) {
                    continue;
                }
                $percent = \core_completion\progress::get_course_progress_percentage($course, (int) $user->id);
                $percents[] = $percent === null ? 0.0 : (float) $percent;
                $record = new \completion_completion(['userid' => $user->id, 'course' => $course->id]);
                $completed += $record->is_complete() ? 1 : 0;
            }
        }
        $ranges = array_fill_keys(array_keys(self::RANGES), 0);
        foreach ($percents as $percent) {
            foreach (self::RANGES as $name => [$low, $high]) {
                if ($percent >= $low && $percent <= $high) {
                    $ranges[$name]++;
                    break;
                }
            }
        }
        sort($percents);
        $count = count($percents);
        $median = null;
        if ($count) {
            $middle = intdiv($count, 2);
            $median = $count % 2 ? $percents[$middle] : ($percents[$middle - 1] + $percents[$middle]) / 2;
        }

        $days = (int) ($args['inactive_days'] ?? 14);
        $never = null;
        $inactive = null;
        if (people::can_see_last_access($call->context, $call->user)) {
            $last = people::course_last_access((int) $course->id);
            $threshold = time() - $days * DAYSECS;
            $never = count(array_filter($learners, static fn($user) => empty($last[(int) $user->id])));
            $inactive = count(array_filter($learners, static fn($user) => ($last[(int) $user->id] ?? 0) < $threshold));
        }
        $overdue = assign_helper::overdue($course, $call->user);
        $behind = array_intersect_key($overdue['items'], $learners);

        return new tool_result([
            'course_id' => (int) $course->id,
            'group_id' => $groupid,
            'learners' => count($learners),
            'completion_enabled' => $enabled,
            'tracked_activities' => $enabled ? count($completion->get_activities()) : 0,
            'average_progress_percent' => $count ? round(array_sum($percents) / $count, 1) : null,
            'median_progress_percent' => $median === null ? null : round($median, 1),
            'progress_ranges' => $ranges,
            'course_completed' => $completed,
            'never_entered' => $never,
            'inactive' => $inactive,
            'inactive_days' => $days,
            'with_overdue_assignments' => count($behind),
            'overdue_assignments_total' => array_sum(array_map('count', $behind)),
            'url' => (new \moodle_url('/report/progress/index.php', ['course' => $course->id]))->out(false),
        ], 1, null, 'course', (int) $course->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::TEACHER)) {
            return ['course_id'];
        }
        return ['course_id', 'group_id', 'learners', 'completion_enabled', 'tracked_activities', 'average_progress_percent',
            'median_progress_percent', 'progress_ranges' => array_keys(self::RANGES), 'course_completed', 'never_entered',
            'inactive', 'inactive_days', 'with_overdue_assignments', 'overdue_assignments_total', 'url'];
    }
}
