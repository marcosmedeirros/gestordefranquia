<?php
/**
 * games.php — Games e Apostas dentro do fbabrasil.com.br.
 *
 * Depois da fusão o antigo subdomínio deixou de existir: o catálogo de
 * minigames e as apostas vivem aqui, no mesmo banco e na mesma sessão do
 * site. Os jogos em si continuam sendo servidos de /games/, que agora usa
 * o backend/auth.php daqui.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/atualizacoes.php';   // ranking de quem atualiza elenco alheio
requireAuth();

$user = getUserSession();
$pdo  = db();
$userId = (int) $user['id'];

// O cartão do time no topo da sidebar só aparece se $team existir.
$stmtTeam = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
$stmtTeam->execute([$userId]);
$team = $stmtTeam->fetch(PDO::FETCH_ASSOC) ?: null;
if ($team) {
    $team['photo_url'] = getTeamPhoto($team['photo_url'] ?? null);
}

$nowBrt    = new DateTime('now', new DateTimeZone('America/Sao_Paulo'));
$nowBrtStr = $nowBrt->format('Y-m-d H:i:s');

// Perfil de jogo — a linha nasce no primeiro acesso, igual ao core/conexao.php
$perfil = ['pontos' => 0, 'fba_points' => 0, 'acertos_eventos' => 0];
try {
    $st = $pdo->prepare("SELECT pontos, fba_points, acertos_eventos FROM games_usuarios WHERE id = ?");
    $st->execute([$userId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        $pdo->prepare("
            INSERT IGNORE INTO games_usuarios (id, nome, email, league, is_admin)
            SELECT id, name, email, COALESCE(league,'ROOKIE'), ? FROM users WHERE id = ?
        ")->execute([(($user['user_type'] ?? '') === 'admin') ? 1 : 0, $userId]);
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    }
    if ($row) $perfil = $row;
} catch (Throwable $e) {
    error_log('[games.php] perfil: ' . $e->getMessage());
}

// ── Registrar palpite ───────────────────────────────────────────────────────
$apostaMsg = null;
$apostaErro = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['opcao_id'])) {
    try {
        $opcaoId = (int) $_POST['opcao_id'];
        if ($opcaoId <= 0) throw new Exception('Escolha inválida.');

        $pdo->beginTransaction();
        $stC = $pdo->prepare("
            SELECT e.id AS evento_id, e.status, e.data_limite
            FROM opcoes o JOIN eventos e ON o.evento_id = e.id
            WHERE o.id = ?
        ");
        $stC->execute([$opcaoId]);
        $ev = $stC->fetch(PDO::FETCH_ASSOC);
        if (!$ev) throw new Exception('Opção inválida.');
        if ($ev['status'] !== 'aberta') throw new Exception('Esse evento já encerrou.');
        if (new DateTime($ev['data_limite'], new DateTimeZone('America/Sao_Paulo')) < $nowBrt) {
            throw new Exception('O prazo desse evento já passou.');
        }

        $stD = $pdo->prepare("
            SELECT p.id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stD->execute([$userId, $ev['evento_id']]);
        $existente = $stD->fetch(PDO::FETCH_ASSOC);

        if ($existente) {
            $pdo->prepare("UPDATE palpites SET opcao_id = ?, valor = 1, odd_registrada = 1, data_palpite = NOW() WHERE id = ?")
                ->execute([$opcaoId, $existente['id']]);
            $apostaMsg = 'Palpite atualizado.';
        } else {
            $pdo->prepare("INSERT INTO palpites (id_usuario, opcao_id, valor, odd_registrada, data_palpite) VALUES (?, ?, 1, 1, NOW())")
                ->execute([$userId, $opcaoId]);
            $apostaMsg = 'Palpite registrado.';
        }
        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $apostaErro = $e->getMessage();
    }
}

// ── Eventos abertos ─────────────────────────────────────────────────────────
$eventos = [];
try {
    $stE = $pdo->prepare("SELECT id, nome, data_limite FROM eventos WHERE status = 'aberta' AND data_limite > ? ORDER BY data_limite ASC LIMIT 50");
    $stE->execute([$nowBrtStr]);
    $eventos = $stE->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($eventos as &$ev) {
        $stO = $pdo->prepare("SELECT id, descricao FROM opcoes WHERE evento_id = ? ORDER BY id ASC");
        $stO->execute([$ev['id']]);
        $ev['opcoes'] = $stO->fetchAll(PDO::FETCH_ASSOC) ?: [];

        $stM = $pdo->prepare("
            SELECT p.opcao_id FROM palpites p JOIN opcoes o ON p.opcao_id = o.id
            WHERE p.id_usuario = ? AND o.evento_id = ? LIMIT 1
        ");
        $stM->execute([$userId, $ev['id']]);
        $ev['meu_palpite'] = (int) ($stM->fetchColumn() ?: 0);

        // Prazo em linguagem de quem lê ("faltam 3h"), e marca urgência quando
        // falta menos de um dia — é o que decide se a pessoa age agora.
        $limite = new DateTime($ev['data_limite'], new DateTimeZone('America/Sao_Paulo'));
        $faltam = $nowBrt->diff($limite);
        $horas  = ($faltam->days * 24) + $faltam->h;
        $ev['urgente'] = $horas < 24;
        if ($faltam->days > 0)      $ev['prazo_txt'] = "faltam {$faltam->days} " . ($faltam->days === 1 ? 'dia' : 'dias');
        elseif ($faltam->h > 0)     $ev['prazo_txt'] = "faltam {$faltam->h}h";
        elseif ($faltam->i > 0)     $ev['prazo_txt'] = "faltam {$faltam->i} min";
        else                        $ev['prazo_txt'] = 'encerrando';
    }
    unset($ev);
} catch (Throwable $e) {
    $eventos = [];
}

// ── Meus palpites ───────────────────────────────────────────────────────────
$historico = [];
try {
    $stH = $pdo->prepare("
        SELECT p.data_palpite, o.descricao AS escolha, e.nome AS evento,
               e.status AS evento_status, e.vencedor_opcao_id, p.opcao_id
        FROM palpites p
        JOIN opcoes o ON p.opcao_id = o.id
        JOIN eventos e ON o.evento_id = e.id
        WHERE p.id_usuario = ?
        ORDER BY p.data_palpite DESC LIMIT 40
    ");
    $stH->execute([$userId]);
    $historico = $stH->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    $historico = [];
}

// ── Estado dos jogos diários ────────────────────────────────────────────────
// O que mais falta na página é saber, de relance, o que já foi jogado hoje —
// sem isso a pessoa precisa entrar em cada um pra descobrir. Cada jogo guarda
// isso na sua própria tabela, então é uma consulta por jogo (todas por índice,
// baratas). Falha em qualquer uma não derruba a página: fica "não sei".
$hoje = $nowBrt->format('Y-m-d');

/** Retorna true/false se der pra saber, null se a tabela não responder. */
function jogouHoje(PDO $pdo, string $sql, array $params): ?bool {
    try {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return (bool)$st->fetchColumn();
    } catch (Throwable $e) {
        return null;
    }
}

