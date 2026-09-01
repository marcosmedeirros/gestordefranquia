<?php
/**
 * O PAINEL DAS ENQUETES, pra viver dentro da aba do /games.
 *
 * Nasceu como página inteira e virou aba porque é ali que a pessoa já está,
 * com o saldo na tela e as outras abas ao lado.
 *
 * Todas as classes levam o prefixo `eq-`: .card, .saldo, .selo e .sub já
 * existem no games.php com outro desenho. O prefixo entra no CSS e dentro
 * de class="" — nunca no código, senão `d.saldo` viraria `d.eq-saldo`.
 */
?>
<style>
/*
 * AS VARIÁVEIS FICAM NO PAINEL, não no :root.
 *
 * Quando isto era página inteira, `:root` era dele. Como aba, `:root` é do
 * /games — e --bg, --panel e --texto existem lá com outros valores. Declarar
 * de novo ali repintava a página inteira, incluindo as outras quatro abas.
 * Presas ao #pane-banca, valem só aqui dentro (os modais também estão dentro
 * dele, então herdam).
 */
#pane-banca{--bg:#0a0a0c;--panel:#141418;--panel2:#1b1b21;--panel3:#232329;
  --borda:rgba(255,255,255,.07);--texto:#f4f4f5;--text2:#a1a1aa;--text3:#71717a;
  --verde:#22c55e;--vermelho:#ef4444;--amber:#f59e0b;--azul:#3b82f6;
  --font:'Inter',system-ui,sans-serif;--num:'Inter',sans-serif;
  color:var(--texto);font-family:var(--font)}
#pane-banca *{box-sizing:border-box}
/* Sem regra de `body`: a de antes trocava o fundo e o padding do /games
   inteiro só por esta aba existir na página. */
.eq-wrap{max-width:none;margin:0}
.eq-topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:6px}
#pane-banca h1{font-size:23px;font-weight:900;letter-spacing:-.5px}
.eq-lead{color:var(--text2);font-size:13.5px;line-height:1.6;margin-bottom:16px;max-width:70ch}

.eq-saldos{display:flex;gap:9px;flex-wrap:wrap;margin-bottom:18px}
.eq-saldo{background:var(--panel);border:1px solid var(--borda);border-radius:12px;
  padding:11px 15px;min-width:126px}
.eq-saldo b{display:block;font-family:var(--num);font-size:20px;font-weight:900;letter-spacing:-.5px}
.eq-saldo span{font-size:9.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--text3)}

.eq-btn{background:var(--panel3);border:1px solid var(--borda);color:var(--texto);
  border-radius:10px;padding:10px 15px;font-family:var(--font);font-size:13px;
  font-weight:800;cursor:pointer;transition:border-color .15s}
.eq-btn:hover{border-color:rgba(255,255,255,.22)}
.eq-btn.eq-pri{background:var(--verde);border-color:var(--verde);color:#08160c}
.eq-btn.eq-mal{color:var(--vermelho);border-color:rgba(239,68,68,.4)}
.eq-btn:disabled{opacity:.45;cursor:not-allowed}

/* Cada aposta é um card na grade; no celular a grade vira uma coluna só.
   `align-items:start` impede que um card alto estique os vizinhos. */
.eq-grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(330px,1fr));
  gap:13px;align-items:start}
@media(max-width:700px){ .eq-grade{grid-template-columns:1fr} }
.eq-card{background:var(--panel);border:1px solid var(--borda);border-radius:15px;
  padding:16px 17px}
.eq-card.eq-minha{border-color:rgba(59,130,246,.35)}
.eq-card.eq-paga{opacity:.72}
.eq-ch{display:flex;align-items:flex-start;gap:10px;flex-wrap:wrap;margin-bottom:4px}
#pane-banca .eq-ch h2{font-size:15.5px;font-weight:800;letter-spacing:-.2px;flex:1;min-width:200px}
.eq-selo{font-size:9.5px;font-weight:900;letter-spacing:.6px;text-transform:uppercase;
  padding:4px 8px;border-radius:99px;white-space:nowrap}
.eq-selo.eq-aberta{background:rgba(34,197,94,.16);color:var(--verde)}
.eq-selo.eq-paga{background:rgba(113,113,122,.2);color:var(--text2)}
.eq-selo.eq-cancelada{background:rgba(239,68,68,.14);color:var(--vermelho)}
.eq-sub{font-size:12px;color:var(--text3);margin-bottom:12px}
.eq-sub b{color:var(--text2)}

