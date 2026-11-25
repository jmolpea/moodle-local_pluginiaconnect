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

namespace local_pluginiaconnect\extension;

use local_pluginiaconnect\local\mcp\tool_registry;

/**
 * Loads the tools that Pluginia plugins declare (amendment 2, section 10B).
 *
 * Only the components of a fixed list in this class are looked at; no other plugin can add a tool. For
 * each one that is installed, at or above its minimum version, the declarative file db/pluginiaconnect.php
 * is read (it only holds data) and every entry is checked against the contract and the permanent
 * prohibitions. An entry that fails is left out and its reason kept for the diagnostics page; it never
 * affects the base catalogue or the other entries. A site without the plugin lists exactly what it listed
 * before.
 *
 * The result is kept for the request, keyed by the installed version of each listed plugin.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class loader {
    /** @var int[] Versions of the extension contract this connector understands. */
    public const CONTRACTS = [1];

    /** @var string Format of the catalogue file of a component (pluginiaconnect/tools.json). */
    public const CATALOG_FORMAT = '1';

    /**
     * @var array The whitelist: component => [reserved infix, minimum plugin version]. A null minimum means the
     *      batch of that plugin is not open yet: its infix is reserved, but none of its tools is accepted.
     */
    private const COMPONENTS = [
        'block_openaiagent' => ['smarttutor', null],
        'local_forumia' => ['forumia', null],
        'local_rsmaxtutor' => ['aitutor', null],
        'mod_ailivesession' => ['ailivesession', null],
        'local_pluginia_analytics' => ['analytics', 2026112500],
        'mod_peerreviewstudio' => ['peerreviewstudio', null],
        'mod_airoleplay' => ['airoleplay', null],
        'mod_aiviva' => ['aiviva', null],
        'mod_decisionarena' => ['decisionarena', null],
        'block_aicourserecommender' => ['aicourserecommender', null],
        'local_coursehead' => ['coursehead', null],
    ];

    /** @var array|null Result of the last load: ['key' => ..., 'tools' => ..., 'report' => ...]. */
    private static ?array $loaded = null;

    /** @var array Components added by unit tests: component => [infix, minimum, declaration file]. */
    private static array $testcomponents = [];

    /**
     * The whitelist, with the components added by unit tests.
     *
     * @return array Component => [infix, minimum version or null].
     */
    public static function components(): array {
        $components = self::COMPONENTS;
        foreach (self::$testcomponents as $component => $entry) {
            $components[$component] = [$entry[0], $entry[1]];
        }
        return $components;
    }

    /**
     * Reserved infixes, one per whitelisted component.
     *
     * @return string[] Component => infix.
     */
    public static function infixes(): array {
        return array_map(static fn(array $entry): string => $entry[0], self::components());
    }

    /**
     * Accepted extension tools.
     *
     * @return string[] Tool name => class.
     */
    public static function tools(): array {
        return self::load()['tools'];
    }

    /**
     * Component that declared an accepted tool, or null.
     *
     * @param string $name Tool name.
     * @return string|null
     */
    public static function component_of(string $name): ?string {
        return self::load()['owners'][$name] ?? null;
    }

    /**
     * What was found for every whitelisted component, for the diagnostics page.
     *
     * @return array Component => ['status' => string, 'version' => int|null, 'catalog' => int|null,
     *               'tools' => string[], 'rejected' => [name => reason]].
     */
    public static function report(): array {
        return self::load()['report'];
    }

    /**
     * The catalogue of one component, as written to its pluginiaconnect/tools.json.
     *
     * @param string $component Component name.
     * @return array|null Null when the component declares nothing that was accepted.
     */
    public static function catalog(string $component): ?array {
        $report = self::report()[$component] ?? null;
        if ($report === null || $report['status'] !== 'loaded') {
            return null;
        }
        $tools = [];
        foreach ($report['tools'] as $name) {
            $tools[] = tool_registry::catalog_entry(self::tools()[$name]);
        }
        return [
            'format' => self::CATALOG_FORMAT,
            'component' => $component,
            'catalog' => $report['catalog'],
            'tools' => $tools,
        ];
    }

    /**
     * The catalogue of one component as the exact text of its file.
     *
     * @param string $component Component name.
     * @return string|null
     */
    public static function catalog_json(string $component): ?string {
        $catalog = self::catalog($component);
        return $catalog === null ? null
            : json_encode($catalog, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    }

    /**
     * Adds components for unit tests of the loader. Refused outside PHPUnit.
     *
     * @param array $components Component => [infix, minimum version, absolute path of its declaration file].
     * @return void
     */
    public static function set_test_components(array $components): void {
        if (!defined('PHPUNIT_TEST') || !PHPUNIT_TEST) {
            throw new \coding_exception('Test components can only be added by unit tests.');
        }
        self::$testcomponents = $components;
        self::$loaded = null;
    }

    /**
     * Forgets the result of the last load.
     *
     * @return void
     */
    public static function reset(): void {
        self::$loaded = null;
    }

    /**
     * Loads every whitelisted component, once per request and set of installed versions.
     *
     * @return array
     */
    private static function load(): array {
        $versions = [];
        foreach (array_keys(self::components()) as $component) {
            $versions[$component] = self::installed_version($component);
        }
        $key = sha1(json_encode([$versions, array_keys(self::$testcomponents)]));
        if (self::$loaded !== null && self::$loaded['key'] === $key) {
            return self::$loaded;
        }
        $base = tool_registry::base();
        $tools = [];
        $owners = [];
        $report = [];
        foreach (self::components() as $component => [$infix, $minimum]) {
            $entry = ['status' => 'not_installed', 'version' => $versions[$component], 'catalog' => null, 'tools' => [],
                'rejected' => []];
            if ($versions[$component] !== null) {
                $entry = self::load_component($component, $infix, $minimum, $versions[$component], $base + $tools, $entry);
                foreach ($entry['accepted'] ?? [] as $name => $class) {
                    $tools[$name] = $class;
                    $owners[$name] = $component;
                }
                unset($entry['accepted']);
            }
            $report[$component] = $entry;
        }
        self::$loaded = ['key' => $key, 'tools' => $tools, 'owners' => $owners, 'report' => $report];
        return self::$loaded;
    }

    /**
     * Installed version of a component, or null when it is not installed.
     *
     * @param string $component Component name.
     * @return int|null
     */
    private static function installed_version(string $component): ?int {
        if (isset(self::$testcomponents[$component])) {
            return (int) (self::$testcomponents[$component][3] ?? 1);
        }
        if (\core_component::get_component_directory($component) === null) {
            return null;
        }
        $version = get_config($component, 'version');
        return $version === false || $version === null ? null : (int) $version;
    }

    /**
     * Path of the declaration file of a component.
     *
     * @param string $component Component name.
     * @return string|null
     */
    private static function declaration_file(string $component): ?string {
        if (isset(self::$testcomponents[$component])) {
            return self::$testcomponents[$component][2];
        }
        $dir = \core_component::get_component_directory($component);
        return $dir === null ? null : $dir . '/db/pluginiaconnect.php';
    }

    /**
     * Validates the declaration of one installed component.
     *
     * @param string $component Component name.
     * @param string $infix Reserved infix.
     * @param int|null $minimum Minimum version, null when its batch is not open.
     * @param int $version Installed version.
     * @param array $taken Names already in use: base catalogue and earlier extensions.
     * @param array $entry Report entry to complete.
     * @return array Report entry, with 'accepted' => [name => class].
     */
    private static function load_component(
        string $component,
        string $infix,
        ?int $minimum,
        int $version,
        array $taken,
        array $entry
    ): array {
        $file = self::declaration_file($component);
        if ($file === null || !is_readable($file)) {
            $entry['status'] = 'no_declaration';
            return $entry;
        }
        if ($minimum === null) {
            $entry['status'] = 'not_accepted_yet';
            return $entry;
        }
        if ($version < $minimum) {
            $entry['status'] = 'version_too_old';
            return $entry;
        }
        try {
            $declaration = self::read_declaration($file);
        } catch (\Throwable $e) {
            $declaration = null;
        }
        if (
            !is_array($declaration) || !in_array($declaration['contract'] ?? null, self::CONTRACTS, true)
            || !is_int($declaration['catalog'] ?? null) || !is_array($declaration['tools'] ?? null)
        ) {
            $entry['status'] = 'invalid_declaration';
            return $entry;
        }
        $entry['status'] = 'loaded';
        $entry['catalog'] = $declaration['catalog'];
        $accepted = [];
        foreach ($declaration['tools'] as $name => $class) {
            $name = (string) $name;
            try {
                $problems = self::check_entry($component, $infix, $name, $class, $taken + $accepted);
            } catch (\Throwable $e) {
                $problems = ['the definition could not be read'];
            }
            if ($problems) {
                // Kept for the diagnostics page of the connector, which lists every refusal with its reason.
                $entry['rejected'][substr($name, 0, 64)] = implode('; ', $problems);
                continue;
            }
            $accepted[$name] = ltrim($class, '\\');
            $entry['tools'][] = $name;
        }
        $entry['accepted'] = $accepted;
        return $entry;
    }

    /**
     * Reads a declaration file in its own scope. The file only sets $extension.
     *
     * @param string $file Absolute path.
     * @return mixed
     */
    private static function read_declaration(string $file) {
        $extension = null;
        require($file);
        return $extension;
    }

    /**
     * Reasons why one declared tool is refused.
     *
     * @param string $component Component that declares it.
     * @param string $infix Reserved infix of the component.
     * @param string $name Declared name.
     * @param mixed $class Declared class.
     * @param array $taken Names already in use.
     * @return string[] Empty when the tool is accepted.
     */
    public static function check_entry(string $component, string $infix, string $name, $class, array $taken): array {
        $problems = rules::name_problems($name);
        if (!rules::has_segment($name, $infix)) {
            $problems[] = "the name does not carry the reserved infix '{$infix}'";
        }
        foreach (self::infixes() as $owner => $other) {
            if ($owner !== $component && rules::has_segment($name, $other)) {
                $problems[] = "the name carries the infix of {$owner}";
            }
        }
        if (isset($taken[$name])) {
            $problems[] = 'the name is already in use';
        }
        if (!is_string($class) || !str_starts_with(ltrim($class, '\\'), $component . '\\pluginiaconnect\\')) {
            $problems[] = "the class is not in the namespace {$component}\\pluginiaconnect";
            return $problems;
        }
        $class = ltrim($class, '\\');
        if (!class_exists($class)) {
            $problems[] = 'the class does not exist';
            return $problems;
        }
        $isread = is_subclass_of($class, read_tool::class);
        $iswrite = is_subclass_of($class, write_tool::class);
        if (!$isread && !$iswrite) {
            $problems[] = 'the class does not extend a base class of the extension point';
            return $problems;
        }
        if ($class::name() !== $name) {
            $problems[] = 'name() does not match the declared name';
        }
        $requirement = $class::requirement();
        $problems = array_merge($problems, rules::requirement_problems($requirement, $isread));
        $definition = tool_registry::describe($class);
        $problems = array_merge($problems, rules::schema_problems($definition, true, $requirement->write));
        if (($definition['annotations']['readOnlyHint'] ?? null) === $requirement->write) {
            $problems[] = 'readOnlyHint does not match the kind of tool';
        }
        return array_values(array_unique($problems));
    }
}
