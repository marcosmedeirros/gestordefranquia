<?php
/**
 * COMPLETA A NACIONALIDADE JOGADOR A JOGADOR — a segunda passada.
 *
 *   php games/core/draftfut_nacoes_cli.php [--n=50]
 *
 * ── POR QUE EXISTE UMA SEGUNDA PASSADA ───────────────────────────────
 *
 * A primeira (@see draftfut_nacionalidades_cli.php) pede o ELENCO de cada
 * clube, e a chave pública da TheSportsDB devolve no máximo dez nomes por
 * clube — dez nomes quase sorteados, em que o craque raramente está. Resultado
 * medido: 238 jogadores achados, mas só 13% do baralho do draft, porque o
 * baralho é feito justamente do topo de cada elenco.
 *
 * Esta passada vira a busca do avesso: pergunta PELO JOGADOR, um por um, e aí
 * a API acha. Medido na mão: Mbappé, Haaland, Vinícius Júnior, Arrascaeta e
 * Lautaro Martínez vieram todos certos, com foto.
 *
 * ── A TRANCA É O CLUBE ───────────────────────────────────────────────
 *
 * Buscar "Rodri" devolveu "Jay Rodriguez, England, _Free Agent Soccer". Por
 * isso o resultado SÓ É ACEITO quando o clube bate com o da carta, ou quando
 * o nome bate inteirinho e tem pelo menos duas palavras. Nome curto de uma
 * palavra só passa pelo clube — que é onde o perigo estava.
 *
 * Roda quantas vezes quiser: só procura quem ainda está sem nação.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/draftfut.php';

const API2 = 'https://www.thesportsdb.com/api/v1/json/3/';
$DESTINO = __DIR__ . '/../data/draftfut_jogadores.php';

$limite = 0;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $limite = (int)$m[1];

function p2(string $url): ?array
{
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
                                CURLOPT_USERAGENT => 'Mozilla/5.0 (FBA-Draft)']);
        $r = curl_exec($ch);
        $cod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($r !== false && $cod === 200) {
            $j = json_decode((string)$r, true);
            if (is_array($j)) return $j;
        }
        sleep(3 + $i * 3);
    }
    return null;
}

/**
 * A chave de comparação mora em draftfut_cartas.php.
 *
 * Aqui ela era iconv('ASCII//TRANSLIT'), que neste PHP SEPARA o acento numa
 * marca ASCII em vez de tirar: "Vinícius Júnior" virava a busca "vin icius j
 * unior" e a API não achava nada. Metade do baralho tem acento no nome, então
 * metade das buscas ia quebrada — e parecia que a API só não tinha aqueles
 * jogadores.
 */
function k2(string $n): string { return draftFutChaveNome($n); }

/* A conferencia de clube mora em draftfut_cartas.php — os dois
   importadores fazem a mesma pergunta. */
function clubeBate(string $kTime, array $kClubes): bool
{
    return draftFutClubeBate($kTime, $kClubes);
}

/* Como a API escreve o clube, pra conferir o resultado. A lista da primeira
   passada já tem os nomes que divergem; aqui ela é reaproveitada ao contrário. */
$aliasClube = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) $aliasClube[$nome] = [k2($nome)];
/* A lista de apelidos mora em draftfut_cartas.php: os dois importadores
   precisam dela, e enquanto cada um tinha a sua elas desandaram. */
$extras = DFUT_CLUBE_API;
foreach ($extras as $nosso => $deles) {
    if (isset($aliasClube[$nosso])) $aliasClube[$nosso][] = k2($deles);
}

/* Quem ainda está sem nação, do baralho do draft. */
$dados = is_file($DESTINO) ? (array)require $DESTINO : [];

$faltam = [];
foreach (draftFutBaralho() as $c) {
    if ($c['ovr'] < DFUT_OVR_MIN) continue;
    if (($c['nac'] ?? '') !== '') continue;
    $faltam[] = [$c['clube'], $c['nome']];
}
/* Os melhores primeiro: são os que mais aparecem no draft, e se a API cair no
   meio é melhor ter os craques do que os reservas. */
$ovrDe = [];
foreach (draftFutBaralho() as $c) $ovrDe[$c['clube'] . '|' . $c['nome']] = $c['ovr'];
usort($faltam, fn($a, $b) => ($ovrDe[$b[0] . '|' . $b[1]] ?? 0) <=> ($ovrDe[$a[0] . '|' . $a[1]] ?? 0));

if ($limite) $faltam = array_slice($faltam, 0, $limite);
fwrite(STDOUT, count($faltam) . " cartas sem nação\n");

$ok = 0; $nao = 0; $i = 0; $seguidas = 0;
foreach ($faltam as [$clube, $nome]) {
    $i++;
    $r = p2(API2 . 'searchplayers.php?p=' . rawurlencode(k2($nome)));
    if ($r === null) {
        /* A chave pública estrangula quem corre, e na primeira rodada ela caiu
           na 22ª busca. Desistir ali deixaria o baralho pela metade: agora
           espera um minuto e segue. Só para de verdade depois de cinco quedas
           seguidas — aí é bloqueio, não soluço. */
        $seguidas++;
        fwrite(STDOUT, "API fora em $nome ($seguidas seguidas) — esperando 60s\n");
        if ($seguidas >= 5) { fwrite(STDOUT, "bloqueado; o resto fica pra outra rodada\n"); break; }
        sleep(60);
        continue;
    }
    $seguidas = 0;

    $kNome   = k2($nome);
    $kClubes = $aliasClube[$clube] ?? [k2($clube)];
    $achou   = null;

    foreach ($r['player'] ?? [] as $p) {
        $nac = trim((string)($p['strNationality'] ?? ''));
        if ($nac === '') continue;

        $kTime = k2((string)($p['strTeam'] ?? ''));
        $peloClube = $kTime !== '' && clubeBate($kTime, $kClubes);
        $peloNome  = k2((string)$p['strPlayer']) === $kNome && str_contains($kNome, ' ');

        if ($peloClube || $peloNome) {
            $achou = ['nac' => $nac, 'foto' => (string)($p['strCutout'] ?? '')];
            break;
        }
    }

    if ($achou) { $dados[$clube][$nome] = $achou; $ok++; } else { $nao++; }

    if ($i % 20 === 0 || $i === count($faltam)) {
        file_put_contents($DESTINO,
            "<?php\n/* Gerado por games/core/draftfut_nacionalidades_cli.php e draftfut_nacoes_cli.php — NÃO EDITE À MÃO. */\nreturn "
            . var_export($dados, true) . ";\n");
        fwrite(STDOUT, "[$i/" . count($faltam) . "] $ok achados, $nao sem casar\n");
    }
    sleep(3);
}

file_put_contents($DESTINO,
    "<?php\n/* Gerado por games/core/draftfut_nacionalidades_cli.php e draftfut_nacoes_cli.php — NÃO EDITE À MÃO. */\nreturn "
    . var_export($dados, true) . ";\n");
fwrite(STDOUT, "pronto: $ok novos com nação, $nao não casaram\n");
