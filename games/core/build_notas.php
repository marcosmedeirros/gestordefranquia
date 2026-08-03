<?php
/**
 * Build-A-Player — as notas de cada lenda, por atributo.
 *
 * A base de jogadores (hoopgrid_players) tem estatística de carreira, prêmios,
 * títulos, posição, altura e peso — mas NÃO tem nota por atributo, que é o que
 * o jogo precisa. Este arquivo deriva as dez notas a partir do que existe e
 * grava numa tabela própria.
 *
 * Por que gravar em vez de calcular na hora:
 *  - o jogo fica rápido (o giro lê uma linha, não recalcula nada);
 *  - a nota fica ESTÁVEL — a mesma lenda vale o mesmo em toda partida;
 *  - dá pra corrigir na mão quem a fórmula errar, e vai errar em alguns:
 *    derivar habilidade de média de carreira é aproximação, não verdade.
 *
 * A conversão pra letra é por PERCENTIL dentro do próprio elenco, não por
 * limiar fixo. Com limiar fixo, uma base só de lendas daria "todo mundo A" —
 * o percentil garante que a escala use a régua inteira, de F a S.
 */

require_once __DIR__ . '/build_dados.php';

/** Os dez slots do build, na mesma divisão do jogo de referência. */
function buildAtributos(): array
{
    return [
        'jump_shot'  => ['label' => 'Jump Shot',          'grupo' => 'SKILLS'],
        'finishing'  => ['label' => 'Finalização',        'grupo' => 'SKILLS'],
        'passing'    => ['label' => 'Passe',              'grupo' => 'SKILLS'],
        'handles'    => ['label' => 'Handles',            'grupo' => 'SKILLS'],
        'perimeter_d'=> ['label' => 'Defesa de Perímetro','grupo' => 'SKILLS'],
        'speed'      => ['label' => 'Velocidade',         'grupo' => 'PHYSICAL'],
        'bounce'     => ['label' => 'Impulsão',           'grupo' => 'PHYSICAL'],
        'size'       => ['label' => 'Tamanho',            'grupo' => 'PHYSICAL'],
        'iq'         => ['label' => 'QI de Jogo',         'grupo' => 'MENTAL'],
        'clutch'     => ['label' => 'Clutch/Liderança',   'grupo' => 'MENTAL'],
    ];
}

/**
 * Quantos jogadores caem em cada letra, em porcentagem do elenco.
 * Vai de F até S — pouca gente no topo, massa no meio, como deve ser.
 */
const BUILD_CURVA_PERCENTIL = [
    /* F */ 6, /* D */ 8, /* D+ */ 11, /* C */ 13, /* C+ */ 14, /* B- */ 14,
    /* B */ 12, /* B+ */ 9, /* A- */ 6, /* A */ 4, /* A+ */ 2, /* S */ 1,
];

function buildGarantirTabelaNotas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto || $pdo->inTransaction()) return;
    $pronto = true;

    $colunas = [];
    foreach (array_keys(buildAtributos()) as $chave) {
        $colunas[] = "`{$chave}` TINYINT NOT NULL DEFAULT 0";
    }

    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS build_notas (
            player_id INT NOT NULL PRIMARY KEY,
            posicao_grupo ENUM('GUARD','BIG') NOT NULL DEFAULT 'GUARD',
            " . implode(",\n            ", $colunas) . ",
            ovr TINYINT NOT NULL DEFAULT 0,
            ajustado_manual TINYINT(1) NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_bn_grupo (posicao_grupo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[build_notas] criar tabela: ' . $e->getMessage());
    }
}

/**
 * GUARD (PG/SG/SF) ou BIG (PF/C).
 *
 * A base costuma trazer só "Forward", que é ambíguo: Larry Bird e Karl Malone
 * ficam com o mesmo rótulo. A altura desempata — ala de 2,06m ou mais entra
 * como BIG. Center é sempre BIG e Guard é sempre GUARD, independente da altura.
 */
function buildGrupoDaPosicao(?string $posicao, ?string $altura = null): string
{
    $p = mb_strtolower((string)$posicao);
    if ($p === '') return 'GUARD';

    if (str_contains($p, 'center') || str_contains($p, 'pivô') || str_contains($p, 'pivo')) return 'BIG';
    if (str_contains($p, 'power forward')) return 'BIG';
    if (str_contains($p, 'guard')) return 'GUARD';

    if (str_contains($p, 'forward')) {
        // 81 polegadas = 6'9" (~2,06m).
        return buildAlturaEmPolegadas($altura) >= 81 ? 'BIG' : 'GUARD';
    }
    return 'GUARD';
}

