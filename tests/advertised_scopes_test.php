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

use local_pluginiaconnect\local\http\request;
use local_pluginiaconnect\local\mcp\server;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\metadata_endpoint;
use local_pluginiaconnect\local\oauth\server_metadata;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tools_matrix;
use local_pluginiaconnect\output\consent_page;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/oauth_helper.php');
require_once(__DIR__ . '/fixtures/mcp_fixture.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\policy\site_policy::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\oauth\server_metadata::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\mcp\server::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\output\consent_page::class)]
/**
 * Scopes the site advertises to assistants (setting "advertisedscopes"): the 401 challenge, the metadata
 * documents, and the consent chain when an assistant asks for the whole advertised list.
 *
 * Claude asks for exactly the scopes of the 401 challenge. With only the baseline advertised, no action
 * that changes data and no action of a manager or an administrator could ever be reached from it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\policy\site_policy
 * @covers     \local_pluginiaconnect\local\oauth\server_metadata
 * @covers     \local_pluginiaconnect\local\mcp\server
 * @covers     \local_pluginiaconnect\output\consent_page
 */
final class advertised_scopes_test extends \advanced_testcase {
    use oauth_helper;

    /** @var string[] Every scope with a read tool, in catalogue order: what all profiles together read. */
    private const ALL_READ = [
        'profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read', 'users.read', 'site.read',
        'offline_access',
    ];

    /**
     * Connector on, every profile allowed, every action that changes data off (the state after installing).
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
        \local_pluginiaconnect_mcp_fixture::enable();
        foreach (tool_registry::all() as $name => $class) {
            if ($class::requirement()->write) {
                foreach (['student', 'teacher', 'manager', 'admin'] as $profile) {
                    set_config("tool_{$name}_{$profile}", 0, 'local_pluginiaconnect');
                }
            }
        }
    }

    /**
     * Scope list of the 401 challenge of a request.
     *
     * @param string|null $token Bearer token, or null for none.
     * @return string[]
     */
    private function challenge_scopes(?string $token): array {
        $request = \local_pluginiaconnect_mcp_fixture::request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], $token);
        $response = server::handle($request, \local_pluginiaconnect_mcp_fixture::authenticator(), 'req-adv');
        $this->assertSame(401, $response->status);
        $this->assertSame(1, preg_match('/, scope="([^"]*)"/', $response->headers['WWW-Authenticate'], $match));
        return explode(' ', $match[1]);
    }

    /**
     * Asserts that the challenge (without a token and with a rejected one) and the metadata say the same.
     *
     * @param string[] $expected Expected scopes, in order.
     * @return void
     */
    private function assert_everywhere(array $expected): void {
        $this->assertSame($expected, site_policy::advertised_scopes());
        $this->assertSame($expected, $this->challenge_scopes(null));
        $this->assertSame($expected, $this->challenge_scopes('pc_at_unknown'));
        $this->assertSame($expected, server_metadata::protected_resource()['scopes_supported']);
    }

    public function test_factory_value_is_active(): void {
        $this->assertSame('active', local\config::DEFAULTS['advertisedscopes']);
        $this->assertSame(site_policy::ADVERTISE_ACTIVE, local\config::string('advertisedscopes'));
    }

    public function test_active_lists_the_read_scopes_of_the_allowed_profiles(): void {
        $this->assert_everywhere(self::ALL_READ);
        $this->assertSame(self::ALL_READ, server_metadata::authorization_server()['scopes_supported']);

        // The factory profiles: teacher and manager, no student, no administrator. No site.read.
        set_config('allowstudentprofile', 0, 'local_pluginiaconnect');
        set_config('allowadminprofile', 0, 'local_pluginiaconnect');
        $this->assert_everywhere(
            ['profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read', 'users.read', 'offline_access']
        );
    }

    public function test_profiles_switched_off_leave_the_baseline(): void {
        foreach (['teacher', 'manager', 'admin'] as $profile) {
            set_config("allow{$profile}profile", 0, 'local_pluginiaconnect');
        }
        $this->assert_everywhere(scope::BASELINE);
        set_config('allowstudentprofile', 0, 'local_pluginiaconnect');
        $this->assert_everywhere(scope::BASELINE);
    }

    public function test_a_read_tool_switched_off_everywhere_removes_its_scope(): void {
        foreach (tool_registry::all() as $name => $class) {
            if ($class::requirement()->scope === 'site.read') {
                set_config("tool_{$name}_admin", 0, 'local_pluginiaconnect');
            }
        }
        $this->assert_everywhere(array_values(array_diff(self::ALL_READ, ['site.read'])));
    }

    public function test_write_switched_on_only_for_the_admin_profile(): void {
        // A teacher action switched on for the administrator profile only.
        set_config('tool_set_activity_visibility_admin', 1, 'local_pluginiaconnect');
        $expected = ['profile', 'courses.read', 'grades.read', 'participants.read', 'content.write', 'manage.read',
            'users.read', 'site.read', 'offline_access'];
        $this->assert_everywhere($expected);
        $this->assertSame($expected, server_metadata::authorization_server()['scopes_supported']);

        // An administrator action as well.
        set_config('tool_set_user_suspension_admin', 1, 'local_pluginiaconnect');
        $this->assertContains('users.write', site_policy::advertised_scopes());

        // The administrator profile switched off: neither write scope is advertised, nor site.read.
        set_config('allowadminprofile', 0, 'local_pluginiaconnect');
        $this->assert_everywhere(['profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read',
            'users.read', 'offline_access']);
    }

    public function test_read_only_site_advertises_no_write_scope(): void {
        set_config('tool_set_activity_visibility_teacher', 1, 'local_pluginiaconnect');
        set_config('tool_enrol_users_in_course_manager', 1, 'local_pluginiaconnect');
        $this->assertContains('content.write', site_policy::advertised_scopes());
        $this->assertContains('enrol.write', site_policy::advertised_scopes());

        set_config('readonlymode', 1, 'local_pluginiaconnect');
        $this->assert_everywhere(self::ALL_READ);
        foreach (site_policy::advertised_scopes() as $name) {
            $this->assertFalse(scope::is_write($name), $name);
        }
    }

    public function test_baseline_keeps_the_previous_behaviour(): void {
        set_config('tool_set_activity_visibility_teacher', 1, 'local_pluginiaconnect');
        set_config('advertisedscopes', 'baseline', 'local_pluginiaconnect');
        $this->assert_everywhere(['profile', 'courses.read', 'grades.read', 'offline_access']);
        // The authorization server still lists every scope it accepts, as before.
        $this->assertSame(scope::all(), server_metadata::authorization_server()['scopes_supported']);

        $request = \local_pluginiaconnect_mcp_fixture::request(['jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize'], null);
        $response = server::handle($request, \local_pluginiaconnect_mcp_fixture::authenticator(), 'req-adv');
        $this->assertSame(
            'Bearer resource_metadata="' . local\urls::resource_metadata()
                . '", scope="profile courses.read grades.read offline_access"',
            $response->headers['WWW-Authenticate']
        );
    }

    public function test_unknown_value_or_connector_off_falls_back_to_the_baseline(): void {
        set_config('tool_set_activity_visibility_teacher', 1, 'local_pluginiaconnect');
        set_config('advertisedscopes', 'everything', 'local_pluginiaconnect');
        $this->assertSame(scope::BASELINE, site_policy::advertised_scopes());

        set_config('advertisedscopes', 'active', 'local_pluginiaconnect');
        set_config('enabled', 0, 'local_pluginiaconnect');
        $this->assertSame(scope::BASELINE, site_policy::advertised_scopes());
        $this->assertSame(scope::BASELINE, server_metadata::protected_resource()['scopes_supported']);
    }

    public function test_list_is_ordered_unique_and_known(): void {
        foreach (tool_registry::all() as $name => $class) {
            foreach (['student', 'teacher', 'manager', 'admin'] as $profile) {
                set_config("tool_{$name}_{$profile}", 1, 'local_pluginiaconnect');
            }
        }
        $advertised = site_policy::advertised_scopes();
        $this->assertSame(array_values(array_unique($advertised)), $advertised);
        $this->assertSame(array_values(array_intersect(scope::all(), $advertised)), $advertised);
        foreach ($advertised as $name) {
            $this->assertTrue(scope::is_valid($name), $name);
        }
        // Every scope of the catalogue has a tool, so with everything on everything is advertised.
        $this->assertSame(scope::all(), $advertised);
    }

    public function test_a_change_of_the_tool_matrix_applies_on_the_next_request(): void {
        $this->setAdminUser();
        // The page sends every cell: start from what is stored now.
        $cells = [];
        foreach (array_keys(tool_registry::all()) as $name) {
            foreach (['student', 'teacher', 'manager', 'admin'] as $profile) {
                $cells[$name][$profile] = site_policy::is_tool_enabled($name, $profile) ? 1 : 0;
            }
        }
        $this->assertNotContains('grades.write', $this->challenge_scopes(null));
        $cells['save_assignment_grade']['teacher'] = 1;
        $this->assertGreaterThan(0, tools_matrix::save($cells, []));
        $this->assertContains('grades.write', $this->challenge_scopes(null));
        $cells['save_assignment_grade']['teacher'] = 0;
        $this->assertGreaterThan(0, tools_matrix::save($cells, []));
        $this->assertNotContains('grades.write', $this->challenge_scopes(null));
        $this->assertSame(self::ALL_READ, $this->challenge_scopes(null));
    }

    public function test_metadata_documents_are_not_cached_by_clients(): void {
        foreach (['/.well-known/oauth-authorization-server', '/resource-metadata'] as $path) {
            $response = metadata_endpoint::handle(new request('GET'), $path);
            $this->assertSame('no-cache', $response->headers['Cache-Control'], $path);
        }
    }

    /**
     * An assistant that asks for the whole advertised list: the consent page cuts it down per profile,
     * offers changes only where an action that changes data is on, and "read only" grants no write scope.
     */
    public function test_consent_with_the_whole_advertised_list(): void {
        global $PAGE;
        $admin = get_admin();
        $this->getDataGenerator()->create_course();
        $this->setAdminUser();
        set_config('tool_set_activity_visibility_admin', 1, 'local_pluginiaconnect');
        $advertised = site_policy::advertised_scopes();
        $this->assertContains('content.write', $advertised);

        [$client] = $this->make_client();
        $request = authorization_request::from_params(
            $this->request_params($client, $this->make_verifier(), ['scope' => implode(' ', $advertised)])
        );
        $this->assertSame($advertised, $request->scopes, 'Every advertised scope is accepted as asked');
        $userid = (int) $admin->id;
        $profiles = ['teacher', 'manager', 'admin'];
        $this->assertTrue(consent_page::offers_changes($request, $userid, $profiles));
        $this->assertFalse(consent_page::offers_changes($request, $userid, ['teacher', 'manager']));

        // Grants: the intersection of what was asked, what the profile may hold and what is switched on.
        $active = static fn(string $profile): array => site_policy::scopes_with_active_tools($profile);
        $this->assertSame(
            ['profile', 'courses.read', 'grades.read', 'participants.read', 'offline_access'],
            scope::granted($request->scopes, 'teacher', true, $userid, $active('teacher'))
        );
        $this->assertSame(
            ['profile', 'courses.read', 'grades.read', 'participants.read', 'manage.read', 'users.read', 'offline_access'],
            scope::granted($request->scopes, 'manager', true, $userid, $active('manager'))
        );
        $adminwrite = scope::granted($request->scopes, 'admin', true, $userid, $active('admin'));
        $this->assertContains('content.write', $adminwrite);
        $this->assertContains('site.read', $adminwrite);
        $adminread = scope::granted($request->scopes, 'admin', false, $userid, $active('admin'));
        foreach ($adminread as $name) {
            $this->assertFalse(scope::is_write($name), "Read only grants no write scope ({$name})");
        }
        $this->assertSame(array_values(array_diff($adminwrite, ['content.write'])), $adminread);

        // The page: each profile lists its own actions; changes only for the administrator profile.
        $PAGE->set_url('/local/pluginiaconnect/oauth/authorize.php');
        $output = $PAGE->get_renderer('core');
        $page = new consent_page($request, $admin, $profiles, true, new \moodle_url('/local/pluginiaconnect/oauth/authorize.php'));
        $data = $page->export_for_template($output);
        $this->assertTrue($data['canwrite']);
        $byprofile = [];
        foreach ($data['profiles'] as $entry) {
            $byprofile[$entry['value']] = $entry;
        }
        $titles = static fn(array $list): array => array_column($list, 'title');
        $teacher = $titles($byprofile['teacher']['readtools']);
        $manager = $titles($byprofile['manager']['readtools']);
        $adminread = $titles($byprofile['admin']['readtools']);
        $this->assertLessThan(count($manager), count($teacher));
        $this->assertLessThan(count($adminread), count($manager));
        $this->assertEmpty(array_diff($teacher, $manager), 'Profiles are cumulative');
        $finduser = (string) tool_registry::get('find_users')::definition()->title;
        $sitestatus = (string) tool_registry::get('get_site_status')::definition()->title;
        $this->assertNotContains($finduser, $teacher);
        $this->assertContains($finduser, $manager);
        $this->assertNotContains($sitestatus, $manager);
        $this->assertContains($sitestatus, $adminread);
        $visibility = (string) tool_registry::get('set_activity_visibility')::definition()->title;
        $this->assertFalse($byprofile['teacher']['haswritetools']);
        $this->assertFalse($byprofile['manager']['haswritetools']);
        $this->assertSame([$visibility], $titles($byprofile['admin']['writetools']));
    }
}
