<?php
/**
 * RODA A IMPORTAÇÃO DAS LIGAS EUROPEIAS.
 *
 *   php games/core/fut_importar_europa_cli.php <caminho do csv> [--gravar]
 *
 * Sem --gravar ele só mostra o que faria. Grava duas coisas:
 *
 *   games/data/elencos/<slug>.php   um por clube, no mesmo formato dos elencos
 *                                   brasileiros — quem lê não sabe a origem
 *   games/core/fut_clubes_eu.php    o catálogo: nome, liga, país, força, escudo
 *
 * A FORÇA NÃO É ESCRITA À MÃO em lugar nenhum: ela sai do elenco
 * (@see futForcaDoElenco), como já acontece com os clubes brasileiros que têm
 * lista real. Vender o camisa 9 do Real Madrid tem que mexer na força dele.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/fut_importar_europa.php';
require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/copero_clubes.php';

$csv = $argv[1] ?? '';
$gravar = in_array('--gravar', $argv, true);
if ($csv === '' || !is_file($csv)) {
    fwrite(STDERR, "uso: php fut_importar_europa_cli.php <csv> [--gravar]\n");
    exit(1);
}

/* O escudo e o nome que o catálogo do Copero já guarda. Ele é a fonte porque
   já foi conferido — o que não está lá entra sem escudo e vira monograma, que
   é melhor do que escudo errado. */
$escudos = [];
foreach (COPERO_CLUBES as $c) {
    if (($c[3] ?? '') !== '') $escudos[$c[0]] = $c[3];
}

$brasileiros = futClubesDoBrasil();

$dados = futEuropaLerCsv($csv);
$catalogo = [];
$semEscudo = [];
$completados = [];
$colisoes = [];

/* ── PRIMEIRA PASSADA: montar os elencos e medir a força crua ─────────
   Duas passadas porque a âncora do esticamento é o CLUBE MAIS FORTE DA LIGA
   (@see FUT_EUROPA_ESTICA), e ele só se conhece depois de medir todos. */
$elencos = [];
$topoDaLiga = [];
foreach (FUT_EUROPA_LIGAS as $meta) {
    $div = $meta['div'];
    foreach ($dados[$div] ?? [] as $clube => $jogadores) {
        /* NOME REPETIDO É PERDA SILENCIOSA DE ELENCO: o catálogo é indexado
           por nome e o arquivo de elenco é achado pelo slug, então dois clubes
           com o mesmo nome viram um só e o segundo passa a jogar com o elenco
           do primeiro sem nada na tela indicando isso. */
        if (isset($brasileiros[$clube])) { $colisoes[] = $clube; continue; }

        $elenco = futEuropaAplicarTetos(futEuropaEscolherElenco($jogadores));

        /* Quem vem curto da base é completado pelo gerador, e a força medida
           ANTES é o nível de quem entra — completar primeiro e medir depois
           faria os que entraram puxarem a própria régua pra cima. */
        if (count($elenco) < FUT_EUROPA_ELENCO_MINIMO) {
            $completados[$clube] = count($elenco);
            $elenco = futEuropaCompletarElenco($elenco, $clube, futForcaDoElenco($elenco));
        }

        $crua = futForcaDoElenco($elenco);
        $elencos[$div][$clube] = ['elenco' => $elenco, 'crua' => $crua];
        $topoDaLiga[$div] = max($topoDaLiga[$div] ?? 0, $crua);
    }
}

// ── SEGUNDA PASSADA: esticar e gravar ────────────────────────────────
foreach (FUT_EUROPA_LIGAS as $meta) {
    $div = $meta['div'];
    foreach ($elencos[$div] ?? [] as $clube => $d) {
        $alvo = futEuropaForcaEsticada($d['crua'], $topoDaLiga[$div]);
        $elenco = futOvrAjustarParaForca($d['elenco'], $alvo);

        /* A FORÇA VEM DE MEDIR O ELENCO DE NOVO, e não do alvo: o
           deslocamento esbarra em piso e em teto de idade, e escrever o alvo
           no catálogo faria ele divergir do elenco que está no arquivo. */
        $forca = futForcaDoElenco($elenco);
        if (($escudos[$clube] ?? '') === '') $semEscudo[] = $clube;

        $catalogo[$clube] = ['nome' => $clube, 'div' => $div, 'pais' => $meta['pais'],
                             'forca' => $forca, 'escudo' => $escudos[$clube] ?? ''];

        if ($gravar) futOvrGravar($clube, $elenco);
    }
}

