<?php
/**
 * controledrafts.php — o circuito do draft, uma liga por vez.
 *
 * Cada liga tem seu bolo de classes, sua roleta e seu estado. A página mostra
 * onde a liga parou e qual é o próximo passo — e, quando um passo não pode
 * rodar, diz o motivo em vez de só desabilitar o botão.
 *
 * O sorteio acontece no servidor (api/controledrafts.php). A animação da
 * roleta aqui é enfeite em cima de um resultado que já veio decidido: se o
 * giro fosse do cliente, daria pra recarregar até cair a classe boa.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo  = db();

$ehAdminGlobal = ($user['user_type'] ?? 'jogador') === 'admin';
$minhasLigas   = $ehAdminGlobal ? ['ELITE','NEXT','RISE','ROOKIE'] : getAdminLeagues($pdo, (int)$user['id']);
if (!$ehAdminGlobal && empty($minhasLigas)) {
    header('Location: /dashboard.php');
    exit;
}
// ?league=ELITE abre direto na aba da liga — é assim que o card do admin
// chega aqui. Liga que a pessoa não administra cai na primeira dela.
$pedida = strtoupper(trim((string)($_GET['league'] ?? '')));
$ligaInicial = in_array($pedida, $minhasLigas, true) ? $pedida : $minhasLigas[0];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Controle de Drafts · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);
  --text:#f0f0f3;--text-2:#8b8b95;--text-3:#6f6f79;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--purple:#a855f7;
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;--radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;padding:22px 18px 60px}
.wrap{max-width:1120px;margin:0 auto}

.topo{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:18px}
h1{font-size:24px;font-weight:800;line-height:1.15}
h1 i{color:var(--red)}
.sub{font-size:13px;color:var(--text-2);margin-top:4px}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border-md);color:var(--text-2);text-decoration:none;font-size:12.5px;font-weight:600}
.voltar:hover{border-color:var(--red);color:var(--red)}

.abas{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:18px}
.aba{padding:9px 18px;border-radius:10px;background:var(--panel-2);border:1.5px solid var(--border);color:var(--text-2);font-size:12.5px;font-weight:700;cursor:pointer;font-family:var(--font);letter-spacing:.4px}
.aba.on{border-color:var(--red);background:color-mix(in srgb,var(--red) 12%,transparent);color:var(--red)}

.card{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;margin-bottom:16px}
.card-tit{font-family:var(--num);font-size:13px;font-weight:700;text-transform:uppercase;letter-spacing:.9px;color:var(--text-2);margin-bottom:14px;display:flex;align-items:center;gap:8px}
.card-tit i{color:var(--red)}

/* ── Trilho dos passos ── */
.passos{display:grid;grid-template-columns:repeat(auto-fit,minmax(210px,1fr));gap:12px}
.passo{background:var(--panel-2);border:1px solid var(--border);border-left:3px solid var(--text-3);border-radius:11px;padding:13px 15px}
.passo.ok{border-left-color:var(--green)}
.passo.travado{border-left-color:var(--amber)}
.passo-n{font-size:10px;font-weight:800;letter-spacing:1px;color:var(--text-3)}
.passo-t{font-size:14px;font-weight:700;margin:3px 0 5px;display:flex;align-items:center;gap:7px}
.passo.ok .passo-t i{color:var(--green)}
.passo.travado .passo-t i{color:var(--amber)}
.passo-d{font-size:12px;color:var(--text-2)}
.passo-b{font-size:11.5px;color:var(--amber);margin-top:7px;line-height:1.45}

/* ── Roleta ── */
.roleta{background:var(--panel-2);border:1.5px dashed var(--border-md);border-radius:12px;padding:26px 18px;text-align:center;overflow:hidden}
.roleta-nome{font-family:var(--num);font-size:26px;font-weight:700;letter-spacing:.5px;min-height:34px;line-height:34px;color:var(--text)}
.roleta.girando .roleta-nome{color:var(--amber)}
.roleta-sub{font-size:11.5px;color:var(--text-3);margin-top:6px;text-transform:uppercase;letter-spacing:1px;font-weight:700}
.roleta.caiu{border-style:solid;border-color:color-mix(in srgb,var(--green) 45%,transparent);background:color-mix(in srgb,var(--green) 7%,var(--panel-2))}
.roleta.caiu .roleta-nome{color:var(--green)}

