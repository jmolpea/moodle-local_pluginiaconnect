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

namespace local_pluginiaconnect\admin;

use local_pluginiaconnect\event\settings_killswitch_changed;
use local_pluginiaconnect\local\config;

#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\admin\setting_bounded_int::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\admin\setting_list::class)]
#[\PHPUnit\Framework\Attributes\CoversClass(\local_pluginiaconnect\admin\callbacks::class)]
/**
 * Tests of the admin settings: limits, list validation and reactions to changes.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 * @covers     \local_pluginiaconnect\admin\setting_bounded_int
 * @covers     \local_pluginiaconnect\admin\setting_list
 * @covers     \local_pluginiaconnect\admin\callbacks
 */
final class settings_test extends \advanced_testcase {
    /**
     * Every bounded setting has a factory default inside its limits.
     */
    public function test_defaults_are_inside_limits(): void {
        foreach (config::LIMITS as $name => [$min, $max]) {
            $this->assertArrayHasKey($name, config::DEFAULTS);
            $this->assertGreaterThanOrEqual($min, config::DEFAULTS[$name], $name);
            $this->assertLessThanOrEqual($max, config::DEFAULTS[$name], $name);
        }
    }

    /**
     * A bounded setting accepts its limits and refuses anything outside or not a whole number.
     */
    public function test_bounded_int_validation(): void {
        $this->resetAfterTest();
        foreach (config::LIMITS as $name => [$min, $max]) {
            $setting = new setting_bounded_int($name, 'Name', 'Description');
            $this->assertTrue($setting->validate((string) $min), $name);
            $this->assertTrue($setting->validate((string) $max), $name);
            $this->assertIsString($setting->validate((string) ($min - 1)), $name);
            $this->assertIsString($setting->validate((string) ($max + 1)), $name);
            foreach (['', 'abc', '1.5', '-5', '10 ; drop'] as $bad) {
                $this->assertIsString($setting->validate($bad), "$name: '$bad'");
            }
            $this->assertEquals(config::DEFAULTS[$name], $setting->get_defaultsetting());
        }
    }

    /**
     * A bounded setting does not store an out of range value.
     */
    public function test_bounded_int_does_not_store_invalid_value(): void {
        $this->resetAfterTest();
        $setting = new setting_bounded_int('ratewriteminute', 'Name', 'Description');
        [, $max] = config::LIMITS['ratewriteminute'];
        $this->assertNotSame('', $setting->write_setting((string) ($max + 1)));
        $this->assertNotEquals($max + 1, get_config('local_pluginiaconnect', 'ratewriteminute'));
        $this->assertSame('', $setting->write_setting('3'));
        $this->assertEquals(3, get_config('local_pluginiaconnect', 'ratewriteminute'));
    }

    /**
     * Entries of each kind of list.
     *
     * @return array[] Kind, entry, expected validity.
     */
    public static function entry_provider(): array {
        return [
            [setting_list::KIND_IDS, '12', true],
            [setting_list::KIND_IDS, '0', false],
            [setting_list::KIND_IDS, '-3', false],
            [setting_list::KIND_IDS, '12a', false],
            [setting_list::KIND_REDIRECTS, 'https://claude.ai/api/mcp/auth_callback', true],
            [setting_list::KIND_REDIRECTS, 'http://claude.ai/api/mcp/auth_callback', false],
            [setting_list::KIND_REDIRECTS, 'https://claude.ai/cb#fragment', false],
            [setting_list::KIND_REDIRECTS, 'https://*.example.com/cb', false],
            [setting_list::KIND_REDIRECTS, 'https://user:pass@example.com/cb', false],
            [setting_list::KIND_REDIRECTS, 'javascript:alert(1)', false],
            [setting_list::KIND_REDIRECTS, 'https://exam ple.com/cb', false],
            [setting_list::KIND_HOSTS, 'claude.ai', true],
            [setting_list::KIND_HOSTS, 'connect.pluginia.es', true],
            [setting_list::KIND_HOSTS, 'https://claude.ai', false],
            [setting_list::KIND_HOSTS, '*.claude.ai', false],
            [setting_list::KIND_HOSTS, 'localhost', false],
            [setting_list::KIND_ACTORS, 'copilot-cowork', true],
            [setting_list::KIND_ACTORS, 'Copilot Cowork', false],
            [setting_list::KIND_ACTORS, '', false],
            ['unknown', 'anything', false],
        ];
    }

    /**
     * Each kind of list accepts only well formed entries.
     *
     * @dataProvider entry_provider
     * @param string $kind Kind of list.
     * @param string $entry Entry.
     * @param bool $expected Whether it is valid.
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('entry_provider')]
    public function test_list_entries(string $kind, string $entry, bool $expected): void {
        $this->assertSame($expected, setting_list::is_valid_entry($kind, $entry));
    }

    /**
     * The factory values of the list settings are valid for their kind.
     */
    public function test_list_defaults_are_valid(): void {
        $kinds = [
            'allowedredirects' => setting_list::KIND_REDIRECTS,
            'cimdtrusteddomains' => setting_list::KIND_HOSTS,
            'unattendedactors' => setting_list::KIND_ACTORS,
        ];
        foreach ($kinds as $name => $kind) {
            $entries = setting_list::split(config::DEFAULTS[$name]);
            $this->assertNotEmpty($entries, $name);
            foreach ($entries as $entry) {
                $this->assertTrue(setting_list::is_valid_entry($kind, $entry), "$name: $entry");
            }
        }
    }

