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

/* ── O QUADRO É UM SÓ, E É AO VIVO ──────────────────────────────────────
 *
 * Começou como uma tier list por pessoa, no localStorage. Virou um quadro
 * compartilhado: quem mexe move pra todo mundo, e quem está com a tela
 * aberta vê mexer.
 *
 * Sem websocket — a Hostinger é PHP e MySQL. O caminho é o mesmo do draft:
 * cada tela pergunta "mudou?" de tempos em tempos, e a resposta é um número
 * de versão. Enquanto a versão não muda, a resposta é minúscula e ninguém
 * redesenha nada.
 *
 * A gravação é por TIME, não pelo quadro inteiro. Mandar o mapa completo
 * faria a última tela a salvar apagar o que as outras acabaram de mover —
 * dois GMs mexendo juntos é exatamente o caso que isto precisa aguentar.
 *
 * O FORMATO GUARDA A ORDEM: uma lista por faixa, não um mapa time→faixa.
 * A primeira versão guardava só em que faixa cada time estava e desenhava
 * em ordem alfabética; quem monta quer dizer também QUEM É O PRIMEIRO da
 * faixa, que numa tier list é metade da opinião. Quem não está em lista
 * nenhuma está no banco.
 */
function tlGarantir(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    $ok = true;
    $pdo->exec("CREATE TABLE IF NOT EXISTS tierlist_quadro (
        chave   VARCHAR(32) NOT NULL PRIMARY KEY,
        posicoes TEXT NOT NULL,
        versao  INT NOT NULL DEFAULT 0,
        atualizado_em DATETIME NOT NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare("INSERT IGNORE INTO tierlist_quadro (chave, posicoes, versao, atualizado_em)
                   VALUES ('ELITE', '{}', 0, NOW())")->execute();
}

const TL_FAIXAS = ['campeao', 'briga', 'meio', 'luta', 'cai'];

/**
 * Uma lista por faixa, sempre as cinco, sempre de inteiros, sem repetido.
 *
 * Também converte o formato antigo (mapa time→faixa), que é o que está
 * gravado de antes de a ordem existir: sem isto o primeiro acesso depois
 * do deploy acharia o quadro vazio e a liga perderia o que já tinha
 * montado.
 */
function tlNormalizar($bruto): array
{
    $saida = array_fill_keys(TL_FAIXAS, []);
    if (!is_array($bruto)) return $saida;

    $vistos = [];
    foreach ($bruto as $chave => $valor) {
        if (is_array($valor)) {                       // formato novo: faixa => [ids]
            if (!in_array((string) $chave, TL_FAIXAS, true)) continue;
            foreach ($valor as $id) {
                $id = (int) $id;
                if ($id > 0 && !isset($vistos[$id])) { $saida[(string) $chave][] = $id; $vistos[$id] = true; }
            }
        } elseif (is_string($valor) && in_array($valor, TL_FAIXAS, true)) {   // formato antigo
            $id = (int) $chave;
            if ($id > 0 && !isset($vistos[$id])) { $saida[$valor][] = $id; $vistos[$id] = true; }
        }
    }
    return $saida;
}

function tlLer(PDO $pdo): array
{
    tlGarantir($pdo);
    $r = $pdo->query("SELECT posicoes, versao FROM tierlist_quadro WHERE chave = 'ELITE'")->fetch(PDO::FETCH_ASSOC);
    return [
        'versao'   => (int) ($r['versao'] ?? 0),
        'posicoes' => tlNormalizar($r ? json_decode((string) $r['posicoes'], true) : []),
    ];
}

$FAIXAS_IDS = array_merge(TL_FAIXAS, ['banco']);

if (isset($_GET['api'])) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    try {
        tlGarantir($pdo);
        $acao = (string) $_GET['api'];

        if ($acao === 'ler') {
            /* A tela manda a versão que já tem. Igual = nada mudou, e a
               resposta não carrega o quadro inteiro à toa. */
            $estado = tlLer($pdo);
            $tenho = isset($_GET['v']) ? (int) $_GET['v'] : -1;
            echo json_encode($tenho === $estado['versao']
                ? ['versao' => $estado['versao'], 'igual' => true]
                : $estado);
            exit;
        }

        if ($acao === 'mover') {
            $corpo  = json_decode(file_get_contents('php://input') ?: '[]', true) ?: [];
            $id     = (int) ($corpo['id'] ?? 0);
            $faixa  = (string) ($corpo['faixa'] ?? '');
            $indice = max(0, (int) ($corpo['indice'] ?? 0));
            if ($id <= 0 || !in_array($faixa, $FAIXAS_IDS, true)) {
                http_response_code(400);
                echo json_encode(['erro' => 'pedido inválido']); exit;
            }
            // O time TEM que ser da ELITE: senão a chave do quadro vira
            // depósito de id qualquer que alguém mande pelo console.
            $st = $pdo->prepare("SELECT 1 FROM teams WHERE id = ? AND league = 'ELITE'");
            $st->execute([$id]);
            if (!$st->fetchColumn()) { http_response_code(400); echo json_encode(['erro' => 'time fora da ELITE']); exit; }

            /* Ler-mexer-gravar dentro de uma transação, com a linha travada:
               duas pessoas movendo no mesmo segundo escrevem uma de cada
               vez, em vez de uma sobrescrever a leitura da outra. */
            $pdo->beginTransaction();
            $r = $pdo->query("SELECT posicoes, versao FROM tierlist_quadro WHERE chave = 'ELITE' FOR UPDATE")->fetch(PDO::FETCH_ASSOC);
            $pos = tlNormalizar(json_decode((string) ($r['posicoes'] ?? '{}'), true));

            // Sai de onde estiver — inclusive da própria faixa, que é como
            // reordenar dentro dela funciona.
            foreach ($pos as $f => $lista) {
                $pos[$f] = array_values(array_filter($lista, fn($x) => (int) $x !== $id));
            }
            if ($faixa !== 'banco') {
                $destino = $pos[$faixa] ?? [];
                $indice  = min($indice, count($destino));
                array_splice($destino, $indice, 0, [$id]);
                $pos[$faixa] = $destino;
            }
            $versao = (int) ($r['versao'] ?? 0) + 1;
            $pdo->prepare("UPDATE tierlist_quadro SET posicoes = ?, versao = ?, atualizado_em = NOW() WHERE chave = 'ELITE'")
                ->execute([json_encode($pos), $versao]);
            $pdo->commit();
            echo json_encode(['versao' => $versao, 'posicoes' => $pos]);
            exit;
        }

        if ($acao === 'limpar') {
            $pdo->prepare("UPDATE tierlist_quadro SET posicoes = '{}', versao = versao + 1, atualizado_em = NOW() WHERE chave = 'ELITE'")->execute();
            echo json_encode(tlLer($pdo));
            exit;
        }

        http_response_code(404);
        echo json_encode(['erro' => 'ação desconhecida']);
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        error_log('[tierlist] ' . $e->getMessage());
        http_response_code(500);
        echo json_encode(['erro' => 'falhou']);
    }
    exit;
}

$estadoInicial = tlLer($pdo);

/* Os 32 da ELITE. */
$times = $pdo->query("SELECT id, city, name, photo_url, public_primary_color AS cor
                        FROM teams WHERE league = 'ELITE'
                    ORDER BY city, name")->fetchAll(PDO::FETCH_ASSOC);

/* O RANKING DA LIGA, que é a régua que a pessoa usa pra decidir.
   É `teams.ranking_points` — o mesmo número do /ranking e o que a liga
   discute. A tabela `team_ranking_points`, por temporada, NÃO serve aqui:
   ela fica zerada até a temporada fechar. @see backend/duvida_dados.php */
$ranking = $pdo->query("SELECT id, city, name, photo_url, ranking_points
                          FROM teams WHERE league = 'ELITE'
                      ORDER BY ranking_points DESC, name ASC")->fetchAll(PDO::FETCH_ASSOC);

/* O QUINTETO DE CADA TIME, tudo de uma vez.
   São 32 times; uma consulta por clique seria 32 idas ao servidor pra
   mostrar cinco nomes que já estão no banco. Vem junto com a página. */
$titulares = [];
$st = $pdo->query("SELECT p.team_id, p.name, p.ovr, p.age, p.position, p.role
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
            'i' => (int) $p['age'],
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
.linha span{white-space:nowrap;overflow:hidden;text-overflow:ellipsis;flex:1;min-width:0}
.linha .pts{color:var(--roxo);font-variant-numeric:tabular-nums;font-size:11px;flex:0 0 auto}
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
.jog .idade{font-size:11px;color:var(--txt2);font-variant-numeric:tabular-nums;flex:0 0 auto}
.jog .o{font-weight:800;font-variant-numeric:tabular-nums;min-width:26px;text-align:right}
#aviso{opacity:0;transition:opacity .25s;font-size:12px;color:var(--roxo);font-weight:600;flex:0 0 auto}
#aviso.on{opacity:1}
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
  <div class="sub">Quadro único da liga — o que você move, todo mundo vê. Arraste, ou toque no escudo e depois na faixa. Toque de novo pra ver o quinteto.</div>
  <span id="aviso">alguém mexeu</span>
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
    <h2>Ranking da ELITE</h2>
    <div class="conf">
      <?php foreach ($ranking as $i => $r): ?>
        <div class="linha">
          <span class="pos"><?= $i + 1 ?></span>
          <?php $e = tlEscudo($r['photo_url'], 48); ?>
          <?php if ($e): ?><img src="<?= htmlspecialchars($e) ?>" alt="" loading="lazy"><?php else: ?><span class="pos"></span><?php endif; ?>
          <span><?= htmlspecialchars(trim($r['city'] . ' ' . $r['name'])) ?></span>
          <b class="pts"><?= (int) $r['ranking_points'] ?></b>
        </div>
      <?php endforeach; ?>
    </div>
    <?php if (!$ranking): ?><div class="vazio">Ranking vazio.</div><?php endif; ?>
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
const porId = {};
TIMES.forEach(t => porId[t.id] = t);

let VERSAO = <?= (int) $estadoInicial['versao'] ?>;
let POSICOES = <?= json_encode((object) $estadoInicial['posicoes']) ?>;

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

/* Desenha na ORDEM GUARDADA de cada faixa — é ela que diz quem é o primeiro,
   que numa tier list é metade da opinião. Só o banco sai na ordem dos times:
   lá é depósito, não ranking. */
function montar(){
  zonas().forEach(z => z.innerHTML = '');
  const colocados = new Set();
  Object.keys(POSICOES).forEach(faixa => {
    const z = document.querySelector('.zona[data-faixa="' + faixa + '"]');
    if (!z) return;
    (POSICOES[faixa] || []).forEach(id => {
      const t = porId[id];
      if (!t) return;
      z.appendChild(criarChip(t));
      colocados.add(String(id));
    });
  });
  const banco = document.getElementById('banco');
  TIMES.forEach(t => { if (!colocados.has(String(t.id))) banco.appendChild(criarChip(t)); });
}
montar();

/* ONDE O ESCUDO FOI SOLTO, dentro da faixa.
   Compara com o meio horizontal de cada escudo já lá: passou do meio,
   entra depois dele. É o que faz "soltar entre o 2º e o 3º" virar
   exatamente isso, em vez de cair sempre no fim. */
function indiceNaZona(zona, x, y, ignorar){
  const irmaos = Array.from(zona.querySelectorAll('.chip')).filter(c => c !== ignorar);
  for (let i = 0; i < irmaos.length; i++) {
    const r = irmaos[i].getBoundingClientRect();
    if (y < r.bottom && x < r.left + r.width / 2) return i;
  }
  return irmaos.length;
}

/* ── ao vivo ───────────────────────────────────────────────
   Mover manda só o time movido. O servidor devolve o quadro inteiro já com
   a mexida de todo mundo, então duas pessoas mexendo juntas convergem
   sozinhas. */
const aviso = document.getElementById('aviso');
function piscar(){
  if (!aviso) return;
  aviso.classList.add('on');
  clearTimeout(piscar.t);
  piscar.t = setTimeout(() => aviso.classList.remove('on'), 1200);
}

async function mover(id, faixa, indice){
  // A tela já está pintada (o escudo foi movido no DOM antes daqui); o
  // servidor confirma e devolve o quadro com a mexida de todo mundo.
  try {
    const r = await fetch('?api=mover', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ id: Number(id), faixa, indice: indice | 0 })
    });
    const d = await r.json();
    if (d && d.posicoes) { VERSAO = d.versao; POSICOES = d.posicoes; montar(); }
  } catch (e) { /* sem rede: fica o que está na tela, o poll conserta */ }
}

async function sincronizar(){
  if (arrastando) return;              // ninguém tem o escudo puxado da mão
  try {
    const r = await fetch('?api=ler&v=' + VERSAO, { cache: 'no-store' });
    const d = await r.json();
    if (d && !d.igual && d.posicoes) {
      VERSAO = d.versao; POSICOES = d.posicoes;
      const marcado = escolhido ? escolhido.dataset.id : null;
      montar();
      if (marcado) {
        const c = document.querySelector('.chip[data-id="' + marcado + '"]');
        if (c) { escolhido = c; c.classList.add('escolhido'); }
      }
      piscar();
    }
  } catch (e) {}
}
setInterval(sincronizar, 1500);
document.addEventListener('visibilitychange', () => { if (!document.hidden) sincronizar(); });

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
    chip.classList.remove('fantasma');
    if (clone) clone.remove();
    clone = null;
    limparAlvo();
    if (z) {
      const i = indiceNaZona(z, ev.clientX, ev.clientY, chip);
      const irmaos = Array.from(z.querySelectorAll('.chip')).filter(c => c !== chip);
      if (i >= irmaos.length) z.appendChild(chip); else z.insertBefore(chip, irmaos[i]);
      mover(chip.dataset.id, z.dataset.faixa, i);
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
    const id = escolhido.dataset.id;
    // No toque não há ponto de soltura dentro da faixa: vai pro fim, que é
    // o que a pessoa espera de "põe esse aqui".
    const i = Array.from(z.querySelectorAll('.chip')).filter(c => c !== escolhido).length;
    z.appendChild(escolhido);
    desmarcar();
    mover(id, z.dataset.faixa, i);
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
        + '<span class="idade">' + (j.i ? j.i + ' anos' : '') + '</span>'
        + '<span class="o">' + j.o + '</span></div>').join('')
    : '<div class="vazio">Este time ainda não tem elenco cadastrado.</div>';
  fundo.classList.add('on');
}
document.getElementById('mFechar').onclick = () => fundo.classList.remove('on');
fundo.onclick = ev => { if (ev.target === fundo) fundo.classList.remove('on'); };
document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fundo.classList.remove('on'); });

/* ── limpar ───────────────────────────────────────────────── */
document.getElementById('limpar').onclick = async () => {
  // O quadro é de todo mundo, então limpar apaga o que a liga montou. Dizer
  // isso no aviso é o que separa um clique curioso de um estrago.
  if (!confirm('Isso limpa o quadro PARA TODO MUNDO. Continuar?')) return;
  desmarcar();
  try {
    const d = await (await fetch('?api=limpar')).json();
    if (d && d.posicoes) { VERSAO = d.versao; POSICOES = d.posicoes; montar(); }
  } catch (e) {}
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
