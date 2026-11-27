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
$times = $pdo->query("SELECT t.id, t.league, t.city, t.name, t.photo_url, t.ranking_points,
                             u.name AS gm_nome
                        FROM teams t
                        LEFT JOIN users u ON u.id = t.user_id
                       WHERE t.league IN ('ELITE','NEXT','RISE','ROOKIE')
                    ORDER BY t.league, t.ranking_points DESC, t.name ASC")->fetchAll(PDO::FETCH_ASSOC);

/**
 * O PRIMEIRO NOME DO GM — e o sobrenome só quando ele é preciso.
 *
 * "Marcos Medeiros" vira "Marcos", que é como a liga chama as pessoas. Só
 * que há três Caios na ELITE (Gomes, Cardoso e Motta): três cards escritos
 * "Caio" não identificam ninguém, e o botão existe justamente pra
 * identificar. Quando o primeiro nome se repete, entra a inicial do
 * sobrenome — "Caio G.", "Caio M.".
 *
 * A contagem é sobre TODAS as ligas de uma vez. Decidir por liga faria o
 * mesmo GM aparecer como "Caio" numa tier list e "Caio M." noutra,
 * dependendo de quais ligas estão marcadas na hora.
 *
 * @param array<int,string> $todos  todos os nomes de GM, pra achar repetido
 */
function tlGm(?string $completo, array $todos): string
{
    $completo = trim((string) $completo);
    if ($completo === '') return '';

    $partes = preg_split('/\s+/', $completo);
    $primeiro = $partes[0];

    $repetido = 0;
    foreach ($todos as $outro) {
        $p = preg_split('/\s+/', trim($outro));
        if ($p && mb_strtolower($p[0]) === mb_strtolower($primeiro)) $repetido++;
    }
    if ($repetido <= 1 || count($partes) < 2) return $primeiro;

    return $primeiro . ' ' . mb_strtoupper(mb_substr(end($partes), 0, 1)) . '.';
}

$nomesDeGm = [];
foreach ($times as $t) if (trim((string) $t['gm_nome']) !== '') $nomesDeGm[] = (string) $t['gm_nome'];

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

/**
 * O escudo SEMPRE pelo nosso domínio.
 *
 * Apontava direto pro `photo_url`, e os 30 times da ROOKIE moram no
 * cdn.nba.com — imagem de outro domínio contamina o canvas e o navegador
 * recusa gerar a imagem ("Tainted canvases may not be exported"). Bastava
 * um time da ROOKIE no quadro pra o Copiar imagem morrer.
 *
 * @see escudo-time.php, que baixa o de fora uma vez e guarda aqui
 */
function tlEscudo(int $teamId, ?string $url, int $px = 128): string
{
    if (trim((string) $url) === '') return '';
    return '/escudo-time.php?t=' . $teamId . '&s=' . $px;
}

/* S no topo: a régua que todo mundo já conhece de tier list.
   Nomeava CAMPEÃO / BRIGA PELO TÍTULO / ... e isso tinha dois problemas —
   amarrava a página a um tema só (campeonato), e um nome já pronto e
   plausível não convida ninguém a trocar. Letra é régua vazia: quem monta
   escreve o que quiser por cima.

   Os ids NÃO mudam junto. Eles são a chave do que está guardado no
   navegador de quem já montou, e renomear a chave apagaria a montagem. */
$FAIXAS = [
    ['id' => 'campeao', 'nome' => 'S', 'cor' => '#f5c542'],
    ['id' => 'briga',   'nome' => 'A', 'cor' => '#6ee7a8'],
    ['id' => 'meio',    'nome' => 'B', 'cor' => '#7cc4ff'],
    ['id' => 'luta',    'nome' => 'C', 'cor' => '#ffa94d'],
    ['id' => 'cai',     'nome' => 'F', 'cor' => '#ff6b6b'],
];

$dados = [];
foreach ($times as $t) {
    $dados[] = [
        'id'  => (int) $t['id'],
        'lg'  => (string) $t['league'],
        'n'   => trim(((string) $t['city']) . ' ' . ((string) $t['name'])),
        // Só o apelido, sem a cidade: é como a liga chama o time no grupo, e
        // é o que cabe embaixo de um escudo de 42px.
        'c'   => trim((string) $t['name']),
        // Sem GM, o card cai no apelido do time: card em branco não diz nada.
        'gm'  => tlGm($t['gm_nome'] ?? null, $nomesDeGm) ?: trim((string) $t['name']),
        'img' => tlEscudo((int) $t['id'], $t['photo_url']),
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
/* O TRACEJADO FICA SEMPRE VISÍVEL, não só no hover.
   Com o sublinhado só aparecendo ao passar o mouse, ninguém descobria que
   dava pra editar — e no celular não existe hover, então lá não aparecia
   nunca. */
#titulo{
  margin:0;font-size:23px;letter-spacing:.4px;font-weight:800;
  border-bottom:1px dashed #3d3d4e;padding:2px 6px;border-radius:5px;min-width:90px;outline:none;
}
#titulo:hover{border-bottom-color:var(--roxo)}
#titulo:focus{border-bottom-color:var(--roxo);background:var(--card)}
/* Vazio mostra o convite, em vez de um título pronto que ninguém troca. */
#titulo:empty::before{content:attr(data-vazio);color:#5c5c72;font-weight:600;font-style:italic}
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
.ligas .divisor{width:1px;align-self:stretch;background:var(--linha);margin:2px 7px}

.palco{max-width:1400px;margin:0 auto;display:grid;grid-template-columns:minmax(0,1fr) 300px;gap:16px;align-items:start}

/* ── as faixas ───────────────────────────────────────────── */
.faixa{display:flex;background:var(--card);border:1px solid var(--linha);border-radius:12px;margin-bottom:10px;overflow:hidden}
.rotulo{
  width:118px;flex:0 0 118px;display:flex;align-items:center;justify-content:center;
  text-align:center;font-weight:800;font-size:26px;line-height:1.15;padding:10px 7px;
  color:#0b0b0f;letter-spacing:.3px;outline:none;word-break:break-word;cursor:text;
  position:relative;
}
/* Texto longo vira letra miúda: quem troca "S" por "BRIGA PELO TÍTULO"
   precisa que caiba, e quem fica na letra quer ela grande. */
.rotulo.texto{font-size:12px}
.rotulo:focus{box-shadow:inset 0 0 0 2px rgba(0,0,0,.45)}
/* O lápis no canto é o que diz "isto se edita" sem gastar uma legenda. */
.rotulo::after{
  content:"✎";position:absolute;top:3px;right:5px;font-size:10px;
  opacity:.4;font-weight:400;pointer-events:none;
}
.rotulo:focus::after{opacity:0}
.zona{flex:1;min-height:84px;display:flex;flex-wrap:wrap;gap:7px;padding:9px;align-content:flex-start}
.zona.alvo{background:rgba(168,85,247,.14);outline:2px dashed var(--roxo);outline-offset:-5px}

.banco{background:var(--card);border:1px solid var(--linha);border-radius:12px;padding:10px;margin-top:14px}
.banco h2{margin:0 0 9px;font-size:12px;color:var(--txt2);letter-spacing:1.3px;text-transform:uppercase}
.banco .zona{min-height:96px;padding:0}

/* ── o escudo ────────────────────────────────────────────── */
.chip{
  width:72px;height:90px;border-radius:10px;background:var(--card2);
  border:1px solid var(--linha);display:flex;flex-direction:column;
  align-items:center;justify-content:center;gap:3px;padding:5px 3px;
  cursor:grab;touch-action:none;user-select:none;position:relative;overflow:hidden;flex:0 0 auto;
}
.chip img{width:42px;height:42px;object-fit:contain;pointer-events:none}
.chip .mono{font-weight:800;font-size:15px;color:var(--txt2);pointer-events:none;height:42px;display:flex;align-items:center}
/* Duas linhas, não uma com "...": "Villanova Valleys" virava "Villanova ..."
   e nome cortado num card de tier list não identifica time nenhum. */
.chip .nome{
  font-size:11px;font-weight:700;line-height:1.1;color:#c9c9d6;letter-spacing:.1px;
  width:100%;text-align:center;pointer-events:none;word-break:break-word;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;
}
.chip.escolhido{border-color:var(--roxo);box-shadow:0 0 0 2px rgba(168,85,247,.45)}
.chip.voando{position:fixed;z-index:999;pointer-events:none;cursor:grabbing;
  transform:scale(1.12);box-shadow:0 12px 28px rgba(0,0,0,.6);border-color:var(--roxo)}
.chip.fantasma{opacity:.28}

/* ── o ranking do lado ───────────────────────────────────── */
.palco.semranking{grid-template-columns:minmax(0,1fr)}
.palco.semranking .lateral{display:none}
.lateral{background:var(--card);border:1px solid var(--linha);border-radius:12px;position:sticky;top:16px;
  max-height:calc(100vh - 32px);display:flex;flex-direction:column;overflow:hidden}
.lateral h2{margin:0;font-size:12px;letter-spacing:1.3px;color:var(--txt2);text-transform:uppercase;
  padding:12px 12px 9px;border-bottom:1px solid var(--linha);flex:0 0 auto}
/* A rolagem fica SÓ na lista, não no painel inteiro: assim o título
   "Ranking" não sobe junto e a pessoa não perde a referência do que
   está olhando. */
#ranking{overflow-y:auto;padding:4px 12px 12px;flex:1 1 auto;scrollbar-width:thin;scrollbar-color:#3a3a4d transparent}
#ranking::-webkit-scrollbar{width:8px}
#ranking::-webkit-scrollbar-track{background:transparent;margin:4px 0}
#ranking::-webkit-scrollbar-thumb{background:#32323f;border-radius:99px;border:2px solid var(--card)}
#ranking::-webkit-scrollbar-thumb:hover{background:var(--roxo)}
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
  .rotulo{width:76px;flex:0 0 76px;font-size:22px;padding:8px 5px}
  .rotulo.texto{font-size:10px}
  .chip{width:62px;height:80px;gap:2px}
  .chip img{width:36px;height:36px}
  .chip .mono{height:36px;font-size:13px}
  .chip .nome{font-size:10px}
  #titulo{font-size:19px}
  .lapis{flex-basis:100%}
}
</style>
</head>
<body>

<div class="topo">
  <h1 id="titulo" contenteditable="true" spellcheck="false" data-vazio="Coloque seu tema aqui"></h1>
  <span class="lapis">✎ editável</span>
  <button class="btn" id="verRanking">Esconder ranking</button>
  <button class="btn" id="limpar">Limpar</button>
  <button class="btn primario" id="copiar">Copiar imagem</button>
</div>

<div class="ligas">
  <span class="rot">Ligas</span>
  <?php foreach ($LIGAS as $lg): ?>
    <button class="liga<?= $lg === 'ELITE' ? ' on' : '' ?>" data-liga="<?= $lg ?>"><?= $lg ?></button>
  <?php endforeach; ?>

  <?php /* Fica aqui, e não entre os botões de ação do topo, porque é da
           mesma natureza das ligas: escolhe O QUE entra no quadro, não o
           que fazer com ele. Lá em cima virava mais um botão num monte. */ ?>
  <span class="divisor"></span>
  <span class="rot">Mostrar</span>
  <button class="liga on" data-modo="time">Times</button>
  <button class="liga" data-modo="gm">GMs</button>
</div>

<div class="palco" id="palco">
  <div>
    <div id="faixas">
      <?php foreach ($FAIXAS as $f): ?>
      <div class="faixa">
        <div class="rotulo" contenteditable="true" spellcheck="false"
             data-faixa-nome="<?= htmlspecialchars($f['id']) ?>"
             data-padrao="<?= htmlspecialchars($f['nome']) ?>"
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

  <aside class="lateral" id="lateral">
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
  titulo: '',
  ligas: ['ELITE'],
  nomes: {},
  // Escondido de saída: o ranking é consulta, o quadro é o que a pessoa
  // veio fazer — e ele não entra no print.
  ranking: false,
  modo: 'time',          // 'time' = apelido do clube · 'gm' = quem comanda
  v: 3,
  faixas: { campeao: [], briga: [], meio: [], luta: [], cai: [] }
};
/* O QUE ESTAVA GRAVADO, ANTES DE MISTURAR COM O PADRÃO.
   Misturar primeiro e olhar depois não funciona: o padrão PREENCHE o campo
   que falta, então um estado antigo sem `v` saía do Object.assign já com
   v:3 e a limpeza abaixo nunca rodava. Quem decide se é estado velho é o
   que veio do disco, não o resultado da mistura. */
