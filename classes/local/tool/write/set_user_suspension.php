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

namespace local_pluginiaconnect\local\tool\write;

use local_pluginiaconnect\local\policy\profile;
use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\people;
use local_pluginiaconnect\local\tool\tool_definition;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_requirement;
use local_pluginiaconnect\local\tool\tool_result;
use local_pluginiaconnect\local\tool\write_tool;

/**
 * Tool 31: suspends or reactivates one user account.
 *
 * It always needs the user's approval on the site, and it can never act on a site
 * administrator, on the caller's own account, on the guest account or on an
 * account of another site.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class set_user_suspension extends write_tool {
    /**
     * Tool name.
     *
     * @return string
     */
    public static function name(): string {
        return 'set_user_suspension';
    }

    /**
     * Tool definition.
     *
     * @return tool_definition
     */
    public static function definition(): tool_definition {
        return new tool_definition(
            self::name(),
            'Set user suspension',
            'Requests the suspension or the reactivation of one user account of the site. A suspended user cannot '
                . 'sign in and is signed out; site administrators and the own account cannot be suspended.'
                . self::approval_text(),
            [
                'user_id' => [
                    'type' => 'integer', 'minimum' => 1,
                    'description' => "Account to change, as returned by 'find_users'.",
                ],
                'suspended' => ['type' => 'boolean', 'description' => 'True to suspend the account, false to reactivate it.'],
            ] + self::control_properties(false),
            ['user_id', 'suspended'],
            self::result_schema([
                'user_id' => ['type' => 'integer'],
                'suspended' => ['type' => 'boolean'],
                'changed' => ['type' => 'boolean'],
                'url' => ['type' => 'string', 'format' => 'uri'],
            ]),
            false,
            true,
            true
        );
    }

    /**
     * Requirements.
     *
     * @return tool_requirement
     */
    public static function requirement(): tool_requirement {
        return new tool_requirement(
            profile::ADMIN,
            'users.write',
            ['moodle/user:update'],
            true,
            tool_requirement::APPROVAL_APPROVAL
        );
    }

    /**
     * Accounts are managed at site level.
     *
     * @param array $args Validated arguments.
     * @return \context
     */
    public function resolve_context(array $args): \context {
        return \context_system::instance();
    }

    /**
     * Loads the account and checks it may be changed.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return \stdClass User record.
     */
    private static function plan(array $args, call_context $call): \stdClass {
        global $CFG, $DB;
        $userid = (int) $args['user_id'];
        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0]);
        if (!$user) {
            throw new tool_exception('not_found', "User {$userid} was not found.");
        }
        if ($userid === (int) $call->user->id) {
            throw new tool_exception('validation_error', 'You cannot suspend or reactivate your own account.');
        }
        if (is_siteadmin($user) || isguestuser($user) || (int) $user->mnethostid !== (int) $CFG->mnet_localhost_id) {
            throw new tool_exception('permission_denied', 'This account cannot be suspended or reactivated through the connector.');
        }
        return $user;
    }

    /**
     * Description of the change.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return string|null
     */
    public function preview(array $args, call_context $call): ?string {
        $user = self::plan($args, $call);
        $name = people::name($user, \context_system::instance());
        $username = self::label($user->username);
        return ($args['suspended'] ? 'Suspend' : 'Reactivate') . " the account of {$name} (user name: {$username}). "
            . 'Currently ' . ($user->suspended ? 'suspended' : 'active') . '. '
            . ($args['suspended'] ? 'The user will be signed out and will not be able to sign in.'
                : 'The user will be able to sign in again.');
    }

    /**
     * Applies the change the way the user administration page does.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call context.
     * @return tool_result
     */
    public function execute(array $args, call_context $call): tool_result {
        global $CFG;
        $user = self::plan($args, $call);
        require_capability('moodle/user:update', \context_system::instance());
        $suspended = $args['suspended'] ? 1 : 0;
        $changed = (int) $user->suspended !== $suspended;
        if ($changed) {
            if ($suspended) {
                // Both functions exist in every supported version; the older name is deprecated since 4.5.
                \core\session\manager::destroy_user_sessions((int) $user->id);
            }
            $update = (object) ['id' => (int) $user->id, 'suspended' => $suspended];
            if (method_exists(\core\user::class, 'update_user')) {
                \core\user::update_user($update, false);
            } else {
                require_once($CFG->dirroot . '/user/lib.php');
                user_update_user($update, false);
            }
        }
        return new tool_result([
            'user_id' => (int) $user->id,
            'suspended' => (bool) $suspended,
            'changed' => $changed,
            'url' => (new \moodle_url('/user/profile.php', ['id' => $user->id]))->out(false),
        ], 1, null, 'user', (int) $user->id);
    }

    /**
     * Output fields.
     *
     * @param string $profile Profile.
     * @return array
     */
    public static function output_fields(string $profile): array {
        return ['user_id', 'suspended', 'changed', 'url'];
    }
}
