<?php
/**
 * TIER LIST — pública, sem login.
 *
 * Cada visitante monta a SUA. Nada vai pro servidor: a montagem, os nomes
 * das faixas, o tema e as ligas escolhidas vivem no localStorage de quem
 * monta. Chegou a ser um quadro único e ao vivo (26/11/2026) e voltou a ser
 * individual no dia seguinte: a graça é cada um montar a sua e mandar o
 * print no grupo, não a liga disputar o mesmo quadro.
 *
 * Ao lado fica o ranking de verdade, pra dar a régua. Clicar num escudo abre
 * o quinteto titular, que é a outra pergunta que a pessoa faz na hora de
 * decidir onde põe cada time.
 *
 * NÃO PRECISA DE SESSÃO. Só lê time, ranking e titulares — tudo que já é
 * público no site. Por isso inclui db.php direto, sem auth.php.
 */

require_once __DIR__ . '/backend/db.php';

$pdo   = db();
$LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

/* Vem tudo das quatro ligas de uma vez, e quem filtra é o navegador.
   Trocar de liga é um clique que a pessoa dá o tempo todo enquanto monta;
   ir ao servidor a cada clique faria a tela piscar por nada. */
$times = $pdo->query("SELECT id, league, city, name, photo_url, ranking_points
                        FROM teams
                       WHERE league IN ('ELITE','NEXT','RISE','ROOKIE')
                    ORDER BY league, ranking_points DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* O QUINTETO DE CADA TIME, tudo junto.
   São mais de cem times; uma consulta por clique seria uma ida ao servidor
   pra mostrar cinco nomes que já estão no banco. */
$titulares = [];
$st = $pdo->query("SELECT p.team_id, p.name, p.ovr, p.age, p.position, p.role
                     FROM players p JOIN teams t ON t.id = p.team_id
                    WHERE t.league IN ('ELITE','NEXT','RISE','ROOKIE')
                 ORDER BY p.team_id,
                          CASE WHEN p.role = 'Titular' THEN 0 ELSE 1 END,
                          FIELD(UPPER(TRIM(p.position)),'PG','SG','SF','PF','C'),
                          p.ovr DESC");
foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
    $tid = (int) $p['team_id'];
    if (!isset($titulares[$tid])) $titulares[$tid] = ['marcados' => false, 'lista' => []];
    $eTitular = trim((string) $p['role']) === 'Titular';
    if ($eTitular) $titulares[$tid]['marcados'] = true;
    // Com quinteto marcado só entram os marcados; sem ele, os 5 melhores.
    if (count($titulares[$tid]['lista']) < 5 && ($eTitular || !$titulares[$tid]['marcados'])) {
        $titulares[$tid]['lista'][] = [
            'n' => $p['name'],
            'o' => (int) $p['ovr'],
            'i' => (int) $p['age'],
            'p' => strtoupper(trim((string) $p['position'])),
        ];
    }
}

/** Miniatura pelo thumbnailer: há escudo de 7 MB na pasta. */
function tlEscudo(?string $url, int $px = 128): string
{
    $url = trim((string) $url);
    if ($url === '') return '';
    if (!preg_match('~^/img/teams/([A-Za-z0-9._-]+)$~', $url, $m)) return $url;
    return '/games/escudo.php?f=' . rawurlencode($m[1]) . '&s=' . $px;
}

/* CAMPEÃO em cima: é a convenção de tier list. Os nomes são só o PADRÃO —
   quem monta renomeia na tela, e o que ele escrever vale daí em diante. */
$FAIXAS = [
    ['id' => 'campeao', 'nome' => 'CAMPEÃO',             'cor' => '#f5c542'],
    ['id' => 'briga',   'nome' => 'BRIGA PELO TÍTULO',   'cor' => '#6ee7a8'],
    ['id' => 'meio',    'nome' => 'MEIO DE TABELA',      'cor' => '#7cc4ff'],
    ['id' => 'luta',    'nome' => 'VAI LUTAR ATÉ O FIM', 'cor' => '#ffa94d'],
    ['id' => 'cai',     'nome' => 'CAI',                 'cor' => '#ff6b6b'],
];

$dados = [];
foreach ($times as $t) {
    $dados[] = [
        'id'  => (int) $t['id'],
        'lg'  => (string) $t['league'],
        'n'   => trim(((string) $t['city']) . ' ' . ((string) $t['name'])),
        'img' => tlEscudo($t['photo_url']),
        'pts' => (int) $t['ranking_points'],
        'q'   => $titulares[(int) $t['id']]['lista'] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Tier List — FBA</title>
<meta name="description" content="Monte sua tier list dos times da FBA.">
<meta property="og:title" content="Tier List — FBA">
<meta property="og:description" content="Monte a sua e mande no grupo.">
<style>
:root{
  --bg:#0b0b0f; --card:#14141b; --card2:#1b1b24; --linha:#262633;
  --txt:#f2f2f7; --txt2:#9a9aae; --roxo:#a855f7;
}
*{box-sizing:border-box;-webkit-tap-highlight-color:transparent}
html,body{margin:0;padding:0}
body{
  background:var(--bg); color:var(--txt); min-height:100vh;
  font-family:system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
  padding:16px; overflow-x:hidden;
}
.topo{max-width:1400px;margin:0 auto 12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap}
#titulo{
  margin:0;font-size:23px;letter-spacing:.4px;font-weight:800;
  border-bottom:1px dashed transparent;padding:2px 4px;border-radius:5px;min-width:60px;outline:none;
}
#titulo:hover{border-bottom-color:var(--linha)}
#titulo:focus{border-bottom-color:var(--roxo);background:var(--card)}
.lapis{color:var(--txt2);font-size:12px;flex:1 1 150px}
.btn{
  background:var(--card2);color:var(--txt);border:1px solid var(--linha);
  border-radius:9px;padding:8px 13px;font-size:13px;font-weight:600;cursor:pointer;
}
.btn:hover{border-color:var(--roxo)}
.btn.primario{background:var(--roxo);border-color:var(--roxo);color:#fff}

/* ── ligas ───────────────────────────────────────────────── */
.ligas{max-width:1400px;margin:0 auto 14px;display:flex;gap:7px;flex-wrap:wrap;align-items:center}
.ligas .rot{font-size:11px;letter-spacing:1.2px;color:var(--txt2);text-transform:uppercase;margin-right:3px}
.liga{
  background:var(--card2);border:1px solid var(--linha);color:var(--txt2);
  border-radius:999px;padding:6px 15px;font-size:12px;font-weight:700;cursor:pointer;letter-spacing:.6px;
}
.liga.on{background:var(--roxo);border-color:var(--roxo);color:#fff}

.palco{max-width:1400px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}

/* ── as faixas ───────────────────────────────────────────── */
.faixa{display:flex;background:var(--card);border:1px solid var(--linha);border-radius:12px;margin-bottom:10px;overflow:hidden}
.rotulo{
  width:118px;flex:0 0 118px;display:flex;align-items:center;justify-content:center;
  text-align:center;font-weight:800;font-size:12px;line-height:1.15;padding:10px 7px;
  color:#0b0b0f;letter-spacing:.3px;outline:none;word-break:break-word;cursor:text;
}
.rotulo:focus{box-shadow:inset 0 0 0 2px rgba(0,0,0,.45)}
.zona{flex:1;min-height:84px;display:flex;flex-wrap:wrap;gap:7px;padding:9px;align-content:flex-start}
.zona.alvo{background:rgba(168,85,247,.14);outline:2px dashed var(--roxo);outline-offset:-5px}

.banco{background:var(--card);border:1px solid var(--linha);border-radius:12px;padding:10px;margin-top:14px}
.banco h2{margin:0 0 9px;font-size:12px;color:var(--txt2);letter-spacing:1.3px;text-transform:uppercase}
.banco .zona{min-height:96px;padding:0}

/* ── o escudo ────────────────────────────────────────────── */
.chip{
  width:58px;height:58px;border-radius:10px;background:var(--card2);
  border:1px solid var(--linha);display:flex;align-items:center;justify-content:center;
  cursor:grab;touch-action:none;user-select:none;position:relative;overflow:hidden;flex:0 0 auto;
}
.chip img{width:46px;height:46px;object-fit:contain;pointer-events:none}
.chip .mono{font-weight:800;font-size:15px;color:var(--txt2);pointer-events:none}
.chip.escolhido{border-color:var(--roxo);box-shadow:0 0 0 2px rgba(168,85,247,.45)}
.chip.voando{position:fixed;z-index:999;pointer-events:none;cursor:grabbing;
  transform:scale(1.12);box-shadow:0 12px 28px rgba(0,0,0,.6);border-color:var(--roxo)}
.chip.fantasma{opacity:.28}

/* ── o ranking do lado ───────────────────────────────────── */
.lateral{background:var(--card);border:1px solid var(--linha);border-radius:12px;padding:12px;position:sticky;top:16px;max-height:calc(100vh - 32px);overflow-y:auto}
.lateral h2{margin:0;font-size:12px;letter-spacing:1.3px;color:var(--txt2);text-transform:uppercase}
.grupo{margin-top:12px}
.grupo b{font-size:11px;color:var(--roxo);letter-spacing:1px}
.linha{display:flex;align-items:center;gap:7px;padding:3px 0;font-size:12px}
.linha .pos{width:19px;text-align:right;color:var(--txt2);font-variant-numeric:tabular-nums;flex:0 0 19px}
.linha img{width:19px;height:19px;object-fit:contain;flex:0 0 19px}
.linha .nm{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.linha .pts{color:var(--roxo);font-variant-numeric:tabular-nums;font-size:11px;flex:0 0 auto}

/* ── o quinteto ──────────────────────────────────────────── */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.76);display:none;align-items:center;justify-content:center;padding:18px;z-index:1000}
.fundo.on{display:flex}
.modal{background:var(--card);border:1px solid var(--linha);border-radius:14px;max-width:370px;width:100%;padding:18px}
.modal header{display:flex;align-items:center;gap:11px;margin-bottom:13px}
.modal header img{width:46px;height:46px;object-fit:contain}
.modal header h3{margin:0;font-size:16px}
.modal header .lg{font-size:10px;color:var(--roxo);letter-spacing:1px;font-weight:800}
.jog{display:flex;align-items:center;gap:9px;padding:7px 0;border-top:1px solid var(--linha);font-size:14px}
.jog .p{width:30px;font-size:10px;font-weight:800;color:var(--roxo);flex:0 0 30px}
.jog .n{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jog .idade{font-size:11px;color:var(--txt2);font-variant-numeric:tabular-nums;flex:0 0 auto}
.jog .o{font-weight:800;font-variant-numeric:tabular-nums;min-width:26px;text-align:right}
.vazio{color:var(--txt2);font-size:13px;padding:8px 0}

@media (max-width:900px){
  body{padding:11px}
  .palco{grid-template-columns:1fr}
  .lateral{position:static;order:2;max-height:none}
  .rotulo{width:84px;flex:0 0 84px;font-size:10px;padding:8px 5px}
  .chip{width:50px;height:50px}
  .chip img{width:39px;height:39px}
  #titulo{font-size:19px}
  .lapis{flex-basis:100%}
}
</style>
</head>
<body>

<div class="topo">
  <h1 id="titulo" contenteditable="true" spellcheck="false">Tier List da FBA</h1>
  <span class="lapis">Clique no título e nos nomes das faixas pra trocar.</span>
  <button class="btn" id="limpar">Limpar</button>
  <button class="btn primario" id="baixar">Baixar imagem</button>
</div>

<div class="ligas">
  <span class="rot">Ligas</span>
  <?php foreach ($LIGAS as $lg): ?>
    <button class="liga<?= $lg === 'ELITE' ? ' on' : '' ?>" data-liga="<?= $lg ?>"><?= $lg ?></button>
  <?php endforeach; ?>
</div>

<div class="palco">
  <div>
    <div id="faixas">
      <?php foreach ($FAIXAS as $f): ?>
      <div class="faixa">
        <div class="rotulo" contenteditable="true" spellcheck="false"
             data-faixa-nome="<?= htmlspecialchars($f['id']) ?>"
             style="background:<?= htmlspecialchars($f['cor']) ?>"><?= htmlspecialchars($f['nome']) ?></div>
        <div class="zona" data-faixa="<?= htmlspecialchars($f['id']) ?>"></div>
      </div>
      <?php endforeach; ?>
    </div>

    <div class="banco">
      <h2>Times — arraste pra cima</h2>
      <div class="zona" data-faixa="banco" id="banco"></div>
    </div>
  </div>

  <aside class="lateral">
    <h2>Ranking</h2>
    <div id="ranking"></div>
  </aside>
</div>

<div class="fundo" id="fundo">
  <div class="modal">
    <header><img id="mImg" src="" alt=""><div><h3 id="mNome"></h3><span class="lg" id="mLiga"></span></div></header>
    <div id="mLista"></div>
    <button class="btn" id="mFechar" style="margin-top:13px;width:100%">Fechar</button>
  </div>
</div>

<script>
const TIMES = <?= json_encode($dados, JSON_UNESCAPED_UNICODE) ?>;
const CHAVE = 'fba_tierlist_v2';
const porId = {};
TIMES.forEach(t => porId[t.id] = t);

/* ── o que fica guardado ──────────────────────────────────
   Tudo no navegador de quem monta: a montagem, os nomes que ele deu às
   faixas, o tema e as ligas escolhidas. */
const PADRAO = {
  titulo: 'Tier List da FBA',
  ligas: ['ELITE'],
  nomes: {},
  faixas: { campeao: [], briga: [], meio: [], luta: [], cai: [] }
};
let E;
try { E = Object.assign({}, PADRAO, JSON.parse(localStorage.getItem(CHAVE) || '{}')); }
catch (err) { E = Object.assign({}, PADRAO); }
if (!E.faixas || typeof E.faixas !== 'object') E.faixas = Object.assign({}, PADRAO.faixas);
if (!Array.isArray(E.ligas) || !E.ligas.length) E.ligas = ['ELITE'];

function guardar(){
  const f = {};
  document.querySelectorAll('.zona').forEach(z => {
    if (z.dataset.faixa === 'banco') return;
    f[z.dataset.faixa] = Array.from(z.querySelectorAll('.chip')).map(c => Number(c.dataset.id));
  });
  E.faixas = f;
  E.titulo = document.getElementById('titulo').textContent.trim() || PADRAO.titulo;
  const nomes = {};
  document.querySelectorAll('[data-faixa-nome]').forEach(r => nomes[r.dataset.faixaNome] = r.textContent.trim());
  E.nomes = nomes;
  try { localStorage.setItem(CHAVE, JSON.stringify(E)); } catch (err) {}
}

const naLiga = t => E.ligas.indexOf(t.lg) !== -1;

/* ── monta os escudos ─────────────────────────────────────── */
function criarChip(t){
  const d = document.createElement('div');
  d.className = 'chip';
  d.dataset.id = t.id;
  d.title = t.n + ' — ' + t.lg;
  d.innerHTML = t.img
    ? '<img src="' + t.img + '" alt="" draggable="false" loading="lazy">'
    : '<span class="mono">' + t.n.split(' ').map(p => p[0]).join('').slice(0,3).toUpperCase() + '</span>';
  return d;
}

const zonas = () => Array.from(document.querySelectorAll('.zona'));

/* Desenha na ORDEM GUARDADA de cada faixa — ela diz quem é o primeiro, que
   numa tier list é metade da opinião. Só o banco sai na ordem do ranking:
   lá é depósito, não opinião.

   Time de liga desmarcada some da tela mas NÃO some do guardado: quem tira
   a NEXT pra olhar só a ELITE e marca de volta encontra tudo onde estava. */
function montar(){
  zonas().forEach(z => z.innerHTML = '');
  const colocados = new Set();
  Object.keys(E.faixas).forEach(faixa => {
    const z = document.querySelector('.zona[data-faixa="' + faixa + '"]');
    if (!z) return;
    (E.faixas[faixa] || []).forEach(id => {
      const t = porId[id];
      if (!t || !naLiga(t)) return;
      z.appendChild(criarChip(t));
      colocados.add(id);
    });
  });
  const banco = document.getElementById('banco');
  TIMES.forEach(t => { if (naLiga(t) && !colocados.has(t.id)) banco.appendChild(criarChip(t)); });
}

function montarRanking(){
  const alvo = document.getElementById('ranking');
  const porLiga = {};
  TIMES.forEach(t => { if (naLiga(t)) (porLiga[t.lg] = porLiga[t.lg] || []).push(t); });
  const ligas = Object.keys(porLiga);
  if (!ligas.length) { alvo.innerHTML = '<div class="vazio">Escolha ao menos uma liga.</div>'; return; }
  alvo.innerHTML = ligas.map(lg => '<div class="grupo"><b>' + lg + '</b>'
    + porLiga[lg].map((t, i) =>
        '<div class="linha"><span class="pos">' + (i + 1) + '</span>'
      + (t.img ? '<img src="' + t.img + '" alt="" loading="lazy">' : '<span class="pos"></span>')
      + '<span class="nm">' + t.n + '</span><b class="pts">' + t.pts + '</b></div>').join('')
    + '</div>').join('');
}

/* ── ligas ────────────────────────────────────────────────── */
document.querySelectorAll('.liga').forEach(b => {
  b.classList.toggle('on', E.ligas.indexOf(b.dataset.liga) !== -1);
  b.onclick = () => {
    const lg = b.dataset.liga, i = E.ligas.indexOf(lg);
    // Desmarcar a última deixaria a tela vazia sem dizer por quê.
    if (i !== -1 && E.ligas.length === 1) return;
    if (i === -1) E.ligas.push(lg); else E.ligas.splice(i, 1);
    b.classList.toggle('on', E.ligas.indexOf(lg) !== -1);
    guardar(); montar(); montarRanking();
  };
});

/* ── título e nomes das faixas ────────────────────────────── */
document.getElementById('titulo').textContent = E.titulo || PADRAO.titulo;
document.querySelectorAll('[data-faixa-nome]').forEach(r => {
  const n = E.nomes && E.nomes[r.dataset.faixaNome];
  if (n) r.textContent = n;
});
// Enter fecha a edição em vez de abrir linha nova dentro do rótulo.
document.querySelectorAll('#titulo,[data-faixa-nome]').forEach(el => {
  el.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); } });
  el.addEventListener('blur', () => {
    if (!el.textContent.trim()) {
      el.textContent = el.id === 'titulo' ? PADRAO.titulo : (el.dataset.faixaNome || '').toUpperCase();
    }
    guardar();
  });
  // Cola só texto: colar de outra página traria HTML e cor junto.
  el.addEventListener('paste', ev => {
    ev.preventDefault();
    document.execCommand('insertText', false, (ev.clipboardData || window.clipboardData).getData('text'));
  });
});

