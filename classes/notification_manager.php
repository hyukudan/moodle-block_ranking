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
 * Notification manager for block_ranking.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking;

/**
 * Handles sending ranking-related notifications via Moodle Message API.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_manager {

    /**
     * Notify a user that they reached the top 3.
     *
     * @param int $userid The user to notify.
     * @param int $courseid The course.
     * @return void
     */
    public static function notify_top3($userid, $courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            debugging('block_ranking: Cannot send top3 notification - course ' . $courseid . ' not found', DEBUG_DEVELOPER);
            return;
        }

        $message = new \core\message\message();
        $message->component = 'block_ranking';
        $message->name = 'ranking_update';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userid;
        $message->subject = get_string('ranking', 'block_ranking');
        $message->fullmessage = get_string('notification_top3', 'block_ranking', $course->fullname);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . get_string('notification_top3', 'block_ranking', $course->fullname) . '</p>';
        $message->smallmessage = get_string('notification_top3', 'block_ranking', $course->fullname);
        $message->notification = 1;
        $message->contexturl = new \moodle_url('/course/view.php', ['id' => $courseid]);
        $message->contexturlname = $course->fullname;
        $message->courseid = $courseid;

        $result = message_send($message);
        if (!$result) {
            debugging('block_ranking: Failed to send top3 notification to user ' . $userid, DEBUG_DEVELOPER);
        }
    }

    /**
     * Notify a user that someone overtook them in the ranking.
     *
     * @param int $userid The user who was overtaken.
     * @param int $overtakenbyid The user who overtook.
     * @param int $courseid The course.
     * @return void
     */
    public static function notify_overtaken($userid, $overtakenbyid, $courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        $overtaker = $DB->get_record('user', ['id' => $overtakenbyid]);
        if (!$course || !$overtaker) {
            debugging('block_ranking: Cannot send overtaken notification - course or user not found', DEBUG_DEVELOPER);
            return;
        }

        $a = new \stdClass();
        $a->username = fullname($overtaker);
        $a->coursename = $course->fullname;

        $message = new \core\message\message();
        $message->component = 'block_ranking';
        $message->name = 'ranking_update';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $userid;
        $message->subject = get_string('ranking', 'block_ranking');
        $message->fullmessage = get_string('notification_overtaken', 'block_ranking', $a);
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = '<p>' . get_string('notification_overtaken', 'block_ranking', $a) . '</p>';
        $message->smallmessage = get_string('notification_overtaken', 'block_ranking', $a);
        $message->notification = 1;
        $message->contexturl = new \moodle_url('/course/view.php', ['id' => $courseid]);
        $message->contexturlname = $course->fullname;
        $message->courseid = $courseid;

        $result = message_send($message);
        if (!$result) {
            debugging('block_ranking: Failed to send overtaken notification to user ' . $userid, DEBUG_DEVELOPER);
        }
    }
}
