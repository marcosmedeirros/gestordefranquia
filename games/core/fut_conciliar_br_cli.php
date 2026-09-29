<?php
/**
 * PÕE OS ELENCOS DA SÉRIE A EM DIA COM QUEM ESTÁ NO CLUBE HOJE.
 *
 *   php games/core/fut_conciliar_br_cli.php [--gravar] [--so=Palmeiras]
 *
 * ── O PROBLEMA ───────────────────────────────────────────────────────
 *
 * Os elencos brasileiros vieram de uma fonte de setembro e envelheceram:
 * medindo contra o elenco de hoje, 71% dos jogadores ainda estão no clube. O
 * Palmeiras estava pior — doze dos dezoito já tinham saído, e o melhor deles,
 * que a tela mostrava com 87, está na Inglaterra.
 *
 * ── DE ONDE VEM CADA PEDAÇO, E O QUE É MODELO ────────────────────────
 *
 * QUEM ESTÁ NO CLUBE vem da API pública do Cartola. É a única fonte aberta com
 * o elenco atual de toda a Série A, e é autoridade sobre uma coisa só: a lista
 * de nomes. Ela não tem idade, não tem nota, e junta volante com meia.
 *
 * IDADE E POSIÇÃO de quem chegou vêm do thesportsdb, e SÓ ENTRAM SE A FONTE
 * CONFIRMAR O CLUBE. Jogador que ela não confirma fica de fora do elenco em
 * vez de entrar com data inventada — o clube perde um nome e não ganha uma
 * mentira, e como o Cartola lista 31 a 44 jogadores por clube e o jogo usa no
 * máximo 30, sobra gente de verdade pra preencher.
 *
 * O OVERALL É A ÚNICA COISA MODELADA, porque não existe em fonte aberta
 * nenhuma. Não é chute: é a mesma curva que o jogo já usa pros 150 clubes sem
 * lista (@see futOverallDoJogador), alimentada por dois fatos —
 * a idade de verdade e QUANTOS JOGOS ele fez na temporada, que é o que separa
 * titular de reserva. No fim o elenco inteiro é deslocado pra bater com a
 * força do clube no catálogo, do mesmo jeito que o importador já faz.
 *
 * QUEM JÁ ESTAVA E CONTINUA NO CLUBE NÃO É TOCADO: nota e idade dele vieram de
 * valor de mercado real, que é melhor do que qualquer coisa que eu calcule.
 */

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/fut_clubes_br.php';
require_once __DIR__ . '/fut_importar_ovr.php';
require_once __DIR__ . '/fut_carreira.php';   // FUT_ELENCO_MINIMO e MAXIMO

$gravar = in_array('--gravar', $argv, true);
$so = '';
foreach ($argv as $a) if (str_starts_with($a, '--so=')) $so = substr($a, 5);
$forcado = 0;
foreach ($argv as $a) if (str_starts_with($a, '--forca=')) $forcado = (int)substr($a, 8);

/** A sigla do Cartola => o nome do clube no jogo. */
const CLUBE_CARTOLA = [
    'FLA' => 'Flamengo',      'BOT' => 'Botafogo',      'COR' => 'Corinthians',
    'BAH' => 'Bahia',         'FLU' => 'Fluminense',    'VAS' => 'Vasco da Gama',
    'PAL' => 'Palmeiras',     'SAO' => 'São Paulo',     'SAN' => 'Santos',
    'RBB' => 'RB Bragantino', 'CAM' => 'Atlético-MG',   'CRU' => 'Cruzeiro',
    'GRE' => 'Grêmio',        'INT' => 'Internacional', 'VIT' => 'Vitória',
    'CAP' => 'Athletico-PR',  'MIR' => 'Mirassol',      'CFC' => 'Coritiba',
    'CHA' => 'Chapecoense',   'REM' => 'Remo',
];

/** As cinco posições do Cartola nas sete do jogo — o que der pra saber. */
const POS_CARTOLA = [1 => 'GOL', 2 => 'LAT', 3 => 'ZAG', 4 => 'MEI', 5 => 'ATA'];

/**
 * A POSIÇÃO DETALHADA DO THESPORTSDB nas sete do jogo.
 *
 * É ela que devolve o que o Cartola perde: "Defensive Midfield" é volante e
 * "Attacking Midfield" é meia, mas pro Cartola os dois são "Meia". Mesma coisa
 * com ponta e centroavante.
 */
