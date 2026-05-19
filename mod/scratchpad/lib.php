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
 * Library plugin functions
 *
 * @package mod_scratchpad
 * @copyright  1999 onwards Martin Dougiamas  {@link http://moodle.com}
 * @copyright  2021 Tengku Alauddin - din@pukunui.net
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 **/


defined('MOODLE_INTERNAL') || die();


/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod.html) this function
 * will create a new instance and return the id number
 * of the new instance.
 * @param object $scratchpad Object containing required scratchpad properties
 * @return int Scratchpad ID
 */
function scratchpad_add_instance($scratchpad) {
    global $DB;

    $scratchpad->timemodified = time();
    $scratchpad->id = $DB->insert_record("scratchpad", $scratchpad);

    scratchpad_grade_item_update($scratchpad);

    return $scratchpad->id;
}

/**
 * Given an object containing all the necessary data,
 * (defined by the form in mod.html) this function
 * will update an existing instance with new data.
 * @param object $scratchpad Object containing required scratchpad properties
 * @return boolean True if successful
 */
function scratchpad_update_instance($scratchpad) {
    global $DB;

    $scratchpad->timemodified = time();
    $scratchpad->id = $scratchpad->instance;

    $result = $DB->update_record("scratchpad", $scratchpad);

    scratchpad_grade_item_update($scratchpad);

    scratchpad_update_grades($scratchpad, 0, false);

    return $result;
}

/**
 * Given an ID of an instance of this module,
 * this function will permanently delete the instance
 * nd any data that depends on it.
 * @param int $id Scratchpad ID
 * @return boolean True if successful
 */
function scratchpad_delete_instance($id) {
    global $DB;

    $result = true;

    if (! $scratchpad = $DB->get_record("scratchpad", array("id" => $id))) {
        return false;
    }

    if (! $DB->delete_records("scratchpad_entries", array("scratchpad" => $scratchpad->id))) {
        $result = false;
    }

    if (! $DB->delete_records("scratchpad", array("id" => $scratchpad->id))) {
        $result = false;
    }

    return $result;
}


function scratchpad_supports($feature) {
    switch($feature) {
        case FEATURE_MOD_INTRO:
            return true;
        case FEATURE_GRADE_HAS_GRADE:
            return false;
        case FEATURE_GRADE_OUTCOMES:
            return false;
        case FEATURE_RATE:
            return false;
        case FEATURE_GROUPS:
            return true;
        case FEATURE_GROUPINGS:
            return true;
        case FEATURE_GROUPMEMBERSONLY:
            return true;
        case FEATURE_COMPLETION_TRACKS_VIEWS:
            return true;
        case FEATURE_BACKUP_MOODLE2:
            return true;
        case FEATURE_COMPLETION_HAS_RULES:
            return true;
        default:
            return null;
    }
}


function scratchpad_get_view_actions() {
    return array('view', 'view all', 'view responses');
}


function scratchpad_get_post_actions() {
    return array('add entry', 'update entry', 'update feedback');
}


function scratchpad_user_outline($course, $user, $mod, $scratchpad) {

    global $DB;

    if ($entry = $DB->get_record("scratchpad_entries", array("userid" => $user->id, "scratchpad" => $scratchpad->id))) {

        $numwords = count(preg_split("/\w\b/", $entry->text)) - 1;

        $result = new stdClass();
        $result->info = get_string("numwords", "", $numwords);
        $result->time = $entry->modified;
        return $result;
    }
    return null;
}


function scratchpad_user_complete($course, $user, $mod, $scratchpad) {

    global $DB, $OUTPUT;

    if ($entry = $DB->get_record("scratchpad_entries", array("userid" => $user->id, "scratchpad" => $scratchpad->id))) {

        echo $OUTPUT->box_start();

        if ($entry->modified) {
            echo "<p><font size=\"1\">".get_string("lastedited").": ".userdate($entry->modified)."</font></p>";
        }
        if ($entry->text) {
            echo scratchpad_format_entry_text($entry, $course, $mod);
        }
        if ($entry->teacher) {
            $grades = make_grades_menu($scratchpad->grade);
            scratchpad_print_feedback($course, $entry, $grades);
        }

        echo $OUTPUT->box_end();

    } else {
        print_string("noentry", "scratchpad");
    }
}

/**
 * Function to be run periodically according to the moodle cron.
 * Finds all scratchpad notifications that have yet to be mailed out, and mails them.
 */
