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
// Patrimônio inicial. Na T1 o quinteto dos mais caros custava F$ 114 e, com o
// 6º homem, o time ideal passa de F$ 135: com 100 ainda não cabe todo mundo,
// mas sobra espaço pra um reserva que muda a rodada (com 75 o 6º virava enfeite).
const FAN_ORCAMENTO = 100.0;
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
const FAN_ENTRADA_MAX = 5000;       // teto da entrada de uma copa, em moedas
const FAN_COPA_VAGAS = [8, 16];     // copa é chave fechada: sem bye
const FAN_RODADAS_MAX = 20;         // liga de pontos corridos: de 1 a 20 rodadas
// Prêmio do ranking DA RODADA, em FBA Points. Geral, por liga e ligas de pontos corridos não pagam.
const FAN_PREMIOS = [1 => 500, 2 => 300, 3 => 200, 4 => 100, 5 => 100, 6 => 100, 7 => 100, 8 => 100, 9 => 100, 10 => 100];

/** Ligas dos usuários: quantas cada um participa (criadas + que entrou) e o teto de gente por liga. */
const FAN_MAX_LIGAS = 3;
const FAN_MAX_MEMBROS = 64;

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
        "CREATE TABLE IF NOT EXISTS fantasy_ligas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nome VARCHAR(40) NOT NULL,
            tipo ENUM('pontos','mata_mata') NOT NULL DEFAULT 'pontos',
            codigo VARCHAR(12) NOT NULL,
            dono_user_id INT NOT NULL,
            rodada_minima INT NOT NULL DEFAULT 1,
            status ENUM('aberta','andamento','encerrada') NOT NULL DEFAULT 'aberta',
            fase_atual INT NOT NULL DEFAULT 0,
            campeao_user_id INT NULL,
            criada_em DATETIME NOT NULL,
            UNIQUE KEY uk_codigo (codigo)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fantasy_liga_membros (
            liga_id INT NOT NULL,
            user_id INT NOT NULL,
            entrou_em DATETIME NOT NULL,
            PRIMARY KEY (liga_id, user_id),
            KEY idx_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS fantasy_confrontos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            liga_id INT NOT NULL,
            fase INT NOT NULL,
            rodada_id INT NULL,
            user_a INT NOT NULL,
            user_b INT NULL,
            pontos_a DECIMAL(7,1) NULL,
            pontos_b DECIMAL(7,1) NULL,
            vencedor INT NULL,
            KEY idx_liga (liga_id, fase),
            KEY idx_rodada (rodada_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ] as $sql) {
        try { $pdo->exec($sql); } catch (Throwable $e) { error_log('[fantasy] tabela: ' . $e->getMessage()); }
    }
    // O 6º homem: reserva de qualquer posição (ver fanDetalheDoTime).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM fantasy_escalacoes LIKE 'reserva'")->fetch()) {
            $pdo->exec("ALTER TABLE fantasy_escalacoes ADD COLUMN reserva INT NULL AFTER capitao");
        }
    } catch (Throwable $e) { error_log('[fantasy] coluna reserva: ' . $e->getMessage()); }
    // Copa: entrada em moedas do Games; o pote inteiro vai pro campeão (premio_pago evita pagar duas vezes).
    try {
        if (!$pdo->query("SHOW COLUMNS FROM fantasy_ligas LIKE 'entrada'")->fetch()) {
            $pdo->exec("ALTER TABLE fantasy_ligas ADD COLUMN entrada INT NOT NULL DEFAULT 0 AFTER tipo,
                        ADD COLUMN pote INT NOT NULL DEFAULT 0 AFTER entrada,
                        ADD COLUMN premio_pago TINYINT(1) NOT NULL DEFAULT 0 AFTER campeao_user_id");
        }
    } catch (Throwable $e) { error_log('[fantasy] colunas da copa: ' . $e->getMessage()); }
    // Tamanho da copa (8 ou 16 times) e duração da liga de pontos corridos (1 a 20 rodadas).
    // NULL = liga criada antes disso: copa aceita de 2 em diante e pontos corridos não acaba.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM fantasy_ligas LIKE 'vagas'")->fetch()) {
            $pdo->exec("ALTER TABLE fantasy_ligas ADD COLUMN vagas TINYINT NULL AFTER pote, ADD COLUMN rodadas TINYINT NULL AFTER vagas");
        }
    } catch (Throwable $e) { error_log('[fantasy] colunas vagas/rodadas: ' . $e->getMessage()); }
}

/**
 * Mexe nas MOEDAS do Games (games_usuarios.pontos). Débito só passa se houver
 * saldo — devolve false sem mexer em nada. Crédito sempre passa.
 */
function fanMexerMoedas(PDO $pdo, int $uid, int $valor): bool
{
    $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, pontos) VALUES (?, 0)")->execute([$uid]);
    if ($valor >= 0) {
        $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")->execute([$valor, $uid]);
        return true;
    }
    $st = $pdo->prepare("UPDATE games_usuarios SET pontos = pontos - ? WHERE id = ? AND pontos >= ?");
    $st->execute([-$valor, $uid, -$valor]);
    return $st->rowCount() > 0;
}

