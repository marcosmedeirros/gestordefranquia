<?php
/**
 * FANTASY FBA — o Cartola da ELITE.
 *
 * Uma temporada da ELITE é uma rodada:
 *   aberta    → mercado aberto, cada um escala 5 (um por posição) e o capitão.
 *   fechada   → ninguém mexe mais; os pontos aparecem como parciais conforme
 *               os times lançam as estatísticas da temporada.
 *   encerrada → pontos finais, preços novos, patrimônio atualizado e moedas pagas.
 *
 * Quem fecha e encerra é o admin, na própria página. O mercado não pode fechar
 * sozinho pelo calendário: a temporada é simulada fora do site, e não existe
 * marco de "começou a temporada regular". A única trava automática é a
 * classificação — com ela definida, a temporada já foi jogada, e escalar
 * depois disso seria escalar sabendo o resultado.
 *
 * PONTUAÇÃO: como o Cartola (gol 8, assistência 5), cada jogada vale um
 * número fixo, aplicado ao jogo médio da temporada, proporcional aos jogos.
 * PREÇO: pontos da última temporada ÷ FAN_PONTOS_POR_FS.
 */

require_once __DIR__ . '/db.php';

const FAN_LIGA = 'ELITE';
// Patrimônio inicial. Na T1 o quinteto dos mais caros custava F$ 114: com
// 100 quase dava pra levar todos. Com 75 o melhor time custa 1,5× o que se
// tem — escalar vira escolha.
const FAN_ORCAMENTO = 75.0;
const FAN_CAPITAO = 1.5;
const FAN_PONTOS_POR_FS = 3.0;     // 60 pontos na temporada = F$ 20
const FAN_PRECO_MIN = 2.0;
const FAN_PRECO_MAX = 40.0;
const FAN_POSICOES = ['PG', 'SG', 'SF', 'PF', 'C'];

/** O que cada jogada vale, por jogo. */
const FAN_SCOUTS = [
    'pts' => ['Ponto', 1.0],
    'reb' => ['Rebote', 1.5],
    'ast' => ['Assistência', 2.0],
    'stl' => ['Roubo', 3.0],
    'blk' => ['Toco', 3.0],
];

/** Bônus da temporada, somados no fim (já inteiros, não multiplicam por jogos). */
const FAN_BONUS = [
    'duplo'      => ['Duplo-duplo de média', 5],
    'triplo'     => ['Triplo-duplo de média', 10],
    'campeao'    => ['Campeão', 5],
    'mvp'        => ['MVP', 15],
    'finals_mvp' => ['MVP das Finais', 8],
    'dpoy'       => ['Defensor do ano', 8],
    'roy'        => ['Calouro do ano', 5],
    '6th_man'    => ['6º homem', 5],
    'mip'        => ['Mais evoluído', 5],
    'all_nba_1'  => ['All-NBA 1º time', 8],
    'all_nba_2'  => ['All-NBA 2º time', 6],
    'all_nba_3'  => ['All-NBA 3º time', 4],
];

/** Moedas por colocação na rodada. */
const FAN_PREMIOS = [1 => 500, 2 => 300, 3 => 200, 4 => 100, 5 => 100, 6 => 100, 7 => 100, 8 => 100, 9 => 100, 10 => 100];

