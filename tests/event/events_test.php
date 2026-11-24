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

namespace local_pluginiaconnect\event;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\local\event_base::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\connection_granted::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\connection_revoked::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\token_reuse_detected::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\tool_called::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\tool_denied::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\action_confirmation_requested::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\action_approved::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\action_rejected::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\site_registered::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\site_unregistered::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\event\settings_killswitch_changed::class)]
/**
 * Tests of the shape and validation of the plugin events.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\local\event_base
 * @covers     \local_pluginiaconnect\event\connection_granted
 * @covers     \local_pluginiaconnect\event\connection_revoked
 * @covers     \local_pluginiaconnect\event\token_reuse_detected
 * @covers     \local_pluginiaconnect\event\tool_called
 * @covers     \local_pluginiaconnect\event\tool_denied
 * @covers     \local_pluginiaconnect\event\action_confirmation_requested
 * @covers     \local_pluginiaconnect\event\action_approved
 * @covers     \local_pluginiaconnect\event\action_rejected
 * @covers     \local_pluginiaconnect\event\site_registered
 * @covers     \local_pluginiaconnect\event\site_unregistered
 * @covers     \local_pluginiaconnect\event\settings_killswitch_changed
 */
final class events_test extends \advanced_testcase {
    /**
     * Every event with the "other" data it needs and whether it is about an object and a user.
     *
     * @return array[] Class, other, has object, needs related user.
     */
    public static function event_provider(): array {
        $tool = ['tool' => 'list_my_courses', 'profile' => 'student', 'outcome' => 'ok', 'reason' => null, 'requestid' => 'r-1'];
        $action = ['tool' => 'set_activity_visibility', 'mode' => 'confirm'];
        return [
            'connection_granted' => [
                connection_granted::class,
                ['clientid' => 3, 'profile' => 'teacher', 'scopes' => 'profile courses.read'],
                true,
                true,
            ],
            'connection_revoked' => [connection_revoked::class, ['revokedby' => 'user'], true, true],
            'token_reuse_detected' => [token_reuse_detected::class, ['kind' => 'refresh'], true, true],
            'exposure_budget_exceeded' => [
                \local_pluginiaconnect\event\exposure_budget_exceeded::class, ['tool' => 'list_course_participants'], true, true,
            ],
            'tool_called' => [tool_called::class, $tool, true, true],
            'tool_denied' => [
                tool_denied::class,
                ['outcome' => 'denied', 'reason' => 'permission_denied'] + $tool,
                true,
                true,
            ],
            'action_confirmation_requested' => [action_confirmation_requested::class, $action, true, true],
            'action_approved' => [action_approved::class, $action, true, true],
            'action_rejected' => [action_rejected::class, $action, true, true],
            'site_registered' => [site_registered::class, [], false, false],
            'site_unregistered' => [site_unregistered::class, [], false, false],
            'settings_killswitch_changed' => [
                settings_killswitch_changed::class,
                ['setting' => 'enabled', 'value' => 1],
                false,
                false,
            ],
        ];
    }

    /**
     * Builds the data of an event.
     *
     * @param array $other Other data.
     * @param bool $hasobject Whether the event is about an object.
     * @param bool $needsuser Whether the event names a user.
     * @return array
     */
    private function event_data(array $other, bool $hasobject, bool $needsuser): array {
        $data = ['context' => \context_system::instance(), 'other' => $other];
        if ($hasobject) {
            $data['objectid'] = 42;
        }
        if ($needsuser) {
            $data['relateduserid'] = $this->getDataGenerator()->create_user()->id;
        }
        return $data;
    }

    /**
     * A well formed event triggers, has a name and a description, and keeps its data.
     *
     * @dataProvider event_provider
     * @param string $class Event class.
     * @param array $other Other data.
     * @param bool $hasobject Whether the event is about an object.
     * @param bool $needsuser Whether the event names a user.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('event_provider')]
    public function test_valid_event(string $class, array $other, bool $hasobject, bool $needsuser): void {
        $this->resetAfterTest();
        // Build the data first: creating the related user triggers a core event of its own.
        $data = $this->event_data($other, $hasobject, $needsuser);
        $sink = $this->redirectEvents();
        $class::create($data)->trigger();
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf($class, $event);
        $this->assertNotEmpty($class::get_name());
        $this->assertNotEmpty($event->get_description());
        $this->assertSame('local_pluginiaconnect', $event->component);
        if ($hasobject) {
            $this->assertEquals(42, $event->objectid);
            $this->assertStringStartsWith('local_pluginiaconnect_', $event->objecttable);
        }
        foreach ($other as $key => $value) {
            $this->assertEquals($value, $event->other[$key]);
        }
        $this->assertDebuggingNotCalled();
    }

    /**
     * Each required key of "other" is enforced.
     *
     * @dataProvider event_provider
     * @param string $class Event class.
     * @param array $other Other data.
     * @param bool $hasobject Whether the event is about an object.
     * @param bool $needsuser Whether the event names a user.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('event_provider')]
    public function test_missing_other_key_is_rejected(string $class, array $other, bool $hasobject, bool $needsuser): void {
        $this->resetAfterTest();
        if (!$other) {
            $this->assertEmpty($other);
            return;
        }
        foreach (array_keys($other) as $key) {
            $partial = $other;
            unset($partial[$key]);
            try {
                $class::create($this->event_data($partial, $hasobject, $needsuser));
                $this->fail("$class accepted other data without '$key'.");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /**
     * Keys outside the declared shape are refused, so that secrets and content cannot be logged.
     *
     * @dataProvider event_provider
     * @param string $class Event class.
     * @param array $other Other data.
     * @param bool $hasobject Whether the event is about an object.
     * @param bool $needsuser Whether the event names a user.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('event_provider')]
    public function test_unknown_other_key_is_rejected(string $class, array $other, bool $hasobject, bool $needsuser): void {
        $this->resetAfterTest();
        foreach (['access_token' => 'pc_at_x', 'arguments' => '{"a":1}', 'result' => 'text'] as $key => $value) {
            try {
                $class::create($this->event_data($other + [$key => $value], $hasobject, $needsuser));
                $this->fail("$class accepted the key '$key'.");
            } catch (\coding_exception $e) {
                $this->assertStringContainsString($key, $e->getMessage());
            }
        }
    }

    /**
     * Events about an object need its id, and events about a user need the user.
     *
     * @dataProvider event_provider
     * @param string $class Event class.
     * @param array $other Other data.
     * @param bool $hasobject Whether the event is about an object.
     * @param bool $needsuser Whether the event names a user.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('event_provider')]
    public function test_object_and_user_are_required(string $class, array $other, bool $hasobject, bool $needsuser): void {
        $this->resetAfterTest();
        if (!$hasobject && !$needsuser) {
            $this->assertNotEmpty($class::create($this->event_data($other, false, false)));
            return;
        }
        if ($hasobject) {
            $this->expectException(\coding_exception::class);
            $class::create($this->event_data($other, false, $needsuser));
        }
    }

    /**
     * Values in "other" must be short scalars.
     */
    public function test_other_values_must_be_short_scalars(): void {
        $this->resetAfterTest();
        $data = $this->event_data(['revokedby' => str_repeat('x', 300)], true, true);
        try {
            connection_revoked::create($data);
            $this->fail('A long value was accepted.');
        } catch (\coding_exception $e) {
            $this->assertStringContainsString('too long', $e->getMessage());
        }
        $data['other'] = ['revokedby' => ['user']];
        $this->expectException(\coding_exception::class);
        connection_revoked::create($data);
    }
}
