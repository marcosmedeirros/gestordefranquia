<?php
/**
 * A CAMADA DO MARCOS por cima do histórico — favoritos, correções, lixeira e
 * jogadores criados na mão (marcos-estudos.php).
 *
 * ── POR QUE É UMA CAMADA, E NÃO UM UPDATE ────────────────────────────
 *
 * `player_season_log` e `player_season_stats` são o histórico do JOGO: telas de
 * jogador, snapshots de temporada e rankings leem delas. Editar ou apagar linha
 * ali pra "arrumar o estudo" mudaria o que o resto do site mostra, e um erro de
 * digitação viraria história. Então nada daqui encosta nessas tabelas: as
 * correções ficam em duas tabelas próprias e são APLICADAS POR CIMA na hora de
 * ler (ver estudosCarregar). Apagar o ajuste devolve o dado original; "excluir"
 * um jogador só o tira dos estudos (lixeira, com restaurar).
 *
 *   estudos_vidas       uma linha por jogador mexido: favorito, excluído, nome
 *                       corrigido, lenda (decisão tua), nota de texto
 *   estudos_temporadas  uma linha por temporada mexida: excluída, ajustes de
 *                       campo (JSON) ou a temporada inteira, se foi criada na mão
 *
 * Jogador criado na mão ganha um id a partir de ESTUDOS_ID_MANUAL, longe de
 * qualquer id do jogo; temporada criada na mão ganha id NEGATIVO.
 */

/** Jogadores criados na mão começam aqui (os do jogo estão abaixo de 100 mil). */
const ESTUDOS_ID_MANUAL = 5000000;

/** As colunas da temporada que dá pra corrigir. */
const ESTUDOS_CAMPOS_TEMP = ['league', 'season_number', 'year', 'team_name', 'ovr', 'age', 'position',
    'skill_in', 'skill_mid', 'skill_3pt', 'skill_post_d', 'skill_per_d', 'skill_play', 'skill_reb', 'skill_athl', 'skill_iq', 'skill_pot'];
/** E as estatísticas por jogo. */
const ESTUDOS_CAMPOS_STATS = ['games', 'min_pg', 'pts_pg', 'reb_pg', 'ast_pg', 'stl_pg', 'blk_pg', 'fg_pct'];

