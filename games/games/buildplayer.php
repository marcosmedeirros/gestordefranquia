<?php
/**
 * buildplayer.php — Build-A-Player
 *
 * Escolhe Guard ou Big, gira as duas roletas (time + lenda) e leva UM atributo
 * da lenda sorteada pro seu build. Dez giros, dez slots, um OVR no fim.
 *
 * O sorteio é do SERVIDOR, sempre. Se o giro fosse do cliente dava pra ficar
 * regirando até cair a lenda certa — e o ranking não valeria nada.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/build_notas.php';

$user_id = (int)$_SESSION['user_id'];
$hoje    = date('Y-m-d');

// Recompensas por posição no Top 100 (o que você pediu).
const BP_MOEDAS = [1 => 500, 5 => 100, 10 => 50];

buildGarantirTabelaNotas($pdo);

function bpGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;

    $pdo->exec("CREATE TABLE IF NOT EXISTS build_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        grupo ENUM('GUARD','BIG') NOT NULL,
        slots TEXT NOT NULL,
        usados TEXT NOT NULL,
        atual_player_id INT NULL,
        ovr INT NULL,
        posicao_rank INT NULL,
        moedas INT NOT NULL DEFAULT 0,
        concluido_em DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_bp_dia (id_usuario, data_jogo),
        INDEX idx_bp_rank (ovr)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
bpGarantirTabelas($pdo);

/** Partida do dia (ou null). */
function bpPartida(PDO $pdo, int $userId, string $hoje): ?array
{
    $st = $pdo->prepare("SELECT * FROM build_partidas WHERE id_usuario=? AND data_jogo=? LIMIT 1");
    $st->execute([$userId, $hoje]);
    $p = $st->fetch(PDO::FETCH_ASSOC) ?: null;
    if ($p) {
        $p['slots']  = json_decode((string)$p['slots'], true) ?: [];
        $p['usados'] = json_decode((string)$p['usados'], true) ?: [];
    }
    return $p;
}

