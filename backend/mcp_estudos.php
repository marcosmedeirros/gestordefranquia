<?php
/**
 * AS FERRAMENTAS DO MCP PARA OS ESTUDOS DE JOGADORES (marcos-estudos.php).
 *
 *   estudo_jogador  LÊ. A ficha de uma vida (ou de um nome, com todas as vidas e a média): pico, peak
 *                   start/end, aposentadoria, estimativa pro jogo, estatísticas, tendências, nota e a
 *                   carreira temporada a temporada. É o mesmo cálculo da página.
 *   estudo_editar   ESCREVE. Favoritar, lixeira, estimativa pro jogo, aposentadoria, nome, lenda, nota,
 *                   tendências, correção/adição/remoção de temporada, desfazer correções e criar jogador.
 *
 * Quem calcula tudo é backend/estudos_jogadores.php e quem grava é backend/estudos_ajustes.php — as
 * MESMAS funções da página, com as mesmas validações. Por isso aqui editar nunca encosta no histórico
 * do jogo (player_season_log / player_season_stats): só na camada do Marcos.
 */

require_once __DIR__ . '/mcp_core.php';
require_once __DIR__ . '/estudos_jogadores.php';

/** As definições que entram no catálogo do MCP (mcp_tools.php). */
function mcpEstudoFerramentas(): array
{
    $peak = [
        'ponto' => ['type' => 'string', 'description' => 'Peak end: "queda" (idade da 1ª temporada em queda, padrão) ou "ultimo" (da última temporada no pico)'],
        'queda' => ['type' => 'integer', 'description' => 'Pontos de OVR abaixo do pico sustentado pra contar como queda (1 a 6, padrão 1)'],
        'atrib' => ['type' => 'integer', 'description' => 'Degraus de queda na soma das letras que também contam como queda (0 = só OVR, padrão)'],
        'plato' => ['type' => 'integer', 'description' => 'Pontos do pico sustentado que ainda contam como "no pico" pro peak start (0 a 3, padrão 1)'],
    ];
    return [
        [
            'name' => 'estudo_jogador',
            'description' => 'LÊ a base de estudos de jogadores do Marcos. Com um NOME: todas as vidas (carreiras) daquele jogador, o peak start/end e a aposentadoria de cada uma, as médias e a estimativa pro jogo. Com um ID (ou nome de um jogador de uma vida só): a ficha completa e a carreira temporada a temporada. Peak end = idade em que começou a cair; aposentadoria é deduzida no jogo (saiu do elenco no meio do sprint com 36+); a estimativa pro jogo vem da carreira real convertida pra escala do 2K.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'jogador' => ['type' => 'string', 'description' => 'Nome do jogador (ex.: "John Wall") ou o id de uma vida (ex.: "1234")'],
                'min_temporadas' => ['type' => 'integer', 'description' => 'No estudo por nome: só vidas com pelo menos N temporadas entram nas médias (padrão 3)'],
                'lendas' => ['type' => 'boolean', 'description' => 'No estudo por nome: incluir vidas de lenda nas médias (padrão: não)'],
            ] + $peak, 'required' => ['jogador']],
        ],
        [
            'name' => 'estudo_editar',
            'description' => 'ESCREVE na camada do Marcos (nunca no histórico do jogo). acao: favoritar | desfavoritar | excluir (lixeira) | restaurar | estimativa (peak end e aposentadoria na ESCALA DO JOGO, por nome: peak end ~30–38, aposentadoria 36+; vazio nos dois = deixar em branco) | ajustar (novo_nome, lenda, nota, tshot, ttouch, aposentadoria — só o que vier) | temporada (corrige uma temporada) | adicionar_temporada | remover_temporada | reverter (desfaz as correções) | nova_vida (cria jogador). Se o nome tiver várias vidas, a ação por vida pede o id.',
            'inputSchema' => ['type' => 'object', 'properties' => [
                'acao' => ['type' => 'string', 'enum' => ['favoritar', 'desfavoritar', 'excluir', 'restaurar', 'estimativa', 'ajustar', 'temporada', 'adicionar_temporada', 'remover_temporada', 'reverter', 'nova_vida']],
                'jogador' => ['type' => 'string', 'description' => 'Nome ou id da vida (em nova_vida, o nome do jogador novo)'],
                'peak_end' => ['type' => ['integer', 'null'], 'description' => 'estimativa: idade do peak end na escala do jogo (null/vazio = em branco)'],
                'aposentadoria' => ['type' => ['integer', 'null'], 'description' => 'estimativa: idade da aposentadoria na escala do jogo. ajustar: aposentadoria DESTA vida no jogo (null = volta a deduzir)'],
                'observacao' => ['type' => 'string', 'description' => 'estimativa: observação sobre a estimativa'],
                'novo_nome' => ['type' => 'string', 'description' => 'ajustar: nome corrigido (vazio = volta ao nome do jogo)'],
                'lenda' => ['type' => 'string', 'description' => 'ajustar: "sim", "nao" ou "auto" (segue o jogo)'],
                'nota' => ['type' => 'string', 'description' => 'ajustar: a nota do Marcos sobre o jogador (vazio apaga)'],
                'tshot' => ['type' => ['integer', 'null'], 'description' => 'ajustar: T/SHOT digitado (0–99; null apaga)'],
                'ttouch' => ['type' => ['integer', 'null'], 'description' => 'ajustar: T/TOUCH digitado (0–99; null apaga)'],
                'temporada' => ['type' => 'integer', 'description' => 'temporada / adicionar_temporada / remover_temporada: o NÚMERO da temporada (ex.: 12)'],
                'campos' => ['type' => 'object', 'description' => 'temporada / adicionar_temporada: ovr, idade, pos (PG..C), liga, time, ano, letras {IN, MID, 3PT, "POST D", "PER D", PLAY, REB, ATHL, IQ, POT: "A+".."F"}, stats {jogos, min, pts, reb, ast, stl, blk, fg}'],
                'motivo' => ['type' => 'string', 'description' => 'Por que está mudando (vai pro log)'],
            ], 'required' => ['acao', 'jogador']],
        ],
    ];
}