montar();
montarRanking();

/* ── arrastar: um só caminho pra mouse e dedo ──────────────
   Pointer Events cobrem os dois. O clone voa junto do dedo e o original
   fica de fantasma no lugar. O clique só acontece se o dedo não andou:
   senão todo arraste abriria o quinteto. */
let arrastando = null, clone = null, saiuDoLugar = false, px0 = 0, py0 = 0, escolhido = null;

function zonaSob(x, y){
  const el = document.elementFromPoint(x, y);
  return el ? el.closest('.zona') : null;
}
function limparAlvo(){ zonas().forEach(z => z.classList.remove('alvo')); }

/* ONDE O ESCUDO FOI SOLTO dentro da faixa: compara com o meio horizontal
   de cada escudo que já está lá. É o que faz "soltar entre o 2º e o 3º"
   virar exatamente isso, em vez de cair sempre no fim. */
function indiceNaZona(zona, x, y, ignorar){
  const irmaos = Array.from(zona.querySelectorAll('.chip')).filter(c => c !== ignorar);
  for (let i = 0; i < irmaos.length; i++) {
    const r = irmaos[i].getBoundingClientRect();
    if (y < r.bottom && x < r.left + r.width / 2) return i;
  }
  return irmaos.length;
}

document.addEventListener('pointerdown', ev => {
  const chip = ev.target.closest('.chip');
  if (!chip || ev.button > 0) return;
  arrastando = chip; saiuDoLugar = false;
  px0 = ev.clientX; py0 = ev.clientY;
  chip.setPointerCapture(ev.pointerId);
});

