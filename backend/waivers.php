<?php
/**
 * Sistema de Waiver (12h) — exclusivo da liga ELITE (slide 21 do FBA Elite 15).
 *
 * Fluxo: jogador dispensado da ELITE fica isolado no waiver por 12h. Durante a
 * janela, outros times podem reivindicar. Ao vencer:
 *   - com reivindicações → vai pro time com MAIOR espaço no cap (desempate:
 *     quem reivindicou primeiro);
 *   - sem reivindicações → cai no free agency.
 * NEXT/RISE não usam waiver: a dispensa cai direto no free agency (fluxo antigo).
 */
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/salary_cap.php';
require_once __DIR__ . '/push.php';

const WAIVER_HOURS = 12;

/** OVR mínimo pra dispensa virar push pra liga. Abaixo disso entra no waiver
 *  em silêncio — quem quiser continua vendo na tela de Dispensas. */
const WAIVER_OVR_MIN_PUSH = 78;

function ensureWaiverTables(PDO $pdo): void
{
    // Todo DDL — inclusive CREATE TABLE IF NOT EXISTS numa tabela que já existe
    // — faz COMMIT implícito no MySQL. Chamada de dentro de uma transação, esta
    // função encerrava a transação e o commit() seguinte morria com "There is
    // no active transaction" (era o que derrubava a dispensa de jogador).
    // Roda uma vez por request e nunca com transação aberta.
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) {
        return;
    }
    $pronto = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS waiver_retention (
        id INT AUTO_INCREMENT PRIMARY KEY,
        player_id INT NULL,
        team_id INT NOT NULL,
        league VARCHAR(20) NOT NULL,
        name VARCHAR(120) NOT NULL,
        age INT NULL,
        position VARCHAR(20) NULL,
        secondary_position VARCHAR(20) NULL,
        ovr INT NOT NULL DEFAULT 0,
        seasons_in_league INT NOT NULL DEFAULT 0,
        drafted_by_team_id INT NULL,
        draft_round INT NULL,
        draft_pick_position INT NULL,
        role VARCHAR(30) NULL,
        waived_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        expires_at DATETIME NOT NULL,
        status ENUM('open','claimed','cleared') NOT NULL DEFAULT 'open',
        claimed_by_team_id INT NULL,
        resolved_at DATETIME NULL,
        INDEX idx_wr_status (status, league)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS waiver_claims (
        id INT AUTO_INCREMENT PRIMARY KEY,
        retention_id INT NOT NULL,
        team_id INT NOT NULL,
        bid_space INT NULL,
        claimed_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_claim (retention_id, team_id),
        INDEX idx_wc_ret (retention_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    // bid_space = espaço no cap do time no momento do lance (o "lance"). Coluna nova em bases antigas.
    // "ADD COLUMN IF NOT EXISTS" só existe em MySQL 8.0.29+/MariaDB 10.0.2+; em versões mais
    // antigas isso é um erro de sintaxe (silenciosamente ignorado pelo catch), deixando a coluna
    // faltando para sempre e quebrando (sem JSON válido) toda leitura de waiver_claims depois.
    // Por isso checamos a existência via SHOW COLUMNS (waiverColExists), que funciona em qualquer versão.
    if (!waiverColExists($pdo, 'waiver_claims', 'bid_space')) {
        try { $pdo->exec("ALTER TABLE waiver_claims ADD COLUMN bid_space INT NULL"); }
        catch (Throwable $e) { error_log('ensureWaiverTables bid_space: ' . $e->getMessage()); }
    }
}

function waiverColExists(PDO $pdo, string $table, string $col): bool
{
    try { return (bool)$pdo->query("SHOW COLUMNS FROM `$table` LIKE " . $pdo->quote($col))->fetch(); }
    catch (Exception $e) { return false; }
}

/** Coloca o jogador dispensado no waiver por 12h. $p = linha completa de players. Retorna o id. */
/**
 * @param int|null $horas Janela de lances. O padrão é WAIVER_HOURS (dispensa
 *                        normal); a sobra do draft entra com 24 — ver
 *                        draftSobrasParaWaiver() em backend/draft_fa.php.
 */
function enterWaiver(PDO $pdo, array $p, string $league, ?int $horas = null): int
{
    ensureWaiverTables($pdo);
    $horas = ($horas !== null && $horas > 0) ? $horas : WAIVER_HOURS;
    $stmt = $pdo->prepare("INSERT INTO waiver_retention
        (player_id, team_id, league, name, age, position, secondary_position, ovr, seasons_in_league,
         drafted_by_team_id, draft_round, draft_pick_position, role, expires_at)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?, DATE_ADD(NOW(), INTERVAL ? HOUR))");
    $stmt->execute([
        $p['id'] ?? null, (int)$p['team_id'], $league, $p['name'], $p['age'] ?? null,
        $p['position'] ?? null, $p['secondary_position'] ?? null, (int)($p['ovr'] ?? 0),
        (int)($p['seasons_in_league'] ?? 0), $p['drafted_by_team_id'] ?? null,
        $p['draft_round'] ?? null, $p['draft_pick_position'] ?? null, $p['role'] ?? 'Titular',
        $horas,
    ]);
    $id = (int)$pdo->lastInsertId();
    notificarEntradaNoWaiver($pdo, $p, $league, $horas);
    return $id;
}

/** Avisa a liga que abriu um waiver — menos o time que dispensou. */
function notificarEntradaNoWaiver(PDO $pdo, array $p, string $league, ?int $horas = null): void
{
    try {
        /*
         * SÓ AVISA A LIGA DE DISPENSA QUE INTERESSA.
         *
         * Antes toda dispensa virava push pra liga inteira. Corte de reserva
         * acontece toda semana, e o aviso que chega sempre é o aviso que
         * ninguém lê mais. O corte do grupo de WhatsApp é mais alto (82+,
         * WHATSAPP_OVR_MIN_ANUNCIO) porque lá o barulho incomoda mais gente.
         */
        if ((int)($p['ovr'] ?? 0) < WAIVER_OVR_MIN_PUSH) return;

        $horas = ($horas !== null && $horas > 0) ? $horas : WAIVER_HOURS;
        // Calouro não escolhido não vem de time nenhum (team_id 0): não há
        // dono antigo pra tirar da notificação, e a liga inteira é avisada.
        $st = $pdo->prepare("SELECT user_id FROM teams WHERE id = ? LIMIT 1");
        $st->execute([(int)($p['team_id'] ?? 0)]);
        $donoAntigo = (int)($st->fetchColumn() ?: 0);

        $ovr  = (int)($p['ovr'] ?? 0);
        $pos  = trim((string)($p['position'] ?? ''));
        $ficha = trim(($pos !== '' ? $pos . ' · ' : '') . ($ovr ? $ovr . ' OVR' : ''));

        sendPushToLeague($pdo, $league, [
            'title' => '⏳ Jogador no Waiver',
            'body'  => trim($p['name'] . ($ficha !== '' ? " ({$ficha})" : '')) . ' está disponível por ' . $horas . 'h. Dê seu lance!',
            'url'   => '/free-agency.php',
        ], 'waiver', $donoAntigo ? [$donoAntigo] : []);

        anunciarWaiverNoGrupo($pdo, $p, $league, $ficha, $horas);
    } catch (Throwable $e) {
        error_log('notificarEntradaNoWaiver: ' . $e->getMessage());
    }
}

/**
 * Dispensa de jogador grande vira aviso no grupo principal do WhatsApp.
 *
 * Mesmo corte da trade (82+): o grupo é pra movimento que muda alguma coisa.
 * Dispensa de reserva acontece toda semana e encheria o grupo.
 *
 * Diz o time que dispensou e até quando dá pra dar lance — quem lê está
 * decidindo se corre atrás, e a janela de 12h é a informação que falta.
 *
 * Falha em silêncio de propósito: aviso no grupo é conforto, e uma exceção
 * aqui não pode desfazer a dispensa, que já aconteceu.
 */
function anunciarWaiverNoGrupo(PDO $pdo, array $p, string $league, string $ficha = '', ?int $horas = null): void
{
    try {
        require_once __DIR__ . '/whatsapp.php';

        // Só ELITE. Hoje isso já é verdade por construção — enterWaiver só é
        // chamado pra ELITE em api/players.php — mas a checagem fica explícita
        // porque esta função é chamável de fora e a regra é da liga, não do
        // caminho de código que por acaso leva até aqui.
        if (strtoupper(trim($league)) !== 'ELITE') return;
        if ((int)($p['ovr'] ?? 0) < WHATSAPP_OVR_MIN_ANUNCIO) return;

        $st = $pdo->prepare("SELECT TRIM(CONCAT(COALESCE(city,''),' ',name)) AS nome
                             FROM teams WHERE id = ? LIMIT 1");
        $st->execute([(int)($p['team_id'] ?? 0)]);
        $time = trim((string)($st->fetchColumn() ?: ''));

        $horas = ($horas !== null && $horas > 0) ? $horas : WAIVER_HOURS;
        $ate = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))
                 ->modify('+' . $horas . ' hours');

        // Sem time de origem é calouro que sobrou do draft, não dispensa.
        $ehCalouro = (int)($p['team_id'] ?? 0) <= 0;

        $txt = whatsappTagDaLiga($league) . ($ehCalouro ? " 🎓 *NÃO ESCOLHIDO NO DRAFT*\n\n" : " 📤 *DISPENSADO*\n\n")
             . '*' . trim((string)$p['name']) . '*' . ($ficha !== '' ? " ({$ficha})" : '') . "\n"
             . ($time !== '' ? "Dispensado pelo {$time}\n" : '')
             . "\nNo waiver até " . $ate->format('d/m H:i') . ' — depois disso vai pro free agency.';

        whatsappParaGrupoPrincipal($pdo, $txt, 'waiver');
    } catch (Throwable $e) {
        error_log('anunciarWaiverNoGrupo: ' . $e->getMessage());
    }
}

/** Temporada ativa da liga (id/ano) para preencher o free_agents ao liberar. */
function waiverActiveSeason(PDO $pdo, string $league): array
{
    $st = $pdo->prepare("SELECT id, year FROM seasons WHERE league = ? AND (status IS NULL OR status NOT IN ('completed')) ORDER BY created_at DESC LIMIT 1");
    $st->execute([$league]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) {
        $st2 = $pdo->prepare("SELECT id, year FROM seasons WHERE league = ? ORDER BY season_number DESC LIMIT 1");
        $st2->execute([$league]);
        $r = $st2->fetch(PDO::FETCH_ASSOC) ?: [];
    }
    return ['id' => $r['id'] ?? null, 'year' => $r['year'] ?? null];
}

/** Manda um waiver não reivindicado para o free agency (espelha a dispensa antiga). */
function waiverToFreeAgency(PDO $pdo, array $w): void
{
    $tn = $pdo->prepare("SELECT CONCAT(city,' ',name) FROM teams WHERE id = ?");
    $tn->execute([(int)$w['team_id']]);
    $teamName = $tn->fetchColumn() ?: null;

    $cols = ['name', 'age', 'position', 'secondary_position', 'overall', 'league', 'original_team_id', 'original_team_name'];
    $vals = [$w['name'], $w['age'], $w['position'], $w['secondary_position'], (int)$w['ovr'], $w['league'], (int)$w['team_id'], $teamName];

    if (waiverColExists($pdo, 'free_agents', 'waived_at')) { $cols[] = 'waived_at'; $vals[] = date('Y-m-d H:i:s'); }
    if (waiverColExists($pdo, 'free_agents', 'season_id') || waiverColExists($pdo, 'free_agents', 'season_year')) {
        $s = waiverActiveSeason($pdo, $w['league']);
        if (waiverColExists($pdo, 'free_agents', 'season_id'))   { $cols[] = 'season_id';   $vals[] = $s['id']; }
        if (waiverColExists($pdo, 'free_agents', 'season_year')) { $cols[] = 'season_year'; $vals[] = $s['year']; }
    }
    $ph = implode(', ', array_fill(0, count($cols), '?'));
    $pdo->prepare("INSERT INTO free_agents (" . implode(', ', $cols) . ") VALUES ($ph)")->execute($vals);
}

/**
 * Recria o jogador reivindicado no elenco do time vencedor.
 *
 * Entra no BANCO, e não com o papel que ele tinha no time anterior. Vinha
 * `$w['role'] ?: 'Titular'`: quem era titular onde foi dispensado nascia
 * titular aqui, e o time que reivindicou acordava com seis no quinteto —
 * sem ter pedido, e sem aviso nenhum. É a mesma regra da trade, do draft e
 * da free agency, que já entregam no banco.
 */
function waiverRecreatePlayer(PDO $pdo, array $w, int $teamId): void
{
    /*
     * O LANCE DO WAIVER NÃO É SALÁRIO — regra da liga, 30/08/2026.
     *
     * O jogador continua recebendo o que está no app, pela tabela de OVR.
     * Antawn Jamison recebe 5M; um lance de 150M ganha o waiver e ele segue
     * recebendo 5M. O lance existe pra decidir QUEM leva e pra comprometer o
     * espaço durante a disputa, não pra virar contrato.
     *
     * Contrato de leilão existe só na Free Agency (`contract_salary`), e lá
     * vale um ano.
     */
    $pdo->prepare("INSERT INTO players
        (team_id, name, age, position, secondary_position, ovr, seasons_in_league,
         drafted_by_team_id, draft_round, draft_pick_position, role)
        VALUES (?,?,?,?,?,?,?,?,?,?,'Banco')")->execute([
        $teamId, $w['name'], $w['age'], $w['position'], $w['secondary_position'], (int)$w['ovr'],
        (int)$w['seasons_in_league'], $w['drafted_by_team_id'], $w['draft_round'], $w['draft_pick_position'],
    ]);
}

