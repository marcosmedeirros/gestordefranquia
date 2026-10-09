<?php
/**
 * ── LENDAS DA QUADRA: AS CARTAS ──────────────────────────────────────
 *
 * Pedido do Victor (09/10/2026): um RPG de tabuleiro 1×1, com baralho de
 * cartas de jogadores da NBA no papel de heróis, vilões e ferreiros.
 *
 * TODA carta tem um jogador. São três classes:
 *
 *   HERÓI    — o jogador entra no mapa como unidade: ATQ, VIDA, MOVIMENTO,
 *              ALCANCE e uma habilidade do arquétipo dele. São os 481 da
 *              NBA atual, saídos do FBA Hoops (@see hoops_cartas.php).
 *   FERREIRO — um equipamento que se prende num herói seu. Cada efeito é
 *              "forjado" por um jogador com o perfil certo: o Arco Longo
 *              é de um grande arremessador, a Armadura de um reboteiro.
 *   VILÃO    — sabotagem contra o adversário. O Roubo de Bola é obra de
 *              um grande ladrão de bola, a Falta Técnica de um defensor.
 *
 * ── O HERÓI SAI DA ESTATÍSTICA ───────────────────────────────────────
 *
 * As oito notas do FBA Hoops já dizem que tipo de jogador cada um é, então
 * o arquétipo não é escolhido à mão: o pivô reboteiro vira TANQUE, o
 * chutador vira ARQUEIRO, o armador que passa vira ESTRATEGISTA, o
 * defensor de perímetro vira LADINO, e o resto é GUERREIRO.
 *
 * O CUSTO (energia, 1 a 8) sai do OVR, e os ATRIBUTOS saem do custo, que
 * o arquétipo distribui entre ataque e vida. É o que deixa o jogo
 * equilibrável — uma carta de 5 de energia vale mais ou menos o mesmo que
 * outra de 5, só que de jeitos diferentes. As notas do jogador mexem ±1.
 *
 * O poder cresce MAIS que o custo (tem um termo ao quadrado). Na primeira
 * versão era uma reta com intercepto, e o herói de custo 1 rendia o dobro
 * por energia que o de custo 7: na simulação, o baralho inicial — só de
 * baratos — ganhava de um baralho cheio de raras e épicas em 70% das
 * vezes. Carta cara tem que valer o que custa.
 */

require_once __DIR__ . '/hoops.php';

/** Raridade pelo OVR: ~55% comum, ~30% rara, ~12% épica, ~3% lendária. */
function lendasRaridade(int $ovr): string
{
    if ($ovr >= 88) return 'lendaria';
    if ($ovr >= 80) return 'epica';
    if ($ovr >= 72) return 'rara';
    return 'comum';
}

/** Custo em energia pelo OVR. */
function lendasCusto(int $ovr): int
{
    foreach ([64 => 1, 68 => 2, 72 => 3, 76 => 4, 80 => 5, 85 => 6, 90 => 7] as $teto => $custo) {
        if ($ovr <= $teto) return $custo;
    }
    return 8;
}

/**
 * Os cinco arquétipos: como cada um divide o poder, e o que sabe fazer.
 * atq/vida são multiplicadores sobre a base do custo.
 */
const LENDAS_ARQUETIPOS = [
    'tanque' => [
        'nome' => 'Tanque', 'icone' => 'bi-shield-fill',
        'atq' => 0.70, 'vida' => 1.45, 'mov' => 1, 'alc' => 1,
        'hab' => 'Muralha', 'hab_desc' => 'Aliados vizinhos recebem 1 de dano a menos.',
    ],
    'guerreiro' => [
        'nome' => 'Guerreiro', 'icone' => 'bi-lightning-charge-fill',
        'atq' => 1.10, 'vida' => 1.05, 'mov' => 2, 'alc' => 1,
        'hab' => 'Investida', 'hab_desc' => '+1 ATQ quando ataca depois de andar no mesmo turno.',
    ],
    'arqueiro' => [
        'nome' => 'Arqueiro', 'icone' => 'bi-bullseye',
        'atq' => 0.80, 'vida' => 0.65, 'mov' => 2, 'alc' => 3,
        'hab' => 'Chuva de 3', 'hab_desc' => 'Acerto crítico com 19 ou 20 no d20.',
    ],
    'estrategista' => [
        'nome' => 'Estrategista', 'icone' => 'bi-diagram-3-fill',
        'atq' => 0.80, 'vida' => 0.85, 'mov' => 2, 'alc' => 2,
        'hab' => 'Assistência', 'hab_desc' => 'No começo do seu turno, aliados vizinhos ganham +1 ATQ até o fim dele.',
    ],
    'ladino' => [
        'nome' => 'Ladino', 'icone' => 'bi-incognito',
        'atq' => 0.95, 'vida' => 0.80, 'mov' => 3, 'alc' => 1,
        'hab' => 'Furtivo', 'hab_desc' => 'Ataca sem sofrer revide.',
    ],
];

