<?php
/**
 * REGISTRAR AS ESCOLHAS QUE JÁ SAÍRAM.
 *
 * Um sorteio aconteceu antes de a cerimônia passar a existir no servidor:
 * as picks foram reveladas na tela de quem conduzia e não ficaram em lugar
 * nenhum. Esta tela devolve esse pedaço da história — quem informa é quem
 * viu acontecer.
 *
 * Ela NÃO sorteia. Só registra quem saiu e em que escolha, tira esses times
 * da urna e coloca no ar pra liga inteira ver. O resto da cerimônia segue
 * pela tela normal, e o sorteio de lá cobre apenas as posições que sobraram.
 *
 * Fica fora do menu e fora do painel: é uma ferramenta de conserto, usada
 * uma vez. Por isso o acesso é por e-mail, e não por perfil de admin — não
 * é atribuição de quem administra uma liga, é reparo pontual.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/loteria_grupos.php';
requireAuth();

/* Quem pode registrar escolhas já sorteadas.
   É por e-mail, e não por perfil de admin, de propósito: mexer no que a
   loteria já decidiu não é atribuição de quem administra uma liga — é reparo
   pontual, e cada nome aqui entrou por decisão explícita. */
const DONOS_DO_AJUSTE = [
    'medeirros99@gmail.com',
    'lennonherman1997@gmail.com',
];

$user = getUserSession();
$pdo  = db();

if (!in_array(strtolower(trim((string)($user['email'] ?? ''))), DONOS_DO_AJUSTE, true)) {
    http_response_code(403);
    exit('Sem acesso.');
}

$liga = strtoupper(trim((string)($_GET['liga'] ?? 'ELITE')));
if (!in_array($liga, ['ELITE', 'NEXT', 'RISE', 'ROOKIE'], true)) $liga = 'ELITE';

// A sessão de draft em configuração da liga, dentro da sprint que está rodando.
$sprint = loteriaSprintAtiva($pdo, $liga);
$st = $pdo->prepare("SELECT ds.id, s.season_number
                       FROM draft_sessions ds
                       JOIN seasons s ON s.id = ds.season_id
                      WHERE ds.league = ? AND ds.status = 'setup' AND s.sprint_id = ?
                   ORDER BY s.season_number DESC, ds.id DESC LIMIT 1");
$st->execute([$liga, $sprint ?? 0]);
$sessao = $st->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Ajuste da loteria · FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Oswald:wght@600;700&display=swap" rel="stylesheet">
<style>
:root{--red:#fc0025;--amber:#f59e0b;--green:#22c55e;--bg:#07070a;--panel:#101013;--panel-2:#16161a;
  --border:rgba(255,255,255,.08);--border-md:rgba(255,255,255,.15);--text:#f0f0f3;--text-2:#9a9aa4;--text-3:#71717a}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:'Montserrat',system-ui,sans-serif;font-size:14px;line-height:1.55}
.wrap{max-width:760px;margin:0 auto;padding:34px 18px 80px;display:flex;flex-direction:column;gap:18px}
h1{font-family:'Oswald',sans-serif;font-size:26px;margin:0 0 6px}
h1 i{color:var(--amber);margin-right:8px}
.sub{color:var(--text-2);margin:0}
.painel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px}
.aviso{display:flex;gap:11px;align-items:flex-start;padding:13px 15px;border-radius:11px;font-size:13px;
  background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.4)}
.aviso i{color:var(--amber);font-size:17px;flex-shrink:0}
.linha{display:flex;align-items:center;gap:10px;padding:8px 0;border-bottom:1px solid rgba(255,255,255,.05)}
.linha:last-child{border-bottom:none}
.pos{font-family:'Oswald',sans-serif;font-size:17px;font-weight:700;width:38px;text-align:right;color:var(--text-3);flex-shrink:0}
select{flex:1;min-width:0;background:var(--panel-2);border:1px solid var(--border);border-radius:9px;
  color:var(--text);font-family:inherit;font-size:13px;padding:9px 11px}
