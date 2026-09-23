<?php
/**
 * O IMPORTADOR DE ELENCOS REAIS.
 *
 * Lê os arquivos de games/data/elencos_fonte/*.txt — a lista de jogadores com
 * posição, idade e valor de mercado — e escreve os elencos do jogo em
 * games/data/elencos/<clube>.php, que é onde futElencoDoClube() procura antes
 * de gerar um elenco fictício.
 *
 * Rodar pela linha de comando:
 *
 *     php games/core/fut_importar.php
 *
 * ── O PROBLEMA CENTRAL: A FONTE NÃO TEM OVR ──────────────────────────
 *
 * Ela tem VALOR DE MERCADO, que é um sinal melhor do que parece — é o preço
 * que o mercado real cobra pelo jogador, e o mercado erra menos que qualquer
 * nota que eu chutasse. Mas valor não é qualidade: um jogador de 19 anos custa
 * caro pelo que vai ser, e um de 36 custa pouco mesmo jogando bem.
 *
 * A saída é INVERTER a conta que o jogo já usa. O jogo calcula
 * valor = base(OVR) × fator(idade); aqui fazemos o caminho de volta:
 * base = valor ÷ fator(idade), e OVR = inversa(base). Assim a idade sai da
 * conta antes de o OVR entrar, e Thiago Silva aos 42 com 500 mil não é
 * avaliado como um jogador de 500 mil — é avaliado como o que 500 mil significa
 * NA IDADE DELE.
 */

require_once __DIR__ . '/fut_mercado.php';
require_once __DIR__ . '/fut_evolucao.php';
require_once __DIR__ . '/fut_clubes_br.php';

/** Como as posições da fonte viram as sete posições do jogo. */
const FUT_IMPORTAR_POSICOES = [
    'Goleiro'        => 'GOL',
    'Zagueiro'       => 'ZAG',
    'Lateral Esq.'   => 'LAT',
    'Lateral Dir.'   => 'LAT',
    'Volante'        => 'VOL',
    'Meia Central'   => 'MEI',
    'Meia Ofensivo'  => 'MEI',
    'Meia Direita'   => 'MEI',
    'Meia Esquerda'  => 'MEI',
    'Ponta Esquerda' => 'PON',
    'Ponta Direita'  => 'PON',
    'Centroavante'   => 'ATA',
    'Seg. Atacante'  => 'ATA',
];

/**
 * As âncoras de VALOR BASE (em milhões, já sem o efeito da idade) para OVR.
 *
 * Esta é a régua do Brasileirão, e não a do catálogo mundial do jogo: aqui o
 * jogador mais caro da fonte custa 32 milhões, enquanto no catálogo um OVR 90
 * vale 150. Usar a tabela do jogo achataria todo o Brasileirão em torno de 78 —
 * o Flamengo ficaria igual ao Novorizontino.
 *
 * Os pontos foram escolhidos para que os melhores da liga cheguem a 86-88 (o
 * teto nacional, abaixo dos 92 que o catálogo dá a Palmeiras e Flamengo) e o
 * fim da fila fique em torno de 52 — jogador de elenco, não de várzea.
 */
/* É UMA LISTA DE PARES [valor, ovr], e não um mapa valor => ovr, porque PHP
   CONVERTE CHAVE FLOAT EM INT. Escrito como mapa, as âncoras 0.025, 0.05,
   0.1, 0.25 e 0.5 colapsavam todas na chave 0 e a última sobrescrevia as
   outras — a tabela inteira abaixo de um milhão virava um único ponto, e
   jogador de 75 mil recebia o mesmo OVR de um de 500 mil. */
const FUT_IMPORTAR_ANCORAS = [
    [0.0, 44],  [0.025, 48], [0.05, 51], [0.1, 54],
    [0.25, 59], [0.5, 63],   [1.0, 67],  [2.0, 71],
    [3.0, 74],  [5.0, 78],   [7.0, 81],  [10.0, 84],
    [14.0, 86], [20.0, 88],  [30.0, 90], [45.0, 92],
    [70.0, 94],
];