/** O arquétipo de um jogador, pelas notas e pela posição. */
function lendasArquetipo(array $c): string
{
    $at = $c['at'];
    if ($c['pos'] === 'C' || ($c['pos'] === 'PF' && ($at['reb'] + $at['dgar']) / 2 >= 75)) return 'tanque';
    if ($at['pas'] >= 78 && in_array($c['pos'], ['PG', 'SG'], true)) return 'estrategista';
    if ($at['arr3'] >= 76) return 'arqueiro';
    if ($at['dper'] >= 76) return 'ladino';
    return 'guerreiro';
}

/** Um jogador do FBA Hoops vira herói. */
function lendasHeroi(array $c): array
{
    $custo = lendasCusto($c['ovr']);
    $tipo  = lendasArquetipo($c);
    $a     = LENDAS_ARQUETIPOS[$tipo];
    $at    = $c['at'];

    $atq  = (0.4 + 0.95 * $custo + 0.06 * $custo ** 2) * $a['atq'];
    $vida = (1.0 + 1.55 * $custo + 0.12 * $custo ** 2) * $a['vida'];
    // As notas mexem pouco: quem finaliza ou arremessa muito bate um pouco
    // mais; quem pega rebote e protege o aro aguenta um pouco mais.
    $atq  += max(-1, min(1, round((max($at['fin'], $at['arr3'], $at['meia']) - 75) / 12)));
    $vida += max(-1, min(2, round((($at['reb'] + $at['dgar']) / 2 - 70) / 10)));

    return [
        'id'     => 'h-' . $c['id'],
        'classe' => 'heroi',
        'nome'   => $c['nome'],
        'foto'   => $c['foto'],
        'time'   => $c['time'],
        'time_sigla' => $c['time_sigla'],
        'logo'   => $c['time_logo'],
        'pos'    => $c['pos'],
        'ovr'    => $c['ovr'],
        'rar'    => lendasRaridade($c['ovr']),
        'custo'  => $custo,
        'tipo'   => $tipo,
        'atq'    => max(1, (int)round($atq)),
        'vida'   => max(1, (int)round($vida)),
        'mov'    => $a['mov'],
        'alc'    => $a['alc'],
        'hab'    => $a['hab'],
        'hab_desc' => $a['hab_desc'],
    ];
}

/**
 * ── FERREIROS E VILÕES ───────────────────────────────────────────────
 *
 * Cada efeito existe em quatro raridades; a mais rara é mais forte e custa
 * mais. `nota` diz que perfil de jogador "forja" ou "comete" aquele
 * efeito — é por ela que o ilustrador de cada versão é escolhido.
 *
 * Os números em `forca` são, na ordem: comum, rara, épica, lendária.
 * `{n}` no texto é trocado pelo número da raridade.
 */
const LENDAS_FERREIROS = [
    'luvas'    => ['nome' => 'Luvas de Aço',        'nota' => 'fin',  'custo' => [1, 2, 3, 4], 'forca' => [1, 2, 3, 4],
                   'desc' => 'O herói ganha +{n} ATQ.'],
    'armadura' => ['nome' => 'Armadura de Garrafão','nota' => 'reb',  'custo' => [1, 2, 3, 4], 'forca' => [2, 4, 6, 8],
                   'desc' => 'O herói ganha +{n} VIDA (e cura o mesmo tanto).'],
    'tenis'    => ['nome' => 'Tênis Alado',         'nota' => 'dper', 'custo' => [1, 2, 3, 4], 'forca' => [1, 1, 2, 2],
                   'desc' => 'O herói anda +{n} casa(s) por turno.'],
    'arco'     => ['nome' => 'Arco Longo',          'nota' => 'arr3', 'custo' => [2, 3, 4, 5], 'forca' => [1, 1, 2, 2],
                   'desc' => 'O herói ataca a +{n} casa(s) de distância.'],
    'escudo'   => ['nome' => 'Escudo de Toco',      'nota' => 'dgar', 'custo' => [1, 2, 2, 3], 'forca' => [1, 1, 2, 3],
                   'desc' => 'Anula os próximos {n} ataque(s) contra o herói.'],
    'amuleto'  => ['nome' => 'Amuleto do Clutch',   'nota' => 'meia', 'custo' => [1, 2, 3, 4], 'forca' => [19, 18, 17, 16],
                   'desc' => 'O herói acerta crítico com {n} ou mais no d20.'],
    'bandagem' => ['nome' => 'Bandagem do Vestiário','nota' => 'pas', 'custo' => [1, 1, 2, 3], 'forca' => [3, 5, 8, 99],
                   'desc' => 'Cura {n} de vida de um herói seu. (99 = cura total)'],
];