const POS_SDB = [
    'goalkeeper' => 'GOL',
    'centre-back' => 'ZAG', 'center-back' => 'ZAG', 'sweeper' => 'ZAG',
    'left-back' => 'LAT', 'right-back' => 'LAT', 'left wing-back' => 'LAT', 'right wing-back' => 'LAT',
    'defensive midfield' => 'VOL', 'defensive midfielder' => 'VOL',
    'central midfield' => 'MEI', 'attacking midfield' => 'MEI',
    'left midfield' => 'PON', 'right midfield' => 'PON',
    'left winger' => 'PON', 'right winger' => 'PON', 'winger' => 'PON',
    'centre-forward' => 'ATA', 'center-forward' => 'ATA', 'second striker' => 'ATA',
    'striker' => 'ATA',
];

/**
 * TERMO GENÉRICO NÃO MANDA NO REGISTRO DO CLUBE.
 *
 * O thesportsdb chama de "Defender" tanto o zagueiro quanto o lateral. Deixando
 * isso vencer, o Agustín Giay e o Arthur Gabriel — laterais do Palmeiras, e
 * assim registrados no Cartola — viravam zagueiros no jogo. O Cartola é o
 * registro do próprio clube: quando a outra fonte fala por cima dele, ela
 * precisa ser ESPECÍFICA pra ter razão.
 */
const POS_SDB_VAGA = ['defender', 'midfielder', 'forward', 'attacker', 'defence', 'midfield'];

/**
 * O QUE CADA POSIÇÃO DO CARTOLA ACEITA como detalhamento.
 *
 * Serve de segunda trava: se a outra fonte diz "Centre-Back" de quem o clube
 * registrou como lateral, não é detalhe — é outro jogador com nome parecido, e
 * aí a idade que viria junto também estaria errada. Melhor perder o jogador.
 */
const POS_COMPATIVEL = [
    'GOL' => ['GOL'], 'LAT' => ['LAT'], 'ZAG' => ['ZAG'],
    /* 'Meia' do Cartola pega o ponta junto: as categorias de la sao de
       pontuacao de fantasy, nao de campo — quem joga pela ponta marca como
       meia. Recusando PON aqui, o Jhon Arias era descartado como se fosse
       outro jogador. */
    'MEI' => ['VOL', 'MEI', 'PON'], 'ATA' => ['PON', 'ATA'],
];

/** Tira acento e pontuação, pra "Éverton" bater com "Everton". */
function conc_chave(string $n): string
{
    $n = strtr(mb_strtolower($n, 'UTF-8'), [
        'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','ê'=>'e','è'=>'e',
        'í'=>'i','ì'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ò'=>'o','ú'=>'u','ü'=>'u',
        'ç'=>'c','ñ'=>'n','ý'=>'y',
    ]);
    return trim(preg_replace('/\s+/', ' ', preg_replace('/[^a-z0-9 ]/', ' ', $n)));
}

/**
 * ACHA O JOGADOR DO ARQUIVO PELO APELIDO DO CARTOLA.
 *
 * O Cartola chama de "Piquerez" quem o arquivo chama de "Joaquín Piquerez", e
 * de "Arias" quem o arquivo chama de "Jhon Arias". Casando só pelo nome
 * inteiro, esses dois apareciam como se tivessem saído E chegado no mesmo dia
 * — perdendo a nota e a idade reais que o arquivo já tinha deles.
 *
 * O SOBRENOME SÓ VALE QUANDO É ÚNICO. Metade do elenco brasileiro se chama
 * Gabriel ou Rodrigo; se dois jogadores do clube terminam igual, o palpite é
 * abandonado e o jogador entra como chegada, que é o erro menos grave.
 *
 * @param array $porChave nome normalizado => jogador
 * @return string|null a chave do arquivo, ou null
 */
function conc_casar(string $apelido, array $porChave): ?string
{
    $k = conc_chave($apelido);
    if (isset($porChave[$k])) return $k;

    $cand = [];
    foreach (array_keys($porChave) as $outro) {
        // "piquerez" dentro de "joaquin piquerez", e vice-versa.
        if (str_contains(' ' . $outro . ' ', ' ' . $k . ' ')
            || str_contains(' ' . $k . ' ', ' ' . $outro . ' ')) {
            $cand[] = $outro;
        }
    }
    return count($cand) === 1 ? $cand[0] : null;
}