function scratchpad_cron () {
    global $CFG, $USER, $DB;
    require_once($CFG->libdir.'/completionlib.php');
    
    $cutofftime = time() - $CFG->maxeditingtime;

    if ($entries = scratchpad_get_unmailed_graded($cutofftime)) {
        $timenow = time();

        $usernamefields = get_all_user_name_fields();
        $requireduserfields = 'id, auth, mnethostid, email, mailformat, maildisplay, lang, deleted, suspended, '
                .implode(', ', $usernamefields);

        // To save some db queries.
        $users = array();
        $courses = array();

        foreach ($entries as $entry) {

            echo "Processing scratchpad entry $entry->id\n";

            if (!empty($users[$entry->userid])) {
                $user = $users[$entry->userid];
            } else {
                if (!$user = $DB->get_record("user", array("id" => $entry->userid), $requireduserfields)) {
                    echo "Could not find user $entry->userid\n";
                    continue;
                }
                $users[$entry->userid] = $user;
            }

            $USER->lang = $user->lang;

            if (!empty($courses[$entry->course])) {
                $course = $courses[$entry->course];
            } else {
                if (!$course = $DB->get_record('course', array('id' => $entry->course), 'id, shortname')) {
                    echo "Could not find course $entry->course\n";
                    continue;
                }
                $courses[$entry->course] = $course;
            }

            if (!empty($users[$entry->teacher])) {
                $teacher = $users[$entry->teacher];
            } else {
                if (!$teacher = $DB->get_record("user", array("id" => $entry->teacher), $requireduserfields)) {
                    echo "Could not find teacher $entry->teacher\n";
                    continue;
                }
                $users[$entry->teacher] = $teacher;
            }

            // All cached.
            $coursescratchpads = get_fast_modinfo($course)->get_instances_of('scratchpad');
            if (empty($coursescratchpads) || empty($coursescratchpads[$entry->scratchpad])) {
                echo "Could not find course module for scratchpad id $entry->scratchpad\n";
                continue;
            }
            $mod = $coursescratchpads[$entry->scratchpad];

            // This is already cached internally.
            $context = context_module::instance($mod->id);
            $canadd = has_capability('mod/scratchpad:addentries', $context, $user);
            $entriesmanager = has_capability('mod/scratchpad:manageentries', $context, $user);

            if (!$canadd and $entriesmanager) {
                continue;  // Not an active participant.
            }

            $scratchpadinfo = new stdClass();
            $scratchpadinfo->teacher = fullname($teacher);
            $scratchpadinfo->scratchpad = format_string($entry->name, true);
            $scratchpadinfo->url = "$CFG->wwwroot/mod/scratchpad/view.php?id=$mod->id";
            $modnamepl = get_string( 'modulenameplural', 'scratchpad' );
            $msubject = get_string( 'mailsubject', 'scratchpad' );

            $postsubject = "$course->shortname: $msubject: ".format_string($entry->name, true);
            $posttext  = "$course->shortname -> $modnamepl -> ".format_string($entry->name, true)."\n";
            $posttext .= "---------------------------------------------------------------------\n";
            $posttext .= get_string("scratchpadmail", "scratchpad", $scratchpadinfo)."\n";
            $posttext .= "---------------------------------------------------------------------\n";
            if ($user->mailformat == 1) {  // HTML.
                $posthtml = "<p><font face=\"sans-serif\">".
                "<a href=\"$CFG->wwwroot/course/view.php?id=$course->id\">$course->shortname</a> ->".
                "<a href=\"$CFG->wwwroot/mod/scratchpad/index.php?id=$course->id\">scratchpads</a> ->".
                "<a href=\"$CFG->wwwroot/mod/scratchpad/view.php?id=$mod->id\">".format_string($entry->name, true)."</a></font></p>";
                $posthtml .= "<hr /><font face=\"sans-serif\">";
                $posthtml .= "<p>".get_string("scratchpadmailhtml", "scratchpad", $scratchpadinfo)."</p>";
                $posthtml .= "</font><hr />";
            } else {
                $posthtml = "";
            }

            if (! email_to_user($user, $teacher, $postsubject, $posttext, $posthtml)) {
                echo "Error: Scratchpad cron: Could not send out mail for id $entry->id to user $user->id ($user->email)\n";
            }
            if (!$DB->set_field("scratchpad_entries", "mailed", "1", array("id" => $entry->id))) {
                echo "Could not update the mailed field for id $entry->id\n";
            }
        }
    }

    return true;
}

