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
require_once __DIR__ . '/fut_partida.php';   // traz junto fut_escalacao e fut_condicao
require_once __DIR__ . '/fut_evolucao.php';
require_once __DIR__ . '/fut_mundo.php';

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
    'Brasileirão Série C' => ['campeao' => 5,  'vice' => 3,  'top4' => 2,  'top8' => 1],
    'Brasileirão Série D' => ['campeao' => 3,  'vice' => 2,  'top4' => 1,  'top8' => 0],
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
    $nome = $clube['nome'] ?? '';

    /* Sem divisão nenhuma: rede de segurança pra quem adicionar um clube novo
       ao catálogo e esquecer a divisão. O estadual é o que sobra pra medir. */
    if ($div === '') {
        $forca = (int)($clube['forca'] ?? 50);
        if ($forca >= 44) return ['texto' => 'Chegar à semifinal do estadual', 'tipo' => 'estadual', 'alvo' => 4];
        return ['texto' => 'Chegar às quartas do estadual', 'tipo' => 'estadual', 'alvo' => 8];
    }

    /* ONDE O CLUBE ESTÁ NA FILA DA DIVISÃO — é daqui que a cobrança sai.
       Antes eram faixas de força escritas à mão, uma por divisão, e cada vez
       que a escala dos elencos mudava as faixas ficavam desencontradas: o
       melhor clube da Série D, com a maior força da divisão, recebia "acesso"
       e cumpria zero vezes em vinte temporadas, enquanto o pior da Série A
       recebia "escapar do rebaixamento" e também não cumpria. Ranqueando, a
       régua acompanha o catálogo sozinha. */
    $divisao = futClubesDaDivisao($div);
    uasort($divisao, fn($a, $b) => (int)$b['forca'] <=> (int)$a['forca']);
    $total = count($divisao);
    $lugar = 1;
    foreach (array_keys($divisao) as $k => $n) {
        if ($n === $nome) { $lugar = $k + 1; break; }
    }
    if ($total < 4) return ['texto' => 'Terminar entre os 2 primeiros', 'tipo' => 'posicao', 'alvo' => 2];

    /* A DIRETORIA COBRA UM POUCO MAIS DO QUE O ELENCO ENTREGA — mas só um
       pouco. Três posições acima do lugar natural do clube é o bastante pra a
       meta exigir uma boa temporada sem exigir um milagre; o técnico cumpre em
       pouco mais da metade dos anos, que é o ponto onde o emprego vale algo e
       a demissão continua sendo um risco de verdade. */
    /* A margem acompanha o tamanho da divisão, e o topo tem piso: nem a
       diretoria do Palmeiras exige o título todo ano — exige briga por ele. */
    $margem = max(2, (int)round($total * 0.12));
    $pisoTopo = max(2, (int)round($total * 0.15));
    $alvo = max($pisoTopo, min($total - 2, $lugar - $margem));

    // ── E o texto, que é o que o jogador lê ──────────────────────────
    $acesso = ['BR2' => 'Série A', 'BR3' => 'Série B', 'BR4' => 'Série C'];
    if ($alvo === 1) {
        $texto = $div === 'BR1' ? 'Ser campeão brasileiro' : 'Ser campeão da divisão';
    } elseif ($div === 'BR1' && $alvo <= 4) {
        $texto = 'Terminar entre os ' . $alvo . ' primeiros da Série A';
    } elseif ($div === 'BR1' && $alvo <= 6) {
        $texto = 'Vaga na Libertadores (top ' . $alvo . ')';
    } elseif ($div !== 'BR1' && $alvo <= 4) {
        $texto = 'Subir para a ' . ($acesso[$div] ?? 'divisão de cima');
    } elseif ($alvo >= $total - $margem) {
        $texto = $div === 'BR4' ? 'Não terminar na lanterna' : 'Escapar do rebaixamento';
    } else {
        $texto = 'Terminar entre os ' . $alvo . ' primeiros';
    }

    return ['texto' => $texto, 'tipo' => 'posicao', 'alvo' => $alvo];
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

    $elenco = futGarantirCondicao(futElencoDoClube($nomeClube, (int)$clube['forca']));
    foreach ($elenco as &$j) $j['potencial'] = futPotencialDe($j);
    unset($j);

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
        'esquema'    => '4-4-2',
        'escalacao'  => [],      // [vaga => nome]; vazio = escala sozinho
        'stats'      => [],      // [nome => gols, assist, cartões, notas]
        'suspensos'  => [],      // [nome => jogos que ainda faltam cumprir]
        'entradas'   => [],      // quem CHEGOU a cada clube (ver fut_mundo)
        'trocas_tecnico' => [],  // quantas vezes cada clube trocou de técnico
        'noticias'   => [],      // o que aconteceu no mundo na virada do ano
        'propostas'  => [],      // clubes que querem o técnico
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
    /* A FORÇA DO ELENCO, e não a de campo.
       As duas existem e servem a perguntas diferentes: "quanto vale este
       clube" é elenco, "quanto ele rende hoje" é campo. Quando esta função
       devolvia a de campo, a resposta oscilava com o cansaço — e como ela é a
       régua que o mercado usa pra saber se um jogador topa vir, um garoto
       recusava o convite na quarta-feira e aceitava no domingo, sem nada ter
       mudado no clube. Quem precisa da força de campo pede por ela
       (@see futCarreiraForcaEmCampo, usada na tabela). */
    $c['forca'] = futForcaDoElenco($estado['elenco'] ?? []);
    return $c;
}

/**
 * Os adversários de um clube numa competição, já com força de elenco e
 * descontando quem foi vendido.
 */
function futCarreiraTimes(array $clubes, array $estado): array
{
    $meu = $estado['clube'] ?? '';
    $rodada = (int)($estado['rodada'] ?? 0);
    $out = [];
    $esquema = $estado['esquema'] ?? '4-4-2';
    foreach ($clubes as $c) {
        if ($c['nome'] === $meu) {
            /* NA TABELA, A MINHA FORÇA É A DE CAMPO — é ela que enfrenta a dos
               rivais no mesmo futPlacar. Fora daqui vale a do elenco. */
            $eu = futCarreiraMeuClube($estado);
            $escalados = futCarreiraEscalacaoAtual($estado);
            if ($escalados) $eu['forca'] = futForcaEscalada($escalados, $esquema);
            $out[] = $eu;
            continue;
        }
        $c['forca'] = futCarreiraForcaEmCampo($estado, $c['nome'], (int)$c['forca'], $rodada);
        $out[] = $c;
    }
    return $out;
}