let bruto = {};
try { bruto = JSON.parse(localStorage.getItem(CHAVE) || '{}') || {}; } catch (err) { bruto = {}; }
let E = Object.assign({}, PADRAO, bruto);
if (!E.faixas || typeof E.faixas !== 'object') E.faixas = Object.assign({}, PADRAO.faixas);
if (!Array.isArray(E.ligas) || !E.ligas.length) E.ligas = ['ELITE'];

/* LIMPEZA ÚNICA DOS NOMES VELHOS.
   Até a v2 a página gravava o nome de TODA faixa, padrão incluído. Quem
   abriu antes da troca pra S/A/B/C/F carrega CAMPEÃO, BRIGA PELO TÍTULO e
   companhia guardados como se tivesse escolhido — e nunca mais veria as
   letras. Comparar com a lista velha não bastava: basta um O a mais
   digitado sem querer pra o nome não bater com nada.

   Então apaga os nomes uma vez só e segue. A montagem, as ligas e o tema
   escrito ficam — eles são escolha de verdade. Daqui em diante só o que
   for diferente do padrão é guardado, e esta limpeza não precisa voltar. */
if (!bruto.v || bruto.v < 3) { E.nomes = {}; E.v = 3; }

function guardar(){
  const f = {};
  document.querySelectorAll('.zona').forEach(z => {
    if (z.dataset.faixa === 'banco') return;
    f[z.dataset.faixa] = Array.from(z.querySelectorAll('.chip')).map(c => Number(c.dataset.id));
  });
  E.faixas = f;
  E.titulo = document.getElementById('titulo').textContent.trim();
  /* SÓ GUARDA O QUE FOI TROCADO.
     Guardava o nome de toda faixa, inclusive o padrão — e aí o padrão
     virava escolha do usuário: quando as faixas passaram de CAMPEÃO/BRIGA
     PELO TÍTULO/... para S/A/B/C/F, quem já tinha aberto a página uma vez
     continuou vendo os nomes velhos pra sempre, sem nunca ter escolhido
     nada. Padrão não é preferência. */
  const nomes = {};
  document.querySelectorAll('[data-faixa-nome]').forEach(r => {
    const txt = r.textContent.trim();
    if (txt && txt !== r.dataset.padrao) nomes[r.dataset.faixaNome] = txt;
  });
  E.nomes = nomes;
  try { localStorage.setItem(CHAVE, JSON.stringify(E)); } catch (err) {}
}

