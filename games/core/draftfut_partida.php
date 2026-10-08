<?php
/**
 * ── A PARTIDA MINUTO A MINUTO ────────────────────────────────────────
 *
 * O motor que já existia (games/core/fut_motor.php) devolve um placar e
 * pronto — é o que o jogo de carreira precisa pra simular uma rodada inteira
 * em milissegundos. Aqui é o contrário: a partida É o espetáculo, então ela
 * sai lance a lance, com nome de quem chutou e quem defendeu, no ritmo do
 * Brasfoot.
 *
 * ── O PLACAR NASCE DOS LANCES, NÃO O CONTRÁRIO ───────────────────────
 *
 * Dava pra sortear "2x1" e inventar lances que somassem isso. Não é o que
 * acontece: cada minuto é sorteado de verdade, o gol sai do lance, e o placar
 * é só a conta no fim. A diferença aparece na hora em que o time fraco
 * segura um 0x0 heroico com três defesaças — isso não dá pra encenar depois
 * de já saber o resultado.
 *
 * A calibragem vem do motor que já existia: 2,55 gols por partida em média e
 * o mando valendo 12 pontos de força, que lá foram MEDIDOS contra o
 * Brasileirão de verdade. Não repito a medição; herdo os números.
 *
 * ── A SEMENTE DEIXA A PARTIDA SER CONTADA DUAS VEZES ─────────────────
 *
 * Toda partida guarda a semente que a gerou. Com ela, reabrir a página conta
 * exatamente o mesmo jogo — e é isso que permite guardar só o placar e o
 * identificador no banco, sem gravar noventa minutos de texto por partida.
 */

require_once __DIR__ . '/draftfut.php';

/**
 * A média de gols é herdada de fut_motor.php, onde foi medida contra o
 * Brasileirão. O MANDO NÃO DÁ PRA HERDAR: lá a força é a do clube, numa
 * escala de 1 a 100 que usa a faixa inteira; aqui é média de OVR de onze
 * cartas, que na prática vive entre 60 e 85. Os mesmos 12 pontos que lá
 * valiam um empurrão viraram aqui uma sentença — na primeira medição deram
 * 86% de vitórias do mandante. Três é o que equivale, na escala comprimida,
 * ao que doze valiam na larga.
 */
const DFUTP_GOLS_MEDIA = 2.55;
const DFUTP_MANDO      = 3;

/** Quantos minutos a partida tem, e quantos lances perigosos ela costuma ter. */
const DFUTP_MINUTOS = 90;
const DFUTP_LANCES  = 18;

/**
 * Simula uma partida inteira, lance a lance.
 *
 * @param array $casa ['nome'=>, 'formacao'=>, 'time'=>[11 cartas], 'forca'=>]
 * @param array $fora idem
 * @param int   $semente  mesma semente, mesma partida
 * @return array ['placar'=>[a,b], 'lances'=>[...], 'estat'=>[...]]
 */