/**
 * Given a course and a time, this module should find recent activity
 * that has occurred in scratchpad activities and print it out.
 * Return true if there was output, or false if there was none.
 *
 * @global stdClass $DB
 * @global stdClass $OUTPUT
 * @param stdClass $course
 * @param bool $viewfullnames
 * @param int $timestart
 * @return bool
 */
function scratchpad_print_recent_activity($course, $viewfullnames, $timestart) {
    global $CFG, $USER, $DB, $OUTPUT;

    if (!get_config('scratchpad', 'showrecentactivity')) {
        return false;
    }

    $dbparams = array($timestart, $course->id, 'scratchpad');
    $namefields = user_picture::fields('u', null, 'userid');
    $sql = "SELECT je.id, je.modified, cm.id AS cmid, $namefields
         FROM {scratchpad_entries} je
              JOIN {scratchpad} j         ON j.id = je.scratchpad
              JOIN {course_modules} cm ON cm.instance = j.id
              JOIN {modules} md        ON md.id = cm.module
              JOIN {user} u            ON u.id = je.userid
         WHERE je.modified > ? AND
               j.course = ? AND
               md.name = ?
         ORDER BY je.modified ASC
    ";

    $newentries = $DB->get_records_sql($sql, $dbparams);

    $modinfo = get_fast_modinfo($course);
    $show    = array();

    foreach ($newentries as $anentry) {

        if (!array_key_exists($anentry->cmid, $modinfo->get_cms())) {
            continue;
        }
        $cm = $modinfo->get_cm($anentry->cmid);

        if (!$cm->uservisible) {
            continue;
        }
        if ($anentry->userid == $USER->id) {
            $show[] = $anentry;
            continue;
        }
        $context = context_module::instance($anentry->cmid);

        // Only teachers can see other students entries.
        if (!has_capability('mod/scratchpad:manageentries', $context)) {
            continue;
        }

        $groupmode = groups_get_activity_groupmode($cm, $course);

        if ($groupmode == SEPARATEGROUPS &&
                !has_capability('moodle/site:accessallgroups',  $context)) {
            if (isguestuser()) {
                // Shortcut - guest user does not belong into any group.
                continue;
            }

            // This will be slow - show only users that share group with me in this cm.
            if (!$modinfo->get_groups($cm->groupingid)) {
                continue;
            }
            $usersgroups = groups_get_all_groups($course->id, $anentry->userid, $cm->groupingid);
            if (is_array($usersgroups)) {
                $usersgroups = array_keys($usersgroups);
                $intersect = array_intersect($usersgroups, $modinfo->get_groups($cm->groupingid));
                if (empty($intersect)) {
                    continue;
                }
            }
        }
        $show[] = $anentry;
    }

    if (empty($show)) {
        return false;
    }

    echo $OUTPUT->heading(get_string('newscratchpadentries', 'scratchpad').':', 3);

    foreach ($show as $submission) {
        $cm = $modinfo->get_cm($submission->cmid);
        $context = context_module::instance($submission->cmid);
        if (has_capability('mod/scratchpad:manageentries', $context)) {
            $link = $CFG->wwwroot.'/mod/scratchpad/report.php?id='.$cm->id;
        } else {
            $link = $CFG->wwwroot.'/mod/scratchpad/view.php?id='.$cm->id;
        }
        print_recent_activity_note($submission->modified,
                                   $submission,
                                   $cm->name,
                                   $link,
                                   false,
                                   $viewfullnames);
    }
    return true;
}

/**
 * Returns the users with data in one scratchpad
 * (users with records in scratchpad_entries, students and teachers)
 * @param int $scratchpadid Scratchpad ID
 * @return array Array of user ids
 */
