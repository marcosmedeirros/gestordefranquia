<?php
/**
 * A BATERIA DE TESTES DO JOGO DE CARREIRA.
 *
 * Roda pela linha de comando:
 *
 *     php games/core/fut_testes.php
 *
 * Não toca no banco e não abre tela: é tudo motor. A ideia é poder mexer na
 * calibragem — preço, cartão, evolução — e saber em dez segundos se alguma
 * coisa saiu do lugar, em vez de descobrir jogando três temporadas.
 *
 * Cada teste diz o que esperava e o que veio. Os limites são FAIXAS, não
 * valores exatos: o jogo é sorteado, e um teste que exige o número exato
 * quebra sozinho na primeira rodada.
 */

require_once __DIR__ . '/fut_carreira.php';

$falhas = 0;
$total = 0;

function ok(string $titulo, bool $passou, string $detalhe = ''): void
{
    global $falhas, $total;
    $total++;
    if (!$passou) $falhas++;
    printf("%s %-52s %s\n", $passou ? '  ok  ' : ' FALHA', $titulo, $detalhe);
}

function entre(float $v, float $min, float $max): bool { return $v >= $min && $v <= $max; }

function secao(string $t): void { echo "\n── $t " . str_repeat('─', max(0, 56 - mb_strlen($t))) . "\n"; }

// ═════════════════════════════════════════════════════════════════════
secao('Catálogo e elencos');

$br = futClubesDoBrasil();
ok('98 clubes brasileiros', count($br) === 98, count($br) . ' clubes');
ok('Série A com 20', count(futClubesDaDivisao('BR1')) === 20);

/* ── A SÉRIE B TEM 21 E A C TEM 15, E ISSO NÃO É BUG ──────────────────
   O jogo alinhou a Série A à temporada de 2026: três desceram e três
   subiram, mas o Remo veio da C — então a B ganhou três e perdeu dois.
   Fechar em vinte exigiria dizer qual clube da B caiu, e essa lista não
   existe em fonte nenhuma aberta.

   O que o teste cobra, então, é o que o jogador sente: NINGUÉM PODE JOGAR
   MAIS RODADAS QUE O VIZINHO. Com número ímpar entra o "bye", e é
   justamente aí que uma tabela injusta passaria despercebida. */
foreach (['BR1', 'BR2', 'BR3', 'BR4'] as $div) {
    $porClube = [];
    foreach (futCarreiraCalendarioDaLiga($div) as $rodada) {
        foreach ($rodada as [$casa, $fora]) {
            $porClube[$casa] = ($porClube[$casa] ?? 0) + 1;
            $porClube[$fora] = ($porClube[$fora] ?? 0) + 1;
        }
    }
    ok("todo clube da $div joga o mesmo tanto",
       $porClube && min($porClube) === max($porClube),
       count($porClube) . ' clubes, ' . ($porClube ? min($porClube) . '-' . max($porClube) : '?') . ' jogos');
}

$nomes = array_keys($br);
ok('nenhum clube repetido', count($nomes) === count(array_unique($nomes)));

$semUf = array_filter($br, fn($c) => $c['uf'] === '');
ok('todo clube tem estado', count($semUf) === 0, count($semUf) . ' sem UF');

$a = futElencoDoClube('Palmeiras', 92);
$b = futElencoDoClube('Palmeiras', 92);
ok('o mesmo clube dá sempre o mesmo elenco', $a === $b);

$erroForca = [];
foreach ($br as $c) {
    $e = futElencoDoClube($c['nome'], (int)$c['forca']);
    $erroForca[] = abs(futForcaDoElenco($e) - (int)$c['forca']);
}
ok('a força do elenco bate com o catálogo', max($erroForca) === 0, 'pior erro: ' . max($erroForca));

$comRepetido = 0;
$velhoNoTopo = 0;
$gerados = 0;
foreach ($br as $c) {
    $e = futElencoDoClube($c['nome'], (int)$c['forca']);
    $ns = array_column($e, 'nome');
    if (count($ns) !== count(array_unique($ns))) $comRepetido++;

    /* O craque velho só é defeito no elenco GERADO — foi lá que idade e OVR
       eram sorteados sem olhar um pro outro. Num elenco real, Hulk aos 40
       sendo o melhor do Fluminense é a vida como ela é, e reprovar isso
       transformaria o teste num alarme que só sabe dar falso positivo. */
    if (futForcaDoElencoReal($c['nome']) !== null) continue;
    $gerados++;
    usort($e, fn($x, $y) => $y['ovr'] <=> $x['ovr']);
    if ((int)$e[0]['idade'] >= 34) $velhoNoTopo++;
}
ok('nenhum elenco com nome repetido', $comRepetido === 0, "$comRepetido elencos");
ok('nenhum craque velho nos elencos gerados', $velhoNoTopo === 0,
   "$velhoNoTopo de $gerados gerados");

$comReal = array_filter(array_keys($br), fn($n) => futForcaDoElencoReal($n) !== null);
ok('há elencos reais importados', count($comReal) > 0, count($comReal) . ' clubes');

if ($comReal) {
    $maiorVelho = 0; $quemVelho = '';
    $forcas = [];
    foreach ($comReal as $nome) {
        $forcas[$nome] = futForcaDoElencoReal($nome);
        foreach (futElencoDoClube($nome, 70) as $j) {
            if ((int)$j['idade'] >= 38 && (int)$j['ovr'] > $maiorVelho) {
                $maiorVelho = (int)$j['ovr']; $quemVelho = $j['nome'] . ' (' . $j['idade'] . ')';
            }
        }
    }
    ok('ninguém de 38+ passa de 82 no elenco real', $maiorVelho <= 82,
       $quemVelho . ' com ' . $maiorVelho);
    arsort($forcas);
    $topo = array_key_first($forcas);
    $fundo = array_key_last($forcas);
    ok('o elenco real espalha a liga em pelo menos 15 pontos',
       $forcas[$topo] - $forcas[$fundo] >= 15,
       "$topo {$forcas[$topo]} … $fundo {$forcas[$fundo]}");
}

// ═════════════════════════════════════════════════════════════════════
secao('Economia');

$noVermelho = 0;
foreach ($br as $c) {
    $e = futElencoDoClube($c['nome'], (int)$c['forca']);
    if (futSaldoAnual((int)$c['forca'], $c['div'], $e) < 0) $noVermelho++;
}
ok('nenhum clube fecha o ano no vermelho', $noVermelho === 0, "$noVermelho clubes");

ok('valor cresce com o OVR', futValorDeMercado(85, 24) > futValorDeMercado(75, 24) * 2);
ok('jovem vale mais que veterano', futValorDeMercado(80, 22) > futValorDeMercado(80, 33) * 3);
ok('salário sai em "mil" quando é pouco', str_contains(futDinheiro(0.31), 'mil'), futDinheiro(0.31));
ok('salário sai em "mi" quando é muito', str_contains(futDinheiro(8.07), 'mi'), futDinheiro(8.07));
ok('o caixa do grande supera o do pequeno',
   futCaixaInicial(92, 'BR1') > futCaixaInicial(55, 'BR3') * 10);

// ═════════════════════════════════════════════════════════════════════
secao('Mercado');

$sportElenco = futElencoDoClube('Sport Recife', 70);
$postos = futPostosDoElenco($sportElenco);
$craque = null;
foreach ($sportElenco as $j) if ($postos[$j['nome']] === 1) $craque = $j;
$vCraque = futValorDeMercado((int)$craque['ovr'], (int)$craque['idade']);
ok('craque fora da lista custa mais que o dobro',
   futPrecoPedido($vCraque, 1, false) >= $vCraque * 2);
ok('quem está à venda sai perto da tabela',
   futPrecoPedido($vCraque, 1, true) <= $vCraque * 1.15);

$naLista = 0; $totalJ = 0;
foreach ($br as $c) {
    $e = futElencoDoClube($c['nome'], (int)$c['forca']);
    $ps = futPostosDoElenco($e);
    foreach ($e as $j) { $totalJ++; if (futEstaAVenda($c['nome'], $j, $ps[$j['nome']])) $naLista++; }
}
$pct = 100 * $naLista / $totalJ;
ok('entre 30% e 60% do mundo está à venda', entre($pct, 30, 60), round($pct) . '%');

// ═════════════════════════════════════════════════════════════════════
secao('Escalação');

$el = futElencoDoClube('Guarani', 55);
foreach (array_keys(FUT_ESQUEMAS) as $esq) {
    $esc = futEscalarAutomatico($el, $esq);
    if (count($esc) !== 11) { ok("esquema $esq escala 11", false, count($esc)); continue; }
}
ok('todo esquema escala 11', true, count(FUT_ESQUEMAS) . ' esquemas');

$esc = futEscalarAutomatico($el, '4-4-2');
$golNaVaga = $esc[0]['pos'] ?? '';
ok('o goleiro vai pro gol', $golNaVaga === 'GOL', $golNaVaga);

$umJogador = ['nome' => 'T', 'pos' => 'ATA', 'ovr' => 80, 'idade' => 25, 'energia' => 100, 'moral' => 75, 'lesao' => 0];
ok('improviso custa OVR', futOvrNaVaga($umJogador, 'GOL') < futOvrNaVaga($umJogador, 'ATA') - 30);

$cansado = $umJogador; $cansado['energia'] = 30;
ok('cansaço derruba o rendimento',
   futOvrNaVaga($cansado, 'ATA') < futOvrNaVaga($umJogador, 'ATA') - 5,
   futOvrNaVaga($umJogador, 'ATA') . ' → ' . futOvrNaVaga($cansado, 'ATA'));