function mcpEstudoEscreve(string $nome): bool
{
    return $nome === 'estudo_editar';
}

/** Roda uma função dos estudos e devolve erro de validação como erro de uso (o assistente lê e corrige). */
function mcpEstudoSeguro(callable $fn)
{
    try { return $fn(); }
    catch (InvalidArgumentException $e) { throw new McpErro($e->getMessage()); }
}

/* ── Achar a vida ──────────────────────────────────────────────────────── */

/**
 * Quem é "este jogador": um id, ou um nome (sem diferenciar maiúscula/acento de ponta), já com os nomes
 * corrigidos pelo Marcos. Devolve [id => ['excluido' => bool]]. Vida na lixeira entra (pra restaurar).
 *
 * @return array<int,array{excluido:bool}>
 */
function mcpEstudoAcharVidas(PDO $pdo, string $busca): array
{
    $busca = trim($busca);
    if ($busca === '') throw new McpErro('Diga o jogador (nome ou id).');
    $meta = estudosMeta($pdo);
    if (ctype_digit($busca)) return [(int)$busca => ['excluido' => !empty($meta[(int)$busca]['excluido'])]];

    $chave = estudosChaveNome($busca);
    $cand = [];   // pid => nome efetivo
    foreach ($pdo->query("SELECT DISTINCT player_id, player_name FROM player_season_log")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $pid = (int)$r['player_id'];
        $cand[$pid] = estudosNomeEfetivo($pid, (string)$r['player_name'], $meta);
    }
    foreach ($meta as $pid => $m) if (trim((string)($m['nome'] ?? '')) !== '') $cand[(int)$pid] = trim($m['nome']);
    $out = [];
    foreach ($cand as $pid => $nome) {
        if (estudosChaveNome($nome) === $chave) $out[$pid] = ['excluido' => !empty($meta[$pid]['excluido'])];
    }
    if ($out) return $out;

    // Nada exato: sugere parecidos.
    $parecidos = [];
    foreach ($cand as $nome) if ($chave !== '' && str_contains(estudosChaveNome($nome), $chave)) $parecidos[$nome] = true;
    $dica = $parecidos ? ' Parecidos: ' . implode(', ', array_slice(array_keys($parecidos), 0, 8)) . '.' : '';
    throw new McpErro("Nenhum jogador com o nome \"$busca\" nos estudos.$dica");
}