function estudosTabelas(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $pdo->exec("CREATE TABLE IF NOT EXISTS estudos_vidas (
        player_id     INT NOT NULL PRIMARY KEY,
        favorito      TINYINT(1) NOT NULL DEFAULT 0,
        favoritado_em DATETIME NULL,
        excluido      TINYINT(1) NOT NULL DEFAULT 0,
        excluido_em   DATETIME NULL,
        nome          VARCHAR(120) NULL,
        lenda         TINYINT(1) NULL,
        tshot         TINYINT UNSIGNED NULL,
        ttouch        TINYINT UNSIGNED NULL,
        nota          TEXT NULL,
        atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS estudos_temporadas (
        player_id INT NOT NULL,
        season_id INT NOT NULL,
        excluida  TINYINT(1) NOT NULL DEFAULT 0,
        manual    TINYINT(1) NOT NULL DEFAULT 0,
        ajuste    TEXT NULL,
        PRIMARY KEY (player_id, season_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $ok = true;
}

/** Os dados por jogador (favorito, excluído, nome, lenda, nota), indexados por player_id. */
function estudosMeta(PDO $pdo): array
{
    estudosTabelas($pdo);
    $out = [];
    foreach ($pdo->query("SELECT * FROM estudos_vidas")->fetchAll(PDO::FETCH_ASSOC) as $r) $out[(int)$r['player_id']] = $r;
    return $out;
}

/** Os ajustes de temporada: player_id => season_id => ['excluida','manual','ajuste'(array)]. */
function estudosAjustes(PDO $pdo): array
{
    estudosTabelas($pdo);
    $out = [];
    foreach ($pdo->query("SELECT * FROM estudos_temporadas")->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[(int)$r['player_id']][(int)$r['season_id']] = [
            'excluida' => (int)$r['excluida'] === 1, 'manual' => (int)$r['manual'] === 1,
            'ajuste' => json_decode((string)$r['ajuste'], true) ?: [],
        ];
    }
    return $out;
}

function estudosGarantirMeta(PDO $pdo, int $id): void
{
    estudosTabelas($pdo);
    $pdo->prepare("INSERT IGNORE INTO estudos_vidas (player_id) VALUES (?)")->execute([$id]);
}

/** Favorita ou desfavorita. */
function estudosFavoritar(PDO $pdo, int $id, bool $valor): void
{
    estudosGarantirMeta($pdo, $id);
    $pdo->prepare("UPDATE estudos_vidas SET favorito = ?, favoritado_em = " . ($valor ? 'NOW()' : 'NULL') . " WHERE player_id = ?")
        ->execute([$valor ? 1 : 0, $id]);
}

/** Manda pra lixeira (ou restaura). Não apaga nada do histórico do jogo. */
function estudosExcluir(PDO $pdo, int $id, bool $valor): void
{
    estudosGarantirMeta($pdo, $id);
    $pdo->prepare("UPDATE estudos_vidas SET excluido = ?, excluido_em = " . ($valor ? 'NOW()' : 'NULL') . " WHERE player_id = ?")
        ->execute([$valor ? 1 : 0, $id]);
}

/** Desfaz todas as correções de um jogador: volta ao dado original do jogo. */
function estudosReverter(PDO $pdo, int $id): void
{
    estudosTabelas($pdo);
    $pdo->prepare("DELETE FROM estudos_temporadas WHERE player_id = ? AND manual = 0")->execute([$id]);
    $pdo->prepare("UPDATE estudos_vidas SET nome = NULL, lenda = NULL, tshot = NULL, ttouch = NULL WHERE player_id = ?")->execute([$id]);
}

/** Cria um jogador na mão. Devolve o id (a partir de ESTUDOS_ID_MANUAL). */
function estudosNovaVida(PDO $pdo, string $nome): int
{
    estudosTabelas($pdo);
    $nome = trim($nome);
    if ($nome === '' || mb_strlen($nome) > 120) throw new InvalidArgumentException('Informe o nome (até 120 letras).');
    $id = max(ESTUDOS_ID_MANUAL, (int)$pdo->query("SELECT COALESCE(MAX(player_id), 0) FROM estudos_vidas")->fetchColumn() + 1);
    $pdo->prepare("INSERT INTO estudos_vidas (player_id, nome, favorito, favoritado_em) VALUES (?, ?, 1, NOW())")->execute([$id, $nome]);
    return $id;
}

/* ── Validação ─────────────────────────────────────────────────────────── */

function estudosNotaOk(?string $v): ?string
{
    $v = strtoupper(trim((string)$v));
    if ($v === '') return null;
    if (!isset(ESTUDOS_NOTAS[$v])) throw new InvalidArgumentException("Nota inválida: {$v} (use A+ até F).");
    return $v;
}

function estudosNumero($v, string $rotulo, float $min, float $max, bool $inteiro = false): ?float
{
    if ($v === null || $v === '') return null;
    if (!is_numeric($v)) throw new InvalidArgumentException("{$rotulo}: informe um número.");
    $n = (float)$v;
    if ($n < $min || $n > $max) throw new InvalidArgumentException("{$rotulo}: fora do intervalo ({$min} a {$max}).");
    return $inteiro ? (float)(int)round($n) : $n;
}

/**
 * Limpa uma temporada vinda da tela: só os campos conhecidos, validados.
 *
 * @return array{temp:array<string,mixed>,stats:?array<string,mixed>}
 */
function estudosLimparTemporada(array $s): array
{
    $pos = strtoupper(trim((string)($s['pos'] ?? '')));
    if ($pos !== '' && !in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true)) throw new InvalidArgumentException('Posição inválida (PG, SG, SF, PF ou C).');
    $ovr = estudosNumero($s['ovr'] ?? null, 'OVR', 1, 99, true);
    $idade = estudosNumero($s['idade'] ?? null, 'Idade', 10, 60, true);
    if ($ovr === null || $idade === null) throw new InvalidArgumentException('Toda temporada precisa de OVR e idade.');
    $liga = strtoupper(trim((string)($s['liga'] ?? '')));
    if (mb_strlen($liga) > 12) throw new InvalidArgumentException('Liga longa demais.');
    $time = trim((string)($s['time'] ?? ''));
    if (mb_strlen($time) > 100) throw new InvalidArgumentException('Nome do time longo demais.');

    $temp = [
        'league' => $liga, 'season_number' => (int)(estudosNumero($s['temporada'] ?? 0, 'Temporada', 0, 999, true) ?? 0),
        'year' => (int)(estudosNumero($s['ano'] ?? 0, 'Ano', 0, 3000, true) ?? 0),
        'team_name' => $time, 'ovr' => (int)$ovr, 'age' => (int)$idade, 'position' => $pos,
    ];
    $letras = (array)($s['letras'] ?? []);
    foreach (ESTUDOS_SKILLS as $col => $sigla) $temp[$col] = estudosNotaOk($letras[$sigla] ?? null);

    $stats = null;
    $st = $s['stats'] ?? null;
    if (is_array($st) && array_filter($st, fn($v) => $v !== null && $v !== '')) {
        $stats = [
            'games'  => (int)(estudosNumero($st['jogos'] ?? null, 'Jogos', 0, 200, true) ?? 0),
            'min_pg' => estudosNumero($st['min'] ?? null, 'Minutos', 0, 60),
            'pts_pg' => estudosNumero($st['pts'] ?? null, 'PTS', 0, 100),
            'reb_pg' => estudosNumero($st['reb'] ?? null, 'REB', 0, 60),
            'ast_pg' => estudosNumero($st['ast'] ?? null, 'AST', 0, 40),
            'stl_pg' => estudosNumero($st['stl'] ?? null, 'STL', 0, 15),
            'blk_pg' => estudosNumero($st['blk'] ?? null, 'BLK', 0, 15),
            'fg_pct' => estudosNumero($st['fg'] ?? null, 'FG%', 0, 100),
        ];
    }
    return ['temp' => $temp, 'stats' => $stats];
}

/** Só o que mudou entre dois conjuntos de campos (comparação por valor, texto vazio = null). */
function estudosDiff(array $novo, array $base, array $campos): array
{
    $norm = fn($v) => ($v === null || $v === '') ? null : (is_numeric($v) ? (float)$v : (string)$v);
    $d = [];
    foreach ($campos as $c) {
        if (!array_key_exists($c, $novo)) continue;
        if ($norm($novo[$c]) !== $norm($base[$c] ?? null)) $d[$c] = $novo[$c] === '' ? null : $novo[$c];
    }
    return $d;
}

/**
 * Salva a edição de um jogador (o popup do lápis).
 *
 * Reconstrói os ajustes dele do zero a partir do que a tela mandou:
 *   - temporada que existia no jogo e voltou igual  → sem ajuste
 *   - temporada que existia e foi mudada            → ajuste só com o que mudou
 *   - temporada do jogo que NÃO veio                 → excluída
 *   - temporada sem season_id (ou negativo)          → criada na mão
 *
 * @param array{nome?:string,lenda?:mixed,nota?:string,temporadas:array<int,array>} $dados
 */
function estudosSalvarVida(PDO $pdo, int $id, array $dados): void
{
    estudosTabelas($pdo);
    $base = estudosBaseDaVida($pdo, $id);
    $nomeBase = $base['nome'];

    // Valida TUDO antes de gravar qualquer coisa.
    $nome = trim((string)($dados['nome'] ?? ''));
    if (mb_strlen($nome) > 120) throw new InvalidArgumentException('Nome longo demais.');
    if ($nome === '' && $nomeBase === '') throw new InvalidArgumentException('Informe o nome do jogador.');
    $lenda = $dados['lenda'] ?? 'auto';
    $lendaVal = ($lenda === 'auto' || $lenda === null || $lenda === '') ? null : ((string)$lenda === '1' || $lenda === true ? 1 : 0);
    $nota = trim((string)($dados['nota'] ?? ''));
    if (mb_strlen($nota) > 5000) throw new InvalidArgumentException('Nota longa demais (5000 letras).');

    $tshot = estudosNumero($dados['tshot'] ?? null, 'T/SHOT', 0, 99, true);
    $ttouch = estudosNumero($dados['ttouch'] ?? null, 'T/TOUCH', 0, 99, true);
    $tshot = $tshot === null ? null : (int)$tshot;
    $ttouch = $ttouch === null ? null : (int)$ttouch;

    $linhas = [];
    $vistos = [];
    foreach ((array)($dados['temporadas'] ?? []) as $i => $s) {
        $sid = isset($s['season_id']) && $s['season_id'] !== '' && $s['season_id'] !== null ? (int)$s['season_id'] : null;
        $lim = estudosLimparTemporada((array)$s);
        if ($sid !== null && $sid > 0 && !isset($base['temporadas'][$sid])) throw new InvalidArgumentException("Temporada #{$sid} não existe neste jogador.");
        if ($sid !== null) { if (isset($vistos[$sid])) throw new InvalidArgumentException('Temporada repetida.'); $vistos[$sid] = true; }
        $linhas[] = ['sid' => $sid, 'lim' => $lim];
    }

    $pdo->beginTransaction();
    try {
        estudosGarantirMeta($pdo, $id);
        $pdo->prepare("UPDATE estudos_vidas SET nome = ?, lenda = ?, tshot = ?, ttouch = ?, nota = ? WHERE player_id = ?")
            ->execute([($nome === '' || $nome === $nomeBase) ? null : $nome, $lendaVal, $tshot, $ttouch, $nota === '' ? null : $nota, $id]);
        $pdo->prepare("DELETE FROM estudos_temporadas WHERE player_id = ?")->execute([$id]);
        $ins = $pdo->prepare("INSERT INTO estudos_temporadas (player_id, season_id, excluida, manual, ajuste) VALUES (?,?,?,?,?)");

        // As temporadas do jogo que não vieram foram apagadas na tela.
        foreach (array_keys($base['temporadas']) as $sid) {
            if (!isset($vistos[$sid])) $ins->execute([$id, $sid, 1, 0, null]);
        }
        $proximoManual = (int)$pdo->query("SELECT COALESCE(MIN(season_id), 0) FROM estudos_temporadas WHERE season_id < 0")->fetchColumn();
        foreach ($linhas as $l) {
            $sid = $l['sid'];
            if ($sid !== null && $sid > 0) {
                $b = $base['temporadas'][$sid];
                $aj = estudosDiff($l['lim']['temp'], $b, ESTUDOS_CAMPOS_TEMP);
                if ($l['lim']['stats'] !== null) {
                    $ds = estudosDiff($l['lim']['stats'], $base['stats'][$sid] ?? [], ESTUDOS_CAMPOS_STATS);
                    if ($ds || !isset($base['stats'][$sid])) $aj['stats'] = $l['lim']['stats'];
                } elseif (isset($base['stats'][$sid])) {
                    $aj['stats'] = false;   // limpou as estatísticas na tela: a temporada fica sem elas
                }
                if ($aj) $ins->execute([$id, $sid, 0, 0, json_encode($aj, JSON_UNESCAPED_UNICODE)]);
            } else {
                // Criada na mão: guarda a temporada inteira, num id negativo.
                $proximoManual--;
                $campos = $l['lim']['temp'];
                if ($l['lim']['stats'] !== null) $campos['stats'] = $l['lim']['stats'];
                $ins->execute([$id, $proximoManual, 0, 1, json_encode($campos, JSON_UNESCAPED_UNICODE)]);
            }
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        throw $e;
    }
}

/**
 * O que o JOGO guarda de um jogador, sem nenhuma correção aplicada: o ponto de
 * comparação pra saber o que mudou.
 *
 * @return array{nome:string,temporadas:array<int,array>,stats:array<int,array>}
 */
function estudosBaseDaVida(PDO $pdo, int $id): array
{
    $st = $pdo->prepare("SELECT * FROM player_season_log WHERE player_id = ? ORDER BY year, season_number");
    $st->execute([$id]);
    $temps = []; $nome = '';
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) { $temps[(int)$r['season_id']] = $r; $nome = $nome ?: (string)$r['player_name']; }
    $stats = [];
    $q = $pdo->prepare("SELECT * FROM player_season_stats WHERE player_id = ? ORDER BY id");
    $q->execute([$id]);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $r) $stats[(int)$r['season_id']] = $r;
    if ($nome === '') {
        $n = $pdo->prepare("SELECT name FROM players WHERE id = ?"); $n->execute([$id]);
        $nome = (string)($n->fetchColumn() ?: '');
    }
    return ['nome' => $nome, 'temporadas' => $temps, 'stats' => $stats];
}

/* ── Aplicar a camada por cima do histórico ────────────────────────────── */

/**
 * Aplica os ajustes de temporada em $log/$stats (por referência), do jeito que o
 * estudosCarregar montou: tira as temporadas excluídas, corrige os campos mudados e
 * acrescenta as criadas na mão. Refaz a soma dos atributos e reordena por ano.
 *
 * @param array<int,array<int,array>> $log
 * @param array<int,array<int,array>> $stats
 * @param array<int,array<int,array>> $ajustes  de estudosAjustes()
 * @param array<int,array>            $meta     de estudosMeta()
 * @param ?array<int>                 $ids      só estas vidas (null = todas)
 */
function estudosAplicarAjustes(array &$log, array &$stats, array $ajustes, array $meta, ?array $ids, string $letras): void
{
    $so = $ids === null ? null : array_flip($ids);
    foreach ($ajustes as $pid => $porTemp) {
        if ($so !== null && !isset($so[$pid])) continue;
        $linhas = [];
        foreach ($log[$pid] ?? [] as $r) $linhas[(int)$r['season_id']] = $r;

        foreach ($porTemp as $sid => $a) {
            if ($a['excluida']) { unset($linhas[$sid], $stats[$pid][$sid]); continue; }
            $aj = $a['ajuste'];
            $st = $aj['stats'] ?? null;
            $semStats = ($aj['stats'] ?? null) === false;
            unset($aj['stats']);
            if ($semStats) unset($stats[$pid][$sid]);
            if ($a['manual']) {
                $linhas[$sid] = ['player_id' => $pid, 'player_name' => (string)($meta[$pid]['nome'] ?? ''), 'sprint_number' => 0,
                                 'season_id' => $sid, 'man' => 1] + $aj + array_fill_keys(ESTUDOS_CAMPOS_TEMP, null);
            } elseif (isset($linhas[$sid]) && ($aj || $semStats)) {
                $linhas[$sid] = $aj + $linhas[$sid];
                $linhas[$sid]['aj'] = 1;
            } elseif (!isset($linhas[$sid])) {
                continue;
            }
            if (is_array($st)) {
                $stats[$pid][$sid] = $st + ['player_id' => $pid, 'season_id' => $sid];
                if (!$a['manual'] && isset($linhas[$sid])) $linhas[$sid]['aj'] = 1;
            }
        }

        foreach ($linhas as &$r) {
            $r['attr_soma'] = estudosAtribSoma($r);
            if ($letras === 'resumo') foreach (ESTUDOS_ATRIBUTOS as $col) unset($r[$col]);
        }
        unset($r);
        $lista = array_values($linhas);
        usort($lista, fn($x, $y) => ((int)$x['year'] <=> (int)$y['year']) ?: ((int)$x['season_number'] <=> (int)$y['season_number'])
                                     ?: ((int)$y['season_id'] <=> (int)$x['season_id']));
        if ($lista) $log[$pid] = $lista; else unset($log[$pid]);
    }
}

/** O nome que vale pra uma vida: a correção do Marcos, ou o do jogo. */
function estudosNomeEfetivo(int $pid, string $original, array $meta): string
{
    $n = trim((string)($meta[$pid]['nome'] ?? ''));
    return $n !== '' ? $n : $original;
}

/**
 * Os player_id que têm este nome (sem diferenciar maiúscula), já com as correções:
 * quem foi renomeado pra outro nome sai, e quem foi renomeado pra este (ou criado na
 * mão com ele) entra. Vidas na lixeira não entram.
 *
 * @return array<int>
 */
function estudosIdsDoNome(PDO $pdo, string $nome, array $meta): array
{
    $alvo = mb_strtolower(trim($nome));
    $st = $pdo->prepare("SELECT DISTINCT player_id FROM player_season_log WHERE LOWER(TRIM(player_name)) = ?");
    $st->execute([$alvo]);
    $ids = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $pid = (int)$pid;
        $novo = trim((string)($meta[$pid]['nome'] ?? ''));
        if ($novo !== '' && mb_strtolower($novo) !== $alvo) continue;   // foi renomeado pra outro nome
        $ids[$pid] = true;
    }
    foreach ($meta as $pid => $m) {
        if (trim((string)($m['nome'] ?? '')) !== '' && mb_strtolower(trim($m['nome'])) === $alvo) $ids[(int)$pid] = true;
    }
    foreach (array_keys($ids) as $pid) if (!empty($meta[$pid]['excluido'])) unset($ids[$pid]);
    return array_keys($ids);
}

/** Quem é lenda de verdade: a flag do jogo, ou a decisão do Marcos por cima dela. @return array<int,true> */
function estudosLendasIds(PDO $pdo, array $meta): array
{
    $out = [];
    foreach ($pdo->query("SELECT id FROM players WHERE is_lenda = 1")->fetchAll(PDO::FETCH_COLUMN) as $id) $out[(int)$id] = true;
    foreach ($meta as $pid => $m) {
        if ($m['lenda'] === null) continue;
        if ((int)$m['lenda'] === 1) $out[(int)$pid] = true; else unset($out[(int)$pid]);
    }
    return $out;
}
