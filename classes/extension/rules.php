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

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\tool_requirement;

/**
 * The permanent prohibitions of the catalogue (amendment 2, D24, D26, D28 and D29), as checks.
 *
 * The extension loader refuses any extension tool that fails one of them, and the unit tests run the
 * same checks over the base catalogue. A rule here is never relaxed without an amendment approved by
 * the product owner.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class rules {
    /** @var string Pattern of every tool name, the one the gateway accepts. */
    public const NAME_PATTERN = '/^[a-z][a-z0-9_]{0,63}$/';

    /** @var string[] Verbs no tool name may start with: removals, resets, code, files, installation. */
    public const FORBIDDEN_VERBS = ['delete', 'remove', 'purge', 'reset', 'restore', 'backup', 'import', 'export', 'upload',
        'run', 'execute', 'install', 'uninstall', 'unenrol', 'unenroll', 'erase', 'destroy', 'drop', 'wipe', 'impersonate',
        'loginas'];

    /**
     * @var string[] Words that may not appear as a segment of a property name of an extension schema: the sensitive
     *      fields of the Pluginia plugins (keys, licences, prompts, provider, model, cost, injectable code, the
     *      accounts the AI of a plugin acts with, and where personal data is sent).
     */
    public const FORBIDDEN_SEGMENTS = ['prompt', 'prompts', 'apikey', 'secret', 'secrets', 'license', 'licence', 'key',
        'model', 'models', 'provider', 'endpoint', 'budget', 'cost', 'costs', 'temperature', 'tokens', 'css', 'js', 'scss',
        'javascript', 'html', 'template', 'disclaimer', 'recipients', 'emailto', 'emailcc', 'bot', 'botuserid', 'tutoruserid',
        'defaultbot', 'defaulttutor', 'password', 'token', 'transcript', 'transcription', 'salt', 'orgid'];

    /** @var string[] Whole property names refused besides the segments above. */
    public const FORBIDDEN_PROPERTIES = ['bot_userid', 'tutor_userid', 'grading_mode', 'publish_directly',
        'publish_resubmissions', 'mask_learner_names', 'suppression_threshold', 'customcss', 'customjs', 'allowjs',
        'excludedpaths', 'storeconversations', 'learnercontext', 'supportincludetranscript', 'scheduled_export_recipients',
        'ai_rate_limit'];

    /** @var string[] Input properties that would name somebody to act as (D28). */
    public const IDENTITY_PROPERTIES = ['as_user', 'as_user_id', 'on_behalf_of', 'author', 'author_id', 'sender', 'sender_id',
        'from_user', 'from_user_id', 'grader', 'grader_id', 'impersonate', 'login_as', 'actor', 'actor_id', 'owner_id',
        'posted_by', 'created_by', 'modified_by'];

    /** @var string Pattern of the same rule, for names not in the list. */
    public const IDENTITY_PATTERN = '/(^|_)(as|from|by|behalf|author|sender|grader|impersonat|actor|owner)(_|$)/';

    /** @var string[] Segments of input properties for files, code or addresses. */
    public const INPUT_SEGMENTS = ['file', 'files', 'attachment', 'attachments', 'sql', 'php', 'command', 'html', 'script',
        'url', 'uri', 'callback', 'password', 'token', 'secret'];

    /** @var string[] Control properties every write tool accepts; the only strings a write extension may take. */
    public const CONTROL_PROPERTIES = ['confirm_token', 'idempotency_key'];

    /**
     * Reasons why a tool name breaks the rules, for any tool.
     *
     * @param string $name Tool name.
     * @return string[] Empty when the name is acceptable.
     */
    public static function name_problems(string $name): array {
        $problems = [];
        if (!preg_match(self::NAME_PATTERN, $name)) {
            $problems[] = 'the name does not match ' . self::NAME_PATTERN;
        }
        $first = explode('_', $name)[0];
        if (in_array($first, self::FORBIDDEN_VERBS, true)) {
            $problems[] = "the name starts with the forbidden verb '{$first}'";
        }
        return $problems;
    }

    /**
     * Reasons why the schemas of a tool break the rules on properties (D26, D28, D29).
     *
     * @param array $definition MCP definition of the tool.
     * @param bool $extension Whether the tool is an extension, which also may not use the sensitive names.
     * @param bool $write Whether the tool changes something.
     * @return string[] Empty when the schemas are acceptable.
     */
    public static function schema_problems(array $definition, bool $extension, bool $write): array {
        $problems = [];
        $input = $definition['inputSchema']['properties'] ?? [];
        foreach (is_array($input) ? $input : [] as $property => $schema) {
            $property = (string) $property;
            // D28: no tool takes somebody to act as; for a write, not even a name that looks like one.
            if (
                in_array($property, self::IDENTITY_PROPERTIES, true)
                || ($write && preg_match(self::IDENTITY_PATTERN, $property))
            ) {
                $problems[] = "input '{$property}' names somebody to act as";
            }
            if (in_array($property, self::CONTROL_PROPERTIES, true)) {
                continue;
            }
            foreach (explode('_', $property) as $segment) {
                if (in_array($segment, self::INPUT_SEGMENTS, true)) {
                    $problems[] = "input '{$property}' takes a file, code, an address or a credential";
                    break;
                }
            }
            if (self::has_key($schema, 'contentEncoding') || self::has_format($schema, 'uri')) {
                $problems[] = "input '{$property}' takes a file or an address";
            }
            if ($extension && $write && !self::is_closed_value($schema)) {
                $problems[] = "input '{$property}' of a write extension is not an enumeration, a boolean, a bounded "
                    . 'integer or a date';
            }
        }
        if ($extension) {
            foreach (['inputSchema', 'outputSchema'] as $part) {
                foreach (self::property_names($definition[$part] ?? []) as $property) {
                    // The control arguments and the pending answer of every write tool are the connector's own.
                    if (!in_array($property, self::CONTROL_PROPERTIES, true) && self::is_sensitive_name($property)) {
                        $problems[] = "{$part} has the sensitive property '{$property}'";
                    }
                }
            }
        }
        return array_values(array_unique($problems));
    }

    /**
     * Reasons why the requirements of an extension tool break the rules.
     *
     * @param tool_requirement $requirement Requirements declared by the tool.
     * @param bool $readclass Whether the class extends the read base class.
     * @return string[]
     */
    public static function requirement_problems(tool_requirement $requirement, bool $readclass): array {
        $problems = [];
        $expected = $requirement->write ? 'extensions.write' : 'extensions.read';
        if ($requirement->scope !== $expected) {
            $problems[] = "the scope must be '{$expected}'";
        }
        if ($readclass === $requirement->write) {
            $problems[] = 'the base class does not match the kind of tool';
        }
        if (!profile::is_valid($requirement->profile) || !profile::at_least($requirement->profile, profile::TEACHER)) {
            $problems[] = 'the minimum profile must be teacher or above';
        }
        $rank = tool_requirement::APPROVAL_RANK[$requirement->approval] ?? null;
        $confirm = tool_requirement::APPROVAL_RANK[tool_requirement::APPROVAL_CONFIRM];
        if ($requirement->write && ($rank === null || $rank < $confirm)) {
            $problems[] = 'a write tool needs confirmation or approval';
        }
        foreach ($requirement->capabilities as $capability) {
            if (!is_string($capability) || !preg_match('~^[a-z0-9_]+/[a-z0-9_]+:[a-z0-9_]+$~', $capability)) {
                $problems[] = 'a capability is not a capability name';
            }
        }
        return $problems;
    }

    /**
     * Whether a property name is one of the sensitive fields an extension may never read or write.
     *
     * @param string $property Property name.
     * @return bool
     */
    public static function is_sensitive_name(string $property): bool {
        $lower = strtolower($property);
        if (in_array($lower, self::FORBIDDEN_PROPERTIES, true)) {
            return true;
        }
        foreach (['prompt', 'apikey', 'secret', 'licen', 'password'] as $fragment) {
            if (str_contains($lower, $fragment)) {
                return true;
            }
        }
        foreach (explode('_', $lower) as $segment) {
            if (in_array($segment, self::FORBIDDEN_SEGMENTS, true)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a reserved infix appears as a whole segment of a name.
     *
     * @param string $name Tool name.
     * @param string $infix Reserved infix.
     * @return bool
     */
    public static function has_segment(string $name, string $infix): bool {
        return in_array($infix, explode('_', $name), true);
    }

    /**
     * Every property name of a schema, at any depth.
     *
     * @param mixed $schema JSON Schema.
     * @return string[]
     */
    public static function property_names($schema): array {
        $names = [];
        $pending = [$schema];
        while ($pending) {
            $node = array_pop($pending);
            if (!is_array($node)) {
                continue;
            }
            if (isset($node['properties']) && is_array($node['properties'])) {
                foreach ($node['properties'] as $name => $child) {
                    $names[] = (string) $name;
                    $pending[] = $child;
                }
            }
            if (isset($node['items'])) {
                $pending[] = $node['items'];
            }
        }
        return array_values(array_unique($names));
    }

    /**
     * Whether an input of a write extension only takes closed values.
     *
     * @param mixed $schema Schema of the property.
     * @return bool
     */
    private static function is_closed_value($schema): bool {
        if (!is_array($schema)) {
            return false;
        }
        $type = $schema['type'] ?? null;
        if (isset($schema['enum']) && is_array($schema['enum'])) {
            return true;
        }
        if ($type === 'boolean') {
            return true;
        }
        if ($type === 'integer') {
            return isset($schema['minimum'], $schema['maximum']);
        }
        if ($type === 'string') {
            return ($schema['format'] ?? '') === 'date-time' && isset($schema['maxLength']);
        }
        if ($type === 'array') {
            return isset($schema['maxItems']) && self::is_closed_value($schema['items'] ?? null);
        }
        return false;
    }

    /**
     * Whether a key appears anywhere in a schema.
     *
     * @param mixed $schema Schema.
     * @param string $key Key.
     * @return bool
     */
    private static function has_key($schema, string $key): bool {
        if (!is_array($schema)) {
            return false;
        }
        if (array_key_exists($key, $schema)) {
            return true;
        }
        foreach ($schema as $child) {
            if (self::has_key($child, $key)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether a format appears anywhere in a schema.
     *
     * @param mixed $schema Schema.
     * @param string $format Format.
     * @return bool
     */
    private static function has_format($schema, string $format): bool {
        if (!is_array($schema)) {
            return false;
        }
        if (($schema['format'] ?? null) === $format) {
            return true;
        }
        foreach ($schema as $child) {
            if (self::has_format($child, $format)) {
                return true;
            }
        }
        return false;
    }
}
