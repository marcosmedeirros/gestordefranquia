<?php
/**
 * ── FBA SHOWDOWN: O MOTOR DA PARTIDA ─────────────────────────────────
 *
 * A partida inteira é UM estado (um array, guardado em JSON no banco), e só
 * este arquivo o modifica. Os dois jogadores mandam uma intenção — "uso o
 * golpe 2", "troco pro 4º" — e o motor confere, aplica e devolve a LISTA DE
 * EVENTOS pra tela animar. O navegador não decide dado, dano nem acerto.
 *
 * ── OS DOIS ESCOLHEM AO MESMO TEMPO ──────────────────────────────────
 *
 * É a diferença pro Lendas da Quadra, e é o que faz este jogo ser este
 * jogo: ninguém joga "na vez". Os dois mandam a escolha escondida e o turno
 * resolve de uma vez, na ordem de PRIORIDADE e depois VELOCIDADE. É daí que
 * vem a leitura — "ele vai trocar, então eu chuto o troco".
 *
 * Por isso psdResolverTurno() recebe as DUAS escolhas juntas. Enquanto
 * faltar uma, não existe turno pra resolver.
 *
 * ── O SORTEIO É DO ESTADO ────────────────────────────────────────────
 *
 * Crítico, acerto, dano variável e o time sorteado saem de uma semente
 * guardada no próprio estado (`rng`), que avança a cada uso. A mesma
 * partida com as mesmas escolhas dá o mesmo resultado — é o que deixa
 * reproduzir uma partida pra investigar reclamação, e o que deixa a
 * simulação em massa ser repetível.
 *
 * ── O QUE A v1 NÃO TEM ───────────────────────────────────────────────
 *
 * Item, habilidade, clima, terreno e Tera. O importador já corta do pool
 * todo golpe que dependeria de mecânica ausente, então nada entra em
 * quadra pela metade: o que está lá funciona inteiro.
 *
 * @see games/core/psd_importar_cli.php — de onde vêm dex, golpes e tipos
 */

// ─────────────────────────────── a base ───────────────────────────────

function psdDex(): array
{
    static $d = null;
    return $d ??= require __DIR__ . '/../data/psd_dex.php';
}

function psdGolpes(): array
{
    static $g = null;
    return $g ??= require __DIR__ . '/../data/psd_golpes.php';
}

function psdTipos(): array
{
    static $t = null;
    return $t ??= require __DIR__ . '/../data/psd_tipos.php';
}

function psdGolpe(string $id): ?array
{
    return psdGolpes()[$id] ?? null;
}

/** Quantos bichos cada um leva, e quantos golpes cada bicho usa. */
const PSD_EQUIPE = 6;
const PSD_GOLPES = 4;

/** Turnos somados antes de o relógio decidir — partida travada não existe. */
const PSD_TURNOS_MAX = 200;

// ─────────────────────────────── o dado ───────────────────────────────

/**
 * xorshift32 com a semente no estado. Devolve [0,1).
 *
 * Não é mt_rand porque mt_rand é global: duas partidas no mesmo processo
 * disputariam o mesmo gerador, e aí a partida deixa de ser reproduzível.
 */
function psdRand(array &$e): float
{
    $s = (int)$e['rng'] & 0xFFFFFFFF;
    if ($s === 0) $s = 2463534242;
    $s ^= ($s << 13) & 0xFFFFFFFF;
    $s ^= ($s >> 17);
    $s ^= ($s << 5) & 0xFFFFFFFF;
    $s &= 0xFFFFFFFF;
    $e['rng'] = $s;
    return $s / 4294967296;
}

/** Inteiro de $min a $max, os dois incluídos. */
function psdSorteio(array &$e, int $min, int $max): int
{
    return $min + (int)floor(psdRand($e) * ($max - $min + 1));
}

/** Verdadeiro com chance de $pct por cento. */
function psdChance(array &$e, float $pct): bool
{
    return psdRand($e) * 100 < $pct;
}

// ─────────────────────────────── montar os bichos ───────────────────────────────

/**
 * ── OS ATRIBUTOS ─────────────────────────────────────────────────────
 *
 * Fórmula da geração 3 em diante, com IV 31 e EV 85 em tudo — que é o que
 * o Showdown usa na Random Battle. Natureza fica neutra na v1: ela mexeria
 * 10% pra cima num atributo e 10% pra baixo noutro, e sem teambuilder
 * ninguém escolheria mesmo.
 */
function psdStats(array $bs, int $nivel): array
{
    $iv = 31; $ev = 85;
    $comum = fn(int $base) => (int)floor((2 * $base + $iv + (int)floor($ev / 4)) * $nivel / 100);

    return [
        'hp'  => $comum($bs[0]) + $nivel + 10,
        'atk' => $comum($bs[1]) + 5,
        'def' => $comum($bs[2]) + 5,
        'spa' => $comum($bs[3]) + 5,
        'spd' => $comum($bs[4]) + 5,
        'spe' => $comum($bs[5]) + 5,
    ];
}

/**
 * Sorteia os quatro golpes de um bicho dentro do pool dele.
 *
 * COM UMA GARANTIA: pelo menos um golpe de dano do próprio tipo. Sem ela o
 * sorteio entrega bichos que só têm cobertura fraca e status — e quem pega
 * esse perdeu a partida no sorteio, não jogando.
 */
function psdSortearGolpes(array &$e, array $esp): array
{
    $todos = $esp['pool'];
    $g = psdGolpes();

    $stab = [];
    foreach ($todos as $id) {
        $m = $g[$id] ?? null;
        if ($m && $m['cat'] !== 'status' && in_array($m['tipo'], $esp['tipos'], true)) $stab[] = $id;
    }

    $escolhidos = [];
    if ($stab) $escolhidos[] = $stab[psdSorteio($e, 0, count($stab) - 1)];

    /* O resto sai de um embaralhamento do pool. Embaralhar com o dado do
       estado, e não com shuffle(), senão a partida deixa de repetir. */
    $resto = array_values(array_diff($todos, $escolhidos));
    for ($i = count($resto) - 1; $i > 0; $i--) {
        $j = psdSorteio($e, 0, $i);
        [$resto[$i], $resto[$j]] = [$resto[$j], $resto[$i]];
    }
    foreach ($resto as $id) {
        if (count($escolhidos) >= PSD_GOLPES) break;
        $escolhidos[] = $id;
    }

    $saida = [];
    foreach ($escolhidos as $id) {
        $pp = (int)($g[$id]['pp'] ?? 10);
        $pp = (int)floor($pp * 1.6);   // PP máximo, como na Random Battle
        $saida[] = ['id' => $id, 'pp' => $pp, 'ppmax' => $pp];
    }
    return $saida;
}

