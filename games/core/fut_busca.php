<?php
/**
 * A BUSCA DO JOGO: jogador, clube ou competição, numa caixa só.
 *
 * O jogo cresceu pra 270 clubes, 6.674 jogadores e trinta e poucas
 * competições, e a única forma de chegar num deles era esbarrar nele — clicar
 * num nome da tabela, achar um adversário no calendário. Quem quisesse ver
 * como está a Premier enquanto dirige na Série B não tinha caminho nenhum.
 *
 * ── POR QUE NO SERVIDOR, E NÃO NO NAVEGADOR ──────────────────────────
 *
 * O índice inteiro de jogadores tem uns 270 KB de JSON. Mandar isso em toda
 * abertura de tela pra servir uma busca que a maioria não usa é caro no lugar
 * errado — ainda mais no celular. Aqui é uma consulta por digitação (com
 * espera no clique da tecla), e montar o índice custa 30 ms com os arquivos
 * já quentes.
 *
 * ── A ORDEM DOS RESULTADOS É A ORDEM DA INTENÇÃO ─────────────────────
 *
 * Quem digita "flamengo" quer o clube, não o lateral chamado Flamengo. Quem
 * digita "serie a" quer a competição. Por isso competição e clube vêm antes de
 * jogador, e dentro de cada grupo o que COMEÇA com o termo vem antes do que só
 * o contém — "Porto" antes de "Vila Nova do Porto".
 */

require_once __DIR__ . '/fut_europa.php';

/** Quantos de cada tipo a busca devolve. */
const FUT_BUSCA_LIMITE = 6;

/** Tira acento e caixa: "Grêmio" tem que ser achado digitando "gremio". */
function futBuscaChave(string $s): string
{
    $s = strtr(mb_strtolower(trim($s), 'UTF-8'), [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e',
        'í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ö'=>'o','ø'=>'o',
        'ú'=>'u','ù'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n','å'=>'a','ß'=>'ss',
    ]);
    return preg_replace('/\s+/', ' ', (string)$s);
}

/**
 * TODAS AS COMPETIÇÕES QUE O JOGO CONHECE.
 *
 * A lista é montada e não escrita: os nomes já existem espalhados por
 * FUT_ESTADUAIS, FUT_REGIONAIS, FUT_LIGAS_EU e pelas divisões brasileiras, e
 * uma segunda lista escrita à mão sairia do lugar na primeira competição nova.
 *
 * @return array nome => ['nome','tipo','div'] — div vazia quando não é liga
 */
function futTodasAsCompeticoes(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    $out = [];
    foreach (['BR1', 'BR2', 'BR3', 'BR4'] as $div) {
        $n = futCarreiraNomeDaDivisao($div);
        if ($n !== '') $out[$n] = ['nome' => $n, 'tipo' => 'liga', 'div' => $div];
    }
    foreach (FUT_LIGAS_EU as $div => $m) {
        $out[$m['nome']] = ['nome' => $m['nome'], 'tipo' => 'liga', 'div' => $div];
        $out[$m['copa']] = ['nome' => $m['copa'], 'tipo' => 'copa', 'div' => ''];
    }
    foreach (FUT_ESTADUAIS as $n) $out[$n] = ['nome' => $n, 'tipo' => 'estadual', 'div' => ''];
    foreach (FUT_REGIONAIS as $n) $out[$n] = ['nome' => $n, 'tipo' => 'copa', 'div' => ''];
    foreach (['Copa do Brasil', 'Libertadores', 'Sul-Americana'] as $n) {
        $out[$n] = ['nome' => $n, 'tipo' => 'copa', 'div' => ''];
    }
    foreach (FUT_CONTINENTAIS_EU as $n) {
        $out[$n] = ['nome' => $n, 'tipo' => 'continental', 'div' => ''];
    }
    return $cache = $out;
}

/**
 * O ÍNDICE DE JOGADORES: todo mundo de todo clube.
 *
 * Inclui quem o técnico já comprou e quem ele vendeu, porque o índice é feito
 * de futElencoNoJogo — o elenco COM o que já aconteceu na carreira aplicado.
 * Um índice do catálogo cru acharia o camisa 9 no clube antigo dele.
 */
