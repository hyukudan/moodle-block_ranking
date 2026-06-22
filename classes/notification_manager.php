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
     * Staff capabilities that prevent ranking digest delivery.
     */
    const STAFF_CAPABILITIES = [
        'moodle/course:update',
        'moodle/grade:viewall',
        'mod/quiz:preview',
    ];

    /**
     * Record that a user's ranking changed on the current site-local day.
     *
     * The first mark of the day stores the current position/points as the
     * daily baseline. Later marks for the same user/course/day are deduped by
     * the unique database index.
     *
     * @param int $userid The user whose points are about to change.
     * @param int $courseid The course.
     * @param int|null $time Timestamp used to calculate the local day.
     * @return void
     */
    public static function mark_daily_state($userid, $courseid, $time = null) {
        global $DB;

        $time = $time ?? time();
        $localdate = self::get_local_date($time);
        $conditions = [
            'courseid' => $courseid,
            'userid' => $userid,
            'localdate' => $localdate,
        ];

        if ($DB->record_exists('block_ranking_daily_state', $conditions)) {
            return;
        }

        $snapshot = self::get_user_ranking_snapshot($userid, $courseid);

        $record = (object) [
            'courseid' => $courseid,
            'userid' => $userid,
            'localdate' => $localdate,
            'startposition' => $snapshot->position,
            'startpoints' => $snapshot->points,
            'endposition' => 0,
            'endpoints' => 0,
            'enteredtop3' => 0,
            'lefttop3' => 0,
            'wasovertaken' => 0,
            'positionslost' => 0,
            'positionsgained' => 0,
            'sent' => 0,
            'timesent' => 0,
            'timecreated' => $time,
            'timemodified' => $time,
        ];

        try {
            $DB->insert_record('block_ranking_daily_state', $record);
        } catch (\dml_write_exception $e) {
            debugging('block_ranking: Failed to mark daily ranking state - ' . $e->getMessage(), DEBUG_DEVELOPER);
        }
    }

    /**
     * Get a Ymd integer for the timestamp in the Moodle site timezone.
     *
     * @param int|null $time Timestamp, defaults to now.
     * @return int
     */
    public static function get_local_date($time = null) {
        $time = $time ?? time();
        $date = (new \DateTimeImmutable('@' . $time))->setTimezone(\core_date::get_server_timezone_object());

        return (int) $date->format('Ymd');
    }

    /**
     * Get the last closed site-local day as a Ymd integer.
     *
     * @param int|null $time Timestamp, defaults to now.
     * @return int
     */
    public static function get_last_closed_local_date($time = null) {
        $time = $time ?? time();
        $date = (new \DateTimeImmutable('@' . $time))
            ->setTimezone(\core_date::get_server_timezone_object())
            ->modify('-1 day');

        return (int) $date->format('Ymd');
    }

    /**
     * Return the start and exclusive end timestamps for a site-local day.
     *
     * @param int $localdate Date in Ymd format.
     * @return int[]
     */
    public static function get_local_day_bounds($localdate) {
        $date = \DateTimeImmutable::createFromFormat(
            '!Ymd',
            (string) $localdate,
            \core_date::get_server_timezone_object()
        );

        if (!$date) {
            throw new \coding_exception('Invalid localdate for ranking digest: ' . $localdate);
        }

        return [$date->getTimestamp(), $date->modify('+1 day')->getTimestamp()];
    }

    /**
     * Get a user's current ranking position and points in a course.
     *
     * @param int $userid The user.
     * @param int $courseid The course.
     * @return \stdClass Object with position and points.
     */
    public static function get_user_ranking_snapshot($userid, $courseid) {
        global $DB;

        $rankrecord = $DB->get_record('ranking_points', ['userid' => $userid, 'courseid' => $courseid]);
        if (!$rankrecord || (float) $rankrecord->points <= 0) {
            return (object) ['position' => 0, 'points' => 0.0];
        }

        $position = self::count_higher_current_users($courseid, (float) $rankrecord->points) + 1;

        return (object) [
            'position' => (int) $position,
            'points' => (float) $rankrecord->points,
        ];
    }

    /**
     * Return ranked users and points as they stood at a cutoff timestamp.
     *
     * @param int $courseid The course.
     * @param int $cutoff Exclusive timestamp. Later logs are subtracted.
     * @return \stdClass[] Records keyed by userid.
     */
    public static function get_course_rankings_at($courseid, $cutoff) {
        global $DB;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return [];
        }

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return [];
        }

        list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $enroljoin = get_enrolled_join($context, 'u.id', true);

        $sql = "SELECT ranked.userid, ranked.points,
                       RANK() OVER (ORDER BY ranked.points DESC) AS position
                  FROM (
                        SELECT DISTINCT rp.userid,
                               rp.points - COALESCE(later.pointssince, 0) AS points
                          FROM {ranking_points} rp
                          JOIN {user} u ON u.id = rp.userid
                          JOIN {role_assignments} ra ON ra.userid = u.id
                    {$enroljoin->joins}
                     LEFT JOIN (
                                SELECT rankingid, SUM(points) AS pointssince
                                  FROM {ranking_logs}
                                 WHERE timecreated >= :cutoff
                              GROUP BY rankingid
                               ) later ON later.rankingid = rp.id
                         WHERE rp.courseid = :courseid
                           AND u.deleted = 0
                           AND u.suspended = 0
                           AND ra.contextid = :contextid
                           AND ra.roleid $rolesql
                           AND {$enroljoin->wheres}
                       ) ranked
                 WHERE ranked.points > 0
              ORDER BY position ASC, userid ASC";

        $params = array_merge($roleparams, $enroljoin->params, [
            'cutoff' => $cutoff,
            'courseid' => $courseid,
            'contextid' => $context->id,
        ]);

        return $DB->get_records_sql($sql, $params);
    }

    /**
     * Check whether a user can receive a daily ranking digest.
     *
     * @param int $userid The user.
     * @param int $courseid The course.
     * @return bool
     */
    public static function is_daily_digest_recipient($userid, $courseid) {
        global $DB;

        $user = $DB->get_record('user', ['id' => $userid, 'deleted' => 0, 'suspended' => 0]);
        if (!$user) {
            return false;
        }

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context || !is_enrolled($context, $userid, '', true)) {
            return false;
        }

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return false;
        }

        list($rolesql, $params) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $params['userid'] = $userid;
        $params['contextid'] = $context->id;

        $sql = "SELECT COUNT(1)
                  FROM {role_assignments}
                 WHERE userid = :userid
                   AND contextid = :contextid
                   AND roleid $rolesql";
        if (!$DB->count_records_sql($sql, $params)) {
            return false;
        }

        foreach (self::STAFF_CAPABILITIES as $capability) {
            if (has_capability($capability, $context, $userid)) {
                return false;
            }
        }

        if (has_capability('moodle/site:config', \context_system::instance(), $userid)) {
            return false;
        }

        return true;
    }

    /**
     * Send a daily ranking digest.
     *
     * @param \stdClass $state Daily state record.
     * @param \stdClass $course Course record.
     * @return bool True if Moodle accepted the message.
     */
    public static function send_daily_digest(\stdClass $state, \stdClass $course) {
        $changes = self::get_daily_digest_changes($state);
        if (empty($changes)) {
            return false;
        }

        $user = \core_user::get_user($state->userid, '*', MUST_EXIST);
        $reporturl = new \moodle_url('/blocks/ranking/report.php', ['courseid' => $course->id]);
        $position = ((int) $state->endposition > 0) ? '#' . (int) $state->endposition : '-';
        $points = format_float((float) $state->endpoints, 1, true, true);

        $a = (object) [
            'firstname' => $user->firstname,
            'coursename' => $course->fullname,
            'position' => $position,
            'points' => $points,
        ];

        $subject = get_string('notification_daily_subject', 'block_ranking', $a);
        $plaintext = get_string('notification_daily_greeting', 'block_ranking', $a) . "\n\n"
            . implode("\n", $changes) . "\n\n"
            . get_string('notification_daily_status', 'block_ranking', $a) . "\n\n"
            . get_string('notification_daily_link', 'block_ranking', $reporturl->out(false));

        $items = '';
        foreach ($changes as $change) {
            $items .= '<li>' . s($change) . '</li>';
        }
        $html = '<p>' . s(get_string('notification_daily_greeting', 'block_ranking', $a)) . '</p>'
            . '<ul>' . $items . '</ul>'
            . '<p>' . s(get_string('notification_daily_status', 'block_ranking', $a)) . '</p>'
            . '<p><a href="' . s($reporturl->out(false)) . '">' .
                s(get_string('see_full_ranking', 'block_ranking')) . '</a></p>';

        $message = new \core\message\message();
        $message->component = 'block_ranking';
        $message->name = 'ranking_update';
        $message->userfrom = \core_user::get_noreply_user();
        $message->userto = $user;
        $message->subject = $subject;
        $message->fullmessage = $plaintext;
        $message->fullmessageformat = FORMAT_PLAIN;
        $message->fullmessagehtml = $html;
        $message->smallmessage = get_string('notification_daily_smallmessage', 'block_ranking', reset($changes));
        $message->notification = 1;
        $message->contexturl = $reporturl;
        $message->contexturlname = $course->fullname;
        $message->courseid = $course->id;

        try {
            return (bool) message_send($message);
        } catch (\Exception $e) {
            debugging('block_ranking: Failed to send daily digest to user ' .
                $state->userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            return false;
        }
    }

    /**
     * Count users currently ranked above the supplied points total.
     *
     * @param int $courseid The course.
     * @param float $points Points to compare.
     * @return int
     */
    protected static function count_higher_current_users($courseid, $points) {
        global $DB;

        $context = \context_course::instance($courseid, IGNORE_MISSING);
        if (!$context) {
            return 0;
        }

        $roleids = block_ranking_helper::get_student_role_ids();
        if (empty($roleids)) {
            return 0;
        }

        list($rolesql, $roleparams) = $DB->get_in_or_equal($roleids, SQL_PARAMS_NAMED, 'role');
        $enroljoin = get_enrolled_join($context, 'u.id', true);

        $sql = "SELECT COUNT(DISTINCT rp.userid)
                  FROM {ranking_points} rp
                  JOIN {user} u ON u.id = rp.userid
                  JOIN {role_assignments} ra ON ra.userid = u.id
            {$enroljoin->joins}
                 WHERE rp.courseid = :courseid
                   AND rp.points > :points
                   AND u.deleted = 0
                   AND u.suspended = 0
                   AND ra.contextid = :contextid
                   AND ra.roleid $rolesql
                   AND {$enroljoin->wheres}";

        $params = array_merge($roleparams, $enroljoin->params, [
            'courseid' => $courseid,
            'points' => $points,
            'contextid' => $context->id,
        ]);

        return (int) $DB->count_records_sql($sql, $params);
    }

    /**
     * Build human-readable digest change lines.
     *
     * @param \stdClass $state Daily state record.
     * @return string[]
     */
    protected static function get_daily_digest_changes(\stdClass $state) {
        $changes = [];

        if (!empty($state->positionsgained)) {
            $key = ((int) $state->positionsgained === 1) ?
                'notification_daily_gained_one' : 'notification_daily_gained_many';
            $changes[] = get_string($key, 'block_ranking', (int) $state->positionsgained);
        }

        if (!empty($state->positionslost)) {
            $key = ((int) $state->positionslost === 1) ?
                'notification_daily_lost_one' : 'notification_daily_lost_many';
            $changes[] = get_string($key, 'block_ranking', (int) $state->positionslost);
        }

        if (!empty($state->enteredtop3)) {
            $changes[] = get_string('notification_daily_enteredtop3', 'block_ranking');
        }

        if (!empty($state->lefttop3)) {
            $changes[] = get_string('notification_daily_lefttop3', 'block_ranking');
        }

        return $changes;
    }

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