function psdMontarBicho(array &$e, string $especieId): array
{
    $esp = psdDex()[$especieId];
    $stats = psdStats($esp['bs'], $esp['nivel']);

    return [
        'esp'    => $especieId,
        'num'    => $esp['num'],
        'nome'   => $esp['nome'],
        'tipos'  => $esp['tipos'],
        'nivel'  => $esp['nivel'],
        'stats'  => $stats,
        'hpmax'  => $stats['hp'],
        'hp'     => $stats['hp'],
        'golpes' => psdSortearGolpes($e, $esp),
        'status' => null,     // par | brn | psn | tox | slp | frz
        'sono'   => 0,        // turnos que faltam dormindo
        'tox'    => 0,        // contador do veneno grave
        'stages' => ['atk' => 0, 'def' => 0, 'spa' => 0, 'spd' => 0, 'spe' => 0, 'acc' => 0, 'eva' => 0],
        'confuso'=> 0,
        'sub'    => 0,        // vida do Substitute
        'semente'=> false,    // Leech Seed grudado
        'protegeu' => false,  // usou Protect no turno passado (pra falhar em sequência)
    ];
}

/** Seis espécies diferentes. */
function psdTimeAleatorio(array &$e): array
{
    $ids = array_keys(psdDex());
    $time = [];
    $vistos = [];
    while (count($time) < PSD_EQUIPE) {
        $id = $ids[psdSorteio($e, 0, count($ids) - 1)];
        if (isset($vistos[$id])) continue;
        $vistos[$id] = true;
        $time[] = psdMontarBicho($e, $id);
    }
    return $time;
}

// ─────────────────────────────── a partida ───────────────────────────────

/**
 * Monta o time a partir de uma lista de espécies escolhida pelo GM.
 * O que não existe na dex é descartado, e o que faltar pra fechar os seis
 * vem do sorteio — uma escalação velha nunca pode travar a partida.
 */
function psdTimeEscalado(array &$e, array $especies): array
{
    $dex = psdDex();
    $time = [];
    $usados = [];

    foreach ($especies as $esp) {
        if (count($time) >= PSD_EQUIPE) break;
        if (!isset($dex[$esp]) || isset($usados[$esp])) continue;
        $usados[$esp] = true;
        $time[] = psdMontarBicho($e, $esp);
    }

    /* ── O QUE COMPLETA UM TIME CURTO ─────────────────────────────────
     *
     * Escalação curta não deveria chegar aqui (psdSalvarTime exige os
     * seis e psdTime completa com a coleção), mas é a última barreira e
     * ela precisa ser SEGURA: sorteando da dex inteira, um time curto
     * podia render um Arceus de graça — exatamente o prêmio que o jogo
     * cobra 13.000 moedas pra dar.
     *
     * Então o que completa sai só do fundo da pirâmide: soma de base
     * stats até 500, que é a faixa "comum" da loja. */
    if (count($time) < PSD_EQUIPE) {
        static $fracos = null;
        if ($fracos === null) {
            $fracos = [];
            foreach ($dex as $id => $p) if (array_sum($p['bs']) <= 500) $fracos[] = $id;
        }
        while (count($time) < PSD_EQUIPE && $fracos) {
            $id = $fracos[psdSorteio($e, 0, count($fracos) - 1)];
            if (isset($usados[$id])) continue;
            $usados[$id] = true;
            $time[] = psdMontarBicho($e, $id);
        }
    }

    return $time;
}

/**
 * $timeA/$timeB são listas de espécies. Nulo quer dizer SORTEADO — é a
 * batalha Aleatória, em que os dois recebem seis na hora e a coleção não
 * conta pra nada. É o modo em que a aposta é sempre justa.
 */
function psdNovaPartida(int $semente, string $nomeA, string $nomeB,
                        ?array $timeA = null, ?array $timeB = null): array
{
    $e = ['rng' => $semente & 0xFFFFFFFF];
    if ($e['rng'] === 0) $e['rng'] = 1;

    $e['turno'] = 1;
    $e['fim']   = null;        // 'a' | 'b' | 'empate'
    $e['motivo']= null;
    $e['lados'] = [
        'a' => ['nome' => $nomeA, 'ativo' => 0, 'rochas' => false, 'precisa_trocar' => false,
                'time' => $timeA === null ? psdTimeAleatorio($e) : psdTimeEscalado($e, $timeA)],
        'b' => ['nome' => $nomeB, 'ativo' => 0, 'rochas' => false, 'precisa_trocar' => false,
                'time' => $timeB === null ? psdTimeAleatorio($e) : psdTimeEscalado($e, $timeB)],
    ];
    $e['eventos'] = [];
    return $e;
}

function psdOutro(string $lado): string { return $lado === 'a' ? 'b' : 'a'; }

/** O bicho que está em quadra, POR REFERÊNCIA — quem chama mexe nele. */
function &psdAtivo(array &$e, string $lado): array
{
    $i = $e['lados'][$lado]['ativo'];
    return $e['lados'][$lado]['time'][$i];
}

function psdVivos(array $e, string $lado): array
{
    $v = [];
    foreach ($e['lados'][$lado]['time'] as $i => $b) if ($b['hp'] > 0) $v[] = $i;
    return $v;
}

// ─────────────────────────────── contas ───────────────────────────────

/**
 * Quanto um golpe de $tipo vale contra um bicho de $tiposAlvo.
 * Dois tipos multiplicam: Ice em Bug/Flying é 1 x 2 = 2.
 */
function psdEficacia(string $tipo, array $tiposAlvo): float
{
    $t = psdTipos();
    $m = 1.0;
    foreach ($tiposAlvo as $alvo) {
        $m *= $t[$tipo][$alvo] ?? 1.0;
    }
    return $m;
}

/**
 * O que cada tipo de golpe faz contra um bicho destes tipos, já com os
 * dois tipos multiplicados. Só o que foge de 1x entra — o resto é ruído.
 *
 * @return array<string,float> tipo do golpe => multiplicador
 */
function psdFraquezas(array $tipos): array
{
    $t = psdTipos();
    $contra = [];
    foreach (array_keys($t) as $atacante) {
        if ($atacante === 'stellar') continue;   // tipo de Tera, não existe em partida
        $m = 1.0;
        foreach ($tipos as $alvo) $m *= $t[$atacante][$alvo] ?? 1.0;
        if ($m != 1.0) $contra[$atacante] = $m;
    }
    arsort($contra);
    return $contra;
}