$machucado = $umJogador; $machucado['nome'] = 'M'; $machucado['lesao'] = 3;
$comMachucado = array_merge($el, [$machucado]);
$escM = futEscalarAutomatico($comMachucado, '4-4-2');
$escalouMachucado = false;
foreach ($escM as $j) if ($j['nome'] === 'M') $escalouMachucado = true;
ok('machucado não é escalado sozinho', !$escalouMachucado);

// ═════════════════════════════════════════════════════════════════════
secao('Partida e estatística');

$e1 = futEscalarAutomatico(futElencoDoClube('Palmeiras', 92), '4-3-3');
$e2 = futEscalarAutomatico(futElencoDoClube('Cuiabá', 72), '4-4-2');

$golsSomados = 0; $golsNoPlacar = 0; $amarelos = 0; $vermelhos = 0; $assist = 0;
$N = 400;
for ($i = 0; $i < $N; $i++) {
    $p = futSimularPartida($e1, $e2, 92, 72, true);
    $golsNoPlacar += $p['meus'];
    $golsSomados += count($p['gols']);
    foreach ($p['gols'] as $g) if ($g['assistente']) $assist++;
    foreach ($p['cartoes'] as $c) { if ($c['tipo'] === 'vermelho') $vermelhos++; else $amarelos++; }
}
ok('todo gol tem autor', $golsSomados === $golsNoPlacar, "$golsSomados de $golsNoPlacar");
ok('amarelos por jogo entre 1,4 e 2,4', entre($amarelos / $N, 1.4, 2.4), round($amarelos / $N, 2));
ok('vermelhos por jogo abaixo de 0,25', $vermelhos / $N < 0.25, round($vermelhos / $N, 3));
$pctAssist = $golsSomados > 0 ? 100 * $assist / $golsSomados : 0;
ok('entre 50% e 80% dos gols têm assistência', entre($pctAssist, 50, 80), round($pctAssist) . '%');

$p = futSimularPartida($e1, $e2, 92, 72, true);
$notasFora = array_filter($p['notas'], fn($n) => $n < 3 || $n > 10);
ok('nota sempre entre 3 e 10', count($notasFora) === 0);

// Quem faz os gols é quem deveria
$porPos = [];
for ($i = 0; $i < 300; $i++) {
    foreach (futSimularPartida($e1, $e2, 92, 72, true)['gols'] as $g) {
        $porPos[$g['autor']['pos']] = ($porPos[$g['autor']['pos']] ?? 0) + 1;
    }
}
$doAtaque = ($porPos['ATA'] ?? 0) + ($porPos['PON'] ?? 0) + ($porPos['MEI'] ?? 0);
$daDefesa = ($porPos['ZAG'] ?? 0) + ($porPos['GOL'] ?? 0);
ok('o ataque faz muito mais gol que a defesa', $doAtaque > $daDefesa * 6,
   "ataque $doAtaque x defesa $daDefesa");

// ═════════════════════════════════════════════════════════════════════
secao('Temporada');

$est = futCarreiraNova('Teste', 'Paysandu');
$est['calendario'] = futCarreiraMontarCalendario($est);
ok('o calendário tem entre 40 e 60 jogos', entre(count($est['calendario']), 40, 60),
   count($est['calendario']) . ' jogos');

$comps = array_unique(array_column($est['calendario'], 'comp'));
ok('mais de uma competição no ano', count($comps) >= 2, implode(', ', $comps));

while (true) { $r = futCarreiraJogarProxima($est); if ($r['fim']) break; $est = $r['estado']; }

$tab = futCarreiraTabelaNacional($est);
$jogos = array_column($tab, 'j');
ok('todos os times com o mesmo número de jogos', min($jogos) === max($jogos),
   min($jogos) . ' a ' . max($jogos));

$minha = futCarreiraCampanha($est, 'Brasileirão Série B');
$naTabela = $tab['Paysandu'] ?? [];
ok('minha linha na tabela bate com a campanha',
   ($naTabela['p'] ?? -1) === $minha['p'] && ($naTabela['sg'] ?? -1) === $minha['sg'],
   $minha['p'] . 'pt');

$t1 = futCarreiraTabelaNacional($est);
$t2 = futCarreiraTabelaNacional($est);
ok('a tabela não muda entre dois F5', $t1 === $t2);

$golsElenco = array_sum(array_column($est['stats'], 'gols'));
$golsJogos = array_sum(array_column($est['resultados'], 'meus'));
ok('gols da temporada batem com os placares', $golsElenco === $golsJogos,
   "$golsElenco x $golsJogos");

$kb = strlen(json_encode($est)) / 1024;
ok('o save cabe em 60 KB', $kb < 60, round($kb, 1) . ' KB');

// ═════════════════════════════════════════════════════════════════════
secao('Carreira longa (20 temporadas)');

$est = futCarreiraNova('Teste', 'Guarani');
$menorElenco = 99; $maiorElenco = 0; $totalApos = 0; $totalBase = 0;
$maiorSalto = 0; $idades = [];

for ($t = 0; $t < 20; $t++) {
    $est['calendario'] = futCarreiraMontarCalendario($est);
    while (true) { $r = futCarreiraJogarProxima($est); if ($r['fim']) break; $est = $r['estado']; }
    $f = futCarreiraFecharTemporada($est);
    $est = $f['estado'];
    $est['falhas'] = 0;   // o teste ignora demissão: quer ver o elenco atravessar o tempo

    $n = count($est['elenco']);
    $menorElenco = min($menorElenco, $n);
    $maiorElenco = max($maiorElenco, $n);
    $totalApos += count($f['relatorio']['aposentados']);
    $totalBase += count($f['relatorio']['novos']);
    foreach ($f['relatorio']['evolucao'] as $ev) $maiorSalto = max($maiorSalto, abs($ev['delta']));
    $idades[] = array_sum(array_column($est['elenco'], 'idade')) / max(1, $n);
}

ok('o elenco nunca fica curto demais', $menorElenco >= FUT_ELENCO_MINIMO, "menor: $menorElenco");
ok('o elenco nunca estoura o limite', $maiorElenco <= FUT_ELENCO_MAXIMO, "maior: $maiorElenco");
ok('gente se aposentou', $totalApos > 0, "$totalApos em 20 anos");
ok('a base entregou jogadores', $totalBase > 0, "$totalBase em 20 anos");
ok('ninguém salta mais de 8 de OVR num ano', $maiorSalto <= 8, "maior salto: $maiorSalto");
$idadeMedia = array_sum($idades) / count($idades);
ok('a idade média do elenco fica entre 22 e 30', entre($idadeMedia, 22, 30), round($idadeMedia, 1) . ' anos');

$velhoDemais = array_filter($est['elenco'], fn($j) => (int)$j['idade'] > FUT_IDADE_APOSENTA_FIM);
ok('ninguém joga além da idade de parar', count($velhoDemais) === 0);

ok('o mundo se mexeu (transferências)', !empty($est['saidas']) || !empty($est['entradas']),
   count($est['saidas'] ?? []) . ' clubes com saída');
ok('houve notícias na virada', !empty($est['noticias']), count($est['noticias'] ?? []) . ' notícias');

$kb = strlen(json_encode($est)) / 1024;
ok('o save de 20 temporadas cabe em 120 KB', $kb < 120, round($kb, 1) . ' KB');

// ═════════════════════════════════════════════════════════════════════
secao('Emprego');

$est['tecnico']['reputacao'] = 70;
$props = futPropostasDeEmprego($est, $br);
ok('técnico com fama recebe proposta', count($props) > 0, count($props) . ' clubes');
$soMelhores = true;
$minha = (int)($br[$est['clube']]['forca'] ?? 0);
foreach ($props as $pr) if ((int)$pr['forca'] <= $minha) $soMelhores = false;
ok('só convida clube melhor que o atual', $soMelhores);

$semFama = $est; $semFama['tecnico']['reputacao'] = 5;
ok('técnico sem fama não recebe nada', count(futPropostasDeEmprego($semFama, $br)) === 0);

if ($props) {
    $antes = $est['clube'];
    $r = futCarreiraTrocarDeClube($est, $props[0]['nome']);
    ok('dá pra trocar de clube', $r['ok'] && $r['estado']['clube'] === $props[0]['nome'],
       "$antes → " . $props[0]['nome']);
    ok('o elenco novo veio junto', count($r['estado']['elenco']) >= FUT_ELENCO_MINIMO,
       count($r['estado']['elenco']) . ' jogadores');
    ok('a reputação foi com o técnico',
       (int)$r['estado']['tecnico']['reputacao'] === (int)$est['tecnico']['reputacao']);
}

// ═════════════════════════════════════════════════════════════════════
secao('Competições');

$t = futSimularTemporada();
$campeoes = [
    'Brasileirão'  => $t['brasileirao']['campeao']['nome'] ?? null,
    'Série B'      => $t['serie_b']['campeao']['nome'] ?? null,
    'Copa'         => $t['copa_brasil']['campeao']['nome'] ?? null,
    'Libertadores' => $t['liberta']['campeao']['nome'] ?? null,
    'Sula'         => $t['sula']['campeao']['nome'] ?? null,
];
$semCampeao = array_filter($campeoes, fn($c) => $c === null);
ok('toda competição tem campeão', count($semCampeao) === 0, implode(', ', array_keys($semCampeao)));

$nGrupos = 0;
foreach ($t['liberta']['grupos'] as $g) $nGrupos += count($g);
ok('a Libertadores tem 32 na fase de grupos', $nGrupos === 32, "$nGrupos clubes");

// ═════════════════════════════════════════════════════════════════════
secao('Europa');

$eu = futClubesDaEuropa();
$daLiga = array_filter($eu, fn($c) => $c['div'] !== FUT_DIV_CONVIDADO);
$convidados = array_filter($eu, fn($c) => $c['div'] === FUT_DIV_CONVIDADO);
ok('114 clubes nas seis ligas', count($daLiga) === 114, count($daLiga) . ' clubes');
ok('58 convidados das continentais', count($convidados) === 58, count($convidados) . ' clubes');

