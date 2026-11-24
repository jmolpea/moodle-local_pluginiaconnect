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
 * Event observers.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$observers = [
    [
        'eventname' => '\local_pluginiaconnect\event\connection_granted',
        'callback' => '\local_pluginiaconnect\observer::connection_granted',
    ],
    [
        'eventname' => '\core\event\user_deleted',
        'callback' => '\local_pluginiaconnect\observer::user_deleted',
    ],
    [
        'eventname' => '\core\event\user_updated',
        'callback' => '\local_pluginiaconnect\observer::user_updated',
    ],
    [
        'eventname' => '\core\event\user_password_updated',
        'callback' => '\local_pluginiaconnect\observer::user_password_updated',
    ],
    [
        'eventname' => '\core\event\role_assigned',
        'callback' => '\local_pluginiaconnect\observer::eligibility_changed',
    ],
    [
        'eventname' => '\core\event\role_unassigned',
        'callback' => '\local_pluginiaconnect\observer::eligibility_changed',
    ],
    [
        'eventname' => '\core\event\user_enrolment_deleted',
        'callback' => '\local_pluginiaconnect\observer::eligibility_changed',
    ],
    [
        'eventname' => '\core\event\cohort_member_added',
        'callback' => '\local_pluginiaconnect\observer::eligibility_changed',
    ],
    [
        'eventname' => '\core\event\cohort_member_removed',
        'callback' => '\local_pluginiaconnect\observer::eligibility_changed',
    ],
    // The definition of a role changed: what its holders are eligible for may have changed with it.
    // (\core\event\role_capabilities_updated is deprecated and core no longer triggers it.)
    [
        'eventname' => '\core\event\capability_assigned',
        'callback' => '\local_pluginiaconnect\observer::role_definition_changed',
    ],
    [
        'eventname' => '\core\event\capability_unassigned',
        'callback' => '\local_pluginiaconnect\observer::role_definition_changed',
    ],
    [
        'eventname' => '\core\event\role_updated',
        'callback' => '\local_pluginiaconnect\observer::role_definition_changed',
    ],
    [
        'eventname' => '\core\event\role_deleted',
        'callback' => '\local_pluginiaconnect\observer::role_definition_changed',
    ],
];
