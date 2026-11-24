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

namespace local_pluginiaconnect\local\oauth;

/**
 * What a valid access token stands for: one grant of one user to one client.
 *
 * Immutable. It never contains the token value.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class grant_context {
    /** @var int Grant record id. */
    public readonly int $grantid;
    /** @var int Access token record id. */
    public readonly int $tokenid;
    /** @var int Moodle user id. */
    public readonly int $userid;
    /** @var int Client record id. */
    public readonly int $clientid;
    /** @var string Client type: cimd, dcr, manual or gateway. */
    public readonly string $clienttype;
    /** @var string Profile chosen at consent. */
    public readonly string $profile;
    /** @var string[] Granted scopes. */
    public readonly array $scopes;
    /** @var string Resource (audience) the grant is bound to. */
    public readonly string $resource;
    /** @var string|null Assistant name declared by the gateway. Informative only. */
    public readonly ?string $actor;
    /** @var string|null Closed assistant identifier declared by the gateway. It can only tighten policy. */
    public readonly ?string $actorid;

    /**
     * Constructor.
     *
     * @param int $grantid Grant record id.
     * @param int $tokenid Access token record id.
     * @param int $userid Moodle user id.
     * @param int $clientid Client record id.
     * @param string $clienttype Client type.
     * @param string $profile Profile chosen at consent.
     * @param string[] $scopes Granted scopes.
     * @param string $resource Resource the grant is bound to.
     * @param string|null $actor Assistant name declared by the gateway.
     * @param string|null $actorid Closed assistant identifier declared by the gateway.
     */
    public function __construct(
        int $grantid,
        int $tokenid,
        int $userid,
        int $clientid,
        string $clienttype,
        string $profile,
        array $scopes,
        string $resource,
        ?string $actor = null,
        ?string $actorid = null
    ) {
        $this->grantid = $grantid;
        $this->tokenid = $tokenid;
        $this->userid = $userid;
        $this->clientid = $clientid;
        $this->clienttype = $clienttype;
        $this->profile = $profile;
        $this->scopes = array_values($scopes);
        $this->resource = $resource;
        $this->actor = $actor;
        $this->actorid = $actorid;
    }

    /**
     * Whether the grant includes a scope.
     *
     * @param string $scope Scope name.
     * @return bool
     */
    public function has_scope(string $scope): bool {
        return in_array($scope, $this->scopes, true);
    }
}
