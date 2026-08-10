<?php
/**
 * Guia do Admin — RESTRITO. Só entra quem já é admin.
 *
 * Diferente de guia.php (público, para GMs), este descreve o painel de
 * administração: permissões, abas, cards de ação e o que cada um faz.
 * O acesso é o mesmo do admin.php: admin geral, admin de liga ou admin
 * do Games. Quem não é nada disso volta pro dashboard.
 *
 * Ao criar ou renomear um card do admin, atualize a seção daqui.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
requireAuth();

$user = getUserSession();
$pdo = db();

$isGlobalAdmin = ($user['user_type'] ?? 'jogador') === 'admin';
$isGamesAdmin  = hasGamesAdminAccess($pdo, (int)$user['id']);
$adminLeagues  = getAdminLeagues($pdo, (int)$user['id']);

// Mesma porta do admin.php: sem nenhum tipo de acesso admin, não entra.
if (!$isGlobalAdmin && empty($adminLeagues) && !$isGamesAdmin) {
    header('Location: /dashboard.php');
    exit;
}

$papel = $isGlobalAdmin
    ? 'Admin geral'
    : (!empty($adminLeagues) ? 'Admin de liga (' . implode(', ', $adminLeagues) . ')' : 'Admin do Games');

$secoes = [
    ['id' => 'permissoes', 'titulo' => 'Quem enxerga o quê'],
    ['id' => 'navegacao',  'titulo' => 'Como o painel é organizado'],
    ['id' => 'liga',       'titulo' => 'A aba de uma liga'],
    ['id' => 'time',       'titulo' => 'Dentro de um time'],
    ['id' => 'gestao',     'titulo' => 'A aba Gestão'],
    ['id' => 'cap',        'titulo' => 'Controle de Cap (ELITE)'],
    ['id' => 'cuidado',    'titulo' => 'O que exige cuidado'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<title>Guia do Admin · FBA Manager</title>
<meta name="robots" content="noindex, nofollow">
<meta name="theme-color" content="#fc0025">
<link rel="icon" href="/img/fba-logo.png">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025; --red-soft:color-mix(in srgb,var(--red) 10%,transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.07); --border-md:rgba(255,255,255,.12);
  --text:#f0f0f3; --text-2:#9a9aa4; --text-3:#8d8d97;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6; --purple:#a855f7; --cyan:#06b6d4;
  --radius:14px; --font:'Montserrat',system-ui,sans-serif; --maxw:860px;
  --red-ink:var(--red); --amber-ink:var(--amber); --green-ink:var(--green); --purple-ink:var(--purple);
}
:root[data-theme="light"]{
  --bg:#f7f8fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e4e7ef; --border-md:#d5dae6;
  --text:#111217; --text-2:#525a68; --text-3:#586170;
  /* escurecidos: em texto pequeno as cores da marca não passam 4.5:1 no claro */
  --red-ink:#c2001d; --amber-ink:#6b4000; --green-ink:#0f6130; --purple-ink:#5b21b6;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.65;-webkit-font-smoothing:antialiased}

.topo{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 88%,transparent);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
.topo-in{max-width:var(--maxw);margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:12px}
.topo-logo{display:flex;align-items:center;gap:9px;font-weight:900;color:var(--text);text-decoration:none;font-size:15px}
.topo-logo img{height:26px}
.topo-acoes{margin-left:auto;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;border-radius:9px;padding:7px 14px;font-size:13px;
  font-weight:700;text-decoration:none;border:1px solid var(--border-md);color:var(--text);
  background:var(--panel-2);cursor:pointer;transition:all .18s}
.btn:hover{border-color:var(--red);color:var(--red-ink)}
.btn-icon{padding:7px 9px}

