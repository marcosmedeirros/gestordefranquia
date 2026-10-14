<?php
/**
 * ── OS OVRs DO BARALHO, TIRADOS DO EA FC ─────────────────────────────
 *
 * Os elencos em games/data/elencos/ são uma foto de 2024/25 e não envelhecem
 * sozinhos: o Lamine Yamal estava 79, o Olise 81, o Ederson 88 — "esses ovrs
 * estão muito antigos" (Marcos, 14/10/2026). Este CLI traz o overall da
 * edição atual do EA FC e grava uma camada que o DRAFT usa por cima do
 * elenco. Nada aqui toca games/data/elencos/, que é o jogo de carreira: lá o
 * OVR é a régua do motor do Brasileirão e mexer nele desregula outro jogo.
 *
 * ── DE ONDE VEM ──────────────────────────────────────────────────────
 *
 * Da API pública que o fut.gg usa pra desenhar a própria lista de cartas
 * (@see https://www.fut.gg/players/). Só o que a carta precisa sai de lá:
 * nome, clube, posição e overall. Preço, estatística, imagem e o resto ficam
 * onde estão.
 *
 * SÓ A CARTA BASE CONTA. O mesmo jogador tem uma dúzia de versões especiais
 * (Time da Semana, Futuro, promoções), todas com overall inflado; a base é a
 * que diz quanto ele vale. Ícones e heróis ficam de fora — o FBA Draft tem as
 * lendas dele (@see draftfut_lendas.php), e misturar as duas listas colocaria
 * um Pelé de 95 dentro do baralho comum.
 *
 * O BRASILEIRÃO NÃO VEM DAQUI, e não é esquecimento: o EA FC tem cinco
 * jogadores da liga brasileira com 75+. Quem joga no Brasil mantém o OVR do
 * elenco, que é a melhor fonte que existe pra eles.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 *   php games/core/draftfut_ovr_cli.php                 (baixa e grava)
 *   php games/core/draftfut_ovr_cli.php --so-relatorio  (não grava nada)
 *   php games/core/draftfut_ovr_cli.php --de=arq.json   (usa um JSON salvo)
 *
 * O resultado é games/data/draftfut_ovr.php, lido pelo baralho. Rodar de novo
 * reescreve o arquivo inteiro: ele é derivado, não é fonte.
 */

require_once __DIR__ . '/draftfut.php';

const FUTGG_API   = 'https://www.fut.gg/api/fut/players/v2/27/';
const FUTGG_PISO  = 75;    // o piso do baralho; abaixo disso a carta não sai
const FUTGG_PAG   = 30;    // o que a API devolve por página
const FUTGG_PAUSA = 350000; // microssegundos entre páginas: não é corrida

/** Nome sem acento, sem pontuação e em minúsculas — pra comparar gente. */
function futggNorm(string $s): string
{
    $s = mb_strtolower(trim($s), 'UTF-8');
    $s = strtr($s, ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','å'=>'a','é'=>'e','ê'=>'e','è'=>'e','ë'=>'e',
                    'í'=>'i','ì'=>'i','î'=>'i','ï'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ø'=>'o',
                    'ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','ß'=>'ss','ł'=>'l','š'=>'s','ž'=>'z',
                    'ć'=>'c','č'=>'c','đ'=>'d','ğ'=>'g','ı'=>'i','ş'=>'s','ý'=>'y','ÿ'=>'y','ę'=>'e','ą'=>'a',
                    'ś'=>'s','ź'=>'z','ż'=>'z','ň'=>'n','ř'=>'r','ů'=>'u','ě'=>'e','ť'=>'t','ď'=>'d']);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $s)));
}

/** A última palavra de três letras ou mais: o "sobrenome" pra desempate. */
function futggSobrenome(string $n): string
{
    $p = array_values(array_filter(explode(' ', futggNorm($n)), fn($x) => mb_strlen($x) >= 3));
    return $p ? end($p) : futggNorm($n);
}

/** Uma página da API, ou null. */
function futggPagina(int $pagina): ?array
{
    $url = FUTGG_API . '?page=' . $pagina . '&overall__gte=' . FUTGG_PISO;
    $c = curl_init($url);
    curl_setopt_array($c, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 30,
        CURLOPT_ENCODING       => '',
        CURLOPT_HTTPHEADER     => ['Accept: application/json',
                                   'User-Agent: FBA Manager (liga amadora; atualizacao de OVR)'],
    ]);
    $corpo = curl_exec($c);
    $erro  = curl_error($c);
    $code  = (int)curl_getinfo($c, CURLINFO_HTTP_CODE);
    curl_close($c);
    if ($corpo === false || $code !== 200) {
        fwrite(STDERR, "página {$pagina}: HTTP {$code} {$erro}\n");
        return null;
    }
    $j = json_decode((string)$corpo, true);
    return is_array($j) ? $j : null;
}

