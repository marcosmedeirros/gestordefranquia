<?php
/**
 * SUPERCOMPUTADOR FBA — quem termina a edição em 1º, quem sobe e quem cai.
 *
 * ── A PERGUNTA É O EDIÇÃO, NÃO A TEMPORADA (19/10/2026) ───────────────
 *
 * A primeira versão deste arquivo respondia "chance de título", querendo
 * dizer o campeão dos playoffs da temporada em curso. Não é essa a corrida
 * que a FBA disputa: o que vale na liga é o RANKING GERAL da edição, somado
 * temporada a temporada, e é ele que decide quem é campeão, quem sobe e quem
 * cai. Decisão do dono da liga: "a ideia é que seja na liga geral".
 *
 * Então CAMPEÃO aqui é terminar a edição em 1º no ranking. Vale igual nas
 * quatro ligas — inclusive na ELITE, onde não há pra onde subir e o 1º lugar
 * é o troféu. A chance de levar os playoffs de uma temporada saiu de cena.
 *
 * ── O QUE ENTRA NA CONTA ──────────────────────────────────────────────
 *
 * A conta não é deste arquivo. Ela é do motor calibrado que o bot já usava
 * (backend/duvida_projecoes.php), medido contra a própria liga:
 *
 *   - FORÇA = OVR ponderado dos 8 melhores do elenco. Acerta a ordem final
 *     com correlação 0,67 — mais que a produção lançada e mais que a posição
 *     da temporada anterior.
 *   - IDADE, por jogador, pela curva tirada de 22 mil pares de temporadas
 *     seguidas do mesmo jogador na FBA, com freio perto do teto de OVR e o
 *     bônus de potencial até os 24. É daqui que sai o envelhecimento do
 *     elenco ao longo da edição — e é por isso que time velho e forte hoje
 *     aparece caindo lá na frente.
 *   - O ACASO do tamanho que a liga mostrou ter, mais um sorteio de mudança
 *     de elenco (troca, draft, leilão) que cresce a cada temporada à frente.
 *   - OS PONTOS JÁ CONQUISTADOS, que são o ponto de partida, e os PONTOS
 *     AINDA EM DISPUTA, que são as temporadas que faltam × a régua oficial.
 *     Edição acabando com alguém 40 pontos à frente é 100%, não "favorito".
 *
 * ── TUDO AQUI É DA EDIÇÃO ATUAL ───────────────────────────────────────
 *
 * Nenhum número deste arquivo olha pra edição passada. A temporada vem de
 * wcTemporadaAtiva, que só procura dentro da edição com status 'active'; o
 * tamanho vem de league_sprint_config.max_seasons, que é o tamanho DESTA
 * edição; as temporadas simuladas vão da de agora até a última dela; e os
 * times são os que estão na liga hoje.
 *
 * A base de pontos é teams.ranking_points, a mesma do /ranking, e ela é
 * zerada no reset de edição (o "Resetar sprint" do Admin, api/seasons.php). Conferido em produção em
 * 19/10/2026, somando team_season_points só das temporadas da edição ativa:
 * ELITE 752=752, NEXT 644=644, RISE 552=552 — bate exatamente, enquanto o
 * acumulado de todas as edições seria 3576, 2766 e 1261.
 *
 * A ROOKIE é a exceção que confirma a regra: lá teams.ranking_points (420)
 * é MENOR que a soma da edição (736), porque a liga zera os pontos quando o
 * GM sobe pra RISE e outro assume a cadeira (ver ranking_ciclos.php,
 * corte_gm). Está certo assim: a projeção parte do que o /ranking mostra,
 * que é o que a liga reconhece como pontuação de cada time hoje.
 *
 * A simulação vai até a ÚLTIMA temporada da edição, não até um horizonte
 * arbitrário: a pergunta é sobre o fim dele, e cortar antes responderia
 * outra coisa.
 *
 * ── O QUE ELE NÃO INVENTA ─────────────────────────────────────────────
 *
 * REBAIXAMENTO AUTOMÁTICO NÃO EXISTE NA FBA. Quem sobe e quem desce é chamada
 * de admin (api/admin.php, promoção por vaga aberta), e a única régua escrita
 * é a da ROOKIE: os 4 primeiros da geral sobem no fim da edição
 * (ranking_ciclos.php, sobe_geral). Por isso as duas zonas são apresentadas
 * como POSIÇÃO NO RANKING, nunca como decreto. Da ELITE não se sobe, então lá
 * o card de subida não existe.
 *
 * A JANELA DO TIME ("Em ascensão", "Reconstrução") é leitura da curva de
 * idade, e não probabilidade: é para onde a força do elenco de hoje anda até
 * o fim da edição, com os números do lado. Troca e draft não estão nela.
 */

