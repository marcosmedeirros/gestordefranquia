<?php
/**
 * A FERRAMENTA DE LISTA DOS ESTUDOS NO MCP: estudo_lista.
 *
 * Responde "os 20 maiores peak end tardios", "quem da ELITE segurou 90+ e ainda joga", "os que entraram
 * jovens e estouraram rápido"… com os mesmos campos, filtros e ordenação da página (marcos-estudos.php).
 * Só leitura. Quem calcula é backend/estudos_jogadores.php (estudosLista).
 */

require_once __DIR__ . '/mcp_estudos.php';

/** As definições que entram no catálogo (mcp_tools.php). */
function mcpEstudoListaFerramenta(): array
{
    return [
        'name' => 'estudo_lista',
        'description' => 'LÊ uma lista dos estudos de jogadores do Marcos, com filtros, ordenação e resumo estatístico (média/mediana/mín/máx de cada coluna). Uma linha por vida (carreira). Use pra rankings e pesquisas: "20 maiores peak end", "ELITE com pico sustentado 90+ que ainda joga", "quem aposentou mais cedo". Campos: ' . implode(', ', array_keys(mcpEstudoCampos())) . '. Peak end e aposentadoria saem combinados (dado do jogo primeiro; "~" = estimativa pro jogo); pe_jogo e ap_jogo são só o dado medido. Lendas ficam ocultas por padrão.',
        'inputSchema' => ['type' => 'object', 'properties' => [
            'grupo' => ['type' => 'string', 'enum' => ['elite', 'outras', 'historico', 'favoritos', 'lixeira'], 'description' => 'elite = ativos na ELITE; outras = ativos em NEXT/RISE/ROOKIE; historico = todo mundo (padrão); favoritos; lixeira'],
            'ordem' => ['type' => 'string', 'description' => 'Campo pra ordenar (padrão ovr). Ex.: pe, ap, pks, ps, apk, pts, tshot'],
            'direcao' => ['type' => 'string', 'enum' => ['asc', 'desc'], 'description' => 'Padrão: desc pra número, asc pra texto'],
            'limite' => ['type' => 'integer', 'description' => 'Quantas linhas mostrar (padrão 20, máx 100)'],
            'colunas' => ['type' => 'array', 'items' => ['type' => 'string'], 'description' => 'Campos a mostrar. Padrão: pos, idade, ovr, pico, pks, ps, pe, ap, nt'],
            'filtros' => ['type' => 'array', 'description' => 'Condições (todas valem). Cada uma: {campo, op, valor, valor2?}. op: = != > >= < <= entre (valor e valor2) | contem = != (texto) | vazio preenchido. Ex.: {"campo":"pe","op":">=","valor":33}',
                'items' => ['type' => 'object', 'properties' => [
                    'campo' => ['type' => 'string'], 'op' => ['type' => 'string'], 'valor' => ['type' => ['number', 'string']], 'valor2' => ['type' => 'number'],
                ], 'required' => ['campo', 'op']]],
            'busca' => ['type' => 'string', 'description' => 'Texto no nome, time ou nota'],
            'liga' => ['type' => 'string', 'description' => 'ELITE, NEXT, RISE ou ROOKIE (liga atual, ou a última da vida)'],
            'posicao' => ['type' => 'string', 'description' => 'PG, SG, SF, PF ou C'],
            'lendas' => ['type' => 'string', 'enum' => ['ocultar', 'mostrar', 'so'], 'description' => 'Padrão: ocultar'],
            'so_ativos' => ['type' => 'boolean', 'description' => 'Só quem está num elenco hoje'],
            'com_stats' => ['type' => 'boolean', 'description' => 'Só quem tem estatística'],
            'sem_vidas_curtas' => ['type' => 'boolean', 'description' => 'Tira vidas de 1 temporada e 99 festivos'],
            'so_ajustados' => ['type' => 'boolean', 'description' => 'Só os que o Marcos corrigiu'],
            'so_com_estimativa' => ['type' => 'boolean', 'description' => 'Só quem tem estimativa pro jogo'],
            'resumo' => ['type' => 'boolean', 'description' => 'Resumo estatístico do filtro (padrão true)'],
            'ponto' => ['type' => 'string', 'description' => 'Definição do peak end: "queda" (padrão) ou "ultimo"'],
            'queda' => ['type' => 'integer', 'description' => 'Pontos abaixo do pico sustentado que contam como queda (1 a 6)'],
            'atrib' => ['type' => 'integer', 'description' => 'Degraus de queda nos atributos que também contam (0 = só OVR)'],
            'plato' => ['type' => 'integer', 'description' => 'Tolerância do peak start (0 a 3)'],
        ]],
    ];
}

