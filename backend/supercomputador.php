<?php
/**
 * SUPERCOMPUTADOR FBA — as chances da liga, calculadas de verdade.
 *
 * ── POR QUE ELE FOI REFEITO (19/10/2026) ──────────────────────────────
 *
 * A primeira versão vivia dentro do mundo-fba.php e tinha simulação própria:
 * um "score composto" de power ranking + pontos + picks, e uma projeção que
 * somava a cada temporada futura ajustes escritos na mão ("Contending -1,5",
 * "Rebuilding +1,8", "picks × 0,3", crescimento de OVR 3,5/2,5/1,5 por faixa
 * de idade × 1,8). Nenhum desses números saiu de medição nenhuma, e o
 * resultado era uma porcentagem com cara de ciência em cima de chute.
 *
 * Enquanto isso o bot já tinha, em backend/duvida_projecoes.php, um motor
 * CALIBRADO contra a própria liga: a força do elenco (OVR ponderado dos 8
 * melhores) acerta a ordem final com correlação 0,67; a curva de idade saiu
 * de 22 mil pares de temporadas seguidas do mesmo jogador; o tamanho do acaso
 * foi escolhido pra simulação reproduzir a mesma correlação que a FBA teve de
 * verdade. Duas contas diferentes pra mesma pergunta — uma no site, outra no
 * WhatsApp — eram piores que nenhuma.
 *
 * Então o SuperComputador não tem mais conta própria. Ele chama o motor do
 * bot e organiza a resposta pra tela.
 *
 * ── O QUE ELE RESPONDE ────────────────────────────────────────────────
 *
 *   1. CHANCE DE TÍTULO da temporada em disputa. Com a chave dos playoffs já
 *      montada, respeita as séries decididas e simula só o que falta; com a
 *      temporada ainda na regular, sorteia regular e playoffs inteiros.
 *   2. O RANKING DO SPRINT à frente: pontos projetados com faixa, chance de
 *      terminar em 1º, de terminar na zona de cima e na zona de baixo.
 *   3. A TABELA DE DADOS por trás disso — força, idade, pontos de hoje — pra
 *      ninguém ter que acreditar na porcentagem no escuro.
 *
 * ── O QUE ELE NÃO INVENTA ─────────────────────────────────────────────
 *
 * REBAIXAMENTO AUTOMÁTICO NÃO EXISTE NA FBA. Quem sobe e quem desce é chamada
 * de admin (api/admin.php, promoção por vaga aberta), e a única régua escrita
 * é a da ROOKIE: os 4 primeiros da classificação geral sobem no fim da edição
 * (ranking_ciclos.php, sobe_geral). Por isso as duas zonas são apresentadas
 * como ZONA DO RANKING — "terminar entre os primeiros" e "entre os últimos" —
 * e nunca como decreto. Da ELITE não se sobe pra lugar nenhum, então lá a
 * zona de cima nem aparece: o card de subida some e sobram título e queda.
 *
 * O HORIZONTE É CURTO DE PROPÓSITO. A ELITE tem sprint de 25 temporadas e
 * projetar até a 25ª é adivinhação: o próprio motor assume que o elenco foge
 * do previsto meio desvio por temporada, o que daqui a 20 temporadas embaralha
 * tudo. A projeção vai até SC_HORIZONTE temporadas à frente, ou até o fim do
 * sprint quando ele estiver mais perto que isso — e a tela diz qual dos dois.
 */

require_once __DIR__ . '/duvida_projecoes.php';
require_once __DIR__ . '/pontuacao_ranking.php';
require_once __DIR__ . '/ranking_ciclos.php';

/** Quantas vezes o sprint inteiro é sorteado. */
const SC_SIMULACOES = 1200;

/** Até quantas temporadas à frente a projeção do ranking vai. */
const SC_HORIZONTE = 5;

/** Tamanho das zonas do ranking (a de cima e a de baixo), em times. */
const SC_ZONA = 4;

/**
 * A versão do cálculo entra na chave do cache.
 *
 * Sem ela, mudar a conta não invalidava nada: a liga era a mesma, os elencos
 * os mesmos, e o servidor seguia entregando o número feito pela conta velha.
 * Toda mudança daqui mexe neste número.
 */
