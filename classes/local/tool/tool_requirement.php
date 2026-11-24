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

/**
 * What a tool needs before it may run. Checked by the call pipeline, not by the tool.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_requirement {
    /** @var string No approval step. */
    public const APPROVAL_NONE = 'none';
    /** @var string Two-step confirmation. */
    public const APPROVAL_CONFIRM = 'confirm';
    /** @var string Approval on a page of the site. */
    public const APPROVAL_APPROVAL = 'approval';
    /** @var array Strictness order of the approval modes. */
    public const APPROVAL_RANK = [self::APPROVAL_NONE => 0, self::APPROVAL_CONFIRM => 1, self::APPROVAL_APPROVAL => 2];

    /** @var string Minimum profile. */
    public readonly string $profile;
    /** @var string OAuth scope. */
    public readonly string $scope;
    /** @var string[] Moodle capabilities required in the resolved context. */
    public readonly array $capabilities;
    /** @var bool Whether the tool changes anything. */
    public readonly bool $write;
    /** @var string Minimum approval mode fixed by the catalogue. */
    public readonly string $approval;

    /**
     * Constructor.
     *
     * @param string $profile Minimum profile.
     * @param string $scope OAuth scope.
     * @param string[] $capabilities Moodle capabilities required in the resolved context.
     * @param bool $write Whether the tool changes anything.
     * @param string $approval Minimum approval mode.
     */
    public function __construct(
        string $profile,
        string $scope,
        array $capabilities = [],
        bool $write = false,
        string $approval = self::APPROVAL_NONE
    ) {
        $this->profile = $profile;
        $this->scope = $scope;
        $this->capabilities = $capabilities;
        $this->write = $write;
        $this->approval = $approval;
    }

    /**
     * The stricter of two approval modes. Unknown values count as the strictest.
     *
     * @param string $one Approval mode.
     * @param string $other Approval mode.
     * @return string
     */
    public static function stricter(string $one, string $other): string {
        $rankone = self::APPROVAL_RANK[$one] ?? 2;
        $rankother = self::APPROVAL_RANK[$other] ?? 2;
        return array_search(max($rankone, $rankother), self::APPROVAL_RANK, true);
    }
}
