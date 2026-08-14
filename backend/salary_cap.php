<?php
/**
 * Motor de cálculo do Salary Cap — exclusivo da liga ELITE.
 *
 * Nada é armazenado: salário, bônus e cap flex são sempre calculados na hora a
 * partir de ovr, draft_round/draft_pick_position, seasons_in_league e
 * season_awards. Isso satisfaz o próprio requisito da regra ("recalcular sempre
 * que o OVR mudar ou houver troca") de graça — não existe estado pra ficar
 * desatualizado.
 *
 * Fonte: regulamento + especificação técnica de Salary Cap (FBA Elite), com duas
 * correções do responsável pela liga sobre o texto original dos PDFs:
 *   1. Rookie scale usa os valores abaixo (substituem os do PDF).
 *   2. Bônus de prêmio vale só pela temporada seguinte ao prêmio, não é permanente.
 */

require_once __DIR__ . '/helpers.php'; // markLoyaltyEligibility (Bônus de Lealdade)

const CAP_BASE_MILLIONS = 205;
const CAP_FLOOR_MILLIONS = 170;
// Quantos jogadores do elenco podem gerar Cap Flex ao mesmo tempo.
const CAP_FLEX_MAX_PLAYERS = 2;
// Bônus de Lealdade: jogador leal (nunca trocado, OVR>=90, draftado pelo draft
// da própria temporada — mesma régua da RISE/NEXT) soma este valor no Cap
// Máximo, limitado a este nº de jogadores por time.
const CAP_LOYALTY_MAX_PLAYERS = 2;
const CAP_LOYALTY_BONUS_MILLIONS = 8;
// Numa troca, o time sem espaco no teto so pode receber ate esta % do que envia.
const CAP_TRADE_MATCH_PCT = 120;

/**
 * Base (Cap Máximo antes de Cap Flex/Bônus de Lealdade) e Piso da folha
 * salarial, configurados pelo admin em Central da Liga (campos "CAP Mínimo"
 * e "CAP Máximo" — os mesmos que as outras ligas usam pro modo ovr_sum).
 * Sem nada configurado (0 ou ausente), cai nos valores padrão do documento
 * FBA Elite 15 — mantém o comportamento de sempre pra quem nunca mexeu.
 */
