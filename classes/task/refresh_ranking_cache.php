<?php
// This file is part of Ranking block for Moodle - http://moodle.org/
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
 * Scheduled task to refresh the ranking cache.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ranking\task;

defined('MOODLE_INTERNAL') || die();

class refresh_ranking_cache extends \core\task\scheduled_task {

    public function get_name() {
        return get_string('pluginname', 'block_ranking') . ' - Refresh ranking cache';
    }

    public function execute() {
        global $DB;

        // Get all courses that have ranking points.
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {ranking_points} WHERE points > 0"
        );

        if (empty($courseids)) {
            return;
        }

        foreach ($courseids as $courseid) {
            $this->refresh_course_ranking($courseid);
        }
    }

    private function refresh_course_ranking($courseid) {
        global $DB;

        // Delete existing cache for this course.
        $DB->delete_records('ranking_cache', ['courseid' => $courseid]);

        // Use window function (MariaDB 10.2+) to calculate rankings efficiently.
        $sql = "SELECT courseid, userid, points,
                       RANK() OVER (ORDER BY points DESC) as position,
                       (SELECT COUNT(*) FROM {ranking_points} WHERE courseid = :courseid2 AND points > 0) as total_users
                FROM {ranking_points}
                WHERE courseid = :courseid AND points > 0
                ORDER BY points DESC";

        $rankings = $DB->get_records_sql($sql, [
            'courseid' => $courseid,
            'courseid2' => $courseid,
        ]);

        $now = time();
        foreach ($rankings as $ranking) {
            $record = new \stdClass();
            $record->courseid = $ranking->courseid;
            $record->userid = $ranking->userid;
            $record->points = $ranking->points;
            $record->position = $ranking->position;
            $record->total_users = $ranking->total_users;
            $record->last_updated = $now;
            $DB->insert_record('ranking_cache', $record);
        }
    }
}