const naLiga = t => E.ligas.indexOf(t.lg) !== -1;

/* ── monta os escudos ─────────────────────────────────────── */
/* O que vai escrito embaixo do escudo: o apelido do clube ou quem o
   comanda. A mesma função serve a tela e ao print, pra os dois nunca
   discordarem. */
const rotuloDe = t => (E.modo === 'gm' ? t.gm : t.c);

function criarChip(t){
  const d = document.createElement('div');
  d.className = 'chip';
  d.dataset.id = t.id;
  d.title = t.n + ' — ' + t.lg;
  const topo = t.img
    ? '<img src="' + t.img + '" alt="" draggable="false" loading="lazy">'
    : '<span class="mono">' + t.n.split(' ').map(p => p[0]).join('').slice(0,3).toUpperCase() + '</span>';
  d.innerHTML = topo + '<span class="nome">' + rotuloDe(t).replace(/[<>&]/g, '') + '</span>';
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
document.getElementById('titulo').textContent = E.titulo || '';
document.querySelectorAll('[data-faixa-nome]').forEach(r => {
  const n = E.nomes && E.nomes[r.dataset.faixaNome];
  if (n) r.textContent = n;
  ajustarRotulo(r);
});
/* Uma letra sozinha fica grande; qualquer coisa maior vira texto miúdo. */
function ajustarRotulo(r){ r.classList.toggle('texto', r.textContent.trim().length > 2); }

// Enter fecha a edição em vez de abrir linha nova dentro do rótulo.
document.querySelectorAll('#titulo,[data-faixa-nome]').forEach(el => {
  el.addEventListener('keydown', ev => { if (ev.key === 'Enter') { ev.preventDefault(); el.blur(); } });
  el.addEventListener('input', () => { if (el.dataset.faixaNome) ajustarRotulo(el); });
  el.addEventListener('blur', () => {
    // Faixa sem nome não é faixa; título vazio só mostra o convite de novo.
    if (!el.textContent.trim() && el.dataset.padrao) el.textContent = el.dataset.padrao;
    if (el.dataset.faixaNome) ajustarRotulo(el);
    guardar();
  });
  // Cola só texto: colar de outra página traria HTML e cor junto.
  el.addEventListener('paste', ev => {
    ev.preventDefault();
    document.execCommand('insertText', false, (ev.clipboardData || window.clipboardData).getData('text'));
  });
});

/* ── esconder o ranking ────────────────────────────────────
   Ele é a régua, mas quem já tem opinião formada quer o quadro inteiro na
   tela — e no print o ranking não entra. A escolha fica guardada. */
const botaoRanking = document.getElementById('verRanking');
function aplicarRanking(){
  document.getElementById('palco').classList.toggle('semranking', !E.ranking);
  botaoRanking.textContent = E.ranking ? 'Esconder ranking' : 'Mostrar ranking';
}
botaoRanking.onclick = () => { E.ranking = !E.ranking; aplicarRanking(); guardar(); };
aplicarRanking();

/* ── time ou GM ────────────────────────────────────────────
   A mesma tier list responde duas perguntas diferentes: "que time é bom?"
   e "que GM é bom?". Trocar o rótulo é o bastante — os escudos continuam
   sendo a cara de cada um. */
function aplicarModo(){
  document.querySelectorAll('[data-modo]').forEach(b => b.classList.toggle('on', b.dataset.modo === E.modo));
  // Só o texto do card muda; a montagem fica onde está.
  document.querySelectorAll('.chip').forEach(c => {
    const t = porId[c.dataset.id];
    const n = c.querySelector('.nome');
    if (t && n) n.textContent = rotuloDe(t);
  });
}
document.querySelectorAll('[data-modo]').forEach(b => {
  b.onclick = () => {
    if (E.modo === b.dataset.modo) return;
    E.modo = b.dataset.modo;
    aplicarModo();
    guardar();
  };
});
aplicarModo();

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
async function desenhar(){
  {
    const linhas = Array.from(document.querySelectorAll('.faixa')).map(f => ({
      nome: f.querySelector('.rotulo').textContent.trim(),
      cor:  f.querySelector('.rotulo').style.background,
      ids:  Array.from(f.querySelectorAll('.chip')).map(c => Number(c.dataset.id))
    }));

    const L = 96, NOME = 23, CEL = L + NOME, ROT = 210, GAP = 10, POR_LINHA = 8, PAD = 26;
    const alturas = linhas.map(f => Math.max(1, Math.ceil(f.ids.length / POR_LINHA)) * (CEL + GAP) + GAP);
    const W = ROT + POR_LINHA * (L + GAP) + GAP + PAD * 2;
    const H = PAD + 76 + alturas.reduce((a, b) => a + b + GAP, 0) + 30;

    const cv = document.createElement('canvas');
    cv.width = W; cv.height = H;
    const c = cv.getContext('2d');
    c.fillStyle = '#0b0b0f'; c.fillRect(0, 0, W, H);
    c.fillStyle = '#f2f2f7'; c.font = '700 34px system-ui,sans-serif';
    // Sem tema escrito, o print leva o nome da página — e não o convite.
    c.fillText(document.getElementById('titulo').textContent.trim() || 'Tier List da FBA', PAD, PAD + 36);
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
      c.fillStyle = '#0b0b0f';
      // Letra sozinha grande, texto miúdo — mesma régra da tela.
      const tamRot = f.nome.trim().length <= 2 ? 52 : 17;
      c.font = '800 ' + tamRot + 'px system-ui,sans-serif';
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
      ls.forEach((ln, i) => c.fillText(ln, PAD + ROT / 2, y + h / 2 + tamRot / 3 + (i - (ls.length - 1) / 2) * (tamRot + 3)));
      c.textAlign = 'left';

      for (let i = 0; i < f.ids.length; i++) {
        const t = porId[f.ids[i]];
        const cx = PAD + ROT + GAP + (i % POR_LINHA) * (L + GAP);
        const cy = y + GAP + Math.floor(i / POR_LINHA) * (CEL + GAP);
        c.fillStyle = '#1b1b24'; c.fillRect(cx, cy, L, CEL);
        const im = await imagem(t && t.img);
        if (im) c.drawImage(im, cx + 8, cy + 8, L - 16, L - 16);
        else if (t) {
          c.fillStyle = '#9a9aae'; c.font = '800 22px system-ui,sans-serif'; c.textAlign = 'center';
          c.fillText(t.n.split(' ').map(p => p[0]).join('').slice(0,3).toUpperCase(), cx + L/2, cy + L/2 + 8);
          c.textAlign = 'left';
        }
        if (t) {
          // O rótulo embaixo, encolhendo a fonte até caber na célula:
          // cortar com "..." num print que vai pro grupo é pior que 9px.
          const rot = rotuloDe(t);
          c.textAlign = 'center';
          c.fillStyle = '#c9c9d6';
          let tam = 16;
          do { c.font = '700 ' + tam + 'px system-ui,sans-serif'; tam--; }
          while (c.measureText(rot).width > L - 6 && tam > 8);
          c.fillText(rot, cx + L / 2, cy + L + 15);
          c.textAlign = 'left';
        }
      }
      y += h + GAP;
    }
    return cv;
  }
}

