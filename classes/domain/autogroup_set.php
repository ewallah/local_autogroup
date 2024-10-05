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
 * Autogroup sets are currently restricted to a one-to-one relationship
 * with courses, however this class exists in order to facilitate any
 * future efforts to allow for multiple autogroup rules to be defined
 * per course.
 *
 * In theory a course could have multiple rules assigning users in
 * different roles to different groups.
 *
 * Each autogroup set links to a single sort module to determine which
 * groups a user should exist in.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\domain;

defined('MOODLE_INTERNAL') || die();

use local_autogroup\domain;
use local_autogroup\exception;
use local_autogroup\sort_module;
use moodle_database;
use stdClass;

require_once(__DIR__ . "/../../../../group/lib.php");

/**
 * Class sort
 * @package local_autogroup\domain
 */
class autogroup_set extends domain {
    /** @var array attributes */
    protected $attributes = [
        'id', 'courseid', 'sortmodule', 'sortconfig', 'timecreated', 'timemodified',
    ];
    /** @var int courseid */
    protected $courseid = 0;
    /** @var sort_module sortmodule */
    protected $sortmodule;
    /** @var string sortmodulename */
    protected $sortmodulename = 'local_autogroup\\sort_module\\profile_field';
    /** @var string sortmoduleshortname */
    protected $sortmoduleshortname = 'profile_field';
    /** @var stdClass sortconfig */
    protected $sortconfig;
    /** @var int timecreated */
    protected $timecreated = 0;
    /** @var int timemodified */
    protected $timemodified = 0;
    /** @var array groups  */
    private $groups = [];
    /** @var array roles  */
    private $roles = [];

    /**
     * Constructor.
     * @param \stdClass $autogroupset
     * @throws exception\invalid_autogroup_set_argument
     */
    public function __construct(stdClass $autogroupset = null) {
        // Set the sortconfig as empty.
        $this->sortconfig = new stdClass();

        // Get the id for this course.
        if ($this->validate_object($autogroupset)) {
            $this->load_from_object($autogroupset);
        }

        $this->initialise();

        if ($this->exists()) {
            // Load autogroup groups for this autogroup set.
            $this->get_autogroups();
        }

        $this->roles = $this->retrieve_applicable_roles();
    }

    /**
     * Validate object.
     * @param stdClass $autogroupset
     * @return bool
     */
    private function validate_object($autogroupset) {
        return is_object($autogroupset)
            && isset($autogroupset->id)
            && $autogroupset->id >= 0
            && isset($autogroupset->courseid)
            && $autogroupset->courseid > 0;
    }

    /**
     * Load from object.
     * @param stdClass $autogroupset
     */
    private function load_from_object(stdClass $autogroupset) {
        $this->id = (int)$autogroupset->id;

        $this->courseid = (int)$autogroupset->courseid;

        if (isset($autogroupset->sortmodule)) {
            $sortmodulename = 'local_autogroup\\sort_module\\' . $autogroupset->sortmodule;
            if (class_exists($sortmodulename)) {
                $this->sortmodulename = $sortmodulename;
                $this->sortmoduleshortname = $autogroupset->sortmodule;
            }
        }

        if (isset($autogroupset->sortconfig)) {
            $sortconfig = json_decode($autogroupset->sortconfig);
            if (json_last_error() == JSON_ERROR_NONE) {
                $this->sortconfig = $sortconfig;
            }
        }

        if (isset($autogroupset->timecreated)) {
            $this->timecreated = $autogroupset->timecreated;
        }
        if (isset($autogroupset->timemodified)) {
            $this->timemodified = $autogroupset->timemodified;
        }
    }

    /**
     * Initialise.
     */
    private function initialise() {
        $this->sortmodule = new $this->sortmodulename($this->sortconfig, $this->courseid);
    }