require_once __DIR__ . '/duvida_projecoes.php';
require_once __DIR__ . '/pontuacao_ranking.php';
require_once __DIR__ . '/ranking_ciclos.php';

/**
 * Quantas vezes a edição inteiro é sorteado.
 *
 * Com 800 sorteios, uma chance perto de 20% sai com margem de ~1,4 ponto —
 * suficiente pra uma tela que arredonda em décimos, e é o que mantém o
 * cálculo da ELITE (edição de 25 temporadas) em segundos e não em dezenas
 * deles.
 */
const SC_SIMULACOES = 800;

/** Tamanho das zonas do ranking (a de cima e a de baixo), em times. */
const SC_ZONA = 4;

/**
 * Teto de temporadas simuladas à frente.
 *
 * Não é escolha de produto — a projeção vai até o fim da edição de propósito.
 * É guarda contra league_sprint_config com um número estranho, que faria a
 * página rodar até o timeout.
 */
const SC_TETO_TEMPORADAS = 25;

/** O máximo que uma temporada rende no ranking: 5 de posição + 10 de campeão. */
const SC_PTS_POR_TEMPORADA = 15;

/**
 * A versão do cálculo entra na chave do cache.
 *
 * Sem ela, mudar a conta não invalidava nada: a liga era a mesma, os elencos
 * os mesmos, e o servidor seguia entregando o número feito pela conta velha.
 * Toda mudança daqui mexe neste número.
 */
const SC_VERSAO = 4;

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
   OS DADOS DE CONTEXTO
   ───────────────────────────────────────────────────────────────────── */

/**
 * Quantas temporadas tem a edição da liga.
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

/**
 * Foto, GM, idade do elenco que forma a força e picks de 1ª rodada à frente.
 *
 * A idade é a dos 8 melhores por OVR, e não a do elenco inteiro, porque são
 * esses 8 que compõem a força — a média geral afunda com o 15º homem de 19
 * anos que não joga.
 *
 * As picks entram como CONTEXTO, não como parte da conta: elas não mudam a
 * força de hoje, e o que elas mudam — o time ser outro daqui a dois anos — o
 * motor já cobre no sorteio de mudança de elenco. Aparecem na tabela porque
 * ajudam a ler a janela: elenco velho sem pick nenhuma é outra história de
 * elenco velho com cinco.
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

/* ─────────────────────────────────────────────────────────────────────
   A JANELA DO TIME — a leitura da curva de idade
   ───────────────────────────────────────────────────────────────────── */

/**
 * A JANELA É CURTA DE PROPÓSITO: as próximas temporadas, não a edição todo.
 *
 * A projeção de pontos vai até o fim da edição porque é lá que o campeão é
 * decidido. A janela do elenco, não: medida em 15 temporadas ela diz que todo
 * time jovem tem o pico daqui a dez anos, o que é verdade pelo modelo e
 * inútil pra quem está decidindo uma troca agora.
 */
const SC_JANELA_ANOS = 5;

/**
 * PRA ONDE O ELENCO DE HOJE ANDA nas próximas temporadas.
 *
 * O motor já devolve a força padronizada do time em cada temporada à frente
 * (z[k]), que é o elenco de hoje envelhecido pela curva da FBA e medido
 * contra a liga daquela mesma temporada. A janela é só a leitura disso:
 * quanto o time ganha ou perde de posição relativa, e em que temporada ele
 * está no auge.
 *
 * A LEITURA É EM z, E NÃO EM OVR, de propósito. Em OVR absoluto o elenco de
 * todo mundo despenca ao longo da edição, porque no modelo ninguém contrata
 * ninguém — mostrar "88,6 hoje, 78,9 no fim" seria vender como previsão um
 * artefato de não ter draft na conta. Contra a liga, que envelhece junto, o
 * movimento que sobra é o real.
 *
 * NÃO É PROBABILIDADE, e o rótulo não promete nada: troca, draft e leilão
 * não estão aqui. É o que acontece se ninguém mexer em nada — que é
 * exatamente a pergunta de quem está decidindo se mexe.
 *
 * @return array ['rotulo','k_pico','z_hoje','z_fim','variacao']
 */
