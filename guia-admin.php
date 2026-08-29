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
    ['id' => 'ciclo',      'titulo' => 'O ciclo de uma temporada'],
    ['id' => 'loteria',    'titulo' => 'Loteria do draft, passo a passo'],
    ['id' => 'draft',      'titulo' => 'O draft, passo a passo'],
    ['id' => 'pontuacao',  'titulo' => 'Pontuação, passo a passo'],
    ['id' => 'avancar',    'titulo' => 'Avançar a temporada'],
    ['id' => 'mercado',    'titulo' => 'Dispensas, Free Agency e Leilão'],
    ['id' => 'moedas',     'titulo' => 'Moedas e a Loja'],
    ['id' => 'liga',       'titulo' => 'A aba de uma liga, card por card'],
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

/* PRINT DE TELA. A borda e a legenda separam a captura do texto — sem elas
   o print de um painel escuro encosta no fundo da página e some. */
figure{margin:16px 0 20px}
figure img{display:block;width:100%;height:auto;border:1px solid var(--border-md);border-radius:12px;
  background:var(--panel-2)}
figcaption{margin-top:8px;font-size:12.5px;color:var(--text-3);line-height:1.5}
figcaption b{color:var(--text-2)}

/* PASSO A PASSO. Numeração à esquerda, contínua dentro de cada bloco. */
.passos{list-style:none;counter-reset:passo;margin:16px 0 18px;padding:0;display:grid;gap:12px}
.passos li{counter-increment:passo;position:relative;padding-left:42px;margin:0;color:var(--text-2)}
.passos li::before{content:counter(passo);position:absolute;left:0;top:-1px;width:28px;height:28px;
  border-radius:9px;display:grid;place-items:center;font-size:12.5px;font-weight:800;
  background:var(--red-soft);color:var(--red-ink);border:1px solid color-mix(in srgb,var(--red) 30%,transparent);
  font-variant-numeric:tabular-nums}
