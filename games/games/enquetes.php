<?php
/**
 * ENQUETES — a página. Toda a matemática mora em core/enquetes_motor.php e
 * chega aqui pela api/enquetes.php; esta tela não decide moeda nenhuma.
 */
require __DIR__ . '/../core/conexao.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
if ($idUsuario <= 0) { header('Location: /login.php'); exit; }

/*
 * SÓ ADMIN GERAL, POR ENQUANTO.
 *
 * Enquanto quem cria declara o próprio resultado, a aba fica com quem
 * responde pela liga. Esconder o card no /games não basta: o endereço é
 * adivinhável e a API responde a quem chamar, então a trava mora aqui e
 * também em api/enquetes.php.
 */
if (($_SESSION['user_type'] ?? '') !== 'admin') {
    header('Location: /games.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Enquetes — FBA Games</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--bg:#0a0a0c;--panel:#141418;--panel2:#1b1b21;--panel3:#232329;
  --borda:rgba(255,255,255,.07);--texto:#f4f4f5;--text2:#a1a1aa;--text3:#71717a;
  --verde:#22c55e;--vermelho:#ef4444;--amber:#f59e0b;--azul:#3b82f6;
  --font:'Inter',system-ui,sans-serif;--num:'Inter',sans-serif}
*{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--texto);font-family:var(--font);
  padding:18px 14px 60px;-webkit-font-smoothing:antialiased}
.wrap{max-width:940px;margin:0 auto}
.topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
h1{font-size:23px;font-weight:900;letter-spacing:-.5px}
.volta{color:var(--text3);text-decoration:none;font-size:13px;font-weight:700}
.volta:hover{color:var(--texto)}
.lead{color:var(--text2);font-size:13.5px;line-height:1.6;margin-bottom:16px;max-width:70ch}

.saldos{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}
.saldo{background:var(--panel);border:1px solid var(--borda);border-radius:12px;
  padding:11px 15px;min-width:126px}
.saldo b{display:block;font-family:var(--num);font-size:20px;font-weight:900;letter-spacing:-.5px}
.saldo span{font-size:9.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--text3)}

.btn{background:var(--panel3);border:1px solid var(--borda);color:var(--texto);
  border-radius:10px;padding:10px 15px;font-family:var(--font);font-size:13px;
  font-weight:800;cursor:pointer;transition:border-color .15s}
