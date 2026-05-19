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
 * Upgrade script
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/scratchpad/lib.php');

function xmldb_scratchpad_upgrade($oldversion=0) {
    global $CFG, $DB;

    $dbman = $DB->get_manager();
    if ($oldversion < 2021113007) {
        #Cleanup for text
        $search = array("<br />", "<br>", "><", '&nbsp;');
        $replace  = array("\n", "\n", ">\n<", ' ');

        $rs = $DB->get_recordset_sql('SELECT id, text FROM {scratchpad_entries}', []);
        foreach ($rs as $record){
            $newline = str_replace($search, $replace, $record->text);
            $newline = strip_tags(html_entity_decode($newline));
            $record->text = $newline;

            $DB->update_record('scratchpad_entries', $record);
        }
        $rs->close();
    }
    return true;
}