/**
 * Os campos que dá pra mostrar, ordenar e filtrar: a MESMA lista de colunas da página.
 * Peak end e aposentadoria vêm combinados (dado do jogo primeiro, senão a estimativa pro jogo, marcada
 * com ~ na saída); `pe_jogo` e `ap_jogo` são só o dado medido.
 *
 * @return array<string,array{0:string,1:callable,2:string,3:int}> chave => [rótulo, leitor(linha), 'num'|'txt', casas decimais]
 */
function mcpEstudoCampos(): array
{
    $m = fn(string $k) => fn(array $l) => $l['m'][$k] ?? null;
    return [
        'pos' => ['Pos', fn($l) => $l['pos'], 'txt', 0], 'liga' => ['Liga', fn($l) => $l['liga'], 'txt', 0], 'time' => ['Time', fn($l) => $l['time'], 'txt', 0],
        'nome' => ['Jogador', fn($l) => $l['nome'], 'txt', 0], 'nota' => ['Nota', fn($l) => $l['nota'], 'txt', 0],
        'idade' => ['Idade', fn($l) => $l['idade'], 'num', 0], 'ovr' => ['OVR', fn($l) => $l['ovr'], 'num', 0],
        'ent' => ['Entrada', $m('idade_ini'), 'num', 0],
        'pot' => ['POT ini', fn($l) => ($p = $l['m']['pot_ini'] ?? null) ? (ESTUDOS_NOTAS[$p] ?? null) : null, 'num', 0],
        'nt' => ['Temp.', fn($l) => $l['festiva'] ? null : ($l['m']['n'] ?? null), 'num', 0], 'vidas' => ['Vidas', fn($l) => $l['vidas'], 'num', 0],
        'oini' => ['OVR ini', $m('ovr_ini'), 'num', 0], 'pico' => ['Pico', $m('pico'), 'num', 0], 'pks' => ['Pico sust.', $m('pico_sust'), 'num', 0],
        'ipk' => ['Idade pico', $m('idade_pico_sust'), 'num', 0], 'a85' => ['85+', $m('anos_85'), 'num', 0], 'a90' => ['90+', $m('anos_90'), 'num', 0],
        'fim' => ['Fim', $m('ovr_fim'), 'num', 0], 'qd' => ['Queda', $m('queda'), 'num', 0],
        'ps' => ['Peak start', $m('peak_start'), 'num', 0],
        'pe' => ['Peak end', fn($l) => ($l['m']['peak_end'] ?? null) ?? $l['pe_real'], 'num', 0], 'pe_jogo' => ['Peak end (jogo)', $m('peak_end'), 'num', 0],
        'apk' => ['Anos pico', $m('anos_pico'), 'num', 0], 'aap' => ['Anos até pico', $m('anos_ate_pico'), 'num', 0],
        'ap' => ['Aposent.', fn($l) => $l['ap'] ?? $l['ap_real'], 'num', 0], 'ap_jogo' => ['Aposent. (jogo)', fn($l) => $l['ap'], 'num', 0],
        'jogos' => ['Jogos', $m('jogos'), 'num', 0], 'min' => ['MIN', $m('min'), 'num', 1], 'pts' => ['PTS', $m('pts_pg'), 'num', 1], 'reb' => ['REB', $m('reb_pg'), 'num', 1],
        'ast' => ['AST', $m('ast_pg'), 'num', 1], 'stl' => ['STL', $m('stl_pg'), 'num', 1], 'blk' => ['BLK', $m('blk_pg'), 'num', 1], 'fg' => ['FG%', $m('fg'), 'num', 1],
        'ppts' => ['Pico PTS', $m('pico_pts_pg'), 'num', 1], 'preb' => ['Pico REB', $m('pico_reb_pg'), 'num', 1], 'past' => ['Pico AST', $m('pico_ast_pg'), 'num', 1],
        'pstl' => ['Pico STL', $m('pico_stl_pg'), 'num', 1], 'pblk' => ['Pico BLK', $m('pico_blk_pg'), 'num', 1], 'pfg' => ['Pico FG%', $m('pico_fg'), 'num', 1],
        'pr' => ['Prêm.', $m('premios_total'), 'num', 0], 'tshot' => ['T/SHOT', fn($l) => $l['tshot'], 'num', 0], 'ttouch' => ['T/TOUCH', fn($l) => $l['ttouch'], 'num', 0],
    ];
}