document.addEventListener('pointermove', ev => {
  if (!arrastando) return;
  const dx = ev.clientX - px0, dy = ev.clientY - py0;
  if (!saiuDoLugar && Math.hypot(dx, dy) < 7) return;   // ainda pode ser clique

  if (!saiuDoLugar) {
    saiuDoLugar = true;
    const r = arrastando.getBoundingClientRect();
    clone = arrastando.cloneNode(true);
    clone.classList.add('voando');
    clone.style.width = r.width + 'px';
    clone.style.height = r.height + 'px';
    document.body.appendChild(clone);
    arrastando.classList.add('fantasma');
  }
  const r = arrastando.getBoundingClientRect();
  clone.style.left = (ev.clientX - r.width / 2) + 'px';
  clone.style.top  = (ev.clientY - r.height / 2) + 'px';

  limparAlvo();
  const z = zonaSob(ev.clientX, ev.clientY);
  if (z) z.classList.add('alvo');
  ev.preventDefault();
}, { passive: false });

document.addEventListener('pointerup', ev => {
  if (!arrastando) return;
  const chip = arrastando;
  if (saiuDoLugar) {
    const z = zonaSob(ev.clientX, ev.clientY);
    chip.classList.remove('fantasma');
    if (clone) clone.remove();
    clone = null;
    limparAlvo();
    if (z) {
      const i = indiceNaZona(z, ev.clientX, ev.clientY, chip);
      const irmaos = Array.from(z.querySelectorAll('.chip')).filter(c => c !== chip);
      if (i >= irmaos.length) z.appendChild(chip); else z.insertBefore(chip, irmaos[i]);
      guardar();
    }
  } else {
    aoClicar(chip);
  }
  arrastando = null; saiuDoLugar = false;
});

