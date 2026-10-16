<?php
/**
 * MESA TÁTICA — o rascunho de elenco do GM.
 *
 * Um tabuleiro pra pensar: arrasta o jogador pra onde quiser e a tela
 * responde na hora com o que aquele time seria — média de OVR, média de
 * idade e em que sistema ele renderia. Serve pra responder "e se eu
 * subisse o moleque?" sem precisar mexer na escalação de verdade.
 *
 * ── O QUE ELA NÃO FAZ, E É O PONTO ───────────────────────────────────
 *
 * NÃO MEXE NA ESCALAÇÃO OFICIAL. Nada daqui vai pro jogo, pro admin ou pro
 * adversário: é papel de rascunho, só do dono do time. Por isso ela é uma
 * aba separada do Meu Elenco em vez de um modo dentro dele — misturar as
 * duas numa tela só é como alguém salva sem querer o que era só ideia.
 *
 * ── SEM POSIÇÃO FIXA ─────────────────────────────────────────────────
 *
 * Pedido do Marcos: aqui o armador pode ir pro garrafão. A escalação de
 * verdade cobra posição; a mesa não cobra nada, porque a graça de rascunhar
 * é justamente testar o absurdo e ver o número responder.
 *
 * ── DE ONDE SAI O "SISTEMA" ──────────────────────────────────────────
 *
 * A nota de cada jogador em cada estilo vem PRONTA do servidor
 * (backend/sistema_proficiencia.php), e a tela só tira a média dos cinco
 * que estiverem em quadra. A fórmula continua existindo num lugar só — foi
 * duplicando conta que o /cap e o dashboard já discordaram do mesmo número.
 * Até a régua das estrelas desce do PHP, em vez de ser recopiada aqui.
 *
 * @see my-roster.php  a escalação de verdade, na outra aba
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/observador.php';
require_once __DIR__ . '/backend/sistema_proficiencia.php';
requireAuth();

$user = getUserSession();
$pdo  = db();
$team = timeDaTela($pdo, (int)$user['id']);
if (!$team) { header('Location: my-roster.php'); exit; }

$teamId = (int)$team['id'];

/* O elenco com as notas por sistema, do mesmo jeito que a Tática monta.
   @see api/tactics.php */
$st = $pdo->prepare('SELECT id, name, position, secondary_position, ovr, age, role, '
                    . sistemaColunasSql('') . ' FROM players WHERE team_id = ? ORDER BY ovr DESC, name');
$st->execute([$teamId]);
$elenco = [];
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $notas = [];
    foreach (array_keys(sistemaNomes()) as $chave) {
        $n = sistemaNotaDoJogador($p, $chave);
        if ($n !== null) $notas[$chave] = round($n, 1);
    }
    $elenco[] = [
        'id'   => (int)$p['id'],
        'nome' => $p['name'],
        'pos'  => $p['position'] . ($p['secondary_position'] ? '/' . $p['secondary_position'] : ''),
        'ovr'  => (int)$p['ovr'],
        'idade'=> (int)$p['age'],
        /* A função de hoje decide onde o jogador NASCE no tabuleiro: abrir a
           mesa já com a escalação atual é o ponto de partida de qualquer
           "e se" — ninguém rascunha do zero. */
        'zona' => ($p['role'] ?? '') === 'Titular' ? 'titulares'
                : (strtolower((string)($p['role'] ?? '')) === 'g-league' ? 'outros' : 'banco'),
        'sist' => $notas ?: null,
    ];
}

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Mesa Tática · FBA Manager</title>
<link rel="manifest" href="/manifest.json?v=3">
<meta name="theme-color" content="#fc0025">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<style>
:root{
  --red:#fc0025;
  --red-2:color-mix(in srgb, var(--red) 85%, white);
  --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
  --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
  --border-red:color-mix(in srgb, var(--red) 22%, transparent);
  --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
  --sidebar-w:260px;
  --font:'Montserrat',sans-serif; --num:'Oswald',sans-serif;
  --radius:14px; --radius-sm:10px; --radius-xs:6px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
}
</style>
<?php include __DIR__ . '/includes/shell-css.php'; ?>
<style>
/* ── AS ABAS ─────────────────────────────────────────────────────────*/
.abas{display:flex;gap:6px;padding:0 32px;margin-top:14px;border-bottom:1px solid var(--border)}
.abas a{padding:11px 18px;font-size:13.5px;font-weight:600;color:var(--text-2);
  border-bottom:2px solid transparent;margin-bottom:-1px}
.abas a:hover{color:var(--text)}
.abas a.on{color:var(--red);border-bottom-color:var(--red)}

/* ── O PAINEL QUE RESPONDE ───────────────────────────────────────────
   Gruda no topo: o sentido da mesa é ver o número mudar enquanto se move
   a peça, e número que sai da tela quando se rola não serve. */
