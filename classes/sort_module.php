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
 * This plugin automatically assigns users to a group within any course
 * upon which they may be enrolled and which has auto-grouping
 * configured.
 *
 * @package    local_autogroup
 * @copyright  Mark Ward (me@moodlemark.com)
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup;

use stdClass;

/**
 * Sort modules are currently only partially functional. They offer a
 * mechanism through which the logic of how we sort users can be switched.
 *
 * Eventually this would allow for groups to be created by various
 * rules, such as organising users by their cohorts, or by the badges they
 * have been awarded.
 *
 * @package local_autogroup
 */
abstract class sort_module {
    /** @var stdClass user */
    protected $user;
    /** @var int courseid */
    protected $courseid;
    /** @var array config */
    protected $config = [];
    /** @var array delimeters */
    protected $delimiters = [];

    /**
     * Constructor.
     * @param stdClass $config
     * @param int $courseid
     */
    abstract public function __construct($config, $courseid);

    /**
     * To string.
     * Child classes will probably override this method.
     * @return string
     */
    public function __toString() {
        return get_class($this);
    }

    /**
     * Eligible groups for user.
     * @param stdClass $user
     * @return array $result
     */
    abstract public function eligible_groups_for_user(stdClass $user);

    /**
     * Get config options.
     * Returns the options to be displayed on the autgroup_set
     * editing form. These are defined per-module.
     *
     * @return array
     */
    abstract public function get_config_options();

    /**
     * Getter.
     * @param string $attribute
     * @return array|null
     */
    public function __get($attribute) {
        if ($attribute == 'groups') {
            return $this->eligible_groups();
        }
        return null;
    }

    /**
     * Setter.
     * @param string $attribute
     * @param string $value
     * @return bool
     */
    public function __set($attribute, $value) {
        return false;
    }

    /**
     * Grouping by.
     * a string which explains how users are being grouped
     *
     * @return string
     */
    abstract public function grouping_by();

    /**
     * Grouping by text.
     * Return display string which explains how users are being grouped
     *
     * @return string
     */
    abstract public function grouping_by_text();

    /**
     * Get array of delimiter string.
     *
     * @return array
     */
    public function get_delimiter_options() {
        return array_combine($this->delimiters, $this->delimiters);
    }

    /**
     * Get delimiter string.
     *
     * @return string
     */
    public function delimited_by() {
        return '';
    }
}
