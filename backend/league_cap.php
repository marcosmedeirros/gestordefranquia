<?php
/**
 * Recálculo automático do CAP de liga (soma dos CAP_TOP_N maiores OVR, ou folha salarial
 * pra ligas em modo 'salary' como a ELITE hoje).
 *
 * Regra: a cada 2 temporadas (temporadas 3, 5, 7, ...), assim que o último
 * time da liga registra o elenco da temporada (player_season_log), o sistema
 * soma o CAP de todos os times, tira a média, e define:
 *   cap_max = média + margem
 *   cap_min = média - margem
 * Ver backend/checklist_temporada.php (mesma query de "times atualizados")
 * e backend/salary_cap.php (payroll do modo salário).
 *
 * Best-effort: qualquer falha aqui é silenciosa e nunca derruba o fluxo de
 * quem chamou (ex: salvar o snapshot do elenco).
 */

require_once __DIR__ . '/helpers.php';

const LEAGUE_CAP_DEFAULT_OVR_MARGIN     = 18; // pontos de OVR pra cima/baixo da média (faixa pedida: 15 a 20)
const LEAGUE_CAP_DEFAULT_SALARY_MARGIN  = 12; // % pra cima/baixo da folha média (ELITE, modo salário)

/**
 * O MÁXIMO QUE O CAP ANDA NUM RECÁLCULO.
 *
 * Sete, e quase sempre menos: o passo é a distância até o alvo quando ela é
 * menor que isso, então liga que cresceu três anda três. A liga pediu assim
 * depois de ver a conta pela média propor um salto de dezoito pontos de uma
 * vez — cap é a régua que decide quem está irregular, e mudá-la de supetão
 * manda gente cortar jogador por causa de uma conta, não de uma decisão.
 */
const LEAGUE_CAP_PASSO_MAXIMO = 7;

function ensureLeagueCapAutoTables(PDO $pdo): void
{
    try {
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_last_season'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_last_season INT NULL");
        }
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_margin'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_margin INT NOT NULL DEFAULT " . LEAGUE_CAP_DEFAULT_OVR_MARGIN);
        }
        if ($pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_auto_margin_pct'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_auto_margin_pct INT NOT NULL DEFAULT " . LEAGUE_CAP_DEFAULT_SALARY_MARGIN);
        }
    } catch (Throwable $e) {}

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS league_cap_history (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league VARCHAR(20) NOT NULL,
            season_number INT NOT NULL,
            cap_mode VARCHAR(10) NOT NULL,
            avg_value INT NOT NULL,
            margin INT NOT NULL,
            cap_min INT NOT NULL,
            cap_max INT NOT NULL,
            teams_total INT NOT NULL,
            teams_above INT NOT NULL,
            teams_below INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {}
}

/**
 * Quantos times da liga já mexeram no elenco nesta temporada — e quais não.
 *
 * A REGRA É A DA BOLINHA DA ABA TIMES, e agora é só esta: o elenco conta como
 * atualizado quando `roster_updated_at` é DESTA temporada, isto é, posterior à
 * criação dela. É a mesma linha que teams.php usa pra decidir entre o ✓ verde
 * e o relógio amarelo no card do time.
 *
 * Antes daqui saía outra conta, e ela mentia. Valia o carimbo
 * `roster_touched_season` OU uma linha em player_season_log daquela temporada
 * — e o log da temporada nasce cheio, por outros caminhos que não são o GM
 * mexendo no elenco. Medido na RISE em 21/09/2026: 24 times com carimbo da
 * temporada, 30 com linha no log, e o checklist do admin anunciando
 * "30 de 30 times" enquanto a aba Times mostrava seis relógios amarelos.
 * Quem lia o admin achava que a liga estava pronta pra virar.
 *
 * A lista dos pendentes vem junto porque "24 de 30" sozinho não diz a quem
 * cobrar — e cobrar é a única coisa que se faz com esse número.
 *
 * ATENÇÃO: as travas de trade e as pendências do GM usam outra função
 * (elencoAtualizadoNaTemporada, em helpers.php), que ainda aceita o log.
 * São contas diferentes de propósito enquanto ninguém decidir bloquear
 * trade por este critério mais duro.
 */
