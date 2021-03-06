<?php
// This file is part of the customcert module for Moodle - http://moodle.org/
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

defined('MOODLE_INTERNAL') || die('Direct access to this script is forbidden.');

require_once($CFG->dirroot . '/mod/customcert/element/element.class.php');
require_once($CFG->dirroot . '/mod/customcert/element/grade/lib.php');

/**
 * Date - Issue
 */
define('CUSTOMCERT_DATE_ISSUE', '1');

/**
 * Date - Completion
 */
define('CUSTOMCERT_DATE_COMPLETION', '2');

/**
 * The customcert element date's core interaction API.
 *
 * @package    customcertelement_date
 * @copyright  2013 Mark Nelson <markn@moodle.com>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class customcert_element_date extends customcert_element_base {

    /**
     * This function renders the form elements when adding a customcert element.
     *
     * @param mod_customcert_edit_element_form $mform the edit_form instance
     */
    public function render_form_elements($mform) {
        // Get the possible date options.
        $dateoptions = array();
        $dateoptions[CUSTOMCERT_DATE_ISSUE] = get_string('issueddate', 'customcertelement_date');
        $dateoptions[CUSTOMCERT_DATE_COMPLETION] = get_string('completiondate', 'customcertelement_date');
        $dateoptions = $dateoptions + customcert_element_grade::get_grade_items();

        $mform->addElement('select', 'dateitem', get_string('dateitem', 'customcertelement_date'), $dateoptions);
        $mform->addHelpButton('dateitem', 'dateitem', 'customcertelement_date');

        $mform->addElement('select', 'dateformat', get_string('dateformat', 'customcertelement_date'), self::get_date_formats());
        $mform->addHelpButton('dateformat', 'dateformat', 'customcertelement_date');

        parent::render_form_elements($mform);
    }

    /**
     * This will handle how form data will be saved into the data column in the
     * customcert_elements table.
     *
     * @param stdClass $data the form data
     * @return string the json encoded array
     */
    public function save_unique_data($data) {
        // Array of data we will be storing in the database.
        $arrtostore = array(
            'dateitem' => $data->dateitem,
            'dateformat' => $data->dateformat
        );

        // Encode these variables before saving into the DB.
        return json_encode($arrtostore);
    }

    /**
     * Handles rendering the element on the pdf.
     *
     * @param pdf $pdf the pdf object
     * @param bool $preview true if it is a preview, false otherwise
     */
    public function render($pdf, $preview) {
        global $COURSE, $DB, $USER;

        // If there is no element data, we have nothing to display.
        if (empty($this->element->data)) {
            return;
        }

        // Decode the information stored in the database.
        $dateinfo = json_decode($this->element->data);
        $dateitem = $dateinfo->dateitem;
        $dateformat = $dateinfo->dateformat;

        // If we are previewing this certificate then just show a demonstration date.
        if ($preview) {
            $date = time();
        } else {
            // Get the page.
            $page = $DB->get_record('customcert_pages', array('id' => $this->element->pageid), '*', MUST_EXIST);
            // Now we can get the issue for this user.
            $issue = $DB->get_record('customcert_issues', array('userid' => $USER->id, 'customcertid' => $page->customcertid), '*', MUST_EXIST);

            if ($dateitem == CUSTOMCERT_DATE_ISSUE) {
                $date = $issue->timecreated;
            } else if ($dateitem == CUSTOMCERT_DATE_COMPLETION) {
                // Get the enrolment end date.
                $sql = "SELECT MAX(c.timecompleted) as timecompleted
                        FROM {course_completions} c
                        WHERE c.userid = :userid
                        AND c.course = :courseid";
                if ($timecompleted = $DB->get_record_sql($sql, array('userid' => $issue->userid, 'courseid' => $COURSE->id))) {
                    if (!empty($timecompleted->timecompleted)) {
                        $date = $timecompleted->timecompleted;
                    }
                }
            } else {
                $gradeitem = new stdClass();
                $gradeitem->gradeitem = $dateitem;
                $gradeitem->gradeformat = GRADE_DISPLAY_TYPE_PERCENTAGE;
                if ($modinfo = customcert_element_grade::get_grade($gradeitem, $issue->userid)) {
                    $date = $modinfo->dategraded;
                }
            }
        }

        // Ensure that a date has been set.
        if (!empty($date)) {
            switch ($dateformat) {
                case 1:
                    $certificatedate = userdate($date, '%B %d, %Y');
                    break;
                case 2:
                    $suffix = $this->get_ordinal_number_suffix(userdate($date, '%d'));
                    $certificatedate = userdate($date, '%B %d' . $suffix . ', %Y');
                    break;
                case 3:
                    $certificatedate = userdate($date, '%d %B %Y');
                    break;
                case 4:
                    $certificatedate = userdate($date, '%B %Y');
                    break;
                default:
                    $certificatedate = userdate($date, get_string('strftimedate', 'langconfig'));
            }

            parent::render_content($pdf, $certificatedate);
        }
    }

    /**
     * Sets the data on the form when editing an element.
     *
     * @param mod_customcert_edit_element_form $mform the edit_form instance
     */
    public function definition_after_data($mform) {
        // Set the item and format for this element.
        $dateinfo = json_decode($this->element->data);
        $this->element->dateitem = $dateinfo->dateitem;
        $this->element->dateformat = $dateinfo->dateformat;

        parent::definition_after_data($mform);
    }

    /**
     * This function is responsible for handling the restoration process of the element.
     *
     * We will want to update the course module the date element is pointing to as it will
     * have changed in the course restore.
     *
     * @param restore_customcert_activity_task $restore
     */
    public function after_restore($restore) {
        global $DB;

        $dateinfo = json_decode($this->element->data);
        if ($newitem = restore_dbops::get_backup_ids_record($restore->get_restoreid(), 'course_module', $dateinfo->dateitem)) {
            $dateinfo->dateitem = $newitem->newitemid;
            $DB->set_field('customcert_elements', 'data', self::save_unique_data($dateinfo), array('id' => $this->element->id));
        }
    }

    /**
     * Helper function to return all the date formats.
     *
     * @return array the list of date formats
     */
    public static function get_date_formats() {
        $dateformats = array();
        $dateformats[1] = 'January 1, 2000';
        $dateformats[2] = 'January 1st, 2000';
        $dateformats[3] = '1 January 2000';
        $dateformats[4] = 'January 2000';
        $dateformats[5] = get_string('userdateformat', 'customcertelement_date');

        return $dateformats;
    }

    /**
     * Helper function to return the suffix of the day of
     * the month, eg 'st' if it is the 1st of the month.
     *
     * @param int $day the day of the month
     * @return string the suffix.
     */
    private function get_ordinal_number_suffix($day) {
        if (!in_array(($day % 100), array(11, 12, 13))) {
            switch ($day % 10) {
                // Handle 1st, 2nd, 3rd.
                case 1:
                    return get_string('numbersuffix_st', 'customcertelement_date');
                case 2:
                    return get_string('numbersuffix_nd', 'customcertelement_date');
                case 3:
                    return get_string('numbersuffix_rd', 'customcertelement_date');
            }
        }
        return 'th';
    }
}
