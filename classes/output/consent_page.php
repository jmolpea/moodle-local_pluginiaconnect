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

namespace local_pluginiaconnect\output;

use local_pluginiaconnect\local\config;
use local_pluginiaconnect\local\oauth\authorization_request;
use local_pluginiaconnect\local\oauth\client;
use local_pluginiaconnect\local\oauth\redirect_policy;
use local_pluginiaconnect\local\policy\scope;
use local_pluginiaconnect\local\policy\site_policy;

/**
 * Data of the consent page.
 *
 * Everything that comes from the client (its name, the assistant name) is
 * passed as plain text and escaped by the template.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class consent_page implements \renderable, \templatable {
    /** @var authorization_request Validated request. */
    private authorization_request $request;
    /** @var \stdClass User who is asked. */
    private \stdClass $user;
    /** @var string[] Profiles the user may choose, lowest first. */
    private array $profiles;
    /** @var bool Whether "read and changes" may be offered. */
    private bool $canwrite;
    /** @var \moodle_url URL the form posts to (the request itself). */
    private \moodle_url $action;
    /** @var string Error to show above the form. */
    private string $error;

    /**
     * Constructor.
     *
     * @param authorization_request $request Validated request.
     * @param \stdClass $user User who is asked.
     * @param string[] $profiles Profiles the user may choose, lowest first.
     * @param bool $canwrite Whether "read and changes" may be offered.
     * @param \moodle_url $action URL the form posts to.
     * @param string $error Error to show above the form.
     */
    public function __construct(
        authorization_request $request,
        \stdClass $user,
        array $profiles,
        bool $canwrite,
        \moodle_url $action,
        string $error = ''
    ) {
        $this->request = $request;
        $this->user = $user;
        $this->profiles = array_values($profiles);
        $this->canwrite = $canwrite;
        $this->action = $action;
        $this->error = $error;
    }

    /**
     * Whether writes requested through this connection will need approval on the site.
     *
     * @param authorization_request $request Request.
     * @return bool
     */
    public static function needs_site_approval(authorization_request $request): bool {
        return $request->actorid !== null && in_array($request->actorid, config::lines('unattendedactors'), true);
    }

    /**
     * Whether "read and changes" would add anything for this user: some profile they may choose has an
     * action that changes data switched on, within the scopes the application asked for.
     *
     * When no such action is active the option is not offered at all: choosing it would give a
     * connection that only reads, under a label that says otherwise.
     *
     * @param authorization_request $request Request.
     * @param int $userid User who is asked.
     * @param string[] $profiles Profiles the user may choose.
     * @return bool
     */
    public static function offers_changes(authorization_request $request, int $userid, array $profiles): bool {
        foreach ($profiles as $profile) {
            $active = site_policy::scopes_with_active_tools($profile);
            foreach (scope::granted($request->scopes, $profile, true, $userid, $active) as $name) {
                if (scope::is_write($name)) {
                    return true;
                }
            }
        }
        return false;
    }

    /**
     * Areas the user may tick on this consent (amendment 2, D21 and D22): those with at least one action switched
     * on for a profile the user may choose, within the scopes the application asked for.
     *
     * @param authorization_request $request Request.
     * @param int $userid User who is asked.
     * @param string[] $profiles Profiles the user may choose.
     * @param bool $canwrite Whether "read and changes" may be offered.
     * @return array Area => titles of its tools, in the order of the areas.
     */
    public static function offered_areas(authorization_request $request, int $userid, array $profiles, bool $canwrite): array {
        $areas = [];
        foreach (array_keys(scope::AREAS) as $area) {
            foreach ($profiles as $profile) {
                $active = site_policy::scopes_with_active_tools($profile);
                $granted = scope::granted($request->scopes, $profile, $canwrite, $userid, $active);
                $within = array_values(array_intersect($granted, scope::AREAS[$area]));
                if ($within) {
                    $areas[$area] = array_values(array_unique(array_merge(
                        $areas[$area] ?? [],
                        site_policy::describe_tools($profile, $within)
                    )));
                }
            }
        }
        return $areas;
    }

    /**
     * Areas the user already granted to this application in a connection that is still active: their boxes
     * start ticked, every other box starts empty.
     *
     * @param authorization_request $request Request.
     * @param int $userid User who is asked.
     * @return string[]
     */
    public static function previous_areas(authorization_request $request, int $userid): array {
        global $DB;
        $areas = [];
        $grants = $DB->get_records(
            'local_pluginiaconnect_grant',
            ['userid' => $userid, 'clientid' => $request->client->id, 'status' => 1],
            '',
            'id, scopes'
        );
        foreach ($grants as $grant) {
            foreach (scope::parse((string) $grant->scopes) as $name) {
                $area = scope::area_of($name);
                if ($area !== null) {
                    $areas[$area] = true;
                }
            }
        }
        return array_keys($areas);
    }

    /**
     * Company that will receive the data the user asks for, when it can be told.
     *
     * @param authorization_request $request Request.
     * @return string Empty when unknown.
     */
    public static function provider_name(authorization_request $request): string {
        $byactor = [
            'claude' => 'Anthropic',
            'chatgpt' => 'OpenAI',
            authorization_request::ACTOR_COWORK => 'Microsoft (Microsoft 365 Copilot)',
        ];
        if ($request->actorid !== null && isset($byactor[$request->actorid])) {
            return $byactor[$request->actorid];
        }
        if ($request->client->type === client::TYPE_CIMD) {
            $byhost = ['claude.ai' => 'Anthropic', 'chatgpt.com' => 'OpenAI'];
            return $byhost[$request->client->host()] ?? '';
        }
        return '';
    }

    /**
     * Template data.
     *
     * @param \renderer_base $output Renderer.
     * @return array
     */
    public function export_for_template(\renderer_base $output): array {
        global $CFG, $SITE;
        $request = $this->request;
        $component = config::COMPONENT;

        $profiles = [];
        // The option to read and change is shown only when it adds at least one action to some profile.
        $canwrite = $this->canwrite && self::offers_changes($request, (int) $this->user->id, $this->profiles);
        foreach ($this->profiles as $index => $profile) {
            $active = site_policy::scopes_with_active_tools($profile);
            // The tools of the areas are listed under their own boxes.
            $readscopes = scope::within_areas(
                scope::granted($request->scopes, $profile, false, (int) $this->user->id, $active),
                []
            );
            $allscopes = $canwrite ? scope::within_areas(
                scope::granted($request->scopes, $profile, true, (int) $this->user->id, $active),
                []
            ) : $readscopes;
            $readtools = site_policy::describe_tools($profile, $readscopes);
            $writetools = array_values(array_diff(site_policy::describe_tools($profile, $allscopes), $readtools));
            $profiles[] = [
                'value' => $profile,
                'id' => 'pc-profile-' . $profile,
                'label' => get_string('profile_' . $profile, $component),
                'description' => get_string('profile_' . $profile . '_desc', $component),
                'checked' => $index === 0,
                'readtools' => array_map(static fn($title) => ['title' => (string) $title], array_values($readtools)),
                'hasreadtools' => !empty($readtools),
                'writetools' => array_map(static fn($title) => ['title' => (string) $title], $writetools),
                'haswritetools' => !empty($writetools),
            ];
        }

        $areas = [];
        $ticked = self::previous_areas($request, (int) $this->user->id);
        foreach (self::offered_areas($request, (int) $this->user->id, $this->profiles, $canwrite) as $area => $titles) {
            $areas[] = [
                'value' => $area,
                'id' => 'pc-area-' . $area,
                'label' => get_string('consent_area_' . $area, $component),
                'description' => get_string('consent_area_' . $area . '_desc', $component),
                'checked' => in_array($area, $ticked, true),
                'tools' => array_map(static fn($title) => ['title' => (string) $title], $titles),
            ];
        }

        $isgateway = $request->client->type === client::TYPE_GATEWAY;
        $provider = self::provider_name($request);
        $privacyurl = config::string('privacyurl');
        $connections = file_exists($CFG->dirroot . '/local/pluginiaconnect/connections.php')
            ? (new \moodle_url('/local/pluginiaconnect/connections.php'))->out(false) : '';

        return [
            'signedin' => get_string('consent_signedin', $component, [
                'site' => format_string($SITE->fullname, true, ['context' => \context_system::instance(), 'escape' => false]),
                'user' => fullname($this->user),
            ]),
            'clientname' => $request->client->name,
            'clienthost' => $request->client->host(),
            'redirecthost' => (string) parse_url($request->redirecturi, PHP_URL_HOST),
            'isloopback' => redirect_policy::is_loopback($request->redirecturi),
            'isgateway' => $isgateway,
            'requester' => !$isgateway ? '' : ($request->actor !== null
                ? get_string('consent_gateway_actor', $component, $request->actor)
                : get_string('consent_gateway', $component)),
            'actionurl' => $this->action->out(false),
            'sesskey' => sesskey(),
            'profiles' => $profiles,
            'areas' => $areas,
            'hasareas' => !empty($areas),
            'canwrite' => $canwrite,
            'siteapproval' => $canwrite && self::needs_site_approval($request),
            'datanotice' => $provider !== ''
                ? get_string('consent_datanotice_provider', $component, $provider)
                : get_string('consent_datanotice', $component),
            'sitenotice' => config::string('sitenotice'),
            'hassitenotice' => config::string('sitenotice') !== '',
            'privacyurl' => preg_match('~^https?://~i', $privacyurl) ? $privacyurl : '',
            'connectionsurl' => $connections,
            'error' => $this->error,
        ];
    }
}
