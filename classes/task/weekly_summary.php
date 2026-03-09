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
 * Weekly summary scheduled task for block_ranking.
 *
 * Sends a weekly ranking position summary notification to all ranked users.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking\task;

/**
 * Scheduled task that sends weekly ranking summaries to users.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class weekly_summary extends \core\task\scheduled_task {

    /**
     * Get the task name.
     *
     * @return string
     */
    public function get_name() {
        return get_string('task_weekly_summary', 'block_ranking');
    }

    /**
     * Execute the task.
     */
    public function execute() {
        global $DB;

        // Get all courses that have ranking points.
        $courseids = $DB->get_fieldset_sql(
            "SELECT DISTINCT courseid FROM {ranking_points}"
        );

        if (empty($courseids)) {
            return;
        }

        // Batch-load all courses (avoids N+1 queries).
        list($insql, $inparams) = $DB->get_in_or_equal($courseids);
        $courses = $DB->get_records_select('course', "id $insql", $inparams);

        foreach ($courseids as $courseid) {
            $course = $courses[$courseid] ?? null;
            if (!$course) {
                continue;
            }

            $this->send_course_summaries($courseid, $course);
        }
    }

    /**
     * Send weekly summaries for a specific course.
     *
     * @param int $courseid
     * @param \stdClass $course
     */
    protected function send_course_summaries($courseid, $course) {
        global $DB;

        // Get all ranked users ordered by points.
        $rankedusers = $DB->get_records('ranking_points', ['courseid' => $courseid], 'points DESC');

        if (empty($rankedusers)) {
            return;
        }

        $position = 0;
        $lastpoints = null;
        $sent = 0;
        $usetemplate = class_exists('\local_achievements\email_template');
        $reporturl = new \moodle_url('/blocks/ranking/report.php', ['courseid' => $courseid]);

        foreach ($rankedusers as $record) {
            if ($lastpoints === null || (float) $record->points < $lastpoints) {
                $position++;
                $lastpoints = (float) $record->points;
            }

            $user = \core_user::get_user($record->userid, '*', MUST_EXIST);

            // Podium emoji based on position.
            if ($position === 1) {
                $posemoji = '🥇';
            } else if ($position === 2) {
                $posemoji = '🥈';
            } else if ($position === 3) {
                $posemoji = '🥉';
            } else {
                $posemoji = '🏅';
            }

            // Plain text (engaging version).
            $plaintext = "¡Hola {$user->firstname}!\n\n"
                . "{$posemoji} Tu posición en el ranking de {$course->fullname}: #{$position} con {$record->points} puntos.\n\n";
            if ($position <= 3) {
                $plaintext .= "¡Increíble! Estás en el podio. ¡Sigue así para mantener tu posición!\n";
            } else if ($position <= 10) {
                $plaintext .= "¡Estás en el TOP 10! Un poco más de esfuerzo y llegarás al podio.\n";
            } else {
                $plaintext .= "¡Cada punto cuenta! Sigue practicando para escalar posiciones.\n";
            }
            $plaintext .= "\nConsulta el ranking completo: " . $reporturl->out(false);

            // Subject with emoji and firstname.
            $subject = "{$posemoji} {$user->firstname}, eres #{$position} en {$course->fullname}";

            // Build HTML.
            if ($usetemplate) {
                $t = '\local_achievements\email_template';

                // Motivational message based on position.
                if ($position === 1) {
                    $motivational = "🔥 ¡Eres el líder indiscutible! Nadie te supera.";
                } else if ($position <= 3) {
                    $motivational = "🏆 ¡Estás en el podio! Sigue así para mantener tu posición.";
                } else if ($position <= 10) {
                    $motivational = "💪 ¡Estás en el TOP 10! Un poco más y llegas al podio.";
                } else {
                    $motivational = "🚀 ¡Cada punto cuenta! Sigue practicando para escalar posiciones.";
                }

                $body = $t::text("¡Hola <strong>{$user->firstname}</strong>! Aquí tienes tu resumen semanal del ranking.")
                    . $t::divider()
                    . $t::stat_row([
                        [$posemoji, "#{$position}", 'Tu posición'],
                        ['⭐', number_format($record->points, 0, ',', '.'), 'Puntos totales'],
                    ])
                    . $t::highlight($motivational)
                    . $t::text("Curso: <strong>{$course->fullname}</strong>");

                $html = $t::wrap(
                    "Resumen semanal del ranking",
                    $body,
                    $reporturl->out(false),
                    "Ver ranking completo",
                    '#1e3a5f'
                );
            } else {
                $html = '<p>' . nl2br(s($plaintext)) . '</p>';
            }

            $message = new \core\message\message();
            $message->component = 'block_ranking';
            $message->name = 'ranking_update';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $subject;
            $message->fullmessage = $plaintext;
            $message->fullmessageformat = FORMAT_HTML;
            $message->fullmessagehtml = $html;
            $message->smallmessage = "{$posemoji} #{$position} en {$course->fullname} con {$record->points} pts";
            $message->notification = 1;
            $message->contexturl = $reporturl;
            $message->contexturlname = $course->fullname;
            $message->courseid = $courseid;

            try {
                message_send($message);
                $sent++;
            } catch (\Exception $e) {
                debugging('block_ranking: Failed to send weekly summary to user ' .
                    $record->userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }
        }

        mtrace("block_ranking: Sent $sent weekly summaries for course $courseid ({$course->shortname})");
    }
}