select:focus{outline:none;border-color:var(--red)}
select.preenchido{border-color:var(--green);color:#fff}
button{font-family:inherit;cursor:pointer;border-radius:10px;border:none;transition:filter .15s}
.btn{background:var(--red);color:#fff;padding:12px 24px;font-size:14px;font-weight:700;display:inline-flex;align-items:center;gap:8px}
.btn:hover:not(:disabled){filter:brightness(1.12)}
.btn:disabled{opacity:.5;cursor:default}
.btn-2{background:transparent;border:1px solid var(--border-md);color:var(--text-2);padding:11px 18px;font-size:13px;font-weight:600}
.rodape{font-size:12px;color:var(--text-3);line-height:1.6}
#msg{display:none;padding:12px 15px;border-radius:11px;font-size:13px}
#msg.ok{display:block;background:rgba(34,197,94,.1);border:1px solid rgba(34,197,94,.4);color:#86efac}
#msg.erro{display:block;background:rgba(252,0,37,.1);border:1px solid rgba(252,0,37,.4);color:#fca5a5}
@media(max-width:600px){.wrap{padding:22px 14px 60px}.pos{width:30px;font-size:15px}}
</style>
</head>
<body>
<div class="wrap">

  <div>
    <h1><i class="bi bi-tools"></i>Ajuste da loteria</h1>
    <p class="sub">Registrar as escolhas que já saíram na cerimônia — <?= htmlspecialchars($liga) ?><?= $sessao ? ' · Temporada ' . (int)$sessao['season_number'] : '' ?></p>
  </div>

  <?php if (!$sessao): ?>
  <div class="aviso"><i class="bi bi-exclamation-triangle-fill"></i>
    <div>A <?= htmlspecialchars($liga) ?> não tem draft em configuração na sprint atual, então não há cerimônia pra ajustar.</div>
  </div>
  <?php else: ?>

  <div class="aviso">
    <i class="bi bi-info-circle-fill"></i>
    <div>
      Preencha só as escolhas que <b>já saíram</b> e deixe o resto em branco. Ao publicar, esses times saem da urna
      e a liga inteira passa a ver essas picks na tela da loteria. <b>Nada é sorteado aqui</b> — o resto da cerimônia
      continua na tela normal, e o sorteio de lá cobre apenas as posições que sobraram.
    </div>
  </div>

  <div id="msg"></div>

  <div class="painel">
    <div id="lista"><div style="color:var(--text-3);padding:14px 0">Carregando os times da loteria…</div></div>
  </div>

  <div style="display:flex;gap:10px;flex-wrap:wrap">
    <button class="btn" id="btnPublicar" disabled><i class="bi bi-broadcast"></i> Publicar o que já saiu</button>
    <a class="btn-2" href="/lottery.php?liga=<?= urlencode($liga) ?>" style="text-decoration:none;display:inline-flex;align-items:center"><i class="bi bi-arrow-left me-1"></i>&nbsp;Ir para a loteria</a>
  </div>

  <div class="rodape">
    A revelação vai da última escolha para a primeira, então normalmente as que já saíram são as de número mais alto.
    Publicar de novo substitui o que estiver no ar.
  </div>

  <?php endif; ?>
</div>

<?php if ($sessao): ?>
<script>
const SESSAO = <?= (int)$sessao['id'] ?>;
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
let elegiveis = [];

function mostrar(texto, tipo){
  const m = $('msg');
  m.className = tipo;
  m.textContent = texto;
  if (tipo === 'ok') m.scrollIntoView({behavior:'smooth', block:'center'});
}

/* As linhas vêm da última escolha para a primeira, na mesma direção da
   revelação: quem vai preencher acabou de ver a cerimônia nessa ordem. */
function montar(){
  $('lista').innerHTML = elegiveis.map((_, i) => {
    const pos = elegiveis.length - i;
    return `<div class="linha">
      <span class="pos">${pos}ª</span>
      <select data-pos="${pos}" onchange="aoEscolher(this)">
        <option value="">— ainda não saiu —</option>
        ${elegiveis.map(t => `<option value="${t.team_id}">${esc(t.rotulo || t.team_name)}</option>`).join('')}
      </select>
    </div>`;
  }).join('');
}

/* Um time não pode ter saído em duas escolhas: some das outras listas. */
function aoEscolher(sel){
  sel.classList.toggle('preenchido', !!sel.value);
  const usados = new Set([...document.querySelectorAll('select[data-pos]')].map(s => s.value).filter(Boolean));
  document.querySelectorAll('select[data-pos]').forEach(s => {
    [...s.options].forEach(o => {
      if (!o.value) return;
      o.hidden = usados.has(o.value) && s.value !== o.value;
      o.disabled = o.hidden;
    });
  });
  $('btnPublicar').disabled = usados.size === 0;
}

async function carregar(){
  const res = await fetch('/api/draft.php', {
    method: 'POST', headers: {'Content-Type':'application/json'},
    body: JSON.stringify({ action:'run_lottery', draft_session_id: SESSAO, preview: true })
  });
  const d = await res.json();
  if (!d.success) { mostrar(d.error || 'Não foi possível carregar a loteria.', 'erro'); return; }

  /* A BOLINHA É DE UM TIME, A ESCOLHA PODE SER DE OUTRO.
     Numa pick trocada, quem sai na cerimônia é o dono atual — "St. Louis
     Archers via PHI". Aqui a lista é dos donos da bolinha, que é o que
     define a posição; sem dizer quem fica com ela, quem preenche procura na
     lista o nome que viu na tela e não acha. */
  const dono = {};
  (d.order || []).forEach(o => {
    if (o.source !== 'playoff' && o.is_swap) dono[o.origin_team_id] = o.team_name;
  });
  elegiveis = (d.balls || []).map(t => Object.assign({}, t, {
    rotulo: dono[t.team_id] ? `${t.team_name} — a pick é do ${dono[t.team_id]}` : t.team_name
  }));
  montar();
}

async function publicar(){
  const jaSaiu = {};
  document.querySelectorAll('select[data-pos]').forEach(s => {
    if (s.value) jaSaiu[s.dataset.pos] = parseInt(s.value, 10);
  });
  if (!Object.keys(jaSaiu).length) return;

  const btn = $('btnPublicar');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Publicando…';
  try {
    /* Sorteia informando quem já saiu: esses times ficam fora da urna e nas
       posições onde saíram. O que o sorteio decide agora são as outras
       posições — que ninguém vai ver, porque só as informadas entram no ar
       como reveladas. */
    const r1 = await fetch('/api/draft.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action:'run_lottery', draft_session_id: SESSAO, ja_saiu: jaSaiu })
    });
    const sorteio = await r1.json();
    if (!sorteio.success) throw new Error(sorteio.error || 'falha ao montar a ordem');

    const r2 = await fetch('/api/draft.php', {
      method: 'POST', headers: {'Content-Type':'application/json'},
      body: JSON.stringify({ action:'lottery_transmitir', draft_session_id: SESSAO,
                             ordem: sorteio.order, ajustes: sorteio.adjustments || [] })
    });
    const t = await r2.json();
    if (!t.success) throw new Error(t.error || 'falha ao publicar');

    for (const pos of Object.keys(jaSaiu)) {
      await fetch('/api/draft.php', {
        method: 'POST', headers: {'Content-Type':'application/json'},
        body: JSON.stringify({ action:'lottery_revelar', draft_session_id: SESSAO, position: parseInt(pos, 10) })
      });
    }

    const n = Object.keys(jaSaiu).length;
    mostrar(`${n} ${n === 1 ? 'escolha registrada' : 'escolhas registradas'}. A liga já vê na tela da loteria, e esses times saíram da urna.`, 'ok');
  } catch (e) {
    mostrar('Não deu pra publicar: ' + e.message, 'erro');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-broadcast"></i> Publicar o que já saiu';
  }
}

$('btnPublicar').addEventListener('click', publicar);
carregar();
</script>
<?php endif; ?>
</body>
</html>
