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
 * autogroup local plugin
 *
 * A course object relates to a Moodle course and acts as a container
 * for multiple groups. Initialising a course object will automatically
 * load each autogroup group for that course into memory.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

use local_autogroup\domain;
use local_autogroup\exception;
use stdClass;

/**
 * Class course
 *
 * A course object relates to a Moodle course and acts as a container
 * for multiple groups. Initialising a course object will automatically
 * load each autogroup group for that course into memory.
 *
 * Courses currently link to a single autogroup_set, however in the
 * future this could be extended to support multiple sets.
 *
 * @package local_autogroup\domain
 */
class course extends domain {
    /** @var array autogroups */
    private $autogroups = [];
    /** @var \context_course context */
    private $context;

    /**
     * Constructor.
     * @param int $courseid
     * @throws exception\invalid_course_argument
     */
    public function __construct(int $courseid) {
        // Get the id for this course.
        $this->parse_course_id($courseid);

        $this->context = \context_course::instance($this->id);

        // Load autogroup groups for this course.
        $this->get_autogroups();
    }

    /**
     * Parse course id.
     * @param object|int $course
     * @return bool
     * @throws exception\invalid_course_argument
     */
    private function parse_course_id($course) {
        if (is_int($course) && $course > 0) {
            $this->id = $course;
            return true;
        }

        if (is_object($course) && isset($course->id) && $course->id > 0) {
            $this->id = $course->id;
            return true;
        }

        throw new exception\invalid_course_argument($course);
    }

    /**
     * Get autogroups.
     */
    private function get_autogroups() {
        global $DB;
        $this->autogroups = $DB->get_records('local_autogroup_set', ['courseid' => $this->id]);

        foreach ($this->autogroups as $id => $settings) {
            try {
                $this->autogroups[$id] = new domain\autogroup_set($settings);
            } catch (exception\invalid_autogroup_set_argument $e) {
                unset($this->autogroups[$id]);
            }
        }
    }

    /**
     * Get membership counts.
     * @return array
     */
    public function get_membership_counts() {
        $result = [];
        foreach ($this->autogroups as $autogroup) {
            $result = $result + $autogroup->membership_count();
        }
        return $result;
    }

    /**
     * Verify all group membership.
     * @return bool
     */
    public function verify_all_group_membership() {
        $result = true;
        $enrolledusers = \get_enrolled_users($this->context);
        foreach ($enrolledusers as $user) {
            $result &= $this->verify_user_group_membership($user);
        }
        return $result;
    }

    /**
     * Verify user group membership.
     * @param stdClass $user
     * @return bool
     */
    public function verify_user_group_membership(stdClass $user) {
        $result = true;
        foreach ($this->autogroups as $autogroup) {
            $result &= $autogroup->verify_user_group_membership($user, $this->context);
        }
        return $result;
    }
}