/* As classes que estão concorrendo, visíveis dentro da roleta. */
.bolo{display:flex;flex-wrap:wrap;gap:7px;justify-content:center;margin-top:16px;
  max-height:132px;overflow-y:auto;padding:2px}
.chip{display:inline-flex;align-items:center;gap:7px;padding:5px 11px;border-radius:999px;
  background:var(--panel-3);border:1px solid var(--border-md);color:var(--text-2);
  font-size:11.5px;font-weight:600;white-space:nowrap}
.chip b{font-family:var(--num);font-size:11px;color:var(--red)}
.roleta.girando .chip{opacity:.35}
.fora{display:flex;gap:9px;align-items:flex-start;margin-top:12px;padding:11px 13px;border-radius:11px;
  font-size:12px;line-height:1.5;color:var(--text-2);
  background:color-mix(in srgb,var(--amber) 8%,transparent);
  border:1px solid color-mix(in srgb,var(--amber) 24%,transparent)}
.fora i{color:var(--amber);flex:none;margin-top:1px}
.fora b{color:var(--text)}

/* A borda transparente existe pro botão ter a mesma caixa do input ao lado —
   sem ela ficavam 2px mais baixos, e a linha inteira parecia torta. */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 20px;border-radius:11px;border:1.5px solid transparent;background:var(--red);color:#fff;font-family:var(--font);font-size:13.5px;font-weight:700;cursor:pointer;transition:filter .15s}
.btn:hover:not(:disabled){filter:brightness(1.12)}
.btn:disabled{opacity:.4;cursor:not-allowed}
.btn.ghost{background:transparent;border:1.5px solid var(--border-md);color:var(--text-2)}
.btn.ghost:hover:not(:disabled){border-color:var(--red);color:var(--red)}
.btn.sm{padding:7px 13px;font-size:12px}
.acoes{display:flex;gap:10px;flex-wrap:wrap;margin-top:14px;align-items:center}

/* Sem isto o input cai no padrão do navegador: caixa branca em cima de uma
   página preta. Herda a fonte da página — input não herda por conta própria. */
input[type=text]{
  flex:1;min-width:220px;background:var(--panel-2);border:1.5px solid var(--border-md);
  color:var(--text);border-radius:11px;padding:11px 14px;
  font-family:var(--font);font-size:13.5px;font-weight:600;outline:none;
  transition:border-color .15s,background .15s;
}
input[type=text]::placeholder{color:var(--text-3);font-weight:500}
input[type=text]:hover{border-color:color-mix(in srgb,var(--red) 40%,var(--border-md))}
/* Anel além da borda: tirei o outline do navegador, então o foco precisa de
   um sinal que não dependa de reparar numa borda de 1,5px. */
input[type=text]:focus{
  border-color:var(--red);background:var(--panel-3);
  box-shadow:0 0 0 3px color-mix(in srgb,var(--red) 18%,transparent);
}

table{width:100%;border-collapse:collapse;font-size:13px}
th{text-align:left;padding:8px 10px;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3);border-bottom:1px solid var(--border)}
td{padding:10px;border-bottom:1px solid var(--border);vertical-align:middle}
tr:last-child td{border-bottom:none}
.pill{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:999px;font-size:10.5px;font-weight:700;letter-spacing:.3px}
.pill.livre{background:color-mix(in srgb,var(--green) 14%,transparent);color:var(--green)}
.pill.usada{background:var(--panel-3);color:var(--text-3)}
.pill.orfa{background:color-mix(in srgb,var(--amber) 14%,transparent);color:var(--amber)}
.vazio{text-align:center;padding:26px;color:var(--text-3);font-size:12.5px}
.num{font-family:var(--num);font-weight:700}

