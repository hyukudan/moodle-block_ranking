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
 * Daily ranking digest scheduled task.
 *
 * @package    block_ranking
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Sends one daily ranking digest per user/course when a relevant change happened.
 *
 * @package    block_ranking
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class daily_ranking_digest extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_daily_ranking_digest', 'block_ranking');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        $targetdate = \block_ranking\notification_manager::get_last_closed_local_date();
        $now = time();

        $this->discard_older_pending_states($targetdate, $now);

        $courseids = $DB->get_fieldset_select(
            'block_ranking_daily_state',
            'DISTINCT courseid',
            'localdate = :localdate AND sent = 0',
            ['localdate' => $targetdate]
        );

        if (empty($courseids)) {
            mtrace("block_ranking: no daily ranking digests pending for {$targetdate}");
            return;
        }

        foreach ($courseids as $courseid) {
            $this->process_course((int) $courseid, $targetdate, $now);
        }
    }

    /**
     * Mark old pending states as handled without sending backlogged digests.
     *
     * @param int $targetdate Last closed site-local date in Ymd format.
     * @param int $now Current timestamp.
     */
    protected function discard_older_pending_states($targetdate, $now) {
        global $DB;

        $DB->execute(
            "UPDATE {block_ranking_daily_state}
                SET sent = 1,
                    timesent = :timesent,
                    timemodified = :timemodified
              WHERE sent = 0
                AND localdate < :targetdate",
            [
                'timesent' => $now,
                'timemodified' => $now,
                'targetdate' => $targetdate,
            ]
        );
    }

    /**
     * Process pending daily states for a course.
     *
     * @param int $courseid The course.
     * @param int $localdate The site-local date in Ymd format.
     * @param int $now Current timestamp.
     */
    protected function process_course($courseid, $localdate, $now) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            $this->mark_course_states_sent($courseid, $localdate, $now);
            return;
        }

        [, $dayend] = \block_ranking\notification_manager::get_local_day_bounds($localdate);
        $rankings = \block_ranking\notification_manager::get_course_rankings_at($courseid, $dayend);

        $states = $DB->get_records(
            'block_ranking_daily_state',
            ['courseid' => $courseid, 'localdate' => $localdate, 'sent' => 0],
            'userid ASC'
        );

        $sent = 0;
        $skipped = 0;

        foreach ($states as $state) {
            $final = $rankings[$state->userid] ?? null;
            $state = $this->finalise_state($state, $final, $now);

            $shouldsend = $this->has_relevant_change($state)
                && \block_ranking\notification_manager::is_daily_digest_recipient($state->userid, $courseid);

            $DB->update_record('block_ranking_daily_state', $state);

            if ($shouldsend && \block_ranking\notification_manager::send_daily_digest($state, $course)) {
                $sent++;
            } else {
                $skipped++;
            }
        }

        mtrace("block_ranking: sent {$sent} daily ranking digests for course {$courseid}; skipped {$skipped}");
    }

    /**
     * Close a daily state with final ranking values and aggregate flags.
     *
     * @param \stdClass $state Daily state record.
     * @param \stdClass|null $final Final ranking record.
     * @param int $now Current timestamp.
     * @return \stdClass
     */
    protected function finalise_state(\stdClass $state, $final, $now) {
        $startposition = (int) $state->startposition;
        $endposition = $final ? (int) $final->position : 0;

        $state->endposition = $endposition;
        $state->endpoints = $final ? (float) $final->points : 0;
        $state->positionsgained = 0;
        $state->positionslost = 0;

        if ($startposition > 0 && $endposition > 0) {
            if ($endposition < $startposition) {
                $state->positionsgained = $startposition - $endposition;
            } else if ($endposition > $startposition) {
                $state->positionslost = $endposition - $startposition;
            }
        }

        $state->enteredtop3 = ($endposition > 0 && $endposition <= 3 && ($startposition === 0 || $startposition > 3)) ? 1 : 0;
        $state->lefttop3 = ($startposition > 0 && $startposition <= 3 && ($endposition === 0 || $endposition > 3)) ? 1 : 0;
        $state->wasovertaken = ((int) $state->positionslost > 0) ? 1 : 0;
        $state->sent = 1;
        $state->timesent = $now;
        $state->timemodified = $now;

        return $state;
    }

    /**
     * Whether the state merits a non-empty digest.
     *
     * @param \stdClass $state Daily state record.
     * @return bool
     */
    protected function has_relevant_change(\stdClass $state) {
        return !empty($state->positionsgained)
            || !empty($state->positionslost)
            || !empty($state->enteredtop3)
            || !empty($state->lefttop3);
    }

    /**
     * Mark all pending states for a missing course as sent.
     *
     * @param int $courseid The course.
     * @param int $localdate The local date.
     * @param int $now Current timestamp.
     */
    protected function mark_course_states_sent($courseid, $localdate, $now) {
        global $DB;

        $DB->execute(
            "UPDATE {block_ranking_daily_state}
                SET sent = 1,
                    timesent = :timesent,
                    timemodified = :timemodified
              WHERE courseid = :courseid
                AND localdate = :localdate
                AND sent = 0",
            [
                'timesent' => $now,
                'timemodified' => $now,
                'courseid' => $courseid,
                'localdate' => $localdate,
            ]
        );
    }
}
