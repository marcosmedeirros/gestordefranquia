<?php
/**
 * Queridômetro semanal por liga — cada GM escolhe outro time pra cada
 * categoria (MVP, MIP, Air Ball, Cobra, Planta), sem repetir time entre elas.
 * Um voto por time por semana (chave = ano+semana ISO). O placar acumula a
 * temporada toda e só zera no avanço de temporada (api/seasons.php).
 *
 * Fica fora de api/ de propósito: api/queridometro.php e api/seasons.php
 * (que dispara o reset) usam essas funções como biblioteca compartilhada.
 */

function ensureQuerdometroTable(PDO $pdo): void
{
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS querido_votos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league VARCHAR(20) NOT NULL,
            week_key VARCHAR(10) NOT NULL,
            voter_team_id INT NOT NULL,
            voter_user_id INT NULL,
            category VARCHAR(20) NOT NULL,
            voted_team_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_voto (league, week_key, voter_team_id, category),
            INDEX idx_liga_semana (league, week_key),
            INDEX idx_voted (voted_team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {}
}

/** Categorias fixas do Queridômetro: chave interna => rótulo exibido. */
function queridometroCategorias(): array
{
    return [
        'MVP'      => 'MVP',
        'MIP'      => 'MIP',
        'AIR_BALL' => 'Air Ball',
        'COBRA'    => 'Cobra',
        'PLANTA'   => 'Planta',
    ];
}

/** Chave da semana atual (ano ISO + semana ISO), ex: "2026-31". */
function queridometroWeekKey(): string
{
    return date('o') . '-' . date('W');
}

/** Já votou essa semana? Voto é tudo-ou-nada (as 5 categorias de uma vez). */
function queridometroJaVotou(PDO $pdo, string $league, int $teamId, ?string $weekKey = null): bool
{
    $weekKey = $weekKey ?? queridometroWeekKey();
    $stmt = $pdo->prepare("SELECT 1 FROM querido_votos WHERE league = ? AND week_key = ? AND voter_team_id = ? LIMIT 1");
    $stmt->execute([$league, $weekKey, $teamId]);
    return (bool)$stmt->fetchColumn();
}

/** Top 3 (time + total de votos) de cada categoria, acumulado desde o último reset. */
function queridometroTop3(PDO $pdo, string $league): array
{
    $out = [];
    foreach (array_keys(queridometroCategorias()) as $cat) {
        $stmt = $pdo->prepare("
            SELECT t.id, t.name, t.city, t.photo_url, COUNT(*) AS votos
            FROM querido_votos v
            INNER JOIN teams t ON t.id = v.voted_team_id
            WHERE v.league = ? AND v.category = ?
            GROUP BY t.id, t.name, t.city, t.photo_url
            ORDER BY votos DESC, t.city ASC
            LIMIT 3
        ");
        $stmt->execute([$league, $cat]);
        $out[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    return $out;
}

/** Zera o placar da liga inteira — chamado no avanço de temporada. */
function resetQueridometroDaLiga(PDO $pdo, string $league): void
{
    try {
        $pdo->prepare("DELETE FROM querido_votos WHERE league = ?")->execute([$league]);
    } catch (Throwable $e) {
        error_log('[resetQueridometroDaLiga] ' . $e->getMessage());
    }
}
