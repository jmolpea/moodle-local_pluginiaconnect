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

namespace local_pluginiaconnect\local\mcp;

/**
 * Outcome of the call pipeline.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class pipeline_result {
    /** @var array Structured content of the result. */
    public readonly array $envelope;
    /** @var bool Whether it is a tool error. */
    public readonly bool $iserror;
    /** @var bool Whether the tool is not in the catalogue (a protocol error, not a tool error). */
    public readonly bool $unknowntool;
    /** @var string|null Scope the token lacks, when the call was refused for it. */
    public readonly ?string $missingscope;

    /**
     * Constructor.
     *
     * @param array $envelope Structured content of the result.
     * @param bool $iserror Whether it is a tool error.
     * @param bool $unknowntool Whether the tool is not in the catalogue.
     * @param string|null $missingscope Scope the token lacks.
     */
    public function __construct(array $envelope, bool $iserror, bool $unknowntool = false, ?string $missingscope = null) {
        $this->envelope = $envelope;
        $this->iserror = $iserror;
        $this->unknowntool = $unknowntool;
        $this->missingscope = $missingscope;
    }

    /**
     * Error code of a tool error, or null.
     *
     * @return string|null
     */
    public function error_code(): ?string {
        return $this->envelope['error']['code'] ?? null;
    }
}
