<?php
/**
 * buildplayer.php — Build a Player.
 *
 * O GM monta um jogador distribuindo pontos entre 20 atributos (letras F a S),
 * escolhe tipo/posição/arquétipo, simula a temporada e os playoffs e descobre
 * em que lugar do Top 100 ele terminou. Um build por dia.
 *
 * Incluído por games/games/index.php — $pdo e $_SESSION já disponíveis.
 */

require_once __DIR__ . '/../core/build_motor.php';

$user_id = (int)$_SESSION['user_id'];
$hoje    = date('Y-m-d');

// Recompensa por posição final no Top 100.
$PREMIOS = [1 => 500, 5 => 100, 10 => 50];

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS buildplayer_partidas (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario    INT NOT NULL,
        data_jogo     DATE NOT NULL,
        tipo          VARCHAR(2) NOT NULL,
        posicao_jogo  VARCHAR(4) NOT NULL,
        arquetipo     VARCHAR(30) NOT NULL,
        atributos     TEXT NOT NULL,
        nota_final    DECIMAL(7,2) NOT NULL DEFAULT 0,
        rank_final    INT NOT NULL DEFAULT 0,
        pontos_ganhos INT NOT NULL DEFAULT 0,
        stats_json    TEXT NULL,
        criado_em     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_bp_user_date (id_usuario, data_jogo)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("INSERT IGNORE INTO fba_game_controls (game_key, is_double) VALUES ('buildplayer', 0)");
} catch (PDOException $e) {}

$multiplicador = getGamePointsMultiplier($pdo, 'buildplayer');