/** O multiplicador de um estágio de atributo: +1 = 1,5x; -1 = 0,67x. */
function psdMultStage(int $n): float
{
    $n = max(-6, min(6, $n));
    return $n >= 0 ? (2 + $n) / 2 : 2 / (2 - $n);
}

/** Precisão e evasão usam outra régua: +1 = 1,33x. */
function psdMultPrecisao(int $n): float
{
    $n = max(-6, min(6, $n));
    return $n >= 0 ? (3 + $n) / 3 : 3 / (3 - $n);
}

/** Velocidade valendo: estágio e paralisia entram aqui. */
function psdVelocidade(array $b): int
{
    $v = $b['stats']['spe'] * psdMultStage($b['stages']['spe']);
    if ($b['status'] === 'par') $v *= 0.5;
    return (int)$v;
}

// ─────────────────────────────── eventos ───────────────────────────────

function psdEvento(array &$e, array $ev): void
{
    $e['eventos'][] = $ev;
}

function psdTexto(array &$e, string $m): void
{
    psdEvento($e, ['t' => 'texto', 'm' => $m]);
}

const PSD_NOME_STATUS = [
    'par' => 'paralisado', 'brn' => 'queimado', 'psn' => 'envenenado',
    'tox' => 'gravemente envenenado', 'slp' => 'dormindo', 'frz' => 'congelado',
];

// ─────────────────────────────── trocar ───────────────────────────────

/**
 * Põe outro bicho em quadra. É aqui que as ROCHAS cobram: Stealth Rock
 * machuca na entrada, e o quanto depende do tipo de quem entrou — é por
 * isso que ela é a carta de campo mais usada do jogo inteiro.
 */
function psdPorEmQuadra(array &$e, string $lado, int $indice): void
{
    $e['lados'][$lado]['ativo'] = $indice;
    $e['lados'][$lado]['precisa_trocar'] = false;

    $b = &psdAtivo($e, $lado);
    /* Estado que é do TURNO em quadra, e não do bicho, morre na troca:
       quem volta pro banco perde boost, confusão e Substitute. */
    $b['stages']  = ['atk' => 0, 'def' => 0, 'spa' => 0, 'spd' => 0, 'spe' => 0, 'acc' => 0, 'eva' => 0];
    $b['confuso'] = 0;
    $b['sub']     = 0;
    $b['semente'] = false;
    $b['protegeu']= false;

    psdEvento($e, ['t' => 'entrou', 'lado' => $lado, 'i' => $indice, 'bicho' => psdBichoPraTela($b)]);

    if ($e['lados'][$lado]['rochas']) {
        $ef = psdEficacia('rock', $b['tipos']);
        $d  = max(1, (int)floor($b['hpmax'] * $ef / 8));
        $b['hp'] = max(0, $b['hp'] - $d);
        psdTexto($e, $b['nome'] . ' se machucou com as pedras!');
        psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $d, 'hp' => $b['hp'], 'hpmax' => $b['hpmax']]);
        if ($b['hp'] <= 0) psdDesmaiou($e, $lado);
    }
    unset($b);
}

function psdDesmaiou(array &$e, string $lado): void
{
    $b = psdAtivo($e, $lado);
    psdTexto($e, $b['nome'] . ' desmaiou!');
    psdEvento($e, ['t' => 'desmaiou', 'lado' => $lado]);
    $e['lados'][$lado]['precisa_trocar'] = true;
}

// ─────────────────────────────── o dano ───────────────────────────────

/**
 * ── A CONTA ──────────────────────────────────────────────────────────
 *
 * É a fórmula da série, e vale escrever por extenso porque todo mundo que
 * jogou Showdown reconhece o resultado dela:
 *
 *   base = ((2 x nível / 5 + 2) x power x Ataque / Defesa) / 50 + 2
 *
 * e depois os multiplicadores: STAB (1,5x se o golpe é do tipo de quem
 * usou), a tabela de tipos, crítico (1,5x), queimadura (metade no físico)
 * e um fator aleatório de 0,85 a 1,00 — esse último é o que faz o mesmo
 * golpe às vezes matar e às vezes deixar vivo por um fio.
 *
 * O CRÍTICO IGNORA o que atrapalha quem bate: estágio negativo de ataque do
 * atacante e estágio positivo de defesa do defensor não contam. É o que
 * impede uma parede de ficar invencível só empilhando defesa.
 */
function psdCalcularDano(array &$e, array $at, array $df, array $mv, bool $crit): array
{
    $fisico = $mv['cat'] === 'physical';

    $stAt = $fisico ? 'atk' : 'spa';
    $stDf = $fisico ? 'def' : 'spd';

    $estAt = $at['stages'][$stAt];
    $estDf = $df['stages'][$stDf];
    if ($crit) { $estAt = max(0, $estAt); $estDf = min(0, $estDf); }

    $A = $at['stats'][$stAt] * psdMultStage($estAt);
    $D = $df['stats'][$stDf] * psdMultStage($estDf);

    $base = (int)floor((int)floor((int)floor(2 * $at['nivel'] / 5 + 2) * $mv['power'] * $A / $D) / 50) + 2;

    $ef   = psdEficacia($mv['tipo'], $df['tipos']);
    if ($ef == 0.0) return ['dano' => 0, 'ef' => 0.0, 'crit' => false];

    $mod  = 1.0;
    if (in_array($mv['tipo'], $at['tipos'], true)) $mod *= 1.5;   // STAB
    $mod *= $ef;
    if ($crit) $mod *= 1.5;
    if ($at['status'] === 'brn' && $fisico) $mod *= 0.5;
    $mod *= (85 + psdSorteio($e, 0, 15)) / 100;

    return ['dano' => max(1, (int)floor($base * $mod)), 'ef' => $ef, 'crit' => $crit];
}

/** Tira vida respeitando o Substitute, que come o dano até quebrar. */
function psdAplicarDano(array &$e, string $lado, int $dano): int
{
    $b = &psdAtivo($e, $lado);

    if ($b['sub'] > 0) {
        $absorvido = min($b['sub'], $dano);
        $b['sub'] -= $absorvido;
        psdEvento($e, ['t' => 'sub', 'lado' => $lado, 'vida' => $b['sub']]);
        if ($b['sub'] <= 0) psdTexto($e, 'O substituto de ' . $b['nome'] . ' quebrou!');
        unset($b);
        return 0;   // nada passou pro bicho
    }

    $real = min($b['hp'], $dano);
    $b['hp'] -= $real;
    psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $real, 'hp' => $b['hp'], 'hpmax' => $b['hpmax']]);
    unset($b);
    return $real;
}