.eq-alts{display:flex;flex-direction:column;gap:7px}
.eq-alt{display:flex;align-items:center;gap:11px;background:var(--panel2);
  border:1px solid var(--borda);border-radius:11px;padding:11px 13px;width:100%;
  color:var(--texto);font-family:var(--font);text-align:left;cursor:pointer;
  position:relative;overflow:hidden;transition:border-color .15s}
.eq-alt:hover{border-color:rgba(255,255,255,.2)}
.eq-alt.eq-ganhou{border-color:rgba(34,197,94,.5);background:rgba(34,197,94,.09)}
.eq-alt.eq-tem{border-color:rgba(59,130,246,.4)}
.eq-alt[disabled]{cursor:default}
.eq-barra{position:absolute;left:0;top:0;bottom:0;background:rgba(255,255,255,.045);
  transition:width .4s ease}
.eq-alt-txt{flex:1;min-width:0;font-size:13.5px;font-weight:700;position:relative}
.eq-alt-min{display:block;font-size:10.5px;font-weight:600;color:var(--text3);margin-top:2px}
.eq-odd{font-family:var(--num);font-size:17px;font-weight:900;color:var(--amber);
  position:relative;white-space:nowrap}
.eq-odd small{display:block;font-size:8.5px;font-weight:800;color:var(--text3);
  letter-spacing:.5px;text-transform:uppercase;text-align:right}

/* O formulário de aposta, embaixo da alternativa escolhida. */
.eq-box{background:var(--panel3);border:1px solid rgba(34,197,94,.35);
  border-radius:11px;padding:11px 12px;margin:-2px 0 3px}
.eq-box-linha{display:flex;gap:7px;flex-wrap:wrap;align-items:center}
#pane-banca .eq-box-linha input{width:110px;font-size:17px;font-weight:900;
  font-family:var(--num);text-align:center;padding:8px 10px}
.eq-box-previa{font-size:12px;color:var(--text2);margin-top:8px;line-height:1.5}
.eq-nota-dono{font-size:12px;color:var(--text3);margin-top:10px;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:9px 11px}

.eq-linha-dono{display:flex;gap:8px;flex-wrap:wrap;margin-top:12px;
  padding-top:12px;border-top:1px solid var(--borda)}
.eq-vazio{color:var(--text3);font-size:13px;padding:26px 0;text-align:center}
/* O encerrado fica no fim, atrás de uma busca: o que importa na chegada é
   o que ainda dá pra apostar. */
.eq-fim{margin-top:30px;padding-top:22px;border-top:1px solid var(--borda)}
.eq-fim-topo{display:flex;align-items:center;gap:12px;flex-wrap:wrap;margin-bottom:14px}
#pane-banca .eq-fim-topo h2{font-size:14px;font-weight:800;letter-spacing:-.2px}
#pane-banca .eq-busca{max-width:260px;margin-left:auto;font-size:13px}
.eq-cont{font-size:11.5px;color:var(--text3);font-weight:700}

.eq-modal{position:fixed;inset:0;background:rgba(0,0,0,.72);display:none;
  align-items:center;justify-content:center;padding:16px;z-index:50}
.eq-modal.eq-on{display:flex}
.eq-mbox{background:var(--panel);border:1px solid var(--borda);border-radius:16px;
  padding:20px;width:100%;max-width:520px;max-height:88vh;overflow:auto}
#pane-banca .eq-mbox h3{font-size:17px;font-weight:900;margin-bottom:4px}
.eq-mbox p.eq-aj{font-size:12px;color:var(--text3);margin-bottom:14px;line-height:1.55}
#pane-banca label{display:block;font-size:10px;font-weight:800;letter-spacing:.6px;
  text-transform:uppercase;color:var(--text3);margin:11px 0 5px}
#pane-banca input,#pane-banca textarea,#pane-banca select{width:100%;background:var(--panel2);border:1px solid var(--borda);
  border-radius:9px;padding:10px 12px;color:var(--texto);font-family:var(--font);
  font-size:14px;font-weight:600;outline:none}
#pane-banca input:focus,#pane-banca textarea:focus{border-color:rgba(255,255,255,.24)}
.eq-alt-linha{display:grid;grid-template-columns:1fr 92px;gap:7px;margin-bottom:7px}
.eq-duo{display:grid;grid-template-columns:1fr 1fr;gap:9px}
.eq-aviso{background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);
  border-radius:10px;padding:10px 12px;font-size:12px;color:var(--text2);
  line-height:1.55;margin-top:12px}
