<?php
/**
 * O AUTO-PICK DO MOCK DRAFT, num lugar só.
 *
 * Esta regra vivia escrita duas vezes — no cron/draft-autopick.php e no
 * check_autopick de api/draft-mock.php — e as duas cópias já tinham divergido
 * no prazo de espera. Pior que a divergência: as duas erravam a mesma coisa, e
 * é o defeito que este arquivo existe pra consertar.
 *
 * O QUE ESTAVA ERRADO: as duas checavam o RELÓGIO antes de olhar a fila. Um
 * time com mock ativo e lista pronta ficava 5 ou 30 minutos parado esperando
 * um prazo que só faz sentido pra quem NÃO deixou lista. Quem preencheu a fila
 * já disse o que queria — não há o que esperar.
 *
 * A REGRA AGORA:
 *   1. Fila do time, com mock ativo → escolhe NA HORA, sem prazo nenhum.
 *   2. Sem fila (ou mock desligado) → espera o prazo e aí pega o melhor
 *      disponível, e só depois que o relógio da 1ª rodada foi armado pelo
 *      admin. É o caso de quem sumiu, e o prazo é a chance dele aparecer.
 *
 * E encadeia: escolhida uma pick, a próxima é avaliada na mesma passada. Numa
 * rodada em que todo mundo deixou lista, o draft inteiro resolve de uma vez —
 * antes levava um minuto por pick, porque o cron tratava uma e ia embora.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/push.php';

/** Teto de picks encadeadas numa passada. Trava de segurança, não regra. */
const DRAFT_AUTOPICK_MAX_CASCATA = 120;

/** Prazo de quem não deixou lista, depois do relógio armado. */
const DRAFT_AUTOPICK_PRAZO_CURTO = 300;    // 5 min
/** Prazo antes de o admin armar o relógio da 1ª rodada. */
const DRAFT_AUTOPICK_PRAZO_LONGO = 1800;   // 30 min

/**
 * Garante as colunas de relógio. Idempotente e silenciosa.
 */
function draftAutopickColunas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    foreach (['current_pick_started_at', 'round1_clock_start_at'] as $col) {
        try { $pdo->exec("ALTER TABLE draft_sessions ADD COLUMN IF NOT EXISTS {$col} DATETIME NULL"); }
        catch (Throwable $e) { /* já existe */ }
    }
}

/**
 * Quem este time escolheria agora — ou null se ainda não é hora.
 *
 * @return array{player_id:int,name:string,position:?string,age:int,ovr:int,motivo:string}|null
 */
