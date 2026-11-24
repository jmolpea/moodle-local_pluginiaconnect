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
 * Unfiltered outcome of a tool. The output filter decides what leaves the site.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class tool_result {
    /** @var array Result data. */
    public array $data;
    /** @var int|null Number of items returned, for the audit trail. */
    public ?int $itemcount;
    /** @var string|null Opaque cursor of the next page. */
    public ?string $nextcursor;
    /** @var string|null Type of the main object affected, for the audit trail. */
    public ?string $objecttype;
    /** @var int|null Id of the main object affected, for the audit trail. */
    public ?int $objectid;
    /** @var int Number of records about people other than the caller that the result contains. */
    public int $thirdparty = 0;

    /**
     * Constructor.
     *
     * @param array $data Result data.
     * @param int|null $itemcount Number of items returned.
     * @param string|null $nextcursor Cursor of the next page.
     * @param string|null $objecttype Type of the main object.
     * @param int|null $objectid Id of the main object.
     */
    public function __construct(
        array $data,
        ?int $itemcount = null,
        ?string $nextcursor = null,
        ?string $objecttype = null,
        ?int $objectid = null
    ) {
        $this->data = $data;
        $this->itemcount = $itemcount;
        $this->nextcursor = $nextcursor;
        $this->objecttype = $objecttype;
        $this->objectid = $objectid;
    }
}