/**
 * Baixa o catálogo inteiro e devolve UMA linha por jogador: a carta base.
 *
 * @return array<int,array> indexado pelo eaId do jogador base
 */
function futggBaixarBase(): array
{
    $base = []; $pagina = 1; $total = null;
    do {
        $j = futggPagina($pagina);
        if (!$j) break;
        $total = $total ?? (int)($j['total'] ?? 0);
        foreach ($j['data'] ?? [] as $p) {
            /* Só carta base de jogador de verdade: promoção infla o overall,
               ícone e herói são outro baralho, e o futebol feminino tem ligas
               próprias que o jogo não usa. */
            if (!empty($p['isIcon']) || !empty($p['isHero'])) continue;
            if (!in_array((string)($p['rarityName'] ?? ''), ['Rare', 'Common'], true)) continue;
            if (($p['gender'] ?? 0) == 1) continue;     // 0 = masculino na API
            $id = (int)($p['basePlayerEaId'] ?? $p['eaId'] ?? 0);
            if (!$id) continue;
            $ovr = (int)($p['overall'] ?? 0);
            /* Mesmo com o filtro de raridade sobra duplicata (versões de
               temporada): fica a de MENOR overall, que é a original. */
            if (isset($base[$id]) && $base[$id]['ovr'] <= $ovr) continue;
            $base[$id] = [
                'nome'  => (string)($p['commonName'] ?: trim(($p['firstName'] ?? '') . ' ' . ($p['lastName'] ?? ''))),
                'comp'  => trim(((string)($p['firstName'] ?? '')) . ' ' . ((string)($p['lastName'] ?? ''))),
                'ovr'   => $ovr,
                'clube' => is_array($p['club'] ?? null) ? (string)($p['club']['name'] ?? '') : '',
                'liga'  => is_array($p['league'] ?? null) ? (string)($p['league']['name'] ?? '') : '',
            ];
        }
        $pagina++;
        usleep(FUTGG_PAUSA);
    } while ($total !== null && ($pagina - 1) * FUTGG_PAG < $total && $pagina < 400);
    return $base;
}

/* ── O QUE ESTE CLI FAZ ──────────────────────────────────────────────── */

$opts = getopt('', ['so-relatorio', 'de:']);
$soRelatorio = isset($opts['so-relatorio']);

if (isset($opts['de'])) {
    $cru = json_decode((string)@file_get_contents((string)$opts['de']), true);
    if (!is_array($cru)) { fwrite(STDERR, "não consegui ler o JSON de --de\n"); exit(1); }
    /* Aceita tanto a lista pronta quanto o formato bruto da API. */
    $base = [];
    foreach ($cru as $p) {
        $base[] = ['nome' => (string)($p['nome'] ?? ''), 'comp' => (string)($p['comp'] ?? (trim(($p['first'] ?? '') . ' ' . ($p['last'] ?? '')))),
                   'ovr' => (int)($p['ovr'] ?? $p['overall'] ?? 0), 'clube' => (string)($p['clube'] ?? ''),
                   'liga' => (string)($p['liga'] ?? '')];
    }
} else {
    echo "baixando o catálogo do EA FC (fut.gg), overall >= " . FUTGG_PISO . "…\n";
    $base = array_values(futggBaixarBase());
}
echo 'jogadores base: ', count($base), "\n";
if (!$base) { fwrite(STDERR, "nada pra casar\n"); exit(1); }

/* Índices de busca: nome comum, nome completo e sobrenome. */
$porNome = []; $porSobre = [];
foreach ($base as $p) {
    foreach (array_unique([futggNorm($p['nome']), futggNorm($p['comp'])]) as $k) {
        if ($k !== '') $porNome[$k][] = $p;
    }
    $porSobre[futggSobrenome($p['nome'])][] = $p;
}

/* Quantas cartas do baralho têm cada nome: é o que diz se "Vitinha" é uma
   pessoa ou três. @see a regra do homônimo logo abaixo */
$noBaralho = [];
foreach (draftFutBaralho() as $c) {
    if ((int)$c['ovr'] < FUTGG_PISO - 6) continue;
    $noBaralho[futggNorm((string)$c['nome'])] = ($noBaralho[futggNorm((string)$c['nome'])] ?? 0) + 1;
}