/** Altura "6-8" ou "6'8\"" vira polegadas. 0 quando não dá pra ler. */
function buildAlturaEmPolegadas(?string $altura): int
{
    if (!$altura) return 0;
    if (preg_match('/(\d+)\s*[-\'’]\s*(\d+)/u', $altura, $m)) {
        return ((int)$m[1] * 12) + (int)$m[2];
    }
    if (preg_match('/^\d+$/', trim($altura))) return (int)$altura;
    return 0;
}

/** Conta quantas vezes um prêmio aparece na lista da base. */
function buildContaPremio(array $premios, string $chave): int
{
    $n = 0;
    foreach ($premios as $p) {
        if (stripos((string)$p, $chave) !== false) $n++;
    }
    return $n;
}

/**
 * Notas CRUAS de um jogador — números sem escala, só pra ordenar depois.
 *
 * Cada fórmula usa o sinal mais direto que a base oferece. Onde o sinal é
 * fraco (velocidade, impulsão), o peso da posição e da era carrega — é
 * assumidamente aproximado, e por isso existe o ajuste manual.
 */
function buildNotasCruas(array $j): array
{
    $stats = [];
    if (!empty($j['stats'])) {
        $decodificado = json_decode((string)$j['stats'], true);
        if (is_array($decodificado)) $stats = $decodificado;
    }
    $premios = [];
    if (!empty($j['premios'])) {
        $decodificado = json_decode((string)$j['premios'], true);
        if (is_array($decodificado)) $premios = $decodificado;
    }

    $gp  = max(1, (int)($stats['gp'] ?? 1));
    $por = fn($chave) => ((float)($stats[$chave] ?? 0)) / $gp; // total -> média

    $pts = (float)($j['pts_medio'] ?? $por('pts'));
    $reb = (float)($j['reb_medio'] ?? $por('reb'));
    $ast = (float)($j['ast_medio'] ?? $por('ast'));
    $stl = $por('stl');
    $blk = $por('blk');
    $tov = $por('tov');

    $tresPorJogo = $por('fg3m');
    $tresPct     = (float)($stats['fg3_pct'] ?? 0);
    $fgPct       = (float)($stats['fg_pct']  ?? 0);
    $ftPct       = (float)($stats['ft_pct']  ?? 0);

    $ehBig    = buildGrupoDaPosicao($j['posicao'] ?? null, $j['altura'] ?? null) === 'BIG';
    $altura   = buildAlturaEmPolegadas($j['altura'] ?? null);
    $peso     = (int)($j['peso'] ?? 0);
    $titulos  = (int)($j['titulos'] ?? 0);

    $mvp      = buildContaPremio($premios, 'MVP');
    $dpoy     = buildContaPremio($premios, 'DPOY');
    $allNba   = buildContaPremio($premios, 'ALLNBA');
    $allStar  = buildContaPremio($premios, 'ALLSTAR');

    return [
        // Arremesso: bolas de 3 por jogo pesam mais que o aproveitamento, pra
        // não premiar quem tentava duas por temporada. FT% entra como "toque".
        'jump_shot' => ($tresPorJogo * 12) + ($tresPct * 25) + ($ftPct * 18) + ($pts * 0.4),

        // Finalização: aproveitamento de quadra puxado pelo garrafão. Big com
        // FG% alto pontua muito aqui, e é o certo.
        'finishing' => ($fgPct * 60) + ($pts * 0.6) + ($ehBig ? 12 : 0) + ($blk * 2),

        'passing'   => ($ast * 9) + ($ehBig ? 0 : 3),

        // Handles: assistência com posse, penalizado por perda de bola.
        'handles'   => ($ast * 6) + ($ehBig ? -8 : 10) - ($tov * 3) + ($pts * 0.3),

        'perimeter_d' => ($stl * 22) + ($dpoy * 10) + ($ehBig ? -6 : 8) + ($allNba * 0.6),

        // Velocidade e impulsão são as mais fracas em sinal: a base não tem
        // nada atlético. Sobra posição, roubo/toco e um empurrão pra quem
        // jogou depois de 2000 (atleta moderno).
        'speed'     => ($ehBig ? 2 : 16) + ($stl * 14) + ($ast * 1.2)
                     + (buildEraModerna($j) ? 5 : 0),
        'bounce'    => ($blk * 10) + ($reb * 1.4) + ($ehBig ? 6 : 4)
                     + (buildEraModerna($j) ? 6 : 0),

        // Tamanho é o único exato: altura e peso vêm da base.
        'size'      => ($altura * 1.6) + ($peso * 0.12),

        // QI: reconhecimento da liga + cuidar da bola.
        'iq'        => ($mvp * 14) + ($allNba * 4) + ($allStar * 1.2) + ($ast * 1.5) - ($tov * 2),

        // Clutch: anel pesa mais que qualquer prêmio individual.
        'clutch'    => ($titulos * 9) + ($mvp * 8) + ($allStar * 1.5) + ($pts * 0.35),
    ];
}