.wrap{max-width:var(--maxw);margin:0 auto;padding:0 20px 90px}
.hero{padding:52px 0 10px}
.hero .kicker{font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;color:var(--red-ink);margin-bottom:12px}
.hero h1{font-size:clamp(28px,5.5vw,42px);font-weight:900;letter-spacing:-1.1px;line-height:1.12;text-wrap:balance;margin-bottom:14px}
.hero p{font-size:17px;color:var(--text-2);max-width:62ch}
.voce{display:inline-flex;align-items:center;gap:8px;margin-top:16px;padding:8px 14px;border-radius:10px;
  background:var(--panel);border:1px solid var(--border);font-size:13px;color:var(--text-2)}
.voce b{color:var(--text)}

.indice{margin:32px 0 8px;padding:18px 20px;background:var(--panel);border:1px solid var(--border);border-radius:var(--radius)}
.indice-t{font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);margin-bottom:12px}
.indice ol{list-style:none;counter-reset:s;display:grid;gap:2px}
.indice li{counter-increment:s}
.indice a{display:flex;gap:10px;align-items:baseline;padding:6px 0;color:var(--text);text-decoration:none;font-weight:600;font-size:14px}
.indice a::before{content:counter(s,decimal-leading-zero);color:var(--text-3);font-size:11px;font-weight:800;
  font-variant-numeric:tabular-nums;min-width:20px}
.indice a:hover{color:var(--red-ink)}

section{padding-top:50px;scroll-margin-top:64px}
h2{font-size:25px;font-weight:900;letter-spacing:-.6px;margin-bottom:6px;text-wrap:balance}
h2 .parte{color:var(--red-ink);font-size:13px;font-weight:800;display:block;letter-spacing:1.2px;text-transform:uppercase;margin-bottom:6px}
.sub{color:var(--text-2);margin-bottom:20px;max-width:64ch}
h3{font-size:16px;font-weight:800;margin:26px 0 8px;letter-spacing:-.2px;display:flex;align-items:center;gap:8px}
h3 .h3-ico{width:26px;height:26px;border-radius:8px;display:grid;place-items:center;font-size:13px;flex-shrink:0}
p{margin-bottom:12px;color:var(--text-2)}
p strong,li strong,td strong{color:var(--text);font-weight:700}
ul,ol{margin:0 0 14px 20px;color:var(--text-2)}
li{margin-bottom:6px}
a{color:var(--red-ink)}

/* lista de cards de acao */
.cards{display:grid;gap:8px;margin:14px 0}
.card-a{display:flex;gap:13px;align-items:flex-start;padding:13px 15px;background:var(--panel);
  border:1px solid var(--border);border-radius:11px}
.card-a .ico{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;font-size:14px;flex-shrink:0}
.card-a .txt{min-width:0}
.card-a .nome{font-size:14px;font-weight:800;color:var(--text);margin-bottom:2px}
.card-a .desc{font-size:13.5px;color:var(--text-2);line-height:1.55}
.card-a .desc b{color:var(--text)}

.selo{font-size:9.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;padding:2px 7px;
  border-radius:999px;border:1px solid;white-space:nowrap;margin-left:7px;vertical-align:middle}
.selo-geral{color:var(--red-ink);border-color:color-mix(in srgb,var(--red) 35%,transparent);background:var(--red-soft)}
.selo-rookie{color:var(--purple-ink);border-color:color-mix(in srgb,var(--purple) 35%,transparent);background:color-mix(in srgb,var(--purple) 11%,transparent)}
.selo-elite{color:var(--amber-ink);border-color:color-mix(in srgb,var(--amber) 35%,transparent);background:color-mix(in srgb,var(--amber) 11%,transparent)}

.nota{border-left:3px solid var(--red);background:var(--panel-2);padding:13px 16px;border-radius:0 10px 10px 0;margin:14px 0;font-size:14.5px}
.nota.amber{border-left-color:var(--amber)}
.nota.green{border-left-color:var(--green)}
.nota p:last-child{margin-bottom:0}

.tabela-wrap{overflow-x:auto;margin:14px 0;border:1px solid var(--border);border-radius:12px}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:400px}
th{text-align:left;padding:10px 14px;font-size:10px;font-weight:800;letter-spacing:.8px;text-transform:uppercase;
  color:var(--text-3);background:var(--panel-2);white-space:nowrap}
