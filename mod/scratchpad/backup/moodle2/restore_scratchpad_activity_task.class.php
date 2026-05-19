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
 * Restore function
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/mod/scratchpad/backup/moodle2/restore_scratchpad_stepslib.php');

class restore_scratchpad_activity_task extends restore_activity_task {

    protected function define_my_settings() {
    }

    protected function define_my_steps() {
        $this->add_step(new restore_scratchpad_activity_structure_step('scratchpad_structure', 'scratchpad.xml'));
    }

    static public function define_decode_contents() {

        $contents = array();
        $contents[] = new restore_decode_content('scratchpad', array('intro'), 'scratchpad');
        $contents[] = new restore_decode_content('scratchpad_entries', array('text', 'entrycomment'), 'scratchpad_entry');

        return $contents;
    }

    static public function define_decode_rules() {

        $rules = array();
        $rules[] = new restore_decode_rule('SCRATCHPADINDEX', '/mod/scratchpad/index.php?id=$1', 'course');
        $rules[] = new restore_decode_rule('SCRATCHPADVIEWBYID', '/mod/scratchpad/view.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('SCRATCHPADREPORT', '/mod/scratchpad/report.php?id=$1', 'course_module');
        $rules[] = new restore_decode_rule('SCRATCHPADEDIT', '/mod/scratchpad/edit.php?id=$1', 'course_module');

        return $rules;

    }

    public static function define_restore_log_rules() {

        $rules = array();
        $rules[] = new restore_log_rule('scratchpad', 'view', 'view.php?id={course_module}', '{scratchpad}');
        $rules[] = new restore_log_rule('scratchpad', 'view responses', 'report.php?id={course_module}', '{scratchpad}');
        $rules[] = new restore_log_rule('scratchpad', 'add entry', 'edit.php?id={course_module}', '{scratchpad}');
        $rules[] = new restore_log_rule('scratchpad', 'update entry', 'edit.php?id={course_module}', '{scratchpad}');
        $rules[] = new restore_log_rule('scratchpad', 'update feedback', 'report.php?id={course_module}', '{scratchpad}');

        return $rules;
    }

    public static function define_restore_log_rules_for_course() {

        $rules = array();
        $rules[] = new restore_log_rule('scratchpad', 'view all', 'index.php?id={course}', null);

        return $rules;
    }
}