function futIndiceDeJogadores(array $estado): array
{
    $out = [];
    foreach (futClubesDoJogo() as $nome => $c) {
        foreach (futElencoNoJogo($estado, $nome, (int)$c['forca']) as $j) {
            $out[] = ['nome' => (string)$j['nome'], 'pos' => (string)($j['pos'] ?? ''),
                      'ovr' => (int)($j['ovr'] ?? 0), 'idade' => (int)($j['idade'] ?? 0),
                      'clube' => $nome];
        }
    }
    return $out;
}

/**
 * Ordena os achados: quem COMEÇA com o termo primeiro.
 *
 * Sem isso, digitar "por" traz "Esporte Clube Porto" antes do "Porto", porque
 * a ordem alfabética não sabe o que a pessoa quis dizer.
 */
function futBuscaOrdenar(array $itens, string $termo, string $campo = 'nome'): array
{
    usort($itens, function ($a, $b) use ($termo, $campo) {
        $ka = futBuscaChave((string)$a[$campo]);
        $kb = futBuscaChave((string)$b[$campo]);
        $pa = str_starts_with($ka, $termo) ? 0 : 1;
        $pb = str_starts_with($kb, $termo) ? 0 : 1;
        if ($pa !== $pb) return $pa <=> $pb;
        return $ka <=> $kb;
    });
    return $itens;
}

/**
 * A BUSCA.
 *
 * @return array ['competicoes'=>[], 'clubes'=>[], 'jogadores'=>[]]
 */
function futCarreiraBuscar(array $estado, string $termo): array
{
    $vazio = ['competicoes' => [], 'clubes' => [], 'jogadores' => []];

    $t = futBuscaChave($termo);
    /* DUAS LETRAS É O PISO. Com uma, "a" casaria com metade do jogo e a lista
       seria um recorte aleatório de seis nomes — pior do que não responder. */
    if (mb_strlen($t) < 2) return $vazio;

    // ── Competições ──────────────────────────────────────────────────
    $comps = [];
    foreach (futTodasAsCompeticoes() as $c) {
        if (str_contains(futBuscaChave($c['nome']), $t)) $comps[] = $c;
    }
    $comps = array_slice(futBuscaOrdenar($comps, $t), 0, FUT_BUSCA_LIMITE);

    // ── Clubes ───────────────────────────────────────────────────────
    $clubes = [];
    foreach (futClubesDoJogo() as $nome => $c) {
        if (!str_contains(futBuscaChave($nome), $t)) continue;
        $clubes[] = [
            'nome'   => $nome,
            'escudo' => (string)($c['escudo'] ?? ''),
            'onde'   => $c['uf'] ?: futPaisDoClube($c),
            'liga'   => futCarreiraRotuloDaDivisao((string)($c['div'] ?? '')),
        ];
    }
    $clubes = array_slice(futBuscaOrdenar($clubes, $t), 0, FUT_BUSCA_LIMITE);

    /* ── Jogadores ────────────────────────────────────────────────────
       O índice só é montado se o termo não achou nada melhor OU se ele é
       longo o bastante pra ser nome de gente. Quem digita "fla" quer o
       Flamengo, e varrer 6.674 jogadores pra isso é gastar trinta
       milissegundos à toa. */
    $jogadores = [];
    if (mb_strlen($t) >= 3) {
        foreach (futIndiceDeJogadores($estado) as $j) {
            if (!str_contains(futBuscaChave($j['nome']), $t)) continue;
            $jogadores[] = $j;
            // Cem candidatos já dão pra ordenar bem; varrer o resto não muda
            // as seis linhas que vão pra tela.
            if (count($jogadores) >= 100) break;
        }
        /* O MELHOR JOGADOR PRIMEIRO, entre os que começam com o termo: quem
           digita "silva" quer o Silva que joga, não o quarto goleiro. */
        $jogadores = futBuscaOrdenar($jogadores, $t);
        usort($jogadores, function ($a, $b) use ($t) {
            $pa = str_starts_with(futBuscaChave($a['nome']), $t) ? 0 : 1;
            $pb = str_starts_with(futBuscaChave($b['nome']), $t) ? 0 : 1;
            if ($pa !== $pb) return $pa <=> $pb;
            return $b['ovr'] <=> $a['ovr'];
        });
        $jogadores = array_slice($jogadores, 0, FUT_BUSCA_LIMITE);
    }

    return ['competicoes' => $comps, 'clubes' => $clubes, 'jogadores' => $jogadores];
}
