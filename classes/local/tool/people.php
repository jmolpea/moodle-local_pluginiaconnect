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

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\guardrail\text_sanitizer;
use local_pluginiaconnect\local\policy\eligibility;
use local_pluginiaconnect\local\policy\profile;

/**
 * Rules for returning data about people other than the caller.
 *
 * Every tool that lists participants goes through this class, so that group
 * separation, suspended enrolments, identity fields and hidden user fields are
 * decided in one place and always the way the Moodle pages decide them.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class people {
    /**
     * Groups the caller is limited to in a course, or null when there is no limit.
     *
     * In a course with separate groups, a user without the capability to access all
     * groups only sees members of their own groups; with no group, nobody.
     *
     * @param \stdClass $course Course record.
     * @param \context $context Course context.
     * @param \stdClass $caller User the call runs as.
     * @return int[]|null
     */
    public static function restricted_groups(\stdClass $course, \context $context, \stdClass $caller): ?array {
        if (
            groups_get_course_groupmode($course) != SEPARATEGROUPS
            || has_capability('moodle/site:accessallgroups', $context, $caller)
        ) {
            return null;
        }
        return array_map('intval', array_keys(groups_get_all_groups($course->id, $caller->id, 0, 'g.id')));
    }

    /**
     * Enrolled users of a course that the caller may see, keyed by id and sorted by name.
     *
     * Suspended enrolments are included only for users who see them on the participants page.
     *
     * @param \stdClass $course Course record.
     * @param \context $context Course context.
     * @param \stdClass $caller User the call runs as.
     * @return \stdClass[]
     */
    public static function visible_enrolled(\stdClass $course, \context $context, \stdClass $caller): array {
        $groups = self::restricted_groups($course, $context, $caller);
        if ($groups === []) {
            return [];
        }
        $onlyactive = !has_capability('moodle/course:viewsuspendedusers', $context, $caller);
        $users = get_enrolled_users($context, '', $groups ?? 0, 'u.*', 'u.lastname, u.firstname, u.id', 0, 0, $onlyactive);
        return array_filter($users, static fn($user) => empty($user->deleted) && !isguestuser($user));
    }

    /**
     * Whether the caller may see when other users last accessed, as the participants page decides it.
     *
     * @param \context $context Course context.
     * @param \stdClass $caller User the call runs as.
     * @return bool
     */
    public static function can_see_last_access(\context $context, \stdClass $caller): bool {
        global $CFG;
        $hidden = array_map('trim', explode(',', (string) ($CFG->hiddenuserfields ?? '')));
        return !in_array('lastaccess', $hidden, true)
            || has_capability('moodle/course:viewhiddenuserfields', $context, $caller);
    }

    /**
     * Last access to a course per user.
     *
     * @param int $courseid Course id.
     * @return int[] User id => timestamp.
     */
    public static function course_last_access(int $courseid): array {
        global $DB;
        // Core has no function that returns this for a whole course without rendering the participants table.
        return array_map('intval', $DB->get_records_menu('user_lastaccess', ['courseid' => $courseid], '', 'userid, timeaccess'));
    }

    /**
     * Profile the caller really holds in the context of the call, never above the one of the connection.
     *
     * @param call_context $call Call data.
     * @return string|null
     */
    public static function effective_profile(call_context $call): ?string {
        return eligibility::effective_profile((int) $call->user->id, $call->grant->profile, $call->context);
    }

    /**
     * Whether e-mail addresses of other users may be returned in this call.
     *
     * Two conditions: the site setting names a profile the caller reaches here, and
     * Moodle itself would show the address to this user in this context.
     *
     * @param call_context $call Call data.
     * @return bool
     */
    public static function may_see_emails(call_context $call): bool {
        $minimum = config::string('showemails');
        $effective = self::effective_profile($call);
        if (!profile::is_valid($minimum) || $effective === null || !profile::at_least($effective, $minimum)) {
            return false;
        }
        return in_array('email', \core_user\fields::get_identity_fields($call->context, false), true);
    }

    /**
     * Custom profile fields of a user that the site allows through the connector and
     * that Moodle would show to the caller.
     *
     * @param int $userid User whose fields are read.
     * @return array List of ['name' => ..., 'value' => ...].
     */
    public static function custom_fields(int $userid): array {
        global $CFG;
        $allowed = config::lines('profilefields');
        if (!$allowed) {
            return [];
        }
        require_once($CFG->dirroot . '/user/profile/lib.php');
        $fields = [];
        foreach (profile_get_user_fields_with_data($userid) as $field) {
            if (!in_array($field->field->shortname, $allowed, true) || !$field->is_visible()) {
                continue;
            }
            $value = text_sanitizer::to_plain((string) $field->display_data());
            if ($value !== '') {
                $fields[] = ['name' => text_sanitizer::clean_plain((string) $field->field->name, 200), 'value' => $value];
            }
        }
        return $fields;
    }

    /**
     * Name of a user as the caller may see it.
     *
     * @param \stdClass $user User record.
     * @param \context $context Context of the call.
     * @return string
     */
    public static function name(\stdClass $user, \context $context): string {
        return text_sanitizer::clean_plain(fullname($user, has_capability('moodle/site:viewfullnames', $context)), 200);
    }

    /**
     * The fields every tool returns about another user: identifier, name, link and,
     * only when allowed, e-mail address and custom profile fields. Never the ID number.
     *
     * @param \stdClass $user User record.
     * @param call_context $call Call data.
     * @param int|null $courseid Course the link refers to.
     * @param bool $emails Result of {@see self::may_see_emails()}, computed once by the caller.
     * @return array
     */
    public static function describe(\stdClass $user, call_context $call, ?int $courseid, bool $emails): array {
        $params = ['id' => $user->id] + ($courseid ? ['course' => $courseid] : []);
        return [
            'user_id' => (int) $user->id,
            'full_name' => self::name($user, $call->context),
            'email' => $emails && !empty($user->email) ? (string) $user->email : null,
            'custom_fields' => self::custom_fields((int) $user->id),
            'url' => (new \moodle_url($courseid ? '/user/view.php' : '/user/profile.php', $params))->out(false),
        ];
    }

    /**
     * Output field list of {@see self::describe()} for a profile.
     *
     * @param string $profile Profile the fields are for.
     * @return array
     */
    public static function fields(string $profile): array {
        $fields = ['user_id', 'full_name', 'url'];
        if (profile::at_least($profile, profile::TEACHER)) {
            $fields[] = 'email';
            $fields['custom_fields'] = ['*' => ['name', 'value']];
        }
        return $fields;
    }

    /**
     * JSON Schema properties of {@see self::describe()}.
     *
     * @return array
     */
    public static function schema(): array {
        return [
            'user_id' => ['type' => 'integer'],
            'full_name' => ['type' => 'string'],
            'email' => ['type' => ['string', 'null']],
            'custom_fields' => [
                'type' => 'array',
                'items' => [
                    'type' => 'object',
                    'properties' => ['name' => ['type' => 'string'], 'value' => ['type' => 'string']],
                ],
            ],
            'url' => ['type' => 'string', 'format' => 'uri'],
        ];
    }
}