function scratchpad_get_participants($scratchpadid) {
    global $DB;

    // Get students.
    $students = $DB->get_records_sql("SELECT DISTINCT u.id
                                      FROM {user} u,
                                      {scratchpad_entries} j
                                      WHERE j.scratchpad=? and
                                      u.id = j.userid", array($scratchpadid));
    // Get teachers.
    $teachers = $DB->get_records_sql("SELECT DISTINCT u.id
                                      FROM {user} u,
                                      {scratchpad_entries} j
                                      WHERE j.scratchpad=? and
                                      u.id = j.teacher", array($scratchpadid));

    // Add teachers to students.
    if ($teachers) {
        foreach ($teachers as $teacher) {
            $students[$teacher->id] = $teacher;
        }
    }
    // Return students array (it contains an array of unique users).
    return $students;
}

/**
 * This function returns true if a scale is being used by one scratchpad
 * @param int $scratchpadid Scratchpad ID
 * @param int $scaleid Scale ID
 * @return boolean True if a scale is being used by one scratchpad
 */
function scratchpad_scale_used ($scratchpadid, $scaleid) {

    global $DB;
    $return = false;

    $rec = $DB->get_record("scratchpad", array("id" => $scratchpadid, "grade" => -$scaleid));

    if (!empty($rec) && !empty($scaleid)) {
        $return = true;
    }

    return $return;
}

/**
 * Checks if scale is being used by any instance of scratchpad
 *
 * This is used to find out if scale used anywhere
 * @param $scaleid int
 * @return boolean True if the scale is used by any scratchpad
 */
function scratchpad_scale_used_anywhere($scaleid) {
    global $DB;

    if ($scaleid and $DB->get_records('scratchpad', array('grade' => -$scaleid))) {
        return true;
    } else {
        return false;
    }
}

/**
 * Implementation of the function for printing the form elements that control
 * whether the course reset functionality affects the scratchpad.
 *
 * @param object $mform form passed by reference
 */
function scratchpad_reset_course_form_definition(&$mform) {
    $mform->addElement('header', 'scratchpadheader', get_string('modulenameplural', 'scratchpad'));
    $mform->addElement('advcheckbox', 'reset_scratchpad', get_string('removemessages', 'scratchpad'));
}

/**
 * Course reset form defaults.
 *
 * @param object $course
 * @return array
 */
function scratchpad_reset_course_form_defaults($course) {
    return array('reset_scratchpad' => 1);
}

/**
 * Removes all entries
 *
 * @param object $data
 */
function scratchpad_reset_userdata($data) {

    global $CFG, $DB;

    $status = array();
    if (!empty($data->reset_scratchpad)) {

        $sql = "SELECT j.id
                FROM {scratchpad} j
                WHERE j.course = ?";
        $params = array($data->courseid);

        $DB->delete_records_select('scratchpad_entries', "scratchpad IN ($sql)", $params);

        $status[] = array('component' => get_string('modulenameplural', 'scratchpad'),
                          'item' => get_string('removeentries', 'scratchpad'),
                          'error' => false);
    }

    return $status;
}

function scratchpad_print_overview($courses, &$htmlarray) {

    global $USER, $CFG, $DB;

    if (!get_config('scratchpad', 'overview')) {
        return array();
    }

    if (empty($courses) || !is_array($courses) || count($courses) == 0) {
        return array();
    }

    if (!$scratchpads = get_all_instances_in_courses('scratchpad', $courses)) {
        return array();
    }

    $strscratchpad = get_string('modulename', 'scratchpad');

    $timenow = time();
    foreach ($scratchpads as $scratchpad) {

        if (empty($courses[$scratchpad->course]->format)) {
            $courses[$scratchpad->course]->format = $DB->get_field('course', 'format', array('id' => $scratchpad->course));
        }

        if ($courses[$scratchpad->course]->format == 'weeks' AND $scratchpad->days) {

            $coursestartdate = $courses[$scratchpad->course]->startdate;

            $scratchpad->timestart  = $coursestartdate + (($scratchpad->section - 1) * 608400);
            if (!empty($scratchpad->days)) {
                $scratchpad->timefinish = $scratchpad->timestart + (3600 * 24 * $scratchpad->days);
            } else {
                $scratchpad->timefinish = 9999999999;
            }
            $scratchpadopen = ($scratchpad->timestart < $timenow && $timenow < $scratchpad->timefinish);

        } else {
            $scratchpadopen = true;
        }

        if ($scratchpadopen) {
            $str = '<div class="scratchpad overview"><div class="name">'.
                   $strscratchpad.': <a '.($scratchpad->visible ? '' : ' class="dimmed"').
                   ' href="'.$CFG->wwwroot.'/mod/scratchpad/view.php?id='.$scratchpad->coursemodule.'">'.
                   $scratchpad->name.'</a></div></div>';

            if (empty($htmlarray[$scratchpad->course]['scratchpad'])) {
                $htmlarray[$scratchpad->course]['scratchpad'] = $str;
            } else {
                $htmlarray[$scratchpad->course]['scratchpad'] .= $str;
            }
        }
    }
}

function scratchpad_get_user_grades($scratchpad, $userid=0) {
    global $DB;

    $params = array();

    if ($userid) {
        $userstr = 'AND userid = :uid';
        $params['uid'] = $userid;
    } else {
        $userstr = '';
    }

    if (!$scratchpad) {
        return false;

    } else {

        $sql = "SELECT userid, modified as datesubmitted, format as feedbackformat,
                rating as rawgrade, entrycomment as feedback, teacher as usermodifier, timemarked as dategraded
                FROM {scratchpad_entries}
                WHERE scratchpad = :jid ".$userstr;
        $params['jid'] = $scratchpad->id;

        $grades = $DB->get_records_sql($sql, $params);

        if ($grades) {
            foreach ($grades as $key => $grade) {
                $grades[$key]->id = $grade->userid;
                if ($grade->rawgrade == -1) {
                    $grades[$key]->rawgrade = null;
                }
            }
        } else {
            return false;
        }

        return $grades;
    }

}


/**
 * Update scratchpad grades in 1.9 gradebook
 *
 * @param object   $scratchpad      if is null, all scratchpads
 * @param int      $userid       if is false al users
 * @param boolean  $nullifnone   return null if grade does not exist
 */
function scratchpad_update_grades($scratchpad=null, $userid=0, $nullifnone=true) {

    global $CFG, $DB;

    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir.'/gradelib.php');
    }

    if ($scratchpad != null) {
        if ($grades = scratchpad_get_user_grades($scratchpad, $userid)) {
            scratchpad_grade_item_update($scratchpad, $grades);
        } else if ($userid && $nullifnone) {
            $grade = new stdClass();
            $grade->userid   = $userid;
            $grade->rawgrade = null;
            scratchpad_grade_item_update($scratchpad, $grade);
        } else {
            scratchpad_grade_item_update($scratchpad);
        }
    } else {
        $sql = "SELECT j.*, cm.idnumber as cmidnumber
                FROM {course_modules} cm
                JOIN {modules} m ON m.id = cm.module
                JOIN {scratchpad} j ON cm.instance = j.id
                WHERE m.name = 'scratchpad'";
        if ($recordset = $DB->get_records_sql($sql)) {
            foreach ($recordset as $scratchpad) {
                if ($scratchpad->grade != false) {
                    scratchpad_update_grades($scratchpad);
                } else {
                    scratchpad_grade_item_update($scratchpad);
                }
            }
        }
    }
}


