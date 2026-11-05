<?php
/**
 * ESTUDOS DE JOGADORES — a base de dados do Marcos, só do dono.
 *
 * Todo jogador que já passou pela FBA, com a carreira inteira: pico, pico
 * sustentado (ignorando o 99 de uma temporada só), peak start/end, estatísticas,
 * FG%, letras, prêmios e tendências. Abas:
 *
 *   ELITE · ativos         quem está hoje nos times da ELITE
 *   Outras ligas · ativos  NEXT, RISE e ROOKIE
 *   Histórico completo     todo mundo, inclusive quem já saiu dos elencos
 *   ★ Favoritos            quem o Marcos marcou com a estrela
 *   Estudo por nome        todas as "vidas" de um mesmo jogador real (os "Kirilenkos") e a média delas
 *   Lixeira                quem o Marcos tirou dos estudos (dá pra restaurar)
 *
 * Clicar numa linha abre a carreira; dali dá pra favoritar, EDITAR (nome, lenda, nota,
 * temporadas, letras, estatísticas), excluir e criar jogador na mão.
 *
 * ── CORREÇÕES NÃO MEXEM NO JOGO ───────────────────────────────────────
 *
 * Editar/excluir aqui grava só na camada do Marcos (estudos_vidas / estudos_temporadas) e é
 * aplicado por cima na hora de ler: o histórico do jogo (player_season_log) não é tocado.
 * "Desfazer correções" devolve o dado original.
 *
 * ── SÓ POR LINK, E SÓ PRO DONO ────────────────────────────────────────
 *
 * Como o tendencys-elite.php: não entra em menu nenhum, e só passa quem está
 * logado com o e-mail do dono (a mesma trava do marcos-dev.php).
 *
 * @see backend/estudos_jogadores.php  as contas e o que é "pico sustentado"
 * @see backend/estudos_ajustes.php    favoritos, correções e lixeira
 * @see api/marcos-estudos.php         a porta de dados
 * @see js/marcos-estudos.js           a tela
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

/** O mesmo dono do marcos-dev.php. */
const DONO_ESTUDOS_PG = 'medeirros99@gmail.com';

$user = getUserSession();
if (strtolower(trim((string)($user['email'] ?? ''))) !== DONO_ESTUDOS_PG) {
    http_response_code(403);
    exit('Sem acesso.');
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Estudos de Jogadores · FBA Manager</title>
<meta name="robots" content="noindex, nofollow">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;--red-soft:color-mix(in srgb,var(--red) 12%,transparent);
  --bg:#07070a;--panel:#101013;--panel-2:#16161a;--panel-3:#1c1c21;
  --border:rgba(255,255,255,.07);--border-md:rgba(255,255,255,.12);
  --text:#f0f0f3;--text-2:#8b8b95;--text-3:#6f6f79;
  --green:#22c55e;--amber:#f59e0b;--blue:#3b82f6;--purple:#a855f7;
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;--radius:12px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
[hidden]{display:none !important}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;padding:16px 16px 60px}
.wrap{max-width:none;margin:0 auto}
#painelNome{max-width:1300px}
a{color:inherit}

.topo{display:flex;align-items:center;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:10px}
h1{font-size:20px;font-weight:800;line-height:1.15}
h1 i{color:var(--red)}
.sub{font-size:12px;color:var(--text-2);margin-top:3px;line-height:1.5}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:6px 12px;border-radius:9px;border:1px solid var(--border-md);
  color:var(--text-2);text-decoration:none;font-size:12px;font-weight:600}
.voltar:hover{border-color:var(--red);color:var(--red)}

/* ── As abas ───────────────────────────────────────────────────────── */
.abas{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px;border-bottom:1px solid var(--border);padding-bottom:8px}
.aba{padding:7px 13px;border-radius:9px;border:1px solid var(--border);background:var(--panel);color:var(--text-2);
  font:inherit;font-size:12.5px;font-weight:700;cursor:pointer}
.aba:hover{color:var(--text)}
.aba.on{background:var(--red-soft);border-color:color-mix(in srgb,var(--red) 40%,transparent);color:var(--red)}
.aba small{font-weight:600;opacity:.75;margin-left:4px}
.aba.estrela.on{background:rgba(245,158,11,.12);border-color:rgba(245,158,11,.4);color:var(--amber)}

