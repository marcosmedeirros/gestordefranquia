<?php
/**
 * EM QUE TEMPORADA A ESTATÍSTICA DEVE CAIR.
 *
 * Estatística nasce de jogo disputado. Só que a tela mandava tudo pra
 * "temporada em andamento", e em andamento inclui a que acabou de nascer e
 * ainda está no draft — ninguém jogou uma partida dela.
 *
 * Foi o que aconteceu na virada da T2 pra T3 da ELITE: a T3 nasceu, virou a
 * corrente, e quem foi lançar os números da T2 gravou 44 linhas na T3. A T2
 * ficou com 22 dos 32 times, e a T3 com estatística de jogo que não existiu.
 *
 * O MARCO É A CLASSIFICAÇÃO, e não o status da temporada. O status seria o
 * caminho óbvio, mas na prática ele não serve: das 64 temporadas do banco,
 * todas estão em `draft` ou `completed` — nenhuma passa por 'regular' ou
 * 'playoffs'. Uma regra baseada nele mandaria pra anterior pra sempre, e a
 * temporada nova nunca receberia lançamento nenhum.
 *
 * A classificação, sim, marca o momento exato: quando o admin define os
 * playoffs daquela temporada no card Pontuação, ela foi disputada. Antes
 * disso, o que se lança é da anterior.
 */

/**
 * A temporada que deve receber lançamento de estatística agora.
 *
 * Devolve também a corrente, pra tela poder dizer "estamos na T3, lançando na
 * T2" — sem isso o GM não tem como saber que as duas são diferentes.
 *
 * @return array{alvo:?array,corrente:?array,motivo:string}
 */
function statsTemporadaAlvo(PDO $pdo, string $liga): array
{
    $liga = strtoupper(trim($liga));
    try {
        // A corrente: a mais recente que não terminou.
        $st = $pdo->prepare("SELECT s.id, s.season_number, s.status, s.year, sp.start_year
                               FROM seasons s LEFT JOIN sprints sp ON sp.id = s.sprint_id
                              WHERE s.league = ? AND (s.status IS NULL OR s.status <> 'completed')
                           ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
        $st->execute([$liga]);
        $corrente = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        /* A mais recente JÁ DISPUTADA: a última com classificação definida,
           dentro da sprint que está rodando. Temporada de era passada não é o
           que ninguém está lançando hoje. */
        $st = $pdo->prepare("SELECT s.id, s.season_number, s.status, s.year, sp.start_year
                               FROM seasons s
                               JOIN sprints sp ON sp.id = s.sprint_id
                              WHERE s.league = ? AND sp.status = 'active'
                                AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
                           ORDER BY s.season_number DESC, s.id DESC LIMIT 1");
        $st->execute([$liga]);
        $disputada = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        if (!$disputada) {
            // Nenhuma temporada da sprint foi classificada ainda: só resta a
            // corrente — é a primeira, e não há anterior pra onde mandar.
            return ['alvo' => $corrente, 'corrente' => $corrente,
                    'motivo' => 'nenhuma temporada classificada ainda'];
        }

        if ($corrente && (int)$disputada['id'] === (int)$corrente['id']) {
            return ['alvo' => $corrente, 'corrente' => $corrente,
                    'motivo' => 'a temporada atual já foi classificada'];
        }

        return ['alvo' => $disputada, 'corrente' => $corrente,
                'motivo' => 'a temporada atual ainda não teve os playoffs definidos'];
    } catch (Throwable $e) {
        error_log('[stats] temporada alvo: ' . $e->getMessage());
        return ['alvo' => null, 'corrente' => null, 'motivo' => 'erro ao decidir a temporada'];
    }
}

/** "Temporada 2 · 2027", do jeito que a tela escreve. */
function statsRotuloTemporada(?array $s): string
{
    if (!$s) return '—';
    $n = (int)($s['season_number'] ?? 0);
    $ano = isset($s['start_year'], $s['season_number'])
        ? (int)$s['start_year'] + $n - 1
        : (int)($s['year'] ?? 0);
    return 'Temporada ' . $n . ($ano ? ' · ' . $ano : '');
}