$tamanhos = [];
foreach (FUT_LIGAS_EU as $div => $m) $tamanhos[$m['nome']] = count(futClubesDaLigaEu($div));
ok('cada liga com 18 ou 20 clubes',
   count(array_filter($tamanhos, fn($n) => $n !== 18 && $n !== 20)) === 0,
   implode(', ', array_map(fn($k, $v) => "$k $v", array_keys($tamanhos), $tamanhos)));

/* NENHUM CLUBE EUROPEU PODE COLIDIR COM UM BRASILEIRO. O catálogo é indexado
   por nome e o elenco é achado pelo slug: um nome repetido faria um clube
   jogar com o elenco do outro, em silêncio e pra sempre. */
$repetidos = array_intersect(array_keys($eu), array_keys(futClubesDoBrasil()));
ok('nenhum nome repetido entre Brasil e Europa', $repetidos === [], implode(', ', $repetidos));
ok('o mundo tem 270 clubes', count(futClubesDoJogo()) === 270, count(futClubesDoJogo()) . ' clubes');

$semElenco = [];
$foraDaEscala = [];
foreach ($eu as $nome => $c) {
    $elenco = futElencoDoClube($nome, (int)$c['forca']);
    if (count($elenco) < 16) { $semElenco[] = $nome; continue; }
    foreach ($elenco as $j) {
        // 89 é FUT_OVR_TETO_JOGADOR, que mora no importador e não é carregado aqui.
        if ((int)$j['ovr'] > 89) $foraDaEscala[] = $j['nome'] . ' ' . $j['ovr'];
    }
}
ok('todo clube europeu tem elenco', $semElenco === [], implode(', ', array_slice($semElenco, 0, 4)));
ok('ninguém passa de 89 de overall', $foraDaEscala === [], implode(', ', array_slice($foraDaEscala, 0, 4)));

/* O ESTICAMENTO DAS LIGAS EUROPEIAS NÃO PODE SAIR DA RÉGUA (@see
   FUT_EUROPA_ESTICA). Em cima, nenhum clube pode passar do teto de jogador —
   um clube de força 90 precisaria de titular de 90, que não existe no jogo.
   Embaixo, o pior europeu não pode cair abaixo da Série B brasileira: o
   Nacional da Madeira é ruim pra Liga Portugal, não é um time de Série C. */
$forcas = array_column($eu, 'forca');
ok('o esticamento não jogou ninguém fora da régua', max($forcas) <= 89 && min($forcas) >= 55,
   min($forcas) . ' a ' . max($forcas));

/* UMA TEMPORADA EUROPEIA INTEIRA. O Porto tem as três competições do ano —
   liga, copa nacional e continental —, que é o caso completo. */
$est = futCarreiraNova('Teste', 'Porto');
$est['calendario'] = futCarreiraMontarCalendario($est);
$comps = array_unique(array_column($est['calendario'], 'comp'));
ok('o ano europeu tem liga, copa e continental', count($comps) === 3, implode(', ', $comps));
ok('nenhum estadual na Europa',
   !array_intersect($comps, array_values(FUT_ESTADUAIS)) && !array_intersect($comps, array_values(FUT_REGIONAIS)));

while (true) { $r = futCarreiraJogarProxima($est); if ($r['fim']) break; $est = $r['estado']; }

$tab = futCarreiraTabelaDaCompeticao($est, 'Liga Portugal');
$jogos = array_column($tab, 'j');
ok('a liga portuguesa fecha com todo mundo em 34',
   $jogos && min($jogos) === 34 && max($jogos) === 34, ($jogos ? min($jogos) . '-' . max($jogos) : 'sem tabela'));

$grupo = futCarreiraTabelaDaCompeticao($est, 'Champions League');
ok('o grupo da Champions tem 4 clubes', count($grupo) === 4, count($grupo) . ' clubes');
ok('o grupo conta só os 6 jogos da fase de grupos',
   $grupo && max(array_column($grupo, 'j')) <= 6, 'máximo ' . ($grupo ? max(array_column($grupo, 'j')) : 0));

/* A COPA TEM QUE ELIMINAR. Foi o bug da Copa do Brasil: o clube jogava as seis
   fases ganhasse ou perdesse. As copas novas usam o mesmo caminho, então este
   teste é o que garante que elas entraram na lista certa. */
$daCopa = array_filter($est['resultados'], fn($r) => ($r['comp'] ?? '') === 'Taça de Portugal');
$perdeu = false;
foreach ($daCopa as $r) if ((int)$r['meus'] < (int)$r['deles']) $perdeu = true;
ok('a copa nacional elimina quem perde',
   !$perdeu || count($daCopa) <= 4, count($daCopa) . ' jogos na Taça');

$f = futCarreiraFecharTemporada($est);
ok('o ano europeu fecha e paga premiação', $f['relatorio']['posicao'] !== null,
   $f['relatorio']['posicao'] . 'º, receita ' . $f['relatorio']['receita']);
ok('a temporada 2 monta calendário de novo',
   count(futCarreiraMontarCalendario($f['estado'])) > 30);

/* ── AS TRÊS CONTINENTAIS ────────────────────────────────────────────
   Cada uma precisa de gente suficiente pra sortear um grupo de quatro e mais
   quatro fases de mata-mata sem repetir adversário. */
$porComp = [];
foreach ($eu as $nome => $c) {
    $v = futContinentalDoClube($c);
    if ($v !== '') $porComp[$v][] = $nome;
}
foreach (FUT_CONTINENTAIS_EU as $comp) {
    ok('a ' . $comp . ' tem clube que chegue', count($porComp[$comp] ?? []) >= 12,
       count($porComp[$comp] ?? []) . ' clubes');
}
ok('nenhum clube disputa duas continentais',
   count(array_merge(...array_values($porComp))) === count(array_unique(array_merge(...array_values($porComp)))));

/* TODO CONVIDADO JOGA ALGUMA COISA. Eles só existem pra isso — um convidado
   sem competição é um elenco de 25 jogadores que nunca entra em campo. */
$paradas = array_filter($convidados, fn($c) => futContinentalDoClube($c) === '');
ok('todo convidado entra numa continental', $paradas === [],
   implode(', ', array_slice(array_keys($paradas), 0, 4)));

/* E NENHUM DELES PODE TE CONTRATAR: não há liga pra eles no jogo, então
   aceitar o convite deixaria o técnico com um ano sem calendário. */
$ofertaveis = array_filter(futClubesParaComecar(100), fn($c) => ($c['div'] ?? '') === FUT_DIV_CONVIDADO);
ok('convidado não aparece pra escolher no começo', $ofertaveis === [],
   implode(', ', array_slice(array_keys($ofertaveis), 0, 4)));

$estRico = futCarreiraNova('Teste', 'Vitória de Guimarães');
$estRico['tecnico']['reputacao'] = 100;
$props = futPropostasDeEmprego($estRico, futClubesDoJogo(), 8);
$ruins = array_filter($props, fn($p) => ($p['div'] ?? '') === FUT_DIV_CONVIDADO);
ok('convidado não manda proposta de emprego', $ruins === [], count($ruins) . ' propostas');

/* UM TÉCNICO EM SÉTIMO NA PREMIER JOGA A CONFERENCE. É o caso que a
   competição nova existe pra cobrir, e o que prova que a vaga dela não
   ficou só com os convidados. */
$naConference = array_filter($daLiga, fn($c) => futContinentalDoClube($c) === 'Conference League');
ok('as seis ligas também dão vaga na Conference', count($naConference) >= 6,
   count($naConference) . ' clubes');

// ═════════════════════════════════════════════════════════════════════
secao('Busca e competições');

require_once __DIR__ . '/fut_busca.php';

$comps = futTodasAsCompeticoes();
ok('o jogo conhece mais de 25 competições', count($comps) >= 25, count($comps) . ' competições');
ok('as seis ligas europeias estão na lista',
   count(array_filter(FUT_LIGAS_EU, fn($m) => isset($comps[$m['nome']]))) === 6);
ok('as seis copas nacionais também',
   count(array_filter(FUT_LIGAS_EU, fn($m) => isset($comps[$m['copa']]))) === 6);

$estB = futCarreiraNova('Teste', 'Athletic-MG');
$estB['calendario'] = futCarreiraMontarCalendario($estB);
for ($i = 0; $i < 12; $i++) { $r = futCarreiraJogarProxima($estB); if ($r['fim']) break; $estB = $r['estado']; }

/* ACENTO NÃO PODE ATRAPALHAR: ninguém digita o circunflexo do Grêmio. */
$r = futCarreiraBuscar($estB, 'gremio');
ok('busca sem acento acha o Grêmio',
   in_array('Grêmio', array_column($r['clubes'], 'nome'), true));

$r = futCarreiraBuscar($estB, 'a');
ok('uma letra não busca nada', $r === ['competicoes' => [], 'clubes' => [], 'jogadores' => []]);

$r = futCarreiraBuscar($estB, 'porto');
ok('quem começa com o termo vem primeiro',
   ($r['clubes'][0]['nome'] ?? '') === 'Porto',
   implode(', ', array_column($r['clubes'], 'nome')));

$r = futCarreiraBuscar($estB, 'neymar');
ok('acha jogador pelo nome', ($r['jogadores'][0]['clube'] ?? '') === 'Santos',
   ($r['jogadores'][0]['nome'] ?? '—') . ' no ' . ($r['jogadores'][0]['clube'] ?? '—'));

/* ── A TABELA DE UMA LIGA QUE NÃO É A SUA ────────────────────────────
   O ponto da página de competição: dirigir na Série B e poder olhar a
   Premier. E o ano tem que andar junto — uma liga parada na rodada 1
   enquanto a outra está na 20 denunciaria que ela não existe de verdade. */
