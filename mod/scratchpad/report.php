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
 * Report view (disabled)
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/

require_once("../../config.php");
require_once("lib.php");


$id = required_param('id', PARAM_INT);   // Course module.

if (! $cm = get_coursemodule_from_id('scratchpad', $id)) {
    print_error("Course Module ID was incorrect");
}

if (! $course = $DB->get_record("course", array("id" => $cm->course))) {
    print_error("Course module is misconfigured");
}

require_login($course, false, $cm);

$context = context_module::instance($cm->id);

require_capability('mod/scratchpad:manageentries', $context);


if (! $scratchpad = $DB->get_record("scratchpad", array("id" => $cm->instance))) {
    print_error("Course module is incorrect");
}

// Header.
$PAGE->set_url('/mod/scratchpad/report.php', array('id' => $id));

$PAGE->navbar->add(get_string("entries", "scratchpad"));
$PAGE->set_title(get_string("modulenameplural", "scratchpad"));
$PAGE->set_heading($course->fullname);

echo $OUTPUT->header();
echo $OUTPUT->heading(get_string("entries", "scratchpad"));


// Make some easy ways to access the entries.
if ( $eee = $DB->get_records("scratchpad_entries", array("scratchpad" => $scratchpad->id))) {
    foreach ($eee as $ee) {
        $entrybyuser[$ee->userid] = $ee;
        $entrybyentry[$ee->id]  = $ee;
    }

} else {
    $entrybyuser  = array ();
    $entrybyentry = array ();
}

// Group mode.
$groupmode = groups_get_activity_groupmode($cm);
$currentgroup = groups_get_activity_group($cm, true);


// Process incoming data if there is any.
if ($data = data_submitted()) {

    confirm_sesskey();

    $feedback = array();
    $data = (array)$data;

    // Peel out all the data from variable names.
    foreach ($data as $key => $val) {
        if (strpos($key, 'r') === 0 || strpos($key, 'c') === 0) {
            $type = substr($key, 0, 1);
            $num  = substr($key, 1);
            if (strpos($key, 'r') === 0 && $val === '') {
                $feedback[$num][$type] = -1;
            } else {
                $feedback[$num][$type] = $val;
            }
        }
    }

    $timenow = time();
    $count = 0;
    foreach ($feedback as $num => $vals) {
        $entry = $entrybyentry[$num];
        // Only update entries where feedback has actually changed.
        $ratingchanged = false;

        $studentrating = clean_param($vals['r'], PARAM_INT);
        $studentcomment = clean_text($vals['c'], FORMAT_PLAIN);

        if ($studentrating != $entry->rating) {
            $ratingchanged = true;
        }

        if ($ratingchanged || $studentcomment != $entry->entrycomment) {
            $newentry = new StdClass();
            $newentry->rating       = $studentrating;
            $newentry->entrycomment = $studentcomment;
            $newentry->teacher      = $USER->id;
            $newentry->timemarked   = $timenow;
            $newentry->mailed       = 0;           // Make sure mail goes out (again, even).
            $newentry->id           = $num;
            if (!$DB->update_record("scratchpad_entries", $newentry)) {
                echo $OUTPUT->notification("Failed to update the scratchpad feedback for user $entry->userid");
            } else {
                $count++;
            }
            $entrybyuser[$entry->userid]->rating     = $studentrating;
            $entrybyuser[$entry->userid]->entrycomment    = $studentcomment;
            $entrybyuser[$entry->userid]->teacher    = $USER->id;
            $entrybyuser[$entry->userid]->timemarked = $timenow;

            $scratchpad = $DB->get_record("scratchpad", array("id" => $entrybyuser[$entry->userid]->scratchpad));
            $scratchpad->cmidnumber = $cm->idnumber;

            scratchpad_update_grades($scratchpad, $entry->userid);
        }
    }

    // Trigger module feedback updated event.
    $event = \mod_scratchpad\event\feedback_updated::create(array(
        'objectid' => $scratchpad->id,
        'context' => $context
    ));
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('scratchpad', $scratchpad);
    $event->trigger();

    echo $OUTPUT->notification(get_string("feedbackupdated", "scratchpad", "$count"), "notifysuccess");

} else {

    // Trigger module viewed event.
    $event = \mod_scratchpad\event\entries_viewed::create(array(
        'objectid' => $scratchpad->id,
        'context' => $context
    ));
    $event->add_record_snapshot('course_modules', $cm);
    $event->add_record_snapshot('course', $course);
    $event->add_record_snapshot('scratchpad', $scratchpad);
    $event->trigger();
}

// Print out the scratchpad entries.

if ($currentgroup) {
    $groups = $currentgroup;
} else {
    $groups = '';
}
$users = get_users_by_capability($context, 'mod/scratchpad:addentries', '', '', '', '', $groups);

if (!$users) {
    echo $OUTPUT->heading(get_string("nousersyet"));

} else {

    groups_print_activity_menu($cm, $CFG->wwwroot . "/mod/scratchpad/report.php?id=$cm->id");

    $grades = make_grades_menu($scratchpad->grade);
    if (!$teachers = get_users_by_capability($context, 'mod/scratchpad:manageentries')) {
        print_error('noentriesmanagers', 'scratchpad');
    }

    echo '<form action="report.php" method="post">';

    if ($usersdone = scratchpad_get_users_done($scratchpad, $currentgroup)) {
        foreach ($usersdone as $user) {
            scratchpad_print_user_entry($course, $user, $entrybyuser[$user->id], $teachers, $grades);
            unset($users[$user->id]);
        }
    }

    foreach ($users as $user) {       // Remaining users.
        scratchpad_print_user_entry($course, $user, null, $teachers, $grades);
    }

    echo "<p class=\"feedbacksave\">";
    echo "<input type=\"hidden\" name=\"id\" value=\"$cm->id\" />";
    echo "<input type=\"hidden\" name=\"sesskey\" value=\"" . sesskey() . "\" />";
    echo "<input type=\"submit\" value=\"".get_string("saveallfeedback", "scratchpad")."\" class=\"btn btn-secondary m-t-1\"/>";
    echo "</p>";
    echo "</form>";
}

echo $OUTPUT->footer();
