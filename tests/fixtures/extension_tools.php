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
 * Tools of an invented Pluginia plugin, local_pcctest, for the tests of the extension loader.
 *
 * One tool follows the contract; each of the others breaks one rule of amendment 2, section 10B.3.
 * They exist only in the test suite.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_pcctest\pluginiaconnect;

// phpcs:disable PSR1.Classes.ClassDeclaration.MultipleClasses, moodle.Commenting.MissingDocblock
// phpcs:disable moodle.Files.MoodleInternal.MoodleInternalGlobalState

defined('MOODLE_INTERNAL') || die();

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * A read tool that follows the contract.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class good_read extends \local_pluginiaconnect\extension\read_tool {
    /** @var string|null Reason the plugin gives for not answering, set by tests. */
    public static ?string $unavailable = null;

    public static function name(): string {
        return 'get_pcctest_summary';
    }

    public static function definition(): tool_definition {
        return new tool_definition(
            static::name(),
            'Get test summary',
            'Returns the number of items of the test plugin in a course. It exists only in the test suite.',
            ['course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course.']],
            ['course_id'],
            ['type' => 'object', 'properties' => [
                'course_id' => ['type' => 'integer'],
                'items' => ['type' => 'integer'],
                'has_custom_instructions' => ['type' => 'boolean'],
            ]]
        );
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'extensions.read', ['moodle/course:viewparticipants']);
    }

    public function resolve_context(array $args): \context {
        return \context_course::instance((int) $args['course_id']);
    }

    protected static function unavailable_reason(): ?string {
        return static::$unavailable;
    }

    protected function run(array $args, call_context $call): tool_result {
        $course = self::columns('course', ['id' => (int) $args['course_id']], ['id', 'fullname']);
        return new tool_result([
            'course_id' => (int) $course->id,
            'items' => 3,
            'has_custom_instructions' => false,
            'secret' => 'NEVER-RETURNED',
        ], 1);
    }

    public static function output_fields(string $profile): array {
        return ['course_id', 'items', 'has_custom_instructions'];
    }
}

/**
 * Breaks the infix rule: its name does not carry "pcctest".
 */
class no_infix extends good_read {
    public static function name(): string {
        return 'get_other_summary';
    }
}

/**
 * Breaks the forbidden verbs rule.
 */
class forbidden_verb extends good_read {
    public static function name(): string {
        return 'delete_pcctest_items';
    }
}

/**
 * Returns a sensitive field: a prompt.
 */
class sensitive_output extends good_read {
    public static function name(): string {
        return 'get_pcctest_prompt';
    }

    public static function definition(): tool_definition {
        return new tool_definition(
            static::name(),
            'Get test prompt',
            'Returns the prompt of the test plugin. It exists only in the test suite.',
            ['course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course.']],
            ['course_id'],
            ['type' => 'object', 'properties' => ['course_prompt' => ['type' => 'string']]]
        );
    }
}

/**
 * Uses a scope of the base catalogue instead of an extension scope.
 */
class wrong_scope extends good_read {
    public static function name(): string {
        return 'get_pcctest_people';
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'participants.read');
    }
}

/**
 * A write tool that takes a free text and names somebody to act as.
 */
class bad_write extends \local_pluginiaconnect\extension\write_tool {
    public static function name(): string {
        return 'update_pcctest_settings';
    }

    public static function definition(): tool_definition {
        return new tool_definition(
            static::name(),
            'Update test settings',
            'Changes the settings of the test plugin. It exists only in the test suite.',
            [
                'course_id' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Course.'],
                'note' => ['type' => 'string', 'maxLength' => 200, 'description' => 'Free text.'],
                'as_user_id' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 1000, 'description' => 'Who.'],
            ] + self::control_properties(),
            ['course_id'],
            self::result_schema(['course_id' => ['type' => 'integer']]),
            false,
            true,
            false
        );
    }

    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::TEACHER, 'extensions.write', [], true, tool_requirement::APPROVAL_CONFIRM);
    }

    public function resolve_context(array $args): \context {
        return \context_course::instance((int) $args['course_id']);
    }

    protected function run(array $args, call_context $call): tool_result {
        return new tool_result(['course_id' => (int) $args['course_id']], 1);
    }

    public static function output_fields(string $profile): array {
        return ['course_id'];
    }
}

/**
 * A class that does not extend a base class of the extension point.
 */
class not_an_extension extends \local_pluginiaconnect\local\tool\base_tool {
    public static function name(): string {
        return 'get_pcctest_raw';
    }

    public static function definition(): tool_definition {
        return good_read::definition();
    }

    public static function requirement(): tool_requirement {
        return good_read::requirement();
    }

    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    public function execute(array $args, call_context $call): tool_result {
        return new tool_result([], 0);
    }

    public static function output_fields(string $profile): array {
        return [];
    }
}
