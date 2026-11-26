<?php
/**
 * TIER LIST DA ELITE — pública, sem login.
 *
 * Cada visitante monta a SUA. Nada é gravado no servidor: a montagem vive no
 * localStorage do navegador de quem monta. É de propósito — a graça é a
 * pessoa montar e mandar o print no grupo, e guardar no banco significaria
 * conta, moderação e uma tabela a mais pra manter.
 *
 * Ao lado fica a classificação de verdade, pra dar a régua: tier list sem o
 * que aconteceu de fato vira chute. Clicar num escudo abre o quinteto
 * titular do time, que é a outra pergunta que a pessoa faz na hora de
 * decidir onde põe cada um.
 *
 * NÃO PRECISA DE SESSÃO. Só lê times, classificação e titulares — tudo que
 * já é público no site. Por isso inclui db.php direto, sem auth.php.
 */

require_once __DIR__ . '/backend/db.php';

$pdo = db();

/* Os 32 da ELITE. */
$times = $pdo->query("SELECT id, city, name, photo_url, public_primary_color AS cor
                        FROM teams WHERE league = 'ELITE'
                    ORDER BY city, name")->fetchAll(PDO::FETCH_ASSOC);

/* A classificação mais recente lançada da ELITE. A tier list é sobre o
   momento da liga, então vale a última campanha que existe — não a de uma
   sprint encerrada. */
$seasonId = (int) $pdo->query("
    SELECT s.id FROM seasons s
     WHERE s.league = 'ELITE'
       AND EXISTS (SELECT 1 FROM season_standings ss WHERE ss.season_id = s.id)
  ORDER BY s.season_number DESC, s.id DESC LIMIT 1")->fetchColumn();

$tabela = ['LESTE' => [], 'OESTE' => []];
if ($seasonId) {
    $st = $pdo->prepare("SELECT ss.position, COALESCE(ss.conference, t.conference) AS conf,
                                t.id, t.city, t.name, t.photo_url
                           FROM season_standings ss JOIN teams t ON t.id = ss.team_id
                          WHERE ss.season_id = ? ORDER BY conf, ss.position");
    $st->execute([$seasonId]);
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $c = strtoupper((string) $r['conf']);
        if (isset($tabela[$c])) $tabela[$c][] = $r;
    }
}

/* O QUINTETO DE CADA TIME, tudo de uma vez.
   São 32 times; uma consulta por clique seria 32 idas ao servidor pra
   mostrar cinco nomes que já estão no banco. Vem junto com a página. */
$titulares = [];
$st = $pdo->query("SELECT p.team_id, p.name, p.ovr, p.position, p.role
                     FROM players p JOIN teams t ON t.id = p.team_id
                    WHERE t.league = 'ELITE'
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
            'p' => strtoupper(trim((string) $p['position'])),
        ];
    }
}

/** Miniatura pelo thumbnailer: o original tem arquivo de 7 MB. */
function tlEscudo(?string $url, int $px = 128): string
{
    $url = trim((string) $url);
    if ($url === '') return '';
    if (!preg_match('~^/img/teams/([A-Za-z0-9._-]+)$~', $url, $m)) return $url;
    return '/games/escudo.php?f=' . rawurlencode($m[1]) . '&s=' . $px;
}