const LENDAS_VILOES = [
    // Era "Lesão", e ficava ilustrada pelo Jokić: carta de machucar alguém
    // com rosto de pessoa real não cai bem. É a enterrada na cabeça do
    // adversário — o mesmo dano, e combina com quem finaliza bem.
    'poster'   => ['nome' => 'Pôster',              'nota' => 'fin',  'custo' => [1, 2, 3, 4], 'forca' => [2, 3, 5, 7],
                   'desc' => 'Enterrada na cabeça: causa {n} de dano a um herói inimigo.'],
    'falta'    => ['nome' => 'Falta Técnica',       'nota' => 'dper', 'custo' => [2, 3, 3, 4], 'forca' => [1, 1, 2, 2],
                   'desc' => 'Um herói inimigo fica atordoado por {n} turno(s): não anda nem ataca.'],
    'maldicao' => ['nome' => 'Maldição do Aro',     'nota' => 'dgar', 'custo' => [1, 2, 3, 3], 'forca' => [1, 2, 3, 4],
                   'desc' => 'Um herói inimigo perde {n} ATQ por 2 turnos.'],
    'marcacao' => ['nome' => 'Marcação Dupla',      'nota' => 'ctrl', 'custo' => [1, 1, 2, 3], 'forca' => [1, 1, 2, 3],
                   'desc' => 'Até {n} herói(s) inimigo(s) não anda(m) no próximo turno.'],
    'roubo'    => ['nome' => 'Roubo de Bola',       'nota' => 'dper', 'custo' => [2, 2, 3, 4], 'forca' => [1, 1, 2, 2],
                   'desc' => 'O rival descarta {n} carta(s) da mão, sorteada(s).'],
    'torcida'  => ['nome' => 'Pressão da Torcida',  'nota' => 'ctrl', 'custo' => [2, 3, 4, 5], 'forca' => [2, 4, 6, 8],
                   'desc' => 'Causa {n} de dano direto na Fortaleza inimiga.'],
    'apagao'   => ['nome' => 'Apagão no Ginásio',   'nota' => 'pas',  'custo' => [2, 3, 4, 5], 'forca' => [1, 1, 2, 3],
                   'desc' => 'O rival começa o próximo turno com {n} de energia a menos.'],
];

const LENDAS_RARIDADES = ['comum', 'rara', 'epica', 'lendaria'];

/**
 * As cartas de efeito, com o ilustrador de cada uma.
 *
 * O ranking da nota do efeito escolhe quem ilustra: a lendária fica com o
 * 1º do ranking, a épica com o 3º, a rara com o 10º, a comum com o 40º —
 * ninguém repete entre cartas, então o jogador que é o rosto da "Arco
 * Longo lendária" não aparece em outra carta de efeito.
 */
function lendasEfeitos(array $herois): array
{
    $posicaoNoRanking = ['lendaria' => 0, 'epica' => 2, 'rara' => 9, 'comum' => 39];
    $usados = [];
    $saida = [];
    foreach (['ferreiro' => LENDAS_FERREIROS, 'vilao' => LENDAS_VILOES] as $classe => $catalogo) {
        foreach ($catalogo as $chave => $e) {
            $ranking = $herois;
            usort($ranking, fn($a, $b) => $b['_at'][$e['nota']] <=> $a['_at'][$e['nota']] ?: $b['ovr'] <=> $a['ovr']);
            foreach (LENDAS_RARIDADES as $i => $rar) {
                // Do lugar do ranking pra baixo, o primeiro que ainda não ilustra nada.
                $ilustra = null;
                for ($k = $posicaoNoRanking[$rar]; $k < count($ranking); $k++) {
                    if (!isset($usados[$ranking[$k]['id']])) { $ilustra = $ranking[$k]; break; }
                }
                $usados[$ilustra['id']] = true;
                $n = $e['forca'][$i];
                $saida[] = [
                    'id'     => ($classe === 'ferreiro' ? 'f-' : 'v-') . "{$chave}-{$rar}",
                    'classe' => $classe,
                    'efeito' => $chave,
                    'nome'   => $e['nome'],
                    'rar'    => $rar,
                    'custo'  => $e['custo'][$i],
                    'forca'  => $n,
                    'desc'   => str_replace('{n}', (string)$n, $e['desc']),
                    'jogador'=> $ilustra['nome'],
                    'foto'   => $ilustra['foto'],
                    'time'   => $ilustra['time'],
                    'logo'   => $ilustra['logo'],
                ];
            }
        }
    }
    return $saida;
}

