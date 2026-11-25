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

namespace local_pluginiaconnect\extension;

use local_pluginiaconnect\local\tool\call_context;
use local_pluginiaconnect\local\tool\tool_exception;
use local_pluginiaconnect\local\tool\tool_result;

/**
 * Base class of the write tools that a whitelisted Pluginia plugin declares (amendment 2, section 10B).
 *
 * Part of the stable contract of the extension point, version 1. A write extension never runs on its
 * first call: the connector asks it for a preview and applies it after a confirmation token or the
 * user's approval on the site, like its own write tools. Its input schema only takes enumerations,
 * booleans, bounded integers and dates; the loader refuses anything else. It never deletes, never acts
 * as another user and never calls the AI the plugin is configured with (D24, D26, D28).
 *
 * No write extension is in the catalogue yet: the first ones need an approved batch (10B.8).
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class write_tool extends \local_pluginiaconnect\local\tool\write_tool {
    /**
     * Applies the change: the plugin's availability check first, then {@see self::run()}.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    final public function execute(array $args, call_context $call): tool_result {
        $problem = static::unavailable_reason();
        if ($problem !== null) {
            throw new tool_exception('tool_disabled', $problem);
        }
        return $this->run($args, $call);
    }

    /**
     * The change itself, as the current user.
     *
     * @param array $args Validated arguments.
     * @param call_context $call Call data.
     * @return tool_result
     */
    abstract protected function run(array $args, call_context $call): tool_result;

    /**
     * Why the plugin cannot act right now, or null.
     *
     * @return string|null
     */
    protected static function unavailable_reason(): ?string {
        return null;
    }
}
