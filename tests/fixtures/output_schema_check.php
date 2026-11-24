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

/**
 * Checks what a tool really returned against the output schema of the catalogue.
 *
 * The gateway validates every answer of a site against that schema and refuses
 * the ones that do not match, so a schema stricter than the real output would
 * break a tool in gateway mode. The tool tests pass every envelope they obtain
 * through this class.
 *
 * It implements the part of JSON Schema the catalogue uses: type (also a list
 * of types), properties, required, additionalProperties false, items, enum,
 * pattern and the date-time format.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class local_pluginiaconnect_output_schema_check {
    /** @var string[] Keywords this class understands. A schema that uses any other is reported. */
    private const KEYWORDS = ['type', 'properties', 'required', 'additionalProperties', 'items', 'enum', 'pattern',
        'format', 'description'];

    /** @var int Envelopes checked since the counter was last read. */
    public static int $checked = 0;

    /**
     * Problems of an envelope against the output schema of its tool.
     *
     * @param string $toolname Tool name.
     * @param array $envelope Envelope as the pipeline returned it.
     * @return string[] One line per problem; empty when the envelope is valid.
     */
    public static function problems(string $toolname, array $envelope): array {
        $class = \local_pluginiaconnect\local\mcp\tool_registry::get($toolname);
        if ($class === null) {
            return [];
        }
        // The schema and the value are compared as they travel: encoded to JSON and decoded again,
        // so that an empty PHP array shows as the list it becomes on the wire.
        $flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;
        $schema = json_decode(json_encode($class::definition()->output_schema(), $flags));
        $value = json_decode(json_encode($envelope, $flags));
        $problems = [];
        self::check($value, $schema, 'structuredContent', $problems);
        self::$checked++;
        // To see which tools and states a run really checked: PCC_SCHEMA_SEEN=/tmp/seen.txt vendor/bin/phpunit ...
        $report = getenv('PCC_SCHEMA_SEEN');
        if ($report) {
            $state = (string) ($envelope['status'] ?? '?');
            $state .= $state === 'error' ? ':' . ($envelope['error']['code'] ?? '?') : '';
            file_put_contents($report, $toolname . ' ' . $state . ' ' . ($problems ? 'INVALID' : 'valid') . "\n", FILE_APPEND);
        }
        return $problems;
    }

    /**
     * Fails the running test when an envelope does not match the output schema of its tool.
     *
     * @param string $toolname Tool name.
     * @param array $envelope Envelope as the pipeline returned it.
     * @return void
     */
    public static function assert_valid(string $toolname, array $envelope): void {
        $problems = self::problems($toolname, $envelope);
        \PHPUnit\Framework\Assert::assertSame(
            [],
            $problems,
            "The result of {$toolname} does not match its output schema: " . implode('; ', $problems)
        );
    }

    /**
     * Problems of any value against any schema, for the tests of this class itself.
     *
     * @param mixed $value Decoded JSON value (objects as stdClass).
     * @param \stdClass $schema Decoded schema.
     * @return string[]
     */
    public static function check_value($value, \stdClass $schema): array {
        $problems = [];
        self::check($value, $schema, '$', $problems);
        return $problems;
    }

    /**
     * Checks one value against one schema.
     *
     * @param mixed $value Decoded JSON value.
     * @param mixed $schema Decoded schema.
     * @param string $path Where the value is, for the report.
     * @param string[] $problems Problems found so far.
     * @return void
     */
    private static function check($value, $schema, string $path, array &$problems): void {
        if (!$schema instanceof \stdClass) {
            $problems[] = "{$path}: the schema is not an object";
            return;
        }
        foreach (array_keys(get_object_vars($schema)) as $keyword) {
            if (!in_array($keyword, self::KEYWORDS, true)) {
                $problems[] = "{$path}: schema keyword '{$keyword}' is not checked by the tests";
            }
        }
        if (isset($schema->type)) {
            $types = is_array($schema->type) ? $schema->type : [$schema->type];
            $matches = false;
            foreach ($types as $type) {
                $matches = $matches || self::is_type($value, (string) $type);
            }
            if (!$matches) {
                $problems[] = "{$path}: expected " . implode('|', $types) . ', got ' . gettype($value);
                return;
            }
        }
        if (isset($schema->enum) && !in_array($value, $schema->enum, true)) {
            $problems[] = "{$path}: " . json_encode($value) . ' is not one of ' . json_encode($schema->enum);
        }
        if (is_string($value)) {
            if (isset($schema->pattern) && !preg_match('~' . str_replace('~', '\~', $schema->pattern) . '~u', $value)) {
                $problems[] = "{$path}: '{$value}' does not match {$schema->pattern}";
            }
            if (
                ($schema->format ?? '') === 'date-time'
                && !preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d+)?(Z|[+-]\d{2}:\d{2})$/', $value)
            ) {
                $problems[] = "{$path}: '{$value}' is not a date-time";
            }
            if (($schema->format ?? '') === 'uri' && !preg_match('~^[a-z][a-z0-9+.-]*:~i', $value)) {
                $problems[] = "{$path}: '{$value}' is not a URI";
            }
        }
        if ($value instanceof \stdClass) {
            $properties = isset($schema->properties) ? get_object_vars($schema->properties) : [];
            foreach ($schema->required ?? [] as $name) {
                if (!property_exists($value, $name)) {
                    $problems[] = "{$path}: required property '{$name}' is missing";
                }
            }
            foreach (get_object_vars($value) as $name => $child) {
                if (array_key_exists($name, $properties)) {
                    self::check($child, $properties[$name], "{$path}.{$name}", $problems);
                } else if (($schema->additionalProperties ?? true) === false) {
                    $problems[] = "{$path}: property '{$name}' is not declared";
                }
            }
        }
        if (is_array($value) && isset($schema->items)) {
            foreach ($value as $index => $item) {
                self::check($item, $schema->items, "{$path}[{$index}]", $problems);
            }
        }
    }

    /**
     * Whether a decoded JSON value has a JSON Schema type.
     *
     * @param mixed $value Decoded JSON value.
     * @param string $type JSON Schema type name.
     * @return bool
     */
    private static function is_type($value, string $type): bool {
        switch ($type) {
            case 'object':
                return $value instanceof \stdClass;
            case 'array':
                return is_array($value);
            case 'string':
                return is_string($value);
            case 'integer':
                return is_int($value) || (is_float($value) && floor($value) === $value);
            case 'number':
                return is_int($value) || is_float($value);
            case 'boolean':
                return is_bool($value);
            case 'null':
                return $value === null;
            default:
                return false;
        }
    }
}