/** Uma condição do filtro bate com a linha? */
function mcpEstudoCasa(array $campos, array $l, array $f): bool
{
    $c = $campos[$f['campo']];
    $v = ($c[1])($l);
    $op = $f['op'];
    if ($op === 'vazio') return $v === null || $v === '';
    if ($op === 'preenchido') return !($v === null || $v === '');
    if ($c[2] === 'txt') {
        $q = estudosChaveNome((string)($f['valor'] ?? ''));
        $t = estudosChaveNome((string)($v ?? ''));
        return match ($op) {
            'contem' => $q === '' || str_contains($t, $q), '=' => $t === $q, '!=' => $t !== $q,
            default => throw new McpErro("Operador \"$op\" não serve pra campo de texto ({$f['campo']}): use contem, =, !=, vazio ou preenchido."),
        };
    }
    $x = is_numeric($f['valor'] ?? null) ? (float)$f['valor'] : null;
    if ($x === null) throw new McpErro("Filtro em {$f['campo']}: informe um número em \"valor\".");
    if ($v === null) return $op === '!=';
    return match ($op) {
        '=' => $v == $x, '!=' => $v != $x, '>' => $v > $x, '>=' => $v >= $x, '<' => $v < $x, '<=' => $v <= $x,
        'entre' => is_numeric($f['valor2'] ?? null) ? ($v >= min($x, (float)$f['valor2']) && $v <= max($x, (float)$f['valor2'])) : throw new McpErro('Filtro "entre": mande valor e valor2.'),
        default => throw new McpErro("Operador \"$op\" desconhecido: use =, !=, >, >=, <, <=, entre, contem, vazio ou preenchido."),
    };
}