/**
 * A FORÇA COM QUE UM CLUBE DO MUNDO ENTRA EM CAMPO nesta altura do ano.
 *
 * A MESMA RÉGUA DOS DOIS LADOS. A tabela media o rival pela média do elenco
 * dele — força cheia, sempre — enquanto o time do jogador entra pela força
 * escalada, que desconta o esquema, o improviso, o cansaço e a moral. A
 * campanha do jogador saía então de um jogo e a dos rivais de outro: o clube
 * mais forte da Série D terminava em último com a maior força da divisão.
 *
 * Aqui o rival passa pelas mesmas três funções que o time do jogador —
 * condição, escalação e futForcaEscalada —, então os pontos que ele faz valem
 * o mesmo que os do jogador.
 *
 * Guarda em memória por clube e rodada: a tabela da Série D pede isto 42 vezes
 * por tela, e gerar elenco e escalar não é de graça.
 */
function futCarreiraForcaEmCampo(array $estado, string $clube, int $forcaCatalogo, int $rodada): int
{
    static $cache = [];
    $chave = $clube . '|' . $forcaCatalogo . '|' . $rodada . '|' . count($estado['saidas'][$clube] ?? []);
    if (isset($cache[$chave])) return $cache[$chave];

    $elenco = futCondicaoSimulada(
        futElencoNoJogo($estado, $clube, $forcaCatalogo), $clube, $rodada);
    $esquema = array_keys(FUT_ESQUEMAS)[crc32($clube) % count(FUT_ESQUEMAS)];
    $forca = futForcaEscalada(futEscalarAutomatico($elenco, $esquema), $esquema);

    if (count($cache) > 4000) $cache = [];   // o save vira outro a cada temporada
    return $cache[$chave] = $forca;
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
        'BR4' => 'Brasileirão Série D',
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
    // A Série D tem 42 clubes: turno e returno ali dariam 82 rodadas.
    return futCalendario($ids, $div !== 'BR4');
}

/**
 * O NOME CURTO da divisão, para a tela.
 *
 * futCarreiraNomeDaDivisao devolve "Brasileirão Série C", que é o nome da
 * competição e fica comprido no meio de uma linha de clube. Aqui sai só
 * "Série C" — e, principalmente, sai um nome: a tela mostrava o código cru do
 * catálogo, então o jogador lia "BR3" na lista de clubes.
 */
function futCarreiraRotuloDaDivisao(string $div): string
{
    return match ($div) {
        'BR1' => 'Série A',
        'BR2' => 'Série B',
        'BR3' => 'Série C',
        'BR4' => 'Série D',
        default => 'estadual',
    };
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
/** Quantas partidas guardam os lances no save. */
const FUT_JOGOS_COM_RESUMO = 12;

/**
 * A ESCALAÇÃO QUE VAI A CAMPO nesta partida.
 *
 * Usa a escolha do jogador quando ela é válida, e escala sozinho quando não é
 * — elenco mudou, jogador vendido, suspenso de última hora. O time NUNCA entra
 * em campo desfalcado por falha de escalação: quem esquece de escalar joga com
 * o que o automático escolher, e não perde por W.O.
 */
function futCarreiraEscalacaoAtual(array $estado): array
{
    $elenco = $estado['elenco'] ?? [];
    $esquema = $estado['esquema'] ?? '4-4-2';
    $fora = array_keys($estado['suspensos'] ?? []);

    $escolha = $estado['escalacao'] ?? [];
    if ($escolha) {
        $v = futValidarEscalacao($escolha, $elenco, $esquema, $fora);
        if ($v['ok']) return $v['escalados'];
    }
    return futEscalarAutomatico($elenco, $esquema, $fora);
}

/**
 * JOGA A PRÓXIMA PARTIDA do calendário, com lances, cartões e notas.
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
    $esquema = $estado['esquema'] ?? '4-4-2';

    $meus = futCarreiraEscalacaoAtual($estado);
    $forcaMeu = futForcaEscalada($meus, $esquema);

    // O adversário escala sozinho, no esquema que o catálogo dele pedir.
    $advClube = $clubes[$j['adversario']] ?? ['nome' => $j['adversario'], 'forca' => 50, 'div' => '', 'uf' => ''];
    $elencoAdv = futElencoNoJogo($estado, $advClube['nome'], (int)$advClube['forca']);
    $elencoAdv = futCondicaoSimulada($elencoAdv, $advClube['nome'], $i);
    $esquemaAdv = array_keys(FUT_ESQUEMAS)[crc32($advClube['nome']) % count(FUT_ESQUEMAS)];
    $deles = futEscalarAutomatico($elencoAdv, $esquemaAdv);

    /* A FORÇA DOS DOIS LADOS SAI DA MESMA FUNÇÃO. Antes o jogador entrava com
       futForcaEscalada — que desconta cansaço, moral e improviso — e o rival
       com a média nominal do elenco dele. Eram duas réguas diferentes na mesma
       partida, e a pior era sempre a do jogador (@see futCondicaoSimulada). */
    $forcaAdv = futForcaEscalada($deles, $esquemaAdv);

    $p = futSimularPartida($meus, $deles, $forcaMeu, $forcaAdv, (bool)$j['casa']);

    // ── O que a partida deixou: estatística, cartão, suspensão ───────
    $estado['stats'] = futAcumularEstatisticas($estado['stats'] ?? [], $meus, $p);
    $estado['suspensos'] = futAtualizarSuspensoes($estado['suspensos'] ?? [], $estado['stats'], $p);
    $estado['stats'] = futZerarAmarelos($estado['stats']);

    // ── E o que ela custou: cansaço, moral e lesão ───────────────────
    $desg = futAplicarDesgaste($estado['elenco'], $meus, $p['meus'], $p['deles']);
    $estado['elenco'] = $desg['elenco'];
    $avisosDaPartida = $desg['noticias'];

    /* Machucado ou suspenso não pode seguir escalado: a escalação salva cai
       e o time volta ao automático, que já respeita quem está fora. Sem isto
       o jogador entraria em campo com dez. */
    if ($avisosDaPartida || $estado['suspensos']) $estado['escalacao'] = [];

    $resultado = [
        'comp'        => $j['comp'],
        'liga_rodada' => $j['liga_rodada'] ?? 0,   // a tabela usa isto
        'adversario'  => $j['adversario'],
        'casa'        => $j['casa'],
        'meus'        => $p['meus'],
        'deles'       => $p['deles'],
        'rodada'      => $j['rodada'],
        'fase'        => $j['fase'] ?? '',
        'eventos'     => $p['eventos'],
        'escalacao'   => array_map(fn($x) => ['nome' => $x['nome'], 'pos' => $x['pos'],
                                              'ovr' => $x['ovr'], 'nota' => $p['notas'][$x['nome']] ?? 6.0], $meus),
        'avisos'      => $avisosDaPartida,
    ];

    /* COPA DECIDE AQUI: quem perdeu não tem mais jogo nela. */
    $mm = futCarreiraResolverMataMata($estado, $resultado);
    $estado = $mm['estado'];
    $resultado['passou'] = $mm['passou'];
    $resultado['penaltis'] = $mm['penaltis'];

    $estado['resultados'][] = $resultado;
    $estado['rodada'] = $i + 1;

    /* OS LANCES SÓ FICAM NOS ÚLTIMOS JOGOS. Uma temporada tem 50 partidas, e
       guardar lances e notas de todas engordaria o save a cada clique sem que
       ninguém vá reler o resumo do jogo 3. O que interessa a longo prazo já
       está somado em 'stats'. */
    $n = count($estado['resultados']);
    if ($n > FUT_JOGOS_COM_RESUMO) {
        for ($k = 0; $k < $n - FUT_JOGOS_COM_RESUMO; $k++) {
            unset($estado['resultados'][$k]['eventos'], $estado['resultados'][$k]['escalacao']);
        }
    }

    return ['fim' => false, 'jogo' => $resultado, 'estado' => $estado];
}
/* ═══════════════════════════════════════════════════════════════════════
   A PARTIDA AO VIVO

   Jogar era clicar num botão e ler o placar: o jogo inteiro acontecia entre
   dois carregamentos de página, e a escalação que você montou com cuidado
   valia tanto quanto o esquema que você nunca olhou. Agora o relógio anda, os
   lances chegam no minuto em que acontecem, e dá pra parar no meio e mexer.

   O QUE FAZ A PAUSA VALER é o motor por pedaços (@see futSimularTrecho): cada
   trecho é simulado com a força e a estratégia do momento, então trocar o time
   aos 60 muda os trinta minutos que faltam — e não muda nada do que já passou.

   O ADVERSÁRIO NÃO MORA NO SAVE. O elenco dele é regerado a cada trecho, e sai
   igual porque futElencoNoJogo e futCondicaoSimulada são determinísticos pelo
   nome do clube e pela rodada. Guardar 25 jogadores do rival no save só pra
   durar 90 minutos engordaria o arquivo de todo mundo.
   ═══════════════════════════════════════════════════════════════════════ */

