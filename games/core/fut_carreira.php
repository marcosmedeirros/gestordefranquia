<?php
/**
 * A CARREIRA — o jogo em si: você é o técnico, e o resto é consequência.
 *
 * O motor sabe fazer partida e tabela, o mercado sabe preço e proposta. Aqui
 * é onde isso vira uma vida: você assume um clube, tem uma meta pra cumprir,
 * joga as rodadas, compra e vende, e no fim do ano a diretoria decide se você
 * fica. Se for bem, clube maior liga. Se for mal, você está desempregado.
 *
 * ── O QUE O SAVE GUARDA, E O QUE ELE NÃO GUARDA ──────────────────────
 *
 * Guardar o elenco dos 98 clubes a cada temporada seria um JSON gigante pra
 * gravar e reler a cada clique. Não é preciso: o elenco de todo clube é
 * DETERMINÍSTICO (fut_elencos.php gera sempre o mesmo a partir do nome), então
 * o save guarda só o que DIVERGIU daquilo — o seu elenco, e os jogadores que
 * mudaram de dono. O resto é recalculado na hora e sai idêntico.
 *
 * É o mesmo princípio de guardar as jogadas de uma partida de xadrez em vez do
 * tabuleiro a cada lance.
 */

require_once __DIR__ . '/fut_competicoes.php';
require_once __DIR__ . '/fut_mercado.php';

/** A versão do save. Se o formato mudar, é por aqui que a migração começa. */
const FUT_SAVE_VERSAO = 1;

/** Quantos jogadores um elenco precisa ter pra competir. */
const FUT_ELENCO_MINIMO = 16;
const FUT_ELENCO_MAXIMO = 30;

/**
 * A premiação por competição, em milhões — o que o clube ganha por ir bem.
 *
 * É o que faz a campanha valer dinheiro e não só orgulho: o técnico que leva um
 * clube de Série B longe na Copa do Brasil resolve o ano financeiro dele, que é
 * exatamente o que acontece no Brasil de verdade.
 */
const FUT_PREMIACAO = [
    'Brasileirão Série A' => ['campeao' => 45, 'vice' => 22, 'top4' => 12, 'top8' => 6],
    'Brasileirão Série B' => ['campeao' => 12, 'vice' => 8,  'top4' => 5,  'top8' => 2],
    'Copa do Brasil'       => ['campeao' => 40, 'vice' => 18, 'top4' => 8,  'top8' => 4],
    'Libertadores'         => ['campeao' => 90, 'vice' => 40, 'top4' => 20, 'top8' => 10],
    'Sul-Americana'        => ['campeao' => 30, 'vice' => 14, 'top4' => 7,  'top8' => 3],
    'Copa do Nordeste'     => ['campeao' => 8,  'vice' => 4,  'top4' => 2,  'top8' => 1],
    'Copa Verde'           => ['campeao' => 5,  'vice' => 2,  'top4' => 1,  'top8' => 0],
    'estadual'             => ['campeao' => 6,  'vice' => 3,  'top4' => 1,  'top8' => 0],
];

/**
 * A META que a diretoria cobra, pela divisão do clube e pelo tamanho dele.
 *
 * A meta é o que transforma uma tabela em emprego. Sem ela, terminar em 15º
 * seria só um número; com ela, é a diferença entre continuar e ser demitido.
 * Clube grande na Série A tem que brigar em cima; clube pequeno só precisa não
 * cair — o que é justo e é o que faz assumir um time pequeno ser um jeito
 * diferente de jogar, e não só uma versão mais difícil do mesmo jogo.
 *
 * @return array ['texto','tipo','alvo']
 */
