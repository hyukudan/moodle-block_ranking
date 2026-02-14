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
 * Ranking block - report page
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

require(__DIR__ . '/../../config.php');

define('DEFAULT_PAGE_SIZE', 100);

$courseid = required_param('courseid', PARAM_INT);
$perpage = optional_param('perpage', DEFAULT_PAGE_SIZE, PARAM_INT);
$group = optional_param('group', null, PARAM_INT);
$period = optional_param('period', 'all', PARAM_ALPHA);
$format = optional_param('format', '', PARAM_ALPHA);

$course = $DB->get_record('course', array('id' => $courseid), '*', MUST_EXIST);

require_login($courseid);
$context = context_course::instance($courseid);

$params = ['courseid' => $courseid];

if ($perpage) {
    $params['perpage'] = $perpage;
}

if ($group) {
    $params['group'] = $group;
}

if ($period !== 'all') {
    $params['period'] = $period;
}

$url = new moodle_url('/blocks/ranking/report.php', $params);

// Handle CSV export.
if ($format === 'csv') {
    $renderable = new \block_ranking\output\report($perpage, $group, $period);
    $renderer = $PAGE->get_renderer('block_ranking');
    $data = $renderable->export_for_template($renderer);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ranking_' . $course->shortname . '_' . $period . '.csv"');

    $out = fopen('php://output', 'w');
    fputcsv($out, [
        get_string('table_position', 'block_ranking'),
        get_string('table_name', 'block_ranking'),
        get_string('table_points', 'block_ranking'),
    ]);

    if (!empty($data['students'])) {
        foreach ($data['students'] as $student) {
            fputcsv($out, [
                $student->position,
                $student->fullname,
                $student->points,
            ]);
        }
    }

    fclose($out);
    exit;
}

// Page info.
$PAGE->set_url($url);
$PAGE->set_context($context);
$PAGE->set_pagelayout('incourse');

$title = get_string('report_title', 'block_ranking', $course->fullname);
$PAGE->set_title($title);
$PAGE->set_heading($title);

$PAGE->navbar->add(get_string('pluginname', 'block_ranking'));

$output = $PAGE->get_renderer('block_ranking');

echo $output->header();
echo $output->container_start('ranking-report');

// Group selector.
if (has_capability('moodle/course:managegroups', $context)) {
    $groups = groups_get_all_groups($course->id);
    if (!empty($groups)) {
        groups_print_course_menu($course, $PAGE->url);
    }
}

// Controls row: period filter + CSV export.
echo html_writer::start_div('ranking-report-controls');

$periodtypes = [
    'all' => get_string('filter_all', 'block_ranking'),
    'weekly' => get_string('weekly', 'block_ranking'),
    'monthly' => get_string('monthly', 'block_ranking'),
];

$periodurl = new moodle_url('/blocks/ranking/report.php', ['courseid' => $courseid, 'perpage' => $perpage]);
if ($group) {
    $periodurl->param('group', $group);
}

$select = new single_select($periodurl, 'period', $periodtypes, $period, null, 'periodselect');
$select->label = get_string('filter_period', 'block_ranking');
echo $output->render($select);

$csvurl = new moodle_url('/blocks/ranking/report.php', array_merge($params, ['format' => 'csv']));
echo html_writer::link($csvurl, get_string('export_csv', 'block_ranking'), [
    'class' => 'btn btn-sm btn-outline-secondary',
]);

echo html_writer::end_div();

// Ranking table.
$renderable = new \block_ranking\output\report($perpage, $group, $period);
echo $output->render($renderable);

// Points evolution chart (only for all-time view).
if ($period === 'all') {
    $sql = "SELECT DATE(FROM_UNIXTIME(rl.timecreated)) as logdate,
                   SUM(rl.points) as totalpoints
              FROM {ranking_logs} rl
             WHERE rl.courseid = :courseid
             GROUP BY DATE(FROM_UNIXTIME(rl.timecreated))
             ORDER BY logdate ASC";

    $records = $DB->get_records_sql($sql, ['courseid' => $courseid]);

    if (count($records) >= 2) {
        $labels = [];
        $values = [];
        foreach ($records as $record) {
            $labels[] = $record->logdate;
            $values[] = (float) $record->totalpoints;
        }

        echo $output->heading(get_string('points_evolution', 'block_ranking'), 4, 'mt-4');

        $chart = new \core\chart_line();
        $chart->set_smooth(true);
        $series = new \core\chart_series(get_string('table_points', 'block_ranking'), $values);
        $chart->add_series($series);
        $chart->set_labels($labels);

        echo $output->render($chart);
    }
}

echo $output->container_end();
echo $output->footer();
