<?php
/**
 * Rascunho tático do time — o que o GM ajusta no dia a dia em tatica.php.
 *
 * GET  ?action=get     → tática salva + elenco + sugestões automáticas
 * POST action=save     → grava o rascunho (não envia diretriz nenhuma)
 *
 * Este rascunho NÃO é a diretriz oficial: ele alimenta o formulário de
 * diretrizes.php na hora do envio, para o GM só revisar em vez de digitar
 * tudo de novo.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
requireAuth();

header('Content-Type: application/json; charset=utf-8');

$pdo  = db();
$user = getUserSession();

$stmtTeam = $pdo->prepare('SELECT id, city, name, league FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$user['id']]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
if (!$team) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Você não tem time nesta liga.']);
    exit;
}
$teamId = (int)$team['id'];

/** Ordem natural do quinteto: armador a pivô. */
const TATICA_ORDEM_POS = ['PG' => 1, 'SG' => 2, 'SF' => 3, 'PF' => 4, 'C' => 5];

/** Os três slots de tática. Fora deles, cai no regular. */
const TATICA_SLOTS = ['regular' => 'Temporada Regular', 'playoffs' => 'Playoffs', 'outra' => 'Outra'];

function tacticaSlot($v): string {
    $v = strtolower(trim((string)$v));
    return isset(TATICA_SLOTS[$v]) ? $v : 'regular';
}

const TATICA_CAMPOS = [
    'starter_1_id','starter_2_id','starter_3_id','starter_4_id','starter_5_id',
    'bench_1_id','bench_2_id','bench_3_id','gleague_1_id','gleague_2_id',
    'pace','offensive_rebound','offensive_aggression','defensive_focus',
    'defensive_rebound','rotation_style','game_style','offense_style',
    'rotation_players','veteran_focus','technical_model','playbook','notes',
];

/**
 * Quinteto sugerido: melhor OVR de cada posição, preferindo quem já está
 * marcado como Titular no elenco. Posição vazia é preenchida pelo melhor
 * disponível, para o time nunca sair com menos de cinco.
 */
function sugerirQuinteto(array $jogadores): array {
    $usados = [];
    $quinteto = [];

    foreach (array_keys(TATICA_ORDEM_POS) as $pos) {
        $candidatos = array_filter($jogadores, function ($p) use ($pos, $usados) {
            if (in_array((int)$p['id'], $usados, true)) return false;
            return strtoupper((string)$p['position']) === $pos
                || strtoupper((string)$p['secondary_position']) === $pos;
        });
        usort($candidatos, function ($a, $b) use ($pos) {
            // Titular marcado no elenco vem antes; depois o de maior OVR.
            $ta = ($a['role'] === 'Titular') ? 1 : 0;
            $tb = ($b['role'] === 'Titular') ? 1 : 0;
            if ($ta !== $tb) return $tb <=> $ta;
            // Posição principal ganha da secundária.
            $pa = strtoupper((string)$a['position']) === $pos ? 1 : 0;
            $pb = strtoupper((string)$b['position']) === $pos ? 1 : 0;
            if ($pa !== $pb) return $pb <=> $pa;
            return (int)$b['ovr'] <=> (int)$a['ovr'];
        });
        $escolhido = $candidatos ? reset($candidatos) : null;
        if ($escolhido) {
            $quinteto[] = (int)$escolhido['id'];
            $usados[] = (int)$escolhido['id'];
        } else {
            $quinteto[] = null;
        }
    }

    // Posições sem ninguém: completa pelo melhor OVR que sobrou.
    $restantes = array_values(array_filter($jogadores, fn($p) => !in_array((int)$p['id'], $usados, true)));
    usort($restantes, fn($a, $b) => (int)$b['ovr'] <=> (int)$a['ovr']);
    foreach ($quinteto as $i => $v) {
        if ($v === null && $restantes) {
            $p = array_shift($restantes);
            $quinteto[$i] = (int)$p['id'];
            $usados[] = (int)$p['id'];
        }
    }
    return $quinteto;
}

/**
 * Distribui exatamente 240 minutos (5 em quadra x 48). Titulares recebem mais,
 * e dentro de cada grupo o peso é o OVR. O resto da divisão vai para o titular
 * de maior OVR, para o total fechar cravado.
 */