.resumo{position:sticky;top:0;z-index:5;display:flex;gap:10px;flex-wrap:wrap;
  padding:14px 0;background:var(--bg)}
.cart{background:var(--panel);border:1px solid var(--border);border-radius:13px;
  padding:11px 16px;min-width:118px}
.cart b{display:block;font-family:var(--num);font-size:23px;line-height:1}
.cart span{font-size:11px;color:var(--text-2)}
.cart.alerta{border-color:color-mix(in srgb,var(--amber) 45%,transparent)}
.cart.alerta b{color:var(--amber)}

.sist{flex:1;min-width:280px;background:var(--panel);border:1px solid var(--border);
  border-radius:13px;padding:11px 16px}
.sist h4{font-size:10.5px;text-transform:uppercase;letter-spacing:1px;color:var(--text-3);
  margin-bottom:7px;font-weight:700}
.sist-l{display:flex;align-items:center;gap:9px;font-size:13px;padding:2px 0}
.sist-l b{flex:1;font-weight:600}
.sist-l .est{color:#f5c518;letter-spacing:1px}
.sist-l .nota{font-family:var(--num);color:var(--text-2);min-width:34px;text-align:right}

/* ── O TABULEIRO ─────────────────────────────────────────────────────*/
.mesa{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,300px),1fr));
  gap:14px;margin-top:4px}
.zona{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:14px;
  min-height:130px;transition:border-color var(--t) var(--ease),background var(--t) var(--ease)}
.zona.sobre{border-color:var(--red);background:var(--red-soft)}
.zona > h3{display:flex;align-items:center;gap:8px;font-size:13px;font-weight:700;
  margin-bottom:10px;text-transform:uppercase;letter-spacing:.6px}
.zona > h3 em{font-style:normal;font-size:11px;color:var(--text-3);font-weight:600;margin-left:auto}
/* Cinco é o limite do quinteto. Passar disso não trava — a mesa é rascunho —
   mas o contador fica vermelho pra não enganar quem só olha o número. */
.zona.cheia > h3 em{color:var(--red)}

.pc{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:11px;
  background:var(--panel-2);border:1px solid var(--border);margin-bottom:7px;cursor:grab;
  user-select:none;touch-action:manipulation}
.pc:active{cursor:grabbing}
.pc.arrastando{opacity:.4}
.pc .ovr{font-family:var(--num);font-size:17px;min-width:28px;text-align:center}
.pc .nm{flex:1;min-width:0;font-size:13px;font-weight:600;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.pc .nm small{display:block;font-weight:500;font-size:11px;color:var(--text-3)}
.pc .mover{display:flex;gap:3px}
.pc .mover button{width:26px;height:26px;border-radius:7px;border:1px solid var(--border-md);
  background:transparent;color:var(--text-3);cursor:pointer;font-size:11px;line-height:1}
.pc .mover button:hover{border-color:var(--red);color:var(--red)}
.vazia{font-size:12.5px;color:var(--text-3);padding:10px 2px}

.aviso{display:flex;gap:10px;align-items:flex-start;background:var(--panel);
  border:1px solid var(--border);border-left:3px solid var(--blue);border-radius:12px;
  padding:12px 15px;margin:14px 0;font-size:12.5px;color:var(--text-2);line-height:1.55}
.aviso i{color:var(--blue);font-size:15px;margin-top:1px}
.barra{display:flex;gap:9px;flex-wrap:wrap;margin:14px 0 0}
.bt{display:inline-flex;align-items:center;gap:7px;padding:9px 15px;border-radius:11px;
  border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);
  font:inherit;font-size:12.5px;font-weight:600;cursor:pointer}
.bt:hover{border-color:var(--red)}
.salvo{font-size:12px;color:var(--text-3);align-self:center}

@media (max-width:992px){ .abas{padding:0 16px} }
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">Mesa <em>Tática</em></div>
</header>