$minhaTab = futCarreiraTabelaNacional($estB);
$rodadaMinha = $minhaTab ? (int)reset($minhaTab)['j'] : 0;
ok('a minha liga andou', $rodadaMinha > 0, "rodada $rodadaMinha");

foreach (['EN1', 'ES1', 'PT1'] as $div) {
    $t = futCarreiraTabelaDeQualquerLiga($estB, $div);
    $jogos = $t ? array_column($t, 'j') : [];
    ok("a tabela da $div existe e é justa",
       $t && min($jogos) === max($jogos) && count($t) === count(futClubesDaDivisaoDoJogo($div)),
       count($t) . ' clubes, rodada ' . ($jogos ? $jogos[0] : 0));
}

/* A MESMA TABELA DUAS VEZES TEM QUE SER A MESMA: ela é sorteada, e se a
   semente escorregasse o líder mudaria a cada F5. */
ok('a tabela de outra liga não muda entre dois F5',
   futCarreiraTabelaDeQualquerLiga($estB, 'EN1') === futCarreiraTabelaDeQualquerLiga($estB, 'EN1'));

/* Copa não tem tabela, e isso é resposta e não falha. */
ok('copa não devolve classificação',
   futCarreiraTabelaDeQualquerLiga($estB, '') === []);

/* ── MUDAR DE ESQUEMA NO MEIO DA PARTIDA ─────────────────────────────
   É a coisa que mais parece substituição sem ser: os onze continuam os
   mesmos, só trocam de lugar. Se o reescalonamento recebesse o elenco em
   vez dos onze em campo, o lateral reserva entraria de graça — cinco
   substituições viravam infinitas, e ninguém perceberia pela tela. */
$estF = futCarreiraNova('Tester', 'Flamengo');
$estF['calendario'] = futCarreiraMontarCalendario($estF);
$estF['fase'] = 'temporada';
$estF = futCarreiraAoVivoIniciar($estF)['estado'];
$estF = futCarreiraAoVivoAvancar($estF, 20)['estado'];

$antes = futCarreiraEscalacaoAtual($estF);
$nomesAntes = array_map(fn($j) => $j['nome'], $antes);
sort($nomesAntes);

$troquei = 0;
foreach (['4-3-3', '3-5-2', '5-3-2', '4-4-2'] as $esq) {
    $r = futCarreiraAoVivoFormacao($estF, $esq);
    if (!$r['ok']) { ok("o $esq aceita os mesmos onze", false, $r['erro']); continue; }
    $estF = $r['estado'];
    $agora = futCarreiraEscalacaoAtual($estF);
    $nomesAgora = array_map(fn($j) => $j['nome'], $agora);
    sort($nomesAgora);
    ok("mudar pro $esq mantém os mesmos onze",
       count($agora) === 11 && $nomesAgora === $nomesAntes,
       count($agora) . ' em campo, força ' . $r['forca']);
    $troquei++;
}

ok('trocar de esquema não gasta substituição',
   count($estF['aovivo']['trocas'] ?? []) === 0,
   $troquei . ' trocas de esquema, ' . count($estF['aovivo']['trocas'] ?? []) . ' substituição(ões)');

ok('esquema que não existe é recusado',
   futCarreiraAoVivoFormacao($estF, '9-0-1')['ok'] === false);

/* Depois do apito não se mexe mais: o resultado já está fechado, e deixar
   mudar aqui só serviria pra mexer numa força que não vai mais ser usada. */
$estF['aovivo']['minuto'] = 90;
ok('esquema não muda com a partida acabada',
   futCarreiraAoVivoFormacao($estF, '4-3-3')['ok'] === false);
/* ── CADA COMPETIÇÃO COM OS CLUBES DELA ──────────────────────────────
   A Copa do Brasil sorteava adversário no catálogo INTEIRO. Funcionava
   enquanto o catálogo era só o Brasil; quando a Europa entrou, o Humaitá
   da Série D estreava contra o Porto e o Palmeiras pegava o Sevilla.
   Ninguém viu por semanas, porque o calendário nunca é olhado inteiro. */
$paisDoClube = function (string $nome) {
    $c = futClubesDoJogo()[$nome] ?? null;
    if (!$c) return '??';
    $div = (string)($c['div'] ?? '');
    if ($div === FUT_DIV_CONVIDADO) return 'CONVIDADO';
    if (isset(FUT_LIGAS_EU[$div])) return $div;
    return ($c['uf'] ?? '') !== '' ? 'BR' : '??';
};

$forasteiros = [];
foreach (['Palmeiras', 'Náutico', 'Humaitá', 'Manchester City', 'Benfica'] as $clubeTeste) {
    $estC = futCarreiraNova('Tester', $clubeTeste);
    $estC['calendario'] = futCarreiraMontarCalendario($estC);
    $meuPais = $paisDoClube($clubeTeste);

    foreach ($estC['calendario'] as $j) {
        // Continental mistura país de propósito — é o que ela é.
        if (in_array($j['comp'], FUT_CONTINENTAIS_EU, true)) continue;
        $p = $paisDoClube((string)$j['adversario']);
        if ($p !== $meuPais) {
            $forasteiros[] = $clubeTeste . ' / ' . $j['comp'] . ': ' . $j['adversario'] . " ({$p})";
        }
    }
}
ok('nenhum clube joga competição de outro país',
   $forasteiros === [], $forasteiros ? implode(' · ', array_slice($forasteiros, 0, 3)) : '5 clubes conferidos');

/* E o convidado das continentais não entra em competição nacional nenhuma:
   ele existe pra ter quem enfrentar na Champions, não tem liga. */
$estCopa = futCarreiraNova('Tester', 'Flamengo');
$estCopa['calendario'] = futCarreiraMontarCalendario($estCopa);
$naCopa = [];
foreach ($estCopa['calendario'] as $j) {
    if ($j['comp'] === 'Copa do Brasil') $naCopa[] = (string)$j['adversario'];
}
$brasileiros = futClubesDoBrasil();
ok('a Copa do Brasil só tem clube brasileiro',
   $naCopa && count(array_diff($naCopa, array_keys($brasileiros))) === 0,
   count($naCopa) . ' adversários');
/* ── AS DUAS PORTAS DE ENTRADA ───────────────────────────────────────
   Escolher dá o jogo inteiro; o convite dá cinco sorteados. O que não
   pode entrar em nenhuma das duas é clube sem campeonato: os convidados
   das continentais existem pra ter quem enfrentar, não pra dirigir. */
$todosP = futClubesParaEscolher();
$divsP = [];
foreach ($todosP as $c) $divsP[$c['div']] = ($divsP[$c['div']] ?? 0) + 1;

ok('escolher abre as dez divisões do jogo', count($divsP) === 10, implode(', ', array_keys($divsP)));
ok('as seis ligas europeias estão na porta de entrada',
   count(array_intersect(array_keys(FUT_LIGAS_EU), array_keys($divsP))) === count(FUT_LIGAS_EU));
ok('clube sem campeonato não é oferecido',
   count(array_filter($todosP, fn($c) => ($c['div'] ?? '') === FUT_DIV_CONVIDADO)) === 0);

/* O MAIOR CLUBE DO JOGO ESTÁ NA LISTA — era isso que o teto de 63 barrava. */
$maisForte = 0;
foreach ($todosP as $c) $maisForte = max($maisForte, (int)$c['forca']);
ok('dá pra escolher o clube mais forte do jogo', $maisForte >= 85, 'força máxima ' . $maisForte);

/* ── O SORTEIO DE ESTREIA ────────────────────────────────────────────
   Mesma leva pra mesma semente (recarregar a página não muda nada) e
   leva diferente quando o jogador pede outra. */
$leva0 = array_keys(futConvitesDeEstreia(futSementeDeEstreia(7, 0)));
ok('a mesma leva sai igual duas vezes',
   $leva0 === array_keys(futConvitesDeEstreia(futSementeDeEstreia(7, 0))),
   implode(', ', $leva0));

$iguais = 0;
for ($k = 1; $k <= 6; $k++) {
    if (array_keys(futConvitesDeEstreia(futSementeDeEstreia(7, $k))) === $leva0) $iguais++;
}
ok('pedir outra leva muda a leva', $iguais <= 1, $iguais . ' de 6 saíram iguais');

/* E dois jogadores diferentes não recebem a mesma ligação. */
ok('cada jogador tem a sua leva',
   array_keys(futConvitesDeEstreia(futSementeDeEstreia(7, 0)))
   !== array_keys(futConvitesDeEstreia(futSementeDeEstreia(8, 0))));

/* A leva continua sendo de começo de carreira: sortear de novo não pode
   ser um atalho pro topo, senão a porta da sorte vira a porta boa. */
$forteDaLeva = 0;
for ($k = 0; $k <= 8; $k++) {
    foreach (futConvitesDeEstreia(futSementeDeEstreia(7, $k)) as $c) {
        $forteDaLeva = max($forteDaLeva, (int)$c['forca']);
    }
}
ok('o convite nunca passa do teto de estreia', $forteDaLeva <= 63, 'mais forte convidado: ' . $forteDaLeva);
/* ── INVERTER DOIS QUE JÁ ESTÃO EM CAMPO ─────────────────────────────
   Passar o lateral pra zaga era a única mexida que o jogo não deixava
   fazer: dava pra trocar de esquema e pra substituir, mas o lugar de cada
   um dentro do esquema estava congelado desde o apito inicial. */
$estI = futCarreiraNova('Tester', 'Flamengo');
$estI['calendario'] = futCarreiraMontarCalendario($estI);
$estI['fase'] = 'temporada';
$estI = futCarreiraAoVivoIniciar($estI)['estado'];
$estI = futCarreiraAoVivoAvancar($estI, 20)['estado'];