$tabela = []; $relatorio = []; $ambiguos = []; $casou = 0; $naoCasou = 0; $difs = [];
foreach (draftFutBaralho() as $c) {
    if ((int)$c['ovr'] < FUTGG_PISO - 6) continue;   // margem: quem subiu entra no baralho
    $n = futggNorm((string)$c['nome']);
    $cand = $porNome[$n] ?? [];
    if (!$cand) {
        /* Sem o nome exato, aceita o sobrenome SE o clube bater — "Carvajal"
           casa com "Daniel Carvajal", mas "Silva" não casa com qualquer um. */
        foreach ($porSobre[futggSobrenome((string)$c['nome'])] ?? [] as $p) {
            if (futggNorm($p['clube']) === futggNorm((string)$c['clube'])) { $cand = [$p]; break; }
        }
    }
    if (!$cand) { $naoCasou++; continue; }

    /* ── HOMÔNIMO NÃO É A MESMA PESSOA ───────────────────────────────
       O primeiro teste deu ao Vitinha do Genoa (69) o overall do Vitinha do
       PSG (90), porque o nome bate e o nome foi a única pergunta. Agora o
       clube decide: casa quem joga no mesmo lugar. Sem clube igual, só vale
       quando existe UM com aquele nome dos dois lados — aí é a mesma pessoa
       que trocou de time, que é o caso comum. Dois de cada lado e clubes
       diferentes: fica o OVR do elenco e a dúvida sai no relatório. */
    $p = null;
    foreach ($cand as $x) {
        if (futggNorm($x['clube']) === futggNorm((string)$c['clube'])) { $p = $x; break; }
    }
    if ($p === null) {
        if (count($cand) === 1 && ($noBaralho[$n] ?? 0) === 1) {
            $p = $cand[0];
        } else {
            $naoCasou++;
            $ambiguos[] = sprintf('%-24s %-18s (%d no EA FC, %d no baralho)',
                mb_substr((string)$c['nome'], 0, 24), mb_substr((string)$c['clube'], 0, 18),
                count($cand), $noBaralho[$n] ?? 0);
            continue;
        }
    }

    $casou++;
    $difs[] = (int)$p['ovr'] - (int)$c['ovr'];
    /* A CHAVE É A DO ELENCO (clube de origem + nome), que é como o baralho
       pergunta. @see draftFutBaralho */
    $tabela[(string)$c['clube'] . '|' . (string)$c['nome']] = (int)$p['ovr'];
    if (abs((int)$p['ovr'] - (int)$c['ovr']) >= 5) {
        $relatorio[] = sprintf('%+3d  %-26s %-20s %d -> %d',
            (int)$p['ovr'] - (int)$c['ovr'], mb_substr((string)$c['nome'], 0, 26),
            mb_substr((string)$c['clube'], 0, 20), (int)$c['ovr'], (int)$p['ovr']);
    }
}

$n = max(1, count($difs));
printf("casaram: %d | sem par (fica o OVR do elenco): %d\n", $casou, $naoCasou);
printf("diferença média: %+.1f | subiram %d, caíram %d, iguais %d\n",
    array_sum($difs) / $n,
    count(array_filter($difs, fn($x) => $x > 0)),
    count(array_filter($difs, fn($x) => $x < 0)),
    count(array_filter($difs, fn($x) => $x === 0)));

if ($ambiguos) {
    sort($ambiguos);
    echo "\nhomônimos deixados de fora (", count($ambiguos), "):\n";
    foreach (array_slice($ambiguos, 0, 30) as $l) echo '  ', $l, "\n";
    if (count($ambiguos) > 30) echo '  … e mais ', count($ambiguos) - 30, "\n";
}

sort($relatorio);
echo "\nmudanças de 5 ou mais:\n";
foreach ($relatorio as $l) echo '  ', $l, "\n";

if ($soRelatorio) { echo "\n--so-relatorio: nada gravado.\n"; exit(0); }

ksort($tabela);
$php = "<?php\n"
     . "/* OVR do EA FC por carta do draft — GERADO por games/core/draftfut_ovr_cli.php.\n"
     . "   NÃO EDITE À MÃO: rode o CLI de novo. Ajuste pontual vai em DFUT_OVR_MAO,\n"
     . "   que tem a última palavra. Gerado em " . date('d/m/Y H:i') . ". */\n"
     . "return " . var_export($tabela, true) . ";\n";
$destino = __DIR__ . '/../data/draftfut_ovr.php';
file_put_contents($destino, $php);
echo "\ngravado: ", realpath($destino), ' (', count($tabela), " cartas)\n";
