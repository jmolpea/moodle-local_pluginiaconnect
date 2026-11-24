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

/**
 * Probe tools for the pipeline tests.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, moodle.Commenting.MissingDocblock

/**
 * Probe tools for the pipeline tests. They exist only in the test suite.
 *
 * The phase 1 catalogue has student-level read tools only, so the steps that
 * deal with higher profiles, Moodle capabilities, write tools and failures are
 * exercised with these probes.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_pluginiaconnect_teacher_probe extends base_tool {
    /** @var int How many times a probe was executed. */
    public static int $executions = 0;

    public static function name(): string {
        return 'teacher_probe';
    }

    public static function definition(): tool_definition {
        return new tool_definition(
            static::name(),
            'Teacher probe',
            'Test probe. It returns fixed data.',
            ['course_id' => ['type' => 'integer', 'minimum' => 1]],
            ['course_id'],
            ['type' => 'object'],
            true,
            false,
            true
        );
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'participants.read', ['moodle/course:viewparticipants']);
    }

    public function resolve_context(array $args): \context {
        return \context_course::instance(self::course((int) $args['course_id'])->id);
    }

    public function execute(array $args, call_context $call): tool_result {
        self::$executions++;
        return new tool_result([
            'visible' => 'yes',
            'idnumber' => 'LEAK-IDNUMBER',
            'people' => [['name' => 'Ana', 'email' => 'leak@example.com', 'secret' => ['token' => 'LEAK-TOKEN']]],
        ], 1, null, 'course', (int) $args['course_id']);
    }

    public static function output_fields(string $profile): array {
        return ['visible', 'people' => ['*' => ['name']]];
    }
}

/**
 * Write probe: a tool that would change something.
 * @package local_pluginiaconnect
 */
class local_pluginiaconnect_write_probe extends local_pluginiaconnect_teacher_probe {
    public static function name(): string {
        return 'write_probe';
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'content.write', [], true, tool_requirement::APPROVAL_CONFIRM);
    }

    public static function definition(): tool_definition {
        return new tool_definition(
            static::name(),
            'Write probe',
            'Test probe. It counts how many times it is applied.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1],
                'value' => ['type' => 'string', 'maxLength' => 50],
                'confirm_token' => ['type' => 'string', 'maxLength' => 100],
                'idempotency_key' => ['type' => 'string', 'minLength' => 8, 'maxLength' => 64],
            ],
            ['course_id'],
            ['type' => 'object'],
            false,
            true,
            true
        );
    }

    public function preview(array $args, call_context $call): ?string {
        return 'Probe change in course ' . (int) $args['course_id'] . ' with value ' . ($args['value'] ?? 'none') . '.';
    }

    public function execute(array $args, call_context $call): tool_result {
        self::$executions++;
        if (($args['value'] ?? '') === 'fail') {
            throw new \local_pluginiaconnect\local\tool\tool_exception('validation_error', 'The probe was told to fail.');
        }
        return new tool_result(['visible' => 'applied-' . ($args['value'] ?? 'none')], 1, null, 'course', (int) $args['course_id']);
    }
}

/**
 * Probe that fails with an unexpected exception carrying sensitive text.
 * @package local_pluginiaconnect
 */
class local_pluginiaconnect_boom_probe extends local_pluginiaconnect_teacher_probe {
    public static function name(): string {
        return 'boom_probe';
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::STUDENT, 'profile');
    }

    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    public function execute(array $args, call_context $call): tool_result {
        throw new \RuntimeException('SENSITIVE /var/www/html/config.php password=hunter2');
    }
}

/**
 * Probe whose core call is refused by a capability check.
 * @package local_pluginiaconnect
 */
class local_pluginiaconnect_denied_probe extends local_pluginiaconnect_boom_probe {
    public static function name(): string {
        return 'denied_probe';
    }

    public function execute(array $args, call_context $call): tool_result {
        require_capability('moodle/site:config', \context_system::instance());
        return new tool_result([]);
    }
}