/** Uma vida só: se o nome tem várias, lista e pede o id. */
function mcpEstudoUmaVida(PDO $pdo, string $busca): int
{
    $vidas = mcpEstudoAcharVidas($pdo, $busca);
    if (count($vidas) === 1) {
        $id = (int)array_key_first($vidas);
        if (!estudosVida($pdo, $id)) throw new McpErro("Não achei a vida #$id.");   // não cria linha na camada pra id que não existe
        return $id;
    }
    $c = estudosCarregar($pdo, array_keys($vidas), null, 'resumo');
    $linhas = [];
    foreach ($vidas as $pid => $info) {
        $ts = $c['log'][$pid] ?? [];
        $linhas[] = "  #$pid · " . ($ts ? $ts[0]['league'] . ' · T' . $ts[0]['season_number'] . '–T' . $ts[count($ts) - 1]['season_number']
            . ' · pico ' . max(array_map(fn($t) => (int)$t['ovr'], $ts)) : 'sem temporadas') . ($info['excluido'] ? ' · na lixeira' : '');
    }
    throw new McpErro("\"$busca\" tem " . count($vidas) . " vidas — diga qual pelo id:\n" . implode("\n", $linhas));
}

/* ── Texto ─────────────────────────────────────────────────────────────── */

function mcpEstN($x, int $d = 1): string
{
    if ($x === null || $x === '') return '—';
    $s = number_format((float)$x, $d, '.', '');
    return str_contains($s, '.') ? rtrim(rtrim($s, '0'), '.') : $s;
}

/** A frase da aposentadoria e da estimativa (compartilhada pela ficha e pelo estudo por nome). */
function mcpEstApos(?int $ap, string $estado): string
{
    return match (true) {
        $ap !== null && $estado === 'manual' => "$ap anos (digitada pelo Marcos)",
        $ap !== null => "$ap anos (deduzida: saiu do elenco no meio do sprint com 36+)",
        $estado === 'ativo' => 'ainda joga (está num elenco)',
        default => '— (a vida acabou sem dar pra saber se aposentou)',
    };
}

function mcpEstReal(?array $r): string
{
    if (!$r) return 'sem estimativa (jogador jovem ou desconhecido — esperar o jogo mostrar)';
    $de = $r['fonte'] === 'manual' ? 'digitada pelo Marcos' : 'carreira real ' . mcpEstN($r['peak_end_real'], 0) . ' / ' . mcpEstN($r['aposent_real'], 0) . ' convertida';
    return 'peak end ~' . mcpEstN($r['peak_end'], 0) . ' · aposentadoria ~' . mcpEstN($r['aposent'], 0) . " ($de)" . ($r['nota'] ? ' — ' . $r['nota'] : '');
}