/**
 * O piso do fator de idade na hora de desfazer a conta.
 *
 * A curva de valor do jogo despenca para 0,03 aos 39 anos, e dividir por um
 * número tão pequeno explode o resultado: Thiago Silva, 42 anos e 500 mil,
 * sairia avaliado como um jogador de 25 milhões — OVR 85. Com o piso em 0,06
 * ele sai em 79, que é um zagueiro veterano ainda muito bom, e é o que ele é.
 */
const FUT_IMPORTAR_PISO_IDADE = 0.06;

/**
 * O TETO DE OVR POR IDADE.
 *
 * O piso de idade acima resolve a explosão, mas não a vaidade: dividir por
 * 0,06 ainda trata um jogador de 45 anos como se tivesse 37. Medindo os
 * elencos reais, Hulk aos 40 anos saía com OVR 87 — o melhor do Fluminense —
 * e Weverton aos 38 com 85. Eles ainda jogam, e jogam bem, mas não são os
 * melhores jogadores do Brasileirão.
 *
 * O teto é por FAIXA e não uma conta: veterano é caso particular, e caso
 * particular se resolve com uma tabela curta que qualquer um lê.
 */
const FUT_IMPORTAR_TETO_IDADE = [
    34 => 90, 35 => 89, 36 => 86, 37 => 84, 38 => 82, 39 => 81,
];
const FUT_IMPORTAR_TETO_VELHO = 79;   // 40 anos ou mais

/** Lê "€ 12.00 mi." / "€ 800 mil" / "-" e devolve o valor em milhões. */
function futImportarValor(string $txt): float
{
    $t = trim($txt);
    if ($t === '' || $t === '-') return 0.0;

    /* DUAS ARMADILHAS NESTE REGEX, as duas descobertas medindo:

       1. "mil" TEM QUE VIR ANTES DE "mi" na alternância. Com (mi|mil), o
          "mi" casa nas duas primeiras letras de "mil" e "€ 800 mil" era
          lido como 800 MILHÕES — o Remo, cujo elenco é todo em milhares,
          saía com força 90, acima do Flamengo.

       2. O decimal da fonte é PONTO ("32.00 mi"), não vírgula. Aceitando só
          vírgula, todo valor em milhões virava zero, e o Flamengo — cujo
          elenco é todo em "mi." — saía com força 76.

       Juntas, as duas inverteram a liga: os ricos ficaram pobres e os pobres
       ricos. O \b no fim impede que "mi" case dentro de outra palavra. */
    if (preg_match('/([0-9]+(?:[.,][0-9]{1,2})?)\s*(mil|bi|mi)\b/iu', $t, $m)) {
        $n = (float)str_replace(',', '.', $m[1]);
        return match (strtolower($m[2])) {
            'bi'  => $n * 1000,
            'mi'  => $n,
            'mil' => $n / 1000,
            default => 0.0,
        };
    }
    return 0.0;
}

/**
 * O OVR de um jogador, a partir do valor de mercado e da idade.
 *
 * Ver a explicação no topo: tira a idade da conta e depois interpola nas
 * âncoras, em escala logarítmica — porque é assim que preço de jogador se
 * comporta, dobrando de faixa em faixa e não somando.
 */
