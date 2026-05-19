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
 * External Library
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

defined('MOODLE_INTERNAL') || die();

require_once("$CFG->libdir/externallib.php");

class mod_scratchpad_external extends external_api {

    public static function get_entry_parameters() {
        return new external_function_parameters(
            array(
                'scratchpadid' => new external_value(PARAM_INT, 'id of scratchpad')
            )
        );
    }

    public static function get_entry_returns() {
        return new external_single_structure(
            array(
                'text' => new external_value(PARAM_RAW, 'scratchpad text'),
                'modified' => new external_value(PARAM_INT, 'last modified time'),
                'rating' => new external_value(PARAM_FLOAT, 'teacher rating'),
                'comment' => new external_value(PARAM_RAW, 'teacher comment'),
                'teacher' => new external_value(PARAM_INT, 'id of teacher')
            )
        );
    }

    public static function get_entry($scratchpadid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::get_entry_parameters(), array('scratchpadid' => $scratchpadid));

        if (! $cm = get_coursemodule_from_id('scratchpad', $params['scratchpadid'])) {
            throw new invalid_parameter_exception('Course Module ID was incorrect');
        }

        if (! $course = $DB->get_record("course", array('id' => $cm->course))) {
            throw new invalid_parameter_exception("Course is misconfigured");
        }

        if (! $scratchpad = $DB->get_record("scratchpad", array("id" => $cm->instance))) {
            throw new invalid_parameter_exception("Course module is incorrect");
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);;
        require_capability('mod/scratchpad:addentries', $context);

        if ($entry = $DB->get_record('scratchpad_entries', array('userid' => $USER->id, 'scratchpad' => $scratchpad->id))) {
            return array(
                'text' => $entry->text,
                'modified' => $entry->modified,
                'rating' => $entry->rating,
                'comment' => $entry->entrycomment,
                'teacher' => $entry->teacher
            );
        } else {
            return "";
        }
    }


    public static function set_text_parameters() {
        return new external_function_parameters(
            array(
                'scratchpadid' => new external_value(PARAM_INT, 'id of scratchpad'),
                'text' => new external_value(PARAM_RAW, 'text to set'),
                'format' => new external_value(PARAM_INT, 'format of text')
            )
        );
    }

    public static function set_text_returns() {
        return new external_value(PARAM_RAW, 'new text');
    }

    public static function set_text($scratchpadid, $text, $format) {
        global $DB, $USER;

        $params = self::validate_parameters(
            self::set_text_parameters(),
            array('scratchpadid' => $scratchpadid, 'text' => $text, 'format' => $format)
        );

        if (! $cm = get_coursemodule_from_id('scratchpad', $params['scratchpadid'])) {
            throw new invalid_parameter_exception('Course Module ID was incorrect');
        }

        if (! $course = $DB->get_record("course", array('id' => $cm->course))) {
            throw new invalid_parameter_exception("Course is misconfigured");
        }

        if (! $scratchpad = $DB->get_record("scratchpad", array("id" => $cm->instance))) {
            throw new invalid_parameter_exception("Course module is incorrect");
        }

        $context = context_module::instance($cm->id);
        self::validate_context($context);;
        require_capability('mod/scratchpad:addentries', $context);

        $entry = $DB->get_record('scratchpad_entries', array('userid' => $USER->id, 'scratchpad' => $scratchpad->id));

        $timenow = time();
        $newentry = new stdClass();
        $newentry->text = $params['text'];
        $newentry->format = $params['format'];
        $newentry->modified = $timenow;

        if ($entry) {
            $newentry->id = $entry->id;
            $DB->update_record("scratchpad_entries", $newentry);
        } else {
            $newentry->userid = $USER->id;
            $newentry->scratchpad = $scratchpad->id;
            $newentry->id = $DB->insert_record("scratchpad_entries", $newentry);
        }

        if ($entry) {
            // Trigger module entry updated event.
            $event = \mod_scratchpad\event\entry_updated::create(array(
                'objectid' => $scratchpad->id,
                'context' => $context
            ));
        } else {
            // Trigger module entry created event.
            $event = \mod_scratchpad\event\entry_created::create(array(
                'objectid' => $scratchpad->id,
                'context' => $context
            ));

        }
        $event->add_record_snapshot('course_modules', $cm);
        $event->add_record_snapshot('course', $course);
        $event->add_record_snapshot('scratchpad', $scratchpad);
        $event->trigger();

        return $newentry->text;
    }
}