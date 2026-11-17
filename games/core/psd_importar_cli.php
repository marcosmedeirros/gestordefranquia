<?php
/**
 * ── FBA SHOWDOWN: O IMPORTADOR DA BASE ───────────────────────────────
 *
 * Monta os três arquivos de dados do jogo a partir da base pública do
 * Pokémon Showdown. É derivado: rodar de novo reescreve tudo.
 *
 *   games/data/psd_tipos.php   a tabela de tipos (18x18)
 *   games/data/psd_golpes.php  só os golpes que algum pool usa
 *   games/data/psd_dex.php     espécie: tipos, stats, nível e pool de golpes
 *
 * ── POR QUE ARQUIVO, E NÃO TABELA ────────────────────────────────────
 *
 * A dex é a mesma pra todo mundo e nunca muda durante uma partida. Em
 * arquivo ela vai no git, sobe no deploy junto com o código, e a partida
 * lê um array já pronto em vez de 6 SELECTs. Tabela só pras partidas e o
 * ranking, que são do jogador.
 *
 * ── DE ONDE VEM ──────────────────────────────────────────────────────
 *
 *   play.pokemonshowdown.com/data/pokedex.json    espécies e base stats
 *   play.pokemonshowdown.com/data/moves.json      golpes
 *   play.pokemonshowdown.com/data/learnsets.json  quem aprende o quê
 *   play.pokemonshowdown.com/data/typechart.js    a tabela de tipos
 *
 * É a mesma base que o Showdown usa pra jogar, então o que aparecer aqui
 * bate com o que o pessoal já conhece de lá — Ice Spinner com 80 de power
 * é 80 nos dois.
 *
 * ── COMO RODAR ───────────────────────────────────────────────────────
 *
 * O PHP desta máquina não tem o pacote de certificados e toda chamada
 * HTTPS morre com "HTTP 0" (o mesmo que acontece no geo_lugares_cli).
 * Então o CLI NÃO baixa nada: ele lê os quatro arquivos de uma pasta que
 * o curl já encheu.
 *
 *   mkdir -p /tmp/psd && cd /tmp/psd
 *   for f in pokedex moves learnsets; do
 *     curl -sO "https://play.pokemonshowdown.com/data/$f.json"; done
 *   curl -sO "https://play.pokemonshowdown.com/data/typechart.js"
 *
 *   php games/core/psd_importar_cli.php --fonte=/tmp/psd
 *   php games/core/psd_importar_cli.php --fonte=/tmp/psd --pool=12
 */

// ─────────────────────────────── argumentos ───────────────────────────────

$opts = getopt('', ['fonte:', 'pool::', 'help']);
if (isset($opts['help']) || !isset($opts['fonte'])) {
    fwrite(STDERR, "uso: php psd_importar_cli.php --fonte=PASTA [--pool=12]\n");
    exit(isset($opts['help']) ? 0 : 1);
}
$FONTE = rtrim((string)$opts['fonte'], "/\\");
/** Quantos golpes ficam disponíveis por espécie. A partida sorteia 4 daqui. */
$POOL  = max(6, min(20, (int)($opts['pool'] ?? 12)));
$SAIDA = dirname(__DIR__) . '/data';

function psdLer(string $caminho): array
{
    if (!is_file($caminho)) {
        fwrite(STDERR, "faltou: $caminho\n");
        exit(1);
    }
    $j = json_decode(file_get_contents($caminho), true);
    if (!is_array($j)) {
        fwrite(STDERR, "json inválido: $caminho\n");
        exit(1);
    }
    return $j;
}

function psdLog(string $m): void { echo $m . "\n"; }

// ─────────────────────────────── a tabela de tipos ───────────────────────────────

/**
 * O typechart vem como JavaScript, não JSON: `exports.BattleTypeChart = {bug:{...}}`.
 * Cada tipo lista o que ele SOFRE (damageTaken), em código:
 *
 *   0 = normal      1 = fraco (x2)      2 = resiste (x0.5)      3 = imune (x0)
 *
 * Aqui a gente inverte pra como o jogo pergunta — "golpe de tipo X contra
 * alvo de tipo Y vale quanto?" — porque é assim que a conta do dano usa.
 */