/** @return array{moedas:int, fba_points:int} */
function fanSaldo(PDO $pdo, int $uid): array
{
    try {
        $st = $pdo->prepare("SELECT pontos, fba_points FROM games_usuarios WHERE id = ?");
        $st->execute([$uid]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['moedas' => (int)($r['pontos'] ?? 0), 'fba_points' => (int)($r['fba_points'] ?? 0)];
    } catch (Throwable $e) {
        return ['moedas' => 0, 'fba_points' => 0];
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
    // Fase de mata-mata criada no fim da rodada passada é decidida nesta.
    $pdo->prepare("UPDATE fantasy_confrontos SET rodada_id = ? WHERE rodada_id IS NULL")->execute([$rodadaId]);
    // Copa que completou os times com o mercado fechado começa agora, com o mercado aberto.
    try {
        $st = $pdo->query("SELECT l.id FROM fantasy_ligas l
                            WHERE l.tipo = 'mata_mata' AND l.status = 'aberta' AND l.vagas IS NOT NULL
                              AND (SELECT COUNT(*) FROM fantasy_liga_membros m WHERE m.liga_id = l.id) >= l.vagas");
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $ligaId) fanComecarCopa($pdo, (int)$ligaId, $rodadaId);
    } catch (Throwable $e) { error_log('[fantasy] copas completas: ' . $e->getMessage()); }
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
    $vazia = ['pg' => null, 'sg' => null, 'sf' => null, 'pf' => null, 'c' => null, 'capitao' => null, 'reserva' => null];
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

    // 6º homem é opcional, de qualquer posição, e não pode estar no quinteto.
    $reserva = (int)($corpo['reserva'] ?? 0) ?: null;
    // O 6º homem é obrigatório pra salvar. Escalação salva antes da regra continua valendo.
    if (!$reserva) return ['ok' => false, 'erro' => 'Falta o 6º homem: são 5 titulares + 1 reserva pra salvar.'];
    if ($reserva && in_array($reserva, $ids, true)) return ['ok' => false, 'erro' => 'O 6º homem não pode estar no quinteto.'];
    $todos = $reserva ? array_merge($ids, [$reserva]) : $ids;

    $ph = implode(',', array_fill(0, count($todos), '?'));
    $st = $pdo->prepare("SELECT p.id, p.name, UPPER(TRIM(p.position)) pos, fp.preco
                           FROM players p JOIN teams t ON t.id = p.team_id
                           JOIN fantasy_precos fp ON fp.player_id = p.id AND fp.rodada_id = ?
                          WHERE t.league = ? AND p.id IN ($ph)");
    $st->execute(array_merge([$rid, FAN_LIGA], $todos));
    $achados = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $achados[(int)$r['id']] = $r;

    $custo = 0.0;
    foreach ($esc as $pos => $id) {
        if (!isset($achados[$id])) return ['ok' => false, 'erro' => 'Um dos jogadores não está mais na ELITE. Troque e salve de novo.'];
        if ($achados[$id]['pos'] !== $pos) return ['ok' => false, 'erro' => "{$achados[$id]['name']} não joga de {$pos}."];
        $custo += (float)$achados[$id]['preco'];
    }
    if ($reserva) {
        if (!isset($achados[$reserva])) return ['ok' => false, 'erro' => 'O 6º homem não está mais na ELITE. Troque e salve de novo.'];
        $custo += (float)$achados[$reserva]['preco'];
    }

    $cartola = fanCartola($pdo, $user);
    $patrimonio = (float)$cartola['patrimonio'];
    if ($custo > $patrimonio + 0.001) {
        return ['ok' => false, 'erro' => 'Custa F$ ' . number_format($custo, 1, ',', '.')
                                        . ' e você tem F$ ' . number_format($patrimonio, 1, ',', '.') . '.'];
    }

    $pdo->prepare("INSERT INTO fantasy_escalacoes (rodada_id, user_id, pg, sg, sf, pf, c, capitao, reserva, custo, patrimonio_inicio, atualizado_em)
                   VALUES (?,?,?,?,?,?,?,?,?,?,?,NOW())
                   ON DUPLICATE KEY UPDATE pg=VALUES(pg), sg=VALUES(sg), sf=VALUES(sf), pf=VALUES(pf), c=VALUES(c),
                       capitao=VALUES(capitao), reserva=VALUES(reserva), custo=VALUES(custo),
                       patrimonio_inicio=VALUES(patrimonio_inicio), atualizado_em=NOW()")
        ->execute([$rid, (int)$user['id'], $esc['PG'], $esc['SG'], $esc['SF'], $esc['PF'], $esc['C'], $capitao, $reserva,
                   round($custo, 1), $patrimonio]);

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
        return ['rodada' => null, 'regras' => fanRegras(), 'admin' => $ehAdmin, 'eu' => (int)$user['id'],
                'ligas' => fanMinhasLigas($pdo, (int)$user['id']), 'max_ligas' => FAN_MAX_LIGAS,
                'minha_liga_fba' => fanLigaFbaDoUsuario($pdo, (int)$user['id']),
                'saldo' => fanSaldo($pdo, (int)$user['id']),
                'ranking' => ['rodada' => [], 'geral' => [], 'por_liga' => []]];
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
            'reserva' => !empty($esc['reserva']) ? (int)$esc['reserva'] : null,
            'salva' => $esc['salva'],
        ],
        'jogadores' => $jogadores,
        'ranking' => fanRanking($pdo, $rodada),
        'historico' => fanHistorico($pdo, (int)$user['id']),
        'ligas' => fanMinhasLigas($pdo, (int)$user['id']),
        'max_ligas' => FAN_MAX_LIGAS,
        'minha_liga_fba' => fanLigaFbaDoUsuario($pdo, (int)$user['id']),
        'saldo' => fanSaldo($pdo, (int)$user['id']),
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

/** Pontos de um time na rodada (com o capitão e o 6º homem) a partir dos pontos dos jogadores. */
function fanPontosDoTime(array $e, array $pontos): float
{
    return fanDetalheDoTime($e, $pontos)['total'];
}

/**
 * O 6º HOMEM: se ele pontuar mais que o PIOR titular, entra no lugar dele.
 * A comparação é pelos pontos do jogador, sem o bônus de capitão; e quem entra
 * não herda o bônus — se o pior for o capitão, o 6º entra com os pontos dele.
 * Empate no pior: vale o primeiro na ordem PG, SG, SF, PF, C.
 *
 * @return array{total:float, substituido:?string}
 */
function fanDetalheDoTime(array $e, array $pontos): array
{
    $raw = [];
    foreach (['pg', 'sg', 'sf', 'pf', 'c'] as $k) {
        $raw[$k] = (float)($pontos['id'][(int)($e[$k] ?? 0)]['total'] ?? 0.0);
    }
    $reserva = (int)($e['reserva'] ?? 0);
    $rp = $reserva > 0 ? (float)($pontos['id'][$reserva]['total'] ?? 0.0) : 0.0;
    $substituido = null;
    if ($reserva > 0) {
        $pior = array_keys($raw, min($raw))[0];
        if ($rp > $raw[$pior]) $substituido = $pior;
    }
    $total = 0.0;
    foreach ($raw as $k => $p) {
        if ($k === $substituido) { $total += $rp; continue; }
        $total += (int)($e[$k] ?? 0) === (int)($e['capitao'] ?? 0) ? $p * FAN_CAPITAO : $p;
    }
    return ['total' => round($total, 1), 'substituido' => $substituido];
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

    /* POR LIGA DA FBA: o mesmo geral, separado pela liga do time de cada
       cartola. Quem tem time em duas ligas aparece nas duas. */
    $porLiga = [];
    try {
        $st = $pdo->query("SELECT t.league, c.user_id, u.name gm, c.nome_time, c.patrimonio,
                                  COALESCE(SUM(e.pontos),0) pontos, COUNT(e.pontos) rodadas
                             FROM fantasy_cartolas c
                             JOIN users u ON u.id = c.user_id
                             JOIN (SELECT DISTINCT user_id, league FROM teams WHERE user_id IS NOT NULL) t ON t.user_id = c.user_id
                        LEFT JOIN fantasy_escalacoes e ON e.user_id = c.user_id AND e.pontos IS NOT NULL
                         GROUP BY t.league, c.user_id, u.name, c.nome_time, c.patrimonio
                           HAVING rodadas > 0
                         ORDER BY pontos DESC");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $g) {
            $lg = (string)$g['league'];
            if (count($porLiga[$lg] ?? []) >= 100) continue;
            $porLiga[$lg][] = [
                'user_id' => (int)$g['user_id'], 'time' => fanNomeCartola($g['nome_time'], $g['gm']),
                'gm' => $g['gm'], 'pontos' => (float)$g['pontos'], 'patrimonio' => (float)$g['patrimonio'],
                'rodadas' => (int)$g['rodadas'],
            ];
        }
    } catch (Throwable $e) {
        error_log('[fantasy] ranking por liga: ' . $e->getMessage());
    }

    return ['rodada' => $rodadaLista, 'geral' => $geral, 'por_liga' => $porLiga];
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
            // O 6º homem também valoriza ou desvaloriza o patrimônio.
            foreach (['pg', 'sg', 'sf', 'pf', 'c', 'reserva'] as $k) {
                [$antes, $depois] = $precos[(int)($e[$k] ?? 0)] ?? [0, 0];
                $delta += $depois - $antes;
            }
            $e['_patrimonio'] = round(max(FAN_ORCAMENTO / 2, (float)$e['patrimonio_inicio'] + $delta), 1);
        }
        unset($e);
        usort($times, fn($a, $b) => $b['_pontos'] <=> $a['_pontos']);

        $upE = $pdo->prepare("UPDATE fantasy_escalacoes SET pontos = ?, patrimonio_fim = ?, colocacao = ?, moedas = ? WHERE id = ?");
        $upC = $pdo->prepare("UPDATE fantasy_cartolas SET patrimonio = ? WHERE user_id = ?");
        $garante = $pdo->prepare("INSERT IGNORE INTO games_usuarios (id, pontos) VALUES (?, 0)");
        // A coluna fantasy_escalacoes.moedas guarda o prêmio, que agora é pago em FBA Points.
        $paga = $pdo->prepare("UPDATE games_usuarios SET fba_points = fba_points + ? WHERE id = ?");
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
    // Com os pontos gravados, os confrontos de mata-mata desta rodada se decidem.
    fanLigasAposRodada($pdo, $rid);
    return ['ok' => true, 'times' => count($times)];
}

/* ─── ligas dos usuários ─────────────────────────────────────────────────── */

function fanNomeCartola(?string $nomeTime, ?string $gm): string
{
    if ($nomeTime) return $nomeTime;
    return 'Time do ' . (explode(' ', trim((string)$gm))[0] ?: 'Cartola');
}

/** A liga da FBA do time do usuário (a primeira, se tiver mais de uma). */
function fanLigaFbaDoUsuario(PDO $pdo, int $userId): ?string
{
    $st = $pdo->prepare("SELECT league FROM teams WHERE user_id = ? ORDER BY FIELD(league,'ELITE','NEXT','RISE','ROOKIE') LIMIT 1");
    $st->execute([$userId]);
    return $st->fetchColumn() ?: null;
}

function fanQuantasLigas(PDO $pdo, int $userId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) FROM fantasy_liga_membros WHERE user_id = ?");
    $st->execute([$userId]);
    return (int)$st->fetchColumn();
}

/** Código de convite curto e sem caracteres ambíguos (0/O, 1/I/L). */
function fanNovoCodigo(PDO $pdo): string
{
    $letras = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';
    $st = $pdo->prepare("SELECT 1 FROM fantasy_ligas WHERE codigo = ?");
    for ($i = 0; $i < 30; $i++) {
        $c = '';
        for ($k = 0; $k < 6; $k++) $c .= $letras[random_int(0, strlen($letras) - 1)];
        $st->execute([$c]);
        if (!$st->fetchColumn()) return $c;
    }
    return strtoupper(bin2hex(random_bytes(4)));
}

function fanMinhasLigas(PDO $pdo, int $userId): array
{
    fanGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT l.*, (SELECT COUNT(*) FROM fantasy_liga_membros m2 WHERE m2.liga_id = l.id) AS membros
                           FROM fantasy_ligas l
                           JOIN fantasy_liga_membros m ON m.liga_id = l.id AND m.user_id = ?
                       ORDER BY m.entrou_em");
    $st->execute([$userId]);
    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $saida[] = [
            'id' => (int)$l['id'], 'nome' => $l['nome'], 'tipo' => $l['tipo'], 'codigo' => $l['codigo'],
            'status' => $l['status'], 'fase_atual' => (int)$l['fase_atual'], 'membros' => (int)$l['membros'],
            'entrada' => (int)($l['entrada'] ?? 0), 'pote' => (int)($l['pote'] ?? 0),
            'vagas' => $l['vagas'] !== null ? (int)$l['vagas'] : null,
            'rodadas' => $l['rodadas'] !== null ? (int)$l['rodadas'] : null,
            'rodadas_feitas' => $l['tipo'] === 'pontos' ? count(fanRodadasContadas($pdo, $l)) : 0,
            'dono' => (int)$l['dono_user_id'] === $userId,
        ];
    }
    return $saida;
}

/** As rodadas encerradas que contam pra uma liga de pontos corridos: as N primeiras desde a criação. */
function fanRodadasContadas(PDO $pdo, array $l): array
{
    $limite = $l['rodadas'] !== null ? max(1, (int)$l['rodadas']) : 1000;
    $st = $pdo->prepare("SELECT id FROM fantasy_rodadas WHERE status = 'encerrada' AND id >= ? ORDER BY id LIMIT {$limite}");
    $st->execute([(int)$l['rodada_minima']]);
    return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** $tamanho: times da copa (8 ou 16) ou rodadas da liga de pontos corridos (1 a 20). */
function fanCriarLiga(PDO $pdo, array $user, string $nome, string $tipo, int $entrada = 0, int $tamanho = 0): array
{
    fanGarantirTabelas($pdo);
    $uid = (int)$user['id'];
    $nome = trim(preg_replace('/\s+/', ' ', strip_tags($nome)));
    if (mb_strlen($nome) < 3 || mb_strlen($nome) > 40) return ['ok' => false, 'erro' => 'O nome da liga precisa ter de 3 a 40 letras.'];
    if (!in_array($tipo, ['pontos', 'mata_mata'], true)) return ['ok' => false, 'erro' => 'Escolha o formato da liga.'];
    // Só a copa cobra entrada; liga de pontos corridos é de graça e não paga nada.
    $entrada = $tipo === 'mata_mata' ? max(0, $entrada) : 0;
    if ($entrada > FAN_ENTRADA_MAX) return ['ok' => false, 'erro' => 'A entrada vai até ' . FAN_ENTRADA_MAX . ' moedas.'];
    $vagas = null; $rodadas = null;
    if ($tipo === 'mata_mata') {
        if (!in_array($tamanho, FAN_COPA_VAGAS, true)) return ['ok' => false, 'erro' => 'Escolha se a copa é de 8 ou 16 times.'];
        $vagas = $tamanho;
    } else {
        if ($tamanho < 1 || $tamanho > FAN_RODADAS_MAX) return ['ok' => false, 'erro' => 'Escolha de 1 a ' . FAN_RODADAS_MAX . ' rodadas.'];
        $rodadas = $tamanho;
    }
    if (fanQuantasLigas($pdo, $uid) >= FAN_MAX_LIGAS) {
        return ['ok' => false, 'erro' => 'Você já está em ' . FAN_MAX_LIGAS . ' ligas, que é o limite. Saia de uma pra criar outra.'];
    }

    /* A liga conta a partir da rodada que ainda dá pra escalar: a aberta, ou a
       próxima se o mercado já fechou — pontuar uma rodada que já estava em
       andamento na hora de criar seria entrar sabendo o resultado. */
    $r = fanRodadaAtual($pdo);
    $minima = $r ? (int)$r['id'] + ($r['status'] === 'aberta' ? 0 : 1) : 1;
    $codigo = fanNovoCodigo($pdo);
    fanCartola($pdo, $user);

    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO fantasy_ligas (nome, tipo, entrada, pote, vagas, rodadas, codigo, dono_user_id, rodada_minima, status, criada_em)
                       VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())")
            ->execute([$nome, $tipo, $entrada, $entrada, $vagas, $rodadas, $codigo, $uid, $minima, $tipo === 'pontos' ? 'andamento' : 'aberta']);
        $id = (int)$pdo->lastInsertId();
        $pdo->prepare("INSERT INTO fantasy_liga_membros (liga_id, user_id, entrou_em) VALUES (?, ?, NOW())")->execute([$id, $uid]);
        // Quem cria a copa também paga a entrada.
        if ($entrada > 0 && !fanMexerMoedas($pdo, $uid, -$entrada)) {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => "Você não tem {$entrada} moedas pra pagar a entrada."];
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fantasy] criar liga: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra criar a liga. Tente de novo.'];
    }
    return ['ok' => true, 'liga_id' => $id, 'codigo' => $codigo];
}

