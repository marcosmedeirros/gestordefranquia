<?php
/**
 * IMPORTA AS CARTAS DO FBA HOOPS — roda na mão (ou no cron semanal).
 *
 *   php games/core/hoops_importar_cli.php              (busca tudo e gera)
 *   php games/core/hoops_importar_cli.php --offline    (só recalcula as notas
 *                                                       em cima do bruto já salvo)
 *   php games/core/hoops_importar_cli.php --amostra    (imprime cartas de conferência)
 *
 * São DOIS arquivos gerados, e a separação é o que deixa calibrar barato:
 *
 *   games/data/hoops_bruto.php   — o que as fontes devolveram, normalizado.
 *                                  Leva uns dois minutos pra buscar.
 *   games/data/hoops_cartas.php  — as cartas prontas, com OVR e notas. Sai do
 *                                  bruto em um segundo, então mexer numa
 *                                  constante de hoops_notas.php e rodar com
 *                                  --offline mostra o efeito na hora.
 *
 * Se uma fonte falhar, o bruto anterior DAQUELA LIGA é mantido: uma
 * EuroLeague fora do ar não pode apagar as cartas europeias.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/hoops_fontes.php';
require_once __DIR__ . '/hoops_notas.php';

$ARQ_BRUTO  = __DIR__ . '/../data/hoops_bruto.php';
$ARQ_CARTAS = __DIR__ . '/../data/hoops_cartas.php';

$offline = in_array('--offline', $argv, true);
$amostra = in_array('--amostra', $argv, true);

$bruto = is_file($ARQ_BRUTO) ? (require $ARQ_BRUTO) : ['ligas' => [], 'times' => []];

if (!$offline) {
    $buscas = [
        'NBA'        => fn() => hoopsEspnLiga('NBA'),
        'WNBA'       => fn() => hoopsEspnLiga('WNBA'),
        'EuroLeague' => fn() => hoopsEuroLiga('EuroLeague'),
        'EuroCup'    => fn() => hoopsEuroLiga('EuroCup'),
        'NBB'        => fn() => hoopsNbbLiga(),
    ];
    foreach ($buscas as $liga => $busca) {
        fwrite(STDOUT, str_pad($liga, 11) . ' … ');
        $r = $busca();
        if (count($r['jogadores']) < 50) {
            fwrite(STDOUT, "só " . count($r['jogadores']) . " jogadores — mantendo o bruto anterior\n");
            continue;
        }
        $bruto['ligas'][$liga] = $r['jogadores'];
        foreach ($r['times'] as $sigla => $t) $bruto['times'][$liga . '|' . $sigla] = $t;
        fwrite(STDOUT, count($r['jogadores']) . " jogadores, {$r['sem_stats']} sem estatística (fora)\n");
    }
    $bruto['gerado_em'] = date('Y-m-d H:i');
    file_put_contents($ARQ_BRUTO,
        "<?php\n/* Gerado por games/core/hoops_importar_cli.php — NÃO EDITE À MÃO. */\nreturn "
        . var_export($bruto, true) . ";\n");
}

// Mesmo nome em duas ligas (quem saiu da EuroLeague pra NBA, por exemplo,
// e ainda aparece no elenco velho de alguém) vira UMA carta: fica a da liga
// mais forte, que é onde ele está jogando de verdade.
$prioridade = ['NBA' => 0, 'WNBA' => 1, 'EuroLeague' => 2, 'EuroCup' => 3, 'NBB' => 4];
$todos = [];
foreach ($bruto['ligas'] as $liga => $lista) foreach ($lista as $j) {
    // A fonte às vezes escreve "Robinson - Earl": na carta o nome vai
    // inteiro, então o hífen com espaço sobrando aparece.
    $j['nome'] = trim(preg_replace(['/\s*-\s*/u', '/\s+/u'], ['-', ' '], $j['nome']));
    $todos[] = $j;
}
usort($todos, fn($a, $b) => $prioridade[$a['liga']] <=> $prioridade[$b['liga']]);
$vistos = [];
$todos = array_values(array_filter($todos, function ($j) use (&$vistos) {
    $chave = strtolower(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $j['nome']));
    if (isset($vistos[$chave])) return false;
    return $vistos[$chave] = true;
}));

$cartas = hoopsNotasCalcular($todos);

