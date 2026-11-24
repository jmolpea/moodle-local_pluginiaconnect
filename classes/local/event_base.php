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

namespace local_pluginiaconnect\local;

/**
 * Common behaviour of the events of the connector.
 *
 * Every event declares the closed list of keys its "other" data may carry.
 * Unknown keys are rejected, so that tokens, tool arguments or returned
 * content can never end up in the site log by mistake.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
abstract class event_base extends \core\event\base {
    /** @var string CRUD letter of the event. */
    protected const CRUD = 'r';
    /** @var string|null Table of the object the event is about. */
    protected const OBJECTTABLE = null;
    /** @var string[] Keys that "other" must contain. */
    protected const REQUIRED_OTHER = [];
    /** @var string[] Keys that "other" may contain in addition to the required ones. */
    protected const OPTIONAL_OTHER = [];
    /** @var bool Whether the event must name the affected user. */
    protected const NEEDS_RELATEDUSER = false;
    /** @var int Longest value accepted in "other". */
    private const MAX_VALUE_LENGTH = 255;

    /**
     * Initialises the static data of the event.
     *
     * @return void
     */
    protected function init() {
        $this->data['crud'] = static::CRUD;
        $this->data['edulevel'] = self::LEVEL_OTHER;
        if (static::OBJECTTABLE !== null) {
            $this->data['objecttable'] = static::OBJECTTABLE;
        }
    }

    /**
     * Localised event name.
     *
     * @return string
     */
    public static function get_name() {
        $parts = explode('\\', static::class);
        return get_string('event_' . end($parts), config::COMPONENT);
    }

    /**
     * Validates the event data against the declared shape.
     *
     * @return void
     */
    protected function validate_data() {
        parent::validate_data();
        if (static::OBJECTTABLE !== null && empty($this->objectid)) {
            throw new \coding_exception('The \'objectid\' must be set.');
        }
        if (static::NEEDS_RELATEDUSER && empty($this->relateduserid)) {
            throw new \coding_exception('The \'relateduserid\' must be set.');
        }
        $other = is_array($this->other) ? $this->other : [];
        foreach (static::REQUIRED_OTHER as $key) {
            if (!array_key_exists($key, $other)) {
                throw new \coding_exception('The \'' . $key . '\' value must be set in other.');
            }
        }
        $allowed = array_merge(static::REQUIRED_OTHER, static::OPTIONAL_OTHER);
        foreach ($other as $key => $value) {
            if (!in_array($key, $allowed, true)) {
                throw new \coding_exception('The \'' . $key . '\' value is not allowed in other.');
            }
            if ($value !== null && !is_scalar($value)) {
                throw new \coding_exception('The \'' . $key . '\' value in other must be scalar.');
            }
            if (is_string($value) && \core_text::strlen($value) > self::MAX_VALUE_LENGTH) {
                throw new \coding_exception('The \'' . $key . '\' value in other is too long.');
            }
        }
    }

    /**
     * The object ids of these events are not restored from backups.
     *
     * @return array|int
     */
    public static function get_objectid_mapping() {
        if (static::OBJECTTABLE === null) {
            return self::NOT_MAPPED;
        }
        return ['db' => static::OBJECTTABLE, 'restore' => self::NOT_MAPPED];
    }

    /**
     * Nothing in "other" is mapped on restore.
     *
     * @return bool
     */
    public static function get_other_mapping() {
        return false;
    }

    /**
     * Value of "other" made safe for a description.
     *
     * @param string $key Key of "other".
     * @return string
     */
    protected function other_text(string $key): string {
        return s((string) ($this->other[$key] ?? ''));
    }
}
