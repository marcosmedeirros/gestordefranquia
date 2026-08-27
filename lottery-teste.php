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

/* ── a urna ──
   As bolinhas giram de verdade: cada uma numa órbita e numa velocidade
   diferente, senão o conjunto vira carrossel em vez de urna. */
.palco{background:linear-gradient(160deg,var(--panel-2),var(--panel));border:1px solid var(--border-md);
  border-radius:18px;padding:26px 20px;text-align:center}
.palco.armado{border-color:rgba(252,0,37,.3);box-shadow:0 0 0 1px rgba(252,0,37,.12),0 20px 60px -22px rgba(252,0,37,.35)}
.maquina{position:relative;width:min(200px,58vw);height:min(200px,58vw);margin:0 auto;display:none}
.maquina.on{display:block}
.globo{position:absolute;inset:0;border-radius:50%;border:3px solid var(--border-md);overflow:hidden;
  background:radial-gradient(circle at 32% 26%,rgba(255,255,255,.10),transparent 58%),var(--panel-3);
  box-shadow:inset 0 0 42px rgba(0,0,0,.55)}
.bola{position:absolute;left:50%;top:50%;width:40px;height:40px;margin:-20px 0 0 -20px;border-radius:50%;
  background:var(--panel);border:2px solid var(--border-md);display:flex;align-items:center;justify-content:center;
  overflow:hidden;box-shadow:0 2px 6px rgba(0,0,0,.45);transition:opacity .45s var(--ease);
  animation-name:gira;animation-timing-function:linear;animation-iteration-count:infinite}
.bola img{width:28px;height:28px;object-fit:contain}
@keyframes gira{from{transform:rotate(0deg) translateX(var(--r)) rotate(0deg)}
                to{transform:rotate(360deg) translateX(var(--r)) rotate(-360deg)}}
.globo.freando .bola{opacity:.2;animation-duration:3.6s!important}
.bola.sorteada{z-index:5;opacity:1!important;border-color:var(--red);
  box-shadow:0 0 0 4px rgba(252,0,37,.18),0 0 34px rgba(252,0,37,.5);
  animation:saiu .78s cubic-bezier(.2,1.25,.4,1) forwards!important}
@keyframes saiu{0%{transform:scale(.65);opacity:.35}55%{transform:scale(2.1)}100%{transform:scale(1.9);opacity:1}}

.palco-rotulo{font-size:12px;letter-spacing:.14em;text-transform:uppercase;color:var(--text-3);font-weight:700}
.palco-carta{min-height:180px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:8px;margin:14px 0 4px}
.palco-logo{width:96px;height:96px;border-radius:18px;object-fit:cover;animation:pula .5s var(--ease)}
@keyframes pula{0%{transform:scale(.7);opacity:.3}60%{transform:scale(1.08)}100%{transform:scale(1)}}
.palco-num{font-family:'Oswald',sans-serif;font-size:38px;font-weight:800;line-height:1;color:var(--red)}
.palco-time{font-family:'Oswald',sans-serif;font-size:clamp(22px,5vw,32px);font-weight:800;line-height:1.1;min-height:34px}
.palco-mov{font-family:'Oswald',sans-serif;font-size:14px;font-weight:700}
.palco-dica{font-size:12px;color:var(--text-3);margin-top:10px}

.urna{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:9px}
.urna-item{background:var(--panel-2);border:1px solid var(--border);border-radius:10px;padding:9px;text-align:center;
  transition:all .45s var(--ease)}