.aviso{display:flex;gap:10px;align-items:flex-start;padding:12px 14px;border-radius:11px;font-size:12.5px;line-height:1.5;margin-bottom:14px}
.aviso i{font-size:15px;flex:none;margin-top:1px}
.aviso.alerta{background:color-mix(in srgb,var(--amber) 9%,transparent);border:1px solid color-mix(in srgb,var(--amber) 26%,transparent)}
.aviso.alerta i{color:var(--amber)}
.aviso.ok{background:color-mix(in srgb,var(--green) 9%,transparent);border:1px solid color-mix(in srgb,var(--green) 26%,transparent)}
.aviso.ok i{color:var(--green)}
@media(max-width:640px){ body{padding:16px 12px 50px} h1{font-size:20px} .passos{grid-template-columns:1fr} }
</style>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <div>
      <h1><i class="bi bi-shuffle"></i> Controle de Drafts</h1>
      <div class="sub">Cada liga tem seu bolo de classes, sua roleta e seu circuito.</div>
    </div>
    <a href="/admin.php" class="voltar"><i class="bi bi-arrow-left"></i> Admin</a>
  </div>

  <div class="abas" id="abas"></div>
  <div id="conteudo"><div class="vazio">Carregando…</div></div>
</div>

<?php // Fora do #conteudo: ele é reescrito a cada recarga e levaria o input junto. ?>
<input type="file" id="arquivoCSV" accept=".csv,.txt,text/csv" style="display:none" onchange="aoEscolherCSV(this)">

<script>
const LIGAS = <?= json_encode(array_values($minhasLigas)) ?>;
let ligaAtual = <?= json_encode($ligaInicial) ?>;
let estado = null;
let ocupado = false;

const esc = s => String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');

async function api(acao, corpo) {
  const url = '/api/controledrafts.php?action=' + encodeURIComponent(acao)
            + '&league=' + encodeURIComponent(ligaAtual);
  const opc = corpo
    ? { method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({ ...corpo, league: ligaAtual }) }
    : {};
  const r = await fetch(url, opc);
  const d = await r.json().catch(() => ({ success:false, error:'Resposta inválida do servidor.' }));
  if (!d.success) throw new Error(d.error || 'Falhou.');
  return d;
}

function renderAbas() {
  document.getElementById('abas').innerHTML = LIGAS.map(l =>
    `<button class="aba ${l === ligaAtual ? 'on' : ''}" onclick="trocarLiga('${l}')">${l}</button>`).join('');
}

async function trocarLiga(l) {
  if (ocupado) return;
  ligaAtual = l;
  renderAbas();
  await carregar();
}

async function carregar() {
  document.getElementById('conteudo').innerHTML = '<div class="vazio">Carregando…</div>';
  try {
    estado = (await api('estado')).estado;
    render();
  } catch (e) {
    document.getElementById('conteudo').innerHTML =
      `<div class="card"><div class="aviso alerta"><i class="bi bi-exclamation-triangle-fill"></i>
       <div>${esc(e.message)}</div></div></div>`;
  }
}

/** Roda uma ação e recarrega o estado. Erro vira aviso, não alert seco. */
async function acao(nome, corpo, confirmar) {
  if (ocupado) return;
  if (confirmar && !confirm(confirmar)) return;
  ocupado = true;
  document.querySelectorAll('.btn').forEach(b => b.disabled = true);
  try {
    const d = await api(nome, corpo || {});
    ocupado = false;
    await carregar();
    if (d.message) mostrarAviso('ok', d.message);
  } catch (e) {
    ocupado = false;
    await carregar();
    mostrarAviso('alerta', e.message);
  }
}

function mostrarAviso(tipo, texto) {
  const alvo = document.getElementById('conteudo');
  const div = document.createElement('div');
  div.className = 'aviso ' + tipo;
  div.innerHTML = `<i class="bi bi-${tipo === 'ok' ? 'check-circle-fill' : 'exclamation-triangle-fill'}"></i><div>${esc(texto)}</div>`;
  alvo.prepend(div);
  setTimeout(() => div.remove(), 7000);
}