    /**
     * A list is stored one entry per line, without duplicates, and a bad entry stores nothing.
     */
    public function test_list_is_normalised_and_validated(): void {
        $this->resetAfterTest();
        $setting = new setting_list('excludedcourses', 'Name', 'Description', setting_list::KIND_IDS);
        $this->assertSame('', $setting->write_setting(" 5, 7\r\n7\n\n9 "));
        $this->assertSame("5\n7\n9", get_config('local_pluginiaconnect', 'excludedcourses'));
        $this->assertSame([5, 7, 9], config::ids('excludedcourses'));

        $this->assertNotSame('', $setting->write_setting("5\nabc"));
        $this->assertSame("5\n7\n9", get_config('local_pluginiaconnect', 'excludedcourses'));

        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame([], config::ids('excludedcourses'));
    }

    /**
     * The assistant that can work unattended cannot be removed from its list.
     */
    public function test_unattended_actor_cannot_be_removed(): void {
        $this->resetAfterTest();
        $setting = new setting_list('unattendedactors', 'Name', 'Description', setting_list::KIND_ACTORS, ['copilot-cowork']);

        $this->assertSame('', $setting->write_setting(''));
        $this->assertSame(['copilot-cowork'], config::lines('unattendedactors'));

        $this->assertSame('', $setting->write_setting('other-agent'));
        $this->assertEqualsCanonicalizing(['copilot-cowork', 'other-agent'], config::lines('unattendedactors'));
    }

    /**
     * The short name of a setting is taken from the full name the admin tree passes.
     */
    public function test_short_name(): void {
        $this->assertSame('enabled', callbacks::short_name('s_local_pluginiaconnect_enabled'));
        $this->assertSame('readonlymode', callbacks::short_name('s_local_pluginiaconnect_readonlymode'));
        $this->assertSame('other', callbacks::short_name('other'));
    }

    /**
     * Changing a switch records an event with the new value and clears the cached policy.
     */
    public function test_killswitch_change(): void {
        $this->resetAfterTest();
        $this->setAdminUser();
        $eligibility = \cache::make('local_pluginiaconnect', 'eligibility');
        $toolconfig = \cache::make('local_pluginiaconnect', 'toolconfig');
        $eligibility->set('u5', 'student');
        $toolconfig->set('matrix', 'x');
        set_config('enabled', 1, 'local_pluginiaconnect');

        $sink = $this->redirectEvents();
        callbacks::killswitch_changed('s_local_pluginiaconnect_enabled');
        callbacks::killswitch_changed('s_local_pluginiaconnect_maxpagesize');
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(settings_killswitch_changed::class, $event);
        $this->assertSame('enabled', $event->other['setting']);
        $this->assertSame(1, $event->other['value']);
        $this->assertFalse($eligibility->get('u5'));
        $this->assertFalse($toolconfig->get('matrix'));
    }

    /**
     * The short setting name, as the emergency CLI script passes it, records the event too.
     */
    public function test_killswitch_change_with_short_name(): void {
        $this->resetAfterTest();
        set_config('enabled', 0, 'local_pluginiaconnect');

        $sink = $this->redirectEvents();
        callbacks::killswitch_changed('enabled');
        $events = $sink->get_events();
        $sink->close();

        $this->assertCount(1, $events);
        $event = reset($events);
        $this->assertInstanceOf(settings_killswitch_changed::class, $event);
        $this->assertSame('enabled', $event->other['setting']);
        $this->assertSame(0, $event->other['value']);
    }

    /**
     * A policy change clears the cached eligibility and tool matrix.
     */
    public function test_policy_change_clears_caches(): void {
        $this->resetAfterTest();
        $eligibility = \cache::make('local_pluginiaconnect', 'eligibility');
        $eligibility->set('u9', 'teacher');
        callbacks::policy_changed();
        $this->assertFalse($eligibility->get('u9'));
    }

    /**
     * The settings page loads and declares every setting that has a factory default.
     */
    public function test_settings_page_declares_every_setting(): void {
        global $CFG;
        $this->resetAfterTest();
        $this->setAdminUser();
        require_once($CFG->libdir . '/adminlib.php');
        $root = admin_get_root(true, true);
        $page = $root->locate('local_pluginiaconnect');
        $this->assertInstanceOf(\admin_settingpage::class, $page);
        $declared = [];
        foreach ($page->settings as $setting) {
            if (!$setting instanceof \admin_setting_heading) {
                $declared[] = $setting->name;
            }
        }
        foreach (array_keys(config::DEFAULTS) as $name) {
            $this->assertContains($name, $declared, $name);
        }
        $this->assertNotNull($root->locate('local_pluginiaconnect_health'));
    }
}
