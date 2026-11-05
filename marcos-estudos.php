<?php
/**
 * ESTUDOS DE JOGADORES — a base de dados do Marcos, só do dono.
 *
 * Todo jogador que já passou pela FBA, com a carreira inteira: pico, pico
 * sustentado (ignorando o 99 de uma temporada só), fim de carreira, estatísticas,
 * FG%, letras, prêmios e tendências. Quatro abas:
 *
 *   ELITE · ativos         quem está hoje nos times da ELITE
 *   Outras ligas · ativos  NEXT, RISE e ROOKIE
 *   Histórico completo     todo mundo, inclusive quem já saiu dos elencos
 *   Estudo por nome        todas as "vidas" de um mesmo jogador real (os
 *                          "Kirilenkos") e a média delas
 *
 * Clicar numa linha abre a carreira temporada a temporada.
 *
 * ── SÓ POR LINK, E SÓ PRO DONO ────────────────────────────────────────
 *
 * Como o tendencys-elite.php: não entra em menu nenhum, e só passa quem está
 * logado com o e-mail do dono (a mesma trava do marcos-dev.php). Só leitura:
 * nada aqui grava no banco.
 *
 * @see backend/estudos_jogadores.php  as contas e o que é "pico sustentado"
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
  --font:'Montserrat',system-ui,sans-serif;--num:'Oswald',sans-serif;--radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
[hidden]{display:none !important}
body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;padding:22px 18px 60px}
.wrap{max-width:1600px;margin:0 auto}
a{color:inherit}

.topo{display:flex;align-items:flex-start;justify-content:space-between;gap:14px;flex-wrap:wrap;margin-bottom:16px}
h1{font-size:24px;font-weight:800;line-height:1.15}
h1 i{color:var(--red)}
.sub{font-size:13px;color:var(--text-2);margin-top:4px;max-width:780px;line-height:1.55}
.voltar{display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border-radius:10px;border:1px solid var(--border-md);
  color:var(--text-2);text-decoration:none;font-size:12.5px;font-weight:600}
.voltar:hover{border-color:var(--red);color:var(--red)}

/* ── As abas ───────────────────────────────────────────────────────── */
.abas{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px;border-bottom:1px solid var(--border);padding-bottom:10px}
.aba{padding:9px 15px;border-radius:10px;border:1px solid var(--border);background:var(--panel);color:var(--text-2);
  font:inherit;font-size:13px;font-weight:700;cursor:pointer}
.aba:hover{color:var(--text)}
.aba.on{background:var(--red-soft);border-color:color-mix(in srgb,var(--red) 40%,transparent);color:var(--red)}
.aba small{font-weight:600;opacity:.75;margin-left:4px}

/* ── Filtros ───────────────────────────────────────────────────────── */
.filtros{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
.busca,.filtros select,.filtros input[type=number],.campo{padding:9px 13px;border-radius:11px;border:1px solid var(--border-md);
  background:var(--panel-2);color:var(--text);font:inherit;font-size:13px}
.busca{flex:1;min-width:190px;max-width:300px}
.filtros input[type=number]{width:128px}
.busca:focus,.filtros select:focus,.filtros input:focus,.campo:focus{outline:none;border-color:var(--red)}
.filtros .ligado{border-color:var(--red);color:var(--red)}
.marca{display:inline-flex;align-items:center;gap:6px;font-size:12.5px;color:var(--text-2);cursor:pointer;user-select:none}
.btn{display:inline-flex;align-items:center;gap:7px;padding:9px 14px;border-radius:11px;border:1px solid var(--border-md);
  background:var(--panel-2);color:var(--text);font:inherit;font-size:12.5px;font-weight:600;cursor:pointer;text-decoration:none}
.btn:hover{border-color:var(--red)}
.conta{font-size:12.5px;color:var(--text-2);margin-left:auto}
.conta b{color:var(--text)}

/* ── A tabela: rola pro lado com o jogador grudado ─────────────────── */
.tabela{overflow:auto;max-height:70vh;border:1px solid var(--border);border-radius:var(--radius);background:var(--panel)}
table{border-collapse:separate;border-spacing:0;width:max-content;min-width:100%}
th,td{padding:8px 10px;font-size:12.5px;line-height:1.3;white-space:nowrap;border-bottom:1px solid var(--border)}
thead th{position:sticky;top:0;z-index:3;background:var(--panel-3);font-size:10.5px;letter-spacing:.4px;text-transform:uppercase;
  color:var(--text-2);font-weight:700;text-align:center;cursor:pointer;user-select:none}
thead th:hover{color:var(--text)}
thead th.ord{color:var(--red)}
thead th.ord::after{content:' ▾';font-size:9px}
thead th.ord.asc::after{content:' ▴'}
tbody tr{cursor:pointer}
tbody tr:hover td{background:var(--panel-2)}
.col-jog{position:sticky;left:0;z-index:2;background:var(--panel);min-width:200px;font-weight:600;text-align:left}
thead .col-jog{z-index:4;background:var(--panel-3)}
tbody tr:hover .col-jog{background:var(--panel-2)}
.col-jog small{display:block;font-weight:500;font-size:11px;color:var(--text-3)}
.n{font-family:var(--num);text-align:center;font-size:13.5px}
.n.fraco{color:var(--text-3)}
.n.alto{color:#86efac}
.n.top{color:#fbbf24}
.flag{color:var(--amber);font-size:11px;margin-left:3px}
.tag{display:inline-block;font-size:10px;font-weight:800;padding:1px 6px;border-radius:999px;background:var(--panel-3);color:var(--text-2);margin-left:5px}
.tag.lenda{background:rgba(245,158,11,.14);color:var(--amber)}
.tag.fora{background:rgba(255,255,255,.05);color:var(--text-3)}
.pg{display:flex;gap:8px;align-items:center;justify-content:center;margin-top:12px;font-size:12.5px;color:var(--text-2)}
.nada{padding:30px 18px;text-align:center;color:var(--text-2);font-size:13.5px}
.rodape{margin-top:14px;font-size:12px;color:var(--text-3);line-height:1.6;max-width:900px}

/* ── Cartões de números ───────────────────────────────────────────── */
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(118px,1fr));gap:9px;margin:12px 0 16px}
.card{background:var(--panel);border:1px solid var(--border);border-radius:11px;padding:10px 12px}
.card b{font-family:var(--num);font-size:20px;display:block;line-height:1.05}
.card span{font-size:10.5px;color:var(--text-2);font-weight:600;text-transform:uppercase;letter-spacing:.3px}
.card small{display:block;font-size:10.5px;color:var(--text-3);margin-top:2px}
.sec{font-size:12px;font-weight:800;text-transform:uppercase;letter-spacing:.8px;color:var(--text-2);margin:18px 0 8px}
.bloco{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);padding:14px 16px;margin-bottom:14px;overflow-x:auto}
.bloco table{width:100%}
.bloco th{position:static;cursor:default;background:none}
.bloco td,.bloco th{text-align:center}
.bloco td:first-child,.bloco th:first-child{text-align:left}

