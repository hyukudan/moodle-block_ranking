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
 * Points awarded event.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\event;

/**
 * Event fired when ranking points are awarded to a user.
 *
 * Other plugins (e.g. local_achievements) can observe this event to trigger
 * achievements, badges, or other gamification actions.
 *
 * Usage:
 *   $event = \block_ranking\event\points_awarded::create([
 *       'context' => context_course::instance($courseid),
 *       'userid' => $userid,
 *       'courseid' => $courseid,
 *       'other' => [
 *           'points' => $points,
 *           'totalpoints' => $totalpoints,
 *           'position' => $position,
 *       ],
 *   ]);
 *   $event->trigger();
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class points_awarded extends \core\event\base {

    /**
     * Set basic properties for the event.
     */
    protected function init() {
        $this->data['crud'] = 'u';
        $this->data['edulevel'] = self::LEVEL_PARTICIPATING;
        $this->data['objecttable'] = 'ranking_points';
    }

    /**
     * Returns localised general event name.
     *
     * @return string
     */
    public static function get_name() {
        return get_string('event_points_awarded', 'block_ranking');
    }

    /**
     * Returns non-localised event description with all event parameters.
     *
     * @return string
     */
    public function get_description() {
        $points = $this->other['points'] ?? 0;
        $total = $this->other['totalpoints'] ?? 0;
        return "The user with id '{$this->userid}' was awarded {$points} points " .
               "(total: {$total}) in the ranking for course '{$this->courseid}'.";
    }

    /**
     * Returns relevant URL.
     *
     * @return \moodle_url
     */
    public function get_url() {
        return new \moodle_url('/blocks/ranking/report.php', ['courseid' => $this->courseid]);
    }
}
