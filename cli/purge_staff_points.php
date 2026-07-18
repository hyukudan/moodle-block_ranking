#!/usr/bin/env php
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
 * Purge ranking points and logs for course staff.
 *
 * @package    block_ranking
 * @copyright  2026 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

define('CLI_SCRIPT', true);

require(__DIR__ . '/../../../config.php');
require_once($CFG->libdir . '/clilib.php');

use block_ranking\block_ranking_helper;
use block_ranking\rankinglib;

list($options, $unrecognized) = cli_get_params(
    [
        'help' => false,
        'dry-run' => false,
        'commit' => false,
    ],
    [
        'h' => 'help',
    ]
);

if ($unrecognized) {
    $unrecognized = implode("\n  ", $unrecognized);
    cli_error("Unknown option(s):\n  " . $unrecognized);
}

if (!empty($options['help'])) {
    $help = "Purge block_ranking points for staff users.

By default this is a dry run. It lists the exact ranking_points IDs and
ranking_logs IDs that would be deleted. Use --commit to delete them.

Options:
  --dry-run   List rows that would be deleted. This is the default.
  --commit    Delete listed rows.
  -h, --help  Show this help.

Examples:
  php blocks/ranking/cli/purge_staff_points.php
  php blocks/ranking/cli/purge_staff_points.php --commit
";
    cli_writeln($help);
    exit(0);
}

if (!empty($options['dry-run']) && !empty($options['commit'])) {
    cli_error('Use either --dry-run or --commit, not both.');
}

$commit = !empty($options['commit']);

$params = [
    'coursecontextlevel' => CONTEXT_COURSE,
    'staffcapability' => 'moodle/course:update',
    'staffpermission' => CAP_ALLOW,
    'staffrapathsuffix' => '/%',
    'staffrcpathsuffix' => '/%',
];

$staffconditions = [];
$adminids = block_ranking_helper::get_site_admin_ids();
if (!empty($adminids)) {
    list($adminsql, $adminparams) = $DB->get_in_or_equal($adminids, SQL_PARAMS_NAMED, 'admin');
    $staffconditions[] = "rp.userid {$adminsql}";
    $params = array_merge($params, $adminparams);
}

$assignmentpathmatch = $DB->sql_like('coursectx.path', $DB->sql_concat('ractx.path', ':staffrapathsuffix'));
$capabilitypathmatch = $DB->sql_like('coursectx.path', $DB->sql_concat('rcctx.path', ':staffrcpathsuffix'));
$staffconditions[] = "EXISTS (
        SELECT 1
          FROM {role_assignments} ra
          JOIN {context} ractx ON ractx.id = ra.contextid
          JOIN {role_capabilities} rc ON rc.roleid = ra.roleid
          JOIN {context} rcctx ON rcctx.id = rc.contextid
         WHERE ra.userid = rp.userid
           AND rc.capability = :staffcapability
           AND rc.permission = :staffpermission
           AND (coursectx.path = ractx.path OR {$assignmentpathmatch})
           AND (coursectx.path = rcctx.path OR {$capabilitypathmatch})
    )";

$studentroleids = block_ranking_helper::get_student_role_ids();
if (!empty($studentroleids)) {
    list($studentrolesql, $studentparams) = $DB->get_in_or_equal($studentroleids, SQL_PARAMS_NAMED, 'studentrole');
    $now = time();
    $params = array_merge($params, $studentparams, [
        'studentactive' => ENROL_USER_ACTIVE,
        'studentenabled' => ENROL_INSTANCE_ENABLED,
        'studentnow1' => $now,
        'studentnow2' => $now,
    ]);

    $activestudentsql = "EXISTS (
            SELECT 1
              FROM {role_assignments} studentra
              JOIN {user_enrolments} studentue ON studentue.userid = studentra.userid
              JOIN {enrol} studente ON studente.id = studentue.enrolid
             WHERE studentra.userid = rp.userid
               AND studentra.contextid = coursectx.id
               AND studentra.roleid {$studentrolesql}
               AND studente.courseid = rp.courseid
               AND studentue.status = :studentactive
               AND studente.status = :studentenabled
               AND studentue.timestart < :studentnow1
               AND (studentue.timeend = 0 OR studentue.timeend > :studentnow2)
        )";
} else {
    $activestudentsql = "0";
}

