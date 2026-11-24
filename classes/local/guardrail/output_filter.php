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

namespace local_pluginiaconnect\local\guardrail;

use local_pluginiaconnect\local\config;

/**
 * Last step before data leaves the site: closed field list and size limits.
 *
 * A field that the tool did not declare for the profile is removed even if the
 * tool, or the core function behind it, returned it.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
final class output_filter {
    /**
     * Applies the closed field list of a tool and the size limits.
     *
     * @param array $data Unfiltered data of the tool.
     * @param array $fields Field specification returned by tool::output_fields().
     * @param int|null $maxchars Maximum size of the JSON of the data; null for the site setting.
     * @return array [filtered data, whether anything was cut]
     */
    public static function apply(array $data, array $fields, ?int $maxchars = null): array {
        $truncated = false;
        $filtered = self::filter($data, $fields, $truncated);
        $filtered = is_array($filtered) ? $filtered : [];
        $maxchars = $maxchars ?? config::int('maxresponsechars');
        while (self::size($filtered) > $maxchars) {
            if (!self::drop_last_item($filtered)) {
                // Nothing left to drop: return no data rather than exceed the limit.
                $filtered = [];
                $truncated = true;
                break;
            }
            $truncated = true;
        }
        return [$filtered, $truncated];
    }

    /**
     * Keeps only declared fields, recursively.
     *
     * @param mixed $data Value to filter.
     * @param array $spec Field specification at this level.
     * @param bool $truncated Set to true when a text was cut.
     * @return mixed
     */
    private static function filter($data, array $spec, bool &$truncated) {
        if (!is_array($data)) {
            return null;
        }
        if (array_key_exists('*', $spec)) {
            $items = [];
            foreach (array_values($data) as $item) {
                $clean = self::filter($item, $spec['*'], $truncated);
                if (is_array($clean)) {
                    $items[] = $clean;
                }
            }
            return $items;
        }
        $out = [];
        foreach ($spec as $key => $value) {
            if (is_int($key)) {
                // Scalar field, or a list of scalars.
                if (!array_key_exists($value, $data)) {
                    continue;
                }
                $out[$value] = self::scalar($data[$value], $truncated);
            } else if (array_key_exists($key, $data)) {
                $child = self::filter($data[$key], $value, $truncated);
                $out[$key] = $child ?? (array_key_exists('*', $value) ? [] : null);
            }
        }
        return $out;
    }

    /**
     * Passes a scalar or a list of scalars; anything else becomes null.
     *
     * @param mixed $value Value.
     * @param bool $truncated Set to true when a text was cut.
     * @return mixed
     */
    private static function scalar($value, bool &$truncated) {
        if (is_string($value)) {
            $clean = text_sanitizer::strip_invisible_characters(fix_utf8($value));
            $cut = text_sanitizer::truncate($clean);
            $truncated = $truncated || $cut !== $clean;
            return $cut;
        }
        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }
        if (is_array($value) && array_is_list($value)) {
            $list = [];
            foreach ($value as $item) {
                if (is_scalar($item)) {
                    $list[] = is_string($item) ? self::scalar($item, $truncated) : $item;
                }
            }
            return $list;
        }
        return null;
    }

    /**
     * Size of the data once encoded.
     *
     * @param array $data Data.
     * @return int Characters.
     */
    private static function size(array $data): int {
        return \core_text::strlen((string) json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    }

    /**
     * Removes the last item of the longest list found in the data.
     *
     * @param array $data Data, modified in place.
     * @return bool False when there is no list item left to remove.
     */
    private static function drop_last_item(array &$data): bool {
        $best = null;
        $bestcount = 0;
        self::longest_list($data, [], $best, $bestcount);
        if ($best === null) {
            return false;
        }
        $target = &$data;
        foreach ($best as $key) {
            $target = &$target[$key];
        }
        array_pop($target);
        return true;
    }

    /**
     * Finds the path to the longest list nested in the data.
     *
     * @param array $data Data to search.
     * @param array $path Keys leading to $data.
     * @param array|null $best Path of the longest list found so far.
     * @param int $bestcount Length of that list.
     * @return void
     */
    private static function longest_list(array $data, array $path, ?array &$best, int &$bestcount): void {
        if ($data !== [] && array_is_list($data) && count($data) > $bestcount) {
            $best = $path;
            $bestcount = count($data);
        }
        foreach ($data as $key => $value) {
            if (is_array($value)) {
                self::longest_list($value, array_merge($path, [$key]), $best, $bestcount);
            }
        }
    }
}