.eq-mfoot{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
@media(max-width:560px){ .eq-duo{grid-template-columns:1fr} #pane-banca h1{font-size:20px} }
</style>
<?php /* Sem <head>/<body> aqui: isto é um pedaço da página, não uma página.
         Eles sobraram da conversão e ficavam soltos no meio do /games. */ ?>
<div class="eq-wrap">
  <div class="eq-topo">
    <?php /* Nada de "voltar aos games": já estamos dentro deles, e a aba de
             onde se veio está logo acima. */ ?>
    <h1>Bicho</h1>
    <?php /* Criar é só do admin; o botão nasce escondido e a listagem o
             revela pra quem pode (DADOS.admin). A API barra de qualquer
             jeito — o botão é só pra não oferecer o que vai ser negado. */ ?>
    <button class="eq-btn eq-pri" id="btnCriar" hidden style="margin-left:auto" onclick="abrirCriar()">
      <i class="bi bi-plus-lg"></i> Criar aposta
    </button>
  </div>
  <p class="eq-lead">
    Quem cria banca: define as alternativas e as odds, e responde com o próprio saldo.
    Acertou quem apostou, o criador paga; errou, o dinheiro é dele.
    Enquanto a aposta está aberta, o pior resultado possível fica <b>retido</b> no saldo de quem criou —
    é isso que garante que ninguém fique devendo.
  </p>

  <div class="eq-saldos" id="saldos"></div>
  <div id="lista" class="eq-grade"><p class="eq-vazio">Carregando…</p></div>

  <?php /* As encerradas (pagas ou canceladas) não competem com as abertas por
           atenção: descem pro fim e ganham uma busca, porque com o tempo elas
           passam a ser muitas e o que se quer ali é achar uma específica. */ ?>
  <div class="eq-fim" id="blocoFim" hidden>
    <div class="eq-fim-topo">
      <h2>Apostas encerradas</h2>
      <span class="eq-cont" id="contFim"></span>
      <input class="eq-busca" id="buscaFim" type="search" placeholder="Buscar por pergunta ou banca…"
             oninput="pintarEncerradas()">
    </div>
    <div id="listaFim" class="eq-grade"></div>
  </div>
</div>

<!-- Criar -->
<div class="eq-modal" id="mCriar">
  <div class="eq-mbox">
    <h3>Nova aposta</h3>
    <p class="eq-aj">Você vira a casa desta aposta. Escolha odds que consiga pagar:
      quanto maior a odd, mais fica retido do seu saldo.</p>

    <label for="cTitulo">Pergunta</label>
    <input id="cTitulo" maxlength="160" placeholder="Ex: Quem ganha a final da ELITE?">

    <label for="cDesc">Detalhe (opcional)</label>
    <input id="cDesc" maxlength="400" placeholder="Como o resultado vai ser decidido">

    <label>Alternativas e odds</label>
    <div id="cAlts"></div>
    <button class="eq-btn" style="padding:7px 11px;font-size:12px" onclick="addAlt()">
      <i class="bi bi-plus"></i> mais uma
    </button>

    <div class="eq-duo" style="margin-top:6px">
      <div><label for="cPessoa">Máximo por pessoa</label><input id="cPessoa" type="number" value="200" min="10"></div>
      <div><label for="cTotal">Máximo total</label><input id="cTotal" type="number" value="1000" min="10"></div>
    </div>
    <div class="eq-duo">
      <div><label for="cDias">Aberta por (dias)</label><input id="cDias" type="number" value="7" min="1"></div>
    </div>

    <div class="eq-aviso" id="cAviso"></div>
    <div class="eq-mfoot">
      <button class="eq-btn" onclick="fechar('mCriar')">Cancelar</button>
      <button class="eq-btn eq-pri" id="cEnviar" onclick="criar()">Criar</button>
    </div>
  </div>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const n = v => Number(v || 0).toLocaleString('pt-BR');
let DADOS = null, alvo = null, ENCERRADAS = [];

async function api(acao, corpo, metodo) {
  const r = await fetch('/api/enquetes.php?acao=' + acao, {
    method: metodo || (corpo ? 'POST' : 'GET'),
    headers: {'Content-Type': 'application/json'},
    body: corpo ? JSON.stringify(corpo) : undefined,
  });
  return await r.json();
}

function fechar(id){ document.getElementById(id).classList.remove('eq-on'); }

async function carregar() {
  const d = await api('listar');
  if (!d.ok) { document.getElementById('lista').innerHTML = '<p class="eq-vazio">Não deu pra carregar.</p>'; return; }
  DADOS = d;

  const btn = document.getElementById('btnCriar');
  if (btn) btn.hidden = !d.admin;

  document.getElementById('saldos').innerHTML = `
    <div class="eq-saldo"><b>${n(d.saldo)}</b><span>suas moedas</span></div>
    <div class="eq-saldo"><b>${n(d.livre)}</b><span>livres pra bancar</span></div>
    ${d.retido ? `<div class="eq-saldo"><b style="color:var(--amber)">${n(d.retido)}</b><span>retido em apostas</span></div>` : ''}`;

  // Em cima o que ainda aceita aposta; embaixo, o histórico.
  const lista = d.enquetes || [];
  const vivas = lista.filter(e => e.status === 'aberta');
  ENCERRADAS = lista.filter(e => e.status !== 'aberta');

  document.getElementById('lista').innerHTML = vivas.length
    ? vivas.map(card).join('')
    : `<p class="eq-vazio">${lista.length ? 'Nenhuma aposta aberta agora.' : 'Nenhuma aposta ainda.'}</p>`;
  pintarEncerradas();
}

/** O histórico do fim da página, filtrado pelo que a pessoa digitou. */
function pintarEncerradas() {
  const bloco = document.getElementById('blocoFim');
  if (!bloco) return;
  bloco.hidden = !ENCERRADAS.length;
  if (!ENCERRADAS.length) return;

  const q = (document.getElementById('buscaFim')?.value || '').trim().toLowerCase();
  const achadas = q
    ? ENCERRADAS.filter(e => `${e.titulo} ${e.descricao || ''} ${e.criador}`.toLowerCase().includes(q))
    : ENCERRADAS;

  document.getElementById('contFim').textContent =
    q ? `${achadas.length} de ${ENCERRADAS.length}` : `${ENCERRADAS.length} no total`;
  document.getElementById('listaFim').innerHTML = achadas.length
    ? achadas.map(card).join('')
    : '<p class="eq-vazio">Nenhuma aposta encerrada com esse termo.</p>';
}

function card(e) {
  const aberta = e.status === 'aberta';
  const total = e.apostado || 1;
  return `
  <div class="eq-card ${e.sou_dono ? 'eq-minha' : ''} ${e.status === 'paga' ? 'eq-paga' : ''}">
    <div class="eq-ch">
      <h2>${esc(e.titulo)}</h2>
      <span class="eq-selo eq-${e.status}">${e.status === 'aberta' ? 'aberta' : e.status === 'paga' ? 'paga' : e.status}</span>
    </div>
    <div class="eq-sub">
      banca: <b>${esc(e.criador)}${e.sou_dono ? ' (você)' : ''}</b> ·
      <b>${n(e.apostado)}</b> de ${n(e.max_total)} apostados ·
      até <b>${n(e.max_pessoa)}</b> por pessoa
      ${e.sou_dono && e.retido ? ` · <b style="color:var(--amber)">${n(e.retido)} retido do seu saldo</b>` : ''}
      ${e.meu_total ? ` · você apostou <b>${n(e.meu_total)}</b>` : ''}
      ${e.descricao ? `<br>${esc(e.descricao)}` : ''}
    </div>
    <div class="eq-alts">
      ${e.alternativas.map(a => `
        <button class="eq-alt ${e.vencedora === a.id ? 'eq-ganhou' : ''} ${a.meu ? 'eq-tem' : ''}"
                ${aberta && !e.sou_dono ? `onclick="abrirAposta(${e.id},${a.id})"` : 'disabled'}>
          <span class="eq-barra" style="width:${Math.round((a.apostado / total) * 100)}%"></span>
          <span class="eq-alt-txt">${esc(a.texto)}
            <span class="eq-alt-min">${n(a.apostado)} apostado${a.meu ? ` · você: ${n(a.meu)}` : ''}${e.vencedora === a.id ? ' · venceu' : ''}</span>
          </span>
          <span class="eq-odd">${Number(a.odd).toFixed(2)}<small>odd</small></span>
        </button>
        <?php /* O formulário nasce FECHADO embaixo de cada alternativa e abre
                 no clique: apostar é escolher a opção e dizer quanto, e um
                 modal por cima tirava da vista justamente a odd que a pessoa
                 está avaliando. */ ?>
        <div class="eq-box" id="box_${e.id}_${a.id}" hidden>
          <div class="eq-box-linha">
            <input type="number" id="val_${e.id}_${a.id}" min="${DADOS.limites.aposta_min}"
                   max="${e.max_pessoa - e.meu_total}" value="${Math.min(50, Math.max(DADOS.limites.aposta_min, e.max_pessoa - e.meu_total))}"
                   oninput="previa(${e.id},${a.id})">
            <button class="eq-btn eq-pri" onclick="apostar(${e.id},${a.id})">Apostar</button>
            <button class="eq-btn" onclick="fecharBox(${e.id},${a.id})">Cancelar</button>
          </div>
          <div class="eq-box-previa" id="prev_${e.id}_${a.id}"></div>
        </div>`).join('')}
    </div>
    ${aberta && e.sou_dono ? `
      <div class="eq-nota-dono">
        Você banca esta aposta — quem aposta são os outros. O resultado você declara aqui embaixo.
      </div>` : ''}
    ${(e.sou_dono || DADOS.admin) && e.status === 'aberta' ? `
      <div class="eq-linha-dono">
        <select id="res_${e.id}" style="max-width:230px">
          <option value="">Declarar o resultado…</option>
          ${e.alternativas.map(a => `<option value="${a.id}">${esc(a.texto)}</option>`).join('')}
        </select>
        <button class="eq-btn" onclick="fecharEnquete(${e.id})">Pagar</button>
        <button class="eq-btn eq-mal" onclick="cancelar(${e.id})">Cancelar e devolver</button>
      </div>` : ''}
    ${DADOS.admin && e.status === 'paga' ? `
      <div class="eq-linha-dono">
        <button class="eq-btn eq-mal" onclick="cancelar(${e.id})">Reverter pagamento (admin)</button>
      </div>` : ''}
  </div>`;
}

/* ── Criar ─────────────────────────────────────────────────────────── */
function addAlt(texto, odd) {
  const box = document.getElementById('cAlts');
  if (box.children.length >= (DADOS?.limites?.alt_max || 4)) return;
  const d = document.createElement('div');
  d.className = 'eq-alt-linha';
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
  document.getElementById('mCriar').classList.add('eq-on');
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
  const alts = [...document.querySelectorAll('.eq-alt-linha')].map(l => ({
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
/**
 * Abre o formulário embaixo da alternativa escolhida.
 *
 * Um por vez: com dois abertos, dá pra digitar num e confirmar no outro.
 */
function abrirAposta(enqId, altId) {
  document.querySelectorAll('.eq-box').forEach(b => b.hidden = true);
  const box = document.getElementById(`box_${enqId}_${altId}`);
  if (!box) return;
  box.hidden = false;
  previa(enqId, altId);
  box.querySelector('input')?.focus();
}

function fecharBox(enqId, altId) {
  const box = document.getElementById(`box_${enqId}_${altId}`);
  if (box) box.hidden = true;
}

/** O que a pessoa recebe se acertar — e o motivo, quando não dá pra apostar. */
function previa(enqId, altId) {
  const e = DADOS.enquetes.find(x => x.id === enqId);
  const a = e?.alternativas.find(x => x.id === altId);
  const campo = document.getElementById(`val_${enqId}_${altId}`);
  const alvo  = document.getElementById(`prev_${enqId}_${altId}`);
  if (!e || !a || !campo || !alvo) return;

  const v = Number(campo.value) || 0;
  const retorno = Math.round(v * a.odd);
  const podeMax = e.max_pessoa - e.meu_total;
  const min = DADOS.limites.aposta_min;
  // O motivo em vez de um botão apagado sem explicação.
  const problema = v < min ? `A aposta mínima é ${n(min)}.`
    : v > podeMax ? `O limite por pessoa aqui é ${n(e.max_pessoa)} — você já apostou ${n(e.meu_total)}.`
    : v > DADOS.saldo ? `Você tem ${n(DADOS.saldo)} moedas.`
    : null;

  alvo.innerHTML = problema
    ? `<b style="color:var(--vermelho)">${problema}</b>`
    : `Na odd <b>${Number(a.odd).toFixed(2)}</b>, acertando você recebe <b>${n(retorno)}</b> —
       lucro de <b>${n(retorno - v)}</b>.
       <br><span style="color:var(--text3)">A odd trava agora: é a que você recebe, mesmo que ela mude depois.</span>`;
  const bt = alvo.parentElement.querySelector('.eq-pri');
  if (bt) bt.disabled = !!problema;
}

async function apostar(enqId, altId) {
  const campo = document.getElementById(`val_${enqId}_${altId}`);
  const r = await api('apostar', {
    enquete_id: enqId, alternativa_id: altId, valor: Number(campo?.value || 0),
  });
  if (!r.ok) { alert(r.erro); return; }
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
