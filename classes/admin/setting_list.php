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

namespace local_pluginiaconnect\admin;

use local_pluginiaconnect\local\config;

defined('MOODLE_INTERNAL') || die();

global $CFG;
require_once($CFG->libdir . '/adminlib.php');

/**
 * Setting that holds a list, one entry per line, with every entry validated.
 *
 * Supported kinds: numeric identifiers, exact https redirect addresses, host
 * names and assistant identifiers. Entries that must always be present can be
 * given; they are added back on save if the administrator removed them.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class setting_list extends \admin_setting_configtextarea {
    /** @var string List of numeric identifiers. */
    public const KIND_IDS = 'ids';
    /** @var string List of exact https redirect addresses. */
    public const KIND_REDIRECTS = 'redirects';
    /** @var string List of host names. */
    public const KIND_HOSTS = 'hosts';
    /** @var string List of assistant identifiers. */
    public const KIND_ACTORS = 'actors';

    /** @var string Kind of entries. */
    private string $kind;
    /** @var string[] Entries that cannot be removed. */
    private array $mandatory;

    /**
     * Constructor.
     *
     * @param string $shortname Setting name without the component prefix.
     * @param string $visiblename Localised name.
     * @param string $description Localised description.
     * @param string $kind One of the KIND_ constants.
     * @param string[] $mandatory Entries that are always kept.
     */
    public function __construct(
        string $shortname,
        string $visiblename,
        string $description,
        string $kind,
        array $mandatory = []
    ) {
        $this->kind = $kind;
        $this->mandatory = $mandatory;
        parent::__construct(
            config::COMPONENT . '/' . $shortname,
            $visiblename,
            $description,
            config::DEFAULTS[$shortname],
            PARAM_RAW,
            60,
            5
        );
    }

    /**
     * Splits a submitted value into trimmed, non-empty, unique entries.
     *
     * @param string $data Submitted value.
     * @return string[]
     */
    public static function split(string $data): array {
        $parts = preg_split('/[\r\n,]+/', $data);
        $parts = array_filter(array_map('trim', $parts), static fn($part) => $part !== '');
        return array_values(array_unique($parts));
    }

    /**
     * Whether one entry is valid for a kind of list.
     *
     * @param string $kind One of the KIND_ constants.
     * @param string $entry Entry to check.
     * @return bool
     */
    public static function is_valid_entry(string $kind, string $entry): bool {
        switch ($kind) {
            case self::KIND_IDS:
                return ctype_digit($entry) && (int) $entry > 0;
            case self::KIND_REDIRECTS:
                $parts = parse_url($entry);
                return is_array($parts) && ($parts['scheme'] ?? '') === 'https' && !empty($parts['host'])
                    && !isset($parts['fragment']) && !isset($parts['user']) && !isset($parts['pass'])
                    && !str_contains($entry, '*') && !preg_match('/\s/', $entry)
                    && !\local_pluginiaconnect\local\oauth\redirect_policy::is_shared_redirect($entry);
            case self::KIND_HOSTS:
                return (bool) preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $entry);
            case self::KIND_ACTORS:
                return (bool) preg_match('/^[a-z0-9][a-z0-9-]{0,29}$/', $entry);
            default:
                return false;
        }
    }

    /**
     * Validates every entry of the list.
     *
     * @param string $data Submitted value.
     * @return bool|string True when valid, otherwise the error message.
     */
    public function validate($data) {
        foreach (self::split((string) $data) as $entry) {
            if (!self::is_valid_entry($this->kind, $entry)) {
                return get_string('error_listentry', config::COMPONENT, s($entry));
            }
        }
        return true;
    }

    /**
     * Stores the list in a normal form: one entry per line, mandatory entries included.
     *
     * @param string $data Submitted value.
     * @return string Empty string when saved, otherwise the error message.
     */
    public function write_setting($data) {
        $valid = $this->validate($data);
        if ($valid !== true) {
            return $valid;
        }
        $entries = self::split((string) $data);
        foreach ($this->mandatory as $entry) {
            if (!in_array($entry, $entries, true)) {
                array_unshift($entries, $entry);
            }
        }
        return $this->config_write($this->name, implode("\n", $entries)) ? '' : get_string('errorsetting', 'admin');
    }
}