/** A ficha de uma vida, em texto. */
function mcpEstudoTextoVida(array $v): string
{
    $m = $v['m']; $mt = $v['meta']; $h = $v['hoje'];
    $tags = [];
    if ($mt['lenda']) $tags[] = 'LENDA' . ($mt['lenda_manual'] !== null ? ' (marcação do Marcos)' : '');
    if ($mt['favorito']) $tags[] = 'FAVORITO';
    if ($mt['excluido']) $tags[] = 'NA LIXEIRA';
    if ($mt['manual']) $tags[] = 'criado pelo Marcos';
    if ($mt['nome_corrigido']) $tags[] = 'renomeado (jogo: ' . $mt['nome_original'] . ')';
    if (!empty($m['festiva'])) $tags[] = '99 FESTIVO (fora de todas as métricas)';
    $onde = $h ? "{$h['time']} · {$h['liga']} · {$h['pos']} · {$h['idade']} anos · OVR {$h['ovr']}" : 'fora dos elencos hoje';
    $o = "{$v['nome']} (#{$v['id']}) · $onde" . ($tags ? ' · ' . implode(' · ', $tags) : '') . "\n";
    if (($m['n'] ?? 0) === 0 || !empty($m['festiva'])) {
        $o .= !empty($m['festiva']) ? "Uma única temporada com OVR 99 (temporada festiva): fica fora das métricas.\n" : "Sem temporadas registradas.\n";
    } else {
        $o .= "Carreira: {$m['n']} temporada(s) (T{$m['temporada_ini']}–T{$m['temporada_fim']}, {$m['ano_ini']}–{$m['ano_fim']}) · OVR {$m['ovr_ini']}→{$m['ovr_fim']} · idade {$m['idade_ini']}→{$m['idade_fim']}\n";
        $o .= "Pico {$m['pico']} (idade {$m['idade_pico']})" . ($m['fluke'] ? ' ⚑ pico de uma temporada só' : '') . " · sustentado {$m['pico_sust']} (idade {$m['idade_pico_sust']})"
            . " · anos 85+: {$m['anos_85']} · 90+: {$m['anos_90']} · queda do pico ao fim: {$m['queda']}\n";
        $o .= 'Peak start ' . $m['peak_start'] . ' → peak end ' . ($m['peak_end'] === null ? 'ainda não começou a cair' : $m['peak_end'])
            . ($m['peak_end'] === null ? '' : " ({$m['anos_pico']} ano(s) de pico, {$m['anos_ate_pico']} até o pico)") . "\n";
        $o .= 'Aposentadoria no jogo: ' . mcpEstApos($v['ap'], $v['ap_estado']) . "\n";
        $o .= 'Estimativa pro jogo: ' . mcpEstReal($v['real']) . "\n";
        $o .= "Entrada: {$m['idade_ini']} anos · POT inicial " . ($m['pot_ini'] ?? '—') . "\n";
        if (($m['jogos'] ?? 0) > 0) {
            $o .= "Estatísticas (média ponderada, {$m['jogos']} jogos em {$m['temporadas_com_stats']} temporada(s)): PTS " . mcpEstN($m['pts_pg']) . ' · REB ' . mcpEstN($m['reb_pg'])
                . ' · AST ' . mcpEstN($m['ast_pg']) . ' · STL ' . mcpEstN($m['stl_pg']) . ' · BLK ' . mcpEstN($m['blk_pg']) . ' · FG% ' . mcpEstN($m['fg']) . ' · MIN ' . mcpEstN($m['min']) . "\n";
            $o .= 'Melhor temporada (20+ jogos): PTS ' . mcpEstN($m['pico_pts_pg']) . ' · REB ' . mcpEstN($m['pico_reb_pg']) . ' · AST ' . mcpEstN($m['pico_ast_pg']) . ' · FG% ' . mcpEstN($m['pico_fg']) . "\n";
        }
        if (!empty($m['premios'])) $o .= 'Prêmios: ' . implode(', ', array_map(fn($t, $q) => "{$q}× $t", array_keys($m['premios']), $m['premios'])) . "\n";
    }
    $td = $v['tendencias']['dados'] ?? [];
    if ($td) $o .= 'Tendências: T/SHOT ' . ($td['T/SHOT'] ?? '—') . ' · T/TOUCH ' . ($td['T/TOUCH'] ?? '—') . "\n";
    if ($mt['nota']) $o .= 'Nota do Marcos: ' . $mt['nota'] . "\n";
    if ($v['outras_vidas']) $o .= 'Outras vidas: ' . implode(' · ', array_map(fn($x) => "#{$x['id']} {$x['liga']} ({$x['n']} temp., pico {$x['pico']})", $v['outras_vidas'])) . "\n";

    if ($v['temporadas']) {
        $o .= "\nTemporada a temporada (✎ = corrigida pelo Marcos, + = criada por ele):\n";
        $o .= "T | ano | liga | time | idade | pos | OVR | IN MID 3PT POSTD PERD PLAY REB ATHL IQ POT | J MIN PTS REB AST STL BLK FG%\n";
        foreach ($v['temporadas'] as $t) {
            $l = implode(' ', array_map(fn($k) => $t['letras'][$k] ?? '·', ['IN', 'MID', '3PT', 'POST D', 'PER D', 'PLAY', 'REB', 'ATHL', 'IQ', 'POT']));
            $s = $t['stats'];
            $st = $s ? implode(' ', [mcpEstN($s['jogos'], 0), mcpEstN($s['min']), mcpEstN($s['pts']), mcpEstN($s['reb']), mcpEstN($s['ast']), mcpEstN($s['stl']), mcpEstN($s['blk']), mcpEstN($s['fg'])]) : '—';
            $o .= "T{$t['temporada']} | {$t['ano']} | {$t['liga']} | {$t['time']} | {$t['idade']} | {$t['pos']} | {$t['ovr']} | $l | $st"
                . ($t['manual'] ? ' +' : ($t['ajustada'] ? ' ✎' : '')) . "\n";
        }
    }
    if ($v['removidas']) $o .= "\nTemporadas que o Marcos tirou dos estudos: " . implode(', ', array_map(fn($t) => 'T' . $t['temporada'], $v['removidas'])) . "\n";
    return $o;
}