function psdTabelaDeTipos(string $js): array
{
    $mult = [0 => 1.0, 1 => 2.0, 2 => 0.5, 3 => 0.0];
    $tabela = [];

    // Cada bloco "tipo:{damageTaken:{...}}"; o resto do arquivo (HPivs etc) não interessa.
    if (!preg_match_all('/([a-z]+)\s*:\s*\{\s*damageTaken\s*:\s*\{([^}]*)\}/', $js, $blocos, PREG_SET_ORDER)) {
        fwrite(STDERR, "não consegui ler o typechart\n");
        exit(1);
    }

    foreach ($blocos as $b) {
        $alvo = $b[1];
        preg_match_all('/([A-Za-z]+)\s*:\s*(\d)/', $b[2], $pares, PREG_SET_ORDER);
        foreach ($pares as $p) {
            $atacante = $p[1];
            // Chaves em minúsculo ("prankster") não são tipo, são marcação de efeito.
            if ($atacante[0] !== strtoupper($atacante[0])) continue;
            $tabela[strtolower($atacante)][$alvo] = $mult[(int)$p[2]] ?? 1.0;
        }
    }

    /* CONFERÊNCIA — se a leitura inverter o sentido do código, tudo continua
       "funcionando" e o jogo fica errado em silêncio. Quatro fatos que todo
       mundo sabe de cor pegam isso na hora. */
    $deveSer = [
        ['ice', 'flying', 2.0],      // o 2x do print do Marcos
        ['electric', 'ground', 0.0],
        ['fighting', 'ghost', 0.0],
        ['ghost', 'normal', 0.0],
        ['water', 'fire', 2.0],
        ['fire', 'water', 0.5],
    ];
    foreach ($deveSer as [$a, $d, $esperado]) {
        $achou = $tabela[$a][$d] ?? -1;
        if ($achou !== $esperado) {
            fwrite(STDERR, "tabela de tipos saiu errada: $a -> $d deu $achou, devia dar $esperado\n");
            exit(1);
        }
    }

    ksort($tabela);
    return $tabela;
}

// ─────────────────────────────── o que entra na dex ───────────────────────────────

/**
 * Quem pode ser sorteado numa Random Battle.
 *
 * Fica de fora:
 *  · o que não existe no jogo de verdade (CAP, Pokestar) — `isNonstandard`;
 *  · forma alternativa (Mega, Gmax, regional) — o sprite e o learnset viram
 *    um caso especial cada um, e pra primeira versão não vale o preço;
 *  · quem ainda evolui — `evos`. Charmander contra Dragonite não é partida,
 *    é atropelamento. O Showdown faz o mesmo na Random.
 */
function psdEntraNaDex(array $p): bool
{
    /* ── O QUE "isNonstandard" QUER DIZER ─────────────────────────────
     *
     * A base marca com isso tudo que não existe na geração atual, e o
     * valor diz POR QUE. Cortar todos era um erro meu: "Past" é só
     * "não está em Scarlet/Violet" — Alakazam, Machamp e Pidgeot são
     * Pokémon de verdade, e num jogo de coleção sumir com eles é pior
     * que qualquer impureza de regra.
     *
     * Sai só o que nunca foi um Pokémon jogável de verdade: CAP (carta
     * inventada pela comunidade), Custom e Unobtainable. */
    $fora = ['CAP' => 1, 'Custom' => 1, 'Unobtainable' => 1];
    if (isset($fora[(string)($p['isNonstandard'] ?? '')])) return false;

    if (!empty($p['forme']) || !empty($p['baseSpecies'])) return false;
    if (!empty($p['evos'])) return false;
    if (($p['num'] ?? 0) <= 0) return false;
    return true;
}

/**
 * ── O NÍVEL ──────────────────────────────────────────────────────────
 *
 * Numa Random Battle do Showdown o nível não é 100 pra todo mundo: quanto
 * mais fraca a espécie, mais alto o nível, pra Butterfree ter chance contra
 * Garchomp. Lá o nível vem da tier competitiva; a tier mora num arquivo que
 * o servidor não serve mais (formats-data.json dá 404), então aqui a régua
 * é a SOMA DOS BASE STATS, que é a melhor aproximação pública que existe.
 *
 *   BST 600+  -> 74      BST 500 -> 82      BST 400 -> 90      BST 300 -> 98
 */
function psdNivel(array $baseStats): int
{
    $bst = array_sum($baseStats);
    $nivel = (int)round(122 - ($bst * 0.08));
    return max(70, min(100, $nivel));
}