function sugerirMinutos(array $jogadores, array $quinteto): array {
    $elegiveis = array_values(array_filter($jogadores, fn($p) => ($p['role'] ?? '') !== 'G-League'));
    if (!$elegiveis) return [];

    $pesos = [];
    foreach ($elegiveis as $p) {
        $id  = (int)$p['id'];
        $ovr = max(40, (int)$p['ovr']);
        // Titular pesa o dobro; quem nem está na rotação pesa pouco.
        $mult = in_array($id, $quinteto, true) ? 2.0
              : (($p['role'] ?? '') === 'Titular' ? 1.4
              : (($p['role'] ?? '') === 'Banco' ? 1.0 : 0.55));
        $pesos[$id] = pow($ovr / 50, 2) * $mult;
    }
    $soma = array_sum($pesos);
    if ($soma <= 0) return [];

    $min = [];
    foreach ($pesos as $id => $peso) {
        // Teto de 42 e piso de 4: ninguém joga o jogo inteiro nem 1 minuto.
        $min[$id] = max(0, min(42, (int)round($peso / $soma * 240)));
    }

    // Acerta a diferença para fechar 240 exatos.
    $diff = 240 - array_sum($min);
    if ($diff !== 0) {
        $ordem = array_keys($min);
        usort($ordem, function ($a, $b) use ($min, $quinteto) {
            $qa = in_array($a, $quinteto, true) ? 1 : 0;
            $qb = in_array($b, $quinteto, true) ? 1 : 0;
            if ($qa !== $qb) return $qb <=> $qa;
            return $min[$b] <=> $min[$a];
        });
        foreach ($ordem as $id) {
            if ($diff === 0) break;
            $passo = $diff > 0 ? 1 : -1;
            $novo = $min[$id] + $passo;
            if ($novo < 0 || $novo > 42) continue;
            $min[$id] = $novo;
            $diff -= $passo;
        }
    }
    return $min;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $stmtP = $pdo->prepare("SELECT id, name, position, secondary_position, ovr, age, role
                            FROM players WHERE team_id = ? ORDER BY ovr DESC, name");
    $stmtP->execute([$teamId]);
    $jogadores = $stmtP->fetchAll(PDO::FETCH_ASSOC);

    $slot = tacticaSlot($_GET['slot'] ?? 'regular');

    // Quais slots ja tem algo salvo, para a tela marcar os preenchidos.
    $stmtSlots = $pdo->prepare('SELECT slot, updated_at FROM team_tactics WHERE team_id = ?');
    $stmtSlots->execute([$teamId]);
    $slotsSalvos = [];
    foreach ($stmtSlots->fetchAll(PDO::FETCH_ASSOC) as $r) $slotsSalvos[$r['slot']] = $r['updated_at'];

    $stmtT = $pdo->prepare('SELECT * FROM team_tactics WHERE team_id = ? AND slot = ?');
    $stmtT->execute([$teamId, $slot]);
    $tatica = $stmtT->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($tatica) {
        $tatica['player_minutes'] = json_decode((string)$tatica['player_minutes'], true) ?: [];
    } elseif ($slot !== 'regular') {
        // Slot novo: parte da tatica regular, que e a base do time.
        $stmtBase = $pdo->prepare("SELECT * FROM team_tactics WHERE team_id = ? AND slot = 'regular'");
        $stmtBase->execute([$teamId]);
        $base = $stmtBase->fetch(PDO::FETCH_ASSOC) ?: null;
        if ($base) {
            $base['player_minutes'] = json_decode((string)$base['player_minutes'], true) ?: [];
            $base['_origem'] = 'regular';
            $tatica = $base;
        }
    } else {
        // Sem rascunho ainda: aproveita o perfil tático que o time já tem em
        // diretrizes.php, para quem já usava o sistema não recomeçar do zero.
        $stmtPerf = $pdo->prepare('SELECT directive_profile FROM teams WHERE id = ?');
        $stmtPerf->execute([$teamId]);
        $perfil = json_decode((string)$stmtPerf->fetchColumn(), true);
        if (is_array($perfil) && $perfil) {
            $tatica = [];
            foreach (TATICA_CAMPOS as $campo) $tatica[$campo] = $perfil[$campo] ?? null;
            $tatica['player_minutes'] = is_array($perfil['player_minutes'] ?? null) ? $perfil['player_minutes'] : [];
            $tatica['_origem'] = 'perfil';
        }
    }

    // Última diretriz enviada, para a opção "repetir a anterior".
    $stmtU = $pdo->prepare("SELECT td.*, dd.description AS deadline_desc
                            FROM team_directives td
                            LEFT JOIN directive_deadlines dd ON dd.id = td.deadline_id
                            WHERE td.team_id = ? ORDER BY td.id DESC LIMIT 1");
    $stmtU->execute([$teamId]);
    $ultima = $stmtU->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($ultima) {
        $stmtM = $pdo->prepare('SELECT player_id, minutes_per_game FROM directive_player_minutes WHERE directive_id = ?');
        $stmtM->execute([(int)$ultima['id']]);
        $mins = [];
        foreach ($stmtM->fetchAll(PDO::FETCH_ASSOC) as $r) $mins[(int)$r['player_id']] = (int)$r['minutes_per_game'];
        $ultima['player_minutes'] = $mins;
    }

    $quintetoSugerido = sugerirQuinteto($jogadores);

    echo json_encode([
        'success'   => true,
        'team'      => ['id' => $teamId, 'name' => trim($team['city'] . ' ' . $team['name']), 'league' => $team['league']],
        'players'   => $jogadores,
        'tactics'   => $tatica,
        'slot'      => $slot,
        'slots'     => TATICA_SLOTS,
        'saved_slots' => $slotsSalvos,
        'last'      => $ultima,
        'suggested' => [
            'starters' => $quintetoSugerido,
            'minutes'  => sugerirMinutos($jogadores, $quintetoSugerido),
        ],
    ]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true) ?: [];
    if (($body['action'] ?? '') !== 'save') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }

    // Só jogadores do próprio elenco entram nos campos de escalação.
    $stmtE = $pdo->prepare('SELECT id FROM players WHERE team_id = ?');
    $stmtE->execute([$teamId]);
    $doElenco = array_map('intval', $stmtE->fetchAll(PDO::FETCH_COLUMN));

    $slot = tacticaSlot($body['slot'] ?? 'regular');
    $valores = ['team_id' => $teamId, 'slot' => $slot];
    foreach (TATICA_CAMPOS as $campo) {
        $v = $body[$campo] ?? null;
        if (substr($campo, -3) === '_id') {
            $v = (int)$v;
            $valores[$campo] = ($v > 0 && in_array($v, $doElenco, true)) ? $v : null;
        } elseif (in_array($campo, ['rotation_players', 'veteran_focus'], true)) {
            $valores[$campo] = ($v === null || $v === '') ? null : max(0, min(99, (int)$v));
        } else {
            $valores[$campo] = ($v === null || $v === '') ? null : mb_substr((string)$v, 0, 5000);
        }
    }

    $minutos = [];
    foreach (($body['player_minutes'] ?? []) as $pid => $m) {
        $pid = (int)$pid;
        if (!in_array($pid, $doElenco, true)) continue;
        $minutos[$pid] = max(0, min(48, (int)$m));
    }
    $valores['player_minutes'] = json_encode($minutos);

    $cols = array_keys($valores);
    $ph   = implode(',', array_fill(0, count($cols), '?'));
    $upd  = implode(',', array_map(fn($c) => "$c = VALUES($c)", array_diff($cols, ['team_id', 'slot'])));

    try {
        $sql = 'INSERT INTO team_tactics (' . implode(',', $cols) . ") VALUES ($ph) ON DUPLICATE KEY UPDATE $upd";
        $pdo->prepare($sql)->execute(array_values($valores));

        // Espelha no perfil tático do time (teams.directive_profile), que e o
        // que diretrizes.php le quando nao ha deadline aberta. Sem isso as duas
        // telas mostrariam coisas diferentes — o oposto de estar sincronizado.
        // So o slot regular vira perfil: ele e a tatica base do time.
        if ($slot === 'regular') {
            $perfil = [];
            foreach (TATICA_CAMPOS as $campo) $perfil[$campo] = $valores[$campo];
            $perfil['bench_players'] = array_values(array_filter([
                $valores['bench_1_id'], $valores['bench_2_id'], $valores['bench_3_id'],
            ]));
            $perfil['player_minutes'] = $minutos;
            $pdo->prepare('UPDATE teams SET directive_profile = ?, directive_profile_updated_at = NOW() WHERE id = ?')
                ->execute([json_encode($perfil), $teamId]);
        }
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['success' => false, 'error' => 'Erro ao salvar a tática.']);
        exit;
    }

    echo json_encode(['success' => true, 'total_minutos' => array_sum($minutos)]);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Método não permitido']);