/** O estudo por nome em texto: uma linha por vida e as médias. */
function mcpEstudoTextoNome(array $d): string
{
    $R = $d['resumo'];
    $o = "{$d['nome']} — {$d['vidas_total']} vida(s); {$d['vidas_usadas']} entram nas médias (mín. {$d['min_temporadas']} temporadas, sem 99 festivo"
       . ($d['com_lendas'] ? '' : ', sem lendas') . ")\n";
    if (!$d['com_lendas'] && $d['lendas_fora'] > 0) $o .= "{$d['lendas_fora']} vida(s) de lenda ficaram fora das médias (passe lendas=true pra contar).\n";
    $o .= 'Estimativa pro jogo: ' . mcpEstReal($d['real']) . "\n\n";
    $o .= "vida | liga | temp. | pico | sust. | peak start | peak end | aposentadoria | fim | PTS | T/SHOT | T/TOUCH | obs\n";
    $vs = $d['vidas'];
    usort($vs, fn($a, $b) => ($b['m']['pico_sust'] ?? 0) <=> ($a['m']['pico_sust'] ?? 0));
    foreach ($vs as $v) {
        $m = $v['m'];
        $sem = ($m['n'] ?? 0) === 0 || $v['festiva'];
        $obs = $v['festiva'] ? '99 festivo' : ($v['usada'] ? '' : ($v['lenda'] && !$d['com_lendas'] ? 'lenda: fora das médias' : 'fora das médias'));
        $o .= '#' . $v['id'] . ($v['fav'] ? '★' : '') . ' | ' . ($v['liga'] ?? '—') . ' | ' . ($m['n'] ?? 0) . ' | ' . ($sem ? '—' : $m['pico']) . ' | ' . ($sem ? '—' : $m['pico_sust'])
            . ' | ' . ($sem ? '—' : $m['peak_start']) . ' | ' . ($sem ? '—' : ($m['peak_end'] ?? 'no pico')) . ' | ' . ($v['ap'] ?? ($v['ap_estado'] === 'ativo' ? 'joga' : '—'))
            . ' | ' . ($sem ? '—' : $m['ovr_fim']) . ' | ' . mcpEstN($m['pts_pg'] ?? null) . ' | ' . mcpEstN($v['tshot'], 0) . ' | ' . mcpEstN($v['ttouch'], 0) . ' | ' . $obs . "\n";
    }
    $o .= "\nMÉDIAS DO JOGO (só as vidas usadas) — média / mediana / mín–máx (vidas):\n";
    foreach (['peak_start' => 'Peak start', 'peak_end' => 'Peak end (começou a cair)', 'aposentadoria' => 'Aposentadoria', 'anos_de_pico' => 'Anos de pico', 'anos_ate_o_pico' => 'Anos até o pico',
              'pico_sustentado' => 'Pico sustentado', 'idade_do_pico' => 'Idade do pico', 'pot_inicial' => 'POT na entrada (A+=12…F=0)', 'idade_inicial' => 'Idade de entrada',
              'temporadas' => 'Temporadas', 'pts' => 'PTS', 'reb' => 'REB', 'ast' => 'AST', 'fg' => 'FG%', 't_shot' => 'T/SHOT', 't_touch' => 'T/TOUCH'] as $k => $rot) {
        $x = $R[$k] ?? null;
        if (!$x || !$x['n']) { $o .= "  $rot: — (0)\n"; continue; }
        $o .= "  $rot: " . mcpEstN($x['media']) . ' / ' . mcpEstN($x['mediana']) . ' / ' . mcpEstN($x['min']) . '–' . mcpEstN($x['max']) . " ({$x['n']})\n";
    }
    return $o . "\nPra ver uma vida em detalhe: estudo_jogador com jogador=<id>.";
}

