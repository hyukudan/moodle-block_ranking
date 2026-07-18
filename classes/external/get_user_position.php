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
 * External function: get user ranking position.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\external;

use block_ranking\block_ranking_helper;
use core\context\course as context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the user's current ranking position and points in a course.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_position extends external_api {

    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course ID'),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid
     * @return array
     */
    public static function execute($courseid) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), ['courseid' => $courseid]);
        $courseid = $params['courseid'];

        require_login($courseid);
        $context = context_course::instance($courseid);
        self::validate_context($context);

        if (block_ranking_helper::is_staff($USER->id, $courseid)) {
            return [
                'position' => 0,
                'points' => 0,
                'totalstudents' => 0,
            ];
        }

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return [
                'position' => 0,
                'points' => 0,
                'totalstudents' => 0,
            ];
        }

        list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        list($staffsql, $staffparams) = block_ranking_helper::get_staff_exclusion_sql('u.id', $context, 'positionstaff');

        // Get user's points.
        $sql = "SELECT DISTINCT rp.*
                  FROM {ranking_points} rp
                  JOIN {user} u ON u.id = rp.userid
                  JOIN {role_assignments} ra ON ra.userid = u.id
                 WHERE rp.userid = :userid
                   AND rp.courseid = :courseid
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND ra.contextid = :contextid
                   AND ra.roleid $rolesql
                   $staffsql";

        $params = array_merge($roleparams, $staffparams, [
            'userid' => $USER->id,
            'courseid' => $courseid,
            'contextid' => $context->id,
        ]);

        $userpoints = $DB->get_record_sql($sql, $params);
        if (!$userpoints) {
            return [
                'position' => 0,
                'points' => 0,
                'totalstudents' => 0,
            ];
        }

        // Calculate position.
        list($countstaffsql, $countstaffparams) = block_ranking_helper::get_staff_exclusion_sql(
            'u.id',
            $context,
            'positioncountstaff'
        );
        $countsql = "SELECT COUNT(DISTINCT rp.userid)
                       FROM {ranking_points} rp
                       JOIN {user} u ON u.id = rp.userid
                       JOIN {role_assignments} ra ON ra.userid = u.id
                      WHERE rp.courseid = :courseid
                        AND rp.points > :points
                        AND u.deleted = 0
                        AND u.suspended = 0
                        AND ra.contextid = :contextid
                        AND ra.roleid $rolesql
                        $countstaffsql";
        $countparams = array_merge($roleparams, $countstaffparams, [
            'courseid' => $courseid,
            'points' => $userpoints->points,
            'contextid' => $context->id,
        ]);
        $position = $DB->count_records_sql($countsql, $countparams) + 1;

        list($totalstaffsql, $totalstaffparams) = block_ranking_helper::get_staff_exclusion_sql(
            'u.id',
            $context,
            'positiontotalstaff'
        );
        $totalsql = "SELECT COUNT(DISTINCT rp.userid)
                       FROM {ranking_points} rp
                       JOIN {user} u ON u.id = rp.userid
                       JOIN {role_assignments} ra ON ra.userid = u.id
                      WHERE rp.courseid = :courseid
                        AND u.deleted = 0
                        AND u.suspended = 0
                        AND ra.contextid = :contextid
                        AND ra.roleid $rolesql
                        $totalstaffsql";
        $totalparams = array_merge($roleparams, $totalstaffparams, [
            'courseid' => $courseid,
            'contextid' => $context->id,
        ]);
        $totalstudents = $DB->count_records_sql($totalsql, $totalparams);

        return [
            'position' => (int) $position,
            'points' => (float) $userpoints->points,
            'totalstudents' => (int) $totalstudents,
        ];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'position' => new external_value(PARAM_INT, 'User position in ranking (0 if not ranked)'),
            'points' => new external_value(PARAM_FLOAT, 'User total points'),
            'totalstudents' => new external_value(PARAM_INT, 'Total students in ranking'),
        ]);
    }
}
