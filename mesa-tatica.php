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
$liga   = strtoupper((string)$team['league']);

/**
 * Monta a ficha que a mesa entende, com as notas por sistema já resolvidas.
 * Mesma forma pro elenco da casa e pra quem vem emprestado de outro time.
 */
function mesaFicha(array $p, bool $deFora = false): array
{
    $notas = [];
    foreach (array_keys(sistemaNomes()) as $chave) {
        $n = sistemaNotaDoJogador($p, $chave);
        if ($n !== null) $notas[$chave] = round($n, 1);
    }
    return [
        'id'    => (int)$p['id'],
        'nome'  => $p['name'],
        'pos'   => $p['position'] . ($p['secondary_position'] ? '/' . $p['secondary_position'] : ''),
        'ovr'   => (int)$p['ovr'],
        'idade' => (int)$p['age'],
        'zona'  => $deFora ? 'banco'
                 : (($p['role'] ?? '') === 'Titular' ? 'titulares'
                 : (strtolower((string)($p['role'] ?? '')) === 'g-league' ? 'outros' : 'banco')),
        'sist'  => $notas ?: null,
        /* De fora = de outro time. A tela pinta em cinza e escreve de quem é:
           num rascunho que mistura elenco e cobiça, confundir os dois é o
           erro que estraga a conta. */
        'fora'  => $deFora ? ($p['time_dono'] ?? 'outro time') : null,
    ];
}

/* ── O EMPRÉSTIMO ────────────────────────────────────────────────────
   Pedido do Marcos: trazer jogador de outro time pra mesa, pra desenhar o
   time que ele QUERIA ter. Só leitura e só da mesma liga — o que entra aqui
   não muda nada em lugar nenhum, é o mesmo rascunho de sempre.

   Duas entradas: `busca` procura pelo nome e `ids` recarrega os que já
   estavam no rascunho quando a página reabre. */
if (isset($_GET['busca']) || isset($_GET['ids'])) {
    header('Content-Type: application/json; charset=utf-8');
    $sel = 'SELECT p.id, p.name, p.position, p.secondary_position, p.ovr, p.age, '
         . "CONCAT(t.city,' ',t.name) AS time_dono, " . sistemaColunasSql('p')
         . ' FROM players p JOIN teams t ON t.id = p.team_id
            WHERE t.league = ? AND p.team_id <> ?';

    if (isset($_GET['ids'])) {
        $ids = array_values(array_filter(array_map('intval', explode(',', (string)$_GET['ids']))));
        if (!$ids) { echo '[]'; exit; }
        $ids = array_slice($ids, 0, 40);
        $sel .= ' AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        $st = $pdo->prepare($sel);
        $st->execute(array_merge([$liga, $teamId], $ids));
    } else {
        $termo = trim((string)$_GET['busca']);
        if (mb_strlen($termo) < 2) { echo '[]'; exit; }
        $st = $pdo->prepare($sel . ' AND p.name LIKE ? ORDER BY p.ovr DESC, p.name LIMIT 12');
        $st->execute([$liga, $teamId, '%' . $termo . '%']);
    }

    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) $out[] = mesaFicha($p, true);
    echo json_encode($out, JSON_UNESCAPED_UNICODE);
    exit;
}

/* O elenco com as notas por sistema, do mesmo jeito que a Tática monta.
   @see api/tactics.php */
$st = $pdo->prepare('SELECT id, name, position, secondary_position, ovr, age, role, '
                    . sistemaColunasSql('') . ' FROM players WHERE team_id = ? ORDER BY ovr DESC, name');
$st->execute([$teamId]);
/* A função de hoje decide onde o jogador NASCE no tabuleiro: abrir a mesa
   já com a escalação atual é o ponto de partida de qualquer "e se" —
   ninguém rascunha do zero. @see mesaFicha */
$elenco = array_map(fn($p) => mesaFicha($p), $st->fetchAll(PDO::FETCH_ASSOC));

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