/** O canvas vira Blob — não data: URL. @see os botões abaixo */
const paraBlob = cv => new Promise((ok, nao) =>
  cv.toBlob(b => b ? ok(b) : nao(new Error('canvas vazio')), 'image/png'));

/* ── COPIAR ────────────────────────────────────────────────
 * O único botão: cola direto no WhatsApp, sem passar pela pasta de
 * downloads. O Baixar saiu — o arquivo salvo era um desvio pra chegar no
 * mesmo lugar.
 *
 * O ClipboardItem tem que ser montado ANTES do await em alguns
 * navegadores, então a promessa do blob vai dentro dele. */
document.getElementById('copiar').onclick = async () => {
  const btn = document.getElementById('copiar');
  const antes = btn.textContent;
  btn.textContent = 'Gerando...';
  try {
    if (!navigator.clipboard || !window.ClipboardItem) throw new Error('navegador sem suporte');
    await navigator.clipboard.write([
      new ClipboardItem({ 'image/png': desenhar().then(paraBlob) })
    ]);
    btn.textContent = 'Copiado!';
    setTimeout(() => btn.textContent = antes, 1800);
    return;
  } catch (e) {
    /* Sem clipboard de imagem (Firefox, navegador antigo), abre a imagem
       numa aba: o print continua possível, é só segurar e salvar. */
    try {
      const url = URL.createObjectURL(await paraBlob(await desenhar()));
      window.open(url, '_blank');
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    } catch (e2) {
      alert('Não consegui gerar a imagem: ' + e2.message);
    }
  }
  btn.textContent = antes;
};
</script>
</body>
</html>