.btn:hover{border-color:rgba(255,255,255,.22)}
.btn.pri{background:var(--verde);border-color:var(--verde);color:#08160c}
.btn.mal{color:var(--vermelho);border-color:rgba(239,68,68,.4)}
.btn:disabled{opacity:.45;cursor:not-allowed}

.card{background:var(--panel);border:1px solid var(--borda);border-radius:15px;
  padding:16px 17px;margin-bottom:13px}
.card.minha{border-color:rgba(59,130,246,.35)}
.card.paga{opacity:.72}
.ch{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:4px}
.ch h2{font-size:15.5px;font-weight:800;letter-spacing:-.2px;flex:1;min-width:200px}
.selo{font-size:9.5px;font-weight:900;letter-spacing:.6px;text-transform:uppercase;
  padding:4px 8px;border-radius:99px;white-space:nowrap}
.selo.aberta{background:rgba(34,197,94,.16);color:var(--verde)}
.selo.paga{background:rgba(113,113,122,.2);color:var(--text2)}
.selo.cancelada{background:rgba(239,68,68,.14);color:var(--vermelho)}
.sub{font-size:12px;color:var(--text3);margin-bottom:12px}
.sub b{color:var(--text2)}

.alts{display:flex;flex-direction:column;gap:7px}
.alt{display:flex;align-items:center;gap:11px;background:var(--panel2);
  border:1px solid var(--borda);border-radius:11px;padding:11px 13px;width:100%;
  color:var(--texto);font-family:var(--font);text-align:left;cursor:pointer;
  position:relative;overflow:hidden;transition:border-color .15s}
.alt:hover{border-color:rgba(255,255,255,.2)}
.alt.ganhou{border-color:rgba(34,197,94,.5);background:rgba(34,197,94,.09)}
.alt.tem{border-color:rgba(59,130,246,.4)}
.alt[disabled]{cursor:default}
.barra{position:absolute;left:0;top:0;bottom:0;background:rgba(255,255,255,.045);
  transition:width .4s ease}
.alt-txt{flex:1;min-width:0;font-size:13.5px;font-weight:700;position:relative}
.alt-min{display:block;font-size:10.5px;font-weight:600;color:var(--text3);margin-top:2px}
.odd{font-family:var(--num);font-size:17px;font-weight:900;color:var(--amber);
  position:relative;white-space:nowrap}
.odd small{display:block;font-size:8.5px;font-weight:800;color:var(--text3);
  letter-spacing:.5px;text-transform:uppercase;text-align:right}

.linha-dono{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;
  padding-top:12px;border-top:1px solid var(--borda)}
.vazio{color:var(--text3);font-size:13px;padding:26px 0;text-align:center}

.modal{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;
  align-items:center;justify-content:center;padding:16px;z-index:50}
.modal.on{display:flex}
.mbox{background:var(--panel);border:1px solid var(--borda);border-radius:16px;
  padding:20px;width:100%;max-width:520px;max-height:88vh;overflow:auto}
.mbox h3{font-size:17px;font-weight:900;margin-bottom:4px}
.mbox p.aj{font-size:12px;color:var(--text3);margin-bottom:14px;line-height:1.55}
label{display:block;font-size:10px;font-weight:800;letter-spacing:.6px;
  text-transform:uppercase;color:var(--text3);margin:11px 0 5px}
input,textarea,select{width:100%;background:var(--panel2);border:1px solid var(--borda);
  border-radius:9px;padding:10px 12px;color:var(--texto);font-family:var(--font);
  font-size:14px;font-weight:600;outline:none}
input:focus,textarea:focus{border-color:rgba(255,255,255,.24)}
.alt-linha{display:grid;grid-template-columns:1fr 92px;gap:7px;margin-bottom:7px}
.duo{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.aviso{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
  border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text2);
  line-height:1.55;margin-top:12px}
.mfoot{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
@media(max-width:560px){ .duo{grid-template-columns:1fr} h1{font-size:20px} }
</style>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <h1>Enquetes</h1>
    <a class="volta" href="/games.php"><i class="bi bi-arrow-left"></i> voltar aos games</a>
    <button class="btn pri" style="margin-left:auto" onclick="abrirCriar()">
      <i class="bi bi-plus-lg"></i> Criar enquete
    </button>
  </div>
  <p class="lead">
    Quem cria banca: define as alternativas e as odds, e responde com o próprio saldo.
    Acertou quem apostou, o criador paga; errou, o dinheiro é dele.
    Enquanto a enquete está aberta, o pior resultado possível fica <b>retido</b> no saldo de quem criou —
    é isso que garante que ninguém fique devendo.
  </p>

  <div class="saldos" id="saldos"></div>
  <div id="lista"><p class="vazio">Carregando…</p></div>
</div>

<!-- Criar -->
<div class="modal" id="mCriar">
  <div class="mbox">
    <h3>Nova enquete</h3>
    <p class="aj">Você vira a casa desta aposta. Escolha odds que consiga pagar:
      quanto maior a odd, mais fica retido do seu saldo.</p>

    <label for="cTitulo">Pergunta</label>
    <input id="cTitulo" maxlength="160" placeholder="Ex: Quem ganha a final da ELITE?">

    <label for="cDesc">Detalhe (opcional)</label>
    <input id="cDesc" maxlength="400" placeholder="Como o resultado vai ser decidido">

    <label>Alternativas e odds</label>
    <div id="cAlts"></div>
    <button class="btn" style="padding:7px 11px;font-size:12px" onclick="addAlt()">
      <i class="bi bi-plus"></i> mais uma
    </button>

    <div class="duo" style="margin-top:6px">
      <div><label for="cPessoa">Máximo por pessoa</label><input id="cPessoa" type="number" value="200" min="10"></div>
      <div><label for="cTotal">Máximo total</label><input id="cTotal" type="number" value="1000" min="10"></div>
    </div>
    <div class="duo">
      <div><label for="cDias">Aberta por (dias)</label><input id="cDias" type="number" value="7" min="1"></div>
    </div>

    <div class="aviso" id="cAviso"></div>
    <div class="mfoot">
      <button class="btn" onclick="fechar('mCriar')">Cancelar</button>
      <button class="btn pri" id="cEnviar" onclick="criar()">Criar</button>
    </div>
  </div>
</div>

<!-- Apostar -->
<div class="modal" id="mAposta">
  <div class="mbox">
    <h3 id="aTitulo">Apostar</h3>
    <p class="aj" id="aSub"></p>
    <label for="aValor">Quanto você aposta</label>
    <input id="aValor" type="number" min="10" value="50" oninput="previa()">
    <div class="aviso" id="aPrevia"></div>
    <div class="mfoot">
      <button class="btn" onclick="fechar('mAposta')">Cancelar</button>
      <button class="btn pri" id="aEnviar" onclick="apostar()">Confirmar aposta</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const n = v => Number(v || 0).toLocaleString('pt-BR');
let DADOS = null, alvo = null;

async function api(acao, corpo, metodo) {
  const r = await fetch('/api/enquetes.php?acao=' + acao, {
    method: metodo || (corpo ? 'POST' : 'GET'),
    headers: {'Content-Type': 'application/json'},
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  return await r.json();
}

function fechar(id){ document.getElementById(id).classList.remove('on'); }

async function carregar() {
  const d = await api('listar');
  if (!d.ok) { document.getElementById('lista').innerHTML = '<p class="vazio">Não deu pra carregar.</p>'; return; }
  DADOS = d;

  document.getElementById('saldos').innerHTML = `
    <div class="saldo"><b>${n(d.saldo)}</b><span>suas moedas</span></div>
    <div class="saldo"><b>${n(d.livre)}</b><span>livres pra bancar</span></div>
    ${d.retido ? `<div class="saldo"><b style="color:var(--amber)">${n(d.retido)}</b><span>retido em enquetes</span></div>` : ''}`;

  const lista = d.enquetes || [];
  document.getElementById('lista').innerHTML = lista.length
    ? lista.map(card).join('')
    : '<p class="vazio">Nenhuma enquete ainda. Crie a primeira.</p>';
}

function card(e) {
  const aberta = e.status === 'aberta';
  const total = e.apostado || 1;
  return `
  <div class="card ${e.sou_dono ? 'minha' : ''} ${e.status === 'paga' ? 'paga' : ''}">
    <div class="ch">
      <h2>${esc(e.titulo)}</h2>
      <span class="selo ${e.status}">${e.status === 'aberta' ? 'aberta' : e.status === 'paga' ? 'paga' : e.status}</span>
    </div>
    <div class="sub">
      banca: <b>${esc(e.criador)}${e.sou_dono ? ' (você)' : ''}</b> ·
      <b>${n(e.apostado)}</b> de ${n(e.max_total)} apostados ·
      até <b>${n(e.max_pessoa)}</b> por pessoa
      ${e.sou_dono && e.retido ? ` · <b style="color:var(--amber)">${n(e.retido)} retido do seu saldo</b>` : ''}
      ${e.meu_total ? ` · você apostou <b>${n(e.meu_total)}</b>` : ''}
      ${e.descricao ? `<br>${esc(e.descricao)}` : ''}
    </div>
    <div class="alts">
      ${e.alternativas.map(a => `
        <button class="alt ${e.vencedora === a.id ? 'ganhou' : ''} ${a.meu ? 'tem' : ''}"
                ${aberta && !e.sou_dono ? `onclick="abrirAposta(${e.id},${a.id})"` : 'disabled'}>
          <span class="barra" style="width:${Math.round((a.apostado / total) * 100)}%"></span>
          <span class="alt-txt">${esc(a.texto)}
            <span class="alt-min">${n(a.apostado)} apostado${a.meu ? ` · você: ${n(a.meu)}` : ''}${e.vencedora === a.id ? ' · venceu' : ''}</span>
          </span>
          <span class="odd">${Number(a.odd).toFixed(2)}<small>odd</small></span>
        </button>`).join('')}
    </div>
    ${(e.sou_dono || DADOS.admin) && e.status === 'aberta' ? `
      <div class="linha-dono">
        <select id="res_${e.id}" style="max-width:230px">
          <option value="">Declarar o resultado…</option>
          ${e.alternativas.map(a => `<option value="${a.id}">${esc(a.texto)}</option>`).join('')}
        </select>
        <button class="btn" onclick="fecharEnquete(${e.id})">Pagar</button>
        <button class="btn mal" onclick="cancelar(${e.id})">Cancelar e devolver</button>
      </div>` : ''}
    ${DADOS.admin && e.status === 'paga' ? `
      <div class="linha-dono">
        <button class="btn mal" onclick="cancelar(${e.id})">Reverter pagamento (admin)</button>
      </div>` : ''}
  </div>`;
}

/* ── Criar ─────────────────────────────────────────────────────────── */
function addAlt(texto, odd) {
  const box = document.getElementById('cAlts');
  if (box.children.length >= (DADOS?.limites?.alt_max || 6)) return;
  const d = document.createElement('div');
  d.className = 'alt-linha';
  d.innerHTML = `<input class="cAltTxt" maxlength="120" placeholder="Alternativa" value="${esc(texto || '')}">
                 <input class="cAltOdd" type="number" step="0.05" min="1.1" max="10"
                        value="${odd || '2.00'}" oninput="previaCriar()">`;
  box.appendChild(d);
  previaCriar();
}

function abrirCriar() {
  document.getElementById('cAlts').innerHTML = '';
  addAlt('', '2.00'); addAlt('', '2.00');
  document.getElementById('cTitulo').value = '';
  document.getElementById('cDesc').value = '';
  previaCriar();
  document.getElementById('mCriar').classList.add('on');
}

/**
 * A conta que o criador precisa ver ANTES de criar: quanto pode ficar preso.
 * É o mesmo pior caso que o servidor calcula — mostrar aqui evita criar uma
 * enquete que vai ser recusada.
 */
function previaCriar() {
  const odds = [...document.querySelectorAll('.cAltOdd')].map(i => Number(i.value) || 0);
  const maiorOdd = Math.max(0, ...odds);
  const total = Number(document.getElementById('cTotal').value) || 0;
  const pior = Math.ceil(total * (maiorOdd - 1));
  const livre = DADOS?.livre ?? 0;
  const cabe = pior <= livre;
  document.getElementById('cAviso').innerHTML =
    `No pior caso — todo o dinheiro na alternativa de odd ${maiorOdd.toFixed(2)} —
     você paga <b>${n(pior)}</b> moedas. Você tem <b>${n(livre)}</b> livres.
     ${cabe ? '' : '<br><b style="color:var(--vermelho)">Não cabe: baixe a odd, o total, ou espere liberar retenção.</b>'}
     <br><span style="color:var(--text3)">A casa fica com ${DADOS?.limites?.taxa ?? 5}% do seu lucro — e nada se você perder.</span>`;
  document.getElementById('cEnviar').disabled = !cabe;
}
['cTotal','cPessoa'].forEach(id => document.addEventListener('input', e => {
  if (e.target && e.target.id === id) previaCriar();
}));

async function criar() {
  const alts = [...document.querySelectorAll('.alt-linha')].map(l => ({
    texto: l.querySelector('.cAltTxt').value.trim(),
    odd: Number(l.querySelector('.cAltOdd').value),
  })).filter(a => a.texto);
  const r = await api('criar', {
    titulo: document.getElementById('cTitulo').value.trim(),
    descricao: document.getElementById('cDesc').value.trim(),
    alternativas: alts,
    max_por_pessoa: Number(document.getElementById('cPessoa').value),
    max_total: Number(document.getElementById('cTotal').value),
    dias: Number(document.getElementById('cDias').value),
  });
  if (!r.ok) { alert(r.erro); return; }
  fechar('mCriar');
  carregar();
}

/* ── Apostar ───────────────────────────────────────────────────────── */
function abrirAposta(enqId, altId) {
  const e = DADOS.enquetes.find(x => x.id === enqId);
  const a = e.alternativas.find(x => x.id === altId);
  alvo = {e, a};
  document.getElementById('aTitulo').textContent = a.texto;
  document.getElementById('aSub').innerHTML =
    `${esc(e.titulo)}<br>Odd agora: <b>${Number(a.odd).toFixed(2)}</b> ·
     você já apostou ${n(e.meu_total)} de ${n(e.max_pessoa)} nesta enquete`;
  document.getElementById('aValor').value = Math.min(50, e.max_pessoa - e.meu_total) || 10;
  previa();
  document.getElementById('mAposta').classList.add('on');
}

function previa() {
  if (!alvo) return;
  const v = Number(document.getElementById('aValor').value) || 0;
  const retorno = Math.round(v * alvo.a.odd);
  const podeMax = alvo.e.max_pessoa - alvo.e.meu_total;
  const ok = v >= (DADOS?.limites?.aposta_min ?? 10) && v <= podeMax && v <= DADOS.saldo;
  document.getElementById('aPrevia').innerHTML =
    `Acertando, você recebe <b>${n(retorno)}</b> moedas — lucro de <b>${n(retorno - v)}</b>.
     <br><span style="color:var(--text3)">A odd trava agora: o que você vê é o que recebe, mesmo que ela mude depois.</span>
     ${v > podeMax ? `<br><b style="color:var(--vermelho)">O limite por pessoa aqui é ${n(alvo.e.max_pessoa)}.</b>` : ''}
     ${v > DADOS.saldo ? `<br><b style="color:var(--vermelho)">Você tem ${n(DADOS.saldo)} moedas.</b>` : ''}`;
  document.getElementById('aEnviar').disabled = !ok;
}

async function apostar() {
  const r = await api('apostar', {
    enquete_id: alvo.e.id, alternativa_id: alvo.a.id,
    valor: Number(document.getElementById('aValor').value),
  });
  if (!r.ok) { alert(r.erro); return; }
  fechar('mAposta');
  carregar();
}

/* ── Fechar / cancelar ─────────────────────────────────────────────── */
async function fecharEnquete(id) {
  const sel = document.getElementById('res_' + id);
  const alt = Number(sel.value);
  if (!alt) { alert('Escolha qual alternativa venceu.'); return; }
  if (!confirm(`Declarar "${sel.options[sel.selectedIndex].text}" como resultado?\n\nOs pagamentos saem na hora.`)) return;
  const r = await api('fechar', {enquete_id: id, alternativa_id: alt});
  if (!r.ok) { alert(r.erro); return; }
  alert(`Pago. Ganhadores receberam ${n(r.pago_aos_ganhadores)}.\n`
      + `Seu resultado: ${r.lucro_do_criador >= 0 ? '+' : ''}${n(r.lucro_do_criador)} moedas`
      + (r.taxa ? ` (taxa da casa: ${n(r.taxa)})` : ''));
  carregar();
}

async function cancelar(id) {
  if (!confirm('Cancelar e devolver todas as apostas?')) return;
  const r = await api('cancelar', {enquete_id: id});
  if (!r.ok) { alert(r.erro); return; }
  carregar();
}

carregar();
</script>
</body>
</html>