/* ── LER ───────────────────────────────────────────────────────────────── */

function mcpEstudoJogador(PDO $pdo, array $a): string
{
    @ini_set('memory_limit', '512M');
    $busca = trim((string)($a['jogador'] ?? ''));
    $vidas = mcpEstudoAcharVidas($pdo, $busca);
    $opc = estudosOpcoes($a);
    if (count($vidas) === 1 || ctype_digit($busca)) {
        $id = (int)array_key_first($vidas);
        $v = estudosVida($pdo, $id, $opc);
        if (!$v) throw new McpErro("Não achei a vida #$id.");
        return mcpEstudoTextoVida($v);
    }
    $nome = null;
    foreach ($vidas as $pid => $_) { $v = estudosVida($pdo, (int)$pid, $opc); if ($v) { $nome = $v['nome']; break; } }
    $min = max(1, min(12, (int)($a['min_temporadas'] ?? ESTUDOS_MIN_TEMP_COORTE)));
    $r = estudosNome($pdo, (string)$nome, $min, $opc, !empty($a['lendas']));
    if (!$r['ok']) throw new McpErro($r['erro']);
    $na = count($vidas) - $r['vidas_total'];
    return mcpEstudoTextoNome($r) . ($na > 0 ? "\n($na vida(s) na lixeira não entram.)" : '');
}

/* ── ESCREVER ──────────────────────────────────────────────────────────── */

/**
 * Edita UMA vida pelo editor de sempre: lê a ficha, deixa $mod mexer nos dados do editor e grava com
 * estudosSalvarVida (a mesma validação da página).
 *
 * @param callable(array &$dados, array $ficha): void $mod
 */
function mcpEstudoEditarVida(PDO $pdo, int $id, callable $mod): array
{
    $v = estudosVida($pdo, $id);
    if (!$v) throw new McpErro("Não achei a vida #$id.");
    $mt = $v['meta'];
    $dados = [
        'nome' => $mt['nome_corrigido'] ?? '',
        'lenda' => $mt['lenda_manual'] === null ? 'auto' : (string)$mt['lenda_manual'],
        'nota' => (string)($mt['nota'] ?? ''),
        'tshot' => $mt['tshot_manual'], 'ttouch' => $mt['ttouch_manual'], 'aposent' => $mt['aposent_manual'],
        'temporadas' => $v['temporadas'],
    ];
    $mod($dados, $v);
    mcpEstudoSeguro(fn() => estudosSalvarVida($pdo, $id, $dados));
    return estudosVida($pdo, $id);
}

