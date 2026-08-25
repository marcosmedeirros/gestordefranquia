<?php
/**
 * STOP — a tela.
 *
 * Só por link: o jogo não aparece no grid de games. Quem chega sem ?sala=CODIGO
 * cai na tela de criar/entrar, e o que se compartilha é a URL com o código.
 *
 * O arquivo faz duas coisas: responde os POSTs de ação (que são só um repasse
 * pro motor, em games/core/stop_motor.php) e desenha a sala. A regra do jogo
 * inteira mora no motor — aqui não se decide nada, nem ponto, nem tempo.
 *
 * A tela se redesenha a partir do estado que o servidor manda a cada 1,5s. Só o
 * que a pessoa está digitando agora escapa dessa regra: reescrever o input
 * embaixo do dedo dela seria roubar a digitação.
 */

require '../core/conexao.php';
require_once '../core/stop_motor.php';

if (!isset($_SESSION['user_id'])) { header("Location: /login.php"); exit; }
$user_id = (int)$_SESSION['user_id'];

stopSchema($pdo);

$st = $pdo->prepare("SELECT nome, COALESCE(pontos,0) pontos FROM games_usuarios WHERE id = ?");
$st->execute([$user_id]);
$eu = $st->fetch(PDO::FETCH_ASSOC) ?: ['nome' => 'Jogador', 'pontos' => 0];

$tokenUrl = strtoupper(trim((string)($_GET['sala'] ?? '')));

// ── Ações ────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['acao'])) {
    header('Content-Type: application/json; charset=utf-8');
    $acao  = (string)$_POST['acao'];
    $token = strtoupper(trim((string)($_POST['sala'] ?? $tokenUrl)));

    try {
        if ($acao === 'criar') {
            echo json_encode(stopCriar($pdo, $user_id, (string)$eu['nome'], (int)($_POST['aposta'] ?? 0)));
            exit;
        }
        if ($acao === 'entrar') {
            echo json_encode(stopEntrar($pdo, $token, $user_id, (string)$eu['nome']));
            exit;
        }
        if ($acao === 'sair')     { echo json_encode(stopSair($pdo, $token, $user_id)); exit; }
        if ($acao === 'comecar')  { echo json_encode(stopComecar($pdo, $token, $user_id)); exit; }
        if ($acao === 'salvar')   {
            $r = json_decode((string)($_POST['respostas'] ?? '[]'), true) ?: [];
            echo json_encode(stopSalvar($pdo, $token, $user_id, $r));
            exit;
        }
        if ($acao === 'parar') {
            $r = json_decode((string)($_POST['respostas'] ?? '[]'), true) ?: [];
            echo json_encode(stopParar($pdo, $token, $user_id, $r));
            exit;
        }
        if ($acao === 'denunciar') {
            echo json_encode(stopDenunciar($pdo, $token, $user_id, (int)($_POST['tema'] ?? -1), (int)($_POST['alvo'] ?? 0)));
            exit;
        }
        if ($acao === 'pronto')   { echo json_encode(stopPronto($pdo, $token, $user_id)); exit; }
        if ($acao === 'estado') {
            $e = stopEstado($pdo, $token, $user_id);
            echo json_encode($e ? ['ok' => true, 'estado' => $e] : ['ok' => false, 'erro' => 'Sala não encontrada.']);
            exit;
        }
        echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
    } catch (Throwable $e) {
        error_log('[stop] ' . $e->getMessage());
        echo json_encode(['ok' => false, 'erro' => 'Deu erro aqui. Tente de novo.']);
    }
    exit;
}

$estadoInicial = $tokenUrl ? stopEstado($pdo, $tokenUrl, $user_id) : null;
?>
<!DOCTYPE html>
<html lang="pt-br">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">
<title>🛑 Stop – FBA Games</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns=%22http://www.w3.org/2000/svg%22 viewBox=%220 0 100 100%22><text y=%22.9em%22 font-size=%2290%22>🛑</text></svg>">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
:root{
  --bg:#0d0d0d;--surface:#181818;--surface-2:#1f1f1f;--border:#2a2a2a;
  --text:#f0f0f0;--text-2:#9ca3af;--text-3:#6b7280;
  --red:#FC082B;--amber:#f59e0b;--green:#22c55e;--blue:#3b82f6;
}
html,body{background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;
  -webkit-tap-highlight-color:transparent}
body{padding:0 0 40px}

