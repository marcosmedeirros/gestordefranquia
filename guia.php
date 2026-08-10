<?php
/**
 * Guia do GM — página PÚBLICA, sem login.
 *
 * Serve pra quem acabou de chegar na FBA e ainda não tem conta. Por isso não
 * chama requireAuth() nem inclui a sidebar (que depende de sessão). Se houver
 * sessão ativa, só troca o botão do topo pra levar ao Dashboard.
 *
 * Cobre as quatro ligas. O que é exclusivo da ELITE vem marcado com o selo
 * .only-elite — o resto vale pra todo mundo. Nada de conteúdo de admin aqui.
 *
 * Ao mexer numa tela do app, atualize a seção correspondente aqui.
 */
// Sem require de db.php/auth.php de propósito: a página não lê nada do banco,
// e uma página pública não deve cair se o banco estiver fora do ar.
if (session_status() === PHP_SESSION_NONE) @session_start();
$logado = !empty($_SESSION['user_id']);

$secoes = [
    ['id' => 'ligas',    'titulo' => 'As quatro ligas'],
    ['id' => 'comecar',  'titulo' => 'Primeiros passos'],
    ['id' => 'time',     'titulo' => 'Seu time no dia a dia'],
    ['id' => 'elenco',   'titulo' => 'Como montar o elenco'],
    ['id' => 'cap',      'titulo' => 'O CAP: o limite do seu elenco'],
    ['id' => 'liga',     'titulo' => 'Acompanhar a liga'],
    ['id' => 'glossario','titulo' => 'Glossário'],
];
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<title>Guia do GM · FBA Manager</title>
<meta name="description" content="Como funciona a FBA: as quatro ligas, acesso e descenso, e o que faz cada tela do app. Guia para novos GMs.">
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
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6; --purple:#a855f7; --gold:#f5c542;
  --radius:14px; --font:'Montserrat',system-ui,sans-serif;
  --maxw:820px;
}
/* Cores de TEXTO. No tema escuro são as próprias cores da marca; no claro
   precisam escurecer, porque âmbar/verde/dourado sobre fundo quase branco não
   passam de ~1.7:1 e o selo fica ilegível. */
:root{ --red-ink:var(--red); --amber-ink:var(--amber); --green-ink:var(--green); --gold-ink:#a8801a; }
:root[data-theme="light"]{
  --bg:#f7f8fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e4e7ef; --border-md:#d5dae6;
  --text:#111217; --text-2:#525a68;
  /* Estes dois são mais escuros que o par do tema escuro de propósito: são
     usados em texto pequeno (rótulos de 10-11px), onde o vermelho da marca e o
     cinza claro não alcançavam 4.5:1 sobre fundo claro. */
  --text-3:#586170;
  --red-ink:#c2001d; --amber-ink:#6b4000; --green-ink:#0f6130; --gold-ink:#5c4500;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{background:var(--bg);color:var(--text);font-family:var(--font);line-height:1.65;
  -webkit-font-smoothing:antialiased}

/* ── topo ─────────────────────────────────────────────── */
.topo{position:sticky;top:0;z-index:20;background:color-mix(in srgb,var(--bg) 88%,transparent);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--border)}
.topo-in{max-width:var(--maxw);margin:0 auto;padding:12px 20px;display:flex;align-items:center;gap:12px}
.topo-logo{display:flex;align-items:center;gap:9px;font-weight:900;letter-spacing:-.3px;
  color:var(--text);text-decoration:none;font-size:15px}
.topo-logo img{height:26px;width:auto}
.topo-acoes{margin-left:auto;display:flex;align-items:center;gap:8px}
.btn{display:inline-flex;align-items:center;gap:6px;border-radius:9px;padding:7px 14px;
  font-size:13px;font-weight:700;text-decoration:none;border:1px solid var(--border-md);
  color:var(--text);background:var(--panel-2);cursor:pointer;transition:all .18s}