function futImportarOvr(float $valorMilhoes, int $idade): int
{
    $fator = max(FUT_IMPORTAR_PISO_IDADE, FUT_VALOR_IDADE[$idade] ?? FUT_IMPORTAR_PISO_IDADE);
    $base = $valorMilhoes / $fator;

    $ancoras = FUT_IMPORTAR_ANCORAS;
    if ($base <= $ancoras[0][0]) return $ancoras[0][1];
    $fim = $ancoras[count($ancoras) - 1];
    if ($base >= $fim[0]) return $fim[1];

    $abaixo = $ancoras[0];
    $acima = $fim;
    foreach ($ancoras as $a) {
        if ($a[0] <= $base) $abaixo = $a;
        if ($a[0] >= $base) { $acima = $a; break; }
    }
    if ($abaixo[0] === $acima[0]) return $abaixo[1];

    /* Interpolação no log do valor: entre 5 e 7 milhões, o 6 cai mais perto do
       5 do que a média simples diria, que é como preço funciona. */
    $lo = log(max(0.001, $abaixo[0]));
    $hi = log($acima[0]);
    $t = ($hi - $lo) > 0 ? (log(max(0.001, $base)) - $lo) / ($hi - $lo) : 0;
    $ovr = $abaixo[1] * (1 - $t) + $acima[1] * $t;

    $teto = 95;
    if ($idade >= 40)      $teto = FUT_IMPORTAR_TETO_VELHO;
    elseif ($idade >= 34)  $teto = FUT_IMPORTAR_TETO_IDADE[$idade] ?? 90;

    return (int)max(40, min($teto, round($ovr)));
}

/**
 * Lê um arquivo de fonte e devolve [clube => lista de jogadores].
 *
 * @return array ['clubes' => array, 'erros' => array]
 */
function futImportarLerFonte(string $caminho): array
{
    $linhas = file($caminho, FILE_IGNORE_NEW_LINES);
    if ($linhas === false) return ['clubes' => [], 'erros' => ["não consegui ler $caminho"]];

    $clubes = [];
    $erros = [];
    $atual = null;

    foreach ($linhas as $n => $linha) {
        $linha = trim($linha);
        if ($linha === '') continue;

        if (str_starts_with($linha, '# CLUBE:')) {
            $atual = trim(substr($linha, 8));
            $clubes[$atual] = $clubes[$atual] ?? [];
            continue;
        }
        if (str_starts_with($linha, '#')) continue;   // comentário
        if ($atual === null) { $erros[] = 'linha ' . ($n + 1) . ': jogador antes de qualquer clube'; continue; }

        $partes = array_map('trim', explode('|', $linha));
        if (count($partes) < 4) { $erros[] = 'linha ' . ($n + 1) . ": não entendi \"$linha\""; continue; }

        [$nome, $posTxt, $nasc] = [$partes[0], $partes[1], $partes[2]];
        $valorTxt = $partes[count($partes) - 1];

        /* O clube entre parênteses é o de ORIGEM de quem está emprestado. O
           jogador joga pelo clube do bloco, então o parêntese sai do nome —
           senão o elenco teria "Wesley (Al-Nassr FC)" escalado em campo. */
        $nome = trim(preg_replace('/\s*\([^)]*\)\s*$/u', '', $nome));

        $pos = FUT_IMPORTAR_POSICOES[$posTxt] ?? null;
        if ($pos === null) { $erros[] = 'linha ' . ($n + 1) . ": posição desconhecida \"$posTxt\""; continue; }

        if (!preg_match('/\((\d{1,2})\)/', $nasc, $m)) {
            $erros[] = 'linha ' . ($n + 1) . ": idade não encontrada em \"$nasc\"";
            continue;
        }
        $idade = (int)$m[1];
        $valor = futImportarValor($valorTxt);

        $clubes[$atual][] = [
            'nome'  => $nome,
            'pos'   => $pos,
            'ovr'   => futImportarOvr($valor, $idade),
            'idade' => $idade,
            'num'   => 0,
            'energia' => 100,
            'moral'   => 75,
            'lesao'   => 0,
            'valor_fonte' => $valor,   // guardado só pra conferência
        ];
    }
    return ['clubes' => $clubes, 'erros' => $erros];
}