function fanGarantirTabelas(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    foreach ([
        "CREATE TABLE IF NOT EXISTS fantasy_rodadas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            season_id INT NOT NULL,
            season_number INT NOT NULL,
            status ENUM('aberta','fechada','encerrada') NOT NULL DEFAULT 'aberta',
            aberta_em DATETIME NOT NULL,
            fechada_em DATETIME NULL,
            encerrada_em DATETIME NULL,
            UNIQUE KEY uk_season (season_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fantasy_precos (
            rodada_id INT NOT NULL,
            player_id INT NOT NULL,
            preco DECIMAL(5,1) NOT NULL,
            base_pontos DECIMAL(6,1) NULL,
            pontos DECIMAL(6,1) NULL,
            preco_depois DECIMAL(5,1) NULL,
            PRIMARY KEY (rodada_id, player_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fantasy_escalacoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            rodada_id INT NOT NULL,
            user_id INT NOT NULL,
            pg INT NULL, sg INT NULL, sf INT NULL, pf INT NULL, c INT NULL,
            capitao INT NULL,
            custo DECIMAL(6,1) NOT NULL DEFAULT 0,
            patrimonio_inicio DECIMAL(7,1) NOT NULL,
            pontos DECIMAL(7,1) NULL,
            patrimonio_fim DECIMAL(7,1) NULL,
            colocacao INT NULL,
            moedas INT NOT NULL DEFAULT 0,
            atualizado_em DATETIME NOT NULL,
            UNIQUE KEY uk_user (rodada_id, user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fantasy_cartolas (
            user_id INT PRIMARY KEY,
            nome_time VARCHAR(40) NULL,
            patrimonio DECIMAL(7,1) NOT NULL DEFAULT " . FAN_ORCAMENTO . ",
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { error_log('[fantasy] tabela: ' . $e->getMessage()); }
    }
}

/* ─── pontuação ───────────────────────────────────────────────────────────── */

/**
 * Pontos de todo mundo numa temporada: player_id => detalhe.
 * Também indexa por nome (minúsculo), porque jogador que passa pelas
 * dispensas é recriado com outro id e perderia o histórico.
 */
function fanPontosDaTemporada(PDO $pdo, int $seasonId): array
{
    static $cache = [];
    if (isset($cache[$seasonId])) return $cache[$seasonId];

    $st = $pdo->prepare("SELECT ps.player_id, ps.team_id, ps.games, ps.pts_pg, ps.reb_pg, ps.ast_pg, ps.stl_pg, ps.blk_pg, p.name
                           FROM player_season_stats ps LEFT JOIN players p ON p.id = ps.player_id
                          WHERE ps.season_id = ? AND ps.games > 0");
    $st->execute([$seasonId]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);
    $maxJogos = max([1, ...array_map(fn($l) => (int)$l['games'], $linhas)]);

    $premios = [];
    $st = $pdo->prepare("SELECT award_type, player_name FROM season_awards WHERE season_id = ?");
    $st->execute([$seasonId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $a) {
        $premios[mb_strtolower(trim($a['player_name']))][] = $a['award_type'];
    }
    $st = $pdo->prepare("SELECT team_id FROM playoff_results WHERE season_id = ? AND position = 'champion' LIMIT 1");
    $st->execute([$seasonId]);
    $campeao = (int)($st->fetchColumn() ?: 0);

    $porId = []; $porNome = [];
    foreach ($linhas as $l) {
        $jogo = 0.0; $scouts = [];
        foreach (FAN_SCOUTS as $k => [, $valor]) {
            $media = (float)$l[$k . '_pg'];
            $scouts[$k] = round($media, 1);
            $jogo += $media * $valor;
        }
        $presenca = min(1, (int)$l['games'] / $maxJogos);

        $bonus = [];
        $dez = count(array_filter([$l['pts_pg'], $l['reb_pg'], $l['ast_pg'], $l['stl_pg'], $l['blk_pg']], fn($v) => (float)$v >= 10));
        if ($dez >= 3) $bonus[] = 'triplo'; elseif ($dez >= 2) $bonus[] = 'duplo';
        if ($campeao && (int)$l['team_id'] === $campeao) $bonus[] = 'campeao';
        foreach ($premios[mb_strtolower(trim((string)$l['name']))] ?? [] as $t) if (isset(FAN_BONUS[$t])) $bonus[] = $t;
        $valorBonus = array_sum(array_map(fn($b) => FAN_BONUS[$b][1], $bonus));

        $d = [
            'jogos' => (int)$l['games'], 'max_jogos' => $maxJogos, 'scouts' => $scouts,
            'por_jogo' => round($jogo, 1), 'presenca' => round($presenca, 2),
            'bonus' => $bonus, 'valor_bonus' => $valorBonus,
            'total' => round($jogo * $presenca + $valorBonus, 1),
        ];
        $porId[(int)$l['player_id']] = $d;
        if ($l['name']) $porNome[mb_strtolower(trim($l['name']))] = $d;
    }
    return $cache[$seasonId] = ['id' => $porId, 'nome' => $porNome, 'max_jogos' => $maxJogos];
}

function fanPrecoPorPontos(float $pontos): float
{
    return round(max(FAN_PRECO_MIN, min(FAN_PRECO_MAX, $pontos / FAN_PONTOS_POR_FS)), 1);
}

/** Sem temporada anterior (calouro, recém-chegado): um palpite pelo OVR. */
function fanPrecoPorOvr(int $ovr): float
{
    return round(max(FAN_PRECO_MIN, min(15, ($ovr - 62) * 0.6)), 1);
}

/* ─── rodadas ─────────────────────────────────────────────────────────────── */

/** As temporadas da ELITE na sprint ativa, da mais velha pra mais nova. */
function fanTemporadas(PDO $pdo): array
{
    $st = $pdo->prepare("SELECT s.id, s.season_number, s.status,
                                EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id) AS classificada
                           FROM seasons s JOIN sprints sp ON sp.id = s.sprint_id
                          WHERE s.league = ? AND sp.status = 'active'
                       ORDER BY s.season_number, s.id");
    $st->execute([FAN_LIGA]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * A rodada de agora. Abre a da temporada corrente quando não há nenhuma
 * pendente, e fecha o mercado da aberta se a classificação já saiu.
 */
function fanRodadaAtual(PDO $pdo): ?array
{
    fanGarantirTabelas($pdo);

    $pendente = $pdo->query("SELECT * FROM fantasy_rodadas WHERE status <> 'encerrada' ORDER BY id DESC LIMIT 1")
                    ->fetch(PDO::FETCH_ASSOC) ?: null;

    if (!$pendente) {
        $temps = fanTemporadas($pdo);
        $corrente = null;
        foreach (array_reverse($temps) as $t) { if ($t['status'] !== 'completed') { $corrente = $t; break; } }
        if ($corrente) {
            $st = $pdo->prepare("SELECT 1 FROM fantasy_rodadas WHERE season_id = ?");
            $st->execute([(int)$corrente['id']]);
            if (!$st->fetchColumn() && !$corrente['classificada']) {
                fanAbrirRodada($pdo, $corrente);
                return fanRodadaAtual($pdo);
            }
        }
        return $pdo->query("SELECT * FROM fantasy_rodadas ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    if ($pendente['status'] === 'aberta') {
        $st = $pdo->prepare("SELECT 1 FROM season_standings WHERE season_id = ? LIMIT 1");
        $st->execute([(int)$pendente['season_id']]);
        if ($st->fetchColumn()) {
            $pdo->prepare("UPDATE fantasy_rodadas SET status = 'fechada', fechada_em = NOW() WHERE id = ? AND status = 'aberta'")
                ->execute([(int)$pendente['id']]);
            $pendente['status'] = 'fechada';
        }
    }
    return $pendente;
}

function fanAbrirRodada(PDO $pdo, array $temporada): void
{
    $pdo->prepare("INSERT IGNORE INTO fantasy_rodadas (season_id, season_number, status, aberta_em) VALUES (?, ?, 'aberta', NOW())")
        ->execute([(int)$temporada['id'], (int)$temporada['season_number']]);
    $st = $pdo->prepare("SELECT id FROM fantasy_rodadas WHERE season_id = ?");
    $st->execute([(int)$temporada['id']]);
    $rodadaId = (int)$st->fetchColumn();
    fanCompletarPrecos($pdo, $rodadaId, (int)$temporada['id']);
}

/** A temporada com estatística imediatamente antes desta. */
function fanTemporadaAnterior(PDO $pdo, int $seasonId): ?int
{
    $anterior = null;
    foreach (fanTemporadas($pdo) as $t) {
        if ((int)$t['id'] === $seasonId) break;
        $st = $pdo->prepare("SELECT 1 FROM player_season_stats WHERE season_id = ? LIMIT 1");
        $st->execute([(int)$t['id']]);
        if ($st->fetchColumn()) $anterior = (int)$t['id'];
    }
    return $anterior;
}

/**
 * Preço pra todo jogador da ELITE que ainda não tem nesta rodada.
 * Ordem: preço de saída da rodada anterior → pontos da temporada anterior → OVR.
 */
function fanCompletarPrecos(PDO $pdo, int $rodadaId, int $seasonId): void
{
    $faltam = $pdo->prepare("SELECT p.id, p.name, p.ovr FROM players p JOIN teams t ON t.id = p.team_id
                              WHERE t.league = ? AND UPPER(TRIM(p.position)) IN ('PG','SG','SF','PF','C')
                                AND NOT EXISTS (SELECT 1 FROM fantasy_precos fp WHERE fp.rodada_id = ? AND fp.player_id = p.id)");
    $faltam->execute([FAN_LIGA, $rodadaId]);
    $jogadores = $faltam->fetchAll(PDO::FETCH_ASSOC);
    if (!$jogadores) return;

    $saida = [];
    $st = $pdo->prepare("SELECT fp.player_id, fp.preco_depois FROM fantasy_precos fp
                           JOIN fantasy_rodadas r ON r.id = fp.rodada_id
                          WHERE r.status = 'encerrada' AND r.id <> ? AND fp.preco_depois IS NOT NULL
                       ORDER BY r.id ASC");
    $st->execute([$rodadaId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $saida[(int)$r['player_id']] = (float)$r['preco_depois'];

    $anterior = fanTemporadaAnterior($pdo, $seasonId);
    $pts = $anterior ? fanPontosDaTemporada($pdo, $anterior) : ['id' => [], 'nome' => []];

    $ins = $pdo->prepare("INSERT IGNORE INTO fantasy_precos (rodada_id, player_id, preco, base_pontos) VALUES (?, ?, ?, ?)");
    foreach ($jogadores as $j) {
        $id = (int)$j['id'];
        $d = $pts['id'][$id] ?? $pts['nome'][mb_strtolower(trim($j['name']))] ?? null;
        if (isset($saida[$id]))  $preco = $saida[$id];
        elseif ($d)              $preco = fanPrecoPorPontos($d['total']);
        else                     $preco = fanPrecoPorOvr((int)$j['ovr']);
        $ins->execute([$rodadaId, $id, $preco, $d ? $d['total'] : null]);
    }
}

/* ─── cartola do usuário ──────────────────────────────────────────────────── */

function fanCartola(PDO $pdo, array $user): array
{
    $pdo->prepare("INSERT IGNORE INTO fantasy_cartolas (user_id, patrimonio) VALUES (?, ?)")
        ->execute([(int)$user['id'], FAN_ORCAMENTO]);
    $st = $pdo->prepare("SELECT * FROM fantasy_cartolas WHERE user_id = ?");
    $st->execute([(int)$user['id']]);
    $c = $st->fetch(PDO::FETCH_ASSOC);
    if (empty($c['nome_time'])) {
        $primeiro = explode(' ', trim((string)($user['name'] ?? 'Cartola')))[0] ?: 'Cartola';
        $c['nome_time'] = 'Time do ' . $primeiro;
    }
    return $c;
}

/** A escalação da rodada; se ainda não salvou, sugere a da rodada anterior. */
function fanEscalacao(PDO $pdo, int $rodadaId, int $userId): array
{
    $st = $pdo->prepare("SELECT * FROM fantasy_escalacoes WHERE rodada_id = ? AND user_id = ?");
    $st->execute([$rodadaId, $userId]);
    if ($e = $st->fetch(PDO::FETCH_ASSOC)) return $e + ['salva' => true];

    $st = $pdo->prepare("SELECT * FROM fantasy_escalacoes WHERE user_id = ? AND rodada_id < ? ORDER BY rodada_id DESC LIMIT 1");
    $st->execute([$userId, $rodadaId]);
    $ult = $st->fetch(PDO::FETCH_ASSOC);
    $vazia = ['pg' => null, 'sg' => null, 'sf' => null, 'pf' => null, 'c' => null, 'capitao' => null];
    return ($ult ? array_intersect_key($ult, $vazia) : $vazia) + ['salva' => false];
}

function fanSalvarEscalacao(PDO $pdo, array $user, array $corpo): array
{
    $rodada = fanRodadaAtual($pdo);
    if (!$rodada || $rodada['status'] !== 'aberta') return ['ok' => false, 'erro' => 'O mercado está fechado.'];
    $rid = (int)$rodada['id'];
    fanCompletarPrecos($pdo, $rid, (int)$rodada['season_id']);

    $esc = [];
    foreach (FAN_POSICOES as $p) {
        $id = (int)($corpo['escalacao'][$p] ?? 0);
        $esc[$p] = $id ?: null;
    }
    $ids = array_values(array_filter($esc));
    if (count($ids) !== 5) return ['ok' => false, 'erro' => 'Escale um jogador em cada posição.'];
    if (count(array_unique($ids)) !== 5) return ['ok' => false, 'erro' => 'Jogador repetido.'];
    $capitao = (int)($corpo['capitao'] ?? 0);
    if (!in_array($capitao, $ids, true)) return ['ok' => false, 'erro' => 'Escolha o capitão entre os cinco.'];

    $ph = implode(',', array_fill(0, 5, '?'));
    $st = $pdo->prepare("SELECT p.id, p.name, UPPER(TRIM(p.position)) pos, fp.preco
                           FROM players p JOIN teams t ON t.id = p.team_id
                           JOIN fantasy_precos fp ON fp.player_id = p.id AND fp.rodada_id = ?
                          WHERE t.league = ? AND p.id IN ($ph)");
    $st->execute(array_merge([$rid, FAN_LIGA], $ids));
    $achados = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $achados[(int)$r['id']] = $r;

    $custo = 0.0;
    foreach ($esc as $pos => $id) {
        if (!isset($achados[$id])) return ['ok' => false, 'erro' => 'Um dos jogadores não está mais na ELITE. Troque e salve de novo.'];
        if ($achados[$id]['pos'] !== $pos) return ['ok' => false, 'erro' => "{$achados[$id]['name']} não joga de {$pos}."];
        $custo += (float)$achados[$id]['preco'];
    }

    $cartola = fanCartola($pdo, $user);
    $patrimonio = (float)$cartola['patrimonio'];
    if ($custo > $patrimonio + 0.001) {
        return ['ok' => false, 'erro' => 'Custa F$ ' . number_format($custo, 1, ',', '.')
                                        . ' e você tem F$ ' . number_format($patrimonio, 1, ',', '.') . '.'];
    }

    $pdo->prepare("INSERT INTO fantasy_escalacoes (rodada_id, user_id, pg, sg, sf, pf, c, capitao, custo, patrimonio_inicio, atualizado_em)
                   VALUES (?,?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE pg=VALUES(pg), sg=VALUES(sg), sf=VALUES(sf), pf=VALUES(pf), c=VALUES(c),
                       capitao=VALUES(capitao), custo=VALUES(custo), patrimonio_inicio=VALUES(patrimonio_inicio), atualizado_em=NOW()")
        ->execute([$rid, (int)$user['id'], $esc['PG'], $esc['SG'], $esc['SF'], $esc['PF'], $esc['C'], $capitao, round($custo, 1), $patrimonio]);

    return ['ok' => true, 'custo' => round($custo, 1)];
}

function fanSalvarNome(PDO $pdo, array $user, string $nome): array
{
    $nome = trim(preg_replace('/\s+/', ' ', strip_tags($nome)));
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 30) return ['ok' => false, 'erro' => 'O nome precisa ter de 3 a 30 letras.'];
    fanCartola($pdo, $user);
    $pdo->prepare("UPDATE fantasy_cartolas SET nome_time = ? WHERE user_id = ?")->execute([$nome, (int)$user['id']]);
    return ['ok' => true, 'nome' => $nome];
}

/* ─── estado pra tela ─────────────────────────────────────────────────────── */

function fanEstado(PDO $pdo, array $user, bool $ehAdmin): array
{
    $rodada = fanRodadaAtual($pdo);
    if (!$rodada) {
        return ['rodada' => null, 'regras' => fanRegras(), 'admin' => $ehAdmin];
    }
    $rid = (int)$rodada['id'];
    if ($rodada['status'] === 'aberta') fanCompletarPrecos($pdo, $rid, (int)$rodada['season_id']);

    // Pontos da rodada: finais se encerrada, parciais se fechada.
    $pontosRodada = $rodada['status'] === 'aberta' ? null : fanPontosDaTemporada($pdo, (int)$rodada['season_id']);

    $st = $pdo->prepare("SELECT p.id, p.name, UPPER(TRIM(p.position)) pos, p.ovr, p.age, COALESCE(p.is_lenda,0) lenda,
                                p.nba_player_id, p.foto_adicional, t.id team_id, t.name time_nome, t.city time_cidade,
                                fp.preco, fp.base_pontos, fp.pontos, fp.preco_depois
                           FROM fantasy_precos fp
                           JOIN players p ON p.id = fp.player_id
                           JOIN teams t ON t.id = p.team_id
                          WHERE fp.rodada_id = ? AND t.league = ?");
    $st->execute([$rid, FAN_LIGA]);

    // Variação: preço desta rodada contra o da anterior.
    $antes = [];
    $st2 = $pdo->prepare("SELECT fp.player_id, fp.preco FROM fantasy_precos fp
                           WHERE fp.rodada_id = (SELECT MAX(id) FROM fantasy_rodadas WHERE id < ?)");
    $st2->execute([$rid]);
    foreach ($st2->fetchAll(PDO::FETCH_ASSOC) as $r) $antes[(int)$r['player_id']] = (float)$r['preco'];

    $jogadores = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $j) {
        $id = (int)$j['id'];
        $foto = trim((string)$j['foto_adicional']);
        if ($foto !== '' && !preg_match('~^(data:image/|https?://)~i', $foto)) $foto = '/' . ltrim($foto, '/');
        if ($foto === '' && $j['nba_player_id']) $foto = "https://cdn.nba.com/headshots/nba/latest/260x190/{$j['nba_player_id']}.png";
        $parcial = $pontosRodada['id'][$id] ?? null;
        $jogadores[] = [
            'id' => $id, 'nome' => $j['name'], 'pos' => $j['pos'], 'ovr' => (int)$j['ovr'], 'idade' => (int)$j['age'],
            'lenda' => (int)$j['lenda'], 'time' => trim($j['time_cidade'] . ' ' . $j['time_nome']), 'time_curto' => $j['time_nome'],
            'foto' => $foto ?: null,
            'preco' => (float)$j['preco'],
            'variacao' => isset($antes[$id]) ? round((float)$j['preco'] - $antes[$id], 1) : null,
            'base' => $j['base_pontos'] !== null ? (float)$j['base_pontos'] : null,
            'pontos' => $parcial,
            'preco_depois' => $j['preco_depois'] !== null ? (float)$j['preco_depois'] : null,
        ];
    }

    $cartola = fanCartola($pdo, $user);
    $esc = fanEscalacao($pdo, $rid, (int)$user['id']);

    $temps = fanTemporadas($pdo);
    $seasonAtual = null;
    foreach ($temps as $t) if ((int)$t['id'] === (int)$rodada['season_id']) $seasonAtual = $t;
    $comStats = 0;
    if ($rodada['status'] !== 'aberta') {
        $st = $pdo->prepare("SELECT COUNT(DISTINCT team_id) FROM player_season_stats WHERE season_id = ?");
        $st->execute([(int)$rodada['season_id']]);
        $comStats = (int)$st->fetchColumn();
    }
    $anterior = fanTemporadaAnterior($pdo, (int)$rodada['season_id']);
    $numAnterior = null;
    foreach ($temps as $t) if ((int)$t['id'] === $anterior) $numAnterior = (int)$t['season_number'];

    return [
        'rodada' => [
            'id' => $rid, 'temporada' => (int)$rodada['season_number'], 'status' => $rodada['status'],
            'base_temporada' => $numAnterior, 'times_com_stats' => $comStats,
            'escalados' => (int)$pdo->query("SELECT COUNT(*) FROM fantasy_escalacoes WHERE rodada_id = {$rid}")->fetchColumn(),
        ],
        'cartola' => ['nome' => $cartola['nome_time'], 'patrimonio' => (float)$cartola['patrimonio']],
        'escalacao' => [
            'PG' => $esc['pg'] ? (int)$esc['pg'] : null, 'SG' => $esc['sg'] ? (int)$esc['sg'] : null,
            'SF' => $esc['sf'] ? (int)$esc['sf'] : null, 'PF' => $esc['pf'] ? (int)$esc['pf'] : null,
            'C' => $esc['c'] ? (int)$esc['c'] : null, 'capitao' => $esc['capitao'] ? (int)$esc['capitao'] : null,
            'salva' => $esc['salva'],
        ],
        'jogadores' => $jogadores,
        'ranking' => fanRanking($pdo, $rodada),
        'historico' => fanHistorico($pdo, (int)$user['id']),
        'regras' => fanRegras(),
        'admin' => $ehAdmin,
        'eu' => (int)$user['id'],
    ];
}

function fanRegras(): array
{
    return [
        'orcamento' => FAN_ORCAMENTO, 'capitao' => FAN_CAPITAO, 'pontos_por_fs' => FAN_PONTOS_POR_FS,
        'scouts' => array_map(fn($k, $v) => ['chave' => $k, 'nome' => $v[0], 'valor' => $v[1]], array_keys(FAN_SCOUTS), FAN_SCOUTS),
        'bonus' => array_map(fn($k, $v) => ['chave' => $k, 'nome' => $v[0], 'valor' => $v[1]], array_keys(FAN_BONUS), FAN_BONUS),
        'premios' => FAN_PREMIOS,
    ];
}

/** Pontos de um time na rodada (com o capitão) a partir dos pontos dos jogadores. */
function fanPontosDoTime(array $e, array $pontos): float
{
    $total = 0.0;
    foreach (['pg', 'sg', 'sf', 'pf', 'c'] as $k) {
        $id = (int)$e[$k];
        $p = $pontos['id'][$id]['total'] ?? 0.0;
        $total += $id === (int)$e['capitao'] ? $p * FAN_CAPITAO : $p;
    }
    return round($total, 1);
}

/** Ranking da rodada (parcial ou final) e o geral. */
function fanRanking(PDO $pdo, array $rodada): array
{
    $rid = (int)$rodada['id'];
    $st = $pdo->prepare("SELECT e.*, u.name AS gm, c.nome_time
                           FROM fantasy_escalacoes e JOIN users u ON u.id = e.user_id
                      LEFT JOIN fantasy_cartolas c ON c.user_id = e.user_id
                          WHERE e.rodada_id = ?");
    $st->execute([$rid]);
    $linhas = $st->fetchAll(PDO::FETCH_ASSOC);

    $pontos = $rodada['status'] === 'aberta' ? null : fanPontosDaTemporada($pdo, (int)$rodada['season_id']);
    $rodadaLista = [];
    foreach ($linhas as $e) {
        $rodadaLista[] = [
            'user_id' => (int)$e['user_id'],
            'time' => $e['nome_time'] ?: 'Time do ' . explode(' ', trim($e['gm']))[0],
            'gm' => $e['gm'],
            'pontos' => $e['pontos'] !== null ? (float)$e['pontos'] : ($pontos ? fanPontosDoTime($e, $pontos) : null),
            'moedas' => (int)$e['moedas'],
        ];
    }
    usort($rodadaLista, fn($a, $b) => ($b['pontos'] ?? 0) <=> ($a['pontos'] ?? 0));

    $geral = $pdo->query("SELECT c.user_id, u.name gm, c.nome_time, c.patrimonio,
                                 COALESCE(SUM(e.pontos),0) pontos, COALESCE(SUM(e.moedas),0) moedas, COUNT(e.pontos) rodadas
                            FROM fantasy_cartolas c JOIN users u ON u.id = c.user_id
                       LEFT JOIN fantasy_escalacoes e ON e.user_id = c.user_id AND e.pontos IS NOT NULL
                        GROUP BY c.user_id, u.name, c.nome_time, c.patrimonio
                          HAVING rodadas > 0
                        ORDER BY pontos DESC LIMIT 100")->fetchAll(PDO::FETCH_ASSOC);
    $geral = array_map(fn($g) => [
        'user_id' => (int)$g['user_id'], 'time' => $g['nome_time'] ?: 'Time do ' . explode(' ', trim($g['gm']))[0],
        'gm' => $g['gm'], 'pontos' => (float)$g['pontos'], 'patrimonio' => (float)$g['patrimonio'],
        'moedas' => (int)$g['moedas'], 'rodadas' => (int)$g['rodadas'],
    ], $geral);

    return ['rodada' => $rodadaLista, 'geral' => $geral];
}

function fanHistorico(PDO $pdo, int $userId): array
{
    $st = $pdo->prepare("SELECT r.season_number, e.pontos, e.colocacao, e.moedas, e.patrimonio_inicio, e.patrimonio_fim
                           FROM fantasy_escalacoes e JOIN fantasy_rodadas r ON r.id = e.rodada_id
                          WHERE e.user_id = ? AND r.status = 'encerrada' ORDER BY r.id DESC");
    $st->execute([$userId]);
    return array_map(fn($h) => [
        'temporada' => (int)$h['season_number'], 'pontos' => (float)$h['pontos'], 'colocacao' => (int)$h['colocacao'],
        'moedas' => (int)$h['moedas'], 'patrimonio_inicio' => (float)$h['patrimonio_inicio'], 'patrimonio_fim' => (float)$h['patrimonio_fim'],
    ], $st->fetchAll(PDO::FETCH_ASSOC));
}

/* ─── admin ───────────────────────────────────────────────────────────────── */

function fanFecharMercado(PDO $pdo): array
{
    $r = fanRodadaAtual($pdo);
    if (!$r || $r['status'] !== 'aberta') return ['ok' => false, 'erro' => 'Não há mercado aberto.'];
    $pdo->prepare("UPDATE fantasy_rodadas SET status = 'fechada', fechada_em = NOW() WHERE id = ?")->execute([(int)$r['id']]);
    return ['ok' => true];
}

function fanReabrirMercado(PDO $pdo): array
{
    $r = fanRodadaAtual($pdo);
    if (!$r || $r['status'] !== 'fechada') return ['ok' => false, 'erro' => 'O mercado não está fechado.'];
    $pdo->prepare("UPDATE fantasy_rodadas SET status = 'aberta', fechada_em = NULL WHERE id = ?")->execute([(int)$r['id']]);
    return ['ok' => true];
}

/**
 * Encerra a rodada: grava os pontos de cada jogador e o preço novo, fecha a
 * conta de cada time (pontos, patrimônio, colocação) e paga as moedas.
 */
function fanEncerrarRodada(PDO $pdo): array
{
    require_once __DIR__ . '/helpers.php';
    ensureGamesSchema($pdo);   // DDL fora da transação

    $r = fanRodadaAtual($pdo);
    if (!$r || $r['status'] !== 'fechada') return ['ok' => false, 'erro' => 'Feche o mercado antes de encerrar a rodada.'];
    $rid = (int)$r['id'];
    $pontos = fanPontosDaTemporada($pdo, (int)$r['season_id']);
    if (!$pontos['id']) return ['ok' => false, 'erro' => 'Nenhum time lançou estatística desta temporada ainda.'];

    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT id FROM fantasy_rodadas WHERE id = ? AND status = 'fechada' FOR UPDATE");
        $st->execute([$rid]);
        if (!$st->fetchColumn()) { $pdo->rollBack(); return ['ok' => false, 'erro' => 'A rodada já foi encerrada.']; }

        $precos = [];
        $up = $pdo->prepare("UPDATE fantasy_precos SET pontos = ?, preco_depois = ? WHERE rodada_id = ? AND player_id = ?");
        foreach ($pdo->query("SELECT player_id, preco FROM fantasy_precos WHERE rodada_id = {$rid}")->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $id = (int)$p['player_id'];
            $d = $pontos['id'][$id] ?? null;
            // Sem estatística: não pontua e o preço fica onde está.
            $novo = $d ? fanPrecoPorPontos($d['total']) : (float)$p['preco'];
            $up->execute([$d ? $d['total'] : null, $novo, $rid, $id]);
            $precos[$id] = [(float)$p['preco'], $novo];
        }

        $times = $pdo->query("SELECT * FROM fantasy_escalacoes WHERE rodada_id = {$rid}")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($times as &$e) {
            $e['_pontos'] = fanPontosDoTime($e, $pontos);
            $delta = 0.0;
            foreach (['pg', 'sg', 'sf', 'pf', 'c'] as $k) {
                [$antes, $depois] = $precos[(int)$e[$k]] ?? [0, 0];
                $delta += $depois - $antes;
            }
            $e['_patrimonio'] = round(max(FAN_ORCAMENTO / 2, (float)$e['patrimonio_inicio'] + $delta), 1);
        }
        unset($e);
        usort($times, fn($a, $b) => $b['_pontos'] <=> $a['_pontos']);

        $upE = $pdo->prepare("UPDATE fantasy_escalacoes SET pontos = ?, patrimonio_fim = ?, colocacao = ?, moedas = ? WHERE id = ?");
        $upC = $pdo->prepare("UPDATE fantasy_cartolas SET patrimonio = ? WHERE user_id = ?");
        $garante = $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, pontos) VALUES (?, 0)");
        $paga = $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?");
        $pos = 0; $ultimoPonto = null; $lugar = 0;
        foreach ($times as $e) {
            $pos++;
            if ($e['_pontos'] !== $ultimoPonto) { $lugar = $pos; $ultimoPonto = $e['_pontos']; }   // empate divide o lugar
            $moedas = FAN_PREMIOS[$lugar] ?? 0;
            $upE->execute([$e['_pontos'], $e['_patrimonio'], $lugar, $moedas, (int)$e['id']]);
            $upC->execute([$e['_patrimonio'], (int)$e['user_id']]);
            if ($moedas > 0) { $garante->execute([(int)$e['user_id']]); $paga->execute([$moedas, (int)$e['user_id']]); }
        }

        $pdo->prepare("UPDATE fantasy_rodadas SET status = 'encerrada', encerrada_em = NOW() WHERE id = ?")->execute([$rid]);
        $pdo->commit();
    } catch (Throwable $ex) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fantasy] encerrar: ' . $ex->getMessage());
        return ['ok' => false, 'erro' => 'Erro ao encerrar a rodada.'];
    }
    return ['ok' => true, 'times' => count($times)];
}
