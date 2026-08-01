<?php
/**
 * Pendências do GM — o que precisa dele agora.
 *
 * O app espalha prazos por muitas telas: proposta de troca esperando resposta,
 * leilão fechando em minutos, sua vez no draft, waiver de 12h, oferta de free
 * agency parada. Nada disso aparecia junto em lugar nenhum — quem não abria a
 * tela certa na hora certa simplesmente perdia.
 *
 * Aqui as consultas que já existem espalhadas pelas APIs são reunidas num
 * formato único. Cada bloco é isolado num try/catch: tabela que ainda não
 * existe ou consulta que falha derruba só aquele item, nunca o dashboard.
 *
 * Cada pendência é:
 *   urgencia  'alta' (tem relógio correndo) | 'media' (bloqueia algo) | 'baixa'
 *   icone     classe do bootstrap-icons
 *   titulo    a frase curta que a pessoa lê
 *   detalhe   contexto (opcional)
 *   url       pra onde ir resolver
 *   prazo     texto do tempo restante (opcional)
 */

require_once __DIR__ . '/helpers.php';

/** Transforma segundos restantes em algo legível ("faltam 3h", "12 min"). */
function pendPrazoTexto(int $segundos): string
{
    if ($segundos <= 0) return 'encerrando';
    if ($segundos < 3600) return 'faltam ' . max(1, (int)round($segundos / 60)) . ' min';
    if ($segundos < 86400) return 'faltam ' . (int)floor($segundos / 3600) . 'h';
    $dias = (int)floor($segundos / 86400);
    return 'faltam ' . $dias . ($dias === 1 ? ' dia' : ' dias');
}

/**
 * Devolve a lista de pendências do GM, já ordenada por urgência.
 *
 * @param array|null $team linha de teams (null = GM sem time, sem pendências)
 */