function futMetaDaTemporada(array $clube): array
{
    $div = $clube['div'] ?? '';
    $forca = (int)($clube['forca'] ?? 50);

    if ($div === 'BR1') {
        if ($forca >= 84) return ['texto' => 'Terminar entre os 4 primeiros da Série A', 'tipo' => 'posicao', 'alvo' => 4];
        if ($forca >= 78) return ['texto' => 'Vaga na Libertadores (top 6)',             'tipo' => 'posicao', 'alvo' => 6];
        if ($forca >= 74) return ['texto' => 'Terminar na primeira metade da tabela',    'tipo' => 'posicao', 'alvo' => 10];
        return ['texto' => 'Escapar do rebaixamento', 'tipo' => 'posicao', 'alvo' => 16];
    }
    if ($div === 'BR2') {
        if ($forca >= 64) return ['texto' => 'Subir para a Série A', 'tipo' => 'posicao', 'alvo' => 4];
        return ['texto' => 'Terminar entre os 8 primeiros da Série B', 'tipo' => 'posicao', 'alvo' => 8];
    }
    if ($div === 'BR3') {
        return ['texto' => 'Brigar pelo acesso à Série B', 'tipo' => 'posicao', 'alvo' => 4];
    }
    // Clube que só joga estadual: a meta é o estadual mesmo.
    return ['texto' => 'Chegar à semifinal do estadual', 'tipo' => 'estadual', 'alvo' => 4];
}

/**
 * Os clubes onde dá pra COMEÇAR uma carreira.
 *
 * Técnico sem currículo não assume o Palmeiras, e deixar assumir tiraria a
 * carreira do jogo — se você já começa no melhor time, não sobra pra onde
 * subir. A régua é a reputação: 0 abre os pequenos, e cada título abre mais.
 */
function futClubesParaComecar(int $reputacao): array
{
    $tetoForca = match (true) {
        $reputacao >= 80 => 100,   // pode dirigir qualquer um
        $reputacao >= 60 => 84,
        $reputacao >= 40 => 78,
        $reputacao >= 20 => 68,
        default          => 58,    // começo de carreira: Série C e estaduais
    };

    $out = [];
    foreach (futClubesDoBrasil() as $c) {
        if ($c['forca'] > $tetoForca) continue;
        $out[$c['nome']] = $c;
    }
    uasort($out, fn($a, $b) => $b['forca'] <=> $a['forca']);
    return $out;
}

/** Garante a tabela do save. Migração preguiçosa, como os outros jogos. */
function futCarreiraGarantirTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS fut_carreira (
        user_id INT NOT NULL PRIMARY KEY,
        save LONGTEXT NOT NULL,
        atualizado DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $feito = true;
}

/** Lê o save de um usuário, ou null se ele ainda não tem carreira. */
function futCarreiraCarregar(PDO $pdo, int $userId): ?array
{
    if ($userId <= 0) return null;
    futCarreiraGarantirTabela($pdo);
    $st = $pdo->prepare("SELECT save FROM fut_carreira WHERE user_id = ?");
    $st->execute([$userId]);
    $json = $st->fetchColumn();
    if (!$json) return null;
    $s = json_decode($json, true);
    return is_array($s) ? $s : null;
}

