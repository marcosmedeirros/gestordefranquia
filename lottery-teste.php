<?php
/**
 * A LOTERIA DE ENSAIO — só ELITE, sem login, sem consequência.
 *
 * Página própria e curta de propósito. A primeira versão embrulhava a tela
 * oficial inteira e herdava tudo dela: abas de liga, escolha de sessão de
 * draft, permissões, painéis de admin. Cada um desses pedaços era uma
 * maneira de a página ficar em branco — e ficou, por causa de uma sessão de
 * draft antiga esquecida em "configuração".
 *
 * Aqui não há sessão de draft nenhuma. A loteria precisa de uma
 * classificação e das regras; o resto era acoplamento. Quem monta os grupos
 * e sorteia é api/loteria-simulador.php, que não escreve nada.
 */
require_once __DIR__ . '/backend/db.php';
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Loteria do Draft · Ensaio · FBA</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&family=Oswald:wght@400;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025; --amber:#f59e0b; --green:#22c55e;
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.07); --border-md:rgba(255,255,255,.13);
  --text:#f0f0f3; --text-2:#9a9aa4; --text-3:#71717a;
  --ease:cubic-bezier(.2,.8,.2,1);
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:'Montserrat',system-ui,sans-serif;font-size:14px;line-height:1.55}
.wrap{max-width:1080px;margin:0 auto;padding:34px 18px 90px;display:flex;flex-direction:column;gap:20px}

.eyebrow{font-family:'Oswald',sans-serif;font-size:11px;font-weight:600;letter-spacing:.2em;text-transform:uppercase;color:var(--red);margin:0 0 6px}
h1{font-family:'Oswald',sans-serif;font-weight:700;font-size:clamp(26px,5vw,38px);margin:0 0 8px;letter-spacing:-.01em}
h1 i{color:var(--red);margin-right:8px}
.sub{color:var(--text-2);margin:0;max-width:66ch}

.ensaio{display:flex;gap:12px;align-items:flex-start;padding:13px 16px;border-radius:12px;font-size:13px;
  background:repeating-linear-gradient(135deg,rgba(245,158,11,.10) 0 12px,rgba(245,158,11,.05) 12px 24px);
  border:1px solid rgba(245,158,11,.42)}
.ensaio i{color:var(--amber);font-size:18px;flex-shrink:0}

.titulo{display:flex;align-items:center;gap:8px;font-family:'Oswald',sans-serif;font-size:13px;font-weight:600;
  letter-spacing:.13em;text-transform:uppercase;color:var(--text-2);margin:8px 0 0}
.titulo i{color:var(--red)}
.painel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:18px}

.acao{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap}
.acao-info{font-size:12px;color:var(--text-3)}
.acao-info b{color:var(--text);font-size:15px;font-family:'Oswald',sans-serif}
button{font-family:inherit;cursor:pointer;border-radius:10px;transition:all .18s var(--ease)}
.btn{background:var(--red);color:#fff;border:none;padding:12px 26px;font-size:14px;font-weight:700;
  display:inline-flex;align-items:center;gap:9px}
.btn:hover:not(:disabled){filter:brightness(1.12)}
.btn:disabled{opacity:.55;cursor:default}
.btn-2{background:transparent;color:var(--text-2);border:1px solid var(--border-md);padding:11px 18px;font-size:13px;font-weight:600}
.btn-2:hover{color:var(--text);border-color:var(--red)}