    /**
     * Set autogroups.
     */
    private function get_autogroups() {
        global $DB;
        $sql = "SELECT g.*" . PHP_EOL
            . "FROM {groups} g" . PHP_EOL
            . "WHERE g.courseid = :courseid" . PHP_EOL
            . "AND " . $DB->sql_like('g.idnumber', ':autogrouptag');
        $param = [
            'courseid' => $this->courseid,
            'autogrouptag' => $this->generate_group_idnumber('%'),
        ];

        $this->groups = $DB->get_records_sql($sql, $param);

        foreach ($this->groups as $k => $group) {
            try {
                $this->groups[$k] = new domain\group($group);
            } catch (exception\invalid_group_argument $e) {
                unset($this->groups[$k]);
            }
        }
    }

    /**
     * Generate group idnumber
     * @param string $groupname
     * @return string
     */
    private function generate_group_idnumber($groupname) {
        // Generate the idnumber for this group.
        $idnumber = implode(
            '|',
            [
                'autogroup',
                $this->id,
                $groupname,
            ]
        );
        return $idnumber;
    }

    /**
     * Retrieve applicable roles.
     * @return array  role ids which should be added to the group
     */
    private function retrieve_applicable_roles() {
        global $DB;
        $roles = $DB->get_records_menu('local_autogroup_roles', ['setid' => $this->id], 'id', 'id, roleid');

        if (empty($roles) && !$this->exists()) {
            $roles = $this->retrieve_default_roles();
        }

        return $roles;
    }

    /**
     * Retrieve default roles.
     * @return array  default eligible roleids
     */
    private function retrieve_default_roles() {
        $config = \get_config('local_autogroup');
        if ($roles = \get_all_roles()) {
            $roles = \role_fix_names($roles, null, ROLENAME_ORIGINAL);
            $newroles = [];
            foreach ($roles as $role) {
                $attributename = 'eligiblerole_' . $role->id;
                if (isset($config->$attributename) && $config->$attributename) {
                    $newroles[] = $role->id;
                }
            }
            return $newroles;
        }
        return false;
    }

    /**
     * Delete.
     * @param bool $cleanupgroups
     * @return bool
     */
    public function delete($cleanupgroups = true) {
        global $DB;
        if (!$this->exists()) {
            return false;
        }

        // This has to be done first to prevent event handler getting in the way.
        $DB->delete_records('local_autogroup_set', ['id' => $this->id]);
        $DB->delete_records('local_autogroup_roles', ['setid' => $this->id]);
        $DB->delete_records('local_autogroup_manual', ['groupid' => $this->id]);

        if ($cleanupgroups) {
            foreach ($this->groups as $k => $group) {
                $group->remove();
                unset($this->groups[$k]);
            }
        } else {
            $this->disassociate_groups();
        }

        return true;
    }

    /**
     * Disassociate groups.
     * Used to unlink generated groups from an autogroup set
     */
    public function disassociate_groups() {
        foreach ($this->groups as $k => $group) {
            $group->idnumber = '';
            $group->update();
            unset($this->groups[$k]);
        }
    }

    /**
     * Get eligible roles.
     * @return array
     */
    public function get_eligible_roles() {
        $cleanroles = [];
        foreach ($this->roles as $role) {
            $cleanroles[$role] = $role;
        }
        return $cleanroles;
    }

    /**
     * This function updates cached roles and returns true if a change has been made.
     *
     * @param array $newroles
     * @return bool
     */
    public function set_eligible_roles($newroles) {
        $oldroles = $this->roles;

        $this->roles = $newroles;

        // Detect changes and report back true or false.
        foreach ($this->roles as $role) {
            if ($key = array_search($role, $oldroles)) {
                // This will remain unchanged.
                unset($oldroles[$key]);
            } else {
                return true;
            }
        }

        // Will return true if a role has been removed.
        return (bool)count($oldroles);
    }

    /**
     * Returns the options to be displayed on the autgroup_set editing form.
     *
     * @return array
     */
    public function get_group_by_options() {
        return $this->sortmodule->get_config_options();
    }

    /**
     * Returns the options to be displayed on the autgroup_set editing form.
     *
     * @return array
     */
    public function get_delimited_by_options() {
        return $this->sortmodule->get_delimiter_options();
    }

    /**
     * Group count.
     * @return int  the count of groups linked to this groupset
     */
    public function get_group_count() {
        return count($this->groups);
    }

