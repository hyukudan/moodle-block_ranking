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
 * Block ranking helper
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking;

/**
 * Block ranking helper class
 *
 * @copyright 2017 Willian Mano http://conecti.me
 * @license   http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block_ranking_helper {

    /**
     * Observe the events, and dispatch them if necessary.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    public static function observer(\core\event\base $event) {
        try {
            self::process_event($event);
        } catch (\Exception $e) {
            debugging(
                'block_ranking: Observer failed for event ' . $event->eventname .
                ' (user=' . $event->relateduserid . ', course=' . $event->courseid . '): ' .
                $e->getMessage(),
                DEBUG_DEVELOPER
            );
            throw $e;
        }
    }

    /**
     * Process the event internally.
     *
     * @param \core\event\base $event The event.
     * @return void
     */
    protected static function process_event(\core\event\base $event) {
        global $DB;

        if (!self::is_student($event->relateduserid, $event->courseid)) {
            return;
        }

        if ($event->eventname == '\mod_quiz\event\attempt_submitted') {

            $enablemultipleattempts = get_config('block_ranking', 'enable_multiple_quizz_attempts');

            if ($enablemultipleattempts !== false && (int)$enablemultipleattempts === 0) {
                $isrepeated = self::is_completion_repeated($event->courseid, $event->relateduserid, $event->contextinstanceid);

                if ($isrepeated) {
                    return;
                }
            }

            $objectid = self::get_coursemodule_instance($event->contextinstanceid, $event->relateduserid);

            if (!$objectid) {
                debugging(
                    'block_ranking: Quiz completion not found or not completed for ' .
                    'cmid=' . $event->contextinstanceid . ', userid=' . $event->relateduserid,
                    DEBUG_DEVELOPER
                );
                return;
            }

            $grade = self::get_quiz_grade($event->objectid);
            manager::add_user_points($objectid, $grade);

            return;
        }

        // Use event data to check completion state (avoids a DB query).
        $eventdata = $event->get_data();
        $completionstate = $eventdata['other']['completionstate'] ?? null;
        if ($completionstate !== null) {
            if (empty($completionstate)) {
                return;
            }
        } else {
            // Fallback for events that don't carry completionstate.
            if (!self::is_completion_completed($event->objectid)) {
                return;
            }
        }

        if (self::is_completion_repeated($event->courseid, $event->relateduserid, $event->contextinstanceid)) {
            return;
        }

        manager::add_user_points($event->objectid);
    }

    /**
     * Get the configured student role IDs.
     *
     * Returns the role IDs configured in the plugin settings, falling back
     * to all roles with the 'student' archetype if not configured.
     *
     * @return array Array of role IDs
     */
    public static function get_student_role_ids() {
        static $cachedids = null;
        if ($cachedids !== null) {
            return $cachedids;
        }

        $config = get_config('block_ranking', 'student_roles');

        if (!empty($config)) {
            // configmulticheckbox stores as comma-separated string of role IDs.
            $cachedids = array_map('intval', array_filter(explode(',', $config)));
        } else {
            // Fallback: use all roles with 'student' archetype.
            $cachedids = array_keys(get_archetype_roles('student'));
        }
        return $cachedids;
    }

    /**
     * Check whether the user is staff for the course.
     *
     * @param int $userid
     * @param int $courseid
     * @return bool
     */
    public static function is_staff($userid, $courseid) {
        if (empty($userid) || empty($courseid)) {
            return false;
        }

        if (is_siteadmin($userid)) {
            return true;
        }

        $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$coursecontext) {
            return false;
        }

        return has_capability('moodle/course:update', $coursecontext, $userid);
    }

    /**
     * Get site administrator user IDs.
     *
     * @return int[]
     */
    public static function get_site_admin_ids() {
        global $CFG;

        if (empty($CFG->siteadmins)) {
            return [];
        }

        return array_values(array_filter(array_map('intval', explode(',', $CFG->siteadmins))));
    }

    /**
     * Get role IDs that grant course update capability in the supplied context.
     *
     * @param \context|null $context
     * @return int[]
     */
    public static function get_staff_role_ids($context = null) {
        static $cachedids = [];

        $cachekey = $context ? $context->id : 0;
        if (isset($cachedids[$cachekey])) {
            return $cachedids[$cachekey];
        }

        $roles = get_roles_with_capability('moodle/course:update', CAP_ALLOW, $context);
        $cachedids[$cachekey] = array_map('intval', array_keys($roles));

        return $cachedids[$cachekey];
    }

    /**
     * Build SQL to exclude site admins and users with staff roles in a course context.
     *
     * This is a cheap defensive filter for leaderboard queries. Exact capability
     * evaluation remains in the award path.
     *
     * @param string $useridexpr SQL expression that resolves to the user ID.
     * @param \context_course $context Course context.
     * @param string $prefix Unique parameter prefix.
     * @return array SQL fragment and named parameters.
     */
    public static function get_staff_exclusion_sql($useridexpr, \context_course $context, $prefix = 'staff') {
        global $DB;

        $conditions = [];
        $params = [];

        $adminids = self::get_site_admin_ids();
        if (!empty($adminids)) {
            list($adminsql, $adminparams) = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, $prefix . 'admin', false);
            $conditions[] = "{$useridexpr} {$adminsql}";
            $params = array_merge($params, $adminparams);
        }

        $assignmentpathmatch = $DB->sql_like(
            ":{$prefix}rapath",
            $DB->sql_concat("{$prefix}ractx.path", ":{$prefix}rapathsuffix")
        );
        $capabilitypathmatch = $DB->sql_like(
            ":{$prefix}rcpath",
            $DB->sql_concat("{$prefix}rcctx.path", ":{$prefix}rcpathsuffix")
        );

        $conditions[] = "NOT EXISTS (
                    SELECT 1
                      FROM {role_assignments} {$prefix}ra
                      JOIN {context} {$prefix}ractx ON {$prefix}ractx.id = {$prefix}ra.contextid
                      JOIN {role_capabilities} {$prefix}rc ON {$prefix}rc.roleid = {$prefix}ra.roleid
                      JOIN {context} {$prefix}rcctx ON {$prefix}rcctx.id = {$prefix}rc.contextid
                     WHERE {$prefix}ra.userid = {$useridexpr}
                       AND {$prefix}rc.capability = :{$prefix}capability
                       AND {$prefix}rc.permission = :{$prefix}permission
                       AND (:{$prefix}raexactpath = {$prefix}ractx.path OR {$assignmentpathmatch})
                       AND (:{$prefix}rcexactpath = {$prefix}rcctx.path OR {$capabilitypathmatch})
                )";
        $params[$prefix . 'capability'] = 'moodle/course:update';
        $params[$prefix . 'permission'] = CAP_ALLOW;
        $params[$prefix . 'raexactpath'] = $context->path;
        $params[$prefix . 'rapath'] = $context->path;
        $params[$prefix . 'rapathsuffix'] = '/%';
        $params[$prefix . 'rcexactpath'] = $context->path;
        $params[$prefix . 'rcpath'] = $context->path;
        $params[$prefix . 'rcpathsuffix'] = '/%';

        if (empty($conditions)) {
            return ['', []];
        }

        return [" AND " . implode("\n                AND ", $conditions), $params];
    }

    /**
     * Verify if the user is a student in the given course.
     *
     * @param int $userid
     * @param int $courseid
     *
     * @return boolean
     */
    protected static function is_student($userid, $courseid = 0) {
        global $DB;

        $roleids = self::get_student_role_ids();
        if (empty($roleids)) {
            return false;
        }

        list($insql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $params['userid'] = $userid;

        if ($courseid > 0) {
            $coursecontext = \context_course::instance($courseid, IGNORE_MISSING);
            if (!$coursecontext) {
                return false;
            }
            $params['contextid'] = $coursecontext->id;

            $sql = "SELECT COUNT(1)
                      FROM {role_assignments}
                     WHERE userid = :userid AND roleid $insql AND contextid = :contextid";
        } else {
            $sql = "SELECT COUNT(1)
                      FROM {role_assignments}
                     WHERE userid = :userid AND roleid $insql";
        }

        return $DB->count_records_sql($sql, $params) > 0;
    }

    /**
     * Get the course completion instance
     *
     * @param int $coursemoduleid
     * @param int $userid
     *
     * @return mixed
     */
    protected static function get_coursemodule_instance($coursemoduleid, $userid) {
        global $DB;

        $cmc = $DB->get_record('course_modules_completion', ['coursemoduleid' => $coursemoduleid, 'userid' => $userid], '*');

        if ($cmc && $cmc->id && $cmc->completionstate != 0) {
            return $cmc->id;
        }

        return false;
    }

    /**
     * Get the quiz attempt grade
     *
     * @param int $id
     *
     * @return mixed
     */
    protected static function get_quiz_grade($id) {
        global $DB;

        $grade = $DB->get_record('quiz_attempts', ['id' => $id], '*');

        if (!$grade) {
            return null;
        }

        return $grade->sumgrades;
    }

    /**
     * Verify if the completion is completed
     *
     * @param int $cmcid
     *
     * @return boolean
     */
    protected static function is_completion_completed($cmcid) {
        global $DB;

        $cmc = $DB->get_record('course_modules_completion', ['id' => $cmcid], '*');

        if ($cmc) {
            return (bool) $cmc->completionstate;
        }
        return false;
    }

    /**
     * Verify if the student already receives points for the completion before
     *
     * @param int $courseid
     * @param int $userid
     * @param int $cmcid
     *
     * @return mixed
     */
    protected static function is_completion_repeated($courseid, $userid, $cmcid) {
        global $DB;

        $sql = "SELECT
                 count(*) as qtd
                FROM {ranking_points} p
                INNER JOIN {ranking_logs} l ON l.rankingid = p.id
                WHERE p.courseid = :courseid
                AND p.userid = :userid
                AND l.course_modules_completion = :cmcid";

        $params['courseid'] = $courseid;
        $params['userid'] = $userid;
        $params['cmcid'] = $cmcid;

        $qtd = $DB->get_record_sql($sql, $params);

        if (!$qtd) {
            return 0;
        }

        return (int) $qtd->qtd;
    }
}