function psdCurar(array &$e, string $lado, int $quanto): void
{
    $b = &psdAtivo($e, $lado);
    $antes = $b['hp'];
    $b['hp'] = min($b['hpmax'], $b['hp'] + $quanto);
    if ($b['hp'] > $antes) {
        psdEvento($e, ['t' => 'cura', 'lado' => $lado, 'valor' => $b['hp'] - $antes, 'hp' => $b['hp'], 'hpmax' => $b['hpmax']]);
    }
    unset($b);
}

// ─────────────────────────────── efeitos ───────────────────────────────

function psdAplicarStatus(array &$e, string $lado, string $st): bool
{
    $b = &psdAtivo($e, $lado);

    if ($b['sub'] > 0)    { unset($b); return false; }   // o substituto segura
    if ($b['status'])     { unset($b); return false; }   // um status por vez

    /* Imunidade por tipo — é a regra que mais aparece em partida: Thunder
       Wave não pega Ground, veneno não pega Steel nem Poison. */
    $imune = [
        'par' => ['electric'], 'brn' => ['fire'],
        'psn' => ['poison', 'steel'], 'tox' => ['poison', 'steel'],
        'frz' => ['ice'],
    ];
    foreach ($imune[$st] ?? [] as $t) {
        if (in_array($t, $b['tipos'], true)) { unset($b); return false; }
    }

    $b['status'] = $st;
    if ($st === 'slp') $b['sono'] = psdSorteio($e, 1, 3);
    if ($st === 'tox') $b['tox']  = 1;

    psdTexto($e, $b['nome'] . ' ficou ' . PSD_NOME_STATUS[$st] . '!');
    psdEvento($e, ['t' => 'status', 'lado' => $lado, 'st' => $st]);
    unset($b);
    return true;
}

function psdAplicarBoosts(array &$e, string $lado, array $boosts): void
{
    $b = &psdAtivo($e, $lado);
    $nomes = ['atk' => 'Ataque', 'def' => 'Defesa', 'spa' => 'Ataque Especial',
              'spd' => 'Defesa Especial', 'spe' => 'Velocidade', 'acc' => 'Precisão', 'eva' => 'Evasão'];

    foreach ($boosts as $k => $n) {
        if (!isset($b['stages'][$k])) continue;
        $antes = $b['stages'][$k];
        $b['stages'][$k] = max(-6, min(6, $antes + $n));
        if ($b['stages'][$k] === $antes) {
            psdTexto($e, ($nomes[$k] ?? $k) . ' de ' . $b['nome'] . ' não pode ' . ($n > 0 ? 'subir' : 'cair') . ' mais!');
            continue;
        }
        $quanto = abs($n) >= 2 ? ' subiu muito' : ' subiu';
        if ($n < 0) $quanto = abs($n) >= 2 ? ' caiu muito' : ' caiu';
        psdTexto($e, ($nomes[$k] ?? $k) . ' de ' . $b['nome'] . $quanto . '!');
        psdEvento($e, ['t' => 'boost', 'lado' => $lado, 'stat' => $k, 'n' => $n]);
    }
    unset($b);
}

// ─────────────────────────────── usar um golpe ───────────────────────────────

/**
 * Antes de agir: congelado, dormindo, paralisado, confuso ou levou flinch.
 * Devolve false se o turno dele morreu aqui.
 */
function psdPodeAgir(array &$e, string $lado): bool
{
    $b = &psdAtivo($e, $lado);
    $nome = $b['nome'];

    if ($b['status'] === 'frz') {
        if (psdChance($e, 20)) {
            $b['status'] = null;
            psdTexto($e, $nome . ' descongelou!');
            psdEvento($e, ['t' => 'status', 'lado' => $lado, 'st' => null]);
        } else {
            psdTexto($e, $nome . ' está congelado!');
            unset($b); return false;
        }
    }

    if ($b['status'] === 'slp') {
        $b['sono']--;
        if ($b['sono'] <= 0) {
            $b['status'] = null;
            psdTexto($e, $nome . ' acordou!');
            psdEvento($e, ['t' => 'status', 'lado' => $lado, 'st' => null]);
        } else {
            psdTexto($e, $nome . ' está dormindo.');
            unset($b); return false;
        }
    }

    if (!empty($b['flinch'])) {
        $b['flinch'] = false;
        psdTexto($e, $nome . ' se assustou e não conseguiu agir!');
        unset($b); return false;
    }

    if ($b['status'] === 'par' && psdChance($e, 25)) {
        psdTexto($e, $nome . ' está paralisado e não conseguiu se mexer!');
        unset($b); return false;
    }

    if ($b['confuso'] > 0) {
        $b['confuso']--;
        if ($b['confuso'] <= 0) {
            psdTexto($e, $nome . ' saiu da confusão!');
        } else {
            psdTexto($e, $nome . ' está confuso!');
            if (psdChance($e, 33)) {
                /* Bate em si mesmo: 40 de power físico, sem tipo, sem crítico. */
                $copia = $b;
                $falso = ['cat' => 'physical', 'power' => 40, 'tipo' => '???'];
                $A = $copia['stats']['atk'] * psdMultStage($copia['stages']['atk']);
                $D = $copia['stats']['def'] * psdMultStage($copia['stages']['def']);
                $base = (int)floor((int)floor((int)floor(2 * $copia['nivel'] / 5 + 2) * 40 * $A / $D) / 50) + 2;
                $d = max(1, (int)floor($base * (85 + psdSorteio($e, 0, 15)) / 100));
                unset($b);
                psdTexto($e, $nome . ' se machucou na própria confusão!');
                $b2 = &psdAtivo($e, $lado);
                $b2['hp'] = max(0, $b2['hp'] - $d);
                psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $d, 'hp' => $b2['hp'], 'hpmax' => $b2['hpmax']]);
                $morreu = $b2['hp'] <= 0;
                unset($b2);
                if ($morreu) psdDesmaiou($e, $lado);
                return false;
            }
        }
    }

    unset($b);
    return true;
}

/**
 * ── O GOLPE ──────────────────────────────────────────────────────────
 *
 * Ordem das coisas, e cada passo existe por um motivo que aparece em
 * partida: gasta PP (acabou o PP, vira Struggle), o Protect do alvo barra,
 * a precisão decide se acerta, e só então o golpe faz o que faz.
 */