/** Quantos minutos de jogo cada pedaço simula. */
const FUT_AOVIVO_PASSO = 5;

/**
 * O adversário da partida em andamento, montado do zero.
 *
 * @return array ['clube','elenco','escalados','esquema','forca']
 */
function futAoVivoAdversario(array $estado, string $adversario, int $rodada): array
{
    $clubes = futClubesDoBrasil();
    $c = $clubes[$adversario] ?? ['nome' => $adversario, 'forca' => 50, 'div' => '', 'uf' => '', 'escudo' => ''];

    $elenco = futCondicaoSimulada(
        futElencoNoJogo($estado, $c['nome'], (int)$c['forca']), $c['nome'], $rodada);
    $esquema = array_keys(FUT_ESQUEMAS)[crc32($c['nome']) % count(FUT_ESQUEMAS)];
    $escalados = futEscalarAutomatico($elenco, $esquema);

    return ['clube' => $c, 'elenco' => $elenco, 'escalados' => $escalados,
            'esquema' => $esquema, 'forca' => futForcaEscalada($escalados, $esquema)];
}

/**
 * APITA O INÍCIO: prepara a partida que está no topo do calendário.
 *
 * @return array ['ok'=>bool,'erro'=>string,'estado'=>array]
 */
function futCarreiraAoVivoIniciar(array $estado): array
{
    if (!empty($estado['aovivo'])) return ['ok' => true, 'erro' => '', 'estado' => $estado];

    $cal = $estado['calendario'] ?? [];
    $i = (int)($estado['rodada'] ?? 0);
    if ($i >= count($cal)) return ['ok' => false, 'erro' => 'A temporada acabou.', 'estado' => $estado];

    $j = $cal[$i];
    $adv = futAoVivoAdversario($estado, $j['adversario'], $i);

    $estado['aovivo'] = [
        'indice'      => $i,
        'minuto'      => 0,
        'meus'        => 0,
        'deles'       => 0,
        'eventos'     => [],
        'cartoes'     => [],
        'gols'        => [],
        'jogaram'     => [],
        'trocas'      => [],   // as substituições já feitas (máximo cinco)
        'numeros'     => ['posse' => 50, 'chutes' => 0, 'chutes_deles' => 0,
                          'no_alvo' => 0, 'no_alvo_deles' => 0, 'trechos' => 0],
        'comp'        => $j['comp'],
        'fase'        => $j['fase'] ?? '',
        'rodada'      => $j['rodada'] ?? 0,
        'liga_rodada' => $j['liga_rodada'] ?? 0,
        'adversario'  => $j['adversario'],
        'casa'        => (bool)$j['casa'],
        'forca_adv'   => $adv['forca'],
    ];

    return ['ok' => true, 'erro' => '', 'estado' => $estado];
}

/**
 * ANDA COM O RELÓGIO até o minuto pedido, simulando o que acontece no caminho.
 *
 * @return array ['ok'=>bool,'estado'=>array,'novos'=>array,'fim'=>bool]
 */
function futCarreiraAoVivoAvancar(array $estado, int $ate): array
{
    $v = $estado['aovivo'] ?? null;
    if (!$v) return ['ok' => false, 'estado' => $estado, 'novos' => [], 'fim' => true];

    $de = (int)$v['minuto'] + 1;
    $ate = min(90, max($de, $ate));
    if ($de > 90) return ['ok' => true, 'estado' => $estado, 'novos' => [], 'fim' => true];

    $esquema = $estado['esquema'] ?? '4-4-2';
    $meus = futCarreiraEscalacaoAtual($estado);
    $forcaMeu = futForcaEscalada($meus, $esquema);
    $adv = futAoVivoAdversario($estado, (string)$v['adversario'], (int)$v['indice']);

    /* A SÚMULA ATRAVESSA OS PEDAÇOS. Quem já levou amarelo hoje leva o
       vermelho no próximo, como em qualquer jogo. */
    $pendurados = [];
    foreach ($v['cartoes'] as $c) {
        if (($c['tipo'] ?? '') === 'amarelo') $pendurados[] = $c['jogador']['nome'];
    }

    $t = futSimularTrecho($meus, $adv['escalados'], $forcaMeu, (int)$v['forca_adv'],
                          (bool)$v['casa'], $de, $ate, $estado['estrategia'] ?? [], $pendurados);

    $v['minuto']  = $ate;
    $v['meus']   += $t['meus'];
    $v['deles']  += $t['deles'];
    $v['eventos'] = array_merge($v['eventos'], $t['eventos']);
    $v['cartoes'] = array_merge($v['cartoes'], $t['cartoes']);
    $v['gols']    = array_merge($v['gols'], $t['gols']);

    /* QUEM PISOU EM CAMPO cansa no fim, mesmo que tenha saído no meio. A lista
       é de nomes porque a escalação pode mudar de um trecho pro outro. */
    foreach ($meus as $x) $v['jogaram'][$x['nome']] = true;

    /* AS ESTATÍSTICAS SOMAM; a posse é média dos trechos, porque ela é uma
       porcentagem e somar porcentagem não quer dizer nada. */
    $n = $t['numeros'] ?? [];
    $ant = $v['numeros'] ?? ['posse' => 50, 'chutes' => 0, 'chutes_deles' => 0,
                             'no_alvo' => 0, 'no_alvo_deles' => 0, 'trechos' => 0];
    $trechos = (int)$ant['trechos'] + 1;
    $v['numeros'] = [
        'posse'         => (int)round((($ant['posse'] * (int)$ant['trechos']) + (int)($n['posse'] ?? 50)) / $trechos),
        'chutes'        => (int)$ant['chutes'] + (int)($n['chutes'] ?? 0),
        'chutes_deles'  => (int)$ant['chutes_deles'] + (int)($n['chutes_deles'] ?? 0),
        'no_alvo'       => (int)$ant['no_alvo'] + (int)($n['no_alvo'] ?? 0),
        'no_alvo_deles' => (int)$ant['no_alvo_deles'] + (int)($n['no_alvo_deles'] ?? 0),
        'trechos'       => $trechos,
    ];

    $estado['aovivo'] = $v;
    return ['ok' => true, 'estado' => $estado, 'novos' => $t['eventos'], 'fim' => $ate >= 90];
}