/**
 * Avisa quem participou do waiver como ele terminou: o vencedor ganha o jogador,
 * quem perdeu fica sabendo, e sem lance nenhum a liga inteira sabe que caiu na FA.
 */
function notificarResultadoDoWaiver(PDO $pdo, array $w, array $claims, int $winner): void
{
    try {
        $nome = (string)$w['name'];

        /*
         * WAIVER QUE NINGUÉM QUIS NÃO VIRA AVISO.
         *
         * Isto mandava push pra LIGA INTEIRA dizer que ninguém reivindicou o
         * jogador — a notificação com menos motivo pra existir do sistema:
         * ela avisa que nada aconteceu, e vai pra todo mundo, inclusive pros
         * que nem sabiam do waiver. Quem se interessar acha ele na Free
         * Agency, que é onde ele foi parar.
         *
         * Quem deu lance continua sendo avisado, sempre: ali houve resultado.
         */
        if (!$claims) return;

        sendPushToTeam($pdo, $winner, [
            'title' => '✅ Waiver vencido!',
            'body'  => "{$nome} é seu. Ele já está no seu elenco.",
            'url'   => '/my-roster.php',
        ], 'waiver');

        foreach ($claims as $c) {
            $tid = (int)$c['team_id'];
            if ($tid === $winner) continue;
            sendPushToTeam($pdo, $tid, [
                'title' => '❌ Waiver perdido',
                'body'  => "{$nome} foi para outro time — o lance vencedor tinha mais espaço no cap.",
                'url'   => '/free-agency.php',
            ], 'waiver');
        }
    } catch (Throwable $e) {
        error_log('notificarResultadoDoWaiver: ' . $e->getMessage());
    }
}