/* ── O TABULEIRO ─────────────────────────────────────────────────────
   A quadra pede mais largura que as listas: ela fica com quase metade e as
   outras duas zonas dividem o resto. */
.mesa{display:grid;grid-template-columns:minmax(0,1.25fr) minmax(0,1fr) minmax(0,1fr);
  gap:14px;margin-top:4px}
@media (max-width:1100px){ .mesa{grid-template-columns:1fr 1fr} #z-titulares{grid-column:1/-1} }
@media (max-width:700px){ .mesa{grid-template-columns:1fr} }

/* ── A QUADRA ────────────────────────────────────────────────────────
   O traçado e as marcas vêm de js/quadra-desenho.js, os mesmos do Meu
   Elenco. O que muda é a regra: aqui o lugar não cobra posição. */
/* Teto de largura: sozinha numa coluna larga a quadra vira um pôster e
   empurra o resto da mesa pra fora da tela. */
.qd-quadra{position:relative;aspect-ratio:500/470;max-width:430px;margin:0 auto;
  border-radius:12px;overflow:hidden;
  border:1px solid var(--border);
  background:radial-gradient(120% 90% at 50% 100%,
    color-mix(in srgb,var(--red) 10%,var(--panel-2)),var(--panel-2) 70%)}
.qd-quadra svg{position:absolute;inset:0;width:100%;height:100%;pointer-events:none}
.qd-quadra svg *{fill:none;stroke:var(--border-md);stroke-width:2;vector-effect:non-scaling-stroke}
.lugar{position:absolute;transform:translate(-50%,-50%);width:92px;display:flex;
  flex-direction:column;align-items:center;gap:3px;cursor:grab;user-select:none}
.lugar:active{cursor:grabbing}
.lugar .bola{display:grid;place-items:center;width:52px;height:52px;border-radius:50%;
  background:var(--panel);border:2px solid var(--marca,var(--border-md));
  font-family:var(--num);font-size:20px;line-height:1}
.lugar.vazio .bola{border-style:dashed;color:var(--text-3);font-size:11px;font-family:var(--font);
  font-weight:600}
.lugar .quem{font-size:11.5px;font-weight:700;text-align:center;line-height:1.2;
  max-width:92px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.lugar .dele{font-size:10px;color:var(--text-3)}
/* O lugar aceso enquanto se arrasta por cima: sem isso não dá pra saber
   onde o jogador vai cair. */
.lugar.sobre .bola{border-color:var(--red);box-shadow:0 0 0 4px var(--red-soft)}
/* Fora de posição não é erro aqui — é a graça. Mas fica marcado, senão
   ninguém repara que botou o pivô na ponta. */
.lugar.trocado .dele{color:var(--amber)}
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

/* ── TRAZER DE OUTRO TIME ────────────────────────────────────────────*/
.buscar{position:relative;margin-top:16px}
.buscar > i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-3);font-size:14px}
.buscar input{width:100%;max-width:460px;padding:11px 14px 11px 38px;border-radius:12px;
  border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);font:inherit;font-size:13px}
.buscar input:focus{outline:none;border-color:var(--red)}
.achados{position:absolute;z-index:20;top:100%;left:0;margin-top:6px;width:100%;max-width:460px;
  background:var(--panel);border:1px solid var(--border-md);border-radius:12px;overflow:hidden;
  box-shadow:0 18px 40px rgba(0,0,0,.5)}
.achados button{display:flex;align-items:center;gap:10px;width:100%;padding:10px 14px;border:0;
  background:transparent;color:var(--text);font:inherit;font-size:13px;cursor:pointer;text-align:left}