// ─────────────────────────────── o pool de golpes ───────────────────────────────

/**
 * Golpes de status que valem a pena num pool automático. Sem essa lista, o
 * sorteio pegaria "Splash" e "Growl" e o bicho entraria em quadra desarmado:
 * são centenas de golpes de status e quase nenhum muda uma partida.
 *
 *   [peso, atributo exigido]
 *
 * O ATRIBUTO EXIGIDO existe porque Swords Dance numa Lilligant de atk 60 e
 * spa 110 é um slot jogado fora — e foi exatamente o que a primeira versão
 * fez. Quem sobe ataque físico só entra em quem bate de físico.
 */
const PSD_STATUS_BONS = [
    // encher o atacante
    'swordsdance' => [90, 'atk'], 'dragondance' => [95, 'atk'], 'bulkup'  => [75, 'atk'],
    'victorydance' => [90, 'atk'], 'curse'      => [60, 'atk'], 'howl'    => [45, 'atk'],
    'nastyplot'  => [90, 'spa'], 'calmmind'   => [85, 'spa'], 'quiverdance' => [95, 'spa'],
    'tailglow'   => [95, 'spa'],
    'shellsmash' => [95, null], 'agility' => [60, null], 'irondefense' => [55, null],
    // curar
    'recover' => [85, null], 'roost' => [85, null], 'synthesis' => [75, null],
    'morningsun' => [75, null], 'moonlight' => [75, null], 'slackoff' => [85, null],
    'softboiled' => [85, null], 'shoreup' => [75, null],
    'painsplit' => [60, null], 'rest' => [55, null],
    // atrapalhar o outro
    'thunderwave' => [80, null], 'willowisp' => [80, null], 'toxic' => [80, null],
    'glare' => [75, null], 'spore' => [95, null], 'sleeppowder' => [85, null],
    'hypnosis' => [55, null], 'yawn' => [65, null], 'leechseed' => [75, null],
    'taunt' => [70, null], 'encore' => [65, null], 'confuseray' => [55, null],
    'screech' => [50, null], 'charm' => [55, null], 'tickle' => [50, null],
    // campo e sobrevivência
    'stealthrock' => [85, null], 'spikes' => [70, null], 'toxicspikes' => [60, null],
    'defog' => [65, null], 'substitute' => [75, null], 'protect' => [70, null],
    'haze' => [50, null],
];

/**
 * ── O GOLPE DÁ PRA JOGAR NESTA VERSÃO? ───────────────────────────────
 *
 * Nem todo golpe forte é um golpe bom: Hyper Beam tem 150 de power e um
 * turno de recarga depois; Solar Beam carrega um turno antes; Explosion
 * mata quem usou; Outrage prende por três turnos e confunde no fim.
 *
 * Numa primeira versão sem essas mecânicas, eles entrariam no pool por
 * serem os mais fortes e seriam SÓ a parte boa — 150 de power de graça.
 * Blissey com Hyper Beam não é Blissey.
 *
 * O corte é pela FLAG do próprio dado, e não por lista escrita à mão: é o
 * que faz a regra continuar certa quando a base mudar.
 */
function psdGolpeUsavel(string $id, array $g): bool
{
    $flags = $g['flags'] ?? [];

    if (!empty($flags['charge']))    return false;  // Solar Beam, Fly, Dig, Sky Attack
    if (!empty($flags['recharge']))  return false;  // Hyper Beam, Giga Impact, Blast Burn
    if (isset($g['selfdestruct']))   return false;  // Explosion, Self-Destruct
    if (!empty($g['ohko']))          return false;  // Fissure, Sheer Cold
    if (isset($g['self']['volatileStatus'])) return false;  // Outrage, Petal Dance, Thrash

    /* POWER QUE NÃO É O POWER. Eruption diz 150 mas vale 150 só com a vida
       cheia; Hex diz 65 e dobra se o alvo tem status; Acrobatics dobra sem
       item. Quem tem `basePowerCallback` mente o número no dado, e um motor
       que lê o número cru entrega Eruption de 150 sempre — que é mais forte
       do que o golpe é no jogo de verdade. */
    if (isset($g['basePowerCallback'])) return false;

    /* Os que sobram precisam de uma regra inteira só pra eles. */
    static $fora = [
        'focuspunch' => 1, 'bide' => 1, 'futuresight' => 1, 'doomdesire' => 1,
        'dreameater' => 1, 'lastresort' => 1, 'naturalgift' => 1, 'uproar' => 1,
        'rollout' => 1, 'iceball' => 1, 'fakeout' => 1, 'firstimpression' => 1,
        'bellydrum' => 1, 'memento' => 1, 'healingwish' => 1, 'lunardance' => 1,
        'finalgambit' => 1, 'counter' => 1, 'mirrorcoat' => 1, 'metalburst' => 1,
        'skydrop' => 1, 'shedtail' => 1, 'batonpass' => 1,
        /* Tera Blast só faz sentido com o Tera ligado; sem ele é um golpe
           normal de 80 que entraria no pool de quase todo mundo. */
        'terablast' => 1,
        /* Belch só funciona depois de comer uma Berry, e a condição dele
           não aparece em campo nenhum do dado — então é na mão mesmo.
           Sem itens, ele nunca poderia ser usado. */
        'belch' => 1,
    ];
    return !isset($fora[$id]);
}

