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
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool 67: results of the status, security or performance checks of the site (core\check).
 *
 * Each check runs now. One that fails is reported as 'unknown'; once the time budget of the call is spent,
 * the remaining ones are not run and are reported as such, so that a slow check cannot hold the request.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_site_checks extends base_tool {
    /** @var array Type of check => capability of its report. */
    private const TYPES = [
        'status' => 'report/status:view',
        'security' => 'report/security:view',
        'performance' => 'report/performance:view',
    ];

    /** @var string[] Result states of core\check. */
    private const STATES = ['na', 'ok', 'info', 'unknown', 'warning', 'error', 'critical'];

    /** @var int Seconds after which no further check is started. */
    private const BUDGET = 15;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_site_checks';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get site checks',
            'Returns, for the site administrator, the result of the status, security or performance checks of the '
                . 'site: each check with its name, component, state (ok, info, warning, error, critical, unknown or not '
                . 'applicable) and a plain text summary. Use it to see what needs attention on the site. The checks run '
                . 'when called; one that fails is reported as unknown, and those left when the time budget runs out are '
                . 'reported as not run.',
            [
                'type' => [
                    'type' => 'string', 'enum' => array_keys(self::TYPES), 'default' => 'status',
                    'description' => 'Which checks to run.',
                ],
                'only_problems' => [
                    'type' => 'boolean', 'default' => false,
                    'description' => 'Return only checks whose state is warning, error, critical or unknown.',
                ],
            ],
            [],
            [
                'type' => 'object',
                'properties' => [
                    'type' => ['type' => 'string', 'enum' => array_keys(self::TYPES)],
                    'checks_total' => ['type' => 'integer'],
                    'checks' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => [
                                'id' => ['type' => 'string'],
                                'name' => ['type' => 'string'],
                                'component' => ['type' => 'string'],
                                'status' => ['type' => 'string', 'enum' => array_merge(self::STATES, ['not_run'])],
                                'summary' => ['type' => 'string'],
                            ],
                        ],
                    ],
                    'url' => ['type' => 'string', 'format' => 'uri'],
                ],
            ]
        );
    }

    /**
     * Requirements. The capability of the report of each type is checked when the type is known.
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
        global $PAGE;
        $type = (string) ($args['type'] ?? 'status');
        if (!has_capability(self::TYPES[$type], \context_system::instance(), $call->user)) {
            throw new tool_exception('permission_denied', 'You do not have permission to do this here.');
        }
        // Some checks render with the page renderer: the page needs a context.
        $PAGE->set_context(\context_system::instance());
        $start = microtime(true);
        $checks = [];
        foreach (\core\check\manager::get_checks($type) as $check) {
            $row = ['id' => '', 'name' => '', 'component' => '', 'status' => 'unknown', 'summary' => ''];
            try {
                $row['id'] = (string) $check->get_id();
                $row['name'] = self::label($check->get_name());
                $row['component'] = (string) $check->get_component();
                if (microtime(true) - $start > self::BUDGET) {
                    $row['status'] = 'not_run';
                    $row['summary'] = 'Not run: the time budget of this call was spent. Open the report on the site.';
                } else {
                    $result = $check->get_result();
                    $status = (string) $result->get_status();
                    $row['status'] = in_array($status, self::STATES, true) ? $status : 'unknown';
                    $row['summary'] = self::plain($result->get_summary());
                }
            } catch (\Throwable $e) {
                $row['status'] = 'unknown';
                $row['summary'] = 'The check could not be run.';
            }
            $checks[] = $row;
        }
        $total = count($checks);
        if (!empty($args['only_problems'])) {
            $checks = array_values(array_filter($checks, static fn($row) =>
                in_array($row['status'], ['warning', 'error', 'critical', 'unknown', 'not_run'], true)));
        }
        return new tool_result([
            'type' => $type,
            'checks_total' => $total,
            'checks' => $checks,
            'url' => (new \moodle_url('/report/' . $type . '/index.php'))->out(false),
        ], count($checks));
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
        return ['type', 'checks_total', 'url', 'checks' => ['*' => ['id', 'name', 'component', 'status', 'summary']]];
    }
}