<main class="main">
  <div class="dash-hero">
    <div>
      <div class="dash-eyebrow"><?= h($team['city'] . ' ' . $team['name']) ?></div>
      <h1 class="dash-title">Mesa Tática</h1>
      <p class="dash-sub">Monte times no rascunho e veja no que dá.</p>
    </div>
  </div>

  <nav class="abas">
    <a href="/my-roster.php">Escalação</a>
    <a href="/mesa-tatica.php" class="on">Mesa tática</a>
  </nav>

  <div class="content">
    <div class="aviso">
      <i class="bi bi-eye-slash"></i>
      <div><b>Isto é rascunho e é só seu.</b> Nada daqui muda a sua escalação, nem aparece
        pro admin ou pros outros GMs. A mesa abre com o time de hoje e dali você mexe à
        vontade — inclusive pondo jogador em posição que não é a dele.</div>
    </div>

    <div class="resumo">
      <div class="cart"><b id="nQuinteto">0</b><span>em quadra</span></div>
      <div class="cart"><b id="nOvr">—</b><span>OVR médio</span></div>
      <div class="cart"><b id="nIdade">—</b><span>idade média</span></div>
      <div class="cart"><b id="nAlto">—</b><span>maior OVR</span></div>
      <div class="sist">
        <h4>Em que sistema esse time joga</h4>
        <div id="sistemas"><div class="vazia">Ponha cinco em quadra pra ver.</div></div>
      </div>
    </div>

    <div class="mesa">
      <div class="zona" id="z-titulares" data-zona="titulares">
        <h3><i class="bi bi-star-fill"></i> Em quadra <em><span id="c-titulares">0</span>/5</em></h3>
        <div class="lista"></div>
      </div>
      <div class="zona" id="z-banco" data-zona="banco">
        <h3><i class="bi bi-people"></i> Banco <em><span id="c-banco">0</span></em></h3>
        <div class="lista"></div>
      </div>
      <div class="zona" id="z-outros" data-zona="outros">
        <h3><i class="bi bi-box-arrow-down"></i> Outros / G-League <em><span id="c-outros">0</span></em></h3>
        <div class="lista"></div>
      </div>
    </div>

    <div class="barra">
      <button class="bt" id="bHoje"><i class="bi bi-arrow-counterclockwise"></i> Voltar ao time de hoje</button>
      <button class="bt" id="bLimpar"><i class="bi bi-eraser"></i> Esvaziar a quadra</button>
      <span class="salvo" id="salvo"></span>
    </div>
  </div>
</main>

<script src="/js/popups.js"></script>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>
<script>
const ELENCO = <?= json_encode($elenco, JSON_UNESCAPED_UNICODE) ?>;
const NOMES  = <?= json_encode(sistemaNomes(), JSON_UNESCAPED_UNICODE) ?>;
/* A régua das estrelas DESCE DO PHP. Recopiar os cortes aqui era o jeito
   mais fácil de a mesa e o resto do site discordarem na primeira vez que
   alguém mexesse num número. @see backend/sistema_proficiencia.php */
const CORTES = <?= json_encode(SISTEMA_ESTRELAS, JSON_UNESCAPED_UNICODE) ?>;
const ZONAS  = ['titulares', 'bancos', 'outros'].map(z => z === 'bancos' ? 'banco' : z);
const CHAVE_SALVA = 'fba-mesa-<?= $teamId ?>';

/* O estado é só "onde está cada jogador". Guardado no aparelho: rascunho é
   coisa de quem está pensando, e pensar não precisa de linha no banco nem
   de botão de salvar — some se ele limpar o navegador, e tudo bem, porque
   "Voltar ao time de hoje" reconstrói o ponto de partida em um clique. */
let onde = {};

function carregar() {
  let salvo = null;
  try { salvo = JSON.parse(localStorage.getItem(CHAVE_SALVA) || 'null'); } catch (e) {}
  onde = {};
  ELENCO.forEach(p => {
    const z = salvo && salvo[p.id];
    onde[p.id] = ZONAS.includes(z) ? z : p.zona;
  });
  if (salvo) marcarSalvo('rascunho de antes, recuperado');
}

function guardar() {
  try { localStorage.setItem(CHAVE_SALVA, JSON.stringify(onde)); } catch (e) {}
  marcarSalvo('rascunho guardado neste aparelho');
}
let tSalvo;
function marcarSalvo(txt) {
  const el = document.getElementById('salvo');
  el.textContent = txt;
  clearTimeout(tSalvo);
  tSalvo = setTimeout(() => { el.textContent = ''; }, 2600);
}

function estrelasDe(nota) {
  if (nota === null) return 0;
  for (const f of CORTES) if (nota >= f.min) return f.estrelas;
  return 1;
}

function cartao(p) {
  const d = document.createElement('div');
  d.className = 'pc';
  d.draggable = true;
  d.dataset.id = p.id;
  d.innerHTML = `<span class="ovr">${p.ovr}</span>
    <span class="nm">${p.nome}<small>${p.pos} · ${p.idade} anos</small></span>
    <span class="mover">
      <button type="button" data-para="titulares" title="Pra quadra"><i class="bi bi-star"></i></button>
      <button type="button" data-para="banco" title="Pro banco"><i class="bi bi-people"></i></button>
      <button type="button" data-para="outros" title="Pra fora"><i class="bi bi-box-arrow-down"></i></button>
    </span>`;
  d.addEventListener('dragstart', e => {
    e.dataTransfer.setData('text/plain', String(p.id));
    d.classList.add('arrastando');
  });
  d.addEventListener('dragend', () => d.classList.remove('arrastando'));
  /* OS BOTÕES EXISTEM POR CAUSA DO CELULAR: arrastar com o dedo numa página
     que rola é briga perdida, e sem eles a mesa só funcionaria no PC. */
  d.querySelectorAll('.mover button').forEach(b => {
    b.addEventListener('click', () => { onde[p.id] = b.dataset.para; desenhar(); guardar(); });
  });
  return d;
}