function render() {
  const e = estado;
  const t = e.temporada;
  const passoPorChave = Object.fromEntries(e.passos.map(p => [p.chave, p]));

  const cabecalho = t
    ? `Temporada <b class="num">${t.numero}</b> · ano <b class="num">${t.ano}</b>`
    : 'Sem temporada em aberto nesta liga.';

  document.getElementById('conteudo').innerHTML = `
    <div class="card">
      <div class="card-tit"><i class="bi bi-diagram-3-fill"></i>Circuito · ${esc(e.league)}</div>
      <div class="sub" style="margin-bottom:14px">${cabecalho}</div>
      <div class="passos">
        ${e.passos.map((p, i) => `
          <div class="passo ${p.feito ? 'ok' : (p.bloqueio ? 'travado' : '')}">
            <div class="passo-n">PASSO ${i + 1}</div>
            <div class="passo-t">
              <i class="bi bi-${p.feito ? 'check-circle-fill' : (p.bloqueio ? 'exclamation-triangle-fill' : 'circle')}"></i>
              ${esc(p.titulo)}
            </div>
            <div class="passo-d">${esc(p.detalhe)}</div>
            ${p.bloqueio ? `<div class="passo-b">${esc(p.bloqueio)}</div>` : ''}
          </div>`).join('')}
      </div>
    </div>

    ${renderRoleta(passoPorChave)}
    ${renderAcoes(passoPorChave)}
    ${renderClasses()}
  `;
}

function renderRoleta(passos) {
  const e = estado;
  const jaSorteou = !!e.sorteio;
  const podeSortear = !jaSorteou && !passos.classe.bloqueio && e.temporada;

  // A roleta mostra o que tem dentro. Antes era um traço e um número — não
  // dava pra ver que a classe recém-cadastrada tinha entrado, nem por que
  // uma classe que existe não estava concorrendo.
  const noBolo = e.classes_disponiveis.filter(c => c.sorteavel);
  const deFora = e.classes_disponiveis.filter(c => !c.sorteavel);

  const chips = noBolo.length
    ? `<div class="bolo">${noBolo.map(c =>
        `<span class="chip" title="${c.jogadores} jogadores">${esc(c.name)}<b>${c.jogadores}</b></span>`).join('')}</div>`
    : '';

  const avisoDeFora = deFora.length
    ? `<div class="fora"><i class="bi bi-exclamation-triangle-fill"></i>
         ${deFora.length === 1 ? '1 classe está' : deFora.length + ' classes estão'} de fora por não ter
         jogador cadastrado: ${deFora.map(c => esc(c.name)).join(', ')}. Use <b>Importar</b> na linha
         ${deFora.length === 1 ? 'dela' : 'de cada uma'}, abaixo.</div>`
    : '';

  const avisoOrfas = (!noBolo.length && e.classes_sem_liga.length)
    ? `<div class="fora"><i class="bi bi-exclamation-triangle-fill"></i>
         Há <b>${e.classes_sem_liga.length}</b> classe(s) sem liga lá embaixo. Enquanto não forem
         trazidas pra ${esc(e.league)}, elas não concorrem nesta roleta.</div>`
    : '';

  return `
    <div class="card">
      <div class="card-tit"><i class="bi bi-dice-3-fill"></i>Roleta da ${esc(e.league)}</div>
      <div class="roleta ${jaSorteou ? 'caiu' : ''}" id="roleta">
        <div class="roleta-nome" id="roletaNome">${jaSorteou ? esc(e.sorteio.classe_nome) : (noBolo.length ? '?' : '—')}</div>
        <div class="roleta-sub" id="roletaSub">${
          jaSorteou ? `${e.sorteio.jogadores} jogadores · sorteada em ${String(e.sorteio.sorteado_em).slice(0,10).split('-').reverse().join('/')}`
                    : `${e.classes_sorteaveis} classe(s) concorrendo`}</div>
        ${jaSorteou ? '' : chips}
      </div>
      ${avisoDeFora}${avisoOrfas}
      <div class="acoes">
        <button class="btn" onclick="girar()" ${podeSortear ? '' : 'disabled'}>
          <i class="bi bi-dice-5-fill"></i>${jaSorteou ? 'Já sorteada' : 'Girar a roleta'}
        </button>
        ${jaSorteou ? '<span class="sub">Classe sorteada não volta pro bolo.</span>' : ''}
      </div>
    </div>`;
}