$campoI = futCarreiraEscalacaoAtual($estI);
$vagasI = array_keys($campoI);
$umI  = $campoI[$vagasI[1]]['nome'];
$doisI = $campoI[$vagasI[2]]['nome'];

$rI = futCarreiraAoVivoInverter($estI, $umI, $doisI);
ok('inverter dois titulares dá certo', $rI['ok'], $rI['erro'] ?: $umI . ' x ' . $doisI);

$depoisI = futCarreiraEscalacaoAtual($rI['estado']);
ok('os dois trocaram de vaga',
   ($depoisI[$vagasI[1]]['nome'] ?? '') === $doisI && ($depoisI[$vagasI[2]]['nome'] ?? '') === $umI);

ok('inverter mantém os mesmos onze',
   count($depoisI) === 11
   && array_diff(array_column($depoisI, 'nome'), array_column($campoI, 'nome')) === []);

ok('inverter não gasta substituição',
   count($rI['estado']['aovivo']['trocas'] ?? []) === 0);

/* NINGUÉM DO BANCO ENTRA POR AQUI. Se entrasse, a inversão viraria uma
   substituição de graça e as cinco do regulamento não significariam nada. */
$banco = futReservas($estI['elenco'], $campoI);
ok('quem está no banco não entra invertendo',
   futCarreiraAoVivoInverter($estI, $umI, $banco[0]['nome'])['ok'] === false,
   $banco[0]['nome'] . ' está fora de campo');

ok('inverter alguém com ele mesmo é recusado',
   futCarreiraAoVivoInverter($estI, $umI, $umI)['ok'] === false);

/* Depois do apito não se mexe mais, igual ao esquema. */
$estI90 = $estI;
$estI90['aovivo']['minuto'] = 90;
ok('não se inverte com a partida acabada',
   futCarreiraAoVivoInverter($estI90, $umI, $doisI)['ok'] === false);

/* O PREÇO ESTÁ NA AFINIDADE: o jogo não proíbe o lateral na zaga, ele
   cobra. Colocar o goleiro na linha tem que derrubar a força. */
$golI = $campoI[$vagasI[0]]['nome'];
$atacI = $campoI[$vagasI[10]]['nome'];
$forcaAntes = futForcaEscalada($campoI, $estI['esquema']);
$rGol = futCarreiraAoVivoInverter($estI, $golI, $atacI);
ok('goleiro na linha custa caro',
   $rGol['ok'] && $rGol['forca'] < $forcaAntes,
   'de ' . $forcaAntes . ' pra ' . $rGol['forca']);
/* ── O ANO INTEIRO SOBREVIVE À VIRADA ────────────────────────────────
   O histórico guardava só a liga nacional; o estadual e a copa eram
   apagados junto com o calendário. Um ano fechado tem que devolver TODAS
   as competições que foram jogadas, e nenhuma que não foi. */
$estH = futCarreiraNova('Tester', 'Flamengo');
$estH['calendario'] = futCarreiraMontarCalendario($estH);
$estH['fase'] = 'temporada';
$guardaH = 0;
while ((int)$estH['rodada'] < count($estH['calendario']) && $guardaH++ < 300) {
    $rH = futCarreiraJogarProxima($estH);
    if (!empty($rH['fim'])) break;
    $estH = $rH['estado'];
}

$fecho = futCarreiraFechoDoAno($estH);
$nomes = array_column($fecho, 'comp');
ok('o ano devolve todas as competições jogadas',
   count($fecho) >= 3 && in_array('Copa do Brasil', $nomes, true),
   implode(', ', $nomes));

/* A SOMA TEM QUE FECHAR. Se uma competição sumisse (ou entrasse duas
   vezes), os jogos não bateriam com o que foi disputado de verdade. */
$somaJogos = 0;
foreach ($fecho as $f) $somaJogos += (int)$f['campanha']['j'];
ok('a campanha de cada competição soma o ano todo',
   $somaJogos === count($estH['resultados']),
   $somaJogos . ' de ' . count($estH['resultados']));

ok('nenhuma competição entra sem jogo disputado',
   count(array_filter($fecho, fn($f) => (int)$f['campanha']['j'] === 0)) === 0);

/* O FECHO SEMPRE TEM PALAVRA. Uma linha em branco no histórico é pior do
   que não ter a linha: parece dado perdido. */
$semTexto = array_filter($fecho, fn($f) => trim(futCarreiraTextoDoFecho($f)) === '');
ok('todo fecho vira frase', count($semTexto) === 0);

/* A liga nacional aparece com posição, e ela bate com a que o fechamento
   da temporada usa pra julgar a meta — são a mesma tabela. */
$liga = null;
foreach ($fecho as $f) if (($f['tipo'] ?? '') === 'liga' && $f['comp'] === 'Brasileirão Série A') $liga = $f;
ok('a posição do nacional bate com a da meta',
   $liga !== null && $liga['posicao'] === futCarreiraMinhaPosicao($estH),
   'histórico ' . ($liga['posicao'] ?? '—') . ', meta ' . (futCarreiraMinhaPosicao($estH) ?? '—'));

/* ── AS PALAVRAS DO FECHO ────────────────────────────────────────── */
ok('campeão é campeão',
   futCarreiraTextoDoFecho(['fecho' => 'campeao', 'fase' => 'Final']) === 'Campeão');
ok('perder a final é vice',
   futCarreiraTextoDoFecho(['fecho' => 'vice', 'fase' => 'Final']) === 'Vice-campeão');
ok('a eliminação diz a fase',
   futCarreiraTextoDoFecho(['fecho' => 'eliminado', 'fase' => 'Quartas']) === 'Caiu nas Quartas');
ok('a semifinal pede outro artigo',
   futCarreiraTextoDoFecho(['fecho' => 'eliminado', 'fase' => 'Semifinal']) === 'Caiu na Semifinal');
ok('grupo não vira lugar de campeonato',
   futCarreiraTextoDoFecho(['fecho' => 'posicao', 'tipo' => 'grupo', 'posicao' => 1]) === '1º no grupo');

/* ── GANHAR A COPA DEIXA MARCA ───────────────────────────────────────
   Só a liga nacional dava taça: quem ganhava a Copa do Brasil ou o
   estadual terminava o ano com a estante vazia. O título da copa é
   forçado aqui na mão (o último jogo dela vira uma final vencida) porque
   depender da sorte de uma temporada faria o teste passar em um ano e
   falhar no outro sem nada ter mudado. */
$estT = $estH;
$estT['titulos'] = [];
$estT['historico'] = [];
for ($k = count($estT['resultados']) - 1; $k >= 0; $k--) {
    if (($estT['resultados'][$k]['comp'] ?? '') !== 'Copa do Brasil') continue;
    $estT['resultados'][$k]['fase'] = 'Final';
    $estT['resultados'][$k]['passou'] = true;
    break;
}

$fechoT = futCarreiraFechoDoAno($estT);
$copaT = null;
foreach ($fechoT as $f) if ($f['comp'] === 'Copa do Brasil') $copaT = $f;
ok('vencer a final é título de copa',
   ($copaT['fecho'] ?? '') === 'campeao', futCarreiraTextoDoFecho($copaT ?? []));

$anoT = (int)$estT['ano'];
$fT = futCarreiraFecharTemporada($estT);
ok('a taça da copa entra na estante',
   in_array('Copa do Brasil ' . $anoT, $fT['estado']['titulos'], true),
   implode(' | ', $fT['estado']['titulos']) ?: 'estante vazia');

/* E o histórico do ano guarda a mesma lista, pra aba Carreira não ter que
   recalcular nada — os jogos daquele ano já foram apagados. */
$ultimoT = end($fT['estado']['historico']);
ok('o ano guarda as competições e as taças',
   count($ultimoT['competicoes'] ?? []) === count($fechoT)
   && in_array('Copa do Brasil ' . $anoT, $ultimoT['titulos'] ?? [], true));
// ═════════════════════════════════════════════════════════════════════
secao('O treino da semana');

/* O contrato do treino é a contrapartida: todo foco que dá, cobra. Estes
   testes garantem as três pontas do triângulo — e que o jogo de quem ignora
   o treino continua exatamente o jogo de antes. */

ok('equilibrado não mexe na força', futTreinoBonusForca('equilibrado') === 0);
ok('intensidade dá +1 de força', futTreinoBonusForca('intensidade') === 1);
ok('recuperação tira 1 de força', futTreinoBonusForca('recuperacao') === -1);
ok('formação não mexe na força', futTreinoBonusForca('formacao') === 0);

$elencoTr = [
    ['nome' => 'Joga 90', 'pos' => 'ATA', 'ovr' => 70, 'idade' => 25, 'energia' => 80, 'moral' => 70, 'lesao' => 0],
    ['nome' => 'Descansa', 'pos' => 'MEI', 'ovr' => 70, 'idade' => 25, 'energia' => 60, 'moral' => 70, 'lesao' => 0],
];
$escTr = [$elencoTr[0]];

$dEq = futAplicarDesgaste($elencoTr, $escTr, 1, 0, [], 'equilibrado');
$dIn = futAplicarDesgaste($elencoTr, $escTr, 1, 0, [], 'intensidade');
$dRe = futAplicarDesgaste($elencoTr, $escTr, 1, 0, [], 'recuperacao');
$en = function (array $d, string $nome): int {
    foreach ($d['elenco'] as $j) if ($j['nome'] === $nome) return (int)$j['energia'];
    return -1;
};

ok('equilibrado cobra os 17 de sempre',
   $en($dEq, 'Joga 90') === 80 - FUT_ENERGIA_POR_JOGO, 'ficou com ' . $en($dEq, 'Joga 90'));
