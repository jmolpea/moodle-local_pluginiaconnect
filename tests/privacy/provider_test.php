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

namespace local_pluginiaconnect\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;
use local_pluginiaconnect\fixtures\integration_records;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/integration_records.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\privacy\provider::class)]
/**
 * Tests of the Privacy API implementation.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\privacy\provider
 */
final class provider_test extends \core_privacy\tests\provider_testcase {
    /** @var string[] Tables of the plugin that hold a user id. */
    private const USER_TABLES = ['grant', 'authcode', 'audit', 'action'];

    /**
     * Creates one row of every kind for a user.
     *
     * @param int $userid User id.
     * @return int Grant id.
     */
    private function create_data(int $userid): int {
        $grantid = integration_records::grant($userid);
        integration_records::authcode($userid);
        integration_records::audit($userid, $grantid);
        integration_records::action($userid, $grantid);
        integration_records::idem($grantid);
        return $grantid;
    }

    /**
     * Number of rows of the plugin that belong to a user, across the tables with a user id.
     *
     * @param int $userid User id.
     * @return int
     */
    private function count_user_rows(int $userid): int {
        global $DB;
        $count = 0;
        foreach (self::USER_TABLES as $table) {
            $count += $DB->count_records('local_pluginiaconnect_' . $table, ['userid' => $userid]);
        }
        return $count;
    }

    /**
     * The metadata describes the six tables and the two external destinations.
     */
    public function test_get_metadata(): void {
        $collection = provider::get_metadata(new collection('local_pluginiaconnect'));
        $names = [];
        foreach ($collection->get_collection() as $item) {
            $names[] = $item->get_name();
            $this->assertNotEmpty($item->get_privacy_fields());
            // Every summary and every field description must be an existing string.
            $this->assertTrue(get_string_manager()->string_exists($item->get_summary(), 'local_pluginiaconnect'));
            foreach ($item->get_privacy_fields() as $key) {
                $this->assertTrue(get_string_manager()->string_exists($key, 'local_pluginiaconnect'), $key);
            }
        }
        sort($names);
        $this->assertSame([
            'assistant',
            'gateway',
            'local_pluginiaconnect_action',
            'local_pluginiaconnect_audit',
            'local_pluginiaconnect_authcode',
            'local_pluginiaconnect_grant',
            'local_pluginiaconnect_idem',
            'local_pluginiaconnect_token',
        ], $names);
    }

    /**
     * Every stored field of the tables with personal data is declared, so the description cannot fall
     * behind the tables.
     */
    public function test_metadata_declares_every_stored_field(): void {
        global $DB;
        $collection = provider::get_metadata(new collection('local_pluginiaconnect'));
        $checked = 0;
        foreach ($collection->get_collection() as $item) {
            if (!$item instanceof \core_privacy\local\metadata\types\database_table) {
                continue;
            }
            $columns = array_keys($DB->get_columns($item->get_name()));
            $declared = array_keys($item->get_privacy_fields());
            sort($columns);
            sort($declared);
            $this->assertSame(array_values(array_diff($columns, ['id'])), $declared, $item->get_name());
            $checked++;
        }
        $this->assertSame(6, $checked);
    }

    /**
     * Only users with data get the system context.
     */
    public function test_get_contexts_for_userid(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $this->create_data($user->id);

        $contextlist = provider::get_contexts_for_userid($user->id);
        $this->assertSame([(int) \context_system::instance()->id], array_map('intval', $contextlist->get_contextids()));
        $this->assertCount(0, provider::get_contexts_for_userid($other->id)->get_contextids());
    }

    /**
     * The user list of the system context holds exactly the users with data.
     */
    public function test_get_users_in_context(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $auditonly = $this->getDataGenerator()->create_user();
        $this->getDataGenerator()->create_user();
        $this->create_data($user->id);
        integration_records::audit($auditonly->id, 999);

        $userlist = new userlist(\context_system::instance(), 'local_pluginiaconnect');
        provider::get_users_in_context($userlist);
        $userids = array_map('intval', $userlist->get_userids());
        sort($userids);
        $expected = [(int) $user->id, (int) $auditonly->id];
        sort($expected);
        $this->assertSame($expected, $userids);

        $course = $this->getDataGenerator()->create_course();
        $userlist = new userlist(\context_course::instance($course->id), 'local_pluginiaconnect');
        provider::get_users_in_context($userlist);
        $this->assertCount(0, $userlist->get_userids());
    }