/** Quantas substituições cabem numa partida. */
const FUT_AOVIVO_TROCAS = 5;

/**
 * TROCA UM JOGADOR NO MEIO DA PARTIDA.
 *
 * É a decisão mais clássica do futebol e a única que faltava na pausa. Vale do
 * minuto seguinte em diante, como a estratégia: o trecho que já foi simulado
 * não volta atrás (@see futSimularTrecho).
 *
 * QUEM SAI NÃO VOLTA, e por isso a conta de trocas é a lista de quem saiu —
 * não um contador solto que uma segunda janela poderia furar.
 *
 * @return array ['ok'=>bool,'erro'=>string,'estado'=>array]
 */
function futCarreiraAoVivoSubstituir(array $estado, string $sai, string $entra): array
{
    $falha = fn(string $e) => ['ok' => false, 'erro' => $e, 'estado' => $estado];

    $v = $estado['aovivo'] ?? null;
    if (!$v) return $falha('Não há partida em andamento.');
    if ((int)$v['minuto'] >= 90) return $falha('A partida acabou.');
    if (count($v['trocas'] ?? []) >= FUT_AOVIVO_TROCAS) {
        return $falha('Você já fez as ' . FUT_AOVIVO_TROCAS . ' substituições.');
    }

    $esquema = $estado['esquema'] ?? '4-4-2';
    $escalados = futCarreiraEscalacaoAtual($estado);

    $vaga = null;
    foreach ($escalados as $iv => $j) {
        if ($j['nome'] === $sai) { $vaga = $iv; break; }
    }
    if ($vaga === null) return $falha($sais = $sai . ' não está em campo.');

    foreach ($v['trocas'] ?? [] as $t) {
        if (($t['entra'] ?? '') === $sai) {
            return $falha($sai . ' acabou de entrar — tire outro.');
        }
    }

    // Quem entra tem que estar no banco, inteiro e disponível.
    $reservas = futReservas($estado['elenco'], $escalados, array_keys($estado['suspensos'] ?? []));
    $achou = null;
    foreach ($reservas as $r) if ($r['nome'] === $entra) { $achou = $r; break; }
    if (!$achou) return $falha($entra . ' não está disponível no banco.');

    /* A ESCALAÇÃO SALVA É UM MAPA DE VAGA PRA NOME. Quando o técnico nunca
       mexeu, ela está vazia e o time é o automático — então ela é montada
       aqui a partir de quem está em campo, senão a troca se perderia. */
    $mapa = $estado['escalacao'] ?? [];
    if (!$mapa) {
        foreach ($escalados as $iv => $j) $mapa[$iv] = $j['nome'];
    }
    $mapa[$vaga] = $entra;

    $val = futValidarEscalacao($mapa, $estado['elenco'], $esquema,
                               array_keys($estado['suspensos'] ?? []));
    if (!$val['ok']) return $falha($val['erro']);

    $estado['escalacao'] = $mapa;
    $v['trocas'][] = ['minuto' => (int)$v['minuto'], 'sai' => $sai, 'entra' => $entra];
    $v['eventos'][] = ['minuto' => (int)$v['minuto'], 'tipo' => 'troca', 'meu' => true,
                       'jogador' => $entra, 'sai' => $sai,
                       'pos' => $achou['pos'] ?? ''];
    $estado['aovivo'] = $v;

    return ['ok' => true, 'erro' => '', 'estado' => $estado];
}

/** As notas de agora, para a tela mostrar enquanto a bola rola. */
function futCarreiraAoVivoNotas(array $estado): array
{
    $v = $estado['aovivo'] ?? null;
    if (!$v) return [];
    return futNotasDaPartida(futCarreiraEscalacaoAtual($estado), $v['gols'],
                             $v['cartoes'], (int)$v['meus'], (int)$v['deles']);
}

/**
 * APITA O FIM: cobra o preço da partida e guarda o resultado.
 *
 * É o mesmo fechamento de futCarreiraJogarProxima — estatística, suspensão,
 * cansaço e o resumo que a tela lê depois. O que muda é de onde vêm os gols.
 *
 * @return array ['ok'=>bool,'jogo'=>array|null,'estado'=>array]
 */