ok('intensidade cobra 20 pelos 90 minutos',
   $en($dIn, 'Joga 90') === 80 - FUT_TREINO_DESGASTE_INTENSO, 'ficou com ' . $en($dIn, 'Joga 90'));
ok('recuperação não muda o custo de quem jogou',
   $en($dRe, 'Joga 90') === 80 - FUT_ENERGIA_POR_JOGO);
ok('recuperação descansa mais quem não jogou',
   $en($dRe, 'Descansa') === min(100, 60 + FUT_ENERGIA_DESCANSO + FUT_TREINO_DESCANSO_EXTRA),
   'ficou com ' . $en($dRe, 'Descansa'));
ok('sem recuperação o descanso é o de sempre',
   $en($dEq, 'Descansa') === min(100, 60 + FUT_ENERGIA_DESCANSO));

/* A intensidade escala por minuto como o desgaste normal: quem entrou aos
   80 não paga o jogo inteiro nem no treino pesado. */
$dMin = futAplicarDesgaste($elencoTr, $escTr, 1, 0, ['Joga 90' => 10], 'intensidade');
$custoDez = 80 - $en($dMin, 'Joga 90');
ok('intensidade também cobra por minuto', $custoDez >= 1 && $custoDez <= 4, 'custou ' . $custoDez);

/* ── O setter recusa o que a tela não deveria oferecer ───────────── */
$estTr = ['elenco' => [
    ['nome' => 'Menino A', 'pos' => 'ATA', 'ovr' => 62, 'idade' => 18, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
    ['nome' => 'Menino B', 'pos' => 'MEI', 'ovr' => 64, 'idade' => 21, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
    ['nome' => 'Veterano', 'pos' => 'ZAG', 'ovr' => 74, 'idade' => 30, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
]];

$rTr = futCarreiraDefinirTreino($estTr, 'formacao', ['Menino A', 'Menino B']);
ok('marcar dois meninos dá certo', $rTr['ok'], $rTr['erro']);
ok('os dois ficam gravados', ($rTr['estado']['treino']['destaques'] ?? []) === ['Menino A', 'Menino B']);

ok('foco inventado é recusado', !futCarreiraDefinirTreino($estTr, 'pesado', [])['ok']);
ok('destaque de fora do elenco é recusado',
   !futCarreiraDefinirTreino($estTr, 'formacao', ['Ninguém'])['ok']);
ok('veterano não vira destaque',
   !futCarreiraDefinirTreino($estTr, 'formacao', ['Veterano'])['ok']);

/* A leitura só devolve destaque com o foco em formação — senão "formação"
   não escolheria nada e viraria enfeite no menu. */
$lido = futCarreiraTreino($rTr['estado']);
ok('com formação, os destaques valem', $lido['destaques'] === ['Menino A', 'Menino B']);
$rSai = futCarreiraDefinirTreino($rTr['estado'], 'intensidade', ['Menino A', 'Menino B']);
ok('fora da formação, destaque não colhe',
   futCarreiraTreino($rSai['estado'])['destaques'] === []);
$semTreino = futCarreiraTreino(['elenco' => []]);
ok('sem treino gravado, o padrão é equilibrado', $semTreino['foco'] === 'equilibrado');

/* ── O destaque evolui a mais no fim do ano ──────────────────────────
   Mesmo jogador, mesmo ano, com e sem a marca: a diferença tem que aparecer
   na média de muitas viradas — um ano só é sorteio. */
$somaCom = 0; $somaSem = 0; $n = 60;
for ($i = 0; $i < $n; $i++) {
    mt_srand(1000 + $i);
    $jov = ['nome' => 'Menino ' . $i, 'pos' => 'MEI', 'ovr' => 60, 'idade' => 18,
            'energia' => 100, 'moral' => 70, 'lesao' => 0];
    $somaCom += (int)futEvoluirJogador($jov, [], true)['ovr'];
    mt_srand(1000 + $i);
    $somaSem += (int)futEvoluirJogador($jov, [], false)['ovr'];
}
$ganho = ($somaCom - $somaSem) / $n;
ok('o destaque cresce mais que o irmão gêmeo', $ganho > 0.5 && $ganho <= 1.2,
   sprintf('+%.2f de diferença média', $ganho));

/* O veterano marcado por engano não ganha nada: o degrau é de formação. */
mt_srand(7);
$velhoCom = futEvoluirJogador(['nome' => 'V', 'pos' => 'ZAG', 'ovr' => 74, 'idade' => 30,
                               'energia' => 100, 'moral' => 70, 'lesao' => 0], [], true);
mt_srand(7);
$velhoSem = futEvoluirJogador(['nome' => 'V', 'pos' => 'ZAG', 'ovr' => 74, 'idade' => 30,
                               'energia' => 100, 'moral' => 70, 'lesao' => 0], [], false);
ok('destaque não rejuvenesce veterano', $velhoCom['ovr'] === $velhoSem['ovr']);
// ═════════════════════════════════════════════════════════════════════
secao('Os prêmios da temporada');

/* Craque e revelação saem da nota do próprio elenco — a única nota que
   existe. O craque precisa de metade dos jogos, e a revelação nunca é o
   próprio craque: prêmio repetido no mesmo nome é tela vazia. */
$estPr = [
    'clube' => 'Clube Inventado FC',     // sem divisão: a parte da liga sai vazia
    'elenco' => [
        ['nome' => 'Veterano Bom', 'pos' => 'MEI', 'ovr' => 78, 'idade' => 29, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
        ['nome' => 'Menino Bom', 'pos' => 'ATA', 'ovr' => 70, 'idade' => 19, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
        ['nome' => 'Faz Pouco', 'pos' => 'ZAG', 'ovr' => 72, 'idade' => 24, 'energia' => 100, 'moral' => 70, 'lesao' => 0],
    ],
    'stats' => [
        'Veterano Bom' => ['pos' => 'MEI', 'jogos' => 30, 'soma_notas' => 225.0, 'gols' => 8, 'assist' => 10],  // 7,50
        'Menino Bom'   => ['pos' => 'ATA', 'jogos' => 28, 'soma_notas' => 201.6, 'gols' => 15, 'assist' => 4],  // 7,20
        'Faz Pouco'    => ['pos' => 'ZAG', 'jogos' => 4,  'soma_notas' => 38.0,  'gols' => 0, 'assist' => 0],   // 9,50, mas 4 jogos
    ],
];

$prA = futCarreiraPremiosDaTemporada($estPr);
ok('o craque é a melhor média com jogo de verdade',
   ($prA['craque']['nome'] ?? '') === 'Veterano Bom', $prA['craque']['nome'] ?? 'ninguém');
ok('nota alta em quatro jogos não ganha prêmio',
   ($prA['craque']['nome'] ?? '') !== 'Faz Pouco');
ok('a revelação é o melhor com até 21 anos',
   ($prA['revelacao']['nome'] ?? '') === 'Menino Bom', $prA['revelacao']['nome'] ?? 'ninguém');
ok('clube sem divisão não tem prêmio de liga', $prA['liga']['artilheiro'] === null);

/* Quando o menino É o melhor do time, ele leva o craque — e a revelação vai
   pro segundo jovem ou fica vazia, nunca duplica. */
$estPr2 = $estPr;
$estPr2['stats']['Menino Bom']['soma_notas'] = 240.8;   // 8,60: agora é o melhor
$prB = futCarreiraPremiosDaTemporada($estPr2);
ok('o menino craque leva o prêmio grande',
   ($prB['craque']['nome'] ?? '') === 'Menino Bom');
ok('a revelação não repete o craque', ($prB['revelacao']['nome'] ?? null) === null,
   $prB['revelacao']['nome'] ?? 'vazia, como deve');

/* Temporada sem stats (fechou sem jogar): nada explode, nada é inventado. */
$prC = futCarreiraPremiosDaTemporada(['clube' => 'Clube Inventado FC', 'elenco' => [], 'stats' => []]);
ok('sem temporada, sem prêmio', $prC['craque'] === null && $prC['revelacao'] === null);
// ═════════════════════════════════════════════════════════════════════
secao('A imprensa');

/* A manchete só sai quando há história. Rodada comum não vira papel. */
$resComum = ['adversario' => 'Oponente FC', 'meus' => 1, 'deles' => 1,
             'eventos' => [], 'escalacao' => []];
$estIm = ['clube' => 'Meu Clube', 'resultados' => [$resComum], 'stats' => [], 'imprensa' => []];
$estIm = futCarreiraImprensa($estIm, $resComum);
ok('empate comum não vira manchete', ($estIm['imprensa'] ?? []) === []);

/* Goleada feita, goleada sofrida. */
$resGol = ['adversario' => 'Oponente FC', 'meus' => 4, 'deles' => 0, 'eventos' => [], 'escalacao' => []];
$e1 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => [$resGol], 'stats' => []], $resGol);
ok('goleada vira manchete boa',
   count($e1['imprensa']) >= 1 && $e1['imprensa'][0]['tom'] === 'boa',
   $e1['imprensa'][0]['texto'] ?? 'nada');
$resVex = ['adversario' => 'Oponente FC', 'meus' => 0, 'deles' => 5, 'eventos' => [], 'escalacao' => []];
$e2 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => [$resVex], 'stats' => []], $resVex);
ok('vexame vira manchete ruim', ($e2['imprensa'][0]['tom'] ?? '') === 'ruim',
   $e2['imprensa'][0]['texto'] ?? 'nada');

/* Hat-trick e marco do artilheiro na mesma noite — e o teto de três. */
$golDele = fn(int $min) => ['tipo' => 'gol', 'meu' => true, 'minuto' => $min,
                            'jogador' => 'Matador', 'pos' => 'ATA', 'assistente' => null];
$resHat = ['adversario' => 'Oponente FC', 'meus' => 3, 'deles' => 0,
           'eventos' => [$golDele(10), $golDele(40), $golDele(80)],
           'escalacao' => [['nome' => 'Matador', 'pos' => 'ATA', 'ovr' => 80, 'nota' => 9.5]]];
$e3 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => [$resHat],
                           'stats' => ['Matador' => ['gols' => 10]]], $resHat);