function psdUsarGolpe(array &$e, string $lado, int $idx): void
{
    $alvoLado = psdOutro($lado);

    $b = &psdAtivo($e, $lado);
    $nome = $b['nome'];
    $b['protegeu'] = false;

    $usandoStruggle = false;
    if (!isset($b['golpes'][$idx]) || $b['golpes'][$idx]['pp'] <= 0) {
        $temPP = false;
        foreach ($b['golpes'] as $g) if ($g['pp'] > 0) { $temPP = true; break; }
        if (!$temPP) {
            $usandoStruggle = true;
        } else {
            unset($b); return;   // escolha inválida: o turno dele passa
        }
    }

    if ($usandoStruggle) {
        $mv = ['nome' => 'Struggle', 'tipo' => '???', 'cat' => 'physical',
               'power' => 50, 'acc' => 100, 'prio' => 0, 'recuo' => [25, 100]];
    } else {
        $b['golpes'][$idx]['pp']--;
        $mv = psdGolpe($b['golpes'][$idx]['id']);
        if (!$mv) { unset($b); return; }
    }

    $atacante = $b;   // cópia pro cálculo; o original muda durante a resolução
    unset($b);

    psdTexto($e, $nome . ' usou ' . $mv['nome'] . '!');
    psdEvento($e, ['t' => 'golpe', 'lado' => $lado, 'nome' => $mv['nome'], 'tipo' => $mv['tipo']]);

    $alvo = psdAtivo($e, $alvoLado);

    // Protect barra o que vier pelo outro lado.
    if (!empty($alvo['protegido']) && ($mv['cat'] ?? '') !== 'status') {
        psdTexto($e, $alvo['nome'] . ' se protegeu!');
        return;
    }

    // Precisão: acc 100 no nosso dado quer dizer "não erra".
    $acc = (int)($mv['acc'] ?? 100);
    if ($acc < 100) {
        $chance = $acc * psdMultPrecisao($atacante['stages']['acc']) / psdMultPrecisao($alvo['stages']['eva']);
        if (!psdChance($e, $chance)) {
            psdTexto($e, 'Mas errou!');
            psdEvento($e, ['t' => 'errou', 'lado' => $lado]);
            return;
        }
    }

    if (($mv['cat'] ?? '') === 'status') {
        psdGolpeDeStatus($e, $lado, $alvoLado, $mv);
        return;
    }

    // ── dano ──
    $ef = psdEficacia($mv['tipo'], $alvo['tipos']);
    if ($ef == 0.0) {
        psdTexto($e, 'Não afeta ' . $alvo['nome'] . '...');
        psdEvento($e, ['t' => 'imune', 'lado' => $alvoLado]);
        return;
    }

    $vezes = 1;
    if (isset($mv['vezes'])) {
        [$min, $max] = $mv['vezes'];
        /* Dois a cinco: o jogo não sorteia liso — 2 e 3 saem muito mais. */
        $vezes = ($min === 2 && $max === 5)
            ? [2, 2, 3, 3, 4, 5][psdSorteio($e, 0, 5)]
            : psdSorteio($e, $min, $max);
    }

    $chanceCrit = isset($mv['crit']) && $mv['crit'] >= 2 ? 12.5 : 4.167;   // 1/8 ou 1/24
    $totalDado = 0;
    $critou = false;

    for ($i = 0; $i < $vezes; $i++) {
        $alvoAgora = psdAtivo($e, $alvoLado);
        if ($alvoAgora['hp'] <= 0) break;

        $crit = psdChance($e, $chanceCrit);
        $r = psdCalcularDano($e, $atacante, $alvoAgora, $mv, $crit);
        if ($crit) $critou = true;

        psdEvento($e, ['t' => 'bate', 'lado' => $alvoLado, 'ef' => $r['ef'], 'crit' => $crit]);
        $totalDado += psdAplicarDano($e, $alvoLado, $r['dano']);
    }

    if ($vezes > 1) psdTexto($e, 'Acertou ' . $vezes . ' vezes!');
    if ($critou)    psdTexto($e, 'Um golpe crítico!');
    if ($ef > 1)    psdTexto($e, 'É super eficaz!');
    if ($ef < 1)    psdTexto($e, 'Não é muito eficaz...');

    // Recuo, dreno e o que o golpe cobra de quem usou.
    if (isset($mv['recuo']) && $totalDado > 0) {
        $r = max(1, (int)floor($totalDado * $mv['recuo'][0] / $mv['recuo'][1]));
        psdTexto($e, $nome . ' se machucou com o recuo!');
        $meu = &psdAtivo($e, $lado);
        $meu['hp'] = max(0, $meu['hp'] - $r);
        psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $r, 'hp' => $meu['hp'], 'hpmax' => $meu['hpmax']]);
        unset($meu);
    }
    if (isset($mv['dreno']) && $totalDado > 0) {
        psdCurar($e, $lado, max(1, (int)floor($totalDado * $mv['dreno'][0] / $mv['dreno'][1])));
        psdTexto($e, $nome . ' sugou energia!');
    }
    if (isset($mv['auto'])) psdAplicarBoosts($e, $lado, $mv['auto']);

    // Efeito secundário: só se o alvo continua de pé e não tem substituto.
    $sec = $mv['sec'] ?? null;
    if ($sec && $totalDado > 0) {
        $alvoAgora = psdAtivo($e, $alvoLado);
        if ($alvoAgora['hp'] > 0 && psdChance($e, (float)$sec['chance'])) {
            if (!empty($sec['status']))  psdAplicarStatus($e, $alvoLado, $sec['status']);
            if (!empty($sec['boosts']))  psdAplicarBoosts($e, $alvoLado, $sec['boosts']);
            if (!empty($sec['auto']))    psdAplicarBoosts($e, $lado, $sec['auto']);
            if (($sec['volatil'] ?? '') === 'flinch') {
                $al = &psdAtivo($e, $alvoLado);
                $al['flinch'] = true;   // só vale se ele ainda não agiu neste turno
                unset($al);
            }
            if (($sec['volatil'] ?? '') === 'confusion') {
                $al = &psdAtivo($e, $alvoLado);
                if ($al['confuso'] <= 0) {
                    $al['confuso'] = psdSorteio($e, 2, 5);
                    psdTexto($e, $al['nome'] . ' ficou confuso!');
                }
                unset($al);
            }
        }
    }

    foreach (['a', 'b'] as $l) {
        if (psdAtivo($e, $l)['hp'] <= 0 && !$e['lados'][$l]['precisa_trocar']) psdDesmaiou($e, $l);
    }
}