    /**
     * The export contains connections, calls and requested changes, and no token hash.
     */
    public function test_export_user_data(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = $this->create_data($user->id);
        $context = \context_system::instance();
        $root = get_string('pluginname', 'local_pluginiaconnect');

        provider::export_user_data(new approved_contextlist($user, 'local_pluginiaconnect', [$context->id]));
        $writer = writer::with_context($context);
        $this->assertTrue($writer->has_any_data());

        $connections = $writer->get_data([$root, get_string('privacy:path:connections', 'local_pluginiaconnect')]);
        $this->assertCount(1, $connections->connections);
        $this->assertSame('student', $connections->connections[0]->profile);
        $this->assertCount(2, $connections->connections[0]->tokens);

        $calls = $writer->get_data([$root, get_string('privacy:path:audit', 'local_pluginiaconnect')]);
        $this->assertCount(1, $calls->calls);
        $this->assertSame('list_my_courses', $calls->calls[0]->tool);

        $actions = $writer->get_data([$root, get_string('privacy:path:actions', 'local_pluginiaconnect')]);
        $this->assertCount(1, $actions->actions);

        // Stored answers of repeated changes are exported when there are any; the key is never part of them.
        $repeats = $writer->get_data([$root, get_string('privacy:path:repeats', 'local_pluginiaconnect')]);
        $stored = $DB->count_records('local_pluginiaconnect_idem', ['grantid' => $grantid]);
        $this->assertCount($stored, $repeats->repeats ?? []);
        foreach ($DB->get_fieldset_select('local_pluginiaconnect_idem', 'keyhash', 'grantid = ?', [$grantid]) as $keyhash) {
            $this->assertStringNotContainsString($keyhash, json_encode($repeats));
        }

        $exported = json_encode([$connections, $calls, $actions]);
        foreach ($DB->get_fieldset_select('local_pluginiaconnect_token', 'tokenhash', 'grantid = ?', [$grantid]) as $hash) {
            $this->assertStringNotContainsString($hash, $exported);
        }
    }

    /**
     * A user without data exports nothing.
     */
    public function test_export_without_data(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $context = \context_system::instance();
        provider::export_user_data(new approved_contextlist($user, 'local_pluginiaconnect', [$context->id]));
        $this->assertFalse(writer::with_context($context)->has_any_data());
    }

    /**
     * Deleting the data of one user leaves the other users untouched.
     */
    public function test_delete_data_for_user(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $grantid = $this->create_data($user->id);
        $othergrantid = $this->create_data($other->id);
        $context = \context_system::instance();

        provider::delete_data_for_user(new approved_contextlist($user, 'local_pluginiaconnect', [$context->id]));

        $this->assertSame(0, $this->count_user_rows($user->id));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_token', ['grantid' => $grantid]));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_idem', ['grantid' => $grantid]));
        $this->assertSame(4, $this->count_user_rows($other->id));
        $this->assertSame(2, $DB->count_records('local_pluginiaconnect_token', ['grantid' => $othergrantid]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_idem', ['grantid' => $othergrantid]));
    }

    /**
     * A context other than the system context deletes nothing.
     */
    public function test_delete_ignores_other_contexts(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $this->create_data($user->id);
        $course = $this->getDataGenerator()->create_course();
        $context = \context_course::instance($course->id);

        provider::delete_data_for_user(new approved_contextlist($user, 'local_pluginiaconnect', [$context->id]));
        provider::delete_data_for_all_users_in_context($context);

        $this->assertSame(4, $this->count_user_rows($user->id));
    }

    /**
     * Deleting the data of a list of users.
     */
    public function test_delete_data_for_users(): void {
        $this->resetAfterTest();
        $first = $this->getDataGenerator()->create_user();
        $second = $this->getDataGenerator()->create_user();
        $kept = $this->getDataGenerator()->create_user();
        foreach ([$first, $second, $kept] as $user) {
            $this->create_data($user->id);
        }

        $userlist = new approved_userlist(\context_system::instance(), 'local_pluginiaconnect', [$first->id, $second->id]);
        provider::delete_data_for_users($userlist);

        $this->assertSame(0, $this->count_user_rows($first->id));
        $this->assertSame(0, $this->count_user_rows($second->id));
        $this->assertSame(4, $this->count_user_rows($kept->id));
    }

    /**
     * Deleting everything in the system context empties the personal tables.
     */
    public function test_delete_data_for_all_users_in_context(): void {
        global $DB;
        $this->resetAfterTest();
        foreach ([1, 2] as $unused) {
            $this->create_data($this->getDataGenerator()->create_user()->id);
        }

        provider::delete_data_for_all_users_in_context(\context_system::instance());

        foreach (['grant', 'authcode', 'audit', 'action', 'token', 'idem'] as $table) {
            $this->assertSame(0, $DB->count_records('local_pluginiaconnect_' . $table), $table);
        }
    }
}
