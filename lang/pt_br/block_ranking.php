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
 * Ranking block portuguese language translation
 *
 * @package    block_ranking
 * @copyright  2017 Willian Mano http://conecti.me
 * @license    http://www.gnu.org/copyleft/gpl.html GNU GPL v3 or later
 */

$string['pluginname'] = 'Bloco Ranking';
$string['ranking'] = 'Ranking';
$string['ranking:addinstance'] = 'Adicionar um novo block de ranking';

$string['nostudents'] = 'Sem estudantes para exibir';
$string['blocktitle'] = 'Título do bloco';

$string['table_position'] = 'Pos';
$string['table_name'] = 'Nome';
$string['table_points'] = 'Pontos';
$string['your_score'] = 'Sua pontuação';
$string['see_full_ranking'] = 'Ver ranking completo';
$string['ranking_graphs'] = 'Gráficos do ranking';
$string['graph_types'] = 'Tipos de gráficos';
$string['graph_select_a_group'] = 'Selecione um grupo';
$string['graph_groups'] = 'Gráfico de pontos por grupo';
$string['graph_group_evolution'] = 'Gráficos da evolução dos pontos do grupo';
$string['graph_group_evolution_title'] = 'Gráficos da evolução dos pontos do grupo na última semana';
$string['graph_groups_avg'] = 'Gráficos de média de pontos por grupo';
$string['graph_access_deny'] = 'Você não tem permissão de visualizar os grupos do curso para ver este relatório.';
$string['graph_no_groups'] = 'Este curso não possui grupos para poder visualizar os relatórios.';

$string['report_title'] = '{$a} : Ranking geral dos estudantes';
$string['report_head'] = 'Detalhes do ranking: Primeiros {$a} estudantes';

// Global configuration.
$string['rankingsize'] = 'Tamanho do ranking';
$string['rankingsize_help'] = 'Número de estudantes que irão aparecer no ranking';
$string['configuration'] = 'Configuração do bloco Ranking';

// Activites points.
$string['resourcepoints'] = 'Pontos para recurso/arquivo';
$string['assignpoints'] = 'Pontos para tarefa';
$string['forumpoints'] = 'Pontos para fórum';
$string['pagepoints'] = 'Pontos para página html';
$string['workshoppoints'] = 'Pontos para laboratório de avaliação';
$string['defaultpoints'] = 'Pontuação padrão';

$string['monthly'] = 'Mensal';
$string['weekly'] = 'Semanal';
$string['general'] = 'Geral';

$string['yes'] = 'Sim';
$string['no'] = 'Não';

$string['enable_multiple_quizz_attempts'] = 'Habilitar mútiplas tentativas no quizz';
$string['enable_multiple_quizz_attempts_help'] = 'Possibilita que os estudantes ganhem pontos em todas as tentativa no quizz. Se essa opção for marcada como não, o estudante só receberá os pontos da primeira tentativa.';

$string['student_roles'] = 'Roles de estudante';
$string['student_roles_help'] = 'Selecione quais roles devem ser considerados como estudantes para o ranking. Usuários com esses roles receberão pontos e aparecerão na tabela de classificação.';

// Privacy API.
$string['privacy:metadata:ranking_points'] = 'Armazena os pontos de ranking do usuário por curso.';
$string['privacy:metadata:ranking_points:userid'] = 'O ID do usuário.';
$string['privacy:metadata:ranking_points:courseid'] = 'O ID do curso.';
$string['privacy:metadata:ranking_points:points'] = 'O total de pontos acumulados pelo usuário.';
$string['privacy:metadata:ranking_points:timecreated'] = 'A hora em que o registro foi criado.';
$string['privacy:metadata:ranking_points:timemodified'] = 'A hora da última modificação do registro.';
$string['privacy:metadata:ranking_logs'] = 'Armazena transações individuais de pontos para o ranking.';
$string['privacy:metadata:ranking_logs:rankingid'] = 'O ID do registro de pontos de ranking associado.';
$string['privacy:metadata:ranking_logs:courseid'] = 'O ID do curso.';
$string['privacy:metadata:ranking_logs:course_modules_completion'] = 'O ID da conclusão do módulo do curso que gerou esta entrada.';
$string['privacy:metadata:ranking_logs:points'] = 'Os pontos concedidos nesta transação.';
$string['privacy:metadata:ranking_logs:timecreated'] = 'A hora em que os pontos foram concedidos.';
