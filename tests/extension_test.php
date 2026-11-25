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

namespace local_pluginiaconnect;

use local_pluginiaconnect\extension\loader;
use local_pluginiaconnect\extension\rules;
use local_pluginiaconnect\local\mcp\call_pipeline;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\user_session;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/extension_tools.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\extension\loader::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\extension\rules::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\extension\read_tool::class)]
/**
 * The extension point (amendment 2, D19, section 10B) and the permanent prohibitions (D24, D26, D28, D29),
 * checked on the base catalogue and on every extension the site loads.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\extension\loader
 * @covers     \local_pluginiaconnect\extension\rules
 * @covers     \local_pluginiaconnect\extension\read_tool
 */
final class extension_test extends \advanced_testcase {
    /** @var string[] Calls no tool may make (D29 c), as patterns over the code without comments. */
    private const FORBIDDEN_CALLS = [
        'delete_records', 'course_delete_module', 'delete_course\s*\(', 'delete_user\s*\(', 'unenrol_user\s*\(',
        'uninstall_plugin', '->delete\s*\(', 'fulldelete\s*\(', 'remove_dir\s*\(', '\bunlink\s*\(', 'loginas',
        'complete_user_login', 'session\\\\manager::set_user', 'update_internal_user_password', 'setnew_password_and_mail',
        '\bset_config\s*\(', '\bunset_config\s*\(', 'assign_capability\s*\(', '\brole_assign\s*\(', 'role_unassign\s*\(',
        'install_plugins\s*\(', '\bexec\s*\(', 'shell_exec', '\bsystem\s*\(', 'passthru', 'proc_open', '\bpopen\s*\(',
        '\beval\s*\(', 'curl', 'download_file_content', 'file_get_contents\s*\(', 'file_put_contents\s*\(',
        'fopen\s*\(', 'get_file_storage', 'reset_course_userdata', 'backup_controller', 'restore_controller',
    ];

    /**
     * Unregisters the components of the tests.
     */
    protected function tearDown(): void {
        loader::set_test_components([]);
        parent::tearDown();
    }