/** Os golpes que não dão dano. */
function psdGolpeDeStatus(array &$e, string $lado, string $alvoLado, array $mv): void
{
    $eu = psdAtivo($e, $lado);

    // Substitute: custa 25% da vida e nasce com ela.
    if (($mv['volatil'] ?? '') === 'substitute') {
        if ($eu['sub'] > 0)                 { psdTexto($e, $eu['nome'] . ' já tem um substituto!'); return; }
        if ($eu['hp'] <= $eu['hpmax'] / 4)  { psdTexto($e, 'Mas falhou!'); return; }
        $custo = (int)floor($eu['hpmax'] / 4);
        $b = &psdAtivo($e, $lado);
        $b['hp'] -= $custo;
        $b['sub'] = $custo;
        unset($b);
        psdTexto($e, $eu['nome'] . ' criou um substituto!');
        psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $custo,
                       'hp' => psdAtivo($e, $lado)['hp'], 'hpmax' => $eu['hpmax']]);
        psdEvento($e, ['t' => 'sub', 'lado' => $lado, 'vida' => $custo]);
        return;
    }

    // Protect: marca, e já falha se foi usado no turno anterior.
    if (($mv['volatil'] ?? '') === 'protect') {
        if (!empty($eu['protegeu'])) { psdTexto($e, 'Mas falhou!'); return; }
        $b = &psdAtivo($e, $lado);
        $b['protegido'] = true;
        $b['protegeu']  = true;
        unset($b);
        psdTexto($e, $eu['nome'] . ' se protegeu!');
        return;
    }

    if (($mv['volatil'] ?? '') === 'leechseed') {
        $alvo = psdAtivo($e, $alvoLado);
        if (in_array('grass', $alvo['tipos'], true)) { psdTexto($e, 'Não afeta ' . $alvo['nome'] . '...'); return; }
        if (!empty($alvo['semente']))                { psdTexto($e, 'Mas falhou!'); return; }
        $b = &psdAtivo($e, $alvoLado);
        $b['semente'] = true;
        unset($b);
        psdTexto($e, $alvo['nome'] . ' foi semeado!');
        return;
    }

    if (($mv['lado'] ?? '') === 'stealthrock') {
        if ($e['lados'][$alvoLado]['rochas']) { psdTexto($e, 'Mas falhou!'); return; }
        $e['lados'][$alvoLado]['rochas'] = true;
        psdTexto($e, 'Pedras pontudas flutuam no campo adversário!');
        psdEvento($e, ['t' => 'rochas', 'lado' => $alvoLado]);
        return;
    }

    if (isset($mv['cura'])) {
        if ($eu['hp'] >= $eu['hpmax']) { psdTexto($e, 'Mas falhou!'); return; }
        psdCurar($e, $lado, (int)floor($eu['hpmax'] * $mv['cura'][0] / $mv['cura'][1]));
        psdTexto($e, $eu['nome'] . ' recuperou vida!');
        return;
    }

    if (!empty($mv['status'])) {
        if (!psdAplicarStatus($e, $alvoLado, $mv['status'])) psdTexto($e, 'Mas falhou!');
        return;
    }

    if (!empty($mv['boosts'])) {
        // Boost em si mesmo é o caso comum (Swords Dance); no alvo, é debuff.
        $positivo = false;
        foreach ($mv['boosts'] as $n) if ($n > 0) $positivo = true;
        psdAplicarBoosts($e, $positivo ? $lado : $alvoLado, $mv['boosts']);
        return;
    }

    psdTexto($e, 'Mas falhou!');
}

// ─────────────────────────────── fim do turno ───────────────────────────────

/**
 * Queimadura, veneno e semente cobram aqui, na ordem em que o jogo cobra.
 * O veneno grave cresce: 1/16, 2/16, 3/16... é o que faz o relógio dele.
 */
function psdFimDeTurno(array &$e): void
{
    foreach (['a', 'b'] as $lado) {
        $b = &psdAtivo($e, $lado);
        if ($b['hp'] <= 0) { unset($b); continue; }

        $nome = $b['nome'];
        $perdeu = 0;

        if ($b['status'] === 'brn' || $b['status'] === 'psn') {
            $perdeu = max(1, (int)floor($b['hpmax'] / ($b['status'] === 'brn' ? 16 : 8)));
            psdTexto($e, $nome . ' se machucou com ' . ($b['status'] === 'brn' ? 'a queimadura' : 'o veneno') . '!');
        } elseif ($b['status'] === 'tox') {
            $perdeu = max(1, (int)floor($b['hpmax'] * $b['tox'] / 16));
            $b['tox'] = min(15, $b['tox'] + 1);
            psdTexto($e, $nome . ' se machucou com o veneno!');
        }

        if ($perdeu > 0) {
            $b['hp'] = max(0, $b['hp'] - $perdeu);
            psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $perdeu, 'hp' => $b['hp'], 'hpmax' => $b['hpmax']]);
        }

        $temSemente = !empty($b['semente']) && $b['hp'] > 0;
        $hpmax = $b['hpmax'];
        unset($b);

        if ($temSemente) {
            $dreno = max(1, (int)floor($hpmax / 8));
            $v = &psdAtivo($e, $lado);
            $v['hp'] = max(0, $v['hp'] - $dreno);
            psdEvento($e, ['t' => 'dano', 'lado' => $lado, 'valor' => $dreno, 'hp' => $v['hp'], 'hpmax' => $v['hpmax']]);
            unset($v);
            psdTexto($e, 'A semente sugou a energia de ' . $nome . '!');
            psdCurar($e, psdOutro($lado), $dreno);
        }

        // O Protect vale um turno só.
        $b = &psdAtivo($e, $lado);
        $b['protegido'] = false;
        $morreu = $b['hp'] <= 0;
        unset($b);
        if ($morreu && !$e['lados'][$lado]['precisa_trocar']) psdDesmaiou($e, $lado);
    }
}

function psdConferirFim(array &$e): void
{
    $va = psdVivos($e, 'a');
    $vb = psdVivos($e, 'b');

    if (!$va && !$vb) { $e['fim'] = 'empate'; $e['motivo'] = 'nocaute duplo'; }
    elseif (!$va)     { $e['fim'] = 'b'; $e['motivo'] = 'nocaute'; }
    elseif (!$vb)     { $e['fim'] = 'a'; $e['motivo'] = 'nocaute'; }
    elseif ($e['turno'] >= PSD_TURNOS_MAX) {
        /* Partida que não acaba: vence quem tem mais bicho de pé, e no
           empate disso, quem tem mais vida somada. Sem isso, dois que só
           se curam ficam a noite inteira. */
        if (count($va) !== count($vb)) {
            $e['fim'] = count($va) > count($vb) ? 'a' : 'b';
        } else {
            $ha = $hb = 0;
            foreach ($e['lados']['a']['time'] as $x) $ha += $x['hp'];
            foreach ($e['lados']['b']['time'] as $x) $hb += $x['hp'];
            $e['fim'] = $ha === $hb ? 'empate' : ($ha > $hb ? 'a' : 'b');
        }
        $e['motivo'] = 'limite de turnos';
    }

    if ($e['fim'] && $e['fim'] !== 'empate') {
        psdTexto($e, $e['lados'][$e['fim']]['nome'] . ' venceu a partida!');
    }
}