function capBaseEFloorDaLiga(PDO $pdo, string $league): array
{
    try {
        $stmt = $pdo->prepare('SELECT cap_min, cap_max FROM league_settings WHERE league = ?');
        $stmt->execute([strtoupper(trim($league))]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        $base  = (int)($row['cap_max'] ?? 0);
        $floor = (int)($row['cap_min'] ?? 0);
        return [
            'base'  => $base > 0 ? $base : CAP_BASE_MILLIONS,
            'floor' => $floor > 0 ? $floor : CAP_FLOOR_MILLIONS,
        ];
    } catch (Throwable $e) {
        return ['base' => CAP_BASE_MILLIONS, 'floor' => CAP_FLOOR_MILLIONS];
    }
}

/**
 * Temporada a partir da qual o Cap Flex passa a valer.
 *
 * A primeira temporada da edição começa logo depois do Draft Inicial, quando
 * ninguém "desenvolveu" ninguém ainda — o Cap Flex existe justamente pra
 * franquia segurar a estrela que ela mesma formou, então não faz sentido no
 * ano 1. Como todo jogador do draft inicial fica com drafted_by_team_id igual
 * ao próprio time, sem essa trava metade da liga ganharia +16M de teto de
 * graça já na estreia.
 *
 * Fica em league_settings pra Administração poder ligar quando quiser, sem
 * depender de deploy.
 */
function capFlexLiberado(PDO $pdo, string $league, ?int $temporadaAtual): bool
{
    // columnExists() só existe dentro dos endpoints de api/, não em helpers.php —
    // por isso a checagem da coluna é feita direto aqui.
    try {
        $temColuna = $pdo->query("SHOW COLUMNS FROM league_settings LIKE 'cap_flex_a_partir_da_temporada'")->fetch();
        if (!$temColuna) {
            $pdo->exec("ALTER TABLE league_settings ADD COLUMN cap_flex_a_partir_da_temporada INT NULL");
        }
        $st = $pdo->prepare("SELECT cap_flex_a_partir_da_temporada FROM league_settings WHERE league = ?");
        $st->execute([strtoupper(trim($league))]);
        $desde = $st->fetchColumn();
    } catch (Throwable $e) {
        return true; // falha de schema não pode desligar uma regra da liga
    }

    // Sem configuração: Cap Flex ligado (comportamento histórico das ligas que já rodam).
    if ($desde === null || $desde === false || $desde === '') return true;
    if ($temporadaAtual === null) return true;
    return $temporadaAtual >= (int)$desde;
}

/** Tabela de salário por OVR (em milhões). OVR 77 ou menos cai no "veteran minimum". */
function capOvrSalaryTable(): array
{
    return [
        99 => 60, 98 => 56, 97 => 52, 96 => 48, 95 => 44, 94 => 40, 93 => 36,
        92 => 32, 91 => 29, 90 => 26, 89 => 23, 88 => 20, 87 => 18, 86 => 16,
        85 => 14, 84 => 12, 83 => 10, 82 => 8, 81 => 6, 80 => 5, 79 => 4, 78 => 3,
    ];
}

const CAP_VETERAN_MINIMUM_MILLIONS = 2;

function capOvrSalary(int $ovr): int
{
    if ($ovr >= 99) return 60;
    if ($ovr <= 77) return CAP_VETERAN_MINIMUM_MILLIONS;
    $table = capOvrSalaryTable();
    return $table[$ovr] ?? CAP_VETERAN_MINIMUM_MILLIONS;
}

/**
 * Rookie scale (valores corrigidos — substituem o PDF original).
 * 1ª rodada por posição do pick; 2ª rodada é sempre 2M, independente da posição.
 */
function capRookieScaleValue(int $draftRound, ?int $draftPickPosition): int
{
    if ($draftRound >= 2) return 2;
    if ($draftPickPosition === null) return 2; // sem posição registrada: cai no piso, nunca superestima
    if ($draftPickPosition <= 3) return 18;
    if ($draftPickPosition <= 8) return 14;
    if ($draftPickPosition <= 12) return 12;
    if ($draftPickPosition <= 16) return 8;
    if ($draftPickPosition <= 22) return 5;
    return 3; // 23-30
}

/**
 * Piso de salário do jovem vindo do Draft Inicial.
 *
 * O draft inicial não tem rookie scale — ele distribuiu elencos, não calouros,
 * e por isso esses jogadores sempre pagaram pela tabela de OVR. Só que a
 * tabela cobra pouco de quem ainda está subindo: um 80 de 21 anos custa 5M, e
 * o time fica com um ativo valioso ocupando quase nada do teto.
 *
 * Este piso corrige isso pelas primeiras rodadas — quanto mais cedo o time
 * escolheu, mais o jovem pesa. É PISO, não preço: quem já custa mais que ele
 * pela tabela continua no valor da tabela. Um 88 de 22 anos vale 20M e segue
 * valendo 20M.
 *
 * A 2ª rodada tem degrau: 78–85 leva 16M, 76 e 77 levam 10M.
 *
 * O teto de 85 na primeira faixa não muda conta nenhuma — de 86 pra cima a
 * tabela por OVR já paga 16M ou mais, então o piso nunca ia pegar ali. Está
 * escrito assim porque foi assim que a regra foi definida, e um limite
 * explícito é melhor que um que existe por coincidência da tabela.
 *
 * A 4ª rodada é a única que vai até os 24 anos. Nas outras o corte é 23.
 */
const CAP_PISO_DRAFT_INICIAL = [
    ['rodadas' => [1, 2], 'ovr_min' => 78, 'ovr_max' => 85, 'idade_max' => 23, 'valor' => 16],
    ['rodadas' => [2],    'ovr_min' => 76, 'ovr_max' => 77, 'idade_max' => 23, 'valor' => 10],
    ['rodadas' => [3],    'ovr_min' => 76, 'ovr_max' => 99, 'idade_max' => 23, 'valor' => 10],
    ['rodadas' => [4],    'ovr_min' => 76, 'ovr_max' => 99, 'idade_max' => 24, 'valor' => 6],
];

/**
 * Quanto o piso cobra deste jogador, ou 0 se ele não se encaixa em faixa
 * nenhuma.
 *
 * Depende de `initdraft_round`, que NÃO existe na tabela players — a migração
 * que tirou esses jogadores da rookie scale apagou as colunas de draft deles.
 * Quem preenche é capMarcarDraftInicial(), chamada em lote antes do cálculo.
 * Sem a marca, devolve 0: o pior caso é o comportamento de antes, não um
 * salário errado.
 */
function capPisoDraftInicial(array $player): int
{
    $round = $player['initdraft_round'] ?? null;
    $idade = $player['age'] ?? null;
    if ($round === null || $idade === null) return 0;

    $round = (int)$round;
    $idade = (int)$idade;
    $ovr   = (int)($player['ovr'] ?? 0);

    $piso = 0;
    foreach (CAP_PISO_DRAFT_INICIAL as $faixa) {
        if (!in_array($round, $faixa['rodadas'], true)) continue;
        if ($ovr < $faixa['ovr_min'] || $ovr > $faixa['ovr_max']) continue;
        if ($idade > $faixa['idade_max']) continue;
        $piso = max($piso, $faixa['valor']);
    }
    return $piso;
}

/**
 * Marca, em lote, de que rodada do Draft Inicial cada jogador veio.
 *
 * Em lote e não um a um porque isto roda pra elenco inteiro em toda tela de
 * cap — uma consulta por jogador seria quinze por time.
 *
 * O casamento é por NOME dentro da liga: é o que sobrou depois que a migração
 * limpou draft_round/draft_pick_position desses jogadores. Jogador renomeado
 * depois do draft escapa, e aí ele simplesmente não recebe o piso.
 */
function capMarcarDraftInicial(PDO $pdo, array &$players, string $league): void
{
    foreach ($players as &$p) $p['initdraft_round'] = null;
    unset($p);
    if (!$players) return;

    // Só ELITE. O salary cap inteiro é dela — nas outras ligas o teto é soma
    // de OVR, e um piso em milhões ali não significaria nada. Sem esta linha,
    // marcar a rodada numa liga de OVR seria trabalho jogado fora no melhor
    // caso, e número errado se algum dia alguém ligasse o modo salary lá.
    if (strtoupper(trim($league)) !== 'ELITE') return;

    try {
        $st = $pdo->prepare("
            SELECT ip.name, MIN(io.round) AS round
            FROM initdraft_order io
            JOIN initdraft_sessions s ON s.id = io.initdraft_session_id
            JOIN initdraft_pool ip     ON ip.id = io.picked_player_id
            WHERE s.league = ? AND io.picked_player_id IS NOT NULL
            GROUP BY ip.name
        ");
        $st->execute([strtoupper(trim($league))]);
        $porNome = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $porNome[mb_strtolower(trim((string)$r['name']))] = (int)$r['round'];
        }
        if (!$porNome) return;

        foreach ($players as &$p) {
            $chave = mb_strtolower(trim((string)($p['name'] ?? '')));
            if (isset($porNome[$chave])) $p['initdraft_round'] = $porNome[$chave];
        }
        unset($p);
    } catch (Throwable $e) {
        // Liga sem draft inicial não tem as tabelas. Segue sem piso.
        error_log('[cap] marcar draft inicial: ' . $e->getMessage());
    }
}

/**
 * O jogador é calouro NESTA temporada? Só nesse caso vale a rookie scale
 * ("VALE SÓ NO ANO 1" no regulamento).
 *
 * DUAS COISAS IMPORTANTES AQUI:
 *
 * 1. O gatilho era `seasons_in_league === 0`, mas essa coluna nasce 0 e NUNCA é
 *    incrementada em lugar nenhum do sistema — nenhuma virada de temporada mexe
 *    nela. Resultado: todo jogador com draft_round ficava eternamente na rookie
 *    scale.
 *
 * 2. O DRAFT INICIAL NÃO É DRAFT DE CALOURO. Ele é um draft de várias rodadas
 *    só pra distribuir os elencos no começo da edição, e grava draft_round /
 *    draft_pick_position em todo mundo (o "Voltar Pick" depende dessas colunas,
 *    por isso elas continuam sendo gravadas). Juntando com o item 1, qualquer
 *    jogador pego da 2ª rodada em diante caía no `return 2` do
 *    capRookieScaleValue — era o "86 aparecendo com 2M". Esses jogadores são
 *    atletas normais e seguem a tabela por OVR.
 *
 * O que separa um do outro é o drafted_season_number: o draft anual
 * (api/draft.php) sempre grava a temporada do draft, o Draft Inicial nunca.
 * Sem essa informação, o jogador é tratado como atleta normal — que é a regra
 * geral do regulamento ("O salário de todo jogador é definido exclusivamente
 * pelo overall").
 */
function capEhCalouroNaTemporadaAtual(array $player, ?int $temporadaAtual): bool
{
    if (($player['draft_round'] ?? null) === null) return false;
    $draftadoEm = $player['drafted_season_number'] ?? null;
    if ($draftadoEm === null || $temporadaAtual === null) return false;  // Draft Inicial cai aqui
    return (int)$draftadoEm === (int)$temporadaAtual;
}

/**
 * Salário base do jogador: rookie scale só na temporada de estreia dele,
 * tabela de OVR em qualquer outro caso.
 */
/** Piso do contrato de lenda. Vale mais que a tabela por OVR até o 94 (que dá exatamente 40M). */
const CAP_LENDA_MINIMO_MILLIONS = 40;

function getPlayerBaseSalary(array $player, ?int $temporadaAtual = null): int
{
    $ovr = (int)($player['ovr'] ?? 0);

    // Lenda da franquia: contrato de no mínimo 40M, acima de qualquer outra
    // régua — inclusive da rookie scale. Passando de 94 de OVR a tabela normal
    // já paga mais que isso e volta a valer sozinha (95 = 44M), então um max()
    // resolve os dois casos sem if de faixa.
    if (!empty($player['is_lenda'])) {
        return max(CAP_LENDA_MINIMO_MILLIONS, capOvrSalary($ovr));
    }

    if (capEhCalouroNaTemporadaAtual($player, $temporadaAtual)) {
        $pick = isset($player['draft_pick_position']) ? (int)$player['draft_pick_position'] : null;
        // Calouro sem posição de pick registrada não vira "mínimo do veterano":
        // sem essa informação a régua por OVR é mais justa do que fixar 2M num
        // jogador de 86. O piso da rookie scale (2M) só vale pra 2ª rodada, que
        // é identificada pelo próprio draft_round.
        if ($pick === null && (int)$player['draft_round'] < 2) {
            return capOvrSalary($ovr);
        }
        return capRookieScaleValue((int)$player['draft_round'], $pick);
    }

    // O piso do Draft Inicial entra por último, como max(): ele levanta quem a
    // tabela cobraria pouco e não encosta em quem já custa mais.
    return max(capOvrSalary($ovr), capPisoDraftInicial($player));
}

/**
 * Cap Flex: só se aplica enquanto o jogador está no time que o draftou
 * (drafted_by_team_id == team_id) e o OVR está nas faixas elegíveis.
 * Aumenta o Cap Máximo da franquia — não o salário do jogador.
 */
function getPlayerCapFlex(array $player): int
{
    $teamId = (int)($player['team_id'] ?? 0);
    $draftedBy = $player['drafted_by_team_id'] ?? null;
    if ($draftedBy === null || (int)$draftedBy !== $teamId) {
        return 0;
    }
    $ovr = (int)($player['ovr'] ?? 0);
    if ($ovr >= 93) return 8;
    if ($ovr >= 90) return 5;
    if ($ovr >= 85) return 3;
    return 0;
}

/**
 * Tabela de bônus por prêmio individual (em milhões), conforme o deck FBA Elite 15.
 * Todos são lidos de season_awards por award_type e valem só pela temporada
 * seguinte ao prêmio (bônus temporário — ver getAwardBonusesByPlayerName).
 * Cadastro: prêmios simples no "Registro de Pontuação"; Finals MVP / All-NBA /
 * All-Defensive na tela de prêmios estendidos (admin).
 */
function capAwardBonusTable(): array
{
    return [
        'mvp' => 5,
        'dpoy' => 3,
        'roy' => 2,
        'mip' => 2,
        '6th_man' => 2,
        'finals_mvp' => 3,
        // All-NBA (1º/2º/3º time) e All-Defensive (1º/2º time). Cada time premia 5 jogadores.
        'all_nba_1' => 3, 'all_nba_2' => 2, 'all_nba_3' => 1,
        'all_def_1' => 2, 'all_def_2' => 1,
    ];
}

/**
 * Bônus de prêmio: vale só pela temporada seguinte à que o prêmio foi registrado,
 * depois some. Como nada é armazenado, isso é automático — a cada chamada,
 * olhamos só os prêmios da temporada imediatamente anterior à ativa.
 * Retorna um mapa "nome do jogador em minúsculas" => soma de bônus (milhões).
 */
function getAwardBonusesByPlayerName(PDO $pdo, int $teamId, string $league): array
{
    $bonuses = [];
    try {
        $stmtCurrent = $pdo->prepare("
            SELECT s.season_number
            FROM seasons s
            WHERE s.league = ? AND (s.status IS NULL OR s.status NOT IN ('completed'))
            ORDER BY s.created_at DESC LIMIT 1
        ");
        $stmtCurrent->execute([$league]);
        $currentSeasonNumber = $stmtCurrent->fetchColumn();
        if ($currentSeasonNumber === false) {
            return $bonuses;
        }
        $priorSeasonNumber = (int)$currentSeasonNumber - 1;
        if ($priorSeasonNumber < 1) {
            return $bonuses;
        }

        $stmtPriorSeason = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND season_number = ? ORDER BY created_at DESC LIMIT 1");
        $stmtPriorSeason->execute([$league, $priorSeasonNumber]);
        $priorSeasonId = $stmtPriorSeason->fetchColumn();
        if (!$priorSeasonId) {
            return $bonuses;
        }

        $bonusTable = capAwardBonusTable();
        $stmtAwards = $pdo->prepare("SELECT award_type, player_name FROM season_awards WHERE season_id = ? AND team_id = ?");
        $stmtAwards->execute([(int)$priorSeasonId, $teamId]);
        foreach ($stmtAwards->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $bonus = $bonusTable[$row['award_type']] ?? 0;
            if ($bonus <= 0) continue;
            $key = mb_strtolower(trim((string)$row['player_name']));
            if ($key === '') continue;
            $bonuses[$key] = ($bonuses[$key] ?? 0) + $bonus;
        }
    } catch (Exception $e) {
        return $bonuses;
    }
    return $bonuses;
}

/**
 * Resumo completo de cap de um time ELITE: folha salarial, cap flex, cap máximo,
 * espaço disponível, status, e o detalhamento por jogador.
 */
function getTeamCapSummary(PDO $pdo, int $teamId): array
{
    $stmtTeam = $pdo->prepare("SELECT id, league FROM teams WHERE id = ?");
    $stmtTeam->execute([$teamId]);
    $team = $stmtTeam->fetch(PDO::FETCH_ASSOC);
    $league = $team['league'] ?? 'ELITE';

    $awardBonuses = getAwardBonusesByPlayerName($pdo, $teamId, $league);

    // Temporada ativa da liga: define quem é calouro (rookie scale) e se o Cap
    // Flex já vale (ver capFlexLiberado).
    $temporada = temporadaAtivaDaLiga($pdo, $league);
    $numTemporada = $temporada ? (int)$temporada['season_number'] : null;
    $flexLiberado = capFlexLiberado($pdo, $league, $numTemporada);

    $stmtPlayers = $pdo->prepare("
        SELECT id, name, team_id, ovr, age, seasons_in_league, drafted_by_team_id, drafted_season_number,
               draft_round, draft_pick_position,
               COALESCE(is_lenda, 0) as is_lenda,
               COALESCE(was_traded, 0) as was_traded
        FROM players WHERE team_id = ? ORDER BY ovr DESC
    ");
    $stmtPlayers->execute([$teamId]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);
    markLoyaltyEligibility($pdo, $players); // preenche is_loyal / cap_bonus_eligible
    capMarcarDraftInicial($pdo, $players, (string)$league); // preenche initdraft_round

    $payroll = 0;
    $roster = [];
    foreach ($players as $p) {
        $baseSalary = getPlayerBaseSalary($p, $numTemporada);
        $isRookieScale = capEhCalouroNaTemporadaAtual($p, $numTemporada);
        $bonus = $awardBonuses[mb_strtolower(trim((string)$p['name']))] ?? 0;
        $flex = $flexLiberado ? getPlayerCapFlex($p) : 0;

        $payroll += $baseSalary + $bonus;

        $roster[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'ovr' => (int)$p['ovr'],
            'base_salary' => $baseSalary,
            // A lenda ignora a rookie scale: o piso de 40M vale por cima dela.
            'is_rookie_scale' => $isRookieScale && empty($p['is_lenda']),
            'is_lenda' => !empty($p['is_lenda']),
            'award_bonus' => $bonus,
            'total_salary' => $baseSalary + $bonus,
            'cap_flex_eligible' => $flex > 0,
            'cap_flex_value' => $flex,
            'cap_flex_counted' => false,
            'is_on_draft_team' => $p['drafted_by_team_id'] !== null && (int)$p['drafted_by_team_id'] === (int)$p['team_id'],
            // Leal e Lenda coexistem, mas os benefícios de cap NÃO se somam: com a
            // tag de lenda o jogador já vale no mínimo 40M, então o +8M da lealdade
            // é anulado. A tag "Leal" continua aparecendo — só o bônus some.
            'is_loyal' => !empty($p['is_loyal']),
            'loyalty_bonus_eligible' => !empty($p['cap_bonus_eligible']) && empty($p['is_lenda']),
            'loyalty_bonus_counted' => false,
        ];
    }

    // O Cap Flex vale para no máximo CAP_FLEX_MAX_PLAYERS jogadores. Quando o
    // elenco tem mais elegíveis, contam os de maior flex (desempate pelo OVR).
    $elegiveis = [];
    foreach ($roster as $i => $r) {
        if ($r['cap_flex_value'] > 0) $elegiveis[$i] = $r;
    }
    uasort($elegiveis, function ($a, $b) {
        return ($b['cap_flex_value'] <=> $a['cap_flex_value']) ?: ($b['ovr'] <=> $a['ovr']);
    });

    $capFlexTotal = 0;
    $contados = 0;
    foreach ($elegiveis as $i => $r) {
        if ($contados >= CAP_FLEX_MAX_PLAYERS) break;
        $roster[$i]['cap_flex_counted'] = true;
        $capFlexTotal += $r['cap_flex_value'];
        $contados++;
    }

    // Bônus de Lealdade: até CAP_LOYALTY_MAX_PLAYERS jogadores elegíveis somam
    // CAP_LOYALTY_BONUS_MILLIONS cada no Cap Máximo (desempate pelo OVR).
    //
    // Nota de regra: o deck da FBA Elite 15 fala em teto de 205M indo até 221M (só o
    // Cap Flex). Dois motivos pra o número de hoje não bater com o do documento, e
    // nenhum é bug:
    //
    //   1. A base deixou de ser fixa — vem do "CAP Máximo" que o admin configura
    //      na Central da Liga (capBaseEFloorDaLiga). O deck virou o padrão de quem
    //      nunca preencheu o campo.
    //   2. O Bônus de Lealdade é regra da liga, acrescentada depois do deck: com
    //      dois leais no elenco, o teto sobe mais 16M por cima de tudo.
    $loyalElegiveis = [];
    foreach ($roster as $i => $r) {
        if (!empty($r['loyalty_bonus_eligible'])) $loyalElegiveis[$i] = $r;
    }
    uasort($loyalElegiveis, fn($a, $b) => $b['ovr'] <=> $a['ovr']);

    $capLoyaltyTotal = 0;
    $loyalContados = 0;
    foreach ($loyalElegiveis as $i => $r) {
        if ($loyalContados >= CAP_LOYALTY_MAX_PLAYERS) break;
        $roster[$i]['loyalty_bonus_counted'] = true;
        $capLoyaltyTotal += CAP_LOYALTY_BONUS_MILLIONS;
        $loyalContados++;
    }

    $baseFloor = capBaseEFloorDaLiga($pdo, (string)$league);
    $capMax = $baseFloor['base'] + $capFlexTotal + $capLoyaltyTotal;
    $space = $capMax - $payroll;
    $status = 'dentro_do_cap';
    if ($payroll > $capMax) {
        $status = 'over_the_cap';
    } elseif ($payroll < $baseFloor['floor']) {
        $status = 'abaixo_do_piso';
    }

    return [
        'team_id' => $teamId,
        'league' => $league,
        'cap_base' => $baseFloor['base'],
        'cap_floor' => $baseFloor['floor'],
        'cap_flex_total' => $capFlexTotal,
        'cap_flex_max_players' => CAP_FLEX_MAX_PLAYERS,
        'cap_flex_used_slots' => $contados,
        'cap_flex_eligible_count' => count($elegiveis),
        'cap_loyalty_total' => $capLoyaltyTotal,
        'cap_loyalty_max_players' => CAP_LOYALTY_MAX_PLAYERS,
        'cap_loyalty_used_slots' => $loyalContados,
        'cap_loyalty_eligible_count' => count($loyalElegiveis),
        'cap_loyalty_bonus_millions' => CAP_LOYALTY_BONUS_MILLIONS,
        'cap_max' => $capMax,
        'payroll' => $payroll,
        'space' => $space,
        'status' => $status,
        'roster' => $roster,
    ];
}

/**
 * Sugestões práticas de como o time pode se ajustar ao cap, conforme o status.
 * Retorna lista de ['type' => ok|danger|warn|info|tip, 'text' => '...'].
 */
function getCapSuggestions(array $summary): array
{
    $out = [];
    $payroll = (int)$summary['payroll'];
    $capMax = (int)$summary['cap_max'];
    $floor = (int)$summary['cap_floor'];
    $space = (int)$summary['space'];
    $roster = $summary['roster'] ?? [];

    $sorted = $roster;
    usort($sorted, fn($a, $b) => (int)$b['total_salary'] <=> (int)$a['total_salary']);

    if ($summary['status'] === 'over_the_cap') {
        $excess = $payroll - $capMax;
        $out[] = ['type' => 'danger', 'text' => "Você está {$excess}M acima do teto ({$capMax}M). É preciso reduzir esse valor em salário até a Trade Deadline."];

        // Menor jogador que sozinho cobre o excesso.
        $single = null;
        foreach (array_reverse($sorted) as $p) {
            if ((int)$p['total_salary'] >= $excess) { $single = $p; break; }
        }
        if ($single) {
            $out[] = ['type' => 'info', 'text' => "Negociar {$single['name']} ({$single['total_salary']}M) já resolveria sozinho — troque por picks ou por um jogador mais barato."];
        }

        $top = array_slice($sorted, 0, 3);
        if ($top) {
            $names = implode(', ', array_map(fn($p) => "{$p['name']} ({$p['total_salary']}M)", $top));
            $out[] = ['type' => 'info', 'text' => "Maiores salários do elenco: {$names}."];
        }
        $out[] = ['type' => 'tip', 'text' => "Numa troca, você pode receber no máximo 120% do salário que enviar — mande mais salário do que recebe para abrir espaço."];
    } elseif ($summary['status'] === 'abaixo_do_piso') {
        $need = $floor - $payroll;
        $out[] = ['type' => 'warn', 'text' => "Você está {$need}M abaixo do piso ({$floor}M). Após a Trade Deadline, todo time precisa alcançar o piso salarial."];
        $out[] = ['type' => 'info', 'text' => "Para subir a folha: contrate um agente livre, faça uma troca recebendo mais salário do que envia, ou suba o OVR de jogadores do elenco."];
        $out[] = ['type' => 'tip', 'text' => "Jogadores de 77 OVR ou menos custam só 2M (mínimo de veterano) — para somar folha, priorize subir OVR ou trazer nomes mais caros."];
    } else {
        $out[] = ['type' => 'ok', 'text' => "Dentro do teto, com {$space}M de espaço disponível."];
        if ($space > 0) {
            $out[] = ['type' => 'tip', 'text' => "Você pode absorver até {$space}M em salário numa troca sem estourar o teto."];
        }
        $out[] = ['type' => 'tip', 'text' => "Mantenha no elenco os jogadores que você mesmo draftou (85+ OVR) — eles somam Cap Flex e aumentam o seu teto."];
    }

    return $out;
}

/**
 * Casamento salarial de troca (regra dos 120%).
 *
 * Vale para TODA troca, tendo o time espaço no cap ou não: nenhum lado pode
 * receber mais de 120% do que envia. Quem manda 10M recebe no máximo 12M.
 * (Receber menos do que envia nunca é problema — a trava só pega quem recebe
 * a mais.)
 *
 * @param int $payrollAtual folha do time antes da troca (só informativo aqui)
 * @param int $capMax       teto do time (só informativo aqui)
 * @param int $enviado      soma dos salários que saem
 * @param int $recebido     soma dos salários que entram
 */
function checkTradeSalaryMatch(int $payrollAtual, int $capMax, int $enviado, int $recebido): array
{
    $folhaDepois = $payrollAtual - $enviado + $recebido;
    $limite = (int)floor($enviado * (CAP_TRADE_MATCH_PCT / 100));

    $base = [
        'enviado'        => $enviado,
        'recebido'       => $recebido,
        'folha_depois'   => $folhaDepois,
        'cap_max'        => $capMax,
        'limite_receber' => $limite,
        'pct'            => CAP_TRADE_MATCH_PCT,
        'aplica'         => true,
    ];

    if ($recebido <= $limite) {
        return $base + [
            'ok'      => true,
            'motivo'  => "Recebe {$recebido}M enviando {$enviado}M — dentro do limite de {$limite}M (" . CAP_TRADE_MATCH_PCT . "% de {$enviado}M).",
            'excesso' => 0,
        ];
    }

    // Estourou: diz de quanto é o excesso e o que faria a troca passar.
    $excesso = $recebido - $limite;
    $envioMinimo = (int)ceil($recebido / (CAP_TRADE_MATCH_PCT / 100));
    return $base + [
        'ok'            => false,
        'excesso'       => $excesso,
        'envio_minimo'  => $envioMinimo,
        'falta_enviar'  => max(0, $envioMinimo - $enviado),
        'motivo'        => "Recebe {$recebido}M enviando {$enviado}M — o limite é {$limite}M (" . CAP_TRADE_MATCH_PCT . "%). Excesso de {$excesso}M.",
    ];
}
