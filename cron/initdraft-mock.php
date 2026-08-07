<?php
/**
 * Cron do autopick por MOCK do Draft Inicial.
 *
 * O problema que ele resolve: o autopick por mock existia só no
 * check_autopick (api/initdraft.php), e quem dispara aquilo é o poll do
 * navegador. Ou seja, só funcionava com alguém de página aberta — justamente
 * o contrário do cenário pra que o mock existe, que é o GM ausente.
 *
 * O cron/initdraft-daily.php também escolhe sozinho, mas só em sessão com
 * agenda diária ligada e só depois do prazo estourar. Numa sessão sem agenda
 * não há prazo nenhum, e o draft simplesmente parava até alguém abrir a tela.
 *
 * Aqui o critério é o mesmo do check_autopick: é a vez do time E o mock dele
 * está ativo E ainda há alguém disponível na fila → escolhe e passa a vez.
 * Sem mock ativo, não faz nada: quem não delegou continua escolhendo na mão.
 *
 * Encadeia: depois de uma pick, o próximo time pode ter mock também, então
 * segue até esbarrar em alguém sem mock. Sem isso, uma sequência de times com
 * mock andaria uma pick por minuto.
 *
 * Rodar a cada minuto:
 *   * * * * * /usr/bin/php /caminho/cron/initdraft-mock.php >> /dev/null 2>&1
 */

if (PHP_SAPI !== "cli") {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/initdraft_pick.php';

date_default_timezone_set('America/Sao_Paulo');

/** Teto de picks por execução: trava de segurança contra laço infinito. */
const INITDRAFT_MOCK_MAX_PICKS = 200;

$pdo = db();
$agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

/**
 * A sessão está com a janela do dia aberta?
 *
 * Sem agenda diária, está sempre aberta. Com agenda, respeita a mesma regra do
 * initdraft-daily: só a partir do horário do relógio, e nada depois que o round
 * do dia terminou — senão este cron atropelaria o "1 round por dia".
 */
function initdraftJanelaAberta(PDO $pdo, array $sessao, DateTimeImmutable $agora): bool
{
    if (empty($sessao['daily_schedule_enabled'])) return true;

    $inicio = $sessao['daily_schedule_start_date'] ?? null;
    if (!$inicio) return false;
    $d = DateTimeImmutable::createFromFormat('Y-m-d', $inicio, $agora->getTimezone());
    if (!$d) return false;
    if ($agora->format('Y-m-d') < $d->format('Y-m-d')) return false;

    $roundDoDia = (int)$d->diff($agora)->format('%a') + 1;
    if ($roundDoDia > (int)$sessao['total_rounds']) return false;

    // Round do dia já terminou: para até amanhã.
    $st = $pdo->prepare('SELECT COUNT(*) FROM initdraft_order
                         WHERE initdraft_session_id = ? AND round = ? AND picked_player_id IS NULL');
    $st->execute([$sessao['id'], $roundDoDia]);
    if ((int)$st->fetchColumn() === 0) return false;

    $relogio = new DateTimeImmutable(
        $agora->format('Y-m-d') . ' ' . ($sessao['daily_clock_start_time'] ?? '19:30:00'),
        $agora->getTimezone()
    );
    return $agora >= $relogio;
}

/** Primeiro da fila do mock que ainda esteja disponível — igual ao check_autopick. */
function initdraftMockEscolha(PDO $pdo, int $sessaoId, int $timeId): ?int
{
    try {
        $st = $pdo->prepare('SELECT is_active FROM initdraft_mock_settings
                             WHERE team_id = ? AND initdraft_session_id = ?');
        $st->execute([$timeId, $sessaoId]);
        if (empty($st->fetchColumn())) return null;

        $st = $pdo->prepare("
            SELECT mq.player_id FROM initdraft_mock_queue mq
            JOIN initdraft_pool ip ON ip.id = mq.player_id
            WHERE mq.team_id = ? AND mq.initdraft_session_id = ? AND ip.draft_status = 'available'
            ORDER BY mq.priority ASC LIMIT 1
        ");
        $st->execute([$timeId, $sessaoId]);
        $id = $st->fetchColumn();
        return $id ? (int)$id : null;
    } catch (Throwable $e) {
        // Base antiga sem as tabelas de mock não pode derrubar o cron.
        return null;
    }
}

$sessoes = $pdo->query("SELECT * FROM initdraft_sessions WHERE status = 'in_progress'")
               ->fetchAll(PDO::FETCH_ASSOC);

$totalPicks = 0;
$log = [];

foreach ($sessoes as $sessao) {
    if (!initdraftJanelaAberta($pdo, $sessao, $agora)) {
        $log[] = "sessao {$sessao['id']}: janela fechada";
        continue;
    }

    $feitas = 0;
    while ($totalPicks < INITDRAFT_MOCK_MAX_PICKS) {
        // Relê a sessão a cada volta: performInitDraftPick avança current_round
        // e current_pick, e a próxima iteração precisa do estado novo.
        $st = $pdo->prepare("SELECT * FROM initdraft_sessions WHERE id = ? AND status = 'in_progress'");
        $st->execute([$sessao['id']]);
        $atual = $st->fetch(PDO::FETCH_ASSOC);
        if (!$atual) break;   // acabou (ou foi concluída pela última pick)

        $st = $pdo->prepare('SELECT * FROM initdraft_order
                             WHERE initdraft_session_id = ? AND picked_player_id IS NULL
                             ORDER BY round ASC, pick_position ASC LIMIT 1');
        $st->execute([$atual['id']]);
        $pick = $st->fetch(PDO::FETCH_ASSOC);
        if (!$pick) break;

        $playerId = initdraftMockEscolha($pdo, (int)$atual['id'], (int)$pick['team_id']);
        if (!$playerId) break;   // time da vez não delegou — para aqui

        try {
            performInitDraftPick($pdo, $atual, $playerId);
        } catch (Throwable $e) {
            // Corrida com o poll do navegador ou com o outro cron: a pick já foi
            // feita. As travas atômicas de performInitDraftPick garantem que
            // ninguém escolheu duas vezes; aqui só registramos e saímos.
            $log[] = "sessao {$atual['id']}: " . $e->getMessage();
            break;
        }

        // Prazo da pick recém-encerrada não vale mais pra próxima.
        $pdo->prepare('UPDATE initdraft_order SET deadline_at = NULL WHERE id = ?')
            ->execute([$pick['id']]);

        $feitas++;
        $totalPicks++;
    }

    if ($feitas) $log[] = "sessao {$sessao['id']}: {$feitas} pick(s) por mock";
}

echo 'OK ' . $agora->format('c')
   . ' sessoes=' . count($sessoes)
   . ' picks=' . $totalPicks
   . ($log ? ' | ' . implode(' | ', $log) : '')
   . "\n";
