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
ok('Série B com 20', count(futClubesDaDivisao('BR2')) === 20);

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
echo "\n" . str_repeat('═', 60) . "\n";
printf("%d testes, %d falha(s)\n", $total, $falhas);
exit($falhas > 0 ? 1 : 0);
