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
 * Notification manager for block_ranking.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
namespace block_ranking;

/**
 * Handles sending ranking-related notifications via Moodle Message API.
 *
 * @package    block_ranking
 * @copyright  2024 block_ranking contributors
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */
class notification_manager {

    /**
     * Notify a user that they reached the top 3.
     *
     * @param int $userid The user to notify.
     * @param int $courseid The course.
     * @return void
     */
    public static function notify_top3($userid, $courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        if (!$course) {
            debugging('block_ranking: Cannot send top3 notification - course ' . $courseid . ' not found', DEBUG_DEVELOPER);
            return;
        }

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $courseurl = new \moodle_url('/course/view.php', ['id' => $courseid]);

        // Get the user's actual position and points for the stat cards.
        $rankrecord = $DB->get_record('ranking_points', ['userid' => $userid, 'courseid' => $courseid]);
        $points = $rankrecord ? $rankrecord->points : 0;

        // Determine exact position (count users with more points + 1).
        $pos = $DB->count_records_select('ranking_points',
            'courseid = :courseid AND points > :points',
            ['courseid' => $courseid, 'points' => $points]
        ) + 1;

        if ($pos === 1) {
            $posemoji = '🥇';
            $postitle = '¡PRIMER PUESTO!';
        } else if ($pos === 2) {
            $posemoji = '🥈';
            $postitle = '¡SEGUNDO PUESTO!';
        } else {
            $posemoji = '🥉';
            $postitle = '¡TERCER PUESTO!';
        }

        // Plain text (engaging).
        $plaintext = "🎉 ¡Enhorabuena {$user->firstname}!\n\n"
            . "{$posemoji} {$postitle}\n\n"
            . "Has llegado al TOP 3 del ranking en {$course->fullname}.\n"
            . "Posición: #{$pos} | Puntos: {$points}\n\n"
            . "¡Sigue así! Estás entre los mejores.\n\n"
            . "Ver el curso: " . $courseurl->out(false);

        $subject = "🥇 ¡{$user->firstname}, estás en el TOP 3!";

        // Build HTML.
        $usetemplate = class_exists('\local_achievements\email_template');
        if ($usetemplate) {
            $t = '\local_achievements\email_template';

            $safefirst = s($user->firstname);
            $safecourse = s($course->fullname);
            $body = $t::text("🎉 <strong>¡Enhorabuena {$safefirst}!</strong>", 'center', true)
                . $t::highlight("{$posemoji} {$postitle} en el ranking")
                . $t::stat_row([
                    [$posemoji, "#{$pos}", 'Tu posición'],
                    ['⭐', number_format($points, 0, ',', '.'), 'Puntos'],
                ])
                . $t::divider()
                . $t::text("Has llegado al <strong>TOP 3</strong> en <strong>{$safecourse}</strong>. ¡Estás entre los mejores!")
                . $t::text("💪 ¡Sigue practicando para mantener tu puesto en el podio!");

            $html = $t::wrap(
                "¡Estás en el TOP 3!",
                $body,
                $courseurl->out(false),
                "Ir al curso",
                '#28a745'
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
        $message->smallmessage = "{$posemoji} ¡{$user->firstname}, estás en el TOP 3 de {$course->fullname}!";
        $message->notification = 1;
        $message->contexturl = $courseurl;
        $message->contexturlname = $course->fullname;
        $message->courseid = $courseid;

        $result = message_send($message);
        if (!$result) {
            debugging('block_ranking: Failed to send top3 notification to user ' . $userid, DEBUG_DEVELOPER);
        }
    }

    /**
     * Notify a user that someone overtook them in the ranking.
     *
     * @param int $userid The user who was overtaken.
     * @param int $overtakenbyid The user who overtook.
     * @param int $courseid The course.
     * @return void
     */
    public static function notify_overtaken($userid, $overtakenbyid, $courseid) {
        global $DB;

        $course = $DB->get_record('course', ['id' => $courseid]);
        $overtaker = $DB->get_record('user', ['id' => $overtakenbyid]);
        if (!$course || !$overtaker) {
            debugging('block_ranking: Cannot send overtaken notification - course or user not found', DEBUG_DEVELOPER);
            return;
        }

        $user = \core_user::get_user($userid, '*', MUST_EXIST);
        $overtakername = fullname($overtaker);
        $courseurl = new \moodle_url('/course/view.php', ['id' => $courseid]);

        // Get user's current points and position.
        $rankrecord = $DB->get_record('ranking_points', ['userid' => $userid, 'courseid' => $courseid]);
        $points = $rankrecord ? $rankrecord->points : 0;
        $currentpos = $DB->count_records_select('ranking_points',
            'courseid = :courseid AND points > :points',
            ['courseid' => $courseid, 'points' => $points]
        ) + 1;

        // Plain text (engaging).
        $plaintext = "¡Hola {$user->firstname}!\n\n"
            . "📊 {$overtakername} te ha adelantado en el ranking de {$course->fullname}.\n\n"
            . "Tu posición actual: #{$currentpos} | Tus puntos: {$points}\n\n"
            . "¡No te rindas! Completa más actividades para recuperar tu posición.\n\n"
            . "Ver el curso: " . $courseurl->out(false);

        $subject = "📊 ¡{$user->firstname}, {$overtakername} te ha adelantado!";

        // Build HTML.
        $usetemplate = class_exists('\local_achievements\email_template');
        if ($usetemplate) {
            $t = '\local_achievements\email_template';

            $safefirst = s($user->firstname);
            $safeovertaker = s($overtakername);
            $safecourse = s($course->fullname);
            $body = $t::text("¡Hola <strong>{$safefirst}</strong>!")
                . $t::highlight("📊 <strong>{$safeovertaker}</strong> te ha adelantado en el ranking")
                . $t::stat_row([
                    ['📍', "#{$currentpos}", 'Tu posición actual'],
                    ['⭐', number_format($points, 0, ',', '.'), 'Tus puntos'],
                ])
                . $t::divider()
                . $t::text("En el curso <strong>{$safecourse}</strong>.")
                . $t::text("💪 ¡No te rindas! Completa más actividades para recuperar tu posición.")
                . $t::text("🚀 ¡Tú puedes!");

            $html = $t::wrap(
                "¡Te han adelantado en el ranking!",
                $body,
                $courseurl->out(false),
                "Ir al curso y practicar",
                '#fd7e14'
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
        $message->smallmessage = "📊 {$overtakername} te ha adelantado en {$course->fullname}";
        $message->notification = 1;
        $message->contexturl = $courseurl;
        $message->contexturlname = $course->fullname;
        $message->courseid = $courseid;

        $result = message_send($message);
        if (!$result) {
            debugging('block_ranking: Failed to send overtaken notification to user ' . $userid, DEBUG_DEVELOPER);
        }
    }
}
