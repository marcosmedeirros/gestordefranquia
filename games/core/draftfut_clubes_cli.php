<?php
/**
 * ACHA OS JOGADORES QUE TROCARAM DE CLUBE — roda na mão.
 *
 *   php games/core/draftfut_clubes_cli.php            (o baralho inteiro)
 *   php games/core/draftfut_clubes_cli.php --n=30     (as 30 melhores cartas)
 *
 * Pedido do Marcos (08/10/2026): "varios e varios jogadores estão com o time
 * desatualizado". Estão mesmo — os elencos em games/data/elencos/ são uma
 * foto do passado, e as fotos dos jogadores denunciaram: o Isak aparece de
 * Liverpool numa carta do Newcastle, o Openda de Juventus numa do Leipzig.
 *
 * ── O CAMPO DE CLUBE DA FONTE NÃO SERVE SOZINHO ──────────────────────
 *
 * Medido antes de escrever uma linha disto, e é por isso que a regra abaixo
 * tem três travas em vez de uma:
 *
 *   · Mohamed Salah vem como "Trabzonspor" — e a FOTO DA PRÓPRIA FONTE
 *     mostra ele de Liverpool. O registro se contradiz.
 *   · Kevin De Bruyne e Thibaut Courtois vêm como "Belgium", que é seleção,
 *     não clube (o idTeam deles aponta pra liga "FIFA World Cup").
 *
 * O que separa o dado bom do podre é o `strStatus`: os três casos reais que
 * eu conferi (Ederson no Fenerbahçe, Bernardo Silva no Real Madrid, Gündoğan
 * no Galatasaray) vêm "Active", e o Salah vem "Free Agent" — um registro que
 * diz ao mesmo tempo que ele está sem clube e que o clube dele é o
 * Trabzonspor. Então:
 *
 *   1. o nome casa POR PALAVRA INTEIRA e a NACIONALIDADE bate (a mesma
 *      tranca das outras passadas, @see draftfut_fotos_cli.php);
 *   2. o status é "Active" — nada de Free Agent nem Retired;
 *   3. o clube novo EXISTE NO NOSSO CATÁLOGO, o que de quebra derruba as
 *      seleções, que não estão lá.
 *
 * Quem não passar nas três fica onde está. Mudar o clube de uma carta mexe na
 * química do time inteiro de quem a escalou, então errar aqui é caro.
 *
 * ── O QUE ISTO NÃO FAZ ───────────────────────────────────────────────
 *
 * Não toca em games/data/elencos/, que é do jogo de carreira. A troca é uma
 * CAMADA só do draft, em games/data/draftfut_transferencias.php, e some se o
 * arquivo for apagado.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/copero_clubes.php';
require_once __DIR__ . '/fut_elencos.php';
require_once __DIR__ . '/draftfut.php';

const API_CLUBE = 'https://www.thesportsdb.com/api/v1/json/3/';
$DESTINO = __DIR__ . '/../data/draftfut_transferencias.php';

$limite = 0;
foreach ($argv as $a) if (preg_match('/^--n=(\d+)$/', $a, $m)) $limite = (int)$m[1];

function pegaClube(string $url): ?array
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

/** As palavras de um nome, sem acento. */
function palavrasClube(string $n): array
{
    $k = draftFutChaveNome($n);
    return $k === '' ? [] : explode(' ', $k);
}

/** O nome da fonte é o meu, palavra a palavra? @see draftfut_fotos_cli.php */
function nomeCasaClube(string $a, string $b): bool
{
    $pa = palavrasClube($a); $pb = palavrasClube($b);
    if (!$pa || !$pb) return false;
    $dentro = fn(array $x, array $y) => !array_diff($x, $y);
    return $dentro($pa, $pb) || $dentro($pb, $pa);
}

/* O catálogo, pra saber se o clube novo é um clube que o jogo conhece. */
$catalogo = [];
foreach (COPERO_CLUBES as [$nome, $liga, $forca, $escudo]) {
    $catalogo[draftFutChaveNome($nome)] = $nome;
}

/* Já conferidos ficam gravados, com mudança ou sem: rodar de novo continua de
   onde parou em vez de gastar a chave repetindo o que já se sabe. */
$trocas = is_file($DESTINO) ? (array)require $DESTINO : [];

$cartas = [];
foreach (draftFutBaralho() as $c) {
    if ($c['ovr'] < DFUT_OVR_MIN) continue;
    if (($c['nac'] ?? '') === '') continue;            // sem nação não há tranca
    if (array_key_exists($c['clube'] . '|' . $c['nome'], $trocas)) continue;
    $cartas[] = $c;
}
usort($cartas, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
if ($limite) $cartas = array_slice($cartas, 0, $limite);

fwrite(STDOUT, count($cartas) . " cartas a conferir\n");

function gravaTrocas(string $destino, array $trocas): void
{
    file_put_contents($destino,
        "<?php\n/* Gerado por games/core/draftfut_clubes_cli.php — NÃO EDITE À MÃO.\n"
        . "   Chave 'Clube antigo|Nome' => clube novo; string vazia = conferido, sem mudança. */\nreturn "
        . var_export($trocas, true) . ";\n");
}

$mudou = 0; $igual = 0; $i = 0; $seguidas = 0;
foreach ($cartas as $c) {
    $i++;
    $chave = $c['clube'] . '|' . $c['nome'];

    $r = pegaClube(API_CLUBE . 'searchplayers.php?p=' . rawurlencode(draftFutChaveNome($c['nome'])));
    if ($r === null) {
        $seguidas++;
        fwrite(STDOUT, "[$i/" . count($cartas) . "] {$c['nome']} — API fora ($seguidas seguidas)\n");
        if ($seguidas >= 5) { fwrite(STDOUT, "bloqueado; o resto fica pra outra rodada\n"); break; }
        sleep(20);
        continue;
    }
    $seguidas = 0;

    $novo = '';
    foreach ($r['player'] ?? [] as $x) {
        if (!nomeCasaClube((string)($x['strPlayer'] ?? ''), $c['nome'])) continue;
        if (trim((string)($x['strNationality'] ?? '')) !== $c['nac']) continue;

        /* Trava 2: só registro ATIVO. "Free Agent" com nome de clube junto é
           registro que se contradiz — foi o caso do Salah. */
        $status = trim((string)($x['strStatus'] ?? ''));
        if (strcasecmp($status, 'Active') !== 0) break;

        $daFonte = (string)($x['strTeam'] ?? '');
        if ($daFonte === '') break;
        if (draftFutClubeBate($daFonte, [$c['clube'], DFUT_CLUBE_API[$c['clube']] ?? $c['clube']])) break;

        /* Trava 3: o clube novo tem que existir no catálogo. Seleção não
           está lá, e é assim que "Belgium" cai fora. */
        $k = draftFutChaveNome($daFonte);
        if (isset($catalogo[$k])) $novo = $catalogo[$k];
        break;
    }

    $trocas[$chave] = $novo;
    if ($novo !== '') {
        $mudou++;
        fwrite(STDOUT, sprintf("[%d/%d] %-24s %-20s -> %s\n", $i, count($cartas), $c['nome'], $c['clube'], $novo));
    } else {
        $igual++;
    }

    if ($i % 20 === 0) {
        gravaTrocas($DESTINO, $trocas);
        fwrite(STDOUT, "   ... $mudou trocas, $igual sem mudança\n");
    }
    sleep(3);
}

gravaTrocas($DESTINO, $trocas);
fwrite(STDOUT, "pronto: $mudou trocaram de clube, $igual seguem onde estavam\n");
