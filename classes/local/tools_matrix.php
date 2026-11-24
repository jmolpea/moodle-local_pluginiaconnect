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

namespace local_pluginiaconnect\local;

use local_pluginiaconnect\admin\callbacks;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\site_policy;
use local_pluginiaconnect\local\tool\tool_requirement;

/**
 * The tool × profile matrix and the approval mode of each write tool, as the administrator edits them.
 *
 * Storage is the one {@see site_policy} reads: settings "tool_<name>_<profile>" (0/1)
 * and "approval_<name>". The administrator can switch cells off and tighten an
 * approval mode; a cell below the minimum profile of the tool does not exist, and an
 * approval mode below the minimum of the catalogue is refused.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tools_matrix {
    /**
     * Current state of the matrix.
     *
     * @return array[] One row per tool: name, title, write, profile, scope, capabilities, minapproval,
     *                 approval, modes (allowed approval modes) and cells (profile => enabled, only applicable profiles).
     */
    public static function rows(): array {
        $rows = [];
        foreach (tool_registry::all() as $name => $class) {
            $requirement = $class::requirement();
            $cells = [];
            foreach (profile::all() as $profile) {
                if (profile::at_least($profile, $requirement->profile)) {
                    $cells[$profile] = site_policy::is_tool_enabled($name, $profile);
                }
            }
            $rows[] = [
                'name' => $name,
                'title' => $class::definition()->title,
                'write' => $requirement->write,
                'profile' => $requirement->profile,
                'scope' => $requirement->scope,
                'capabilities' => $requirement->capabilities,
                'minapproval' => $requirement->approval,
                'approval' => self::stored_approval($name, $requirement->approval),
                'modes' => self::allowed_modes($requirement->approval),
                'cells' => $cells,
            ];
        }
        return $rows;
    }

    /**
     * Approval modes an administrator may choose for a tool: its minimum and anything stricter.
     *
     * @param string $minimum Minimum mode fixed by the catalogue.
     * @return string[]
     */
    public static function allowed_modes(string $minimum): array {
        $floor = tool_requirement::APPROVAL_RANK[$minimum] ?? PHP_INT_MAX;
        $modes = [];
        foreach (tool_requirement::APPROVAL_RANK as $mode => $rank) {
            if ($rank >= $floor) {
                $modes[] = $mode;
            }
        }
        return $modes;
    }

    /**
     * Saves the matrix.
     *
     * Unknown tools, profiles below the minimum of a tool and approval modes below
     * the catalogue minimum are ignored: nothing a form sends can widen the catalogue.
     *
     * @param array $cells Tool name => [profile => bool].
     * @param array $approvals Tool name => approval mode, for write tools.
     * @return int Number of settings that changed.
     */
    public static function save(array $cells, array $approvals): int {
        $changed = 0;
        foreach (tool_registry::all() as $name => $class) {
            $requirement = $class::requirement();
            foreach (profile::all() as $profile) {
                if (!profile::at_least($profile, $requirement->profile)) {
                    continue;
                }
                $new = empty($cells[$name][$profile]) ? 0 : 1;
                if ((int) site_policy::is_tool_enabled($name, $profile) !== $new) {
                    set_config("tool_{$name}_{$profile}", $new, config::COMPONENT);
                    $changed++;
                }
            }
            if (!$requirement->write) {
                continue;
            }
            $mode = $approvals[$name] ?? null;
            if (
                is_string($mode) && in_array($mode, self::allowed_modes($requirement->approval), true)
                && $mode !== self::stored_approval($name, $requirement->approval)
            ) {
                set_config("approval_{$name}", $mode, config::COMPONENT);
                $changed++;
            }
        }
        if ($changed) {
            // Tool availability decides which scopes a consent can grant: drop every cached answer.
            callbacks::policy_changed();
        }
        return $changed;
    }

    /**
     * Approval mode chosen for a tool, never below the catalogue minimum.
     *
     * @param string $name Tool name.
     * @param string $minimum Catalogue minimum.
     * @return string
     */
    private static function stored_approval(string $name, string $minimum): string {
        $stored = get_config(config::COMPONENT, "approval_{$name}");
        if (!is_string($stored) || !isset(tool_requirement::APPROVAL_RANK[$stored])) {
            return $minimum;
        }
        return tool_requirement::stricter($minimum, $stored);
    }
}
