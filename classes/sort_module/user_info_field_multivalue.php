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
 * @copyright  2023 Catalyst IT Australia Pty Ltd
 * @author     Tomo Tsuyuki <tomotsuyuki@catalyst-au.net>
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace local_autogroup\sort_module;

use local_autogroup\sort_module;
use stdClass;

/**
 * Class course
 * @package local_autogroup\domain
 */
class user_info_field_multivalue extends sort_module {
    /** @var string field */
    private $field = '';
    /** @var string delimeter */
    private $delimiter = '';
    /** @var array delimeters */
    protected $delimiters = [',', '|', ';'];

    /**
     * Constructor.
     * @param stdClass $config
     * @param int $courseid
     */
    public function __construct($config, $courseid) {
        if ($this->config_is_valid($config)) {
            $this->field = $config->field;
            $this->delimiter = empty($config->delimiter) ? '' : $config->delimiter;
        }
        $this->courseid = (int)$courseid;
    }

    /**
     * Config is valid.
     * @param stdClass $config
     * @return bool
     */
    public function config_is_valid(stdClass $config) {
        if (!isset($config->field)) {
            return false;
        }

        // Ensure that the stored option is valid.
        if (array_key_exists($config->field, $this->get_config_options())) {
            return true;
        }

        return false;
    }

    /**
     * Get config options.
     * Returns the options to be displayed on the autgroup_set
     * editing form. These are defined per-module.
     *
     * @return array
     */
    public function get_config_options() {
        global $DB;

        $options = [];
        $infofields = $DB->get_records('user_info_field', ['datatype' => 'text']);

        foreach ($infofields as $field) {
            $options[$field->id] = $field->name;
        }
        return $options;
    }

    /**
     * Eligible groups for users.
     * @param stdClass $user
     * @return array $result
     */
    public function eligible_groups_for_user(stdClass $user) {
        global $DB;

        $field = $this->field;
        if (empty($field)) {
            return [];
        }
        $data = $DB->get_record('user_info_data', ['fieldid' => $field, 'userid' => $user->id]);
        if ($data && !empty($data->data)) {
            $delimiteddata = explode($this->delimiter, $data->data);
            $trimmeddata = array_map('trim', $delimiteddata);
            return $trimmeddata;
        }
        return [];
    }

    /**
     * Grouping by.
     * @return bool|string
     */
    public function grouping_by() {
        return empty($this->field) ? false : $this->field;
    }

    /**
     * Grouping by text.
     * @return bool|string
     */
    public function grouping_by_text() {
        global $DB;
        if (empty($this->field)) {
            return false;
        }

        $field = $DB->get_field('user_info_field', 'name', ['id' => $this->field]);
        if (empty($field)) {
            return false;
        }
        return (string)$field;
    }

    /**
     * Get delimiter string.
     *
     * @return string
     */
    public function delimited_by() {
        return $this->delimiter;
    }
}