function renderAcoes(passos) {
  const e = estado;
  const temPool = passos.pool.feito;
  const podeAplicar = !!e.sorteio && !temPool;
  const podeAbrir = passos.loteria.feito && !passos.aberto.feito && e.sessao;
  return `
    <div class="card">
      <div class="card-tit"><i class="bi bi-play-circle-fill"></i>Próximos passos</div>
      <div class="acoes">
        <button class="btn" onclick="acao('aplicar_classe')" ${podeAplicar ? '' : 'disabled'}>
          <i class="bi bi-people-fill"></i>Colocar os jogadores no draft
        </button>
        <button class="btn ghost" onclick="acao('limpar_pool', null, 'Tirar todos os jogadores do draft desta temporada?')" ${temPool ? '' : 'disabled'}>
          <i class="bi bi-eraser-fill"></i>Limpar o pool
        </button>
        <a class="btn ghost" href="/lottery.php" style="text-decoration:none">
          <i class="bi bi-shuffle"></i>Loteria
        </a>
        <button class="btn" onclick="acao('abrir_draft', null, 'Abrir o draft para os GMs escolherem?')" ${podeAbrir ? '' : 'disabled'}>
          <i class="bi bi-unlock-fill"></i>Abrir o draft
        </button>
      </div>
      ${e.com_campanha === 0 ? `
        <div class="aviso alerta" style="margin-top:14px">
          <i class="bi bi-exclamation-triangle-fill"></i>
          <div><b>A loteria não tem de onde partir.</b> Esta temporada não tem classificação
          registrada (<span class="num">0</span> linhas em <code>season_standings</code>), e é a campanha
          que define quem entra no sorteio da ordem. Os dois primeiros passos funcionam normalmente.</div>
        </div>` : ''}
    </div>`;
}

function renderClasses() {
  const e = estado;
  const linha = (c, tipo) => `
    <tr>
      <td><b>${esc(c.name)}</b></td>
      <td class="num">${c.jogadores}</td>
      <td>${
        tipo === 'usada' ? `<span class="pill usada">usada${c.usada_em ? ' em ' + c.usada_em : ''}</span>`
      : tipo === 'orfa'  ? `<span class="pill orfa">sem liga</span>`
      // Sem jogador ela não entra na roleta — dizer "no bolo" seria mentira.
      : c.jogadores === 0 ? `<span class="pill orfa">sem jogadores</span>`
      : `<span class="pill livre">no bolo</span>`}</td>
      <td style="text-align:right;white-space:nowrap">${
        tipo === 'orfa'
          ? `<button class="btn ghost sm" onclick="acao('atribuir_liga',{template_id:${c.id}},'Colocar “${esc(c.name).replace(/'/g,'')}” no bolo da ${e.league}?')">
               <i class="bi bi-box-arrow-in-down"></i>Trazer pra ${e.league}</button>`
      : tipo === 'usada'
          // Classe sorteada é registro do que aconteceu: nem importa nem apaga.
          ? `<button class="btn ghost sm" onclick="verJogadores(${c.id},'${esc(c.name).replace(/'/g,'')}')"><i class="bi bi-list-ul"></i>Ver</button>`
          : `<button class="btn ghost sm" onclick="verJogadores(${c.id},'${esc(c.name).replace(/'/g,'')}')"><i class="bi bi-list-ul"></i>Ver</button>
             <button class="btn ghost sm" onclick="abrirImport(${c.id},'${esc(c.name).replace(/'/g,'')}')"><i class="bi bi-upload"></i>Importar</button>
             <button class="btn ghost sm" onclick="acao('excluir_classe',{template_id:${c.id}},'Apagar a classe “${esc(c.name).replace(/'/g,'')}” e os jogadores dela?')"><i class="bi bi-trash"></i></button>`}</td>
    </tr>`;

  const todas = [
    ...e.classes_disponiveis.map(c => linha(c, 'livre')),
    ...e.classes_usadas.map(c => linha(c, 'usada')),
  ];

  const semJog = e.classes_disponiveis.filter(c => c.jogadores === 0).length;
  const contagem = [
    `<b class="num">${e.classes_disponiveis.length + e.classes_usadas.length}</b> no total`,
    `<b class="num">${e.classes_sorteaveis}</b> no bolo`,
    semJog ? `<b class="num">${semJog}</b> sem jogadores` : '',
    e.classes_usadas.length ? `<b class="num">${e.classes_usadas.length}</b> já usada(s)` : '',
  ].filter(Boolean).join(' · ');

  return `
    <div class="card">
      <div class="card-tit"><i class="bi bi-collection-fill"></i>Classes da ${esc(e.league)}</div>
      <div class="sub" style="margin:-6px 0 12px">${contagem}</div>
      ${todas.length ? `<table><thead><tr><th>Classe</th><th>Jogadores</th><th>Estado</th><th></th></tr></thead>
        <tbody>${todas.join('')}</tbody></table>`
        : `<div class="vazio">Nenhuma classe nesta liga ainda.</div>`}
      <div class="acoes">
        <input type="text" id="novaClasse" placeholder="Nome da nova classe (ex: Draft 2040)"
               maxlength="120" autocomplete="off" onkeydown="if(event.key==='Enter')criarClasse()">
        <button class="btn" onclick="criarClasse()"><i class="bi bi-plus-lg"></i>Criar vazia</button>
      </div>
      <div class="sub" style="margin-top:8px">
        Cria a classe vazia nesta liga. Depois use <b>Importar</b> na linha dela pra trazer os jogadores.
      </div>
    </div>

    ${e.classes_sem_liga.length ? `
      <div class="card">
        <div class="card-tit"><i class="bi bi-question-circle-fill"></i>Classes sem liga (${e.classes_sem_liga.length})</div>
        <div class="sub" style="margin-bottom:12px">
          Classes que ficaram sem dono — ou porque foram criadas antes de as classes terem
          liga, ou porque saíram por um caminho do admin que não mandava a liga junto.
          Elas não entram na roleta de ninguém até serem atribuídas, e depois não saem mais.
        </div>
        <div class="acoes" style="margin:0 0 12px">
          <button class="btn" onclick="acao('atribuir_liga_todas',{},'Trazer TODAS as ${e.classes_sem_liga.length} classes sem liga pro bolo da ${e.league}?\\n\\nDepois disso elas não mudam mais de liga.')">
            <i class="bi bi-box-arrow-in-down"></i>Trazer todas pra ${e.league}
          </button>
        </div>
        <table><thead><tr><th>Classe</th><th>Jogadores</th><th>Estado</th><th></th></tr></thead>
          <tbody>${e.classes_sem_liga.map(c => linha(c, 'orfa')).join('')}</tbody></table>
      </div>` : ''}`;
}

