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
 * Privacy Subsystem implementation for block_ranking.
 *
 * @package    block_ranking
 * @copyright  2024 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\privacy;

use core_privacy\local\metadata\collection;
use core_privacy\local\request\approved_contextlist;
use core_privacy\local\request\approved_userlist;
use core_privacy\local\request\contextlist;
use core_privacy\local\request\userlist;
use core_privacy\local\request\writer;

/**
 * Privacy provider for block_ranking.
 *
 * @copyright  2024 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class provider implements
    \core_privacy\local\metadata\provider,
    \core_privacy\local\request\plugin\provider,
    \core_privacy\local\request\core_userlist_provider {

    /**
     * Returns metadata about the personal data stored by this plugin.
     *
     * @param collection $collection The initialised collection to add items to.
     * @return collection A listing of user data stored through this system.
     */
    public static function get_metadata(collection $collection): collection {
        $collection->add_database_table(
            'ranking_points',
            [
                'userid' => 'privacy:metadata:ranking_points:userid',
                'courseid' => 'privacy:metadata:ranking_points:courseid',
                'points' => 'privacy:metadata:ranking_points:points',
                'timecreated' => 'privacy:metadata:ranking_points:timecreated',
                'timemodified' => 'privacy:metadata:ranking_points:timemodified',
            ],
            'privacy:metadata:ranking_points'
        );

        $collection->add_database_table(
            'ranking_logs',
            [
                'rankingid' => 'privacy:metadata:ranking_logs:rankingid',
                'courseid' => 'privacy:metadata:ranking_logs:courseid',
                'course_modules_completion' => 'privacy:metadata:ranking_logs:course_modules_completion',
                'points' => 'privacy:metadata:ranking_logs:points',
                'timecreated' => 'privacy:metadata:ranking_logs:timecreated',
            ],
            'privacy:metadata:ranking_logs'
        );

        return $collection;
    }

    /**
     * Get the list of contexts that contain user information for the specified user.
     *
     * @param int $userid The user to search.
     * @return contextlist The contextlist containing the list of contexts used in this plugin.
     */
    public static function get_contexts_for_userid(int $userid): contextlist {
        $contextlist = new contextlist();

        $sql = "SELECT ctx.id
                  FROM {context} ctx
                  JOIN {ranking_points} rp ON rp.courseid = ctx.instanceid
                 WHERE ctx.contextlevel = :contextlevel
                   AND rp.userid = :userid";

        $params = [
            'contextlevel' => CONTEXT_COURSE,
            'userid' => $userid,
        ];

        $contextlist->add_from_sql($sql, $params);

        return $contextlist;
    }

    /**
     * Get the list of users who have data within a context.
     *
     * @param userlist $userlist The userlist containing the list of users.
     */
    public static function get_users_in_context(userlist $userlist) {
        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $sql = "SELECT rp.userid
                  FROM {ranking_points} rp
                 WHERE rp.courseid = :courseid";

        $params = ['courseid' => $context->instanceid];

        $userlist->add_from_sql('userid', $sql, $params);
    }

    /**
     * Export all user data for the specified approved contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts to export for.
     */
    public static function export_user_data(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            // Export ranking points.
            $points = $DB->get_records('ranking_points', [
                'userid' => $userid,
                'courseid' => $courseid,
            ]);

            foreach ($points as $point) {
                $data = (object) [
                    'courseid' => $point->courseid,
                    'points' => $point->points,
                    'timecreated' => \core_privacy\local\request\transform::datetime($point->timecreated),
                    'timemodified' => \core_privacy\local\request\transform::datetime($point->timemodified),
                ];

                writer::with_context($context)->export_data(
                    [get_string('pluginname', 'block_ranking'), 'points'],
                    $data
                );

                // Export associated logs.
                $logs = $DB->get_records('ranking_logs', ['rankingid' => $point->id]);
                $logdata = [];
                foreach ($logs as $log) {
                    $logdata[] = (object) [
                        'course_modules_completion' => $log->course_modules_completion,
                        'points' => $log->points,
                        'timecreated' => \core_privacy\local\request\transform::datetime($log->timecreated),
                    ];
                }

                if (!empty($logdata)) {
                    writer::with_context($context)->export_data(
                        [get_string('pluginname', 'block_ranking'), 'logs'],
                        (object) ['logs' => $logdata]
                    );
                }
            }
        }
    }

    /**
     * Delete all data for all users in the specified context.
     *
     * @param \context $context The specific context to delete data for.
     */
    public static function delete_data_for_all_users_in_context(\context $context) {
        global $DB;

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;

        $transaction = $DB->start_delegated_transaction();
        // Delete logs first (they reference ranking_points via rankingid).
        $DB->delete_records('ranking_logs', ['courseid' => $courseid]);
        $DB->delete_records('ranking_points', ['courseid' => $courseid]);
        $transaction->allow_commit();
    }

    /**
     * Delete all user data for the specified user, in the specified contexts.
     *
     * @param approved_contextlist $contextlist The approved contexts and user information to delete for.
     */
    public static function delete_data_for_user(approved_contextlist $contextlist) {
        global $DB;

        $userid = $contextlist->get_user()->id;

        foreach ($contextlist->get_contexts() as $context) {
            if ($context->contextlevel != CONTEXT_COURSE) {
                continue;
            }

            $courseid = $context->instanceid;

            $transaction = $DB->start_delegated_transaction();

            // Get ranking point IDs for this user/course to delete associated logs.
            $pointids = $DB->get_fieldset_select(
                'ranking_points',
                'id',
                'userid = :userid AND courseid = :courseid',
                ['userid' => $userid, 'courseid' => $courseid]
            );

            if (!empty($pointids)) {
                list($insql, $params) = $DB->get_in_or_equal($pointids);
                $DB->delete_records_select('ranking_logs', "rankingid $insql", $params);
            }

            $DB->delete_records('ranking_points', ['userid' => $userid, 'courseid' => $courseid]);
            $transaction->allow_commit();
        }
    }

    /**
     * Delete multiple users within a single context.
     *
     * @param approved_userlist $userlist The approved context and user information to delete for.
     */
    public static function delete_data_for_users(approved_userlist $userlist) {
        global $DB;

        $context = $userlist->get_context();

        if ($context->contextlevel != CONTEXT_COURSE) {
            return;
        }

        $courseid = $context->instanceid;
        $userids = $userlist->get_userids();

        if (empty($userids)) {
            return;
        }

        $transaction = $DB->start_delegated_transaction();

        list($usersql, $userparams) = $DB->get_in_or_equal($userids, SQL_PARAMS_NAMED);

        // Get ranking point IDs for these users in this course.
        $sql = "SELECT id FROM {ranking_points} WHERE userid $usersql AND courseid = :courseid";
        $params = array_merge($userparams, ['courseid' => $courseid]);
        $pointids = $DB->get_fieldset_sql($sql, $params);

        if (!empty($pointids)) {
            list($pointsql, $pointparams) = $DB->get_in_or_equal($pointids);
            $DB->delete_records_select('ranking_logs', "rankingid $pointsql", $pointparams);
        }

        $DB->delete_records_select('ranking_points', "userid $usersql AND courseid = :courseid", $params);
        $transaction->allow_commit();
    }
}