const SC_VERSAO = 1;

/* ─────────────────────────────────────────────────────────────────────
   O CACHE
   ───────────────────────────────────────────────────────────────────── */

/**
 * Lê (ou grava) o pacote já calculado de uma liga.
 *
 * Em arquivo de temp, como a matriz da loteria: a conta é cara demais pra
 * refazer a cada F5 e barata demais pra merecer tabela. Falha de escrita é
 * ignorada — sem cache a página apenas recalcula.
 */
function scCache(string $chave, ?array $gravar = null): ?array
{
    $arquivo = sys_get_temp_dir() . '/fba_sc_' . $chave . '.json';
    if ($gravar === null) {
        if (!is_readable($arquivo)) return null;
        $c = json_decode((string)file_get_contents($arquivo), true);
        return is_array($c) ? $c : null;
    }
    @file_put_contents($arquivo, json_encode($gravar));
    return null;
}

/**
 * A ASSINATURA DA LIGA: muda sempre que muda alguma coisa que entra na conta.
 *
 * Elenco (quantos, soma de OVR e de idade por time), pontos de ranking e a
 * temporada em curso. Uma troca aceita, um draft, um ponto lançado — qualquer
 * um deles muda a assinatura e o cache cai sozinho. Sem isso o GM faria a
 * troca da vida dele e o SuperComputador seguiria mostrando o time de ontem.
 */