    /**
     * Membership counts.
     * @return array
     */
    public function get_membership_counts() {
        $result = [];
        foreach ($this->groups as $groupid => $group) {
            $result[$groupid] = $group->membership_count();
        }
        return $result;
    }

    /**
     * The actual value of the field this is currently grouping by.
     *
     * @return string
     */
    public function grouping_by() {
        return $this->sortmodule->grouping_by();
    }

    /**
     * Display name of the field this is currently grouping by.
     *
     * @return string
     */
    public function grouping_by_text() {
        return $this->sortmodule->grouping_by_text();
    }

    /**
     * Delimiter.
     *
     * @return string
     */
    public function delimited_by() {
        return $this->sortmodule->delimited_by();
    }

    /**
     * Set course.
     * @param int $courseid
     */
    public function set_course($courseid) {
        if (is_numeric($courseid) && (int)$courseid > 0) {
            $this->courseid = $courseid;
        }
    }

    /**
     * Set sort module for this groupset.
     *
     * @param string $sortmodule
     */
    public function set_sort_module($sortmodule = 'profile_field') {
        if ($this->sortmoduleshortname == $sortmodule) {
            return;
        }

        $this->sortmodulename = 'local_autogroup\\sort_module\\' . $sortmodule;
        $this->sortmoduleshortname = $sortmodule;

        $this->sortconfig = new stdClass();

        $this->sortmodule = new $this->sortmodulename($this->sortconfig, $this->courseid);
    }

    /**
     * Set options.
     * @param stdClass $config
     */
    public function set_options(stdClass $config) {
        if ($this->sortmodule->config_is_valid($config)) {
            $this->sortconfig = $config;

            // Reinit since the old sortmodule may be out of date.
            $this->initialise();
        }
    }

    /**
     * Verify user group membership
     * @param stdClass $user
     * @param \context_course $context
     * @return bool
     */
    public function verify_user_group_membership(stdClass $user, \context_course $context) {
        $eligiblegroups = [];

        // We only want to check with the sorting module if this user has the correct role assignment.
        if ($this->user_is_eligible_in_context($user->id, $context)) {
            // An array of strings from the sort module.
            $eligiblegroups = $this->sortmodule->eligible_groups_for_user($user);
        }

        // An array of groupids which will be populated as we ensure membership.
        $validgroups = [];
        $newgroup = false;

        foreach ($eligiblegroups as $eligiblegroup) {
            [$group, $groupcreated] = $this->get_or_create_group_by_idnumber($eligiblegroup);
            if ($group) {
                $validgroups[] = $group->id;
                $group->ensure_user_is_member($user->id);
                if ($group->courseid == $this->courseid) {
                    if (!$newgroup || $groupcreated) {
                        $newgroup = $group->id;
                    }
                }
            }
        }

        // Now run through other groups and ensure user is not a member.
        foreach ($this->groups as $key => $group) {
            if (!in_array($key, $validgroups)) {
                if ($group->ensure_user_is_not_member($user->id) && $newgroup) {
                    $this->update_forums($user->id, $group->id, $newgroup);
                }
            }
        }

        return true;
    }

    /**
     * Whether or not the user is eligible to be grouped by this autogroup set
     *
     * @param int $userid
     * @param \context_course $context
     * @return bool
     */
    private function user_is_eligible_in_context($userid, \context_course $context) {
        $roleassignments = \get_user_roles($context, $userid);

        foreach ($roleassignments as $role) {
            if (in_array($role->roleid, $this->roles)) {
                return true;
            }
        }
        return false;
    }