/* CAMPEÃO em cima: é a convenção de tier list, e a lista que o Marcos mandou
   veio do pior pro melhor. A ordem aqui é a de exibição. */
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
        'n'   => trim(((string) $t['city']) . ' ' . ((string) $t['name'])),
        'img' => tlEscudo($t['photo_url']),
        'q'   => $titulares[(int) $t['id']]['lista'] ?? [],
    ];
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Tier List da ELITE — FBA</title>
<meta name="description" content="Monte sua tier list dos 32 times da ELITE.">
<meta property="og:title" content="Tier List da ELITE — FBA">
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
a{color:var(--roxo)}
.topo{max-width:1400px;margin:0 auto 14px;display:flex;align-items:center;gap:12px;flex-wrap:wrap}
.topo h1{margin:0;font-size:22px;letter-spacing:.5px}
.topo .sub{color:var(--txt2);font-size:13px;flex:1 1 220px;min-width:0}
.btn{
  background:var(--card2);color:var(--txt);border:1px solid var(--linha);
  border-radius:9px;padding:8px 13px;font-size:13px;font-weight:600;cursor:pointer;
}
.btn:hover{border-color:var(--roxo)}
.btn.primario{background:var(--roxo);border-color:var(--roxo);color:#fff}

.palco{max-width:1400px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}

/* ── as faixas ───────────────────────────────────────────── */
.faixa{display:flex;background:var(--card);border:1px solid var(--linha);border-radius:12px;margin-bottom:10px;overflow:hidden}
.faixa .rotulo{
  width:118px;flex:0 0 118px;display:flex;align-items:center;justify-content:center;
  text-align:center;font-weight:800;font-size:12px;line-height:1.15;padding:10px 7px;
  color:#0b0b0f;letter-spacing:.3px;
}
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

/* ── a tabela do lado ────────────────────────────────────── */
.lateral{background:var(--card);border:1px solid var(--linha);border-radius:12px;padding:12px;position:sticky;top:16px}
.lateral h2{margin:0 0 4px;font-size:12px;letter-spacing:1.3px;color:var(--txt2);text-transform:uppercase}
.lateral .conf{margin-top:12px}
.lateral .conf b{font-size:11px;color:var(--roxo);letter-spacing:1px}
.linha{display:flex;align-items:center;gap:7px;padding:3px 0;font-size:12px}
.linha .pos{width:19px;text-align:right;color:var(--txt2);font-variant-numeric:tabular-nums;flex:0 0 19px}
.linha img{width:19px;height:19px;object-fit:contain;flex:0 0 19px}
.linha span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.corte{border-top:1px dashed #3a3a4d;margin:5px 0 4px;position:relative}
.corte i{position:absolute;right:0;top:-8px;background:var(--card);padding:0 5px;font-size:9px;font-style:normal;color:var(--txt2);letter-spacing:.5px}

/* ── o quinteto ──────────────────────────────────────────── */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.76);display:none;align-items:center;justify-content:center;padding:18px;z-index:1000}
.fundo.on{display:flex}
.modal{background:var(--card);border:1px solid var(--linha);border-radius:14px;max-width:370px;width:100%;padding:18px}
.modal header{display:flex;align-items:center;gap:11px;margin-bottom:13px}
.modal header img{width:46px;height:46px;object-fit:contain}
.modal header h3{margin:0;font-size:16px}
.jog{display:flex;align-items:center;gap:9px;padding:7px 0;border-top:1px solid var(--linha);font-size:14px}
.jog .p{width:30px;font-size:10px;font-weight:800;color:var(--roxo);flex:0 0 30px}
.jog .n{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.jog .o{font-weight:800;font-variant-numeric:tabular-nums}
.vazio{color:var(--txt2);font-size:13px;padding:8px 0}

@media (max-width:900px){
  body{padding:11px}
  .palco{grid-template-columns:1fr}
  .lateral{position:static;order:2}
  .faixa .rotulo{width:84px;flex:0 0 84px;font-size:10px;padding:8px 5px}
  .chip{width:50px;height:50px}
  .chip img{width:39px;height:39px}
  .topo h1{font-size:18px}
  .topo .sub{flex-basis:100%}
}
</style>
</head>
<body>

<div class="topo">
  <h1>Tier List da ELITE</h1>
  <div class="sub">Arraste os escudos. No celular, toque num escudo e depois na faixa. Clique num escudo já posicionado pra ver o quinteto.</div>
  <button class="btn" id="limpar">Limpar</button>
  <button class="btn primario" id="baixar">Baixar imagem</button>
</div>

<div class="palco">
  <div>
    <div id="faixas">
      <?php foreach ($FAIXAS as $f): ?>
      <div class="faixa">
        <div class="rotulo" style="background:<?= htmlspecialchars($f['cor']) ?>"><?= htmlspecialchars($f['nome']) ?></div>
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
    <h2>Classificação atual</h2>
    <?php foreach (['LESTE', 'OESTE'] as $conf): if (!$tabela[$conf]) continue; ?>
    <div class="conf">
      <b><?= $conf ?></b>
      <?php foreach ($tabela[$conf] as $i => $r): ?>
        <?php if ($i === 8): ?><div class="corte"><i>FORA DOS PLAYOFFS</i></div><?php endif; ?>
        <div class="linha">
          <span class="pos"><?= (int) $r['position'] ?></span>
          <?php $e = tlEscudo($r['photo_url'], 48); ?>
          <?php if ($e): ?><img src="<?= htmlspecialchars($e) ?>" alt="" loading="lazy"><?php else: ?><span class="pos"></span><?php endif; ?>
          <span><?= htmlspecialchars(trim($r['city'] . ' ' . $r['name'])) ?></span>
        </div>
      <?php endforeach; ?>
    </div>
    <?php endforeach; ?>
    <?php if (!$tabela['LESTE'] && !$tabela['OESTE']): ?>
      <div class="vazio">Sem classificação lançada.</div>
    <?php endif; ?>
  </aside>
</div>

<div class="fundo" id="fundo">
  <div class="modal">
    <header><img id="mImg" src="" alt=""><h3 id="mNome"></h3></header>
    <div id="mLista"></div>
    <button class="btn" id="mFechar" style="margin-top:13px;width:100%">Fechar</button>
  </div>
</div>

<script>
const TIMES = <?= json_encode($dados, JSON_UNESCAPED_UNICODE) ?>;
const CHAVE = 'fba_tierlist_elite_v1';
const porId = {};
TIMES.forEach(t => porId[t.id] = t);

/* ── monta os escudos ─────────────────────────────────────── */
function criarChip(t){
  const d = document.createElement('div');
  d.className = 'chip';
  d.dataset.id = t.id;
  d.title = t.n;
  d.innerHTML = t.img
    ? '<img src="' + t.img + '" alt="" draggable="false">'
    : '<span class="mono">' + t.n.split(' ').map(p => p[0]).join('').slice(0,3).toUpperCase() + '</span>';
  return d;
}

const zonas = () => Array.from(document.querySelectorAll('.zona'));

function montar(estado){
  zonas().forEach(z => z.innerHTML = '');
  const banco = document.getElementById('banco');
  const postos = {};
  Object.entries(estado || {}).forEach(([id, faixa]) => postos[id] = faixa);
  TIMES.forEach(t => {
    const faixa = postos[t.id] || 'banco';
    const alvo = document.querySelector('.zona[data-faixa="' + faixa + '"]') || banco;
    alvo.appendChild(criarChip(t));
  });
}

function salvar(){
  const e = {};
  zonas().forEach(z => {
    if (z.dataset.faixa === 'banco') return;
    z.querySelectorAll('.chip').forEach(c => e[c.dataset.id] = z.dataset.faixa);
  });
  try { localStorage.setItem(CHAVE, JSON.stringify(e)); } catch (err) {}
}

let inicial = {};
try { inicial = JSON.parse(localStorage.getItem(CHAVE) || '{}'); } catch (err) {}
montar(inicial);

/* ── arrastar: um só caminho pra mouse e dedo ──────────────
   Pointer Events cobrem os dois. O clone voa junto do dedo e o original
   fica de fantasma no lugar, pra pessoa ver de onde saiu. O clique só
   acontece se o dedo não andou: senão todo arraste abriria o quinteto. */
let arrastando = null, clone = null, saiuDoLugar = false, px0 = 0, py0 = 0, escolhido = null;

function zonaSob(x, y){
  const el = document.elementFromPoint(x, y);
  return el ? el.closest('.zona') : null;
}
function limparAlvo(){ zonas().forEach(z => z.classList.remove('alvo')); }

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
    if (z) z.appendChild(chip);
    chip.classList.remove('fantasma');
    if (clone) clone.remove();
    clone = null;
    limparAlvo();
    salvar();
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
   No celular arrastar 32 escudos cansa, e o toque errado desfaz o que já
   foi feito. Um toque marca, o toque na faixa coloca — e abrir o quinteto
   continua a um toque, pelo botão do próprio card marcado. */
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
    salvar();
  });
});

