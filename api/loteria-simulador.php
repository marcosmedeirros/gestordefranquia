<?php
/**
 * O SORTEADOR DE ENSAIO.
 *
 * Devolve a loteria da liga — quem entra, em que grupo, com quantas
 * bolinhas e com que chance — e, quando pedido, sorteia.
 *
 * Não depende de sessão de draft. A loteria precisa de duas coisas: uma
 * classificação lançada e as regras. Amarrar o ensaio a um draft "em
 * configuração" fazia a página depender de um estado que nem sempre existe,
 * e foi isso que a deixou em branco.
 *
 * Não escreve nada e não lê nada de ninguém, então dispensa login: o que
 * mostra é o que a liga anuncia no comunicado.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/loteria_grupos.php';

const SIM_PISO_IDX = 11;   // os 3 piores não caem além da 12ª escolha

try {
    $pdo = db();
    loteriaGarantirColunas($pdo);

    $liga = strtoupper(trim((string)($_GET['liga'] ?? 'ELITE')));
    if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) $liga = 'ELITE';
    $sortear = !empty($_GET['sortear']);

    // A última temporada da liga com classificação lançada.
    $st = $pdo->prepare("SELECT s.id, s.season_number FROM seasons s
                          WHERE s.league = ?
                            AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                       ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
    $st->execute([$liga]);
    $temporada = $st->fetch(PDO::FETCH_ASSOC);
    if (!$temporada) {
        echo json_encode(['success' => false, 'error' => "A {$liga} ainda não tem classificação lançada."]);
        exit;
    }

    $st = $pdo->prepare("SELECT ss.team_id, ss.position, COALESCE(ss.conference, t.conference) AS conference,
                                ss.wins, ss.points_for, ss.points_against, ss.overall_position, ss.lottery_group,
                                CONCAT(t.city,' ',t.name) AS team_name, t.photo_url
                           FROM season_standings ss
                           JOIN teams t ON t.id = ss.team_id
                          WHERE ss.season_id = ?");
    $st->execute([(int)$temporada['id']]);
    $standings = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$standings) {
        echo json_encode(['success' => false, 'error' => "A {$liga} não tem posições registradas."]);
        exit;
    }

    $g = loteriaMontarGrupos($standings);
    if (!$g['elegiveis']) {
        echo json_encode(['success' => false, 'error' => "Ninguém ficou fora do playoff na {$liga}."]);
        exit;
    }

    $foto = [];
    foreach ($standings as $row) {
        $foto[(int)$row['team_id']] = (!empty($row['photo_url']) && trim($row['photo_url']) !== '')
            ? $row['photo_url'] : '/img/default-team.png';
    }

    // Ordem natural: pior campanha primeiro. É o retrato antes de qualquer
    // bolinha rolar, e a régua contra a qual se mede quem subiu ou caiu.
    $natural = $g['elegiveis'];
    usort($natural, $g['pior_primeiro']);

    $protegidos = array_values(array_filter($natural, fn($t) => ($g['grupo_de'][$t] ?? 2) === 1));

    $ordem = $natural;
    $ajustes = [];
    if ($sortear) {
        // Sorteio ponderado sem reposição, sem renormalizar quem sobra.
        $pool = $g['bolinhas'];
        $ordem = [];
        $total = array_sum($pool);
        while ($pool) {
            $bolinha = random_int(1, max(1, $total));
            $acumulado = 0;
            foreach ($pool as $tid => $peso) {
                $acumulado += $peso;
                if ($bolinha <= $acumulado) {
                    $ordem[] = (int)$tid;
                    $total -= $peso;
                    unset($pool[$tid]);
                    break;
                }
            }
        }

        /* Piso: os 3 piores não caem além da 12ª. A ordem de atendimento é
           sorteada — quem é atendido primeiro fica com a vaga mais funda, e
           uma ordem fixa daria a três times de bolinhas iguais chances
           diferentes. */
        $fila = $protegidos;
        shuffle($fila);
        foreach ($fila as $tid) {
            $i = array_search($tid, $ordem, true);
            if ($i === false || $i <= SIM_PISO_IDX) continue;
            for ($j = SIM_PISO_IDX; $j >= 0; $j--) {
                if (!in_array($ordem[$j], $protegidos, true)) {
                    $troca = $ordem[$j];
                    $ordem[$j] = $tid;
                    $ordem[$i] = $troca;
                    $ajustes[] = ($g['nomes'][$tid] ?? "Time #$tid")
                        . ' subiu pelo piso de proteção: está entre os 3 piores e não pode cair além da 12ª escolha.';
                    break;
                }
            }
        }
    }

    $naturalIdx = array_flip($natural);
    $times = [];
    foreach ($ordem as $posicao => $tid) {
        $grupo = $g['grupo_de'][$tid] ?? 2;
        $times[] = [
            'pick'        => $posicao + 1,
            'team_id'     => $tid,
            'team_name'   => $g['nomes'][$tid] ?? "Time #$tid",
            'conference'  => $g['conferencias'][$tid] ?? '',
            'photo_url'   => $foto[$tid] ?? '/img/default-team.png',
            'posicao'     => $g['posicoes'][$tid] ?? 0,
            'grupo'       => $grupo,
            'grupo_label' => LOTERIA_GRUPOS_META[$grupo]['label'],
            'bolinhas'    => $g['bolinhas'][$tid] ?? 0,
            'top1'        => $g['top1'][$tid] ?? 0,
            'top3'        => $g['top3'][$tid] ?? 0,
            'top5'        => $g['top5'][$tid] ?? 0,
            // Quanto subiu ou caiu em relação à ordem da campanha.
            'delta'       => ($naturalIdx[$tid] ?? $posicao) - $posicao,
        ];
    }

    echo json_encode([
        'success'   => true,
        'liga'      => $liga,
        'temporada' => (int)$temporada['season_number'],
        'sorteado'  => $sortear,
        'bolinhas'  => array_sum($g['bolinhas']),
        'times'     => $times,
        'ajustes'   => $ajustes,
        'matriz'    => loteriaMatriz($g['bolinhas'], $protegidos, SIM_PISO_IDX),
        'grupos'    => LOTERIA_GRUPOS_META,
    ]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Não foi possível montar a loteria agora.']);
}
