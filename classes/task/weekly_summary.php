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

        // Pause between deliveries to avoid SMTP bursts (lessons learned: Microsoft
        // S3115 throttle escalated to S3140 blocklist after sub-second bursts on
        // Monday mornings). Configurable via the pacing_usec setting; 1.5s default.
        // 0 is a valid value (disable pacing); get_config returns false when the
        // setting hasn't been initialised yet, so we check explicitly to keep that
        // distinction.
        $pacingraw = get_config('block_ranking', 'pacing_usec');
        $pacingusec = ($pacingraw === false) ? 1500000 : (int)$pacingraw;

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

        $summaries = [];
        foreach ($courseids as $courseid) {
            $course = $courses[$courseid] ?? null;
            if (!$course) {
                continue;
            }

            $this->collect_course_summaries($courseid, $course, $summaries);
        }

        $this->send_user_summaries($summaries, $pacingusec);
    }

    /**
     * Add weekly summary rows for a specific course, grouped by user.
     *
     * @param int $courseid
     * @param \stdClass $course
     * @param array<int, array{userid: int, items: \stdClass[]}> $summaries User summaries.
     */
    protected function collect_course_summaries($courseid, $course, array &$summaries) {
        global $DB;

        // Get all ranked users ordered by points.
        $rankedusers = $DB->get_records('ranking_points', ['courseid' => $courseid], 'points DESC, userid ASC');

        if (empty($rankedusers)) {
            return;
        }

        $position = 0;
        $lastpoints = null;
        $reporturl = new \moodle_url('/blocks/ranking/report.php', ['courseid' => $courseid]);
        $participantcount = count($rankedusers);
        $coursename = self::compact_course_name(
            format_string($course->fullname, true, ['context' => \context_course::instance($courseid)])
        );

        foreach ($rankedusers as $record) {
            if ($lastpoints === null || (float) $record->points < $lastpoints) {
                $position++;
                $lastpoints = (float) $record->points;
            }

            $userid = (int)$record->userid;
            if (!isset($summaries[$userid])) {
                $summaries[$userid] = [
                    'userid' => $userid,
                    'items' => [],
                ];
            }

            $item = new \stdClass();
            $item->courseid = $courseid;
            $item->coursename = $coursename;
            $item->position = $position;
            $item->points = (float)$record->points;
            $item->pointslabel = self::format_points((float)$record->points);
            $item->participantcount = $participantcount;
            $item->reporturl = $reporturl->out(false);

            $summaries[$userid]['items'][] = $item;
        }

        mtrace("block_ranking: Collected weekly summaries for course $courseid ({$course->shortname})");
    }

    /**
     * Send one weekly notification per user.
     *
     * @param array<int, array{userid: int, items: \stdClass[]}> $summaries User summaries.
     * @param int $pacingusec Microseconds to sleep between message_send() calls (0 = disabled).
     */
    protected function send_user_summaries(array $summaries, int $pacingusec = 0): void {
        if (empty($summaries)) {
            return;
        }

        $sent = 0;
        $remaining = count($summaries);

        foreach ($summaries as $summary) {
            $userid = (int)$summary['userid'];
            $user = \core_user::get_user($userid, '*', IGNORE_MISSING);
            if (!$user || !empty($user->deleted) || !empty($user->suspended)) {
                --$remaining; // Saltado: sin pausa (el pacing es para envíos reales).
                continue;
            }

            $items = $summary['items'];
            if (empty($items)) {
                --$remaining; // Saltado: sin pausa (el pacing es para envíos reales).
                continue;
            }

            usort($items, static function(\stdClass $a, \stdClass $b): int {
                return strcasecmp($a->coursename, $b->coursename);
            });

            $payload = $this->build_user_payload($user, $items);
            $message = new \core\message\message();
            $message->component = 'block_ranking';
            $message->name = 'ranking_update';
            $message->userfrom = \core_user::get_noreply_user();
            $message->userto = $user;
            $message->subject = $payload['subject'];
            $message->fullmessage = $payload['fullmessage'];
            $message->fullmessageformat = FORMAT_HTML;
            $message->fullmessagehtml = $payload['fullmessagehtml'];
            $message->smallmessage = $payload['smallmessage'];
            $message->notification = 1;
            $message->contexturl = $payload['contexturl'];
            $message->contexturlname = $payload['contexturlname'];
            $message->courseid = $payload['courseid'];

            // Skip the pause after the last delivery — no point waiting before exiting.
            try {
                message_send($message);
                $sent++;
            } catch (\Exception $e) {
                debugging('block_ranking: Failed to send weekly summary to user ' .
                    $userid . ': ' . $e->getMessage(), DEBUG_DEVELOPER);
            }

            if (--$remaining > 0 && $pacingusec > 0) {
                usleep($pacingusec);
            }
        }

        mtrace("block_ranking: Sent $sent weekly ranking summaries to users");
    }

    /**
     * Build the subject, body and link for one user's grouped ranking summary.
     *
     * @param \stdClass $user User record.
     * @param \stdClass[] $items Ranking rows.
     * @return array<string, mixed> Message payload.
     */
    protected function build_user_payload(\stdClass $user, array $items): array {
        $subject = 'Tu ranking semanal';
        $displayname = trim((string)($user->firstname ?? ''));
        if ($displayname === '') {
            $displayname = fullname($user);
        }

        $lines = [];
        foreach ($items as $item) {
            $lines[] = "{$item->coursename}: #{$item->position} ({$item->pointslabel} pts)";
        }

        $singlecourse = count($items) === 1;
        $contexturl = $singlecourse
            ? new \moodle_url('/blocks/ranking/report.php', ['courseid' => (int)$items[0]->courseid])
            : new \moodle_url('/my/courses.php');
        $contexturlname = $singlecourse ? $items[0]->coursename : 'Mis cursos';
        $courseid = $singlecourse ? (int)$items[0]->courseid : SITEID;
        $motivational = self::get_motivational_text($items);

        $fullmessage = "¡Hola {$displayname}!\n\n"
            . "Aquí tienes tu resumen semanal del ranking.\n\n"
            . implode("\n", $lines)
            . "\n\n{$motivational}"
            . "\n\nConsulta el ranking: " . $contexturl->out(false);

        $fullmessagehtml = '<p>' . nl2br(s($fullmessage)) . '</p>';
        if (class_exists('\local_achievements\email_template')) {
            $t = '\local_achievements\email_template';
            $body = $t::text(
                '¡Hola <strong>' . s($displayname) . '</strong>! Aquí tienes tu resumen semanal del ranking.',
                'left'
            );

            $body .= $t::divider();
            $body .= $t::stat_row(self::get_summary_cards($items));
            $body .= $t::highlight(self::format_html_lines($items), 'neutral', 'left');
            $body .= $t::highlight(s($motivational), 'warning', 'left');

            $fullmessagehtml = $t::wrap(
                'Resumen semanal del ranking',
                $body,
                $contexturl->out(false),
                $singlecourse ? 'Ver ranking completo' : 'Ver mis cursos',
                '#1e3a5f'
            );
        }

        return [
            'subject' => $subject,
            'fullmessage' => $fullmessage,
            'fullmessagehtml' => $fullmessagehtml,
            'smallmessage' => $singlecourse ? $lines[0] : 'Ranking semanal: ' . count($items) . ' cursos',
            'contexturl' => $contexturl,
            'contexturlname' => $contexturlname,
            'courseid' => $courseid,
        ];
    }

    /**
     * Return compact stat cards for the existing branded template.
     *
     * @param \stdClass[] $items Ranking rows.
     * @return array<int, array<int, string>>
     */
    private static function get_summary_cards(array $items): array {
        if (count($items) === 1) {
            $item = $items[0];
            return [
                [self::position_icon((int)$item->position), '#' . $item->position, 'Tu posición'],
                ['', $item->pointslabel, 'Puntos totales'],
            ];
        }

        $bestposition = min(array_map(static function(\stdClass $item): int {
            return (int)$item->position;
        }, $items));

        return [
            ['', (string)count($items), 'Cursos'],
            [self::position_icon($bestposition), '#' . $bestposition, 'Mejor posición'],
        ];
    }

    /**
     * Build the HTML course list.
     *
     * @param \stdClass[] $items Ranking rows.
     * @return string HTML lines.
     */
    private static function format_html_lines(array $items): string {
        $lines = [];
        foreach ($items as $item) {
            $lines[] = s($item->coursename) . ': <strong>#' . (int)$item->position . '</strong> (' .
                s($item->pointslabel) . ' pts)';
        }
        return implode('<br>', $lines);
    }

    /**
     * Return motivational copy without claiming TOP 10 in small rankings.
     *
     * @param \stdClass[] $items Ranking rows.
     * @return string Motivational copy.
     */
    private static function get_motivational_text(array $items): string {
        foreach ($items as $item) {
            if ((int)$item->position === 1) {
                return '¡Vas en cabeza! Sigue así para mantener tu posición.';
            }
        }

        foreach ($items as $item) {
            if ((int)$item->position <= 3) {
                return '¡Estás en el podio! Sigue así para mantener tu posición.';
            }
        }

        foreach ($items as $item) {
            if ((int)$item->position <= 10 && (int)$item->participantcount >= 15) {
                return '¡Estás en el TOP 10! Un poco más de esfuerzo y llegarás al podio.';
            }
        }

        return '¡Cada punto cuenta! Sigue practicando para escalar posiciones.';
    }

    /**
     * Return the course name as it should appear in compact notification copy.
     *
     * @param string $fullname Formatted course full name.
     * @return string Compact course name.
     */
    public static function compact_course_name(string $fullname): string {
        $original = trim($fullname);
        $compact = preg_replace('/\s+/', ' ', $original);
        $compact = trim((string)$compact);

        do {
            $previous = $compact;
            $compact = preg_replace(
                '/^(Pack Premium|Temario PDF|Curso Online)(\s*:\s*|\s*\+\s*Curso Online\s*:?\s*)/i',
                '',
                $compact
            );
            $compact = trim((string)$compact);
        } while ($compact !== '' && $compact !== $previous);

        return $compact !== '' ? $compact : $original;
    }

    /**
     * Format ranking points for compact notification lines.
     *
     * @param float $points Points.
     * @return string Formatted points.
     */
    private static function format_points(float $points): string {
        $decimals = (abs($points - round($points)) < 0.00001) ? 0 : 1;
        return number_format($points, $decimals, ',', '.');
    }

    /**
     * Return a display icon for a ranking position.
     *
     * @param int $position Ranking position.
     * @return string Icon.
     */
    private static function position_icon(int $position): string {
        if ($position === 1) {
            return '';
        }
        if ($position === 2) {
            return '';
        }
        if ($position === 3) {
            return '';
        }
        return '';
    }
}