/* ── OS CONVIDADOS ────────────────────────────────────────────────────
   Entram sem esticamento, e de propósito: o esticamento serve pra abrir a
   distância dentro de um campeonato, e estes não disputam campeonato nenhum
   no jogo. Eles já chegam selecionados — são os primeiros do país deles —,
   então a faixa que eles ocupam (uns 65 a 80) é a que deve ser mesmo. */
foreach (futEuropaLerConvidados($csv) as $clube => $d) {
    if (isset($brasileiros[$clube]) || isset($catalogo[$clube])) { $colisoes[] = $clube; continue; }

    $elenco = $d['elenco'];
    if (count($elenco) < FUT_EUROPA_ELENCO_MINIMO) {
        $completados[$clube] = count($elenco);
        $elenco = futEuropaCompletarElenco($elenco, $clube, futForcaDoElenco($elenco));
    }
    if (($escudos[$clube] ?? '') === '') $semEscudo[] = $clube;

    $catalogo[$clube] = ['nome' => $clube, 'div' => FUT_DIV_CONVIDADO, 'pais' => $d['pais'],
                         'forca' => futForcaDoElenco($elenco), 'escudo' => $escudos[$clube] ?? ''];

    if ($gravar) futOvrGravar($clube, $elenco);
}

// ── O catálogo ───────────────────────────────────────────────────────
uasort($catalogo, function ($a, $b) {
    return [$a['div'], -$a['forca']] <=> [$b['div'], -$b['forca']];
});

$linhas = [];
$ligaAtual = '';
foreach ($catalogo as $c) {
    if ($c['div'] !== $ligaAtual) {
        $ligaAtual = $c['div'];
        $nome = $ligaAtual === FUT_DIV_CONVIDADO ? 'Os convidados das continentais' : '';
        foreach (FUT_EUROPA_LIGAS as $m) if ($m['div'] === $ligaAtual) $nome = $m['nome'];
        $linhas[] = ($linhas ? "\n" : '') . '    // ── ' . $nome . ' ─────────────────────────────';
    }
    $linhas[] = sprintf("    [%s, '%s', '%s', %d, %s],",
        var_export($c['nome'], true), $c['div'], $c['pais'], $c['forca'],
        var_export($c['escudo'], true));
}

$php = <<<'CAB'
<?php
/**
 * OS CLUBES EUROPEUS DO JOGO DE CARREIRA — seis ligas, 114 clubes.
 *
 * GERADO por games/core/fut_importar_europa_cli.php. NÃO EDITE À MÃO: a
 * próxima importação apaga a mudança. Escudo novo vai em FUT_ESCUDOS
 * (@see fut_clubes_br.php), que é lido depois deste arquivo e ganha dele.
 *
 * ── Os campos ─────────────────────────────────────────────────────────
 *
 *   nome    como aparece na tela
 *   div     a liga: EN1, ES1, IT1, DE1, FR1, PT1 — o mesmo código do Copero
 *   pais    ENG, ESP, ITA, GER, FRA, POR
 *   forca   1 a 100, a MESMA régua do Brasil. Ela SAI DO ELENCO, não foi
 *           escrita: é a média dos onze melhores (@see futForcaDoElenco)
 *   escudo  a URL do catálogo do Copero, ou vazio (aí vira monograma)
 *
 * Nome de clube é referência factual. O jogo não é afiliado nem endossado por
 * nenhum deles e não hospeda escudo.
 */

const FUT_CLUBES_EU = [

CAB;
$php .= implode("\n", $linhas) . "\n];\n";

if ($gravar) file_put_contents(__DIR__ . '/fut_clubes_eu.php', $php);

// ── O relatório ──────────────────────────────────────────────────────
$porLiga = [];
foreach ($catalogo as $c) $porLiga[$c['div']][] = $c['forca'];
foreach ($porLiga as $div => $f) {
    echo str_pad($div, 5) . ' ' . str_pad(count($f), 3) . ' clubes   força '
        . min($f) . '-' . max($f) . '   média ' . round(array_sum($f) / count($f), 1) . "\n";
}
echo "\n" . count($catalogo) . " clubes" . ($gravar ? ' GRAVADOS' : ' (nada gravado, use --gravar)') . "\n";
if ($colisoes) echo "COLISÃO DE NOME (ficaram de fora): " . implode(', ', $colisoes) . "\n";
foreach ($completados as $clube => $n) echo "completado: $clube tinha $n na base\n";
echo "\nsem escudo (" . count($semEscudo) . "): " . implode(', ', $semEscudo) . "\n";
