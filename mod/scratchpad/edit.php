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
 * Edit page
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

require_once("../../config.php");
require_once('./edit_form.php');
require_once($CFG->dirroot.'/lib/completionlib.php');
$id = required_param('id', PARAM_INT);    // Course Module ID.

if (!$cm = get_coursemodule_from_id('scratchpad', $id)) {
    print_error("Course Module ID was incorrect");
}

if (!$course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error("Course is misconfigured");
}

$context = context_module::instance($cm->id);

require_login($course, false, $cm);

require_capability('mod/scratchpad:addentries', $context);

if (! $scratchpad = $DB->get_record("scratchpad", array("id" => $cm->instance))) {
    print_error("Course module is incorrect");
}
if (!empty($scratchpad->preventry)){
    $prev_scratchpad = $DB->get_record("scratchpad", array("id" => $scratchpad->preventry));
    $prev_entry = $DB->get_record("scratchpad_entries", array("userid" => $USER->id, "scratchpad" => $scratchpad->preventry));
}
// Header.
$PAGE->set_url('/mod/scratchpad/edit.php', array('id' => $id));
$PAGE->navbar->add(get_string('edit'));
$PAGE->set_title(format_string($scratchpad->name));
$PAGE->set_heading($course->fullname);

$data = new stdClass();

$entry = $DB->get_record("scratchpad_entries", array("userid" => $USER->id, "scratchpad" => $scratchpad->id));
if ($entry) {
    $data->entryid = $entry->id;
    $data->text = $entry->text;
    $data->textformat = $entry->format;
} else {
    $data->entryid = null;
    $data->text = '';
    $data->textformat = FORMAT_HTML;
}

$data->id = $cm->id;

$editoroptions = array(
    'maxfiles' => EDITOR_UNLIMITED_FILES,
    'context' => $context,
    'subdirs' => false,
    'enable_filemanagement' => true
);

#$data = file_prepare_standard_editor($data, 'text', $editoroptions, $context, 'mod_scratchpad', 'entry', $data->entryid);

$form = new mod_scratchpad_entry_form(null, array('entryid' => $data->entryid, 'text_editor' => $data->text));
#$form->set_data($data);
if ($form->is_cancelled()) {
    redirect($CFG->wwwroot . '/mod/scratchpad/view.php?id=' . $cm->id);
} else if ($fromform = $form->get_data()) {
    // If data submitted, then process and store.

    // Prevent CSFR.
    confirm_sesskey();
    $timenow = time();

    // This will be overwriten after being we have the entryid.
    $newentry = new stdClass();
    $newentry->text = $fromform->text_editor;
    $newentry->format = FORMAT_HTML;
    $newentry->modified = $timenow;

    if ($entry) {
        $newentry->id = $entry->id;
        if (!$DB->update_record("scratchpad_entries", $newentry)) {
            print_error("Could not update your scratchpad");
        }
    } else {
        $newentry->userid = $USER->id;
        $newentry->scratchpad = $scratchpad->id;
        if (!$newentry->id = $DB->insert_record("scratchpad_entries", $newentry)) {
            print_error("Could not insert a new scratchpad entry");
        }
    }

    // Update completion state.
    $completion = new completion_info($course);
    if ($completion->is_enabled($cm) && $scratchpad->completionanswer) {
        $completion->update_state($cm, COMPLETION_COMPLETE);
    }

    // Relink using the proper entryid.
    // We need to do this as draft area didn't have an itemid associated when creating the entry.
    // $fromform = file_postupdate_standard_editor($fromform, 'text', $editoroptions,
    //     $editoroptions['context'], 'mod_scratchpad', 'entry', $newentry->id);
    $newentry->text = $fromform->text_editor;
    $newentry->format = FORMAT_HTML;

    $DB->update_record('scratchpad_entries', $newentry);

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

    redirect(new moodle_url('/mod/scratchpad/view.php?id='.$cm->id));
    die;
} else{
    $form->set_data(['text_editor' => $data->text, 'id' => $data->id]);
}


echo $OUTPUT->header();
echo $OUTPUT->heading(format_string($scratchpad->name));

if(!empty($prev_scratchpad)){
    $prev_intro = format_module_intro('scratchpad', $prev_scratchpad, $cm->id);
}
if (!empty($prev_intro)){
    echo '<table border="2" width="99%"><tr><td>';
    echo $OUTPUT->box($prev_intro);
    
    if (!empty($prev_entry->text)){
        echo $OUTPUT->box($prev_entry->text);
    }
    echo '</td></tr></table>';
}

$intro = format_module_intro('scratchpad', $scratchpad, $cm->id);
echo $OUTPUT->box($intro);

// Otherwise fill and print the form.
$form->display();

echo $OUTPUT->footer();