/**
 * Create grade item for given scratchpad
 *
 * @param object $scratchpad object with extra cmidnumber
 * @param mixed optional array/object of grade(s); 'reset' means reset grades in gradebook
 * @return int 0 if ok, error code otherwise
 */
function scratchpad_grade_item_update($scratchpad, $grades=null) {
    global $CFG;
    if (!function_exists('grade_update')) { // Workaround for buggy PHP versions.
        require_once($CFG->libdir.'/gradelib.php');
    }

    if (property_exists($scratchpad, 'cmidnumber')) {
        $params = array('itemname' => $scratchpad->name, 'idnumber' => $scratchpad->cmidnumber);
    } else {
        $params = array('itemname' => $scratchpad->name);
    }

    // if ($scratchpad->grade > 0) {
        // $params['gradetype']  = GRADE_TYPE_VALUE;
        // $params['grademax']   = $scratchpad->grade;
        // $params['grademin']   = 0;
        // $params['multfactor'] = 1.0;

    // } else if ($scratchpad->grade < 0) {
        // $params['gradetype'] = GRADE_TYPE_SCALE;
        // $params['scaleid']   = -$scratchpad->grade;

    // } else {
        // $params['gradetype']  = GRADE_TYPE_NONE;
        // $params['multfactor'] = 1.0;
    // }

    if ($grades === 'reset') {
        $params['reset'] = true;
        $grades = null;
    }

    return grade_update('mod/scratchpad', $scratchpad->course, 'mod', 'scratchpad', $scratchpad->id, 0, $grades, $params);
}


/**
 * Delete grade item for given scratchpad
 *
 * @param   object   $scratchpad
 * @return  object   grade_item
 */
function scratchpad_grade_item_delete($scratchpad) {
    global $CFG;

    require_once($CFG->libdir.'/gradelib.php');

    return grade_update('mod/scratchpad', $scratchpad->course, 'mod', 'scratchpad', $scratchpad->id, 0, null, array('deleted' => 1));
}