#topbar{position:sticky;top:0;z-index:50;height:52px;background:rgba(13,13,13,.96);
  backdrop-filter:blur(10px);border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;padding:0 14px;gap:10px}
#topbar a{color:var(--text-2);text-decoration:none;font-size:13px;display:flex;align-items:center;gap:5px}
.saldo{background:var(--red);color:#fff;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:700;
  display:flex;align-items:center;gap:5px;white-space:nowrap}

.wrap{max-width:920px;margin:0 auto;padding:16px 14px}
.card{background:var(--surface);border:1px solid var(--border);border-radius:14px;padding:18px;margin-bottom:14px}
h1{font-size:21px;font-weight:800;margin-bottom:6px}
h2{font-size:14px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);margin-bottom:12px}
p.sub{font-size:13px;color:var(--text-2);line-height:1.6}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 18px;border-radius:11px;
  border:1.5px solid transparent;background:var(--red);color:#fff;font-family:inherit;font-size:14px;
  font-weight:700;cursor:pointer}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn.ghost{background:transparent;border-color:var(--border);color:var(--text-2)}
.btn.green{background:var(--green)}
.btn.sm{padding:7px 12px;font-size:12.5px}
.linha{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-top:14px}

input[type=text],input[type=number]{background:var(--surface-2);border:1.5px solid var(--border);color:var(--text);
  border-radius:11px;padding:11px 13px;font-family:inherit;font-size:14px;font-weight:600;outline:none;width:100%}
input:focus{border-color:var(--red)}
input::placeholder{color:var(--text-3);font-weight:500}

/* ── Sala ── */
.faixa{display:flex;gap:10px;flex-wrap:wrap;align-items:center;justify-content:space-between;margin-bottom:14px}
.letra{font-size:44px;font-weight:900;line-height:1;color:var(--red);font-family:'Segoe UI',system-ui}
.rodada-txt{font-size:12px;color:var(--text-2);font-weight:700;text-transform:uppercase;letter-spacing:1px}
.cronometro{font-size:19px;font-weight:800;font-variant-numeric:tabular-nums;color:var(--text-2)}
.cronometro.correndo{color:var(--amber)}

.temas{display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:10px}
.tema{background:var(--surface-2);border:1px solid var(--border);border-radius:11px;padding:10px 12px}
.tema-nome{font-size:11px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--text-3);
  margin-bottom:6px}
.tema input{padding:9px 11px}

.placar{display:flex;flex-direction:column;gap:7px}
.jog{display:flex;align-items:center;gap:9px;background:var(--surface-2);border:1px solid var(--border);
  border-radius:10px;padding:9px 12px;font-size:13.5px}