/**
 * Monta os golpes disponíveis de uma espécie.
 *
 * A ideia é simples e vale repetir porque é o que decide se o jogo é bom:
 * um pool bom tem (1) golpe do próprio tipo, que é o que mais machuca pelo
 * STAB, (2) COBERTURA — tipos diferentes, pra não existir um adversário que
 * zera você — e (3) uma ou duas cartas de status.
 *
 * Por isso o critério não é "os 12 mais fortes": é o MELHOR DE CADA TIPO,
 * ordenado por quanto machuca. Doze golpes mais fortes de Charizard seriam
 * doze variações de fogo, e aí Blastoise ganharia sempre.
 */
function psdPoolDaEspecie(array $esp, array $legais, array $golpes, int $quantos): array
{
    $tipos = array_map('strtolower', $esp['types']);
    $atk   = (int)$esp['baseStats']['atk'];
    $spa   = (int)$esp['baseStats']['spa'];

    $porTipo = [];   // tipo => melhor golpe de dano daquele tipo
    $status  = [];

    /* De que lado este bicho bate. Decide quais boosts fazem sentido. */
    $bateDe = ($atk >= $spa) ? 'atk' : 'spa';

    foreach ($legais as $id) {
        $g = $golpes[$id] ?? null;
        if (!$g) continue;
        if (!psdGolpeUsavel($id, $g)) continue;

        $cat = strtolower($g['category'] ?? '');

        if ($cat === 'status') {
            if (!isset(PSD_STATUS_BONS[$id])) continue;
            [$peso, $exige] = PSD_STATUS_BONS[$id];
            if ($exige !== null && $exige !== $bateDe) continue;
            $status[$id] = $peso;
            continue;
        }

        $power = (int)($g['basePower'] ?? 0);
        if ($power <= 0) continue;   // golpe de dano fixo/variável: fora da v1

        /* Precisão `true` quer dizer "nunca erra" no formato do Showdown. */
        $acc = $g['accuracy'] ?? true;
        $acc = ($acc === true) ? 1.0 : ((int)$acc / 100);
        if ($acc < 0.7) continue;    // Focus Blast e companhia: frustra mais que ajuda

        $tipo = strtolower($g['type']);
        $stab = in_array($tipo, $tipos, true) ? 1.5 : 1.0;

        /* O golpe só vale o que o ATRIBUTO que ele usa vale: Machamp com um
           golpe especial de 120 bate menos que com um físico de 80. */
        $ataque = ($cat === 'physical') ? $atk : $spa;

        $nota = $power * $acc * $stab * ($ataque / 100);

        if (!isset($porTipo[$tipo]) || $nota > $porTipo[$tipo]['nota']) {
            $porTipo[$tipo] = ['id' => $id, 'nota' => $nota, 'stab' => $stab > 1];
        }
    }

    if (!$porTipo) return [];

    // Os de dano, do que mais machuca pro que menos — STAB na frente da fila.
    $dano = array_values($porTipo);
    usort($dano, fn($a, $b) => ($b['stab'] <=> $a['stab']) ?: ($b['nota'] <=> $a['nota']));

    arsort($status);
    $statusIds = array_slice(array_keys($status), 0, 2);

    $pool = [];
    foreach ($dano as $d) {
        if (count($pool) >= $quantos - count($statusIds)) break;
        $pool[] = $d['id'];
    }
    foreach ($statusIds as $s) {
        if (count($pool) >= $quantos) break;
        $pool[] = $s;
    }

    return $pool;
}