function scratchpad_get_users_done($scratchpad, $currentgroup) {
    global $DB;

    $params = array();

    $sql = "SELECT u.* FROM {scratchpad_entries} j
            JOIN {user} u ON j.userid = u.id ";

    // Group users.
    if ($currentgroup != 0) {
        $sql .= "JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = ?";
        $params[] = $currentgroup;
    }

    $sql .= " WHERE j.scratchpad=? ORDER BY j.modified DESC";
    $params[] = $scratchpad->id;
    $scratchpads = $DB->get_records_sql($sql, $params);

    $cm = scratchpad_get_coursemodule($scratchpad->id);
    if (!$scratchpads || !$cm) {
        return null;
    }

    // Remove unenrolled participants.
    foreach ($scratchpads as $key => $user) {

        $context = context_module::instance($cm->id);

        $canadd = has_capability('mod/scratchpad:addentries', $context, $user);
        $entriesmanager = has_capability('mod/scratchpad:manageentries', $context, $user);

        if (!$entriesmanager and !$canadd) {
            unset($scratchpads[$key]);
        }
    }

    return $scratchpads;
}

/**
 * Counts all the scratchpad entries (optionally in a given group)
 */
function scratchpad_count_entries($scratchpad, $groupid = 0) {
    global $DB;

    $cm = scratchpad_get_coursemodule($scratchpad->id);
    $context = context_module::instance($cm->id);

    if ($groupid) {     // How many in a particular group?

        $sql = "SELECT DISTINCT u.id FROM {scratchpad_entries} j
                JOIN {groups_members} g ON g.userid = j.userid
                JOIN {user} u ON u.id = g.userid
                WHERE j.scratchpad = ? AND g.groupid = ?";
        $scratchpads = $DB->get_records_sql($sql, array($scratchpad->id, $groupid));

    } else { // Count all the entries from the whole course.

        $sql = "SELECT DISTINCT u.id FROM {scratchpad_entries} j
                JOIN {user} u ON u.id = j.userid
                WHERE j.scratchpad = ?";
        $scratchpads = $DB->get_records_sql($sql, array($scratchpad->id));
    }

    if (!$scratchpads) {
        return 0;
    }

    $canadd = get_users_by_capability($context, 'mod/scratchpad:addentries', 'u.id');
    $entriesmanager = get_users_by_capability($context, 'mod/scratchpad:manageentries', 'u.id');

    // Remove unenrolled participants.
    foreach ($scratchpads as $userid => $notused) {

        if (!isset($entriesmanager[$userid]) && !isset($canadd[$userid])) {
            unset($scratchpads[$userid]);
        }
    }

    return count($scratchpads);
}

function scratchpad_get_unmailed_graded($cutofftime) {
    global $DB;

    $sql = "SELECT je.*, j.course, j.name FROM {scratchpad_entries} je
            JOIN {scratchpad} j ON je.scratchpad = j.id
            WHERE je.mailed = '0' AND je.timemarked < ? AND je.timemarked > 0";
    return $DB->get_records_sql($sql, array($cutofftime));
}

function scratchpad_log_info($log) {
    global $DB;

    $sql = "SELECT j.*, u.firstname, u.lastname
            FROM {scratchpad} j
            JOIN {scratchpad_entries} je ON je.scratchpad = j.id
            JOIN {user} u ON u.id = je.userid
            WHERE je.id = ?";
    return $DB->get_record_sql($sql, array($log->info));
}

/**
 * Returns the scratchpad instance course_module id
 *
 * @param integer $scratchpad
 * @return object
 */