document.addEventListener('pointercancel', () => {
  if (arrastando) arrastando.classList.remove('fantasma');
  if (clone) { clone.remove(); clone = null; }
  limparAlvo(); arrastando = null; saiuDoLugar = false;
});

/* ── tocar pra escolher, tocar na faixa pra colocar ────────
   No celular arrastar dezenas de escudos cansa. Um toque marca, o toque na
   faixa coloca, e o segundo toque no mesmo escudo abre o quinteto. */
function aoClicar(chip){
  if (escolhido === chip) { abrirQuinteto(chip.dataset.id); desmarcar(); return; }
  desmarcar();
  escolhido = chip;
  chip.classList.add('escolhido');
}
function desmarcar(){
  if (escolhido) escolhido.classList.remove('escolhido');
  escolhido = null;
}
document.querySelectorAll('.zona').forEach(z => {
  z.addEventListener('click', ev => {
    if (!escolhido || ev.target.closest('.chip')) return;
    z.appendChild(escolhido);
    desmarcar();
    guardar();
  });
});

/* ── o quinteto ───────────────────────────────────────────── */
const fundo = document.getElementById('fundo');
function abrirQuinteto(id){
  const t = porId[id];
  if (!t) return;
  document.getElementById('mNome').textContent = t.n;
  document.getElementById('mLiga').textContent = t.lg;
  const img = document.getElementById('mImg');
  img.src = t.img || '';
  img.style.display = t.img ? '' : 'none';
  document.getElementById('mLista').innerHTML = t.q.length
    ? t.q.map(j => '<div class="jog"><span class="p">' + (j.p || '--') + '</span>'
        + '<span class="n">' + j.n.replace(/[<>&]/g, '') + '</span>'
        + '<span class="idade">' + (j.i ? j.i + ' anos' : '') + '</span>'
        + '<span class="o">' + j.o + '</span></div>').join('')
    : '<div class="vazio">Este time ainda não tem elenco cadastrado.</div>';
  fundo.classList.add('on');
}
document.getElementById('mFechar').onclick = () => fundo.classList.remove('on');
fundo.onclick = ev => { if (ev.target === fundo) fundo.classList.remove('on'); };
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fundo.classList.remove('on'); });

