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

$sql = "SELECT rp.id, rp.userid, rp.courseid, rp.points, c.fullname AS coursename
          FROM {ranking_points} rp
          JOIN {course} c ON c.id = rp.courseid
      ORDER BY rp.courseid ASC, rp.userid ASC, rp.id ASC";

$rs = $DB->get_recordset_sql($sql);
$purges = [];
$courseids = [];
$logcount = 0;

foreach ($rs as $point) {
    if (!block_ranking_helper::is_staff($point->userid, $point->courseid)) {
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

if (empty($purges)) {
    cli_writeln('No staff ranking rows found.');
    exit(0);
}

cli_writeln($commit ? 'Rows selected for deletion:' : 'Dry run: rows that would be deleted:');
foreach ($purges as $purge) {
    $logids = empty($purge->logids) ? '-' : implode(',', $purge->logids);
    cli_writeln(
        "ranking_points id={$purge->pointid}; userid={$purge->userid}; courseid={$purge->courseid}; " .
        "points={$purge->points}; ranking_logs ids={$logids}; course=\"{$purge->coursename}\""
    );
}

$pointids = array_map(function($purge) {
    return $purge->pointid;
}, $purges);

cli_writeln('');
cli_writeln('Summary:');
cli_writeln('  ranking_points rows: ' . count($pointids));
cli_writeln('  ranking_logs rows: ' . $logcount);
cli_writeln('  affected courses: ' . count($courseids));

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