/** Lenda com nota, do grupo pedido, que ainda não saiu nesta partida. */
function bpSortearLenda(PDO $pdo, string $grupo, array $usados): ?array
{
    $fora = '';
    $params = [$grupo];
    if ($usados) {
        $fora = ' AND n.player_id NOT IN (' . implode(',', array_fill(0, count($usados), '?')) . ')';
        $params = array_merge($params, array_map('intval', $usados));
    }

    $st = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                         FROM build_notas n
                         INNER JOIN hoopgrid_players p ON p.id = n.player_id
                         WHERE n.posicao_grupo = ?{$fora}
                         ORDER BY RAND() LIMIT 1");
    $st->execute($params);
    $l = $st->fetch(PDO::FETCH_ASSOC) ?: null;

    // Pool menor que os 10 slots: repete lenda em vez de deixar a partida
    // sem saída. Travar aqui prenderia o jogador — ele não terminaria o build
    // nem poderia começar outro, porque só se joga uma vez por dia.
    if (!$l && $usados) {
        $st2 = $pdo->prepare("SELECT n.*, p.nome, p.time_atual, p.times, p.altura, p.peso
                              FROM build_notas n
                              INNER JOIN hoopgrid_players p ON p.id = n.player_id
                              WHERE n.posicao_grupo = ? ORDER BY RAND() LIMIT 1");
        $st2->execute([$grupo]);
        $l = $st2->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    if (!$l) return null;

    // Time exibido na roleta: o atual, ou o primeiro da carreira.
    $time = $l['time_atual'] ?: '';
    if ($time === '' && !empty($l['times'])) {
        $lista = json_decode((string)$l['times'], true);
        if (is_array($lista) && $lista) $time = (string)$lista[0];
    }
    $l['time_exibido'] = $time ?: 'NBA';
    return $l;
}

/** OVR do build: média dos valores das dez letras escolhidas. */
function bpCalcularOvr(array $slots): int
{
    $attrs = array_keys(buildAtributos());
    $soma = 0;
    foreach ($attrs as $a) {
        $soma += buildValorDaLetra((int)($slots[$a]['nivel'] ?? 0));
    }
    return (int)round($soma / count($attrs));
}

/**
 * Posição no Top 100 de todos os tempos. Empate no OVR desempata pela
 * partida mais antiga — quem chegou primeiro fica na frente.
 */
function bpPosicaoNoRank(PDO $pdo, int $ovr, int $partidaId): int
{
    $st = $pdo->prepare("SELECT COUNT(*) + 1 FROM build_partidas
                         WHERE concluido_em IS NOT NULL
                           AND (ovr > ? OR (ovr = ? AND id < ?))");
    $st->execute([$ovr, $ovr, $partidaId]);
    return (int)$st->fetchColumn();
}

/** Moedas conforme a posição. Fora do top 10 não paga. */
function bpMoedasDaPosicao(int $pos): int
{
    if ($pos <= 1)  return BP_MOEDAS[1];
    if ($pos <= 5)  return BP_MOEDAS[5];
    if ($pos <= 10) return BP_MOEDAS[10];
    return 0;
}

// ── AÇÕES (AJAX) ────────────────────────────────────────────────────────────
if (($_POST['acao'] ?? '') !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $acao = $_POST['acao'];
    $attrs = array_keys(buildAtributos());

    try {
        if ($acao === 'comecar') {
            $grupo = ($_POST['grupo'] ?? '') === 'BIG' ? 'BIG' : 'GUARD';
            if (bpPartida($pdo, $user_id, $hoje)) {
                echo json_encode(['ok' => false, 'msg' => 'Você já jogou hoje. Volte amanhã!']);
                exit;
            }
            // Sem lenda no grupo não dá pra montar nada — melhor avisar antes
            // do que abrir uma partida que não fecha.
            $st = $pdo->prepare("SELECT COUNT(*) FROM build_notas WHERE posicao_grupo = ?");
            $st->execute([$grupo]);
            if ((int)$st->fetchColumn() === 0) {
                echo json_encode(['ok' => false, 'msg' => 'Ainda não há lendas cadastradas nesse tipo de build.']);
                exit;
            }
            $vazios = [];
            foreach ($attrs as $a) $vazios[$a] = null;

            $pdo->prepare("INSERT INTO build_partidas (id_usuario, data_jogo, grupo, slots, usados) VALUES (?,?,?,?,?)")
                ->execute([$user_id, $hoje, $grupo, json_encode($vazios), json_encode([])]);

            echo json_encode(['ok' => true]);
            exit;
        }

        $partida = bpPartida($pdo, $user_id, $hoje);
        if (!$partida) { echo json_encode(['ok' => false, 'msg' => 'Comece uma partida primeiro.']); exit; }
        if ($partida['concluido_em']) { echo json_encode(['ok' => false, 'msg' => 'Esta partida já terminou.']); exit; }

        if ($acao === 'girar') {
            // Uma lenda por vez: só gira de novo depois de escolher o atributo.
            if (!empty($partida['atual_player_id'])) {
                echo json_encode(['ok' => false, 'msg' => 'Escolha um atributo da lenda atual antes de girar.']);
                exit;
            }
            $lenda = bpSortearLenda($pdo, $partida['grupo'], $partida['usados']);
            if (!$lenda) { echo json_encode(['ok' => false, 'msg' => 'Acabaram as lendas disponíveis.']); exit; }

            $pdo->prepare("UPDATE build_partidas SET atual_player_id=? WHERE id=?")
                ->execute([(int)$lenda['player_id'], (int)$partida['id']]);

            $notas = [];
            foreach ($attrs as $a) {
                $notas[$a] = ['nivel' => (int)$lenda[$a], 'letra' => BUILD_LETRAS[(int)$lenda[$a]]];
            }
            echo json_encode([
                'ok' => true,
                'lenda' => [
                    'id'     => (int)$lenda['player_id'],
                    'nome'   => $lenda['nome'],
                    'time'   => $lenda['time_exibido'],
                    'altura' => $lenda['altura'],
                    'peso'   => $lenda['peso'],
                    'notas'  => $notas,
                ],
            ]);
            exit;
        }

        if ($acao === 'escolher') {
            $attr = (string)($_POST['atributo'] ?? '');
            if (!in_array($attr, $attrs, true)) { echo json_encode(['ok' => false, 'msg' => 'Atributo inválido.']); exit; }
            if (empty($partida['atual_player_id'])) { echo json_encode(['ok' => false, 'msg' => 'Gire primeiro.']); exit; }
            if ($partida['slots'][$attr] !== null) { echo json_encode(['ok' => false, 'msg' => 'Esse slot já está preenchido.']); exit; }

            $st = $pdo->prepare("SELECT n.*, p.nome FROM build_notas n
                                 INNER JOIN hoopgrid_players p ON p.id = n.player_id
                                 WHERE n.player_id = ? LIMIT 1");
            $st->execute([(int)$partida['atual_player_id']]);
            $lenda = $st->fetch(PDO::FETCH_ASSOC);
            if (!$lenda) { echo json_encode(['ok' => false, 'msg' => 'Lenda não encontrada.']); exit; }

            $slots = $partida['slots'];
            $slots[$attr] = [
                'nivel' => (int)$lenda[$attr],
                'letra' => BUILD_LETRAS[(int)$lenda[$attr]],
                'de'    => $lenda['nome'],
            ];
            $usados = $partida['usados'];
            $usados[] = (int)$partida['atual_player_id'];

            $faltam = count(array_filter($slots, fn($s) => $s === null));
            $terminou = $faltam === 0;

            $ovr = $terminou ? bpCalcularOvr($slots) : null;
            $pdo->prepare("UPDATE build_partidas SET slots=?, usados=?, atual_player_id=NULL, ovr=?, concluido_em=? WHERE id=?")
                ->execute([
                    json_encode($slots), json_encode($usados), $ovr,
                    $terminou ? date('Y-m-d H:i:s') : null, (int)$partida['id'],
                ]);

            $resposta = ['ok' => true, 'slots' => $slots, 'faltam' => $faltam, 'terminou' => $terminou];

            if ($terminou) {
                $pos = bpPosicaoNoRank($pdo, (int)$ovr, (int)$partida['id']);
                $moedas = bpMoedasDaPosicao($pos);
                $pdo->prepare("UPDATE build_partidas SET posicao_rank=?, moedas=? WHERE id=?")
                    ->execute([$pos, $moedas, (int)$partida['id']]);
                if ($moedas > 0) {
                    $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                        ->execute([$moedas, $user_id]);
                }
                $resposta += ['ovr' => $ovr, 'posicao' => $pos, 'moedas' => $moedas];
            }

            echo json_encode($resposta);
            exit;
        }

        echo json_encode(['ok' => false, 'msg' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        error_log('[buildplayer] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'msg' => 'Erro interno. Tente de novo.']);
    }
    exit;
}

// ── TELA ────────────────────────────────────────────────────────────────────
$partida   = bpPartida($pdo, $user_id, $hoje);
$ATRIBUTOS = buildAtributos();
$temNotas  = (int)$pdo->query("SELECT COUNT(*) FROM build_notas")->fetchColumn();

// A posição exibida é recalculada AGORA, não a gravada no fim da partida:
// se alguém te ultrapassou depois, o certo é mostrar onde você está hoje.
// As moedas continuam sendo as da posição no momento em que você fechou —
// prêmio já pago não se recalcula.
$posicaoAtual = null;
if ($partida && $partida['concluido_em']) {
    $posicaoAtual = bpPosicaoNoRank($pdo, (int)$partida['ovr'], (int)$partida['id']);
}

$preenchidos = 0;
if ($partida) {
    foreach ($ATRIBUTOS as $c => $_) if (!empty($partida['slots'][$c])) $preenchidos++;
}

$topGeral = $pdo->query("SELECT b.ovr, b.grupo, u.nome
                         FROM build_partidas b
                         INNER JOIN games_usuarios u ON u.id = b.id_usuario
                         WHERE b.concluido_em IS NOT NULL
                         ORDER BY b.ovr DESC, b.id ASC LIMIT 10")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Build-A-Player</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --blue:#3b82f6;--blue-soft:rgba(59,130,246,.12);
  --radius:14px;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

/* TOP BAR — a mesma dos outros jogos */
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}

.main{max-width:620px;margin:0 auto;padding:16px 12px 60px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:16px;margin-bottom:14px}
.card-title{font-size:10px;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:var(--text2);margin-bottom:12px;display:flex;align-items:center;justify-content:space-between;gap:8px}

/* ── ESCOLHA DO TIPO ── */
.intro{text-align:center;padding:8px 0 18px}
.intro h1{font-size:22px;font-weight:900;letter-spacing:-.4px;margin-bottom:8px}
.intro p{font-size:13px;color:var(--text2);line-height:1.55;max-width:420px;margin:0 auto}
.tipos{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.tipo{background:var(--panel2);border:1.5px solid var(--border);border-radius:var(--radius);padding:22px 14px;text-align:center;cursor:pointer;transition:.2s}
.tipo:hover{border-color:var(--red);background:var(--red-soft);transform:translateY(-2px)}
.tipo i{font-size:26px;color:var(--red);display:block;margin-bottom:8px}
.tipo b{display:block;font-size:17px;font-weight:900;letter-spacing:.5px}
.tipo span{font-size:10.5px;color:var(--text2);letter-spacing:.4px}

/* ── ROLETAS ── */
.reels{display:grid;grid-template-columns:1fr 1.5fr;gap:8px;margin-bottom:12px}
.reel{background:var(--panel2);border:1px solid var(--border2);border-radius:12px;padding:14px 10px;text-align:center;min-height:88px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:5px;overflow:hidden;transition:.2s}
.reel-label{font-size:8px;font-weight:700;letter-spacing:1.1px;text-transform:uppercase;color:var(--text3)}
.reel-val{font-size:16px;font-weight:900;line-height:1.15}
.reel.spin .reel-val{animation:reelRoll .5s cubic-bezier(.4,0,.6,1) infinite}
@keyframes reelRoll{0%{transform:translateY(-16px);opacity:0}30%{opacity:1}70%{opacity:1}100%{transform:translateY(16px);opacity:0}}
.reel.hit{border-color:var(--red);box-shadow:0 0 0 3px var(--red-soft)}

.spin-btn{width:100%;background:var(--red);color:#fff;border:0;border-radius:12px;padding:15px;font-family:var(--font);font-size:15px;font-weight:800;letter-spacing:.3px;cursor:pointer;transition:.15s}
.spin-btn:hover:not(:disabled){filter:brightness(1.12)}
.spin-btn:active:not(:disabled){transform:scale(.985)}
.spin-btn:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.hint{font-size:11.5px;color:var(--text2);text-align:center;margin-top:10px;min-height:16px}

/* ── NOTAS DA LENDA SORTEADA ── */
.notas{display:grid;grid-template-columns:repeat(2,1fr);gap:7px;margin-top:14px}
.nota{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:10px 11px;display:flex;align-items:center;justify-content:space-between;gap:8px;cursor:pointer;transition:.15s}
.nota:hover:not(.off){border-color:var(--red);background:var(--red-soft);transform:translateY(-1px)}
.nota.off{opacity:.28;cursor:not-allowed}
.nota-nome{font-size:11px;font-weight:600;line-height:1.25}
.nota-letra{font-size:17px;font-weight:900;flex-shrink:0}

/* ── SLOTS DO BUILD ── */
.slots{display:flex;flex-direction:column;gap:5px}
.slot{display:flex;align-items:center;gap:9px;background:var(--panel2);border:1px solid var(--border);border-radius:9px;padding:8px 11px;transition:.25s}
.slot.novo{border-color:var(--green);background:var(--green-soft)}
.slot-icon{width:20px;font-size:12px;color:var(--text3);flex-shrink:0;text-align:center}
.slot-txt{flex:1;min-width:0}
.slot-nome{font-size:11.5px;font-weight:600;color:var(--text);display:block}
.slot-de{font-size:9.5px;color:var(--text3);display:block;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot-letra{font-size:15px;font-weight:900;flex-shrink:0;color:var(--text3)}
.grupo-sep{font-size:8px;font-weight:700;letter-spacing:1.1px;color:var(--text3);margin:9px 0 1px;padding-left:2px}

.ovr-box{text-align:center;padding:14px 0 2px;margin-top:10px;border-top:1px solid var(--border)}
.ovr-num{font-size:38px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.ovr-cap{font-size:9px;font-weight:700;letter-spacing:1.3px;color:var(--text3)}

/* ── TELA FINAL ── */
.fim{text-align:center;padding:6px 0}
.fim-ovr{font-size:58px;font-weight:900;line-height:1;color:var(--amber);font-variant-numeric:tabular-nums}
.fim-cap{font-size:9px;font-weight:700;letter-spacing:1.4px;color:var(--text3);margin-bottom:16px}
.fim-pos{display:inline-flex;align-items:baseline;gap:6px;background:var(--panel2);border:1px solid var(--border2);border-radius:999px;padding:8px 18px}
.fim-pos b{font-size:24px;font-weight:900;color:var(--red)}
.fim-pos span{font-size:11px;color:var(--text2)}
.fim-antes{font-size:10px;color:var(--text3);margin-top:6px}
.fim-moedas{display:inline-flex;align-items:center;gap:7px;background:var(--amber-soft);border:1px solid var(--amber);color:var(--amber);border-radius:999px;padding:9px 18px;font-size:13px;font-weight:800;margin-top:14px}
.fim-nada{font-size:12px;color:var(--text2);margin-top:14px}

/* ── RANKING ── */
.rank{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px}
.rank:last-child{border-bottom:0}
.rank-pos{width:24px;font-weight:900;color:var(--text3);flex-shrink:0;font-size:11px}
.rank.top1 .rank-pos{color:#ffd700}
.rank.top2 .rank-pos{color:#c0c8d4}
.rank.top3 .rank-pos{color:#cd7f32}
.rank-nome{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.rank-tag{font-size:8px;font-weight:700;letter-spacing:.6px;color:var(--text3);border:1px solid var(--border);border-radius:999px;padding:1px 7px;flex-shrink:0}
.rank-ovr{font-weight:900;color:var(--amber);flex-shrink:0;font-variant-numeric:tabular-nums}

.aviso{background:var(--amber-soft);border:1px solid var(--amber);border-radius:var(--radius);padding:14px 16px;font-size:12.5px;color:var(--amber);line-height:1.55}
.aviso a{color:var(--amber);font-weight:700}
.vazio{font-size:12px;color:var(--text3);text-align:center;padding:14px 0}
.progresso{height:4px;border-radius:999px;background:var(--panel3);overflow:hidden;margin-bottom:14px}
.progresso>div{height:100%;background:var(--red);transition:width .35s ease}

@media(max-width:420px){
  .reels{grid-template-columns:1fr 1.3fr}
  .reel-val{font-size:14px}
  .notas{grid-template-columns:1fr}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Build-A-<span>Player</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="topbar-right">
    <?php if ($partida && !$partida['concluido_em']): ?>
    <div class="chip"><i class="bi bi-grid-3x3-gap"></i><span id="chipSlots"><?= $preenchidos ?>/10</span></div>
    <?php endif; ?>
    <div class="chip" style="color:var(--amber)"><i class="bi bi-trophy-fill"></i><?= $partida && $partida['ovr'] ? (int)$partida['ovr'] : '—' ?></div>
  </div>
</div>

<div class="main">

<?php if (!$temNotas): ?>
  <div class="aviso">
    <b>Nenhuma lenda cadastrada ainda.</b><br>
    Aplique o elenco em <a href="/games/admin/build-lendas.php">Lendas do Build-A-Player</a> pra liberar o jogo.
  </div>

<?php elseif (!$partida): ?>
  <div class="intro">
    <h1>🏗️ Monte sua lenda</h1>
    <p>Gire a roleta, veja as notas da lenda sorteada e leve <b>uma</b> delas pro seu build. Dez giros, dez atributos — e um OVR no fim.</p>
  </div>
  <div class="card">
    <div class="card-title">Escolha o tipo de build</div>
    <div class="tipos">
      <div class="tipo" onclick="bpComecar('GUARD')">
        <i class="bi bi-lightning-charge-fill"></i>
        <b>GUARD</b><span>PG · SG · SF</span>
      </div>
      <div class="tipo" onclick="bpComecar('BIG')">
        <i class="bi bi-shield-fill"></i>
        <b>BIG</b><span>PF · C</span>
      </div>
    </div>
  </div>

<?php elseif ($partida['concluido_em']): ?>
  <div class="card">
    <div class="card-title">Seu build de hoje · <?= htmlspecialchars($partida['grupo']) ?></div>
    <div class="fim">
      <div class="fim-ovr"><?= (int)$partida['ovr'] ?></div>
      <div class="fim-cap">OVERALL</div>
      <div class="fim-pos">
        <b>#<?= (int)($posicaoAtual ?? $partida['posicao_rank']) ?></b>
        <span>de todos os tempos</span>
      </div>
      <?php if ($posicaoAtual && (int)$posicaoAtual !== (int)$partida['posicao_rank']): ?>
      <div class="fim-antes">você fechou em #<?= (int)$partida['posicao_rank'] ?></div>
      <?php endif; ?>
      <?php if ((int)$partida['moedas'] > 0): ?>
      <div class="fim-moedas"><i class="bi bi-coin"></i>+<?= (int)$partida['moedas'] ?> moedas</div>
      <?php else: ?>
      <div class="fim-nada">Top 10 paga moedas. Amanhã tem mais!</div>
      <?php endif; ?>
    </div>
  </div>

<?php else: ?>
  <div class="progresso"><div id="barra" style="width:<?= $preenchidos * 10 ?>%"></div></div>
  <div class="card">
    <div class="reels">
      <div class="reel" id="reelTime">
        <div class="reel-label">Time</div>
        <div class="reel-val" id="valTime">—</div>
      </div>
      <div class="reel" id="reelLenda">
        <div class="reel-label">Lenda</div>
        <div class="reel-val" id="valLenda">—</div>
      </div>
    </div>
    <button class="spin-btn" id="btnSpin" onclick="bpGirar()"><i class="bi bi-dice-3-fill"></i> GIRAR</button>
    <div class="hint" id="hint">Gire pra sortear uma lenda.</div>
    <div class="notas" id="notas"></div>
  </div>
<?php endif; ?>

<?php if ($temNotas && $partida): ?>
  <div class="card">
    <div class="card-title"><span>Seu build</span><span id="faltam" style="color:var(--red)"></span></div>
    <div class="slots">
      <?php $grupoAtual = ''; foreach ($ATRIBUTOS as $chave => $info):
        if ($info['grupo'] !== $grupoAtual): $grupoAtual = $info['grupo']; ?>
        <div class="grupo-sep"><?= htmlspecialchars($grupoAtual) ?></div>
      <?php endif;
        $s = $partida['slots'][$chave] ?? null; ?>
      <div class="slot" data-attr="<?= $chave ?>">
        <span class="slot-icon"><i class="bi bi-<?= $s ? 'check-circle-fill' : 'circle' ?>"></i></span>
        <span class="slot-txt">
          <span class="slot-nome"><?= htmlspecialchars($info['label']) ?></span>
          <span class="slot-de"><?= $s ? htmlspecialchars($s['de']) : 'vazio' ?></span>
        </span>
        <span class="slot-letra"><?= $s ? htmlspecialchars($s['letra']) : '–' ?></span>
      </div>
      <?php endforeach; ?>
    </div>
    <div class="ovr-box">
      <div class="ovr-num" id="ovrNum"><?= $partida['ovr'] ? (int)$partida['ovr'] : '–' ?></div>
      <div class="ovr-cap">OVERALL</div>
    </div>
  </div>
<?php endif; ?>

<?php if ($temNotas): ?>
  <div class="card">
    <div class="card-title"><span><i class="bi bi-trophy-fill"></i> Top 10 de todos os tempos</span></div>
    <?php if (!$topGeral): ?>
      <div class="vazio">Ninguém montou um build ainda. Seja o primeiro.</div>
    <?php else: foreach ($topGeral as $i => $t): ?>
      <div class="rank <?= $i < 3 ? 'top' . ($i + 1) : '' ?>">
        <span class="rank-pos"><?= $i + 1 ?>º</span>
        <span class="rank-nome"><?= htmlspecialchars($t['nome']) ?></span>
        <span class="rank-tag"><?= htmlspecialchars($t['grupo']) ?></span>
        <span class="rank-ovr"><?= (int)$t['ovr'] ?></span>
      </div>
    <?php endforeach; endif; ?>
  </div>
<?php endif; ?>

</div>

<script>
const BP_LABELS = <?= json_encode(array_map(fn($a) => $a['label'], $ATRIBUTOS)) ?>;
// Cor por nível: vermelho no fundo da escala, roxo no S.
const BP_CORES = ['#ef4444','#ef4444','#f97316','#f59e0b','#f59e0b','#eab308','#84cc16','#22c55e','#22c55e','#06b6d4','#3b82f6','#a855f7'];
let bpTravado = false;

async function bpPost(dados) {
  const res = await fetch(window.location.href, { method: 'POST', body: new URLSearchParams(dados) });
  return res.json();
}

async function bpComecar(grupo) {
  if (bpTravado) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'comecar', grupo });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }
  location.reload();
}

/** Roda nomes falsos nos reels enquanto o servidor não responde. */
function bpRolar(ligar) {
  document.getElementById('reelTime')?.classList.toggle('spin', ligar);
  document.getElementById('reelLenda')?.classList.toggle('spin', ligar);
  if (!ligar) return;
  const times = ['CHI','LAL','BOS','GSW','MIA','SAS','NYK','PHX','DET','HOU'];
  const nomes = ['. . .','? ? ?','— — —'];
  let i = 0;
  const t = setInterval(() => {
    if (!document.getElementById('reelTime')?.classList.contains('spin')) { clearInterval(t); return; }
    document.getElementById('valTime').textContent  = times[i % times.length];
    document.getElementById('valLenda').textContent = nomes[i % nomes.length];
    i++;
  }, 90);
}

async function bpGirar() {
  if (bpTravado) return;
  bpTravado = true;
  const btn = document.getElementById('btnSpin');
  btn.disabled = true;
  document.getElementById('notas').innerHTML = '';
  document.getElementById('reelTime').classList.remove('hit');
  document.getElementById('reelLenda').classList.remove('hit');
  bpRolar(true);

  const r = await bpPost({ acao: 'girar' });
  // Segura a rolagem um pouco pra dar peso ao sorteio.
  await new Promise(res => setTimeout(res, 750));
  bpRolar(false);

  if (!r.ok) {
    document.getElementById('hint').textContent = r.msg;
    btn.disabled = false; bpTravado = false; return;
  }

  document.getElementById('valTime').textContent  = r.lenda.time;
  document.getElementById('valLenda').textContent = r.lenda.nome;
  document.getElementById('reelTime').classList.add('hit');
  document.getElementById('reelLenda').classList.add('hit');
  document.getElementById('hint').innerHTML = 'Escolha <b>um</b> atributo pra levar.';

  const ocupados = [...document.querySelectorAll('.slot')]
    .filter(s => s.querySelector('.slot-de').textContent !== 'vazio')
    .map(s => s.dataset.attr);

  document.getElementById('notas').innerHTML = Object.entries(r.lenda.notas).map(([chave, n]) => {
    const off = ocupados.includes(chave);
    return `<div class="nota ${off ? 'off' : ''}" ${off ? '' : `onclick="bpEscolher('${chave}')"`}>
      <span class="nota-nome">${BP_LABELS[chave]}</span>
      <span class="nota-letra" style="color:${BP_CORES[n.nivel]}">${n.letra}</span>
    </div>`;
  }).join('');

  bpTravado = false;
}

async function bpEscolher(attr) {
  if (bpTravado) return;
  bpTravado = true;
  const r = await bpPost({ acao: 'escolher', atributo: attr });
  if (!r.ok) { alert(r.msg); bpTravado = false; return; }

  const slot = document.querySelector(`.slot[data-attr="${attr}"]`);
  const dado = r.slots[attr];
  slot.classList.add('novo');
  slot.querySelector('.slot-icon').innerHTML = '<i class="bi bi-check-circle-fill"></i>';
  slot.querySelector('.slot-icon').style.color = 'var(--green)';
  slot.querySelector('.slot-de').textContent = dado.de;
  const letra = slot.querySelector('.slot-letra');
  letra.textContent = dado.letra;
  letra.style.color = BP_CORES[dado.nivel];
  setTimeout(() => slot.classList.remove('novo'), 900);

  const feitos = 10 - r.faltam;
  document.getElementById('barra').style.width = (feitos * 10) + '%';
  document.getElementById('chipSlots').textContent = feitos + '/10';
  document.getElementById('faltam').textContent = r.faltam ? `faltam ${r.faltam}` : '';
  document.getElementById('notas').innerHTML = '';
  document.getElementById('reelTime').classList.remove('hit');
  document.getElementById('reelLenda').classList.remove('hit');
  document.getElementById('hint').textContent = r.terminou ? 'Build completo!' : 'Gire de novo pra próxima lenda.';
  document.getElementById('btnSpin').disabled = false;

  if (r.terminou) { setTimeout(() => location.reload(), 700); return; }
  bpTravado = false;
}

document.addEventListener('DOMContentLoaded', () => {
  const vazios = [...document.querySelectorAll('.slot-de')].filter(e => e.textContent === 'vazio').length;
  const el = document.getElementById('faltam');
  if (el) el.textContent = vazios ? `faltam ${vazios}` : '';
});
</script>
</body>
</html>