// ─────────────────────────────── roda ───────────────────────────────

psdLog("lendo de $FONTE");

$dexBruta   = psdLer("$FONTE/pokedex.json");
$golpes     = psdLer("$FONTE/moves.json");
$learnsets  = psdLer("$FONTE/learnsets.json");
$typechartJ = @file_get_contents("$FONTE/typechart.js");
if ($typechartJ === false) { fwrite(STDERR, "faltou: $FONTE/typechart.js\n"); exit(1); }

psdLog(sprintf('  pokedex %d · golpes %d · learnsets %d', count($dexBruta), count($golpes), count($learnsets)));

$tipos = psdTabelaDeTipos($typechartJ);
psdLog('  tabela de tipos: ' . count($tipos) . ' tipos, conferida');

$dex = [];
$usados = [];
$semPool = 0;

foreach ($dexBruta as $id => $p) {
    if (!psdEntraNaDex($p)) continue;

    $ls = $learnsets[$id]['learnset'] ?? null;
    if (!$ls) continue;

    /* ── DE QUE GERAÇÃO SÃO OS GOLPES ─────────────────────────────────
     *
     * A fonte vem como "9M", "8L1", "7T": o primeiro dígito é a geração.
     *
     * Misturar gerações daria o mesmo bicho com golpe que ele nunca teve
     * junto. Mas exigir a geração 9 apagava Alakazam, Machamp e Pidgeot
     * — eles não existem em Scarlet/Violet —, e num jogo de COLEÇÃO
     * sumir justo com o que todo mundo procura é pior que a impureza.
     *
     * Então cada espécie usa a geração MAIS RECENTE em que ela apareceu:
     * gen 9 pra quem está lá, gen 8 pra quem parou no Sword/Shield, e
     * assim por diante. Cada um com o moveset que teve de verdade. */
    $maiorGen = 0;
    foreach ($ls as $fontes) {
        foreach ($fontes as $f) {
            if ($f !== '' && $f[0] >= '1' && $f[0] <= '9') $maiorGen = max($maiorGen, (int)$f[0]);
        }
    }
    if (!$maiorGen) continue;

    $legais = [];
    foreach ($ls as $mv => $fontes) {
        foreach ($fontes as $f) {
            if ($f !== '' && (int)$f[0] === $maiorGen) { $legais[] = $mv; break; }
        }
    }
    if (!$legais) continue;

    $pool = psdPoolDaEspecie($p, $legais, $golpes, $POOL);
    if (count($pool) < 4) { $semPool++; continue; }

    foreach ($pool as $m) $usados[$m] = true;

    $dex[$id] = [
        'num'   => (int)$p['num'],
        'nome'  => $p['name'],
        'tipos' => array_map('strtolower', $p['types']),
        'bs'    => [
            (int)$p['baseStats']['hp'],  (int)$p['baseStats']['atk'], (int)$p['baseStats']['def'],
            (int)$p['baseStats']['spa'], (int)$p['baseStats']['spd'], (int)$p['baseStats']['spe'],
        ],
        'nivel' => psdNivel($p['baseStats']),
        'pool'  => $pool,
    ];
}

uasort($dex, fn($a, $b) => $a['num'] <=> $b['num']);
psdLog(sprintf('  dex: %d espécies (%d descartadas por pool curto)', count($dex), $semPool));

/**
 * Só os golpes que algum pool usa — não adianta carregar 954 se o jogo vê 250.
 *
 * Vai junto tudo que o motor precisa pra o golpe ser o golpe de verdade, e
 * não só um número de dano: o RECUO do Flare Blitz, o ROUBO DE VIDA do
 * Draining Kiss, a QUEDA DE DEFESA que o Close Combat cobra de quem usa, a
 * CHANCE DE EFEITO do Thunderbolt e as duas a cinco pancadas do Bullet Seed.
 * É o que separa um jogo de pedra-papel-tesoura de um Showdown.
 */
