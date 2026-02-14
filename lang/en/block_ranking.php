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
 * Ranking block english language translation
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Ranking block';
$string['ranking'] = 'Ranking';
$string['ranking:addinstance'] = 'Add a new ranking block';

$string['nostudents'] = 'No students to show';
$string['blocktitle'] = 'Block Title';

$string['table_position'] = 'Pos';
$string['table_name'] = 'Fullname';
$string['table_points'] = 'Points';
$string['your_score'] = 'Your score';
$string['see_full_ranking'] = 'See full ranking';
$string['ranking_graphs'] = 'Ranking graphs';

$string['graph_types'] = 'Graph types';
$string['graph_select_a_group'] = 'Select a group';
$string['graph_groups'] = 'Graph of groups points';
$string['graph_group_evolution'] = 'Graph of group points evolution';
$string['graph_group_evolution_title'] = 'Graph of group points evolution in last week';
$string['graph_groups_avg'] = 'Graph of groups points average';
$string['graph_access_deny'] = 'You don\'t have access to view the course groups to view that report.';
$string['graph_no_groups'] = 'There is no group in this course to view this report.';

$string['report_title'] = '{$a} : General students ranking';
$string['report_head'] = 'Ranking details: First {$a} students';

// Global configuration.
$string['rankingsize'] = 'Size of the ranking';
$string['rankingsize_help'] = 'Number of students that will appear in ranking';
$string['configuration'] = 'Block Ranking configuration';

// Activites points.
$string['resourcepoints'] = 'Points to resource';
$string['assignpoints'] = 'Points to assign';
$string['forumpoints'] = 'Points to forum';
$string['pagepoints'] = 'Points to page';
$string['workshoppoints'] = 'Points to workshop';
$string['quizpoints'] = 'Points to quiz';
$string['lessonpoints'] = 'Points to lesson';
$string['scormpoints'] = 'Points to SCORM package';
$string['urlpoints'] = 'Points to URL';
$string['defaultpoints'] = 'Default points';
$string['grade_multiplier'] = 'Grade multiplier';
$string['grade_multiplier_help'] = 'Multiplier applied to grade-based bonus points. E.g. 1.0 = grade added as-is, 0.5 = half grade points, 2.0 = double grade points.';

$string['monthly'] = 'Monthly';
$string['weekly'] = 'Weekly';
$string['general'] = 'General';

$string['yes'] = 'Yes';
$string['no'] = 'No';

$string['enable_multiple_quizz_attempts'] = 'Enable multiple quizz attempts';
$string['enable_multiple_quizz_attempts_help'] = 'Enable studens to add points in every quizz attempt. If this options is marked as NO, the student only will receive the points of the first attempt.';

$string['student_roles'] = 'Student roles';
$string['student_roles_help'] = 'Select which roles should be considered as students for the ranking. Users with these roles will receive points and appear in the leaderboard.';

// Report page.
$string['filter_period'] = 'Period';
$string['filter_all'] = 'All time';
$string['export_csv'] = 'Export CSV';
$string['points_evolution'] = 'Points evolution';
$string['no_data_for_chart'] = 'Not enough data to display the chart.';

// Notifications.
$string['messageprovider:ranking_update'] = 'Ranking position changes';
$string['notification_top3'] = 'Congratulations! You reached the top 3 in the ranking for {$a}.';
$string['notification_overtaken'] = '{$a->username} has overtaken you in the ranking for {$a->coursename}.';
$string['notification_weekly_summary'] = 'Your ranking position in {$a->coursename}: #{$a->position} with {$a->points} points.';

// Scheduled tasks.
$string['task_weekly_summary'] = 'Send weekly ranking position summaries';

// Events.
$string['event_points_awarded'] = 'Ranking points awarded';

// Privacy API.
$string['privacy:metadata:ranking_points'] = 'Stores user ranking points per course.';
$string['privacy:metadata:ranking_points:userid'] = 'The ID of the user.';
$string['privacy:metadata:ranking_points:courseid'] = 'The ID of the course.';
$string['privacy:metadata:ranking_points:points'] = 'The total points accumulated by the user.';
$string['privacy:metadata:ranking_points:timecreated'] = 'The time the record was created.';
$string['privacy:metadata:ranking_points:timemodified'] = 'The time the record was last modified.';
$string['privacy:metadata:ranking_logs'] = 'Stores individual point transactions for the ranking.';
$string['privacy:metadata:ranking_logs:rankingid'] = 'The ID of the associated ranking points record.';
$string['privacy:metadata:ranking_logs:courseid'] = 'The ID of the course.';
$string['privacy:metadata:ranking_logs:course_modules_completion'] = 'The ID of the course module completion that triggered this entry.';
$string['privacy:metadata:ranking_logs:points'] = 'The points awarded in this transaction.';
$string['privacy:metadata:ranking_logs:timecreated'] = 'The time the points were awarded.';