/**
 * Resolve todos os waivers vencidos (status open, expires_at <= agora).
 * Chamado pelo agendador (cron), pelo admin e, como rede de segurança, ao abrir a aba.
 * Retorna ['resolved'=>, 'claimed'=>, 'cleared'=>].
 */
/**
 * Tira da fila quem não tem mais espaço pro salário do jogador.
 *
 * Vale só onde o cap existe (ELITE): fora dela não há folha pra estourar, e
 * filtrar recusaria lance legítimo. A ordem dos que sobram é preservada — o
 * maior lance continua na frente.
 */
function waiverFiltrarPorEspaco(PDO $pdo, array $claims, int $ovr): array
{
    if (count($claims) < 1) return $claims;

    $custo = getPlayerBaseSalary(['ovr' => $ovr]);
    if ($custo <= 0) return $claims;

    $sobram = [];
    foreach ($claims as $c) {
        $teamId = (int)$c['team_id'];
        try {
            $liga = $pdo->query("SELECT league FROM teams WHERE id = " . $teamId)->fetchColumn();
            if (strtoupper(trim((string)$liga)) !== 'ELITE') { $sobram[] = $c; continue; }

            $r = getTeamCapSummary($pdo, $teamId);
            $espaco = (int)$r['cap_max'] - (int)$r['payroll'];
            if ($espaco >= $custo) $sobram[] = $c;
            else error_log("[waiver] time {$teamId} sem espaço ({$espaco}M) pro salário de {$custo}M — perdeu a vez");
        } catch (Throwable $e) {
            // Falha de cálculo não pode tirar alguém da disputa: na dúvida,
            // o lance continua valendo — era o comportamento de antes.
            error_log('[waiver] espaco: ' . $e->getMessage());
            $sobram[] = $c;
        }
    }
    return $sobram;
}

