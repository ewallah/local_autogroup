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
 * A group object relates to a Moodle group and is generally the end
 * point for most usecases.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

defined('MOODLE_INTERNAL') || die();

use local_autogroup\domain;
use local_autogroup\exception;
use stdClass;
use moodle_database;

require_once(__DIR__ . "/../../../../group/lib.php");

/**
 * Class group
 *
 * Wraps the standard Moodle group object with additional
 * helper functions.
 *
 * Save / create / update functions here refer to the core
 * Moodle functions in order to maintain event calls etc.
 *
 * @package local_autogroup\domain
 */
class group extends domain {
    /** @var array attributes */
    protected $attributes = [
        'id', 'courseid', 'idnumber', 'name', 'description', 'descriptionformat',
        'enrolmentkey', 'picture', 'timecreated', 'timemodified',
    ];
    /** @var int courseid */
    protected $courseid = 0;
    /** @var string idnumber */
    protected $idnumber = '';
    /** @var string name */
    protected $name = '';
    /** @var string description */
    protected $description = '';
    /** @var int descriptionformat */
    protected $descriptionformat = 1;
    /** @var string enrolmentkey */
    protected $enrolmentkey = '';
    /** @var int picture */
    protected $picture = 0;
    /** @var int timecreated */
    protected $timecreated = 0;
    /** @var int timemodified */
    protected $timemodified = 0;
    /** @var array members */
    private $members;

    /**
     * Constructor.
     * @param int|stdClass $group
     * @throws exception\invalid_group_argument
     */
    public function __construct($group) {
        if (is_int($group) && $group > 0) {
            $this->load_from_database($group);
        } else if ($this->validate_object($group)) {
            $this->load_from_object($group);
        } else {
            throw new exception\invalid_group_argument($group);
        }
        $this->get_members();
    }

    /**
     * Load from database.
     * @param int $groupid
     */
    private function load_from_database($groupid) {
        global $DB;
        $group = $DB->get_record('groups', ['id' => $groupid]);
        if ($this->validate_object($group)) {
            $this->load_from_object($group);
        }
    }

    /**
     * Validate object.
     * @param stdClass $group
     * @return bool
     */
    private function validate_object($group) {
        if (!is_object($group)) {
            return false;
        }
        if (!isset($group->timecreated)) {
            $group->timecreated = time();
        }
        if (!isset($group->timemodified)) {
            $group->timemodified = 0;
        }
        return isset($group->id)
            && $group->id >= 0
            && strlen($group->name) > 0
            && strstr($group->idnumber, 'autogroup|');
    }

    /**
     * Load from object.
     * @param \stdclass $group
     */
    private function load_from_object(\stdclass $group) {
        foreach ($this->attributes as $attribute) {
            $this->$attribute = $group->$attribute;
        }
    }

    /**
     * Get members.
     */
    private function get_members() {
        global $DB;
        $this->members = $DB->get_records_menu('groups_members', ['groupid' => $this->id], 'id', 'id,userid');
    }

    /**
     * Check that an user is member and add it if necessary.
     * @param int $userid
     * @return bool true if user has just been added as member, false otherwise.
     */
    public function ensure_user_is_member($userid) {
        foreach ($this->members as $member) {
            if ($member === $userid) {
                return false;
            }
        }

        // User was not found as a member so will now make member a user.
        \groups_add_member($this->as_object(), $userid, 'local_autogroup');
        return true;
    }

    /**
     *  As object.
     * @return \stdclass $group
     */
    private function as_object() {
        $group = new \stdclass();
        foreach ($this->attributes as $attribute) {
            $group->$attribute = $this->$attribute;
        }
        return $group;
    }

    /**
     * Check that an user is NOT member and remove it if necessary.
     * @param int $userid
     * @return bool true if user has just been removed, false otherwise.
     */
    public function ensure_user_is_not_member($userid) {
        // Do not allow autogroup to remove this User if they were manually assigned to group.
        $pluginconfig = get_config('local_autogroup');
        if ($pluginconfig->preservemanual) {
            global $DB;
            if ($DB->record_exists('local_autogroup_manual', ['userid' => $userid, 'groupid' => $this->id])) {
                return;
            }
        }

        foreach ($this->members as $member) {
            if ($member === $userid) {
                \groups_remove_member($this->as_object(), $userid);
                return true;
            }
        }
        return false;
    }

    /**
     * Membership count.
     * @return int
     */
    public function membership_count() {
        return count($this->members);
    }

    /**
     * Adds this group to the application if it hasn't been created already
     *
     * @return void
     */
    public function create() {
        if ($this->id == 0) {
            $this->id = (int)\groups_create_group($this->as_object());
        }
    }

    /**
     * Is valid autogroup.
     * @return bool   whether this group is an autogroup or not
     */
    public function is_valid_autogroup() {
        global $DB;
        if (!$this->is_autogroup()) {
            return false;
        }

        $idparts = explode('|', $this->idnumber);
        if (!isset($idparts[1])) {
            return false;
        }

        $groupsetid = (int)$idparts[1];
        if ($groupsetid < 1) {
            return false;
        }

        return $DB->record_exists('local_autogroup_set', ['id' => $groupsetid, 'courseid' => $this->courseid]);
    }

    /**
     * Is auto group.
     * @return bool   whether this group is an autogroup or not
     */
    private function is_autogroup() {
        return strstr($this->idnumber, 'autogroup|');
    }

    /**
     * Delete this group from the application
     * @return bool
     */
    public function remove() {
        if ($this->is_autogroup()) {
            return \groups_delete_group($this->id);
        }
        return false;
    }

    /**
     * Update this group from the application
     * @return bool
     */
    public function update() {
        if (!$this->exists()) {
            return false;
        }
        return \groups_update_group($this->as_object());
    }
}
