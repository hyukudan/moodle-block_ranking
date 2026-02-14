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
 * Weekly summary scheduled task for block_ranking.
 *
 * Sends a weekly ranking position summary notification to all ranked users.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\task;

/**
 * Scheduled task that sends weekly ranking summaries to users.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weekly_summary extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_weekly_summary', 'block_ranking');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        // Get all courses that have ranking points.
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {ranking_points}"
        );

        if (empty($courseids)) {
            return;
        }

        foreach ($courseids as $courseid) {
            $course = $DB->get_record('course', ['id' => $courseid]);
            if (!$course) {
                continue;
            }

            $this->send_course_summaries($courseid, $course);
        }
    }

    /**
     * Send weekly summaries for a specific course.
     *
     * @param int $courseid
     * @param \stdClass $course
     */
    protected function send_course_summaries($courseid, $course) {
        global $DB;

        // Get all ranked users ordered by points.
        $rankedusers = $DB->get_records('ranking_points', ['courseid' => $courseid], 'points DESC');

        if (empty($rankedusers)) {
            return;
        }

        $position = 0;
        $lastpoints = null;
        $sent = 0;

        foreach ($rankedusers as $record) {
            if ($lastpoints === null || (float) $record->points < $lastpoints) {
                $position++;
                $lastpoints = (float) $record->points;
            }

            $a = new \stdClass();
            $a->coursename = $course->fullname;
            $a->position = $position;
            $a->points = $record->points;

            $message = new \core\message\message();
            $message->component = 'block_ranking';
            $message->name = 'ranking_update';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $record->userid;
            $message->subject = get_string('ranking', 'block_ranking');
            $message->fullmessage = get_string('notification_weekly_summary', 'block_ranking', $a);
            $message->fullmessageformat = FORMAT_PLAIN;
            $message->fullmessagehtml = '<p>' .
                get_string('notification_weekly_summary', 'block_ranking', $a) . '</p>';
            $message->smallmessage = get_string('notification_weekly_summary', 'block_ranking', $a);
            $message->notification = 1;
            $message->contexturl = new \moodle_url('/blocks/ranking/report.php', ['courseid' => $courseid]);
            $message->contexturlname = $course->fullname;
            $message->courseid = $courseid;

            try {
                message_send($message);
                $sent++;
            } catch (\Exception $e) {
                debugging('block_ranking: Failed to send weekly summary to user ' .
                    $record->userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        mtrace("block_ranking: Sent $sent weekly summaries for course $courseid ({$course->shortname})");
    }
}