function fanEntrarLiga(PDO $pdo, array $user, string $codigo): array
{
    fanGarantirTabelas($pdo);
    $uid = (int)$user['id'];
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    $st = $pdo->prepare("SELECT * FROM fantasy_ligas WHERE codigo = ?");
    $st->execute([$codigo]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['ok' => false, 'erro' => 'Convite inválido. Confira o código.'];
    $ligaId = (int)$l['id'];

    $st = $pdo->prepare("SELECT 1 FROM fantasy_liga_membros WHERE liga_id = ? AND user_id = ?");
    $st->execute([$ligaId, $uid]);
    if ($st->fetchColumn()) return ['ok' => true, 'liga_id' => $ligaId, 'ja' => true];

    if ($l['status'] === 'encerrada') return ['ok' => false, 'erro' => 'Essa liga já terminou.'];
    if ($l['tipo'] === 'mata_mata' && $l['status'] !== 'aberta') return ['ok' => false, 'erro' => 'Esse mata-mata já começou — não dá mais pra entrar.'];

    $st = $pdo->prepare("SELECT COUNT(*) FROM fantasy_liga_membros WHERE liga_id = ?");
    $st->execute([$ligaId]);
    if ((int)$st->fetchColumn() >= FAN_MAX_MEMBROS) return ['ok' => false, 'erro' => 'Essa liga está cheia (' . FAN_MAX_MEMBROS . ' participantes).'];
    if (fanQuantasLigas($pdo, $uid) >= FAN_MAX_LIGAS) {
        return ['ok' => false, 'erro' => 'Você já está em ' . FAN_MAX_LIGAS . ' ligas, que é o limite. Saia de uma pra entrar nesta.'];
    }

    fanCartola($pdo, $user);
    $entrada = (int)($l['entrada'] ?? 0);
    $pdo->beginTransaction();
    try {
        // Trava a liga: ninguém entra (nem paga) depois de o mata-mata começar.
        $st = $pdo->prepare("SELECT status FROM fantasy_ligas WHERE id = ? FOR UPDATE");
        $st->execute([$ligaId]);
        if ($l['tipo'] === 'mata_mata' && $st->fetchColumn() !== 'aberta') {
            $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Esse mata-mata já começou — não dá mais pra entrar.'];
        }
        // Copa é chave fechada: completou, fechou.
        $vagas = $l['vagas'] !== null ? (int)$l['vagas'] : null;
        if ($l['tipo'] === 'mata_mata' && $vagas !== null) {
            $st = $pdo->prepare("SELECT COUNT(*) FROM fantasy_liga_membros WHERE liga_id = ?");
            $st->execute([$ligaId]);
            if ((int)$st->fetchColumn() >= $vagas) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => "Essa copa já está completa ({$vagas}/{$vagas})."];
            }
        }
        $ins = $pdo->prepare("INSERT IGNORE INTO fantasy_liga_membros (liga_id, user_id, entrou_em) VALUES (?, ?, NOW())");
        $ins->execute([$ligaId, $uid]);
        if ($ins->rowCount() > 0 && $entrada > 0) {
            if (!fanMexerMoedas($pdo, $uid, -$entrada)) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => "A entrada dessa copa é {$entrada} moedas e você não tem saldo."];
            }
            $pdo->prepare("UPDATE fantasy_ligas SET pote = pote + ? WHERE id = ?")->execute([$entrada, $ligaId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fantasy] entrar liga: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra entrar na liga. Tente de novo.'];
    }
    // Fechou a chave com o mercado aberto: a copa começa na hora. Com o mercado
    // fechado, começa quando a próxima rodada abrir (fanAbrirRodada).
    $comecou = false;
    if ($l['tipo'] === 'mata_mata' && $l['vagas'] !== null) {
        $st = $pdo->prepare("SELECT COUNT(*) FROM fantasy_liga_membros WHERE liga_id = ?");
        $st->execute([$ligaId]);
        $r = fanRodadaAtual($pdo);
        if ($r && $r['status'] === 'aberta' && (int)$st->fetchColumn() >= (int)$l['vagas']) {
            $comecou = fanComecarCopa($pdo, $ligaId, (int)$r['id'])['ok'];
        }
    }
    return ['ok' => true, 'liga_id' => $ligaId, 'nome' => $l['nome'], 'pagou' => $entrada, 'comecou' => $comecou];
}