.btn:hover{border-color:var(--red);color:var(--red-ink)}
.btn-red{background:var(--red);border-color:var(--red);color:#fff}
.btn-red:hover{opacity:.88;color:#fff}
.btn-icon{padding:7px 9px}

/* ── layout ───────────────────────────────────────────── */
.wrap{max-width:var(--maxw);margin:0 auto;padding:0 20px 90px}
.hero{padding:56px 0 12px}
.hero .kicker{font-size:11px;font-weight:800;letter-spacing:1.6px;text-transform:uppercase;
  color:var(--red-ink);margin-bottom:12px}
.hero h1{font-size:clamp(30px,6vw,46px);font-weight:900;letter-spacing:-1.2px;line-height:1.1;
  text-wrap:balance;margin-bottom:14px}
.hero p{font-size:17px;color:var(--text-2);max-width:60ch}

/* indice */
.indice{margin:34px 0 8px;padding:18px 20px;background:var(--panel);border:1px solid var(--border);
  border-radius:var(--radius)}
.indice-t{font-size:10px;font-weight:800;letter-spacing:1.2px;text-transform:uppercase;
  color:var(--text-3);margin-bottom:12px}
.indice ol{list-style:none;counter-reset:s;display:grid;gap:2px}
.indice li{counter-increment:s}
.indice a{display:flex;gap:10px;align-items:baseline;padding:6px 0;color:var(--text);
  text-decoration:none;font-weight:600;font-size:14px;border-bottom:1px solid transparent}
.indice a::before{content:counter(s,decimal-leading-zero);color:var(--text-3);font-size:11px;
  font-weight:800;font-variant-numeric:tabular-nums;min-width:20px}
.indice a:hover{color:var(--red-ink)}

/* secoes */
section{padding-top:52px;scroll-margin-top:64px}
h2{font-size:26px;font-weight:900;letter-spacing:-.6px;margin-bottom:6px;text-wrap:balance}
h2 .parte{color:var(--red-ink);font-size:13px;font-weight:800;display:block;letter-spacing:1.2px;
  text-transform:uppercase;margin-bottom:6px}
.sub{color:var(--text-2);margin-bottom:22px;max-width:62ch}
h3{font-size:17px;font-weight:800;margin:26px 0 7px;letter-spacing:-.2px}
p{margin-bottom:12px;color:var(--text-2)}
p strong,li strong{color:var(--text);font-weight:700}
ul,ol{margin:0 0 14px 20px;color:var(--text-2)}
li{margin-bottom:6px}
a{color:var(--red-ink)}

/* cartão de aba */
.aba{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);
  padding:18px 20px;margin-bottom:12px}
.aba-cab{display:flex;align-items:center;gap:10px;margin-bottom:8px;flex-wrap:wrap}
.aba-ico{width:32px;height:32px;border-radius:9px;display:grid;place-items:center;flex-shrink:0;
  background:var(--red-soft);color:var(--red);font-size:15px}
.aba-nome{font-size:16px;font-weight:800;letter-spacing:-.2px}
.aba p:last-child{margin-bottom:0}

/* selos */
.selo{font-size:9.5px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;
  padding:3px 8px;border-radius:999px;border:1px solid;white-space:nowrap}
.selo-elite{color:var(--amber-ink);border-color:color-mix(in srgb,var(--amber) 35%,transparent);
  background:color-mix(in srgb,var(--amber) 11%,transparent)}
.selo-todas{color:var(--green-ink);border-color:color-mix(in srgb,var(--green) 35%,transparent);
  background:color-mix(in srgb,var(--green) 11%,transparent)}
.selo-lenda{color:var(--gold-ink);border-color:color-mix(in srgb,var(--gold) 40%,transparent);
  background:color-mix(in srgb,var(--gold) 12%,transparent)}

/* nota destacada */
.nota{border-left:3px solid var(--red);background:var(--panel-2);padding:13px 16px;
  border-radius:0 10px 10px 0;margin:14px 0;font-size:14.5px}
.nota.amber{border-left-color:var(--amber)}
.nota.green{border-left-color:var(--green)}
.nota p:last-child{margin-bottom:0}

/* pirâmide das ligas */
.piramide{display:grid;gap:8px;margin:20px 0}
.liga-l{display:flex;align-items:center;gap:14px;padding:14px 18px;border-radius:12px;
  border:1px solid var(--border);background:var(--panel)}
.liga-l .pos{font-size:11px;font-weight:800;color:var(--text-3);min-width:18px;
  font-variant-numeric:tabular-nums}
.liga-l .nome{font-size:16px;font-weight:900;letter-spacing:.3px}
.liga-l .desc{font-size:13px;color:var(--text-2);margin-left:auto;text-align:right}
.liga-l.elite{border-color:color-mix(in srgb,var(--amber) 30%,transparent)}
.liga-l.elite .nome{color:var(--amber)}
.liga-l.next .nome{color:var(--blue)}
.liga-l.rise .nome{color:var(--green)}
.liga-l.rookie .nome{color:var(--purple)}

/* tabela */
.tabela-wrap{overflow-x:auto;margin:14px 0;border:1px solid var(--border);border-radius:12px}
table{width:100%;border-collapse:collapse;font-size:14px;min-width:340px}
th{text-align:left;padding:10px 14px;font-size:10px;font-weight:800;letter-spacing:.8px;
  text-transform:uppercase;color:var(--text-3);background:var(--panel-2);white-space:nowrap}
td{padding:10px 14px;border-top:1px solid var(--border);color:var(--text-2)}
td:first-child{color:var(--text);font-weight:600}
.num{text-align:right;font-variant-numeric:tabular-nums}

/* comandos do bot */
code{font-family:ui-monospace,"SF Mono",Menlo,Consolas,monospace;font-size:13px;
  padding:2px 7px;border-radius:6px;background:var(--panel-2);border:1px solid var(--border);
  color:var(--text);white-space:nowrap}

/* glossario */
dl{display:grid;gap:0}
dt{font-weight:800;font-size:14.5px;margin-top:14px;color:var(--text)}
dt:first-child{margin-top:0}
dd{margin:3px 0 0;color:var(--text-2);font-size:14.5px}

.rodape{margin-top:60px;padding-top:22px;border-top:1px solid var(--border);
  font-size:13px;color:var(--text-3);display:flex;gap:14px;flex-wrap:wrap;align-items:center}
.rodape a{color:var(--text-2);text-decoration:none}
.rodape a:hover{color:var(--red-ink)}

@media (max-width:560px){
  .hero{padding-top:36px}
  .liga-l{flex-wrap:wrap;gap:8px}
  .liga-l .desc{margin-left:0;text-align:left;width:100%}
  .aba{padding:15px 16px}
}
@media (prefers-reduced-motion:reduce){*{transition:none!important;scroll-behavior:auto!important}}
html{scroll-behavior:smooth}
</style>
</head>
<body>

<header class="topo">
  <div class="topo-in">
    <a class="topo-logo" href="/"><img src="/img/fba-logo.png" alt=""> FBA Manager</a>
    <div class="topo-acoes">
      <button class="btn btn-icon" id="btnTema" title="Alternar tema" aria-label="Alternar tema"><i class="bi bi-circle-half"></i></button>
      <?php if ($logado): ?>
        <a class="btn btn-red" href="/dashboard.php"><i class="bi bi-speedometer2"></i> Dashboard</a>
      <?php else: ?>
        <a class="btn btn-red" href="/login.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
      <?php endif; ?>
    </div>
  </div>
</header>

<div class="wrap">

  <div class="hero">
    <div class="kicker">Guia do GM</div>
    <h1>Você acabou de virar dono de uma franquia. E agora?</h1>
    <p>Este guia explica como a FBA funciona e o que faz cada tela do app. Não precisa ler tudo de uma vez — comece pelos <a href="#comecar">primeiros passos</a> e volte aqui quando bater dúvida.</p>
  </div>

  <nav class="indice">
    <div class="indice-t">Neste guia</div>
    <ol>
      <?php foreach ($secoes as $s): ?>
      <li><a href="#<?= $s['id'] ?>"><?= htmlspecialchars($s['titulo']) ?></a></li>
      <?php endforeach; ?>
    </ol>
  </nav>

  <!-- ═══════════════ 1. AS LIGAS ═══════════════ -->
  <section id="ligas">
    <h2><span class="parte">Parte 1</span>As quatro ligas</h2>
    <p class="sub">A FBA não é uma liga só. São quatro, empilhadas — e você sobe ou desce entre elas conforme o desempenho do seu time.</p>

    <div class="piramide">
      <div class="liga-l elite"><span class="pos">1º</span><span class="nome">ELITE</span><span class="desc">O topo. Não tem pra onde subir.</span></div>
      <div class="liga-l next"><span class="pos">2º</span><span class="nome">NEXT</span><span class="desc">Sobe pra ELITE, cai pra RISE.</span></div>
      <div class="liga-l rise"><span class="pos">3º</span><span class="nome">RISE</span><span class="desc">Sobe pra NEXT, cai pra ROOKIE.</span></div>
      <div class="liga-l rookie"><span class="pos">4º</span><span class="nome">ROOKIE</span><span class="desc">A porta de entrada. É por aqui que quase todo mundo começa.</span></div>
    </div>

    <h3>Acesso e descenso</h3>
    <p>No fim de cada temporada, <strong>os 4 primeiros de cada liga sobem</strong> e <strong>os 4 últimos descem</strong>. A ELITE não tem acesso, porque já é o topo — lá os 4 últimos caem pra NEXT e ponto.</p>
    <p>Na prática isso significa que <strong>ninguém fica parado</strong>. Um time que domina a ROOKIE pode chegar à ELITE em algumas temporadas, e um time da ELITE que desmonta o elenco cai. É o mesmo elenco, as mesmas picks e o mesmo GM — só muda a liga em que você disputa.</p>

    <div class="nota">
      <p>Quer ver sua chance de subir ou cair? A aba <strong>Mundo FBA</strong> projeta isso com base na temporada em andamento.</p>
    </div>

    <h3>O que muda de uma liga pra outra</h3>
    <p>As telas do app são as mesmas em todas as ligas. O que muda de verdade é <strong>como o limite do elenco é calculado</strong>: a ELITE usa salary cap em milhões, e as outras três usam soma de OVR. Isso está explicado na <a href="#cap">Parte 5</a>.</p>
  </section>

  <!-- ═══════════════ 2. PRIMEIROS PASSOS ═══════════════ -->
  <section id="comecar">
    <h2><span class="parte">Parte 2</span>Primeiros passos</h2>
    <p class="sub">A ordem que resolve 90% das dúvidas de quem chegou agora.</p>

    <ol>
      <li><strong>Veja seu elenco.</strong> Abra <strong>Meu Elenco</strong> e conheça quem você tem. Repare no OVR de cada jogador — é o número que manda em quase tudo.</li>
      <li><strong>Confira o CAP.</strong> No Dashboard tem um card com o seu limite. Se estiver estourado ou abaixo do piso, isso precisa ser resolvido antes de qualquer outra coisa.</li>
      <li><strong>Complete o elenco.</strong> Todo time tem um número mínimo e máximo de jogadores (o Dashboard avisa quando você está fora). Pra completar, use <strong>Free Agency</strong>, <strong>Leilão</strong> ou <strong>Trades</strong>.</li>
      <li><strong>Escolha uma tática.</strong> Sem tática ativa seu time joga sem plano. A aba <strong>Tática</strong> resolve em um minuto.</li>
      <li><strong>Fique de olho no Dashboard.</strong> O bloco <em>Precisa de você</em> lista exatamente o que está pendente. Se ele estiver vazio, está tudo em ordem.</li>
    </ol>

    <div class="nota green">
      <p>Regra de bolso: <strong>se o Dashboard não está reclamando, você não está atrasado em nada.</strong> Ele é a sua lista de tarefas.</p>
    </div>
  </section>

  <!-- ═══════════════ 3. SEU TIME ═══════════════ -->
  <section id="time">
    <h2><span class="parte">Parte 3</span>Seu time no dia a dia</h2>
    <p class="sub">As telas que você vai abrir com mais frequência.</p>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-house-door-fill"></i></span><span class="aba-nome">Dashboard</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>A tela inicial. Mostra o que precisa da sua atenção, o resumo do seu time (jogadores, CAP, picks, trocas usadas), sua posição na liga e o que está acontecendo na FBA. <strong>Comece sempre por aqui.</strong></p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-person-fill"></i></span><span class="aba-nome">Meu Elenco</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Seu plantel completo. Aqui você define a <strong>função</strong> de cada jogador (Titular, Banco, G-League), marca quem está disponível pra troca, edita dados e dispensa quem não serve mais.</p>
      <p>Na ELITE, cada jogador mostra também <strong>quanto ele ocupa do seu salary cap</strong>.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-clipboard-data-fill"></i></span><span class="aba-nome">Tática</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>O estilo de jogo do seu time. Escolha uma antes de a janela de edição fechar — sem tática ativa, você entra em quadra sem plano definido. Dá pra trocar entre temporadas conforme o elenco muda.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-calendar3"></i></span><span class="aba-nome">Picks</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Suas escolhas de draft, ano a ano, de 1ª e 2ª rodada — as suas e as que você recebeu de outros times. Cada pick mostra dois números:</p>
      <ul>
        <li><strong>TROCA</strong> — o valor estimado dela como moeda de troca. É a mesma escala do simulador de trocas, pra comparar picks e jogadores entre si.</li>
        <li><strong>CAP</strong> — quanto o calouro vai custar no seu cap no primeiro ano <span class="selo selo-elite">Elite</span>. A 2ª rodada custa 2M sempre; a 1ª depende de onde a pick cair, por isso aparece uma faixa.</li>
      </ul>
      <p>Picks futuras valem menos que as próximas: quanto mais distante o ano, maior a incerteza sobre quem vai estar escolhendo.</p>
    </div>
  </section>

  <!-- ═══════════════ 4. MONTAR O ELENCO ═══════════════ -->
  <section id="elenco">
    <h2><span class="parte">Parte 4</span>Como montar o elenco</h2>
    <p class="sub">Existem seis caminhos para trazer um jogador. Cada um serve a um momento diferente.</p>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-people-fill"></i></span><span class="aba-nome">Jogadores</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>A lista de todos os jogadores da liga, com filtros e busca. Use pra estudar o mercado antes de propor qualquer coisa: quem tem o que você precisa, quem está disponível pra troca, quanto custa.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-arrow-left-right"></i></span><span class="aba-nome">Trades</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Trocas com outros GMs, envolvendo jogadores e picks dos dois lados. Você propõe, o outro aceita ou recusa. Há um <strong>limite de trocas por ciclo</strong> — o Dashboard mostra quantas você já usou.</p>
      <p>Antes de propor, vale usar o <strong>simulador de trocas</strong>: ele calcula o resultado e descarta, sem mexer em nada de verdade. Serve pra ver se a troca cabe no CAP dos dois times.</p>
      <div class="nota amber">
        <p><strong>Proposta parada dá aviso.</strong> Trades pendentes há mais de 24 horas sem resposta geram um registro contra o time que não respondeu. Se não quer, recuse — não deixe parado.</p>
      </div>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-currency-dollar"></i></span><span class="aba-nome">Free Agency</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Jogadores sem time, livres pra contratar. É o caminho mais direto pra completar um elenco abaixo do mínimo. Há limites de quantas contratações você pode fazer — a própria tela mostra quantas restam.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-hammer"></i></span><span class="aba-nome">Leilão</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Disputa aberta por um jogador: quem der o maior lance leva. Diferente da Free Agency, aqui você concorre com os outros GMs em tempo real.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-shop"></i></span><span class="aba-nome">Mercado</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>O ponto de encontro do que está em movimento na liga — quem está atrás do quê, quem está oferecendo o quê.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-hand-thumbs-down"></i></span><span class="aba-nome">Dispensas</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>Cortar um jogador do elenco. Tem um número limitado de dispensas por período, então não é uma saída pra usar à toa. Antes de confirmar, a tela mostra como fica o seu CAP depois do corte.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-diagram-3-fill"></i></span><span class="aba-nome">Draft e Draft Inicial</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>O <strong>Draft</strong> é o recrutamento de calouros a cada temporada, na ordem definida pela loteria. O <strong>Draft Inicial</strong> só acontece quando uma liga está sendo formada — é quando todos montam o elenco do zero.</p>
      <p>Nos dois, você pode montar um <strong>mock</strong>: uma lista de preferências em ordem. Se chegar a sua vez e você não estiver online, com o mock ativo o sistema escolhe sozinho o primeiro nome da sua lista que ainda estiver disponível e passa pro próximo. Sem mock ativo e com o prazo estourado, entra o melhor OVR disponível.</p>
      <div class="nota green">
        <p><strong>Monte o mock.</strong> É a diferença entre escolher quem você quer e receber quem sobrou.</p>
      </div>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-shuffle"></i></span><span class="aba-nome">Loteria</span><span class="selo selo-todas">Todas as ligas</span></div>
      <p>O sorteio que define a ordem de escolha do draft. Times com campanha pior têm mais chance de pegar as primeiras picks.</p>
    </div>
  </section>

  <!-- ═══════════════ 5. O CAP ═══════════════ -->
  <section id="cap">
    <h2><span class="parte">Parte 5</span>O CAP: o limite do seu elenco</h2>
    <p class="sub">Todo time tem um teto — e um piso. É o que impede alguém de juntar todas as estrelas num elenco só. O jeito de calcular muda conforme a liga.</p>

    <h3>Nas ligas NEXT, RISE e ROOKIE <span class="selo selo-todas">Soma de OVR</span></h3>
    <p>Aqui o CAP é simples: soma-se o <strong>OVR dos seus 10 melhores jogadores</strong>. Esse número precisa ficar entre um mínimo e um máximo definidos pela liga — os dois valores aparecem na sua tela.</p>
    <p>Repare que só os 10 melhores contam. Os jogadores do fim do elenco não pesam no CAP, o que dá liberdade pra manter profundidade sem penalidade.</p>
    <p>Existe um <strong>bônus de lealdade</strong> que aumenta o seu teto: manter jogadores formados na casa, que nunca foram trocados, libera alguns pontos a mais de CAP. É o sistema recompensando quem constrói em vez de só negociar.</p>

    <h3>Na liga ELITE <span class="selo selo-elite">Salary cap</span></h3>
    <p>Na ELITE o CAP é em milhões, como na NBA. Cada jogador tem um salário derivado do OVR dele, e a soma de todos é a sua <strong>folha salarial</strong>.</p>

    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Elemento</th><th>O que é</th></tr></thead>
        <tbody>
          <tr><td>Cap Base</td><td>O teto que todo time começa tendo.</td></tr>
          <tr><td>Cap Flex</td><td>Bônus por manter jogadores que você mesmo draftou. Vale pra um número limitado de jogadores.</td></tr>
          <tr><td>Bônus de Lealdade</td><td>Bônus por jogador leal de alto OVR — nunca trocado e vindo do seu próprio draft.</td></tr>
          <tr><td>Cap Máximo</td><td>Cap Base + Cap Flex + Bônus de Lealdade. O seu teto real.</td></tr>
          <tr><td>Folha Salarial</td><td>A soma do que você paga hoje.</td></tr>
          <tr><td>Cap Mínimo</td><td>O piso. Depois da Trade Deadline, todo time precisa alcançá-lo.</td></tr>
        </tbody>
      </table>
    </div>

    <p>Os valores exatos aparecem na aba <strong>Salário Cap</strong>, que também traz o detalhamento jogador a jogador e um simulador de trocas.</p>

    <h3>Rookie Scale <span class="selo selo-elite">Elite</span></h3>
    <p>Calouro não paga pela tabela de OVR na temporada de estreia — paga pela posição em que foi draftado. Quanto mais cedo a escolha, mais caro:</p>
    <div class="tabela-wrap">
      <table>
        <thead><tr><th>Posição no draft</th><th class="num">Custo no cap</th></tr></thead>
        <tbody>
          <tr><td>1ª rodada, picks 1–3</td><td class="num">18M</td></tr>
          <tr><td>1ª rodada, picks 4–8</td><td class="num">14M</td></tr>
          <tr><td>1ª rodada, picks 9–12</td><td class="num">12M</td></tr>
          <tr><td>1ª rodada, picks 13–16</td><td class="num">8M</td></tr>
          <tr><td>1ª rodada, picks 17–22</td><td class="num">5M</td></tr>
          <tr><td>1ª rodada, picks 23–30</td><td class="num">3M</td></tr>
          <tr><td>2ª rodada, qualquer posição</td><td class="num">2M</td></tr>
        </tbody>
      </table>
    </div>
    <p>Vale <strong>só no ano 1</strong>. Da segunda temporada em diante, o jogador passa a custar pela tabela de OVR como todo mundo.</p>

    <h3>A tag LENDA <span class="selo selo-lenda">Lenda</span> <span class="selo selo-elite">Elite</span></h3>
    <p>Cada franquia pode marcar <strong>um jogador</strong> como a sua lenda. O nome fica dourado e ganha a tag LENDA nas telas.</p>
    <p>Em troca do símbolo, tem um custo: a lenda <strong>ignora a tabela de OVR e passa a valer no mínimo 40M</strong> no seu cap, mais bônus e prêmios. Se o OVR dele passar de 94, a tabela normal volta a valer — a essa altura ela já cobra mais que os 40M.</p>
    <div class="nota amber">
      <p>Um jogador pode ser <strong>leal e lenda ao mesmo tempo</strong>, e as duas tags aparecem. Mas os benefícios de cap <strong>não se somam</strong>: com a lenda marcada, o Bônus de Lealdade dele é anulado.</p>
    </div>
    <p>Quem marca é o GM, no próprio elenco. Escolha com cuidado: é uma por time.</p>
  </section>

  <!-- ═══════════════ 6. ACOMPANHAR A LIGA ═══════════════ -->
  <section id="liga">
    <h2><span class="parte">Parte 6</span>Acompanhar a liga</h2>
    <p class="sub">As telas que não mexem no seu time, mas contam o que está acontecendo em volta.</p>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-table"></i></span><span class="aba-nome">Tabela</span></div>
      <p>Classificação e playoffs por temporada, nas quatro ligas. É aqui que você vê quem está na zona de acesso e quem está na de descenso.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-bar-chart-fill"></i></span><span class="aba-nome">Rankings</span></div>
      <p>Os melhores em cada recorte — de times e de jogadores.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-globe-americas"></i></span><span class="aba-nome">Mundo FBA</span></div>
      <p>A visão geral das quatro ligas, com projeções: chance de título, de subir e de cair, calculadas a partir da temporada em andamento.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-chat-square-text-fill"></i></span><span class="aba-nome">Timeline</span></div>
      <p>O feed da liga. Anúncios de contratação, provocação entre GMs, o que estiver rolando. Participe — metade da graça da FBA está aqui.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-trophy-fill"></i></span><span class="aba-nome">Prêmios e Hall da Fama</span></div>
      <p><strong>Prêmios</strong> traz as premiações da temporada. O <strong>Hall da Fama</strong> guarda quem marcou época — e conquista em liga mais alta pesa mais.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-controller"></i></span><span class="aba-nome">Games</span></div>
      <p>Os joguinhos da FBA. Jogando você acumula moedas e <strong>FBA Points</strong>, que são trocados por itens dentro dos jogos.</p>
    </div>

    <div class="aba">
      <div class="aba-cab"><span class="aba-ico"><i class="bi bi-whatsapp"></i></span><span class="aba-nome">O bot do grupo</span></div>
      <p>Nos grupos do WhatsApp da FBA — o principal e os Chat Off de cada liga — dá pra consultar tudo sem abrir o site. Escreva o comando começando com barra e ele responde ali mesmo:</p>
      <div class="tabela-wrap">
        <table>
          <thead><tr><th>Comando</th><th>O que responde</th></tr></thead>
          <tbody>
            <tr><td><code>/meucap</code></td><td>Sua folha, sem digitar o time</td></tr>
            <tr><td><code>/meuelenco</code></td><td>Seu elenco</td></tr>
            <tr><td><code>/minhaspicks</code></td><td>Suas picks</td></tr>
            <tr><td><code>/jogador lebron</code></td><td>Time, posição, idade, OVR e salário</td></tr>
            <tr><td><code>/comparar lebron x tatum</code></td><td>Dois jogadores lado a lado</td></tr>
            <tr><td><code>/comparartime lakers x celtics</code></td><td>Dois times lado a lado</td></tr>
            <tr><td><code>/time lakers</code></td><td>Elenco, folha, campanha e os melhores</td></tr>
            <tr><td><code>/cap lakers</code></td><td>Folha detalhada, espaço no CAP e maiores salários</td></tr>
            <tr><td><code>/picks lakers</code></td><td>Todas as picks do time, por ano</td></tr>
            <tr><td><code>/classificacao elite</code></td><td>A tabela da liga</td></tr>
            <tr><td><code>/trocas</code></td><td>As últimas trocas aprovadas</td></tr>
            <tr><td><code>/lendas</code></td><td>Quem está marcado como LENDA</td></tr>
            <tr><td><code>/hall</code></td><td>O Hall da Fama</td></tr>
            <tr><td><code>/premios</code></td><td>Os prêmios da temporada</td></tr>
            <tr><td><code>/ajuda</code></td><td>A lista completa</td></tr>
          </tbody>
        </table>
      </div>
      <p>Não precisa escrever o nome inteiro: <code>/cap lakers</code> basta. Se o pedaço servir pra mais de um time, ele lista as opções em vez de chutar. E no Chat Off de uma liga, <code>/classificacao</code> sozinho já responde a tabela daquela liga.</p>
      <p>Os comandos que começam com <strong>meu</strong> reconhecem você pelo telefone do seu perfil — não precisa digitar o time. Se ainda não cadastrou o número no site, o bot avisa.</p>
    </div>
  </section>

  <!-- ═══════════════ 7. GLOSSÁRIO ═══════════════ -->
  <section id="glossario">
    <h2><span class="parte">Parte 7</span>Glossário</h2>
    <p class="sub">Os termos que aparecem o tempo todo.</p>
    <dl>
      <dt>GM</dt><dd>General Manager. Você. O dono da franquia, responsável por elenco, trocas e draft.</dd>
      <dt>OVR</dt><dd>Overall. A nota geral do jogador. Manda no salário, no CAP e em quase tudo.</dd>
      <dt>CAP</dt><dd>O limite do elenco. Soma de OVR dos 10 melhores nas ligas NEXT, RISE e ROOKIE; folha salarial em milhões na ELITE.</dd>
      <dt>Piso salarial</dt><dd>O mínimo que a folha precisa alcançar. Ficar abaixo dele também é problema — não só estourar o teto.</dd>
      <dt>Pick</dt><dd>Uma escolha de draft. Identificada pelo ano e pela rodada, e negociável como qualquer ativo.</dd>
      <dt>Mock</dt><dd>Sua lista de preferências no draft. Com ela ativa, o sistema escolhe por você se não estiver online na sua vez.</dd>
      <dt>Rookie Scale</dt><dd>A tabela que define o salário do calouro no primeiro ano, pela posição do draft em vez do OVR.</dd>
      <dt>Jogador leal</dt><dd>Nunca foi trocado e veio do draft da própria franquia. Rende bônus de CAP.</dd>
      <dt>Lenda</dt><dd>Um jogador por franquia, marcado pelo GM. Nome dourado, e passa a valer no mínimo 40M no cap da ELITE.</dd>
      <dt>Acesso e descenso</dt><dd>Os 4 primeiros de cada liga sobem; os 4 últimos descem.</dd>
      <dt>Trade Deadline</dt><dd>O prazo final para trocas na temporada. Depois dele, o piso salarial passa a ser cobrado.</dd>
      <dt>Waiver / Dispensa</dt><dd>Cortar um jogador do elenco. Há um limite por período.</dd>
    </dl>
  </section>

  <footer class="rodape">
    <span>FBA Manager · Guia do GM</span>
    <a href="/login.php">Entrar</a>
    <a href="#">Voltar ao topo</a>
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
