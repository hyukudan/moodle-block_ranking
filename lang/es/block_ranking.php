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