/** A linha da temporada (pelo número) dentro dos dados do editor. */
function mcpEstudoAcharTemporada(array $dados, int $numero): int
{
    $achou = [];
    foreach ($dados['temporadas'] as $i => $t) if ((int)$t['temporada'] === $numero) $achou[] = $i;
    if (!$achou) throw new McpErro("Essa vida não tem a temporada T$numero.");
    if (count($achou) > 1) throw new McpErro("Essa vida tem mais de uma linha na temporada T$numero.");
    return $achou[0];
}

/** Aplica os campos que vieram (ovr, idade, pos, liga, time, ano, letras, stats) numa linha de temporada. */
function mcpEstudoCamposNaTemporada(array $t, array $campos): array
{
    foreach (['ovr', 'idade', 'pos', 'liga', 'time', 'ano'] as $k) if (array_key_exists($k, $campos)) $t[$k] = $campos[$k];
    foreach ((array)($campos['letras'] ?? []) as $sigla => $nota) $t['letras'][$sigla] = $nota;
    if (isset($campos['stats']) && is_array($campos['stats'])) $t['stats'] = array_merge((array)($t['stats'] ?? []), $campos['stats']);
    return $t;
}

function mcpEstudoEditar(PDO $pdo, array $a): string
{
    @ini_set('memory_limit', '512M');
    $acao = (string)($a['acao'] ?? '');
    $busca = trim((string)($a['jogador'] ?? ''));
    $acoes = ['favoritar', 'desfavoritar', 'excluir', 'restaurar', 'estimativa', 'ajustar', 'temporada', 'adicionar_temporada', 'remover_temporada', 'reverter', 'nova_vida'];
    if (!in_array($acao, $acoes, true)) throw new McpErro('Ação desconhecida: "' . $acao . '". Use: ' . implode(', ', $acoes) . '.');

    if ($acao === 'nova_vida') {
        $id = mcpEstudoSeguro(fn() => estudosNovaVida($pdo, $busca));
        return "Criei \"$busca\" (#$id), já nos favoritos. Agora adicione as temporadas com acao=adicionar_temporada.";
    }

    if ($acao === 'estimativa') {
        // Por NOME: vale pra todas as vidas. Aceita id e usa o nome da vida.
        $nome = $busca;
        if (ctype_digit($busca)) { $v = estudosVida($pdo, (int)$busca); if (!$v) throw new McpErro("Não achei a vida #$busca."); $nome = $v['nome']; }
        else mcpEstudoAcharVidas($pdo, $busca);   // confirma que o nome existe
        mcpEstudoSeguro(fn() => estudosReaisSalvar($pdo, $nome, $a['peak_end'] ?? null, $a['aposentadoria'] ?? null, (string)($a['observacao'] ?? '')));
        $r = estudosReais($pdo)[estudosChaveNome($nome)] ?? null;
        return "Estimativa de $nome gravada (escala do jogo): " . mcpEstReal($r) . "\nVale pra todas as vidas de $nome.";
    }

    $id = mcpEstudoUmaVida($pdo, $busca);

    if ($acao === 'favoritar' || $acao === 'desfavoritar') {
        estudosFavoritar($pdo, $id, $acao === 'favoritar');
        $v = estudosVida($pdo, $id);
        return ($acao === 'favoritar' ? 'Favoritado: ' : 'Desfavoritado: ') . "{$v['nome']} (#$id).";
    }
    if ($acao === 'excluir' || $acao === 'restaurar') {
        estudosExcluir($pdo, $id, $acao === 'excluir');
        $v = estudosVida($pdo, $id);
        return $acao === 'excluir'
            ? "{$v['nome']} (#$id) foi pra lixeira dos estudos (o histórico do jogo não foi tocado). Pra voltar: acao=restaurar."
            : "{$v['nome']} (#$id) restaurado nos estudos.";
    }
    if ($acao === 'reverter') {
        estudosReverter($pdo, $id);
        return "Correções de #$id desfeitas: nome, lenda, tendências, aposentadoria e temporadas voltaram ao dado do jogo (favorito e lixeira não mudam).";
    }

    $mudou = [];
    $v = mcpEstudoEditarVida($pdo, $id, function (array &$d, array $ficha) use ($a, $acao, &$mudou) {
        if ($acao === 'ajustar') {
            if (array_key_exists('novo_nome', $a)) { $d['nome'] = (string)$a['novo_nome']; $mudou[] = 'nome'; }
            if (array_key_exists('lenda', $a)) {
                $l = mb_strtolower((string)$a['lenda']);
                if (!in_array($l, ['sim', 'nao', 'não', 'auto'], true)) throw new McpErro('lenda: use "sim", "nao" ou "auto".');
                $d['lenda'] = $l === 'sim' ? '1' : ($l === 'auto' ? 'auto' : '0');
                $mudou[] = 'lenda';
            }
            if (array_key_exists('nota', $a)) { $d['nota'] = (string)$a['nota']; $mudou[] = 'nota'; }
            foreach (['tshot', 'ttouch'] as $k) if (array_key_exists($k, $a)) { $d[$k] = $a[$k]; $mudou[] = $k; }
            if (array_key_exists('aposentadoria', $a)) { $d['aposent'] = $a['aposentadoria']; $mudou[] = 'aposentadoria'; }
            if (!$mudou) throw new McpErro('Nada pra ajustar: mande novo_nome, lenda, nota, tshot, ttouch ou aposentadoria.');
            return;
        }
        $num = (int)($a['temporada'] ?? 0);
        if ($num <= 0) throw new McpErro('Diga o número da temporada (ex.: temporada=12).');
        $campos = is_array($a['campos'] ?? null) ? $a['campos'] : [];
        if ($acao === 'temporada') {
            if (!$campos) throw new McpErro('Mande os campos a corrigir em "campos" (ex.: {"ovr": 90}).');
            $i = mcpEstudoAcharTemporada($d, $num);
            $d['temporadas'][$i] = mcpEstudoCamposNaTemporada($d['temporadas'][$i], $campos);
            $mudou[] = "T$num";
        } elseif ($acao === 'remover_temporada') {
            $i = mcpEstudoAcharTemporada($d, $num);
            array_splice($d['temporadas'], $i, 1);
            $mudou[] = "T$num removida";
        } elseif ($acao === 'adicionar_temporada') {
            foreach ($d['temporadas'] as $t) if ((int)$t['temporada'] === $num) throw new McpErro("Essa vida já tem a temporada T$num — use acao=temporada pra corrigir.");
            foreach (['ovr', 'idade'] as $k) if (!isset($campos[$k])) throw new McpErro("Falta \"$k\" em campos (toda temporada precisa de ovr e idade).");
            $ult = $d['temporadas'] ? end($d['temporadas']) : null;
            $nova = ['season_id' => null, 'temporada' => $num, 'ano' => $ult ? $ult['ano'] + ($num - $ult['temporada']) : 0, 'liga' => $ult['liga'] ?? 'ELITE',
                     'time' => $ult['time'] ?? '', 'idade' => 0, 'ovr' => 0, 'pos' => $ult['pos'] ?? '', 'letras' => [], 'stats' => null];
            $d['temporadas'][] = mcpEstudoCamposNaTemporada($nova, $campos);
            $mudou[] = "T$num adicionada";
        } else {
            throw new McpErro('Ação desconhecida: ' . $acao);
        }
    });
    return 'Gravado em ' . $v['nome'] . " (#$id): " . implode(', ', $mudou) . ".\n\n" . implode("\n", array_slice(explode("\n", mcpEstudoTextoVida($v)), 0, 8));
}
