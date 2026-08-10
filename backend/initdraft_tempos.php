<?php
/**
 * Conta quanto cada GM demorou pra escolher no draft inicial.
 *
 * Separado da pagina porque e a parte que precisa de teste: a pagina so
 * desenha o que sai daqui. Ver initdraft-tempos.php pro porque da medida.
 */

/** Hora em que a janela do dia abre, quando o draft não usou agenda diária. */
const TEMPOS_ABERTURA_PADRAO = '08:00:00';

/**
 * Mede cada pick de uma sessão e agrega por time.
 * Devolve ['picks' => [...], 'times' => [...]].
 */
function temposDaSessao(PDO $pdo, array $sessao): array
{
    $stmt = $pdo->prepare("
        SELECT o.id, o.round, o.pick_position, o.picked_at, o.team_id,
               t.city, t.mascot, t.name AS team_name,
               u.name AS gm,
               p.name AS jogador
        FROM initdraft_order o
        JOIN teams t ON t.id = o.team_id
        LEFT JOIN users u ON u.id = t.user_id
        LEFT JOIN initdraft_pool p ON p.id = o.picked_player_id
        WHERE o.initdraft_session_id = ? AND o.picked_at IS NOT NULL
        ORDER BY o.picked_at ASC, o.round ASC, o.pick_position ASC
    ");
    $stmt->execute([(int)$sessao['id']]);
    $linhas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$linhas) return ['picks' => [], 'times' => []];

    $usaAgenda = (int)($sessao['daily_schedule_enabled'] ?? 0) === 1;
    $abertura = $usaAgenda && !empty($sessao['daily_clock_start_time'])
        ? (string)$sessao['daily_clock_start_time']
        : TEMPOS_ABERTURA_PADRAO;

    // Âncora da primeira pick: quando a sessão começou. Sem isso, a primeira
    // pick não teria contra o que medir.
    $anterior = !empty($sessao['started_at']) ? strtotime((string)$sessao['started_at']) : null;

    $picks = [];
    $porTime = [];

    foreach ($linhas as $l) {
        $quando = strtotime((string)$l['picked_at']);

        // Uma regra só: o relógio começa na ÚLTIMA abertura de janela que já
        // tinha acontecido quando a pick foi feita, ou na pick anterior — o que
        // for mais recente.
        //
        // É isso que impede a madrugada de virar demora: se a janela abre 09:00
        // e o cara escolheu 09:07, são 7 minutos, não importa que a pick
        // anterior tenha sido às 23h de ontem.
        //
        // Ter uma regra só, e não um caso especial pra pick antes da abertura,
        // importa: o caso especial media essa pick pelo intervalo cru e cuspia
        // "23h" onde as vizinhas mostravam minutos.
        $aberturaDoDia = strtotime(date('Y-m-d', $quando) . ' ' . $abertura);
        if ($aberturaDoDia > $quando) {
            $aberturaDoDia = strtotime('-1 day', $aberturaDoDia);   // ainda não abriu hoje
        }
        $inicio = max((int)$anterior, $aberturaDoDia);
        if (!$inicio) $inicio = $quando;   // primeira pick sem started_at

        $segundos = max(0, $quando - $inicio);
        $bruto    = $anterior ? max(0, $quando - (int)$anterior) : 0;

        // A 1ª da 1ª rodada não entra no ranking: o tempo dela é o tempo de
        // divulgar o link do draft, não o de decidir. Fica na lista pra não
        // sumir sem explicação, só não conta.
        $conta = !((int)$l['round'] === 1 && (int)$l['pick_position'] === 1);

        $nome = trim(trim((string)$l['city']) . ' ' . trim((string)$l['mascot'])) ?: (string)$l['team_name'];
        $picks[] = [
            'round' => (int)$l['round'], 'pick' => (int)$l['pick_position'],
            'time' => $nome, 'gm' => $l['gm'] ?: 'sem dono',
            'jogador' => $l['jogador'] ?: '—',
            'quando' => (string)$l['picked_at'],
            'segundos' => $segundos, 'bruto' => $bruto,
            'conta' => $conta,
        ];

        if (!$conta) { $anterior = $quando; continue; }

        $k = (int)$l['team_id'];
        if (!isset($porTime[$k])) {
            $porTime[$k] = ['time' => $nome, 'gm' => $l['gm'] ?: 'sem dono',
                            'picks' => 0, 'total' => 0, 'pior' => 0, 'pior_jogador' => null];
        }
        $porTime[$k]['picks']++;
        $porTime[$k]['total'] += $segundos;
        if ($segundos > $porTime[$k]['pior']) {
            $porTime[$k]['pior'] = $segundos;
            $porTime[$k]['pior_jogador'] = $l['jogador'] ?: null;
        }

        $anterior = $quando;
    }

    foreach ($porTime as &$t) {
        $t['media'] = $t['picks'] ? (int)round($t['total'] / $t['picks']) : 0;
    }
    unset($t);

    // Quem mais demorou primeiro — é o ranking que a página promete.
    uasort($porTime, fn($a, $b) => $b['media'] <=> $a['media']);

    return ['picks' => $picks, 'times' => array_values($porTime)];
}

/** Segundos em algo legível: "3min 20s", "1h 04min". */
function fmtDuracao(int $s): string
{
    if ($s < 60) return $s . 's';
    if ($s < 3600) return floor($s / 60) . 'min ' . str_pad((string)($s % 60), 2, '0', STR_PAD_LEFT) . 's';
    return floor($s / 3600) . 'h ' . str_pad((string)floor(($s % 3600) / 60), 2, '0', STR_PAD_LEFT) . 'min';
}