function draftFutPartida(array $casa, array $fora, int $semente): array
{
    mt_srand($semente);

    $fCasa = (int)$casa['forca'] + DFUTP_MANDO;
    $fFora = (int)$fora['forca'];

    /* A FATIA DE CADA UM NOS LANCES sai da diferença de força. O divisor é
       grande (24) porque a escala aqui é média de OVR, que anda num trecho
       estreito: com 16, dez pontos de diferença já davam 73% da posse e o
       jogo virava treino de finalização. Com 24, dez pontos dão ~65%, que é
       um favorito claro ainda sujeito a levar susto. */
    $vantagem = ($fCasa - $fFora) / 24;
    $pesoCasa = 1 / (1 + exp(-$vantagem));

    $lances = [];
    $gols = [0, 0];
    $estat = ['chutes' => [0, 0], 'no_gol' => [0, 0], 'defesas' => [0, 0], 'posse' => [0, 0]];

    /* OS MINUTOS DOS LANCES SÃO SORTEADOS E ORDENADOS, e não espalhados em
       intervalo fixo: futebol tem minuto morto e tem três chances em dois
       minutos. Regularidade demais faz a narração parecer relógio. */
    $minutos = [];
    for ($i = 0; $i < DFUTP_LANCES; $i++) $minutos[] = mt_rand(1, DFUTP_MINUTOS);
    sort($minutos);

    $intervaloPosto = false;
    foreach ($minutos as $min) {
        /* O INTERVALO É UM LANCE, e entra na hora em que o relógio passa dos
           45: sem ele a narração atravessa noventa minutos sem respirar, e
           quem lê perde a noção de quanto falta. */
        if (!$intervaloPosto && $min > 45) {
            $lances[] = ['min' => 45, 'tipo' => 'intervalo', 'lado' => -1,
                         'texto' => 'Fim do primeiro tempo.',
                         'casa' => $gols[0], 'fora' => $gols[1],
                         'placar' => $gols[0] . 'x' . $gols[1]];
            $intervaloPosto = true;
        }
        $atacaCasa = (mt_rand(1, 1000) / 1000) < $pesoCasa;
        $lado = $atacaCasa ? 0 : 1;
        $estat['posse'][$lado]++;

        $atacante = $atacaCasa ? $casa : $fora;
        $defensor = $atacaCasa ? $fora : $casa;

        /* A CHANCE DE GOL POR LANCE É A MESMA PROS DOIS, e é isso que mantém
           a média da partida onde ela foi medida. Eu a inclinava pela posse —
           o time melhor converteria mais —, só que aí a vantagem contava duas
           vezes: ele já tem mais lances, e cada lance dele valia mais. O total
           de gols subia junto com o desequilíbrio (3,07 num jogo de favorito)
           em vez de ficar na média.

           A superioridade aparece em QUANTOS lances cada um cria, que é a
           conta logo acima. Quem domina faz mais gols porque chuta mais, não
           porque a bola dele entra mais fácil. */
        $chanceGol = DFUTP_GOLS_MEDIA / DFUTP_LANCES;

        $estat['chutes'][$lado]++;
        $r = mt_rand(1, 10000) / 10000;

        if ($r < $chanceGol) {
            $gols[$lado]++;
            $estat['no_gol'][$lado]++;
            $autor = draftFutSorteiaAtacante($atacante);
            $lances[] = ['min' => $min, 'tipo' => 'gol', 'lado' => $lado,
                         'texto' => draftFutTextoGol($autor, $atacante['nome']),
                         'casa' => $gols[0], 'fora' => $gols[1],
                         'placar' => $gols[0] . 'x' . $gols[1]];
        } elseif ($r < $chanceGol * 2.6) {
            $estat['no_gol'][$lado]++;
            $estat['defesas'][1 - $lado]++;
            $autor = draftFutSorteiaAtacante($atacante);
            $goleiro = draftFutGoleiro($defensor);
            $lances[] = ['min' => $min, 'tipo' => 'defesa', 'lado' => $lado,
                         'texto' => draftFutTextoDefesa($autor, $goleiro)];
        } elseif ($r < $chanceGol * 4.2) {
            $autor = draftFutSorteiaAtacante($atacante);
            $lances[] = ['min' => $min, 'tipo' => 'perdeu', 'lado' => $lado,
                         'texto' => draftFutTextoPerdeu($autor)];
        } else {
            $autor = draftFutSorteiaAtacante($atacante);
            $lances[] = ['min' => $min, 'tipo' => 'lance', 'lado' => $lado,
                         'texto' => draftFutTextoLance($autor, $atacante['nome'])];
        }
    }

    /* O apito final é um lance como os outros: quem lê a narração espera o
       fim escrito, não a lista simplesmente acabando. */
    $lances[] = ['min' => DFUTP_MINUTOS, 'tipo' => 'fim', 'lado' => -1,
                 'texto' => 'Fim de jogo.', 'casa' => $gols[0], 'fora' => $gols[1],
                 'placar' => $gols[0] . 'x' . $gols[1]];

    $tp = max(1, $estat['posse'][0] + $estat['posse'][1]);
    $estat['posse'] = [(int)round(100 * $estat['posse'][0] / $tp),
                       100 - (int)round(100 * $estat['posse'][0] / $tp)];

    return ['placar' => $gols, 'lances' => $lances, 'estat' => $estat, 'semente' => $semente];
}

/**
 * Quem leva o lance: sorteia entre os onze, com peso pra quem joga na frente
 * e pra quem é melhor.
 *
 * O goleiro fica de fora porque gol de goleiro é piada, não evento — e a
 * piada deixa de ter graça na terceira vez que acontece numa tarde.
 */
