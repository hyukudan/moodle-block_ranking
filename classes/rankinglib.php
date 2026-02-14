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
 * Ranking block lib
 *
 * @package    block_ranking
 * @copyright  2020 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking;

use cache;
use user_picture;

/**
 * Ranking block lib class.
 *
 * @package    block_ranking
 * @copyright  2020 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class rankinglib {

    /**
     * Invalidate ranking caches for a given course.
     *
     * Deletes the most commonly used general ranking cache keys for this course.
     * Date-filtered caches (weekly/monthly) are left to expire via TTL (5 min).
     *
     * @param int $courseid
     * @return void
     */
    public static function invalidate_course_cache($courseid) {
        $cache = cache::make('block_ranking', 'course_ranking');

        // Delete the most common general ranking keys for this course.
        $commonsizes = [10, 20, 50, 100];
        $keys = [];
        foreach ($commonsizes as $size) {
            $keys[] = "general_{$courseid}_{$size}_0";
        }
        $cache->delete_many($keys);
    }

    /**
     * Get the start-of-week timestamp respecting user timezone and site calendar settings.
     *
     * @return int Unix timestamp of the beginning of the current week.
     */
    public static function get_week_start() {
        $now = time();
        $userdate = usergetdate($now);
        $usermidnight = usergetmidnight($now);
        $weekday = $userdate['wday']; // 0 = Sunday, 6 = Saturday.
        $startwday = (int) get_config('core', 'calendar_startwday');
        $daysback = ($weekday - $startwday + 7) % 7;

        return $usermidnight - ($daysback * DAYSECS);
    }

    /**
     * Get the start-of-month timestamp respecting user timezone.
     *
     * @return int Unix timestamp of the beginning of the current month.
     */
    public static function get_month_start() {
        $userdate = usergetdate(time());
        return make_timestamp($userdate['year'], $userdate['mon'], 1);
    }

    /**
     * Count total ranked students in the current course.
     *
     * @param int|null $groupid Optional group filter.
     * @return int
     */
    public function count_students($groupid = null) {
        global $COURSE, $DB, $PAGE;

        $context = $PAGE->context;

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return 0;
        }

        list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

        $sql = "SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            INNER JOIN {role_assignments} a ON a.userid = u.id
            INNER JOIN {ranking_points} r ON r.userid = u.id AND r.courseid = :r_courseid
            INNER JOIN {context} c ON c.id = a.contextid";

        $params = array_merge($roleparams, [
            'contextid' => $context->id,
            'courseid' => $COURSE->id,
            'r_courseid' => $COURSE->id,
        ]);

        if ($groupid) {
            $sql .= " INNER JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid";
            $params['groupid'] = $groupid;
        }

        $sql .= " WHERE a.contextid = :contextid
            AND a.roleid $rolesql
            AND c.instanceid = :courseid";

        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Count total ranked students by date range in the current course.
     *
     * @param int $datestart
     * @param int $dateend
     * @return int
     */
    public function count_students_by_date($datestart, $dateend) {
        global $COURSE, $DB, $PAGE;

        $context = $PAGE->context;

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return 0;
        }

        list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

        $sql = "SELECT COUNT(DISTINCT u.id)
            FROM {user} u
            INNER JOIN {role_assignments} a ON a.userid = u.id
            INNER JOIN {ranking_points} r ON r.userid = u.id AND r.courseid = :r_courseid
            INNER JOIN {ranking_logs} rl ON rl.rankingid = r.id
            INNER JOIN {context} c ON c.id = a.contextid
            WHERE a.contextid = :contextid
            AND a.roleid $rolesql
            AND c.instanceid = :courseid
            AND rl.timecreated BETWEEN :datestart AND :dateend";

        $params = array_merge($roleparams, [
            'contextid' => $context->id,
            'courseid' => $COURSE->id,
            'r_courseid' => $COURSE->id,
            'datestart' => $datestart,
            'dateend' => $dateend,
        ]);

        return $DB->count_records_sql($sql, $params);
    }

    /**
     * Return the list of students in the course ranking
     *
     * @param int $limit
     * @param int $groupid
     * @param int $offset
     *
     * @return mixed
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_students($limit = 10, $groupid = null, $offset = 0) {
        global $COURSE, $DB, $PAGE;

        $context = $PAGE->context;

        // Try cache first (only for non-offset requests — paginated requests skip cache).
        $cache = cache::make('block_ranking', 'course_ranking');
        $cachekey = "general_{$COURSE->id}_{$limit}_" . ($groupid ?? 0);
        $users = ($offset === 0) ? $cache->get($cachekey) : false;

        if ($users === false) {
            $roleids = block_ranking_helper::get_student_role_ids();
            if (empty($roleids)) {
                return get_string('nostudents', 'block_ranking');
            }

            list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

            $userfields = user_picture::fields('u', ['username']);
            $sql = "SELECT $userfields, r.points
                FROM
                    {user} u
                INNER JOIN {role_assignments} a ON a.userid = u.id
                INNER JOIN {ranking_points} r ON r.userid = u.id AND r.courseid = :r_courseid
                INNER JOIN {context} c ON c.id = a.contextid";

            $params = array_merge($roleparams, [
                'contextid' => $context->id,
                'courseid' => $COURSE->id,
                'r_courseid' => $COURSE->id
            ]);

            if ($groupid) {
                $sql .= " INNER JOIN {groups_members} gm ON gm.userid = u.id AND gm.groupid = :groupid";

                $params['groupid'] = $groupid;
            }

            $sql .= " WHERE a.contextid = :contextid
                AND a.roleid $rolesql
                AND c.instanceid = :courseid
                ORDER BY r.points DESC, u.firstname ASC";

            $users = array_values($DB->get_records_sql($sql, $params, $offset, $limit));
            if ($offset === 0) {
                $cache->set($cachekey, $users);
            }
        }

        return $this->get_aditionaldata($users, $offset);
    }

    /**
     * Get the students points based on a time interval
     *
     * @param int $datestart
     * @param int $dateend
     * @param int $limit
     * @param int $offset
     *
     * @return mixed
     *
     * @throws \coding_exception
     * @throws \dml_exception
     */
    public function get_students_by_date($datestart, $dateend, $limit = 10, $offset = 0) {
        global $COURSE, $DB, $PAGE;

        $context = $PAGE->context;

        // Try cache first (only for non-offset requests).
        $cache = cache::make('block_ranking', 'course_ranking');
        $cachekey = "dated_{$COURSE->id}_{$limit}_{$datestart}_{$dateend}";
        $users = ($offset === 0) ? $cache->get($cachekey) : false;

        if ($users === false) {
            $roleids = block_ranking_helper::get_student_role_ids();
            if (empty($roleids)) {
                return get_string('nostudents', 'block_ranking');
            }

            list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');

            $userfields = user_picture::fields('u', ['username']);
            $sql = "SELECT $userfields,
                    SUM(rl.points) as points
                FROM
                    {user} u
                INNER JOIN {role_assignments} a ON a.userid = u.id
                INNER JOIN {ranking_points} r ON r.userid = u.id AND r.courseid = :r_courseid
                INNER JOIN {ranking_logs} rl ON rl.rankingid = r.id
                INNER JOIN {context} c ON c.id = a.contextid
                WHERE a.contextid = :contextid
                AND a.roleid $rolesql
                AND c.instanceid = :courseid
                AND rl.timecreated BETWEEN :datestart AND :dateend
                GROUP BY u.id, $userfields
                ORDER BY points DESC, u.firstname ASC";

            $params = array_merge($roleparams, [
                'contextid' => $context->id,
                'courseid' => $COURSE->id,
                'r_courseid' => $COURSE->id,
                'datestart' => $datestart,
                'dateend' => $dateend,
            ]);

            $users = array_values($DB->get_records_sql($sql, $params, $offset, $limit));
            if ($offset === 0) {
                $cache->set($cachekey, $users);
            }
        }

        return $this->get_aditionaldata($users, $offset);
    }

    /**
     * Get the users aditional data.
     *
     * @param array $data
     * @param int $offset Starting offset for position calculation.
     *
     * @return string|array
     *
     * @throws \coding_exception
     */
    protected function get_aditionaldata($data, $offset = 0) {
        global $USER, $OUTPUT;

        if (empty($data)) {
            return get_string('nostudents', 'block_ranking');
        }

        // Find the max points for progress bar calculation.
        $maxpoints = 0;
        foreach ($data as $item) {
            if ($item->points > $maxpoints) {
                $maxpoints = (float) $item->points;
            }
        }

        $lastpos = $offset + 1;
        $lastpoints = current($data)->points;
        for ($i = 0; $i < count($data); $i++) {

            $data[$i]->isself = ($data[$i]->id == $USER->id);
            $data[$i]->class = $data[$i]->isself ? 'ranking-row-self' : '';

            if ($i > 0 && $lastpoints > $data[$i]->points) {
                $lastpos = $offset + $i + 1;
                $lastpoints = $data[$i]->points;
            }

            $data[$i]->position = $lastpos;
            $data[$i]->userpic = $OUTPUT->user_picture($data[$i], ['size' => 35, 'alttext' => false]);
            $data[$i]->fullname = fullname($data[$i]);

            // Medal classes for top 3.
            $data[$i]->isgold = ($lastpos === 1);
            $data[$i]->issilver = ($lastpos === 2);
            $data[$i]->isbronze = ($lastpos === 3);
            $data[$i]->istop3 = ($lastpos <= 3);

            // Progress bar percentage.
            $data[$i]->progresspct = ($maxpoints > 0) ? round(($data[$i]->points / $maxpoints) * 100) : 0;
        }

        return $data;
    }
}