function desenhar() {
  ZONAS.forEach(z => {
    const lista = document.querySelector('#z-' + z + ' .lista');
    lista.innerHTML = '';
    const dentro = ELENCO.filter(p => onde[p.id] === z);
    dentro.sort((a, b) => b.ovr - a.ovr);
    if (!dentro.length) {
      lista.innerHTML = '<div class="vazia">Arraste alguém pra cá.</div>';
    } else {
      dentro.forEach(p => lista.appendChild(cartao(p)));
    }
    document.getElementById('c-' + z).textContent = dentro.length;
  });

  const q = ELENCO.filter(p => onde[p.id] === 'titulares');
  document.getElementById('z-titulares').classList.toggle('cheia', q.length > 5);
  document.getElementById('nQuinteto').textContent = q.length;
  const media = (arr, f) => arr.length ? (arr.reduce((s, x) => s + f(x), 0) / arr.length) : null;
  const mOvr = media(q, p => p.ovr), mIdade = media(q, p => p.idade);
  document.getElementById('nOvr').textContent   = mOvr   === null ? '—' : mOvr.toFixed(1);
  document.getElementById('nIdade').textContent = mIdade === null ? '—' : mIdade.toFixed(1);
  document.getElementById('nAlto').textContent  = q.length ? Math.max(...q.map(p => p.ovr)) : '—';

  // Quinteto incompleto ou estufado: o número existe, mas avisa que é de outro tamanho.
  document.querySelectorAll('.resumo .cart').forEach(c => c.classList.remove('alerta'));
  if (q.length && q.length !== 5) {
    document.getElementById('nQuinteto').parentElement.classList.add('alerta');
  }

  desenharSistemas(q);
}

/* A MÉDIA DOS CINCO EM CADA SISTEMA. A nota de cada jogador veio pronta do
   servidor; aqui é média aritmética, e quem não tem ficha técnica
   simplesmente não entra na conta — zerar quem ninguém mediu faria o time
   parecer ruim em vez de desconhecido, que é a mesma regra do PHP. */
function desenharSistemas(q) {
  const alvo = document.getElementById('sistemas');
  const comFicha = q.filter(p => p.sist);
  if (comFicha.length < 1) {
    alvo.innerHTML = '<div class="vazia">' + (q.length
      ? 'Ninguém em quadra tem ficha técnica lançada.'
      : 'Ponha cinco em quadra pra ver.') + '</div>';
    return;
  }
  const linhas = Object.keys(NOMES).map(k => {
    const notas = comFicha.map(p => p.sist[k]).filter(n => n != null);
    const m = notas.length ? notas.reduce((a, b) => a + b, 0) / notas.length : null;
    return { nome: NOMES[k], nota: m };
  }).filter(x => x.nota !== null).sort((a, b) => b.nota - a.nota).slice(0, 3);

  alvo.innerHTML = linhas.map(l => {
    const e = estrelasDe(l.nota);
    return `<div class="sist-l"><b>${l.nome}</b>
      <span class="est">${'★'.repeat(e)}${'☆'.repeat(5 - e)}</span>
      <span class="nota">${l.nota.toFixed(1)}</span></div>`;
  }).join('') + (comFicha.length < q.length
    ? `<div class="vazia">${q.length - comFicha.length} em quadra sem ficha técnica — não entram na conta.</div>`
    : '');
}

ZONAS.forEach(z => {
  const el = document.getElementById('z-' + z);
  el.addEventListener('dragover', e => { e.preventDefault(); el.classList.add('sobre'); });
  el.addEventListener('dragleave', () => el.classList.remove('sobre'));
  el.addEventListener('drop', e => {
    e.preventDefault();
    el.classList.remove('sobre');
    const id = parseInt(e.dataTransfer.getData('text/plain'), 10);
    if (!id) return;
    onde[id] = z;
    desenhar(); guardar();
  });
});

document.getElementById('bHoje').addEventListener('click', () => {
  ELENCO.forEach(p => { onde[p.id] = p.zona; });
  desenhar(); guardar(); marcarSalvo('voltou pro time de hoje');
});
document.getElementById('bLimpar').addEventListener('click', () => {
  ELENCO.forEach(p => { if (onde[p.id] === 'titulares') onde[p.id] = 'banco'; });
  desenhar(); guardar();
});

carregar();
desenhar();
</script>
</body>
</html>