// Partida de hoje, se já existir.
$partida = null;
try {
    $st = $pdo->prepare("SELECT * FROM buildplayer_partidas WHERE id_usuario = ? AND data_jogo = ?");
    $st->execute([$user_id, $hoje]);
    $partida = $st->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {}

// ─── AJAX: simular ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'simular') {
    header('Content-Type: application/json');

    if ($partida) {
        echo json_encode(['ok' => false, 'msg' => 'Você já montou seu jogador hoje. Volte amanhã!']);
        exit;
    }

    $tipo = ($_POST['tipo'] ?? 'G') === 'B' ? 'B' : 'G';
    $posicao = (string)($_POST['posicao'] ?? '');
    if (!in_array($posicao, buildPosicoesDoTipo($tipo), true)) {
        echo json_encode(['ok' => false, 'msg' => 'Posição inválida pro tipo escolhido.']);
        exit;
    }

    $arquetipo = (string)($_POST['arquetipo'] ?? '');
    $arqs = buildArquetipos();
    if (!isset($arqs[$arquetipo]) || $arqs[$arquetipo]['tipo'] !== $tipo) {
        echo json_encode(['ok' => false, 'msg' => 'Arquétipo inválido pro tipo escolhido.']);
        exit;
    }

    $enviados = json_decode($_POST['atributos'] ?? '[]', true) ?: [];
    $niveis = [];
    $maxNivel = count(BUILD_LETRAS) - 1;
    foreach (buildAtributos() as [$chave]) {
        $n = (int)($enviados[$chave] ?? 0);
        $niveis[$chave] = max(0, min($maxNivel, $n));
    }

    // O orçamento é conferido AQUI, não só no navegador.
    $custo = buildCustoTotal($niveis);
    if ($custo > BUILD_ORCAMENTO) {
        echo json_encode(['ok' => false, 'msg' => "Seu build custa {$custo} pontos e o limite é " . BUILD_ORCAMENTO . '.']);
        exit;
    }

    // Semente do dia + usuário: o mesmo build simulado hoje dá sempre o mesmo
    // resultado, mas o azar/sorte dos playoffs varia entre jogadores e dias.
    $semente = crc32($hoje . '|' . $user_id . '|bp');
    $r = buildSimular($niveis, $tipo, $arquetipo, $semente);

    $premio = 0;
    foreach ($PREMIOS as $ate => $valor) {
        if ($r['posicao'] <= $ate) { $premio = $valor; break; }
    }
    $premio *= $multiplicador;

    try {
        $pdo->beginTransaction();
        $pdo->prepare("INSERT INTO buildplayer_partidas
            (id_usuario, data_jogo, tipo, posicao_jogo, arquetipo, atributos, nota_final, rank_final, pontos_ganhos, stats_json)
            VALUES (?,?,?,?,?,?,?,?,?,?)")
            ->execute([$user_id, $hoje, $tipo, $posicao, $arquetipo, json_encode($niveis),
                       $r['nota_final'], $r['posicao'], $premio, json_encode($r['stats'])]);

        if ($premio > 0) {
            $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                ->execute([$premio, $user_id]);
        }
        $pdo->commit();
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        echo json_encode(['ok' => false, 'msg' => 'Erro ao salvar a simulação.']);
        exit;
    }

    // Vizinhança no ranking, pra dar contexto de quem ficou perto.
    $vizinhos = [];
    $rivais = $r['rivais'];
    $ini = max(0, $r['posicao'] - 4);
    for ($i = $ini; $i < min(count($rivais), $ini + 7); $i++) {
        $vizinhos[] = ['pos' => $i + 1 + ($i + 1 >= $r['posicao'] ? 1 : 0), 'nome' => $rivais[$i]['nome'],
                       'arquetipo' => $rivais[$i]['arquetipo'], 'nota' => round($rivais[$i]['nota'], 1)];
    }

    echo json_encode([
        'ok'       => true,
        'stats'    => $r['stats'],
        'posicao'  => $r['posicao'],
        'total'    => $r['total'],
        'nota'     => $r['nota_final'],
        'playoffs' => ['rodadas' => $r['playoffs']['rodadas']],
        'premio'   => $premio,
        'vizinhos' => $vizinhos,
        'top3'     => array_map(fn($x) => ['nome' => $x['nome'], 'nota' => round($x['nota'], 1)], array_slice($rivais, 0, 3)),
    ]);
    exit;
}

$ATRIBUTOS  = buildAtributos();
$ARQUETIPOS = buildArquetipos();
$grupos = [];
foreach ($ATRIBUTOS as [$chave, $rotulo, $grupo, $pg, $pb]) {
    $grupos[$grupo][] = ['chave' => $chave, 'rotulo' => $rotulo, 'pg' => $pg, 'pb' => $pb];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<title>Build a Player — FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);
  --bg:#08080b;--panel:#111115;--panel2:#17171c;--panel3:#1e1e24;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.13);
  --text:#f2f2f5;--text2:#8b8b95;--text3:#6a6a74;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--purple:#a855f7;--gold:#eab308;
}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:'Montserrat',sans-serif;-webkit-font-smoothing:antialiased;padding-bottom:40px}
.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 16px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50;flex-wrap:wrap}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;margin-left:8px;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;color:var(--amber);background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);border-radius:999px;padding:3px 8px;vertical-align:middle}
.chip{display:inline-flex;align-items:center;gap:6px;background:var(--panel2);border:1px solid var(--border);border-radius:999px;padding:6px 12px;font-size:12px;font-weight:700}
.wrap{max-width:1080px;margin:0 auto;padding:18px 16px}
.intro{font-size:12.5px;color:var(--text2);line-height:1.6;margin-bottom:16px}
.intro b{color:var(--text)}

.card{background:var(--panel);border:1px solid var(--border);border-radius:14px;margin-bottom:14px;overflow:hidden}
.card-h{padding:12px 16px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;flex-wrap:wrap}
.card-h h2{font-size:13px;font-weight:800;letter-spacing:.3px;display:flex;align-items:center;gap:8px}
.card-h h2 i{color:var(--red)}
.card-b{padding:16px}

