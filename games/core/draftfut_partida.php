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
 * @param bool  $desempatar no duelo não existe empate: empatou, vai pros
 *        pênaltis. Contra o bot fica false, porque lá o empate tem prêmio
 *        próprio. @see draftFutPenaltis
 * @return array ['placar'=>[a,b], 'lances'=>[...], 'estat'=>[...], 'penaltis'=>]
 */
function draftFutPartida(array $casa, array $fora, int $semente, ?int $mando = null,
                         bool $desempatar = false): array
{
    mt_srand($semente);

    /* O MANDO É OPCIONAL, e no duelo por código ele é ZERO. O padrão vale
       contra o bot, onde o GM joga em casa e o adversário é gerado. Entre dois
       GMs não há casa: quem criou o duelo não pode ganhar três pontos de força
       por ter clicado primeiro. */
    $fCasa = (int)$casa['forca'] + ($mando ?? DFUTP_MANDO);
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

    /* ── NO DUELO NÃO EXISTE EMPATE ───────────────────────────────
       Pedido do Marcos (09/10/2026), vendo um 1x1 ao vivo: "isso nunca é
       empate, vou ter que criar os pênaltis". E empate não era raro: dos
       seis duelos concluídos até ali, QUATRO terminaram iguais — a escala
       de força é estreita e os times saem parecidos, então o 1x1 é o
       resultado mais provável, não o azar.

       Contra o bot o empate continua existindo: lá ele tem prêmio próprio
       (DF_EMPATE) e serve de consolo. Aqui tem aposta dos dois lados e
       alguém precisa levar.

       Os pênaltis entram como lances normais da narração, então o relógio
       da tela os mostra um a um, como o resto do jogo. */
    $penaltis = null;
    if ($desempatar && $gols[0] === $gols[1]) {
        /* O APITO DOS 90' DEIXA DE SER O FIM. A narração abre o prêmio e os
           botões no lance de tipo 'fim' — com a disputa vindo depois, o
           resultado apareceria antes da primeira cobrança, que é o mesmo
           defeito que o Marcos já tinha apontado no placar adiantado. */
        $lances[count($lances) - 1]['tipo'] = 'apito';
        $lances[count($lances) - 1]['texto'] = 'Fim do tempo normal: '
                . $gols[0] . ' a ' . $gols[1] . '.';

        [$penaltis, $lancesPen] = draftFutPenaltis($casa, $fora, $pesoCasa);
        foreach ($lancesPen as $l) $lances[] = $l;
        /* O placar do tempo normal NÃO muda: 1x1 nos pênaltis continua 1x1,
           e quem decide o vencedor é `penaltis`. Somar as cobranças no
           placar faria a narração contar uma partida que não aconteceu. */
    }

    return ['placar' => $gols, 'lances' => $lances, 'estat' => $estat,
            'penaltis' => $penaltis, 'semente' => $semente];
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
 * A DISPUTA DE PÊNALTIS.
 *
 * Cinco cobranças pra cada, e morte súbita se continuar igual. A ordem é
 * alternada de verdade (casa, fora, casa, fora...) porque é assim que a
 * tensão sobe: o segundo a bater sempre cobra sabendo o que o outro fez.
 *
 * PARA QUANDO JÁ ESTÁ DECIDIDO. Com 3x0 depois de três cobranças, as duas
 * últimas de cada lado não são batidas — é a regra do futebol, e sem ela a
 * narração seguiria cobrando pênalti de uma disputa que acabou.
 *
 * A força pesa pouco de propósito: `$pesoCasa` move a chance entre 68% e
 * 82%, que é a faixa real de conversão. Pênalti é onde o time pior tem a
 * melhor chance da partida, e é isso que faz valer a pena assistir.
 *
 * @return array [[gols casa, gols fora], lances]
 */
function draftFutPenaltis(array $casa, array $fora, float $pesoCasa): array
{
    $nomes = [(string)($casa['nome'] ?? 'Casa'), (string)($fora['nome'] ?? 'Fora')];

    /* ── A ORDEM DOS BATEDORES ────────────────────────────────────────
       Quem bate primeiro é quem o técnico mandaria: atacante na frente,
       goleiro no fim da fila. Na primeira versão a ordem era a das vagas da
       formação, e a vaga 1 é o GOLEIRO — a disputa abria com o goleiro
       cobrando, que é o último recurso do futebol de verdade, não o primeiro.

       Faltando gente, a fila repete: um time incompleto não trava a disputa. */
    $ordem = ['ATA' => 1, 'PON' => 2, 'MEI' => 3, 'VOL' => 4, 'LAT' => 5, 'ZAG' => 6, 'GOL' => 9];
    $batedores = [[], []];
    foreach ([0 => $casa, 1 => $fora] as $i => $t) {
        $fila = [];
        for ($v = 0; $v < DFUT_VAGAS; $v++) {
            $c = $t['time'][$v] ?? null;
            if (!$c) continue;
            $fila[] = [$ordem[$c['pos']] ?? 7, -(int)($c['ovr'] ?? 0), $v, (string)$c['nome']];
        }
        sort($fila);
        foreach ($fila as $x) $batedores[$i][] = $x[3];
        if (!$batedores[$i]) $batedores[$i] = [$nomes[$i]];
    }

    $pen = [0, 0];
    $batidas = [0, 0];
    $lances = [['min' => DFUTP_MINUTOS, 'tipo' => 'penaltis', 'lado' => -1,
                'texto' => 'Empate no tempo normal. Vamos pros pênaltis!']];

    /* A força pesa pouco: a conversão anda entre 68% e 82%. */
    $chance = [0.68 + 0.14 * $pesoCasa, 0.68 + 0.14 * (1 - $pesoCasa)];

    /** Uma cobrança: sorteia, soma e narra. O placar da disputa vai no texto
     *  JÁ ATUALIZADO, e por isso ela narra na hora em que acontece — guardar
     *  as duas cobranças de uma rodada pra narrar depois fazia o número ao
     *  lado da cobrança perdida já mostrar o gol que ainda não tinha saído. */
    $cobrar = function (int $lado) use (&$pen, &$batidas, &$lances, $batedores, $chance, $nomes) {
        $fila = $batedores[$lado];
        $quem = $fila[$batidas[$lado] % count($fila)];
        $marcou = (mt_rand(1, 1000) / 1000) <= $chance[$lado];
        $batidas[$lado]++;
        if ($marcou) $pen[$lado]++;
        $lances[] = [
            'min'   => DFUTP_MINUTOS,
            'tipo'  => $marcou ? 'pen_gol' : 'pen_erro',
            'lado'  => $lado,
            'texto' => draftFutTextoPenalti($quem, $marcou)
                     . '  (' . $pen[0] . '-' . $pen[1] . ')',
        ];
    };

    /** PARA QUANDO JÁ ESTÁ DECIDIDO: com 3x0 em três cobranças, as duas
     *  últimas não são batidas. É a regra do futebol, e sem ela a narração
     *  segue cobrando pênalti de uma disputa que acabou. */
    $decidido = function () use (&$pen, &$batidas) {
        $faltam = [max(0, 5 - $batidas[0]), max(0, 5 - $batidas[1])];
        return $pen[0] > $pen[1] + $faltam[1] || $pen[1] > $pen[0] + $faltam[0];
    };

    for ($serie = 0; $serie < 5; $serie++) {
        for ($lado = 0; $lado < 2; $lado++) {
            if ($decidido()) break 2;
            $cobrar($lado);
        }
    }

    /* MORTE SÚBITA: um de cada, até alguém falhar sozinho. O teto existe
       porque isto roda num laço e um empate eterno travaria a página. */
    for ($extra = 0; $pen[0] === $pen[1] && $extra < 15; $extra++) {
        $cobrar(0);
        $cobrar(1);
    }

    /* Teto estourado com tudo igual — 15 rodadas de morte súbita, que não
       deve acontecer nunca. Decide a moeda, porque devolver empate aqui seria
       devolver pro duelo exatamente o que ele não aceita. */
    if ($pen[0] === $pen[1]) $pen[mt_rand(0, 1)]++;

    $vence = $pen[0] > $pen[1] ? 0 : 1;
    $lances[] = ['min' => DFUTP_MINUTOS, 'tipo' => 'fim', 'lado' => -1,
                 'texto' => $nomes[$vence] . ' vence nos pênaltis por '
                           . max($pen) . ' a ' . min($pen) . '.',
                 'placar' => $pen[0] . 'x' . $pen[1]];

    return [$pen, $lances];
}

/** O texto de uma cobrança. */
function draftFutTextoPenalti(string $quem, bool $marcou): string
{
    /* NOVE E OITO, não quatro e quatro. Uma disputa tem catorze cobranças
       fáceis, e com quatro frases a mesma aparecia três vezes na mesma tela —
       exatamente o que o comentário dos textos da partida já avisava. */
    $gol = ["{$quem} bate no canto e marca!",
            "{$quem} desloca o goleiro: GOL!",
            "{$quem} cobra com categoria e converte.",
            "{$quem} manda no meio e o goleiro já tinha caído. Gol.",
            "{$quem} escolhe o canto esquerdo e não dá chance. Gol.",
            "Frieza de {$quem}: espera o goleiro cair e toca do outro lado.",
            "{$quem} bate forte, sem olhar pro goleiro. No fundo da rede!",
            "{$quem} pega firme e a bola entra no ângulo. Gol!",
            "{$quem} cobra no alto, perto da trave. Gol, sem defesa possível."];
    $erro = ["{$quem} bate mal e o goleiro defende!",
             "{$quem} manda na trave! Perdeu.",
             "{$quem} isola por cima do gol.",
             "O goleiro adivinha o canto e pega a de {$quem}!",
             "{$quem} bate fraco e o goleiro segura no meio do gol!",
             "Que defesa! {$quem} caprichou e o goleiro espalmou.",
             "{$quem} manda no travessão. A bola sobe pra fora!",
             "{$quem} escorrega na hora da batida e manda longe."];
    $t = $marcou ? $gol : $erro;
    return $t[mt_rand(0, count($t) - 1)];
}

/**
 * Um adversário gerado pra o modo contra a máquina.
 *
 * A FORÇA ALVO É A DO JOGADOR, com uma variação pequena: um adversário
 * sorteado do baralho inteiro seria fraco demais ou impossível, e nos dois
 * casos o jogo acaba na primeira partida. Assim o draft bem montado sempre
 * encara alguém à altura, e o mérito vem de jogar, não de ter tido sorte.
 *
 * ── NINGUÉM JOGA DOS DOIS LADOS ──────────────────────────────────────
 *
 * `$jaEmCampo` são os nomes do time de quem está jogando. Sem isso o bot
 * sorteava do baralho inteiro e podia escalar exatamente a mesma carta: a
 * tela das escalações mostrou o Emiliano Martínez no gol dos dois times, com
 * a mesma foto e o mesmo OVR, o que é um erro visível de um lado ao outro da
 * mesma página. Dentro do próprio time isso já era impedido por `$usados`;
 * faltava o outro lado.
 */
function draftFutAdversarioDaMaquina(int $forcaAlvo, int $semente, array $jaEmCampo = []): array
{
    mt_srand($semente ^ 0x5EED);
    $alvo = $forcaAlvo + mt_rand(-3, 3);
    $formacao = array_rand(DFUT_FORMACOES);
    $vagas = DFUT_FORMACOES[$formacao];

    /* Os nomes de lá entram como se já tivessem sido usados aqui — é
       exatamente o que `$usados` quer dizer pro sorteio. */
    $time = []; $usados = array_values(array_filter($jaEmCampo));
    foreach ($vagas as $i => [$rot, $pos]) {
        $ops = draftFutOpcoes($pos, $usados, max(50, $alvo - 6), min(99, $alvo + 6));
        if (!$ops) { $time[$i] = null; continue; }
        $c = $ops[mt_rand(0, count($ops) - 1)];
        $time[$i] = $c;
        $usados[] = $c['nome'];
    }

    /* O NOME DO TIME DA MÁQUINA É O CLUBE MAIS REPETIDO NELE, e só ele.
       Pedido do Marcos (08/10/2026): "nao precisa disso de combinado, apenas
       o nome do time. normal tipo Barcelona". Chamar de "Combinado Barcelona"
       explicava o truque em vez de deixar o time ser um time — e quem joga
       não precisa saber como a lista foi montada pra encarar o Barcelona.

       O ESCUDO VEM JUNTO: ele já está no catálogo, então o adversário entra
       no placar com a cara dele em vez de um nome solto. */
    $clubes = array_count_values(array_filter(array_column($time, 'clube')));
    arsort($clubes);
    $base = (string)(array_key_first($clubes) ?: 'Seleção');

    $escudo = '';
    foreach (COPERO_CLUBES as [$cn, $cl, $cf, $ce]) {
        if ($cn === $base) { $escudo = (string)$ce; break; }
    }

    return ['nome' => $base, 'formacao' => $formacao, 'time' => $time,
            'escudo' => $escudo,
            'forca' => draftFutForcaDoTime($formacao, $time)];
}