async function criarClasse() {
  const campo = document.getElementById('novaClasse');
  const nome = (campo?.value || '').trim();
  if (!nome) { mostrarAviso('alerta', 'Dê um nome à classe.'); campo?.focus(); return; }
  await acao('criar_classe', { name: nome });
}

/**
 * Lê o CSV das classes: name, position, ovr, age e, opcional, pick_hint.
 *
 * Aceita vírgula ou ponto-e-vírgula (planilha em português salva com ";") e
 * campo entre aspas com vírgula dentro. Sem cabeçalho reconhecível, assume a
 * ordem das colunas — que é como a maioria das listas vem colada de planilha.
 */
function lerCSV(texto) {
  const linhas = texto.replace(/\r/g, '').split('\n').filter(l => l.trim() !== '');
  if (!linhas.length) return [];

  const sep = (linhas[0].match(/;/g) || []).length > (linhas[0].match(/,/g) || []).length ? ';' : ',';
  const partir = linha => {
    const saida = []; let atual = ''; let entreAspas = false;
    for (const ch of linha) {
      if (ch === '"') { entreAspas = !entreAspas; continue; }
      if (ch === sep && !entreAspas) { saida.push(atual.trim()); atual = ''; continue; }
      atual += ch;
    }
    saida.push(atual.trim());
    return saida;
  };

  const primeira = partir(linhas[0]).map(c => c.toLowerCase());
  const temCabecalho = primeira.some(c => ['name','nome','position','pos','posicao','posição','ovr','age','idade'].includes(c));
  const col = {
    name: 0, position: 1, ovr: 2, age: 3, pick_hint: 4,
  };
  if (temCabecalho) {
    const achar = (...nomes) => { const i = primeira.findIndex(c => nomes.includes(c)); return i >= 0 ? i : null; };
    col.name      = achar('name','nome') ?? 0;
    col.position  = achar('position','pos','posicao','posição') ?? 1;
    col.ovr       = achar('ovr','overall') ?? 2;
    col.age       = achar('age','idade') ?? 3;
    col.pick_hint = achar('pick_hint','ordem','pick');
  }

  return linhas.slice(temCabecalho ? 1 : 0).map(l => {
    const p = partir(l);
    return {
      name: p[col.name] ?? '',
      position: p[col.position] ?? '',
      ovr: p[col.ovr] ?? '',
      age: p[col.age] ?? '',
      pick_hint: col.pick_hint !== null && col.pick_hint !== undefined ? (p[col.pick_hint] ?? '') : '',
    };
  }).filter(j => (j.name || '').trim() !== '');
}