function resolveExpiredWaivers(PDO $pdo): array
{
    $out = ['resolved' => 0, 'claimed' => 0, 'cleared' => 0];

    try {
        ensureWaiverTables($pdo);
        $rows = $pdo->query("SELECT * FROM waiver_retention WHERE status = 'open' AND expires_at <= NOW() ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        // Rede de segurança: isso roda a cada acesso a /api/waivers.php (via GET), então um
        // erro aqui não pode derrubar a página inteira — o cron (api/waiver-cron.php) é quem
        // resolve de verdade; aqui é só best-effort.
        error_log('resolveExpiredWaivers select: ' . $e->getMessage());
        return $out;
    }

    foreach ($rows as $w) {
        $wid = (int)$w['id'];
        try {
            /*
             * A REGRA, E ELA NÃO MUDA: MAIOR LANCE LEVA. Empate, o primeiro.
             *
             * O COALESCE existe porque `bid_space` é NULL em lance de base
             * antiga, e em MySQL o NULL vem ANTES de tudo num DESC — um lance
             * sem valor passava na frente de quem apostou de verdade. Hoje não
             * há nenhum NULL em produção, mas a ordenação não pode depender
             * disso: NULL vale zero, que é o que ele significa.
             */
            $cs = $pdo->prepare("SELECT team_id, bid_space, claimed_at FROM waiver_claims
                                  WHERE retention_id = ?
                               ORDER BY COALESCE(bid_space, 0) DESC, claimed_at ASC, id ASC");
            $cs->execute([$wid]);
            $claims = $cs->fetchAll(PDO::FETCH_ASSOC);

            /*
             * O SALÁRIO DELE AINDA CABE?
             *
             * O lance é conferido contra o cap na hora em que é dado, mas os
             * lances não se somam: um time com 10M de espaço podia apostar
             * 10M em três dispensados de 8M e, ganhando os três, terminar com
             * 24M de folha e 10M de espaço. O anúncio da liga é explícito —
             * o lance vale "desde que tenha espaço suficiente para realizar a
             * movimentação", e ninguém deve ficar ferrado de cap pelo sistema.
             *
             * Quem não tem mais espaço perde a vez pro lance seguinte, que é
             * o que aconteceria se ele nem tivesse apostado. O lance continua
             * sem virar salário: o custo aqui é o salário do jogador, o mesmo
             * que já está no app.
             */
            $claims = waiverFiltrarPorEspaco($pdo, $claims, (int)$w['ovr']);

            $pdo->beginTransaction();
            $winner = 0;
            if ($claims) {
                $winner = (int)$claims[0]['team_id'];
                waiverRecreatePlayer($pdo, $w, $winner);
                $pdo->prepare("UPDATE waiver_retention SET status='claimed', claimed_by_team_id=?, resolved_at=NOW() WHERE id=?")->execute([$winner, $wid]);
                $out['claimed']++;
            } else {
                waiverToFreeAgency($pdo, $w);
                $pdo->prepare("UPDATE waiver_retention SET status='cleared', resolved_at=NOW() WHERE id=?")->execute([$wid]);
                $out['cleared']++;
            }
            $pdo->commit();
            $out['resolved']++;

            // Só depois do commit — push nunca dentro de transação.
            notificarResultadoDoWaiver($pdo, $w, $claims, $winner);
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) { $pdo->rollBack(); }
            error_log('resolveExpiredWaivers resolve #' . $wid . ': ' . $e->getMessage());
        }
    }
    return $out;
}