/** O que o convite é, antes de entrar — pra copa mostrar quanto custa. */
function fanPreviaConvite(PDO $pdo, int $uid, string $codigo): array
{
    fanGarantirTabelas($pdo);
    $codigo = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codigo));
    $st = $pdo->prepare("SELECT l.id, l.nome, l.tipo, l.entrada, l.pote, l.vagas, l.rodadas, l.status,
                                (SELECT COUNT(*) FROM fantasy_liga_membros m WHERE m.liga_id = l.id) membros,
                                (SELECT COUNT(*) FROM fantasy_liga_membros m WHERE m.liga_id = l.id AND m.user_id = ?) ja
                           FROM fantasy_ligas l WHERE l.codigo = ?");
    $st->execute([$uid, $codigo]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['ok' => false, 'erro' => 'Convite inválido. Confira o código.'];
    return ['ok' => true, 'liga' => [
        'id' => (int)$l['id'], 'nome' => $l['nome'], 'tipo' => $l['tipo'], 'entrada' => (int)$l['entrada'],
        'pote' => (int)$l['pote'], 'status' => $l['status'], 'membros' => (int)$l['membros'], 'ja' => (int)$l['ja'] > 0,
        'vagas' => $l['vagas'] !== null ? (int)$l['vagas'] : null, 'rodadas' => $l['rodadas'] !== null ? (int)$l['rodadas'] : null,
    ], 'saldo' => fanSaldo($pdo, $uid)];
}

/** Sair da liga. Quem criou, ao sair, apaga a liga pra todos. */
function fanSairLiga(PDO $pdo, array $user, int $ligaId): array
{
    fanGarantirTabelas($pdo);
    $uid = (int)$user['id'];
    $st = $pdo->prepare("SELECT l.* FROM fantasy_ligas l
                           JOIN fantasy_liga_membros m ON m.liga_id = l.id AND m.user_id = ?
                          WHERE l.id = ?");
    $st->execute([$uid, $ligaId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['ok' => false, 'erro' => 'Você não está nessa liga.'];

    $entrada = (int)($l['entrada'] ?? 0);
    if ((int)$l['dono_user_id'] === $uid) {
        if ($entrada > 0 && $l['status'] === 'andamento') {
            return ['ok' => false, 'erro' => 'A copa já começou e tem pote em jogo — dá pra excluir depois que sair o campeão.'];
        }
        $pdo->beginTransaction();
        try {
            // Copa que nem começou: devolve a entrada de todo mundo antes de apagar.
            if ($entrada > 0 && $l['status'] === 'aberta') {
                $st = $pdo->prepare("SELECT user_id FROM fantasy_liga_membros WHERE liga_id = ?");
                $st->execute([$ligaId]);
                foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $membro) fanMexerMoedas($pdo, (int)$membro, $entrada);
            }
            $pdo->prepare("DELETE FROM fantasy_confrontos WHERE liga_id = ?")->execute([$ligaId]);
            $pdo->prepare("DELETE FROM fantasy_liga_membros WHERE liga_id = ?")->execute([$ligaId]);
            $pdo->prepare("DELETE FROM fantasy_ligas WHERE id = ?")->execute([$ligaId]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[fantasy] excluir liga: ' . $e->getMessage());
            return ['ok' => false, 'erro' => 'Não deu pra excluir a liga.'];
        }
        return ['ok' => true, 'excluida' => true];
    }
    if ($l['tipo'] === 'mata_mata' && $l['status'] === 'andamento') {
        return ['ok' => false, 'erro' => 'O mata-mata já começou — dá pra sair quando ele terminar.'];
    }
    $pdo->beginTransaction();
    try {
        $del = $pdo->prepare("DELETE FROM fantasy_liga_membros WHERE liga_id = ? AND user_id = ?");
        $del->execute([$ligaId, $uid]);
        // Saiu da copa antes de começar: a entrada volta e sai do pote.
        if ($del->rowCount() > 0 && $entrada > 0 && $l['status'] === 'aberta') {
            fanMexerMoedas($pdo, $uid, $entrada);
            $pdo->prepare("UPDATE fantasy_ligas SET pote = GREATEST(pote - ?, 0) WHERE id = ?")->execute([$entrada, $ligaId]);
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[fantasy] sair liga: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra sair da liga.'];
    }
    return ['ok' => true];
}

/** Monta os confrontos de uma fase, na ordem da lista; o que sobrar sozinho passa direto. */
function fanCriarFase(PDO $pdo, int $ligaId, int $fase, array $ids, ?int $rodadaId): void
{
    $ins = $pdo->prepare("INSERT INTO fantasy_confrontos (liga_id, fase, rodada_id, user_a, user_b, vencedor) VALUES (?, ?, ?, ?, ?, ?)");
    $ids = array_values($ids);
    for ($i = 0; $i < count($ids); $i += 2) {
        $a = (int)$ids[$i];
        $b = isset($ids[$i + 1]) ? (int)$ids[$i + 1] : null;
        $ins->execute([$ligaId, $fase, $rodadaId, $a, $b, $b === null ? $a : null]);
    }
}

function fanIniciarMataMata(PDO $pdo, array $user, int $ligaId): array
{
    fanGarantirTabelas($pdo);
    $uid = (int)$user['id'];
    $st = $pdo->prepare("SELECT * FROM fantasy_ligas WHERE id = ?");
    $st->execute([$ligaId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l || (int)$l['dono_user_id'] !== $uid) return ['ok' => false, 'erro' => 'Só quem criou a liga pode começar o mata-mata.'];
    if ($l['tipo'] !== 'mata_mata' || $l['status'] !== 'aberta') return ['ok' => false, 'erro' => 'Esse mata-mata já começou.'];

    $r = fanRodadaAtual($pdo);
    if (!$r || $r['status'] !== 'aberta') {
        return ['ok' => false, 'erro' => 'Comece com o mercado aberto: a 1ª fase é decidida na rodada em que dá pra escalar.'];
    }
    return fanComecarCopa($pdo, $ligaId, (int)$r['id']);
}

/**
 * Sorteia a 1ª fase e põe a copa em andamento, decidida na rodada $rodadaId.
 * Copa com vagas (8/16) só começa completa; copa antiga (sem vagas) com 2 ou mais.
 * Usada pelo botão do dono, pela entrada que completa a chave e pela abertura de rodada.
 */
function fanComecarCopa(PDO $pdo, int $ligaId, int $rodadaId): array
{
    $propria = !$pdo->inTransaction();
    if ($propria) $pdo->beginTransaction();
    $falha = function (string $msg) use ($pdo, $propria) {
        if ($propria && $pdo->inTransaction()) $pdo->rollBack();
        return ['ok' => false, 'erro' => $msg];
    };
    try {
        $st = $pdo->prepare("SELECT * FROM fantasy_ligas WHERE id = ? FOR UPDATE");
        $st->execute([$ligaId]);
        $l = $st->fetch(PDO::FETCH_ASSOC);
        if (!$l || $l['tipo'] !== 'mata_mata' || $l['status'] !== 'aberta') return $falha('Esse mata-mata já começou.');

        $st = $pdo->prepare("SELECT user_id FROM fantasy_liga_membros WHERE liga_id = ?");
        $st->execute([$ligaId]);
        $ids = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $vagas = $l['vagas'] !== null ? (int)$l['vagas'] : null;
        if ($vagas !== null && count($ids) < $vagas) {
            $faltam = $vagas - count($ids);
            return $falha("A copa começa com {$vagas} times — falta" . ($faltam > 1 ? 'm' : '') . " {$faltam}.");
        }
        if (count($ids) < 2) return $falha('Precisa de pelo menos 2 participantes. Mande o convite pros amigos.');
        if ($vagas !== null) $ids = array_slice($ids, 0, $vagas);

        shuffle($ids);
        fanCriarFase($pdo, $ligaId, 1, $ids, $rodadaId);
        $pdo->prepare("UPDATE fantasy_ligas SET status = 'andamento', fase_atual = 1, rodada_minima = ? WHERE id = ?")
            ->execute([$rodadaId, $ligaId]);
        if ($propria) $pdo->commit();
    } catch (Throwable $e) {
        if ($propria && $pdo->inTransaction()) $pdo->rollBack();
        error_log('[fantasy] começar copa: ' . $e->getMessage());
        return ['ok' => false, 'erro' => 'Não deu pra começar o mata-mata.'];
    }
    return ['ok' => true];
}

/**
 * Decide os confrontos da rodada que acabou de encerrar e avança as fases.
 * Quem fez mais pontos passa; empate vai pro maior patrimônio; sem escalação,
 * o time conta 0.
 */
function fanLigasAposRodada(PDO $pdo, int $rid): void
{
    try {
        $pts = [];
        $st = $pdo->prepare("SELECT e.user_id, e.pontos, COALESCE(e.patrimonio_fim, c.patrimonio, 0) patr
                               FROM fantasy_escalacoes e LEFT JOIN fantasy_cartolas c ON c.user_id = e.user_id
                              WHERE e.rodada_id = ?");
        $st->execute([$rid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $pts[(int)$r['user_id']] = [(float)($r['pontos'] ?? 0), (float)$r['patr']];

        $st = $pdo->prepare("SELECT * FROM fantasy_confrontos WHERE rodada_id = ? AND vencedor IS NULL AND user_b IS NOT NULL");
        $st->execute([$rid]);
        $up = $pdo->prepare("UPDATE fantasy_confrontos SET pontos_a = ?, pontos_b = ?, vencedor = ? WHERE id = ?");
        $ligas = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
            $a = (int)$c['user_a']; $b = (int)$c['user_b'];
            [$pa, $patA] = $pts[$a] ?? [0.0, 0.0];
            [$pb, $patB] = $pts[$b] ?? [0.0, 0.0];
            $venc = $pa > $pb ? $a : ($pb > $pa ? $b : ($patB > $patA ? $b : $a));
            $up->execute([$pa, $pb, $venc, (int)$c['id']]);
            $ligas[(int)$c['liga_id']] = true;
        }
        foreach (array_keys($ligas) as $ligaId) fanAvancarMataMata($pdo, $ligaId);

        // Pontos corridos que completaram as N rodadas: encerra e marca o campeão
        // (mais pontos nas rodadas contadas; empate, maior patrimônio). Não paga nada.
        $st = $pdo->query("SELECT * FROM fantasy_ligas WHERE tipo = 'pontos' AND status = 'andamento' AND rodadas IS NOT NULL");
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $contadas = fanRodadasContadas($pdo, $l);
            if (count($contadas) < (int)$l['rodadas']) continue;
            $in = implode(',', array_map('intval', $contadas));
            $top = $pdo->prepare("SELECT m.user_id
                                    FROM fantasy_liga_membros m
                               LEFT JOIN fantasy_escalacoes e ON e.user_id = m.user_id AND e.pontos IS NOT NULL AND e.rodada_id IN ({$in})
                               LEFT JOIN fantasy_cartolas c ON c.user_id = m.user_id
                                   WHERE m.liga_id = ?
                                GROUP BY m.user_id, c.patrimonio
                                ORDER BY COALESCE(SUM(e.pontos), 0) DESC, COALESCE(c.patrimonio, 0) DESC
                                   LIMIT 1");
            $top->execute([(int)$l['id']]);
            $campeao = $top->fetchColumn();
            $pdo->prepare("UPDATE fantasy_ligas SET status = 'encerrada', campeao_user_id = ? WHERE id = ? AND status = 'andamento'")
                ->execute([$campeao !== false ? (int)$campeao : null, (int)$l['id']]);
        }
    } catch (Throwable $e) {
        error_log('[fantasy] ligas após rodada: ' . $e->getMessage());
    }
}

function fanAvancarMataMata(PDO $pdo, int $ligaId): void
{
    $st = $pdo->prepare("SELECT * FROM fantasy_ligas WHERE id = ?");
    $st->execute([$ligaId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l || $l['status'] !== 'andamento') return;
    $fase = (int)$l['fase_atual'];

    $st = $pdo->prepare("SELECT vencedor FROM fantasy_confrontos WHERE liga_id = ? AND fase = ? ORDER BY id");
    $st->execute([$ligaId, $fase]);
    $vencedores = $st->fetchAll(PDO::FETCH_COLUMN);
    if (!$vencedores || in_array(null, $vencedores, true)) return;   // fase ainda não acabou
    $vencedores = array_map('intval', $vencedores);

    if (count($vencedores) === 1) {
        // Campeão leva o pote todo, uma vez só (premio_pago = 0 na condição).
        $fim = $pdo->prepare("UPDATE fantasy_ligas SET status = 'encerrada', campeao_user_id = ?, premio_pago = 1 WHERE id = ? AND premio_pago = 0");
        $fim->execute([$vencedores[0], $ligaId]);
        if ($fim->rowCount() > 0 && (int)($l['pote'] ?? 0) > 0) fanMexerMoedas($pdo, $vencedores[0], (int)$l['pote']);
        return;
    }
    // Próxima fase sem rodada: ela é ligada à rodada que abrir (fanAbrirRodada).
    fanCriarFase($pdo, $ligaId, $fase + 1, $vencedores, null);
    $pdo->prepare("UPDATE fantasy_ligas SET fase_atual = ? WHERE id = ?")->execute([$fase + 1, $ligaId]);
}

/** A tabela (pontos corridos) ou a chave (mata-mata) de uma liga, pra quem participa dela. */
function fanTabelaLiga(PDO $pdo, int $uid, int $ligaId): array
{
    fanGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT l.* FROM fantasy_ligas l
                           JOIN fantasy_liga_membros m ON m.liga_id = l.id AND m.user_id = ?
                          WHERE l.id = ?");
    $st->execute([$uid, $ligaId]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return ['ok' => false, 'erro' => 'Essa liga não existe ou você não participa dela.'];

    $st = $pdo->prepare("SELECT m.user_id, u.name gm, c.nome_time, COALESCE(c.patrimonio, ?) patrimonio
                           FROM fantasy_liga_membros m
                           JOIN users u ON u.id = m.user_id
                      LEFT JOIN fantasy_cartolas c ON c.user_id = m.user_id
                          WHERE m.liga_id = ? ORDER BY m.entrou_em");
    $st->execute([FAN_ORCAMENTO, $ligaId]);
    $membros = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $m) {
        $membros[(int)$m['user_id']] = ['user_id' => (int)$m['user_id'], 'time' => fanNomeCartola($m['nome_time'], $m['gm']),
                                        'gm' => $m['gm'], 'patrimonio' => (float)$m['patrimonio']];
    }

    // Parcial da rodada em andamento, pra tabela e os confrontos andarem junto.
    $rodada = fanRodadaAtual($pdo);
    $parcial = [];
    if ($rodada && $rodada['status'] === 'fechada' && $membros) {
        $pontos = fanPontosDaTemporada($pdo, (int)$rodada['season_id']);
        $ph = implode(',', array_fill(0, count($membros), '?'));
        $st = $pdo->prepare("SELECT * FROM fantasy_escalacoes WHERE rodada_id = ? AND user_id IN ($ph)");
        $st->execute(array_merge([(int)$rodada['id']], array_keys($membros)));
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $e) $parcial[(int)$e['user_id']] = fanPontosDoTime($e, $pontos);
    }

    $contadas = $l['tipo'] === 'pontos' ? fanRodadasContadas($pdo, $l) : [];
    $resp = [
        'ok' => true, 'eu' => $uid, 'dono' => (int)$l['dono_user_id'] === $uid,
        'liga' => ['id' => (int)$l['id'], 'nome' => $l['nome'], 'tipo' => $l['tipo'], 'codigo' => $l['codigo'],
                   'status' => $l['status'], 'fase_atual' => (int)$l['fase_atual'],
                   'entrada' => (int)($l['entrada'] ?? 0), 'pote' => (int)($l['pote'] ?? 0),
                   'vagas' => $l['vagas'] !== null ? (int)$l['vagas'] : null,
                   'rodadas' => $l['rodadas'] !== null ? (int)$l['rodadas'] : null,
                   'rodadas_feitas' => count($contadas)],
        'membros' => array_values($membros),
    ];

    if ($l['tipo'] === 'pontos') {
        // Só as N rodadas da liga contam — a 21ª não mexe numa liga de 20.
        $soma = [];
        if ($membros && $contadas) {
            $ph = implode(',', array_fill(0, count($membros), '?'));
            $phR = implode(',', array_fill(0, count($contadas), '?'));
            $st = $pdo->prepare("SELECT e.user_id, SUM(e.pontos) pts, COUNT(e.pontos) rodadas
                                   FROM fantasy_escalacoes e
                                  WHERE e.pontos IS NOT NULL AND e.rodada_id IN ($phR) AND e.user_id IN ($ph)
                               GROUP BY e.user_id");
            $st->execute(array_merge($contadas, array_keys($membros)));
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $s) $soma[(int)$s['user_id']] = [(float)$s['pts'], (int)$s['rodadas']];
        }
        $contaParcial = $l['status'] !== 'encerrada' && $rodada && $rodada['status'] === 'fechada'
            && (int)$rodada['id'] >= (int)$l['rodada_minima'] && !in_array((int)$rodada['id'], $contadas, true)
            && ($l['rodadas'] === null || count($contadas) < (int)$l['rodadas']);
        $tabela = [];
        foreach ($membros as $id => $m) {
            $tabela[] = $m + ['pontos' => round($soma[$id][0] ?? 0, 1), 'rodadas' => $soma[$id][1] ?? 0,
                              'parcial' => $contaParcial ? ($parcial[$id] ?? null) : null];
        }
        usort($tabela, fn($a, $b) => [$b['pontos'] + ($b['parcial'] ?? 0), $b['patrimonio']]
                                 <=> [$a['pontos'] + ($a['parcial'] ?? 0), $a['patrimonio']]);
        $resp['tabela'] = $tabela;
        $resp['campeao'] = $l['campeao_user_id'] ? ($membros[(int)$l['campeao_user_id']] ?? null) : null;
        return $resp;
    }

    $quem = fn($u) => $u === null ? null
        : ($membros[(int)$u] ?? ['user_id' => (int)$u, 'time' => 'Saiu da liga', 'gm' => '', 'patrimonio' => 0]);
    $st = $pdo->prepare("SELECT c.*, r.season_number FROM fantasy_confrontos c
                      LEFT JOIN fantasy_rodadas r ON r.id = c.rodada_id
                          WHERE c.liga_id = ? ORDER BY c.fase, c.id");
    $st->execute([$ligaId]);
    $fases = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $c) {
        $f = (int)$c['fase'];
        $fases[$f] ??= ['fase' => $f, 'temporada' => $c['season_number'] !== null ? (int)$c['season_number'] : null, 'confrontos' => []];
        $noAr = !$c['vencedor'] && $rodada && (int)$c['rodada_id'] === (int)$rodada['id'];
        $fases[$f]['confrontos'][] = [
            'a' => $quem($c['user_a']), 'b' => $quem($c['user_b'] !== null ? (int)$c['user_b'] : null),
            'pontos_a' => $c['pontos_a'] !== null ? (float)$c['pontos_a'] : null,
            'pontos_b' => $c['pontos_b'] !== null ? (float)$c['pontos_b'] : null,
            'parcial_a' => $noAr ? ($parcial[(int)$c['user_a']] ?? null) : null,
            'parcial_b' => $noAr && $c['user_b'] !== null ? ($parcial[(int)$c['user_b']] ?? null) : null,
            'vencedor' => $c['vencedor'] !== null ? (int)$c['vencedor'] : null,
        ];
    }
    $resp['fases'] = array_values($fases);
    $resp['campeao'] = $l['campeao_user_id'] ? $quem((int)$l['campeao_user_id']) : null;
    return $resp;
}