function futCarreiraAoVivoFechar(array $estado): array
{
    $v = $estado['aovivo'] ?? null;
    if (!$v || (int)$v['minuto'] < 90) {
        return ['ok' => false, 'jogo' => null, 'estado' => $estado];
    }

    $esquema = $estado['esquema'] ?? '4-4-2';
    $meus = futCarreiraEscalacaoAtual($estado);

    /* Todo mundo que entrou em campo na partida, e não só quem estava lá no
       apito final: é quem vai cansar e quem ganha jogo na estatística. */
    $porNome = [];
    foreach ($estado['elenco'] ?? [] as $x) $porNome[$x['nome']] = $x;
    $participaram = [];
    foreach (array_keys($v['jogaram']) as $nome) {
        if (isset($porNome[$nome])) $participaram[] = $porNome[$nome];
    }
    if (!$participaram) $participaram = $meus;

    $p = [
        'meus'    => (int)$v['meus'],
        'deles'   => (int)$v['deles'],
        'eventos' => $v['eventos'],
        'gols'    => $v['gols'],
        'cartoes' => $v['cartoes'],
        'notas'   => futNotasDaPartida($participaram, $v['gols'], $v['cartoes'],
                                       (int)$v['meus'], (int)$v['deles']),
    ];

    $estado['stats'] = futAcumularEstatisticas($estado['stats'] ?? [], $participaram, $p);
    $estado['suspensos'] = futAtualizarSuspensoes($estado['suspensos'] ?? [], $estado['stats'], $p);
    $estado['stats'] = futZerarAmarelos($estado['stats']);

    $desg = futAplicarDesgaste($estado['elenco'], $participaram, $p['meus'], $p['deles']);
    $estado['elenco'] = $desg['elenco'];
    $avisos = $desg['noticias'];

    if ($avisos || $estado['suspensos']) $estado['escalacao'] = [];

    $resultado = [
        'comp'        => $v['comp'],
        'liga_rodada' => $v['liga_rodada'],
        'adversario'  => $v['adversario'],
        'casa'        => $v['casa'],
        'meus'        => $p['meus'],
        'deles'       => $p['deles'],
        'rodada'      => $v['rodada'],
        'fase'        => $v['fase'],
        'eventos'     => $p['eventos'],
        'escalacao'   => array_map(fn($x) => ['nome' => $x['nome'], 'pos' => $x['pos'],
                                              'ovr' => $x['ovr'], 'nota' => $p['notas'][$x['nome']] ?? 6.0], $participaram),
        'avisos'      => $avisos,
    ];

    $mm = futCarreiraResolverMataMata($estado, $resultado);
    $estado = $mm['estado'];
    $resultado['passou'] = $mm['passou'];
    $resultado['penaltis'] = $mm['penaltis'];

    $estado['resultados'][] = $resultado;
    $estado['rodada'] = (int)$v['indice'] + 1;
    unset($estado['aovivo']);

    $n = count($estado['resultados']);
    if ($n > FUT_JOGOS_COM_RESUMO) {
        for ($k = 0; $k < $n - FUT_JOGOS_COM_RESUMO; $k++) {
            unset($estado['resultados'][$k]['eventos'], $estado['resultados'][$k]['escalacao']);
        }
    }

    return ['ok' => true, 'jogo' => $resultado, 'estado' => $estado];
}

/**
 * AS COMPETIÇÕES EM QUE PERDER SIGNIFICA IR PRA CASA.
 *
 * A copa regional não entra: lá a fase que o jogo simula é de grupos, e grupo
 * não elimina no jogo — classifica no fim.
 */
function futCarreiraEhMataMata(string $comp, string $fase): bool
{
    if (!in_array($comp, ['Copa do Brasil', 'Libertadores', 'Sul-Americana'], true)) return false;
    return stripos($fase, 'grupo') === false;
}

/**
 * DECIDE SE O CLUBE SEGUE NA COPA, E TIRA O RESTO DO CALENDÁRIO SE NÃO SEGUIR.
 *
 * A COPA NÃO ELIMINAVA NINGUÉM. O calendário nascia com as seis fases da Copa
 * do Brasil e as seis eram jogadas, ganhasse ou perdesse — medindo três
 * carreiras, todas as três jogaram a final, e duas delas depois de terem
 * perdido na segunda fase. Isso não é uma copa, é uma lista de seis jogos
 * avulsos com nomes bonitos.
 *
 * EMPATE VAI PROS PÊNALTIS, como em mata-mata de jogo único. O placar do tempo
 * normal continua sendo o que a tabela e a estatística enxergam; os pênaltis
 * só decidem quem passa.
 *
 * @return array ['estado'=>array, 'passou'=>bool, 'penaltis'=>bool]
 */