/** Jogou de 2000 pra cá? Usado só como empurrão nos atributos atléticos. */
function buildEraModerna(array $j): bool
{
    $eras = [];
    if (!empty($j['eras'])) {
        $d = json_decode((string)$j['eras'], true);
        if (is_array($d)) $eras = $d;
    }
    foreach ($eras as $e) {
        if (preg_match('/(\d{4})/', (string)$e, $m) && (int)$m[1] >= 2000) return true;
    }
    return !empty($j['ativo']);
}

/**
 * Converte as notas cruas de TODO o elenco em níveis de letra, por percentil.
 * $crusPorJogador = [player_id => [atributo => valor cru]].
 * Devolve [player_id => [atributo => nível 0..11]].
 */
function buildAplicarCurva(array $crusPorJogador): array
{
    $saida = [];
    foreach (array_keys($crusPorJogador) as $id) $saida[$id] = [];

    $total = count($crusPorJogador);
    if ($total === 0) return $saida;

    foreach (array_keys(buildAtributos()) as $attr) {
        $ordenado = [];
        foreach ($crusPorJogador as $id => $cru) {
            $ordenado[$id] = $cru[$attr] ?? 0;
        }
        asort($ordenado); // pior primeiro

        // Fatia o elenco ordenado segundo a curva: limites[i] é a posição em
        // que a letra i termina.
        $limites = [];
        $acumulado = 0;
        foreach (BUILD_CURVA_PERCENTIL as $i => $pct) {
            $acumulado += $pct;
            $limites[$i] = (int)round($total * $acumulado / 100);
        }

        $posicao = 0;
        $nivel = 0;

        foreach (array_keys($ordenado) as $id) {
            while ($nivel < count(BUILD_CURVA_PERCENTIL) - 1 && $posicao >= $limites[$nivel]) {
                $nivel++;
            }
            $saida[$id][$attr] = $nivel;
            $posicao++;
        }
    }

    return $saida;
}

/**
 * Recalcula as notas de todo mundo e grava.
 * Quem tem ajustado_manual = 1 é preservado — correção do admin não se perde.
 */
function buildSincronizarNotas(PDO $pdo): array
{
    buildGarantirTabelaNotas($pdo);

    $jogadores = $pdo->query("SELECT id, nome, posicao, altura, peso, titulos, ativo, eras,
                                     premios, stats, pts_medio, reb_medio, ast_medio
                              FROM hoopgrid_players")->fetchAll(PDO::FETCH_ASSOC);
    if (!$jogadores) return ['total' => 0, 'gravados' => 0, 'preservados' => 0];

    $crus = [];
    $grupos = [];
    foreach ($jogadores as $j) {
        $crus[(int)$j['id']]   = buildNotasCruas($j);
        $grupos[(int)$j['id']] = buildGrupoDaPosicao($j['posicao'] ?? null, $j['altura'] ?? null);
    }

    $niveis = buildAplicarCurva($crus);

    $manuais = $pdo->query("SELECT player_id FROM build_notas WHERE ajustado_manual = 1")
                   ->fetchAll(PDO::FETCH_COLUMN);
    $manuais = array_flip(array_map('intval', $manuais));

    $attrs = array_keys(buildAtributos());
    $cols  = implode(', ', array_map(fn($a) => "`$a`", $attrs));
    $ph    = implode(', ', array_fill(0, count($attrs), '?'));
    $upd   = implode(', ', array_map(fn($a) => "`$a` = VALUES(`$a`)", $attrs));

    $stmt = $pdo->prepare("INSERT INTO build_notas (player_id, posicao_grupo, $cols, ovr)
                           VALUES (?, ?, $ph, ?)
                           ON DUPLICATE KEY UPDATE posicao_grupo = VALUES(posicao_grupo), $upd, ovr = VALUES(ovr)");

    $gravados = 0;
    foreach ($niveis as $id => $porAttr) {
        if (isset($manuais[$id])) continue;

        $valores = [];
        $soma = 0;
        foreach ($attrs as $a) {
            $n = (int)($porAttr[$a] ?? 0);
            $valores[] = $n;
            $soma += buildValorDaLetra($n);
        }
        $ovr = (int)round($soma / count($attrs));

        $stmt->execute(array_merge([$id, $grupos[$id]], $valores, [$ovr]));
        $gravados++;
    }

    return [
        'total'       => count($jogadores),
        'gravados'    => $gravados,
        'preservados' => count($manuais),
    ];
}
