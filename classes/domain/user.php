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
 * A user object relates to a real Moodle user; it acts as a container
 * for multiple courses which in turn contain multiple groups.
 * Initialising a course object will automatically load each autogroup
 * group which could be relevant for a user into memory.
 *
 * A user is also a group member; a membership register is also maintained
 * by this class.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

use local_autogroup\domain;
use local_autogroup\exception;
use stdclass;

/**
 * Class user
 *
 * Wraps a standard moodle user with additional helper functions, linking
 * to the users courses and on to their autogroups.
 *
 * TODO: Some of the functionality here belongs in a repository class
 *
 * @package local_autogroup\domain
 */
class user extends domain {
    /** @var array membership */
    private $membership = [];
    /** @var array courses */
    private $courses = [];
    /** @var stdclass object */
    private $object;

    /**
     * Constructor.
     * @param object|int $user
     * @param int $onlyload a courseid to restrict loading to
     * @throws exception\invalid_user_argument
     */
    public function __construct($user, $onlyload = 0) {
        // Get the data for this user.
        $this->parse_user_data($user);

        // Register which autogroup groups this user is a member of currently.
        $this->get_group_membership();

        // If applicable, load courses this user is on and their autogroup groups.
        $this->get_courses($onlyload);
    }

    /**
     * Parse user data.
     * @param object|int $user
     * @return bool
     * @throws exception\invalid_user_argument
     */
    private function parse_user_data($user) {
        global $DB;
        // TODO: restructure to allow usage of custom profile fields.

        if (is_int($user) && $user > 0) {
            $this->id = $user;
            $this->object = $DB->get_record('user', ['id' => $user]);
            return true;
        }

        if (is_object($user) && isset($user->id) && $user->id > 0) {
            $this->id = $user->id;
            $this->object = $user;
            return true;
        }

        throw new exception\invalid_user_argument($user);
    }

    /**
     * Get group membership.
     */
    private function get_group_membership() {
        global $DB;
        $sql = "SELECT g.id, g.courseid" . PHP_EOL
            . "FROM {groups} g" . PHP_EOL
            . "LEFT JOIN {groups_members} gm" . PHP_EOL
            . "ON gm.groupid = g.id" . PHP_EOL
            . "WHERE gm.userid = :userid" . PHP_EOL
            . "AND " . $DB->sql_like('g.idnumber', ':autogrouptag');
        $param = [
            'userid' => $this->id,
            'autogrouptag' => 'autogroup|%',
        ];

        $this->membership = $DB->get_records_sql_menu($sql, $param);
    }

    /**
     * Get courses for this user where an autogroup set has been added
     * @param int $onlyload
     */
    private function get_courses(int $onlyload = 0) {
        global $DB;
        if ($onlyload < 1) {
            $sql = "SELECT e.courseid" . PHP_EOL
                . "FROM {enrol} e" . PHP_EOL
                . "LEFT JOIN {user_enrolments} ue" . PHP_EOL
                . "ON ue.enrolid = e.id" . PHP_EOL
                . "LEFT JOIN {local_autogroup_set} gs" . PHP_EOL
                . "ON gs.courseid = e.courseid" . PHP_EOL
                . "WHERE ue.userid = :userid" . PHP_EOL
                . "AND gs.id IS NOT NULL";
            $param = ['userid' => $this->id];

            $this->courses = $DB->get_fieldset_sql($sql, $param);
        } else {
            $this->courses[] = $onlyload;
        }

        foreach ($this->courses as $k => $courseid) {
            try {
                $courseid = (int)$courseid;
                $this->courses[$k] = new course($courseid);
            } catch (exception\invalid_course_argument $e) {
                unset($this->courses[$k]);
            }
        }
    }

    /**
     * Verify user group membership
     * @return bool
     */
    public function verify_user_group_membership() {
        $result = true;
        foreach ($this->courses as $course) {
            $result &= $course->verify_user_group_membership($this->object);
        }

        $this->get_group_membership();
        return $result;
    }
}