function futCarreiraResolverMataMata(array $estado, array $resultado): array
{
    $comp = (string)($resultado['comp'] ?? '');
    $fase = (string)($resultado['fase'] ?? '');
    if (!futCarreiraEhMataMata($comp, $fase)) {
        return ['estado' => $estado, 'passou' => true, 'penaltis' => false];
    }

    $meus = (int)$resultado['meus'];
    $deles = (int)$resultado['deles'];
    $penaltis = false;

    if ($meus > $deles) {
        $passou = true;
    } elseif ($meus < $deles) {
        $passou = false;
    } else {
        $penaltis = true;
        $clubes = futClubesDoBrasil();
        $minha = (int)futCarreiraMeuClube($estado)['forca'];
        $dele = (int)($clubes[$resultado['adversario']]['forca'] ?? 50);
        $passou = futPenaltis($minha, $dele);
    }

    if (!$passou) {
        /* ELIMINADO: o que sobrava dessa copa no calendário some. Os jogos
           passados ficam — eles são a campanha, e é deles que o chaveamento
           conta até onde o clube foi. */
        $i = (int)($estado['rodada'] ?? 0);
        $novo = [];
        foreach ($estado['calendario'] ?? [] as $k => $j) {
            if ($k > $i && ($j['comp'] ?? '') === $comp) continue;
            $novo[] = $j;
        }
        $estado['calendario'] = $novo;
    }

    return ['estado' => $estado, 'passou' => $passou, 'penaltis' => $penaltis];
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
/**
 * A TABELA DO ESTADUAL, pelo mesmo molde da nacional.
 *
 * Existe porque a meta de quem não tem divisão nacional é o estadual, e até
 * aqui ela não tinha como ser avaliada: o fechamento comparava a colocação na
 * LIGA, que pra esses clubes é null, e null nunca passa em comparação nenhuma.
 * O resultado era que os 42 clubes só-estaduais reprovavam em toda temporada e
 * o técnico era demitido na segunda, sempre — jogasse bem ou mal.
 *
 * O estadual é turno único entre os clubes do estado. Os jogos do técnico
 * entram como aconteceram; o resto é simulado com a mesma semente da tabela
 * nacional, pra tabela não dançar entre dois F5.
 */
function futCarreiraTabelaEstadual(array $estado): array
{
    $clubes = futClubesDoBrasil();
    $eu = $clubes[$estado['clube']] ?? null;
    $uf = $eu['uf'] ?? '';
    if ($uf === '' || !isset(FUT_ESTADUAIS[$uf])) return [];

    $comp  = FUT_ESTADUAIS[$uf];
    $times = futCarreiraTimes(futClubesDoEstado($uf), $estado);
    $porNome = [];
    foreach ($times as $t) $porNome[$t['nome']] = $t;
    if (count($porNome) < 2) return [];

    // O que o técnico já jogou no estadual, do jeito que terminou.
    $meus = [];
    foreach ($estado['resultados'] ?? [] as $r) {
        if (($r['comp'] ?? '') !== $comp) continue;
        $meus[] = $r;
    }
    if (!$meus) return [];

    mt_srand(crc32($estado['clube'] . '|estadual|t' . ($estado['temporada'] ?? 0) . '|' . count($meus)));

    $resultados = [];
    $jogados = [];
    foreach ($meus as $r) {
        $resultados[] = $r['casa']
            ? ['casa' => $estado['clube'], 'fora' => $r['adversario'], 'gc' => $r['meus'], 'gf' => $r['deles']]
            : ['casa' => $r['adversario'], 'fora' => $estado['clube'], 'gc' => $r['deles'], 'gf' => $r['meus']];
        $jogados[$r['adversario']] = true;
    }

    /* Os jogos entre os OUTROS clubes do estado, pra tabela ter sentido: sem
       eles o técnico apareceria sozinho com pontos e todo o resto zerado. */
    $nomes = array_keys($porNome);
    foreach ($nomes as $i => $casa) {
        foreach (array_slice($nomes, $i + 1) as $fora) {
            if ($casa === $estado['clube'] || $fora === $estado['clube']) continue;
            $p = futPlacar($porNome[$casa]['forca'], $porNome[$fora]['forca']);
            $resultados[] = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
        }
    }
    mt_srand();

    return futClassificacao($nomes, $resultados);
}

/**
 * A TABELA DE UM GRUPO DE CLUBES, com os jogos do técnico como aconteceram.
 *
 * É o miolo que o estadual e a copa regional compartilham: pega quem disputa,
 * usa os resultados de verdade do técnico e simula os jogos entre os outros
 * pra tabela ter sentido — sem isso ele apareceria sozinho com pontos e todo
 * o resto zerado.
 */
function futCarreiraTabelaDeGrupo(array $estado, array $clubes, string $comp): array
{
    $times = futCarreiraTimes($clubes, $estado);
    $porNome = [];
    foreach ($times as $t) $porNome[$t['nome']] = $t;
    if (count($porNome) < 2) return [];

    $meus = [];
    foreach ($estado['resultados'] ?? [] as $r) {
        if (($r['comp'] ?? '') !== $comp) continue;
        $meus[] = $r;
    }
    if (!$meus) return [];

    mt_srand(crc32($estado['clube'] . '|' . $comp . '|t' . ($estado['temporada'] ?? 0) . '|' . count($meus)));

    $resultados = [];
    foreach ($meus as $r) {
        $resultados[] = $r['casa']
            ? ['casa' => $estado['clube'], 'fora' => $r['adversario'], 'gc' => $r['meus'], 'gf' => $r['deles']]
            : ['casa' => $r['adversario'], 'fora' => $estado['clube'], 'gc' => $r['deles'], 'gf' => $r['meus']];
    }

    $nomes = array_keys($porNome);
    foreach ($nomes as $i => $casa) {
        foreach (array_slice($nomes, $i + 1) as $fora) {
            if ($casa === $estado['clube'] || $fora === $estado['clube']) continue;
            $p = futPlacar($porNome[$casa]['forca'], $porNome[$fora]['forca']);
            $resultados[] = ['casa' => $casa, 'fora' => $fora, 'gc' => $p['casa'], 'gf' => $p['fora']];
        }
    }
    mt_srand();

    return futClassificacao($nomes, $resultados);
}

/**
 * AS COMPETIÇÕES DO ANO, na ordem em que aparecem no calendário.
 *
 * Sai do calendário e não dos resultados: o técnico quer ver a tabela da
 * competição que vai jogar amanhã, e não só a das que já começaram.
 *
 * @return array [nome da competição => ['jogos'=>int,'jogados'=>int,'tabela'=>bool]]
 */
function futCarreiraCompeticoesDoAno(array $estado): array
{
    $out = [];
    foreach ($estado['calendario'] ?? [] as $j) {
        $c = (string)$j['comp'];
        if (!isset($out[$c])) $out[$c] = ['jogos' => 0, 'jogados' => 0, 'tabela' => false];
        $out[$c]['jogos']++;
    }
    foreach ($estado['resultados'] ?? [] as $r) {
        $c = (string)($r['comp'] ?? '');
        if (!isset($out[$c])) $out[$c] = ['jogos' => 0, 'jogados' => 0, 'tabela' => false];
        $out[$c]['jogados']++;
    }

    /* QUEM TEM TABELA é quem joga todo mundo contra todo mundo: o nacional, o
       estadual e a copa regional na fase de grupos. Mata-mata não tem
       classificação, e inventar uma seria mentir pro técnico. */
    $clubes = futClubesDoBrasil();
    $eu = $clubes[$estado['clube']] ?? [];
    $comTabela = [];
    $div = $eu['div'] ?? '';
    if ($div !== '') $comTabela[] = futCarreiraNomeDaDivisao($div);
    $uf = $eu['uf'] ?? '';
    if ($uf !== '' && isset(FUT_ESTADUAIS[$uf])) $comTabela[] = FUT_ESTADUAIS[$uf];
    $regiao = $eu['regiao'] ?? '';
    if ($regiao !== '' && isset(FUT_REGIONAIS[$regiao])) $comTabela[] = FUT_REGIONAIS[$regiao];

    foreach ($out as $c => $d) $out[$c]['tabela'] = in_array($c, $comTabela, true);
    return $out;
}

/**
 * A tabela de uma competição qualquer do ano, ou [] quando ela não tem tabela.
 */
function futCarreiraTabelaDaCompeticao(array $estado, string $comp): array
{
    $clubes = futClubesDoBrasil();
    $eu = $clubes[$estado['clube']] ?? [];

    $div = $eu['div'] ?? '';
    if ($div !== '' && $comp === futCarreiraNomeDaDivisao($div)) {
        return futCarreiraTabelaNacional($estado);
    }

    $uf = $eu['uf'] ?? '';
    if ($uf !== '' && isset(FUT_ESTADUAIS[$uf]) && $comp === FUT_ESTADUAIS[$uf]) {
        return futCarreiraTabelaDeGrupo($estado, futClubesDoEstado($uf), $comp);
    }

    $regiao = $eu['regiao'] ?? '';
    if ($regiao !== '' && isset(FUT_REGIONAIS[$regiao]) && $comp === FUT_REGIONAIS[$regiao]) {
        /* Na copa regional o clube joga seis adversários, não a região toda.
           A tabela é do grupo dele: ele e quem ele enfrentou. */
        $grupo = [$eu];
        $vistos = [$estado['clube'] => true];
        foreach ($estado['calendario'] ?? [] as $j) {
            if (($j['comp'] ?? '') !== $comp) continue;
            $n = (string)$j['adversario'];
            if (isset($vistos[$n]) || !isset($clubes[$n])) continue;
            $vistos[$n] = true;
            $grupo[] = $clubes[$n];
        }
        return futCarreiraTabelaDeGrupo($estado, $grupo, $comp);
    }

    return [];
}

/** Onde o técnico terminou no estadual. Null quando o clube não disputa um. */
function futCarreiraPosicaoNoEstadual(array $estado): ?int
{
    $tab = futCarreiraTabelaEstadual($estado);
    if (!$tab) return null;
    $pos = 0;
    foreach (array_keys($tab) as $nome) {
        $pos++;
        if ($nome === $estado['clube']) return $pos;
    }
    return null;
}

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

    /* ── A meta foi cumprida? ─────────────────────────────────────────
       A colocação que vale depende do TIPO da meta. Isso era ignorado: a conta
       olhava sempre a liga nacional, e clube sem divisão não tem uma — a
       comparação com null reprovava todo mundo, todo ano, e o técnico era
       demitido na segunda temporada jogasse como jogasse. São 42 dos 98
       clubes, vários deles entre os que o jogo oferece pra começar. */
    $meta = $estado['meta'] ?? futMetaDaTemporada($clube);
    $posicaoDaMeta = ($meta['tipo'] ?? '') === 'estadual'
        ? futCarreiraPosicaoNoEstadual($estado)
        : $posicao;
    $cumpriu = $posicaoDaMeta !== null && $posicaoDaMeta <= (int)($meta['alvo'] ?? 99);

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
        'aposentados' => [], 'novos' => [], 'evolucao' => [],
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

    /* A ESTATÍSTICA DO ANO VAI PRO HISTÓRICO E ZERA. Artilharia e cartão são
       da temporada, não da carreira: somar tudo pra sempre daria um artilheiro
       com 300 gols e nenhuma disputa ano a ano. O que sobrevive é o resumo. */
    $statsDoAno = $estado['stats'] ?? [];
    $art = futArtilharia($statsDoAno, 3);
    $estado['historico'][count($estado['historico']) - 1]['artilheiros'] = $art;
    $estado['stats'] = [];
    $estado['suspensos'] = [];

    /* OS EMPRESTADOS VOLTAM PRA CASA ANTES DE TUDO. Se ficassem, o ano
       seguinte os trataria como seus: eles envelheceriam no seu elenco e o
       clube dono nunca os veria de volta. */
    $dev = futCarreiraDevolverEmprestados($estado);
    $estado = $dev['estado'];
    $relatorio['devolvidos'] = $dev['devolvidos'];

    // ── O ELENCO ATRAVESSA O ANO: evolui, envelhece, aposenta, renova ──
    $forcaCat = (int)($clubes[$estado['clube']]['forca'] ?? 50);
    $ano = futPassarAnoNoElenco($estado['elenco'], $statsDoAno, $estado['clube'], $forcaCat, (int)$estado['ano']);
    $estado['elenco'] = futDescansoDeFimDeAno($ano['elenco']);
    $estado['escalacao'] = [];   // o elenco mudou; reescala na pré-temporada

    $relatorio['aposentados'] = $ano['aposentados'];
    $relatorio['novos'] = $ano['novos'];
    $relatorio['evolucao'] = $ano['evolucao'];

    // ── E O MUNDO ANDA JUNTO ───────────────────────────────────────────
    $noticias = [];
    foreach ($ano['aposentados'] as $a) {
        $noticias[] = sprintf('%s pendurou as chuteiras aos %d anos.', $a['nome'], (int)$a['idade']);
    }
    foreach ($ano['novos'] as $n) {
        $noticias[] = sprintf('%s, %d anos, subiu da base (%s, %d).',
            $n['nome'], (int)$n['idade'], $n['pos'], (int)$n['ovr']);
    }

    $mercadoIA = futTransferenciasDaIA($estado, $clubes);
    $estado = $mercadoIA['estado'];
    $noticias = array_merge($noticias, $mercadoIA['noticias']);

    $danca = futDancaDosTecnicos($estado, $clubes);
    $estado = $danca['estado'];
    $noticias = array_merge($noticias, $danca['noticias']);

    $estado['noticias'] = array_slice($noticias, 0, 40);

    /* AS PROPOSTAS SÓ APARECEM PRA QUEM NÃO FOI DEMITIDO. Quem levou o bilhete
       azul escolhe clube na tela de desempregado, que é outra lista e outra
       régua — receber convite do Palmeiras no mesmo dia em que foi demitido
       seria estranho. */
    $estado['propostas'] = $demitido ? [] : futPropostasDeEmprego($estado, $clubes);

    $estado['meta'] = futMetaDaTemporada($clube);

    return ['estado' => $estado, 'relatorio' => $relatorio];
}

