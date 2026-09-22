<?php
/**
 * QUAIS PICKS DE UM TIME AINDA VALEM — a régua que o "copiar time" usa.
 *
 * O texto copiado vinha com picks de anos que já passaram. A causa não é um
 * bug só: eram QUATRO filtros diferentes, um por tela, e nenhum deles
 * perguntava o que importa.
 *
 *     my-roster.php   season_year >  $currentSeasonYear
 *     dashboard.php   season_year >= $copySeasonYear
 *     teams.php (2x)  season_year >= baseYear   (no JavaScript)
 *
 * Comparar com o ano corrente não responde à pergunta certa. A pick do ano
 * corrente vale enquanto o draft daquele ano não acontecer — é escolha que o
 * time ainda vai fazer — e deixa de valer no instante em que ele escolhe com
 * ela. Nenhum dos quatro sabia disso: ou cortavam o ano corrente inteiro
 * (escondendo pick que o time ainda tem), ou deixavam passar (mostrando pick
 * já gasta como patrimônio). Dependendo da tela, o mesmo time saía com listas
 * diferentes.
 *
 * Aqui a régua é uma só e olha o fato: a pick some quando foi USADA, não
 * quando o calendário virou. Quem sabe isso é a vaga do draft, e
 * picksJaUsadas() já responde — é a mesma régua que impede a Trade Machine de
 * negociar pick gasta.
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/picks_usadas.php';

/**
 * As picks que o time realmente tem pra mostrar ou negociar.
 *
 * @param int $anoCorrente ano da temporada em andamento na liga
 * @return array as linhas de `picks`, na ordem ano/rodada
 */
function picksVisiveisDoTime(PDO $pdo, int $teamId, int $anoCorrente): array
{
    if ($teamId <= 0) return [];
    try {
        require_once __DIR__ . '/pick_protection.php';
        $st = $pdo->prepare('SELECT p.id, p.season_year, p.round, orig.city, orig.name AS team_name,
                                    p.original_team_id, p.team_id, p.swap_type, p.protection
                               FROM picks p
                               JOIN teams orig ON orig.id = p.original_team_id
                              WHERE p.team_id = ?
                           ORDER BY CAST(p.season_year AS UNSIGNED) ASC, p.round ASC');
        $st->execute([$teamId]);
        $todas = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[picks] visiveis: ' . $e->getMessage());
        return [];
    }
    if (!$todas) return [];

    $usadas = picksJaUsadas($pdo);

    return array_values(array_filter($todas, function ($p) use ($usadas, $anoCorrente) {
        // Já escolheu com ela: não é mais patrimônio, seja de que ano for.
        if (isset($usadas[(int)$p['id']])) return false;
        /* Ano passado e sem uso registrado é sobra de draft antigo — some
           também. Sem este corte, uma liga com histórico carregaria dez anos
           de picks mortas no texto do "copiar time". */
        return (int)$p['season_year'] >= $anoCorrente;
    }));
}

/** O ano da temporada em andamento, que é a base da régua acima. */
function picksAnoCorrenteDaLiga(PDO $pdo, string $league): int
{
    require_once __DIR__ . '/helpers.php';
    $t = temporadaAtivaDaLiga($pdo, $league);
    return (int)($t['year'] ?? 0) ?: (int)date('Y');
}
