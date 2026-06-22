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
 * Ranking block spanish language translation.
 *
 * Only strings that need a Spanish override are listed here; the rest
 * fall back to the English file automatically.
 *
 * @package    block_ranking
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pacing_usec'] = 'Microsegundos entre envíos del resumen semanal';
$string['pacing_usec_desc'] = 'Pausa entre cada message_send() durante la tarea cron weekly_summary. 1500000 = 1,5 segundos (recomendado). Pon 0 para desactivar. Evita ráfagas sub-segundo que históricamente dispararon los bloqueos S3115/S3140 de Microsoft.';

// Resúmenes diarios de ranking (agregación, sustituye a las notificaciones en tiempo real).
$string['see_full_ranking'] = 'Ver ranking completo';
$string['notification_daily_subject'] = '{$a->firstname}, novedades de tu ranking en {$a->coursename}';
$string['notification_daily_greeting'] = 'Hola {$a->firstname}, aquí tienes tu resumen de ranking en {$a->coursename}.';
$string['notification_daily_gained_one'] = 'Has subido 1 posición.';
$string['notification_daily_gained_many'] = 'Has subido {$a} posiciones.';
$string['notification_daily_lost_one'] = 'Has bajado 1 posición.';
$string['notification_daily_lost_many'] = 'Has bajado {$a} posiciones.';
$string['notification_daily_enteredtop3'] = 'Has entrado en el TOP 3.';
$string['notification_daily_lefttop3'] = 'Has salido del TOP 3.';
$string['notification_daily_status'] = 'Posición actual: {$a->position}. Puntos: {$a->points}.';
$string['notification_daily_link'] = 'Consulta el ranking completo: {$a}';
$string['notification_daily_smallmessage'] = 'Novedades de ranking: {$a}';
$string['task_daily_ranking_digest'] = 'Enviar resúmenes diarios de ranking';
$string['privacy:metadata:block_ranking_daily_state'] = 'Almacena el estado del resumen diario de ranking por usuario y curso.';
$string['privacy:metadata:block_ranking_daily_state:userid'] = 'El ID del usuario.';
$string['privacy:metadata:block_ranking_daily_state:courseid'] = 'El ID del curso.';
$string['privacy:metadata:block_ranking_daily_state:localdate'] = 'La fecha local del sitio del estado del resumen.';
$string['privacy:metadata:block_ranking_daily_state:startposition'] = 'La posición en el ranking en la primera marca del día.';
$string['privacy:metadata:block_ranking_daily_state:startpoints'] = 'Los puntos en el ranking en la primera marca del día.';
$string['privacy:metadata:block_ranking_daily_state:endposition'] = 'La posición en el ranking al final del día local.';
$string['privacy:metadata:block_ranking_daily_state:endpoints'] = 'Los puntos en el ranking al final del día local.';
$string['privacy:metadata:block_ranking_daily_state:enteredtop3'] = 'Si el usuario entró en el TOP 3 durante el día.';
$string['privacy:metadata:block_ranking_daily_state:lefttop3'] = 'Si el usuario salió del TOP 3 durante el día.';
$string['privacy:metadata:block_ranking_daily_state:wasovertaken'] = 'Si el usuario perdió posiciones durante el día.';
$string['privacy:metadata:block_ranking_daily_state:positionslost'] = 'Número de posiciones perdidas durante el día.';
$string['privacy:metadata:block_ranking_daily_state:positionsgained'] = 'Número de posiciones ganadas durante el día.';
$string['privacy:metadata:block_ranking_daily_state:sent'] = 'Si el estado del resumen diario se ha procesado.';
$string['privacy:metadata:block_ranking_daily_state:timesent'] = 'El momento en que se procesó el estado del resumen diario.';
$string['privacy:metadata:block_ranking_daily_state:timecreated'] = 'El momento en que se creó el estado.';
$string['privacy:metadata:block_ranking_daily_state:timemodified'] = 'El momento de la última modificación del estado.';