    /**
     * Get or create group by idnumber.
     * @param stdClass|string $group
     * @return [bool|domain/group, bool]
     */
    private function get_or_create_group_by_idnumber($group) {
        if (is_object($group) && isset($group->idnumber) && isset($group->friendlyname)) {
            $groupname = $group->friendlyname;
            $groupidnumber = $group->idnumber;
        } else {
            $groupidnumber = (string)$group;
            $groupname = ucfirst((string)$group);
        }

        $idnumber = $this->generate_group_idnumber($groupidnumber);

        // Firstly run through existing groups and check for matches.
        foreach ($this->groups as $group) {
            if ($group->idnumber == $idnumber) {
                if ($group->name != $groupname) {
                    $group->name = $groupname;
                    $group->update();
                }

                return [$group, false];
            }
        }

        // If we don't find a match, create a new group.
        $data = new \stdClass();
        $data->id = 0;
        $data->name = $groupname;
        $data->idnumber = $idnumber;
        $data->courseid = $this->courseid;
        $data->description = '';
        $data->descriptionformat = 0;
        $data->enrolmentkey = null;
        $data->picture = 0;
        $data->hidepicture = 0;

        try {
            $newgroup = new domain\group($data);
            $newgroup->create();
            $this->groups[$newgroup->id] = $newgroup;
        } catch (exception\invalid_group_argument $e) {
            return [false, false];
        }

        return [$this->groups[$newgroup->id], true];
    }

    /**
     * Create.
     * @return bool
     */
    public function create() {
        return $this->save();
    }

    /**
     * Save or create this autogroup set to the database
     * @param bool $cleanupold
     */
    public function save($cleanupold = true) {
        global $DB;
        $this->update_timestamps();

        $data = $this->as_object();
        $data->sortconfig = json_encode($data->sortconfig);
        if ($this->exists()) {
            $DB->update_record('local_autogroup_set', $data);
        } else {
            $this->id = $DB->insert_record('local_autogroup_set', $data);
        }

        $this->save_roles();

        // If the user wants to preserve old groups we will need to detatch them now.
        if (!$cleanupold) {
            $this->disassociate_groups();
        }
    }

    /**
     * As object.
     * @return stdClass $autogroupset
     */
    private function as_object() {
        $autogroupset = new \stdClass();
        foreach ($this->attributes as $attribute) {
            $autogroupset->$attribute = $this->$attribute;
        }

        // This is a special case because we dont want
        // to export the sort module, just the name of the module.
        $autogroupset->sortmodule = $this->sortmoduleshortname;
        return $autogroupset;
    }

    /**
     * Save roles.
     * This function builds a list of roles to add and a list of roles to
     * remove, before carrying out the action on the database. It will only
     * run if the autogroup_set exists since roles must be keyed against
     * the autogroup_set id.
     *
     * @return bool
     * @throws \coding_exception
     */
    private function save_roles() {
        global $DB;
        if (!$this->exists()) {
            return false;
        }

        $rolestoremove = $DB->get_records_menu(
            'local_autogroup_roles',
            ['setid' => $this->id],
            'id',
            'id, roleid'
        );
        $rolestoadd = [];

        foreach ($this->roles as $role) {
            if ($key = array_search($role, $rolestoremove)) {
                // We don't want to remove this from the DB.
                unset($rolestoremove[$key]);
            } else {
                // We want to add this to the DB.
                $newrow = new stdClass();
                $newrow->setid = $this->id;
                $newrow->roleid = $role;
                $rolestoadd[] = $newrow;
            }
        }

        $changed = false;

        if (count($rolestoremove)) {
            // If there are changes to make do them and return true.
            [$in, $params] = $DB->get_in_or_equal($rolestoremove);
            $params[] = $this->id;

            // If there are changes to make do them and return true.
            $sql = "DELETE FROM {local_autogroup_roles}" . PHP_EOL
                . "WHERE roleid " . $in . PHP_EOL
                . "AND setid = ?";

            $DB->execute($sql, $params);

            $changed = true;
        }

        if (count($rolestoadd)) {
            $DB->insert_records('local_autogroup_roles', $rolestoadd);
            $changed = true;
        }

        if ($changed) {
            $this->roles = $this->retrieve_applicable_roles();
        }

        return $changed;
    }

    /**
     * Replace forum_discussions groupid by a new one.
     * @param int $userid
     * @param int $oldgroupid
     * @param int $newgroupid
     */
    private function update_forums($userid, $oldgroupid, $newgroupid) {
        global $DB;
        $conditions = ['course' => $this->courseid, 'userid' => $userid, 'groupid' => $oldgroupid];
        $DB->set_field('forum_discussions', 'groupid', $newgroupid, $conditions);
    }
}
