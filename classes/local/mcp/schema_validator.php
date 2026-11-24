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

namespace local_pluginiaconnect\local\mcp;

/**
 * Strict validator for tool arguments.
 *
 * It implements only the JSON Schema keywords the catalogue uses: type (object,
 * string, integer, number, boolean, array), properties, required,
 * additionalProperties false, minimum, maximum, minLength, maxLength, enum,
 * items, minItems, maxItems and uniqueItems. Unknown properties are always
 * rejected and no value is coerced: "5" is not an integer.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class schema_validator {
    /**
     * Validates the arguments of a tool call against the input schema of the tool.
     *
     * @param mixed $args Decoded "arguments" member (objects as associative arrays).
     * @param array $properties Property name => schema.
     * @param string[] $required Required property names.
     * @return string|null Null when valid, otherwise a message naming the first problem.
     */
    public static function validate($args, array $properties, array $required): ?string {
        if (!is_array($args) || ($args !== [] && array_is_list($args))) {
            return 'Arguments must be a JSON object.';
        }
        foreach ($args as $name => $value) {
            if (!is_string($name) || !array_key_exists($name, $properties)) {
                return "Unknown argument '" . self::label((string) $name) . "'.";
            }
        }
        foreach ($required as $name) {
            if (!array_key_exists($name, $args)) {
                return "'{$name}' is required.";
            }
        }
        foreach ($args as $name => $value) {
            $problem = self::check($value, $properties[$name], "'{$name}'");
            if ($problem !== null) {
                return $problem;
            }
        }
        return null;
    }

    /**
     * Checks one value against one schema.
     *
     * @param mixed $value Value.
     * @param array $schema Schema of the value.
     * @param string $label How the value is named in messages.
     * @return string|null
     */
    private static function check($value, array $schema, string $label): ?string {
        switch ($schema['type'] ?? '') {
            case 'integer':
                if (!is_int($value)) {
                    return "{$label} must be an integer.";
                }
                return self::range($value, $schema, $label);
            case 'number':
                if (!is_int($value) && !is_float($value)) {
                    return "{$label} must be a number.";
                }
                if (is_float($value) && !is_finite($value)) {
                    return "{$label} must be a finite number.";
                }
                return self::range($value, $schema, $label);
            case 'boolean':
                return is_bool($value) ? null : "{$label} must be true or false.";
            case 'string':
                if (!is_string($value)) {
                    return "{$label} must be a string.";
                }
                $length = \core_text::strlen($value);
                if (isset($schema['minLength']) && $length < $schema['minLength']) {
                    return "{$label} must have at least {$schema['minLength']} characters.";
                }
                if (isset($schema['maxLength']) && $length > $schema['maxLength']) {
                    return "{$label} must have at most {$schema['maxLength']} characters.";
                }
                if (isset($schema['enum']) && !in_array($value, $schema['enum'], true)) {
                    return "{$label} must be one of: " . implode(', ', $schema['enum']) . '.';
                }
                return null;
            case 'array':
                if (!is_array($value) || !array_is_list($value)) {
                    return "{$label} must be a list.";
                }
                if (isset($schema['minItems']) && count($value) < $schema['minItems']) {
                    return "{$label} must have at least {$schema['minItems']} items.";
                }
                if (isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
                    return "{$label} must have at most {$schema['maxItems']} items.";
                }
                foreach ($value as $item) {
                    $problem = self::check($item, $schema['items'] ?? [], "Each item of {$label}");
                    if ($problem !== null) {
                        return $problem;
                    }
                }
                if (!empty($schema['uniqueItems']) && count(array_unique($value, SORT_REGULAR)) !== count($value)) {
                    return "{$label} must not contain repeated items.";
                }
                return null;
            default:
                // A schema this validator does not understand never validates: fail closed.
                return "{$label} cannot be validated.";
        }
    }

    /**
     * Checks minimum and maximum.
     *
     * @param int|float $value Value.
     * @param array $schema Schema.
     * @param string $label Name in messages.
     * @return string|null
     */
    private static function range($value, array $schema, string $label): ?string {
        if (isset($schema['minimum']) && $value < $schema['minimum']) {
            return "{$label} must be at least {$schema['minimum']}.";
        }
        if (isset($schema['maximum']) && $value > $schema['maximum']) {
            return "{$label} must be at most {$schema['maximum']}.";
        }
        return null;
    }

    /**
     * Makes a client-supplied name safe to echo in a message.
     *
     * @param string $name Name sent by the client.
     * @return string
     */
    private static function label(string $name): string {
        return substr(preg_replace('/[^A-Za-z0-9_.-]/', '?', $name), 0, 40);
    }
}
