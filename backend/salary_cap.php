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

const CAP_BASE_MILLIONS = 205;
const CAP_FLOOR_MILLIONS = 180;

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
 * Salário base do jogador: rookie scale na temporada de estreia (seasons_in_league
 * = 0 e com draft_round conhecido), tabela de OVR em qualquer outro caso.
 */
function getPlayerBaseSalary(array $player): int
{
    $ovr = (int)($player['ovr'] ?? 0);
    $seasonsInLeague = (int)($player['seasons_in_league'] ?? 0);
    $draftRound = $player['draft_round'] ?? null;

    if ($seasonsInLeague === 0 && $draftRound !== null) {
        return capRookieScaleValue((int)$draftRound, isset($player['draft_pick_position']) ? (int)$player['draft_pick_position'] : null);
    }
    return capOvrSalary($ovr);
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
 * Tabela de bônus por prêmio individual (em milhões). Só os prêmios abaixo têm
 * fonte de dados hoje (season_awards, preenchida pelo card "Posições" / Registro
 * de Pontuação). Finais MVP, All-NBA e All-Defensivo estão documentados na
 * especificação original mas não têm nenhum formulário de registro no sistema
 * ainda — não é possível calcular esses bônus até essa tela existir.
 */
function capAwardBonusTable(): array
{
    return [
        'mvp' => 5,
        'dpoy' => 3,
        'roy' => 2,
        'mip' => 2,
        '6th_man' => 2,
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

    $stmtPlayers = $pdo->prepare("
        SELECT id, name, team_id, ovr, seasons_in_league, drafted_by_team_id, draft_round, draft_pick_position
        FROM players WHERE team_id = ? ORDER BY ovr DESC
    ");
    $stmtPlayers->execute([$teamId]);
    $players = $stmtPlayers->fetchAll(PDO::FETCH_ASSOC);

    $payroll = 0;
    $capFlexTotal = 0;
    $roster = [];
    foreach ($players as $p) {
        $baseSalary = getPlayerBaseSalary($p);
        $isRookieScale = (int)($p['seasons_in_league'] ?? 0) === 0 && $p['draft_round'] !== null;
        $bonus = $awardBonuses[mb_strtolower(trim((string)$p['name']))] ?? 0;
        $flex = getPlayerCapFlex($p);

        $payroll += $baseSalary + $bonus;
        $capFlexTotal += $flex;

        $roster[] = [
            'id' => (int)$p['id'],
            'name' => $p['name'],
            'ovr' => (int)$p['ovr'],
            'base_salary' => $baseSalary,
            'is_rookie_scale' => $isRookieScale,
            'award_bonus' => $bonus,
            'total_salary' => $baseSalary + $bonus,
            'cap_flex_eligible' => $flex > 0,
            'cap_flex_value' => $flex,
            'is_on_draft_team' => $p['drafted_by_team_id'] !== null && (int)$p['drafted_by_team_id'] === (int)$p['team_id'],
        ];
    }

    $capMax = CAP_BASE_MILLIONS + $capFlexTotal;
    $space = $capMax - $payroll;
    $status = 'dentro_do_cap';
    if ($payroll > $capMax) {
        $status = 'over_the_cap';
    } elseif ($payroll < CAP_FLOOR_MILLIONS) {
        $status = 'abaixo_do_piso';
    }

    return [
        'team_id' => $teamId,
        'league' => $league,
        'cap_base' => CAP_BASE_MILLIONS,
        'cap_floor' => CAP_FLOOR_MILLIONS,
        'cap_flex_total' => $capFlexTotal,
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