/* ── limpar ───────────────────────────────────────────────── */
document.getElementById('limpar').onclick = () => {
  if (!confirm('Tirar todos os times das faixas?')) return;
  desmarcar();
  E.faixas = { campeao: [], briga: [], meio: [], luta: [], cai: [] };
  guardar(); montar();
};

/* ── baixar a imagem ──────────────────────────────────────
   Desenhada no canvas na mão, sem biblioteca: os escudos vêm do mesmo
   domínio (/games/escudo.php), então o canvas não fica contaminado e o
   toDataURL funciona. */
document.getElementById('baixar').onclick = async () => {
  const btn = document.getElementById('baixar');
  btn.textContent = 'Gerando...';
  try {
    const linhas = Array.from(document.querySelectorAll('.faixa')).map(f => ({
      nome: f.querySelector('.rotulo').textContent.trim(),
      cor:  f.querySelector('.rotulo').style.background,
      ids:  Array.from(f.querySelectorAll('.chip')).map(c => Number(c.dataset.id))
    }));

    const L = 96, ROT = 210, GAP = 10, POR_LINHA = 8, PAD = 26;
    const alturas = linhas.map(f => Math.max(1, Math.ceil(f.ids.length / POR_LINHA)) * (L + GAP) + GAP);
    const W = ROT + POR_LINHA * (L + GAP) + GAP + PAD * 2;
    const H = PAD + 76 + alturas.reduce((a, b) => a + b + GAP, 0) + 30;

    const cv = document.createElement('canvas');
    cv.width = W; cv.height = H;
    const c = cv.getContext('2d');
    c.fillStyle = '#0b0b0f'; c.fillRect(0, 0, W, H);
    c.fillStyle = '#f2f2f7'; c.font = '700 34px system-ui,sans-serif';
    c.fillText(document.getElementById('titulo').textContent.trim(), PAD, PAD + 36);
    c.fillStyle = '#9a9aae'; c.font = '16px system-ui,sans-serif';
    c.fillText(E.ligas.join(' · ') + '  —  fbabrasil.com.br/tierlist.php', PAD, PAD + 62);

    const imagem = src => new Promise(ok => {
      if (!src) return ok(null);
      const i = new Image();
      i.onload = () => ok(i); i.onerror = () => ok(null);
      i.src = src;
    });

    let y = PAD + 76;
    for (let k = 0; k < linhas.length; k++) {
      const f = linhas[k], h = alturas[k];
      c.fillStyle = '#14141b'; c.fillRect(PAD, y, W - PAD * 2, h);
      c.fillStyle = f.cor || '#888'; c.fillRect(PAD, y, ROT, h);
      c.fillStyle = '#0b0b0f'; c.font = '800 17px system-ui,sans-serif';
      c.textAlign = 'center';
      const palavras = f.nome.split(' ');
      const ls = [];
      let atual = '';
      palavras.forEach(p => {
        const t = atual ? atual + ' ' + p : p;
        if (c.measureText(t).width > ROT - 18 && atual) { ls.push(atual); atual = p; }
        else atual = t;
      });
      if (atual) ls.push(atual);
      ls.forEach((ln, i) => c.fillText(ln, PAD + ROT / 2, y + h / 2 + 6 + (i - (ls.length - 1) / 2) * 20));
      c.textAlign = 'left';

      for (let i = 0; i < f.ids.length; i++) {
        const t = porId[f.ids[i]];
        const cx = PAD + ROT + GAP + (i % POR_LINHA) * (L + GAP);
        const cy = y + GAP + Math.floor(i / POR_LINHA) * (L + GAP);
        c.fillStyle = '#1b1b24'; c.fillRect(cx, cy, L, L);
        const im = await imagem(t && t.img);
        if (im) c.drawImage(im, cx + 8, cy + 8, L - 16, L - 16);
        else if (t) {
          c.fillStyle = '#9a9aae'; c.font = '800 22px system-ui,sans-serif'; c.textAlign = 'center';
          c.fillText(t.n.split(' ').map(p => p[0]).join('').slice(0,3).toUpperCase(), cx + L/2, cy + L/2 + 8);
          c.textAlign = 'left';
        }
      }
      y += h + GAP;
    }

    const a = document.createElement('a');
    a.download = 'tierlist.png';
    a.href = cv.toDataURL('image/png');
    a.click();
  } catch (e) {
    alert('Não consegui gerar a imagem: ' + e.message);
  }
  btn.textContent = 'Baixar imagem';
};
</script>
</body>
</html>
