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

namespace local_pluginiaconnect\local\tool;

use local_pluginiaconnect\local\policy\profile;

/**
 * Static description of a tool as the assistant sees it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_definition {
    /**
     * @var string Pattern every link in a result must match: an https address or, for a development
     *      site, an http address on localhost (the only http address the plugin runs on). It rules out
     *      other schemes, such as "javascript:" or "data:". The gateway checks on top of it that the
     *      link belongs to the site of the connection.
     */
    public const URL_PATTERN = '^(https://|http://localhost(:[0-9]+)?(/|$))';

    /** @var string Tool name. */
    public readonly string $name;
    /** @var string Human readable title. */
    public readonly string $title;
    /** @var string What the tool returns or changes, when to use it, limits and effects. */
    public readonly string $description;
    /** @var array Properties of the input schema (JSON Schema, property name => schema). */
    public readonly array $properties;
    /** @var string[] Names of the required input properties. */
    public readonly array $required;
    /** @var array JSON Schema of the "data" member of a successful result. */
    public readonly array $dataschema;
    /** @var bool Whether the tool only reads. */
    public readonly bool $readonly;
    /** @var bool Whether the tool deletes, overwrites, cancels, removes access or sends something irrevocable. */
    public readonly bool $destructive;
    /** @var bool Whether repeating the call has no additional effect. */
    public readonly bool $idempotent;

    /**
     * Constructor.
     *
     * @param string $name Tool name.
     * @param string $title Title.
     * @param string $description Description.
     * @param array $properties Input properties.
     * @param string[] $required Required input properties.
     * @param array $dataschema Schema of the result data.
     * @param bool $readonly Read-only tool.
     * @param bool $destructive Destructive tool.
     * @param bool $idempotent Idempotent tool.
     */
    public function __construct(
        string $name,
        string $title,
        string $description,
        array $properties,
        array $required,
        array $dataschema,
        bool $readonly = true,
        bool $destructive = false,
        bool $idempotent = true
    ) {
        $this->name = $name;
        $this->title = $title;
        $this->description = $description;
        $this->properties = $properties;
        $this->required = $required;
        $this->dataschema = $dataschema;
        $this->readonly = $readonly;
        $this->destructive = $destructive;
        $this->idempotent = $idempotent;
    }

    /**
     * Input schema: always an object that rejects unknown properties.
     *
     * @return array
     */
    public function input_schema(): array {
        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'properties' => $this->properties ?: new \stdClass(),
        ];
        if ($this->required) {
            $schema['required'] = $this->required;
        }
        return $schema;
    }

    /**
     * Output schema of structuredContent. It covers successful results and tool errors.
     *
     * @return array
     */
    public function output_schema(): array {
        // A read tool answers 'ok' or 'error'; a tool that changes data may also answer that the change
        // waits for a confirmation token or for the user's approval on the site.
        $statuses = $this->readonly ? ['ok', 'error'] : ['ok', 'error', 'confirmation_required', 'approval_pending'];
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['status', 'meta'],
            'properties' => [
                'status' => ['type' => 'string', 'enum' => $statuses],
                'data' => self::closed($this->dataschema),
                'error' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['code', 'message'],
                    'properties' => [
                        'code' => ['type' => 'string', 'enum' => tool_exception::CODES],
                        'message' => ['type' => 'string'],
                        'retry_after' => ['type' => 'integer'],
                    ],
                ],
                'meta' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'required' => ['request_id'],
                    'properties' => [
                        'site' => ['type' => 'string'],
                        'profile' => ['type' => 'string', 'enum' => profile::all()],
                        'truncated' => ['type' => 'boolean'],
                        'next_cursor' => ['type' => ['string', 'null']],
                        'request_id' => ['type' => 'string'],
                    ],
                ],
            ],
        ];
    }

    /**
     * A data schema with every object closed and every link constrained.
     *
     * Each object in it gets "additionalProperties": false, so a result can only carry the fields
     * the catalogue declares, and each property named "url" or ending in "_url" gets
     * {@see self::URL_PATTERN}. Tool definitions are written without them and closed here, in one
     * place, so that no tool can forget.
     *
     * @param array $schema JSON Schema of a value.
     * @param string|null $name Name of the property the schema belongs to, if any.
     * @return array
     */
    private static function closed(array $schema, ?string $name = null): array {
        $types = (array) ($schema['type'] ?? []);
        if (in_array('object', $types, true)) {
            $schema += ['additionalProperties' => false];
            foreach ($schema['properties'] ?? [] as $property => $child) {
                $schema['properties'][$property] = self::closed($child, (string) $property);
            }
            if (empty($schema['properties'])) {
                // An empty PHP array would be written as a JSON list, which is not a valid "properties".
                $schema['properties'] = new \stdClass();
            }
        }
        if (in_array('array', $types, true) && isset($schema['items']) && is_array($schema['items'])) {
            $schema['items'] = self::closed($schema['items']);
        }
        if (
            in_array('string', $types, true) && $name !== null && preg_match('/(^|_)url$/', $name)
            && !isset($schema['pattern'])
        ) {
            $schema['pattern'] = self::URL_PATTERN;
        }
        return $schema;
    }

    /**
     * Tool definition in the format of an MCP tools/list entry.
     *
     * @param string $scope OAuth scope the tool requires.
     * @return array
     */
    public function to_mcp(string $scope): array {
        $security = [['type' => 'oauth2', 'scopes' => [$scope]]];
        return [
            'name' => $this->name,
            'title' => $this->title,
            'description' => $this->description,
            'inputSchema' => $this->input_schema(),
            'outputSchema' => $this->output_schema(),
            'annotations' => [
                'title' => $this->title,
                'readOnlyHint' => $this->readonly,
                'destructiveHint' => $this->destructive,
                'idempotentHint' => $this->idempotent,
                'openWorldHint' => false,
            ],
            // OpenAI reads the security schemes at the top level in some clients and in _meta in others.
            'securitySchemes' => $security,
            '_meta' => ['securitySchemes' => $security],
        ];
    }
}