function leagueRosterUpdateStatus(PDO $pdo, string $league, int $seasonId): array
{
    ensureRosterTouchColumn($pdo);

    $st = $pdo->prepare("SELECT created_at FROM seasons WHERE id = ?");
    $st->execute([$seasonId]);
    $inicio = (string)($st->fetchColumn() ?: '');

    /* Sem data de criação da temporada não dá pra dizer o que é "desta
       temporada". Aí sobra o carimbo, que é o sinal honesto que existe. */
    $sql = $inicio !== ''
        ? "SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome,
                  (roster_updated_at IS NOT NULL AND roster_updated_at >= ?) AS ok
             FROM teams WHERE league = ? ORDER BY nome"
        : "SELECT id, TRIM(CONCAT(COALESCE(city,''),' ',COALESCE(name,''))) AS nome,
                  (roster_touched_season = ?) AS ok
             FROM teams WHERE league = ? ORDER BY nome";

    $st = $pdo->prepare($sql);
    $st->execute([$inicio !== '' ? $inicio : $seasonId, $league]);

    $total = 0; $done = 0; $pendentes = []; $pendentesIds = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $total++;
        if ((int)$t['ok'] === 1) { $done++; continue; }
        $pendentes[] = trim((string)$t['nome']) ?: ('Time #' . (int)$t['id']);
        $pendentesIds[] = (int)$t['id'];
    }

    return [
        'total'         => $total,
        'done'          => $done,
        'complete'      => $total > 0 && $done >= $total,
        'pendentes'     => $pendentes,
        // Os ids saem junto pra quem precisa de mais do que o nome — o bot
        // marca o GM no grupo, e pra isso tem que chegar no dono do time.
        'pendentes_ids' => $pendentesIds,
    ];
}

/**
 * Calcula e grava o novo cap_min/cap_max da liga com base na média do CAP
 * de todos os times (soma dos CAP_TOP_N maiores OVR, ou folha salarial em modo 'salary').
 * Sempre roda de fato (não checa "é hora de recalcular" — isso é feito por
 * quem chama, ver maybeAutoRecalcularCapDaLiga). Retorna o resumo do
 * cálculo, ou null se não havia times/dados suficientes.
 */
/**
 * A CONTA DO CAP, SEM GRAVAR NADA.
 *
 * Saiu de dentro do recálculo pra que dê pra MOSTRAR a faixa antes de aplicar:
 * o comando do grupo pergunta "confirma?" e, sem isto, perguntava sobre um
 * número que ninguém tinha visto. A tela do admin e o bot passam a poder
 * responder "vai ficar assim" com a mesma conta que vai rodar.
 *
 * @return array|null null quando a liga não tem times ou elenco pra tirar média.
 */
function capPreviaDoRecalculo(PDO $pdo, string $league): ?array
{
    return recalcularCapDaLiga($pdo, $league, 0, true);
}

/**
 * @param bool $apenasCalcular não grava nada e não avisa ninguém — só devolve
 *                             a conta. É o que a prévia usa.
 */
