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
 * Scheduled task to purge old ranking logs.
 *
 * @package    block_ranking
 * @copyright  2026 PreparaOposiciones
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

namespace block_ranking\task;

defined('MOODLE_INTERNAL') || die();

class purge_old_logs extends \core\task\scheduled_task {

    public function get_name() {
        return 'Purge old ranking logs';
    }

    public function execute() {
        global $DB;

        $retentiondays = (int) get_config('block_ranking', 'log_retention_days');
        if ($retentiondays <= 0) {
            $retentiondays = 180;
        }

        $cutoff = time() - ($retentiondays * 86400);
        $count = $DB->count_records_select('ranking_logs', 'timecreated < ?', [$cutoff]);

        if ($count > 0) {
            $DB->delete_records_select('ranking_logs', 'timecreated < ?', [$cutoff]);
            mtrace("block_ranking: purged {$count} log records older than {$retentiondays} days");
        } else {
            mtrace("block_ranking: no old logs to purge");
        }
    }
}