    /**
     * Registers the invented plugin local_pcctest.
     *
     * @param string $file Declaration file in tests/fixtures.
     * @param int|null $minimum Minimum version.
     * @param int $version Installed version.
     */
    private function register(
        string $file = 'extension_declaration.php',
        ?int $minimum = 2026112500,
        int $version = 2026112500
    ): void {
        global $CFG;
        loader::set_test_components(['local_pcctest' => ['pcctest', $minimum,
            $CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/' . $file, $version]]);
    }

    public function test_loader_accepts_only_what_follows_the_contract(): void {
        $base = tool_registry::base();
        $this->register();
        $report = loader::report()['local_pcctest'];
        $this->assertSame('loaded', $report['status']);
        $this->assertSame(2026112500, $report['catalog']);
        $this->assertSame(['get_pcctest_summary'], $report['tools']);
        $rejected = $report['rejected'];
        $this->assertStringContainsString("reserved infix 'pcctest'", $rejected['get_other_summary']);
        $this->assertStringContainsString("forbidden verb 'delete'", $rejected['delete_pcctest_items']);
        $this->assertStringContainsString("sensitive property 'course_prompt'", $rejected['get_pcctest_prompt']);
        $this->assertStringContainsString("scope must be 'extensions.read'", $rejected['get_pcctest_people']);
        $this->assertStringContainsString("'note' of a write extension", $rejected['update_pcctest_settings']);
        $this->assertStringContainsString("'as_user_id' names somebody to act as", $rejected['update_pcctest_settings']);
        $this->assertStringContainsString('does not extend a base class', $rejected['get_pcctest_raw']);
        $this->assertStringContainsString('not in the namespace local_pcctest', $rejected['get_pcctest_outside']);
        $this->assertStringContainsString('does not exist', $rejected['get_pcctest_missing']);
        $this->assertStringContainsString('name() does not match', $rejected['get_pcctest_renamed']);
        $this->assertStringContainsString('already in use', $rejected['list_my_courses']);
        // The base catalogue is untouched and its own class keeps its name.
        $this->assertSame($base, tool_registry::base());
        $this->assertSame(\local_pluginiaconnect\local\tool\read\list_my_courses::class, tool_registry::get('list_my_courses'));
        $this->assertSame(\local_pcctest\pluginiaconnect\good_read::class, tool_registry::get('get_pcctest_summary'));
        $this->assertSame('local_pcctest', loader::component_of('get_pcctest_summary'));
        $this->assertNull(tool_registry::get('get_pcctest_prompt'));
        $this->assertCount(count($base) + count(loader::tools()), tool_registry::all());
        // Its catalogue holds the accepted tool only, in the format the gateway reads.
        $catalog = loader::catalog('local_pcctest');
        $this->assertSame('local_pcctest', $catalog['component']);
        $this->assertSame(['get_pcctest_summary'], array_column($catalog['tools'], 'name'));
        $this->assertSame('extensions.read', $catalog['tools'][0]['x-pluginia']['scope']);
        $this->assertStringEndsWith("\n", loader::catalog_json('local_pcctest'));
    }

    public function test_a_plugin_not_accepted_adds_nothing(): void {
        $base = tool_registry::all();
        foreach ([[null, 2026112500, 'not_accepted_yet'], [2026120100, 2026112500, 'version_too_old']] as [$min, $v, $status]) {
            $this->register('extension_declaration.php', $min, $v);
            $this->assertSame($status, loader::report()['local_pcctest']['status']);
            $this->assertNull(tool_registry::get('get_pcctest_summary'));
        }
        $this->register('extension_badcontract.php');
        $this->assertSame('invalid_declaration', loader::report()['local_pcctest']['status']);
        $this->register('does_not_exist.php');
        $this->assertSame('no_declaration', loader::report()['local_pcctest']['status']);
        loader::set_test_components([]);
        $this->assertSame(array_keys($base), array_keys(tool_registry::all()));
        // A whitelisted plugin that is not installed is reported as such.
        foreach (loader::report() as $component => $entry) {
            if (\core_component::get_component_directory($component) === null) {
                $this->assertSame('not_installed', $entry['status'], $component);
                $this->assertSame([], $entry['tools']);
            }
        }
    }

    public function test_extension_tool_runs_through_the_whole_chain_and_starts_off(): void {
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        $this->register();
        $generator = $this->getDataGenerator();
        $course = $generator->create_course();
        $teacher = $generator->create_and_enrol($course, 'editingteacher');
        $student = $generator->create_and_enrol($course, 'student');
        $run = function (\stdClass $user, string $profile, array $scopes) use ($course) {
            [, $grant] = \local_pluginiaconnect_mcp_fixture::grant($user->id, $profile, $scopes);
            $loaded = user_session::load($grant);
            return call_pipeline::run(
                $grant,
                $loaded,
                'get_pcctest_summary',
                ['course_id' => (int) $course->id],
                \core\uuid::generate()
            );
        };
        // Off by default, as every tool added after version 1.0 (D23).
        $this->assertFalse(site_policy::is_tool_enabled('get_pcctest_summary', profile::TEACHER));
        $this->assertSame('tool_disabled', $run($teacher, 'teacher', ['extensions.read'])->error_code());
        set_config('tool_get_pcctest_summary_teacher', 1, 'local_pluginiaconnect');
        set_config('tool_get_pcctest_summary_student', 1, 'local_pluginiaconnect');
        // The extension scope, the minimum profile and the capability are those of any other tool.
        $this->assertSame('scope_missing', $run($teacher, 'teacher', ['courses.read', 'participants.read'])->error_code());
        $this->assertSame('profile_not_allowed', $run($student, 'student', ['extensions.read'])->error_code());
        $result = $run($teacher, 'teacher', ['extensions.read']);
        $this->assertFalse($result->iserror, json_encode($result->envelope));
        $this->assertSame(3, $result->envelope['data']['items']);
        // The closed output list keeps out what the tool returned beyond it.
        $this->assertStringNotContainsString('NEVER-RETURNED', json_encode($result->envelope));
        // The plugin's own availability check (a licence, for instance) stops the call with its reason.
        \local_pcctest\pluginiaconnect\good_read::$unavailable = 'The plugin needs an active licence.';
        try {
            $result = $run($teacher, 'teacher', ['extensions.read']);
            $this->assertSame('tool_disabled', $result->error_code());
            $this->assertSame('The plugin needs an active licence.', $result->envelope['error']['message']);
        } finally {
            \local_pcctest\pluginiaconnect\good_read::$unavailable = null;
        }
        $this->assertContains('get_pcctest_summary', array_keys(site_policy::usable_tools('teacher', ['extensions.read'])));
    }

    /**
     * Permanent rules (D24, D28, D29) on every tool the site offers: the base catalogue and every loaded extension.
     */
    public function test_permanent_prohibitions_on_base_and_extensions(): void {
        $this->register();
        $infixes = loader::infixes();
        $checked = 0;
        foreach (tool_registry::all() as $name => $class) {
            $requirement = $class::requirement();
            $extension = !tool_registry::is_base($name);
            $this->assertSame([], rules::name_problems($name), $name);
            $this->assertSame([], rules::schema_problems(tool_registry::describe($class), $extension, $requirement->write), $name);
            if ($requirement->write) {
                $this->assertContains($requirement->approval, ['confirm', 'approval'], "{$name}: a write never runs unconfirmed");
            }
            foreach ($infixes as $component => $infix) {
                if (!$extension) {
                    $this->assertFalse(rules::has_segment($name, $infix), "{$name} carries the reserved infix of {$component}");
                }
            }
            $checked++;
        }
        $this->assertGreaterThanOrEqual(47, $checked);
        // The checks catch what they are there for.
        $this->assertNotSame([], rules::name_problems('delete_course'));
        $this->assertNotSame([], rules::name_problems('uninstall_plugin_x'));
        $this->assertNotSame([], rules::name_problems('Bad-Name'));
        $this->assertTrue(rules::is_sensitive_name('ai_apikey'));
        $this->assertTrue(rules::is_sensitive_name('license_key'));
        $this->assertTrue(rules::is_sensitive_name('bot_userid'));
        $this->assertTrue(rules::is_sensitive_name('custom_js'));
        $this->assertTrue(rules::is_sensitive_name('monthly_budget'));
        $this->assertFalse(rules::is_sensitive_name('has_custom_instructions'));
        $this->assertFalse(rules::is_sensitive_name('completion_pct'));
        $definition = ['inputSchema' => ['properties' => [
            'user_id' => ['type' => 'integer'], 'on_behalf_of' => ['type' => 'integer'],
            'callback_url' => ['type' => 'string', 'format' => 'uri', 'maxLength' => 100],
            'upload' => ['type' => 'string', 'contentEncoding' => 'base64', 'maxLength' => 100],
        ]]];
        $problems = implode(' ', rules::schema_problems($definition, false, true));
        $this->assertStringContainsString('on_behalf_of', $problems);
        $this->assertStringContainsString('callback_url', $problems);
        $this->assertStringContainsString('upload', $problems);
        $this->assertStringNotContainsString("'user_id'", $problems);
    }

    /**
     * Static analysis of the code of every tool (D29 c): no tool deletes, impersonates, sets passwords or
     * settings, assigns roles, installs, runs code or calls the network. Base tools and extension classes alike.
     */
    public function test_no_tool_code_makes_a_forbidden_call(): void {
        global $CFG;
        $this->register();
        $files = [];
        $root = $CFG->dirroot . '/local/pluginiaconnect/classes/';
        $dirs = [$root . 'local/tool', $root . 'extension'];
        $components = array_unique(array_filter(array_map([loader::class, 'component_of'], array_keys(loader::tools()))));
        foreach ($components as $component) {
            $dir = \core_component::get_component_directory($component);
            if ($dir !== null) {
                $dirs[] = $dir . '/classes/pluginiaconnect';
            }
        }
        foreach ($dirs as $dir) {
            if (!is_dir($dir)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if ($file->getExtension() === 'php') {
                    $files[] = $file->getPathname();
                }
            }
        }
        $this->assertGreaterThanOrEqual(50, count($files));
        foreach ($files as $path) {
            $code = '';
            foreach (\PhpToken::tokenize((string) file_get_contents($path)) as $token) {
                if (!$token->is([T_COMMENT, T_DOC_COMMENT, T_CONSTANT_ENCAPSED_STRING])) {
                    $code .= $token->text;
                }
            }
            foreach (self::FORBIDDEN_CALLS as $pattern) {
                $this->assertDoesNotMatchRegularExpression('~' . $pattern . '~i', $code, "{$path} matches {$pattern}");
            }
        }
        // The analysis sees what it looks for.
        $this->assertMatchesRegularExpression('~\bset_config\s*\(~i', '$x = set_config("a", 1);');
        $this->assertMatchesRegularExpression('~delete_records~i', '$DB->delete_records_select("t", "1=1");');
    }

    /**
     * The tools added after version 1.0 start off, also the reads (D23); the 22 reads of 1.0 keep their default.
     */
    public function test_new_tools_start_off(): void {
        $this->resetAfterTest();
        $this->assertCount(22, site_policy::ORIGINAL_READS);
        foreach (tool_registry::all() as $name => $class) {
            $original = in_array($name, site_policy::ORIGINAL_READS, true);
            if ($original) {
                $this->assertFalse($class::requirement()->write, $name);
            }
            foreach (profile::all() as $profile) {
                $this->assertSame($original, site_policy::is_tool_enabled($name, $profile), "{$name} for {$profile}");
            }
        }
    }

    /**
     * The catalogue file of each extension the site loads matches its definitions, as in the plugin's own CI.
     */
    public function test_catalogue_files_of_installed_extensions_are_up_to_date(): void {
        foreach (loader::report() as $component => $entry) {
            if ($entry['status'] !== 'loaded') {
                continue;
            }
            $file = \core_component::get_component_directory($component) . '/pluginiaconnect/tools.json';
            $this->assertFileExists($file, $component);
            $this->assertSame(
                loader::catalog_json($component),
                str_replace("\r\n", "\n", (string) file_get_contents($file)),
                "Run php local/pluginiaconnect/cli/export_catalog.php --component={$component}"
            );
            $this->assertSame([], $entry['rejected'], $component);
        }
        $this->assertTrue(true);
    }
}