/* ── Barra de controle ─────────────────────────────────────────────── */
.barra{display:flex;gap:7px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.busca,.barra select,.barra input[type=number],.campo,.painel select,.painel input[type=number],.painel input[type=text],.painel textarea,.ed select,.ed input,.ed textarea{
  padding:7px 11px;border-radius:9px;border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);font:inherit;font-size:12.5px}
.busca{flex:1;min-width:170px;max-width:280px}
.busca:focus,.barra select:focus,.campo:focus,.painel select:focus,.painel input:focus,.painel textarea:focus,.ed select:focus,.ed input:focus,.ed textarea:focus{outline:none;border-color:var(--red)}
.marca{display:inline-flex;align-items:center;gap:5px;font-size:12px;color:var(--text-2);cursor:pointer;user-select:none}
.btn{display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:9px;border:1px solid var(--border-md);
  background:var(--panel-2);color:var(--text);font:inherit;font-size:12px;font-weight:600;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn:hover{border-color:var(--red)}
.btn:disabled{opacity:.45;cursor:default}
.btn.on{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.btn.perigo:hover{border-color:#ef4444;color:#fca5a5}
.btn.ok{background:var(--red);border-color:var(--red);color:#fff}
.btn.peq{padding:4px 8px;font-size:11px}
.badge{display:inline-block;min-width:17px;padding:0 5px;border-radius:999px;background:var(--red);color:#fff;font-size:10px;font-weight:800;text-align:center;line-height:17px}
.conta{font-size:12px;color:var(--text-2);margin-left:auto}
.conta b{color:var(--text)}
.sep{width:1px;height:22px;background:var(--border-md)}

/* ── Painéis (filtros, colunas, peak, resumo) ─────────────────────── */
.painel{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;margin-bottom:9px;font-size:12.5px}
.painel h3{font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);margin-bottom:8px;font-weight:800}
.painel .linha{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:8px}
.painel .dica{font-size:11.5px;color:var(--text-3);line-height:1.55;max-width:880px}
.painel .dica b{color:var(--text-2)}
.regra{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:6px}
.regra input[type=number],.regra input[type=text]{width:110px}
.regra .opcs{display:flex;gap:4px;flex-wrap:wrap}
.regra .opcs label{display:inline-flex;gap:3px;align-items:center;padding:3px 8px;border:1px solid var(--border-md);border-radius:999px;font-size:11.5px;cursor:pointer;color:var(--text-2)}
.regra .opcs label:has(input:checked){border-color:var(--red);color:var(--red);background:var(--red-soft)}
.regra .opcs input{display:none}
.regra .rm{padding:5px 8px}
.cols{display:grid;grid-template-columns:repeat(auto-fill,minmax(150px,1fr));gap:3px 12px}
.cols .grupo{grid-column:1/-1;font-size:10.5px;text-transform:uppercase;letter-spacing:.6px;color:var(--text-3);margin-top:6px;font-weight:800}
.resumo-tab{width:100%;max-width:760px}
.resumo-tab td,.resumo-tab th{padding:3px 9px;font-size:12px}
.resumo-tab th{position:static;cursor:default;background:none;text-align:right}
.resumo-tab th:first-child,.resumo-tab td:first-child{text-align:left}
.resumo-tab td{text-align:right;font-family:var(--num)}
.resumo-tab td:first-child{font-family:var(--font)}
details.painel > summary{cursor:pointer;font-size:11px;text-transform:uppercase;letter-spacing:.7px;color:var(--text-2);font-weight:800}
details.painel[open] > summary{margin-bottom:8px}

/* ── A tabela: a rolagem é a da PÁGINA ─────────────────────────────────
   A tabela cresce até o fim e quem rola é a janela — o cabeçalho (position:sticky)
   e a coluna do jogador ficam presos à JANELA. Pra isso NENHUM ancestral da tabela
   pode ter overflow. Por isso a tabela larga estica a própria página (.wrap.larga) e
   quem rola pro lado também é a janela; barra, painéis e rodapé ficam presos à
   esquerda (position:sticky;left:0, classe .fixo) pra não irem embora. */
.tabela-pagina{border:1px solid var(--border);border-radius:var(--radius);background:var(--panel)}
.wrap.larga{width:max-content;min-width:100%;max-width:none}
.wrap.larga .fixo{position:sticky;left:0;width:calc(100vw - 32px)}
.tabela{overflow:auto;border:1px solid var(--border);border-radius:var(--radius);background:var(--panel)}
table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
th,td{padding:5px 9px;font-size:12px;line-height:1.25;white-space:nowrap;border-bottom:1px solid var(--border)}
body.denso th,body.denso td{padding:2px 8px;font-size:11.5px}
thead th{position:sticky;top:0;z-index:3;background:var(--panel-3);font-size:10px;letter-spacing:.4px;text-transform:uppercase;
  color:var(--text-2);font-weight:700;text-align:center;cursor:pointer;user-select:none}
thead th:hover{color:var(--text)}
thead th.ord{color:var(--red)}
thead th .seq{font-size:8px;vertical-align:super;margin-left:1px}
thead th.ord::after{content:' ▾';font-size:9px}
thead th.ord.asc::after{content:' ▴'}
tbody tr{cursor:pointer}
tbody tr:hover td{background:var(--panel-2)}
.col-jog{position:sticky;left:0;z-index:2;background:var(--panel);min-width:230px;font-weight:600;text-align:left}
thead .col-jog{z-index:4;background:var(--panel-3)}
tbody tr:hover .col-jog{background:var(--panel-2)}
.col-jog .nm{display:flex;align-items:center;gap:6px}
.col-jog .nm .txt{flex:1;min-width:0}
.col-jog small{display:block;font-weight:500;font-size:10.5px;color:var(--text-3)}
.ic{background:none;border:0;color:var(--text-3);cursor:pointer;padding:2px 3px;font-size:13px;line-height:1;border-radius:5px}
.ic:hover{color:var(--text);background:var(--panel-3)}
.ic.fav{color:var(--amber)}
.ic.perigo:hover{color:#fca5a5}
.acoes{display:flex;gap:1px;opacity:.55}
tr:hover .acoes{opacity:1}
tr.ajust td:first-child .nm .txt > span.nome::after{content:' ✎';color:var(--blue);font-size:10px}
.n{font-family:var(--num);text-align:center;font-size:13px}
.n.fraco{color:var(--text-3)}
.n.alto{color:#86efac}
.n.top{color:#fbbf24}
.flag{color:var(--amber);font-size:11px;margin-left:3px}
.tag{display:inline-block;font-size:9.5px;font-weight:800;padding:1px 6px;border-radius:999px;background:var(--panel-3);color:var(--text-2);margin-left:5px}
.tag.lenda{background:rgba(245,158,11,.14);color:var(--amber)}
.tag.fora{background:rgba(255,255,255,.05);color:var(--text-3)}
.tag.festa{background:rgba(168,85,247,.14);color:#c4b5fd}
.tag.manual{background:rgba(59,130,246,.15);color:#93c5fd}
.aviso-festa{margin:4px 0 14px;padding:11px 14px;border-radius:11px;background:rgba(168,85,247,.1);border:1px solid rgba(168,85,247,.3);
  font-size:12.5px;line-height:1.55;color:#ddd6fe}
.pg{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:10px;font-size:12px;color:var(--text-2)}
.nada{padding:30px 18px;text-align:center;color:var(--text-2);font-size:13px}
.rodape{margin-top:12px;font-size:11.5px;color:var(--text-3);line-height:1.6;max-width:1000px}

/* ── Cartões de números ───────────────────────────────────────────── */
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:8px;margin:10px 0 14px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:9px 11px}
.card b{font-family:var(--num);font-size:19px;display:block;line-height:1.05}
.card span{font-size:10px;color:var(--text-2);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.card small{display:block;font-size:10.5px;color:var(--text-3);margin-top:2px}
.sec{font-size:11.5px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin:16px 0 7px}
.bloco{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:12px 14px;margin-bottom:12px;overflow-x:auto}
.bloco table{width:100%}
.bloco th{position:static;cursor:default;background:none}
.bloco td,.bloco th{text-align:center}
.bloco td:first-child,.bloco th:first-child{text-align:left}

.letras{display:flex;gap:6px;flex-wrap:wrap}
.letra{background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:11px;color:var(--text-2);text-align:center;min-width:54px}
.letra b{display:block;font-family:var(--num);font-size:16px;color:var(--text);line-height:1.1}
.letra.a b{color:#86efac}.letra.b b{color:#93c5fd}.letra.c b{color:#cbd5e1}.letra.d b,.letra.f b{color:#fca5a5}

/* ── Estudo por nome ──────────────────────────────────────────────── */
.escolha{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:12px}
.chip{padding:4px 10px;border-radius:999px;border:1px solid var(--border);background:var(--panel);color:var(--text-2);
  font:inherit;font-size:11.5px;font-weight:600;cursor:pointer}
.chip:hover{border-color:var(--red);color:var(--text)}
.chip small{color:var(--text-3);margin-left:4px}
.explica{font-size:12px;color:var(--text-3);line-height:1.6;margin-bottom:12px;max-width:900px}
.explica b{color:var(--text-2)}
.grafico{width:100%;height:auto;display:block}
.leg{display:flex;gap:12px;flex-wrap:wrap;font-size:11.5px;color:var(--text-2);margin-top:6px}
.leg i{display:inline-block;width:14px;height:3px;border-radius:2px;margin-right:5px;vertical-align:middle}
tr.fora-media td{opacity:.5}
td.est{font-style:italic;color:#a5b4fc}
.porvida{font-family:var(--num);letter-spacing:.3px}

/* ── O popup da carreira e o editor ───────────────────────────────── */
/* margin:auto de volta: o reset `*{margin:0}` lá em cima apaga a centralização do <dialog>. */
dialog{border:1px solid var(--border-md);border-radius:16px;background:var(--panel);color:var(--text);
  width:min(1180px,97vw);max-height:93vh;padding:0;overflow:hidden;margin:auto}
dialog.peq{width:min(440px,94vw)}
dialog::backdrop{background:rgba(0,0,0,.72)}
.dlg{display:flex;flex-direction:column;max-height:93vh}
.dlg-cab{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:14px 18px 8px}
.dlg-cab h2{font-size:19px;font-weight:800;line-height:1.15}
.dlg-cab p{font-size:12px;color:var(--text-2);margin-top:3px}
.dlg-acoes{display:flex;gap:6px;flex-wrap:wrap;padding:0 18px 8px}
.dlg-corpo{overflow:auto;padding:0 18px 18px}
.dlg-pe{display:flex;gap:8px;flex-wrap:wrap;align-items:center;padding:10px 18px;border-top:1px solid var(--border);background:var(--panel-2)}
.fechar{background:none;border:1px solid var(--border-md);color:var(--text-2);border-radius:9px;padding:6px 10px;cursor:pointer;font:inherit}
.fechar:hover{border-color:var(--red);color:var(--red)}
.tend{display:grid;grid-template-columns:repeat(auto-fill,minmax(98px,1fr));gap:6px}
.tend div{background:var(--panel-3);border-radius:8px;padding:6px 9px;font-size:11px;color:var(--text-2)}
.tend div b{display:block;font-family:var(--num);font-size:16px;color:var(--text)}
.tend div.dest{border:1px solid color-mix(in srgb,var(--red) 45%,transparent);background:color-mix(in srgb,var(--red) 9%,transparent);color:var(--text)}
.carga{padding:40px;text-align:center;color:var(--text-2)}
.nota-vida{margin:6px 0 12px;padding:9px 12px;border-radius:10px;background:rgba(59,130,246,.09);border:1px solid rgba(59,130,246,.28);font-size:12.5px;line-height:1.55;color:#bfdbfe;white-space:pre-wrap}
.erro{margin:8px 18px 0;padding:8px 12px;border-radius:9px;background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.4);color:#fecaca;font-size:12.5px}

/* editor */
.ed .linha{display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end;margin-bottom:10px}
.ed label.cp{display:flex;flex-direction:column;gap:3px;font-size:10.5px;text-transform:uppercase;letter-spacing:.4px;color:var(--text-2);font-weight:700}
.ed textarea{width:100%;min-height:54px;resize:vertical}
.ed table{min-width:0}
.ed table input,.ed table select{padding:3px 4px;font-size:12px;border-radius:6px}
.ed table input[type=number]{width:58px}
.ed table input[data-f=time]{width:140px}
.ed table select[data-l]{width:62px}
.ed table select[data-f=liga]{width:84px}
.ed table select[data-f=pos]{width:60px}
.ed table td{padding:2px 3px}
.ed table th{position:static;cursor:default;padding:4px 3px;font-size:9.5px}
.ed .w-s{min-width:46px}.ed .w-m{min-width:62px}.ed .w-l{min-width:110px}
.ed tr.nova td{background:rgba(59,130,246,.07)}
.ed tr{cursor:default}
.ed tr:hover td{background:none}

@media (max-width:640px){
  .wrap.larga .fixo{width:calc(100vw - 20px)}
  body{padding:10px 10px 50px}
  .conta{margin-left:0;width:100%}
  .busca{max-width:none}
  .col-jog{min-width:170px}
}
</style>
</head>
<body>
<div class="wrap larga">

  <div class="topo fixo">
    <div>
      <h1><i class="bi bi-database"></i> Estudos de Jogadores</h1>
      <p class="sub">Sua base de dados de todo jogador que já passou pela FBA. Clique numa linha pra abrir a carreira · ☆ favorita · ✎ edita · 🗑 manda pra lixeira.
         Editar nunca mexe no histórico do jogo, só na sua camada.</p>
    </div>
    <a class="voltar" href="/dashboard.php"><i class="bi bi-arrow-left"></i> Voltar</a>
  </div>

  <div class="abas fixo" id="abas">
    <button class="aba on" data-aba="elite">ELITE · ativos<small id="nElite"></small></button>
    <button class="aba" data-aba="outras">Outras ligas · ativos<small id="nOutras"></small></button>
    <button class="aba" data-aba="historico">Histórico completo<small id="nHist"></small></button>
    <button class="aba estrela" data-aba="favoritos"><i class="bi bi-star-fill"></i> Favoritos<small id="nFav"></small></button>
    <button class="aba" data-aba="nome"><i class="bi bi-people"></i> Estudo por nome</button>
    <button class="aba" data-aba="lixeira"><i class="bi bi-trash3"></i> Lixeira<small id="nLix"></small></button>
  </div>

  <!-- Peak -->
  <div class="painel fixo" id="pPeak" hidden>
    <h3>Como o peak é medido</h3>
    <div class="linha">
      <label class="marca">Peak end é a idade
        <select id="pkPonto"><option value="queda">da 1ª temporada EM QUEDA</option><option value="ultimo">da ÚLTIMA temporada no pico</option></select>
      </label>
      <label class="marca">Queda =
        <input type="number" id="pkQueda" min="1" max="6" style="width:62px"> pt(s) abaixo do pico sust.
      </label>
      <label class="marca">Atributos também contam:
        <select id="pkAtrib"><option value="0">não (só OVR)</option><option value="1">se a soma cair 1 degrau</option><option value="2">se cair 2 degraus</option><option value="3">se cair 3 degraus</option><option value="4">se cair 4 degraus</option></select>
      </label>
      <label class="marca">Peak start: até
        <input type="number" id="pkPlato" min="0" max="3" style="width:62px"> pt(s) do pico sust.
      </label>
      <button class="btn peq ok" id="pkAplica">Aplicar</button>
      <button class="btn peq" id="pkPadrao">Padrão</button>
    </div>
    <p class="dica"><b>Peak end</b> = idade em que o jogador <b>começou a cair</b> (ex.: 32, 38). Padrão: a 1ª temporada, depois do pico, em que o OVR fica abaixo do pico sustentado.
      Se o número não bate com o que você espera, mexa aqui: <b>"da última temporada no pico"</b> dá a idade de antes da queda; <b>queda = 2 ou 3</b> ignora tropeço de 1 ponto;
      ligar <b>atributos</b> antecipa o peak end (a soma das letras costuma oscilar). Ao aplicar, as listas são recalculadas.</p>
  </div>

  <!-- ABAS DE LISTA -->
  <section id="painelLista">
    <div class="barra fixo" id="barra">
      <input class="busca" id="fBusca" type="search" placeholder="Buscar jogador, time ou nota…" autocomplete="off">
      <select id="fLendas" title="O que fazer com as lendas (is_lenda do jogo, ou as que você marcou)">
        <option value="ocultar">Lendas: ocultar</option><option value="mostrar">Lendas: mostrar</option><option value="so">Lendas: só elas</option>
      </select>
      <label class="marca"><input type="checkbox" id="fCurtas"> sem vidas de 1 temp.</label>
      <label class="marca"><input type="checkbox" id="fStats"> só com stats</label>
      <label class="marca" id="lAtivos"><input type="checkbox" id="fAtivos"> só em elenco</label>
      <label class="marca"><input type="checkbox" id="fAjust"> só ajustados</label>
      <span class="sep"></span>
      <button class="btn" id="bFiltros"><i class="bi bi-funnel"></i> Filtros <span class="badge" id="nRegras" hidden>0</span></button>
      <button class="btn" id="bColunas"><i class="bi bi-layout-three-columns"></i> Colunas</button>
      <button class="btn" id="bPeak"><i class="bi bi-sliders"></i> Peak</button>
      <button class="btn" id="bDenso" title="Linhas mais juntas"><i class="bi bi-distribute-vertical"></i></button>
      <span class="sep"></span>
      <button class="btn" id="bNovo"><i class="bi bi-plus-lg"></i> Novo jogador</button>
      <button class="btn" id="bCsv"><i class="bi bi-download"></i> CSV</button>
      <select id="fPorPag" title="Quantas linhas mostrar de uma vez">
        <option value="100">100/pág.</option><option value="250" selected>250/pág.</option>
        <option value="500">500/pág.</option><option value="1000">1000/pág.</option><option value="0">Todas</option>
      </select>
      <button class="btn" id="bLimpar" hidden><i class="bi bi-x-lg"></i> Limpar tudo</button>
      <span class="conta" id="conta"></span>
    </div>

    <!-- Construtor de filtros -->
    <div class="painel fixo" id="pFiltros" hidden>
      <h3>Filtros — combine quantos quiser</h3>
      <div class="linha">
        <span>Mostrar jogadores que atendem</span>
        <select id="rModo"><option value="todas">a TODAS as regras</option><option value="qualquer">a QUALQUER regra</option></select>
        <button class="btn peq" id="rAdd"><i class="bi bi-plus-lg"></i> Regra</button>
        <button class="btn peq" id="rZera"><i class="bi bi-x-lg"></i> Tirar todas</button>
        <span class="sep"></span>
        <select id="rPreset"><option value="">Filtros salvos…</option></select>
        <button class="btn peq" id="rSalva"><i class="bi bi-save"></i> Salvar este</button>
        <button class="btn peq perigo" id="rApaga"><i class="bi bi-trash3"></i></button>
      </div>
      <div id="rLista"></div>
      <p class="dica" id="rAtalhos"></p>
    </div>

    <!-- Colunas -->
    <div class="painel fixo" id="pColunas" hidden>
      <h3>Colunas da tabela</h3>
      <div class="linha">
        <button class="btn peq" id="cTodas">Todas</button>
        <button class="btn peq" id="cEssencial">Só o essencial</button>
        <button class="btn peq" id="cPeak">Foco em peak</button>
        <button class="btn peq" id="cStats">Foco em stats</button>
      </div>
      <div class="cols" id="cLista"></div>
    </div>

    <details class="painel fixo" id="pResumo">
      <summary>Estatísticas do filtro atual (média, mediana, mín, máx por coluna)</summary>
      <div id="rResumo"></div>
    </details>

    <div class="tabela-pagina"><table id="tabela"><thead id="cab"></thead><tbody id="corpo"></tbody></table></div>
    <div class="pg fixo" id="paginacao"></div>
    <p class="rodape fixo">
      <b>Pico</b> é o maior OVR que o jogador teve. <b>Pico sust.</b> é o maior OVR que ele <em>segurou por duas
      temporadas seguidas</em> — um 99 isolado entre dois 85 vira 85. O <span class="flag">⚑</span> marca o pico de uma
      temporada só. <b>Fim</b> é o OVR da última temporada registrada e <b>Queda</b> é o pico sustentado menos o fim.<br>
      <b>Peak start</b> é a idade em que chega ao patamar de pico. <b>Peak end</b> é a idade em que <em>começou a cair</em> (configurável em
      <b>Peak</b>); "no pico" = ainda não caiu. <b>Anos pico</b> = peak end − peak start; <b>Anos até pico</b> = da entrada ao peak start.
      <b>Aposent.</b> é a idade em que parou de jogar <em>no jogo</em>: o jogo não registra isso, então é deduzida (saiu do elenco no
      meio do sprint com 36+ anos, o mínimo pra aposentar) e marcada com <b>?</b>; o que você digitar no editor vale por cima (✎).
      <b>"joga"</b> = ainda num elenco. Onde o jogo ainda não mostra, <b>Peak end</b> e <b>Aposent.</b> trazem uma <em>estimativa na escala do jogo</em> (por nome; a carreira real
      convertida: no 2K o peak end fica entre 30 e 38 e a aposentadoria é de 36+), em <b style="color:#a5b4fc">~roxo itálico</b>; o dado do jogo sempre vem primeiro. As estimativas nunca
      entram nas médias do estudo por nome, e ficam em branco pra quem é jovem ou eu não conheço — aí se espera o jogo da FBA mostrar.
      Corrija no editor (✎, seção "Vida real").
      <b>Entrada</b> é a idade na primeira temporada registrada. <b>POT ini</b> é o POT dessa temporada.
      <b>T/SHOT</b> e <b>T/TOUCH</b> são as tendências do jogo (0–99), só pra ELITE de 75 OVR pra cima (ou o que você digitar no editor).<br>
      Estatísticas são médias ponderadas por jogos; os picos só contam temporadas com 20 jogos ou mais.
      <b>Lendas</b> ficam ocultas por padrão (e fora das médias do estudo por nome).
      <b>99 festivo:</b> jogador de uma única temporada de OVR 99 (T20 de 2044) — fora de todas as métricas.
      <b>Shift+clique</b> no cabeçalho soma uma ordenação secundária.
    </p>
  </section>

  <!-- ABA ESTUDO POR NOME -->
  <section id="painelNome" hidden>
    <p class="explica fixo">A FBA refaz as classes a cada sprint, então o mesmo jogador real aparece várias vezes — cada uma é uma
      <b>vida</b> com a sua carreira (o Kirilenko tem 5). Escolha um nome pra ver todas as vidas, o peak end de cada uma e a média delas.
      As médias usam só vidas com pelo menos <b>N temporadas</b> (padrão 3), sem 99 festivo e sem lendas (a menos que você marque).</p>
    <div class="escolha fixo">
      <input class="campo busca" id="nomeBusca" list="listaNomes" placeholder="Nome do jogador (ex.: Andrei Kirilenko)" autocomplete="off">
      <datalist id="listaNomes"></datalist>
      <label class="marca">Vidas com pelo menos
        <select class="campo" id="nomeMin"><option>1</option><option>2</option><option selected>3</option><option>4</option><option>5</option><option>6</option><option>8</option></select>
        temporadas
      </label>
      <label class="marca"><input type="checkbox" id="nomeLendas"> incluir lendas</label>
      <button class="btn" id="nomeIr"><i class="bi bi-search"></i> Estudar</button>
      <button class="btn" id="nomePeak"><i class="bi bi-sliders"></i> Peak</button>
    </div>
    <div class="sec fixo" style="margin-top:4px">Nomes com mais vidas</div>
    <div class="chips fixo" id="nomeChips"></div>
    <div id="nomeResultado" class="fixo"></div>
  </section>

</div>

<dialog id="dlg"><div class="dlg" id="dlgConteudo"></div></dialog>
<dialog id="dlgPergunta" class="peq"><div class="dlg" id="perguntaConteudo"></div></dialog>

<script src="<?= assetUrl('/js/marcos-estudos.js') ?>"></script>
</body>
</html>