.achados button:hover{background:var(--panel-2)}
.achados .o{font-family:var(--num);font-size:16px;min-width:26px}
.achados .t{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.achados .t small{display:block;font-size:11px;color:var(--text-3)}
.achados .nada{padding:12px 14px;font-size:12.5px;color:var(--text-3)}
/* ── QUEM VEIO DE FORA ───────────────────────────────────────────────
   Cinza e sem cor nenhuma: é o jeito de a tela nunca deixar esquecer que
   aquele jogador não é do time. */
.pc.fora,.lugar.fora{filter:grayscale(1);opacity:.78}
.pc.fora{border-style:dashed}
.pc.fora .nm small::after,.lugar.fora .dele::after{content:' · emprestado';color:var(--text-3)}
.lugar.fora .bola{border-style:dashed}

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
        <?php /* ── O CAMPINHO ──────────────────────────────────────────
             A mesma meia quadra do Meu Elenco (js/quadra-desenho.js), mas
             aqui os cinco lugares são só MARCAS: qualquer um cabe em
             qualquer um deles, inclusive o armador no garrafão. A etiqueta
             mostra a posição de verdade do jogador que está ali — é assim
             que se enxerga, de um relance, que o time está jogando com
             quatro alas. */ ?>
        <div class="qd-quadra" id="quadra"></div>
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

    <?php /* ── TRAZER DE OUTRO TIME ────────────────────────────────────
         Pra desenhar o time que ele QUERIA ter. Quem vem de fora fica em
         cinza e com o nome do dono embaixo: num rascunho que mistura elenco
         e cobiça, confundir os dois é o erro que estraga a conta. */ ?>
    <div class="buscar">
      <i class="bi bi-search"></i>
      <input id="qBusca" type="search" autocomplete="off"
             placeholder="Trazer jogador de outro time da <?= h($liga) ?>…">
      <div class="achados" id="achados" hidden></div>
    </div>

    <div class="barra">
      <button class="bt" id="bCopiar"><i class="bi bi-clipboard"></i> Copiar time</button>
      <button class="bt" id="bHoje"><i class="bi bi-arrow-counterclockwise"></i> Voltar ao time de hoje</button>
      <button class="bt" id="bDevolver" hidden><i class="bi bi-person-dash"></i>
        Devolver os emprestados (<span id="nFora">0</span>)</button>
      <button class="bt" id="bLimpar"><i class="bi bi-eraser"></i> Esvaziar a quadra</button>
      <span class="salvo" id="salvo"></span>
    </div>
  </div>
</main>

<script src="/js/popups.js"></script>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>
<script src="/js/quadra-desenho.js"></script>
<script>
const ELENCO = <?= json_encode($elenco, JSON_UNESCAPED_UNICODE) ?>;
const NOMES  = <?= json_encode(sistemaNomes(), JSON_UNESCAPED_UNICODE) ?>;
/* A régua das estrelas DESCE DO PHP. Recopiar os cortes aqui era o jeito
   mais fácil de a mesa e o resto do site discordarem na primeira vez que
   alguém mexesse num número. @see backend/sistema_proficiencia.php */
const CORTES = <?= json_encode(SISTEMA_ESTRELAS, JSON_UNESCAPED_UNICODE) ?>;
const ZONAS  = ['titulares', 'bancos', 'outros'].map(z => z === 'bancos' ? 'banco' : z);
const TIME_NOME = <?= json_encode(trim($team['city'] . ' ' . $team['name']), JSON_UNESCAPED_UNICODE) ?>;
const CHAVE_SALVA = 'fba-mesa-<?= $teamId ?>';

/* O estado é só "onde está cada jogador". Guardado no aparelho: rascunho é
   coisa de quem está pensando, e pensar não precisa de linha no banco nem
   de botão de salvar — some se ele limpar o navegador, e tudo bem, porque
   "Voltar ao time de hoje" reconstrói o ponto de partida em um clique. */
let onde = {};

/* ELENCO é a lista viva da mesa: nasce com o time da casa e cresce com
   quem for trazido de outro time. Por isso deixou de ser const. */
let ELENCO_MESA = ELENCO.slice();
/* marca da quadra -> id do jogador que o GM pôs ali à mão */
let fixo = {};

async function carregar() {
  let salvo = null;
  try { salvo = JSON.parse(localStorage.getItem(CHAVE_SALVA) || 'null'); } catch (e) {}
  /* Formato antigo do rascunho era só {id: zona}; o novo tem os
     emprestados junto. Ler os dois evita que quem já rascunhou perca tudo. */
  const zonas = salvo && salvo.onde ? salvo.onde : (salvo || {});
  const fora  = (salvo && salvo.fora) || [];
  fixo = (salvo && salvo.fixo) || {};

  if (fora.length) {
    try {
      const r = await fetch('?ids=' + fora.join(','));
      const lista = await r.json();
      lista.forEach(p => { if (!ELENCO_MESA.some(x => x.id === p.id)) ELENCO_MESA.push(p); });
    } catch (e) { /* sem rede o rascunho abre só com o time da casa */ }
  }

  onde = {};
  ELENCO_MESA.forEach(p => {
    const z = zonas[p.id];
    onde[p.id] = ZONAS.includes(z) ? z : p.zona;
  });
  if (salvo) marcarSalvo('rascunho de antes, recuperado');
}

function guardar() {
  const fora = ELENCO_MESA.filter(p => p.fora).map(p => p.id);
  try { localStorage.setItem(CHAVE_SALVA, JSON.stringify({ onde, fora, fixo })); } catch (e) {}
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
  d.className = 'pc' + (p.fora ? ' fora' : '');
  d.draggable = true;
  d.dataset.id = p.id;
  d.innerHTML = `<span class="ovr">${p.ovr}</span>
    <span class="nm">${p.nome}<small>${p.pos} · ${p.idade} anos${p.fora ? ' · ' + p.fora : ''}</small></span>
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
      b.addEventListener('click', () => {
        const para = b.dataset.para;
        /* Pelo botão "pra quadra" ele cai na primeira marca livre; não
           havendo nenhuma, na que combina com a posição dele, e aí quem
           estava lá desce pro banco. */
        if (para === 'titulares') {
          const livre = MARCAS.find(m => !fixo[m])
                     || String(p.pos).split('/').find(x => MARCAS.includes(x))
                     || MARCAS[0];
          porNaMarca(p.id, livre);
        } else {
          tirarDaQuadra(p.id, para);
        }
        desenhar(); guardar();
      });
  });
  return d;
}

/* ── A QUADRA ──────────────────────────────────────────────────────────
   Cinco marcas, e quem está "em quadra" ocupa uma delas. A marca NÃO cobra
   posição: ela é só o lugar no desenho, e a etiqueta embaixo diz a posição
   real de quem está ali — em laranja quando não bate, que é o sinal de que
   o time está improvisando. */
const D = window.QuadraDesenho || {};
const MARCAS = D.POS || ['PG','SG','SF','PF','C'];

function quintetoEmOrdem() {
  /* QUEM O GM COLOCOU NA MARCA MANDA. O arrasto fixa o lugar (`fixo`), e só
     o que sobra é arrumado sozinho: pela posição do jogador quando ela
     existe, pelo que vagar quando não existe. Sem a parte fixa, arrastar o
     pivô pra ponta não adiantava nada — o desenho recalculava tudo no frame
     seguinte e devolvia ele pro garrafão. */
  const fila = ELENCO_MESA.filter(p => onde[p.id] === 'titulares');
  const ocupadas = {};
  const pendentes = [];

  fila.forEach(p => {
    const marca = Object.keys(fixo).find(m => fixo[m] === p.id);
    if (marca && !ocupadas[marca]) ocupadas[marca] = p; else pendentes.push(p);
  });
  const sobra = [];
  pendentes.forEach(p => {
    const dele = String(p.pos).split('/');
    const vaga = MARCAS.find(m => dele.includes(m) && !ocupadas[m]);
    if (vaga) ocupadas[vaga] = p; else sobra.push(p);
  });
  MARCAS.forEach(m => { if (!ocupadas[m] && sobra.length) ocupadas[m] = sobra.shift(); });
  return { ocupadas, sobra };
}

/**
 * Põe o jogador numa marca da quadra.
 *
 * QUEM ESTAVA ALI VAI PRO BANCO — pedido do Marcos, e é o que faz sentido:
 * quando se promove alguém a titular, o que saiu continua no elenco, não
 * desaparece nem vai parar na G-League. Vale inclusive quando a troca é
 * entre dois que já estavam em quadra: aí os dois só trocam de marca, e
 * ninguém desce.
 */
function porNaMarca(id, marca) {
  /* QUEM ESTÁ NA MARCA É QUEM O DESENHO MOSTRA ALI, e não só quem foi
     fixado à mão: a maior parte do quinteto chega à marca pelo arranjo
     automático, e olhar só o `fixo` fazia o titular de verdade escapar pra
     outra marca livre em vez de descer — o contrário do que foi pedido. */
  const atuais = quintetoEmOrdem().ocupadas;
  const atual = atuais[marca] ? atuais[marca].id : null;
  const marcaDeOrigem = Object.keys(atuais).find(m => atuais[m].id === id);
  const eraTitular = onde[id] === 'titulares';

  if (atual && atual !== id) {
    if (eraTitular && marcaDeOrigem) {
      fixo[marcaDeOrigem] = atual;      // troca de lugares, os dois seguem em quadra
    } else {
      onde[atual] = 'banco';            // promoção: o que estava no lugar desce
      delete fixo[marca];
    }
  } else if (marcaDeOrigem && marcaDeOrigem !== marca) {
    delete fixo[marcaDeOrigem];
  }

  onde[id] = 'titulares';
  fixo[marca] = id;
}

/** Tira o jogador da quadra: some da marca e vai pra zona pedida. */
function tirarDaQuadra(id, zona) {
  const m = Object.keys(fixo).find(k => fixo[k] === id);
  if (m) delete fixo[m];
  onde[id] = zona;
}

function desenharQuadra() {
  const el = document.getElementById('quadra');
  const { ocupadas, sobra } = quintetoEmOrdem();
  let html = D.SVG || '';

  MARCAS.forEach(m => {
    const [x, y] = (D.LUGAR || {})[m] || [50, 50];
    const p = ocupadas[m];
    const cor = (D.CORES || {})[m] || 'var(--border-md)';
    const foraDePosicao = p && !String(p.pos).split('/').includes(m);
    html += `<div class="lugar ${p ? '' : 'vazio'} ${foraDePosicao ? 'trocado' : ''} ${p && p.fora ? 'fora' : ''}"
                  data-marca="${m}" ${p ? `data-id="${p.id}" draggable="true"` : ''}
                  style="left:${x}%;top:${y}%;--marca:${cor}">
      <span class="bola">${p ? p.ovr : m}</span>
      ${p ? `<span class="quem">${p.nome}</span>
             <span class="dele">${p.pos}${foraDePosicao ? ' na vaga de ' + m : ''}</span>` : ''}
    </div>`;
  });
  el.innerHTML = html;

  /* Passou de cinco: o excedente não some da conta nem da tela, aparece
     listado embaixo. Sumir seria mentir sobre o que está em quadra. */
  if (sobra.length) {
    const extra = document.createElement('div');
    extra.className = 'vazia';
    extra.style.cssText = 'position:absolute;left:8px;right:8px;bottom:6px;text-align:center';
    extra.textContent = 'Sem lugar no desenho: ' + sobra.map(p => p.nome).join(', ');
    el.appendChild(extra);
  }

  el.querySelectorAll('.lugar').forEach(l => {
    const id = parseInt(l.dataset.id || '0', 10);
    if (id) {
      l.addEventListener('dragstart', e => e.dataTransfer.setData('text/plain', String(id)));
      /* Tocar no jogador da quadra manda ele pro banco: no celular é o
         gesto que substitui arrastar pra fora. */
      l.addEventListener('click', () => { tirarDaQuadra(id, 'banco'); desenhar(); guardar(); });
    }
    l.addEventListener('dragover', e => { e.preventDefault(); l.classList.add('sobre'); });
    l.addEventListener('dragleave', () => l.classList.remove('sobre'));
    l.addEventListener('drop', e => {
      e.preventDefault(); e.stopPropagation();
      l.classList.remove('sobre');
      const vindo = parseInt(e.dataTransfer.getData('text/plain'), 10);
      if (!vindo || vindo === id) return;
      porNaMarca(vindo, l.dataset.marca);
      desenhar(); guardar();
    });
  });
}

function desenhar() {
  desenharQuadra();
  ZONAS.filter(z => z !== 'titulares').forEach(z => {
    const lista = document.querySelector('#z-' + z + ' .lista');
    lista.innerHTML = '';
    const dentro = ELENCO_MESA.filter(p => onde[p.id] === z);
    dentro.sort((a, b) => b.ovr - a.ovr);
    if (!dentro.length) {
      lista.innerHTML = '<div class="vazia">Arraste alguém pra cá.</div>';
    } else {
      dentro.forEach(p => lista.appendChild(cartao(p)));
    }
    document.getElementById('c-' + z).textContent = dentro.length;
  });

  const q = ELENCO_MESA.filter(p => onde[p.id] === 'titulares');
  document.getElementById('c-titulares').textContent = q.length;
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

  const fora = ELENCO_MESA.filter(p => p.fora).length;
  document.getElementById('bDevolver').hidden = !fora;
  document.getElementById('nFora').textContent = fora;

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
  fixo = {};
  ELENCO_MESA.forEach(p => { onde[p.id] = p.zona; });
  desenhar(); guardar(); marcarSalvo('voltou pro time de hoje');
});
document.getElementById('bLimpar').addEventListener('click', () => {
  fixo = {};
  ELENCO_MESA.forEach(p => { if (onde[p.id] === 'titulares') onde[p.id] = 'banco'; });
  desenhar(); guardar();
});

/* ── A BUSCA DE QUEM VEM DE FORA ──────────────────────────────────────
   Procura pelo nome entre os outros times da liga e põe no banco da mesa.
   Espera o dedo parar antes de perguntar ao servidor: sem isso seria uma
   consulta por tecla digitada. */
const qBusca  = document.getElementById('qBusca');
const achados = document.getElementById('achados');
let tBusca;

function fecharAchados() { achados.hidden = true; achados.innerHTML = ''; }

qBusca.addEventListener('input', () => {
  clearTimeout(tBusca);
  const termo = qBusca.value.trim();
  if (termo.length < 2) { fecharAchados(); return; }
  tBusca = setTimeout(async () => {
    let lista = [];
    try {
      const r = await fetch('?busca=' + encodeURIComponent(termo));
      lista = await r.json();
    } catch (e) { return; }
    // Quem já está na mesa sai da lista: trazer duas vezes só confundiria.
    lista = lista.filter(p => !ELENCO_MESA.some(x => x.id === p.id));
    achados.innerHTML = '';
    if (!lista.length) {
      achados.innerHTML = '<div class="nada">Ninguém com esse nome em outro time da liga.</div>';
      achados.hidden = false;
      return;
    }
    lista.forEach(p => {
      const b = document.createElement('button');
      b.type = 'button';
      b.innerHTML = '<span class="o">' + p.ovr + '</span>'
        + '<span class="t">' + p.nome + '<small>' + p.pos + ' · ' + p.idade
        + ' anos · ' + p.fora + '</small></span><i class="bi bi-plus-lg"></i>';
      b.addEventListener('click', () => {
        ELENCO_MESA.push(p);
        onde[p.id] = 'banco';
        qBusca.value = '';
        fecharAchados();
        desenhar(); guardar();
        marcarSalvo(p.nome + ' entrou no banco da mesa');
      });
      achados.appendChild(b);
    });
    achados.hidden = false;
  }, 280);
});
document.addEventListener('click', e => {
  if (!e.target.closest('.buscar')) fecharAchados();
});

/* Devolver todos de uma vez: emprestado espalhado pelo tabuleiro é chato de
   caçar um por um quando se quer voltar a olhar o time de verdade. */
document.getElementById('bDevolver').addEventListener('click', () => {
  ELENCO_MESA = ELENCO_MESA.filter(p => !p.fora);
  Object.keys(onde).forEach(id => {
    if (!ELENCO_MESA.some(p => String(p.id) === String(id))) delete onde[id];
  });
  desenhar(); guardar(); marcarSalvo('emprestados devolvidos');
});

/* ── COPIAR O TIME ────────────────────────────────────────────────────
   O formato é o que se manda no grupo: a marca da quadra, o nome, o OVR e
   a idade. Quem veio de outro time leva o dono entre parênteses — colar um
   rascunho no grupo sem isso é anunciar contratação que não houve. */
function textoDoTime() {
  const { ocupadas, sobra } = quintetoEmOrdem();
  const linha = p => p.nome + ' ' + p.ovr + '/' + p.idade + 'y'
                   + (p.fora ? ' (' + p.fora + ')' : '');

  const fora = [];
  MARCAS.forEach(m => { if (ocupadas[m]) fora.push(m + ': ' + linha(ocupadas[m])); });
  sobra.forEach(p => fora.push('--: ' + linha(p)));

  const banco = ELENCO_MESA.filter(p => onde[p.id] === 'banco').sort((a, b) => b.ovr - a.ovr);
  const outros = ELENCO_MESA.filter(p => onde[p.id] === 'outros').sort((a, b) => b.ovr - a.ovr);

  const q = ELENCO_MESA.filter(p => onde[p.id] === 'titulares');
  const med = (arr, f) => arr.length ? (arr.reduce((s, x) => s + f(x), 0) / arr.length).toFixed(1) : '-';

  let t = TIME_NOME + '\n\n' + (fora.length ? fora.join('\n') : '(ninguém em quadra)');
  if (banco.length)  t += '\n\nBanco:\n'  + banco.map(linha).join('\n');
  if (outros.length) t += '\n\nOutros / G-League:\n' + outros.map(linha).join('\n');
  if (q.length) {
    t += '\n\nOVR médio ' + med(q, p => p.ovr) + ' · idade média ' + med(q, p => p.idade);
    /* O sistema entra porque é o que a mesa responde e o texto, sem ele,
       seria só uma lista de nomes que qualquer um já tinha. */
    const comFicha = q.filter(p => p.sist);
    if (comFicha.length) {
      const melhor = Object.keys(NOMES).map(k => {
        const n = comFicha.map(p => p.sist[k]).filter(x => x != null);
        return { nome: NOMES[k], nota: n.length ? n.reduce((a, b) => a + b, 0) / n.length : null };
      }).filter(x => x.nota !== null).sort((a, b) => b.nota - a.nota)[0];
      if (melhor) t += '\nSistema: ' + melhor.nome + ' (' + melhor.nota.toFixed(1) + ')';
    }
  }
  return t;
}

document.getElementById('bCopiar').addEventListener('click', async (e) => {
  const txt = textoDoTime();
  try {
    await navigator.clipboard.writeText(txt);
  } catch (err) {
    /* Área de transferência bloqueada (http, permissão): o jeito antigo
       ainda funciona, e sem ele o botão não faria nada em metade dos casos. */
    const ta = document.createElement('textarea');
    ta.value = txt; ta.style.position = 'fixed'; ta.style.opacity = '0';
    document.body.appendChild(ta); ta.select(); document.execCommand('copy'); ta.remove();
  }
  const b = e.currentTarget, antes = b.innerHTML;
  b.innerHTML = '<i class="bi bi-check-lg"></i> Copiado!';
  setTimeout(() => { b.innerHTML = antes; }, 1800);
});

carregar().then(desenhar);
</script>
</body>
</html>