td{padding:10px 14px;border-top:1px solid var(--border);color:var(--text-2);vertical-align:top}
td:first-child{color:var(--text);font-weight:600;white-space:nowrap}

.rodape{margin-top:58px;padding-top:22px;border-top:1px solid var(--border);font-size:13px;color:var(--text-3);
  display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.rodape a{color:var(--text-2);text-decoration:none}
.rodape a:hover{color:var(--red-ink)}

@media (max-width:560px){
  .hero{padding-top:34px}
  .card-a{padding:12px 13px;gap:11px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
html{scroll-behavior:smooth}
</style>
</head>
<body>

<header class="topo">
  <div class="topo-in">
    <a class="topo-logo" href="/admin.php"><img src="/img/fba-logo.png" alt=""> FBA Manager</a>
    <div class="topo-acoes">
      <button class="btn btn-icon" id="btnTema" title="Alternar tema" aria-label="Alternar tema"><i class="bi bi-circle-half"></i></button>
      <a class="btn" href="/guia.php"><i class="bi bi-book-half"></i> Guia do GM</a>
      <a class="btn" href="/admin.php"><i class="bi bi-arrow-left"></i> Admin</a>
    </div>
  </div>
</header>

<div class="wrap">

  <div class="hero">
    <div class="kicker">Guia do Admin</div>
    <h1>O painel de administração, card por card</h1>
    <p>O que cada aba e cada botão do <strong>/admin.php</strong> faz, e o que muda conforme o seu nível de acesso. Para o guia voltado ao GM — regras da liga, CAP, draft — veja o <a href="/guia.php">Guia do GM</a>.</p>
    <div class="voce"><i class="bi bi-person-badge-fill" style="color:var(--red-ink)"></i> Seu acesso: <b><?= htmlspecialchars($papel) ?></b></div>
  </div>

  <nav class="indice">
    <div class="indice-t">Neste guia</div>
    <ol>
      <?php foreach ($secoes as $s): ?>
      <li><a href="#<?= $s['id'] ?>"><?= htmlspecialchars($s['titulo']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <!-- ═══ 1. PERMISSÕES ═══ -->
  <section id="permissoes">
    <h2><span class="parte">Parte 1</span>Quem enxerga o quê</h2>
    <p class="sub">Existem três níveis de acesso, e eles mudam o que aparece no painel. Se um card descrito aqui não existe pra você, quase sempre é isso.</p>

    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Nível</th><th>O que enxerga</th></tr></thead>
        <tbody>
          <tr>
            <td>Admin geral</td>
            <td>Todas as ligas, a aba Gestão e as ações restritas — <strong>Force Trade</strong> e <strong>Site Admin</strong>. É o nível mais alto.</td>
          </tr>
          <tr>
            <td>Admin de liga</td>
            <td>Só as ligas em que foi cadastrado. Tem todos os cards daquela liga, mas não as ações restritas ao admin geral.</td>
          </tr>
          <tr>
            <td>Admin do Games</td>
            <td>Entra no painel, mas <strong>só enxerga a aba Games</strong>. Não vê ligas nem Gestão.</td>
          </tr>
        </tbody>
      </table>
    </div>

    <p>O vínculo de admin de liga fica numa tabela própria (<code>league_admins</code>), separada do tipo do usuário. Um detalhe que costuma confundir: quando alguém vira <strong>admin geral</strong> e ainda não tem nenhuma liga cadastrada, o sistema atribui as quatro automaticamente no primeiro acesso.</p>

    <div class="nota">
      <p>Quem não tem nenhum desses três acessos é redirecionado para o dashboard ao tentar abrir <code>/admin.php</code>. Este guia usa a mesma regra.</p>
    </div>
  </section>

  <!-- ═══ 2. NAVEGAÇÃO ═══ -->
  <section id="navegacao">
    <h2><span class="parte">Parte 2</span>Como o painel é organizado</h2>
    <p class="sub">Duas camadas: as abas no topo escolhem o contexto, e dentro de cada uma ficam os cards de ação.</p>

    <ul>
      <li><strong>Uma aba por liga</strong> (ELITE, NEXT, RISE, ROOKIE) — tudo que é operação de temporada: mercado, draft, pontuação, punições.</li>
      <li><strong>Gestão</strong> — o que é transversal: usuários, times, ferramentas gerais.</li>
      <li><strong>Games</strong> — a área dos joguinhos.</li>
    </ul>
    <p>Dentro de uma liga, clicar num time abre a tela dele. O caminho fica visível no <strong>breadcrumb</strong> do topo, e o botão <strong>Voltar</strong> sobe um nível.</p>
  </section>

  <!-- ═══ 3. ABA DA LIGA ═══ -->
  <section id="liga">
    <h2><span class="parte">Parte 3</span>A aba de uma liga</h2>
    <p class="sub">A tela mais usada no dia a dia. De cima para baixo: configurações, busca rápida, cards de ação e a lista de times.</p>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-sliders"></i></span> Configurações</h3>
    <p>As chaves da liga, editáveis direto na aba (não ficam escondidas atrás de um card, justamente por serem consultadas o tempo todo). Depois de mexer, use <strong>Salvar</strong>:</p>
    <ul>
      <li><strong>CAP Mínimo</strong> e <strong>CAP Máximo</strong> — a faixa em que o elenco precisa ficar.</li>
      <li><strong>Máx. Trocas por temporada</strong> — o limite de trades de cada time.</li>
      <li><strong>Temporadas por Sprint</strong> — quantas temporadas formam um sprint.</li>
      <li><strong>Trades</strong> — ativas ou bloqueadas para toda a liga.</li>
      <li><strong>Free Agency</strong> — liga e desliga o mercado de agentes livres.</li>
    </ul>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-search"></i></span> Busca Rápida</h3>
    <p>Dois modos: <strong>Jogador</strong>, para achar alguém por nome em qualquer time da liga, e <strong>Pick</strong>, para listar as escolhas de um time. Serve pra responder na hora aquele "quem tem a pick de 2027 do fulano?".</p>

    <h3><span class="h3-ico" style="background:rgba(59,130,246,.14);color:var(--blue)"><i class="bi bi-shop-window"></i></span> Mercado</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(59,130,246,.12);color:#3b82f6"><i class="bi bi-arrow-left-right"></i></div>
        <div class="txt"><div class="nome">Trades</div><div class="desc">As trocas da liga: acompanhar, aprovar e resolver o que estiver pendente.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="bi bi-people-fill"></i></div>
        <div class="txt"><div class="nome">Free Agency</div><div class="desc">Administra o mercado de agentes livres da liga.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(239,68,68,.12);color:#ef4444"><i class="bi bi-hammer"></i></div>
        <div class="txt"><div class="nome">Leilão</div><div class="desc">Cria e conduz os leilões de jogadores.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(239,68,68,.12);color:#ef4444"><i class="bi bi-person-dash-fill"></i></div>
        <div class="txt"><div class="nome">Dispensas</div><div class="desc">Os cortes de elenco da liga e os limites de cada time.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-trophy-fill"></i></span> Draft</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.12);color:#a855f7"><i class="bi bi-trophy-fill"></i></div>
        <div class="txt"><div class="nome">Draft</div><div class="desc">Conduz o draft da temporada. Com um draft em andamento, a aba mostra de quem é a vez e o cronômetro da pick.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.08);color:#a855f7"><i class="bi bi-archive-fill"></i></div>
        <div class="txt"><div class="nome">Banco de Classes</div><div class="desc">O acervo de classes de draft — de onde saem os calouros de cada temporada.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(16,185,129,.14);color:#10b981"><i class="bi bi-graph-up-arrow"></i></span> Temporada e pontuação</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(6,182,212,.12);color:#06b6d4"><i class="bi bi-bar-chart-steps"></i></div>
        <div class="txt"><div class="nome">Pontuação por Time</div><div class="desc">Ajusta a pontuação time a time.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(16,185,129,.12);color:#10b981"><i class="bi bi-clipboard-data-fill"></i></div>
        <div class="txt"><div class="nome">Pontuação</div><div class="desc">O lançamento oficial da temporada — é o que alimenta a Tabela que os GMs veem.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(234,179,8,.12);color:#eab308"><i class="bi bi-star-half"></i></div>
        <div class="txt"><div class="nome">Prêmios Estendidos</div><div class="desc">As premiações além das principais.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(59,130,246,.12);color:#3b82f6"><i class="bi bi-alarm"></i></div>
        <div class="txt"><div class="nome">Agendador de Fases</div><div class="desc">Programa as fases da temporada com data e hora, em vez de abrir e fechar tudo na mão.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(244,63,94,.14);color:#f43f5e"><i class="bi bi-shield-exclamation"></i></span> Disciplina</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="bi bi-shield-check"></i></div>
        <div class="txt"><div class="nome">FBA SERASA</div><div class="desc">O histórico de quem deixa trade parada. Avisos são gerados <b>automaticamente</b> para propostas pendentes há mais de 24 h — não precisa lançar na mão.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(244,63,94,.12);color:#f43f5e"><i class="bi bi-exclamation-triangle-fill"></i></div>
        <div class="txt"><div class="nome">Punições</div><div class="desc">Aplica e acompanha punições de time, com efeito e alcance definidos por temporada.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(20,184,166,.14);color:#14b8a6"><i class="bi bi-three-dots"></i></span> Outros</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(20,184,166,.12);color:#14b8a6"><i class="bi bi-clipboard2-pulse"></i></div>
        <div class="txt"><div class="nome">Tática</div><div class="desc">A janela de edição de táticas e o que cada time escolheu.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="bi bi-coin"></i></div>
        <div class="txt"><div class="nome">Moedas</div><div class="desc">Saldo de moedas dos usuários.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.12);color:#a855f7"><i class="bi bi-clipboard-plus"></i></div>
        <div class="txt"><div class="nome">Inscrição ROOKIE <span class="selo selo-rookie">Só na ROOKIE</span></div>
        <div class="desc">O link único de cadastro. Um link só serve para várias pessoas, então dá pra jogar no grupo. Para chamar alguém específico, use <b>Interessados</b> na Gestão.</div></div></div>
      <div class="card-a"><div class="ico" style="background:color-mix(in srgb,var(--red) 12%,transparent);color:var(--red)"><i class="bi bi-lightning-fill"></i></div>
        <div class="txt"><div class="nome">Force Trade <span class="selo selo-geral">Admin geral</span></div>
        <div class="desc">Executa uma troca sem passar pelo aceite dos dois lados. É atalho de correção, não de operação.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-people-fill"></i></span> Times</h3>
    <p>A lista de times da liga fecha a aba. Clicar num deles abre a tela do time.</p>
  </section>

  <!-- ═══ 4. DENTRO DE UM TIME ═══ -->
  <section id="time">
    <h2><span class="parte">Parte 4</span>Dentro de um time</h2>
    <p class="sub">É aqui que se corrige o que está errado num elenco. Tudo o que muda dados de verdade passa por esta tela.</p>

    <ul>
      <li><strong>Editar o time</strong> — nome, cidade e dados da franquia.</li>
      <li><strong>Contador de trocas</strong> — corrige o número de trades usadas quando ele sai do lugar.</li>
      <li><strong>Jogadores</strong> — adicionar, editar e remover. É por aqui que o admin marca a <a href="/guia.php#cap">LENDA</a> de qualquer time.</li>
      <li><strong>Picks</strong> — adicionar, editar, remover e <strong>mover</strong> uma pick de um time para outro.</li>
    </ul>

    <div class="nota amber">
      <p>Editar por aqui <strong>não gera trade nem registro de negociação</strong> — o dado simplesmente muda. Para uma troca de verdade entre dois times, use Trades (ou Force Trade), que deixa histórico.</p>
    </div>
  </section>

  <!-- ═══ 5. GESTÃO ═══ -->
  <section id="gestao">
    <h2><span class="parte">Parte 5</span>A aba Gestão</h2>
    <p class="sub">O que não pertence a uma liga só: pessoas, acessos e ferramentas gerais.</p>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-table"></i></span> A tabela de usuários</h3>
    <p>Filtrada pela liga selecionada nos botões do topo, com o total de times de cada uma. Cada linha traz <strong>Usuário</strong>, <strong>Time</strong>, <strong>Ligas Admin</strong> e <strong>Admin Geral</strong> — ou seja, é aqui que se concede e se tira acesso de administração.</p>
    <p>Nas ações de cada linha dá pra <strong>editar</strong> (nome e cidade do time, liga do usuário), <strong>remover o time</strong> ou <strong>remover o usuário</strong>.</p>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-grid-1x2-fill"></i></span> Os cards</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="bi bi-person-plus-fill"></i></div>
        <div class="txt"><div class="nome">Adicionar GM</div><div class="desc">Cria um GM e o time dele direto, sem passar por convite.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(139,92,246,.12);color:#8b5cf6"><i class="bi bi-chat-left-dots-fill"></i></div>
        <div class="txt"><div class="nome">Ouvidoria</div><div class="desc">As mensagens que os GMs mandaram pela Ouvidoria.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(234,179,8,.12);color:#eab308"><i class="bi bi-award-fill"></i></div>
        <div class="txt"><div class="nome">Hall da Fama</div><div class="desc">Adiciona GMs (ativos ou inativos) e ajusta títulos por liga. Tem <b>filtro por liga</b> e <b>busca por GM ou time</b> — um mesmo GM pode ter linhas em ligas diferentes, e filtrando você vê só a linha daquela liga.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(236,72,153,.12);color:#ec4899"><i class="bi bi-record-circle"></i></div>
        <div class="txt"><div class="nome">Roletas</div><div class="desc">As roletas de sorteio.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.12);color:#a855f7"><i class="bi bi-clipboard-plus"></i></div>
        <div class="txt"><div class="nome">Inscrição ROOKIE</div><div class="desc">O mesmo link único de cadastro da aba ROOKIE, à mão aqui também.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.08);color:#a855f7"><i class="bi bi-shuffle"></i></div>
        <div class="txt"><div class="nome">Drafts Aleatórios</div><div class="desc">Drafts por sorteio, fora do fluxo da temporada. É o que se usa para montar uma liga nova ou distribuir marcas NBA.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="bi bi-dice-3-fill"></i></div>
        <div class="txt"><div class="nome">Loterias</div><div class="desc">As loterias que definem a ordem de escolha.</div></div></div>
      <div class="card-a"><div class="ico" style="background:color-mix(in srgb,var(--red) 12%,transparent);color:var(--red)"><i class="bi bi-newspaper"></i></div>
        <div class="txt"><div class="nome">The Pathetic</div><div class="desc">Edita o jornal da liga.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(6,182,212,.12);color:#06b6d4"><i class="bi bi-person-bounding-box"></i></div>
        <div class="txt"><div class="nome">Sincronizar Fotos NBA</div><div class="desc">Busca e atualiza as fotos dos jogadores. É demorado — dispare e deixe terminar.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="bi bi-person-lines-fill"></i></div>
        <div class="txt"><div class="nome">Interessados</div><div class="desc">A lista de espera de quem quer entrar. O contador no canto do card mostra quantos aguardam. Daqui sai o convite para alguém específico.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(56,189,248,.12);color:#38bdf8"><i class="bi bi-book-half"></i></div>
        <div class="txt"><div class="nome">Ver guia do usuário</div><div class="desc">Abre o <a href="/guia.php">Guia do GM</a> numa aba nova — a mesma página pública que você manda para quem chega.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(59,130,246,.12);color:#3b82f6"><i class="bi bi-globe2"></i></div>
        <div class="txt"><div class="nome">Site Admin <span class="selo selo-geral">Admin geral</span></div><div class="desc">Configurações do site, fora do escopo das ligas.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(34,197,94,.12);color:#22c55e"><i class="bi bi-emoji-smile-fill"></i></div>
        <div class="txt"><div class="nome">Disparar abraço <span class="selo selo-geral">Admin geral</span></div><div class="desc">Sorteia um GM e manda o abraço no grupo do WhatsApp na hora. O abraço já sai sozinho todo dia às 15h — este botão é pra quando você quer mandar fora de hora, e ele posta mesmo que o de hoje já tenha saído. A pessoa é marcada de verdade quando tem telefone no perfil.</div></div></div>
    </div>
  </section>

  <!-- ═══ 6. CAP ELITE ═══ -->
  <section id="cap">
    <h2><span class="parte">Parte 6</span>Controle de Cap <span class="selo selo-elite">Elite</span></h2>
    <p class="sub">Um card que existe só na aba ELITE, porque só ela usa salary cap em milhões.</p>
    <p>Traz a <strong>tabela de referência de OVR para salário</strong> e, ao lado, <strong>quantos jogadores ativos da ELITE existem em cada OVR</strong>. Serve para enxergar o efeito real de mexer na tabela antes de mexer: subir a faixa dos 88 é uma coisa quando há dois jogadores ali, e outra bem diferente quando há vinte.</p>
    <p>Lista também as <strong>lendas marcadas</strong> na liga. As regras de LENDA, rookie scale e bônus de lealdade estão explicadas no <a href="/guia.php#cap">Guia do GM</a> — aqui é só o painel de leitura.</p>
  </section>

  <!-- ═══ 7. CUIDADO ═══ -->
  <section id="cuidado">
    <h2><span class="parte">Parte 7</span>O que exige cuidado</h2>
    <p class="sub">As ações sem desfazer, reunidas num lugar só.</p>

    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Ação</th><th>Por que pensar duas vezes</th></tr></thead>
        <tbody>
          <tr><td>Remover usuário</td><td>Some com o acesso da pessoa. Confira antes se o time dela precisa ser transferido, e não apagado junto.</td></tr>
          <tr><td>Remover time</td><td>Leva junto o vínculo do elenco e das picks daquele time.</td></tr>
          <tr><td>Force Trade</td><td>Não passa pelo aceite dos dois lados. Combine antes com os GMs envolvidos.</td></tr>
          <tr><td>Editar jogador ou pick pela tela do time</td><td>Muda o dado sem gerar histórico de negociação — depois não dá pra reconstituir o que aconteceu.</td></tr>
          <tr><td>Mexer no CAP da liga</td><td>Vale para todos os times de uma vez. Pode deixar meia liga fora do cap de uma tacada.</td></tr>
          <tr><td>Bloquear Trades ou Free Agency</td><td>Afeta a liga inteira na hora. Avise antes.</td></tr>
        </tbody>
      </table>
    </div>

    <div class="nota green">
      <p>Uma coisa que <strong>não</strong> precisa de cuidado: o simulador de trocas do Salary Cap. Ele calcula e descarta — nenhum jogador muda de time.</p>
    </div>
  </section>

  <footer class="rodape">
    <span>FBA Manager · Guia do Admin</span>
    <a href="/admin.php">Voltar ao Admin</a>
    <a href="/guia.php">Guia do GM</a>
    <a href="#">Topo</a>
  </footer>

</div>

<script>
(function(){
  var btn = document.getElementById('btnTema');
  if (!btn) return;
  btn.addEventListener('click', function(){
    var atual = document.documentElement.dataset.theme === 'light' ? 'dark' : 'light';
    document.documentElement.dataset.theme = atual;
    try { localStorage.setItem('fba-theme', atual); } catch (e) {}
  });
})();
</script>
</body>
</html>
