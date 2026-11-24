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
use local_pluginiaconnect\local\mcp\tool_registry;
use local_pluginiaconnect\local\oauth\client_repository;
use local_pluginiaconnect\local\oauth\grant_context;
use local_pluginiaconnect\local\tool\base_tool;
use local_pluginiaconnect\local\urls;

/**
 * The decision function: whether a tool call is permitted.
 *
 * Effective permission = Moodle capabilities ∩ profile ∩ scopes ∩ site policy.
 * Every layer can only take away. The function has no side effects; it only
 * reads settings, capabilities and enrolment state. Any unexpected state denies.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class decision {
    /**
     * Checks that do not depend on the arguments: site switches, tool matrix, profile and scope.
     *
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user User the call runs as.
     * @param string $toolname Tool name.
     * @return decision_result
     */
    public static function before_context(grant_context $grant, \stdClass $user, string $toolname): decision_result {
        global $CFG;

        if (!site_policy::is_enabled() || !site_policy::mode_active_for($grant->clienttype)) {
            return decision_result::deny('site_unavailable', 'The connector is switched off on this site.');
        }
        if (
            !empty($CFG->maintenance_enabled)
            && !has_capability('moodle/site:maintenanceaccess', \context_system::instance(), $user)
        ) {
            return decision_result::deny('site_unavailable', 'The site is in maintenance mode.');
        }
        if (!urls::is_own_resource($grant->resource)) {
            return decision_result::deny('permission_denied', 'This connection was not issued for this site.');
        }
        // The site still accepts this kind of application: registration method, trusted domain, redirect address.
        $client = client_repository::get_by_id($grant->clientid);
        if (!$client || !client_repository::is_still_accepted($client)) {
            return decision_result::deny('permission_denied', 'This site no longer accepts the application you connected.');
        }
        // The user may still use the connector at all: connect capability and excluded cohorts, checked live.
        if (eligibility::access_denial($user) !== null) {
            return decision_result::deny('permission_denied', 'Your account may no longer use the connector on this site.');
        }
        $class = tool_registry::get($toolname);
        if ($class === null) {
            return decision_result::deny('tool_disabled', 'This action does not exist.');
        }
        $requirement = $class::requirement();
        if (
            !site_policy::is_tool_enabled($toolname, $grant->profile)
            || !site_policy::actor_allows($grant->actorid, $requirement->write)
        ) {
            return decision_result::deny('tool_disabled', 'Your site administrator has disabled this action.');
        }
        if ($requirement->write && config::bool('readonlymode')) {
            return decision_result::deny('tool_disabled', 'Changes through the connector are disabled on this site.');
        }
        if (!profile::at_least($grant->profile, $requirement->profile)) {
            return decision_result::deny(
                'profile_not_allowed',
                "This action needs the {$requirement->profile} profile. Reconnect and choose it if it applies to you."
            );
        }
        if (!$grant->has_scope($requirement->scope)) {
            $message = $requirement->write
                ? "This connection is read-only. Reconnect and choose 'Read and changes' to allow it."
                : 'This connection does not include the access this action needs. Reconnect to grant it.';
            return decision_result::deny('scope_missing', $message);
        }
        return decision_result::allow();
    }

    /**
     * Checks that depend on the context the tool acts on: site scope, course
     * visibility, live profile eligibility and the Moodle capabilities of the tool.
     *
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user User the call runs as.
     * @param string $toolname Tool name.
     * @param \context $context Context resolved from the arguments.
     * @return decision_result
     */
    public static function in_context(
        grant_context $grant,
        \stdClass $user,
        string $toolname,
        \context $context
    ): decision_result {
        $class = tool_registry::get($toolname);
        if ($class === null) {
            return decision_result::deny('tool_disabled', 'This action does not exist.');
        }
        $requirement = $class::requirement();

        // A course the user cannot enter is reported as missing, whatever the reason, so that its existence is not revealed.
        $coursecontext = $context->get_course_context(false);
        if ($coursecontext && $coursecontext->instanceid != SITEID) {
            $courseid = (int) $coursecontext->instanceid;
            $course = get_course($courseid);
            if (!can_access_course($course, $user, '', true)) {
                // Only a call that named the course gets its id back: an activity or submission id
                // must not reveal which course holds it.
                $message = $context->contextlevel == CONTEXT_COURSE
                    ? "Course {$courseid} was not found or is not visible to you."
                    : base_tool::ITEM_NOT_FOUND;
                return decision_result::deny('not_found', $message);
            }
        }
        if (!context_filter::is_context_allowed($context)) {
            return decision_result::deny('out_of_scope', 'This course is not available through the connector.');
        }
        if (!eligibility::is_eligible((int) $user->id, $grant->profile)) {
            return decision_result::deny(
                'profile_not_allowed',
                "Your account no longer qualifies for the {$grant->profile} profile. Reconnect and choose another profile."
            );
        }
        // The profile the user really holds here, never above the one of the connection, must reach the tool's minimum.
        $effective = eligibility::effective_profile((int) $user->id, $grant->profile, $context);
        if ($effective === null || !profile::at_least($effective, $requirement->profile)) {
            return decision_result::deny('permission_denied', 'You do not have permission to do this here.');
        }
        // Changes need the write capability in the very place they are made, not just somewhere on the site.
        if ($requirement->write && !has_capability(eligibility::CAP_WRITE, $coursecontext ?: $context, $user)) {
            return decision_result::deny('permission_denied', 'You do not have permission to make changes here.');
        }
        foreach ($requirement->capabilities as $capability) {
            if (!has_capability($capability, $context, $user)) {
                return decision_result::deny('permission_denied', 'You do not have permission to do this here.');
            }
        }
        return decision_result::allow();
    }

    /**
     * Full decision for a call whose context is known.
     *
     * @param grant_context $grant Grant behind the token.
     * @param \stdClass $user User the call runs as.
     * @param string $toolname Tool name.
     * @param \context $context Context resolved from the arguments.
     * @return decision_result
     */
    public static function evaluate(grant_context $grant, \stdClass $user, string $toolname, \context $context): decision_result {
        try {
            $result = self::before_context($grant, $user, $toolname);
            return $result->allowed ? self::in_context($grant, $user, $toolname, $context) : $result;
        } catch (\Throwable $e) {
            return decision_result::deny('permission_denied', 'The permission check could not be completed.');
        }
    }
}
