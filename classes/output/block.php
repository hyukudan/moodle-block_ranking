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
 * Ranking block
 *
 * @package    block_ranking
 * @copyright  2020 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\output;

use block_ranking\rankinglib;
use block_ranking\studentlib;
use renderable;
use templatable;
use renderer_base;

/**
 * Ranking block renderable class.
 *
 * @package    block_ranking
 * @copyright  2020 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class block implements renderable, templatable {

    /**
     * @var int $rankingsize the ranking size.
     */
    protected $rankingsize;

    /**
     * Block constructor.
     * @param int $rankingsize
     */
    public function __construct($rankingsize) {
        $this->rankingsize = $rankingsize;
    }

    /**
     * Export the data.
     *
     * @param renderer_base $output
     *
     * @return array|\stdClass
     *
     * @throws \coding_exception
     *
     * @throws \dml_exception
     */
    public function export_for_template(renderer_base $output) {
        $rankinglib = new rankinglib();

        $weekstart = rankinglib::get_week_start();
        $monthstart = rankinglib::get_month_start();

        $general = $rankinglib->get_students($this->rankingsize);
        $weekly = $rankinglib->get_students_by_date($weekstart, time(), $this->rankingsize);
        $monthly = $rankinglib->get_students_by_date($monthstart, time(), $this->rankingsize);

        $returndata = [
            'generalranking' => is_array($general) ? $general : [],
            'weeklyranking' => is_array($weekly) ? $weekly : [],
            'monthlyranking' => is_array($monthly) ? $monthly : [],
        ];

        $studentlib = new studentlib();
        if ($studentlib->is_student()) {
            $gpoints = $studentlib->get_total_course_points();
            $wpoints = $studentlib->get_student_points_by_date($weekstart, time());
            $mpoints = $studentlib->get_student_points_by_date($monthstart, time());
            $returndata['studentdata'] = [
                'generalpoints' => $gpoints ?: 0,
                'weeklypoints' => $wpoints ?: 0,
                'monthlypoints' => $mpoints ?: 0,
            ];
        }

        return $returndata;
    }
}