function scJanelaDoTime(array $z): array
{
    $z = array_values($z);
    $ate = min(count($z) - 1, SC_JANELA_ANOS);
    $hoje = (float)($z[0] ?? 0.0);
    $fim  = (float)($z[$ate] ?? $hoje);
    $kPico = 0;
    for ($k = 0; $k <= $ate; $k++) if ($z[$k] > $z[$kPico]) $kPico = $k;
    $variacao = $fim - $hoje;

    // Meio desvio é o tamanho que o motor dá a uma temporada inteira de
    // mudança de elenco: abaixo disso o movimento não se distingue de ruído.
    if      ($variacao >=  0.40) $rotulo = 'Em ascensão';
    elseif  ($variacao <= -0.40) $rotulo = $hoje >= 0.40 ? 'Janela fechando' : 'Em queda';
    elseif  ($hoje     >=  0.40) $rotulo = 'No pico';
    elseif  ($hoje     <= -0.40) $rotulo = 'Reconstrução';
    else                         $rotulo = 'Estável';

    return ['rotulo' => $rotulo, 'k_pico' => $kPico, 'z_hoje' => round($hoje, 2),
            'z_fim' => round($fim, 2), 'variacao' => round($variacao, 2)];
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
 * @return array ['fase','fixo','chave','inicio','fonte']
 */
function scFaseDaTemporada(PDO $pdo, string $liga, array $temp, array $ids): array
{
    $sid = (int)$temp['id'];
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

    // 1) Temporada já registrada por inteiro: ela está toda no ranking.
    if ($registrada) {
        return ['fase' => 'encerrada', 'fixo' => $fixo, 'chave' => null, 'inicio' => 1, 'fonte' => ''];
    }

    // 2) Regular lançada, playoffs em aberto: a regular ainda deve pontos ao
    //    ranking, e os playoffs saem da chave que está montada.
    if ($posAtual) {
        foreach ($posAtual as $id => $p) if (isset($fixo[$id])) $fixo[$id] += pontosPorPosicao($p);
        $chave = null; $fonte = '';
        $ch = projChaveAtual($pdo, $liga);
        if ($ch && (int)$ch['temporada']['id'] === $sid && !projRodarChave($ch['chave'], null)['campeao']) {
            $chave = $ch['chave'];
            $fonte = (string)$ch['fonte'];
        }
        return ['fase' => $chave ? 'playoffs' : 'entre', 'fixo' => $fixo, 'chave' => $chave,
                'inicio' => 1, 'fonte' => $fonte];
    }

    // 3) Regular rolando: a temporada inteira ainda vai ser jogada.
    return ['fase' => 'regular', 'fixo' => $fixo, 'chave' => null, 'inicio' => 0, 'fonte' => ''];
}

/**
 * O PACOTE DE UMA LIGA — é isto que a tela e o /opta desenham.
 *
 * Uma simulação só responde tudo, de propósito: campeão, subir e cair saem do
 * MESMO sorteio, então os números conversam entre si. Em contas separadas o
 * time podia aparecer com 40% de 1º lugar e 50% de ficar fora do top 4.
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

    $ids  = array_keys($ctx);
    $fase = scFaseDaTemporada($pdo, $liga, $temp, $ids);
    $n    = (int)$temp['season_number'];

    /* ATÉ O FIM DO EDIÇÃO. A última temporada dele, contada a partir da de
       agora (k = 0), é o que decide quem é campeão — cortar antes disso
       responderia outra pergunta. O teto é só guarda contra configuração
       estranha. */
    $inicio  = (int)$fase['inicio'];
    $ultimoK = $totalTemporadas > 0 ? max(0, $totalTemporadas - $n) : 4;
    $ultimoK = min($ultimoK, SC_TETO_TEMPORADAS);
    $restantes = max(0, $ultimoK - $inicio + 1);

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
    $cCampeao = $zero; $cTop3 = $zero; $cCima = $zero; $cBaixo = $zero; $somaPos = $zero;
    $totais = array_fill_keys(array_keys($times), []);

    for ($s = 0; $s < $N; $s++) {
        $pts = [];
        foreach ($base as $id => $b) $pts[$id] = $b + ($fase['fixo'][$id] ?? 0);

        // Os playoffs que já estão montados: série decidida fica como foi, em
        // aberto é sorteada pela força de hoje.
        if ($fase['chave']) {
            foreach (projRodarChave($fase['chave'], $serieAgora, $decididas)['etapa'] as $id => $e) {
                if (isset($pts[$id])) $pts[$id] += pontosDePlayoff($e);
            }
        }

        for ($k = $inicio; $k <= $ultimoK; $k++) {
            foreach (projPontosDaTemporada(projSortearTemporada($times, projForcasSorteadas($times, $k))) as $id => $p) {
                if (isset($pts[$id])) $pts[$id] += $p;
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
            if ($i === 1) $cCampeao[$id]++;
            if ($i <= 3) $cTop3[$id]++;
            if ($i <= $zonaCima) $cCima[$id]++;
            if ($i > $nTimes - $zonaBaixo) $cBaixo[$id]++;
        }
        foreach ($pts as $id => $p) $totais[$id][] = $p;
    }

    // Posição de hoje no ranking.
    $posHoje = [];
    $ordHoje = $base;
    arsort($ordHoje);
    $i = 0;
    foreach ($ordHoje as $id => $_) $posHoje[$id] = ++$i;

    $emDisputa = $restantes * SC_PTS_POR_TEMPORADA;

    $linhas = [];
    foreach ($times as $id => $t) {
        $xs = $totais[$id];
        sort($xs);
        $janela = scJanelaDoTime($t['z']);
        $linhas[] = [
            'id'        => $id,
            'nome'      => $t['nome'],
            'conf'      => $ctx[$id]['conf'] ?: ($t['conf'] !== 'Liga' ? $t['conf'] : ''),
            'foto'      => $ctx[$id]['foto'] ?? null,
            'gm'        => $ctx[$id]['gm'] ?? null,
            'sem_gm'    => (bool)($ctx[$id]['sem_gm'] ?? false),
            'forca'     => round($t['forca'][0], 1),
            'z'         => $janela['z_hoje'],
            'variacao'  => $janela['variacao'],
            'janela'    => $janela['rotulo'],
            'pico'      => $n + $janela['k_pico'],
            'idade'     => $ctx[$id]['idade'] ?? null,
            'elenco'    => $ctx[$id]['elenco'] ?? 0,
            'picks'     => $ctx[$id]['picks'] ?? 0,
            'pts_hoje'  => $base[$id],
            'pos_hoje'  => $posHoje[$id],
            'campeao'   => round($cCampeao[$id] / $N * 100, 1),
            'top3'      => round($cTop3[$id]    / $N * 100, 1),
            'cima'      => round($cCima[$id]    / $N * 100, 1),
            'baixo'     => round($cBaixo[$id]   / $N * 100, 1),
            'pts_fim'   => (int)round(array_sum($xs) / $N),
            'pts_lo'    => (int)round(projQuantil($xs, 0.10)),
            'pts_hi'    => (int)round(projQuantil($xs, 0.90)),
            'pos_media' => round($somaPos[$id] / $N, 1),
        ];
    }

    // Posição projetada = a ordem pela média de pontos, que é o que a tabela
    // mostra. A média de posição fica guardada como medida de dispersão.
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
        // existe — oferecer "chance de subir" ao campeão da ELITE seria
        // mostrar uma porta sem o outro lado.
        'tem_subida'  => $liga !== 'ELITE',
        'sobe_de_vdd' => (int)(cicloConfig($liga)['sobe_geral'] ?? 0),
        // Edição na última temporada e ela já registrada: não sobrou nada em
        // disputa, e a tabela vira o resultado em vez de projeção.
        'tem_disputa' => $restantes > 0 || !empty($fase['chave']),
        'temporada'   => [
            'numero'    => $n,
            // Qual edição, escrito: "T9/25" sozinho não diz de qual delas, e
            // a numeração das temporadas reinicia a cada edição nova.
            'edicao'    => (int)($temp['sprint_number'] ?? 0),
            'total'     => $totalTemporadas ?: null,
            'fim'       => $n + $ultimoK,
            'restantes' => $restantes,
            'em_disputa'=> $emDisputa,
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
 * As frases que resumem a liga.
 *
 * O "mais aberto" é o time com a maior faixa de pontos (10%–90%): não é o
 * melhor nem o pior, é aquele cuja edição ainda pode dar em qualquer coisa.
 */
function scDestaques(array $linhas): array
{
    if (!$linhas) return [];
    $porCampeao = $linhas; usort($porCampeao, fn($a, $b) => $b['campeao'] <=> $a['campeao']);
    $porSubida  = $linhas; usort($porSubida,  fn($a, $b) => $b['delta'] <=> $a['delta']);
    $porQueda   = $linhas; usort($porQueda,   fn($a, $b) => $a['delta'] <=> $b['delta']);
    $porFaixa   = $linhas; usort($porFaixa,   fn($a, $b) => ($b['pts_hi'] - $b['pts_lo']) <=> ($a['pts_hi'] - $a['pts_lo']));

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
    if ($fav = $primeiro($porCampeao, fn($l) => $l['campeao'] > 0)) {
        $d[] = ['rotulo' => 'Favorito da edição', 'time' => $fav['nome'],
                'valor' => scPct($fav['campeao']), 'cor' => 'ouro'];
    }
    // Azarão: a maior chance de 1º lugar entre os que hoje estão na metade de
    // baixo do ranking. Favorito que já lidera não é surpresa nenhuma.
    $metade = (int)ceil(count($linhas) / 2);
    if ($az = $primeiro($porCampeao, fn($l) => $l['pos_hoje'] > $metade && $l['campeao'] >= 1)) {
        $d[] = ['rotulo' => 'Azarão', 'time' => $az['nome'],
                'valor' => scPct($az['campeao']) . ' de 1º', 'cor' => 'roxo'];
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
        $out[] = ($i + 1) . '. ' . $l['nome'] . ' — ' . scPct((float)$l[$campo]);
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
