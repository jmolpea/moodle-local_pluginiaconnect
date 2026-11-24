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

namespace local_pluginiaconnect\task;

use local_pluginiaconnect\fixtures\integration_records;

defined('MOODLE_INTERNAL') || die();

require_once(__DIR__ . '/../fixtures/integration_records.php');

/**
 * Tests of the scheduled tasks.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\task\cleanup_expired
 * @covers     \local_pluginiaconnect\task\prune_audit
 */
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\task\cleanup_expired::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\task\prune_audit::class)]
final class tasks_test extends \advanced_testcase {
    /**
     * Pending actions past their time are expired and lose their stored arguments.
     */
    public function test_pending_actions_expire(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        $now = time();
        $late = integration_records::action($user->id, $grantid, 'pending', $now - 10);
        $live = integration_records::action($user->id, $grantid, 'pending', $now + 300);

        cleanup_expired::cleanup_actions($now);

        $expired = $DB->get_record('local_pluginiaconnect_action', ['id' => $late], '*', MUST_EXIST);
        $this->assertSame('expired', $expired->status);
        $this->assertNull($expired->args);
        $this->assertNull($expired->tokenhash);
        $this->assertEquals($now, $expired->timeresolved);

        $pending = $DB->get_record('local_pluginiaconnect_action', ['id' => $live], '*', MUST_EXIST);
        $this->assertSame('pending', $pending->status);
        $this->assertNotNull($pending->args);
    }

    /**
     * Resolved actions are kept one day for idempotent replies, then deleted.
     */
    public function test_resolved_actions_are_deleted_after_a_day(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        $now = time();
        $old = integration_records::action($user->id, $grantid, 'executed', $now - 2 * DAYSECS, $now - DAYSECS - 60);
        $recent = integration_records::action($user->id, $grantid, 'executed', $now - HOURSECS, $now - HOURSECS);

        cleanup_expired::cleanup_actions($now);

        $this->assertFalse($DB->record_exists('local_pluginiaconnect_action', ['id' => $old]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_action', ['id' => $recent]));
    }

    /**
     * Idempotency keys past their lifetime are deleted.
     */
    public function test_idempotency_keys_expire(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        $now = time();
        $old = integration_records::idem($grantid, $now - 1);
        $live = integration_records::idem($grantid, $now + 100);

        cleanup_expired::cleanup_idempotency_keys($now);

        $this->assertFalse($DB->record_exists('local_pluginiaconnect_idem', ['id' => $old]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_idem', ['id' => $live]));
    }

    /**
     * The whole clean-up task runs, including the part done by the token service.
     */
    public function test_cleanup_task_runs(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        $late = integration_records::action($user->id, $grantid, 'pending', time() - 10);

        $task = new cleanup_expired();
        $this->assertNotEmpty($task->get_name());
        $task->execute();

        $this->assertSame('expired', $DB->get_field('local_pluginiaconnect_action', 'status', ['id' => $late]));
        // A live grant is not touched by the clean-up.
        $this->assertEquals(1, $DB->get_field('local_pluginiaconnect_grant', 'status', ['id' => $grantid]));
    }

    /**
     * Audit records older than the retention period are deleted; the period is clamped to its limits.
     */
    public function test_prune_audit(): void {
        global $DB;
        $this->resetAfterTest();
        $user = $this->getDataGenerator()->create_user();
        $grantid = integration_records::grant($user->id);
        $now = time();
        set_config('auditretentiondays', 60, 'local_pluginiaconnect');
        $old = integration_records::audit($user->id, $grantid, $now - 61 * DAYSECS);
        $recent = integration_records::audit($user->id, $grantid, $now - 59 * DAYSECS);

        $task = new prune_audit();
        $this->assertNotEmpty($task->get_name());
        prune_audit::prune($now);
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_audit', ['id' => $old]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_audit', ['id' => $recent]));

        // A value below the minimum of 30 days is treated as 30 days: the 59 day old record goes.
        set_config('auditretentiondays', 1, 'local_pluginiaconnect');
        $young = integration_records::audit($user->id, $grantid, $now - 20 * DAYSECS);
        prune_audit::prune($now);
        $this->assertFalse($DB->record_exists('local_pluginiaconnect_audit', ['id' => $recent]));
        $this->assertTrue($DB->record_exists('local_pluginiaconnect_audit', ['id' => $young]));
    }

    /**
     * Both tasks are registered.
     */
    public function test_tasks_are_registered(): void {
        $this->assertInstanceOf(
            cleanup_expired::class,
            \core\task\manager::get_scheduled_task('\\' . cleanup_expired::class)
        );
        $this->assertInstanceOf(prune_audit::class, \core\task\manager::get_scheduled_task('\\' . prune_audit::class));
    }
}