.rolar{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th{font-family:'Oswald',sans-serif;font-size:10px;font-weight:600;letter-spacing:.1em;text-transform:uppercase;
  color:var(--text-3);text-align:left;padding:0 10px 9px;border-bottom:1px solid var(--border-md);white-space:nowrap}
td{padding:9px 10px;border-bottom:1px solid rgba(255,255,255,.04);white-space:nowrap}
tr:last-child td{border-bottom:none}
th.n,td.n{text-align:right;font-family:'Oswald',sans-serif;font-weight:600;font-variant-numeric:tabular-nums}
.logo{width:24px;height:24px;border-radius:6px;object-fit:cover;flex-shrink:0}
.time{display:flex;align-items:center;gap:9px;font-weight:600}
.chip{display:inline-block;font-size:10px;font-weight:700;letter-spacing:.05em;padding:2px 8px;border-radius:999px;
  background:var(--panel-3);color:var(--text-2);border:1px solid var(--border)}
.g1{color:#a5b4fc}.g2{color:#6ee7b7}.g3{color:#fcd34d}.g4{color:#fca5a5}
.bolinhas{display:inline-flex;gap:3px;vertical-align:middle}
.bolinha{width:9px;height:9px;border-radius:50%;background:var(--red);box-shadow:0 0 5px rgba(252,0,37,.55)}

/* resultado */
.picks{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.pick{display:flex;align-items:center;gap:11px;background:var(--panel-2);border:1px solid var(--border);
  border-radius:10px;padding:9px 13px;min-width:0;animation:entra .34s var(--ease) both}
.pick.top{border-color:rgba(245,158,11,.5);background:linear-gradient(180deg,rgba(245,158,11,.10),transparent)}
.pick-n{font-family:'Oswald',sans-serif;font-size:17px;font-weight:700;color:var(--text-3);width:26px;flex-shrink:0}
.pick.top .pick-n{color:var(--amber)}
.pick-nome{font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;min-width:0;flex:1}
.delta{font-family:'Oswald',sans-serif;font-size:12px;font-weight:700;flex-shrink:0}
.sobe{color:var(--green)}.desce{color:var(--red)}.igual{color:var(--text-3)}
@keyframes entra{from{opacity:0;transform:translateY(7px)}to{opacity:1;transform:none}}

.aviso{display:flex;gap:10px;align-items:flex-start;font-size:12.5px;color:var(--text-2);
  background:var(--panel-2);border-radius:10px;padding:11px 14px;margin-top:12px}
.aviso i{color:var(--amber);flex-shrink:0}
.rodape{font-size:12px;color:var(--text-3);border-top:1px solid var(--border);padding-top:16px;line-height:1.6}
.carregando{text-align:center;color:var(--text-3);padding:26px;font-size:13px}
@media(max-width:720px){
  .picks{grid-template-columns:1fr}
  .wrap{padding:24px 14px 60px}
  .painel{padding:14px}
}
</style>
</head>
<body>
<div class="wrap">

  <header>
    <p class="eyebrow">FBA ELITE · Loteria do Draft</p>
    <h1><i class="bi bi-shuffle"></i>Loteria de ensaio</h1>
    <p class="sub">Modelo 3-2-1: os times fora do playoff entram em quatro grupos, cada um com um número de bolinhas.
      Quanto mais bolinhas, mais chance de pegar as primeiras escolhas.</p>
  </header>

  <div class="ensaio">
    <i class="bi bi-cone-striped"></i>
    <div><b>Nada aqui é gravado.</b> É a loteria de verdade, com as bolinhas de verdade, sorteando de verdade —
      só que o resultado vive nesta aba e some quando você fechar. Sorteie quantas vezes quiser.</div>
  </div>

  <div class="painel acao">
    <div class="acao-info">
      <b id="cabecalho">Carregando…</b>
      <div id="subcabecalho"></div>
    </div>
    <div style="display:flex;gap:9px;flex-wrap:wrap">
      <button class="btn" id="btnSortear" disabled><i class="bi bi-dice-5-fill"></i> Sortear a loteria</button>
      <button class="btn-2" id="btnLimpar" style="display:none"><i class="bi bi-arrow-counterclockwise"></i> Voltar às chances</button>
    </div>
  </div>

  <div id="secaoResultado" style="display:none">
    <div class="titulo"><i class="bi bi-trophy-fill"></i> Ordem do draft sorteada</div>
    <div class="painel">
      <div class="picks" id="picks"></div>
      <div id="ajustes"></div>
    </div>
  </div>

  <div class="titulo"><i class="bi bi-percent"></i> As chances de cada time</div>
  <div class="painel">
    <div class="rolar">
      <table>
        <thead><tr>
          <th>Time</th><th>Grupo</th><th>Bolinhas</th>
          <th class="n">Escolha nº 1</th><th class="n">Top 3</th><th class="n">Top 5</th>
        </tr></thead>
        <tbody id="tabela"><tr><td colspan="6" class="carregando">Carregando a loteria…</td></tr></tbody>
      </table>
    </div>
    <div class="rodape" id="rodape" style="margin-top:14px"></div>
  </div>

</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const CLASSE_GRUPO = {1:'g1', 2:'g2', 3:'g3', 4:'g4'};
let dados = null;

/* Uma bolinha desenhada por bolinha de verdade. O número diz a mesma coisa,
   mas é olhando as três contra uma que se entende o modelo sem ler a regra. */
function bolinhas(n){
  return '<span class="bolinhas">' + '<span class="bolinha"></span>'.repeat(n) + '</span>';
}

function pintarTabela(d){
  $('tabela').innerHTML = d.times
    .slice()
    .sort((a, b) => a.grupo - b.grupo || b.bolinhas - a.bolinhas || a.posicao - b.posicao)
    .map(t => `
      <tr>
        <td><span class="time"><img class="logo" src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">${esc(t.team_name)}</span></td>
        <td><span class="chip ${CLASSE_GRUPO[t.grupo] || ''}">${esc(t.grupo_label)}</span></td>
        <td>${bolinhas(t.bolinhas)} <span style="color:var(--text-3);font-size:11px">${t.bolinhas}</span></td>
        <td class="n">${t.top1.toFixed(1)}%</td>
        <td class="n">${t.top3.toFixed(1)}%</td>
        <td class="n">${t.top5.toFixed(1)}%</td>
      </tr>`).join('');

  const soma = d.times.reduce((a, t) => a + t.top1, 0);
  $('rodape').innerHTML =
    `<b>${d.bolinhas} bolinhas</b> na urna, entre ${d.times.length} times. `
    + `A coluna <b>Escolha nº 1</b> é a única que soma 100% (deu ${soma.toFixed(1)}%): há uma primeira escolha só. `
    + `Top 3 e Top 5 somam 300% e 500%, porque são três e cinco escolhas sendo distribuídas.<br>`
    + `Os 3 piores recordes não podem cair além da 12ª escolha — é o piso de proteção.`;
}

function pintarPicks(d){
  $('picks').innerHTML = d.times.map((t, i) => {
    const seta = t.delta > 0 ? `<span class="delta sobe">▲ ${t.delta}</span>`
               : t.delta < 0 ? `<span class="delta desce">▼ ${-t.delta}</span>`
               : `<span class="delta igual">—</span>`;
    return `<div class="pick${t.pick <= 3 ? ' top' : ''}" style="animation-delay:${i * 35}ms">
      <span class="pick-n">${t.pick}</span>
      <img class="logo" src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
      <span class="pick-nome">${esc(t.team_name)}</span>
      ${seta}
    </div>`;
  }).join('');

  $('ajustes').innerHTML = (d.ajustes || []).map(a =>
    `<div class="aviso"><i class="bi bi-shield-fill-check"></i><span>${esc(a)}</span></div>`).join('');
}

async function carregar(sortear){
  const btn = $('btnSortear');
  btn.disabled = true;
  if (sortear) btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Sorteando…';
  try {
    const res = await fetch('/api/loteria-simulador.php?liga=ELITE' + (sortear ? '&sortear=1' : ''));
    const d = await res.json();
    if (!d.success) {
      $('tabela').innerHTML = `<tr><td colspan="6" class="carregando">${esc(d.error || 'Não foi possível montar a loteria.')}</td></tr>`;
      $('cabecalho').textContent = 'Loteria indisponível';
      return;
    }
    dados = d;
    pintarTabela(d);
    $('cabecalho').textContent = `${d.liga} · Temporada ${d.temporada}`;
    $('subcabecalho').textContent = d.sorteado
      ? 'Ordem sorteada agora — só nesta aba'
      : `${d.times.length} times na loteria, ${d.bolinhas} bolinhas`;

    if (d.sorteado) {
      pintarPicks(d);
      $('secaoResultado').style.display = '';
      $('btnLimpar').style.display = '';
      $('secaoResultado').scrollIntoView({ behavior: 'smooth', block: 'start' });
    } else {
      $('secaoResultado').style.display = 'none';
      $('btnLimpar').style.display = 'none';
    }
  } catch (e) {
    $('cabecalho').textContent = 'Falha ao carregar';
  } finally {
    btn.disabled = false;
    btn.innerHTML = dados && dados.sorteado
      ? '<i class="bi bi-arrow-repeat"></i> Sortear de novo'
      : '<i class="bi bi-dice-5-fill"></i> Sortear a loteria';
  }
}

$('btnSortear').addEventListener('click', () => carregar(true));
$('btnLimpar').addEventListener('click', () => carregar(false));
carregar(false);
</script>
</body>
</html>