function pendenciasDoGm(PDO $pdo, array $user, ?array $team): array
{
    if (!$team) return [];

    $teamId = (int)$team['id'];
    $userId = (int)$user['id'];
    $league = (string)($team['league'] ?? '');
    $itens  = [];

    // ── Propostas de troca esperando você ───────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM trades WHERE to_team_id = ? AND status = 'pending'");
        $st->execute([$teamId]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $itens[] = [
                'urgencia' => 'media',
                'icone'    => 'bi-arrow-left-right',
                'titulo'   => $n === 1 ? '1 proposta de troca esperando você' : "{$n} propostas de troca esperando você",
                'detalhe'  => 'Aceite, recuse ou faça uma contraproposta.',
                'url'      => '/trades.php',
            ];
        }
    } catch (Throwable $e) {}

    // ── Sua vez no draft da temporada ───────────────────────────────────────
    // Esta é a mais crítica: tem relógio correndo e, até agora, o dashboard
    // não avisava nada (só o Draft Inicial tinha banner).
    try {
        // ds.* de propósito: round1_clock_start_at é adicionada por ALTER em
        // api/draft.php, então não está no schema base — pedir a coluna pelo
        // nome faria a consulta falhar onde ela ainda não existe.
        $st = $pdo->prepare("
            SELECT ds.*
            FROM draft_sessions ds
            JOIN seasons s ON s.id = ds.season_id
            WHERE s.league = ? AND ds.status = 'in_progress'
            ORDER BY ds.id DESC LIMIT 1
        ");
        $st->execute([$league]);
        $sessao = $st->fetch(PDO::FETCH_ASSOC);

        if ($sessao) {
            $stPick = $pdo->prepare("
                SELECT team_id FROM draft_order
                WHERE draft_session_id = ? AND round = ? AND pick_position = ?
                  AND picked_player_id IS NULL LIMIT 1
            ");
            $stPick->execute([$sessao['id'], $sessao['current_round'], $sessao['current_pick']]);
            $daVez = (int)($stPick->fetchColumn() ?: 0);

            if ($daVez === $teamId) {
                // Rodada 1 tem relógio curto (5 min); as demais, 30 min.
                $inicio = strtotime((string)$sessao['current_pick_started_at']);
                if ((int)$sessao['current_round'] === 1 && !empty($sessao['round1_clock_start_at'])) {
                    $inicio = max($inicio, strtotime((string)$sessao['round1_clock_start_at']));
                    $limite = $inicio + 300;
                } else {
                    $limite = $inicio + 1800;
                }
                $itens[] = [
                    'urgencia' => 'alta',
                    'icone'    => 'bi-clipboard-check-fill',
                    'titulo'   => 'É a sua vez no Draft',
                    'detalhe'  => 'Rodada ' . (int)$sessao['current_round'] . ' · pick ' . (int)$sessao['current_pick'],
                    'url'      => '/drafts.php',
                    'prazo'    => $inicio > 0 ? pendPrazoTexto($limite - time()) : null,
                ];
            }
        }
    } catch (Throwable $e) {}

    // ── Sua vez no Draft Inicial ────────────────────────────────────────────
    try {
        // A vez é a primeira pick ainda sem jogador — mesmo critério que o
        // dashboard já usa pra montar o banner do Draft Inicial.
        $st = $pdo->prepare("
            SELECT s.access_token, o.team_id
            FROM initdraft_sessions s
            JOIN initdraft_order o ON o.initdraft_session_id = s.id AND o.picked_player_id IS NULL
            WHERE s.league = ? AND s.status = 'in_progress'
            ORDER BY o.round ASC, o.pick_position ASC LIMIT 1
        ");
        $st->execute([$league]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['team_id'] === $teamId) {
            $itens[] = [
                'urgencia' => 'alta',
                'icone'    => 'bi-people-fill',
                'titulo'   => 'É a sua vez no Draft Inicial',
                'detalhe'  => 'A liga está esperando a sua escolha.',
                'url'      => '/initdraftselecao.php?token=' . urlencode((string)$row['access_token']),
            ];
        }
    } catch (Throwable $e) {}

    // ── Sua vez no Draft de Lendas ──────────────────────────────────────────
    // Sem isto a pessoa não descobre: esse draft não tem item no menu lateral.
    try {
        $fim = $pdo->query("SELECT finalizado_em FROM legends_draft_status WHERE id = 1")->fetchColumn();
        if ($fim === false || $fim === null) {
            $st = $pdo->query("
                SELECT user_id, pick_number FROM legends_draft_picks
                WHERE player_name IS NULL AND skipped = 0
                ORDER BY pick_number ASC LIMIT 1
            ");
            $vez = $st->fetch(PDO::FETCH_ASSOC);
            if ($vez && (int)$vez['user_id'] === $userId) {
                $itens[] = [
                    'urgencia' => 'alta',
                    'icone'    => 'bi-star-fill',
                    'titulo'   => 'É a sua vez no Draft de Lendas',
                    'detalhe'  => 'Escolha ' . (int)$vez['pick_number'] . ' — a liga está esperando.',
                    'url'      => '/legends-draft.php',
                ];
            }
        }
    } catch (Throwable $e) {}

    // ── Leilão: propostas na sua vitrine e leilões abertos pra dar lance ─────
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM leilao_propostas lp
            JOIN leilao_jogadores l ON l.id = lp.leilao_id
            WHERE l.team_id = ? AND l.status = 'ativo' AND lp.status = 'pendente'
        ");
        $st->execute([$teamId]);
        $n = (int)$st->fetchColumn();
        if ($n > 0) {
            $itens[] = [
                'urgencia' => 'alta',
                'icone'    => 'bi-hammer',
                'titulo'   => $n === 1 ? '1 proposta no seu leilão' : "{$n} propostas no seu leilão",
                'detalhe'  => 'O leilão fecha em 20 minutos depois de aberto.',
                'url'      => '/leilao.php',
            ];
        }
    } catch (Throwable $e) {}

    try {
        $st = $pdo->prepare("
            SELECT MIN(TIMESTAMPDIFF(SECOND, NOW(), l.data_fim)) AS falta, COUNT(*) AS n
            FROM leilao_jogadores l
            WHERE l.status = 'ativo' AND l.league_id = (SELECT id FROM leagues WHERE name = ? LIMIT 1) AND l.team_id <> ?
              AND l.data_fim IS NOT NULL AND l.data_fim > NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM leilao_propostas p
                  WHERE p.leilao_id = l.id AND p.team_id = ?
              )
        ");
        $st->execute([$league, $teamId, $teamId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['n'] > 0) {
            $n = (int)$row['n'];
            $itens[] = [
                'urgencia' => 'alta',
                'icone'    => 'bi-gavel',
                'titulo'   => $n === 1 ? '1 leilão aberto pra dar lance' : "{$n} leilões abertos pra dar lance",
                'url'      => '/leilao.php',
                'prazo'    => pendPrazoTexto((int)$row['falta']),
            ];
        }
    } catch (Throwable $e) {}

    // ── Waivers: janela de 12h pra reivindicar ──────────────────────────────
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS n, MIN(TIMESTAMPDIFF(SECOND, NOW(), wr.expires_at)) AS falta
            FROM waiver_retention wr
            WHERE wr.status = 'open' AND wr.league = ? AND wr.expires_at > NOW()
              AND wr.team_id <> ?
              AND NOT EXISTS (
                  SELECT 1 FROM waiver_claims wc
                  WHERE wc.retention_id = wr.id AND wc.team_id = ?
              )
        ");
        $st->execute([$league, $teamId, $teamId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['n'] > 0) {
            $n = (int)$row['n'];
            $itens[] = [
                'urgencia' => 'alta',
                'icone'    => 'bi-person-dash-fill',
                'titulo'   => $n === 1 ? '1 jogador nas dispensas' : "{$n} jogadores nas dispensas",
                'detalhe'  => 'Dá pra reivindicar enquanto a janela estiver aberta.',
                'url'      => '/dispensas.php',
                'prazo'    => pendPrazoTexto((int)$row['falta']),
            ];
        }
    } catch (Throwable $e) {}

    // ── Apostas abertas onde você ainda não palpitou ────────────────────────
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS n, MIN(TIMESTAMPDIFF(SECOND, NOW(), e.data_limite)) AS falta
            FROM eventos e
            WHERE e.status = 'aberta' AND e.data_limite > NOW()
              AND NOT EXISTS (
                  SELECT 1 FROM palpites p
                  JOIN opcoes o ON o.id = p.opcao_id
                  WHERE o.evento_id = e.id AND p.id_usuario = ?
              )
        ");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && (int)$row['n'] > 0) {
            $n = (int)$row['n'];
            $itens[] = [
                'urgencia' => 'baixa',
                'icone'    => 'bi-graph-up-arrow',
                'titulo'   => $n === 1 ? '1 aposta aberta sem seu palpite' : "{$n} apostas abertas sem seu palpite",
                'url'      => '/games.php?aba=apostas',
                'prazo'    => pendPrazoTexto((int)$row['falta']),
            ];
        }
    } catch (Throwable $e) {}

    // ── Elenco fora do tamanho permitido ────────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM players WHERE team_id = ?");
        $st->execute([$teamId]);
        $n = (int)$st->fetchColumn();
        if ($n > 0 && ($n < 13 || $n > 15)) {
            $itens[] = [
                'urgencia' => 'media',
                'icone'    => 'bi-people',
                'titulo'   => $n < 13
                    ? 'Elenco abaixo do mínimo (' . $n . ' de 13)'
                    : 'Elenco acima do máximo (' . $n . ' de 15)',
                'detalhe'  => $n < 13 ? 'Contrate pra completar.' : 'Dispense até voltar ao limite.',
                'url'      => '/my-roster.php',
            ];
        }
    } catch (Throwable $e) {}

    // ── Sem tática ativa ────────────────────────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM team_tactics WHERE team_id = ? AND is_active = 1");
        $st->execute([$teamId]);
        if ((int)$st->fetchColumn() === 0) {
            $itens[] = [
                'urgencia' => 'media',
                'icone'    => 'bi-clipboard2-pulse',
                'titulo'   => 'Você não tem tática ativa',
                'detalhe'  => 'Escolha uma antes da janela de edição fechar.',
                'url'      => '/tatica.php',
            ];
        }
    } catch (Throwable $e) {}

    // Alta primeiro; dentro do mesmo nível, mantém a ordem em que foi montado.
    $peso = ['alta' => 0, 'media' => 1, 'baixa' => 2];
    usort($itens, fn($a, $b) => ($peso[$a['urgencia']] ?? 3) <=> ($peso[$b['urgencia']] ?? 3));

    return $itens;
}