$textos = array_map(fn($m) => $m['texto'], $e3['imprensa']);
ok('hat-trick vira manchete', (bool)preg_grep('/faz três/u', $textos), implode(' | ', $textos));
ok('no máximo três manchetes por partida', count($e3['imprensa']) <= 3,
   count($e3['imprensa']) . ' manchetes');

/* Invencibilidade no marco exato — e silêncio fora dele. */
$vitoria = ['adversario' => 'Oponente FC', 'meus' => 1, 'deles' => 0, 'eventos' => [], 'escalacao' => []];
$cinco = array_fill(0, 5, $vitoria);
$e4 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => $cinco, 'stats' => []], $vitoria);
ok('5 jogos sem perder vira manchete',
   (bool)preg_grep('/5 jogos sem perder/u', array_map(fn($m) => $m['texto'], $e4['imprensa'])));
$seis = array_fill(0, 6, $vitoria);
$e5 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => $seis, 'stats' => []], $vitoria);
ok('6 jogos sem perder fica quieto', ($e5['imprensa'] ?? []) === []);

/* A crise anuncia a terceira derrota, não todas. */
$derrota = ['adversario' => 'Oponente FC', 'meus' => 0, 'deles' => 1, 'eventos' => [], 'escalacao' => []];
$e6 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => array_fill(0, 3, $derrota), 'stats' => []], $derrota);
ok('terceira derrota seguida é crise',
   (bool)preg_grep('/3ª derrota/u', array_map(fn($m) => $m['texto'], $e6['imprensa'])));
$e7 = futCarreiraImprensa(['clube' => 'Meu Clube', 'resultados' => array_fill(0, 4, $derrota), 'stats' => []], $derrota);
ok('a quarta não repete o enterro', ($e7['imprensa'] ?? []) === []);

/* O mural não cresce pra sempre. */
$cheio = ['clube' => 'Meu Clube', 'resultados' => [$resGol], 'stats' => [],
          'imprensa' => array_fill(0, 12, ['texto' => 'velha', 'tom' => 'boa', 'jogo' => 1])];
$e8 = futCarreiraImprensa($cheio, $resGol);
ok('o mural guarda no máximo 12', count($e8['imprensa']) === 12);
ok('a manchete nova entra na frente', $e8['imprensa'][0]['texto'] !== 'velha');
// ═════════════════════════════════════════════════════════════════════
secao('Propostas pelos seus jogadores');

/* Um elenco de 18 (acima do mínimo) com um craque no meio, pra os sorteios
   terem de onde escolher sem esbarrar na trava do tamanho. */
$elencoOf = [];
for ($i = 0; $i < 18; $i++) {
    $elencoOf[] = ['nome' => 'Jogador ' . $i, 'pos' => 'MEI', 'ovr' => 68, 'idade' => 24,
                   'energia' => 100, 'moral' => 75, 'lesao' => 0];
}
$elencoOf[0] = ['nome' => 'Craque', 'pos' => 'ATA', 'ovr' => 84, 'idade' => 25,
                'energia' => 100, 'moral' => 75, 'lesao' => 0];

$baseOf = ['clube' => 'Flamengo', 'fase' => 'temporada', 'rodada' => 10, 'caixa' => 50.0,
           'elenco' => $elencoOf, 'stats' => [], 'ofertas' => [], 'mensagens' => [],
           'resultados' => [], 'imprensa' => [], 'entradas' => [], 'saidas' => [], 'escalacao' => []];

/* ── O craque atrai mais que o mediano ────────────────────────────── */
$pesoCraque = futOfertaPeso($elencoOf[0], []);
$pesoComum  = futOfertaPeso($elencoOf[1], []);
ok('o craque atrai muito mais olhares', $pesoCraque > $pesoComum * 4,
   $pesoCraque . ' contra ' . $pesoComum);

$emFase = futOfertaPeso($elencoOf[1], ['Jogador 1' => ['jogos' => 10, 'soma_notas' => 75.0]]);
ok('quem está em boa fase atrai mais', $emFase > $pesoComum, $emFase . ' contra ' . $pesoComum);

/* ── A proposta nasce com prazo e com um comprador que cabe ───────── */
$achou = null;
for ($i = 0; $i < 400 && !$achou; $i++) {
    $r = futOfertasGerar($baseOf);
    if (!empty($r['ofertas'])) $achou = $r;
}
ok('alguém acaba batendo na porta', $achou !== null);
if ($achou) {
    $of = $achou['ofertas'][0];
    ok('a proposta tem prazo de rodada',
       (int)$of['expira'] === 10 + FUT_OFERTA_VALIDADE, 'expira em ' . $of['expira']);
    ok('o alvo é do elenco',
       in_array($of['jogador'], array_column($elencoOf, 'nome'), true), $of['jogador']);
    ok('o comprador não é o próprio clube', $of['clube'] !== 'Flamengo');
    ok('o valor fica perto do de mercado',
       $of['valor'] >= $of['mercado'] * 0.8 && $of['valor'] <= $of['mercado'] * 1.5,
       sprintf('%.2f por %.2f de mercado', $of['valor'], $of['mercado']));
    ok('a proposta vira recado', (bool)preg_grep('/ofereceu/u', $achou['mensagens']));
}

/* ── Fora da temporada ninguém liga ───────────────────────────────── */
$noMercado = $baseOf; $noMercado['fase'] = 'mercado';
$quieto = true;
for ($i = 0; $i < 200; $i++) if (futOfertasGerar($noMercado)['ofertas']) { $quieto = false; break; }
ok('na fase de mercado não chega proposta', $quieto);

/* ── O teto de propostas na mesa ──────────────────────────────────── */
$cheio = $baseOf;
$cheio['ofertas'] = array_fill(0, FUT_OFERTAS_MAX,
    ['clube' => 'X', 'jogador' => 'Y', 'pos' => 'MEI', 'ovr' => 70,
     'valor' => 1.0, 'mercado' => 1.0, 'expira' => 99]);
ok('com a mesa cheia, ninguém mais liga',
   count(futOfertasGerar($cheio)['ofertas']) === FUT_OFERTAS_MAX);

/* ── O prazo vence ────────────────────────────────────────────────── */
$vencendo = $baseOf;
$vencendo['ofertas'] = [['clube' => 'Palmeiras', 'jogador' => 'Craque', 'pos' => 'ATA', 'ovr' => 84,
                         'valor' => 50.0, 'mercado' => 40.0, 'expira' => 10]];
$venceu = futOfertasExpirar($vencendo);
ok('proposta vencida sai da mesa', $venceu['ofertas'] === []);
ok('e o clube avisa que desistiu', (bool)preg_grep('/desistiu/u', $venceu['mensagens']));

$aindaVale = $vencendo;
$aindaVale['ofertas'][0]['expira'] = 11;
ok('proposta no prazo continua de pé', count(futOfertasExpirar($aindaVale)['ofertas']) === 1);

/* ── Aceitar ──────────────────────────────────────────────────────── */
$comOferta = $baseOf;
$comOferta['ofertas'] = [['clube' => 'Palmeiras', 'jogador' => 'Craque', 'pos' => 'ATA', 'ovr' => 84,
                          'valor' => 50.0, 'mercado' => 40.0, 'expira' => 13]];
$ace = futOfertaAceitar($comOferta, 0);
ok('aceitar dá certo', $ace['ok'], $ace['motivo']);
ok('o dinheiro entra no caixa', abs($ace['estado']['caixa'] - 100.0) < 0.01, (string)$ace['estado']['caixa']);
ok('o jogador sai do elenco',
   !in_array('Craque', array_column($ace['estado']['elenco'], 'nome'), true));
ok('o comprador fica com ele de verdade',
   ($ace['estado']['entradas']['Palmeiras'][0]['nome'] ?? '') === 'Craque');
ok('a mesa fica vazia depois de aceitar', $ace['estado']['ofertas'] === []);
ok('a venda vira manchete',
   (bool)preg_grep('/rumo ao Palmeiras/u', array_column($ace['estado']['imprensa'], 'texto')));

/* Elenco no mínimo não vende: o time ficaria sem gente. */
$noMinimo = $comOferta;
$noMinimo['elenco'] = array_slice($elencoOf, 0, FUT_ELENCO_MINIMO);
$rMin = futOfertaAceitar($noMinimo, 0);
ok('no mínimo do elenco, não dá pra vender', !$rMin['ok'], $rMin['motivo']);

/* ── Recusar ──────────────────────────────────────────────────────── */
$recTentadora = futOfertaRecusar($comOferta, 0);   // 50 por 40 = 1,25x... abaixo do corte
ok('recusar tira da mesa', $recTentadora['estado']['ofertas'] === []);

$moralDe = function (array $est, string $nome): int {
    foreach ($est['elenco'] as $j) if ($j['nome'] === $nome) return (int)$j['moral'];
    return -1;
};
$ok125 = $moralDe($recTentadora['estado'], 'Craque');
ok('proposta comum recusada não mexe na moral', $ok125 === 75, 'moral ' . $ok125);

$bemAcima = $comOferta;
$bemAcima['ofertas'][0]['valor'] = 40.0 * FUT_OFERTA_TENTADORA + 1;
$rec2 = futOfertaRecusar($bemAcima, 0);
$moral2 = $moralDe($rec2['estado'], 'Craque');
ok('segurar quem queria ir custa moral', $moral2 === 75 - FUT_OFERTA_MORAL_SEGURADO, 'moral ' . $moral2);
ok('e ele deixa isso registrado', (bool)preg_grep('/segurado/u', $rec2['estado']['mensagens']));

