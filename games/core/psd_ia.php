<?php
/**
 * ── FBA SHOWDOWN: O JOGADOR DE MÁQUINA ───────────────────────────────
 *
 * Serve a dois donos, e por isso mora aqui e não dentro de um deles:
 *
 *   TREINO  — o adversário de quem abre o jogo sem ninguém online.
 *   SIMULAÇÃO — os dois lados, quando o CLI roda mil partidas pra ver se o
 *               motor quebra (@see psd_simular_cli.php).
 *
 * ── O QUANTO ELA PENSA ───────────────────────────────────────────────
 *
 * Pouco, de propósito. Ela olha a tabela de tipos e escolhe o golpe que
 * mais machuca o que está na frente; troca quando o ativo cai; e não faz
 * leitura nenhuma do que o outro vai fazer. Dá partida decente contra
 * quem está aprendendo e perde pra quem já pegou o jeito — que é o papel
 * dela, já que o jogo de verdade é contra outro GM.
 *
 * O parâmetro $solta é quanto ela joga no chute, em porcentagem. O jogo
 * usa 0; a simulação usa 33, porque uma máquina que só escolhe o golpe
 * mais forte nunca usa status, e aí Substitute, Protect, Stealth Rock,
 * Leech Seed, cura e sono nunca rodariam no teste.
 */

require_once __DIR__ . '/psd_motor.php';

function psdIA(array &$e, string $lado, int $solta = 0): array
{
    $vivos = psdVivos($e, $lado);
    if (!$vivos) return ['tipo' => 'nada'];

    $meu = psdAtivo($e, $lado);

    /* Caiu: entra quem tem mais vida. Escolher por vantagem de tipo seria
       melhor, mas também seria a máquina sabendo o que o outro tem. */
    if ($meu['hp'] <= 0 || $e['lados'][$lado]['precisa_trocar']) {
        $melhor = $vivos[0]; $maisVida = -1;
        foreach ($vivos as $i) {
            $hp = $e['lados'][$lado]['time'][$i]['hp'];
            if ($hp > $maisVida) { $maisVida = $hp; $melhor = $i; }
        }
        return ['tipo' => 'trocar', 'i' => $melhor];
    }

    $outros = array_values(array_diff($vivos, [$e['lados'][$lado]['ativo']]));

    /* Troca por desespero: se o que está em quadra toma o dobro do golpe
       do tipo de quem está na frente e já está com pouca vida, sai. */
    if ($outros && $meu['hp'] < $meu['hpmax'] * 0.3 && psdChance($e, 35)) {
        return ['tipo' => 'trocar', 'i' => $outros[psdSorteio($e, 0, count($outros) - 1)]];
    }

    $comPP = [];
    foreach ($meu['golpes'] as $i => $g) if ($g['pp'] > 0) $comPP[] = $i;
    if (!$comPP) return ['tipo' => 'golpe', 'i' => 0];   // vira Struggle no motor

    if ($solta > 0 && psdChance($e, (float)$solta)) {
        return ['tipo' => 'golpe', 'i' => $comPP[psdSorteio($e, 0, count($comPP) - 1)]];
    }

    $alvo = psdAtivo($e, psdOutro($lado));
    $melhorI = $comPP[0]; $melhorNota = -1;

    foreach ($comPP as $i) {
        $mv = psdGolpe($meu['golpes'][$i]['id']);
        if (!$mv) continue;

        if ($mv['cat'] === 'status') {
            /* Status vale alguma coisa, mas só no começo: encher o ataque
               com o adversário quase morto é desperdiçar o turno que
               ganhava a partida. */
            $nota = ($alvo['hp'] > $alvo['hpmax'] * 0.6) ? 55 : 10;
        } else {
            $ef   = psdEficacia($mv['tipo'], $alvo['tipos']);
            $stab = in_array($mv['tipo'], $meu['tipos'], true) ? 1.5 : 1.0;
            $nota = $mv['power'] * $ef * $stab * ($mv['acc'] / 100);
        }

        if ($nota > $melhorNota) { $melhorNota = $nota; $melhorI = $i; }
    }

    return ['tipo' => 'golpe', 'i' => $melhorI];
}