// O NBB: quem é só régua sai agora (já serviu pros percentis), e os 20
// escolhidos sobem até o piso de OVR deles (games/data/hoops_nbb.php).
$cartas = array_values(array_filter($cartas, fn($c) => empty($c['so_regua'])));
$pisos = array_column(require __DIR__ . '/../data/hoops_nbb.php', 'piso', 'nome');
foreach ($cartas as &$c) {
    if ($c['liga'] !== 'NBB' || !isset($pisos[$c['nome']]) || $c['ovr'] >= $pisos[$c['nome']]) continue;
    $delta = $pisos[$c['nome']] - $c['ovr'];
    $c['ovr'] = $pisos[$c['nome']];
    // Teto de OVR+10: quem jogou poucos minutos tem taxas por minuto altas,
    // e o empurrão do piso levava o Maicon Douglas (78) a 99 de rebote.
    foreach ($c['at'] as $k => $v) $c['at'][$k] = max(25, min(99, $c['ovr'] + 10, $v + (int)round($delta * 0.6)));
}
unset($c);

// A correção à mão (games/data/hoops_ajustes.php): o OVR vai pro valor
// pedido e as notas andam 60% disso, pra carta continuar coerente.
$ajustes = is_file(__DIR__ . '/../data/hoops_ajustes.php') ? (require __DIR__ . '/../data/hoops_ajustes.php') : [];
$achados = [];
foreach ($cartas as &$c) {
    if (!isset($ajustes[$c['nome']])) continue;
    $delta = (int)$ajustes[$c['nome']] - $c['ovr'];
    $c['ovr'] = (int)$ajustes[$c['nome']];
    foreach ($c['at'] as $k => $v) $c['at'][$k] = max(25, min(99, $v + (int)round($delta * 0.6)));
    $achados[$c['nome']] = true;
}
unset($c);
foreach (array_diff_key($ajustes, $achados) as $nome => $_) {
    fwrite(STDOUT, "AVISO: ajuste manual pra \"{$nome}\", mas não existe carta com esse nome.\n");
}
usort($cartas, fn($a, $b) => $b['ovr'] <=> $a['ovr'] ?: strcmp($a['nome'], $b['nome']));

file_put_contents($ARQ_CARTAS,
    "<?php\n/* Gerado por games/core/hoops_importar_cli.php em " . ($bruto['gerado_em'] ?? '?')
    . " — NÃO EDITE À MÃO. */\nreturn "
    . var_export(['cartas' => $cartas, 'times' => $bruto['times'], 'gerado_em' => $bruto['gerado_em'] ?? null], true) . ";\n");

// ── Resumo ──────────────────────────────────────────────────────────────
$porLiga = [];
$porRar  = [];
$porPos  = [];
foreach ($cartas as $c) {
    $porLiga[$c['liga']] = ($porLiga[$c['liga']] ?? 0) + 1;
    $porRar[hoopsRaridade($c['ovr'])] = ($porRar[hoopsRaridade($c['ovr'])] ?? 0) + 1;
    $porPos[$c['pos']] = ($porPos[$c['pos']] ?? 0) + 1;
}
fwrite(STDOUT, "\n" . count($cartas) . " cartas\n");
fwrite(STDOUT, '  por liga:      ' . json_encode($porLiga) . "\n");
fwrite(STDOUT, '  por raridade:  ' . json_encode($porRar) . "\n");
fwrite(STDOUT, '  por posição:   ' . json_encode($porPos) . "\n");

if ($amostra) {
    $linha = function (array $c): string {
        $at = implode(' ', array_map(fn($k) => HOOPS_ATRIBUTOS[$k]['curto'] . ' ' . str_pad($c['at'][$k], 2, ' ', STR_PAD_LEFT), array_keys(HOOPS_ATRIBUTOS)));
        return sprintf("  %2d %-3s/%-3s %-26s %-10s %-4s  %s  | %4.1f pts %4.1f reb %4.1f ast\n",
            $c['ovr'], $c['pos'], $c['pos2'] ?? '-', mb_strimwidth($c['nome'], 0, 26), $c['liga'], $c['time_sigla'], $at,
            $c['linha']['pts'], $c['linha']['reb'], $c['linha']['ast']);
    };
    foreach (array_keys($porLiga) as $liga) {
        $daLiga = array_values(array_filter($cartas, fn($c) => $c['liga'] === $liga));
        fwrite(STDOUT, "\n── {$liga}: top 12 ──\n");
        foreach (array_slice($daLiga, 0, 12) as $c) fwrite(STDOUT, $linha($c));
        fwrite(STDOUT, "   … mediana:\n" . $linha($daLiga[intdiv(count($daLiga), 2)]));
    }
}
