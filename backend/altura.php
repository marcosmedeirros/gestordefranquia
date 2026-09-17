<?php
/**
 * A ALTURA DO JOGADOR, no formato do jogo: pés e polegadas, 6'5".
 *
 * Quem digita escreve de todo jeito — 6'5, 6´5, 6’5”, 6-5, 6 5, 6.5, 65 — e o
 * banco guarda de um jeito só, pra toda tela mostrar igual. Em players.height.
 */

/** Garante players.height. DDL: chamar fora de transação. */
function alturaGarantirColuna(PDO $pdo): void
{
    static $feito = false;
    if ($feito || $pdo->inTransaction()) return;
    $feito = true;
    try {
        if (!$pdo->query("SHOW COLUMNS FROM players LIKE 'height'")->fetch()) {
            $pdo->exec("ALTER TABLE players ADD COLUMN height VARCHAR(8) NULL AFTER age");
        }
    } catch (Throwable $e) {
        error_log('[altura] coluna height: ' . $e->getMessage());
    }
}

/**
 * Normaliza pra 6'5". Vazio é válido e vira NULL (limpa a altura).
 *
 * Aceita de 5'0" a 8'11" — o jogo não tem jogador fora disso, e o limite é o
 * que separa "7'1" de alguém que digitou 71 querendo dizer outra coisa.
 * "65" (dois dígitos colados) é lido como 6'5"; "610" como 6'10".
 *
 * @return array{0: bool, 1: ?string} [válido, altura]
 */
function alturaNormalizar($bruto): array
{
    $t = trim((string)$bruto);
    if ($t === '') return [true, null];

    // Todo tipo de aspa e separador vira espaço; sobram só os números.
    $t = preg_replace("/[\\x{2019}\\x{2018}\\x{00B4}\\x{0060}'\\x{201D}\\x{201C}\"\\-\\.,\\/]+/u", ' ', $t);
    $t = trim(preg_replace('/\s+/', ' ', $t));

    if (preg_match('/^(\d)\s+(\d{1,2})$/', $t, $m)) {
        [$pes, $pol] = [(int)$m[1], (int)$m[2]];
    } elseif (preg_match('/^(\d)$/', $t, $m)) {
        [$pes, $pol] = [(int)$m[1], 0];
    } elseif (preg_match('/^(\d)(\d{1,2})$/', $t, $m)) {
        [$pes, $pol] = [(int)$m[1], (int)$m[2]];
    } else {
        return [false, null];
    }
    if ($pes < 5 || $pes > 8 || $pol > 11) return [false, null];
    return [true, "{$pes}'{$pol}\""];
}