function scratchpad_get_coursemodule($scratchpadid) {

    global $DB;

    return $DB->get_record_sql("SELECT cm.id FROM {course_modules} cm
                                JOIN {modules} m ON m.id = cm.module
                                WHERE cm.instance = ? AND m.name = 'scratchpad'", array($scratchpadid));
}



function scratchpad_print_user_entry($course, $user, $entry, $teachers, $grades) {

    global $USER, $OUTPUT, $DB, $CFG;

    require_once($CFG->dirroot.'/lib/gradelib.php');

    echo "\n<table class=\"scratchpaduserentry m-b-1\" id=\"entry-" . $user->id . "\">";

    echo "\n<tr>";
    echo "\n<td class=\"userpix\" rowspan=\"2\">";
    echo $OUTPUT->user_picture($user, array('courseid' => $course->id, 'alttext' => true));
    echo "</td>";
    echo "<td class=\"userfullname\">".fullname($user);
    if ($entry) {
        echo " <span class=\"lastedit\">".get_string("lastedited").": ".userdate($entry->modified)."</span>";
    }
    echo "</td>";
    echo "</tr>";

    echo "\n<tr><td>";
    if ($entry) {
        echo scratchpad_format_entry_text($entry, $course);
    } else {
        print_string("noentry", "scratchpad");
    }
    echo "</td></tr>";

    if ($entry) {
        echo "\n<tr>";
        echo "<td class=\"userpix\">";
        if (!$entry->teacher) {
            $entry->teacher = $USER->id;
        }
        if (empty($teachers[$entry->teacher])) {
            $teachers[$entry->teacher] = $DB->get_record('user', array('id' => $entry->teacher));
        }
        echo $OUTPUT->user_picture($teachers[$entry->teacher], array('courseid' => $course->id, 'alttext' => true));
        echo "</td>";
        echo "<td>".get_string("feedback").":";

        $attrs = array();
        $hiddengradestr = '';
        $gradebookgradestr = '';
        $feedbackdisabledstr = '';
        $feedbacktext = $entry->entrycomment;

        // If the grade was modified from the gradebook disable edition also skip if scratchpad is not graded.
        $gradinginfo = grade_get_grades($course->id, 'mod', 'scratchpad', $entry->scratchpad, array($user->id));
        if (!empty($gradinginfo->items[0]->grades[$entry->userid]->str_long_grade)) {
            if ($gradingdisabled = $gradinginfo->items[0]->grades[$user->id]->locked
                    || $gradinginfo->items[0]->grades[$user->id]->overridden) {
                $attrs['disabled'] = 'disabled';
                $hiddengradestr = '<input type="hidden" name="r'.$entry->id.'" value="'.$entry->rating.'"/>';
                $gradebooklink = '<a href="'.$CFG->wwwroot.'/grade/report/grader/index.php?id='.$course->id.'">';
                $gradebooklink .= $gradinginfo->items[0]->grades[$user->id]->str_long_grade.'</a>';
                $gradebookgradestr = '<br/>'.get_string("gradeingradebook", "scratchpad").':&nbsp;'.$gradebooklink;

                $feedbackdisabledstr = 'disabled="disabled"';
                $feedbacktext = $gradinginfo->items[0]->grades[$user->id]->str_feedback;
            }
        }

        // Grade selector.
        $attrs['id'] = 'r' . $entry->id;
        echo html_writer::label(fullname($user)." ".get_string('grade'), 'r'.$entry->id, true, array('class' => 'accesshide'));
        echo html_writer::select($grades, 'r'.$entry->id, $entry->rating, get_string("nograde").'...', $attrs);
        echo $hiddengradestr;
        // Rewrote next three lines to show entry needs to be regraded due to resubmission.
        if (!empty($entry->timemarked) && $entry->modified > $entry->timemarked) {
            echo " <span class=\"lastedit\">".get_string("needsregrade", "scratchpad"). "</span>";
        } else if ($entry->timemarked) {
            echo " <span class=\"lastedit\">".userdate($entry->timemarked)."</span>";
        }
        echo $gradebookgradestr;

        // Feedback text.
        echo html_writer::label(fullname($user)." ".get_string('feedback'), 'c'.$entry->id, true, array('class' => 'accesshide'));
        echo "<p><textarea id=\"c$entry->id\" name=\"c$entry->id\" rows=\"12\" cols=\"60\" $feedbackdisabledstr>";
        p($feedbacktext);
        echo "</textarea></p>";

        if ($feedbackdisabledstr != '') {
            echo '<input type="hidden" name="c'.$entry->id.'" value="'.$feedbacktext.'"/>';
        }
        echo "</td></tr>";
    }
    echo "</table>\n";

}

function scratchpad_print_feedback($course, $entry, $grades) {

    global $CFG, $DB, $OUTPUT;

    require_once($CFG->dirroot.'/lib/gradelib.php');

    if (! $teacher = $DB->get_record('user', array('id' => $entry->teacher))) {
        print_error('Weird scratchpad error');
    }

    echo '<table class="feedbackbox">';

    echo '<tr>';
    echo '<td class="left picture">';
    echo $OUTPUT->user_picture($teacher, array('courseid' => $course->id, 'alttext' => true));
    echo '</td>';
    echo '<td class="entryheader">';
    echo '<span class="author">'.fullname($teacher).'</span>';
    echo '&nbsp;&nbsp;<span class="time">'.userdate($entry->timemarked).'</span>';
    echo '</td>';
    echo '</tr>';

    echo '<tr>';
    echo '<td class="left side">&nbsp;</td>';
    echo '<td class="entrycontent">';

    echo '<div class="grade">';

    // Gradebook preference.
    $gradinginfo = grade_get_grades($course->id, 'mod', 'scratchpad', $entry->scratchpad, array($entry->userid));
    if (!empty($gradinginfo->items[0]->grades[$entry->userid]->str_long_grade)) {
        echo get_string('grade').': ';
        echo $gradinginfo->items[0]->grades[$entry->userid]->str_long_grade;
    } else {
        print_string('nograde');
    }
    echo '</div>';

    // Feedback text.
    echo format_text($entry->entrycomment, FORMAT_PLAIN);
    echo '</td></tr></table>';
}

/**
 * Serves the scratchpad files.
 *
 * @package  mod_scratchpad
 * @category files
 * @param stdClass $course course object
 * @param stdClass $cm course module object
 * @param stdClass $context context object
 * @param string $filearea file area
 * @param array $args extra arguments
 * @param bool $forcedownload whether or not force download
 * @param array $options additional options affecting the file serving
 * @return bool false if file not found, does not return if found - just send the file
 */
function scratchpad_pluginfile($course, $cm, $context, $filearea, $args, $forcedownload, array $options=array()) {
    global $DB, $USER;

    if ($context->contextlevel != CONTEXT_MODULE) {
        return false;
    }

    require_course_login($course, true, $cm);

    if (!$course->visible && !has_capability('moodle/course:viewhiddencourses', $context)) {
        return false;
    }

    // Args[0] should be the entry id.
    $entryid = intval(array_shift($args));
    $entry = $DB->get_record('scratchpad_entries', array('id' => $entryid), 'id, userid', MUST_EXIST);

    $canmanage = has_capability('mod/scratchpad:manageentries', $context);
    if (!$canmanage && !has_capability('mod/scratchpad:addentries', $context)) {
        // Even if it is your own entry.
        return false;
    }

    // Students can only see their own entry.
    if (!$canmanage && $USER->id !== $entry->userid) {
        return false;
    }

    if ($filearea !== 'entry') {
        return false;
    }

    $fs = get_file_storage();
    $relativepath = implode('/', $args);
    $fullpath = "/$context->id/mod_scratchpad/$filearea/$entryid/$relativepath";
    $file = $fs->get_file_by_hash(sha1($fullpath));

    // Finally send the file.
    send_stored_file($file, null, 0, $forcedownload, $options);
}

function scratchpad_format_entry_text($entry, $course = false, $cm = false) {

    if (!$cm) {
        if ($course) {
            $courseid = $course->id;
        } else {
            $courseid = 0;
        }
        $cm = get_coursemodule_from_instance('scratchpad', $entry->scratchpad, $courseid);
    }

    $context = context_module::instance($cm->id);
    $entrytext = file_rewrite_pluginfile_urls($entry->text, 'pluginfile.php', $context->id, 'mod_scratchpad', 'entry', $entry->id);

    $formatoptions = array(
        'context' => $context,
        'noclean' => false,
        'trusted' => false
    );
    return format_text($entrytext, $entry->format, $formatoptions);
}

/**
  * Obtains the automatic completion state for this scratchpad based on any conditions
  * in scratchpad settings.
  *
  * @param object $course Course
  * @param object $cm Course-module
  * @param int $userid User ID
  * @param bool $type Type of comparison (or/and; can be used as return value if no conditions)
  * @return bool True if completed, false if not, $type if conditions not set.
  */
function scratchpad_get_completion_state($course,$cm,$userid,$type) {
    global $CFG,$DB;

    // Get scratchpad details
    $scratchpad = $DB->get_record('scratchpad', array('id' => $cm->instance), '*', MUST_EXIST);

    // If completion option is enabled, evaluate it and return true/false 
    if($scratchpad->completionanswer) {
        return $scratchpad->completionanswer <= $DB->get_field_sql("
SELECT 
    COUNT(1) 
FROM 
    {scratchpad} s
    INNER JOIN {scratchpad_entries} se ON s.id=se.scratchpad
WHERE
    se.userid=:userid AND se.scratchpad=:scratchpadid",
            array('userid'=>$userid,'scratchpadid'=>$scratchpad->id));
    } else {
        // Completion option is not enabled so just return $type
        return $type;
    }
}

