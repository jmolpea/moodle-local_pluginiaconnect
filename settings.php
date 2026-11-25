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
 * Site administration settings.
 *
 * Factory defaults and numeric limits live in \local_pluginiaconnect\local\config
 * and are never repeated here.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

use local_pluginiaconnect\admin\callbacks;
use local_pluginiaconnect\admin\setting_bounded_int;
use local_pluginiaconnect\admin\setting_list;
use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\urls;

defined('MOODLE_INTERNAL') || die();

// The category and the report pages are visible to anyone holding their capability, not only to
// site administrators: managers may read the audit trail without being able to change settings.
// Up to Moodle 5.0 core creates the "Local plugins" category only for site administrators, so
// for other users the category hangs from "Plugins", which always exists.
$ADMIN->add($ADMIN->locate('localplugins') ? 'localplugins' : 'modules', new admin_category(
    'local_pluginiaconnect_category',
    get_string('pluginname', config::COMPONENT)
));

if ($hassiteconfig) {
    $component = config::COMPONENT;
    $defaults = config::DEFAULTS;
    $str = static fn(string $key, $a = null): string => get_string($key, $component, $a);
    $policychanged = [callbacks::class, 'policy_changed'];
    $killswitchchanged = [callbacks::class, 'killswitch_changed'];

    $page = new admin_settingpage('local_pluginiaconnect', $str('settings'));

    if ($ADMIN->fulltree) {
        // Adds an on/off setting.
        $checkbox = static function (string $name, ?callable $callback = null) use ($page, $component, $defaults, $str): void {
            $setting = new admin_setting_configcheckbox(
                $component . '/' . $name,
                $str('setting_' . $name),
                $str('setting_' . $name . '_desc'),
                $defaults[$name]
            );
            if ($callback) {
                $setting->set_updatedcallback($callback);
            }
            $page->add($setting);
        };
        // Adds a list setting, one entry per line.
        $list = static function (string $name, string $kind, $callback = null, array $mandatory = []) use ($page, $str): void {
            $setting = new setting_list($name, $str('setting_' . $name), $str('setting_' . $name . '_desc'), $kind, $mandatory);
            if ($callback) {
                $setting->set_updatedcallback($callback);
            }
            $page->add($setting);
        };
        // Adds a whole number setting with hard limits.
        $bounded = static function (string $name) use ($page, $str): void {
            [$min, $max] = config::LIMITS[$name];
            $page->add(new setting_bounded_int(
                $name,
                $str('setting_' . $name),
                $str('setting_' . $name . '_desc', (object) ['min' => $min, 'max' => $max])
            ));
        };
        $heading = static function (string $name) use ($page, $component, $str): void {
            $page->add(new admin_setting_heading(
                $component . '/heading_' . $name,
                $str('heading_' . $name),
                $str('heading_' . $name . '_desc')
            ));
        };

        // Link to the configuration manual, before anything else. The address lives in urls::MANUAL only.
        $page->add(new admin_setting_heading(
            $component . '/manuallink',
            $str('manuallink'),
            html_writer::link(urls::MANUAL, $str('manuallink_desc'), ['target' => '_blank', 'rel' => 'noopener'])
        ));

        // General.
        $heading('general');
        $checkbox('enabled', $killswitchchanged);
        $checkbox('readonlymode', $killswitchchanged);
        $checkbox('directmode', $killswitchchanged);
        $checkbox('gatewayenabled', $killswitchchanged);
        $page->add(new admin_setting_configtextarea(
            $component . '/sitenotice',
            $str('setting_sitenotice'),
            $str('setting_sitenotice_desc'),
            $defaults['sitenotice'],
            PARAM_TEXT
        ));
        $page->add(new admin_setting_configtext(
            $component . '/privacyurl',
            $str('setting_privacyurl'),
            $str('setting_privacyurl_desc'),
            $defaults['privacyurl'],
            PARAM_URL,
            60
        ));

        // Profiles.
        $heading('profiles');
        $checkbox('allowstudentprofile', $policychanged);
        $checkbox('allowteacherprofile', $policychanged);
        $checkbox('allowmanagerprofile', $policychanged);
        $checkbox('allowadminprofile', $policychanged);
        $list('adminprofileusers', setting_list::KIND_IDS, $policychanged);
        $list('excludedcohorts', setting_list::KIND_IDS, $policychanged);

        // Scope.
        $heading('scope');
        $list('allowedcategories', setting_list::KIND_IDS, $policychanged);
        $list('excludedcategories', setting_list::KIND_IDS, $policychanged);
        $list('excludedcourses', setting_list::KIND_IDS, $policychanged);
        $checkbox('includehiddencourses', $policychanged);
        $page->add(new admin_setting_configtextarea(
            $component . '/profilefields',
            $str('setting_profilefields'),
            $str('setting_profilefields_desc'),
            $defaults['profilefields'],
            PARAM_TEXT,
            60,
            3
        ));
        $page->add(new admin_setting_configselect(
            $component . '/showemails',
            $str('setting_showemails'),
            $str('setting_showemails_desc'),
            $defaults['showemails'],
            [
                'none' => $str('showemails_none'),
                'teacher' => $str('showemails_teacher'),
                'manager' => $str('showemails_manager'),
                'admin' => $str('showemails_admin'),
            ]
        ));

        // OAuth.
        $heading('oauth');
        $list('allowedredirects', setting_list::KIND_REDIRECTS);
        $checkbox('allowloopback');
        $checkbox('allowdcr');
        $checkbox('allowcimd');
        $list('cimdtrusteddomains', setting_list::KIND_HOSTS);
        // Read on every request (challenge and metadata): no cache to refresh when it changes.
        $page->add(new admin_setting_configselect(
            $component . '/advertisedscopes',
            $str('setting_advertisedscopes'),
            $str('setting_advertisedscopes_desc'),
            $defaults['advertisedscopes'],
            [
                'active' => $str('advertisedscopes_active'),
                'baseline' => $str('advertisedscopes_baseline'),
            ]
        ));
        $bounded('accesstokenttl');
        $bounded('refreshidlettl');
        $bounded('grantabsolutettl');
        $checkbox('revokeonpasswordchange');

        // Limits.
        $heading('limits');
        foreach (
            [
                'ratereadminute', 'ratereadday', 'ratewriteminute', 'ratewriteday', 'exposurebudgethour',
                'maxpagesize', 'maxresponsechars', 'maxbulkusers', 'maxrecipients', 'maxforumpostsday', 'logwindowdays',
            ] as $name
        ) {
            $bounded($name);
        }

        // Retention.
        $heading('retention');
        $bounded('auditretentiondays');
        $checkbox('logip');

        // Assistants.
        $heading('assistants');
        $list('unattendedactors', setting_list::KIND_ACTORS, $policychanged, ['copilot-cowork']);
        $actorpolicy = new admin_setting_configselect(
            $component . '/actorpolicy_copilotcowork',
            $str('setting_actorpolicy_copilotcowork'),
            $str('setting_actorpolicy_copilotcowork_desc'),
            $defaults['actorpolicy_copilotcowork'],
            [
                'approval' => $str('actorpolicy_approval'),
                'readonly' => $str('actorpolicy_readonly'),
                'blocked' => $str('actorpolicy_blocked'),
            ]
        );
        $actorpolicy->set_updatedcallback($policychanged);
        $page->add($actorpolicy);
    }

    $ADMIN->add('local_pluginiaconnect_category', $page);
    $ADMIN->add('local_pluginiaconnect_category', new admin_externalpage(
        'local_pluginiaconnect_tools',
        $str('tools'),
        new moodle_url('/local/pluginiaconnect/admin/tools.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('local_pluginiaconnect_category', new admin_externalpage(
        'local_pluginiaconnect_register',
        $str('register'),
        new moodle_url('/local/pluginiaconnect/admin/register.php'),
        'moodle/site:config'
    ));
    $ADMIN->add('local_pluginiaconnect_category', new admin_externalpage(
        'local_pluginiaconnect_health',
        $str('health'),
        new moodle_url('/local/pluginiaconnect/admin/health.php'),
        'moodle/site:config'
    ));
}

$ADMIN->add('local_pluginiaconnect_category', new admin_externalpage(
    'local_pluginiaconnect_connections',
    get_string('adminconnections', config::COMPONENT),
    new moodle_url('/local/pluginiaconnect/admin/connections.php'),
    'local/pluginiaconnect:manageconnections'
));
$ADMIN->add('local_pluginiaconnect_category', new admin_externalpage(
    'local_pluginiaconnect_audit',
    get_string('audit', config::COMPONENT),
    new moodle_url('/local/pluginiaconnect/admin/audit.php'),
    'local/pluginiaconnect:viewaudit'
));