.jog .nome{flex:1;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.jog .pts{font-weight:800;font-variant-numeric:tabular-nums}
.jog.eu{border-color:var(--red)}
.jog .selo{font-size:10px;font-weight:800;text-transform:uppercase;letter-spacing:.6px;padding:2px 7px;
  border-radius:999px;background:rgba(34,197,94,.14);color:var(--green);white-space:nowrap}

/* ── Votação ── */
.voto-tema{border:1px solid var(--border);border-radius:12px;padding:12px;margin-bottom:10px;background:var(--surface-2)}
.voto-tema h3{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.7px;color:var(--amber);
  margin-bottom:9px}
.resp{display:flex;align-items:center;gap:9px;padding:7px 0;border-top:1px solid var(--border);font-size:13.5px}
.resp:first-of-type{border-top:0}
.resp .quem{color:var(--text-3);font-size:11.5px;min-width:88px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.resp .txt{flex:1;font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.resp .txt.vazio{color:var(--text-3);font-weight:400;font-style:italic}
.den{display:inline-flex;align-items:center;gap:5px;padding:4px 9px;border-radius:999px;cursor:pointer;
  border:1px solid var(--border);background:transparent;color:var(--text-3);font-family:inherit;
  font-size:11.5px;font-weight:700;white-space:nowrap}
.den.on{border-color:var(--red);color:var(--red);background:rgba(252,8,43,.1)}
.den:disabled{opacity:.35;cursor:default}
.den.caiu{border-color:var(--red);background:var(--red);color:#fff}

.aviso{display:flex;gap:9px;align-items:flex-start;padding:11px 13px;border-radius:11px;font-size:12.5px;
  line-height:1.5;color:var(--text-2);background:rgba(245,158,11,.08);border:1px solid rgba(245,158,11,.24);
  margin-bottom:12px}
.aviso i{color:var(--amber);flex:none;margin-top:1px}
.erro{background:rgba(252,8,43,.09);border-color:rgba(252,8,43,.3)}
.erro i{color:var(--red)}

.link-caixa{display:flex;gap:8px;align-items:center;background:var(--surface-2);border:1px dashed var(--border);
  border-radius:11px;padding:10px 12px;margin-top:12px}
.link-caixa code{flex:1;font-size:12.5px;color:var(--text-2);overflow:hidden;text-overflow:ellipsis;white-space:nowrap}

.podio{text-align:center;padding:8px 0 4px}
.podio .campeao{font-size:24px;font-weight:900;color:var(--green);margin:6px 0}
.podio .premio{font-size:13.5px;color:var(--text-2)}

@media(max-width:560px){
  .temas{grid-template-columns:1fr}
  .letra{font-size:36px}
  /* No estreito a linha vira duas: quem respondeu em cima, a resposta embaixo
     com a bandeira. Em uma linha só sobravam ~120px pro texto, e a resposta
     saía cortada — justo o que a pessoa precisa ler pra decidir se vale. */
  .resp{flex-wrap:wrap;row-gap:2px}
  .resp .quem{min-width:100%;order:-1}
  .resp .txt{white-space:normal;overflow:visible;word-break:break-word}
}
</style>
</head>
<body>

<div id="topbar">
  <a href="/games.php"><i class="bi bi-arrow-left"></i> Games</a>
  <strong style="font-size:14px">🛑 STOP</strong>
  <span class="saldo"><i class="bi bi-coin"></i> <span id="saldo"><?= (int)$eu['pontos'] ?></span></span>
</div>

<div class="wrap" id="tela"></div>

<script>
const SALA_URL = <?= json_encode($tokenUrl) ?>;
const MEU_ID   = <?= (int)$user_id ?>;
let estado     = <?= json_encode($estadoInicial) ?>;
let ocupado    = false;
let editando   = null;   // índice do campo em foco — ver comentário em pintar()

const $ = s => document.querySelector(s);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));

async function acao(nome, extra = {}) {
  const fd = new FormData();
  fd.append('acao', nome);
  fd.append('sala', (estado && estado.token) || SALA_URL || '');
  for (const k in extra) fd.append(k, extra[k]);
  const r = await fetch('index.php?game=stop', { method: 'POST', body: fd });
  return r.json();
}

/** O que está digitado agora nos campos da rodada. */
function respostasDaTela() {
  const out = {};
  document.querySelectorAll('.tema input').forEach(i => out[i.dataset.idx] = i.value);
  return out;
}

async function puxarEstado() {
  const d = await acao('estado');
  if (d.ok) { estado = d.estado; pintar(); }
}

// ── Ações ──
async function criarSala(btn) {
  const aposta = parseInt($('#aposta').value || '0', 10) || 0;
  btn.disabled = true;
  const d = await acao('criar', { aposta });
  if (!d.ok) { btn.disabled = false; return mostrarErro(d.erro); }
  location.href = 'index.php?game=stop&sala=' + d.token;
}
async function entrarSala(btn) {
  const cod = ($('#codigo') ? $('#codigo').value : SALA_URL).trim().toUpperCase();
  if (!cod) return mostrarErro('Digite o código da sala.');
  btn.disabled = true;
  const d = await acao('entrar', { sala: cod });
  if (!d.ok) { btn.disabled = false; return mostrarErro(d.erro); }
  location.href = 'index.php?game=stop&sala=' + cod;
}
async function sairSala() {
  if (!confirm('Sair da sala?' + (estado.aposta > 0 && estado.status === 'aguardando'
      ? '\n\nSua entrada de ' + estado.aposta + ' moedas volta pra você.' : ''))) return;
  await acao('sair');
  location.href = 'index.php?game=stop';
}
async function comecar(btn) {
  btn.disabled = true;
  const d = await acao('comecar');
  if (!d.ok) { btn.disabled = false; return mostrarErro(d.erro); }
  puxarEstado();
}
async function pararTudo(btn) {
  btn.disabled = true;
  await acao('parar', { respostas: JSON.stringify(respostasDaTela()) });
  puxarEstado();
}
async function denunciar(tema, alvo, el) {
  if (ocupado) return;
  ocupado = true;
  el.classList.toggle('on');           // resposta imediata; o estado real vem no próximo ciclo
  await acao('denunciar', { tema, alvo });
  ocupado = false;
  puxarEstado();
}
async function marcarPronto(btn) {
  btn.disabled = true;
  await acao('pronto');
  puxarEstado();
}
function mostrarErro(msg) {
  const el = $('#erro');
  if (el) { el.innerHTML = '<i class="bi bi-exclamation-triangle-fill"></i><span>' + esc(msg) + '</span>'; el.style.display = 'flex'; }
  else alert(msg);
}
function copiarLink() {
  const url = location.origin + '/games/games/index.php?game=stop&sala=' + estado.token;
  navigator.clipboard.writeText(url).then(() => {
    const b = $('#btCopiar');
    b.innerHTML = '<i class="bi bi-check-lg"></i> Copiado';
    setTimeout(() => b.innerHTML = '<i class="bi bi-link-45deg"></i> Copiar link', 1800);
  });
}

/**
 * Salva o que foi digitado, sem esperar o STOP.
 *
 * É o que garante que a rodada não se perca se a pessoa cair ou o tempo estourar
 * com ela no meio da digitação. Com atraso, pra não mandar uma requisição por
 * tecla.
 */
let salvarTimer = null;
function agendarSalvar() {
  clearTimeout(salvarTimer);
  salvarTimer = setTimeout(() => acao('salvar', { respostas: JSON.stringify(respostasDaTela()) }), 700);
}

// ── Desenho ──
function pintar() {
  if (!estado) return desenharEntrada();
  const s = estado;
  if (!s.sou_da_sala)              return desenharConvite();
  if (s.status === 'aguardando')   return desenharEspera();
  if (s.status === 'escrevendo')   return desenharRodada();
  if (s.status === 'votando')      return desenharVotacao();
  if (s.status === 'encerrada')    return desenharFim();
  if (s.status === 'cancelada')    return desenharEntrada('Essa sala foi cancelada.');
}

function desenharEntrada(msg) {
  $('#tela').innerHTML = `
    <div class="card">
      <h1>Stop</h1>
      <p class="sub">4 a 10 pessoas, 5 rodadas de 10 temas. Sai uma letra, todo mundo escreve,
        e quem terminar primeiro aperta STOP. Depois as respostas aparecem e a mesa decide o que vale.</p>
      ${msg ? `<div class="aviso erro" style="margin-top:12px"><i class="bi bi-x-circle-fill"></i><span>${esc(msg)}</span></div>` : ''}
      <div class="aviso" id="erro" style="display:none;margin-top:12px"></div>

      <h2 style="margin-top:20px">Criar uma sala</h2>
      <p class="sub">A entrada é a mesma pra todo mundo e o vencedor leva o bolo inteiro.
         Deixe 0 pra jogar sem apostar.</p>
      <div class="linha">
        <div style="flex:1;min-width:150px">
          <input type="number" id="aposta" min="0" max="5000" step="10" value="50" placeholder="Entrada em moedas">
        </div>
        <button class="btn" onclick="criarSala(this)"><i class="bi bi-plus-circle-fill"></i> Criar sala</button>
      </div>

      <h2 style="margin-top:24px">Entrar com um código</h2>
      <div class="linha">
        <div style="flex:1;min-width:150px">
          <input type="text" id="codigo" maxlength="6" placeholder="Ex.: H7K2QP" style="text-transform:uppercase">
        </div>
        <button class="btn ghost" onclick="entrarSala(this)"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
      </div>
    </div>`;
}

function desenharConvite() {
  const s = estado;
  $('#tela').innerHTML = `
    <div class="card">
      <h1>Sala ${esc(s.token)}</h1>
      <p class="sub">${s.na_sala} de ${s.max} jogadores${s.aposta > 0 ? ` · entrada de <b>${s.aposta}</b> moedas` : ' · sem aposta'}.</p>
      <div class="aviso" id="erro" style="display:none;margin-top:12px"></div>
      ${s.status !== 'aguardando'
        ? `<div class="aviso" style="margin-top:12px"><i class="bi bi-clock-fill"></i><span>Essa partida já começou. Peça o link da próxima.</span></div>`
        : `<div class="linha">
             <button class="btn" onclick="entrarSala(this)">
               <i class="bi bi-box-arrow-in-right"></i> Entrar${s.aposta > 0 ? ` (${s.aposta} moedas)` : ''}
             </button>
           </div>`}
    </div>`;
}

function placarHTML(mostrarPronto) {
  return `<div class="placar">${estado.jogadores.map(j => `
    <div class="jog ${j.sou_eu ? 'eu' : ''}">
      <span class="nome">${esc(j.nome)}</span>
      ${mostrarPronto && j.pronto ? '<span class="selo">pronto</span>' : ''}
      <span class="pts">${j.pontos}</span>
    </div>`).join('')}</div>`;
}

function desenharEspera() {
  const s = estado;
  const falta = Math.max(0, s.min - s.na_sala);
  $('#tela').innerHTML = `
    <div class="card">
      <h1>Sala ${esc(s.token)}</h1>
      <p class="sub">${s.na_sala} de ${s.max} jogadores${s.aposta > 0
        ? ` · entrada de <b>${s.aposta}</b> moedas · bolo de <b>${s.na_sala * s.aposta}</b>` : ' · sem aposta'}</p>
      <div class="link-caixa">
        <code>${location.origin}/games/games/index.php?game=stop&amp;sala=${esc(s.token)}</code>
        <button class="btn ghost sm" id="btCopiar" onclick="copiarLink()"><i class="bi bi-link-45deg"></i> Copiar link</button>
      </div>
      <div class="aviso" id="erro" style="display:none;margin-top:12px"></div>
      ${falta > 0
        ? `<div class="aviso" style="margin-top:12px"><i class="bi bi-people-fill"></i>
             <span>Falta${falta > 1 ? 'm' : ''} <b>${falta}</b> jogador${falta > 1 ? 'es' : ''} pra começar. Mande o link.</span></div>`
        : ''}
      <div class="linha">
        ${s.sou_criador
          ? `<button class="btn green" onclick="comecar(this)" ${falta > 0 ? 'disabled' : ''}>
               <i class="bi bi-play-fill"></i> Começar a partida</button>`
          : `<span class="sub">Esperando ${esc(estado.jogadores[0] ? estado.jogadores[0].nome : 'o dono da sala')} começar.</span>`}
        <button class="btn ghost" onclick="sairSala()">Sair</button>
      </div>
    </div>
    <div class="card"><h2>Na sala</h2>${placarHTML(false)}</div>`;
}

function relogio(seg) {
  if (seg === null || seg === undefined) return '';
  const m = Math.floor(seg / 60), r = seg % 60;
  return m + ':' + String(r).padStart(2, '0');
}

function desenharRodada() {
  const s = estado;
  // Redesenhar o bloco inteiro apagaria o que a pessoa está digitando. Enquanto
  // a rodada é a mesma, só o cabeçalho (relógio) é atualizado.
  const jaTem = $('#grade-temas') && $('#grade-temas').dataset.rodada === String(s.rodada);
  if (jaTem) {
    $('#relogio').textContent = relogio(s.segundos);
    $('#relogio').className = 'cronometro' + (s.segundos !== null && s.segundos <= 30 ? ' correndo' : '');
    return;
  }

  $('#tela').innerHTML = `
    <div class="card">
      <div class="faixa">
        <div>
          <div class="rodada-txt">Rodada ${s.rodada} de ${s.rodadas}</div>
          <div class="letra">${esc(s.letra)}</div>
        </div>
        <div style="text-align:right">
          <div class="rodada-txt">tempo</div>
          <div class="cronometro" id="relogio">${relogio(s.segundos)}</div>
        </div>
      </div>
      <div class="temas" id="grade-temas" data-rodada="${s.rodada}">
        ${s.temas.map((t, i) => `
          <div class="tema">
            <div class="tema-nome">${esc(t)}</div>
            <input type="text" maxlength="60" data-idx="${i}" autocomplete="off"
                   value="${esc((s.minhas && s.minhas[i]) || '')}">
          </div>`).join('')}
      </div>
      <div class="linha">
        <button class="btn" onclick="pararTudo(this)" style="flex:1;font-size:17px;padding:14px">
          <i class="bi bi-hand-index-thumb-fill"></i> STOP!
        </button>
      </div>
    </div>
    <div class="card"><h2>Placar</h2>${placarHTML(false)}</div>`;

  document.querySelectorAll('.tema input').forEach(i => {
    i.addEventListener('input', agendarSalvar);
    i.addEventListener('focus', () => editando = i.dataset.idx);
    i.addEventListener('blur', () => editando = null);
  });
  const primeiro = document.querySelector('.tema input');
  if (primeiro) primeiro.focus();
}

function desenharVotacao() {
  const s = estado;
  const nomes = {};
  s.jogadores.forEach(j => nomes[j.user_id] = j.nome);
  const euPronto = s.jogadores.some(j => j.sou_eu && j.pronto);

  $('#tela').innerHTML = `
    <div class="card">
      <div class="faixa">
        <div>
          <div class="rodada-txt">Rodada ${s.rodada} · letra ${esc(s.letra)}</div>
          <h1 style="margin-top:4px">O que vale?</h1>
        </div>
        <div style="text-align:right">
          <div class="rodada-txt">tempo</div>
          <div class="cronometro" id="relogio">${relogio(s.segundos)}</div>
        </div>
      </div>
      <div class="aviso">
        <i class="bi bi-flag-fill"></i>
        <span>${s.parou ? `<b>${esc(s.parou)}</b> apertou STOP. ` : ''}
        Marque o que não deveria valer. Uma resposta cai com <b>${s.maioria}</b> denúncia${s.maioria > 1 ? 's' : ''}.</span>
      </div>
      ${s.temas.map((t, i) => `
        <div class="voto-tema">
          <h3>${esc(t)}</h3>
          ${(s.grade[i] || []).map(r => {
            const caiu = r.denuncias >= s.maioria;
            const vazio = !r.texto || !r.texto.trim();
            return `<div class="resp">
              <span class="quem">${esc(nomes[r.user_id] || '?')}</span>
              <span class="txt ${vazio ? 'vazio' : ''}">${vazio ? '(em branco)' : esc(r.texto)}</span>
              ${vazio || r.user_id === MEU_ID
                ? `<button class="den" disabled>${r.denuncias || 0} <i class="bi bi-flag"></i></button>`
                : `<button class="den ${caiu ? 'caiu' : (r.denunciei ? 'on' : '')}"
                     onclick="denunciar(${i},${r.user_id},this)">
                     ${r.denuncias || 0} <i class="bi bi-flag${r.denunciei ? '-fill' : ''}"></i></button>`}
            </div>`;
          }).join('') || '<div class="resp"><span class="txt vazio">ninguém respondeu</span></div>'}
        </div>`).join('')}
      <div class="linha">
        <button class="btn green" onclick="marcarPronto(this)" ${euPronto ? 'disabled' : ''} style="flex:1">
          <i class="bi bi-check-lg"></i> ${euPronto ? 'Esperando os outros…' : 'Terminei de votar'}
        </button>
      </div>
    </div>
    <div class="card"><h2>Placar</h2>${placarHTML(true)}</div>`;
}

function desenharFim() {
  const s = estado;
  const campeoes = s.vencedores || [];
  $('#tela').innerHTML = `
    <div class="card">
      <div class="podio">
        <div class="rodada-txt">fim das ${s.rodadas} rodadas</div>
        <div class="campeao">${campeoes.length ? esc(campeoes.join(' e ')) : '—'}</div>
        <div class="premio">${s.premio > 0
          ? `levou <b>${s.premio}</b> moedas${campeoes.length > 1 ? ' (dividido)' : ''}`
          : 'partida sem aposta'}</div>
      </div>
    </div>
    <div class="card"><h2>Placar final</h2>${placarHTML(false)}</div>
    <div class="card">
      <div class="linha">
        <button class="btn" onclick="location.href='index.php?game=stop'">
          <i class="bi bi-arrow-repeat"></i> Nova sala</button>
        <button class="btn ghost" onclick="location.href='/games.php'">Voltar aos games</button>
      </div>
    </div>`;
}

/**
 * O relógio local.
 *
 * Anda de segundo em segundo entre um ciclo e outro, senão o número ficaria
 * pulando de 1,5 em 1,5 — o servidor continua sendo a fonte da verdade a cada
 * atualização de estado.
 */
setInterval(() => {
  if (!estado || estado.segundos === null || estado.segundos === undefined) return;
  if (estado.status !== 'escrevendo' && estado.status !== 'votando') return;
  estado.segundos = Math.max(0, estado.segundos - 1);
  const el = $('#relogio');
  if (el) {
    el.textContent = relogio(estado.segundos);
    el.className = 'cronometro' + (estado.segundos <= 30 ? ' correndo' : '');
  }
}, 1000);

if (SALA_URL) setInterval(puxarEstado, 1500);
pintar();
</script>
</body>
</html>