/** Escreve o elenco de um clube como arquivo PHP que devolve a lista. */
function futImportarEscrever(string $clube, array $elenco, string $destino): string
{
    // O potencial entra aqui: o elenco real precisa dele pra evoluir no jogo.
    foreach ($elenco as &$j) {
        unset($j['valor_fonte']);
        $j['potencial'] = futPotencialDe($j);
    }
    unset($j);

    $elenco = futRenumerar($elenco);

    $linhas = [];
    $linhas[] = '<?php';
    $linhas[] = '/**';
    $linhas[] = ' * ELENCO REAL — ' . $clube . '.';
    $linhas[] = ' *';
    $linhas[] = ' * Gerado por games/core/fut_importar.php a partir de';
    $linhas[] = ' * games/data/elencos_fonte/. NÃO EDITE À MÃO: mexa na fonte e rode o';
    $linhas[] = ' * importador de novo, senão a próxima importação apaga a mudança.';
    $linhas[] = ' *';
    $linhas[] = ' * O OVR saiu do valor de mercado descontada a idade — ver a explicação no';
    $linhas[] = ' * topo do importador.';
    $linhas[] = ' */';
    $linhas[] = '';
    $linhas[] = 'return [';
    foreach ($elenco as $j) {
        $linhas[] = sprintf(
            "    ['nome' => %s, 'pos' => '%s', 'ovr' => %d, 'idade' => %d, 'num' => %d, " .
            "'potencial' => %d, 'energia' => 100, 'moral' => 75, 'lesao' => 0],",
            var_export($j['nome'], true), $j['pos'], $j['ovr'], $j['idade'], $j['num'], $j['potencial']
        );
    }
    $linhas[] = '];';
    $linhas[] = '';

    $arquivo = $destino . '/' . futSlugDoClube($clube) . '.php';
    file_put_contents($arquivo, implode("\n", $linhas));
    return $arquivo;
}

// ═════════════════════════════════════════════════════════════════════
//  Execução pela linha de comando
// ═════════════════════════════════════════════════════════════════════
if (PHP_SAPI === 'cli' && realpath($argv[0] ?? '') === realpath(__FILE__)) {
    $fonteDir = __DIR__ . '/../data/elencos_fonte';
    $destino = __DIR__ . '/../data/elencos';
    if (!is_dir($destino)) mkdir($destino, 0775, true);

    $catalogo = futClubesDoBrasil();
    $todosErros = [];
    $escritos = 0;

    foreach (glob($fonteDir . '/*.txt') as $arquivo) {
        echo "── " . basename($arquivo) . "\n";
        $r = futImportarLerFonte($arquivo);
        $todosErros = array_merge($todosErros, $r['erros']);

        foreach ($r['clubes'] as $clube => $elenco) {
            if (!$elenco) continue;

            $noCatalogo = isset($catalogo[$clube]);
            $forcaJogo = futForcaDoElenco($elenco);
            $forcaCat = $noCatalogo ? (int)$catalogo[$clube]['forca'] : 0;

            $caminho = futImportarEscrever($clube, $elenco, $destino);
            $escritos++;

            printf("   %-16s %2d jogadores | força %d%s%s\n",
                $clube, count($elenco), $forcaJogo,
                $noCatalogo ? sprintf(' (catálogo %d, %+d)', $forcaCat, $forcaJogo - $forcaCat) : '',
                $noCatalogo ? '' : '  <== NÃO ESTÁ NO CATÁLOGO');

            // Nome repetido dentro do elenco quebra as chaves do jogo inteiro.
            $nomes = array_column($elenco, 'nome');
            $rep = array_diff_assoc($nomes, array_unique($nomes));
            if ($rep) $todosErros[] = "$clube: nome repetido — " . implode(', ', array_unique($rep));
        }
    }

    echo "\n$escritos elenco(s) escrito(s) em games/data/elencos/\n";
    if ($todosErros) {
        echo "\nPROBLEMAS:\n";
        foreach ($todosErros as $e) echo "  · $e\n";
        exit(1);
    }
    echo "nenhum problema.\n";
}