.letras{display:flex;gap:6px;flex-wrap:wrap}
.letra{background:var(--panel-3);border:1px solid var(--border);border-radius:8px;padding:5px 9px;font-size:11px;color:var(--text-2);text-align:center;min-width:54px}
.letra b{display:block;font-family:var(--num);font-size:16px;color:var(--text);line-height:1.1}
.letra.a b{color:#86efac}.letra.b b{color:#93c5fd}.letra.c b{color:#cbd5e1}.letra.d b,.letra.f b{color:#fca5a5}

/* ── Estudo por nome ──────────────────────────────────────────────── */
.escolha{display:flex;gap:9px;flex-wrap:wrap;align-items:center;margin-bottom:10px}
.chips{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:14px}
.chip{padding:5px 11px;border-radius:999px;border:1px solid var(--border);background:var(--panel);color:var(--text-2);
  font:inherit;font-size:12px;font-weight:600;cursor:pointer}
.chip:hover{border-color:var(--red);color:var(--text)}
.chip small{color:var(--text-3);margin-left:4px}
.explica{font-size:12px;color:var(--text-3);line-height:1.6;margin-bottom:14px;max-width:900px}
.explica b{color:var(--text-2)}
.grafico{width:100%;height:auto;display:block}
.leg{display:flex;gap:12px;flex-wrap:wrap;font-size:11.5px;color:var(--text-2);margin-top:6px}
.leg i{display:inline-block;width:14px;height:3px;border-radius:2px;margin-right:5px;vertical-align:middle}
tr.fora-media td{opacity:.5}

/* ── O popup da carreira ──────────────────────────────────────────── */
/* margin:auto de volta: o reset `*{margin:0}` lá em cima apaga a centralização do <dialog>. */
dialog{border:1px solid var(--border-md);border-radius:16px;background:var(--panel);color:var(--text);
  width:min(1100px,96vw);max-height:92vh;padding:0;overflow:hidden;margin:auto}
dialog::backdrop{background:rgba(0,0,0,.72)}
.dlg{display:flex;flex-direction:column;max-height:92vh}
.dlg-cab{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;padding:16px 20px 10px}
.dlg-cab h2{font-size:20px;font-weight:800;line-height:1.15}
.dlg-cab p{font-size:12.5px;color:var(--text-2);margin-top:3px}
.dlg-corpo{overflow:auto;padding:0 20px 20px}
.fechar{background:none;border:1px solid var(--border-md);color:var(--text-2);border-radius:9px;padding:6px 10px;cursor:pointer;font:inherit}
.fechar:hover{border-color:var(--red);color:var(--red)}
.tend{display:grid;grid-template-columns:repeat(auto-fill,minmax(98px,1fr));gap:6px}
.tend div{background:var(--panel-3);border-radius:8px;padding:6px 9px;font-size:11px;color:var(--text-2)}
.tend div b{display:block;font-family:var(--num);font-size:16px;color:var(--text)}
.carga{padding:40px;text-align:center;color:var(--text-2)}

@media (max-width:640px){
  body{padding:14px 10px 50px}
  .conta{margin-left:0;width:100%}
  .busca{max-width:none}
}
</style>
</head>
<body>
<div class="wrap">

  <div class="topo">
    <div>
      <h1><i class="bi bi-database"></i> Estudos de Jogadores</h1>
      <p class="sub">A base de dados de todo jogador que já passou pela FBA: carreira temporada a temporada, pico,
         pico <b>sustentado</b> (sem o 99 de uma temporada só), fim de carreira, estatísticas, FG%, letras, prêmios e
         tendências. Página só sua, sem link em menu. Clique numa linha pra abrir a carreira.</p>
    </div>
    <a class="voltar" href="/dashboard.php"><i class="bi bi-arrow-left"></i> Voltar</a>
  </div>

  <div class="abas" id="abas">
    <button class="aba on" data-aba="elite">ELITE · ativos<small id="nElite"></small></button>
    <button class="aba" data-aba="outras">Outras ligas · ativos<small id="nOutras"></small></button>
    <button class="aba" data-aba="historico">Histórico completo<small id="nHist"></small></button>
    <button class="aba" data-aba="nome"><i class="bi bi-people"></i> Estudo por nome</button>
  </div>

  <!-- ABAS DE LISTA -->
  <section id="painelLista">
    <div class="filtros" id="filtros">
      <input class="busca" id="fBusca" type="search" placeholder="Buscar jogador ou time…" autocomplete="off">
      <select id="fPos"><option value="">Posição</option><option>PG</option><option>SG</option><option>SF</option><option>PF</option><option>C</option></select>
      <select id="fLiga"><option value="">Liga</option><option>ELITE</option><option>NEXT</option><option>RISE</option><option>ROOKIE</option></select>
      <input id="fOvr" type="number" min="1" max="99" placeholder="OVR mín.">
      <input id="fPico" type="number" min="1" max="99" placeholder="Pico sust. mín.">
      <label class="marca"><input type="checkbox" id="fCurtas"> ocultar vidas de 1 temporada</label>
      <label class="marca"><input type="checkbox" id="fStats"> só com estatística</label>
      <label class="marca" id="lAtivos"><input type="checkbox" id="fAtivos"> só quem está num elenco</label>
      <button class="btn" id="bLimpar" hidden><i class="bi bi-x-lg"></i> Limpar</button>
      <button class="btn" id="bCsv"><i class="bi bi-download"></i> Baixar CSV</button>
      <span class="conta" id="conta"></span>
    </div>
    <div class="tabela"><table id="tabela"><thead id="cab"></thead><tbody id="corpo"></tbody></table></div>
    <div class="pg" id="paginacao"></div>
    <p class="rodape">
      <b>Pico</b> é o maior OVR que o jogador teve. <b>Pico sust.</b> é o maior OVR que ele <em>segurou por duas
      temporadas seguidas</em> — um 99 isolado entre dois 85 vira 85. O <span class="flag">⚑</span> marca o pico de uma
      temporada só. <b>Fim</b> é o OVR da última temporada registrada e <b>Queda</b> é o pico sustentado menos o fim.
      Estatísticas são médias ponderadas por jogos; só existem pra parte das temporadas (e o FG% some nas primeiras).
      Os picos de estatística só contam temporadas com 20 jogos ou mais.
    </p>
  </section>

  <!-- ABA ESTUDO POR NOME -->
  <section id="painelNome" hidden>
    <p class="explica">A FBA refaz as classes a cada sprint, então o mesmo jogador real aparece várias vezes — cada uma é uma
      <b>vida</b> com a sua carreira (o Kirilenko tem 5). Escolha um nome pra ver todas as vidas e a média delas.
      As médias usam só vidas com pelo menos <b>N temporadas</b> (padrão 3): vida de uma temporada só costuma ser uma foto
      isolada, e um 99 desses puxaria o pico médio pra um número que ninguém sustentou. As vidas curtas continuam na lista, apagadas.</p>
    <div class="escolha">
      <input class="campo busca" id="nomeBusca" list="listaNomes" placeholder="Nome do jogador (ex.: Andrei Kirilenko)" autocomplete="off">
      <datalist id="listaNomes"></datalist>
      <label class="marca">Vidas com pelo menos
        <select class="campo" id="nomeMin"><option>1</option><option>2</option><option selected>3</option><option>4</option><option>5</option><option>6</option><option>8</option></select>
        temporadas
      </label>
      <button class="btn" id="nomeIr"><i class="bi bi-search"></i> Estudar</button>
    </div>
    <div class="sec" style="margin-top:4px">Nomes com mais vidas</div>
    <div class="chips" id="nomeChips"></div>
    <div id="nomeResultado"></div>
  </section>

</div>

<dialog id="dlg"><div class="dlg" id="dlgConteudo"></div></dialog>

<script src="<?= assetUrl('/js/marcos-estudos.js') ?>"></script>
</body>
</html>