function recalcularCapDaLiga(PDO $pdo, string $league, int $seasonNumber, bool $apenasCalcular = false): ?array
{
    ensureLeagueCapAutoTables($pdo);

    $stmtCfg = $pdo->prepare("SELECT cap_mode, cap_auto_margin, cap_auto_margin_pct, cap_min, cap_max FROM league_settings WHERE league = ?");
    $stmtCfg->execute([$league]);
    $cfg = $stmtCfg->fetch(PDO::FETCH_ASSOC) ?: [];
    $capMode = $cfg['cap_mode'] ?? 'ovr_sum';
    $ovrMargin = (int)($cfg['cap_auto_margin'] ?? LEAGUE_CAP_DEFAULT_OVR_MARGIN);
    $salaryMarginPct = (int)($cfg['cap_auto_margin_pct'] ?? LEAGUE_CAP_DEFAULT_SALARY_MARGIN);

    $stmtTeams = $pdo->prepare("SELECT id FROM teams WHERE league = ?");
    $stmtTeams->execute([$league]);
    $teamIds = array_map('intval', $stmtTeams->fetchAll(PDO::FETCH_COLUMN));
    if (!$teamIds) return null;

    $values = [];
    if ($capMode === 'salary') {
        require_once __DIR__ . '/salary_cap.php';
        foreach ($teamIds as $tid) {
            $summary = getTeamCapSummary($pdo, $tid);
            if ($summary) $values[$tid] = (int)($summary['payroll'] ?? 0);
        }
    } else {
        foreach ($teamIds as $tid) {
            $values[$tid] = topEightCap($pdo, $tid);
        }
    }
    if (!$values) return null;

    $avg = array_sum($values) / count($values);
    if ($capMode === 'salary') {
        $margin = (int)round($avg * ($salaryMarginPct / 100));
        $marginRecord = $salaryMarginPct; // guarda o % usado, pra ficar claro no histórico
    } else {
        $margin = $ovrMargin;
        $marginRecord = $ovrMargin;
    }

    /* O ALVO é a faixa que a média de hoje pediria. Ele quase nunca vira o cap
       novo: entre um e outro está o passo. */
    $alvoMax = (int)round($avg) + $margin;
    $alvoMin = max(0, (int)round($avg) - $margin);

    /* O CAP ANDA DE POUCO EM POUCO, e nunca dá um salto.
       O cálculo pela média sozinho propôs 805–841 pra RISE em 21/09/2026,
       contra os 794–823 que estavam valendo: onze e dezoito pontos de uma vez.
       Os admins olharam e puseram 800–830 na mão. É esse o comportamento certo,
       e a razão é simples: o cap é a régua que decide quem está irregular, e
       um salto muda de um dia pro outro quem pode fechar troca e quem tem que
       cortar jogador.

       Então o novo cap sai do ATUAL, andando na direção do alvo, no máximo
       LEAGUE_CAP_PASSO_MAXIMO por vez. Liga que cresceu pouco anda pouco (a
       distância manda); liga que disparou anda o teto e continua subindo no
       recálculo seguinte, com a liga tendo tempo de acompanhar.

       Vale pros dois lados: descida em salto deixaria metade da liga irregular
       sem ninguém ter feito nada. E vale só quando JÁ EXISTE cap — na primeira
       vez da liga não há de onde andar, e o alvo entra inteiro. */
    $atualMin = (int)($cfg['cap_min'] ?? 0);
    $atualMax = (int)($cfg['cap_max'] ?? 0);
    $temCapAtual = $atualMin > 0 && $atualMax > 0;

    $passo = static function (int $atual, int $alvo): int {
        $dif = $alvo - $atual;
        if ($dif === 0) return $atual;
        $anda = min(abs($dif), LEAGUE_CAP_PASSO_MAXIMO);
        return $atual + ($dif > 0 ? $anda : -$anda);
    };

    $newMax = $temCapAtual ? $passo($atualMax, $alvoMax) : $alvoMax;
    $newMin = $temCapAtual ? max(0, $passo($atualMin, $alvoMin)) : $alvoMin;

    $acima = 0; $abaixo = 0;
    foreach ($values as $v) {
        if ($v > $newMax) $acima++;
        elseif ($v < $newMin) $abaixo++;
    }

    // A prévia para aqui: a conta está pronta e nada foi tocado.
    if ($apenasCalcular) {
        return [
            'league' => $league, 'cap_mode' => $capMode,
            'avg' => (int)round($avg), 'margin' => $marginRecord,
            'cap_min' => $newMin, 'cap_max' => $newMax,
            'antes_min' => $temCapAtual ? $atualMin : null,
            'antes_max' => $temCapAtual ? $atualMax : null,
            'alvo_min' => $alvoMin, 'alvo_max' => $alvoMax,
            'segurou' => $temCapAtual && ($alvoMin !== $newMin || $alvoMax !== $newMax),
            'teams_total' => count($values), 'teams_above' => $acima, 'teams_below' => $abaixo,
        ];
    }

    $pdo->prepare("UPDATE league_settings SET cap_min = ?, cap_max = ?, cap_auto_last_season = ? WHERE league = ?")
        ->execute([$newMin, $newMax, $seasonNumber, $league]);

    $pdo->prepare("INSERT INTO league_cap_history
        (league, season_number, cap_mode, avg_value, margin, cap_min, cap_max, teams_total, teams_above, teams_below)
        VALUES (?,?,?,?,?,?,?,?,?,?)")
        ->execute([$league, $seasonNumber, $capMode, (int)round($avg), $marginRecord, $newMin, $newMax, count($values), $acima, $abaixo]);

    $resumo = [
        'league' => $league, 'season_number' => $seasonNumber, 'cap_mode' => $capMode,
        'avg' => (int)round($avg), 'margin' => $marginRecord, 'cap_min' => $newMin, 'cap_max' => $newMax,
        'teams_total' => count($values), 'teams_above' => $acima, 'teams_below' => $abaixo,
        /* De onde veio e pra onde ia: sem isso, o admin que esperava 841 e viu
           830 não tem como saber se o teto segurou ou se a conta mudou. */
        'antes_min' => $temCapAtual ? $atualMin : null,
        'antes_max' => $temCapAtual ? $atualMax : null,
        'alvo_min'  => $alvoMin,
        'alvo_max'  => $alvoMax,
        'segurou'   => $temCapAtual && ($alvoMin !== $newMin || $alvoMax !== $newMax),
    ];
    notificarRecalculoCapDaLiga($pdo, $resumo);

    return $resumo;
}

/** Avisa por push todo mundo da liga que o CAP mudou. Best-effort. */
function notificarRecalculoCapDaLiga(PDO $pdo, array $resumo): void
{
    $pushFile = __DIR__ . '/push.php';
    if (!file_exists($pushFile)) return;
    require_once $pushFile;

    try {
        $stmt = $pdo->prepare("SELECT id FROM users WHERE league = ?");
        $stmt->execute([$resumo['league']]);
        $userIds = $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        error_log('notificarRecalculoCapDaLiga (usuários): ' . $e->getMessage());
        return;
    }

    $unidade = $resumo['cap_mode'] === 'salary' ? 'M' : '';
    $payload = [
        'title' => "📊 Novo CAP da {$resumo['league']}",
        'body'  => "Temporada {$resumo['season_number']}: CAP agora é {$resumo['cap_min']}{$unidade}–{$resumo['cap_max']}{$unidade} (média {$resumo['avg']}{$unidade} entre os times).",
        'url'   => '/dashboard.php',
    ];
    foreach ($userIds as $uid) {
        try {
            sendPushToUser($pdo, (int)$uid, $payload, 'cap');
        } catch (Throwable $e) {
            error_log('notificarRecalculoCapDaLiga (push user_id=' . $uid . '): ' . $e->getMessage());
        }
    }

    capAvisarGrupoDoRecalculo($pdo, $resumo);
}

/**
 * O CAP NOVO NO GAMEPLAY DA LIGA.
 *
 * O push já existia, mas push é aviso de quem está com o app instalado e com
 * a permissão ligada — e a faixa do CAP é a régua que decide quem pode fechar
 * troca hoje à noite. Quem não soube que ela mudou monta elenco contra um
 * número que não existe mais.
 *
 * Quantos times ficaram fora da faixa vai junto: é o que transforma o aviso em
 * ação, porque quem está fora tem que se ajustar.
 */
function capAvisarGrupoDoRecalculo(PDO $pdo, array $resumo): void
{
    try {
        require_once __DIR__ . '/whatsapp.php';
        require_once __DIR__ . '/leilao_bot.php';   // botGrupoDaCerimonia()

        $liga = strtoupper((string)($resumo['league'] ?? ''));
        if ($liga === '') return;

        $grupo = botGrupoDaCerimonia($pdo, $liga);
        if (!$grupo) return;

        $un = ($resumo['cap_mode'] ?? '') === 'salary' ? 'M' : '';
        $fora = [];
        if (!empty($resumo['teams_above'])) $fora[] = (int)$resumo['teams_above'] . ' acima';
        if (!empty($resumo['teams_below'])) $fora[] = (int)$resumo['teams_below'] . ' abaixo';

        // De quanto foi o passo: é o que mostra que a régua andou devagar.
        $de = '';
        if (!empty($resumo['antes_min']) && !empty($resumo['antes_max'])) {
            $de = "_Era {$resumo['antes_min']}–{$resumo['antes_max']}{$un}._\n";
        }

        $txt = "📊 *NOVO CAP DEFINIDO — {$liga}*\n\n"
             . "Cap máximo: *{$resumo['cap_max']}{$un}*\n"
             . "Cap mínimo: *{$resumo['cap_min']}{$un}*\n"
             . ($de !== '' ? "\n" . $de : '')
             . "\n_Média dos elencos: {$resumo['avg']}{$un}"
             . ($fora ? ' · ' . implode(' e ', $fora) . ' da faixa' : ' · todo mundo dentro da faixa')
             . '_';

        whatsappEnfileirar($pdo, $grupo, $txt, true, 'cap');
    } catch (Throwable $e) {
        error_log('[cap] aviso no grupo: ' . $e->getMessage());
    }
}

/**
 * O CAP NÃO SE MEXE MAIS SOZINHO.
 *
 * Antes o recálculo disparava sozinho nas temporadas 3, 5, 7…, assim que o
 * último time da liga registrava o elenco. Isso punha o CAP pra mudar no meio
 * de um avanço de temporada, sem ninguém pedir e sem ninguém conferir os
 * números antes — a liga só descobria pelo push, com o valor já gravado.
 *
 * Agora quem manda é o admin: o botão "Calcular CAP" na aba da liga chama
 * recalcularCapAgora(), que faz exatamente a mesma conta de sempre. A régua do
 * "a cada 2 temporadas" continua valendo — só que como decisão de quem
 * administra, e não como automatismo.
 *
 * A função antiga fica aqui sem efeito, e não some: ela é chamada de fora, e
 * apagá-la trocaria "não faz nada" por erro fatal no meio de um salvamento de
 * elenco.
 */
function maybeAutoRecalcularCapDaLiga(PDO $pdo, string $league, int $seasonId, int $seasonNumber): ?array
{
    return null;
}

/**
 * O botão do admin: recalcula o CAP da liga agora, na temporada em curso.
 *
 * Devolve ['ok' => bool, 'erro' => ?string, 'resumo' => ?array]. O separado
 * importa porque, do lado do recalcularCapDaLiga, "esta liga não tem
 * temporada" e "não deu pra tirar a média" voltam os dois como null, e o
 * admin precisa saber qual dos dois é.
 */
function recalcularCapAgora(PDO $pdo, string $league): array
{
    /* A TEMPORADA DA SPRINT ATIVA, e não a de maior número no banco.
       Pegando o maior season_number da liga, a ELITE caía na temporada 20 da
       sprint 1, encerrada — e o cálculo ia parar no histórico com esse número,
       enquanto a liga está na temporada 2 da sprint 2. Os season_number
       recomeçam a cada sprint, então "o maior" não quer dizer "a de agora". */
    $st = $pdo->prepare("SELECT s.id, s.season_number
                           FROM seasons s
                           JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE s.league = ? AND sp.status = 'active'
                       ORDER BY s.season_number DESC LIMIT 1");
    $st->execute([$league]);
    $temp = $st->fetch(PDO::FETCH_ASSOC);

    // Liga sem sprint ativa (ainda não migrou, ou fechou a última): cai na
    // última temporada cadastrada, que é o que existe pra usar.
    if (!$temp) {
        $st = $pdo->prepare('SELECT id, season_number FROM seasons
                              WHERE league = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$league]);
        $temp = $st->fetch(PDO::FETCH_ASSOC);
    }
    if (!$temp) {
        return ['ok' => false, 'erro' => 'Esta liga não tem temporada cadastrada.', 'resumo' => null];
    }

    $resumo = recalcularCapDaLiga($pdo, $league, (int)$temp['season_number']);
    if (!$resumo) {
        return ['ok' => false, 'erro' => 'Não há times com elenco suficiente pra tirar a média.', 'resumo' => null];
    }

    /* Quantos times já mexeram no elenco desta temporada. Não trava nada — o
       admin pediu, a conta sai —, mas volta junto pra ele ver na hora se está
       tirando média de uma liga que metade ainda não atualizou. Era isso que a
       espera automática garantia, e que agora é responsabilidade de quem
       clica. */
    $resumo['atualizados'] = leagueRosterUpdateStatus($pdo, $league, (int)$temp['id']);

    return ['ok' => true, 'erro' => null, 'resumo' => $resumo];
}
