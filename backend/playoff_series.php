<?php
/**
 * Séries de playoff: quem enfrentou quem, quem passou e em quantos jogos.
 *
 * Antes o banco guardava só ONDE cada time parou (playoff_results.position).
 * Isso responde "quem foi campeão", mas não "o Coyotes já passou pelo Empire?"
 * — que é a pergunta que rende no grupo. A série guarda o confronto inteiro.
 *
 * O placar não é gravado: ele é DERIVADO do número de jogos. Numa melhor de 7 o
 * vencedor sempre chega a 4 vitórias, então 6 jogos é 4-2, 5 é 4-1, e assim por
 * diante. Guardar 4 e 2 separados abriria espaço pra alguém salvar 3-2, que não
 * existe.
 */

const PLAYOFF_MELHOR_DE = 7;   // vencedor precisa de 4 vitórias

/** As fases, da primeira à última. A ordem vale pra exibir e pra ordenar. */
function playoffFases(): array
{
    return [
        'r1'    => '1ª rodada',
        'r2'    => 'Semi de conferência',
        'cf'    => 'Final de conferência',
        'final' => 'Grande final',
    ];
}

function ensurePlayoffSeriesTable(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS playoff_series (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            league ENUM('ELITE','NEXT','RISE','ROOKIE') NOT NULL,
            fase ENUM('r1','r2','cf','final') NOT NULL,
            conferencia ENUM('LESTE','OESTE') NULL,
            team_a_id INT NOT NULL,
            team_b_id INT NOT NULL,
            winner_team_id INT NOT NULL,
            jogos TINYINT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_ps_season (season_id),
            INDEX idx_ps_a (team_a_id),
            INDEX idx_ps_b (team_b_id),
            INDEX idx_ps_win (winner_team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[playoff_series] criar tabela: ' . $e->getMessage());
    }
}

/**
 * Placar da série a partir do número de jogos. 6 jogos numa melhor de 7 é 4-2.
 * Retorna [vitóriasDoVencedor, vitóriasDoPerdedor] ou null se o número não fecha.
 */
function playoffPlacarPorJogos(int $jogos): ?array
{
    $paraVencer = intdiv(PLAYOFF_MELHOR_DE, 2) + 1;   // 4 numa melhor de 7
    if ($jogos < $paraVencer || $jogos > PLAYOFF_MELHOR_DE) return null;
    return [$paraVencer, $jogos - $paraVencer];
}

/**
 * Substitui as séries de uma temporada.
 *
 * Apaga e regrava de propósito: o admin edita o chaveamento inteiro de uma vez,
 * e casar linha a linha com o que já existe daria margem pra sobrar série de
 * uma versão anterior do bracket.
 *
 * @param array $series Cada item: fase, conferencia, team_a_id, team_b_id,
 *                      winner_team_id, jogos.
 * @return array ['salvas' => int, 'ignoradas' => array de motivos]
 */
function salvarPlayoffSeries(PDO $pdo, int $seasonId, string $league, array $series): array
{
    ensurePlayoffSeriesTable($pdo);

    $fases = array_keys(playoffFases());
    $validas = [];
    $ignoradas = [];

    foreach ($series as $s) {
        $fase = (string)($s['fase'] ?? '');
        $a = (int)($s['team_a_id'] ?? 0);
        $b = (int)($s['team_b_id'] ?? 0);
        $w = (int)($s['winner_team_id'] ?? 0);
        $j = (int)($s['jogos'] ?? 0);

        if (!in_array($fase, $fases, true)) { $ignoradas[] = "fase inválida: {$fase}"; continue; }
        if (!$a || !$b || $a === $b)        { $ignoradas[] = 'série sem os dois times'; continue; }
        // O vencedor tem que ser um dos dois — senão dá pra gravar um terceiro
        // time como campeão de uma série que ele não jogou.
        if ($w !== $a && $w !== $b)        { $ignoradas[] = 'vencedor não é um dos dois times'; continue; }
        if (playoffPlacarPorJogos($j) === null) { $ignoradas[] = "número de jogos inválido: {$j}"; continue; }

        $conf = strtoupper((string)($s['conferencia'] ?? ''));
        $validas[] = [$seasonId, $league, $fase, in_array($conf, ['LESTE','OESTE'], true) ? $conf : null, $a, $b, $w, $j];
    }

    // Quem chama pode já estar numa transação — os dois pontos que gravam
    // (save_history e register_pontuacao) abrem uma antes de chegar aqui, e o
    // PDO recusa transação aninhada. Só abro a minha quando sou o primeiro; do
    // contrário entro na de fora, que é o comportamento certo mesmo: se o
    // registro da temporada falhar depois, as séries voltam atrás junto.
    $minhaTransacao = !$pdo->inTransaction();
    if ($minhaTransacao) $pdo->beginTransaction();

    try {
        $pdo->prepare("DELETE FROM playoff_series WHERE season_id = ?")->execute([$seasonId]);
        $ins = $pdo->prepare("INSERT INTO playoff_series
            (season_id, league, fase, conferencia, team_a_id, team_b_id, winner_team_id, jogos)
            VALUES (?,?,?,?,?,?,?,?)");
        foreach ($validas as $v) $ins->execute($v);
        if ($minhaTransacao) $pdo->commit();
    } catch (Throwable $e) {
        if ($minhaTransacao) $pdo->rollBack();
        throw $e;
    }

    return ['salvas' => count($validas), 'ignoradas' => $ignoradas];
}

/**
 * Histórico de séries entre dois times. Só o que já foi jogado.
 *
 * Retorna ['a' => vitórias do A, 'b' => vitórias do B, 'series' => [...]].
 */
function playoffSeriesEntre(PDO $pdo, int $aId, int $bId, ?int $sprintId = null): array
{
    ensurePlayoffSeriesTable($pdo);
    $out = ['a' => 0, 'b' => 0, 'series' => []];

    try {
        $filtro = $sprintId ? ' AND s.sprint_id = ?' : '';
        $params = [$aId, $bId, $bId, $aId];
        if ($sprintId) $params[] = $sprintId;

        $st = $pdo->prepare("
            SELECT ps.fase, ps.team_a_id, ps.team_b_id, ps.winner_team_id, ps.jogos,
                   s.season_number, sp.sprint_number
            FROM playoff_series ps
            JOIN seasons s ON s.id = ps.season_id
            LEFT JOIN sprints sp ON sp.id = s.sprint_id
            WHERE ((ps.team_a_id = ? AND ps.team_b_id = ?)
                OR (ps.team_a_id = ? AND ps.team_b_id = ?)) {$filtro}
            ORDER BY sp.sprint_number DESC, s.season_number DESC
        ");
        $st->execute($params);

        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['winner_team_id'] === $aId) $out['a']++; else $out['b']++;
            $out['series'][] = $r;
        }
    } catch (Throwable $e) {
        error_log('[playoff_series] entre: ' . $e->getMessage());
    }

    return $out;
}