/* ── Proposta órfã (o jogador saiu por outro caminho) ─────────────── */
$orfa = $comOferta;
$orfa['elenco'] = array_values(array_filter($elencoOf, fn($j) => $j['nome'] !== 'Craque'));
$rOrfa = futOfertaAceitar($orfa, 0);
ok('proposta por quem não está mais no elenco é recusada com jeito', !$rOrfa['ok'], $rOrfa['motivo']);
ok('e ela some da mesa', $rOrfa['estado']['ofertas'] === []);

ok('índice que não existe não explode', !futOfertaAceitar($baseOf, 7)['ok']);
// ═════════════════════════════════════════════════════════════════════
secao('Desafios da temporada');

$vit = fn(int $a, int $b) => ['adversario' => 'X', 'meus' => $a, 'deles' => $b,
                              'comp' => 'Brasileirão Série A', 'casa' => true,
                              'rodada' => 1, 'fase' => ''];

$baseD = ['clube' => 'Flamengo', 'temporada' => 1, 'caixa' => 50.0,
          'tecnico' => ['reputacao' => 20],
          'elenco' => [
              ['nome' => 'Garoto', 'pos' => 'ATA', 'ovr' => 70, 'idade' => 19,
               'energia' => 100, 'moral' => 70, 'lesao' => 0],
              ['nome' => 'Veterano', 'pos' => 'ZAG', 'ovr' => 75, 'idade' => 31,
               'energia' => 100, 'moral' => 70, 'lesao' => 0],
          ],
          'stats' => [], 'resultados' => [], 'mensagens' => [], 'imprensa' => [],
          'desafios' => [], 'marcos' => []];

/* ── O sorteio é sempre o mesmo pro mesmo clube e temporada ───────── */
$d1 = futDesafiosSortear($baseD);
$d2 = futDesafiosSortear($baseD);
ok('o sorteio não muda a cada leitura', array_column($d1, 'id') === array_column($d2, 'id'),
   implode(', ', array_column($d1, 'id')));
ok('são três desafios', count($d1) === FUT_DESAFIOS_POR_ANO);

$outroAno = $baseD; $outroAno['temporada'] = 2;
ok('o ano seguinte traz outros',
   array_column(futDesafiosSortear($outroAno), 'id') !== array_column($d1, 'id'));

$outroClube = $baseD; $outroClube['clube'] = 'Santos';
ok('outro clube sorteia diferente',
   array_column(futDesafiosSortear($outroClube), 'id') !== array_column($d1, 'id'));

/* ── Save sem nada gravado já mostra os três ──────────────────────── */
$lidos = futCarreiraDesafios($baseD);
ok('save antigo ganha os três na leitura', count($lidos) === FUT_DESAFIOS_POR_ANO);
ok('cada um vem com texto, alvo e prêmio',
   $lidos[0]['texto'] !== '' && $lidos[0]['alvo'] > 0 && !empty($lidos[0]['premio']['tipo']),
   $lidos[0]['texto']);
ok('ninguém nasce feito', !$lidos[0]['feito'] && !$lidos[0]['batido']);

/* ── As contas de cada desafio ────────────────────────────────────── */
$seq = $baseD;
$seq['resultados'] = [$vit(1,0), $vit(2,2), $vit(3,1), $vit(0,1), $vit(1,0), $vit(1,0)];
ok('a sequência sem perder é a MAIOR, não a atual',
   futDesafioSequenciaSemPerder($seq) === 3, (string)futDesafioSequenciaSemPerder($seq));

$gols = $baseD;
$gols['stats'] = ['Garoto' => ['gols' => 11, 'jogos' => 20], 'Veterano' => ['gols' => 3, 'jogos' => 20]];
ok('o artilheiro é o maior do elenco', futDesafioMaiorArtilheiro($gols) === 11);

$semSofrer = $baseD;
$semSofrer['resultados'] = [$vit(1,0), $vit(0,0), $vit(2,1), $vit(3,0)];
ok('conta os jogos sem sofrer gol', futDesafioJogosSemSofrer($semSofrer) === 3);

$golead = $baseD;
$golead['resultados'] = [$vit(3,0), $vit(4,1), $vit(2,0), $vit(5,1)];
ok('goleada é 3 ou mais de diferença', futDesafioGoleadas($golead) === 3);

$garotos = $baseD;
$garotos['stats'] = ['Garoto' => ['jogos' => 14], 'Veterano' => ['jogos' => 30]];
ok('só os de até 21 contam nos jogos dos garotos', futDesafioJogosDosGarotos($garotos) === 14);

$venda = futDesafioRegistrarVenda($baseD, 18.0);
$venda = futDesafioRegistrarVenda($venda, 31.5);
$venda = futDesafioRegistrarVenda($venda, 9.0);
ok('o marco guarda a MAIOR venda', futDesafioMaiorVenda($venda) === 31);

/* ── O prêmio paga, e paga uma vez só ─────────────────────────────── */
$catD = futDesafiosCatalogo();
$comDesafio = function (string $id, array $est) use ($catD): array {
    $est['desafios'] = [['id' => $id, 'feito' => false]];
    return $est;
};

$pagaD = $comDesafio('artilheiro', $gols);
$pagaD['stats']['Garoto']['gols'] = $catD['artilheiro']['alvo'];
$caixaAntes = $pagaD['caixa'];
$r1 = futDesafiosConferir($pagaD);
ok('o desafio batido paga', count($r1['pagos']) === 1, $r1['pagos'][0]['texto'] ?? 'nada');
ok('o dinheiro entra no caixa',
   abs($r1['estado']['caixa'] - ($caixaAntes + $catD['artilheiro']['premio']['valor'])) < 0.01,
   (string)$r1['estado']['caixa']);
ok('fica marcado como feito', !empty($r1['estado']['desafios'][0]['feito']));
ok('vira recado', (bool)preg_grep('/Desafio cumprido/u', $r1['estado']['mensagens']));
ok('vira manchete',
   (bool)preg_grep('/cumpriu o desafio/u', array_column($r1['estado']['imprensa'], 'texto')));

$r2 = futDesafiosConferir($r1['estado']);
ok('não paga duas vezes', $r2['pagos'] === []);
ok('e o caixa não mexe de novo', abs($r2['estado']['caixa'] - $r1['estado']['caixa']) < 0.01);

/* Reputação e moral também são pagas. */
$rep = $comDesafio('atropelo', $golead);
$rRep = futDesafiosConferir($rep);
ok('prêmio de reputação sobe a reputação',
   (int)$rRep['estado']['tecnico']['reputacao'] === 20 + $catD['atropelo']['premio']['valor'],
   (string)$rRep['estado']['tecnico']['reputacao']);

$mor = $comDesafio('negociante', futDesafioRegistrarVenda($baseD, 25.0));
$rMor = futDesafiosConferir($mor);
ok('prêmio de moral sobe a moral de todo o elenco',
   (int)$rMor['estado']['elenco'][0]['moral'] === 70 + $catD['negociante']['premio']['valor']
   && (int)$rMor['estado']['elenco'][1]['moral'] === 70 + $catD['negociante']['premio']['valor']);

/* ── Quem não bateu não recebe ────────────────────────────────────── */
$longe = $comDesafio('invencivel', $baseD);
$rLonge = futDesafiosConferir($longe);
ok('sem bater o alvo, nada cai', $rLonge['pagos'] === []);
ok('e o progresso aparece parado em zero',
   futCarreiraDesafios($longe)[0]['progresso'] === 0);

/* O progresso nunca passa do alvo na tela — barra de 140% seria mentira. */
$estourado = $comDesafio('artilheiro', $gols);
$estourado['stats']['Garoto']['gols'] = 99;
ok('o progresso mostrado para no alvo',
   futCarreiraDesafios($estourado)[0]['progresso'] === $catD['artilheiro']['alvo']);

/* Desafio de um catálogo antigo some em silêncio em vez de quebrar a tela. */
$fantasma = $baseD;
$fantasma['desafios'] = [['id' => 'desafio_que_nao_existe_mais', 'feito' => false]];
ok('desafio de catálogo velho não quebra nada', futCarreiraDesafios($fantasma) === []);

ok('o prêmio vira frase', futDesafioTextoDoPremio(['tipo' => 'reputacao', 'valor' => 5]) === '+5 de reputação');

/* O PRÊMIO NÃO PODE PAGAR PRA SEMPRE. Save com `desafios` em [] (que é como
   o ano novo e o save novo ficam) precisa sortear e GRAVAR o feito — senão
   nada marca nada e a conferência da rodada seguinte paga tudo de novo. */
$vazio = futDesafioRegistrarVenda($baseD, 25.0);
$vazio['desafios'] = [];   // lista vazia, não ausente
$v1 = futDesafiosConferir($vazio);
$pagouAlgo = count($v1['pagos']);
ok('com a lista vazia, o sorteio acontece e o feito é gravado',
   !empty($v1['estado']['desafios']), count($v1['estado']['desafios'] ?? []) . ' desafios');
$v2 = futDesafiosConferir($v1['estado']);
ok('e a rodada seguinte não paga tudo de novo', $v2['pagos'] === [],
   $pagouAlgo . ' na primeira, ' . count($v2['pagos']) . ' na segunda');
ok('nem o caixa nem a moral andam na segunda',
   abs($v2['estado']['caixa'] - $v1['estado']['caixa']) < 0.01
   && (int)$v2['estado']['elenco'][0]['moral'] === (int)$v1['estado']['elenco'][0]['moral']);
// ═════════════════════════════════════════════════════════════════════
echo "\n" . str_repeat('═', 60) . "\n";
printf("%d testes, %d falha(s)\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