// ─────────────────────────────── o turno ───────────────────────────────

/**
 * ── RESOLVER O TURNO ─────────────────────────────────────────────────
 *
 * Recebe as DUAS escolhas e devolve o estado com os eventos do turno.
 * Cada escolha é ['tipo' => 'golpe', 'i' => 0..3] ou ['tipo' => 'trocar', 'i' => 0..5].
 *
 * A ORDEM: troca acontece antes de qualquer golpe — é por isso que trocar
 * pra quem resiste é a jogada que todo mundo aprende primeiro. Depois os
 * golpes, por prioridade e então velocidade.
 */
function psdResolverTurno(array &$e, array $escolhaA, array $escolhaB): array
{
    $e['eventos'] = [];
    if ($e['fim']) return $e;

    $esc = ['a' => $escolhaA, 'b' => $escolhaB];

    /* ── TURNO DE REPOSIÇÃO ───────────────────────────────────────────
     *
     * Caiu um bicho? Então este turno é SÓ pra pôr outro em quadra: ninguém
     * ataca, nada cobra no fim, e o contador não anda.
     *
     * Sem isso o adversário ganha um golpe de graça enquanto o substituto
     * entra — e aí derrubar alguém passa a valer dois turnos em vez de um,
     * que é o dobro do que vale no jogo de verdade. Apareceu na primeira
     * partida simulada: Bellossom caiu e o Swalot bateu no Gumshoos que
     * acabava de entrar.
     *
     * A entrada ainda pode matar (as pedras do Stealth Rock cobram aqui), e
     * aí vem outra reposição logo em seguida. É o que acontece na série. */
    if ($e['lados']['a']['precisa_trocar'] || $e['lados']['b']['precisa_trocar']) {
        foreach (['a', 'b'] as $lado) {
            if (!$e['lados'][$lado]['precisa_trocar']) continue;
            $vivos = psdVivos($e, $lado);
            if (!$vivos) continue;                 // acabou: psdConferirFim resolve
            $i = (int)($esc[$lado]['i'] ?? -1);
            if (!in_array($i, $vivos, true)) $i = $vivos[0];   // escolha inválida ou vazia
            psdPorEmQuadra($e, $lado, $i);
        }
        psdConferirFim($e);
        return $e;
    }

    // 1. trocas
    foreach (['a', 'b'] as $lado) {
        if (($esc[$lado]['tipo'] ?? '') !== 'trocar') continue;
        $i = (int)($esc[$lado]['i'] ?? -1);
        if (!in_array($i, psdVivos($e, $lado), true) || $i === $e['lados'][$lado]['ativo']) continue;
        psdTexto($e, $e['lados'][$lado]['nome'] . ' trocou para ' . $e['lados'][$lado]['time'][$i]['nome'] . '!');
        psdPorEmQuadra($e, $lado, $i);
    }

    // 2. ordem dos golpes
    $agem = [];
    foreach (['a', 'b'] as $lado) {
        if (($esc[$lado]['tipo'] ?? '') !== 'golpe') continue;
        if (psdAtivo($e, $lado)['hp'] <= 0) continue;

        $b = psdAtivo($e, $lado);
        $i = (int)($esc[$lado]['i'] ?? 0);
        $mv = isset($b['golpes'][$i]) ? psdGolpe($b['golpes'][$i]['id']) : null;
        $agem[] = ['lado' => $lado, 'i' => $i, 'prio' => (int)($mv['prio'] ?? 0), 'vel' => psdVelocidade($b)];
    }

    usort($agem, function ($x, $y) use (&$e) {
        if ($x['prio'] !== $y['prio']) return $y['prio'] <=> $x['prio'];
        if ($x['vel']  !== $y['vel'])  return $y['vel']  <=> $x['vel'];
        return psdRand($e) < 0.5 ? -1 : 1;   // empate de velocidade é no dado
    });

    foreach ($agem as $a) {
        if ($e['fim']) break;
        if (psdAtivo($e, $a['lado'])['hp'] <= 0) continue;
        if (!psdPodeAgir($e, $a['lado'])) continue;
        psdUsarGolpe($e, $a['lado'], $a['i']);
    }

    // 3. fim de turno e fim de partida
    psdFimDeTurno($e);
    psdConferirFim($e);
    if (!$e['fim']) $e['turno']++;

    return $e;
}

// ─────────────────────────────── pra tela ───────────────────────────────

/**
 * ── O QUE ESTE GOLPE FAZ, EM TRÊS PALAVRAS ───────────────────────────
 *
 * O menu mostrava nome, tipo e PP, e mais nada. Quem não conhece Pokémon
 * escolhia "Swords Dance" esperando dano, via o boneco pular e a vida do
 * outro não mexer, e concluía que o jogo tinha travado — foi exatamente
 * a reclamação do Anderson em 18/11/2026: "usei um poder e o meu boneco
 * não fez nada".
 *
 * Então cada golpe diz o que faz, em português e curto o bastante pra
 * caber embaixo do nome no celular.
 */
const PSD_NOME_ATRIBUTO = [
    'atk' => 'ATQ', 'def' => 'DEF', 'spa' => 'ATQ ESP',
    'spd' => 'DEF ESP', 'spe' => 'VEL', 'acc' => 'PRECISÃO', 'eva' => 'EVASÃO',
];

const PSD_FAZ_STATUS = [
    'par' => 'paralisa', 'brn' => 'queima', 'psn' => 'envenena',
    'tox' => 'envenena forte', 'slp' => 'faz dormir', 'frz' => 'congela',
];

/** "A", "A e B", "A, B e C" — vírgula até o penúltimo, "e" no último. */
function psdLista(array $itens): string
{
    if (count($itens) <= 1) return (string)($itens[0] ?? '');
    $ultimo = array_pop($itens);
    return implode(', ', $itens) . ' e ' . $ultimo;
}