/* ── o quinteto ───────────────────────────────────────────── */
const fundo = document.getElementById('fundo');
function abrirQuinteto(id){
  const t = porId[id];
  if (!t) return;
  document.getElementById('mNome').textContent = t.n;
  const img = document.getElementById('mImg');
  img.src = t.img || '';
  img.style.display = t.img ? '' : 'none';
  document.getElementById('mLista').innerHTML = t.q.length
    ? t.q.map(j => '<div class="jog"><span class="p">' + (j.p || '--') + '</span>'
        + '<span class="n">' + j.n.replace(/[<>&]/g, '') + '</span>'
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
  try { localStorage.removeItem(CHAVE); } catch (e) {}
  desmarcar();
  montar({});
};

/* ── baixar a imagem ──────────────────────────────────────
   Desenhado no canvas na mão, sem biblioteca: os escudos vêm do mesmo
   domínio (/games/escudo.php), então o canvas não fica contaminado e o
   toDataURL funciona. */
document.getElementById('baixar').onclick = async () => {
  const btn = document.getElementById('baixar');
  btn.textContent = 'Gerando...';
  try {
    const FAIXAS = Array.from(document.querySelectorAll('.faixa')).map(f => ({
      nome: f.querySelector('.rotulo').textContent.trim(),
      cor:  f.querySelector('.rotulo').style.background,
      ids:  Array.from(f.querySelectorAll('.chip')).map(c => c.dataset.id)
    }));

    const L = 96, ROT = 210, GAP = 10, POR_LINHA = 8, PAD = 26;
    const alturas = FAIXAS.map(f => Math.max(1, Math.ceil(f.ids.length / POR_LINHA)) * (L + GAP) + GAP);
    const W = ROT + POR_LINHA * (L + GAP) + GAP + PAD * 2;
    const H = PAD + 76 + alturas.reduce((a, b) => a + b + GAP, 0) + 46;

    const cv = document.createElement('canvas');
    cv.width = W; cv.height = H;
    const c = cv.getContext('2d');
    c.fillStyle = '#0b0b0f'; c.fillRect(0, 0, W, H);
    c.fillStyle = '#f2f2f7'; c.font = '700 34px system-ui,sans-serif';
    c.fillText('Tier List da ELITE', PAD, PAD + 36);
    c.fillStyle = '#9a9aae'; c.font = '16px system-ui,sans-serif';
    c.fillText('fbabrasil.com.br/tierlist.php', PAD, PAD + 62);

    const imagem = src => new Promise(ok => {
      if (!src) return ok(null);
      const i = new Image();
      i.onload = () => ok(i); i.onerror = () => ok(null);
      i.src = src;
    });

    let y = PAD + 76;
    for (let k = 0; k < FAIXAS.length; k++) {
      const f = FAIXAS[k], h = alturas[k];
      c.fillStyle = '#14141b';
      c.fillRect(PAD, y, W - PAD * 2, h);
      c.fillStyle = f.cor || '#888';
      c.fillRect(PAD, y, ROT, h);
      c.fillStyle = '#0b0b0f'; c.font = '800 17px system-ui,sans-serif';
      c.textAlign = 'center';
      const palavras = f.nome.split(' ');
      const linhas = [];
      let atual = '';
      palavras.forEach(p => {
        const t = atual ? atual + ' ' + p : p;
        if (c.measureText(t).width > ROT - 18 && atual) { linhas.push(atual); atual = p; }
        else atual = t;
      });
      if (atual) linhas.push(atual);
      linhas.forEach((ln, i) => c.fillText(ln, PAD + ROT / 2, y + h / 2 + 6 + (i - (linhas.length - 1) / 2) * 20));
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
    a.download = 'tierlist-elite.png';
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