function scAssinatura(PDO $pdo, string $liga, ?array $temp): string
{
    $partes = [SC_VERSAO, $liga, (int)($temp['id'] ?? 0), (string)($temp['status'] ?? '')];
    try {
        $st = $pdo->prepare("SELECT t.id, COALESCE(t.ranking_points,0) pts, COUNT(p.id) n,
                                    COALESCE(SUM(p.ovr),0) so, COALESCE(SUM(p.age),0) sa
                               FROM teams t LEFT JOIN players p ON p.team_id = t.id
                              WHERE t.league = ? GROUP BY t.id ORDER BY t.id");
        $st->execute([$liga]);
        foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $partes[] = implode(',', $r);
    } catch (Throwable $e) { $partes[] = 'sem-assinatura-' . date('YmdH'); }
    // A classificação e a chave em andamento também mudam a resposta.
    if (!empty($temp['id'])) {
        try {
            $st = $pdo->prepare('SELECT team_id, position FROM season_standings WHERE season_id = ? ORDER BY team_id');
            $st->execute([(int)$temp['id']]);
            foreach ($st->fetchAll(PDO::FETCH_NUM) as $r) $partes[] = implode(':', $r);
        } catch (Throwable $e) {}
        try {
            $st = $pdo->prepare('SELECT COUNT(*), COALESCE(MAX(id),0) FROM playoff_series WHERE season_id = ?');
            $st->execute([(int)$temp['id']]);
            $partes[] = implode('/', (array)$st->fetch(PDO::FETCH_NUM));
        } catch (Throwable $e) {}
    }
    return md5(implode('|', $partes));
}

/* ─────────────────────────────────────────────────────────────────────
   OS DADOS DE CONTEXTO (o que a tabela mostra ao lado da porcentagem)
   ───────────────────────────────────────────────────────────────────── */

/**
 * Foto, GM, idade do elenco que forma a força e picks de 1ª rodada à frente.
 *
 * A idade é a dos 8 melhores por OVR, e não a do elenco inteiro, porque são
 * esses 8 que compõem a força — a média geral afunda com o 15º homem de 19
 * anos que não joga.
 *
 * As picks entram como CONTEXTO, não como parte da conta: elas não mudam a
 * força de hoje, mudam a chance de o time ser outro daqui a dois anos, e isso
 * o motor já cobre no sorteio de mudança de elenco.
 */
function scContextoDosTimes(PDO $pdo, string $liga): array
{
    $ctx = [];
    $st = $pdo->prepare("SELECT t.id, t.photo_url, t.user_id, COALESCE(t.conference,'') conf,
                                COALESCE(t.ranking_points,0) pts, COALESCE(t.ranking_titles,0) titulos,
                                u.name gm
                           FROM teams t LEFT JOIN users u ON u.id = t.user_id
                          WHERE t.league = ?");
    $st->execute([$liga]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $ctx[(int)$r['id']] = [
            'foto'    => $r['photo_url'],
            'gm'      => $r['gm'],
            'sem_gm'  => empty($r['user_id']),
            'conf'    => (string)$r['conf'],
            'pts'     => (int)$r['pts'],
            'titulos' => (int)$r['titulos'],
            'idade'   => null,
            'elenco'  => 0,
            'picks'   => 0,
        ];
    }
    if (!$ctx) return [];

    $st = $pdo->prepare('SELECT team_id, ovr, age FROM players WHERE team_id IN
                         (SELECT id FROM teams WHERE league = ?) ORDER BY team_id, ovr DESC');
    $st->execute([$liga]);
    $porTime = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $porTime[(int)$r['team_id']][] = $r;
    foreach ($porTime as $id => $js) {
        if (!isset($ctx[$id])) continue;
        $ctx[$id]['elenco'] = count($js);
        $oito = array_slice($js, 0, 8);
        $idades = array_values(array_filter(array_map(fn($j) => (int)$j['age'], $oito), fn($a) => $a > 0));
        $ctx[$id]['idade'] = $idades ? round(array_sum($idades) / count($idades), 1) : null;
    }

    // Picks de 1ª rodada que ainda vão acontecer — a mesma régua do bot.
    try {
        $corte = function_exists('anoDeCorteDasPicks') ? anoDeCorteDasPicks($pdo, $liga) : (int)date('Y');
        $st = $pdo->prepare("SELECT p.team_id, COUNT(*) n FROM picks p JOIN teams t ON t.id = p.team_id
                              WHERE t.league = ? AND p.round = '1' AND CAST(p.season_year AS UNSIGNED) >= ?
                              GROUP BY p.team_id");
        $st->execute([$liga, $corte]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($ctx[(int)$r['team_id']])) $ctx[(int)$r['team_id']]['picks'] = (int)$r['n'];
        }
    } catch (Throwable $e) { /* sem picks cadastradas a coluna fica em branco */ }

    return $ctx;
}

/**
 * Quantas temporadas tem o sprint da liga.
 *
 * A fonte é league_sprint_config, a mesma tabela que o Admin edita. O mapa
 * abaixo é só a reserva pra base que ainda não tem a tabela — foi uma cópia
 * fixa no código por muito tempo e só por sorte não divergiu.
 */
function scTotalDeTemporadas(PDO $pdo, string $liga): int
{
    static $mapa = null;
    if ($mapa === null) {
        $mapa = ['ELITE' => 25, 'NEXT' => 20, 'RISE' => 15, 'ROOKIE' => 15];
        try {
            foreach ($pdo->query('SELECT league, max_seasons FROM league_sprint_config') as $r) {
                if ((int)$r['max_seasons'] > 0) $mapa[strtoupper((string)$r['league'])] = (int)$r['max_seasons'];
            }
        } catch (Throwable $e) {}
    }
    return $mapa[strtoupper(trim($liga))] ?? 0;
}

/* ─────────────────────────────────────────────────────────────────────
   A SIMULAÇÃO
   ───────────────────────────────────────────────────────────────────── */

/**
 * ONDE A TEMPORADA EM CURSO ESTÁ, e o que dela ainda falta somar no ranking.
 *
 * O /ranking (teams.ranking_points) só recebe a temporada no REGISTRO FINAL —
 * regular, playoffs e prêmios de uma vez. Salvar a classificação no card grava
 * a regular em team_ranking_points mas NÃO no ranking. Tomar essa linha por
 * "já somada" deixaria a regular em andamento de fora da projeção; foi o erro
 * que a auditoria de 17/09 pegou na ELITE T4, e é por isso que a conta de
 * "quanto a T atual ainda deve" mora aqui e não é adivinhada.
 *
 * @return array ['fase','fixo','chave','inicio','titulo_de','fonte']
 */
function scFaseDaTemporada(PDO $pdo, string $liga, array $temp, array $ids): array
{
    $sid = (int)$temp['id'];
    $n   = (int)$temp['season_number'];
    $fixo = array_fill_keys($ids, 0);

    $registrada = false;
    try {
        $st = $pdo->prepare('SELECT COUNT(*) FROM playoff_results WHERE season_id = ?');
        $st->execute([$sid]);
        $registrada = (int)$st->fetchColumn() > 0;
    } catch (Throwable $e) {}

    $posAtual = [];
    try {
        $st = $pdo->prepare('SELECT team_id, position FROM season_standings WHERE season_id = ? AND COALESCE(position,0) > 0');
        $st->execute([$sid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $posAtual[(int)$r['team_id']] = (int)$r['position'];
    } catch (Throwable $e) {}

    // 1) Temporada já registrada por inteiro: ela está toda no ranking e o
    //    título em disputa é o da próxima.
    if ($registrada) {
        return ['fase' => 'encerrada', 'fixo' => $fixo, 'chave' => null,
                'inicio' => 1, 'titulo_de' => $n + 1, 'fonte' => ''];
    }

    // 2) Regular lançada, playoffs em aberto: a regular ainda deve pontos ao
    //    ranking, e o título sai da chave que está montada.
    if ($posAtual) {
        foreach ($posAtual as $id => $p) if (isset($fixo[$id])) $fixo[$id] += pontosPorPosicao($p);
        $chave = null; $fonte = '';
        $ch = projChaveAtual($pdo, $liga);
        if ($ch && (int)$ch['temporada']['id'] === $sid && !projRodarChave($ch['chave'], null)['campeao']) {
            $chave = $ch['chave'];
            $fonte = (string)$ch['fonte'];
        }
        return ['fase' => $chave ? 'playoffs' : 'entre', 'fixo' => $fixo, 'chave' => $chave,
                'inicio' => 1, 'titulo_de' => $chave ? $n : $n + 1, 'fonte' => $fonte];
    }

    // 3) Regular rolando: a temporada inteira ainda vai ser jogada.
    return ['fase' => 'regular', 'fixo' => $fixo, 'chave' => null,
            'inicio' => 0, 'titulo_de' => $n, 'fonte' => ''];
}

/**
 * O PACOTE DE UMA LIGA — é isto que a tela desenha.
 *
 * Uma simulação só responde tudo, de propósito: título, playoff e ranking
 * saem do MESMO sorteio, então os números conversam entre si. Em duas contas
 * separadas o time podia aparecer com 40% de título e 30% de playoff.
 */
function scPacoteDaLiga(PDO $pdo, string $liga, ?int $totalTemporadas = null): array
{
    $liga = strtoupper(trim($liga));
    $totalTemporadas = $totalTemporadas ?? scTotalDeTemporadas($pdo, $liga);
    $vazio = ['tem_dados' => false, 'liga' => $liga];

    projComandosDoBot();
    $temp = wcTemporadaAtiva($pdo, $liga);
    if (!$temp) return $vazio;

    $assinatura = scAssinatura($pdo, $liga, $temp);
    if ($pronto = scCache($assinatura)) return $pronto;

    $ctx = scContextoDosTimes($pdo, $liga);
    if (count($ctx) < 4) return $vazio;

    $ids = array_keys($ctx);
    $fase = scFaseDaTemporada($pdo, $liga, $temp, $ids);

    $n = (int)$temp['season_number'];
    // A última temporada do sprint, contada a partir da de agora (k = 0).
    $fimDoSprint = $totalTemporadas > 0 ? max(0, $totalTemporadas - $n) : SC_HORIZONTE;
    $inicio = (int)$fase['inicio'];
    $ultimoK = min($fimDoSprint, $inicio + SC_HORIZONTE - 1);
    $ateOFim = $ultimoK >= $fimDoSprint;

    $times = projLigaNoFuturo($pdo, $liga, max($ultimoK, 0));
    if (count($times) < 4) return $vazio;

    $base = [];
    foreach ($times as $id => $_) $base[$id] = $ctx[$id]['pts'] ?? 0;
    $nTimes = count($times);
    $zonaCima  = min(SC_ZONA, (int)floor($nTimes / 3));
    $zonaBaixo = min(SC_ZONA, (int)floor($nTimes / 3));

    // Semente fixa por assinatura: o mesmo estado da liga dá sempre o mesmo
    // número. Sem isso dois GMs abrindo a página lado a lado veriam chances
    // diferentes e nenhum dos dois confiaria na tela.
    mt_srand(crc32($assinatura));

    $z0 = [];
    foreach ($times as $id => $t) $z0[$id] = $t['z'][0];
    $cacheChance = [];
    $serieAgora = function (int $a, int $b) use (&$cacheChance, $z0): int {
        $p = $cacheChance["{$a}-{$b}"] ??= projChanceSerie($z0[$a] ?? 0.0, $z0[$b] ?? 0.0);
        return (mt_rand() / mt_getrandmax()) < $p ? $a : $b;
    };
    $decididas = $fase['chave'] ? projSeriesDecididas($fase['chave']) : [];

    $N = SC_SIMULACOES;
    $zero = array_fill_keys(array_keys($times), 0);
    $cTitulo = $zero; $cFinal = $zero; $cPlayoff = $zero; $cPrimeiro = $zero;
    $cCima = $zero; $cBaixo = $zero; $somaPos = $zero; $somaTitulos = $zero;
    $totais = array_fill_keys(array_keys($times), []);

    for ($s = 0; $s < $N; $s++) {
        $pts = [];
        foreach ($base as $id => $b) $pts[$id] = $b + ($fase['fixo'][$id] ?? 0);

        // A temporada em disputa: ou os playoffs que já estão montados, ou ela
        // inteira sorteada (regular + playoffs).
        if ($fase['chave']) {
            $r = projRodarChave($fase['chave'], $serieAgora, $decididas);
            foreach ($r['etapa'] as $id => $e) {
                if (!isset($pts[$id])) continue;
                $pts[$id] += pontosDePlayoff($e);
                $cPlayoff[$id]++;
                if ($e === 'champion' || $e === 'runner_up') $cFinal[$id]++;
                if ($e === 'champion') { $cTitulo[$id]++; $somaTitulos[$id]++; }
            }
        }

        for ($k = $inicio; $k <= $ultimoK; $k++) {
            $temporada = projSortearTemporada($times, projForcasSorteadas($times, $k));
            foreach (projPontosDaTemporada($temporada) as $id => $p) {
                if (isset($pts[$id])) $pts[$id] += $p;
            }
            foreach ($temporada as $id => $t) {
                if (!isset($pts[$id])) continue;
                if ($t['etapa'] === 'champion') $somaTitulos[$id]++;
                if ($k !== $inicio || $fase['chave']) continue;
                // A primeira temporada sorteada é a que está em disputa.
                if ($t['pos'] <= PROJ_VAGAS_PLAYOFF) $cPlayoff[$id]++;
                if ($t['etapa'] === 'champion' || $t['etapa'] === 'runner_up') $cFinal[$id]++;
                if ($t['etapa'] === 'champion') $cTitulo[$id]++;
            }
        }

        // Empate de pontos se resolve no sorteio: ninguém ganha posição pela
        // ordem do banco.
        $ordem = [];
        foreach ($pts as $id => $p) $ordem[$id] = $p + mt_rand() / mt_getrandmax() * 0.5;
        arsort($ordem);
        $i = 0;
        foreach ($ordem as $id => $_) {
            $i++;
            $somaPos[$id] += $i;
            if ($i === 1) $cPrimeiro[$id]++;
            if ($i <= $zonaCima) $cCima[$id]++;
            if ($i > $nTimes - $zonaBaixo) $cBaixo[$id]++;
        }
        foreach ($pts as $id => $p) $totais[$id][] = $p;
    }

    // Posição de hoje no ranking (a mesma régua da tabela: pontos, títulos).
    $hoje = $base;
    $posHoje = [];
    $ordHoje = $hoje;
    arsort($ordHoje);
    $i = 0;
    foreach ($ordHoje as $id => $_) $posHoje[$id] = ++$i;

    $linhas = [];
    foreach ($times as $id => $t) {
        $xs = $totais[$id];
        sort($xs);
        $linhas[] = [
            'id'       => $id,
            'nome'     => $t['nome'],
            'conf'     => $ctx[$id]['conf'] ?: ($t['conf'] !== 'Liga' ? $t['conf'] : ''),
            'foto'     => $ctx[$id]['foto'] ?? null,
            'gm'       => $ctx[$id]['gm'] ?? null,
            'sem_gm'   => (bool)($ctx[$id]['sem_gm'] ?? false),
            'forca'    => round($t['forca'][0], 1),
            'z'        => round($t['z'][0], 2),
            'idade'    => $ctx[$id]['idade'] ?? null,
            'elenco'   => $ctx[$id]['elenco'] ?? 0,
            'picks'    => $ctx[$id]['picks'] ?? 0,
            'pts_hoje' => $hoje[$id],
            'pos_hoje' => $posHoje[$id],
            'titulo'   => round($cTitulo[$id]   / $N * 100, 1),
            'final'    => round($cFinal[$id]    / $N * 100, 1),
            'playoff'  => round($cPlayoff[$id]  / $N * 100, 1),
            'primeiro' => round($cPrimeiro[$id] / $N * 100, 1),
            'cima'     => round($cCima[$id]     / $N * 100, 1),
            'baixo'    => round($cBaixo[$id]    / $N * 100, 1),
            'titulos'  => round($somaTitulos[$id] / $N, 2),
            'pts_fim'  => (int)round(array_sum($xs) / $N),
            'pts_lo'   => (int)round(projQuantil($xs, 0.10)),
            'pts_hi'   => (int)round(projQuantil($xs, 0.90)),
            'pos_fim'  => round($somaPos[$id] / $N, 1),
        ];
    }

    // Posição projetada = a ordem pela média de pontos, que é o que a tabela
    // de fato vai mostrar. A média de posição fica como medida de dispersão.
    usort($linhas, fn($a, $b) => $b['pts_fim'] <=> $a['pts_fim']);
    foreach ($linhas as $i => &$l) {
        $l['pos_proj'] = $i + 1;
        $l['delta']    = $l['pos_hoje'] - $l['pos_proj'];
    }
    unset($l);

    $pacote = [
        'tem_dados'   => true,
        'liga'        => $liga,
        'n_sim'       => $N,
        'n_times'     => $nTimes,
        'zona_cima'   => $zonaCima,
        'zona_baixo'  => $zonaBaixo,
        // Da ELITE não se sobe: é a liga de cima. Lá o card de subida nem
        // existe — mostrar "chance de subir" pro campeão da ELITE seria
        // oferecer uma porta que não tem do outro lado.
        'tem_subida'  => $liga !== 'ELITE',
        // Sprint na última temporada, com ela já registrada: não sobrou nada
        // pra simular. A tela mostra a tabela e cala as porcentagens em vez
        // de encher a página de 0%.
        'tem_titulo'  => array_sum(array_column($linhas, 'titulo')) > 0,
        'tem_futuro'  => $ultimoK >= $inicio || !empty($fase['chave']),
        'sobe_de_vdd' => (int)(cicloConfig($liga)['sobe_geral'] ?? 0),
        'temporada'   => [
            'numero'    => $n,
            'total'     => $totalTemporadas ?: null,
            'alvo'      => $n + $ultimoK,
            'titulo_de' => (int)$fase['titulo_de'],
            'ate_o_fim' => $ateOFim,
            'fase'      => $fase['fase'],
            'fonte'     => $fase['fonte'],
        ],
        'times'       => $linhas,
        'destaques'   => scDestaques($linhas),
        'gerado_em'   => time(),
    ];
    scCache($assinatura, $pacote);
    return $pacote;
}

/**
 * As quatro frases que resumem a liga.
 *
 * O "mais aberto" é o time com a maior faixa de pontos (10%–90%): não é o
 * melhor nem o pior, é aquele cuja temporada ainda pode dar em qualquer coisa.
 */
function scDestaques(array $linhas): array
{
    if (!$linhas) return [];
    $porTitulo = $linhas; usort($porTitulo, fn($a, $b) => $b['titulo'] <=> $a['titulo']);
    $porSubida = $linhas; usort($porSubida, fn($a, $b) => $b['delta'] <=> $a['delta']);
    $porQueda  = $linhas; usort($porQueda,  fn($a, $b) => $a['delta'] <=> $b['delta']);
    $porFaixa  = $linhas; usort($porFaixa,  fn($a, $b) => ($b['pts_hi'] - $b['pts_lo']) <=> ($a['pts_hi'] - $a['pts_lo']));

    /* UM TIME NÃO OCUPA DUAS CADEIRAS. Sem isso o mesmo nome aparecia como
       favorito, azarão e quem mais sobe ao mesmo tempo — cinco chips pra
       dizer uma coisa só. Quem já foi citado sai da disputa do chip seguinte. */
    $usados = [];
    $primeiro = function (array $lista, callable $serve) use (&$usados): ?array {
        foreach ($lista as $l) {
            if (isset($usados[$l['id']]) || !$serve($l)) continue;
            $usados[$l['id']] = true;
            return $l;
        }
        return null;
    };

    $d = [];
    if ($fav = $primeiro($porTitulo, fn($l) => $l['titulo'] > 0)) {
        $d[] = ['rotulo' => 'Favorito ao título', 'time' => $fav['nome'],
                'valor' => scPct($fav['titulo']), 'cor' => 'ouro'];
    }
    // Azarão: o de maior chance de título entre os que hoje estão na metade
    // de baixo do ranking. Favorito que já é 1º não é surpresa nenhuma.
    $metade = (int)ceil(count($linhas) / 2);
    if ($az = $primeiro($porTitulo, fn($l) => $l['pos_hoje'] > $metade && $l['titulo'] >= 1)) {
        $d[] = ['rotulo' => 'Azarão', 'time' => $az['nome'],
                'valor' => scPct($az['titulo']) . ' de título', 'cor' => 'roxo'];
    }
    if ($sb = $primeiro($porSubida, fn($l) => $l['delta'] > 0)) {
        $d[] = ['rotulo' => 'Quem mais sobe', 'time' => $sb['nome'],
                'valor' => '+' . $sb['delta'] . ' no ranking', 'cor' => 'verde'];
    }
    if ($cd = $primeiro($porQueda, fn($l) => $l['delta'] < 0)) {
        $d[] = ['rotulo' => 'Quem mais cai', 'time' => $cd['nome'],
                'valor' => $cd['delta'] . ' no ranking', 'cor' => 'vermelho'];
    }
    if ($im = $primeiro($porFaixa, fn($l) => $l['pts_hi'] > $l['pts_lo'])) {
        $d[] = ['rotulo' => 'Mais imprevisível', 'time' => $im['nome'],
                'valor' => $im['pts_lo'] . '–' . $im['pts_hi'] . ' pts', 'cor' => 'azul'];
    }
    return $d;
}

/* ─────────────────────────────────────────────────────────────────────
   O TEXTO PRO WHATSAPP
   ───────────────────────────────────────────────────────────────────── */

/** Uma lista pronta pra colar no grupo. */
function scTextoDaLista(string $emoji, string $titulo, array $linhas, string $campo, string $rotuloLiga, int $quantos = 10): string
{
    $validas = array_values(array_filter($linhas, fn($l) => (float)$l[$campo] > 0));
    if (!$validas) return '';
    $out = [$emoji . ' ' . mb_strtoupper($titulo) . ' — ' . $rotuloLiga];
    foreach (array_slice($validas, 0, $quantos) as $i => $l) {
        $out[] = ($i + 1) . '. ' . $l['nome'] . ' — ' . $l[$campo] . '%';
    }
    return implode("\n", $out);
}

/** O número com cara de porcentagem de aposta: <1% e >99% em vez de 0 e 100. */
function scPct(float $v): string
{
    if ($v > 0 && $v < 1) return '<1%';
    if ($v > 99 && $v < 100) return '>99%';
    return rtrim(rtrim(number_format($v, 1, ',', '.'), '0'), ',') . '%';
}