/**
 * ACEITAR UMA PROPOSTA e mudar de clube.
 *
 * O técnico leva a reputação e o histórico; o elenco e o caixa são do clube
 * novo. É a subida na carreira: você chega num time melhor e recomeça a
 * cobrança de outro patamar, com uma meta mais dura.
 */
function futCarreiraTrocarDeClube(array $estado, string $novoClube): array
{
    $clubes = futClubesDoBrasil();
    $c = $clubes[$novoClube] ?? null;
    if (!$c) return ['ok' => false, 'motivo' => 'Clube desconhecido.', 'estado' => $estado];

    $elenco = futGarantirCondicao(futElencoNoJogo($estado, $novoClube, (int)$c['forca']));
    foreach ($elenco as &$j) if (!isset($j['potencial'])) $j['potencial'] = futPotencialDe($j);
    unset($j);

    $estado['clube'] = $novoClube;
    $estado['elenco'] = $elenco;
    $estado['caixa'] = futCaixaInicial((int)$c['forca'], $c['div']);
    $estado['escalacao'] = [];
    $estado['stats'] = [];
    $estado['suspensos'] = [];
    $estado['calendario'] = [];
    $estado['resultados'] = [];
    $estado['rodada'] = 0;
    $estado['falhas'] = 0;        // clube novo, conta zerada
    $estado['propostas'] = [];
    $estado['fase'] = 'mercado';
    $estado['meta'] = futMetaDaTemporada($c);
    $estado['mensagens'][] = 'Você assumiu o ' . $novoClube . '.';

    return ['ok' => true, 'motivo' => 'Você é o novo técnico do ' . $novoClube . '.', 'estado' => $estado];
}

/**
 * COMPRAR um jogador. Devolve o estado novo ou o motivo da recusa.
 *
 * @return array ['ok'=>bool, 'motivo'=>string, 'estado'=>array]
 */
/**
 * PEDE UM JOGADOR EMPRESTADO até o fim da temporada.
 *
 * É como clube pequeno monta elenco: não custa passe, só a folha — e devolve
 * no fim do ano. O que segura o abuso são três travas: o clube dono só
 * empresta quem não é titular (@see futPodeSerEmprestado), o seu elenco
 * comporta três emprestados ao mesmo tempo, e o mesmo jogador não pode ser
 * emprestado mais de três vezes na carreira.
 *
 * @return array ['ok'=>bool,'motivo'=>string,'estado'=>array]
 */