function psdGolpeResumo(array $mv): string
{
    // Golpe de dano: o que importa já está no power; aqui vai só o extra.
    if (($mv['cat'] ?? '') !== 'status') {
        $partes = [];
        if (isset($mv['auto'])) {
            $sobe = []; $desce = [];
            foreach ($mv['auto'] as $k => $n) {
                $nome = PSD_NOME_ATRIBUTO[$k] ?? $k;
                if ($n > 0) $sobe[] = $nome; else $desce[] = $nome;
            }
            if ($sobe)  $partes[] = 'sobe seu '  . psdLista($sobe);
            if ($desce) $partes[] = 'baixa seu ' . psdLista($desce);
        }
        if (isset($mv['recuo'])) $partes[] = 'você se machuca';
        if (isset($mv['dreno'])) $partes[] = 'rouba vida';
        if (isset($mv['vezes'])) $partes[] = 'bate várias vezes';
        $sec = $mv['sec'] ?? null;
        if ($sec) {
            $c = (int)($sec['chance'] ?? 100);
            if (!empty($sec['status']))  $partes[] = $c . '% ' . (PSD_FAZ_STATUS[$sec['status']] ?? $sec['status']);
            elseif (($sec['volatil'] ?? '') === 'flinch') $partes[] = $c . '% assusta';
            elseif (($sec['volatil'] ?? '') === 'confusion') $partes[] = $c . '% confunde';
            elseif (!empty($sec['boosts'])) {
                $k = array_key_first($sec['boosts']);
                $partes[] = $c . '% baixa ' . (PSD_NOME_ATRIBUTO[$k] ?? $k) . ' do rival';
            }
        }
        return implode(' · ', array_slice($partes, 0, 2));
    }

    // Golpe de status: aqui o resumo É a informação toda.
    if (($mv['volatil'] ?? '') === 'substitute') return 'cria um substituto';
    if (($mv['volatil'] ?? '') === 'protect')    return 'protege do próximo golpe';
    if (($mv['volatil'] ?? '') === 'leechseed')  return 'suga vida do rival todo turno';
    if (($mv['lado'] ?? '') === 'stealthrock')   return 'pedras que machucam quem entrar';
    if (isset($mv['cura']))                      return 'cura metade da sua vida';
    if (!empty($mv['status']))                   return PSD_FAZ_STATUS[$mv['status']] ?? 'aplica status';

    if (!empty($mv['boosts'])) {
        $sobe = [];
        $desce = [];
        foreach ($mv['boosts'] as $k => $n) {
            $nome = PSD_NOME_ATRIBUTO[$k] ?? $k;
            if ($n > 0) $sobe[] = $nome; else $desce[] = $nome;
        }
        $txt = [];
        if ($sobe)  $txt[] = 'sobe '  . psdLista($sobe);
        if ($desce) $txt[] = 'baixa ' . psdLista($desce);
        return implode(', ', $txt);
    }

    return 'efeito especial';
}

function psdBichoPraTela(array $b): array
{
    return [
        'esp' => $b['esp'], 'num' => $b['num'], 'nome' => $b['nome'],
        'tipos' => $b['tipos'], 'nivel' => $b['nivel'],
        'hp' => $b['hp'], 'hpmax' => $b['hpmax'], 'status' => $b['status'],
    ];
}

/**
 * O que CADA jogador pode ver.
 *
 * A equipe do adversário não vai inteira: o Showdown só mostra o que já
 * entrou em quadra, e é isso que faz a troca ter graça. Mandar tudo e
 * esconder no CSS seria entregar o jogo — mesma regra do STOP.
 */
function psdEstadoPara(array $e, string $lado): array
{
    $outro = psdOutro($lado);
    $meu   = $e['lados'][$lado];
    $dele  = $e['lados'][$outro];

    $equipe = [];
    foreach ($meu['time'] as $i => $b) {
        $equipe[] = psdBichoPraTela($b) + ['i' => $i, 'ativo' => $i === $meu['ativo']];
    }

    /* Os golpes vão com nome, tipo e power JUNTOS. A tela poderia carregar
       o psd_golpes.php inteiro e procurar pelo id, mas aí o navegador
       baixaria 51 KB de dado pra usar quatro linhas — e, pior, passaria a
       ter uma cópia da base que pode divergir da do servidor. */
    /* A EFICÁCIA CONTRA QUEM ESTÁ NA FRENTE vai pronta do servidor.
       É a conta que decide o turno, e deixar a tela fazer significaria
       mandar a tabela de tipos inteira pro navegador e ter duas cópias
       da mesma regra. O alvo é público — ele está em quadra. */
    $tiposAlvo = $dele['time'][$dele['ativo']]['tipos'];

    $golpes = [];
    foreach ($meu['time'][$meu['ativo']]['golpes'] as $g) {
        $mv = psdGolpe($g['id']) ?? [];
        $ehDano = ($mv['cat'] ?? 'status') !== 'status';
        $golpes[] = [
            'id'    => $g['id'],
            'pp'    => $g['pp'],
            'ppmax' => $g['ppmax'],
            'nome'  => $mv['nome']  ?? $g['id'],
            'tipo'  => $mv['tipo']  ?? 'normal',
            'cat'   => $mv['cat']   ?? 'status',
            'power' => $mv['power'] ?? 0,
            'acc'   => $mv['acc']   ?? 100,
            'faz'   => $mv ? psdGolpeResumo($mv) : '',
            'efic'  => $ehDano ? psdEficacia($mv['tipo'], $tiposAlvo) : null,
            'stab'  => $ehDano && in_array($mv['tipo'], $meu['time'][$meu['ativo']]['tipos'], true),
        ];
    }

    return [
        'turno'   => $e['turno'],
        'fim'     => $e['fim'],
        'motivo'  => $e['motivo'],
        'venci'   => $e['fim'] && $e['fim'] === $lado,
        // Qual letra eu sou: os eventos vêm marcados com 'a'/'b' e a tela
        // precisa saber qual dos dois lados do campo é o dela.
        'lado_eu' => $lado,
        'eu'      => ['nome' => $meu['nome'], 'equipe' => $equipe,
                      'ativo' => psdBichoPraTela($meu['time'][$meu['ativo']]),
                      'golpes' => $golpes,
                      'rochas' => $meu['rochas'], 'precisa_trocar' => $meu['precisa_trocar']],
        /* O `contra` é o que machuca o adversário que está em quadra: a
           tabela de tipos já resolvida pros dois tipos dele. Só do ATIVO,
           que é público — o resto da equipe continua escondido. É o que o
           guia de dentro da partida mostra. */
        'ele'     => ['nome' => $dele['nome'],
                      'ativo' => psdBichoPraTela($dele['time'][$dele['ativo']])
                                 + ['contra' => psdFraquezas($dele['time'][$dele['ativo']]['tipos'])],
                      'restam' => count(psdVivos($e, $outro)),
                      'rochas' => $dele['rochas']],
        'eventos' => $e['eventos'],
    ];
}