function draftAutopickJogador(PDO $pdo, array $session, int $teamId): ?array
{
    $sessionId = (int)$session['id'];

    /* 1. A FILA DO TIME. Vem primeiro e sem olhar o relógio: o GM já disse o
          que queria, e fazer ele esperar cinco minutos por uma decisão que já
          está tomada é o bug que esta função conserta. */
    $st = $pdo->prepare('SELECT is_active FROM draft_mock_settings
                          WHERE team_id = ? AND draft_session_id = ?');
    $st->execute([$teamId, $sessionId]);
    $mockAtivo = (bool)($st->fetchColumn() ?: false);

    if ($mockAtivo) {
        $q = $pdo->prepare('
            SELECT mq.player_id, dp.name, dp.position, dp.age, dp.ovr
              FROM draft_mock_queue mq
              JOIN draft_pool dp ON dp.id = mq.player_id
             WHERE mq.team_id = ? AND mq.draft_session_id = ?
               AND dp.draft_status = "available"
             ORDER BY mq.priority ASC
             LIMIT 1');
        $q->execute([$teamId, $sessionId]);
        $escolha = $q->fetch(PDO::FETCH_ASSOC);
        if ($escolha) {
            $escolha['motivo'] = 'fila';
            return $escolha;
        }
    }

    /* 2. SEM LISTA: aí sim o prazo, e só depois que o admin armou o relógio da
          1ª rodada. Antes disso ninguém é escolhido por fora — o draft pode
          estar parado de propósito, esperando a hora marcada. */
    if (empty($session['current_pick_started_at'])) return null;

    $agora        = time();
    $inicioPick   = strtotime((string)$session['current_pick_started_at']);
    $inicioRelogio = !empty($session['round1_clock_start_at'])
        ? strtotime((string)$session['round1_clock_start_at']) : null;
    $relogioArmado = $inicioRelogio !== null && $agora >= $inicioRelogio;

    if (!$relogioArmado) return null;

    // O maior entre os dois: uma pick que já estava aberta há horas ganha
    // 5 minutos frescos a partir da hora marcada, em vez de estourar na hora.
    $referencia = max($inicioPick, $inicioRelogio);
    if (($agora - $referencia) < DRAFT_AUTOPICK_PRAZO_CURTO) return null;

    $b = $pdo->prepare('
        SELECT id AS player_id, name, position, age, ovr
          FROM draft_pool
         WHERE season_id = ? AND draft_status = "available"
         ORDER BY COALESCE(pick_hint, 999999) ASC, ovr DESC, name ASC
         LIMIT 1');
    $b->execute([(int)$session['season_id']]);
    $melhor = $b->fetch(PDO::FETCH_ASSOC);
    if (!$melhor) return null;

    $melhor['motivo'] = 'prazo';
    return $melhor;
}

/**
 * Grava a escolha e move a sessão pra próxima pick.
 *
 * @return array{ok:bool,proximo_time:?int,proxima:?array}
 */
function draftAutopickGravar(PDO $pdo, array $session, array $pick, array $jogador): array
{
    $sessionId = (int)$session['id'];
    $teamId    = (int)$pick['team_id'];
    $playerId  = (int)$jogador['player_id'];

    $pdo->beginTransaction();
    try {
        // A condição `picked_player_id IS NULL` é o que impede a escolha dupla
        // quando o cron e a tela do GM caem na mesma pick no mesmo segundo.
        $up = $pdo->prepare('UPDATE draft_order
                                SET picked_player_id = ?, picked_at = NOW(), team_id = ?
                              WHERE id = ? AND picked_player_id IS NULL');
        $up->execute([$playerId, $teamId, (int)$pick['id']]);
        if ($up->rowCount() === 0) {
            $pdo->rollBack();
            return ['ok' => false, 'proximo_time' => null, 'proxima' => null];
        }

        $st = $pdo->prepare('SELECT COUNT(*) FROM draft_order WHERE draft_session_id = ? AND round = ?');
        $st->execute([$sessionId, (int)$pick['round']]);
        $tamanhoRodada = (int)$st->fetchColumn();
        $numeroPick = (((int)$pick['round'] - 1) * $tamanhoRodada) + (int)$pick['pick_position'];

        $pdo->prepare('UPDATE draft_pool
                          SET draft_status = "drafted", drafted_by_team_id = ?, draft_order = ?
                        WHERE id = ?')->execute([$teamId, $numeroPick, $playerId]);

        // Só cria o jogador no elenco se ele ainda não estiver lá: o mesmo
        // nome entrando duas vezes é elenco com jogador fantasma.
        $chk = $pdo->prepare('SELECT id FROM players WHERE team_id = ? AND name = ? LIMIT 1');
        $chk->execute([$teamId, $jogador['name']]);
        if (!$chk->fetchColumn()) {
            $pdo->prepare('INSERT INTO players
                (team_id, drafted_by_team_id, name, position, age, ovr, role, available_for_trade)
                VALUES (?, ?, ?, ?, ?, ?, "Banco", 0)')
                ->execute([$teamId, $teamId, $jogador['name'], $jogador['position'],
                           (int)$jogador['age'], (int)$jogador['ovr']]);
        }

        $nx = $pdo->prepare('SELECT round, pick_position, team_id FROM draft_order
                              WHERE draft_session_id = ? AND picked_player_id IS NULL
                           ORDER BY round ASC, pick_position ASC LIMIT 1');
        $nx->execute([$sessionId]);
        $proxima = $nx->fetch(PDO::FETCH_ASSOC) ?: null;

        if ($proxima) {
            $pdo->prepare('UPDATE draft_sessions
                              SET current_round = ?, current_pick = ?, current_pick_started_at = NOW()
                            WHERE id = ?')
                ->execute([(int)$proxima['round'], (int)$proxima['pick_position'], $sessionId]);
        } else {
            $pdo->prepare('UPDATE draft_sessions SET status = "completed", completed_at = NOW() WHERE id = ?')
                ->execute([$sessionId]);
        }

        $pdo->commit();
        return [
            'ok' => true,
            'proximo_time' => $proxima ? (int)$proxima['team_id'] : null,
            'proxima' => $proxima,
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[autopick] gravar sessao ' . $sessionId . ': ' . $e->getMessage());
        return ['ok' => false, 'proximo_time' => null, 'proxima' => null];
    }
}

/** Avisa o próximo da vez. Falha aqui não pode travar o draft. */
function draftAutopickAvisar(PDO $pdo, int $teamId, int $round, int $pick): void
{
    try {
        $st = $pdo->prepare('SELECT u.id FROM teams t JOIN users u ON u.id = t.user_id WHERE t.id = ? LIMIT 1');
        $st->execute([$teamId]);
        $userId = (int)($st->fetchColumn() ?: 0);
        if (!$userId) return;

        sendPushToUser($pdo, $userId, [
            'title'      => '🏀 É a sua vez no Draft!',
            'body'       => "Rodada {$round} · Pick #{$pick} — sua vez de escolher.",
            'url'        => '/drafts.php',
            'primaryKey' => 'draft_pick_' . $teamId . '_' . $round . '_' . $pick,
        ], 'draft');
    } catch (Throwable $e) {
        error_log('[autopick] push: ' . $e->getMessage());
    }
}

/**
 * Roda o auto-pick de uma sessão até travar, e devolve o que foi escolhido.
 *
 * Trava quando chega numa pick cujo time não tem lista e ainda está no prazo —
 * que é o ponto em que a decisão volta a ser de gente.
 *
 * @return list<array{round:int,pick:int,team_id:int,player:string,motivo:string}>
 */
function draftAutopickSessao(PDO $pdo, int $sessionId): array
{
    draftAutopickColunas($pdo);
    $feitas = [];

    for ($i = 0; $i < DRAFT_AUTOPICK_MAX_CASCATA; $i++) {
        $st = $pdo->prepare('SELECT * FROM draft_sessions WHERE id = ? AND status = "in_progress"');
        $st->execute([$sessionId]);
        $session = $st->fetch(PDO::FETCH_ASSOC);
        if (!$session) break;

        $sp = $pdo->prepare('SELECT * FROM draft_order
                              WHERE draft_session_id = ? AND round = ? AND pick_position = ?
                                AND picked_player_id IS NULL');
        $sp->execute([$sessionId, (int)$session['current_round'], (int)$session['current_pick']]);
        $pick = $sp->fetch(PDO::FETCH_ASSOC);
        if (!$pick) break;

        /* A vez precisa ter um começo registrado pro prazo poder correr. Sem
           isso, marca agora e segue — a fila do time não depende disso, então
           quem tem lista ainda é atendido nesta mesma passada. */
        if (empty($session['current_pick_started_at'])) {
            $pdo->prepare('UPDATE draft_sessions SET current_pick_started_at = NOW() WHERE id = ?')
                ->execute([$sessionId]);
            $session['current_pick_started_at'] = date('Y-m-d H:i:s');
        }

        $jogador = draftAutopickJogador($pdo, $session, (int)$pick['team_id']);
        if (!$jogador) break;

        $r = draftAutopickGravar($pdo, $session, $pick, $jogador);
        if (!$r['ok']) break;

        $feitas[] = [
            'round'   => (int)$pick['round'],
            'pick'    => (int)$pick['pick_position'],
            'team_id' => (int)$pick['team_id'],
            'player'  => (string)$jogador['name'],
            'motivo'  => (string)$jogador['motivo'],
        ];

        /* A escolha automática também vira notícia no grupo. Pra quem lê, ela
           é uma pick como outra qualquer — quem escolheu foi o time, mesmo que
           pela lista que ele deixou. A função sai sozinha fora da 1ª rodada e
           depois da 10ª escolha. */
        try {
            require_once __DIR__ . '/draft_bot.php';
            draftBotAnunciarEscolha($pdo, $sessionId, (int)$pick['round'], (int)$pick['pick_position']);
        } catch (Throwable $e) {
            error_log('[autopick] anunciar: ' . $e->getMessage());
        }

        if ($r['proxima'] && $r['proximo_time']) {
            draftAutopickAvisar($pdo, (int)$r['proximo_time'],
                (int)$r['proxima']['round'], (int)$r['proxima']['pick_position']);
        }
    }

    return $feitas;
}
