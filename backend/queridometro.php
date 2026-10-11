<?php
/**
 * Queridômetro da temporada, por liga — cada GM escolhe outro time pra cada
 * categoria (MVP, MIP, Fraco, Cobra, Planta), sem repetir time entre elas.
 * Um voto por time POR TEMPORADA (chave = id da temporada ativa da liga): o
 * popup só aparece uma vez por temporada e o quadro da temporada nova já
 * nasce limpo, porque tudo aqui é filtrado pela chave.
 *
 * A chave da temporada não é só cosmética — é ela que impede um segundo voto,
 * e é ela que zera o placar na virada. Nada é apagado: o histórico fica, e é
 * dele que sai a regra do intervalo (@see queridometroBloqueados) — não dá
 * pra votar no mesmo GM em duas temporadas seguidas.
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
        // A chave AIR_BALL segue com esse nome de propósito: é o que está
        // gravado nos votos. Trocá-la exigiria migrar querido_votos sem ganho
        // nenhum — o que a liga vê é o rótulo.
        'AIR_BALL' => 'Fraco',
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
        'AIR_BALL' => 'O pior GM da temporada — decisão errada atrás de decisão errada.',
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

/**
 * Zera o placar da liga — no avanço de temporada e no fim de sprint.
 *
 * ── ELE NÃO APAGA MAIS NADA ──────────────────────────────────────────
 *
 * Apagava: `DELETE FROM querido_votos WHERE league = ?`. E era um DELETE
 * inútil, porque quem zera o quadro é a CHAVE DA TEMPORADA — o placar e o
 * "já votou" filtram por `season_key`, então a temporada nova sempre nasce
 * limpa, com ou sem faxina. O próprio comentário no topo deste arquivo já
 * dizia isso.
 *
 * Inútil e caro: era ele que impedia a regra do intervalo de uma temporada
 * (@see queridometroBloqueados). Sem o voto do ano passado no banco, não há
 * como saber em quem você votou no ano passado. O pedido do Marcos
 * (10/10/2026) — "votei no anderson pra fraco em uma, na próxima eu não
 * posso, somente na outra" — só existe se o histórico existir.
 *
 * A função fica porque os dois pontos de virada a chamam (@see
 * api/seasons.php), e porque "zerar o queridômetro" continua sendo uma
 * intenção legítima — ela só não precisa de DELETE pra cumprir.
 */
function resetQueridometroDaLiga(PDO $pdo, string $league): void
{
    /* Nada a fazer: a virada de temporada muda a season_key e o quadro da
       nova nasce vazio sozinho. Apagar aqui destruiria o histórico que a
       regra do intervalo precisa ler. */
}

/**
 * A chave da temporada ANTERIOR da liga, ou '' se não houver.
 *
 * A corrente é a última temporada não encerrada; a anterior é a de id
 * imediatamente abaixo dela. Sem temporada aberta (a chave corrente cai em
 * 'S0'), a "anterior" é simplesmente a última que existiu — é o intervalo
 * entre ciclos, e lá o que valeu por último continua valendo.
 */
function queridometroSeasonKeyAnterior(PDO $pdo, string $league): string
{
    try {
        $league = strtoupper($league);
        $atual = (int)ltrim(queridometroSeasonKey($pdo, $league), 'S');
        if ($atual > 0) {
            $st = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND id < ? ORDER BY id DESC LIMIT 1");
            $st->execute([$league, $atual]);
        } else {
            $st = $pdo->prepare("SELECT id FROM seasons WHERE league = ? ORDER BY id DESC LIMIT 1");
            $st->execute([$league]);
        }
        $id = (int)($st->fetchColumn() ?: 0);
        return $id > 0 ? 'S' . $id : '';
    } catch (Throwable $e) {
        error_log('[queridometroSeasonKeyAnterior] ' . $e->getMessage());
        return '';
    }
}

/**
 * EM QUEM ESTE GM NÃO PODE VOTAR AGORA: quem ele votou na temporada passada.
 *
 * Pedido do Marcos (10/10/2026): "tá dando pra votar 2 temporadas seguidas,
 * tipo eu votei no anderson pra fraco em uma, na próxima eu não posso,
 * somente na outra". O queridômetro vira perseguição quando o mesmo GM leva
 * o mesmo voto do mesmo votante todo ano — e, com trinta times na liga,
 * pular um ano não aperta ninguém: sobram vinte e nove.
 *
 * O bloqueio é POR TIME, não por categoria. Travar só "Anderson pra Fraco"
 * deixaria votar "Anderson pra Cobra" no ano seguinte, que é a mesma
 * perseguição com outro nome.
 *
 * @return array<int,string> id do time => nome do GM, pra tela explicar
 */
function queridometroBloqueados(PDO $pdo, string $league, int $teamId): array
{
    $anterior = queridometroSeasonKeyAnterior($pdo, $league);
    if ($anterior === '') return [];
    try {
        $league = strtoupper($league);
        $st = $pdo->prepare("SELECT DISTINCT v.voted_team_id, u.name
                               FROM querido_votos v
                               JOIN teams t ON t.id = v.voted_team_id
                          LEFT JOIN users u ON u.id = t.user_id
                              WHERE v.league = ? AND v.season_key = ? AND v.voter_team_id = ?");
        $st->execute([$league, $anterior, $teamId]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['voted_team_id']] = (string)($r['name'] ?? '');
        }
        if (!$out) return [];

        /* ── A REGRA NÃO PODE TRANCAR A VOTAÇÃO ───────────────────────────
           São cinco categorias e cinco nomes diferentes. Numa liga de trinta
           times isso é folgado: tira cinco, sobram vinte e quatro. Numa liga
           pequena — ou numa que esvaziou — bloquear deixaria menos candidatos
           que categorias, e o GM abriria o popup sem conseguir fechar voto
           nenhum, sem nada na tela explicando por quê.

           Aí o intervalo cede. Ele é um freio de convívio, e um freio que
           impede o carro de andar não serve. */
        $stN = $pdo->prepare("SELECT COUNT(*) FROM teams WHERE league = ? AND id <> ?");
        $stN->execute([$league, $teamId]);
        $candidatos = (int)$stN->fetchColumn() - count($out);
        if ($candidatos < count(queridometroCategorias())) return [];

        return $out;
    } catch (Throwable $e) {
        /* Banco sem a tabela, ou consulta falhando: não travar ninguém. A
           regra é um freio de convívio, não uma trava de integridade. */
        error_log('[queridometroBloqueados] ' . $e->getMessage());
        return [];
    }
}