.opts{display:flex;gap:8px;flex-wrap:wrap}
.opt{padding:9px 14px;border-radius:10px;border:1px solid var(--border);background:var(--panel2);color:var(--text2);font-size:12.5px;font-weight:700;cursor:pointer;transition:.15s}
.opt:hover{border-color:var(--border2);color:var(--text)}
.opt.on{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.opt small{display:block;font-size:10px;font-weight:500;color:var(--text3);margin-top:2px}
.opt.on small{color:var(--red)}

.orc{position:sticky;top:57px;z-index:40;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:12px 16px;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.orc-num{font-size:22px;font-weight:900;font-variant-numeric:tabular-nums}
.orc-num.ruim{color:var(--red)}
.orc-bar{flex:1;min-width:160px;height:7px;border-radius:999px;background:var(--panel3);overflow:hidden}
.orc-bar>div{height:100%;background:var(--green);transition:width .2s,background .2s}
.orc-bar>div.ruim{background:var(--red)}

.grupo{margin-bottom:18px}
.grupo-t{font-size:10.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text3);margin-bottom:8px}
.attr{display:flex;align-items:center;gap:10px;padding:7px 0;border-bottom:1px solid var(--border);flex-wrap:wrap}
.attr:last-child{border-bottom:none}
.attr-nome{flex:1 1 150px;font-size:12.5px;font-weight:600;display:flex;align-items:center;gap:6px}
.rel{font-size:9px;font-weight:800;padding:1px 5px;border-radius:4px;background:var(--panel3);color:var(--text3)}
.rel.alto{background:rgba(34,197,94,.14);color:var(--green)}
.attr-ctl{display:flex;align-items:center;gap:8px}
.bt{width:28px;height:28px;border-radius:8px;border:1px solid var(--border2);background:var(--panel2);color:var(--text);font-size:15px;font-weight:800;cursor:pointer;line-height:1;transition:.15s}
.bt:hover:not(:disabled){border-color:var(--red);color:var(--red)}
.bt:disabled{opacity:.28;cursor:not-allowed}
.letra{width:42px;text-align:center;font-size:15px;font-weight:900;font-variant-numeric:tabular-nums}
.custo{width:52px;text-align:right;font-size:10.5px;color:var(--text3);font-variant-numeric:tabular-nums}

.acao{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:6px}
.btn-go{background:var(--red);border:none;color:#fff;font-weight:800;font-size:14px;border-radius:11px;padding:13px 26px;cursor:pointer;transition:.15s}
.btn-go:hover:not(:disabled){filter:brightness(1.12)}
.btn-go:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.btn-sec{background:transparent;border:1px solid var(--border2);color:var(--text2);font-weight:700;font-size:12.5px;border-radius:10px;padding:11px 18px;cursor:pointer}
.btn-sec:hover{border-color:var(--red);color:var(--red)}

.res{display:none}
.res.on{display:block}
.res-top{text-align:center;padding:26px 16px 20px}
.res-pos{font-size:66px;font-weight:900;line-height:1;font-variant-numeric:tabular-nums}
.res-pos small{font-size:20px;color:var(--text3);font-weight:800}
.res-lbl{font-size:12px;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:var(--text3);margin-top:6px}
.res-msg{font-size:14px;margin-top:12px;color:var(--text2);line-height:1.5}
.res-premio{display:inline-flex;align-items:center;gap:8px;margin-top:14px;background:rgba(234,179,8,.12);border:1px solid rgba(234,179,8,.35);color:var(--gold);border-radius:999px;padding:9px 18px;font-weight:800;font-size:14px}
.stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(88px,1fr));gap:9px}
.stat{background:var(--panel2);border:1px solid var(--border);border-radius:11px;padding:11px;text-align:center}
.stat-v{font-size:19px;font-weight:900;font-variant-numeric:tabular-nums}
.stat-k{font-size:9.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--text3);margin-top:2px}
.rk{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:9px;font-size:12.5px}
.rk.eu{background:var(--red-soft);border:1px solid rgba(252,0,37,.3);font-weight:800}
.rk-pos{width:34px;font-weight:800;color:var(--text3);font-variant-numeric:tabular-nums}
.rk.eu .rk-pos{color:var(--red)}
.rk-nome{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.rk-arq{font-size:10.5px;color:var(--text3)}
.rk-nota{font-variant-numeric:tabular-nums;color:var(--text2);font-weight:700}
@media(max-width:560px){
  .res-pos{font-size:52px}
  .attr-nome{flex-basis:100%}
}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="/games.php" class="back-btn" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">Build a <span>Player</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
  </div>
  <div class="chip"><i class="bi bi-people-fill" style="color:var(--purple)"></i> Top 100 da liga</div>
</div>

<div class="wrap">

<?php if ($partida):
    $st = json_decode($partida['stats_json'] ?: '{}', true) ?: [];
    $rank = (int)$partida['rank_final'];
?>
  <div class="card">
    <div class="card-h"><h2><i class="bi bi-check-circle-fill"></i> Você já jogou hoje</h2></div>
    <div class="card-b">
      <div class="res-top" style="padding-top:8px">
        <div class="res-pos"><?= $rank ?><small>º</small></div>
        <div class="res-lbl">no Top 100</div>
        <div class="res-msg">
          Seu <b><?= htmlspecialchars($ARQUETIPOS[$partida['arquetipo']]['nome'] ?? $partida['arquetipo']) ?></b>
          (<?= htmlspecialchars($partida['posicao_jogo']) ?>) fechou a temporada com nota
          <b><?= number_format((float)$partida['nota_final'], 1, ',', '.') ?></b>.
        </div>
        <?php if ((int)$partida['pontos_ganhos'] > 0): ?>
          <div class="res-premio"><i class="bi bi-coin"></i> +<?= (int)$partida['pontos_ganhos'] ?> moedas</div>
        <?php endif; ?>
      </div>
      <?php if ($st): ?>
      <div class="stat-grid" style="margin-top:14px">
        <?php foreach ([['pts','PTS'],['reb','REB'],['ast','AST'],['stl','ROU'],['blk','TOC'],['fg','FG%'],['tp','3P%'],['min','MIN']] as [$k,$l]): ?>
          <div class="stat"><div class="stat-v"><?= htmlspecialchars((string)($st[$k] ?? '-')) ?></div><div class="stat-k"><?= $l ?></div></div>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <p style="text-align:center;color:var(--text3);font-size:12px;margin-top:16px">Volta amanhã pra montar outro jogador.</p>
    </div>
  </div>

<?php else: ?>

  <p class="intro">
    Distribua <b><?= BUILD_ORCAMENTO ?> pontos</b> entre os atributos e monte seu jogador. Cada letra a mais custa
    bem mais que a anterior — <b>ninguém consegue ser bom em tudo</b>. O que separa um top 10 de um reserva é
    concentrar no que a sua posição realmente usa. Depois a temporada e os playoffs rodam, e você descobre
    onde ficou entre os 100 melhores da liga.
    <br><b>Top 10</b> vale 50 moedas · <b>Top 5</b> vale 100 · <b>1º lugar</b> vale 500 (boa sorte).
  </p>

  <div id="montagem">
    <div class="card">
      <div class="card-h"><h2><i class="bi bi-person-badge"></i> Quem é o seu jogador</h2></div>
      <div class="card-b">
        <div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px">Tipo</div>
        <div class="opts" id="optTipo">
          <button type="button" class="opt on" data-tipo="G">Guard<small>PG · SG · SF</small></button>
          <button type="button" class="opt" data-tipo="B">Big<small>SF · PF · C</small></button>
        </div>

        <div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin:16px 0 8px">Posição</div>
        <div class="opts" id="optPos"></div>

        <div style="font-size:11px;font-weight:700;color:var(--text3);text-transform:uppercase;letter-spacing:.8px;margin:16px 0 8px">Arquétipo <span style="text-transform:none;font-weight:500">— dá bônus se você levar os atributos dele pra B ou mais</span></div>
        <div class="opts" id="optArq"></div>
      </div>
    </div>

    <div class="orc">
      <div>
        <div style="font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text3)">Pontos usados</div>
        <div class="orc-num" id="orcNum">0 / <?= BUILD_ORCAMENTO ?></div>
      </div>
      <div class="orc-bar"><div id="orcBar" style="width:0%"></div></div>
      <button type="button" class="btn-sec" id="btnZerar"><i class="bi bi-arrow-counterclockwise"></i> Zerar</button>
    </div>

    <div class="card">
      <div class="card-h"><h2><i class="bi bi-sliders"></i> Atributos</h2>
        <span style="font-size:11px;color:var(--text3)">A etiqueta verde marca o que mais pesa na sua posição</span>
      </div>
      <div class="card-b" id="listaAttrs"></div>
    </div>

    <div class="acao">
      <button type="button" class="btn-go" id="btnSimular" disabled>Simular temporada</button>
      <span id="aviso" style="font-size:12px;color:var(--text3)"></span>
    </div>
  </div>

  <div class="res" id="resultado"></div>

<?php endif; ?>
</div>

<script>
const LETRAS  = <?= json_encode(BUILD_LETRAS) ?>;
const CUSTOS  = <?= json_encode(array_map('buildCustoDoNivel', range(0, count(BUILD_LETRAS) - 1))) ?>;
const ORC     = <?= BUILD_ORCAMENTO ?>;
const GRUPOS  = <?= json_encode($grupos, JSON_UNESCAPED_UNICODE) ?>;
const ARQS    = <?= json_encode($ARQUETIPOS, JSON_UNESCAPED_UNICODE) ?>;
const POSICOES= {G: <?= json_encode(buildPosicoesDoTipo('G')) ?>, B: <?= json_encode(buildPosicoesDoTipo('B')) ?>};

let tipo = 'G', posicao = 'PG', arquetipo = '';
let niveis = {};
Object.values(GRUPOS).flat().forEach(a => niveis[a.chave] = 0);

const $ = id => document.getElementById(id);
const custoTotal = () => Object.values(niveis).reduce((s, n) => s + CUSTOS[n], 0);

function renderPosicoes() {
  $('optPos').innerHTML = POSICOES[tipo].map(p =>
    `<button type="button" class="opt${p === posicao ? ' on' : ''}" data-pos="${p}">${p}</button>`).join('');
}

function renderArquetipos() {
  const lista = Object.entries(ARQS).filter(([, a]) => a.tipo === tipo);
  if (!lista.some(([k]) => k === arquetipo)) arquetipo = lista[0][0];
  $('optArq').innerHTML = lista.map(([k, a]) =>
    `<button type="button" class="opt${k === arquetipo ? ' on' : ''}" data-arq="${k}">${a.nome}</button>`).join('');
}

function renderAtributos() {
  $('listaAttrs').innerHTML = Object.entries(GRUPOS).map(([grupo, attrs]) => `
    <div class="grupo">
      <div class="grupo-t">${grupo}</div>
      ${attrs.map(a => {
        const peso = tipo === 'G' ? a.pg : a.pb;
        const alto = peso >= 1.1;
        const n = niveis[a.chave];
        return `
        <div class="attr" data-chave="${a.chave}">
          <div class="attr-nome">${a.rotulo}
            <span class="rel${alto ? ' alto' : ''}">${peso.toFixed(2).replace('.', ',')}x</span>
          </div>
          <div class="attr-ctl">
            <button type="button" class="bt" data-op="-" ${n === 0 ? 'disabled' : ''}>−</button>
            <div class="letra">${LETRAS[n]}</div>
            <button type="button" class="bt" data-op="+">+</button>
            <div class="custo">${CUSTOS[n]}p</div>
          </div>
        </div>`;
      }).join('')}
    </div>`).join('');
  atualizarOrcamento();
}

function atualizarOrcamento() {
  const c = custoTotal();
  const passou = c > ORC;
  $('orcNum').textContent = `${c} / ${ORC}`;
  $('orcNum').classList.toggle('ruim', passou);
  const bar = $('orcBar');
  bar.style.width = Math.min(100, (c / ORC) * 100) + '%';
  bar.classList.toggle('ruim', passou);

  // Desabilita o "+" do que não cabe mais no orçamento.
  document.querySelectorAll('.attr').forEach(el => {
    const chave = el.dataset.chave;
    const n = niveis[chave];
    const mais = el.querySelector('[data-op="+"]');
    const menos = el.querySelector('[data-op="-"]');
    const proximo = n + 1 < CUSTOS.length ? CUSTOS[n + 1] - CUSTOS[n] : Infinity;
    mais.disabled = n + 1 >= LETRAS.length || c + proximo > ORC;
    menos.disabled = n === 0;
  });

  const btn = $('btnSimular');
  btn.disabled = passou || c === 0;
  $('aviso').textContent = passou ? 'Você passou do orçamento.'
    : (c === 0 ? 'Distribua seus pontos pra liberar a simulação.'
    : (c < ORC ? `Ainda sobram ${ORC - c} pontos.` : 'Orçamento no talo.'));
}

$('optTipo').addEventListener('click', e => {
  const b = e.target.closest('[data-tipo]'); if (!b) return;
  tipo = b.dataset.tipo;
  document.querySelectorAll('#optTipo .opt').forEach(x => x.classList.toggle('on', x.dataset.tipo === tipo));
  posicao = POSICOES[tipo][0];
  renderPosicoes(); renderArquetipos(); renderAtributos();
});
$('optPos').addEventListener('click', e => {
  const b = e.target.closest('[data-pos]'); if (!b) return;
  posicao = b.dataset.pos;
  document.querySelectorAll('#optPos .opt').forEach(x => x.classList.toggle('on', x.dataset.pos === posicao));
});
$('optArq').addEventListener('click', e => {
  const b = e.target.closest('[data-arq]'); if (!b) return;
  arquetipo = b.dataset.arq;
  document.querySelectorAll('#optArq .opt').forEach(x => x.classList.toggle('on', x.dataset.arq === arquetipo));
});
$('listaAttrs').addEventListener('click', e => {
  const b = e.target.closest('[data-op]'); if (!b || b.disabled) return;
  const chave = b.closest('.attr').dataset.chave;
  const n = niveis[chave];
  if (b.dataset.op === '+') {
    if (n + 1 >= LETRAS.length) return;
    if (custoTotal() + (CUSTOS[n + 1] - CUSTOS[n]) > ORC) return;
    niveis[chave] = n + 1;
  } else {
    if (n === 0) return;
    niveis[chave] = n - 1;
  }
  const el = b.closest('.attr');
  el.querySelector('.letra').textContent = LETRAS[niveis[chave]];
  el.querySelector('.custo').textContent = CUSTOS[niveis[chave]] + 'p';
  atualizarOrcamento();
});
$('btnZerar').addEventListener('click', () => {
  Object.keys(niveis).forEach(k => niveis[k] = 0);
  renderAtributos();
});

$('btnSimular').addEventListener('click', async () => {
  const btn = $('btnSimular');
  btn.disabled = true;
  btn.textContent = 'Simulando temporada...';
  try {
    const fd = new FormData();
    fd.append('action', 'simular');
    fd.append('tipo', tipo);
    fd.append('posicao', posicao);
    fd.append('arquetipo', arquetipo);
    fd.append('atributos', JSON.stringify(niveis));
    const r = await fetch(location.href, { method: 'POST', body: fd });
    const d = await r.json();
    if (!d.ok) { alert(d.msg || 'Erro ao simular.'); btn.disabled = false; btn.textContent = 'Simular temporada'; return; }
    mostrarResultado(d);
  } catch (e) {
    alert('Erro de conexão.');
    btn.disabled = false; btn.textContent = 'Simular temporada';
  }
});

function mostrarResultado(d) {
  $('montagem').style.display = 'none';
  const s = d.stats;
  const faseTxt = ['eliminado na 1ª rodada', 'chegou às semifinais', 'chegou à final de conferência',
                   'chegou às finais', 'É CAMPEÃO'][d.playoffs.rodadas] || 'ficou fora dos playoffs';

  let msg;
  if (d.posicao === 1) msg = 'Você é <b>O MELHOR JOGADOR DA LIGA</b>. Isso quase nunca acontece.';
  else if (d.posicao <= 5) msg = 'Entrou no <b>top 5</b> da liga. Build de elite.';
  else if (d.posicao <= 10) msg = 'Fechou no <b>top 10</b>. Muito sólido.';
  else if (d.posicao <= 30) msg = 'Titular de bom nível, mas longe do topo.';
  else if (d.posicao <= 60) msg = 'Rotação. Faltou concentrar os pontos no que a posição usa.';
  else msg = 'Banco. Espalhar os pontos não funciona — escolha uma identidade.';

  const linhas = d.vizinhos.map(v => `
    <div class="rk"><div class="rk-pos">${v.pos}º</div>
      <div class="rk-nome">${v.nome} <span class="rk-arq">· ${v.arquetipo}</span></div>
      <div class="rk-nota">${v.nota}</div></div>`).join('');

  $('resultado').innerHTML = `
    <div class="card">
      <div class="card-b">
        <div class="res-top">
          <div class="res-pos">${d.posicao}<small>º</small></div>
          <div class="res-lbl">entre ${d.total} jogadores</div>
          <div class="res-msg">${msg}<br>Nos playoffs, ${faseTxt}.</div>
          ${d.premio > 0 ? `<div class="res-premio"><i class="bi bi-coin"></i> +${d.premio} moedas</div>` : ''}
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2><i class="bi bi-bar-chart-fill"></i> Sua temporada</h2></div>
      <div class="card-b">
        <div class="stat-grid">
          ${[['pts','PTS'],['reb','REB'],['ast','AST'],['stl','ROU'],['blk','TOC'],['fg','FG%'],['tp','3P%'],['min','MIN']]
            .map(([k, l]) => `<div class="stat"><div class="stat-v">${s[k]}</div><div class="stat-k">${l}</div></div>`).join('')}
        </div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2><i class="bi bi-list-ol"></i> Onde você ficou</h2></div>
      <div class="card-b">
        ${linhas}
        <div class="rk eu" style="margin-top:6px"><div class="rk-pos">${d.posicao}º</div>
          <div class="rk-nome">Você <span class="rk-arq">· ${ARQS[arquetipo].nome} (${posicao})</span></div>
          <div class="rk-nota">${d.nota}</div></div>
      </div>
    </div>
    <div class="card">
      <div class="card-h"><h2><i class="bi bi-trophy-fill"></i> O topo da liga</h2></div>
      <div class="card-b">
        ${d.top3.map((t, i) => `<div class="rk"><div class="rk-pos">${i + 1}º</div>
          <div class="rk-nome">${t.nome}</div><div class="rk-nota">${t.nota}</div></div>`).join('')}
      </div>
    </div>
    <div class="acao"><a href="/games.php" class="btn-sec"><i class="bi bi-arrow-left"></i> Voltar pros games</a></div>`;
  $('resultado').classList.add('on');
  window.scrollTo({ top: 0, behavior: 'smooth' });
}

<?php if (!$partida): ?>
renderPosicoes(); renderArquetipos(); renderAtributos();
<?php endif; ?>
</script>
</body>
</html>
