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

        [$daystart, $dayend] = \block_ranking\notification_manager::get_local_day_bounds($targetdate);
        $courseids = $this->get_activity_course_ids($targetdate, $daystart, $dayend);

        if (empty($courseids)) {
            mtrace("block_ranking: no daily ranking digests pending for {$targetdate}");
            return;
        }

        foreach ($courseids as $courseid) {
            $this->process_course((int) $courseid, $targetdate, $now);
        }
    }

    /**
     * Get courses with ranking activity or pending daily states for a local day.
     *
     * @param int $localdate The site-local date in Ymd format.
     * @param int $daystart Inclusive start timestamp.
     * @param int $dayend Exclusive end timestamp.
     * @return int[]
     */
    protected function get_activity_course_ids($localdate, $daystart, $dayend) {
        global $DB;

        $sql = "SELECT DISTINCT courseid
                  FROM {block_ranking_daily_state}
                 WHERE localdate = :localdate
                   AND sent = 0
                 UNION
                SELECT DISTINCT courseid
                  FROM {ranking_logs}
                 WHERE timecreated >= :daystart
                   AND timecreated < :dayend";

        return $DB->get_fieldset_sql($sql, [
            'localdate' => $localdate,
            'daystart' => $daystart,
            'dayend' => $dayend,
        ]);
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

        [$daystart, $dayend] = \block_ranking\notification_manager::get_local_day_bounds($localdate);
        $startrankings = \block_ranking\notification_manager::get_course_rankings_at($courseid, $daystart);
        $endrankings = \block_ranking\notification_manager::get_course_rankings_at($courseid, $dayend);

        $created = $this->create_missing_daily_states(
            $courseid,
            $localdate,
            $now,
            $startrankings,
            $endrankings
        );

        $states = $DB->get_records(
            'block_ranking_daily_state',
            ['courseid' => $courseid, 'localdate' => $localdate, 'sent' => 0],
            'userid ASC'
        );

        $sent = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($states as $state) {
            $final = $endrankings[$state->userid] ?? null;
            $state = $this->finalise_state($state, $final, $now);

            $shouldsend = $this->has_relevant_change($state)
                && \block_ranking\notification_manager::is_daily_digest_recipient($state->userid, $courseid);

            if (!$shouldsend) {
                $state->sent = 1;
                $state->timesent = $now;
                $DB->update_record('block_ranking_daily_state', $state);
                $skipped++;
                continue;
            }

            $state->sent = 0;
            $state->timesent = 0;
            $DB->update_record('block_ranking_daily_state', $state);

            if (\block_ranking\notification_manager::send_daily_digest($state, $course)) {
                $state->sent = 1;
                $state->timesent = $now;
                $state->timemodified = $now;
                $DB->update_record('block_ranking_daily_state', $state);
                $sent++;
            } else {
                $failed++;
            }
        }

        mtrace("block_ranking: sent {$sent} daily ranking digests for course {$courseid}; " .
            "skipped {$skipped}; failed {$failed}; created {$created}");
    }

    /**
     * Create daily states for users whose position changed without earning points.
     *
     * @param int $courseid The course.
     * @param int $localdate The site-local date in Ymd format.
     * @param int $now Current timestamp.
     * @param \stdClass[] $startrankings Rankings keyed by user id at day start.
     * @param \stdClass[] $endrankings Rankings keyed by user id at day end.
     * @return int Number of rows inserted.
     */
    protected function create_missing_daily_states($courseid, $localdate, $now, array $startrankings, array $endrankings) {
        global $DB;

        $existinguserids = array_fill_keys($DB->get_fieldset_select(
            'block_ranking_daily_state',
            'userid',
            'courseid = :courseid AND localdate = :localdate',
            ['courseid' => $courseid, 'localdate' => $localdate]
        ), true);

        $userids = array_unique(array_merge(array_keys($startrankings), array_keys($endrankings)));
        sort($userids, SORT_NUMERIC);

        $created = 0;
        foreach ($userids as $userid) {
            if (isset($existinguserids[$userid])) {
                continue;
            }

            $start = $startrankings[$userid] ?? null;
            $end = $endrankings[$userid] ?? null;

            $state = (object) [
                'courseid' => $courseid,
                'userid' => (int) $userid,
                'localdate' => $localdate,
                // Users without an observer-created row use the reconstructed day-start ranking,
                // equivalent to the previous day's closing position from current points minus later logs.
                'startposition' => $start ? (int) $start->position : 0,
                'startpoints' => $start ? (float) $start->points : 0,
                'endposition' => 0,
                'endpoints' => 0,
                'enteredtop3' => 0,
                'lefttop3' => 0,
                'wasovertaken' => 0,
                'positionslost' => 0,
                'positionsgained' => 0,
                'sent' => 0,
                'timesent' => 0,
                'timecreated' => $now,
                'timemodified' => $now,
            ];
            $state = $this->finalise_state($state, $end, $now);

            if (!$this->has_relevant_change($state)) {
                continue;
            }

            try {
                $DB->insert_record('block_ranking_daily_state', $state);
                $existinguserids[$userid] = true;
                $created++;
            } catch (\dml_write_exception $e) {
                debugging('block_ranking: Failed to create daily ranking state - ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        return $created;
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
