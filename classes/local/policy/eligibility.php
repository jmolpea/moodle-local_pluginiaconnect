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

/**
 * Which profiles a user may connect with. Always derived from real capabilities.
 *
 * The profile chosen at consent is only a ceiling: it grants nothing by itself.
 * Each call checks again, live, that the user still holds the profile capability
 * in the context the tool acts on.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class eligibility {
    /** @var string Capability to connect an assistant at all. */
    public const CAP_CONNECT = 'local/pluginiaconnect:connect';
    /** @var string Capability to request write scopes. */
    public const CAP_WRITE = 'local/pluginiaconnect:write';
    /** @var int How many candidate courses are inspected when looking for one inside the site scope. */
    private const SCAN_LIMIT = 200;

    /**
     * Capability that backs a profile, or null for the administrator profile.
     *
     * @param string $profile Profile name.
     * @return string|null
     */
    public static function capability(string $profile): ?string {
        return match ($profile) {
            profile::STUDENT => 'local/pluginiaconnect:usestudentprofile',
            profile::TEACHER => 'local/pluginiaconnect:useteacherprofile',
            profile::MANAGER => 'local/pluginiaconnect:usemanagerprofile',
            default => null,
        };
    }

    /**
     * Whether the user may connect an assistant, and why not when they may not.
     *
     * @param \stdClass $user User record.
     * @return string|null Null when allowed; otherwise one of: guest, inactive, nocapability, cohort, noprofile.
     */
    public static function connect_denial(\stdClass $user): ?string {
        return self::access_denial($user) ?? (self::eligible_profiles((int) $user->id) ? null : 'noprofile');
    }

    /**
     * Whether the user may use the connector at all, whatever the profile.
     *
     * This is checked at consent and again, live, on every tool call and on
     * every token refresh: a user who loses the connect capability or joins an
     * excluded cohort loses existing connections on their next use. It is cheap
     * on purpose: one capability check and one indexed query per excluded cohort.
     *
     * @param \stdClass $user User record.
     * @return string|null Null when allowed; otherwise one of: guest, inactive, nocapability, cohort.
     */
    public static function access_denial(\stdClass $user): ?string {
        global $CFG;
        if (empty($user->id) || isguestuser($user)) {
            return 'guest';
        }
        if (!empty($user->deleted) || !empty($user->suspended) || empty($user->confirmed) || $user->auth === 'nologin') {
            return 'inactive';
        }
        if (!has_capability(self::CAP_CONNECT, \context_system::instance(), $user)) {
            return 'nocapability';
        }
        $excluded = config::ids('excludedcohorts');
        if ($excluded) {
            require_once($CFG->dirroot . '/cohort/lib.php');
            foreach ($excluded as $cohortid) {
                if (cohort_is_member($cohortid, $user->id)) {
                    return 'cohort';
                }
            }
        }
        return null;
    }

    /**
     * Highest profile, not above the profile of the grant, that the user holds live in a context.
     *
     * Output fields are chosen with it, so a teacher connection never yields
     * teacher-level fields in a course where the user is only a student.
     *
     * @param int $userid User id.
     * @param string $grantprofile Profile chosen at consent: the ceiling.
     * @param \context $context Context the tool acts on.
     * @return string|null Null when the user holds no profile there.
     */
    public static function effective_profile(int $userid, string $grantprofile, \context $context): ?string {
        // Outside any course (system or user context) the teacher profile cannot be tied to one course:
        // it holds there when the user still teaches some course inside the site scope.
        $coursecontext = $context->get_course_context(false);
        $outsidecourse = !$coursecontext || $coursecontext->instanceid == SITEID;
        foreach (array_reverse(profile::all()) as $candidate) {
            if (!profile::at_least($grantprofile, $candidate)) {
                continue;
            }
            // The same holds for a manager of one category: at site level the profile holds while the user
            // manages some category in scope. Each tool then limits itself to what the user can manage.
            $sitewide = $outsidecourse && $context->contextlevel != CONTEXT_COURSECAT;
            if ($sitewide && ($candidate === profile::TEACHER || $candidate === profile::MANAGER)) {
                if (self::is_eligible($userid, $candidate)) {
                    return $candidate;
                }
                continue;
            }
            if (self::is_eligible_in_context($userid, $candidate, $context)) {
                return $candidate;
            }
        }
        return null;
    }

    /**
     * Profiles the user may choose, lowest first. Cached for ten minutes.
     *
     * @param int $userid User id.
     * @return string[]
     */
    public static function eligible_profiles(int $userid): array {
        $cache = \cache::make(config::COMPONENT, 'eligibility');
        $cached = $cache->get('u' . $userid);
        if (is_string($cached)) {
            return $cached === '' ? [] : explode(',', $cached);
        }
        $profiles = [];
        foreach (profile::all() as $profile) {
            if (self::compute($userid, $profile)) {
                $profiles[] = $profile;
            }
        }
        $cache->set('u' . $userid, implode(',', $profiles));
        return $profiles;
    }

    /**
     * Whether the user may hold a profile at all (cached answer).
     *
     * @param int $userid User id.
     * @param string $profile Profile name.
     * @return bool
     */
    public static function is_eligible(int $userid, string $profile): bool {
        return in_array($profile, self::eligible_profiles($userid), true);
    }

    /**
     * Live check: the user holds the capability of a profile in a concrete context.
     * Never cached, so that a removed role takes effect on the next call.
     *
     * @param int $userid User id.
     * @param string $profile Profile whose capability is checked (the minimum profile of the tool).
     * @param \context $context Context the tool acts on.
     * @return bool
     */
    public static function is_eligible_in_context(int $userid, string $profile, \context $context): bool {
        if (!profile::is_valid($profile) || !config::bool(profile::setting($profile))) {
            return false;
        }
        switch ($profile) {
            case profile::STUDENT:
                return has_capability(self::capability($profile), \context_system::instance(), $userid);
            case profile::TEACHER:
                $coursecontext = $context->get_course_context(false);
                return $coursecontext && $coursecontext->instanceid != SITEID
                    && has_capability(self::capability($profile), $coursecontext, $userid);
            case profile::MANAGER:
                return has_capability(self::capability($profile), $context, $userid);
            default:
                return self::is_allowed_admin($userid);
        }
    }

    /**
     * Whether the user holds the write capability in at least one course in scope.
     *
     * @param int $userid User id.
     * @return bool
     */
    public static function can_write_somewhere(int $userid): bool {
        return self::has_course_in_scope(self::CAP_WRITE, $userid);
    }

    /**
     * Forgets the cached profiles of a user.
     *
     * @param int $userid User id.
     * @return void
     */
    public static function invalidate(int $userid): void {
        \cache::make(config::COMPONENT, 'eligibility')->delete('u' . $userid);
    }

    /**
     * Forgets every cached answer. Used when a site setting changes.
     *
     * @return void
     */
    public static function invalidate_all(): void {
        \cache::make(config::COMPONENT, 'eligibility')->purge();
    }

    /**
     * Computes eligibility for one profile without the cache.
     *
     * @param int $userid User id.
     * @param string $profile Profile name.
     * @return bool
     */
    private static function compute(int $userid, string $profile): bool {
        if (!config::bool(profile::setting($profile))) {
            return false;
        }
        switch ($profile) {
            case profile::STUDENT:
                return has_capability(self::capability($profile), \context_system::instance(), $userid);
            case profile::TEACHER:
                return self::has_course_in_scope(self::capability($profile), $userid);
            case profile::MANAGER:
                return self::has_manager_scope($userid);
            default:
                return self::is_allowed_admin($userid);
        }
    }

    /**
     * Whether the user is a site administrator allowed to use the administrator profile.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function is_allowed_admin(int $userid): bool {
        if (!config::bool('allowadminprofile') || !is_siteadmin($userid)) {
            return false;
        }
        $listed = config::ids('adminprofileusers');
        return !$listed || in_array($userid, $listed, true);
    }

    /**
     * Whether the user holds a capability in at least one course inside the site scope.
     *
     * @param string $capability Capability name.
     * @param int $userid User id.
     * @return bool
     */
    private static function has_course_in_scope(string $capability, int $userid): bool {
        $courses = get_user_capability_course($capability, $userid, true, '', '', self::SCAN_LIMIT);
        if (!$courses) {
            return false;
        }
        foreach ($courses as $course) {
            if ($course->id != SITEID && context_filter::is_course_allowed((int) $course->id)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Whether the user holds the manager capability at site level or in a category in scope.
     *
     * @param int $userid User id.
     * @return bool
     */
    private static function has_manager_scope(int $userid): bool {
        global $DB;
        $capability = self::capability(profile::MANAGER);
        if (has_capability($capability, \context_system::instance(), $userid)) {
            return true;
        }
        // Categories where the user has any role assigned are the only candidates.
        $sql = "SELECT DISTINCT ctx.instanceid
                  FROM {role_assignments} ra
                  JOIN {context} ctx ON ctx.id = ra.contextid
                 WHERE ra.userid = :userid AND ctx.contextlevel = :level";
        $categoryids = $DB->get_fieldset_sql($sql, ['userid' => $userid, 'level' => CONTEXT_COURSECAT]);
        foreach ($categoryids as $categoryid) {
            $context = \context_coursecat::instance($categoryid, IGNORE_MISSING);
            if (
                $context && context_filter::is_category_allowed((int) $categoryid)
                && has_capability($capability, $context, $userid)
            ) {
                return true;
            }
        }
        return false;
    }
}