let _importTpl = null;

function abrirImport(tplId, nome) {
  _importTpl = tplId;
  const inp = document.getElementById('arquivoCSV');
  inp.value = '';
  inp.dataset.nome = nome;
  inp.click();
}

async function aoEscolherCSV(input) {
  const arq = input.files?.[0];
  if (!arq || !_importTpl) return;
  const texto = await arq.text();
  const jogadores = lerCSV(texto);
  if (!jogadores.length) {
    mostrarAviso('alerta', 'Não achei nenhuma linha com nome nesse arquivo.');
    return;
  }
  if (!confirm(`Importar ${jogadores.length} jogador(es) para “${input.dataset.nome}”?\n\n`
             + `Isso substitui a lista atual da classe.\n\n`
             + `Primeiro: ${jogadores[0].name} · ${jogadores[0].position} · OVR ${jogadores[0].ovr}`)) return;
  await acao('importar_jogadores', { template_id: _importTpl, players: jogadores });
}

/** Mostra os jogadores da classe, só pra conferir. */
async function verJogadores(tplId, nome) {
  try {
    const r = await fetch('/api/controledrafts.php?action=jogadores_da_classe&league='
      + encodeURIComponent(ligaAtual) + '&template_id=' + tplId);
    const d = await r.json();
    if (!d.success) throw new Error(d.error);
    const j = d.players || [];
    if (!j.length) { mostrarAviso('alerta', `“${nome}” ainda não tem jogadores.`); return; }
    // Nome, posição e ordem. OVR e idade são iguais pra todo mundo e ficam
    // por conta do 2K — mostrá-los aqui seria repetir 60/18 sessenta vezes.
    const linhas = j.map((p, i) => `${String(p.pick_hint ?? (i + 1)).padStart(2)}. ${p.name} · ${p.position}`);
    alert(`${nome} — ${j.length} jogadores\n\n` + linhas.join('\n'));
  } catch (e) {
    mostrarAviso('alerta', e.message || 'Não deu pra carregar.');
  }
}

/**
 * Gira a roleta. O servidor já devolve a classe sorteada junto da lista de
 * candidatas — a animação passa pelos nomes e para na que veio decidida.
 */
async function girar() {
  if (ocupado) return;
  ocupado = true;
  document.querySelectorAll('.btn').forEach(b => b.disabled = true);

  const cx = document.getElementById('roleta');
  const nome = document.getElementById('roletaNome');
  const sub = document.getElementById('roletaSub');
  cx.classList.add('girando');
  sub.textContent = 'girando…';

  let d;
  try {
    d = await api('sortear', {});
  } catch (e) {
    cx.classList.remove('girando');
    ocupado = false;
    await carregar();
    mostrarAviso('alerta', e.message);
    return;
  }

  // Passa pelos nomes desacelerando, e termina na sorteada.
  const nomes = (d.candidatas || []).map(c => c.name);
  let i = 0, espera = 60;
  const passar = () => {
    nome.textContent = nomes.length ? nomes[i++ % nomes.length] : '…';
    espera *= 1.16;
    if (espera < 320) return setTimeout(passar, espera);
    cx.classList.remove('girando');
    cx.classList.add('caiu');
    nome.textContent = d.sorteada.name;
    sub.textContent = d.sorteada.jogadores + ' jogadores';
    setTimeout(async () => { ocupado = false; await carregar(); mostrarAviso('ok', d.message); }, 1100);
  };
  setTimeout(passar, espera);
}

renderAbas();
carregar();
</script>
</body>
</html>
