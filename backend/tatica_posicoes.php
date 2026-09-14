<?php
/**
 * AS POSIÇÕES DO ELENCO NO "RETRATO" DA TÁTICA.
 *
 * O card de táticas do admin acende em vermelho o que o time mexeu desde o
 * último "Feito no jogo" (ou desde a virada de temporada / o fim da regular).
 * A posição do jogador não mora na tática — mora em `players`, e o GM muda
 * pela tela de Tática ou pelo Meu Elenco —, então ela entra no retrato à parte,
 * na chave `posicoes`: id do jogador => "PG" ou "PG/SG".
 *
 * "Era PG, virou PG/SG" conta como mudança: é o que o operacional precisa
 * aplicar no jogo. Retrato antigo sem `posicoes` não compara nada.
 */

/** "PG", ou "PG/SG" quando há secundária (diferente da principal). */
function taticaPosicaoTexto($principal, $secundaria): string
{
    $pri = strtoupper(trim((string)$principal));
    $sec = strtoupper(trim((string)$secundaria));
    return ($sec !== '' && $sec !== $pri) ? "{$pri}/{$sec}" : $pri;
}

/** @return array<string,string> id do jogador (string) => posição */
function taticaPosicoesDoTime(PDO $pdo, int $teamId): array
{
    $st = $pdo->prepare("SELECT id, position, secondary_position FROM players WHERE team_id = ?");
    $st->execute([$teamId]);
    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
        $saida[(string)$p['id']] = taticaPosicaoTexto($p['position'], $p['secondary_position']);
    }
    return $saida;
}

/**
 * A posição vai mudar: guarda a de ANTES nos retratos do time que ainda não
 * conhecem esse jogador. Os retratos antigos (de antes das posições existirem)
 * não têm `posicoes`, e sem isso o card nunca acendia — nem sobrescrevia nada,
 * só não tinha com o que comparar. Retrato que já tem o jogador fica como está:
 * mudar duas vezes continua comparando com o que está no jogo.
 */
function taticaGuardarPosicaoAnterior(PDO $pdo, int $teamId, int $playerId, string $antes): void
{
    if ($antes === '') return;
    try {
        $cols = $pdo->query("SHOW COLUMNS FROM team_tactics")->fetchAll(PDO::FETCH_COLUMN);
        $colunas = array_values(array_intersect(['snapshot_feito_json', 'snapshot_offs_json', 'snapshot_json'], $cols));
        if (!$colunas) return;
        $st = $pdo->prepare("SELECT slot, " . implode(', ', $colunas) . " FROM team_tactics WHERE team_id = ?");
        $st->execute([$teamId]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $linha) {
            foreach ($colunas as $col) {
                if (empty($linha[$col])) continue;
                $retrato = json_decode((string)$linha[$col], true);
                if (!is_array($retrato)) continue;
                $pos = is_array($retrato['posicoes'] ?? null) ? $retrato['posicoes'] : [];
                if (array_key_exists((string)$playerId, $pos)) continue;
                $pos[(string)$playerId] = $antes;
                $retrato['posicoes'] = $pos;
                $pdo->prepare("UPDATE team_tactics SET {$col} = ? WHERE team_id = ? AND slot = ?")
                    ->execute([json_encode($retrato, JSON_UNESCAPED_UNICODE), $teamId, $linha["slot"]]);
            }
        }
    } catch (Throwable $e) {
        error_log('taticaGuardarPosicaoAnterior: ' . $e->getMessage());
    }
}

/**
 * Algum jogador que estava no retrato está com outra posição agora?
 * Quem chegou depois do retrato (não está nele) não conta.
 */
function taticaPosicoesMudaram(?array $retrato, array $atuais): bool
{
    if (!is_array($retrato) || !isset($retrato['posicoes']) || !is_array($retrato['posicoes'])) return false;
    foreach ($atuais as $id => $pos) {
        if (array_key_exists((string)$id, $retrato['posicoes']) && (string)$retrato['posicoes'][(string)$id] !== (string)$pos) {
            return true;
        }
    }
    return false;
}

/**
 * LUGAR DO TITULAR NA QUADRA.
 *
 * O titular joga na posição principal ou, se o GM escalou ele ali pela quadra
 * do Meu Elenco, na secundária: Giannis (SF/PF) pode fechar o PF com um SF de
 * verdade ao lado. O lugar escolhido fica em players.lineup_slot; NULL é a
 * principal. Lugar que não bate mais com as posições (a secundária mudou
 * depois) volta a ser a principal. Quem monta o quinteto usa esta função.
 */
function taticaLugarEmQuadra(array $p): string
{
    $pri = strtoupper(trim((string)($p['position'] ?? '')));
    $sec = strtoupper(trim((string)($p['secondary_position'] ?? '')));
    $lugar = strtoupper(trim((string)($p['lineup_slot'] ?? '')));
    return ($lugar !== '' && ($lugar === $pri || $lugar === $sec)) ? $lugar : $pri;
}

/** Cria players.lineup_slot na primeira vez. Devolve se a coluna existe. */
function ensurePlayerLineupSlotColumn(PDO $pdo): bool
{
    static $ok = null;
    if ($ok !== null) return $ok;
    try {
        if ($pdo->query("SHOW COLUMNS FROM players LIKE 'lineup_slot'")->rowCount() === 0) {
            $pdo->exec("ALTER TABLE players ADD COLUMN lineup_slot VARCHAR(2) NULL DEFAULT NULL");
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('ensurePlayerLineupSlotColumn: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}
