<?php
/**
 * API do Waiver (ELITE). GET lista os jogadores no waiver + reivindicações;
 * POST claim/unclaim (times reivindicam) e resolve (admin). Resolve os vencidos
 * preguiçosamente a cada acesso, como rede de segurança do agendador.
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/waivers.php';
require_once __DIR__ . '/../backend/salary_cap.php'; // capCabeNoTime()
header('Content-Type: application/json');

requireAuth();
$user = getUserSession();
$pdo  = db();

// Tudo abaixo fica protegido por um try/catch geral: qualquer erro inesperado
// (banco, coluna faltando, etc.) deve virar uma resposta JSON de erro — nunca
// um fatal cru, que quebraria o JSON.parse() no front e faria a lista de
// dispensados simplesmente não carregar.
try {
    $stmtT = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
    $stmtT->execute([(int)$user['id']]);
    $team = $stmtT->fetch(PDO::FETCH_ASSOC) ?: null;
    $myTeamId = $team ? (int)$team['id'] : 0;
    $myLeague = $team ? strtoupper($team['league']) : ($user['league'] ?? 'ELITE');
    try { $isAdmin = hasAdminAccess($pdo, (int)$user['id']); } catch (Throwable $e) { $isAdmin = false; }

    ensureWaiverTables($pdo);
    // Rede de segurança: resolve vencidos ao abrir (o agendador é o principal).
    // O resultado é guardado porque a ação 'resolve' do admin roda depois daqui
    // — sem isso ela sempre respondia "0 resolvidos", mesmo tendo acabado de
    // resolver tudo neste mesmo request.
    $resolvidosNaEntrada = resolveExpiredWaivers($pdo);

    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $stmt = $pdo->prepare("
            SELECT wr.id, wr.name, wr.age, wr.position, wr.secondary_position, wr.ovr, wr.team_id,
                   wr.expires_at, TIMESTAMPDIFF(SECOND, NOW(), wr.expires_at) AS seconds_left,
                   CONCAT(t.city,' ',t.name) AS waived_by_name, t.photo_url AS waived_by_photo,
                   /* Nem a CONTAGEM de lances sai: saber que já tem gente
                      disputando (ou que não tem ninguém) muda o valor que o
                      time escolhe, e o lance é cego. */
                   (SELECT COUNT(*) FROM waiver_claims wc WHERE wc.retention_id = wr.id AND wc.team_id = ?) AS mine
            FROM waiver_retention wr
            JOIN teams t ON t.id = wr.team_id
            WHERE wr.status = 'open' AND wr.league = 'ELITE'
            ORDER BY wr.expires_at ASC");
        $stmt->execute([$myTeamId]);
        $open = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Lance do meu time = espaço no cap agora; e o maior lance / meu lance por waiver.
        $myCapSpace = null;
        if ($myLeague === 'ELITE' && $myTeamId > 0) {
            try { $myCapSpace = (int)getTeamCapSummary($pdo, $myTeamId)['space']; } catch (Throwable $e) {}
        }
        // Quanto cada dispensado custaria e se cabe — a tela desabilita o
        // botão de quem não cabe em vez de deixar o time descobrir na hora
        // do erro, ou pior, ganhar o lance e estourar o cap.
        /*
         * O LANCE É CEGO.
         *
         * A resposta não diz quem está ganhando, com quanto, nem quantos
         * lances existem — nada disso sai do servidor, e não só some da tela:
         * qualquer um abriria o DevTools e leria o JSON.
         *
         * A regra da liga é essa: cada time decide o valor sem saber o que os
         * outros fizeram. Entregar o líder transformaria a janela de 12h numa
         * corrida de dar o último lance no último minuto, e quem estivesse
         * online na hora certa ganharia sempre.
         *
         * O próprio lance continua vindo: é o que a pessoa precisa ver pra
         * saber que apostou e pra poder mudar.
         */
        $mbStmt = $pdo->prepare("SELECT bid_space FROM waiver_claims WHERE retention_id = ? AND team_id = ?");
        foreach ($open as &$w) {
            $rid = (int)$w['id'];
            $fit = $myTeamId > 0 ? capCabeNoTime($pdo, $myTeamId, (int)$w['ovr']) : null;
            $w['cap_custo'] = $fit['custo']   ?? null;
            $w['cap_cabe']  = $fit['cabe']    ?? true;
            $w['cap_unidade'] = $fit['unidade'] ?? 'M';
            try {
                $mbStmt->execute([$rid, $myTeamId]);
                $mv = $mbStmt->fetchColumn();
                $w['my_bid'] = ($mv !== null && $mv !== false) ? (int)$mv : null;
            } catch (Throwable $e) {
                // Não deixa um erro pontual (ex.: coluna bid_space faltando numa base
                // antiga) derrubar a listagem inteira — só esse card fica sem lance.
                error_log('waivers.php bid lookup #' . $rid . ': ' . $e->getMessage());
                $w['my_bid'] = null;
            }
        }
        unset($w);

        // Resolvidos recentes (para mostrar o desfecho)
        $recent = $pdo->query("
            SELECT wr.name, wr.ovr, wr.status, wr.resolved_at,
                   CONCAT(t.city,' ',t.name) AS from_name,
                   (SELECT CONCAT(c.city,' ',c.name) FROM teams c WHERE c.id = wr.claimed_by_team_id) AS to_name
            FROM waiver_retention wr JOIN teams t ON t.id = wr.team_id
            WHERE wr.status IN ('claimed','cleared') AND wr.league = 'ELITE'
            ORDER BY wr.resolved_at DESC LIMIT 12")->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true, 'league' => 'ELITE',
            'my_team_id' => $myTeamId, 'my_league' => $myLeague, 'is_admin' => $isAdmin,
            'can_claim' => ($myLeague === 'ELITE' && $myTeamId > 0),
            'my_cap_space' => $myCapSpace,
            'waiver_hours' => WAIVER_HOURS,
            'open' => $open, 'recent' => $recent,
        ]);
        exit;
    }

    if ($method === 'POST') {
        $data = json_decode(file_get_contents('php://input'), true) ?: [];
        $action = $data['action'] ?? '';

        if ($action === 'resolve') {
            if (!$isAdmin) { http_response_code(403); echo json_encode(['success' => false, 'error' => 'Apenas administradores']); exit; }
            // Soma o que a rede de segurança já resolveu na entrada deste request.
            $agora = resolveExpiredWaivers($pdo);
            foreach ($agora as $k => $v) $agora[$k] = $v + ($resolvidosNaEntrada[$k] ?? 0);
            echo json_encode(['success' => true] + $agora);
            exit;
        }

        $wid = (int)($data['id'] ?? 0);
        if ($wid <= 0) { echo json_encode(['success' => false, 'error' => 'ID inválido']); exit; }

        // Waiver alvo
        $st = $pdo->prepare("SELECT * FROM waiver_retention WHERE id = ?");
        $st->execute([$wid]);
        $w = $st->fetch(PDO::FETCH_ASSOC);
        if (!$w || $w['status'] !== 'open') { echo json_encode(['success' => false, 'error' => 'Waiver não está mais disponível.']); exit; }

        if ($action === 'claim') {
            if ($myTeamId <= 0 || $myLeague !== 'ELITE') { echo json_encode(['success' => false, 'error' => 'Só times da ELITE podem dar lance.']); exit; }
            if ((int)$w['team_id'] === $myTeamId) { echo json_encode(['success' => false, 'error' => 'Você não pode dar lance num jogador que o seu time dispensou.']); exit; }
            if (strtotime($w['expires_at']) <= time()) { echo json_encode(['success' => false, 'error' => 'A janela de 12h já encerrou.']); exit; }
            // Sem espaço pro salário dele, o lance não pode existir: ganhar
            // era virar over the cap na hora em que o jogador entra no elenco.
            $fit = capCabeNoTime($pdo, $myTeamId, (int)$w['ovr']);
            if (!$fit['cabe']) {
                echo json_encode(['success' => false,
                    'error' => $w['name'] . ' custa ' . capValorEscrito($fit['custo'], $fit['unidade'])
                             . ' no cap, e ' . capEspacoEscrito($fit['espaco'], $fit['unidade'])
                             . '. Libere espaço antes de dar o lance.']);
                exit;
            }
            /*
             * O LANCE É ESCOLHIDO PELO TIME, com o espaço no cap como TETO.
             *
             * Antes o lance era sempre o espaço inteiro: quem tinha 18M
             * apostava 18M em tudo, sempre, e o leilão virava uma tabela de
             * quem tem mais cap — sem decisão nenhuma pra tomar. Agora o time
             * diz quanto quer gastar, e o teto continua sendo o espaço dele.
             */
            $espaco = null;
            try { $espaco = (int)getTeamCapSummary($pdo, $myTeamId)['space']; } catch (Throwable $e) {}

            $bid = $data['bid'] ?? null;
            // Sem valor informado, vale o espaço inteiro — é o que os clientes
            // antigos mandavam, e recusar quebraria quem não atualizou a tela.
            $bid = ($bid === null || $bid === '') ? $espaco : (int)$bid;

            if ($bid === null) {
                echo json_encode(['success' => false, 'error' => 'Não consegui calcular o seu espaço no cap agora. Tente de novo.']);
                exit;
            }
            if ($bid <= 0) {
                echo json_encode(['success' => false, 'error' => 'O lance precisa ser maior que zero.']);
                exit;
            }
            if ($espaco !== null && $bid > $espaco) {
                echo json_encode(['success' => false,
                    'error' => 'O lance de ' . $bid . 'M passa do seu espaço no cap (' . $espaco . 'M).']);
                exit;
            }

            /*
             * EDITAR O LANCE CUSTA A PRIORIDADE.
             *
             * O desempate é por quem chegou primeiro (claimed_at). Sem mexer
             * nesse carimbo, dava pra entrar cedo com 1M só pra guardar lugar
             * na fila e subir pro valor de verdade no fim — ficando à frente
             * de quem apostou o mesmo valor horas antes. Editar é um lance
             * novo, e a hora passa a ser a da edição.
             */
            $st = $pdo->prepare("SELECT bid_space FROM waiver_claims WHERE retention_id = ? AND team_id = ?");
            $st->execute([$wid, $myTeamId]);
            $anterior = $st->fetchColumn();
            $jaTinha = ($anterior !== false);
            $mudou = $jaTinha && (int)$anterior !== $bid;

            if (!$jaTinha) {
                $pdo->prepare("INSERT INTO waiver_claims (retention_id, team_id, bid_space) VALUES (?, ?, ?)")
                    ->execute([$wid, $myTeamId, $bid]);
            } elseif ($mudou) {
                $pdo->prepare("UPDATE waiver_claims SET bid_space = ?, claimed_at = NOW()
                                WHERE retention_id = ? AND team_id = ?")->execute([$bid, $wid, $myTeamId]);
            }
            // Reenviar o MESMO valor não mexe em nada — não faria sentido
            // perder a prioridade sem ter mudado o lance.

            echo json_encode(['success' => true, 'claimed' => true, 'bid_space' => $bid,
                              'editado' => $mudou, 'espaco' => $espaco]);
            exit;
        }

        if ($action === 'unclaim') {
            $pdo->prepare("DELETE FROM waiver_claims WHERE retention_id = ? AND team_id = ?")->execute([$wid, $myTeamId]);
            echo json_encode(['success' => true, 'claimed' => false]);
            exit;
        }

        echo json_encode(['success' => false, 'error' => 'Ação inválida']);
        exit;
    }

    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Método não suportado']);
} catch (Throwable $e) {
    error_log('api/waivers.php: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar dispensas. Tente novamente.']);
}
