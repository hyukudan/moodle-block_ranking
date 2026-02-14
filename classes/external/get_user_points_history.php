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
 * External function: get user points history.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\external;

use core\context\course as context_course;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_multiple_structure;
use core_external\external_single_structure;
use core_external\external_value;

/**
 * Returns the points history (log entries) for the current user in a course.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class get_user_points_history extends external_api {

    /**
     * Define parameters.
     *
     * @return external_function_parameters
     */
    public static function execute_parameters() {
        return new external_function_parameters([
            'courseid' => new external_value(PARAM_INT, 'The course ID'),
            'limit' => new external_value(PARAM_INT, 'Max records to return', VALUE_DEFAULT, 50),
        ]);
    }

    /**
     * Execute the function.
     *
     * @param int $courseid
     * @param int $limit
     * @return array
     */
    public static function execute($courseid, $limit = 50) {
        global $DB, $USER;

        $params = self::validate_parameters(self::execute_parameters(), [
            'courseid' => $courseid,
            'limit' => $limit,
        ]);
        $courseid = $params['courseid'];
        $limit = $params['limit'];

        require_login($courseid);
        $context = context_course::instance($courseid);
        self::validate_context($context);

        $sql = "SELECT rl.id, rl.points, rl.timecreated, rl.course_modules_completion,
                       m.name as modulename, cm.instance
                  FROM {ranking_logs} rl
                  JOIN {ranking_points} rp ON rp.id = rl.rankingid
             LEFT JOIN {course_modules_completion} cmc ON cmc.id = rl.course_modules_completion
             LEFT JOIN {course_modules} cm ON cm.id = cmc.coursemoduleid
             LEFT JOIN {modules} m ON m.id = cm.module
                 WHERE rp.userid = :userid AND rp.courseid = :courseid
                 ORDER BY rl.timecreated DESC";

        $records = $DB->get_records_sql($sql, [
            'userid' => $USER->id,
            'courseid' => $courseid,
        ], 0, $limit);

        $history = [];
        foreach ($records as $record) {
            $history[] = [
                'id' => (int) $record->id,
                'points' => (float) $record->points,
                'timecreated' => (int) $record->timecreated,
                'activitytype' => $record->modulename ?? '',
            ];
        }

        return ['entries' => $history];
    }

    /**
     * Define return structure.
     *
     * @return external_single_structure
     */
    public static function execute_returns() {
        return new external_single_structure([
            'entries' => new external_multiple_structure(
                new external_single_structure([
                    'id' => new external_value(PARAM_INT, 'Log entry ID'),
                    'points' => new external_value(PARAM_FLOAT, 'Points awarded'),
                    'timecreated' => new external_value(PARAM_INT, 'Timestamp of when points were awarded'),
                    'activitytype' => new external_value(PARAM_TEXT, 'Activity module type name'),
                ])
            ),
        ]);
    }
}