function draftFutSorteiaAtacante(array $time): string
{
    $peso = ['ATA' => 10, 'PON' => 7, 'MEI' => 5, 'VOL' => 2, 'LAT' => 2, 'ZAG' => 1, 'GOL' => 0];
    $bolas = [];
    foreach ($time['time'] as $c) {
        if (!$c) continue;
        $p = ($peso[$c['pos']] ?? 1) * max(1, (int)$c['ovr'] - 55);
        for ($i = 0; $i < $p; $i++) $bolas[] = $c['nome'];
    }
    if (!$bolas) return 'o camisa 10';
    return $bolas[mt_rand(0, count($bolas) - 1)];
}

/** O goleiro do time, pra narração ter nome na defesa. */
function draftFutGoleiro(array $time): string
{
    foreach ($time['time'] as $c) {
        if ($c && $c['pos'] === 'GOL') return $c['nome'];
    }
    return 'o goleiro';
}

/* ── OS TEXTOS ───────────────────────────────────────────────────────
   Varios por tipo, sorteados: a mesma frase repetida dezoito vezes faz a
   partida inteira parecer um jogo só. */

function draftFutTextoGol(string $a, string $time): string
{
    $t = ["GOOOL! {$a} manda pra dentro e o {$time} comemora!",
          "GOOOL do {$time}! {$a} aparece na hora certa e empurra pro gol!",
          "GOOOL! Que chute de {$a}! Não teve defesa.",
          "GOOOL! {$a} invade a área e bate cruzado pro fundo da rede!",
          "GOOOL do {$time}! {$a} pega de primeira e estufa a rede!"];
    return $t[mt_rand(0, count($t) - 1)];
}

function draftFutTextoDefesa(string $a, string $g): string
{
    $t = ["{$a} chuta forte e {$g} espalma pra escanteio!",
          "Defesaça de {$g} no chute de {$a}!",
          "{$a} bate colocado e {$g} voa pra salvar!",
          "Cara a cara, {$a} finaliza e {$g} fecha o ângulo. Que defesa!"];
    return $t[mt_rand(0, count($t) - 1)];
}

function draftFutTextoPerdeu(string $a): string
{
    $t = ["{$a} fica na cara do gol e manda por cima! Inacreditável.",
          "{$a} chuta e a bola passa raspando a trave.",
          "{$a} tenta de fora da área e manda pra fora.",
          "{$a} cabeceia livre e joga pra fora! Perdeu uma enorme."];
    return $t[mt_rand(0, count($t) - 1)];
}

function draftFutTextoLance(string $a, string $time): string
{
    $t = ["{$a} puxa o contra-ataque pelo meio.",
          "O {$time} troca passes no campo de ataque com {$a}.",
          "{$a} arranca pela ponta e cruza na área.",
          "Falta perigosa pro {$time}. {$a} na cobrança.",
          "{$a} tenta o lançamento, mas a defesa corta."];
    return $t[mt_rand(0, count($t) - 1)];
}

/**
 * Um adversário gerado pra o modo contra a máquina.
 *
 * A FORÇA ALVO É A DO JOGADOR, com uma variação pequena: um adversário
 * sorteado do baralho inteiro seria fraco demais ou impossível, e nos dois
 * casos o jogo acaba na primeira partida. Assim o draft bem montado sempre
 * encara alguém à altura, e o mérito vem de jogar, não de ter tido sorte.
 */
function draftFutAdversarioDaMaquina(int $forcaAlvo, int $semente): array
{
    mt_srand($semente ^ 0x5EED);
    $alvo = $forcaAlvo + mt_rand(-3, 3);
    $formacao = array_rand(DFUT_FORMACOES);
    $vagas = DFUT_FORMACOES[$formacao];

    $time = []; $usados = [];
    foreach ($vagas as $i => [$rot, $pos]) {
        $ops = draftFutOpcoes($pos, $usados, max(50, $alvo - 6), min(99, $alvo + 6));
        if (!$ops) { $time[$i] = null; continue; }
        $c = $ops[mt_rand(0, count($ops) - 1)];
        $time[$i] = $c;
        $usados[] = $c['nome'];
    }

    /* O nome do time da máquina sai do clube mais repetido nele: um time com
       cinco jogadores do Palmeiras se chamando "Adversário" seria uma
       oportunidade jogada fora. */
    $clubes = array_count_values(array_filter(array_column($time, 'clube')));
    arsort($clubes);
    $base = (string)(array_key_first($clubes) ?: 'Seleção');

    return ['nome' => 'Combinado ' . $base, 'formacao' => $formacao, 'time' => $time,
            'forca' => draftFutForcaDoTime($formacao, $time)];
}
