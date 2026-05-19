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

defined('MOODLE_INTERNAL') || die();

require_once($CFG->dirroot.'/lib/formslib.php');

class mod_scratchpad_entry_form extends moodleform {

    public function definition() {
        // $this->_form->addElement('editor', 'text_editor', get_string('entry', 'mod_scratchpad'),
        //         null, $this->_customdata['editoroptions']);
        // $this->_form->setType('text_editor', PARAM_RAW);
        // $this->_form->addRule('text_editor', null, 'required', null, 'client');
        $this->_form->addElement('textarea', 'text_editor', get_string('entry', 'mod_scratchpad'), 'wrap="virtual" rows="20" cols="50"');
        $this->_form->addRule('text_editor', null, 'required');
        $this->_form->addElement('hidden', 'id');
        $this->_form->setType('id', PARAM_INT);

        $this->add_action_buttons();
    }

    function validation($data, $files) {
        return array();
    }
}
