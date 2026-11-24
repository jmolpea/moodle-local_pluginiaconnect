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

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Tool: find_users.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class find_users extends base_tool {
    /** @var int Most users returned. There is no paging: a broad search must be narrowed. */
    private const MAX_RESULTS = 20;

    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'find_users';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Find users',
            'Returns up to 20 user accounts of the site whose name matches a text of at least 3 characters, with their '
                . 'identifier and a link. Use it to get the user identifiers that other tools take, for example before '
                . 'enrolling named people. The e-mail address is searched and returned only when the site shows '
                . 'e-mail addresses to this user. Deleted accounts are never returned, and there is no way to list all '
                . 'users: a search with more than 20 matches returns the first 20 and says so.',
            [
                'search_text' => [
                    'type' => 'string', 'minLength' => 3, 'maxLength' => 100,
                    'description' => 'Part of the name, or of the e-mail address when the site shows it to this user.',
                ],
            ],
            ['search_text'],
            [
                'type' => 'object',
                'properties' => [
                    'users' => [
                        'type' => 'array',
                        'items' => [
                            'type' => 'object',
                            'properties' => people::schema() + ['suspended' => ['type' => 'boolean']],
                        ],
                    ],
                    'more_matches' => ['type' => 'boolean'],
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
        return new tool_requirement(profile::MANAGER, 'users.read', ['moodle/user:viewdetails']);
    }

    /**
     * The tool lists other people.
     *
     * @return bool
     */
    public static function returns_third_party_data(): bool {
        return true;
    }

    /**
     * The search covers the whole site.
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
        global $DB;
        $query = trim((string) $args['search_text']);
        if (\core_text::strlen($query) < 3) {
            return new tool_result(['users' => [], 'more_matches' => false], 0);
        }
        // The core search matches the name and only the identity fields this user is allowed to see,
        // skips deleted accounts and leaves out people whose profile the user cannot view.
        $found = \core_user::search($query, null, self::MAX_RESULTS + 1, 5);
        $more = count($found) > self::MAX_RESULTS;
        $ids = array_map(static fn($row) => (int) $row->id, array_slice(array_values($found), 0, self::MAX_RESULTS));
        $emails = people::may_see_emails($call);
        $users = [];
        if ($ids) {
            $records = $DB->get_records_list('user', 'id', $ids);
            foreach ($ids as $id) {
                $user = $records[$id] ?? null;
                if (!$user || $user->deleted || isguestuser($user) || $id == $call->user->id) {
                    continue;
                }
                $users[] = people::describe($user, $call, null, $emails) + ['suspended' => !empty($user->suspended)];
            }
        }
        $result = new tool_result(['users' => $users, 'more_matches' => $more], count($users));
        $result->thirdparty = count($users);
        return $result;
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile of the token.
     * @return array
     */
    public static function output_fields(string $profile): array {
        if (!profile::at_least($profile, profile::MANAGER)) {
            return [];
        }
        return ['more_matches', 'users' => ['*' => array_merge(people::fields($profile), ['suspended'])]];
    }
}