/** Grava o save. */
function futCarreiraSalvar(PDO $pdo, int $userId, array $estado): void
{
    if ($userId <= 0) return;
    futCarreiraGarantirTabela($pdo);
    $st = $pdo->prepare("INSERT INTO fut_carreira (user_id, save, atualizado)
                         VALUES (?, ?, NOW())
                         ON DUPLICATE KEY UPDATE save = VALUES(save), atualizado = NOW()");
    $st->execute([$userId, json_encode($estado, JSON_UNESCAPED_UNICODE)]);
}

/** Apaga a carreira (recomeçar do zero). */
function futCarreiraApagar(PDO $pdo, int $userId): void
{
    if ($userId <= 0) return;
    futCarreiraGarantirTabela($pdo);
    $st = $pdo->prepare("DELETE FROM fut_carreira WHERE user_id = ?");
    $st->execute([$userId]);
}

/**
 * Começa uma carreira nova num clube.
 */
function futCarreiraNova(string $nomeTecnico, string $nomeClube, int $ano = 2026): array
{
    $clubes = futClubesDoBrasil();
    $clube = $clubes[$nomeClube] ?? null;
    if (!$clube) throw new InvalidArgumentException("Clube desconhecido: $nomeClube");

    $elenco = futElencoDoClube($nomeClube, (int)$clube['forca']);

    return [
        'versao'     => FUT_SAVE_VERSAO,
        'tecnico'    => ['nome' => $nomeTecnico, 'reputacao' => 10],
        'clube'      => $nomeClube,
        'ano'        => $ano,
        'temporada'  => 1,
        'caixa'      => futCaixaInicial((int)$clube['forca'], $clube['div']),
        'elenco'     => $elenco,
        'saidas'     => [],       // jogadores que deixaram os outros clubes
        'meta'       => futMetaDaTemporada($clube),
        'fase'       => 'mercado',   // mercado -> temporada -> fim
        'calendario' => [],
        'rodada'     => 0,
        'resultados' => [],
        'historico'  => [],
        'titulos'    => [],
        'mensagens'  => ['Você assumiu o ' . $nomeClube . '. A diretoria espera: ' . futMetaDaTemporada($clube)['texto'] . '.'],
    ];
}

/**
 * O clube do save, já com a força REAL do elenco atual.
 *
 * É por aqui que comprar e vender muda o jogo: a força que vai pro motor sai
 * do elenco que você tem hoje, não do número fixo do catálogo.
 */
function futCarreiraMeuClube(array $estado): array
{
    $clubes = futClubesDoBrasil();
    $c = $clubes[$estado['clube']] ?? ['nome' => $estado['clube'], 'forca' => 50, 'div' => '', 'uf' => '', 'escudo' => ''];
    $c['forca'] = futForcaDoElenco($estado['elenco'] ?? []);
    return $c;
}

/**
 * Os adversários de um clube numa competição, já com força de elenco e
 * descontando quem foi vendido.
 */
function futCarreiraTimes(array $clubes, array $estado): array
{
    $saidas = $estado['saidas'] ?? [];
    $meu = $estado['clube'] ?? '';
    $out = [];
    foreach ($clubes as $c) {
        if ($c['nome'] === $meu) { $out[] = futCarreiraMeuClube($estado); continue; }
        $elenco = futElencoDoClube($c['nome'], (int)$c['forca']);
        if (!empty($saidas[$c['nome']])) {
            $foram = $saidas[$c['nome']];
            $elenco = array_values(array_filter($elenco, fn($j) => !in_array($j['nome'], $foram, true)));
        }
        $c['forca'] = futForcaDoElenco($elenco);
        $out[] = $c;
    }
    return $out;
}

/**
 * O nome da competição nacional de uma divisão.
 */
function futCarreiraNomeDaDivisao(string $div): string
{
    return match ($div) {
        'BR1' => 'Brasileirão Série A',
        'BR2' => 'Brasileirão Série B',
        'BR3' => 'Brasileirão Série C',
        default => '',
    };
}

/**
 * O CALENDÁRIO DA LIGA INTEIRA, igual toda vez que for pedido.
 *
 * É a peça que faz a tabela bater com a campanha do jogador. A primeira versão
 * montava o calendário do jogador embaralhando adversários por conta própria e
 * depois, pra desenhar a tabela, simulava os jogos dos outros com uma conta
 * solta — o resultado era uma classificação onde uns times tinham jogado 40
 * partidas e outros 20, na mesma rodada. Não dava pra confiar em nada ali.
 *
 * Agora existe UM calendário só: o do round-robin da divisão. Os jogos do
 * jogador são os dele dentro desse calendário, e a tabela simula exatamente as
 * mesmas rodadas. Como futCalendario() é determinístico e os clubes entram em
 * ordem alfabética, ele sai idêntico a cada chamada sem precisar ser guardado
 * no save.
 */
function futCarreiraCalendarioDaLiga(string $div): array
{
    $ids = array_keys(futClubesDaDivisao($div));
    sort($ids);   // ordem fixa: é o que torna o round-robin reproduzível
    return futCalendario($ids);
}

/**
 * MONTA O CALENDÁRIO DA TEMPORADA do clube do jogador.
 *
 * O ano segue a forma do calendário brasileiro: o estadual abre, e o nacional
 * ocupa o resto, com a Copa do Brasil e a copa regional encaixadas no meio.
 * Tudo vira uma lista só de compromissos, porque é assim que o técnico vive o
 * ano — ele clica "próxima partida" e o jogo diz qual é, em vez de obrigar a
 * escolher que competição avançar.
 *
 * @return array lista de ['comp','adversario','casa','fase','rodada']
 */
function futCarreiraMontarCalendario(array $estado): array
{
    $meu = $estado['clube'];
    $clubes = futClubesDoBrasil();
    $eu = $clubes[$meu] ?? null;
    if (!$eu) return [];

    $abertura = [];   // o começo do ano: estadual e regional
    $miolo = [];      // o resto: nacional e Copa do Brasil

    // ── O estadual, pra quem tem ─────────────────────────────────────
    $uf = $eu['uf'] ?? '';
    if ($uf !== '' && isset(FUT_ESTADUAIS[$uf])) {
        $nomes = array_values(array_diff(array_keys(futClubesDoEstado($uf)), [$meu]));
        shuffle($nomes);
        foreach ($nomes as $i => $adv) {
            $abertura[] = ['comp' => FUT_ESTADUAIS[$uf], 'adversario' => $adv,
                           'casa' => $i % 2 === 0, 'fase' => 'Turno único'];
        }
    }

    // ── A copa regional (Nordeste ou Verde) ──────────────────────────
    $regiao = $eu['regiao'] ?? '';
    if ($regiao !== '' && isset(FUT_REGIONAIS[$regiao])) {
        $nomes = array_values(array_diff(array_keys(futClubesDaRegiao($regiao)), [$meu]));
        shuffle($nomes);
        foreach (array_slice($nomes, 0, 6) as $i => $adv) {
            $abertura[] = ['comp' => FUT_REGIONAIS[$regiao], 'adversario' => $adv,
                           'casa' => $i % 2 === 0, 'fase' => 'Fase de grupos'];
        }
    }
    shuffle($abertura);

    // ── O nacional, extraído do calendário da divisão ────────────────
    $div = $eu['div'] ?? '';
    if ($div !== '') {
        $nome = futCarreiraNomeDaDivisao($div);
        foreach (futCarreiraCalendarioDaLiga($div) as $n => $jogos) {
            foreach ($jogos as [$casa, $fora]) {
                if ($casa !== $meu && $fora !== $meu) continue;
                $miolo[] = [
                    'comp'       => $nome,
                    'adversario' => $casa === $meu ? $fora : $casa,
                    'casa'       => $casa === $meu,
                    'fase'       => 'Rodada ' . ($n + 1),
                    'liga_rodada'=> $n + 1,   // pra tabela saber até onde simular
                ];
            }
        }
    }

    // ── A Copa do Brasil: mata-mata até cair ─────────────────────────
    $todos = array_keys($clubes);
    shuffle($todos);
    $advCopa = array_values(array_diff(array_slice($todos, 0, 10), [$meu]));
    $fases = ['Primeira fase', 'Segunda fase', 'Oitavas', 'Quartas', 'Semifinal', 'Final'];
    foreach ($fases as $i => $fase) {
        if (!isset($advCopa[$i])) break;
        $miolo[] = ['comp' => 'Copa do Brasil', 'adversario' => $advCopa[$i],
                    'casa' => $i % 2 === 0, 'fase' => $fase, 'copa_fase' => $i];
    }

    /* A Copa do Brasil é ESPALHADA pelo ano em vez de ficar toda no fim: as
       fases dela acontecem entre as rodadas do nacional, e empilhá-las no
       final faria o jogador disputar seis mata-matas seguidos em dezembro. */
    $daCopa = array_values(array_filter($miolo, fn($j) => $j['comp'] === 'Copa do Brasil'));
    $doNacional = array_values(array_filter($miolo, fn($j) => $j['comp'] !== 'Copa do Brasil'));
    $ordenado = $doNacional;
    if ($daCopa && $doNacional) {
        $passo = max(1, (int)floor(count($doNacional) / (count($daCopa) + 1)));
        $ordenado = [];
        $c = 0;
        foreach ($doNacional as $i => $j) {
            $ordenado[] = $j;
            if ($c < count($daCopa) && ($i + 1) % $passo === 0) $ordenado[] = $daCopa[$c++];
        }
        while ($c < count($daCopa)) $ordenado[] = $daCopa[$c++];
    }

    $cal = array_merge($abertura, $ordenado);
    foreach ($cal as $i => &$j) $j['rodada'] = $i + 1;
    unset($j);
    return $cal;
}
/**
 * JOGA A PRÓXIMA PARTIDA do calendário.
 *
 * @return array ['fim'=>bool, 'jogo'=>array|null, 'estado'=>array]
 */
function futCarreiraJogarProxima(array $estado): array
{
    $cal = $estado['calendario'] ?? [];
    $i = (int)($estado['rodada'] ?? 0);
    if ($i >= count($cal)) return ['fim' => true, 'jogo' => null, 'estado' => $estado];

    $j = $cal[$i];
    $clubes = futClubesDoBrasil();
    $meuTime = futCarreiraMeuClube($estado);

    $advClube = $clubes[$j['adversario']] ?? ['nome' => $j['adversario'], 'forca' => 50, 'div' => '', 'uf' => ''];
    $advTime = futCarreiraTimes([$advClube], $estado)[0];

    $p = $j['casa']
        ? futPlacar($meuTime['forca'], $advTime['forca'])
        : futPlacar($advTime['forca'], $meuTime['forca']);

    $meusGols  = $j['casa'] ? $p['casa'] : $p['fora'];
    $golsDeles = $j['casa'] ? $p['fora'] : $p['casa'];

    $resultado = [
        'comp'       => $j['comp'],
        'liga_rodada'=> $j['liga_rodada'] ?? 0,   // a tabela usa isto
        'adversario' => $j['adversario'],
        'casa'       => $j['casa'],
        'meus'       => $meusGols,
        'deles'      => $golsDeles,
        'rodada'     => $j['rodada'],
    ];

    $estado['resultados'][] = $resultado;
    $estado['rodada'] = $i + 1;

    return ['fim' => false, 'jogo' => $resultado, 'estado' => $estado];
}

/** A campanha do clube numa competição: J, V, E, D, pontos. */
function futCarreiraCampanha(array $estado, ?string $comp = null): array
{
    $t = ['j' => 0, 'v' => 0, 'e' => 0, 'd' => 0, 'gp' => 0, 'gc' => 0, 'p' => 0];
    foreach ($estado['resultados'] ?? [] as $r) {
        if ($comp !== null && $r['comp'] !== $comp) continue;
        $t['j']++;
        $t['gp'] += $r['meus'];
        $t['gc'] += $r['deles'];
        if ($r['meus'] > $r['deles'])      { $t['v']++; $t['p'] += 3; }
        elseif ($r['meus'] === $r['deles']) { $t['e']++; $t['p'] += 1; }
        else                                { $t['d']++; }
    }
    $t['sg'] = $t['gp'] - $t['gc'];
    return $t;
}

/**
 * A TABELA da competição nacional, com o clube do jogador dentro dela.
 *
 * Simula as mesmas rodadas do calendário da liga que o jogador já disputou,
 * trocando os jogos dele pelos resultados de verdade. Como todo mundo joga as
 * mesmas rodadas, a classificação fica honesta: ninguém aparece com 30 jogos
 * enquanto o jogador tem 12.
 *
 * Os jogos dos outros clubes não vão pro save — são recalculados com semente
 * fixa na temporada. Sem a semente, atualizar a página daria uma tabela
 * diferente a cada vez, e o jogador concluiria (com razão) que o jogo inventa
 * os números.
 */
function futCarreiraTabelaNacional(array $estado): array
{
    $clubes = futClubesDoBrasil();
    $div = $clubes[$estado['clube']]['div'] ?? '';
    if ($div === '') return [];

    $comp = futCarreiraNomeDaDivisao($div);
    $times = futCarreiraTimes(futClubesDaDivisao($div), $estado);
    $porNome = [];
    foreach ($times as $t) $porNome[$t['nome']] = $t;

    // Até que rodada da liga o jogador chegou, e o que aconteceu nos jogos dele.
    $ateRodada = 0;
    $meus = [];
    foreach ($estado['resultados'] ?? [] as $r) {
        if ($r['comp'] !== $comp) continue;
        $ateRodada = max($ateRodada, (int)($r['liga_rodada'] ?? 0));
        $meus[(int)($r['liga_rodada'] ?? 0)] = $r;
    }
    if ($ateRodada <= 0) return [];

    mt_srand(crc32($estado['clube'] . '|t' . $estado['temporada'] . '|r' . $ateRodada));

    $resultados = [];
    foreach (futCarreiraCalendarioDaLiga($div) as $n => $jogos) {
        $rodada = $n + 1;
        if ($rodada > $ateRodada) break;
        foreach ($jogos as [$casa, $fora]) {
            if (!isset($porNome[$casa], $porNome[$fora])) continue;

            // O jogo do jogador entra como foi de verdade.
            if (($casa === $estado['clube'] || $fora === $estado['clube']) && isset($meus[$rodada])) {
                $r = $meus[$rodada];
                $resultados[] = $r['casa']
                    ? ['casa' => $estado['clube'], 'fora' => $r['adversario'], 'gc' => $r['meus'], 'gf' => $r['deles']]
                    : ['casa' => $r['adversario'], 'fora' => $estado['clube'], 'gc' => $r['deles'], 'gf' => $r['meus']];
                continue;
            }
            $p = futPlacar($porNome[$casa]['forca'], $porNome[$fora]['forca']);
            $resultados[] = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
        }
    }
    mt_srand();   // devolve o sorteio ao estado normal

    return futClassificacao(array_keys($porNome), $resultados);
}
/** A posição do clube do jogador na tabela nacional (1 = líder). */
function futCarreiraMinhaPosicao(array $estado): ?int
{
    $tab = futCarreiraTabelaNacional($estado);
    if (!$tab) return null;
    $pos = 0;
    foreach (array_keys($tab) as $nome) {
        $pos++;
        if ($nome === $estado['clube']) return $pos;
    }
    return null;
}

/**
 * FECHA A TEMPORADA: paga a folha, distribui premiação, julga a meta e decide
 * se o técnico continua no emprego.
 *
 * @return array ['estado'=>array, 'relatorio'=>array]
 */
function futCarreiraFecharTemporada(array $estado): array
{
    $clube = futCarreiraMeuClube($estado);
    $clubes = futClubesDoBrasil();
    $div = $clubes[$estado['clube']]['div'] ?? '';

    $receita = futReceitaAnual((int)($clubes[$estado['clube']]['forca'] ?? 50), $div);
    $folha   = futFolhaDoElenco($estado['elenco']);
    $posicao = futCarreiraMinhaPosicao($estado);

    // ── Premiação pela campanha no nacional ──────────────────────────
    $comp = $div === 'BR1' ? 'Brasileirão Série A' : ($div === 'BR2' ? 'Brasileirão Série B' : '');
    $premio = 0;
    if ($comp !== '' && $posicao !== null && isset(FUT_PREMIACAO[$comp])) {
        $t = FUT_PREMIACAO[$comp];
        if ($posicao === 1)      $premio = $t['campeao'];
        elseif ($posicao === 2)  $premio = $t['vice'];
        elseif ($posicao <= 4)   $premio = $t['top4'];
        elseif ($posicao <= 8)   $premio = $t['top8'];
    }

    $estado['caixa'] = round($estado['caixa'] + $receita + $premio - $folha, 2);

    // ── A meta foi cumprida? ─────────────────────────────────────────
    $meta = $estado['meta'] ?? futMetaDaTemporada($clube);
    $cumpriu = $posicao !== null && $posicao <= (int)($meta['alvo'] ?? 99);

    // ── Reputação e emprego ──────────────────────────────────────────
    $rep = (int)($estado['tecnico']['reputacao'] ?? 10);
    if ($posicao === 1)      $rep += 15;
    elseif ($cumpriu)        $rep += 6;
    else                     $rep -= 8;
    $rep = max(0, min(100, $rep));
    $estado['tecnico']['reputacao'] = $rep;

    /* DEMISSÃO: não cumprir a meta não demite na hora — a diretoria dá uma
       segunda chance na primeira vez. Demitir no primeiro ano ruim tornaria
       assumir clube pequeno uma armadilha, já que lá a margem é mínima. */
    $falhas = (int)($estado['falhas'] ?? 0);
    $falhas = $cumpriu ? 0 : $falhas + 1;
    $estado['falhas'] = $falhas;
    $demitido = $falhas >= 2;

    $titulo = null;
    if ($posicao === 1 && $comp !== '') {
        $titulo = $comp . ' ' . $estado['ano'];
        $estado['titulos'][] = $titulo;
    }

    $estado['historico'][] = [
        'ano'      => $estado['ano'],
        'clube'    => $estado['clube'],
        'comp'     => $comp,
        'posicao'  => $posicao,
        'campanha' => futCarreiraCampanha($estado, $comp ?: null),
        'cumpriu'  => $cumpriu,
        'titulo'   => $titulo,
    ];

    $relatorio = [
        'posicao'   => $posicao,
        'receita'   => $receita,
        'folha'     => $folha,
        'premio'    => $premio,
        'caixa'     => $estado['caixa'],
        'meta'      => $meta,
        'cumpriu'   => $cumpriu,
        'demitido'  => $demitido,
        'reputacao' => $rep,
        'titulo'    => $titulo,
    ];

    // ── O ano vira ───────────────────────────────────────────────────
    $estado['ano']++;
    $estado['temporada']++;
    $estado['rodada'] = 0;
    $estado['resultados'] = [];
    $estado['calendario'] = [];
    $estado['fase'] = $demitido ? 'desempregado' : 'mercado';

    // Todo mundo envelhece um ano, e o OVR acompanha a curva de carreira.
    $estado['elenco'] = futCarreiraEnvelhecerElenco($estado['elenco']);
    $estado['meta'] = futMetaDaTemporada($clube);

    return ['estado' => $estado, 'relatorio' => $relatorio];
}

/**
 * Passa um ano no elenco: todo mundo fica mais velho, e o OVR segue a curva.
 *
 * O GAROTO MELHORA E O VETERANO PIORA, e é isso que faz o elenco ter idade
 * como recurso e não como enfeite. Um time inteiro de 33 anos ganha este ano e
 * se desmancha no seguinte — que é a armadilha clássica do gênero, e ela
 * precisa existir pro jogo ter consequência de médio prazo.
 */
function futCarreiraEnvelhecerElenco(array $elenco): array
{
    foreach ($elenco as &$j) {
        $antes = FUT_CURVA_IDADE[(int)$j['idade']] ?? -18;
        $j['idade'] = (int)$j['idade'] + 1;
        $depois = FUT_CURVA_IDADE[(int)$j['idade']] ?? -18;

        /* O degrau da curva é o que o ano fez com ele. Jovem sobe (de -5 pra
           -4 é +1), veterano desce. Um ruído pequeno evita que todo elenco
           evolua igualzinho. */
        $delta = $depois - $antes + (mt_rand(0, 100) < 25 ? (mt_rand(0, 1) ? 1 : -1) : 0);
        $j['ovr'] = (int)max(25, min(99, (int)$j['ovr'] + $delta));
    }
    unset($j);

    // Quem passou dos 39 pendura as chuteiras.
    return array_values(array_filter($elenco, fn($j) => (int)$j['idade'] <= 39));
}

/**
 * COMPRAR um jogador. Devolve o estado novo ou o motivo da recusa.
 *
 * @return array ['ok'=>bool, 'motivo'=>string, 'estado'=>array]
 */
function futCarreiraComprar(array $estado, string $clubeVendedor, string $jogador, float $oferta): array
{
    $clubes = futClubesDoBrasil();
    $vendedor = $clubes[$clubeVendedor] ?? null;
    if (!$vendedor) return ['ok' => false, 'motivo' => 'Clube desconhecido.', 'estado' => $estado];

    if (count($estado['elenco']) >= FUT_ELENCO_MAXIMO) {
        return ['ok' => false, 'motivo' => 'Seu elenco está cheio (' . FUT_ELENCO_MAXIMO . ' jogadores).', 'estado' => $estado];
    }
    if ($oferta > $estado['caixa']) {
        return ['ok' => false, 'motivo' => 'Você não tem esse dinheiro em caixa.', 'estado' => $estado];
    }

    $elencoDele = futElencoDoClube($clubeVendedor, (int)$vendedor['forca']);
    $foram = $estado['saidas'][$clubeVendedor] ?? [];
    $elencoDele = array_values(array_filter($elencoDele, fn($j) => !in_array($j['nome'], $foram, true)));

    $alvo = null;
    foreach ($elencoDele as $j) if ($j['nome'] === $jogador) { $alvo = $j; break; }
    if (!$alvo) return ['ok' => false, 'motivo' => 'Esse jogador não está mais no clube.', 'estado' => $estado];

    $postos = futPostosDoElenco($elencoDele);
    $valor = futValorDeMercado((int)$alvo['ovr'], (int)$alvo['idade']);
    $pedido = futPrecoPedido($valor, $postos[$alvo['nome']] ?? 25);

    $r = futAvaliarProposta($oferta, $pedido, count($elencoDele), $postos[$alvo['nome']] ?? 25);
    if (!$r['aceita']) return ['ok' => false, 'motivo' => $r['motivo'], 'estado' => $estado];

    // O jogador também precisa querer vir.
    $meu = futCarreiraMeuClube($estado);
    $salario = futSalarioDe((int)$alvo['ovr'], (int)$alvo['idade']);
    $quer = futJogadorAceita((int)$alvo['ovr'], $salario, $salario, (int)$meu['forca']);
    if (!$quer['aceita']) return ['ok' => false, 'motivo' => $quer['motivo'], 'estado' => $estado];

    $estado['caixa'] = round($estado['caixa'] - $oferta, 2);
    $estado['saidas'][$clubeVendedor][] = $alvo['nome'];
    $alvo['num'] = 0;
    $estado['elenco'][] = $alvo;
    $estado['mensagens'][] = sprintf('%s (%d) chegou do %s por %.2f mi.',
        $alvo['nome'], $alvo['ovr'], $clubeVendedor, $oferta);

    return ['ok' => true, 'motivo' => $r['motivo'], 'estado' => $estado];
}

/**
 * VENDER um jogador do seu elenco.
 */
function futCarreiraVender(array $estado, string $jogador, float $oferta, string $comprador): array
{
    if (count($estado['elenco']) <= FUT_ELENCO_MINIMO) {
        return ['ok' => false, 'motivo' => 'Seu elenco ficaria abaixo do mínimo de ' . FUT_ELENCO_MINIMO . '.', 'estado' => $estado];
    }
    $achou = false;
    foreach ($estado['elenco'] as $i => $j) {
        if ($j['nome'] !== $jogador) continue;
        unset($estado['elenco'][$i]);
        $achou = true;
        break;
    }
    if (!$achou) return ['ok' => false, 'motivo' => 'Esse jogador não está no seu elenco.', 'estado' => $estado];

    $estado['elenco'] = array_values($estado['elenco']);
    $estado['caixa'] = round($estado['caixa'] + $oferta, 2);
    $estado['mensagens'][] = sprintf('%s foi vendido ao %s por %.2f mi.', $jogador, $comprador, $oferta);

    return ['ok' => true, 'motivo' => 'Venda fechada.', 'estado' => $estado];
}