function conc_buscar(string $url): ?array
{
    static $ctx = null;
    if ($ctx === null) $ctx = stream_context_create(['http' => ['timeout' => 25, 'user_agent' => 'Mozilla/5.0']]);

    $cache = sys_get_temp_dir() . '/fut_conc_' . md5($url) . '.json';
    if (is_file($cache)) {
        $j = json_decode((string)file_get_contents($cache), true);
        if (is_array($j)) return $j;
    }
    for ($i = 1; $i <= 3; $i++) {
        $d = @file_get_contents($url, false, $ctx);
        if ($d !== false) {
            $j = json_decode($d, true);
            if (is_array($j)) {
                // Resposta vazia não vira cache: a fonte devolve 200 com null
                // quando está limitando, e isso congelaria o jogador como perdido.
                $tem = false;
                foreach ($j as $v) if (is_array($v)) $tem = true;
                if ($tem) { file_put_contents($cache, json_encode($j)); return $j; }
            }
        }
        sleep($i * 2);
    }
    return null;
}

/**
 * A FICHA DE QUEM CHEGOU, se a fonte confirmar que ele está nesse clube.
 *
 * A confirmação do clube é o que separa este jogador do homônimo: "Gabriel" e
 * "Rodrigo" existem aos montes no futebol brasileiro, e pegar a data de
 * nascimento do primeiro resultado poria um garoto de 18 no lugar de um
 * veterano de 34.
 *
 * @return array{idade:int,pos:string}|null
 */
function conc_ficha(string $nome, string $clube, string $posCartola, string $inteiro = ''): ?array
{
    /* DUAS GRAFIAS: o apelido e o nome de registro. "Piquerez" sozinho pode
       não achar ninguém, e "Joaquín Piquerez" acha; já "Vitor Roque" acha pelo
       apelido e o nome de registro dele é comprido demais pra bater. */
    $j = ['player' => []];
    foreach (array_unique(array_filter([$nome, $inteiro])) as $termo) {
        $r = conc_buscar('https://www.thesportsdb.com/api/v1/json/3/searchplayers.php?p=' . rawurlencode($termo));
        foreach ($r['player'] ?? [] as $x) $j['player'][] = $x;
    }
    $alvo = conc_chave($clube);

    foreach ($j['player'] ?? [] as $p) {
        $time = conc_chave((string)($p['strTeam'] ?? ''));
        if ($time === '' || (strpos($time, $alvo) === false && strpos($alvo, $time) === false)) continue;

        $nasc = (string)($p['dateBorn'] ?? '');
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $nasc, $m)) continue;

        $idade = (int)((new DateTime('2026-01-01'))->diff(new DateTime($nasc))->y);
        if ($idade < 15 || $idade > 45) continue;

        $det = mb_strtolower(trim((string)($p['strPosition'] ?? '')), 'UTF-8');
        $fina = in_array($det, POS_SDB_VAGA, true) ? null : (POS_SDB[$det] ?? null);

        // Contradição entre as duas fontes: é outro jogador, e a idade dele
        // também não serve.
        $aceita = POS_COMPATIVEL[$posCartola] ?? [$posCartola];
        if ($fina !== null && !in_array($fina, $aceita, true)) continue;

        return ['idade' => $idade, 'pos' => $fina ?? $posCartola];
    }
    return null;
}

// ═════════════════════════════════════════════════════════════════════
$mercado = conc_buscar('https://api.cartola.globo.com/atletas/mercado');
if (!$mercado) { fwrite(STDERR, "o mercado do Cartola não respondeu\n"); exit(1); }

$sigla = [];
foreach ($mercado['clubes'] as $id => $c) $sigla[$id] = $c['abreviacao'] ?? '';

$hoje = [];
foreach ($mercado['atletas'] as $a) {
    if ((int)$a['posicao_id'] === 6) continue;                     // técnico
    $clube = CLUBE_CARTOLA[$sigla[$a['clube_id']] ?? ''] ?? null;
    if (!$clube) continue;
    $hoje[$clube][] = [
        'nome'    => (string)$a['apelido'],
        'inteiro' => (string)($a['nome'] ?? ''),
        'pos'     => POS_CARTOLA[(int)$a['posicao_id']] ?? 'MEI',
        'jogos'   => (int)$a['jogos_num'],
    ];
}

$dir = __DIR__ . '/../data/elencos/';
$catalogo = futClubesDoBrasil();
$relatorio = [];