$statusDiario = [
    'termo'     => jogouHoje($pdo, "SELECT 1 FROM termo_historico WHERE id_usuario=? AND data_jogo=? LIMIT 1", [$userId, $hoje]),
    'memoria'   => jogouHoje($pdo, "SELECT 1 FROM memoria_historico WHERE id_usuario=? AND data_jogo=? AND status<>'jogando' LIMIT 1", [$userId, $hoje]),
    'bomba'     => jogouHoje($pdo, "SELECT 1 FROM bomba_historico WHERE id_usuario=? AND data_jogo=? AND status<>'jogando' LIMIT 1", [$userId, $hoje]),
    // concluido_em é gravado tanto no acerto quanto ao esgotar as 8 tentativas
    'quemsoueu' => jogouHoje($pdo, "SELECT 1 FROM quemsoueu_partidas WHERE id_usuario=? AND data_jogo=? AND concluido_em IS NOT NULL LIMIT 1", [$userId, $hoje]),
    // O quiz não tem tabela de partida: jogar é ter votado na pergunta do dia.
    'quizdodia' => jogouHoje($pdo, "SELECT 1 FROM quiz_votos v JOIN quiz_perguntas p ON p.id = v.pergunta_id
                                    WHERE v.id_usuario=? AND p.data_uso=? LIMIT 1", [$userId, $hoje]),
];

// Sequências: só valem se a última partida foi hoje ou ontem.
$ontem = (clone $nowBrt)->modify('-1 day')->format('Y-m-d');
$streaks = ['termo' => 0, 'memoria' => 0];
foreach (['termo' => 'termo_historico', 'memoria' => 'memoria_historico'] as $jogo => $tabela) {
    try {
        $st = $pdo->prepare("SELECT data_jogo, streak_count FROM {$tabela} WHERE id_usuario=? ORDER BY data_jogo DESC LIMIT 1");
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if ($row && in_array($row['data_jogo'], [$hoje, $ontem], true)) {
            $streaks[$jogo] = (int)($row['streak_count'] ?? 0);
        }
    } catch (Throwable $e) {}
}

// O denominador é o número de jogos diários do catálogo, sempre. As tabelas
// de histórico são criadas na primeira jogada, e o null de "tabela ainda não
// existe" tirava o jogo da conta — a página mostrava 0/4 com 5 jogos na tela.
$diariosFeitos = count(array_filter($statusDiario, fn($v) => $v === true));
$diariosTotal  = count($statusDiario);

// ── Ranking ─────────────────────────────────────────────────────────────────
// Um SELECT só traz todo mundo; a separação Geral / por liga é feita em PHP,
// porque são os mesmos dados vistos de ângulos diferentes.
// A porcentagem sai de acertos ÷ palpites em eventos que já encerraram —
// palpite de evento aberto ainda não é acerto nem erro.
$rankingBase = [];
try {
    $stmtRk = $pdo->query("
        SELECT g.id, g.pontos, g.fba_points, g.acertos_eventos,
               u.name AS gm, u.photo_url, COALESCE(u.league, 'ROOKIE') AS league,
               (
                   SELECT COUNT(*)
                   FROM palpites p
                   JOIN opcoes o ON o.id = p.opcao_id
                   JOIN eventos e ON e.id = o.evento_id
                   WHERE p.id_usuario = g.id
                     AND e.status = 'encerrada'
                     AND e.vencedor_opcao_id IS NOT NULL
               ) AS palpites_resolvidos
        FROM games_usuarios g
        JOIN users u ON u.id = g.id
    ");
    foreach ($stmtRk->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $resolvidos = (int)$r['palpites_resolvidos'];
        $acertos    = (int)$r['acertos_eventos'];
        $rankingBase[] = [
            'gm'         => $r['gm'],
            'foto'       => getUserPhoto($r['photo_url'] ?? null),
            'league'     => $r['league'],
            'sou_eu'     => (int)$r['id'] === $userId,
            'pontos'     => (int)$r['pontos'],
            'fba_points' => (int)$r['fba_points'],
            'acertos'    => $acertos,
            'resolvidos' => $resolvidos,
            'pct'        => $resolvidos > 0 ? round($acertos * 100 / $resolvidos, 1) : null,
        ];
    }
} catch (Throwable $e) {
    error_log('[games.php] ranking: ' . $e->getMessage());
}

$rankingLigas = ['GERAL' => 'Geral', 'ELITE' => 'ELITE', 'NEXT' => 'NEXT', 'RISE' => 'RISE', 'ROOKIE' => 'ROOKIE'];

/** Ordena por um critério e devolve o top N. Quem não tem % fica no fim. */
function rankingPor(array $base, string $liga, string $criterio, int $limite = 10): array {
    $lista = $liga === 'GERAL' ? $base : array_values(array_filter($base, fn($r) => $r['league'] === $liga));
    usort($lista, function ($a, $b) use ($criterio) {
        if ($criterio === 'pct') {
            // Sem palpite resolvido não entra na disputa de porcentagem.
            if ($a['pct'] === null && $b['pct'] === null) return 0;
            if ($a['pct'] === null) return 1;
            if ($b['pct'] === null) return -1;
            if ($a['pct'] === $b['pct']) return $b['acertos'] <=> $a['acertos'];
            return $b['pct'] <=> $a['pct'];
        }
        return $b[$criterio] <=> $a[$criterio];
    });
    if ($criterio === 'pct') {
        $lista = array_values(array_filter($lista, fn($r) => $r['pct'] !== null));
    }
    return array_slice($lista, 0, $limite);
}

$rankingCriterios = [
    'pontos'     => ['label' => 'Moedas',     'icone' => 'bi-coin',       'cor' => '#f59e0b', 'sufixo' => ''],
    'fba_points' => ['label' => 'FBA Points', 'icone' => 'bi-star-fill',  'cor' => '#fc0025', 'sufixo' => ''],
    'acertos'    => ['label' => 'Acertos',    'icone' => 'bi-trophy-fill','cor' => '#22c55e', 'sufixo' => ''],
    'pct'        => ['label' => '% de acerto','icone' => 'bi-percent',    'cor' => '#3b82f6', 'sufixo' => '%'],
];

// ── Catálogo de minigames ───────────────────────────────────────────────────
$jogosDiarios = [
    ['key' => 'termo',     'nome' => 'Termo',       'sub' => 'Adivinhe a palavra',  'icone' => 'bi-fonts',            'cor' => '#22c55e'],
    ['key' => 'memoria',   'nome' => 'Memória',     'sub' => 'Ache os pares',       'icone' => 'bi-grid-3x3-gap-fill','cor' => '#a855f7'],
    ['key' => 'bomba',     'nome' => 'Bomba',       'sub' => 'Ache os diamantes',   'icone' => 'bi-gem',              'cor' => '#ef4444'],
    ['key' => 'quemsoueu', 'nome' => 'Quem Sou Eu?','sub' => 'Descubra pelas dicas','icone' => 'bi-question-circle',  'cor' => '#3b82f6'],
    ['key' => 'quizdodia', 'nome' => 'Quiz do Dia', 'sub' => 'Vote com a maioria', 'icone' => 'bi-chat-square-quote','cor' => '#eab308'],
];
$jogosLivres = [
    ['key' => 'buildplayer','nome' => 'Build-A-Player','sub' => 'Monte a lenda perfeita','icone' => 'bi-tools',      'cor' => '#f97316'],
    ['key' => 'dreamteam', 'nome' => 'Starting5x5', 'sub' => 'Monte o time e dispute','icone' => 'bi-people-fill',  'cor' => '#6366f1'],
    ['key' => 'flappy',    'nome' => 'Flappy Bird', 'sub' => 'Desvie dos canos',  'icone' => 'bi-airplane',       'cor' => '#f43f5e'],
    ['key' => 'pinguim',   'nome' => 'Pinguim Run', 'sub' => 'Corra e ganhe',     'icone' => 'bi-snow',           'cor' => '#38bdf8'],
    ['key' => 'blackjack', 'nome' => 'Blackjack',   'sub' => 'Chegue a 21',       'icone' => 'bi-suit-heart-fill','cor' => '#ef4444'],
    ['key' => 'roleta',    'nome' => 'Roleta',      'sub' => 'Cassino europeu',   'icone' => 'bi-record-circle',  'cor' => '#22c55e'],
];

$abasValidas = ['games', 'apostas', 'ranking'];
$abaInicial = 'games';
if (isset($_GET['aba']) && in_array($_GET['aba'], $abasValidas, true)) $abaInicial = $_GET['aba'];
if ($apostaMsg || $apostaErro) $abaInicial = 'apostas';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#fc0025">
<title>Games - FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<style>
    :root {
        --red:#fc0025; --red-soft:rgba(252,0,37,.10); --border-red:rgba(252,0,37,.22);
        --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
        --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
        --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
        --amber:#f59e0b; --green:#22c55e; --blue:#3b82f6;
        --sidebar-w:260px; --font:'Montserrat',sans-serif;
        --radius:14px; --radius-sm:10px; --radius-xs:6px;
        --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
    }
    :root[data-theme="light"] {
        --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
        --border:#e3e6ee; --border-md:#d7dbe6; --text:#111217;
        --text-2:#5b6270; --text-3:#657080;
    }
    *,*::before,*::after { box-sizing:border-box; margin:0; padding:0; }
    html,body { height:100%; }
    body { font-family:var(--font); background:var(--bg); color:var(--text); -webkit-font-smoothing:antialiased; }
    .app { display:flex; min-height:100vh; }
        .sidebar { position:fixed; top:0; left:0; width:260px; height:100vh; background:var(--panel); border-right:1px solid var(--border); display:flex; flex-direction:column; z-index:300; overflow-y:auto; scrollbar-width:none; transition:transform var(--t) var(--ease); }
        .sidebar::-webkit-scrollbar { display:none; }
        .sb-brand { padding:22px 18px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center; gap:12px; flex-shrink:0; }
        .sb-logo { width:34px; height:34px; border-radius:9px; background:var(--red); display:flex; align-items:center; justify-content:center; font-weight:800; font-size:13px; color:#fff; flex-shrink:0; }
        .sb-brand-text { font-weight:700; font-size:15px; line-height:1.1; }
        .sb-brand-text span { display:block; font-size:11px; font-weight:400; color:var(--text-2); }
        .sb-team { margin:14px 14px 0; background:var(--panel-2); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px; display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-team img { width:40px; height:40px; border-radius:9px; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-team-name { font-size:13px; font-weight:600; color:var(--text); line-height:1.2; }
        .sb-team-league { font-size:11px; color:var(--red); font-weight:600; }
        .sb-nav { flex:1; padding:12px 10px 8px; }
        .sb-section { font-size:10px; font-weight:600; letter-spacing:1.2px; text-transform:uppercase; color:var(--text-3); padding:12px 10px 6px; }
        .sb-nav a { font-family:'Inter',sans-serif; display:flex; align-items:center; gap:10px; padding:10px 10px; border-radius:var(--radius-sm); color:var(--text-2); font-size:13px; font-weight:500; text-decoration:none; margin-bottom:2px; transition:all var(--t) var(--ease); }
        .sb-nav a i { font-size:15px; width:18px; text-align:center; flex-shrink:0; }
        .sb-nav a:hover { background:var(--panel-2); color:var(--text); }
        .sb-nav a.active { background:var(--red-soft); color:var(--red); font-weight:600; }
        .sb-nav a.active i { color:var(--red); }
        .sb-footer { padding:12px 14px; border-top:1px solid var(--border); display:flex; align-items:center; gap:10px; flex-shrink:0; }
        .sb-avatar { width:30px; height:30px; border-radius:50%; object-fit:cover; border:1px solid var(--border-md); flex-shrink:0; }
        .sb-username { font-size:12px; font-weight:500; color:var(--text); flex:1; min-width:0; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
        .sb-logout { width:26px; height:26px; border-radius:7px; background:transparent; border:1px solid var(--border); color:var(--text-2); display:flex; align-items:center; justify-content:center; font-size:12px; cursor:pointer; transition:all var(--t) var(--ease); text-decoration:none; flex-shrink:0; }
        .sb-logout:hover { background:var(--red-soft); border-color:var(--red); color:var(--red); }
        .sb-theme-toggle { margin:0 14px 12px; padding:8px 10px; border-radius:10px; border:1px solid var(--border); background:var(--panel-2); color:var(--text); display:flex; align-items:center; justify-content:center; gap:8px; font-size:12px; font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); }
        .sb-theme-toggle:hover { border-color:var(--border-red); color:var(--red); }
    .main { flex:1; margin-left:var(--sidebar-w); display:flex; flex-direction:column; min-width:0; }

    .page-hero { padding:28px 32px 0; }
    .page-eyebrow { font-size:11px; font-weight:600; letter-spacing:1.4px; text-transform:uppercase; color:var(--red); margin-bottom:4px; }
    .page-title { font-size:26px; font-weight:800; line-height:1.1; display:flex; align-items:center; gap:10px; }
    .page-title i { color:var(--red); }
    .page-sub { font-size:13px; color:var(--text-2); margin-top:4px; }
    .content { padding:24px 32px 56px; flex:1; }

    .topbar { display:none; position:fixed; top:0; left:0; right:0; height:54px; background:var(--panel); border-bottom:1px solid var(--border); align-items:center; padding:0 16px; gap:12px; z-index:260; }
    .topbar-title { font-weight:700; font-size:15px; flex:1; }
    .topbar-title em { color:var(--red); font-style:normal; }
    .menu-btn { background:none; border:none; color:var(--text); font-size:22px; cursor:pointer; }
    .sb-overlay { display:none; position:fixed; inset:0; background:rgba(0,0,0,.6); z-index:270; }
    .sb-overlay.open { display:block; }

    /* saldos */
    .saldos { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:22px; }
    .saldo { display:flex; align-items:center; gap:9px; background:var(--panel); border:1px solid var(--border-md);
             border-radius:var(--radius-sm); padding:10px 16px; }
    .saldo i { font-size:17px; }
    .saldo-val { font-size:17px; font-weight:800; line-height:1; }
    .saldo-lbl { font-size:10.5px; font-weight:700; letter-spacing:.5px; text-transform:uppercase; color:var(--text-3); margin-top:2px; }

    /* abas */
    .g-tabs { display:flex; border-bottom:1px solid var(--border); margin-bottom:26px; }
    .g-tab { display:flex; align-items:center; gap:8px; padding:12px 24px; font-size:13px; font-weight:600;
             color:var(--text-2); cursor:pointer; border:none; background:none; position:relative;
             font-family:var(--font); transition:color var(--t) var(--ease); }
    .g-tab::after { content:''; position:absolute; bottom:-1px; left:0; right:0; height:2px; background:transparent;
                    transition:background var(--t) var(--ease); }
    .g-tab.active { color:var(--text); }
    .g-tab.active::after { background:var(--red); }
    .g-pane { display:none; }
    .g-pane.active { display:block; }

    /* catálogo */
    .sec-label { font-size:11px; font-weight:800; letter-spacing:1.2px; text-transform:uppercase;
                 width:100%;
                 color:var(--text-3); margin:0 0 14px; display:flex; align-items:center; gap:8px; }
    .sec-label i { color:var(--red); font-size:13px; }
    .grid { display:grid; grid-template-columns:repeat(auto-fill,minmax(178px,1fr)); gap:14px; margin-bottom:34px; }
    .card-jogo { display:flex; flex-direction:column; align-items:flex-start; gap:3px; text-decoration:none;
                 background:var(--panel); border:1px solid var(--border); border-radius:var(--radius);
                 padding:18px 16px; transition:all var(--t) var(--ease); position:relative; }
    .card-jogo:hover { border-color:var(--border-md); transform:translateY(-2px); }
    .card-jogo:active { transform:translateY(0); }
    .card-topo { display:flex; align-items:flex-start; justify-content:space-between;
                 width:100%; margin-bottom:9px; }
    .card-jogo .ico { width:42px; height:42px; border-radius:11px; display:flex; align-items:center;
                      justify-content:center; font-size:20px; }
    .card-jogo .nome { font-size:14px; font-weight:700; color:var(--text); }
    .card-jogo .sub { font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:7px; }

    /* Já jogado hoje: o card recua um pouco, sem sumir — ainda dá pra rejogar */
    .card-jogo.feito { border-color:rgba(34,197,94,.22); }
    .card-jogo.feito .ico { opacity:.55; }
    .card-jogo.feito .nome { color:var(--text-2); }
    .card-jogo.feito .sub { color:var(--green); }

    .selo { display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .selo.ok { width:20px; height:20px; border-radius:50%; background:rgba(34,197,94,.15);
               color:var(--green); font-size:11px; }
    .selo.pendente { width:7px; height:7px; border-radius:50%; background:var(--red); margin-top:6px;
                     box-shadow:0 0 0 3px color-mix(in srgb, var(--red) 18%, transparent); }

    .streak { display:inline-flex; align-items:center; gap:3px; font-size:10.5px; font-weight:800;
              color:var(--amber); background:rgba(245,158,11,.12); border-radius:20px; padding:1px 7px; }

    .sec-contador { margin-left:auto; font-size:10.5px; font-weight:800; letter-spacing:.3px;
                    color:var(--text-3); background:var(--panel-2); border:1px solid var(--border);
                    border-radius:20px; padding:3px 10px; text-transform:none; }
    .sec-contador.completo { color:var(--green); border-color:rgba(34,197,94,.3);
                             background:rgba(34,197,94,.10); }

    /* apostas */
    .card { background:var(--panel); border:1px solid var(--border); border-radius:var(--radius); margin-bottom:16px; }
    .card-head { padding:14px 18px; border-bottom:1px solid var(--border); display:flex; align-items:center;
                 justify-content:space-between; gap:12px; flex-wrap:wrap; }
    .card-head-left { display:flex; align-items:center; gap:9px; font-weight:700; font-size:14px; }
    .card-head-left i { color:var(--red); }
    .prazo { font-size:11.5px; color:var(--text-3); display:flex; align-items:center; gap:5px;
             background:var(--panel-2); border:1px solid var(--border); border-radius:20px; padding:3px 10px; }
    .prazo.urgente { color:var(--amber); border-color:rgba(245,158,11,.3); background:rgba(245,158,11,.10); }
    .card-body { padding:16px 18px; }
    .opcoes { display:flex; gap:10px; flex-wrap:wrap; }
    .op-btn { flex:1; min-width:150px; background:var(--panel-2); border:1px solid var(--border-md); color:var(--text);
              border-radius:var(--radius-sm); padding:12px 16px; font-family:var(--font); font-size:13px;
              font-weight:600; cursor:pointer; transition:all var(--t) var(--ease); text-align:left; }
    .op-btn:hover { border-color:var(--border-red); color:var(--red); }
    .op-btn.escolhida { border-color:var(--green); color:var(--green); background:rgba(34,197,94,.08); }
    .op-btn.escolhida::before { content:'\F26E'; font-family:'bootstrap-icons'; margin-right:7px; }

    .alerta { border-radius:var(--radius-sm); padding:11px 15px; font-size:13px; margin-bottom:16px; }
    .alerta.ok { background:rgba(34,197,94,.10); border:1px solid rgba(34,197,94,.30); color:var(--green); }
    .alerta.err { background:rgba(239,68,68,.10); border:1px solid rgba(239,68,68,.30); color:#ef4444; }

    .vazio { text-align:center; padding:44px 20px; color:var(--text-3); }
    .vazio i { font-size:30px; display:block; margin-bottom:10px; opacity:.5; }
    .vazio p { font-size:13px; }

    table.hist { width:100%; border-collapse:collapse; }
    table.hist th { text-align:left; font-size:10.5px; font-weight:800; letter-spacing:.6px; text-transform:uppercase;
                    color:var(--text-3); padding:0 12px 10px; }
    table.hist td { padding:11px 12px; border-top:1px solid var(--border); font-size:13px; }
    .pill { font-size:10.5px; font-weight:700; padding:3px 9px; border-radius:20px; white-space:nowrap; }
    .pill.aberta { background:rgba(59,130,246,.12); color:var(--blue); }
    .pill.acertou { background:rgba(34,197,94,.12); color:var(--green); }
    .pill.errou { background:rgba(239,68,68,.12); color:#ef4444; }
    .tbl-wrap { overflow-x:auto; }

    /* ranking */
    .rk-ligas { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:20px; }
    .rk-liga { background:var(--panel); border:1px solid var(--border-md); color:var(--text-2);
        font-family:var(--font); font-size:12.5px; font-weight:700; border-radius:20px;
        padding:7px 16px; cursor:pointer; transition:all var(--t) var(--ease); }
    .rk-liga:hover { color:var(--text); }
    .rk-liga.active { background:var(--red-soft); border-color:var(--border-red); color:var(--red); }
    .rk-bloco { display:none; }
    .rk-bloco.active { display:block; }
    .rk-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(280px,1fr)); gap:16px; }
    .rk-linha { display:flex; align-items:center; gap:11px; padding:8px 8px; border-radius:var(--radius-sm); }
    .rk-linha:hover { background:var(--panel-2); }
    .rk-pos { width:22px; text-align:center; font-size:12px; font-weight:800; color:var(--text-3); flex-shrink:0; }
    /* Pódio: ouro, prata e bronze dão a leitura das 3 primeiras posições sem
       precisar contar as linhas. */
    .rk-linha:nth-child(1) .rk-pos { color:#f5c542; }
    .rk-linha:nth-child(2) .rk-pos { color:#c9ced6; }
    .rk-linha:nth-child(3) .rk-pos { color:#cd8032; }
    .rk-linha:nth-child(1) .rk-foto { border-color:#f5c542; }
    .rk-linha:nth-child(2) .rk-foto { border-color:#c9ced6; }
    .rk-linha:nth-child(3) .rk-foto { border-color:#cd8032; }
    .rk-linha:nth-child(1) .rk-nome { font-weight:700; }
    /* Destaca a linha do próprio GM, pra ele se achar na lista */
    .rk-linha.eu { background:var(--red-soft); }
    .rk-linha.eu .rk-nome { color:var(--red); font-weight:700; }
    .rk-foto { width:30px; height:30px; border-radius:50%; object-fit:cover;
        border:1px solid var(--border-md); flex-shrink:0; }
    .rk-nome { flex:1; min-width:0; font-size:13px; font-weight:600;
        white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .rk-val { font-size:13.5px; font-weight:800; flex-shrink:0;
        font-variant-numeric:tabular-nums; }

    @media (max-width:992px) {
        :root { --sidebar-w: 0px; }
        .main { margin-left:0; padding-top:54px; }
        .topbar { display:flex; }
            .sidebar { transform:translateX(-260px); }
            .sidebar.open { transform:translateX(0); }
        .page-hero { padding:20px 18px 0; }
        .content { padding:18px 18px 44px; }
        .g-tab { flex:1; justify-content:center; padding:12px 10px; }
    }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
    @media (prefers-reduced-motion: reduce) { *,*::before,*::after { transition-duration:.01ms !important; } }
</style>
</head>
<body>
<div class="app">

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
    <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
    <div class="topbar-title">FBA <em>Manager</em></div>
</header>

<main class="main">
    <div class="page-hero">
        <div class="page-eyebrow">Diversão da liga</div>
        <h1 class="page-title"><i class="bi bi-controller"></i> Games</h1>
        <p class="page-sub">Minigames diários pra ganhar moedas e as apostas dos eventos da FBA.</p>
    </div>

    <div class="content">

        <div class="saldos">
            <div class="saldo">
                <i class="bi bi-coin" style="color:var(--amber)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['pontos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Moedas</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-star-fill" style="color:var(--red)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['fba_points'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">FBA Points</div>
                </div>
            </div>
            <div class="saldo">
                <i class="bi bi-trophy-fill" style="color:var(--green)"></i>
                <div>
                    <div class="saldo-val"><?= number_format((int)$perfil['acertos_eventos'], 0, ',', '.') ?></div>
                    <div class="saldo-lbl">Acertos</div>
                </div>
            </div>
        </div>

        <div class="g-tabs">
            <button class="g-tab <?= $abaInicial === 'games' ? 'active' : '' ?>" data-aba="games" onclick="trocarAba('games')">
                <i class="bi bi-joystick"></i> Games
            </button>
            <button class="g-tab <?= $abaInicial === 'apostas' ? 'active' : '' ?>" data-aba="apostas" onclick="trocarAba('apostas')">
                <i class="bi bi-graph-up-arrow"></i> Apostas
            </button>
            <button class="g-tab <?= $abaInicial === 'ranking' ? 'active' : '' ?>" data-aba="ranking" onclick="trocarAba('ranking')">
                <i class="bi bi-bar-chart-fill"></i> Ranking
            </button>
        </div>

        <!-- ── Aba Games ─────────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'games' ? 'active' : '' ?>" id="pane-games">
            <div class="sec-label">
                <i class="bi bi-calendar-check-fill"></i> Minigames diários
                <?php if ($diariosTotal > 0): ?>
                <span class="sec-contador <?= $diariosFeitos === $diariosTotal ? 'completo' : '' ?>">
                    <?= $diariosFeitos ?>/<?= $diariosTotal ?> hoje
                </span>
                <?php endif; ?>
            </div>
            <div class="grid">
                <?php foreach ($jogosDiarios as $j):
                    $feito  = $statusDiario[$j['key']] ?? null;
                    $streak = $streaks[$j['key']] ?? 0;
                ?>
                <a class="card-jogo <?= $feito === true ? 'feito' : '' ?>" href="/games/games/index.php?game=<?= urlencode($j['key']) ?>">
                    <div class="card-topo">
                        <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                        <?php if ($feito === true): ?>
                            <span class="selo ok" title="Você já jogou hoje"><i class="bi bi-check-lg"></i></span>
                        <?php elseif ($feito === false): ?>
                            <span class="selo pendente" title="Ainda não jogou hoje"></span>
                        <?php endif; ?>
                    </div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub">
                        <?php if ($feito === true): ?>
                            Jogado hoje
                        <?php else: ?>
                            <?= htmlspecialchars($j['sub']) ?>
                        <?php endif; ?>
                        <?php if ($streak > 1): ?>
                            <span class="streak" title="Sequência de dias"><i class="bi bi-fire"></i><?= $streak ?></span>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>

            <div class="sec-label"><i class="bi bi-joystick"></i> Minigames</div>
            <div class="grid">
                <?php foreach ($jogosLivres as $j): ?>
                <a class="card-jogo" href="/games/games/index.php?game=<?= urlencode($j['key']) ?>">
                    <div class="ico" style="background:<?= $j['cor'] ?>1f;color:<?= $j['cor'] ?>"><i class="bi <?= $j['icone'] ?>"></i></div>
                    <div class="nome"><?= htmlspecialchars($j['nome']) ?></div>
                    <div class="sub"><?= htmlspecialchars($j['sub']) ?></div>
                </a>
                <?php endforeach; ?>
            </div>

        </div>

        <!-- ── Aba Apostas ───────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'apostas' ? 'active' : '' ?>" id="pane-apostas">
            <?php if ($apostaMsg): ?>
                <div class="alerta ok"><i class="bi bi-check-circle-fill"></i> <?= htmlspecialchars($apostaMsg) ?></div>
            <?php endif; ?>
            <?php if ($apostaErro): ?>
                <div class="alerta err"><i class="bi bi-exclamation-triangle-fill"></i> <?= htmlspecialchars($apostaErro) ?></div>
            <?php endif; ?>

            <div class="sec-label"><i class="bi bi-lightning-charge-fill"></i> Eventos abertos</div>
            <?php if (empty($eventos)): ?>
                <div class="card"><div class="vazio">
                    <i class="bi bi-hourglass-split"></i>
                    <p>Nenhum evento aberto agora. Assim que a organização abrir um, ele aparece aqui.</p>
                </div></div>
            <?php else: ?>
                <?php foreach ($eventos as $ev): ?>
                <div class="card">
                    <div class="card-head">
                        <div class="card-head-left"><i class="bi bi-flag-fill"></i> <?= htmlspecialchars($ev['nome']) ?></div>
                        <div class="prazo <?= !empty($ev['urgente']) ? 'urgente' : '' ?>"
                             title="Fecha em <?= date('d/m/Y \à\s H:i', strtotime($ev['data_limite'])) ?>">
                            <i class="bi bi-clock"></i>
                            <?= htmlspecialchars($ev['prazo_txt']) ?>
                        </div>
                    </div>
                    <div class="card-body">
                        <div class="opcoes">
                            <?php foreach ($ev['opcoes'] as $op): ?>
                            <form method="POST" style="flex:1;min-width:150px;display:flex">
                                <input type="hidden" name="opcao_id" value="<?= (int)$op['id'] ?>">
                                <button type="submit" class="op-btn <?= (int)$ev['meu_palpite'] === (int)$op['id'] ? 'escolhida' : '' ?>">
                                    <?= htmlspecialchars($op['descricao']) ?>
                                </button>
                            </form>
                            <?php endforeach; ?>
                        </div>
                        <?php if ($ev['meu_palpite']): ?>
                        <div style="font-size:11.5px;color:var(--text-3);margin-top:11px">
                            <i class="bi bi-info-circle"></i> Dá pra trocar sua escolha até o prazo acabar.
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endforeach; ?>
            <?php endif; ?>

            <div class="sec-label" style="margin-top:30px"><i class="bi bi-clock-history"></i> Meus palpites</div>
            <div class="card">
                <?php if (empty($historico)): ?>
                    <div class="vazio"><i class="bi bi-inbox"></i><p>Você ainda não deu nenhum palpite.</p></div>
                <?php else: ?>
                <div class="card-body tbl-wrap">
                    <table class="hist">
                        <thead><tr><th>Evento</th><th>Sua escolha</th><th>Quando</th><th>Resultado</th></tr></thead>
                        <tbody>
                        <?php foreach ($historico as $h): ?>
                            <tr>
                                <td><?= htmlspecialchars($h['evento']) ?></td>
                                <td style="color:var(--text-2)"><?= htmlspecialchars($h['escolha']) ?></td>
                                <td style="color:var(--text-3);font-size:12px"><?= date('d/m/Y', strtotime($h['data_palpite'])) ?></td>
                                <td>
                                    <?php if ($h['evento_status'] !== 'encerrada' || $h['vencedor_opcao_id'] === null): ?>
                                        <span class="pill aberta">Em aberto</span>
                                    <?php elseif ((int)$h['vencedor_opcao_id'] === (int)$h['opcao_id']): ?>
                                        <span class="pill acertou">Acertou</span>
                                    <?php else: ?>
                                        <span class="pill errou">Errou</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- ── Aba Ranking ───────────────────────────────────────────── -->
        <div class="g-pane <?= $abaInicial === 'ranking' ? 'active' : '' ?>" id="pane-ranking">
            <div class="rk-ligas">
                <?php foreach ($rankingLigas as $chave => $rotulo): ?>
                <button type="button" class="rk-liga <?= $chave === 'GERAL' ? 'active' : '' ?>"
                        data-liga="<?= $chave ?>" onclick="trocarLigaRanking('<?= $chave ?>')">
                    <?= htmlspecialchars($rotulo) ?>
                </button>
                <?php endforeach; ?>
            </div>

            <?php foreach ($rankingLigas as $chave => $rotulo): ?>
            <div class="rk-bloco <?= $chave === 'GERAL' ? 'active' : '' ?>" id="rk-<?= $chave ?>">
                <div class="rk-grid">
                    <?php foreach ($rankingCriterios as $crit => $meta):
                        $lista = rankingPor($rankingBase, $chave, $crit);
                    ?>
                    <div class="card">
                        <div class="card-head">
                            <div class="card-head-left">
                                <i class="bi <?= $meta['icone'] ?>" style="color:<?= $meta['cor'] ?>"></i>
                                <?= htmlspecialchars($meta['label']) ?>
                            </div>
                        </div>
                        <div class="card-body" style="padding:8px 10px 12px">
                            <?php if (empty($lista)): ?>
                                <div class="vazio" style="padding:26px 12px"><p>Sem dados ainda.</p></div>
                            <?php else: ?>
                                <?php foreach ($lista as $i => $r): ?>
                                <div class="rk-linha <?= !empty($r['sou_eu']) ? 'eu' : '' ?>">
                                    <div class="rk-pos"><?= $i + 1 ?></div>
                                    <img class="rk-foto" src="<?= htmlspecialchars($r['foto']) ?>" alt="">
                                    <div class="rk-nome"><?= htmlspecialchars($r['gm']) ?></div>
                                    <div class="rk-val" style="color:<?= $meta['cor'] ?>">
                                        <?= $crit === 'pct'
                                            ? number_format($r['pct'], 1, ',', '.')
                                            : number_format($r[$crit], 0, ',', '.') ?><?= $meta['sufixo'] ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php
                // Quem mais ajudou a manter os elencos em dia. Fica no fim do
                // ranking, e não numa aba própria, porque é o mesmo assunto:
                // placar de gente. Só a liga de quem está olhando — o ranking
                // de atualização é da comunidade dela.
                $ligaRk = strtoupper((string)($team['league'] ?? ''));
                $rkAtualiza = $ligaRk ? atualizacaoRanking($pdo, $ligaRk, 12) : [];
            ?>
            <?php if ($rkAtualiza): ?>
            <div class="card" style="margin-top:16px">
                <div class="card-head">
                    <div class="card-head-left">
                        <i class="bi bi-pencil-square" style="color:#22c55e"></i>
                        Quem mais atualizou elencos · <?= htmlspecialchars($ligaRk) ?>
                    </div>
                </div>
                <div class="card-body" style="padding:8px 10px 12px">
                    <?php foreach ($rkAtualiza as $i => $r): ?>
                    <div class="rk-linha <?= (int)$r['user_id'] === (int)$userId ? 'eu' : '' ?>">
                        <div class="rk-pos"><?= $i + 1 ?></div>
                        <div class="rk-nome"><?= htmlspecialchars($r['gm']) ?></div>
                        <div class="rk-val" style="color:#22c55e">
                            <?= (int)$r['times'] ?> time<?= (int)$r['times'] === 1 ? '' : 's' ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                    <p style="font-size:11.5px;color:var(--text-3,#8a8a99);margin:10px 4px 0;line-height:1.5">
                        Time parado sem skills ou estatísticas pode ser preenchido por qualquer GM da liga,
                        uma vez cada, em <a href="/teams.php" style="color:inherit;text-decoration:underline">Times</a>.
                    </p>
                </div>
            </div>
            <?php endif; ?>
        </div>

    </div>
</main>
</div>

<script>
function trocarAba(aba) {
    document.querySelectorAll('.g-tab').forEach(t => t.classList.toggle('active', t.dataset.aba === aba));
    document.querySelectorAll('.g-pane').forEach(p => p.classList.toggle('active', p.id === 'pane-' + aba));
    try { sessionStorage.setItem('gamesAba', aba); } catch (e) {}
    history.replaceState(null, '', aba === 'games' ? location.pathname : '?aba=' + aba);
}

function trocarLigaRanking(liga) {
    document.querySelectorAll('.rk-liga').forEach(b => b.classList.toggle('active', b.dataset.liga === liga));
    document.querySelectorAll('.rk-bloco').forEach(b => b.classList.toggle('active', b.id === 'rk-' + liga));
}

(function () {
    // Só restaura a aba salva quando a URL não pediu uma explicitamente.
    if (!location.search.includes('aba=')) {
        try {
            const salva = sessionStorage.getItem('gamesAba');
            if (salva && salva !== 'games') trocarAba(salva);
        } catch (e) {}
    }
    const menuBtn = document.getElementById('menuBtn');
    const sidebar = document.getElementById('sidebar');
    const overlay = document.getElementById('sbOverlay');
    menuBtn?.addEventListener('click', () => { sidebar?.classList.add('open'); overlay?.classList.add('open'); });
    overlay?.addEventListener('click', () => { sidebar?.classList.remove('open'); overlay?.classList.remove('open'); });
})();
</script>
</body>
</html>
