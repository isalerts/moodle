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
 * This file contains the ingest manager for the assignfeedback_editpdf plugin
 *
 * @package   assignfeedback_editpdf
 * @copyright 2012 Davo Smith
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace assignfeedback_editpdf;

/**
 * Functions for generating the annotated pdf.
 *
 * This class controls the ingest of student submission files to a normalised
 * PDF 1.4 document with all submission files concatinated together. It also
 * provides the functions to generate a downloadable pdf with all comments and
 * annotations embedded.
 * @copyright 2012 Davo Smith
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class document_services {

    /** File area for generated pdf */
    const FINAL_PDF_FILEAREA = 'download';
    /** File area for combined pdf */
    const COMBINED_PDF_FILEAREA = 'combined';
    /** File area for page images */
    const PAGE_IMAGE_FILEAREA = 'pages';
    /** Filename for combined pdf */
    const COMBINED_PDF_FILENAME = 'combined.pdf';

    /**
     * This function will take an int or an assignment instance and
     * return an assignment instance. It is just for convenience.
     * @param int|\assign $assignment
     * @return assign
     */
    private static function get_assignment_from_param($assignment) {
        global $CFG;

        require_once($CFG->dirroot . '/mod/assign/locallib.php');

        if (!is_object($assignment)) {
            $cm = \get_coursemodule_from_instance('assign', $assignment, 0, false, MUST_EXIST);
            $context = \context_module::instance($cm->id);

            $assignment = new \assign($context, null, null);
        }
        return $assignment;
    }

    /**
     * Get a hash that will be unique and can be used in a path name.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     */
    private static function hash($assignment, $userid, $attemptnumber) {
        if (is_object($assignment)) {
            $assignmentid = $assignment->get_instance()->id;
        } else {
            $assignmentid = $assignment;
        }
        return sha1($assignmentid . '_' . $userid . '_' . $attemptnumber);
    }

    /**
     * This function will search for all files that can be converted
     * and concatinated into a PDF (1.4) - for any submission plugin
     * for this students attempt.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return array(stored_file)
     */
    public static function list_compatible_submission_files_for_attempt($assignment, $userid, $attemptnumber) {
        global $USER, $DB;

        $assignment = self::get_assignment_from_param($assignment);

        // Capability checks.
        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        $files = array();

        if ($assignment->get_instance()->teamsubmission) {
            $submission = $assignment->get_group_submission($userid, 0, false);
        } else {
            $submission = $assignment->get_user_submission($userid, false);
        }
        $user = $DB->get_record('user', array('id' => $userid));

        // User has not submitted anything yet.
        if (!$submission) {
            return $files;
        }
        // Ask each plugin for it's list of files.
        foreach ($assignment->get_submission_plugins() as $plugin) {
            if ($plugin->is_enabled() && $plugin->is_visible()) {
                $pluginfiles = $plugin->get_files($submission, $user);
                foreach ($pluginfiles as $filename => $file) {
                    if (($file instanceof \stored_file) && ($file->get_mimetype() === 'application/pdf')) {
                        $files[$filename] = $file;
                    }
                }
            }
        }
        return $files;
    }

    /**
     * This function return the combined pdf for all valid submission files.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return stored_file
     */
    public static function get_combined_pdf_for_attempt($assignment, $userid, $attemptnumber) {

        global $USER, $DB;

        $assignment = self::get_assignment_from_param($assignment);

        // Capability checks.
        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);
        if ($assignment->get_instance()->teamsubmission) {
            $submission = $assignment->get_group_submission($userid, 0, false);
        } else {
            $submission = $assignment->get_user_submission($userid, false);
        }

        $contextid = $assignment->get_context()->id;
        $component = 'assignfeedback_editpdf';
        $filearea = self::COMBINED_PDF_FILEAREA;
        $itemid = $grade->id;
        $filepath = '/';
        $filename = self::COMBINED_PDF_FILENAME;
        $fs = \get_file_storage();

        $combinedpdf = $fs->get_file($contextid, $component, $filearea, $itemid, $filepath, $filename);
        if (!$combinedpdf ||
                ($submission && ($combinedpdf->get_timemodified() < $submission->timemodified))) {
            return self::generate_combined_pdf_for_attempt($assignment, $userid, $attemptnumber);
        }
        return $combinedpdf;
    }

    /**
     * This function will take all of the compatible files for a submission
     * and combine them into one PDF.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return stored_file
     */
    public static function generate_combined_pdf_for_attempt($assignment, $userid, $attemptnumber) {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        $files = self::list_compatible_submission_files_for_attempt($assignment, $userid, $attemptnumber);

        $pdf = new pdf();
        if (!$files) {
            // No valid submission files - create an empty pdf.
            $pdf->AddPage();
        } else {

            // Create a mega joined PDF.
            $compatiblepdfs = array();
            foreach ($files as $file) {
                $compatiblepdf = pdf::ensure_pdf_compatible($file);
                if ($compatiblepdf) {
                    array_push($compatiblepdfs, $compatiblepdf);
                }
            }

            $tmpdir = \make_temp_directory('assignfeedback_editpdf/combined/' . self::hash($assignment, $userid, $attemptnumber));
            $tmpfile = $tmpdir . '/' . self::COMBINED_PDF_FILENAME;

            @unlink($tmpfile);
            $pagecount = $pdf->combine_pdfs($compatiblepdfs, $tmpfile);
            if ($pagecount == 0) {
                // We at least want a single blank page.
                $pdf->AddPage();
                @unlink($tmpfile);
                $files = false;
            }
        }

        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);
        $record = new \stdClass();

        $record->contextid = $assignment->get_context()->id;
        $record->component = 'assignfeedback_editpdf';
        $record->filearea = self::COMBINED_PDF_FILEAREA;
        $record->itemid = $grade->id;
        $record->filepath = '/';
        $record->filename = self::COMBINED_PDF_FILENAME;
        $fs = \get_file_storage();

        $fs->delete_area_files($record->contextid, $record->component, $record->filearea, $record->itemid);

        if (!$files) {
            // This was a blank pdf.
            $content = $pdf->Output(self::COMBINED_PDF_FILENAME, 'S');
            $file = $fs->create_file_from_string($record, $content);
        } else {
            // This was a combined pdf.
            $file = $fs->create_file_from_pathname($record, $tmpfile);
            @unlink($tmpfile);
        }

        return $file;
    }

    /**
     * This function will generate and return a list of the page images from a pdf.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return array(stored_file)
     */
    public static function generate_page_images_for_attempt($assignment, $userid, $attemptnumber) {
        global $CFG;

        require_once($CFG->libdir . '/pdflib.php');

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        // Need to generate the page images - first get a combined pdf.
        $file = self::get_combined_pdf_for_attempt($assignment, $userid, $attemptnumber);
        if (!$file) {
            throw \moodle_exception('Could not generate combined pdf.');
        }

        $tmpdir = \make_temp_directory('assignfeedback_editpdf/pageimages/' . self::hash($assignment, $userid, $attemptnumber));
        $combined = $tmpdir . '/' . self::COMBINED_PDF_FILENAME;
        $file->copy_content_to($combined); // Copy the file.

        $pdf = new pdf();

        $pdf->set_image_folder($tmpdir);
        $pagecount = $pdf->set_pdf($combined);

        $i = 0;
        $images = array();
        for ($i = 0; $i < $pagecount; $i++) {
            $images[$i] = $pdf->get_image($i);
        }
        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);

        $files = array();
        $record = new \stdClass();
        $record->contextid = $assignment->get_context()->id;
        $record->component = 'assignfeedback_editpdf';
        $record->filearea = self::PAGE_IMAGE_FILEAREA;
        $record->itemid = $grade->id;
        $record->filepath = '/';
        $fs = \get_file_storage();

        foreach ($images as $index => $image) {
            $record->filename = basename($image);
            $files[$index] = $fs->create_file_from_pathname($record, $tmpdir . '/' . $image);
            @unlink($tmpdir . '/' . $image);
        }
        @unlink($combined);
        @rmdir($tmpdir);

        return $files;
    }

    /**
     * This function returns a list of the page images from a pdf.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return array(stored_file)
     */
    public static function get_page_images_for_attempt($assignment, $userid, $attemptnumber) {

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        if ($assignment->get_instance()->teamsubmission) {
            $submission = $assignment->get_group_submission($userid, 0, false);
        } else {
            $submission = $assignment->get_user_submission($userid, false);
        }
        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);

        $contextid = $assignment->get_context()->id;
        $component = 'assignfeedback_editpdf';
        $filearea = self::PAGE_IMAGE_FILEAREA;
        $itemid = $grade->id;
        $filepath = '/';

        $fs = \get_file_storage();

        $files = $fs->get_directory_files($contextid, $component, $filearea, $itemid, $filepath);

        if (!empty($files)) {
            $first = reset($files);
            if ($first->get_timemodified() < $submission->timemodified) {

                $fs->delete_area_files($contextid, $component, $filearea, $itemid);
                // Image files are stale - regenerate them.
                $files = array();
            } else {
                return $files;
            }
        }
        return self::generate_page_images_for_attempt($assignment, $userid, $attemptnumber);
    }

    /**
     * This function returns sensible filename for a feedback file.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return string
     */
    protected static function get_downloadable_feedback_filename($assignment, $userid, $attemptnumber) {
        global $DB;

        $assignment = self::get_assignment_from_param($assignment);

        $groupmode = groups_get_activity_groupmode($assignment->get_course_module());
        $groupname = '';
        if ($groupmode) {
            $groupid = groups_get_activity_group($assignment->get_course_module(), true);
            $groupname = groups_get_group_name($groupid).'-';
        }
        if ($groupname == '-') {
            $groupname = '';
        }
        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);
        $user = $DB->get_record('user', array('id'=>$userid), '*', MUST_EXIST);

        if ($assignment->is_blind_marking()) {
            $prefix = $groupname . get_string('participant', 'assign');
            $prefix = str_replace('_', ' ', $prefix);
            $prefix = clean_filename($prefix . '_' . $assignment->get_uniqueid_for_user($userid) . '_');
        } else {
            $prefix = $groupname . fullname($user);
            $prefix = str_replace('_', ' ', $prefix);
            $prefix = clean_filename($prefix . '_' . $assignment->get_uniqueid_for_user($userid) . '_');
        }
        $prefix .= $grade->attemptnumber;

        return $prefix . '.pdf';
    }

    /**
     * This function takes the combined pdf and embeds all the comments and annotations.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return stored_file
     */
    public static function generate_feedback_document($assignment, $userid, $attemptnumber) {

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }
        if (!$assignment->can_grade()) {
            \print_error('nopermission');
        }

        // Need to generate the page images - first get a combined pdf.
        $file = self::get_combined_pdf_for_attempt($assignment, $userid, $attemptnumber);
        if (!$file) {
            throw \moodle_exception('Could not generate combined pdf.');
        }

        $tmpdir = \make_temp_directory('assignfeedback_editpdf/final/' . self::hash($assignment, $userid, $attemptnumber));
        $combined = $tmpdir . '/' . self::COMBINED_PDF_FILENAME;
        $file->copy_content_to($combined); // Copy the file.

        $pdf = new pdf();

        $fs = \get_file_storage();
        $stamptmpdir = \make_temp_directory('assignfeedback_editpdf/stamps/' . self::hash($assignment, $userid, $attemptnumber));
        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);
        // Copy any new stamps to this instance.
        if ($files = $fs->get_area_files($assignment->get_context()->id,
                                         'assignfeedback_editpdf',
                                         'stamps',
                                         $grade->id,
                                         "filename",
                                         false)) {
            foreach ($files as $file) {
                $filename = $stamptmpdir . '/' . $file->get_filename();
                $file->copy_content_to($filename); // Copy the file.
            }
        }

        $pagecount = $pdf->set_pdf($combined);
        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);
        page_editor::release_drafts($grade->id);

        for ($i = 0; $i < $pagecount; $i++) {
            $pdf->copy_page();
            $comments = page_editor::get_comments($grade->id, $i, false);
            $annotations = page_editor::get_annotations($grade->id, $i, false);

            foreach ($comments as $comment) {
                $pdf->add_comment($comment->rawtext,
                                  $comment->x,
                                  $comment->y,
                                  $comment->width,
                                  $comment->colour);
            }

            foreach ($annotations as $annotation) {
                $pdf->add_annotation($annotation->x,
                                     $annotation->y,
                                     $annotation->endx,
                                     $annotation->endy,
                                     $annotation->colour,
                                     $annotation->type,
                                     $annotation->path,
                                     $stamptmpdir);
            }
        }

        fulldelete($stamptmpdir);

        $filename = self::get_downloadable_feedback_filename($assignment, $userid, $attemptnumber);
        $filename = clean_param($filename, PARAM_FILE);

        $generatedpdf = $tmpdir . '/' . $filename;
        $pdf->save_pdf($generatedpdf);


        $record = new \stdClass();

        $record->contextid = $assignment->get_context()->id;
        $record->component = 'assignfeedback_editpdf';
        $record->filearea = self::FINAL_PDF_FILEAREA;
        $record->itemid = $grade->id;
        $record->filepath = '/';
        $record->filename = $filename;


        // Only keep one current version of the generated pdf.
        $fs->delete_area_files($record->contextid, $record->component, $record->filearea, $record->itemid);

        $file = $fs->create_file_from_pathname($record, $generatedpdf);

        // Cleanup.
        @unlink($generatedpdf);
        @unlink($combined);
        @rmdir($tmpdir);

        return $file;
    }

    /**
     * This function returns the generated pdf (if it exists).
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return stored_file
     */
    public static function get_feedback_document($assignment, $userid, $attemptnumber) {

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }

        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);

        $contextid = $assignment->get_context()->id;
        $component = 'assignfeedback_editpdf';
        $filearea = self::FINAL_PDF_FILEAREA;
        $itemid = $grade->id;
        $filepath = '/';

        $fs = \get_file_storage();
        $files = $fs->get_area_files($contextid,
                                     $component,
                                     $filearea,
                                     $itemid,
                                     "itemid, filepath, filename",
                                     false);
        if ($files) {
            return reset($files);
        }
        return false;
    }

    /**
     * This function deletes the generated pdf for a student.
     * @param int|\assign $assignment
     * @param int $userid
     * @param int $attemptnumber (-1 means latest attempt)
     * @return bool
     */
    public static function delete_feedback_document($assignment, $userid, $attemptnumber) {

        $assignment = self::get_assignment_from_param($assignment);

        if (!$assignment->can_view_submission($userid)) {
            \print_error('nopermission');
        }
        if (!$assignment->can_grade()) {
            \print_error('nopermission');
        }

        $grade = $assignment->get_user_grade($userid, true, $attemptnumber);

        $contextid = $assignment->get_context()->id;
        $component = 'assignfeedback_editpdf';
        $filearea = self::FINAL_PDF_FILEAREA;
        $itemid = $grade->id;

        $fs = \get_file_storage();
        return $fs->delete_area_files($contextid, $component, $filearea, $itemid);
    }

}