.passos li b{color:var(--text)}

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
    <h1>Como tocar a liga, do sorteio ao fim da temporada</h1>
    <p>As <strong>Partes 3 a 9</strong> ensinam a fazer: o ciclo da temporada, a loteria, o draft, a pontuação, o mercado e a loja — com print de cada tela. Da <strong>Parte 10</strong> em diante é referência: cada aba e cada card do <strong>/admin.php</strong>, e o que muda conforme o seu acesso. Para o guia do GM — regras da liga, CAP, draft —, veja o <a href="/guia.php">Guia do GM</a>.</p>
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

    <figure>
      <img src="/img/guia/admin-liga.png" alt="A aba ELITE do painel: cabeçalho da liga, checklist da temporada e a grade de cards de ação." loading="lazy">
      <figcaption><b>A aba de uma liga.</b> No topo, os números da liga e o botão <b>Avançar Temporada</b>. No meio, o <b>Checklist da Temporada</b>. Embaixo, os cards de ação.</figcaption>
    </figure>
  </section>

  <!-- ═══ 3. O CICLO DA TEMPORADA ═══ -->
  <section id="ciclo">
    <h2><span class="parte">Parte 3</span>O ciclo de uma temporada</h2>
    <p class="sub">Antes de qualquer card isolado, é isto que importa: a ordem em que as coisas acontecem. O painel já traz esse roteiro pronto no <strong>Checklist da Temporada</strong>, no topo da aba de cada liga.</p>

    <p>O checklist não é enfeite — ele é a fonte da verdade sobre o que falta. Cada item fica <strong>verde</strong> quando está pronto e <strong>pendente</strong> quando não está, com a contagem quando faz sentido ("28 de 32 times atualizados"). Antes dele, o admin descobria o que faltava só na hora em que o <b>Avançar Temporada</b> travava.</p>

    <h3><span class="h3-ico" style="background:rgba(16,185,129,.14);color:#10b981"><i class="bi bi-list-check"></i></span> A ordem das coisas</h3>
    <ol class="passos">
      <li><b>Times atualizados</b> — os GMs atualizam os elencos. Enquanto faltar time, o resto anda mas o número fica aparecendo.</li>
      <li><b>Pontuação registrada — etapa 1</b> — prêmios individuais, NBA Cup, prêmios estendidos e a classificação final. <b>É isto que libera a loteria</b> e monta o chaveamento dos playoffs.</li>
      <li><b>Playoffs registrados</b> — o chaveamento é preenchido e a etapa 2 da pontuação fecha a conta.</li>
      <li><b>Loteria feita</b> — sorteia a ordem do draft. Só roda depois que a classificação existe, porque é dela que saem os grupos.</li>
      <li><b>Draft finalizado</b> — 1ª rodada pick a pick, 2ª rodada por preferências. Quem sobra vai para as Dispensas.</li>
      <li><b>Prêmios lançados</b> e <b>Sem trocas pendentes</b> — os dois são <span class="selo selo-elite">opcional</span>: avisam, mas não travam o avanço.</li>
      <li><b>Avançar Temporada</b> — fecha a temporada e abre a seguinte.</li>
    </ol>

    <div class="nota amber">
      <p><strong>A pontuação é o gargalo.</strong> Sem ela registrada, o avanço fica bloqueado e a loteria não tem de onde tirar os grupos. Quando algo parecer travado sem explicação, comece olhando este item.</p>
    </div>
  </section>

  <!-- ═══ 4. LOTERIA ═══ -->
  <section id="loteria">
    <h2><span class="parte">Parte 4</span>Loteria do draft, passo a passo</h2>
    <p class="sub">A loteria define a ordem de escolha do draft. Vale para as quatro ligas, no modelo <strong>3-2-1 anti-tanking</strong>: o pior time deixa de ser o favorito à Pick 1.</p>

    <figure>
      <img src="/img/guia/lottery.png" alt="Tela da Loteria do Draft, com a ordem já sorteada listada de 1 a 28 e o botão Teste a loteria no canto superior direito." loading="lazy">
      <figcaption><b>Loteria do Draft</b> (menu lateral → <b>Loteria</b>, ou o card <b>Loteria do Draft</b> na aba da liga). Aqui já sorteada: a ordem aparece pick a pick.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(245,158,11,.14);color:var(--amber)"><i class="bi bi-play-circle"></i></span> Como conduzir</h3>
    <ol class="passos">
      <li><b>Confira que a pontuação da etapa 1 está registrada.</b> É a classificação que separa quem entra na loteria de quem vai para o fim da fila. Sem ela, não sorteie.</li>
      <li><b>Abra a Loteria do Draft</b> pela aba da liga. A tela mostra a <b>Prévia</b>: quem entra, em que grupo e com que chance.</li>
      <li><b>Mostre as chances antes de revelar.</b> A tabela de probabilidades fica visível de propósito — todo mundo vê as chances antes de saber o resultado.</li>
      <li><b>Use "Teste a loteria" à vontade.</b> O botão no canto superior direito simula sem gravar nada. É o lugar de tirar dúvida.</li>
      <li><b>Sorteie.</b> A ordem inteira é decidida de uma vez no servidor — e só então você <b>revela pick por pick, no clique</b>. Dá para transmitir a revelação sem que ninguém (nem você) saiba o resultado antes.</li>
    </ol>

    <h3><span class="h3-ico" style="background:rgba(245,158,11,.14);color:var(--amber)"><i class="bi bi-percent"></i></span> As regras que você vai ter que explicar</h3>
    <p>Estas perguntas voltam toda temporada, então valem decoradas:</p>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Grupo</th><th>Bolinhas</th><th>Top 3 / Top 5</th></tr></thead>
        <tbody>
          <tr><td>3 piores recordes</td><td>2</td><td>16% / 28%</td></tr>
          <tr><td>4º ao 10º pior (fora do play-in)</td><td>3</td><td><strong>24% / 39%</strong> — a maior chance</td></tr>
          <tr><td>Eliminados no play-in</td><td>2</td><td>16% / 28%</td></tr>
          <tr><td>Derrotados no 7x8</td><td>1</td><td>8% / 15% — a menor chance</td></tr>
        </tbody>
      </table>
    </div>
    <ul>
      <li><strong>Quem entra:</strong> os 16 times fora do playoff disputam as 16 primeiras picks. Os 16 do playoff pegam as últimas, em ordem inversa de quão longe foram — <strong>o campeão escolhe por último</strong>.</li>
      <li><strong>Piso de proteção:</strong> os 3 piores não caem além da Pick 12; os demais da loteria podem cair até a Pick 16. Se o sorteio esbarrar na trava, o ajuste é aplicado e <strong>aparece listado</strong> — não é silencioso.</li>
      <li><strong>Por que o pior time não é o favorito:</strong> é o ponto do modelo. Quem tentou competir até o fim é mais premiado que quem afundou de propósito.</li>
    </ul>
  </section>

  <!-- ═══ 5. DRAFT ═══ -->
  <section id="draft">
    <h2><span class="parte">Parte 5</span>O draft, passo a passo</h2>
    <p class="sub">As duas rodadas funcionam de maneiras <strong>diferentes</strong>. A 1ª é pick a pick, na vez de cada um. A 2ª abre tudo de uma vez e resolve no fim do prazo. Confundir as duas é a fonte mais comum de confusão na liga.</p>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-diagram-3"></i></span> Como loteria, classe e draft se encaixam</h3>
    <p>São <strong>três peças separadas</strong>, e é normal confundir. Cada uma responde a uma pergunta diferente:</p>

    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Peça</th><th>Responde</th><th>Onde fica</th></tr></thead>
        <tbody>
          <tr><td>Loteria</td><td><strong>Quem escolhe, e em que ordem</strong></td><td>Card <b>Loteria do Draft</b></td></tr>
          <tr><td>Classe</td><td><strong>Quem está disponível</strong> para ser escolhido</td><td>Card <b>Banco de Classes</b></td></tr>
          <tr><td>Sessão de draft</td><td>Junta as duas e <strong>conduz o evento</strong></td><td>Card <b>Draft</b></td></tr>
        </tbody>
      </table>
    </div>

    <p>A ordem importa: <strong>a loteria vem primeiro</strong> (precisa da classificação), a classe pode ser preparada a qualquer momento, e a sessão de draft só faz sentido com as duas prontas. Uma classe sem loteria não tem para quem distribuir; uma loteria sem classe não tem quem distribuir.</p>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-archive-fill"></i></span> Banco de Classes: preparar os calouros</h3>
    <p>O banco guarda as classes prontas para reusar — cada uma é um nome e uma lista de jogadores. Elas <strong>não pertencem a uma temporada</strong>: ficam salvas até você usar.</p>

    <figure>
      <img src="/img/guia/classes-banco.png" alt="Banco de Classes de Draft, com as classes salvas por ano, número de jogadores e os botões Editar e Excluir." loading="lazy">
      <figcaption><b>Banco de Classes de Draft</b>. Cada linha traz o nome, <b>quantos jogadores</b> tem e quando foi criada. O botão <b>Nova Classe</b> fica no canto superior direito.</figcaption>
    </figure>

    <div class="nota amber">
      <p>Repare na contagem de jogadores: uma classe com <strong>0 jogadores</strong> existe mas está vazia, e importar ela no draft não traz ninguém. Confira esse número antes de usar.</p>
    </div>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Criar uma classe por CSV</h4>
    <ol class="passos">
      <li><b>Abra o Banco de Classes</b> pelo card da aba da liga e clique em <b>Nova Classe</b>.</li>
      <li><b>Baixe o modelo CSV</b> pelo botão da própria tela — assim você não erra o cabeçalho.</li>
      <li><b>Preencha as colunas obrigatórias:</b> <code>name</code>, <code>position</code>, <code>ovr</code> e <code>age</code>. Há uma quinta, opcional: <code>ordem</code>, que fixa a posição do jogador no board de disponíveis.</li>
      <li><b>Arraste o arquivo</b> na área de importação (ou clique para escolher).</li>
      <li><b>Confira a contagem</b> na lista do banco. Se veio 0, o arquivo não foi lido — quase sempre é o cabeçalho.</li>
    </ol>
    <p>Dá para montar sem CSV também, adicionando jogador por jogador — vale para classe pequena ou para corrigir uma que já existe, pelo <b>Editar</b>.</p>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Levar a classe para o draft</h4>
    <ol class="passos">
      <li><b>Crie a sessão de draft</b> no card <b>Draft</b> da liga.</li>
      <li><b>Abra a importação de jogadores</b> dentro da sessão.</li>
      <li><b>Escolha a classe no seletor "Usar classe do banco"</b> e clique em <b>Usar esta</b>. O pool da sessão é preenchido com ela.</li>
      <li>Se preferir, <b>importe um CSV direto</b> ali mesmo, sem passar pelo banco — serve para uma classe que você não vai reaproveitar.</li>
    </ol>

    <div class="nota">
      <p>Usar a classe <strong>copia</strong> os jogadores para a sessão. Mexer na classe do banco depois disso não altera um draft já montado, e o contrário também vale: escolher jogadores no draft não gasta a classe do banco.</p>
    </div>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-play-fill"></i></span> Conduzir o draft</h3>
    <figure>
      <img src="/img/guia/draft-admin.png" alt="Card Draft da liga ELITE no painel admin, com os controles da sessão." loading="lazy">
      <figcaption><b>Card Draft</b> na aba da liga: cria a sessão, acompanha de quem é a vez e traz os controles do admin.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-1-circle-fill"></i></span> 1ª rodada — pick a pick</h3>
    <ol class="passos">
      <li><b>Confira que a loteria está feita e a classe importada.</b> Sem ordem não há de quem seja a vez; sem classe não há quem escolher.</li>
      <li><b>O relógio corre por pick.</b> Se o GM não escolhe a tempo, o melhor disponível pela ordem é escolhido por ele.</li>
      <li><b>Acompanhe pela aba</b>, que mostra de quem é a vez e o cronômetro.</li>
      <li>Se precisar, use <b>Preencher pick passada</b> para completar uma vaga que ficou em aberto.</li>
    </ol>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-2-circle-fill"></i></span> 2ª rodada — todos ao mesmo tempo, por preferências</h3>
    <p>Quando a 1ª rodada acaba, <strong>todas as vagas da 2ª abrem juntas</strong> e começa um cronômetro de <strong>20 minutos</strong>. Não há vez de ninguém.</p>
    <ol class="passos">
      <li><b>Cada GM escolhe até 3 jogadores, na ordem que preferir</b> (1ª, 2ª e 3ª opção). Pode trocar à vontade enquanto o prazo corre.</li>
      <li><b>Ninguém leva nada na hora.</b> Escolher é registrar uma intenção, não pegar o jogador.</li>
      <li><b>No fim do prazo o sistema resolve tudo</b>, da pick mais alta para a mais baixa: se alguém à frente levou a 1ª opção, o GM desce para a 2ª, e depois para a 3ª.</li>
      <li><b>Quem não escolheu ninguém perde a pick.</b> Não há prorrogação.</li>
      <li><b>Quem sobra vai para as Dispensas</b> por 24 horas, e só depois para a Free Agency.</li>
    </ol>

    <figure>
      <img src="/img/guia/drafts.png" alt="Tela do draft vista pelo GM, com as vagas da 2ª rodada em grade." loading="lazy">
      <figcaption><b>A tela que o GM vê.</b> Na 2ª rodada aberta, cada card mostra quantas escolhas a vaga tem e traz o botão <b>Escolher</b> para o dono — e para o admin, em qualquer vaga.</figcaption>
    </figure>

    <div class="nota amber">
      <p><strong>Sobre "Finalizar draft" na 2ª rodada:</strong> ele <strong>resolve as preferências antes</strong> de encerrar — pode clicar com segurança para fechar antes dos 20 minutos. O que ele não faz é devolver tempo: quem ainda não escolheu perde a pick na hora.</p>
    </div>

    <div class="nota">
      <p><strong>Não existe cron neste projeto.</strong> A resolução do prazo dispara quando <strong>alguém abre a tela do draft</strong> depois de vencido. Na prática sempre tem gente olhando, mas se quiser garantir, abra a página você mesmo quando o relógio zerar.</p>
    </div>
  </section>

  <!-- ═══ 6. PONTUAÇÃO ═══ -->
  <section id="pontuacao">
    <h2><span class="parte">Parte 6</span>Pontuação, passo a passo</h2>
    <p class="sub">O lançamento oficial da temporada, e o item que trava o avanço. São <strong>dois salvamentos</strong>, não um — de propósito, para acompanhar a temporada como ela acontece de verdade.</p>

    <figure>
      <img src="/img/guia/pontuacao.png" alt="Tela Registro de Pontuação da ELITE, mostrando a etapa 1 com os campos de prêmios individuais." loading="lazy">
      <figcaption><b>Registro de Pontuação</b> (card <b>Pontuação</b> na aba da liga). O selo no canto diz em que etapa você está.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(16,185,129,.14);color:#10b981"><i class="bi bi-1-circle-fill"></i></span> Etapa 1 — temporada regular</h3>
    <p>Prêmios individuais (MVP, DPOY, MIP, 6º Homem e ROY — <strong>1 ponto cada</strong>), NBA Cup, prêmios estendidos e a classificação final.</p>
    <p><strong>Salvar aqui já faz três coisas:</strong> atualiza a Tabela, <strong>libera a loteria</strong> e monta o chaveamento dos playoffs. É por isso que esta etapa vem antes da loteria no ciclo.</p>

    <h3><span class="h3-ico" style="background:rgba(16,185,129,.14);color:#10b981"><i class="bi bi-2-circle-fill"></i></span> Etapa 2 — playoffs</h3>
    <p>Com os playoffs decididos, o chaveamento é preenchido e o segundo salvamento <strong>soma tudo</strong> (seeds + prêmios + playoffs) e registra a pontuação da temporada.</p>

    <div class="nota green">
      <p>Tudo que você digitar fica guardado como <strong>rascunho</strong>, mesmo fechando a página. Dá para preencher aos poucos, conferir com calma e só então salvar.</p>
    </div>

    <h3><span class="h3-ico" style="background:rgba(245,158,11,.14);color:var(--amber)"><i class="bi bi-star-fill"></i></span> Prêmios estendidos <span class="selo selo-elite">Elite</span></h3>
    <p>All-NBA e All-Defensive são da temporada regular e entram <strong>na etapa 1</strong>. O <strong>Finals MVP é de playoffs</strong> e por isso só aparece <strong>na etapa 2</strong> — se você o procurar na primeira, não vai achar. Nenhum deles é enfeite: todos <strong>somam no cap do jogador</strong> no ano seguinte.</p>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Prêmio</th><th>Vagas</th><th>Bônus no cap</th></tr></thead>
        <tbody>
          <tr><td>Finals MVP</td><td>1</td><td>+3M</td></tr>
          <tr><td>All-NBA — 1º Time</td><td>5</td><td>+3M</td></tr>
          <tr><td>All-NBA — 2º Time</td><td>5</td><td>+2M</td></tr>
          <tr><td>All-NBA — 3º Time</td><td>5</td><td>+1M</td></tr>
          <tr><td>All-Defensive — 1º Time</td><td>5</td><td>+2M</td></tr>
        </tbody>
      </table>
    </div>

    <h3><span class="h3-ico" style="background:rgba(6,182,212,.14);color:var(--cyan)"><i class="bi bi-bar-chart-steps"></i></span> Pontuação por Time</h3>
    <p>Card separado, para <strong>corrigir</strong> a pontuação de um time específico depois do fato. Não substitui o registro oficial — é conserto pontual.</p>
    <figure>
      <img src="/img/guia/pontos-time.png" alt="Tela Pontuação por Temporada, com a pontuação de cada time da liga." loading="lazy">
      <figcaption><b>Pontuação por Time</b>: ajuste fino, time a time.</figcaption>
    </figure>
  </section>

  <!-- ═══ 7. AVANÇAR TEMPORADA ═══ -->
  <section id="avancar">
    <h2><span class="parte">Parte 7</span>Avançar a temporada</h2>
    <p class="sub">O botão que fecha um ano e abre o próximo. Fica no topo da aba da liga, e é a ação mais pesada do painel.</p>

    <figure>
      <img src="/img/guia/avancar-temp.png" alt="Modal de avanço de temporada, com o resumo do que será feito." loading="lazy">
      <figcaption><b>Avançar Temporada</b>: confira o resumo antes de confirmar.</figcaption>
    </figure>

    <ul>
      <li><strong>A pontuação precisa estar registrada.</strong> Sem isso o avanço é bloqueado — e é o motivo mais comum de o botão recusar.</li>
      <li>O checklist mostra o que ainda falta <strong>antes</strong> de você clicar. Use-o.</li>
      <li>Ao virar o ano, a temporada antiga é marcada como concluída. Isso tem efeitos em cadeia: os <strong>contadores de dispensa zeram</strong> e os calouros <strong>deixam de valer a rookie scale</strong>, voltando à tabela por OVR.</li>
    </ul>

    <div class="nota amber">
      <p>Avançar não tem botão de desfazer. Confira o checklist inteiro antes — principalmente pontuação e playoffs.</p>
    </div>
  </section>

  <!-- ═══ 8. MERCADO ═══ -->
  <section id="mercado">
    <h2><span class="parte">Parte 8</span>Dispensas, Free Agency e Leilão</h2>
    <p class="sub">Os três mercados da liga, e como um alimenta o outro.</p>

    <h3><span class="h3-ico" style="background:rgba(239,68,68,.14);color:#ef4444"><i class="bi bi-hourglass-split"></i></span> Dispensas (waiver)</h3>
    <p>Quando um time dispensa alguém, o jogador fica <strong>12 horas</strong> aceitando lance. Vence quem tiver <strong>maior espaço no cap</strong> no momento do lance; empate vai para quem deu o lance primeiro. Se ninguém der lance, ele vira <strong>free agent</strong> automaticamente.</p>

    <figure>
      <img src="/img/guia/dispensas.png" alt="Tela de Dispensas, com os jogadores no waiver e o tempo restante." loading="lazy">
      <figcaption><b>Dispensas</b>: quem está no waiver e quanto tempo falta. Os lances ficam fechados até o prazo vencer.</figcaption>
    </figure>

    <div class="nota">
      <p><strong>A sobra do draft também passa por aqui.</strong> Quem não é escolhido no draft entra nas Dispensas por <strong>24 horas</strong> — não vai direto para a Free Agency. É a mesma esteira: recebeu lance, vai para o time; não recebeu, vira free agent.</p>
    </div>

    <h3><span class="h3-ico" style="background:rgba(34,197,94,.14);color:var(--green)"><i class="bi bi-people-fill"></i></span> Free Agency</h3>
    <p>O mercado de agentes livres, em moedas. É o destino final de quem passou pelo waiver sem receber proposta e dos calouros não escolhidos. Pode ser <strong>ligado e desligado para a liga inteira</strong> nas configurações da aba.</p>
    <figure>
      <img src="/img/guia/free-agency.png" alt="Tela da Free Agency com a lista de agentes livres." loading="lazy">
      <figcaption><b>Free Agency</b>: a lista de agentes livres e os lances.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(239,68,68,.14);color:#ef4444"><i class="bi bi-hammer"></i></span> Leilão</h3>
    <p>O time coloca um jogador em leilão e a liga dá lances. <strong>Não há limite</strong> de quantos jogadores um time pode leiloar — o <em>Slot de leilão</em> vendido na loja é liberado na hora da compra e não desconta de contador nenhum, porque não existe teto para descontar.</p>
  </section>

  <!-- ═══ 9. MOEDAS E LOJA ═══ -->
  <section id="moedas">
    <h2><span class="parte">Parte 9</span>Moedas e a Loja</h2>
    <p class="sub">Os GMs ganham moedas nos minigames e gastam na loja. Parte das compras se aplica sozinha; outra parte espera você.</p>

    <figure>
      <img src="/img/guia/games-moedas.png" alt="Tela Games, com os minigames diários e a troca de moedas." loading="lazy">
      <figcaption><b>Games</b>: é onde a moeda nasce — minigames diários e apostas dos eventos.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(34,197,94,.14);color:var(--green)"><i class="bi bi-lightning-charge-fill"></i></span> O que é automático</h3>
    <p>Estas compras <strong>não passam por você</strong>: são aplicadas no instante da compra e nem aparecem na fila.</p>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Item</th><th>O que acontece</th></tr></thead>
        <tbody>
          <tr><td>Slot extra de waiver</td><td>Soma <strong>+1 dispensa</strong> ao time nesta temporada. Zera na virada do ano, como está escrito na loja.</td></tr>
          <tr><td>Slot extra de G-League</td><td>Soma <strong>+1 vaga</strong> na G-League do time.</td></tr>
          <tr><td>Slot de leilão</td><td>Fica registrado e liberado na hora. <strong>Não soma em contador nenhum</strong> — não existe limite de leilão no sistema.</td></tr>
        </tbody>
      </table>
    </div>

    <h3><span class="h3-ico" style="background:rgba(245,158,11,.14);color:var(--amber)"><i class="bi bi-hourglass-split"></i></span> O que espera você</h3>
    <p>O que mexe num jogador específico continua passando por gente. Fica em <strong>Pedidos da Loja</strong>, na Gestão:</p>
    <ul>
      <li><strong>Badge</strong> — o GM pede pelo <em>Meu Elenco</em>, escolhendo o jogador e a badge. Cai na fila para você aprovar. <strong>A badge só é gasta quando você aplica</strong>: se recusar, ela volta para o GM. E com uma badge comprada, ele só pode ter <strong>um pedido esperando</strong> por vez.</li>
      <li><strong>City Edition</strong> — o uniforme, que precisa ser produzido.</li>
    </ul>

    <figure>
      <img src="/img/guia/loja-pedidos.png" alt="Tela Pedidos da Loja, com a fila de resgates aguardando o admin." loading="lazy">
      <figcaption><b>Pedidos da Loja</b> (Gestão): a fila do que precisa da sua mão.</figcaption>
    </figure>

    <p>O card <strong>Moedas</strong>, na aba da liga, mostra o saldo de cada usuário — é onde se confere uma reclamação de saldo.</p>
  </section>

  <!-- ═══ 10. ABA DA LIGA ═══ -->
  <section id="liga">
    <h2><span class="parte">Parte 10</span>A aba de uma liga, card por card</h2>
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
        <div class="txt"><div class="nome">Dispensas</div><div class="desc">Os cortes de elenco da liga e os limites de cada time. Detalhes na <a href="#mercado">Parte 8</a>.</div></div></div>
    </div>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como forçar uma troca</h4>
    <p>Force Trade executa a troca sem passar pelo aceite dos dois lados. É atalho de correção — combine antes com os GMs.</p>
    <ol class="passos">
      <li><b>Abra o card Force Trade</b> (só admin geral).</li>
      <li><b>Escolha os times participantes.</b> São dois ou mais.</li>
      <li><b>Monte os itens da troca</b> pelo botão de adicionar: jogadores e picks, de cada lado.</li>
      <li><b>Escreva a observação</b> dizendo por que a troca foi forçada. Ela fica no registro, e é o que explica o caso daqui a três meses.</li>
      <li><b>Confirme.</b> A troca é executada na hora e gera histórico, diferente de editar elenco pela tela do time.</li>
    </ol>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como abrir ou fechar o mercado</h4>
    <ol class="passos">
      <li><b>Vá nas configurações no topo da aba da liga</b> — elas ficam à vista de propósito, não escondidas atrás de um card.</li>
      <li><b>Use os botões de Trades</b> (Ativas / Bloqueadas) e <b>Free Agency</b> (Ativa / Bloqueada).</li>
      <li><b>Clique em Salvar.</b> Vale para a liga inteira <b>na hora</b> — avise antes de fechar.</li>
    </ol>

    <h3><span class="h3-ico" style="background:rgba(168,85,247,.14);color:var(--purple)"><i class="bi bi-trophy-fill"></i></span> Draft</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.12);color:#a855f7"><i class="bi bi-trophy-fill"></i></div>
        <div class="txt"><div class="nome">Draft</div><div class="desc">Conduz o draft da temporada. Com um draft em andamento, a aba mostra de quem é a vez e o cronômetro da pick. As duas rodadas funcionam de formas diferentes — veja a <a href="#draft">Parte 5</a>.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(168,85,247,.08);color:#a855f7"><i class="bi bi-archive-fill"></i></div>
        <div class="txt"><div class="nome">Banco de Classes</div><div class="desc">O acervo de classes de draft — de onde saem os calouros de cada temporada. Como criar por CSV e levar para o draft: <a href="#draft">Parte 5</a>.</div></div></div>
    </div>

    <h3><span class="h3-ico" style="background:rgba(16,185,129,.14);color:#10b981"><i class="bi bi-graph-up-arrow"></i></span> Temporada e pontuação</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(6,182,212,.12);color:#06b6d4"><i class="bi bi-bar-chart-steps"></i></div>
        <div class="txt"><div class="nome">Pontuação por Time</div><div class="desc">Ajusta a pontuação time a time.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(16,185,129,.12);color:#10b981"><i class="bi bi-clipboard-data-fill"></i></div>
        <div class="txt"><div class="nome">Pontuação</div><div class="desc">O lançamento oficial da temporada, em <b>dois salvamentos</b>: primeiro os prêmios e a classificação — que já atualizam a Tabela, liberam a loteria e montam o chaveamento —, e depois os playoffs, que fecham a pontuação. Na ELITE, a NBA Cup e os prêmios estendidos são preenchidos aí mesmo, na primeira parte. O que estiver digitado fica salvo como rascunho até o registro fechar.</div></div></div>
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
    <figure>
      <img src="/img/guia/punicoes.png" alt="Tela de Punições, com as punições aplicadas na liga." loading="lazy">
      <figcaption><b>Punições</b>: cada uma com efeito, alcance e a temporada em que vale.</figcaption>
    </figure>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como aplicar uma punição</h4>
    <ol class="passos">
      <li><b>Abra o card Punições</b> da liga.</li>
      <li><b>Preencha os três campos:</b> <b>Motivo</b> (o que aconteceu, em texto), <b>Time</b> e <b>Consequência</b>.</li>
      <li><b>Escolha a consequência</b> na lista. Cada uma tem efeito próprio no sistema:</li>
    </ol>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Consequência</th><th>O que o sistema faz</th></tr></thead>
        <tbody>
          <tr><td>Aviso formal</td><td>Só registra. Não bloqueia nada.</td></tr>
          <tr><td>Perda da pick 1ª rodada</td><td>Tira a escolha de 1ª rodada do time.</td></tr>
          <tr><td>Perda de pick específica</td><td>Você aponta <strong>qual</strong> pick — a tela pede isso.</td></tr>
          <tr><td>Trades bloqueadas por uma temporada</td><td>O time não negocia. Pede <strong>alcance</strong> (por quanto tempo).</td></tr>
          <tr><td>Trades sem picks</td><td>Pode trocar jogadores, mas não picks. Pede alcance.</td></tr>
          <tr><td>Sem poder usar FA na temporada</td><td>Bloqueia a Free Agency para o time. Pede alcance.</td></tr>
          <tr><td>Rotação automática</td><td>A escalação passa a ser definida pelo sistema. Pede alcance.</td></tr>
        </tbody>
      </table>
    </div>
    <p>Precisa de uma consequência que não está na lista? Dá para <strong>cadastrar uma nova</strong> pelo campo da própria tela. Se o nome não bater com nenhum efeito conhecido, ela entra como <strong>aviso formal</strong> — registra, mas não bloqueia nada. Escreva o nome exatamente como na tabela acima quando quiser o efeito real.</p>

    <div class="nota">
      <p>O <strong>FBA SERASA</strong> não precisa de você: avisos por trade parada há mais de 24 h são gerados <strong>sozinhos</strong>. Não lance na mão.</p>
    </div>

    <h3><span class="h3-ico" style="background:rgba(20,184,166,.14);color:#14b8a6"><i class="bi bi-three-dots"></i></span> Outros</h3>
    <div class="cards">
      <div class="card-a"><div class="ico" style="background:rgba(20,184,166,.12);color:#14b8a6"><i class="bi bi-clipboard2-pulse"></i></div>
        <div class="txt"><div class="nome">Tática</div><div class="desc">A janela de edição de táticas e o que cada time escolheu.</div></div></div>
      <div class="card-a"><div class="ico" style="background:rgba(245,158,11,.12);color:#f59e0b"><i class="bi bi-coin"></i></div>
        <div class="txt"><div class="nome">Moedas</div><div class="desc">Saldo de moedas dos usuários — onde se confere reclamação de saldo. O que a loja faz com elas está na <a href="#moedas">Parte 9</a>.</div></div></div>
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
    <h2><span class="parte">Parte 11</span>Dentro de um time</h2>
    <p class="sub">É aqui que se corrige o que está errado num elenco. Tudo o que muda dados de verdade passa por esta tela.</p>

    <p>Para chegar aqui: <strong>aba da liga → clique no time</strong> na lista do fim da página. O breadcrumb do topo mostra onde você está, e <strong>Voltar</strong> sobe um nível.</p>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-person-gear"></i></span> O que dá para fazer</h3>
    <ul>
      <li><strong>Editar o time</strong> — nome, cidade e dados da franquia.</li>
      <li><strong>Contador de trocas</strong> — corrige o número de trades usadas quando ele sai do lugar.</li>
      <li><strong>Jogadores</strong> — adicionar, editar e remover.</li>
      <li><strong>Picks</strong> — adicionar, editar, remover e <strong>mover</strong> de um time para outro.</li>
    </ul>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como corrigir um jogador</h4>
    <ol class="passos">
      <li><b>Abra o time</b> pela lista da aba da liga.</li>
      <li><b>Ache o jogador</b> na lista do elenco e use <b>editar</b>.</li>
      <li><b>Ajuste o que precisa</b> — OVR, posição, idade, função.</li>
      <li>Para marcar a <b>LENDA</b> da franquia, é aqui também. Cada time tem <b>uma</b>, e ela passa a valer no mínimo 40M no cap (<a href="/guia.php#cap">regra no Guia do GM</a>).</li>
    </ol>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como mover uma pick de time</h4>
    <ol class="passos">
      <li><b>Abra o time que tem a pick.</b></li>
      <li><b>Na lista de picks, use mover</b> e escolha o time de destino.</li>
      <li><b>Confira no Trade Machine</b> depois: a pick tem que aparecer no time novo, com o número certo.</li>
    </ol>
    <div class="nota amber">
      <p>Mover pick por aqui <strong>não gera trade</strong>. Se a pick mudou de dono por causa de uma negociação de verdade, faça pelo <strong>Force Trade</strong> — ele deixa histórico, e é o histórico que responde "por que essa pick está com esse time?" seis meses depois.</p>
    </div>

    <div class="nota amber">
      <p>Editar por aqui <strong>não gera trade nem registro de negociação</strong> — o dado simplesmente muda. Para uma troca de verdade entre dois times, use Trades (ou Force Trade), que deixa histórico.</p>
    </div>
  </section>

  <!-- ═══ 5. GESTÃO ═══ -->
  <section id="gestao">
    <h2><span class="parte">Parte 12</span>A aba Gestão</h2>
    <p class="sub">O que não pertence a uma liga só: pessoas, acessos e ferramentas gerais.</p>

    <figure>
      <img src="/img/guia/admin-gestao.png" alt="Aba Gestão do painel, com a tabela de usuários e os cards de ferramentas." loading="lazy">
      <figcaption><b>Gestão</b>: a tabela de usuários em cima, os cards de ferramenta embaixo.</figcaption>
    </figure>

    <h3><span class="h3-ico" style="background:rgba(148,163,184,.14);color:#94a3b8"><i class="bi bi-table"></i></span> A tabela de usuários</h3>
    <p>Filtrada pela liga selecionada nos botões do topo, com o total de times de cada uma. Cada linha traz <strong>Usuário</strong>, <strong>Time</strong>, <strong>Ligas Admin</strong> e <strong>Admin Geral</strong> — ou seja, é aqui que se concede e se tira acesso de administração.</p>
    <p>Nas ações de cada linha dá pra <strong>editar</strong> (nome e cidade do time, liga do usuário), <strong>remover o time</strong> ou <strong>remover o usuário</strong>.</p>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como dar ou tirar acesso de admin</h4>
    <ol class="passos">
      <li><b>Filtre pela liga</b> nos botões do topo e ache a pessoa na tabela.</li>
      <li><b>Use as colunas Ligas Admin e Admin Geral</b> — é ali que o acesso é concedido e retirado.</li>
      <li><b>Admin de liga</b> vale só para as ligas marcadas. <b>Admin geral</b> abre tudo, inclusive Force Trade e Site Admin.</li>
    </ol>
    <div class="nota">
      <p>Quem vira <strong>admin geral</strong> sem nenhuma liga cadastrada recebe as quatro automaticamente no primeiro acesso. Não precisa marcar uma a uma.</p>
    </div>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como entrar com um GM novo</h4>
    <p>Há três caminhos, e cada um serve a uma situação:</p>
    <ol class="passos">
      <li><b>Adicionar GM</b> — cria a pessoa e o time direto, sem convite. Use quando já está tudo combinado.</li>
      <li><b>Interessados</b> — a lista de quem pediu para entrar. O contador no card mostra quantos aguardam; daqui sai o convite para <b>alguém específico</b>.</li>
      <li><b>Inscrição ROOKIE</b> — um link único que serve para várias pessoas. É o que se joga no grupo quando você quer abrir para quem chegar.</li>
    </ol>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como atender um pedido da loja</h4>
    <ol class="passos">
      <li><b>Abra Pedidos da Loja.</b> Só aparece aqui o que precisa de gente — slot de waiver, G-League e leilão já foram aplicados sozinhos.</li>
      <li><b>Badge:</b> o pedido diz o jogador e a badge. <b>Aprovar consome</b> a badge do GM; <b>recusar não tira nada</b> e ele pode pedir de novo.</li>
      <li><b>City Edition:</b> o uniforme precisa ser produzido antes de marcar como atendido.</li>
    </ol>

    <h4 style="font-size:14px;font-weight:800;margin:20px 0 8px;color:var(--text)">Como mexer no Hall da Fama</h4>
    <ol class="passos">
      <li><b>Abra o card Hall da Fama.</b></li>
      <li><b>Filtre por liga</b> ou <b>busque por GM ou time</b>.</li>
      <li><b>Adicione o GM</b> (ativo ou inativo) e ajuste os títulos.</li>
    </ol>
    <div class="nota">
      <p>Um mesmo GM pode ter <strong>linhas em ligas diferentes</strong>. Filtrando por liga você vê só a linha daquela liga — se parecer que sumiu, quase sempre é o filtro.</p>
    </div>

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
        <div class="txt"><div class="nome">Loterias</div><div class="desc">Loterias avulsas, para sortear qualquer coisa fora do fluxo da temporada. <b>Não confunda com a Loteria do Draft</b> (<a href="#loteria">Parte 4</a>), que é a da ordem de escolha.</div></div></div>
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
    <h2><span class="parte">Parte 13</span>Controle de Cap <span class="selo selo-elite">Elite</span></h2>
    <p class="sub">Um card que existe só na aba ELITE, porque só ela usa salary cap em milhões.</p>
    <p>Traz a <strong>tabela de referência de OVR para salário</strong> e, ao lado, <strong>quantos jogadores ativos da ELITE existem em cada OVR</strong>. Serve para enxergar o efeito real de mexer na tabela antes de mexer: subir a faixa dos 88 é uma coisa quando há dois jogadores ali, e outra bem diferente quando há vinte.</p>
    <p>Lista também as <strong>lendas marcadas</strong> na liga. As regras de LENDA, rookie scale e bônus de lealdade estão explicadas no <a href="/guia.php#cap">Guia do GM</a> — aqui é só o painel de leitura.</p>

    <figure>
      <img src="/img/guia/cap.png" alt="Tela do Salary Cap, com a tabela de OVR para salário e a distribuição de jogadores." loading="lazy">
      <figcaption><b>Salary Cap</b>: a tabela de referência e quantos jogadores existem em cada OVR.</figcaption>
    </figure>

    <div class="nota amber">
      <p><strong>A rookie scale vale só na primeira temporada profissional do calouro.</strong> O draft roda no fim de uma temporada e o calouro estreia na seguinte — então o carimbo dele é do ano do draft, e o salário de pick vale durante o ano seguinte. Na virada, ele volta para a tabela por OVR.</p>
    </div>
  </section>

  <!-- ═══ 7. CUIDADO ═══ -->
  <section id="cuidado">
    <h2><span class="parte">Parte 14</span>O que exige cuidado</h2>
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
          <tr><td>Avançar Temporada</td><td>Fecha o ano e não tem desfazer. Zera os contadores de dispensa e tira a rookie scale dos calouros. Confira o checklist inteiro antes.</td></tr>
          <tr><td>Finalizar draft na 2ª rodada</td><td>Resolve as preferências e encerra na hora. Quem ainda não escolheu <strong>perde a pick</strong> — não há como devolver o tempo.</td></tr>
          <tr><td>Sortear a loteria</td><td>A ordem é gravada de uma vez. Para tirar dúvida, use o <strong>Teste a loteria</strong>, que simula sem gravar.</td></tr>
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
