<?php
/**
 * Queridômetro da temporada, por liga — cada GM escolhe outro time pra cada
 * categoria (MVP, MIP, Air Ball, Cobra, Planta), sem repetir time entre elas.
 * Um voto por time POR TEMPORADA (chave = id da temporada ativa da liga): o
 * popup só aparece uma vez por temporada e o placar zera no avanço de
 * temporada e no fim de sprint (api/seasons.php).
 *
 * A chave da temporada não é só cosmética — é ela que impede um segundo voto.
 * O placar também é filtrado por ela, então mesmo se o reset falhar a
 * temporada nova já começa com o quadro limpo.
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
            season_key VARCHAR(16) NOT NULL,
            voter_team_id INT NOT NULL,
            voter_user_id INT NULL,
            category VARCHAR(20) NOT NULL,
            voted_team_id INT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_voto (league, season_key, voter_team_id, category),
            INDEX idx_liga_temporada (league, season_key),
            INDEX idx_voted (voted_team_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {}

    // Bancos que rodaram a versão semanal: a coluna se chamava week_key e
    // guardava "ano-semana". O CHANGE leva o índice UNIQUE junto. Os votos
    // antigos ficam com chave de semana, que nunca casa com a de temporada —
    // ou seja, ninguém fica travado sem poder votar, e o placar já ignora eles
    // porque o top3 filtra pela chave da temporada corrente.
    static $migrado = false;
    if (!$migrado && !$pdo->inTransaction()) {
        $migrado = true;
        try {
            if ($pdo->query("SHOW COLUMNS FROM querido_votos LIKE 'week_key'")->fetch()) {
                $pdo->exec("ALTER TABLE querido_votos CHANGE COLUMN week_key season_key VARCHAR(16) NOT NULL");
            }
        } catch (Throwable $e) {
            error_log('[ensureQuerdometroTable] migrar week_key: ' . $e->getMessage());
        }
    }
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

/** Descrição de cada categoria, pro tooltip no card e no popup de voto. */
function queridometroDescricoes(): array
{
    return [
        'MVP'      => 'O melhor GM da temporada — quem mais se destacou.',
        'MIP'      => 'Most Improved: quem mais evoluiu nesta temporada.',
        'AIR_BALL' => 'O pior GM da temporada — decisão errada, jogada furada.',
        'COBRA'    => 'Não dá pra confiar — promete e não cumpre, trai combinado.',
        'PLANTA'   => 'Sumido — não participa, não responde, não faz nada na liga.',
    ];
}

/**
 * Chave da temporada corrente da liga, ex: "S120".
 *
 * Sem temporada aberta (entre um ciclo e outro), cai em "S0" — todo mundo
 * compartilha a mesma chave nesse intervalo, então continua valendo um voto
 * por time.
 */
function queridometroSeasonKey(PDO $pdo, string $league): string
{
    try {
        $stmt = $pdo->prepare("SELECT id FROM seasons
                               WHERE league = ? AND (status IS NULL OR status <> 'completed')
                               ORDER BY id DESC LIMIT 1");
        $stmt->execute([strtoupper($league)]);
        return 'S' . (int)($stmt->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        error_log('[queridometroSeasonKey] ' . $e->getMessage());
        return 'S0';
    }
}

/** Já votou nesta temporada? Voto é tudo-ou-nada (as 5 categorias de uma vez). */
function queridometroJaVotou(PDO $pdo, string $league, int $teamId, ?string $seasonKey = null): bool
{
    $seasonKey = $seasonKey ?? queridometroSeasonKey($pdo, $league);
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM querido_votos WHERE league = ? AND season_key = ? AND voter_team_id = ? LIMIT 1");
        $stmt->execute([$league, $seasonKey, $teamId]);
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        // Banco ainda sem a tabela/coluna nova: não trava o GM.
        error_log('[queridometroJaVotou] ' . $e->getMessage());
        return false;
    }
}

/**
 * Top 3 (GM + total de votos) de cada categoria, na temporada corrente. O voto
 * é registrado no time (voted_team_id), mas exibido no GM dono dele — se o time
 * trocar de dono, o placar já acumulado passa a contar pro dono atual, sem
 * precisar migrar voto nenhum.
 */
function queridometroTop3(PDO $pdo, string $league, ?string $seasonKey = null): array
{
    $seasonKey = $seasonKey ?? queridometroSeasonKey($pdo, $league);
    $out = [];
    foreach (array_keys(queridometroCategorias()) as $cat) {
        try {
            $stmt = $pdo->prepare("
                SELECT u.id, u.name, u.photo_url, COUNT(*) AS votos
                FROM querido_votos v
                INNER JOIN teams t ON t.id = v.voted_team_id
                INNER JOIN users u ON u.id = t.user_id
                WHERE v.league = ? AND v.season_key = ? AND v.category = ?
                GROUP BY u.id, u.name, u.photo_url
                ORDER BY votos DESC, u.name ASC
                LIMIT 3
            ");
            $stmt->execute([$league, $seasonKey, $cat]);
            $out[$cat] = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            error_log('[queridometroTop3] ' . $e->getMessage());
            $out[$cat] = [];
        }
    }
    return $out;
}

/** Zera o placar da liga inteira — no avanço de temporada e no fim de sprint. */
function resetQueridometroDaLiga(PDO $pdo, string $league): void
{
    try {
        $pdo->prepare("DELETE FROM querido_votos WHERE league = ?")->execute([$league]);
    } catch (Throwable $e) {
        error_log('[resetQueridometroDaLiga] ' . $e->getMessage());
    }
}
