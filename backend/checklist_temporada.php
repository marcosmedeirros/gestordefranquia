<?php
/**
 * Checklist da temporada — o que precisa estar pronto pra virar a temporada.
 *
 * Antes disso o admin tinha que lembrar de cabeça o que faltava, e descobria
 * pela metade: o "Avançar Temporada" só barrava por causa da pontuação. Aqui
 * cada etapa vira um item verde/pendente, com a contagem quando faz sentido
 * ("28 de 32 times atualizados").
 *
 * Cada item é isolado num try/catch: consulta que falha vira 'desconhecido'
 * em vez de derrubar a tela inteira do admin.
 */

require_once __DIR__ . '/helpers.php';

/**
 * @return array lista de itens: chave, titulo, feito (bool|null), detalhe,
 *               obrigatorio (se false, é aviso e não trava o avanço)
 */
function checklistDaTemporada(PDO $pdo, string $league, array $season): array
{
    $seasonId = (int)$season['id'];
    $itens = [];

    $add = function (string $chave, string $titulo, ?bool $feito, string $detalhe = '', bool $obrigatorio = true) use (&$itens) {
        $itens[] = compact('chave', 'titulo', 'feito', 'detalhe', 'obrigatorio');
    };

    // ── Times atualizados ───────────────────────────────────────────────────
    // A contagem mora em backend/league_cap.php e é chamada daqui: a regra já
    // esteve escrita nos dois arquivos, e regra repetida diverge.
    try {
        require_once __DIR__ . '/league_cap.php';
        $st = leagueRosterUpdateStatus($pdo, $league, $seasonId);
        $totalTimes = $st['total'];
        $atualizados = $st['done'];

        $add(
            'times',
            'Times atualizados',
            $st['complete'],
            "{$atualizados} de {$totalTimes} times"
        );
    } catch (Throwable $e) {
        $add('times', 'Times atualizados', null, 'não deu pra verificar');
    }

    // ── Loteria ─────────────────────────────────────────────────────────────
    // Todas as ligas têm loteria; o resultado fica no snapshot da ordem
    // do draft, gravado quando a loteria roda.
    if (in_array($league, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) {
        try {
            // A loteria está feita quando a ordem do draft existe (draft_order).
            // O draft_order_snapshot serve de alternativa, mas sozinho não vale:
            // ele só é gravado ao FINALIZAR os playoffs, muito depois — quem
            // olhasse só pra ele via a loteria como pendente mesmo já sorteada.
            $st = $pdo->prepare("SELECT COUNT(*) FROM draft_order do_
                                 JOIN draft_sessions ds ON ds.id = do_.draft_session_id
                                 WHERE ds.season_id = ?");
            $st->execute([$seasonId]);
            $temOrdem = (int)$st->fetchColumn() > 0;

            if (!$temOrdem) {
                $st2 = $pdo->prepare("SELECT draft_order_snapshot FROM seasons WHERE id = ?");
                $st2->execute([$seasonId]);
                $temOrdem = !empty($st2->fetchColumn());
            }
            $add('loteria', 'Loteria feita', $temOrdem, $temOrdem ? 'ordem do draft definida' : 'ordem do draft ainda não sorteada');
        } catch (Throwable $e) {
            $add('loteria', 'Loteria feita', null, 'não deu pra verificar');
        }
    }

    // ── Draft ───────────────────────────────────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT status FROM draft_sessions WHERE season_id = ? ORDER BY id DESC LIMIT 1");
        $st->execute([$seasonId]);
        $status = $st->fetchColumn();
        $add(
            'draft',
            'Draft finalizado',
            $status === 'completed',
            $status === false || $status === null ? 'nenhum draft criado' : "situação: {$status}"
        );
    } catch (Throwable $e) {
        $add('draft', 'Draft finalizado', null, 'não deu pra verificar');
    }

    // ── Free Agency ─────────────────────────────────────────────────────────
    // "Feita" = teve pedido na temporada e nenhum ficou em aberto.
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) AS total,
                   SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS abertos
            FROM fa_requests WHERE season_id = ?
        ");
        $st->execute([$seasonId]);
        $fa = $st->fetch(PDO::FETCH_ASSOC) ?: ['total' => 0, 'abertos' => 0];
        $total   = (int)$fa['total'];
        $abertos = (int)$fa['abertos'];

        if ($total === 0) {
            // Temporada sem nenhum pedido é uma free agency sem pendência, não
            // uma etapa por fazer — marcar como pendente travaria o avanço de
            // uma temporada em que simplesmente ninguém pediu ninguém.
            $add('fa', 'Free Agency feita', true, 'nenhum pedido nesta temporada');
        } else {
            $add('fa', 'Free Agency feita', $abertos === 0,
                $abertos === 0 ? "{$total} pedidos, todos resolvidos" : "{$abertos} de {$total} pedidos ainda abertos");
        }
    } catch (Throwable $e) {
        $add('fa', 'Free Agency feita', null, 'não deu pra verificar');
    }

    // ── Pontuação ───────────────────────────────────────────────────────────
    // É a única que já travava o avanço antes deste checklist existir.
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM season_history WHERE season_id = ?");
        $st->execute([$seasonId]);
        $add('pontuacao', 'Pontuação registrada', (int)$st->fetchColumn() > 0,
            'sem isso o avanço fica bloqueado');
    } catch (Throwable $e) {
        $add('pontuacao', 'Pontuação registrada', null, 'não deu pra verificar');
    }

    // ── Campeão / playoffs ──────────────────────────────────────────────────
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM playoff_results WHERE season_id = ?");
        $st->execute([$seasonId]);
        $n = (int)$st->fetchColumn();
        $add('playoffs', 'Playoffs registrados', $n > 0,
            $n > 0 ? "{$n} posições registradas" : 'campeão e vice ainda não definidos');
    } catch (Throwable $e) {
        $add('playoffs', 'Playoffs registrados', null, 'não deu pra verificar');
    }

    // ── Prêmios ─────────────────────────────────────────────────────────────
    // Aviso, não trava: dá pra virar a temporada sem os prêmios lançados.
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM season_awards WHERE season_id = ?");
        $st->execute([$seasonId]);
        $n = (int)$st->fetchColumn();
        $add('premios', 'Prêmios lançados', $n > 0,
            $n > 0 ? "{$n} prêmios" : 'MVP, DPOY e companhia ainda em branco', false);
    } catch (Throwable $e) {
        $add('premios', 'Prêmios lançados', null, 'não deu pra verificar', false);
    }

    // ── Trocas pendentes ────────────────────────────────────────────────────
    // Aviso: proposta que fica pendurada na virada não tem mais como ser
    // aceita direito, então vale resolver antes.
    try {
        $st = $pdo->prepare("
            SELECT COUNT(*) FROM trades t
            JOIN teams tt ON tt.id = t.to_team_id
            WHERE tt.league = ? AND t.status = 'pending'
        ");
        $st->execute([$league]);
        $n = (int)$st->fetchColumn();
        $add('trocas', 'Sem trocas pendentes', $n === 0,
            $n === 0 ? 'nenhuma proposta em aberto' : "{$n} propostas ainda em aberto", false);
    } catch (Throwable $e) {
        $add('trocas', 'Sem trocas pendentes', null, 'não deu pra verificar', false);
    }

    return $itens;
}

/** true quando todos os itens obrigatórios estão verdes. */
function checklistCompleto(array $itens): bool
{
    foreach ($itens as $i) {
        if (!empty($i['obrigatorio']) && $i['feito'] !== true) return false;
    }
    return true;
}
