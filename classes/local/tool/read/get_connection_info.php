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

namespace local_pluginiaconnect\local\tool\read;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\mcp\protocol;
use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: get_connection_info.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class get_connection_info extends base_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'get_connection_info';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Get connection info',
            'Returns the name and address of the connected learning site, the name of the signed-in user, the profile '
                . 'and access level chosen for this connection, its scopes and its usage limits. Use it to find out '
                . 'which site and account the connection belongs to and whether it allows changes. It reads no course data.',
            [],
            [],
            [
                'type' => 'object',
                'properties' => [
                    'site_name' => ['type' => 'string'],
                    'site_url' => ['type' => 'string', 'format' => 'uri'],
                    'user_full_name' => ['type' => 'string'],
                    'profile' => ['type' => 'string', 'enum' => profile::all()],
                    'access_level' => ['type' => 'string', 'enum' => ['read', 'read_write']],
                    'scopes' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'assistant' => ['type' => ['string', 'null']],
                    'connection_expires_at' => ['type' => ['string', 'null'], 'format' => 'date-time'],
                    'connector_version' => ['type' => 'string'],
                    'limits' => [
                        'type' => 'object',
                        'properties' => [
                            'reads_per_minute' => ['type' => 'integer'],
                            'reads_per_day' => ['type' => 'integer'],
                            'writes_per_minute' => ['type' => 'integer'],
                            'writes_per_day' => ['type' => 'integer'],
                            'max_page_size' => ['type' => 'integer'],
                        ],
                    ],
                ],
            ]
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(profile::STUDENT, 'profile');
    }

    /**
     * The tool acts on the user's own connection.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    /**
     * Runs the tool.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $DB, $SITE, $CFG;

        $write = false;
        foreach ($call->grant->scopes as $name) {
            $write = $write || scope::is_write($name);
        }
        $expires = $DB->get_field('local_pluginiaconnect_grant', 'timeabsoluteexpiry', ['id' => $call->grant->grantid]);
        return new tool_result([
            'site_name' => format_string($SITE->fullname, true, ['context' => \context_system::instance()]),
            'site_url' => $CFG->wwwroot,
            'user_full_name' => fullname($call->user),
            'profile' => $call->grant->profile,
            'access_level' => $write ? 'read_write' : 'read',
            'scopes' => $call->grant->scopes,
            'assistant' => $call->grant->actor,
            'connection_expires_at' => self::iso($expires ? (int) $expires : null),
            'connector_version' => protocol::server_info()['version'],
            'limits' => [
                'reads_per_minute' => config::int('ratereadminute'),
                'reads_per_day' => config::int('ratereadday'),
                'writes_per_minute' => config::int('ratewriteminute'),
                'writes_per_day' => config::int('ratewriteday'),
                'max_page_size' => config::int('maxpagesize'),
            ],
        ], 1, null, 'grant', $call->grant->grantid);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return [
            'site_name', 'site_url', 'user_full_name', 'profile', 'access_level', 'scopes', 'assistant',
            'connection_expires_at', 'connector_version',
            'limits' => ['reads_per_minute', 'reads_per_day', 'writes_per_minute', 'writes_per_day', 'max_page_size'],
        ];
    }
}