function mcpEstudoLista(PDO $pdo, array $a): string
{
    @ini_set('memory_limit', '512M');
    $grupo = (string)($a['grupo'] ?? 'historico');
    if (!in_array($grupo, ['elite', 'outras', 'historico', 'favoritos', 'lixeira'], true)) throw new McpErro('grupo: elite, outras, historico, favoritos ou lixeira.');
    $campos = mcpEstudoCampos();
    $linhas = estudosLista($pdo, $grupo, estudosOpcoes($a));
    $total = count($linhas);

    $lendas = (string)($a['lendas'] ?? 'ocultar');
    if (!in_array($lendas, ['ocultar', 'mostrar', 'so'], true)) throw new McpErro('lendas: ocultar (padrão), mostrar ou so.');
    $q = estudosChaveNome((string)($a['busca'] ?? ''));
    $liga = strtoupper(trim((string)($a['liga'] ?? '')));
    $pos = strtoupper(trim((string)($a['posicao'] ?? '')));
    $filtros = [];
    foreach ((array)($a['filtros'] ?? []) as $f) {
        if (!is_array($f) || !isset($campos[$f['campo'] ?? ''])) throw new McpErro('Filtro com campo inválido: "' . (is_array($f) ? ($f['campo'] ?? '') : '') . '". Campos: ' . implode(', ', array_keys($campos)) . '.');
        $f['op'] = (string)($f['op'] ?? '=');
        $filtros[] = $f;
    }
    $selecao = $grupo === 'favoritos' || $grupo === 'lixeira';
    $linhas = array_values(array_filter($linhas, function (array $l) use ($selecao, $lendas, $q, $liga, $pos, $a, $campos, $filtros) {
        if (!$selecao) {
            if ($lendas === 'ocultar' && $l['lenda']) return false;
            if ($lendas === 'so' && !$l['lenda']) return false;
        }
        if ($q !== '' && !str_contains(estudosChaveNome($l['nome'] . ' ' . ($l['time'] ?? '') . ' ' . ($l['nota'] ?? '')), $q)) return false;
        if ($liga !== '' && strtoupper((string)$l['liga']) !== $liga) return false;
        if ($pos !== '' && strtoupper((string)$l['pos']) !== $pos) return false;
        if (!empty($a['so_ativos']) && !$l['ativo']) return false;
        if (!empty($a['com_stats']) && !(($l['m']['jogos'] ?? 0) > 0)) return false;
        if (!empty($a['sem_vidas_curtas']) && (($l['m']['n'] ?? 0) <= 1 || $l['festiva'])) return false;
        if (!empty($a['so_ajustados']) && !$l['ajustada']) return false;
        if (!empty($a['so_com_estimativa']) && $l['pe_real'] === null && $l['ap_real'] === null) return false;
        foreach ($filtros as $f) if (!mcpEstudoCasa($campos, $l, $f)) return false;
        return true;
    }));

    $ordem = (string)($a['ordem'] ?? 'ovr');
    if (!isset($campos[$ordem])) throw new McpErro("ordem inválida: \"$ordem\". Campos: " . implode(', ', array_keys($campos)) . '.');
    $txt = $campos[$ordem][2] === 'txt';
    $dir = strtolower((string)($a['direcao'] ?? ($txt ? 'asc' : 'desc'))) === 'asc' ? 1 : -1;
    usort($linhas, function ($x, $y) use ($campos, $ordem, $dir, $txt) {
        $vx = ($campos[$ordem][1])($x); $vy = ($campos[$ordem][1])($y);
        if ($vx === null && $vy === null) return strcmp($x['nome'], $y['nome']);
        if ($vx === null) return 1;      // vazio vai sempre pro fim
        if ($vy === null) return -1;
        $r = $txt ? strcmp((string)$vx, (string)$vy) : $vx <=> $vy;
        return $r ? $dir * $r : strcmp($x['nome'], $y['nome']);
    });

    $cols = $a['colunas'] ?? ['pos', 'idade', 'ovr', 'pico', 'pks', 'ps', 'pe', 'ap', 'nt'];
    if (!is_array($cols)) throw new McpErro('colunas: uma lista de campos.');
    foreach ($cols as $k) if (!isset($campos[$k])) throw new McpErro("Coluna inválida: \"$k\". Campos: " . implode(', ', array_keys($campos)) . '.');
    if (!in_array($ordem, $cols, true) && $ordem !== 'nome') $cols[] = $ordem;

    $limite = max(1, min(100, (int)($a['limite'] ?? 20)));
    $n = count($linhas);
    if ($n === 0) return "Nenhum jogador com esses filtros (de $total na aba $grupo).";

    $fmt = function (string $k, array $l) use ($campos): string {
        $v = ($campos[$k][1])($l);
        if ($v === null || $v === '') return ($k === 'ap' && $l['ap_estado'] === 'ativo') ? 'joga' : '—';
        $s = $campos[$k][2] === 'txt' ? (string)$v : mcpEstN($v, $campos[$k][3]);
        if ($k === 'pot') $s = (string)(array_search((int)$v, ESTUDOS_NOTAS, true) ?: $s);
        if ($k === 'pe' && ($l['m']['peak_end'] ?? null) === null) $s = '~' . $s;                  // estimativa pro jogo
        if ($k === 'ap' && $l['ap'] === null) $s = '~' . $s;
        if ($k === 'ap' && $l['ap'] !== null && $l['ap_estado'] === 'aposentou') $s .= '?';        // deduzida
        return $s;
    };
    $o = "ESTUDOS · $grupo · $n de $total jogador(es)" . (!$selecao && $lendas === 'ocultar' ? ' (lendas ocultas)' : '')
       . " · ordenado por {$campos[$ordem][0]} " . ($dir > 0 ? '↑' : '↓') . "\n";
    $o .= 'id | jogador | liga | ' . implode(' | ', array_map(fn($k) => $campos[$k][0], $cols)) . "\n";
    foreach (array_slice($linhas, 0, $limite) as $l) {
        $tags = ($l['fav'] ? '★' : '') . ($l['lenda'] ? ' [lenda]' : '') . ($l['ajustada'] ? ' ✎' : '');
        $o .= "#{$l['id']} | {$l['nome']}$tags | " . ($l['liga'] ?? '—') . ' | ' . implode(' | ', array_map(fn($k) => $fmt($k, $l), $cols)) . "\n";
    }
    if ($n > $limite) $o .= "... mostrando $limite de $n (aumente \"limite\", até 100, ou filtre mais)\n";
    $o .= "~ = estimativa pro jogo (o jogo ainda não mostra) · ? = aposentadoria deduzida · ✎ = corrigido pelo Marcos · ★ = favorito\n";

    if (($a['resumo'] ?? true) !== false) {
        $o .= "\nResumo do filtro (todos os $n): coluna — jogadores com valor · média / mediana / mín–máx\n";
        foreach ($cols as $k) {
            if ($campos[$k][2] !== 'num') continue;
            $vs = array_values(array_filter(array_map(fn($l) => ($campos[$k][1])($l), $linhas), fn($v) => $v !== null));
            if (!$vs) { $o .= "  {$campos[$k][0]}: —\n"; continue; }
            sort($vs);
            $med = count($vs) % 2 ? $vs[intdiv(count($vs), 2)] : ($vs[count($vs) / 2 - 1] + $vs[count($vs) / 2]) / 2;
            $d = $campos[$k][3];
            $o .= "  {$campos[$k][0]}: " . count($vs) . ' · ' . mcpEstN(array_sum($vs) / count($vs), max($d, 1)) . ' / ' . mcpEstN($med, max($d, 1)) . ' / ' . mcpEstN($vs[0], $d) . '–' . mcpEstN(end($vs), $d) . "\n";
        }
    }
    return $o;
}