$staffwhere = '(' . implode("\n        OR ", $staffconditions) . ')';

$sql = "SELECT rp.id, rp.userid, rp.courseid, rp.points, c.fullname AS coursename,
               CASE WHEN {$activestudentsql} THEN 1 ELSE 0 END AS hasactivestudentrole
          FROM {ranking_points} rp
          JOIN {course} c ON c.id = rp.courseid
          JOIN {context} coursectx
            ON coursectx.contextlevel = :coursecontextlevel
           AND coursectx.instanceid = rp.courseid
         WHERE {$staffwhere}
      ORDER BY rp.courseid ASC, rp.userid ASC, rp.id ASC";

$rs = $DB->get_recordset_sql($sql, $params);
$purges = [];
$omitted = [];
$courseids = [];
$logcount = 0;

foreach ($rs as $point) {
    if (!empty($point->hasactivestudentrole)) {
        $omitted[] = (object) [
            'pointid' => (int) $point->id,
            'userid' => (int) $point->userid,
            'courseid' => (int) $point->courseid,
            'coursename' => $point->coursename,
            'points' => (float) $point->points,
        ];
        continue;
    }

    $logids = $DB->get_fieldset_select(
        'ranking_logs',
        'id',
        'rankingid = :rankingid',
        ['rankingid' => $point->id]
    );

    $purges[] = (object) [
        'pointid' => (int) $point->id,
        'userid' => (int) $point->userid,
        'courseid' => (int) $point->courseid,
        'coursename' => $point->coursename,
        'points' => (float) $point->points,
        'logids' => array_map('intval', $logids),
    ];

    $courseids[(int) $point->courseid] = true;
    $logcount += count($logids);
}
$rs->close();

if (empty($purges) && empty($omitted)) {
    cli_writeln('No staff ranking rows found.');
    exit(0);
}

if (!empty($purges)) {
    cli_writeln($commit ? 'Rows selected for deletion:' : 'Dry run: rows that would be deleted:');
    foreach ($purges as $purge) {
        $logids = empty($purge->logids) ? '-' : implode(',', $purge->logids);
        cli_writeln(
            "ranking_points id={$purge->pointid}; userid={$purge->userid}; courseid={$purge->courseid}; " .
            "points={$purge->points}; ranking_logs ids={$logids}; course=\"{$purge->coursename}\""
        );
    }
} else {
    cli_writeln('No staff-only ranking rows selected for deletion.');
}

if (!empty($omitted)) {
    cli_writeln('');
    cli_writeln('Rows omitted because the user is also an active student in the course:');
    foreach ($omitted as $skip) {
        cli_writeln(
            "ranking_points id={$skip->pointid}; userid={$skip->userid}; courseid={$skip->courseid}; " .
            "points={$skip->points}; course=\"{$skip->coursename}\""
        );
    }
}

$pointids = array_map(function($purge) {
    return $purge->pointid;
}, $purges);

cli_writeln('');
cli_writeln('Summary:');
cli_writeln('  ranking_points rows: ' . count($pointids));
cli_writeln('  ranking_logs rows: ' . $logcount);
cli_writeln('  affected courses: ' . count($courseids));
cli_writeln('  omitted active students: ' . count($omitted));

if (empty($pointids)) {
    cli_writeln('');
    cli_writeln('No rows were deleted.');
    exit(0);
}

if (!$commit) {
    cli_writeln('');
    cli_writeln('No rows were deleted. Re-run with --commit to apply this purge.');
    exit(0);
}

$transaction = $DB->start_delegated_transaction();
try {
    foreach (array_chunk($pointids, 1000) as $chunk) {
        list($pointsql, $pointparams) = $DB->get_in_or_equal($chunk, SQL_PARAMS_NAMED, 'pointid');
        $DB->delete_records_select('ranking_logs', "rankingid $pointsql", $pointparams);
        $DB->delete_records_select('ranking_points', "id $pointsql", $pointparams);
    }

    $transaction->allow_commit();
} catch (Exception $e) {
    $transaction->rollback($e);
}

foreach (array_keys($courseids) as $courseid) {
    rankinglib::invalidate_course_cache($courseid);
}

cli_writeln('');
cli_writeln('Deleted staff ranking rows and invalidated affected course ranking caches.');
