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
 * Ranking block upgrade
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

defined('MOODLE_INTERNAL') || die();

/**
 * Upgrade the ranking block
 *
 * @param int $oldversion
 * @param object $block
 * @return bool
 */
function xmldb_block_ranking_upgrade($oldversion, $block) {
    global $DB;

    $dbman = $DB->get_manager();

    if ($oldversion < 2015030300) {
        // Drop the mirror table.
        $table = new xmldb_table('ranking_cmc_mirror');
        if ($dbman->table_exists($table)) {
            $dbman->drop_table($table);
        }

        upgrade_plugin_savepoint(true, 2015030300, 'block', 'ranking');
    }

    if ($oldversion < 2015051800) {
        $criteria = [
            'plugin' => 'block_ranking',
            'name' => 'lastcomputedid',
        ];

        $DB->delete_records('config_plugins', $criteria);

        upgrade_plugin_savepoint(true, 2015051800, 'block', 'ranking');
    }

    if ($oldversion < 2026021400) {
        // Add indexes to ranking_points table.
        $table = new xmldb_table('ranking_points');

        $index = new xmldb_index('courseid_userid_uix', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('userid_ix', XMLDB_INDEX_NOTUNIQUE, ['userid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Add indexes to ranking_logs table.
        $table = new xmldb_table('ranking_logs');

        $index = new xmldb_index('rankingid_ix', XMLDB_INDEX_NOTUNIQUE, ['rankingid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        $index = new xmldb_index('cmc_ix', XMLDB_INDEX_NOTUNIQUE, ['course_modules_completion']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021400, 'block', 'ranking');
    }

    if ($oldversion < 2026021401) {
        $table = new xmldb_table('ranking_logs');

        // Composite index for date-filtered ranking queries.
        $index = new xmldb_index('rankingid_timecreated_ix', XMLDB_INDEX_NOTUNIQUE, ['rankingid', 'timecreated']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        // Course ID index for report chart queries.
        $index = new xmldb_index('courseid_ix', XMLDB_INDEX_NOTUNIQUE, ['courseid']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021401, 'block', 'ranking');
    }

    if ($oldversion < 2026021600) {
        // Create ranking_cache table for precalculated rankings.
        $table = new xmldb_table('ranking_cache');

        $table->add_field('id', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, XMLDB_SEQUENCE, null);
        $table->add_field('courseid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('userid', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('points', XMLDB_TYPE_NUMBER, '10', null, XMLDB_NOTNULL, null, '0', 5);
        $table->add_field('position', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('total_users', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);
        $table->add_field('last_updated', XMLDB_TYPE_INTEGER, '10', null, XMLDB_NOTNULL, null, null);

        $table->add_key('primary', XMLDB_KEY_PRIMARY, ['id']);
        $table->add_key('courseid_fk', XMLDB_KEY_FOREIGN, ['courseid'], 'course', ['id']);
        $table->add_key('userid_fk', XMLDB_KEY_FOREIGN, ['userid'], 'user', ['id']);

        $table->add_index('uq_course_user', XMLDB_INDEX_UNIQUE, ['courseid', 'userid']);
        $table->add_index('idx_course_pos', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'position']);

        if (!$dbman->table_exists($table)) {
            $dbman->create_table($table);
        }

        // Add performance index on ranking_points for leaderboard queries.
        $table = new xmldb_table('ranking_points');
        $index = new xmldb_index('idx_courseid_points', XMLDB_INDEX_NOTUNIQUE, ['courseid', 'points']);
        if (!$dbman->index_exists($table, $index)) {
            $dbman->add_index($table, $index);
        }

        upgrade_plugin_savepoint(true, 2026021600, 'block', 'ranking');
    }

    return true;
}