function futCarreiraPedirEmprestado(array $estado, string $clubeDono, string $jogador): array
{
    $falha = fn(string $m) => ['ok' => false, 'motivo' => $m, 'estado' => $estado];

    $clubes = futClubesDoBrasil();
    $dono = $clubes[$clubeDono] ?? null;
    if (!$dono) return $falha('Clube desconhecido.');
    if ($clubeDono === ($estado['clube'] ?? '')) return $falha('Esse jogador já é seu.');

    if (count($estado['elenco']) >= FUT_ELENCO_MAXIMO) {
        return $falha('Seu elenco está cheio (' . FUT_ELENCO_MAXIMO . ' jogadores).');
    }

    $jaEmprestados = 0;
    foreach ($estado['elenco'] as $j) if (!empty($j['emprestado_de'])) $jaEmprestados++;
    if ($jaEmprestados >= FUT_EMPRESTIMO_MAXIMO) {
        return $falha('Você já tem ' . FUT_EMPRESTIMO_MAXIMO . ' jogadores emprestados.');
    }

    $elencoDele = futElencoDoClube($clubeDono, (int)$dono['forca']);
    $foram = $estado['saidas'][$clubeDono] ?? [];
    $elencoDele = array_values(array_filter($elencoDele, fn($j) => !in_array($j['nome'], $foram, true)));

    $alvo = null;
    foreach ($elencoDele as $j) if ($j['nome'] === $jogador) { $alvo = $j; break; }
    if (!$alvo) return $falha('Esse jogador não está mais no clube.');

    $postos = futPostosDoElenco($elencoDele);
    $posto = $postos[$alvo['nome']] ?? 25;
    if (!futPodeSerEmprestado($alvo, $posto)) {
        return $falha('O ' . $clubeDono . ' não empresta ' . $alvo['nome'] . ' — ele é titular lá.');
    }

    /* QUANTAS VEZES ESSE JOGADOR JÁ RODOU. Fica gravado no histórico do save,
       e não no jogador, porque ele volta pro clube dono no fim do ano e o
       objeto some do seu elenco. */
    $vezes = (int)($estado['emprestimos'][$alvo['nome']] ?? 0);
    if ($vezes >= FUT_EMPRESTIMO_POR_JOGADOR) {
        return $falha($alvo['nome'] . ' já foi emprestado ' . $vezes . ' vezes — não pode de novo.');
    }

    /* O JOGADOR PRECISA QUERER — e o garoto quer muito mais.
       Empréstimo é pra jogar: quem tem vinte anos e não entra desce de
       divisão sem pensar duas vezes, porque minuto em campo vale mais que a
       camisa. O reserva de trinta e sete não desce, e é por isso que a
       margem dele é curta. Com uma margem só, o clube pequeno não conseguia
       ninguém e o empréstimo virava enfeite. */
    $meu = futCarreiraMeuClube($estado);
    $jovem = (int)$alvo['idade'] <= FUT_EMPRESTIMO_IDADE;
    $margem = $jovem ? 26 : 8;
    if ((int)$meu['forca'] + $margem < (int)$alvo['ovr']) {
        return $falha($alvo['nome'] . ($jovem
            ? ' quer jogar, mas não num clube tão abaixo do dele.'
            : ' não vê o seu clube como um passo à frente.'));
    }

    $estado['saidas'][$clubeDono][] = $alvo['nome'];
    $alvo['num'] = 0;
    $alvo['energia'] = 100;
    $alvo['moral'] = 85;          // chega animado: veio pra jogar
    $alvo['lesao'] = 0;
    $alvo['emprestado_de'] = $clubeDono;
    $estado['elenco'][] = $alvo;
    $estado['elenco'] = futRenumerar($estado['elenco']);
    $estado['emprestimos'][$alvo['nome']] = $vezes + 1;

    return ['ok' => true, 'estado' => $estado,
            'motivo' => $alvo['nome'] . ' chegou por empréstimo do ' . $clubeDono
                      . ', até o fim da temporada.'];
}

/**
 * DEVOLVE QUEM ESTAVA EMPRESTADO. Chamado na virada da temporada.
 *
 * @return array ['estado'=>array,'devolvidos'=>array]
 */
function futCarreiraDevolverEmprestados(array $estado): array
{
    $devolvidos = [];
    $fica = [];
    foreach ($estado['elenco'] ?? [] as $j) {
        if (empty($j['emprestado_de'])) { $fica[] = $j; continue; }
        $devolvidos[] = $j['nome'] . ' voltou para o ' . $j['emprestado_de'];

        /* O DONO O RECEBE DE VOLTA: tirar o nome de 'saidas' é o que faz ele
           reaparecer no elenco do clube dele. Sem isto, o jogador sumiria do
           jogo — saiu de lá e não está mais aqui. */
        $lista = $estado['saidas'][$j['emprestado_de']] ?? [];
        $estado['saidas'][$j['emprestado_de']] = array_values(
            array_filter($lista, fn($n) => $n !== $j['nome']));
    }
    $estado['elenco'] = futRenumerar($fica);
    return ['estado' => $estado, 'devolvidos' => $devolvidos];
}

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
    $posto = $postos[$alvo['nome']] ?? 25;
    $valor = futValorDeMercado((int)$alvo['ovr'], (int)$alvo['idade']);
    /* O MESMO PREÇO QUE A TELA MOSTROU. Se aqui a conta fosse outra, o
       jogador clicaria em comprar por 10 e levaria recusa por 24. */
    $pedido = futPrecoPedido($valor, $posto, futEstaAVenda($clubeVendedor, $alvo, $posto));

    $r = futAvaliarProposta($oferta, $pedido, count($elencoDele), $posto);
    if (!$r['aceita']) return ['ok' => false, 'motivo' => $r['motivo'], 'estado' => $estado];

    // O jogador também precisa querer vir.
    $meu = futCarreiraMeuClube($estado);
    $salario = futSalarioDe((int)$alvo['ovr'], (int)$alvo['idade']);
    $quer = futJogadorAceita((int)$alvo['ovr'], $salario, $salario, (int)$meu['forca']);
    if (!$quer['aceita']) return ['ok' => false, 'motivo' => $quer['motivo'], 'estado' => $estado];

    $estado['caixa'] = round($estado['caixa'] - $oferta, 2);
    $estado['saidas'][$clubeVendedor][] = $alvo['nome'];
    $alvo['num'] = 0;
    $alvo['energia'] = 100; $alvo['moral'] = 80; $alvo['lesao'] = 0;
    if (!isset($alvo['potencial'])) $alvo['potencial'] = futPotencialDe($alvo);
    $estado['elenco'][] = $alvo;
    $estado['escalacao'] = [];   // elenco mudou: reescala na próxima
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
        /* EMPRESTADO NÃO SE VENDE: o passe é de outro clube. A tela já
           esconde o botão, mas quem manda o POST na mão passaria por ela. */
        if (!empty($j['emprestado_de'])) {
            return ['ok' => false, 'estado' => $estado,
                    'motivo' => $jogador . ' está emprestado pelo ' . $j['emprestado_de'] . ' — o passe não é seu.'];
        }
        unset($estado['elenco'][$i]);
        $achou = true;
        break;
    }
    if (!$achou) return ['ok' => false, 'motivo' => 'Esse jogador não está no seu elenco.', 'estado' => $estado];

    $estado['elenco'] = array_values($estado['elenco']);
    $estado['escalacao'] = [];   // vendeu alguém: a escalação velha não vale mais
    $estado['caixa'] = round($estado['caixa'] + $oferta, 2);
    $estado['mensagens'][] = sprintf('%s foi vendido ao %s por %.2f mi.', $jogador, $comprador, $oferta);

    return ['ok' => true, 'motivo' => 'Venda fechada.', 'estado' => $estado];
}
