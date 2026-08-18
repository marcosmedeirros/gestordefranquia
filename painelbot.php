<?php
/**
 * PAINEL DO BOT — o WhatsApp da conta do bot, dentro do site.
 *
 * Duas colunas no desktop, uma no celular: lista de conversas à esquerda,
 * a conversa aberta à direita. O que entra vem da whatsapp_conversas
 * (preenchida pelo webhook) e o que sai vem da whatsapp_fila, que sempre
 * guardou tudo. A API que junta as duas é api/painelbot.php.
 *
 * ARQUIVO DESLIGADO POR PADRÃO. Guardar conversa de grupo é decisão de
 * quem responde pelo grupo, então a página abre com o seletor à vista e
 * não grava nada antes de alguém escolher.
 *
 * O envio vai pra fila como 'manual' e sai pelo worker local — o mesmo
 * caminho de tudo que o bot manda. Sai pelo número do bot, sempre: a
 * Evolution está pareada com um número só, e não existe "mandar como
 * outra pessoa" a partir dela.
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/whatsapp.php';
requireAuth();

$user = getUserSession();
$pdo  = db();

// Admin geral apenas: a tela mostra conversa de grupo inteiro, e admin de
// liga não responde por isso.
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

ensureWhatsAppTables($pdo);
$captura = whatsappCaptura($pdo);
$botLigado = whatsappAtivo($pdo);
$janela = whatsappDentroDaJanela(null, $pdo);
$plantaoSempre = whatsappPlantaoSempre($pdo);
$plantaoAte = (!$plantaoSempre && whatsappPlantaoAtivo($pdo)) ? whatsappPlantaoAte($pdo) : null;

// Grupos conhecidos, pra oferecer no seletor do modo "grupos escolhidos".
$gruposVistos = [];
try {
    $st = $pdo->query("SELECT v.jid, COALESCE(c.nome, v.ultimo_autor, v.jid) AS nome, v.mensagens
                       FROM whatsapp_grupos_vistos v
                       LEFT JOIN whatsapp_grupos_comando c ON c.jid = v.jid
                       ORDER BY v.visto_em DESC LIMIT 60");
    $gruposVistos = $st->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Painel do Bot · FBA</title>
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
/* Altura travada na tela e o fio como único elemento que rola. Sem isto o
   painel de captura empurrava a página inteira pra baixo e a conversa
   rolava junto com o cabeçalho — numa tela de chat, quem rola é o fio. */
html,body{height:100%}
body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);
  -webkit-font-smoothing:antialiased;height:100dvh;display:flex;flex-direction:column;
  overflow:hidden}

/* ── Topo ─────────────────────────────────────────────────────────── */
.topo{display:flex;align-items:center;gap:12px;padding:10px 14px;background:var(--panel);
  border-bottom:1px solid var(--border);z-index:20;flex:none}
.topo h1{margin:0;font-size:15px;font-weight:800;letter-spacing:-.2px}
.topo .voltar{display:flex;align-items:center;justify-content:center;width:32px;height:32px;
  border-radius:9px;border:1px solid var(--border);color:var(--text2);text-decoration:none;
  flex-shrink:0;transition:.15s}
.topo .voltar:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.selo{margin-left:auto;display:flex;align-items:center;gap:6px;flex-wrap:wrap;justify-content:flex-end}
.pino{font-size:10px;font-weight:800;letter-spacing:.5px;padding:4px 9px;border-radius:99px;
  border:1px solid;white-space:nowrap}
.pino.on{color:var(--green);border-color:rgba(34,197,94,.3);background:var(--green-soft)}
.pino.off{color:var(--text3);border-color:var(--border);background:var(--panel3)}
.pino.alerta{color:var(--amber);border-color:rgba(245,158,11,.32);background:var(--amber-soft)}

/* ── Layout ───────────────────────────────────────────────────────── */
.wrap{display:grid;grid-template-columns:1fr;flex:1;min-height:0}
@media(min-width:860px){ .wrap{grid-template-columns:320px 1fr} }

.lista{border-right:1px solid var(--border);background:var(--panel);overflow-y:auto;
  display:flex;flex-direction:column;min-height:0}
.busca{padding:9px 11px;border-bottom:1px solid var(--border);position:sticky;top:0;
  background:var(--panel);z-index:5}
