<?php
/**
 * fantasy.php — o Cartola da ELITE. Por enquanto só por link (fora do menu e
 * do /games). A regra mora em backend/fantasy.php; a tela conversa com
 * api/fantasy.php.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();
$user = getUserSession();
$pdo  = db();
// Saldo de moedas do Games — é onde o prêmio da rodada cai.
$moedas = 0;
try {
    $stMoedas = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $stMoedas->execute([(int)$user['id']]);
    $moedas = (int)($stMoedas->fetchColumn() ?: 0);
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Fantasy FBA · FBA Manager</title>
<link rel="icon" type="image/png" href="/games/fbagames.png">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:color-mix(in srgb,var(--red) 12%,transparent);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);
  --text:#f0f0f3;--text-2:#8b8b95;--text-3:#6f6f79;
  --green:#22c55e;--amber:#f59e0b;--down:#ef4444;
  --court:#1a130c;--court-line:rgba(255,196,140,.22);
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased}
button{font-family:var(--font)}
.wrap{max-width:1240px;margin:0 auto}
.num{font-family:var(--num);font-variant-numeric:tabular-nums}

/* ── Cabeçalho do cartola ── */
.cab{display:grid;grid-template-columns:1fr auto;gap:14px;align-items:stretch;margin-bottom:14px}
.meu{background:linear-gradient(135deg,var(--red-soft),var(--panel) 60%);border:1px solid var(--border-md);border-radius:16px;padding:16px 18px;display:flex;gap:14px;align-items:center;min-width:0}
.escudo{width:54px;height:54px;border-radius:14px;background:var(--red);display:grid;place-items:center;font-family:var(--num);font-size:22px;font-weight:700;color:#fff;flex-shrink:0}
.meu h1{font-size:20px;font-weight:800;line-height:1.1;display:flex;align-items:center;gap:8px;min-width:0}
.meu h1 span{overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.meu h1 button{background:none;border:0;color:var(--text-3);cursor:pointer;font-size:14px}
.meu h1 button:hover{color:var(--red)}
.meu small{display:block;font-size:12px;color:var(--text-2);margin-top:4px}
.numeros{display:flex;gap:10px}
.numeros div{background:var(--panel);border:1px solid var(--border);border-radius:14px;padding:12px 16px;min-width:118px}
.numeros span{display:block;font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text-3)}
.numeros strong{display:block;font-family:var(--num);font-size:24px;font-weight:600;margin-top:2px}

.status{display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:10px 14px;margin-bottom:14px;font-size:13px}
.selo{display:inline-flex;align-items:center;gap:6px;font-size:11px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;border-radius:99px;padding:4px 11px}
.selo.aberta{background:rgba(34,197,94,.14);color:var(--green)}
.selo.fechada{background:rgba(245,158,11,.14);color:var(--amber)}
.selo.encerrada{background:var(--panel-3);color:var(--text-2)}
.selo i{font-size:8px}
.status .txt{color:var(--text-2)}
.status .admin{margin-left:auto;display:flex;gap:6px}

.abas{display:flex;gap:6px;margin-bottom:14px;overflow-x:auto}
.aba{padding:9px 16px;border-radius:10px;background:var(--panel-2);border:1.5px solid var(--border);color:var(--text-2);font-size:12.5px;font-weight:700;cursor:pointer;white-space:nowrap}
.aba[aria-selected="true"]{border-color:var(--red);background:var(--red-soft);color:var(--red)}

.painel{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:14px}
.tit{font-size:12px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--text-2);margin-bottom:10px;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 14px;border-radius:10px;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);font-size:12.5px;font-weight:700;cursor:pointer}
.btn:hover{border-color:var(--red)}
.btn.pri{background:var(--red);border-color:var(--red);color:#fff}
.btn:disabled{opacity:.45;cursor:not-allowed}
.btn.peq{padding:6px 10px;font-size:11.5px}

/* ── Escalação ── */
.grid-esc{display:grid;grid-template-columns:minmax(0,1fr) 440px;gap:14px;align-items:start}
.lado{position:sticky;top:14px;display:flex;flex-direction:column;gap:14px}
.quadra{position:relative;aspect-ratio:500/400;border-radius:14px;background:radial-gradient(circle at 50% 30%,#2a1d11,var(--court));border:1px solid var(--border);overflow:hidden}
.quadra svg{position:absolute;inset:0;width:100%;height:100%}
.quadra svg *{fill:none;stroke:var(--court-line);stroke-width:2;vector-effect:non-scaling-stroke}
.lugar{position:absolute;transform:translate(-50%,-50%);width:96px;display:flex;flex-direction:column;align-items:center;gap:3px;text-align:center}
.bola{width:60px;height:60px;border-radius:50%;background:var(--panel-2);border:2px dashed var(--border-md);display:grid;place-items:center;position:relative;cursor:pointer;color:var(--text-3);font-family:var(--num);font-size:15px}
.bola img{width:100%;height:100%;border-radius:50%;object-fit:cover;object-position:top;background:var(--panel-3)}
.lugar.cheio .bola{border:2px solid var(--red)}
.lugar.cap .bola{box-shadow:0 0 0 3px var(--amber)}
.bola .c{position:absolute;top:-5px;right:-6px;background:var(--amber);color:#111;font-family:var(--font);font-size:10px;font-weight:900;border-radius:99px;width:20px;height:20px;display:grid;place-items:center;border:2px solid var(--court)}
.lugar .nm{font-size:11.5px;font-weight:700;max-width:96px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;text-shadow:0 1px 3px #000}
.lugar .pr{font-family:var(--num);font-size:12px;color:var(--text-2);background:rgba(0,0,0,.45);border-radius:6px;padding:0 6px}
.lugar .pt{font-family:var(--num);font-size:14px;font-weight:700;color:var(--green);background:rgba(0,0,0,.55);border-radius:6px;padding:0 6px}
.lugar .vz{font-size:10.5px;color:var(--text-3)}
.orc{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin:12px 0 8px}
.orc div{background:var(--panel-2);border-radius:10px;padding:8px 10px}
.orc span{display:block;font-size:10px;color:var(--text-3);text-transform:uppercase;letter-spacing:.6px;font-weight:700}
.orc strong{font-family:var(--num);font-size:19px;font-weight:600}
.orc .neg{color:var(--down)}
.acoes{display:flex;gap:8px;flex-wrap:wrap;align-items:center}
.msg{font-size:12.5px;color:var(--text-2);margin-top:8px;min-height:18px}
.msg.err{color:var(--down)}.msg.ok{color:var(--green)}
.dica{font-size:11.5px;color:var(--text-3);margin-top:6px}

.filtros{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px}
.filtros input,.filtros select{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text);border-radius:10px;padding:8px 11px;font-family:var(--font);font-size:13px}
.filtros input{flex:1;min-width:160px}
.chips{display:flex;gap:5px;flex-wrap:wrap}
.chip{padding:7px 11px;border-radius:99px;border:1px solid var(--border-md);background:transparent;color:var(--text-2);font-size:12px;font-weight:700;cursor:pointer}
.chip[aria-pressed="true"]{background:var(--red);border-color:var(--red);color:#fff}

.lista{display:flex;flex-direction:column;gap:6px;max-height:calc(100vh - 260px);overflow:auto;padding-right:2px}
.card{display:grid;grid-template-columns:46px minmax(0,1fr) auto auto;gap:12px;align-items:center;background:var(--panel-2);border:1px solid var(--border);border-radius:12px;padding:8px 10px}
.card.on{border-color:var(--red);background:linear-gradient(90deg,var(--red-soft),var(--panel-2) 60%)}
.foto{width:46px;height:46px;border-radius:50%;background:var(--panel-3);overflow:hidden;display:grid;place-items:center;font-weight:800;font-size:13px;color:var(--text-2)}
.foto img{width:100%;height:100%;object-fit:cover;object-position:top}
.card b{display:block;font-size:13.5px;font-weight:700;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.card small{display:block;font-size:11.5px;color:var(--text-2);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.pos{display:inline-block;font-size:10px;font-weight:800;background:var(--panel-3);border-radius:5px;padding:1px 5px;margin-right:4px;color:var(--text)}
/* Rolagem fina e escura, na cor do tema — a padrão do Windows é um trilho cinza largo. */
.lista,.tabwrap,.abas,.caixa{scrollbar-width:thin;scrollbar-color:color-mix(in srgb,var(--red) 55%,var(--panel-3)) transparent}
.lista::-webkit-scrollbar,.tabwrap::-webkit-scrollbar,.abas::-webkit-scrollbar{width:6px;height:6px}
.lista::-webkit-scrollbar-track,.tabwrap::-webkit-scrollbar-track,.abas::-webkit-scrollbar-track{background:transparent}
.lista::-webkit-scrollbar-thumb,.tabwrap::-webkit-scrollbar-thumb,.abas::-webkit-scrollbar-thumb{background:var(--panel-3);border-radius:99px}
.lista:hover::-webkit-scrollbar-thumb,.tabwrap:hover::-webkit-scrollbar-thumb,.abas:hover::-webkit-scrollbar-thumb{background:color-mix(in srgb,var(--red) 60%,var(--panel-3))}
.lista{padding-right:6px}
.valores{display:grid;grid-template-columns:repeat(2,auto);gap:0 14px;text-align:right}
.valores span{font-size:9.5px;color:var(--text-3);text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.valores strong{font-family:var(--num);font-size:15px;font-weight:600}
.var{font-family:var(--num);font-size:11.5px}
.var.up{color:var(--green)}.var.down{color:var(--down)}.var.zero{color:var(--text-3)}
.add{width:38px;height:38px;border-radius:10px;border:0;background:var(--green);color:#06140a;font-size:20px;font-weight:900;cursor:pointer;display:grid;place-items:center}
.add.tirar{background:var(--down);color:#fff}
.add:disabled{background:var(--panel-3);color:var(--text-3);cursor:not-allowed}

/* ── Tabelas ── */
.tabwrap{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:13px}
th{font-size:10px;letter-spacing:.6px;text-transform:uppercase;color:var(--text-3);text-align:left;padding:8px;border-bottom:1px solid var(--border)}
td{padding:9px 8px;border-bottom:1px solid var(--border);white-space:nowrap}
td.r,th.r{text-align:right}
tr.eu td{background:var(--red-soft)}
.pos-rank{font-family:var(--num);font-size:16px;color:var(--text-2);width:34px}
.grid2{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.regra{display:flex;justify-content:space-between;align-items:center;padding:9px 0;border-bottom:1px solid var(--border);font-size:13.5px}
.regra strong{font-family:var(--num);font-size:17px;color:var(--green)}
.texto p{font-size:13px;color:var(--text-2);line-height:1.6;margin-bottom:8px}
.texto b{color:var(--text)}
.vazio{padding:30px;text-align:center;color:var(--text-2);font-size:13px}

/* ── Detalhe do jogador ── */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.72);z-index:500;display:flex;align-items:center;justify-content:center;padding:16px}
.fundo[hidden]{display:none}
.caixa{background:var(--panel);border:1px solid var(--border-md);border-radius:16px;width:100%;max-width:420px;padding:18px}
.caixa h2{font-size:17px;font-weight:800}
.caixa .linha{display:flex;justify-content:space-between;padding:7px 0;border-bottom:1px solid var(--border);font-size:13px}
.caixa .linha span{color:var(--text-2)}

/* ── Barra do topo: sem menu lateral, só voltar pro Games e o saldo ── */
.main{min-height:100vh;padding:0 18px 60px}
.barra-topo{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--bg) 88%,transparent);backdrop-filter:blur(10px);-webkit-backdrop-filter:blur(10px);border-bottom:1px solid var(--border);margin:0 -18px 18px;padding:10px 18px}
.barra-topo .in{max-width:1240px;margin:0 auto;display:flex;align-items:center;gap:12px}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:8px 12px;border-radius:10px;border:1px solid var(--border-md);color:var(--text-2);text-decoration:none;font-size:12.5px;font-weight:700}
.voltar:hover{border-color:var(--red);color:var(--red)}
.marca{font-weight:800;font-size:15px}.marca em{color:var(--red);font-style:normal}
.saldo{margin-left:auto;display:inline-flex;align-items:center;gap:7px;background:rgba(245,158,11,.1);border:1px solid rgba(245,158,11,.3);color:var(--amber);border-radius:99px;padding:6px 13px;font-family:var(--num);font-size:16px;font-weight:600}
.saldo small{font-family:var(--font);font-size:10px;font-weight:700;letter-spacing:.5px;text-transform:uppercase;color:var(--text-2)}

/* ── Janela do nome do time ── */
#inNome{width:100%;background:var(--panel-2);border:1.5px solid var(--border-md);color:var(--text);border-radius:11px;padding:12px 14px;font-family:var(--font);font-size:15px;font-weight:700;outline:none}
#inNome:focus{border-color:var(--red)}
.nome-rodape{display:flex;justify-content:space-between;align-items:center;gap:8px;margin-top:6px}
.nome-rodape small{font-size:11px;color:var(--text-3)}
.nome-rodape .msg{margin:0;min-height:0}
@media (max-width:1100px){
  .grid-esc{grid-template-columns:1fr}
  .lado{position:static;order:-1}
  .lista{max-height:none}
}
@media (max-width:640px){
  .main{padding-left:10px;padding-right:10px}
  .barra-topo{margin:0 -10px 14px;padding:9px 10px}
  .marca{display:none}
  .cab{grid-template-columns:1fr}
  .numeros div{flex:1;min-width:0;padding:10px}
  .numeros strong{font-size:19px}
  .lugar{width:74px}.bola{width:48px;height:48px}.lugar .nm{max-width:74px;font-size:10.5px}
  .card{grid-template-columns:40px minmax(0,1fr) auto;gap:9px}
  .foto{width:40px;height:40px}
  .valores{grid-column:2;grid-row:2;justify-content:start;text-align:left;grid-template-columns:repeat(2,auto)}
  .card .add{grid-column:3;grid-row:1 / span 2}
  .grid2{grid-template-columns:1fr}
  .status .admin{margin-left:0}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important}}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>
<div class="app">
<div class="main">
<header class="barra-topo">
  <div class="in">
    <a class="voltar" href="/games.php"><i class="bi bi-arrow-left"></i> Games</a>
    <span class="marca">Fantasy <em>FBA</em></span>
    <span class="saldo" title="Suas moedas no Games"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?> <small>moedas</small></span>
  </div>
</header>
<div class="wrap">
  <div class="cab">
    <div class="meu">
      <div class="escudo" id="escudo">F</div>
      <div style="min-width:0">
        <h1><span id="nomeTime">Fantasy FBA</span> <button id="btNome" title="Mudar nome do time" aria-label="Mudar nome do time"><i class="bi bi-pencil"></i></button></h1>
        <small id="subTime">Fantasy da ELITE · escale 5, escolha o capitão</small>
      </div>
    </div>
    <div class="numeros">
      <div><span>Patrimônio</span><strong class="num" id="nPatrimonio">—</strong></div>
      <div><span>Última rodada</span><strong class="num" id="nUltima">—</strong></div>
    </div>
  </div>

  <div class="status" id="status"><span class="txt">Carregando…</span></div>

  <div class="abas" role="tablist">
    <button class="aba" role="tab" aria-selected="true" data-aba="escalar">Escalação</button>
    <button class="aba" role="tab" aria-selected="false" data-aba="parciais">Parciais</button>
    <button class="aba" role="tab" aria-selected="false" data-aba="ranking">Ranking</button>
    <button class="aba" role="tab" aria-selected="false" data-aba="regras">Como pontua</button>
  </div>

  <section data-painel="escalar">
    <div class="grid-esc">
      <div class="painel">
        <p class="tit"><i class="bi bi-shop"></i> Mercado</p>
        <div class="filtros">
          <input type="search" id="busca" placeholder="Buscar jogador ou time" aria-label="Buscar jogador ou time">
          <select id="ordem" aria-label="Ordenar">
            <option value="preco">Maior preço</option>
            <option value="barato">Menor preço</option>
            <option value="base">Pontos na última temporada</option>
            <option value="custo">Pontos por F$</option>
            <option value="ovr">OVR</option>
          </select>
        </div>
        <div class="filtros"><div class="chips" id="chipsPos"></div></div>
        <div class="lista" id="lista"></div>
      </div>
      <div class="lado">
        <div class="painel">
          <p class="tit"><i class="bi bi-dribbble"></i> Meu quinteto</p>
          <div class="quadra" id="quadra"></div>
          <div class="orc">
            <div><span>Custo</span><strong class="num" id="oCusto">0,0</strong></div>
            <div><span>Sobra</span><strong class="num" id="oSobra">0,0</strong></div>
            <div><span>Base</span><strong class="num" id="oBase">0</strong></div>
          </div>
          <div class="acoes">
            <button class="btn pri" id="btSalvar"><i class="bi bi-check2-circle"></i> Salvar escalação</button>
            <button class="btn" id="btLimpar">Limpar</button>
          </div>
          <div class="msg" id="msg" role="status"></div>
          <p class="dica">Toque na bolinha pra filtrar a posição · toque no escalado pra escolher capitão</p>
        </div>
      </div>
    </div>
  </section>

  <section data-painel="parciais" hidden>
    <div class="grid2">
      <div class="painel">
        <p class="tit"><i class="bi bi-lightning-charge"></i> Meu time na rodada</p>
        <div id="meusPontos"></div>
      </div>
      <div class="painel">
        <p class="tit"><i class="bi bi-fire"></i> Quem mais pontua</p>
        <div class="tabwrap" id="topJogadores"></div>
      </div>
    </div>
  </section>

  <section data-painel="ranking" hidden>
    <div class="grid2">
      <div class="painel"><p class="tit"><i class="bi bi-trophy"></i> Rodada</p><div class="tabwrap" id="rankRodada"></div></div>
      <div class="painel"><p class="tit"><i class="bi bi-bar-chart"></i> Geral</p><div class="tabwrap" id="rankGeral"></div></div>
    </div>
  </section>

  <section data-painel="regras" hidden>
    <div class="grid2">
      <div class="painel">
        <p class="tit">Cada jogada vale (por jogo)</p>
        <div id="scouts"></div>
        <p class="tit" style="margin-top:18px">Bônus da temporada</p>
        <div id="bonus"></div>
      </div>
      <div class="painel texto" id="textoRegras"></div>
    </div>
  </section>
</div>
</div>
</div>

<div class="fundo" id="fundo" hidden>
  <div class="caixa" role="dialog" aria-modal="true" aria-labelledby="dTit">
    <div style="display:flex;gap:12px;align-items:center;margin-bottom:12px">
      <div class="foto" id="dFoto" style="width:56px;height:56px"></div>
      <div style="min-width:0"><h2 id="dTit"></h2><small id="dSub" style="color:var(--text-2);font-size:12px"></small></div>
      <button class="btn peq" id="dFechar" style="margin-left:auto" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>
    </div>
    <div id="dCorpo"></div>
  </div>
</div>

<div class="fundo" id="fundoNome" hidden>
  <form class="caixa" id="formNome" role="dialog" aria-modal="true" aria-labelledby="nTit">
    <h2 id="nTit">Nome do seu time</h2>
    <p style="font-size:12.5px;color:var(--text-2);margin:4px 0 14px">É como você aparece no ranking do Fantasy.</p>
    <input id="inNome" maxlength="30" autocomplete="off" aria-label="Nome do time">
    <div class="nome-rodape"><small id="nConta">0/30</small><span class="msg err" id="nErro" role="alert"></span></div>
    <div class="acoes" style="justify-content:flex-end;margin-top:14px">
      <button type="button" class="btn" id="nCancelar">Cancelar</button>
      <button type="submit" class="btn pri" id="nSalvar"><i class="bi bi-check2"></i> Salvar</button>
    </div>
  </form>
</div>

<script>
const POS = ['PG','SG','SF','PF','C'];
const LUGAR = {PG:[50,18],SG:[82,40],SF:[18,40],PF:[70,76],C:[30,76]};
const SVG = `<svg viewBox="0 0 500 400" preserveAspectRatio="none" aria-hidden="true"><rect x="1" y="1" width="498" height="398"/>
  <path d="M200 1 A50 50 0 0 0 300 1"/><rect x="180" y="240" width="140" height="159"/><path d="M200 240 A50 50 0 0 1 300 240"/>
  <path d="M40 399 V300 A210 210 0 0 1 460 300 V399"/><line x1="225" y1="378" x2="275" y2="378"/><circle cx="250" cy="365" r="9"/></svg>`;
const S = { dados: null, porId: new Map(), esc: {PG:null,SG:null,SF:null,PF:null,C:null}, cap: null, pos: '', busca: '', ordem: 'preco', sujo: false };
const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
const f1 = n => (Math.round((+n || 0) * 10) / 10).toLocaleString('pt-BR', {minimumFractionDigits:1, maximumFractionDigits:1});
const ini = n => String(n).split(/\s+/).filter(Boolean).slice(0,2).map(p => p[0]).join('').toUpperCase();
const foto = j => j.foto ? `<img src="${esc(j.foto)}" alt="" loading="lazy" onerror="this.replaceWith(document.createTextNode('${esc(ini(j.nome))}'))">` : esc(ini(j.nome));
const aberta = () => S.dados?.rodada?.status === 'aberta';
const orcamento = () => S.dados?.cartola?.patrimonio ?? 100;
const custo = () => POS.reduce((s, p) => s + (S.esc[p] ? S.porId.get(S.esc[p])?.preco || 0 : 0), 0);

async function carregar() {
  const r = await fetch('/api/fantasy.php', {credentials: 'same-origin'});
  const d = await r.json();
  if (!d.ok) { $('status').innerHTML = `<span class="txt">${esc(d.erro || 'Erro ao carregar.')}</span>`; return; }
  S.dados = d;
  S.porId = new Map((d.jogadores || []).map(j => [j.id, j]));
  if (d.escalacao) {
    POS.forEach(p => S.esc[p] = d.escalacao[p] && S.porId.has(d.escalacao[p]) ? d.escalacao[p] : null);
    S.cap = S.esc && Object.values(S.esc).includes(d.escalacao.capitao) ? d.escalacao.capitao : null;
  }
  S.sujo = d.escalacao && !d.escalacao.salva && POS.some(p => S.esc[p]);
  tudo();
}

function tudo() { cabecalho(); status(); mercado(); quadra(); parciais(); ranking(); regras(); }

function cabecalho() {
  const d = S.dados;
  $('nomeTime').textContent = d.cartola?.nome || 'Fantasy FBA';
  $('escudo').textContent = ini(d.cartola?.nome || 'F').slice(0, 2);
  $('nPatrimonio').textContent = 'F$ ' + f1(orcamento());
  const ult = d.historico?.[0];
  $('nUltima').textContent = ult ? f1(ult.pontos) : '—';
  $('subTime').textContent = ult ? `${ult.colocacao}º na T${ult.temporada}${ult.moedas ? ' · +' + ult.moedas + ' moedas' : ''}` : 'Fantasy da ELITE · escale 5, escolha o capitão';
}

function status() {
  const d = S.dados, r = d.rodada;
  if (!r) { $('status').innerHTML = '<span class="txt">Nenhuma rodada ainda — a primeira abre com a próxima temporada da ELITE.</span>'; return; }
  const txt = {
    aberta: `Rodada T${r.temporada} · preços pela T${r.base_temporada ?? '—'} · ${r.escalados} time${r.escalados === 1 ? '' : 's'} escalado${r.escalados === 1 ? '' : 's'}`,
    fechada: `Rodada T${r.temporada} em andamento · parciais com ${r.times_com_stats} de 32 times lançados`,
    encerrada: `Rodada T${r.temporada} encerrada · a próxima abre com a T${r.temporada + 1}`,
  }[r.status];
  const nome = {aberta: 'Mercado aberto', fechada: 'Mercado fechado', encerrada: 'Rodada encerrada'}[r.status];
  let adm = '';
  if (d.admin) {
    if (r.status === 'aberta') adm = `<button class="btn peq" data-admin="fechar"><i class="bi bi-lock"></i> Fechar mercado</button>`;
    if (r.status === 'fechada') adm = `<button class="btn peq" data-admin="reabrir">Reabrir</button><button class="btn peq pri" data-admin="encerrar"><i class="bi bi-flag"></i> Encerrar rodada</button>`;
  }
  $('status').innerHTML = `<span class="selo ${r.status}"><i class="bi bi-circle-fill"></i> ${nome}</span><span class="txt">${txt}</span>${adm ? `<span class="admin">${adm}</span>` : ''}`;
}

function mercado() {
  $('chipsPos').innerHTML = ['', ...POS].map(p => `<button class="chip" data-pos="${p}" aria-pressed="${S.pos === p}">${p || 'Todos'}</button>`).join('');
  const b = S.busca.trim().toLowerCase();
  const ord = {
    preco: (x, y) => y.preco - x.preco, barato: (x, y) => x.preco - y.preco,
    base: (x, y) => (y.base ?? -1) - (x.base ?? -1), custo: (x, y) => ((y.base ?? 0) / y.preco) - ((x.base ?? 0) / x.preco),
    ovr: (x, y) => y.ovr - x.ovr,
  }[S.ordem];
  const lin = [...S.porId.values()].filter(j => (!S.pos || j.pos === S.pos) && (!b || (j.nome + ' ' + j.time).toLowerCase().includes(b))).sort(ord);
  const sobra = orcamento() - custo();
  $('lista').innerHTML = lin.slice(0, 200).map(j => {
    const on = S.esc[j.pos] === j.id;
    const antes = S.esc[j.pos] ? S.porId.get(S.esc[j.pos]).preco : 0;
    const cabe = on || j.preco <= sobra + antes + 1e-9;
    const v = j.variacao;
    const varTxt = v == null ? '' : `<span class="var ${v > 0 ? 'up' : v < 0 ? 'down' : 'zero'}">${v > 0 ? '▲' : v < 0 ? '▼' : '='} ${f1(Math.abs(v))}</span>`;
    return `<div class="card${on ? ' on' : ''}">
      <button class="foto" data-ver="${j.id}" aria-label="Ver ${esc(j.nome)}" style="border:0;cursor:pointer">${foto(j)}</button>
      <div style="min-width:0"><b>${esc(j.nome)}</b>
        <small><span class="pos">${j.pos}</span>${esc(j.time_curto)} · OVR ${j.ovr}</small></div>
      <div class="valores"><span>Preço</span><span>Últ. temp.</span>
        <strong>F$ ${f1(j.preco)}</strong><strong>${j.base != null ? f1(j.base) : '—'}</strong>
        <span>${varTxt}</span><span></span></div>
      ${aberta() ? `<button class="add${on ? ' tirar' : ''}" data-add="${j.id}" ${cabe ? '' : 'disabled'} title="${cabe ? '' : 'Não cabe no patrimônio'}" aria-label="${on ? 'Tirar' : 'Escalar'} ${esc(j.nome)}">${on ? '−' : '+'}</button>` : '<span></span>'}
    </div>`;
  }).join('') || '<div class="vazio">Nenhum jogador com esse filtro.</div>';
}

function quadra() {
  const pontos = !aberta();
  $('quadra').innerHTML = SVG + POS.map(p => {
    const j = S.esc[p] && S.porId.get(S.esc[p]); const [x, y] = LUGAR[p]; const cap = j && S.cap === j.id;
    const pt = j && pontos ? (j.pontos ? j.pontos.total * (cap ? S.dados.regras.capitao : 1) : 0) : null;
    return `<div class="lugar${j ? ' cheio' : ''}${cap ? ' cap' : ''}" style="left:${x}%;top:${y}%">
      <button class="bola" data-lugar="${p}" aria-label="${j ? esc(j.nome) + (cap ? ', capitão' : '') : 'Escalar ' + p}" style="padding:0">${j ? foto(j) : p}${cap ? '<span class="c">C</span>' : ''}</button>
      <div class="nm">${j ? esc(j.nome.split(' ').slice(-1)[0]) : ''}</div>
      ${j ? (pt !== null ? `<div class="pt">${f1(pt)}</div>` : `<div class="pr">F$ ${f1(j.preco)}</div>`) : `<div class="vz">${p}</div>`}
    </div>`;
  }).join('');
  const c = custo(), sobra = orcamento() - c;
  $('oCusto').textContent = f1(c);
  $('oSobra').textContent = f1(sobra); $('oSobra').classList.toggle('neg', sobra < 0);
  $('oBase').textContent = f1(POS.reduce((s, p) => { const j = S.esc[p] && S.porId.get(S.esc[p]); return s + (j?.base || 0) * (j && S.cap === j.id ? S.dados.regras.capitao : 1); }, 0));
  const completo = POS.every(p => S.esc[p]) && S.cap;
  $('btSalvar').disabled = !aberta() || !completo || sobra < 0;
  $('btLimpar').disabled = !aberta();
  if (!aberta()) aviso(S.dados.rodada?.status === 'fechada' ? 'Mercado fechado: acompanhe as parciais.' : '');
  else if (S.sujo) aviso('Escalação da rodada passada. Confira e salve pra valer nesta.');
}

function aviso(t, tipo) { const m = $('msg'); m.textContent = t; m.className = 'msg' + (tipo ? ' ' + tipo : ''); }

function escalar(id) {
  if (!aberta()) return;
  const j = S.porId.get(id); const p = j.pos;
  if (S.esc[p] === id) { S.esc[p] = null; if (S.cap === id) S.cap = null; }
  else {
    const antes = S.esc[p] ? S.porId.get(S.esc[p]).preco : 0;
    if (custo() - antes + j.preco > orcamento() + 1e-9) return aviso(`${j.nome} custa F$ ${f1(j.preco)} e sobram F$ ${f1(orcamento() - custo() + antes)}.`, 'err');
    if (S.cap === S.esc[p]) S.cap = null;
    S.esc[p] = id;
    if (!S.cap) S.cap = id;
  }
  S.sujo = true; aviso('Não esqueça de salvar.');
  mercado(); quadra();
}

async function salvar() {
  $('btSalvar').disabled = true;
  try {
    const r = await fetch('/api/fantasy.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'},
      body: JSON.stringify({acao: 'salvar', escalacao: S.esc, capitao: S.cap})});
    const d = await r.json();
    if (!d.ok) return aviso(d.erro || 'Não deu pra salvar.', 'err');
    S.sujo = false; aviso('Escalação salva ✓', 'ok');
    await carregar(); aviso('Escalação salva ✓', 'ok');
  } catch (e) { aviso('Sem conexão. Tente de novo.', 'err'); }
  finally { quadra(); }
}

function parciais() {
  const d = S.dados, r = d.rodada;
  if (!r || r.status === 'aberta') {
    $('meusPontos').innerHTML = '<div class="vazio">Os pontos aparecem quando o mercado fechar e os times começarem a lançar as estatísticas da temporada.</div>';
    $('topJogadores').innerHTML = '<div class="vazio">Ainda não há parciais nesta rodada.</div>';
    return;
  }
  const cap = d.regras.capitao;
  let total = 0;
  const linhas = POS.map(p => {
    const j = S.esc[p] && S.porId.get(S.esc[p]); if (!j) return '';
    const c = S.cap === j.id; const pts = j.pontos ? j.pontos.total * (c ? cap : 1) : 0; total += pts;
    return `<tr><td><span class="pos">${p}</span>${esc(j.nome)}${c ? ' <b style="color:var(--amber)">C</b>' : ''}</td>
      <td class="r">${j.pontos ? j.pontos.jogos + ' j' : '<span style="color:var(--text-3)">sem stats</span>'}</td><td class="r num">${f1(pts)}</td></tr>`;
  }).join('');
  $('meusPontos').innerHTML = POS.some(p => S.esc[p])
    ? `<div style="font-family:var(--num);font-size:42px;font-weight:600;line-height:1">${f1(total)} <span style="font-size:14px;color:var(--text-2);font-family:var(--font)">pontos ${r.status === 'fechada' ? '(parcial)' : ''}</span></div>
       <div class="tabwrap" style="margin-top:10px"><table><tbody>${linhas}</tbody></table></div>`
    : '<div class="vazio">Você não escalou nesta rodada.</div>';
  const top = [...S.porId.values()].filter(j => j.pontos).sort((a, b) => b.pontos.total - a.pontos.total).slice(0, 25);
  $('topJogadores').innerHTML = top.length ? `<table><thead><tr><th>Jogador</th><th class="r">Por jogo</th><th class="r">Bônus</th><th class="r">Pontos</th></tr></thead><tbody>
    ${top.map(j => `<tr><td><span class="pos">${j.pos}</span><button data-ver="${j.id}" style="background:none;border:0;color:var(--text);cursor:pointer;font:inherit">${esc(j.nome)}</button></td>
      <td class="r num">${f1(j.pontos.por_jogo)}</td><td class="r num">${j.pontos.valor_bonus ? '+' + j.pontos.valor_bonus : '—'}</td><td class="r num"><b>${f1(j.pontos.total)}</b></td></tr>`).join('')}</tbody></table>`
    : '<div class="vazio">Nenhum time lançou estatística desta temporada ainda.</div>';
}

function ranking() {
  const d = S.dados, rk = d.ranking || {rodada: [], geral: []};
  const aberta_ = d.rodada?.status === 'aberta';
  $('rankRodada').innerHTML = rk.rodada.length ? `<table><thead><tr><th></th><th>Time</th><th class="r">${aberta_ ? '' : 'Pontos'}</th></tr></thead><tbody>
    ${rk.rodada.map((t, i) => `<tr class="${t.user_id === d.eu ? 'eu' : ''}"><td class="pos-rank">${aberta_ ? '' : i + 1}</td>
      <td><b>${esc(t.time)}</b><br><small style="color:var(--text-2)">${esc(t.gm)}</small></td>
      <td class="r num">${aberta_ ? '<span style="color:var(--text-3)">escalado</span>' : f1(t.pontos)}${t.moedas ? `<br><small style="color:var(--amber)">+${t.moedas} moedas</small>` : ''}</td></tr>`).join('')}</tbody></table>`
    : '<div class="vazio">Ninguém escalou nesta rodada ainda.</div>';
  $('rankGeral').innerHTML = rk.geral.length ? `<table><thead><tr><th></th><th>Time</th><th class="r">Pontos</th><th class="r">Patrimônio</th></tr></thead><tbody>
    ${rk.geral.map((t, i) => `<tr class="${t.user_id === d.eu ? 'eu' : ''}"><td class="pos-rank">${i + 1}</td><td><b>${esc(t.time)}</b><br><small style="color:var(--text-2)">${esc(t.gm)} · ${t.rodadas} rodada${t.rodadas > 1 ? 's' : ''}</small></td>
      <td class="r num">${f1(t.pontos)}</td><td class="r num">F$ ${f1(t.patrimonio)}</td></tr>`).join('')}</tbody></table>`
    : '<div class="vazio">O ranking geral começa quando a primeira rodada for encerrada.</div>';
}

function regras() {
  const g = S.dados.regras;
  $('scouts').innerHTML = g.scouts.map(s => `<div class="regra"><span>${s.nome}</span><strong>+${String(s.valor).replace('.', ',')}</strong></div>`).join('');
  $('bonus').innerHTML = g.bonus.map(s => `<div class="regra"><span>${s.nome}</span><strong>+${s.valor}</strong></div>`).join('');
  const premios = Object.entries(g.premios);
  $('textoRegras').innerHTML = `
    <p class="tit">Como funciona</p>
    <p><b>Cada temporada da ELITE é uma rodada.</b> Com o mercado aberto, você escala um jogador de cada posição (PG, SG, SF, PF e C) e escolhe o capitão, que pontua <b>${String(g.capitao).replace('.', ',')}×</b>.</p>
    <p><b>A pontuação é o jogo médio da temporada.</b> Exemplo: 25 pontos, 8 rebotes e 6 assistências = 25 + 12 + 12 = <b>49</b>. Quem jogou só parte da temporada ganha proporcional: 41 de 82 jogos vale metade. No fim somam os bônus.</p>
    <p><b>O preço sai da última temporada:</b> pontos ÷ ${String(g.pontos_por_fs).replace('.', ',')}. Quem fez 60 pontos custa F$ 20. Quando a rodada encerra, o preço de cada jogador vira o que ele pontuou nela — e o seu patrimônio sobe ou cai junto com os cinco que você escalou.</p>
    <p><b>Todo mundo começa com F$ ${f1(g.orcamento)}.</b> Na rodada seguinte, seu limite é o patrimônio novo. A escalação passada fica sugerida, mas só vale depois de salvar.</p>
    <p><b>Moedas por rodada:</b> ${premios.slice(0, 3).map(([p, m]) => `${p}º ${m}`).join(' · ')} · 4º ao 10º ${premios[3]?.[1] ?? 0}.</p>
    <p style="color:var(--text-3)">O mercado fecha antes de a temporada ser jogada. Os pontos aparecem como parciais conforme os times lançam as estatísticas, e a rodada é encerrada pelo admin.</p>`;
}

function verJogador(id) {
  const j = S.porId.get(id); if (!j) return;
  $('dFoto').innerHTML = foto(j);
  $('dTit').textContent = j.nome;
  $('dSub').textContent = `${j.pos} · ${j.time} · OVR ${j.ovr} · ${j.idade} anos`;
  const g = S.dados.regras;
  let corpo = `<div class="linha"><span>Preço</span><b class="num">F$ ${f1(j.preco)}</b></div>
    <div class="linha"><span>Pontos na última temporada</span><b class="num">${j.base != null ? f1(j.base) : '—'}</b></div>`;
  if (j.pontos) {
    const p = j.pontos;
    corpo += `<p class="tit" style="margin-top:14px">Nesta rodada</p>` +
      g.scouts.map(s => `<div class="linha"><span>${s.nome} ${f1(p.scouts[s.chave])} × ${String(s.valor).replace('.', ',')}</span><b class="num">${f1(p.scouts[s.chave] * s.valor)}</b></div>`).join('') +
      `<div class="linha"><span>Por jogo</span><b class="num">${f1(p.por_jogo)}</b></div>
       <div class="linha"><span>Jogos (${p.jogos} de ${p.max_jogos})</span><b class="num">× ${Math.round(p.presenca * 100)}%</b></div>` +
      p.bonus.map(b => `<div class="linha"><span>${g.bonus.find(x => x.chave === b)?.nome || b}</span><b class="num">+${g.bonus.find(x => x.chave === b)?.valor || 0}</b></div>`).join('') +
      `<div class="linha"><span><b style="color:var(--text)">Total</b></span><b class="num" style="color:var(--green)">${f1(p.total)}</b></div>`;
  }
  $('dCorpo').innerHTML = corpo;
  $('fundo').hidden = false;
}

document.addEventListener('click', async e => {
  const t = e.target.closest('button'); if (!t) return;
  if (t.dataset.aba) {
    document.querySelectorAll('.aba').forEach(a => a.setAttribute('aria-selected', a === t));
    document.querySelectorAll('[data-painel]').forEach(p => p.hidden = p.dataset.painel !== t.dataset.aba);
  } else if (t.dataset.pos !== undefined && t.classList.contains('chip')) { S.pos = t.dataset.pos; mercado(); }
  else if (t.dataset.add) escalar(+t.dataset.add);
  else if (t.dataset.ver) verJogador(+t.dataset.ver);
  else if (t.dataset.lugar) {
    const p = t.dataset.lugar, id = S.esc[p];
    if (id && aberta()) { S.cap = id; S.sujo = true; aviso(`${S.porId.get(id).nome} é o capitão. Salve pra valer.`); quadra(); }
    else if (id) verJogador(id);
    else { S.pos = p; mercado(); document.getElementById('lista').scrollIntoView({behavior: 'smooth', block: 'start'}); }
  } else if (t.dataset.admin) {
    const acao = t.dataset.admin;
    const pergunta = {fechar: 'Fechar o mercado? Ninguém mais consegue escalar nesta rodada.', reabrir: 'Reabrir o mercado?',
      encerrar: `Encerrar a rodada? ${S.dados.rodada.times_com_stats} de 32 times lançaram estatística. Os pontos, os preços e as moedas ficam definitivos.`}[acao];
    if (!confirm(pergunta)) return;
    t.disabled = true;
    const r = await fetch('/api/fantasy.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({acao})});
    const d = await r.json();
    if (!d.ok) alert(d.erro || 'Não deu.');
    carregar();
  }
});
$('btSalvar').addEventListener('click', salvar);
$('btLimpar').addEventListener('click', () => { POS.forEach(p => S.esc[p] = null); S.cap = null; S.sujo = true; aviso(''); mercado(); quadra(); });
$('busca').addEventListener('input', e => { S.busca = e.target.value; mercado(); });
$('ordem').addEventListener('change', e => { S.ordem = e.target.value; mercado(); });
/* Nome do time: janela do site, não o prompt do navegador. */
const contaNome = () => { $('nConta').textContent = `${$('inNome').value.length}/30`; };
const fecharNome = () => { $('fundoNome').hidden = true; $('btNome').focus(); };
$('btNome').addEventListener('click', () => {
  $('inNome').value = S.dados?.cartola?.nome || '';
  $('nErro').textContent = '';
  contaNome();
  $('fundoNome').hidden = false;
  $('inNome').focus(); $('inNome').select();
});
$('inNome').addEventListener('input', () => { contaNome(); $('nErro').textContent = ''; });
$('nCancelar').addEventListener('click', fecharNome);
$('fundoNome').addEventListener('click', e => { if (e.target.id === 'fundoNome') fecharNome(); });
$('formNome').addEventListener('submit', async e => {
  e.preventDefault();
  const nome = $('inNome').value.trim();
  if (nome.length < 3) { $('nErro').textContent = 'Use pelo menos 3 letras.'; return; }
  $('nSalvar').disabled = true;
  try {
    const r = await fetch('/api/fantasy.php', {method: 'POST', credentials: 'same-origin', headers: {'Content-Type': 'application/json'}, body: JSON.stringify({acao: 'nome', nome})});
    const d = await r.json();
    if (!d.ok) { $('nErro').textContent = d.erro || 'Não deu pra salvar.'; return; }
    S.dados.cartola.nome = d.nome; cabecalho(); fecharNome();
  } catch (_) { $('nErro').textContent = 'Sem conexão. Tente de novo.'; }
  finally { $('nSalvar').disabled = false; }
});
$('fundo').addEventListener('click', e => { if (e.target.id === 'fundo') $('fundo').hidden = true; });
$('dFechar').addEventListener('click', () => $('fundo').hidden = true);
document.addEventListener('keydown', e => { if (e.key === 'Escape') { $('fundo').hidden = true; if (!$('fundoNome').hidden) fecharNome(); } });

carregar();
</script>
</body>
</html>
