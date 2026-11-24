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

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once(__DIR__ . '/fixtures/integration_records.php');
require_once($CFG->dirroot . '/cohort/lib.php');

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\observer::class)]
/**
 * Tests of the reactions to core events.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\observer
 */
final class observer_test extends \advanced_testcase {
    /**
     * Number of active grants of a user.
     *
     * @param int $userid User id.
     * @return int
     */
    private function active_grants(int $userid): int {
        global $DB;
        return $DB->count_records('local_pluginiaconnect_grant', ['userid' => $userid, 'status' => 1]);
    }

    /**
     * Deleting a user removes their connections, tokens and codes.
     */
    public function test_user_deleted_removes_connections(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        integration_records::authcode($user->id);
        integration_records::grant($other->id);

        delete_user($user);

        $this->assertFalse($DB->record_exists('local_pluginiaconnect_grant', ['userid' => $user->id]));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_token', ['grantid' => $grantid]));
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_authcode', ['userid' => $user->id]));
        $this->assertSame(1, $this->active_grants($other->id));
    }

    /**
     * Suspending a user revokes their connections.
     */
    public function test_suspended_user_is_revoked(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);

        $DB->set_field('user', 'suspended', 1, ['id' => $user->id]);
        \core\event\user_updated::create_from_userid($user->id)->trigger();

        $grant = $DB->get_record('local_pluginiaconnect_grant', ['id' => $grantid], '*', MUST_EXIST);
        $this->assertEquals(0, $grant->status);
        $this->assertSame('system', $grant->revokedby);
    }

    /**
     * Updating a user who stays active keeps their connections.
     */
    public function test_updated_active_user_keeps_connections(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        integration_records::grant($user->id);

        \core\event\user_updated::create_from_userid($user->id)->trigger();

        $this->assertSame(1, $this->active_grants($user->id));
    }

    /**
     * A password change revokes the connections of that user, and only when the site says so.
     */
    public function test_password_change(): void {
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $other = $this->getDataGenerator()->create_user();
        integration_records::grant($user->id);
        integration_records::grant($other->id);

        set_config('revokeonpasswordchange', 0, 'local_pluginiaconnect');
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertSame(1, $this->active_grants($user->id));

        set_config('revokeonpasswordchange', 1, 'local_pluginiaconnect');
        \core\event\user_password_updated::create_from_user($user)->trigger();
        $this->assertSame(0, $this->active_grants($user->id));
        $this->assertSame(1, $this->active_grants($other->id));
    }

    /**
     * Role, enrolment and cohort changes drop the cached profiles of the user.
     */
    public function test_role_and_membership_changes_invalidate_eligibility(): void {
        global $DB;
        $this->resetAfterTest();
        $generator = $this->getDataGenerator();
        $user = $generator->create_user();
        $course = $generator->create_course();
        $cache = \cache::make('local_pluginiaconnect', 'eligibility');
        $key = 'u' . $user->id;
        $teacherrole = $DB->get_field('role', 'id', ['shortname' => 'editingteacher'], MUST_EXIST);
        $context = \context_course::instance($course->id);

        $cache->set($key, 'student');
        role_assign($teacherrole, $user->id, $context->id);
        $this->assertFalse($cache->get($key), 'role_assigned');

        $cache->set($key, 'student');
        role_unassign($teacherrole, $user->id, $context->id);
        $this->assertFalse($cache->get($key), 'role_unassigned');

        $generator->enrol_user($user->id, $course->id, 'student');
        $cache->set($key, 'student');
        $manual = enrol_get_plugin('manual');
        $instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
        $manual->unenrol_user($instance, $user->id);
        $this->assertFalse($cache->get($key), 'user_enrolment_deleted');

        $cohort = $generator->create_cohort();
        $cache->set($key, 'student');
        cohort_add_member($cohort->id, $user->id);
        $this->assertFalse($cache->get($key), 'cohort_member_added');

        $cache->set($key, 'student');
        cohort_remove_member($cohort->id, $user->id);
        $this->assertFalse($cache->get($key), 'cohort_member_removed');
    }
}
