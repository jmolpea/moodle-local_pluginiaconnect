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

namespace local_pluginiaconnect\local\policy;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\tool\tool_requirement;

/**
 * What the site administrator allows, independently of roles.
 *
 * Settings are read on every call (Moodle already caches plugin settings), so a
 * tool that the administrator switches off stops working immediately.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class site_policy {
    /** @var string Assistant identifier of Microsoft 365 Copilot Cowork. */
    public const ACTOR_COWORK = 'copilot-cowork';

    /**
     * Master switch of the connector.
     *
     * @return bool
     */
    public static function is_enabled(): bool {
        return config::bool('enabled');
    }

    /**
     * Whether the connection mode a client belongs to is switched on.
     *
     * @param string $clienttype Client type: gateway, cimd, dcr or manual.
     * @return bool
     */
    public static function mode_active_for(string $clienttype): bool {
        return $clienttype === 'gateway' ? config::bool('gatewayenabled') : config::bool('directmode');
    }

    /**
     * Cell of the tool × profile matrix. Never saved means on for read tools and off for write tools.
     *
     * @param string $tool Tool name.
     * @param string $profile Profile of the token.
     * @return bool
     */
    public static function is_tool_enabled(string $tool, string $profile): bool {
        $class = tool_registry::get($tool);
        if ($class === null || !profile::is_valid($profile)) {
            return false;
        }
        $value = get_config(config::COMPONENT, "tool_{$tool}_{$profile}");
        if ($value === false || $value === null || $value === '') {
            return !$class::requirement()->write;
        }
        return !empty($value);
    }

    /**
     * Whether the site lets an assistant use a tool of the given kind.
     *
     * @param string|null $actorid Assistant identifier declared by the gateway.
     * @param bool $write Whether the tool changes anything.
     * @return bool
     */
    public static function actor_allows(?string $actorid, bool $write): bool {
        if ($actorid !== self::ACTOR_COWORK) {
            return true;
        }
        $policy = config::string('actorpolicy_copilotcowork');
        if ($policy === 'blocked') {
            return false;
        }
        return !($write && $policy === 'readonly');
    }

    /**
     * Tool classes a connection may use: catalogue, cut down by site switches,
     * profile, scopes, the tool matrix and the assistant policy.
     *
     * @param string $profile Profile of the token.
     * @param string[] $scopes Scopes of the token.
     * @param string|null $actorid Assistant identifier declared by the gateway.
     * @return string[] Tool name => class name.
     */
    public static function usable_tools(string $profile, array $scopes, ?string $actorid = null): array {
        $usable = [];
        foreach (tool_registry::all() as $name => $class) {
            $requirement = $class::requirement();
            if (
                !profile::at_least($profile, $requirement->profile)
                || !in_array($requirement->scope, $scopes, true)
                || !self::is_tool_enabled($name, $profile)
                || !self::actor_allows($actorid, $requirement->write)
            ) {
                continue;
            }
            if ($requirement->write && config::bool('readonlymode')) {
                continue;
            }
            $usable[$name] = $class;
        }
        return $usable;
    }

    /**
     * Scopes that have at least one usable tool for a profile.
     *
     * @param string $profile Profile.
     * @return string[]
     */
    public static function scopes_with_active_tools(string $profile): array {
        $scopes = [];
        foreach (self::usable_tools($profile, scope::all()) as $class) {
            $scopes[$class::requirement()->scope] = true;
        }
        return array_keys($scopes);
    }

    /**
     * Titles of the tools usable with a profile and a set of scopes, for the consent page.
     *
     * @param string $profile Profile.
     * @param string[] $scopes Scopes.
     * @return string[]
     */
    public static function describe_tools(string $profile, array $scopes): array {
        $titles = [];
        foreach (self::usable_tools($profile, $scopes) as $class) {
            $titles[] = $class::definition()->title;
        }
        return $titles;
    }

    /**
     * Effective approval mode of a write tool: the strictest of the catalogue
     * minimum, the administrator's choice and the floor of the assistant.
     *
     * @param string $tool Tool name.
     * @param string|null $actorid Assistant identifier declared by the gateway.
     * @return string none, confirm or approval.
     */
    public static function approval_mode(string $tool, ?string $actorid): string {
        $class = tool_registry::get($tool);
        if ($class === null) {
            return tool_requirement::APPROVAL_APPROVAL;
        }
        $requirement = $class::requirement();
        $mode = $requirement->approval;
        if ($requirement->write && $mode === tool_requirement::APPROVAL_NONE) {
            // A write tool never runs on its first call, even if its class forgot to say how it is confirmed.
            $mode = tool_requirement::APPROVAL_CONFIRM;
        }
        $setting = get_config(config::COMPONENT, "approval_{$tool}");
        if (is_string($setting) && $setting !== '') {
            $mode = tool_requirement::stricter($mode, $setting);
        }
        if ($actorid !== null && in_array($actorid, self::unattended_actors(), true)) {
            $mode = tool_requirement::APPROVAL_APPROVAL;
        }
        return $mode;
    }

    /**
     * Assistants that may run without a person present. Copilot Cowork cannot be removed.
     *
     * @return string[]
     */
    public static function unattended_actors(): array {
        return array_values(array_unique(array_merge([self::ACTOR_COWORK], config::lines('unattendedactors'))));
    }
}