.busca input{width:100%;background:var(--panel3);border:1px solid var(--border);border-radius:9px;
  padding:8px 11px;color:var(--text);font-family:var(--font);font-size:13px}
.busca input:focus{outline:none;border-color:var(--border2)}

.chat{display:flex;gap:10px;align-items:center;padding:10px 12px;border-bottom:1px solid var(--border);
  cursor:pointer;transition:.12s;background:none;border-left:none;border-right:none;border-top:none;
  width:100%;text-align:left;font-family:var(--font);color:var(--text)}
.chat:hover{background:var(--panel2)}
.chat.on{background:var(--red-soft);box-shadow:inset 3px 0 0 var(--red)}
.chat-foto{flex:none;width:38px;height:38px;border-radius:50%;display:flex;align-items:center;
  justify-content:center;font-size:15px;background:var(--panel3);color:var(--text2)}
.chat-txt{flex:1;min-width:0}
.chat-nome{font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.chat-ult{font-size:11.5px;color:var(--text2);white-space:nowrap;overflow:hidden;
  text-overflow:ellipsis;margin-top:2px}
.chat-hora{flex:none;font-size:9.5px;color:var(--text3);font-family:var(--num);align-self:flex-start}

/* ── Conversa ─────────────────────────────────────────────────────── */
.conversa{display:flex;flex-direction:column;min-width:0;min-height:0;background:var(--bg)}
.conversa-topo{display:flex;align-items:center;gap:10px;padding:10px 14px;
  border-bottom:1px solid var(--border);background:var(--panel)}
.conversa-topo b{font-size:14px;font-weight:800}
.conversa-topo small{display:block;font-size:10px;color:var(--text3);font-family:var(--num)}
.btn-voltar-lista{display:none;background:none;border:none;color:var(--text2);font-size:17px;
  cursor:pointer;padding:0 2px}
@media(max-width:859px){
  .wrap.aberta .lista{display:none}
  .wrap:not(.aberta) .conversa{display:none}
  .btn-voltar-lista{display:block}
}
.btn-apagar{margin-left:auto;background:none;border:1px solid var(--border);color:var(--text3);
  border-radius:8px;padding:5px 9px;font-size:11px;font-weight:700;cursor:pointer;font-family:var(--font)}
.btn-apagar:hover{border-color:var(--red);color:var(--red)}

.fio{flex:1;overflow-y:auto;padding:14px;display:flex;flex-direction:column;gap:7px}
.balao{max-width:min(560px,82%);padding:8px 11px;border-radius:12px;font-size:13.5px;
  line-height:1.45;white-space:pre-wrap;word-break:break-word;position:relative}
.balao.in{align-self:flex-start;background:var(--panel2);border:1px solid var(--border)}
.balao.out{align-self:flex-end;background:#0f4a2c;border:1px solid #14653a;color:#eafff3}
:root[data-theme="light"] .balao.out{background:#d9fdd3;border-color:#b7ebae;color:#111}
.balao-autor{display:block;font-size:10.5px;font-weight:800;color:var(--red);margin-bottom:2px}
.balao-pe{display:block;font-size:9.5px;color:var(--text3);font-family:var(--num);
  margin-top:4px;text-align:right}
.balao.out .balao-pe{color:rgba(255,255,255,.55)}
:root[data-theme="light"] .balao.out .balao-pe{color:#4a7a52}
.balao.pendente{opacity:.62;border-style:dashed}
.balao.falhou{background:var(--red-soft);border-color:rgba(252,0,37,.4);color:var(--text)}
.dia{align-self:center;font-size:9.5px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  color:var(--text3);background:var(--panel);border:1px solid var(--border);border-radius:99px;
  padding:3px 11px;margin:6px 0}

.escrever{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--border);
  background:var(--panel);align-items:flex-end}
.escrever textarea{flex:1;min-height:40px;max-height:140px;resize:none;background:var(--panel3);
  border:1px solid var(--border);border-radius:11px;padding:10px 12px;color:var(--text);
  font-family:var(--font);font-size:13.5px;line-height:1.4}
.escrever textarea:focus{outline:none;border-color:var(--border2)}
.escrever button{flex:none;width:42px;height:42px;border-radius:50%;border:none;background:var(--red);
  color:#fff;font-size:16px;cursor:pointer;transition:.15s}
.escrever button:disabled{background:var(--panel3);color:var(--text3);cursor:not-allowed}
.escrever button:hover:not(:disabled){filter:brightness(1.12)}

.vazio{flex:1;display:flex;flex-direction:column;align-items:center;justify-content:center;
  gap:8px;color:var(--text3);text-align:center;padding:30px}
.vazio i{font-size:34px;opacity:.5}

/* ── Faixa da captura ─────────────────────────────────────────────── */
/* Gaveta: fica fechada, porque é configuração e não conversa. Abre sozinha
   quando o arquivo está desligado — aí o seletor É a coisa a fazer. */
.captura{padding:12px 14px;background:var(--panel);border-bottom:1px solid var(--border);
  flex:none;display:none;max-height:38vh;overflow-y:auto}
.captura.aberto{display:block}
.pino-btn{cursor:pointer;font-family:var(--font)}
.captura-tit{font-size:10px;font-weight:800;letter-spacing:1px;text-transform:uppercase;
  color:var(--text3);margin-bottom:8px}
.captura-ops{display:flex;gap:7px;flex-wrap:wrap}
.cap-op{background:var(--panel2);border:1.5px solid var(--border);border-radius:10px;
  padding:7px 12px;cursor:pointer;font-family:var(--font);font-size:12px;font-weight:700;
  color:var(--text2);transition:.15s}
.cap-op:hover{border-color:var(--border2);color:var(--text)}
.cap-op.on{border-color:var(--red);background:var(--red-soft);color:var(--red)}
.captura-nota{font-size:11.5px;color:var(--text2);line-height:1.5;margin:9px 0 0}
.captura-grupos{margin-top:9px;display:none;flex-wrap:wrap;gap:6px}
.captura-grupos.on{display:flex}
.gsel{font-size:11px;font-weight:700;padding:5px 10px;border-radius:99px;cursor:pointer;
  border:1px solid var(--border);background:var(--panel2);color:var(--text2);font-family:var(--font)}
.gsel.on{border-color:var(--green);color:var(--green);background:var(--green-soft)}
/* Grupos de comando: painel que abre pelo pino "Grupos", igual ao de
   arquivo — a tela principal continua sendo a conversa. */
.cmd{display:none;padding:14px 16px;border-bottom:1px solid var(--border);background:var(--panel)}
.cmd.aberto{display:block}
.cmd-tit{display:flex;align-items:center;justify-content:space-between;font-size:13px;font-weight:800}
.cmd-fecha{background:none;border:0;color:var(--text3);cursor:pointer;padding:2px 4px}
.cmd-nota{font-size:11.5px;color:var(--text3);line-height:1.55;margin:6px 0 12px}
.cmd-nota code{color:var(--text2)}
.cmd-lista{display:flex;flex-direction:column;gap:6px}
.cmd-item{display:grid;grid-template-columns:1fr 118px 32px;gap:8px;align-items:center;
  background:var(--panel2);border:1px solid var(--border);border-radius:9px;padding:7px 9px}
.cmd-nome{font-size:13px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.cmd-item select,.cmd-form select,.cmd-form input{background:var(--panel3);color:var(--text);
  border:1px solid var(--border);border-radius:7px;padding:6px 8px;font-size:12px;font-family:inherit;width:100%}
.cmd-x{background:none;border:1px solid var(--border);border-radius:7px;color:var(--text3);
  cursor:pointer;height:30px}
.cmd-x:hover{color:#ef4444;border-color:#ef4444}
.cmd-vazio{font-size:12px;color:var(--text3)}
.cmd-sub{font-size:10.5px;font-weight:800;letter-spacing:.06em;text-transform:uppercase;
  color:var(--text3);margin:14px 0 6px}
.cmd-visto{display:block;width:100%;text-align:left;background:var(--panel2);cursor:pointer;
  border:1px dashed var(--border2);border-radius:9px;padding:7px 9px;margin-bottom:6px;color:var(--text)}
.cmd-visto:hover{border-color:var(--red);border-style:solid}
.cmd-visto b{display:block;font-size:12.5px}
.cmd-visto small{display:block;font-size:11px;color:var(--text3);overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.cmd-manual{margin-top:14px;font-size:12px;color:var(--text3)}
.cmd-manual summary{cursor:pointer}
.cmd-form{display:grid;grid-template-columns:1fr 1fr 118px auto;gap:7px;margin-top:8px}
.cmd-form button{background:var(--red);border:0;border-radius:7px;color:#fff;font-weight:700;
  padding:0 14px;cursor:pointer;font-size:12px}
.cmd-msg{font-size:12px;margin-top:9px;min-height:15px}
.cmd-msg.erro{color:#ef4444}.cmd-msg.ok{color:#4ade80}
@media(max-width:640px){
  /* Empilhado: três colunas em 375px deixam o nome do grupo com 90px. */
  .cmd-item{grid-template-columns:1fr 32px;gap:6px}
  .cmd-item select{grid-column:1/-1}
  .cmd-form{grid-template-columns:1fr}
  .cmd-form button{padding:9px}
}
</style>
</head>
<body>

<div class="topo">
  <a href="/admin.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
  <h1>Painel do Bot</h1>
  <div class="selo">
    <?php if (!$botLigado): ?>
      <span class="pino off" title="Ligue em Central da Liga">Bot desligado</span>
    <?php elseif ($plantaoSempre): ?>
      <button class="pino on pino-btn" onclick="plantao('off')"
              title="Plantão sem prazo: a janela de horário não vale, o bot manda enquanto o PC estiver ligado. Clique pra voltar ao horário normal.">
        Sempre ativo
      </button>
    <?php elseif (!$janela): ?>
      <button class="pino alerta pino-btn" onclick="plantao(4)"
              title="Fora de 08:45–18:00 só sai comando e mensagem manual. Clique pra liberar tudo por 4 horas.">
        Fora da janela · liberar
      </button>
    <?php elseif ($plantaoAte): ?>
      <button class="pino on pino-btn" onclick="plantao(0)"
              title="Plantão ligado: a janela está liberada. Clique pra voltar ao normal.">
        Plantão até <?= htmlspecialchars(date('H:i', strtotime($plantaoAte))) ?>
      </button>
    <?php else: ?>
      <span class="pino on">Bot ativo</span>
    <?php endif; ?>
    <?php if ($botLigado && !$plantaoSempre): ?>
      <button class="pino pino-btn" onclick="plantao('sempre')"
              title="Ignorar a janela de horário de vez: o bot passa a mandar sempre que o PC da Evolution estiver ligado.">
        <i class="bi bi-infinity"></i> Sempre
      </button>
    <?php endif; ?>
    <button class="pino pino-btn" onclick="cmdAbrir()"
          title="Os grupos onde o bot responde /comando">
    <i class="bi bi-people-fill"></i> Grupos
  </button>
  <button class="pino pino-btn <?= $captura['modo'] === 'off' ? 'off' : 'on' ?>" id="pinoCaptura"
            onclick="document.getElementById('painelCaptura').classList.toggle('aberto')"
            title="O que este painel arquiva">
      Arquivo: <?= htmlspecialchars($captura['modo']) ?>
    </button>
  </div>
</div>

<div class="captura <?= $captura['modo'] === 'off' ? 'aberto' : '' ?>" id="painelCaptura">
  <div class="captura-tit">O que este painel arquiva</div>
  <div class="captura-ops">
    <?php foreach ([['off','Nada'],['pv','Só conversas privadas'],['grupos','Só grupos escolhidos'],['tudo','Tudo']] as [$v,$rot]): ?>
      <button class="cap-op <?= $captura['modo'] === $v ? 'on' : '' ?>" data-modo="<?= $v ?>"><?= $rot ?></button>
    <?php endforeach; ?>
  </div>
  <div class="captura-grupos <?= $captura['modo'] === 'grupos' ? 'on' : '' ?>" id="capGrupos">
    <?php foreach ($gruposVistos as $g): ?>
      <button class="gsel <?= in_array($g['jid'], $captura['jids'], true) ? 'on' : '' ?>"
              data-jid="<?= htmlspecialchars($g['jid']) ?>"><?= htmlspecialchars(mb_substr($g['nome'], 0, 26)) ?></button>
    <?php endforeach; ?>
  </div>
  <p class="captura-nota">Enquanto estiver em <b>Nada</b>, o bot lê e esquece, como sempre fez — a lista abaixo
  mostra só o que ele <b>enviou</b>. Ligar faz o site guardar o texto das mensagens recebidas, e conversa de grupo
  inclui muita coisa que não é da liga. O histórico começa no momento em que você liga; o que passou não volta.</p>
</div>

<!-- ══════════════════════════════════════════════════════════════
     GRUPOS DE COMANDO — onde o bot responde /comando.
     Veio do painel do quiz, que era o lugar errado: cadastro de grupo
     do bot não tem nada a ver com pergunta do dia. O quiz continua
     saindo num grupo só, definido à parte.
     ══════════════════════════════════════════════════════════════ -->
<div class="cmd" id="painelCmd">
  <div class="cmd-tit">
    <span>Onde o bot responde comando</span>
    <button class="cmd-fecha" onclick="document.getElementById('painelCmd').classList.remove('aberto')"
            title="Fechar"><i class="bi bi-x-lg"></i></button>
  </div>
  <p class="cmd-nota">Só nos grupos desta lista o bot atende <code>/time</code>, <code>/meucap</code> e
  os demais. A <b>liga</b> é o que faz ele responder da divisão certa quando o mesmo nome existe em duas —
  sem ela, todo grupo responde ELITE. Isto não liga quiz em lugar nenhum.</p>

  <div id="cmdLista" class="cmd-lista"><span class="cmd-vazio">Carregando…</span></div>

  <div class="cmd-novos" id="cmdNovos"></div>

  <details class="cmd-manual">
    <summary>Cadastrar pelo identificador</summary>
    <div class="cmd-form">
      <input id="cmdJid"  placeholder="1203...@g.us">
      <input id="cmdNome" placeholder="Nome do grupo">
      <select id="cmdLiga">
        <option value="">— sem liga —</option>
        <option value="ELITE">ELITE</option><option value="NEXT">NEXT</option>
        <option value="RISE">RISE</option><option value="ROOKIE">ROOKIE</option>
      </select>
      <button onclick="cmdSalvar()">Salvar</button>
    </div>
  </details>
  <div class="cmd-msg" id="cmdMsg"></div>
</div>

<div class="wrap" id="wrap">
  <div class="lista">
    <div class="busca"><input id="busca" placeholder="Buscar conversa…" autocomplete="off"></div>
    <div id="listaChats"></div>
  </div>

  <div class="conversa">
    <div class="conversa-topo" id="conversaTopo" style="display:none">
      <button class="btn-voltar-lista" onclick="fecharConversa()"><i class="bi bi-chevron-left"></i></button>
      <div style="min-width:0">
        <b id="conversaNome"></b>
        <small id="conversaJid"></small>
      </div>
      <button class="btn-apagar" onclick="apagarArquivo()">Apagar arquivo</button>
    </div>

    <div class="fio" id="fio">
      <div class="vazio"><i class="bi bi-chat-dots"></i><span>Escolha uma conversa à esquerda.</span></div>
    </div>

    <div class="escrever" id="escrever" style="display:none">
      <textarea id="texto" placeholder="Escreva… (sai pelo número do bot)" rows="1"></textarea>
      <button id="enviar" title="Enviar"><i class="bi bi-send-fill"></i></button>
    </div>
  </div>
</div>

<script>
const API = '/api/painelbot.php';
let chats = [], jidAberto = null, timerFio = null, timerLista = null;

const esc = (s) => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const hora = (s) => { const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? '' : d.toLocaleTimeString('pt-BR', {hour:'2-digit', minute:'2-digit'}); };
const diaDe = (s) => { const d = new Date(String(s).replace(' ', 'T'));
  return isNaN(d) ? '' : d.toLocaleDateString('pt-BR', {day:'2-digit', month:'short', year:'numeric'}); };

async function api(acao, dados){
  const opt = dados
    ? {method:'POST', headers:{'Content-Type':'application/x-www-form-urlencoded'},
       body: new URLSearchParams({acao, ...dados})}
    : {};
  const r = await fetch(dados ? API : `${API}?acao=${acao}`, opt);
  const d = await r.json().catch(() => ({erro:'resposta inválida'}));
  if (!r.ok || d.erro) throw new Error(d.erro || 'falhou');
  return d;
}

// ── Grupos de comando ─────────────────────────────────────────────
//
// Onde o bot responde /comando. Nada a ver com o quiz, que sai num
// grupo só definido à parte — foi por morarem na mesma tela que os
// dois pareciam a mesma coisa.
let _cmdCarregado = false;

function cmdAbrir(){
  document.getElementById('painelCmd').classList.toggle('aberto');
  if (document.getElementById('painelCmd').classList.contains('aberto') && !_cmdCarregado) cmdCarregar();
}

function cmdMsg(txt, tipo){
  const el = document.getElementById('cmdMsg');
  el.textContent = txt || '';
  el.className = 'cmd-msg ' + (tipo || '');
}

const LIGAS_CMD = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

function cmdSelLiga(jid, atual){
  return `<select onchange="cmdMudarLiga('${jid}', this.value)">
    <option value=""${!atual ? ' selected' : ''}>— sem liga —</option>
    ${LIGAS_CMD.map(l => `<option value="${l}"${atual === l ? ' selected' : ''}>${l}</option>`).join('')}
  </select>`;
}

async function cmdCarregar(){
  const lista = document.getElementById('cmdLista');
  const novos = document.getElementById('cmdNovos');
  lista.innerHTML = '<span class="cmd-vazio">Carregando…</span>';
  try {
    const d = await api('grupos');
    _cmdCarregado = true;
    window._cmdGrupos = d.grupos || [];

    lista.innerHTML = d.grupos.length
      ? d.grupos.map(g => `<div class="cmd-item" data-jid="${esc(g.jid)}">
          <span class="cmd-nome">${esc(g.nome || g.jid)}</span>
          ${cmdSelLiga(g.jid, g.liga)}
          <button class="cmd-x" onclick="cmdRemover('${g.jid}', '${esc(g.nome || '')}')"
                  title="Tirar da lista"><i class="bi bi-trash3"></i></button>
        </div>`).join('')
      : '<span class="cmd-vazio">Nenhum grupo cadastrado — o bot não responde comando em lugar nenhum.</span>';

    // Os que o bot ouviu mas ninguém cadastrou. É daqui que sai o
    // cadastro de um grupo novo: o JID já vem preenchido.
    novos.innerHTML = (d.vistos || []).length ? `
      <div class="cmd-sub">O bot ouviu falar nestes — clique pra ativar</div>
      ${d.vistos.map(v => `<button class="cmd-visto" onclick="cmdAtivar(this)"
          data-jid="${esc(v.jid)}" data-nome="${esc(v.nome || '')}">
        <b>${esc(v.nome || v.jid.replace('@g.us',''))}</b>
        <small>${esc(v.ultimo_autor || 'alguém')}: ${esc(v.ultima_mensagem || '—')}</small>
      </button>`).join('')}` : '';
    cmdMsg('');
  } catch (e) {
    lista.innerHTML = '';
    cmdMsg(e.message || 'Erro ao carregar os grupos.', 'erro');
  }
}

/** Ativa um grupo que o bot já ouviu: pede o nome e a liga na hora. */
async function cmdAtivar(botao){
  const jid = botao.dataset.jid;
  const nome = prompt('Nome do grupo:', botao.dataset.nome || '');
  if (nome === null || !nome.trim()) return;
  const liga = (prompt('Liga do grupo (ELITE, NEXT, RISE, ROOKIE) — vazio pra nenhuma:', '') || '')
                 .trim().toUpperCase();
  if (liga && !LIGAS_CMD.includes(liga)) { cmdMsg('Liga inválida.', 'erro'); return; }
  await cmdGravar(jid, nome.trim(), liga);
}

async function cmdSalvar(){
  const jid  = document.getElementById('cmdJid').value.trim();
  const nome = document.getElementById('cmdNome').value.trim();
  const liga = document.getElementById('cmdLiga').value;
  if (!jid || !nome) { cmdMsg('Identificador e nome são obrigatórios.', 'erro'); return; }
  await cmdGravar(jid, nome, liga);
  document.getElementById('cmdJid').value = '';
  document.getElementById('cmdNome').value = '';
}

async function cmdMudarLiga(jid, liga){
  const g = (window._cmdGrupos || []).find(x => x.jid === jid);
  await cmdGravar(jid, (g && g.nome) || jid, liga, 'Liga atualizada.');
}

async function cmdGravar(jid, nome, liga, ok){
  try {
    await api('grupo_salvar', {jid, nome, liga: liga || ''});
    await cmdCarregar();
    cmdMsg(ok || 'Pronto — o bot já atende nesse grupo.', 'ok');
  } catch (e) { cmdMsg(e.message || 'Não deu pra salvar.', 'erro'); }
}

async function cmdRemover(jid, nome){
  if (!confirm(`Tirar ${nome || jid} da lista?\n\nO bot para de responder comando lá.`)) return;
  try {
    await api('grupo_remover', {jid});
    await cmdCarregar();
    cmdMsg('Removido.', 'ok');
  } catch (e) { cmdMsg(e.message || 'Não deu pra remover.', 'erro'); }
}

/* ── Lista ──────────────────────────────────────────────────────── */
async function carregarChats(){
  try {
    const d = await api('chats');
    chats = d.chats || [];
    desenharChats();
  } catch (e) { /* rede caiu: mantém o que está na tela */ }
}

function desenharChats(){
  const filtro = (document.getElementById('busca').value || '').toLowerCase();
  const alvo = document.getElementById('listaChats');
  const vis = chats.filter(c => !filtro || (c.nome + ' ' + c.ultima).toLowerCase().includes(filtro));

  if (!vis.length){
    alvo.innerHTML = `<div class="vazio" style="padding:24px 16px">
      <i class="bi bi-inbox"></i><span>${chats.length ? 'Nada com esse texto.' : 'Nenhuma conversa ainda.'}</span></div>`;
    return;
  }
  alvo.innerHTML = vis.map(c => `
    <button class="chat ${c.jid === jidAberto ? 'on' : ''}" onclick="abrir('${esc(c.jid)}')">
      <span class="chat-foto"><i class="bi bi-${c.grupo ? 'people-fill' : 'person-fill'}"></i></span>
      <span class="chat-txt">
        <span class="chat-nome">${esc(c.nome)}</span>
        <span class="chat-ult">${c.direcao === 'out' ? '↩ ' : ''}${esc(c.ultima || '')}</span>
      </span>
      <span class="chat-hora">${hora(c.quando)}</span>
    </button>`).join('');
}

/* ── Conversa ───────────────────────────────────────────────────── */
async function abrir(jid){
  jidAberto = jid;
  document.getElementById('wrap').classList.add('aberta');
  document.getElementById('conversaTopo').style.display = 'flex';
  document.getElementById('escrever').style.display = 'flex';
  desenharChats();
  await carregarFio(true);
  clearInterval(timerFio);
  timerFio = setInterval(() => carregarFio(false), 5000);
}

function fecharConversa(){
  jidAberto = null;
  clearInterval(timerFio);
  document.getElementById('wrap').classList.remove('aberta');
  desenharChats();
}

async function carregarFio(rolar){
  if (!jidAberto) return;
  let d;
  try { d = await api(`mensagens&jid=${encodeURIComponent(jidAberto)}`); }
  catch (e) { return; }
  if (d.jid !== jidAberto) return;   // trocou de conversa enquanto carregava

  document.getElementById('conversaNome').textContent = d.nome;
  document.getElementById('conversaJid').textContent = d.jid;

  const fio = document.getElementById('fio');
  const noFim = fio.scrollHeight - fio.scrollTop - fio.clientHeight < 60;

  let ultimoDia = '';
  fio.innerHTML = (d.mensagens || []).map(m => {
    const dia = diaDe(m.quando);
    let sep = '';
    if (dia && dia !== ultimoDia){ sep = `<div class="dia">${esc(dia)}</div>`; ultimoDia = dia; }
    const classes = ['balao', m.direcao];
    if (m.direcao === 'out' && !m.entregue) classes.push(m.erro ? 'falhou' : 'pendente');
    return sep + `<div class="${classes.join(' ')}">
      ${m.autor && d.grupo ? `<span class="balao-autor">${esc(m.autor)}</span>` : ''}
      ${esc(m.texto)}
      <span class="balao-pe">${hora(m.quando)}${
        m.direcao === 'out' ? (m.erro ? ' · falhou' : m.entregue ? ' ✓' : ' · na fila') : ''}</span>
    </div>`;
  }).join('') || `<div class="vazio"><i class="bi bi-chat"></i><span>Sem mensagens guardadas nesta conversa.</span></div>`;

  if (rolar || noFim) fio.scrollTop = fio.scrollHeight;
}

/* ── Enviar ─────────────────────────────────────────────────────── */
const caixa = document.getElementById('texto');
caixa.addEventListener('input', () => {
  caixa.style.height = 'auto';
  caixa.style.height = Math.min(140, caixa.scrollHeight) + 'px';
});
caixa.addEventListener('keydown', (e) => {
  if (e.key === 'Enter' && !e.shiftKey){ e.preventDefault(); enviar(); }
});
document.getElementById('enviar').addEventListener('click', enviar);

async function enviar(){
  const texto = caixa.value.trim();
  if (!texto || !jidAberto) return;
  const botao = document.getElementById('enviar');
  botao.disabled = true;
  try {
    await api('enviar', {jid: jidAberto, texto});
    caixa.value = ''; caixa.style.height = 'auto';
    await carregarFio(true);
    carregarChats();
  } catch (e) {
    alert(e.message);
  } finally { botao.disabled = false; }
}

async function apagarArquivo(){
  if (!jidAberto) return;
  if (!confirm('Apagar as mensagens RECEBIDAS guardadas desta conversa? O que o bot enviou continua no registro de envios.')) return;
  try {
    const d = await api('apagar', {jid: jidAberto});
    alert(`${d.apagadas} mensagem(ns) apagada(s).`);
    carregarFio(true); carregarChats();
  } catch (e) { alert(e.message); }
}

/* ── Plantão ────────────────────────────────────────────────────── */
async function plantao(modo){
  const msg = modo === 'sempre'
    ? 'Deixar o bot sempre ativo?\n\nA janela de horário deixa de valer: o bot manda enquanto o PC da Evolution estiver ligado, de madrugada inclusive.'
    : (modo === 'off' || modo === 0)
      ? 'Voltar ao horário normal? Fora de 08:45–18:00 só sairá comando e mensagem manual.'
      : `Liberar a janela de envio por ${modo} horas?\n\nTudo que estiver na fila (aviso de trade, quiz) passa a sair agora, inclusive fora do horário.`;
  if (!confirm(msg)) return;
  try {
    const d = await api('plantao', {modo: String(modo)});
    alert(d.sempre ? 'Bot sempre ativo.'
        : d.ate    ? `Liberado até ${d.ate.slice(11, 16)}.`
                   : 'De volta ao horário normal.');
    location.reload();
  } catch (e) { alert(e.message); }
}

/* ── Captura ────────────────────────────────────────────────────── */
document.querySelectorAll('.cap-op').forEach(b => b.addEventListener('click', () => salvarCaptura(b.dataset.modo)));
document.querySelectorAll('.gsel').forEach(b => b.addEventListener('click', () => {
  b.classList.toggle('on');
  salvarCaptura('grupos');
}));

async function salvarCaptura(modo){
  const jids = [...document.querySelectorAll('.gsel.on')].map(b => b.dataset.jid).join(',');
  if (modo === 'tudo' && !confirm('Arquivar TODAS as mensagens de todos os grupos em que o bot está?\n\nAs pessoas dos grupos não sabem que existe esse registro.')) return;
  try {
    await api('captura', {modo, jids});
    document.querySelectorAll('.cap-op').forEach(b => b.classList.toggle('on', b.dataset.modo === modo));
    document.getElementById('capGrupos').classList.toggle('on', modo === 'grupos');
    const pino = document.getElementById('pinoCaptura');
    pino.textContent = 'Arquivo: ' + modo;
    pino.className = 'pino pino-btn ' + (modo === 'off' ? 'off' : 'on');
    // Escolheu o que grava: a gaveta já cumpriu o papel dela.
    if (modo !== 'off') document.getElementById('painelCaptura').classList.remove('aberto');
  } catch (e) { alert(e.message); }
}

document.getElementById('busca').addEventListener('input', desenharChats);
carregarChats();
timerLista = setInterval(carregarChats, 8000);
</script>
</body>
</html>
