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
 * Declaration file of the invented plugin local_pcctest, as db/pluginiaconnect.php of a Pluginia plugin.
 *
 * @package    local_pluginiaconnect
 * @copyright  2026 RSMAX Consulting S.L. (Pluginia)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

$extension = [
    'contract' => 1,
    'catalog' => 2026112500,
    'tools' => [
        'get_pcctest_summary' => \local_pcctest\pluginiaconnect\good_read::class,
        'get_other_summary' => \local_pcctest\pluginiaconnect\no_infix::class,
        'delete_pcctest_items' => \local_pcctest\pluginiaconnect\forbidden_verb::class,
        'get_pcctest_prompt' => \local_pcctest\pluginiaconnect\sensitive_output::class,
        'get_pcctest_people' => \local_pcctest\pluginiaconnect\wrong_scope::class,
        'update_pcctest_settings' => \local_pcctest\pluginiaconnect\bad_write::class,
        'get_pcctest_raw' => \local_pcctest\pluginiaconnect\not_an_extension::class,
        'get_pcctest_outside' => \local_pluginiaconnect\local\tool\read\list_my_courses::class,
        'get_pcctest_missing' => \local_pcctest\pluginiaconnect\does_not_exist::class,
        'get_pcctest_renamed' => \local_pcctest\pluginiaconnect\good_read::class,
        'list_my_courses' => \local_pcctest\pluginiaconnect\good_read::class,
    ],
];