foreach ($hoje as $clube => $doCartola) {
    if ($so !== '' && $clube !== $so) continue;

    $arq = $dir . futSlugDoClube($clube) . '.php';
    $antigo = is_file($arq) ? require $arq : [];

    $porChave = [];
    foreach ($antigo as $j) $porChave[conc_chave($j['nome'])] = $j;

    /* A FORÇA-ALVO É A DE ANTES DA FAXINA. O clube não ficou pior porque
       trocou de elenco — trocar de elenco é o que todo clube faz. Medir depois
       de tirar os que saíram faria o Palmeiras desabar por ter vendido bem. */
    /* CLUBE SEM ARQUIVO CAI NO CATALOGO, e o ?: nao dava conta disso:
       futForcaDoElenco([]) devolve 40, que e verdadeiro, entao o Remo — que
       subiu da terceira divisao e nunca teve lista — seria montado no nivel de
       um time de Serie D dentro da Serie A. */
    /* CLUBE SEM ARQUIVO CAI NO CATALOGO, e o ?: nao dava conta disso:
       futForcaDoElenco([]) devolve 40, que e verdadeiro, entao um clube sem
       lista seria montado no nivel de um time de Serie D dentro da Serie A.

       E CLUBE QUE SUBIU DE DIVISAO PRECISA DE --forca. O arquivo dele e do
       nivel da divisao de onde ele veio: conciliar o Remo mantendo a forca
       antiga poe uma Serie C dentro da Serie A, trinta pontos abaixo do
       Flamengo. O nome dos jogadores a fonte da; o nivel novo, nao. */
    $alvo = $forcado ?: ($antigo ? futForcaDoElenco($antigo) : (int)($catalogo[$clube]['forca'] ?? 60));

    $maxJogos = max(1, max(array_column($doCartola, 'jogos')));
    $novo = [];
    $ficaram = $chegaram = $semFonte = 0;
    $sairam = [];

    foreach ($doCartola as $c) {
        $k = conc_casar($c['nome'], $porChave);
        if ($k !== null) {
            $novo[] = $porChave[$k];                 // já era daqui: não se mexe
            unset($porChave[$k]);
            $ficaram++;
            continue;
        }

        $f = conc_ficha($c['nome'], $clube, $c['pos'], $c['inteiro']);
        if (!$f) { $semFonte++; continue; }

        /* O POSTO VEM DE QUANTOS JOGOS ELE FEZ. Titular joga quase tudo e cai
           no topo da curva do clube; quem entrou em duas partidas cai no fim
           dela. É o único sinal de nível que existe em fonte aberta. */
        $papel = $c['jogos'] / $maxJogos;
        $posto = (int)max(1, min(25, round(25 - $papel * 22)));

        $semente = crc32($clube . '|' . $c['nome']) & 0x7FFFFFFF;
        $novo[] = [
            'nome'  => $c['nome'],
            'pos'   => $f['pos'],
            'ovr'   => futOverallDoJogador($alvo, $posto, $f['idade'], $semente),
            'idade' => $f['idade'],
        ];
        $chegaram++;
    }

    foreach ($porChave as $j) $sairam[] = $j['nome'];

    if (count($novo) < FUT_ELENCO_MINIMO) {
        $relatorio[] = sprintf('%-16s PULADO — sobraria com %d jogadores', $clube, count($novo));
        continue;
    }

    /* O ELENCO DO JOGO PARA EM 30 e o Cartola lista até 44: entra quem joga.
       Cortar pelos jogos e não pelo overall é de propósito — o overall dos que
       chegaram é modelo, e deixá-lo escolher quem fica seria o modelo
       decidindo o elenco. */
    usort($novo, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
    $novo = array_slice($novo, 0, FUT_ELENCO_MAXIMO);
    $novo = futOvrAjustarParaForca($novo, $alvo);

    $relatorio[] = sprintf('%-16s %2d ficaram, %2d chegaram, %2d saíram, %2d sem fonte -> %d no elenco (força %d)',
        $clube, $ficaram, $chegaram, count($sairam), $semFonte, count($novo), futForcaDoElenco($novo));

    if ($gravar) futOvrGravar($clube, $novo);
}

echo implode("\n", $relatorio) . "\n";
echo "\n" . ($gravar ? 'GRAVADO' : 'nada gravado — use --gravar') . "\n";
