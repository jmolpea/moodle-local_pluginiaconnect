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

use local_pluginiaconnect\fixtures\integration_records;
use local_pluginiaconnect\local\audit\report;
use local_pluginiaconnect\local\connections;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\token_service;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tools_matrix;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/integration_records.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_fixture.php');
require_once($CFG->dirroot . '/local/pluginiaconnect/tests/fixtures/mcp_test_tools.php');
require_once($CFG->libdir . '/adminlib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(connections::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(report::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(tools_matrix::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(observer::class)]
/**
 * Tests of what the user and administrator pages rely on: listing and revoking
 * connections, the audit report, the tool matrix, page permissions and the
 * notification of a new connection.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\connections
 * @covers     \local_pluginiaconnect\local\audit\report
 * @covers     \local_pluginiaconnect\local\tools_matrix
 * @covers     \local_pluginiaconnect\observer
 */
final class phase2_pages_test extends \advanced_testcase {
    /**
     * Each test starts clean.
     */
    protected function setUp(): void {
        parent::setUp();
        $this->resetAfterTest();
    }

    /**
     * Removes the probe tools registered by a test.
     */
    protected function tearDown(): void {
        tool_registry::set_test_tools([]);
        parent::tearDown();
    }

    /**
     * A user sees their own connections only, with profile, access level and dates, and no secret.
     */
    public function test_own_connections_list(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Pérez']);
        $other = $generator->create_user();
        $readgrant = integration_records::grant($ana->id);
        [, $context] = \local_pluginiaconnect_mcp_fixture::grant(
            $ana->id,
            'teacher',
            ['profile', 'courses.read', 'grades.write'],
            ['clienttype' => 'gateway', 'actor' => 'Copilot <b>Cowork</b>', 'actorid' => 'copilot-cowork']
        );
        integration_records::grant($other->id);
        $revoked = integration_records::grant($ana->id);
        token_service::revoke_grant($revoked, 'user');

        $rows = connections::for_user($ana->id);
        $this->assertCount(2, $rows);
        $byid = array_column($rows, null, 'id');

        $read = $byid[$readgrant];
        $this->assertSame('Ana Pérez', $read['userfullname']);
        $this->assertFalse($read['write']);
        $this->assertSame(get_string('level_read', 'local_pluginiaconnect'), $read['levelname']);
        $this->assertSame(get_string('profile_student', 'local_pluginiaconnect'), $read['profilename']);
        $this->assertFalse($read['hasactor']);

        $write = $byid[$context->grantid];
        $this->assertTrue($write['write']);
        $this->assertSame(get_string('level_write', 'local_pluginiaconnect'), $write['levelname']);
        $this->assertTrue($write['isgateway']);
        // The assistant name declared by the gateway is plain text for the template to escape.
        $this->assertSame('Copilot <b>Cowork</b>', $write['actor']);

        // Nothing that could act as a credential is in the rows.
        $encoded = json_encode($rows);
        foreach ($DB->get_fieldset_select('local_pluginiaconnect_token', 'tokenhash', '1 = 1') as $hash) {
            $this->assertStringNotContainsString($hash, $encoded);
        }
        $this->assertStringNotContainsString('pc_at_', $encoded);
    }

    /**
     * A user can revoke their own connection and nobody else's; the token dies at once.
     */
    public function test_revoke_own(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $eve = $generator->create_user();
        [$token, $context] = \local_pluginiaconnect_mcp_fixture::grant($ana->id, 'student', ['profile', 'courses.read']);
        $this->assertNotNull(token_service::authenticate($token));

        $this->assertFalse(connections::revoke_own($context->grantid, (int) $eve->id), 'Another user cannot revoke it');
        $this->assertNotNull(token_service::authenticate($token));
        $this->assertFalse(connections::revoke_own(987654, (int) $ana->id));

        $sink = $this->redirectEvents();
        $this->assertTrue(connections::revoke_own($context->grantid, (int) $ana->id));
        $events = $sink->get_events();
        $sink->close();
        $this->assertNull(token_service::authenticate($token));
        $this->assertSame('user', $DB->get_field('local_pluginiaconnect_grant', 'revokedby', ['id' => $context->grantid]));
        $this->assertCount(1, $events);
        $this->assertInstanceOf(\local_pluginiaconnect\event\connection_revoked::class, reset($events));
        $this->assertSame([], connections::for_user($ana->id));
    }

    /**
     * Administrator list: filters, paging and the three ways of revoking.
     */
    public function test_admin_connections(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user();
        $ben = $generator->create_user();
        $clienta = integration_records::client();
        $clientb = integration_records::client();
        $a1 = integration_records::grant($ana->id, $clienta);
        $a2 = integration_records::grant($ana->id, $clientb);
        $b1 = integration_records::grant($ben->id, $clienta);
        $b2 = integration_records::grant($ben->id, $clientb);

        $this->assertSame(4, connections::count(null, null));
        $this->assertSame(2, connections::count((int) $ana->id, null));
        $this->assertSame(2, connections::count(null, $clienta));
        $this->assertSame(1, connections::count((int) $ben->id, $clientb));
        $this->assertCount(3, connections::search(null, null, 1, 3));
        $this->assertSame([$b2], array_column(connections::search((int) $ben->id, $clientb), 'id'));
        $this->assertEqualsCanonicalizing([$clienta, $clientb], array_keys(connections::clients_in_use()));

        $this->assertTrue(connections::revoke_one($a1));
        $this->assertFalse(connections::revoke_one(987654));
        $this->assertSame('admin', $DB->get_field('local_pluginiaconnect_grant', 'revokedby', ['id' => $a1]));
        $this->assertSame(1, connections::revoke_user((int) $ana->id));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $a2]));
        $this->assertSame(1, connections::revoke_client($clienta));
        $this->assertEquals(0, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $b1]));
        $this->assertSame([$b2], array_column(connections::search(null, null), 'id'));
        $this->assertSame([$clientb], array_keys(connections::clients_in_use()));
        $this->assertSame(0, $DB->count_records_select(
            'local_pluginiaconnect_token',
            'grantid <> :keep AND timerevoked IS NULL',
            ['keep' => $b2]
        ));
    }

    /**
     * Audit report: filters, counting, summary of refusals, the user's own list and the export.
     */
    public function test_audit_report(): void {
        global $DB;
        $generator = $this->getDataGenerator();
        $ana = $generator->create_user(['firstname' => 'Ana', 'lastname' => 'Pérez']);
        $ben = $generator->create_user();
        $course = $generator->create_course(['fullname' => 'History & Art 101']);
        $grant = integration_records::grant($ana->id);
        $now = time();

        $ok = integration_records::audit($ana->id, $grant, $now - 10);
        $denied = integration_records::audit($ana->id, $grant, $now - 20);
        $DB->update_record('local_pluginiaconnect_audit', (object) [
            'id' => $denied, 'outcome' => 'denied', 'reason' => 'permission_denied', 'tool' => 'get_my_grades',
            'courseid' => $course->id,
        ]);
        $limited = integration_records::audit($ben->id, $grant, $now - 30);
        $DB->update_record('local_pluginiaconnect_audit', (object) [
            'id' => $limited, 'outcome' => 'rate_limited', 'reason' => 'rate_limited',
        ]);
        $old = integration_records::audit($ana->id, $grant, $now - 5 * DAYSECS);

        $ids = static function (array $filters): array {
            $found = [];
            $recordset = report::rows($filters);
            foreach ($recordset as $record) {
                $found[] = (int) $record->id;
            }
            $recordset->close();
            return $found;
        };
        $this->assertSame([$ok, $denied, $limited, $old], $ids([]), 'Newest first');
        $this->assertSame(4, report::count([]));
        $this->assertSame([$ok, $denied, $old], $ids(['userid' => (int) $ana->id]));
        $this->assertSame([$denied], $ids(['tool' => 'get_my_grades']));
        $this->assertSame([$denied], $ids(['courseid' => (int) $course->id]));
        $this->assertSame([$limited], $ids(['outcome' => 'rate_limited']));
        $this->assertSame([$ok, $denied], $ids(['userid' => (int) $ana->id, 'timefrom' => $now - DAYSECS]));
        $this->assertSame([$old], $ids(['timeto' => $now - DAYSECS]));
        $this->assertSame(1, report::count(['outcome' => 'denied', 'userid' => (int) $ana->id]));

        $this->assertSame([
            ['outcome' => 'denied', 'reason' => 'permission_denied', 'total' => 1],
            ['outcome' => 'rate_limited', 'reason' => 'rate_limited', 'total' => 1],
        ], report::summary([]));
        $this->assertSame([], report::summary(['outcome' => 'ok']));

        $this->assertEqualsCanonicalizing(['list_my_courses', 'get_my_grades'], array_keys(report::tools_seen()));
        $this->assertEqualsCanonicalizing(['ok', 'denied', 'rate_limited'], array_keys(report::outcomes_seen()));

        // Filter cleaning drops anything that is not a plain value.
        $this->assertSame([], report::clean_filters([
            'userid' => -3, 'courseid' => 'abc', 'tool' => "x' OR 1=1 --", 'outcome' => '<script>', 'timefrom' => 0,
            'unknown' => 5,
        ]));
        $this->assertSame(
            ['userid' => 7, 'timeto' => 99, 'tool' => 'list_my_courses', 'outcome' => 'ok'],
            report::clean_filters(['userid' => '7', 'timeto' => 99, 'tool' => 'list_my_courses', 'outcome' => 'ok'])
        );

        // The user's own list never includes other users' calls and shows display values only.
        $own = report::recent_for_user((int) $ana->id);
        $this->assertSame([$ok, $denied, $old], array_column($own, 'id'));
        $this->assertSame('History & Art 101', $own[1]['course']);
        $this->assertTrue($own[1]['refused']);
        $this->assertSame('Ana Pérez', $own[0]['user']);

        // Neither the display rows nor the export carry the hash of the arguments.
        $hash = hash('sha256', '{}');
        $this->assertStringNotContainsString($hash, json_encode($own));
        $columns = report::export_columns();
        $this->assertArrayNotHasKey('argshash', $columns);
        $recordset = report::rows(['tool' => 'get_my_grades']);
        $exported = report::export_row($recordset->current());
        $recordset->close();
        $this->assertSame(array_keys($columns), array_keys($exported));
        $this->assertNotContains($hash, $exported);
        $this->assertSame('denied', $exported['outcome']);
        $this->assertSame((string) $course->id, $exported['courseid']);
    }

    /**
     * The own list is capped and a spreadsheet cannot be made to run a formula from the export.
     */
    public function test_audit_limits_and_csv_safety(): void {
        global $DB;
        $user = $this->getDataGenerator()->create_user(['firstname' => '=HYPERLINK("http://evil")', 'lastname' => 'X']);
        $grant = integration_records::grant($user->id);
        for ($i = 0; $i < report::OWN_LIMIT + 5; $i++) {
            integration_records::audit($user->id, $grant, time() - $i);
        }
        $this->assertCount(report::OWN_LIMIT, report::recent_for_user((int) $user->id));

        $recordset = report::rows(['userid' => (int) $user->id], 0, 1);
        $row = report::export_row($recordset->current());
        $recordset->close();
        $this->assertStringStartsWith("'=", $row['user']);

        // A deleted user and a removed client still give a readable row.
        $DB->delete_records('user', ['id' => $user->id]);
        $recordset = report::rows(['userid' => (int) $user->id], 0, 1);
        $described = report::describe($recordset->current());
        $recordset->close();
        $this->assertStringContainsString((string) $user->id, $described['user']);
        $this->assertStringNotContainsString('[[', $described['user']);
        $this->assertStringNotContainsString('[[', $described['client']);
    }

    /**
     * Tool matrix: switching cells off works at once; nothing a form sends can widen the catalogue.
     */
    public function test_tools_matrix(): void {
        tool_registry::set_test_tools(['write_probe' => \local_pluginiaconnect_write_probe::class]);
        $rows = array_column(tools_matrix::rows(), null, 'name');

        $this->assertSame(['student', 'teacher', 'manager', 'admin'], array_keys($rows['list_my_courses']['cells']));
        $this->assertTrue($rows['list_my_courses']['cells']['student']);
        $this->assertFalse($rows['list_my_courses']['write']);
        $probe = $rows['write_probe'];
        $this->assertSame(['teacher', 'manager', 'admin'], array_keys($probe['cells']), 'No cell below the minimum profile');
        $this->assertFalse($probe['cells']['teacher'], 'Write tools are off until an administrator switches them on');
        $this->assertSame(tool_requirement::APPROVAL_CONFIRM, $probe['minapproval']);
        $this->assertSame(['confirm', 'approval'], $probe['modes'], 'The mode below the minimum is not offered');

        // Build a submission equal to the current state, then change three things.
        $cells = [];
        foreach ($rows as $name => $row) {
            $cells[$name] = $row['cells'];
        }
        $this->assertSame(0, tools_matrix::save($cells, ['write_probe' => 'confirm']));

        $cells['list_my_courses']['student'] = false;
        $cells['write_probe']['teacher'] = true;
        // Attempts to widen: a cell below the minimum profile, an unknown tool, a weaker approval mode.
        $cells['write_probe']['student'] = true;
        $cells['run_sql'] = ['admin' => true];
        $this->assertSame(2, tools_matrix::save($cells, ['write_probe' => 'none', 'run_sql' => 'none']));

        $this->assertFalse(site_policy::is_tool_enabled('list_my_courses', 'student'));
        $this->assertTrue(site_policy::is_tool_enabled('list_my_courses', 'teacher'));
        $this->assertTrue(site_policy::is_tool_enabled('write_probe', 'teacher'));
        $this->assertFalse(get_config('local_pluginiaconnect', 'tool_write_probe_student'));
        $this->assertFalse(get_config('local_pluginiaconnect', 'tool_run_sql_admin'));
        $this->assertFalse(get_config('local_pluginiaconnect', 'approval_run_sql'));
        $this->assertSame(tool_requirement::APPROVAL_CONFIRM, site_policy::approval_mode('write_probe', null));

        // Tightening is accepted and survives a later attempt to go back below it by direct configuration.
        $this->assertSame(1, tools_matrix::save($cells, ['write_probe' => 'approval']));
        $this->assertSame(tool_requirement::APPROVAL_APPROVAL, site_policy::approval_mode('write_probe', null));
        set_config('approval_write_probe', 'none', 'local_pluginiaconnect');
        $this->assertSame(tool_requirement::APPROVAL_CONFIRM, site_policy::approval_mode('write_probe', null));
        $this->assertSame('confirm', array_column(tools_matrix::rows(), null, 'name')['write_probe']['approval']);
        $this->assertSame(['none', 'confirm', 'approval'], tools_matrix::allowed_modes('none'));
        $this->assertSame([], tools_matrix::allowed_modes('bogus'));
    }

    /**
     * Saving the matrix refreshes cached eligibility, because tools decide which scopes a consent can grant.
     */
    public function test_tools_matrix_purges_caches(): void {
        $cache = \cache::make('local_pluginiaconnect', 'eligibility');
        $cache->set('u12345', 'student');
        $cells = [];
        foreach (tools_matrix::rows() as $row) {
            $cells[$row['name']] = $row['cells'];
        }
        tools_matrix::save($cells, []);
        $this->assertSame('student', $cache->get('u12345'), 'Nothing changed: nothing purged');
        $cells['get_my_grades']['student'] = false;
        tools_matrix::save($cells, []);
        $this->assertFalse($cache->get('u12345'));
    }

    /**
     * Every administrator page is registered with the capability the specification gives it.
     */
    public function test_admin_pages_require_their_capability(): void {
        $this->setAdminUser();
        $root = admin_get_root(true, true);
        $expected = [
            'local_pluginiaconnect_tools' => 'moodle/site:config',
            'local_pluginiaconnect_register' => 'moodle/site:config',
            'local_pluginiaconnect_health' => 'moodle/site:config',
            'local_pluginiaconnect_connections' => 'local/pluginiaconnect:manageconnections',
            'local_pluginiaconnect_audit' => 'local/pluginiaconnect:viewaudit',
        ];
        foreach ($expected as $name => $capability) {
            $page = $root->locate($name);
            $this->assertInstanceOf(\admin_externalpage::class, $page, $name);
            $this->assertSame([$capability], $page->req_capability, $name);
        }

        // A manager reads the audit report but cannot manage connections or reach the settings.
        $manager = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->role_assign('manager', $manager->id);
        $this->setUser($manager);
        $root = admin_get_root(true, true);
        $this->assertTrue($root->locate('local_pluginiaconnect_audit')->check_access());
        $this->assertFalse($root->locate('local_pluginiaconnect_connections')->check_access());
        $this->assertNull($root->locate('local_pluginiaconnect_tools'));
        $this->assertNull($root->locate('local_pluginiaconnect_register'));

        // A student sees none of them.
        $this->setUser($this->getDataGenerator()->create_user());
        $root = admin_get_root(true, true);
        $this->assertFalse($root->locate('local_pluginiaconnect_audit')->check_access());
    }

    /**
     * A new connection sends one notification to its owner, which they can switch off.
     */
    public function test_new_connection_notification(): void {
        $user = $this->getDataGenerator()->create_user();
        $clientid = integration_records::client();
        $grantid = integration_records::grant($user->id, $clientid);
        $this->preventResetByRollback();
        $sink = $this->redirectMessages();

        token_service::notify('connection_granted', $grantid, (int) $user->id, [
            'clientid' => $clientid, 'profile' => 'teacher', 'scopes' => 'profile courses.read',
        ]);
        $messages = $sink->get_messages();
        $this->assertCount(1, $messages);
        $message = reset($messages);
        $this->assertSame('local_pluginiaconnect', $message->component);
        $this->assertSame('connectioncreated', $message->eventtype);
        $this->assertEquals($user->id, $message->useridto);
        $this->assertSame(get_string('message_connectioncreated_subject', 'local_pluginiaconnect'), $message->subject);
        $this->assertStringContainsString('/local/pluginiaconnect/connections.php', $message->fullmessage);
        $this->assertStringContainsString('teacher', $message->fullmessage);
        $this->assertStringNotContainsString('courses.read', $message->fullmessage, 'Scopes are not part of the message');
        $sink->clear();

        // The provider exists, so it shows in the notification preferences of the user.
        $providers = array_column(message_get_providers_for_user($user->id), 'name', 'component');
        $this->assertSame('connectioncreated', $providers['local_pluginiaconnect'] ?? null);

        // An event about a user who no longer exists sends nothing and does not fail.
        token_service::notify('connection_granted', $grantid, 987654, [
            'clientid' => $clientid, 'profile' => 'teacher', 'scopes' => 'profile',
        ]);
        $this->assertCount(0, $sink->get_messages());
        $sink->close();
    }

    /**
     * The preferences link appears for the user themselves while the connector is on or they still have a connection.
     */
    public function test_preferences_link(): void {
        global $CFG, $PAGE;
        require_once($CFG->dirroot . '/local/pluginiaconnect/lib.php');
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $course = get_site();
        $call = static function (\stdClass $whose) use ($course): bool {
            $node = \navigation_node::create('Account', null, \navigation_node::TYPE_CONTAINER);
            local_pluginiaconnect_extend_navigation_user_settings(
                $node,
                $whose,
                \context_user::instance($whose->id),
                $course,
                \context_course::instance($course->id)
            );
            return (bool) $node->get('local_pluginiaconnect_connections');
        };

        $this->setUser($user);
        $this->assertFalse($call($user), 'Connector off and no connection');
        set_config('enabled', 1, 'local_pluginiaconnect');
        $this->assertTrue($call($user));
        $this->assertFalse($call($other), 'Never on the preferences of another user');
        set_config('enabled', 0, 'local_pluginiaconnect');
        integration_records::grant($user->id);
        $this->assertTrue($call($user), 'A user with a connection can always reach the page to remove it');
        $this->setGuestUser();
        $this->assertFalse($call(guest_user()));
    }
}
