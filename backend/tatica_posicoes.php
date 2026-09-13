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
