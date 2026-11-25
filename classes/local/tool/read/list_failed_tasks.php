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
 * Tool 66: failing scheduled tasks, failed ad hoc tasks and the latest failed runs with a redacted excerpt.
 *
 * The output of a task can hold server paths, SQL, personal data and, sometimes, credentials printed by a
 * plugin. The excerpt is cut, redacted and sanitised, and an excerpt that looks like it holds a secret is not
 * returned at all (D29). The custom data of ad hoc tasks is never read.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class list_failed_tasks extends base_tool {
    /** @var int Longest excerpt of the output of a run. */
    public const EXCERPT_CHARS = 2000;

    /** @var int Latest failed runs returned. */
    private const RUNS = 20;

    /** @var string[] Patterns of what looks like a secret: an excerpt that matches one is withheld whole. */
    public const SECRET_PATTERNS = [
        '/\b(password|passwd|pwd|secret|token|api[_-]?key|apikey|bearer|authorization|private[_-]?key|client[_-]?secret)'
            . '\b\s*["\']?\s*[:=]/i',
        '/-----BEGIN [A-Z ]*PRIVATE KEY-----/',
        '/\b(sk|pk|rk)-[A-Za-z0-9_-]{16,}/',
        '/\bAKIA[0-9A-Z]{16}\b/',
        '/\b[a-z][a-z0-9+.-]*:\/\/[^\s:@\/]+:[^\s@\/]+@/i',
        '/\bBearer\s+[A-Za-z0-9._~+\/-]{16,}/i',
        '/\b[A-Fa-f0-9]{40,}\b/',
        '/\b[A-Za-z0-9+\/_-]{48,}={0,2}/',
    ];

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'list_failed_tasks';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'List failed tasks',
            'Returns, for the site administrator, the scheduled tasks that are failing, the ad hoc tasks waiting after '
                . 'a failure and the latest failed runs in the task log, each run with an excerpt of its output of at most '
                . '2000 characters. Use it to find out why background processing fails. Server paths, network addresses '
                . 'and e-mail addresses are replaced in the excerpt, and an excerpt that looks like it holds a password, '
                . 'key or token is withheld whole. Task data is never returned.',
            [],
            [],
            [
                'type' => 'object',
                'properties' => [
                    'task_log_enabled' => ['type' => 'boolean'],
                    'failing_scheduled_tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'name' => ['type' => 'string'],
                                'component' => ['type' => 'string'],
                                'classname' => ['type' => 'string'],
                                'fail_delay_seconds' => ['type' => 'integer'],
                                'last_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'next_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'failed_adhoc_tasks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'component' => ['type' => 'string'],
                                'classname' => ['type' => 'string'],
                                'fail_delay_seconds' => ['type' => 'integer'],
                                'queued_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'next_run' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                            ],
                        ],
                    ],
                    'failed_adhoc_total' => ['type' => 'integer'],
                    'recent_failed_runs' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'kind' => ['type' => 'string', 'enum' => ['scheduled', 'adhoc']],
                                'component' => ['type' => 'string'],
                                'classname' => ['type' => 'string'],
                                'started_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                                'duration_seconds' => ['type' => 'number'],
                                'output_excerpt_user_content' => ['type' => ['string', 'null']],
                                'output_note' => ['type' => ['string', 'null']],
                            ],
                        ],
                    ],
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
        return new tool_requirement(profile::ADMIN, 'site.read', ['moodle/site:config']);
    }

    /**
     * The tool looks at the site.
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
        global $CFG, $DB;
        $scheduled = [];
        foreach (\core\task\manager::get_all_scheduled_tasks() as $task) {
            if ($task->get_fail_delay() <= 0) {
                continue;
            }
            $scheduled[] = [
                'name' => self::label($task->get_name()),
                'component' => (string) $task->get_component(),
                'classname' => ltrim(get_class($task), '\\'),
                'fail_delay_seconds' => (int) $task->get_fail_delay(),
                'last_run' => self::iso((int) $task->get_last_run_time()),
                'next_run' => $task->get_disabled() ? null : self::iso((int) $task->get_next_run_time()),
            ];
        }
        // Read without the custom data: it is what the task was queued with and may hold anything.
        $adhoctotal = $DB->count_records_select('task_adhoc', 'faildelay > 0');
        $adhoc = [];
        $rows = $DB->get_records_select(
            'task_adhoc',
            'faildelay > 0',
            null,
            'nextruntime ASC, id ASC',
            'id, component, classname, faildelay, nextruntime, timecreated',
            0,
            50
        );
        foreach ($rows as $row) {
            $adhoc[] = [
                'component' => (string) $row->component,
                'classname' => ltrim((string) $row->classname, '\\'),
                'fail_delay_seconds' => (int) $row->faildelay,
                'queued_at' => self::iso((int) $row->timecreated),
                'next_run' => self::iso((int) $row->nextruntime),
            ];
        }
        $runs = [];
        $logenabled = !isset($CFG->task_logmode) || !empty($CFG->task_logmode);
        $logs = $DB->get_records(
            'task_log',
            ['result' => 1],
            'timestart DESC, id DESC',
            'id, type, component, classname, timestart, timeend, output',
            0,
            self::RUNS
        );
        foreach ($logs as $log) {
            [$excerpt, $note] = self::excerpt((string) $log->output);
            $runs[] = [
                'kind' => (int) $log->type === 1 ? 'adhoc' : 'scheduled',
                'component' => (string) $log->component,
                'classname' => ltrim((string) $log->classname, '\\'),
                'started_at' => self::iso((int) $log->timestart),
                'duration_seconds' => round(max(0, (float) $log->timeend - (float) $log->timestart), 2),
                'output_excerpt_user_content' => $excerpt,
                'output_note' => $note,
            ];
        }
        return new tool_result([
            'task_log_enabled' => $logenabled,
            'failing_scheduled_tasks' => $scheduled,
            'failed_adhoc_tasks' => $adhoc,
            'failed_adhoc_total' => $adhoctotal,
            'recent_failed_runs' => $runs,
            'url' => (new \moodle_url('/admin/tasklogs.php', ['filter' => '', 'result' => 1]))->out(false),
        ], count($scheduled) + count($adhoc) + count($runs));
    }

    /**
     * Excerpt of the output of a run: its last characters, redacted; nothing when it looks like it holds a secret.
     *
     * @param string $output Output as stored.
     * @return array [excerpt or null, note or null]
     */
    public static function excerpt(string $output): array {
        global $CFG;
        $output = trim($output);
        if ($output === '') {
            return [null, 'The run left no output.'];
        }
        // The cause of a failure is usually at the end of the output.
        $text = \core_text::strlen($output) > self::EXCERPT_CHARS
            ? '...' . \core_text::substr($output, -self::EXCERPT_CHARS) : $output;
        foreach (self::SECRET_PATTERNS as $pattern) {
            if (preg_match($pattern, $text)) {
                return [null, 'The output was withheld because it looks like it contains a password, key or token. '
                    . 'Read it in the task logs of the site.'];
            }
        }
        $paths = array_filter([$CFG->dataroot ?? '', $CFG->dirroot ?? '', $CFG->tempdir ?? '', $CFG->cachedir ?? '',
            $CFG->localcachedir ?? '', $CFG->wwwroot ?? '']);
        usort($paths, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($paths as $path) {
            $text = str_replace($path, '[site path]', $text);
        }
        $text = preg_replace('~(?<![\w.])(/(?:var|home|srv|opt|usr|tmp|etc|root|data|mnt)/[^\s:\'"]*)~', '[server path]', $text);
        $text = preg_replace('~\b[A-Za-z]:\\\\[^\s:\'"]*~', '[server path]', $text);
        $text = preg_replace('/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/', '[e-mail]', $text);
        $text = preg_replace('/\b\d{1,3}(\.\d{1,3}){3}\b/', '[address]', $text);
        $text = preg_replace('/\b[0-9a-f]{1,4}(:[0-9a-f]{0,4}){4,7}\b/i', '[address]', $text);
        return [text_sanitizer::clean_plain($text, self::EXCERPT_CHARS + 3), null];
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if ($profile !== profile::ADMIN) {
            return [];
        }
        return ['task_log_enabled', 'failed_adhoc_total', 'url',
            'failing_scheduled_tasks' => ['*' => ['name', 'component', 'classname', 'fail_delay_seconds', 'last_run',
                'next_run']],
            'failed_adhoc_tasks' => ['*' => ['component', 'classname', 'fail_delay_seconds', 'queued_at', 'next_run']],
            'recent_failed_runs' => ['*' => ['kind', 'component', 'classname', 'started_at', 'duration_seconds',
                'output_excerpt_user_content', 'output_note']],
        ];
    }
}
