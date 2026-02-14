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
 * Ranking block settings file
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die;

if ($ADMIN->fulltree) {

    // Student roles multi-select setting.
    $roles = role_get_names(null, ROLENAME_ORIGINAL);
    $roleoptions = [];
    $defaultroles = [];
    foreach ($roles as $role) {
        $roleoptions[$role->id] = $role->localname;
    }
    // Default: all roles with 'student' archetype (configmulticheckbox expects key => 1 format).
    $studentarchetyperoles = get_archetype_roles('student');
    foreach ($studentarchetyperoles as $role) {
        $defaultroles[$role->id] = 1;
    }

    $settings->add(new admin_setting_configmulticheckbox(
        'block_ranking/student_roles',
        get_string('student_roles', 'block_ranking'),
        get_string('student_roles_help', 'block_ranking'),
        $defaultroles,
        $roleoptions
    ));

    $settings->add(new admin_setting_configtext('block_ranking/rankingsize', get_string('rankingsize', 'block_ranking'),
        get_string('rankingsize_help', 'block_ranking'), 10, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/resourcepoints', get_string('resourcepoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/assignpoints', get_string('assignpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/forumpoints', get_string('forumpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/pagepoints', get_string('pagepoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/workshoppoints', get_string('workshoppoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/quizpoints', get_string('quizpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/lessonpoints', get_string('lessonpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/scormpoints', get_string('scormpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/urlpoints', get_string('urlpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/defaultpoints', get_string('defaultpoints', 'block_ranking'),
        '', 2, PARAM_INT));

    $settings->add(new admin_setting_configtext('block_ranking/grade_multiplier',
        get_string('grade_multiplier', 'block_ranking'),
        get_string('grade_multiplier_help', 'block_ranking'),
        '1', PARAM_RAW));

    $settings->add(new admin_setting_configselect(
                    'block_ranking/enable_multiple_quizz_attempts',
                    get_string('enable_multiple_quizz_attempts', 'block_ranking'),
                    get_string('enable_multiple_quizz_attempts_help', 'block_ranking'),
                    '1',
                    array(
                        '1' => get_string('yes', 'block_ranking'),
                        '0' => get_string('no', 'block_ranking')
                    )
                ));
}
