<?php
/**
 * ATUALIZAR O ELENCO DE OUTRO GM
 *
 * O caminho de quem quer ajudar (e ganhar moeda) preenchendo skills e
 * estatísticas de um time da própria liga que está parado.
 *
 * É deliberadamente parecida com atualizar-elenco.php — mesma lógica de
 * modelo, mesmo formato de CSV, mesma revisão antes de gravar. O que muda
 * é quem pode: aqui é terceiro, e cada time só aceita uma vez.
 *
 * Não é a mesma página porque o caminho do dono grava por APIs que checam
 * dono; misturar os dois significaria afrouxar aquelas checagens, que é a
 * última coisa que se quer mexer.
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/atualizacoes.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
ensureAtualizacaoTables($pdo);

$stMeu = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
$stMeu->execute([(int)$user['id']]);
$meuTime = $stMeu->fetch(PDO::FETCH_ASSOC);
if (!$meuTime) { header('Location: /dashboard.php'); exit; }
$minhaLiga = strtoupper((string)$meuTime['league']);
$timeAlvo  = (int)($_GET['time'] ?? 0);
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Atualizar elenco de outro time · FBA</title>
<?php include __DIR__ . '/includes/head-pwa.php'; ?>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
:root{
  --bg:#0b0b0e;--panel:#131317;--panel2:#191920;--panel3:#22222b;
  --border:#26262f;--border2:#33333f;
  --text:#f2f2f5;--text2:#9a9aa8;--text3:#6b6b78;
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);
  --green:#22c55e;--green-soft:rgba(34,197,94,.12);
  --amber:#f59e0b;--amber-soft:rgba(245,158,11,.12);
  --font:'Inter',system-ui,-apple-system,sans-serif;
  --num:'JetBrains Mono',ui-monospace,monospace;
}
:root[data-theme="light"]{
  --bg:#f4f4f6;--panel:#fff;--panel2:#fafafb;--panel3:#eeeef2;
  --border:#e2e2e8;--border2:#d2d2da;--text:#17171c;--text2:#5c5c6a;--text3:#8a8a99;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);-webkit-font-smoothing:antialiased}
.topo{display:flex;align-items:center;gap:12px;padding:12px 16px;background:var(--panel);
  border-bottom:1px solid var(--border);position:sticky;top:0;z-index:20}
.topo h1{margin:0;font-size:15px;font-weight:800}
.voltar{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--border);color:var(--text2);text-decoration:none;flex-shrink:0;transition:.15s}
.voltar:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.saldo{margin-left:auto;font-family:var(--num);font-size:13px;font-weight:800;color:var(--amber)}
.main{max-width:1100px;margin:0 auto;padding:18px 16px 60px}
.painel{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:16px;margin-bottom:14px}
.titulo{font-size:11px;font-weight:800;letter-spacing:1px;text-transform:uppercase;color:var(--text3);
  margin-bottom:10px;display:flex;align-items:center;gap:7px}
.lead{font-size:13.5px;line-height:1.6;color:var(--text2);margin:0 0 12px}
.btn{display:inline-flex;align-items:center;gap:7px;background:var(--red);color:#fff;border:0;
  border-radius:10px;padding:11px 18px;font-family:var(--font);font-size:13px;font-weight:800;
  cursor:pointer;transition:.15s}
.btn:hover:not(:disabled){filter:brightness(1.1)}
.btn:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.btn.ghost{background:transparent;border:1.5px solid var(--border2);color:var(--text2)}
.btn.ghost:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
/* O botão principal: azul, sozinho na linha e maior que os outros. */
.btn.azul{background:#2563eb}
.btn.azul:hover{background:#1d4ed8;filter:none}
.btn.largo{display:flex;width:100%;justify-content:center;margin-top:12px;padding:14px 18px;
  font-size:14px;cursor:pointer}
.acoes{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.grade-times{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:10px}
.time-card{background:var(--panel2);border:1.5px solid var(--border);border-radius:12px;padding:12px;
  text-align:left;cursor:pointer;font-family:var(--font);color:var(--text);transition:.15s}
.time-card:hover:not(:disabled){border-color:var(--red);background:var(--red-soft);transform:translateY(-1px)}
.time-card:disabled{opacity:.45;cursor:not-allowed}
.time-card b{display:block;font-size:14px;font-weight:800;margin-bottom:3px}
.time-card small{display:block;font-size:11px;color:var(--text2)}
.chip{display:inline-block;font-size:9.5px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
  border-radius:99px;padding:2px 8px;margin-top:7px;border:1px solid}
.chip.falta{color:var(--amber);border-color:rgba(245,158,11,.3);background:var(--amber-soft)}
.chip.ok{color:var(--green);border-color:rgba(34,197,94,.3);background:var(--green-soft)}
.chip.travado{color:var(--text3);border-color:var(--border);background:var(--panel3)}
.aviso{display:flex;gap:9px;align-items:flex-start;border-radius:10px;padding:11px 13px;font-size:13px;
  line-height:1.5;margin-top:12px;border:1px solid}
.aviso.ok{color:var(--green);border-color:rgba(34,197,94,.3);background:var(--green-soft)}
.aviso.err{color:var(--red);border-color:var(--red-soft);background:var(--red-soft)}
.aviso.info{color:var(--text2);border-color:var(--border);background:var(--panel2)}
.rolar{overflow-x:auto;border:1px solid var(--border);border-radius:10px}
table{width:100%;border-collapse:collapse;font-size:12.5px}
th{background:var(--panel2);color:var(--text3);font-size:9.5px;font-weight:800;letter-spacing:.8px;
  text-transform:uppercase;padding:8px 9px;text-align:left;white-space:nowrap}
td{padding:6px 9px;border-top:1px solid var(--border);white-space:nowrap}
td.nm{font-weight:700}
.val{font-family:var(--num);font-variant-numeric:tabular-nums}
.mudou{color:var(--amber);font-weight:800}
.premio{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px}
.premio span{flex:1;min-width:130px;background:var(--panel2);border:1px solid var(--border);border-radius:10px;
  padding:9px 12px;font-size:9.5px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;color:var(--text3);
  display:flex;flex-direction:column;gap:2px}
.premio b{font-family:var(--num);font-size:18px;font-weight:900;color:var(--amber);letter-spacing:-.5px}
.oculto{display:none}
</style>
</head>
<body>

<div class="topo">
  <a href="/teams.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
  <h1>Atualizar elenco de outro time</h1>
  <span class="saldo" id="saldo"></span>
</div>

<div class="main">

  <div class="painel" id="painelEscolha">
    <div class="titulo"><i class="bi bi-people-fill"></i> Times da <?= htmlspecialchars($minhaLiga) ?></div>
    <p class="lead">Preencha as skills e as estatísticas de um time parado e receba moedas por isso.
    <b>Cada time aceita uma atualização de terceiro</b> — depois disso só o dono muda. Escolha um:</p>
    <div class="premio">
      <span>Skills<b id="pSkills">—</b></span>
      <span>Estatísticas<b id="pStats">—</b></span>
      <span>Times que você já atualizou<b id="pTimes">—</b></span>
      <span>Moedas ganhas assim<b id="pMoedas">—</b></span>
    </div>
    <div id="listaTimes" class="grade-times" style="margin-top:14px"></div>
  </div>

  <div class="painel oculto" id="painelCSV">
    <div class="titulo"><i class="bi bi-filetype-csv"></i> <span id="tituloTime"></span></div>
    <p class="lead">Baixe o modelo, preencha (à mão ou pedindo pra uma IA a partir de um print) e suba o
    arquivo. Nada é gravado direto do CSV: você confere na tabela e clica em salvar.</p>
    <div class="acoes">
      <button class="btn ghost" id="btnModeloSkills"><i class="bi bi-download"></i> Modelo de skills</button>
      <button class="btn ghost" id="btnModeloStats"><i class="bi bi-download"></i> Modelo de estatísticas</button>
      <button class="btn ghost" id="btnPrompt"><i class="bi bi-clipboard"></i> Copiar prompt pra IA</button>
      <button class="btn ghost" id="btnTrocar"><i class="bi bi-arrow-left-right"></i> Trocar de time</button>
    </div>

    <!-- Sozinho na linha e em azul: é o passo principal da página. Junto dos
         outros, o botão que faz a coisa acontecer ficava com o mesmo peso do
         que baixa um modelo. -->
    <label class="btn azul largo" id="rotuloEnviar">
      <i class="bi bi-upload"></i> Enviar atualização
      <input type="file" id="arquivo" accept=".csv,text/csv" hidden>
    </label>
    <div id="msgImport"></div>
  </div>

  <div class="painel oculto" id="painelRevisao">
    <div class="titulo"><i class="bi bi-eye-fill"></i> Confira antes de salvar</div>
    <div class="rolar"><table id="tabela"></table></div>
    <div class="acoes" style="margin-top:14px">
      <button class="btn" id="btnSalvar"><i class="bi bi-save2"></i> Salvar e receber</button>
      <button class="btn ghost" id="btnLimpar" title="Descarta o que foi lido e volta pro envio">
        <i class="bi bi-eraser"></i> Limpar
      </button>
      <span class="lead" style="margin:0" id="resumoPremio"></span>
    </div>
    <div id="msgSalvar"></div>
  </div>

</div>

<script>
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const API = '/api/atualizar-time.php';

let SKILLS = {}, NOTAS = [], STATS = {}, PREMIO = {skills:100, stats:80};
let elenco = [], timeAtual = null, novosSkills = {}, novosStats = {}, csvBruto = {skills:'', stats:''};

function msg(el, tipo, texto) {
  el.innerHTML = `<div class="aviso ${tipo}"><i class="bi bi-${
    tipo === 'ok' ? 'check-circle-fill' : tipo === 'err' ? 'x-octagon-fill' : 'info-circle'}"></i><div>${texto}</div></div>`;
}

/* ── CSV ─────────────────────────────────────────────────────────── */
function csvEscape(v){ v = String(v ?? ''); return /[",\n\r]/.test(v) ? '"' + v.replace(/"/g,'""') + '"' : v; }
function baixarCSV(nome, linhas){
  // BOM: sem ele o Excel do Windows abre os acentos errados.
  const csv = '﻿' + linhas.map(l => l.map(csvEscape).join(',')).join('\r\n');
  const a = document.createElement('a');
  a.href = URL.createObjectURL(new Blob([csv], {type:'text/csv;charset=utf-8;'}));
  a.download = nome; document.body.appendChild(a); a.click(); a.remove();
}
function parseCSV(texto){
  texto = texto.replace(/^﻿/, '');
  const linhas = []; let linha = [], campo = '', aspas = false;
  for (let i = 0; i < texto.length; i++){
    const c = texto[i];
    if (aspas){ if (c === '"'){ if (texto[i+1] === '"'){ campo += '"'; i++; } else aspas = false; } else campo += c; }
    else if (c === '"') aspas = true;
    else if (c === ',') { linha.push(campo); campo = ''; }
    else if (c === '\r') {}
    else if (c === '\n') { linha.push(campo); linhas.push(linha); linha = []; campo = ''; }
    else campo += c;
  }
  if (campo !== '' || linha.length) { linha.push(campo); linhas.push(linha); }
  return linhas.filter(l => !(l.length === 1 && l[0].trim() === ''));
}

/* ── Escolha do time ─────────────────────────────────────────────── */
async function carregarTimes(){
  const r = await fetch(`${API}?acao=elegiveis`);
  const d = await r.json();
  if (!d.ok) { msg($('listaTimes'), 'err', esc(d.erro || 'Erro ao listar.')); return; }

  PREMIO = d.premio || PREMIO;
  $('pSkills').textContent = PREMIO.skills;
  $('pStats').textContent  = PREMIO.stats;
  $('pTimes').textContent  = d.resumo?.times ?? 0;
  $('pMoedas').textContent = d.resumo?.moedas ?? 0;

  if (!d.times.length){ msg($('listaTimes'), 'info', 'Nenhum outro time na sua liga.'); return; }

  $('listaTimes').innerHTML = d.times.map(t => `
    <button class="time-card" ${t.travado ? 'disabled' : ''} data-id="${t.id}">
      <b>${esc(t.nome)}</b>
      <small>${esc(t.dono)} · ${t.jogadores} jogadores</small>
      ${t.travado ? '<span class="chip travado">já atualizado</span>'
        : (t.faltam || []).length === 1
          // Metade feita: dizer O QUE falta evita mandar o CSV errado e
          // descobrir depois que aquele lado não valia mais nada.
          ? `<span class="chip falta">falta ${t.faltam[0] === 'skills' ? 'skills' : 'estatísticas'}</span>`
        : t.sem_skills > 0 ? `<span class="chip falta">${t.sem_skills} sem skills</span>`
        : '<span class="chip ok">skills completas</span>'}
    </button>`).join('');

  $('listaTimes').querySelectorAll('.time-card:not(:disabled)').forEach(b =>
    b.addEventListener('click', () => abrirTime(parseInt(b.dataset.id, 10))));
}

async function abrirTime(id){
  const r = await fetch(`${API}?acao=elenco&time=${id}`);
  const d = await r.json();
  if (!d.ok){ msg($('listaTimes'), 'err', esc(d.erro || 'Não deu pra abrir.')); return; }

  timeAtual = d.time; elenco = d.jogadores;
  SKILLS = d.skills; NOTAS = d.notas; STATS = d.stats; PREMIO = d.premio;
  novosSkills = {}; novosStats = {}; csvBruto = {skills:'', stats:''};

  $('tituloTime').textContent = `${d.time.nome} — ${d.time.dono}`;
  $('painelEscolha').classList.add('oculto');
  $('painelCSV').classList.remove('oculto');
  $('painelRevisao').classList.add('oculto');
  $('msgImport').innerHTML = '';
}

$('btnTrocar').addEventListener('click', () => {
  $('painelCSV').classList.add('oculto');
  $('painelRevisao').classList.add('oculto');
  $('painelEscolha').classList.remove('oculto');
  carregarTimes();
});

/* ── Modelos ─────────────────────────────────────────────────────── */
function linhasSkills(){
  const cab = ['id', 'jogador', 'ovr', 'idade', ...Object.values(SKILLS)];
  return [cab, ...elenco.map(p => [p.id, p.name, p.ovr, p.age,
    ...Object.keys(SKILLS).map(k => p[k] || '')])];
}
function linhasStats(){
  return [['id', 'jogador', ...Object.values(STATS)],
          ...elenco.map(p => [p.id, p.name, ...Object.keys(STATS).map(() => '')])];
}
$('btnModeloSkills').addEventListener('click', () => baixarCSV('skills.csv', linhasSkills()));
$('btnModeloStats').addEventListener('click', () => baixarCSV('estatisticas.csv', linhasStats()));

$('btnPrompt').addEventListener('click', async (ev) => {
  // Pede ARQUIVO, não texto na tela. Copiar CSV do chat e colar num editor é
  // onde a coisa quebra: o chat mete markdown, quebra linha errado e o acento
  // vem sem BOM. Pedindo o .csv pronto, o caminho vira baixar e subir.
  const texto =
    'Preencha este CSV com os dados dos jogadores de basquete a partir da imagem que vou anexar.\n\n' +
    'As notas de skill são: ' + NOTAS.join(', ') + '.\n' +
    'NÃO altere as colunas "id" e "jogador" — elas ligam cada linha ao jogador certo.\n\n' +
    'IMPORTANTE: me devolva o resultado como um ARQUIVO .csv pronto pra baixar, ' +
    'codificado em UTF-8, separado por vírgula, com o mesmo cabeçalho do modelo. ' +
    'Não escreva o CSV no meio da conversa e não explique nada — só gere o arquivo.\n\n' +
    '--- MODELO ---\n' +
    linhasSkills().map(l => l.map(csvEscape).join(',')).join('\n');
  try { await navigator.clipboard.writeText(texto); } catch (e) {
    const t = document.createElement('textarea'); t.value = texto;
    document.body.appendChild(t); t.select(); document.execCommand('copy'); t.remove();
  }
  const antes = ev.currentTarget.innerHTML;
  ev.currentTarget.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
  setTimeout(() => { ev.currentTarget.innerHTML = antes; }, 1800);
});

/* ── Importar ────────────────────────────────────────────────────── */
$('arquivo').addEventListener('change', (e) => {
  const f = e.target.files[0];
  if (f) importar(f);
  e.target.value = '';
});

function importar(file){
  const fr = new FileReader();
  fr.onload = () => {
    const texto = String(fr.result || '');
    const linhas = parseCSV(texto);
    if (linhas.length < 2){ msg($('msgImport'), 'err', 'CSV vazio ou sem linhas de jogador.'); return; }

    const cab = linhas[0].map(c => c.trim().toLowerCase());
    const iId = cab.indexOf('id');
    if (iId < 0){ msg($('msgImport'), 'err', 'Falta a coluna <b>id</b>. Use o modelo desta página.'); return; }

    // O tipo sai do cabeçalho: se tem as skills é de skills, se tem Jogos é
    // de estatística. Ler o conteúdo em vez de pedir pra pessoa escolher tira
    // um passo e um jeito de errar.
    const rotSkills = Object.values(SKILLS).map(s => s.toLowerCase());
    const rotStats  = Object.values(STATS).map(s => s.toLowerCase());
    const ehSkills = rotSkills.every(r => cab.includes(r));
    const ehStats  = rotStats.every(r => cab.includes(r));
    if (!ehSkills && !ehStats){
      msg($('msgImport'), 'err', 'Não reconheci as colunas. Baixe um dos modelos e preencha sem mudar o cabeçalho.');
      return;
    }

    const idsDoTime = new Set(elenco.map(p => p.id));
    let aplicados = 0, fora = 0;

    if (ehSkills){
      csvBruto.skills = texto;
      const iOvr = cab.indexOf('ovr'), iIdade = cab.indexOf('idade');
      linhas.slice(1).forEach(l => {
        const id = parseInt(l[iId], 10);
        if (!idsDoTime.has(id)) { fora++; return; }
        const reg = {id};
        Object.entries(SKILLS).forEach(([col, rot]) => {
          const v = (l[cab.indexOf(rot.toLowerCase())] || '').trim().toUpperCase();
          if (v) reg[col] = v;
        });
        if (iOvr   >= 0 && l[iOvr]   !== '') reg.ovr = parseInt(l[iOvr], 10);
        if (iIdade >= 0 && l[iIdade] !== '') reg.age = parseInt(l[iIdade], 10);
        novosSkills[id] = reg; aplicados++;
      });
    } else {
      csvBruto.stats = texto;
      linhas.slice(1).forEach(l => {
        const id = parseInt(l[iId], 10);
        if (!idsDoTime.has(id)) { fora++; return; }
        const reg = {id};
        Object.entries(STATS).forEach(([col, rot]) => {
          reg[col] = (l[cab.indexOf(rot.toLowerCase())] || '').trim();
        });
        novosStats[id] = reg; aplicados++;
      });
    }

    msg($('msgImport'), aplicados ? 'ok' : 'err',
      `${aplicados} jogador(es) lidos do CSV de <b>${ehSkills ? 'skills' : 'estatísticas'}</b>.` +
      (fora ? ` ${fora} linha(s) ignorada(s): não são deste time.` : '') +
      (aplicados ? ' Confira abaixo e salve.' : ''));

    if (aplicados) desenharRevisao();
  };
  fr.readAsText(file, 'utf-8');
}

/* ── Revisão ─────────────────────────────────────────────────────── */
function desenharRevisao(){
  const temSkills = Object.keys(novosSkills).length > 0;
  const temStats  = Object.keys(novosStats).length > 0;
  const colsS = Object.entries(SKILLS), colsE = Object.entries(STATS);

  const cab = ['Jogador']
    .concat(temSkills ? ['OVR', 'Idade', ...colsS.map(([,r]) => r)] : [])
    .concat(temStats  ? colsE.map(([,r]) => r) : []);

  const linhas = elenco.filter(p => novosSkills[p.id] || novosStats[p.id]).map(p => {
    const s = novosSkills[p.id] || {}, e = novosStats[p.id] || {};
    const tds = [`<td class="nm">${esc(p.name)}</td>`];
    if (temSkills){
      const mudou = (novo, velho) => novo != null && String(novo) !== String(velho ?? '');
      tds.push(`<td class="val ${mudou(s.ovr, p.ovr) ? 'mudou' : ''}">${esc(s.ovr ?? p.ovr)}</td>`);
      tds.push(`<td class="val ${mudou(s.age, p.age) ? 'mudou' : ''}">${esc(s.age ?? p.age)}</td>`);
      colsS.forEach(([col]) => {
        const novo = s[col];
        tds.push(`<td class="val ${mudou(novo, p[col]) ? 'mudou' : ''}">${esc(novo ?? p[col] ?? '—')}</td>`);
      });
    }
    if (temStats) colsE.forEach(([col]) => tds.push(`<td class="val mudou">${esc(e[col] || '0')}</td>`));
    return `<tr>${tds.join('')}</tr>`;
  }).join('');

  $('tabela').innerHTML = `<thead><tr>${cab.map(c => `<th>${esc(c)}</th>`).join('')}</tr></thead><tbody>${linhas}</tbody>`;

  const total = (temSkills ? PREMIO.skills : 0) + (temStats ? PREMIO.stats : 0);
  $('resumoPremio').innerHTML = `Vale <b style="color:var(--amber)">${total} moedas</b>` +
    (temSkills && temStats ? ' (skills + estatísticas).'
      : temSkills ? ' — dá pra subir o CSV de estatísticas também antes de salvar.'
      : ' — dá pra subir o CSV de skills também antes de salvar.');
  $('painelRevisao').classList.remove('oculto');
  $('painelRevisao').scrollIntoView({behavior:'smooth', block:'nearest'});
}

/* ── Limpar ──────────────────────────────────────────────────────────
   Mandou o CSV errado, ou o do time errado, e percebeu na conferência: sem
   isto o jeito de recomeçar era sair e entrar de novo na página. Nada foi
   gravado ainda, então limpar é só esquecer o que foi lido. */
$('btnLimpar').addEventListener('click', () => {
  novosSkills = {}; novosStats = {};
  csvBruto = {skills: '', stats: ''};
  $('tabela').innerHTML = '';
  $('painelRevisao').classList.add('oculto');
  $('msgSalvar').innerHTML = '';
  msg($('msgImport'), 'info', 'Limpo. Envie o CSV de novo quando quiser.');
  $('painelCSV').scrollIntoView({behavior: 'smooth', block: 'nearest'});
});

/* ── Salvar ──────────────────────────────────────────────────────── */
$('btnSalvar').addEventListener('click', async () => {
  const btn = $('btnSalvar');
  btn.disabled = true;
  btn.innerHTML = '<i class="bi bi-hourglass-split"></i> Salvando…';
  $('msgSalvar').innerHTML = '';
  try {
    const r = await fetch(API, {
      method: 'POST', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({
        acao: 'salvar', time: timeAtual.id,
        skills: Object.values(novosSkills), stats: Object.values(novosStats),
        csv_skills: csvBruto.skills, csv_stats: csvBruto.stats,
      }),
    });
    const d = await r.json();
    if (!r.ok || !d.ok){ msg($('msgSalvar'), 'err', esc(d.erro || 'Erro ao salvar.')); return; }

    // O que sobrou e o que foi ignorado precisam ser ditos: receber 80 no
    // lugar de 180 sem explicação vira reclamação, e "não aceita mais nada"
    // dito de um time que ainda pode receber stats é mentira.
    const nome = t => t === 'skills' ? 'skills' : 'estatísticas';
    const ignorados = d.ignorados || [], faltam = d.faltam || [];
    msg($('msgSalvar'), 'ok',
      `Pronto — <b>${d.moedas} moedas</b> creditadas.` +
      (d.skills ? ` ${d.skills} jogador(es) com skills.` : '') +
      (d.stats ? ` ${d.stats} com estatísticas.` : '') +
      (ignorados.length
        ? ` Ignorei ${ignorados.map(nome).join(' e ')}: outra pessoa já tinha feito.` : '') +
      (faltam.length
        ? ` Ainda falta ${faltam.map(nome).join(' e ')} — qualquer um da liga pode completar.`
        : ' Este time não aceita mais atualização de terceiro.'));
    btn.classList.add('oculto');
    $('painelCSV').classList.add('oculto');
    setTimeout(() => {
      $('painelRevisao').classList.add('oculto');
      $('painelEscolha').classList.remove('oculto');
      btn.classList.remove('oculto');
      carregarTimes();
    }, 3200);
  } catch (e) {
    msg($('msgSalvar'), 'err', 'Erro de rede. Tente de novo.');
  } finally {
    btn.disabled = false;
    btn.innerHTML = '<i class="bi bi-save2"></i> Salvar e receber';
  }
});

/* ── Início ──────────────────────────────────────────────────────── */
carregarTimes().then(() => {
  const alvo = <?= $timeAlvo ?>;
  if (alvo > 0) abrirTime(alvo);
});
</script>
</body>
</html>
