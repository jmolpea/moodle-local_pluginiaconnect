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
use local_pluginiaconnect\local\policy\eligibility;

/**
 * Reactions to changes of the plugin settings.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class callbacks {
    /** @var string[] Settings that switch the connector, or a part of it, on and off. */
    public const KILLSWITCHES = ['enabled', 'readonlymode', 'directmode', 'gatewayenabled'];

    /**
     * A setting that decides who is eligible, or which tools exist, was changed:
     * forget every cached answer so that the change applies on the next call.
     *
     * @return void
     */
    public static function policy_changed(): void {
        eligibility::invalidate_all();
        \cache::make(config::COMPONENT, 'toolconfig')->purge();
    }

    /**
     * One of the switches was changed: record it and refresh the caches.
     *
     * @param string $fullname Full name of the admin setting, as passed by the admin tree.
     * @return void
     */
    public static function killswitch_changed(string $fullname): void {
        self::policy_changed();
        $name = self::short_name($fullname);
        if (!in_array($name, self::KILLSWITCHES, true)) {
            return;
        }
        settings_killswitch_changed::create([
            'context' => \context_system::instance(),
            'other' => ['setting' => $name, 'value' => (int) config::bool($name)],
        ])->trigger();
    }

    /**
     * Short setting name from the full name used by the admin tree.
     *
     * @param string $fullname For example "s_local_pluginiaconnect_enabled".
     * @return string For example "enabled".
     */
    public static function short_name(string $fullname): string {
        $prefix = 's_' . config::COMPONENT . '_';
        return str_starts_with($fullname, $prefix) ? substr($fullname, strlen($prefix)) : $fullname;
    }
}