$golpesSaida = [];
foreach (array_keys($usados) as $id) {
    $g = $golpes[$id];
    $acc = $g['accuracy'] ?? true;

    $m = [
        'nome'  => $g['name'],
        'tipo'  => strtolower($g['type']),
        'cat'   => strtolower($g['category']),
        'power' => (int)($g['basePower'] ?? 0),
        'acc'   => ($acc === true) ? 100 : (int)$acc,   // 100 aqui quer dizer "não erra"
        'pp'    => (int)($g['pp'] ?? 10),
        'prio'  => (int)($g['priority'] ?? 0),
    ];

    if (!empty($g['flags']['contact'])) $m['contato'] = 1;
    if (isset($g['recoil']))   $m['recuo']  = [(int)$g['recoil'][0], (int)$g['recoil'][1]];
    if (isset($g['drain']))    $m['dreno']  = [(int)$g['drain'][0], (int)$g['drain'][1]];
    if (isset($g['multihit'])) $m['vezes']  = is_array($g['multihit'])
        ? [(int)$g['multihit'][0], (int)$g['multihit'][1]]
        : [(int)$g['multihit'], (int)$g['multihit']];
    if (!empty($g['critRatio']) && (int)$g['critRatio'] > 1) $m['crit'] = (int)$g['critRatio'];

    // Status que o golpe de status aplica direto (Thunder Wave -> par).
    if (!empty($g['status']))  $m['status'] = $g['status'];
    if (!empty($g['boosts']))  $m['boosts'] = $g['boosts'];   // alvo
    if (!empty($g['self']['boosts'])) $m['auto'] = $g['self']['boosts'];  // quem usou

    /* Cura. Recover e companhia trazem heal:[1,2] = metade da vida.
       Moonlight, Morning Sun e Shore Up dependem do clima e por isso o
       Showdown resolve por função, sem campo — sem clima na v1, valem
       os mesmos 50%. */
    if (isset($g['heal'])) {
        $m['cura'] = [(int)$g['heal'][0], (int)$g['heal'][1]];
    } elseif (in_array($id, ['moonlight', 'morningsun', 'shoreup'], true)) {
        $m['cura'] = [1, 2];
    }

    // Os que têm regra própria no motor: Substitute, Protect, Leech Seed, Stealth Rock.
    if (!empty($g['volatileStatus']))  $m['volatil'] = $g['volatileStatus'];
    if (!empty($g['sideCondition']))   $m['lado']    = $g['sideCondition'];

    // Efeito secundário de golpe de dano: chance + o que acontece.
    $sec = $g['secondary'] ?? null;
    if (is_array($sec)) {
        $s = ['chance' => (int)($sec['chance'] ?? 100)];
        if (!empty($sec['status']))         $s['status']  = $sec['status'];
        if (!empty($sec['volatileStatus'])) $s['volatil'] = $sec['volatileStatus'];
        if (!empty($sec['boosts']))         $s['boosts']  = $sec['boosts'];
        if (!empty($sec['self']['boosts'])) $s['auto']    = $sec['self']['boosts'];
        if (count($s) > 1) $m['sec'] = $s;
    }

    $golpesSaida[$id] = $m;
}
ksort($golpesSaida);
psdLog('  golpes em uso: ' . count($golpesSaida));

/* CONFERÊNCIA — os armadilha não podem ter sobrado em pool nenhum. */
foreach (['hyperbeam', 'gigaimpact', 'solarbeam', 'blastburn', 'outrage', 'petaldance', 'explosion', 'focuspunch'] as $proibido) {
    if (isset($golpesSaida[$proibido])) {
        fwrite(STDERR, "o corte falhou: $proibido ainda está num pool\n");
        exit(1);
    }
}

// ─────────────────────────────── grava ───────────────────────────────

function psdGravar(string $arquivo, string $titulo, array $dados): void
{
    $cab = "<?php\n/* $titulo — GERADO por games/core/psd_importar_cli.php.\n"
         . "   NÃO EDITE À MÃO: rode o CLI de novo. Gerado em " . date('d/m/Y H:i') . ". */\n"
         . "return ";
    file_put_contents($arquivo, $cab . var_export($dados, true) . ";\n");
    printf("  %s  %s KB\n", basename($arquivo), number_format(filesize($arquivo) / 1024, 0));
}

@mkdir($SAIDA, 0775, true);
psdGravar("$SAIDA/psd_tipos.php",  'Tabela de tipos do FBA Showdown', $tipos);
psdGravar("$SAIDA/psd_golpes.php", 'Golpes do FBA Showdown',          $golpesSaida);
psdGravar("$SAIDA/psd_dex.php",    'Pokédex do FBA Showdown',         $dex);

psdLog('pronto.');