/**
 * O catálogo inteiro: heróis, ferreiros e vilões.
 *
 * Montá-lo custa uns 70ms (ler as 1.300 cartas do FBA Hoops e ordenar os
 * rankings dos ilustradores), e isso era pago em CADA jogada. Agora ele é
 * guardado pronto em games/data/lendas_catalogo.php e só é refeito quando
 * as cartas do Hoops ou este arquivo mudam. Se a pasta não deixar gravar,
 * tudo continua funcionando — só sem o atalho.
 */
function lendasCatalogo(): array
{
    static $cat = null;
    if ($cat !== null) return $cat;
    $guardado = __DIR__ . '/../data/lendas_catalogo.php';
    $fontes = max(@filemtime(__DIR__ . '/../data/hoops_cartas.php') ?: 0, @filemtime(__FILE__) ?: 0);
    if (is_file($guardado) && filemtime($guardado) >= $fontes) {
        $cat = require $guardado;
        if (is_array($cat) && $cat) return $cat;
    }
    $cat = lendasMontarCatalogo();
    @file_put_contents($guardado, "<?php\n/* Gerado por lendasCatalogo() — NÃO EDITE À MÃO. */\nreturn " . var_export($cat, true) . ";\n", LOCK_EX);
    return $cat;
}

function lendasMontarCatalogo(): array
{
    $herois = [];
    foreach (hoopsDados()['cartas'] as $c) {
        if ($c['liga'] !== 'NBA') continue;
        $h = lendasHeroi($c);
        $h['_at'] = $c['at']; // só pra escolher ilustrador; sai abaixo
        $herois[] = $h;
    }
    $efeitos = lendasEfeitos($herois);
    foreach ($herois as &$h) unset($h['_at']);
    unset($h);
    $cat = [];
    foreach (array_merge($herois, $efeitos) as $c) $cat[$c['id']] = $c;
    return $cat;
}

/**
 * ── O BARALHO INICIAL ────────────────────────────────────────────────
 *
 * Trinta cartas, fracas, com a MESMA composição pra todo mundo — ninguém
 * começa na frente. Só os rostos dos heróis mudam (sorteados entre as
 * comuns, com a semente do GM, pra o baralho dele ser sempre o mesmo):
 *
 *   16 heróis comuns ... 5 de custo 1 · 6 de custo 2 · 5 de custo 3
 *    7 ferreiros comuns  2 Bandagem · 2 Luvas · Armadura · Tênis · Escudo
 *    7 vilões comuns ... 2 Pôster · 2 Falta Técnica · Torcida · Maldição · Roubo
 */
const LENDAS_INICIAL_HEROIS = [1 => 5, 2 => 6, 3 => 5];
const LENDAS_INICIAL_EFEITOS = [
    'f-bandagem-comum' => 2, 'f-luvas-comum' => 2, 'f-armadura-comum' => 1, 'f-tenis-comum' => 1, 'f-escudo-comum' => 1,
    'v-poster-comum' => 2, 'v-falta-comum' => 2, 'v-torcida-comum' => 1, 'v-maldicao-comum' => 1, 'v-roubo-comum' => 1,
];

/** @return string[] os 30 ids do baralho inicial de um GM */
function lendasBaralhoInicial(int $uid): array
{
    $porCusto = [];
    foreach (lendasCatalogo() as $c) {
        if ($c['classe'] === 'heroi' && $c['rar'] === 'comum') $porCusto[$c['custo']][] = $c['id'];
    }
    mt_srand($uid * 7919 + 13);
    $baralho = [];
    foreach (LENDAS_INICIAL_HEROIS as $custo => $qtd) {
        $lista = $porCusto[$custo] ?? [];
        for ($i = count($lista) - 1; $i > 0; $i--) { $j = mt_rand(0, $i); [$lista[$i], $lista[$j]] = [$lista[$j], $lista[$i]]; }
        $baralho = array_merge($baralho, array_slice($lista, 0, $qtd));
    }
    mt_srand();
    foreach (LENDAS_INICIAL_EFEITOS as $id => $qtd) for ($i = 0; $i < $qtd; $i++) $baralho[] = $id;
    return $baralho;
}