.urna-item.saiu{opacity:.16;filter:grayscale(1)}
.urna-item img{width:30px;height:30px;border-radius:7px;object-fit:cover}
.urna-nome{font-size:11px;font-weight:600;margin-top:5px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.urna-odd{font-family:'Oswald',sans-serif;font-size:12px;color:var(--red);font-weight:700}

/* resultado */
.picks{display:grid;grid-template-columns:repeat(2,1fr);gap:8px}
.pick.vazio{opacity:.4;border-style:dashed}
.pick.novo{border-color:var(--red);box-shadow:0 0 0 1px rgba(252,0,37,.25)}
@media(prefers-reduced-motion:reduce){.bola{animation:none!important}.bola.sorteada{animation:none!important;transform:scale(1.85)}}
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
    <?php /* A REVELAÇÃO É O PONTO DA CERIMÔNIA. Sair da urna com a ordem
             pronta responde à pergunta certa e mata a graça — o que prende é
             a bolinha girando antes de cada nome. Começa pela última escolha
             e sobe até a nº 1, que é o que se guarda para o fim. */ ?>
    <div class="titulo"><i class="bi bi-stars"></i> Revelação</div>
    <div class="palco" id="palco">
      <div class="palco-rotulo" id="palcoRotulo">Pronto para começar</div>
      <div class="maquina" id="maquina"><div class="globo" id="globo"></div></div>
      <div class="palco-carta">
        <img class="palco-logo" id="palcoLogo" src="/img/default-team.png" alt="" style="display:none" onerror="this.src='/img/default-team.png'">
        <div class="palco-num" id="palcoNum">#?</div>
        <div class="palco-time" id="palcoTime">—</div>
        <div class="palco-mov" id="palcoMov"></div>
      </div>
      <button class="btn" id="btnRevelar"><i class="bi bi-caret-right-fill"></i> Revelar</button>
      <div class="palco-dica" id="palcoDica">A revelação começa pela última escolha e sobe até a nº 1.</div>
    </div>

    <div class="titulo"><i class="bi bi-collection-fill"></i> Ainda na urna <span id="urnaConta" style="color:var(--text-3);font-weight:400"></span></div>
    <div class="painel"><div class="urna" id="urna"></div></div>

    <div class="titulo"><i class="bi bi-list-ol"></i> Ordem do draft</div>
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

function marcador(delta){
  return delta > 0 ? `<span class="delta sobe">▲ ${delta}</span>`
       : delta < 0 ? `<span class="delta desce">▼ ${-delta}</span>`
       : `<span class="delta igual">—</span>`;
}

/* O quadro nasce vazio e vai sendo preenchido pela revelação. Mostrar tudo
   de cara transformaria a cerimônia numa conferência de resultado. */
function pintarPicks(d, reveladas){
  $('picks').innerHTML = d.times.map(t => {
    const visivel = !reveladas || reveladas.has(t.pick);
    if (!visivel) {
      return `<div class="pick vazio" id="pk-${t.pick}">
        <span class="pick-n">${t.pick}</span>
        <span class="pick-nome" style="color:var(--text-3)">Aguardando…</span>
      </div>`;
    }
    return `<div class="pick${t.pick <= 3 ? ' top' : ''}" id="pk-${t.pick}">
      <span class="pick-n">${t.pick}</span>
      <img class="logo" src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
      <span class="pick-nome">${esc(t.team_name)}</span>
      ${marcador(t.delta)}
    </div>`;
  }).join('');

  $('ajustes').innerHTML = (d.ajustes || []).map(a =>
    `<div class="aviso"><i class="bi bi-shield-fill-check"></i><span>${esc(a)}</span></div>`).join('');
}

function pintarUrna(d, reveladas){
  $('urna').innerHTML = d.times
    .slice()
    .sort((a, b) => b.bolinhas - a.bolinhas || a.posicao - b.posicao)
    .map(t => `<div class="urna-item${reveladas.has(t.pick) ? ' saiu' : ''}" id="ur-${t.team_id}">
        <img src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
        <div class="urna-nome">${esc(t.team_name)}</div>
        <div class="urna-odd">${t.bolinhas} ${t.bolinhas === 1 ? 'bolinha' : 'bolinhas'}</div>
      </div>`).join('');
  const faltam = d.times.length - reveladas.size;
  $('urnaConta').textContent = faltam ? `· ${faltam} concorrendo` : '· urna vazia';
}

/* A URNA GIRANDO. Cada bolinha ganha órbita, velocidade e sentido próprios —
   com os mesmos valores o conjunto giraria em bloco, que não é urna. */
function girarUrna(candidatas, sorteada, aoTerminarBruto){
  /* A conclusão roda UMA vez, e roda de qualquer jeito.
     Navegador estrangula timer de aba escondida: quem clica em revelar e
     troca de aba volta e encontra a bolinha parada no meio do giro, com o
     botão travado. O relógio de segurança destrava sozinho. */
  let terminou = false;
  const aoTerminar = () => { if (terminou) return; terminou = true; clearTimeout(seguro); aoTerminarBruto(); };
  const seguro = setTimeout(aoTerminar, 6000);

  const maquina = $('maquina'), globo = $('globo');
  globo.classList.remove('freando');
  globo.innerHTML = '';
  maquina.classList.add('on');   // precisa estar visível pra medir o raio

  const raio = Math.max(Math.min(maquina.clientWidth, maquina.clientHeight) / 2 - 25, 18);
  globo.innerHTML = candidatas.map(t => {
    const r = Math.round(10 + Math.random() * raio);
    const dur = (1.0 + Math.random() * 0.9).toFixed(2);
    const atraso = (-Math.random() * 2).toFixed(2);
    const sentido = Math.random() < 0.5 ? 'normal' : 'reverse';
    return `<div class="bola" id="bl-${t.team_id}" style="--r:${r}px;animation-duration:${dur}s;animation-delay:${atraso}s;animation-direction:${sentido}">
      <img src="${esc(t.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
    </div>`;
  }).join('');

  setTimeout(() => {
    globo.classList.add('freando');
    const bola = document.getElementById('bl-' + sorteada.team_id);
    if (bola) bola.classList.add('sorteada');
    setTimeout(() => { maquina.classList.remove('on'); aoTerminar(); }, bola ? 820 : 200);
  }, 1500 + Math.random() * 700);
}

let fila = [], reveladas = new Set(), revelando = false;

function prepararRevelacao(d){
  // Da última escolha até a nº 1 — o melhor fica para o fim.
  fila = d.times.map(t => t.pick).sort((a, b) => b - a);
  reveladas = new Set();
  revelando = false;
  pintarPicks(d, reveladas);
  pintarUrna(d, reveladas);
  $('palcoRotulo').textContent = 'Pronto para começar';
  $('palcoNum').textContent = '#?';
  $('palcoTime').textContent = '—';
  $('palcoMov').textContent = '';
  $('palcoLogo').style.display = 'none';
  $('palco').classList.remove('armado');
  // A dica volta ao começo: ela terminou a rodada anterior dizendo "ordem
  // revelada", e ficaria mentindo em cima de um sorteio novo.
  $('palcoDica').textContent = 'A revelação começa pela última escolha e sobe até a nº 1.';
  atualizarBotaoRevelar();
}

function atualizarBotaoRevelar(){
  const btn = $('btnRevelar');
  if (!fila.length) {
    btn.style.display = 'none';
    $('palcoRotulo').textContent = 'Sorteio completo';
    $('palcoDica').textContent = 'Ordem revelada. Sorteie de novo quantas vezes quiser.';
    return;
  }
  btn.style.display = '';
  btn.disabled = revelando;
  btn.innerHTML = `<i class="bi bi-caret-right-fill"></i> Revelar a ${fila[0]}ª escolha`;
  $('palcoRotulo').textContent = fila.length === 1 ? 'A escolha nº 1' : `Faltam ${fila.length} escolhas`;
}

function revelarProxima(){
  if (revelando || !fila.length || !dados) return;
  revelando = true;
  const pick = fila.shift();
  const time = dados.times.find(t => t.pick === pick);
  atualizarBotaoRevelar();
  $('palco').classList.add('armado');
  $('palcoNum').textContent = '#' + pick;
  $('palcoTime').textContent = '…';
  $('palcoMov').textContent = '';
  $('palcoLogo').style.display = 'none';

  // No globo: quem ainda não saiu. Sem isso a bolinha certa seria a única a
  // girar, e a revelação perderia a dúvida.
  const candidatas = dados.times.filter(t => !reveladas.has(t.pick));

  girarUrna(candidatas, time, () => {
    reveladas.add(pick);
    $('palcoLogo').src = time.photo_url || '/img/default-team.png';
    $('palcoLogo').style.display = '';
    $('palcoTime').textContent = time.team_name;
    $('palcoMov').innerHTML = time.delta > 0
      ? `<span class="sobe">▲ subiu ${time.delta} ${time.delta === 1 ? 'posição' : 'posições'}</span>`
      : time.delta < 0
        ? `<span class="desce">▼ caiu ${-time.delta} ${time.delta === -1 ? 'posição' : 'posições'}</span>`
        : `<span class="igual">ficou onde a campanha deixou</span>`;

    const slot = document.getElementById('pk-' + pick);
    if (slot) {
      slot.className = 'pick novo' + (pick <= 3 ? ' top' : '');
      slot.innerHTML = `<span class="pick-n">${pick}</span>
        <img class="logo" src="${esc(time.photo_url)}" alt="" onerror="this.src='/img/default-team.png'">
        <span class="pick-nome">${esc(time.team_name)}</span>${marcador(time.delta)}`;
    }
    document.getElementById('ur-' + time.team_id)?.classList.add('saiu');
    const faltam = dados.times.length - reveladas.size;
    $('urnaConta').textContent = faltam ? `· ${faltam} concorrendo` : '· urna vazia';

    revelando = false;
    atualizarBotaoRevelar();
  });
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
      prepararRevelacao(d);
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
$('btnRevelar').addEventListener('click', revelarProxima);
// Espaço revela a próxima: numa transmissão, o dedo fica nele.
document.addEventListener('keydown', e => {
  if (e.code === 'Space' && $('secaoResultado').style.display !== 'none' && fila.length) {
    e.preventDefault();
    revelarProxima();
  }
});
carregar(false);
</script>
</body>
</html>
