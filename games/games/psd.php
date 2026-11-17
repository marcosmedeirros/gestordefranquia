<?php
/**
 * FBA SHOWDOWN — a tela.
 *
 * Duas telas no mesmo arquivo, porque são dois momentos da mesma coisa:
 *
 *   SALÃO   — saldo, aposta, e as três portas: treino (contra a máquina,
 *             sem dinheiro), fila (qualquer um com a mesma entrada) e
 *             código (desafiar alguém pelo grupo).
 *   QUADRA  — a partida. Os dois escolhem escondido; quando as duas
 *             escolhas entram, o turno resolve e a tela ANIMA os eventos
 *             que o servidor devolveu.
 *
 * A tela não sabe regra nenhuma. Ela não calcula dano, não decide se
 * acertou e não sabe o que o adversário escolheu — só acende o que o
 * servidor disse que pode e anima o que ele disse que aconteceu.
 *
 * @see games/core/psd_motor.php   as regras
 * @see games/core/psd_online.php  a partida, o relógio e a aposta
 */

require '../core/conexao.php';
require_once __DIR__ . '/../core/psd_online.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];
$meuNome = (string)($_SESSION['nome'] ?? $_SESSION['user_name'] ?? 'Você');

// ─────────────────────────────── a API ───────────────────────────────

$acao = (string)($_REQUEST['acao'] ?? '');
if ($acao !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $r = match ($acao) {
        'ver'      => psdVer($pdo, $user_id),
        'abrir'    => psdAbrir($pdo, $user_id, $meuNome,
                               in_array($_POST['modo'] ?? '', ['treino', 'fila', 'codigo'], true) ? $_POST['modo'] : 'treino',
                               (int)($_POST['aposta'] ?? 0)),
        'entrar'   => psdEntrar($pdo, $user_id, $meuNome, (string)($_POST['codigo'] ?? '')),
        'escolher' => psdEscolher($pdo, $user_id, ['tipo' => (string)($_POST['tipo'] ?? 'golpe'), 'i' => (int)($_POST['i'] ?? 0)]),
        'desistir' => psdDesistir($pdo, $user_id),
        'cancelar' => psdDesistirDaEspera($pdo, $user_id),
        'ranking'  => ['lista' => psdRanking($pdo)],
        default    => ['ok' => false, 'erro' => 'Ação desconhecida.'],
    };
    echo json_encode($r, JSON_UNESCAPED_UNICODE);
    exit;
}

$saldo = psdSaldo($pdo, $user_id);
?>
<!doctype html>
<html lang="pt-BR">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FBA Showdown</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
  :root{
    --bg:#0e1016; --painel:#161a23; --linha:#242a37; --txt:#e8ecf5; --fraco:#8d97ab;
    --hp-verde:#49d049; --hp-amarelo:#f5c542; --hp-vermelho:#ef4444; --acento:#f5c542;
    --normal:#A8A878;--fire:#F08030;--water:#6890F0;--electric:#F8D030;--grass:#78C850;
    --ice:#98D8D8;--fighting:#C03028;--poison:#A040A0;--ground:#E0C068;--flying:#A890F0;
    --psychic:#F85888;--bug:#A8B820;--rock:#B8A038;--ghost:#705898;--dragon:#7038F8;
    --dark:#705848;--steel:#B8B8D0;--fairy:#EE99AC;--stellar:#7bd6c4;
  }
  *{box-sizing:border-box}
  body{margin:0;background:var(--bg);color:var(--txt);
       font:14px/1.45 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif;
       display:flex;justify-content:center;padding:14px 14px 40px}
  .jogo{width:100%;max-width:560px}
  button{font:inherit;color:inherit}

  .topo{display:flex;align-items:center;gap:9px;margin-bottom:12px}
  .topo .voltar{background:none;border:0;color:var(--fraco);cursor:pointer;padding:4px 6px 4px 0;font-size:17px}
  .topo b{font-size:16px;letter-spacing:.3px}
  .topo .saldo{margin-left:auto;background:var(--painel);border:1px solid var(--linha);
               border-radius:999px;padding:4px 12px;font-weight:700;font-size:12.5px}
  .topo .saldo i{color:var(--acento)}

  /* ── SALÃO ────────────────────────────────────────────────── */
  .cartao{background:var(--painel);border:1px solid var(--linha);border-radius:12px;
          padding:14px;margin-bottom:11px}
  .cartao h3{margin:0 0 4px;font-size:14.5px;display:flex;align-items:center;gap:7px}
  .cartao p{margin:0 0 11px;font-size:12.5px;color:var(--fraco);line-height:1.5}
  .bt{display:block;width:100%;background:#1e2533;border:1px solid var(--linha);
      border-radius:9px;padding:10px;font-weight:700;font-size:13.5px;cursor:pointer;
      transition:background .15s,transform .08s}
  .bt:hover{background:#273045}
  .bt:active{transform:translateY(1px)}
  .bt.ouro{background:var(--acento);color:#15181f;border-color:var(--acento)}
  .bt.ouro:hover{filter:brightness(1.08)}
  .bt:disabled{opacity:.45;cursor:not-allowed}
  .bt.perigo{background:#2a1a1d;border-color:#582a30;color:#ffb3bb}

  .linha2{display:flex;gap:8px}
  .linha2 .bt{flex:1}
  input[type=text],input[type=number]{width:100%;background:#0f131b;border:1px solid var(--linha);
      border-radius:9px;padding:10px;color:var(--txt);font:inherit;margin-bottom:9px}
  input::placeholder{color:#5c6577}
  .fichas{display:flex;gap:6px;flex-wrap:wrap;margin-bottom:10px}
  .ficha{background:#0f131b;border:1px solid var(--linha);border-radius:999px;
         padding:5px 12px;font-size:12px;font-weight:700;cursor:pointer}
  .ficha.on{background:var(--acento);color:#15181f;border-color:var(--acento)}

  .codigo{font-size:30px;font-weight:800;letter-spacing:7px;text-align:center;
          background:#0f131b;border:1px dashed var(--linha);border-radius:10px;padding:14px;margin:10px 0}

  /* ── QUADRA ───────────────────────────────────────────────── */
  .barraTopo{display:flex;align-items:center;gap:8px;margin-bottom:8px;font-size:12px;color:var(--fraco)}
  .barraTopo .pote{margin-left:auto;color:var(--acento);font-weight:700}
  .relogio{background:var(--painel);border:1px solid var(--linha);border-radius:7px;
           padding:2px 9px;font-weight:700;font-variant-numeric:tabular-nums;color:var(--txt)}
  .relogio.curto{color:#ff8b8b;border-color:#582a30}

  .campo{position:relative;aspect-ratio:16/10;border-radius:14px;overflow:hidden;
         background:linear-gradient(#8ec5e8 0%,#b9dcf0 48%,#cfe6c0 48%,#a7cf86 100%);
         box-shadow:0 10px 30px rgba(0,0,0,.45)}
  .turno{position:absolute;top:10px;left:10px;z-index:5;background:rgba(20,24,33,.82);
         padding:3px 11px;border-radius:7px;font-weight:700;font-size:12px}
  .plat{position:absolute;border-radius:50%;background:rgba(90,140,70,.35);
        box-shadow:inset 0 2px 0 rgba(255,255,255,.25)}
  .plat.deles{width:36%;height:8%;right:10%;top:45%}
  .plat.meu{width:44%;height:10%;left:14%;bottom:4%}

  /* ── O TAMANHO DO BICHO ────────────────────────────────────────────
   *
   * Largura em porcentagem ESTRAGA: o sprite do Chimecho é quase todo
   * rabo, e forçado a 34% da quadra ele vira um gigante do lado de um
   * Jumpluff miúdo. Cada espécie tem seu tamanho no arquivo, e é ele que
   * dá a escala certa — Wailord grande, Joltik pequeno, como na série.
   *
   * Então: tamanho natural, com teto pra nenhum estourar a quadra. O
   * pixelated é porque o desenho é pequeno de origem; esticado com
   * suavização ele borra. */
  /* ── O TAMANHO E A AMPLIAÇÃO ───────────────────────────────────────
   *
   * O desenho sai no tamanho NATURAL (cada espécie tem o seu: Wailord
   * grande, Joltik pequeno) e depois é ampliado por um fator igual pros
   * dois. Largura em porcentagem, que era o que estava aqui antes,
   * achatava essa diferença — o Chimecho, que é quase todo rabo, virava
   * um gigante ao lado de um Jumpluff miúdo.
   *
   * A ampliação mora em --z e aparece em TODO keyframe: transform é uma
   * propriedade só, então uma animação que escrevesse apenas translate()
   * apagaria a escala e o bicho encolheria no meio do golpe.
   *
   * transform-origin no pé: ampliar faz ele crescer pra cima, de pé na
   * quadra, em vez de afundar no chão. */
  .bicho{position:absolute;z-index:3;filter:drop-shadow(0 6px 7px rgba(0,0,0,.35));
         transition:opacity .3s;width:auto;height:auto;image-rendering:pixelated;
         transform:scale(var(--z));transform-origin:bottom center}
  .bicho.deles{--z:1.5;right:19%;top:21%;max-width:24%;max-height:26%;
               animation:flutua 3.1s ease-in-out infinite}
  .bicho.meu{--z:1.9;left:19%;bottom:8%;max-width:26%;max-height:30%;
             animation:flutua 3.6s ease-in-out infinite .4s}
  @keyframes flutua{0%,100%{transform:scale(var(--z)) translateY(0)}
                    50%{transform:scale(var(--z)) translateY(-4px)}}
  .bicho.meu.ataca{animation:invest .5s ease-in-out}
  @keyframes invest{0%{transform:scale(var(--z)) translate(0,0)}
                    35%{transform:scale(var(--z)) translate(24px,-15px)}
                    100%{transform:scale(var(--z)) translate(0,0)}}
  .bicho.deles.ataca{animation:investD .5s ease-in-out}
  @keyframes investD{0%{transform:scale(var(--z)) translate(0,0)}
                     35%{transform:scale(var(--z)) translate(-24px,15px)}
                     100%{transform:scale(var(--z)) translate(0,0)}}
  .bicho.levou{animation:treme .42s linear 2}
  @keyframes treme{0%,100%{transform:scale(var(--z)) translateX(0)}
                   25%{transform:scale(var(--z)) translateX(-6px)}
                   50%{transform:scale(var(--z)) translateX(5px)}
                   75%{transform:scale(var(--z)) translateX(-3px)}}
  .bicho.piscou{filter:drop-shadow(0 6px 7px rgba(0,0,0,.35)) brightness(3) saturate(0)}
  .bicho.caiu{opacity:0;transform:scale(var(--z)) translateY(16px)}

  .dano{position:absolute;z-index:6;font-weight:800;font-size:22px;color:#fff;
        text-shadow:0 2px 0 #c0392b,0 0 10px rgba(0,0,0,.6);opacity:0;pointer-events:none}
  .dano.deles{right:17%;top:17%} .dano.meu{left:28%;bottom:30%}
  .dano.cura{text-shadow:0 2px 0 #1f8f4a,0 0 10px rgba(0,0,0,.6)}
  .dano.vai{animation:sobe 1s ease-out}
  @keyframes sobe{0%{opacity:0;transform:translateY(6px) scale(.8)}
                  25%{opacity:1;transform:translateY(-6px) scale(1.12)}
                  100%{opacity:0;transform:translateY(-34px) scale(1)}}
  .campo.sacode{animation:sacode .3s linear}
  @keyframes sacode{0%,100%{transform:translate(0,0)}30%{transform:translate(-3px,2px)}60%{transform:translate(3px,-2px)}}

  .barra{position:absolute;z-index:4;background:rgba(246,248,252,.93);border-radius:8px;
         padding:5px 9px 6px;min-width:168px;box-shadow:0 3px 9px rgba(0,0,0,.3);color:#1b2030}
  .barra.deles{top:10%;right:7%} .barra.meu{bottom:30%;left:4%}
  .barra .nome{display:flex;align-items:center;gap:5px;font-weight:700;font-size:12.5px}
  .barra .nv{font-weight:600;font-size:10.5px;color:#5b6477}
  .barra .st{font-size:9px;font-weight:800;letter-spacing:.5px;text-transform:uppercase;
             padding:1px 5px;border-radius:4px;color:#fff}
  .st-par{background:#c9a227}.st-brn{background:#e2622b}.st-psn,.st-tox{background:#9b4fa8}
  .st-slp{background:#6b7280}.st-frz{background:#4aa3d4}
  .barra .trilho{height:6px;border-radius:4px;background:#2d3444;margin-top:4px;overflow:hidden}
  .barra .hp{height:100%;border-radius:4px;background:var(--hp-verde);
             transition:width .9s cubic-bezier(.3,.8,.4,1),background .4s}
  .barra .num{font-size:10.5px;color:#5b6477;text-align:right;margin-top:2px;font-variant-numeric:tabular-nums}
  .barra .sub{font-size:9.5px;color:#7a8396;margin-top:1px}

  .bolas{position:absolute;z-index:4;display:flex;flex-wrap:wrap;gap:3px;width:52px}
  .bolas.deles{top:5%;right:2%} .bolas.meu{bottom:4%;left:2%}
  .bola{width:11px;height:11px;border-radius:50%;background:#e8413a;
        border:1.5px solid rgba(0,0,0,.45);box-shadow:inset 0 -5px 0 #f3f3f3}
  .bola.caiu{background:#59606f;box-shadow:inset 0 -5px 0 #8a919f;opacity:.55}
  .pedras{position:absolute;z-index:4;font-size:11px;color:#4b3a2a;background:rgba(255,255,255,.7);
          border-radius:5px;padding:1px 6px}
  .pedras.deles{top:40%;right:8%} .pedras.meu{bottom:20%;left:6%}

  .log{background:var(--painel);border:1px solid var(--linha);border-top:0;
       border-radius:0 0 12px 12px;padding:9px 12px;min-height:50px;font-size:13px;color:#cfd6e4}
  .log b{color:#fff} .log .eficaz{color:#ffd166;font-weight:700}
  .log .fraco{color:#9aa5b8}

  .rotulo{font-size:11px;letter-spacing:1.3px;text-transform:uppercase;color:var(--fraco);margin:13px 2px 6px}
  .golpes{display:grid;grid-template-columns:1fr 1fr;gap:7px}
  .golpe{display:flex;flex-direction:column;align-items:flex-start;gap:2px;cursor:pointer;
         background:var(--painel);border:1px solid var(--linha);border-left:4px solid var(--cor,#555);
         border-radius:9px;padding:8px 10px;text-align:left;transition:transform .08s,background .15s}
  .golpe:hover:not(:disabled){background:#1d2330;transform:translateY(-1px)}
  .golpe:disabled{opacity:.4;cursor:not-allowed}
  .golpe .n{font-weight:700;font-size:13.5px}
  .golpe .b{display:flex;align-items:center;gap:6px;width:100%}
  .golpe .tipo{font-size:9.5px;font-weight:800;letter-spacing:.6px;text-transform:uppercase;
               background:var(--cor,#555);color:#15181f;padding:1px 6px;border-radius:4px}
  .golpe .pp{margin-left:auto;font-size:11px;color:var(--fraco);font-variant-numeric:tabular-nums}

  .trocas{display:grid;grid-template-columns:repeat(3,1fr);gap:7px}
  .troca{display:flex;align-items:center;gap:5px;background:var(--painel);
         border:1px solid var(--linha);border-radius:9px;padding:5px 7px;cursor:pointer;
         font-size:11.5px;font-weight:600;text-align:left}
  .troca:hover:not(:disabled){background:#1d2330}
  .troca img{width:26px;height:26px;object-fit:contain;flex:none}
  .troca:disabled{opacity:.35;cursor:not-allowed}
  .troca.atual{border-color:#3b82f6;background:#17213a}
  .troca .hpmini{display:block;height:3px;border-radius:2px;background:var(--hp-verde);margin-top:2px}

  .aviso{background:#17213a;border:1px solid #2b3a5c;border-radius:9px;padding:9px 11px;
         font-size:12.5px;color:#b9c6e0;margin-top:11px;text-align:center}
  .erro{background:#2a1a1d;border:1px solid #582a30;color:#ffb3bb;border-radius:9px;
        padding:9px 11px;font-size:12.5px;margin-bottom:10px}
  .fim{text-align:center;padding:16px}
  .fim h2{margin:0 0 4px;font-size:22px}
  .fim .premio{color:var(--acento);font-weight:800;font-size:15px;margin-bottom:12px}

  @media (max-width:420px){
    .golpes{grid-template-columns:1fr}
    .barra{min-width:138px;padding:4px 7px 5px}
    .barra .nome{font-size:11.5px}
  }
</style>
</head>
<body>
<div class="jogo">

  <div class="topo">
    <button class="voltar" onclick="location.href='/games/'" title="Voltar"><i class="bi bi-arrow-left"></i></button>
    <b>FBA Showdown</b>
    <span class="saldo"><i class="bi bi-coin"></i> <span id="saldo"><?= number_format($saldo, 0, ',', '.') ?></span></span>
  </div>

  <div id="tela"></div>
</div>

<?php /* Os pop-ups são do jogo, não do navegador: confirmarSite/avisarSite. */ ?>
<script src="/js/popups.js?v=<?= @filemtime(dirname(__DIR__, 2) . '/js/popups.js') ?: 1 ?>"></script>
<script>
const EU = <?= json_encode($meuNome, JSON_UNESCAPED_UNICODE) ?>;
const TELA = document.getElementById('tela');
const SALDO = document.getElementById('saldo');

let estado = null, ultimoSeq = 0, animando = false, aposta = 0, erro = '';

/* A narração do último turno, guardada. A tela se redesenha a cada 1,5s e
   sem isto o texto piscava e sumia — justo na hora em que você quer ler
   por que levou 180 de dano, e justo na tela de fim de partida. */
let ultimoLog = '';

const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
const num = n => Number(n || 0).toLocaleString('pt-BR');
const espera = ms => new Promise(r => setTimeout(r, ms));

/* ── O SPRITE ──────────────────────────────────────────────────────
 *
 * Primeiro o nosso, baixado pelo psd_sprites_cli. Se faltar — espécie
 * nova, download pela metade —, cai no espelho público em vez de deixar
 * um quadrado quebrado na quadra.
 *
 *   frente  gif animado, de frente   (o adversário)
 *   costas  gif animado, de costas   (o seu)
 *   icone   png parado               (a fileira de troca)
 */
const PSD_ESPELHO = 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon';
const PSD_CAMINHO = {
  frente: ['frente', 'gif', '/other/showdown'],
  costas: ['costas', 'gif', '/other/showdown/back'],
  icone:  ['icone',  'png', ''],
};

function sprite(n, qual){
  const [pasta, ext] = PSD_CAMINHO[qual];
  return `/games/img/psd/${pasta}/${n}.${ext}`;
}

/* A escada: gif animado nosso → desenho oficial nosso (os 14 da gen 9 que
   não têm animado) → espelho público. Cada degrau tenta uma vez só. */
function spriteFallback(img, n, qual){
  const [pasta, ext, caminho] = PSD_CAMINHO[qual];
  if (ext === 'gif' && !img.dataset.tentouPng) {
    img.dataset.tentouPng = '1';
    img.src = `/games/img/psd/${pasta}/${n}.png`;
    return;
  }
  img.onerror = null;
  img.src = `${PSD_ESPELHO}${caminho}/${n}.${ext}`;
}

async function chamar(acao, dados){
  const corpo = new URLSearchParams(dados || {});
  const r = await fetch('?acao=' + acao, {method:'POST', body:corpo});
  return r.json();
}

// ─────────────────────────── SALÃO ───────────────────────────

function desenharSalao(d){
  const pode = s => s <= (d.saldo ?? 0);
  TELA.innerHTML = `
    ${erro ? `<div class="erro">${esc(erro)}</div>` : ''}

    <div class="cartao">
      <h3><i class="bi bi-controller"></i> Treinar</h3>
      <p>Contra a máquina, pra pegar o jeito. Não cobra e não paga moeda.</p>
      <button class="bt" onclick="abrir('treino',0)">Jogar agora</button>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-coin"></i> Valendo moedas</h3>
      <p>Cada um paga a entrada e <b>quem ganhar leva as duas</b>. Desistir antes
         de alguém entrar devolve; depois que começou, o dinheiro está em jogo.</p>
      <div class="fichas" id="fichas">
        ${[100,250,500,1000,2500,5000].map(v =>
          `<button class="ficha ${v===aposta?'on':''}" ${pode(v)?'':'disabled style="opacity:.35"'}
                   onclick="setAposta(${v})">${num(v)}</button>`).join('')}
      </div>
      <div class="linha2">
        <button class="bt ouro" ${aposta?'':'disabled'} onclick="abrir('fila',aposta)">
          <i class="bi bi-people-fill"></i> Procurar</button>
        <button class="bt" ${aposta?'':'disabled'} onclick="abrir('codigo',aposta)">
          <i class="bi bi-link-45deg"></i> Criar código</button>
      </div>
    </div>

    <div class="cartao">
      <h3><i class="bi bi-box-arrow-in-right"></i> Entrar com código</h3>
      <input type="text" id="cod" maxlength="6" placeholder="AS 6 LETRAS"
             style="text-transform:uppercase;letter-spacing:5px;text-align:center;font-weight:800">
      <button class="bt" onclick="entrar()">Entrar</button>
    </div>`;
}

function setAposta(v){ aposta = v; erro=''; desenharSalao(estado || {saldo:0}); }

async function abrir(modo, v){
  erro = '';
  const r = await chamar('abrir', {modo, aposta:v});
  if (!r.ok) { erro = r.erro || 'Não deu.'; desenharSalao(estado || {saldo:0}); return; }
  ultimoSeq = 0; puxar();
}

async function entrar(){
  const c = (document.getElementById('cod').value || '').trim();
  erro = '';
  const r = await chamar('entrar', {codigo:c});
  if (!r.ok) { erro = r.erro || 'Não deu.'; desenharSalao(estado || {saldo:0}); return; }
  ultimoSeq = 0; puxar();
}

function desenharEsperando(d){
  TELA.innerHTML = `
    <div class="cartao" style="text-align:center">
      <h3 style="justify-content:center"><i class="bi bi-hourglass-split"></i> Esperando adversário</h3>
      ${d.codigo ? `<p>Manda o código pra quem você quer desafiar.</p>
                    <div class="codigo">${esc(d.codigo)}</div>
                    <button class="bt" onclick="copiar('${esc(d.codigo)}')">Copiar</button>`
                 : `<p>Procurando alguém com entrada de <b>${num(d.aposta)}</b> moedas…</p>`}
      <div style="margin-top:10px">
        <button class="bt perigo" onclick="cancelar()">Cancelar e receber de volta</button>
      </div>
    </div>`;
}

function copiar(c){ navigator.clipboard?.writeText(c); }
async function cancelar(){ await chamar('cancelar'); ultimoSeq = 0; puxar(); }

// ─────────────────────────── QUADRA ───────────────────────────

function corDoTipo(t){ return `var(--${t})`; }

function barraHtml(b, lado, mostrarNum){
  const pct = Math.max(0, Math.round(100 * b.hp / b.hpmax));
  const cor = pct > 50 ? 'var(--hp-verde)' : pct > 20 ? 'var(--hp-amarelo)' : 'var(--hp-vermelho)';
  return `<div class="barra ${lado}">
      <div class="nome">${esc(b.nome)} <span class="nv">N${b.nivel}</span>
        ${b.status ? `<span class="st st-${b.status}">${b.status}</span>` : ''}</div>
      <div class="trilho"><div class="hp" id="hp-${lado}" style="width:${pct}%;background:${cor}"></div></div>
      ${mostrarNum ? `<div class="num" id="num-${lado}">${b.hp}/${b.hpmax}</div>` : ''}
    </div>`;
}

function bolasHtml(n, total, lado){
  let h = `<div class="bolas ${lado}">`;
  for (let i=0;i<total;i++) h += `<i class="bola ${i<n?'':'caiu'}"></i>`;
  return h + '</div>';
}

function desenharPartida(d){
  const meu = d.eu.ativo, dele = d.ele.ativo;
  const vivosMeus = d.eu.equipe.filter(x => x.hp > 0).length;

  const campo = `
    <div class="barraTopo">
      <span>Turno ${d.turno}</span>
      ${d.treino ? '<span>· treino</span>' : ''}
      ${d.acabou ? '' : `<span class="relogio ${d.segundos<=10?'curto':''}" id="relogio">${d.segundos}s</span>`}
      ${d.premio ? `<span class="pote"><i class="bi bi-coin"></i> ${num(d.premio)}</span>` : ''}
    </div>

    <div class="campo" id="campo">
      <div class="turno">Turno ${d.turno}</div>
      <div class="plat deles"></div><div class="plat meu"></div>
      ${bolasHtml(d.ele.restam, 6, 'deles')}
      ${bolasHtml(vivosMeus, 6, 'meu')}
      ${d.ele.rochas ? '<div class="pedras deles"><i class="bi bi-gem"></i> pedras</div>' : ''}
      ${d.eu.rochas ? '<div class="pedras meu"><i class="bi bi-gem"></i> pedras</div>' : ''}
      ${barraHtml(dele, 'deles', false)}
      <img class="bicho deles" id="sp-deles" src="${sprite(dele.num,'frente')}"
           onerror="spriteFallback(this,${dele.num},'frente')" alt="${esc(dele.nome)}">
      <div class="dano deles" id="dano-deles"></div>
      ${barraHtml(meu, 'meu', true)}
      <img class="bicho meu" id="sp-meu" src="${sprite(meu.num,'costas')}"
           onerror="spriteFallback(this,${meu.num},'costas')" alt="${esc(meu.nome)}">
      <div class="dano meu" id="dano-meu"></div>
    </div>
    <div class="log" id="log">${ultimoLog}</div>`;

  if (d.acabou) { TELA.innerHTML = campo + fimHtml(d); return; }

  const precisaTrocar = d.eu.precisa_trocar;
  const travado = !d.falta_eu;

  const golpes = d.eu.golpes.map((g,i) => {
    const off = travado || precisaTrocar || g.pp <= 0;
    return `<button class="golpe" style="--cor:${corDoTipo(g.tipo||'normal')}"
              ${off?'disabled':''} onclick="jogar('golpe',${i})">
        <span class="n">${esc(g.nome)}</span>
        <span class="b"><span class="tipo">${esc(g.tipo)}</span>
        <span class="pp">${g.pp}/${g.ppmax}</span></span></button>`;
  }).join('');

  const trocas = d.eu.equipe.map(b => {
    const off = travado || b.hp<=0 || b.ativo;
    const pct = Math.max(0,Math.round(100*b.hp/b.hpmax));
    return `<button class="troca ${b.ativo?'atual':''}" ${off?'disabled':''} onclick="jogar('trocar',${b.i})">
        <img src="${sprite(b.num,'icone')}"
             onerror="spriteFallback(this,${b.num},'icone')">
        <span>${esc(b.nome)}<i class="hpmini" style="width:${pct}%;background:${pct>50?'var(--hp-verde)':pct>20?'var(--hp-amarelo)':'var(--hp-vermelho)'}"></i></span>
      </button>`;
  }).join('');

  let menu = '';
  if (precisaTrocar) {
    menu = `<div class="aviso">Seu Pokémon caiu. Escolha quem entra.</div>
            <div class="rotulo">Trocar</div><div class="trocas">${trocas}</div>`;
  } else if (travado) {
    menu = `<div class="aviso"><i class="bi bi-hourglass-split"></i> Esperando ${esc(d.ele.nome)} escolher…</div>`;
  } else {
    menu = `<div class="rotulo">Lutar</div><div class="golpes">${golpes}</div>
            <div class="rotulo">Trocar</div><div class="trocas">${trocas}</div>
            <div style="margin-top:14px"><button class="bt perigo" onclick="desistir()">Desistir</button></div>`;
  }

  TELA.innerHTML = campo + menu;
}

function fimHtml(d){
  const ganhei = d.venci;
  const empate = d.fim === 'empate';
  return `<div class="fim">
      <h2>${empate ? 'Empate' : ganhei ? 'Você venceu!' : 'Você perdeu'}</h2>
      <div class="premio">${
        d.treino ? 'Treino não paga moeda.'
        : empate ? 'Entrada devolvida pros dois.'
        : ganhei ? `+${num(d.premio)} moedas` : `-${num(d.aposta)} moedas`}</div>
      <button class="bt ouro" onclick="voltarAoSalao()">Jogar de novo</button>
    </div>`;
}

/* ── SAIR DA TELA DE FIM ───────────────────────────────────────────
 *
 * A partida terminada continua vindo do servidor por cinco minutos, de
 * propósito: é o que deixa você ver quem ganhou mesmo se recarregar a
 * página. Só que isso fazia o "Jogar de novo" voltar pra ela mesma, em
 * laço — a tela pedia o estado, recebia a mesma partida acabada e
 * desenhava o mesmo resultado.
 *
 * Então a tela anota qual fim já foi visto e, daí em diante, mostra o
 * salão no lugar dele. Resolver isso no servidor seria apagar a partida,
 * e aí quem recarregasse perderia o resultado. */
let fimVisto = 0;

function voltarAoSalao(){
  if (estado && estado.id) fimVisto = estado.id;
  estado = null; ultimoSeq = 0; erro = ''; ultimoLog = '';
  TELA.innerHTML = '';
  puxar();
}

async function jogar(tipo, i){
  document.querySelectorAll('.golpe,.troca').forEach(b => b.disabled = true);
  const r = await chamar('escolher', {tipo, i});
  if (!r.ok && r.erro) { erro = r.erro; }
  puxar();
}

async function desistir(){
  /* Pop-up do site, nunca o do navegador (@see js/popups.js). */
  const ok = await confirmarSite(
    'Desistir entrega a partida e o prêmio inteiro pro adversário.',
    {titulo:'Desistir?', confirmar:'Desistir', perigo:true});
  if (!ok) return;
  await chamar('desistir'); puxar();
}

// ─────────────────────────── a animação ───────────────────────────

async function animar(d, eventos){
  animando = true;
  const log = document.getElementById('log');
  const campo = document.getElementById('campo');
  ultimoLog = '';
  if (log) log.innerHTML = '';

  for (const ev of eventos) {
    const l = ev.lado ? (ev.lado === d.meuLado ? 'meu' : 'deles') : null;
    const sp = l ? document.getElementById('sp-'+l) : null;

    if (ev.t === 'texto') {
      const cls = /super eficaz/.test(ev.m) ? 'eficaz' : /Não é muito|Não afeta|errou|falhou/.test(ev.m) ? 'fraco' : '';
      ultimoLog += `<div class="${cls}">${esc(ev.m)}</div>`;
      if (log) log.innerHTML = ultimoLog;
      await espera(620);

    } else if (ev.t === 'golpe') {
      const meuSp = document.getElementById('sp-' + (ev.lado === d.meuLado ? 'meu' : 'deles'));
      if (meuSp) { meuSp.classList.add('ataca'); setTimeout(()=>meuSp.classList.remove('ataca'), 500); }
      await espera(330);

    } else if (ev.t === 'dano') {
      if (sp) { sp.classList.add('levou','piscou'); }
      if (campo) { campo.classList.add('sacode'); setTimeout(()=>campo.classList.remove('sacode'), 300); }
      mostrarNumero(l, '-' + ev.valor, false);
      ajustarHp(l, ev.hp, ev.hpmax);
      await espera(520);
      if (sp) { sp.classList.remove('piscou'); setTimeout(()=>sp.classList.remove('levou'), 300); }

    } else if (ev.t === 'cura') {
      mostrarNumero(l, '+' + ev.valor, true);
      ajustarHp(l, ev.hp, ev.hpmax);
      await espera(480);

    } else if (ev.t === 'desmaiou') {
      if (sp) sp.classList.add('caiu');
      await espera(620);

    } else if (ev.t === 'entrou') {
      if (sp) {
        sp.classList.remove('caiu');
        sp.src = sprite(ev.bicho.num, l === 'meu' ? 'costas' : 'frente');
        sp.onerror = () => spriteFallback(sp, ev.bicho.num, l === 'meu' ? 'costas' : 'frente');
      }
      await espera(420);

    } else {
      await espera(160);
    }
  }
  animando = false;
}

function mostrarNumero(l, texto, cura){
  const el = document.getElementById('dano-'+l);
  if (!el) return;
  el.textContent = texto;
  el.classList.toggle('cura', !!cura);
  el.classList.remove('vai'); void el.offsetWidth; el.classList.add('vai');
}

function ajustarHp(l, hp, hpmax){
  const el = document.getElementById('hp-'+l);
  if (!el) return;
  const pct = Math.max(0, Math.round(100*hp/hpmax));
  el.style.width = pct + '%';
  el.style.background = pct > 50 ? 'var(--hp-verde)' : pct > 20 ? 'var(--hp-amarelo)' : 'var(--hp-vermelho)';
  const n = document.getElementById('num-'+l);
  if (n) n.textContent = hp + '/' + hpmax;
}

// ─────────────────────────── o laço ───────────────────────────

async function puxar(){
  if (animando) return;
  let d;
  try { d = await (await fetch('?acao=ver')).json(); } catch(e){ return; }

  if (d.saldo !== undefined) SALDO.textContent = num(d.saldo);

  if (d.em === 'nada')       { estado = d; desenharSalao(d); return; }
  if (d.em === 'esperando')  { estado = d; desenharEsperando(d); return; }
  if (d.em !== 'jogando')    { return; }

  // Fim que o GM já leu e dispensou: daqui pra frente, salão.
  if (d.acabou && d.id === fimVisto) { estado = d; desenharSalao(d); return; }

  /* A vista já vem filtrada pelo meu lado, mas os EVENTOS vêm marcados com
     'a'/'b' — então a tela precisa saber qual das duas letras sou eu pra
     saber de que lado da quadra a animação acontece. */
  d.meuLado = d.lado_eu;

  const novo = d.seq > ultimoSeq;
  const primeira = ultimoSeq === 0;
  ultimoSeq = d.seq;
  estado = d;

  desenharPartida(d);
  if (novo && !primeira && d.eventos && d.eventos.length) {
    await animar(d, d.eventos);
    desenharPartida(d);
  }
}

setInterval(() => { if (!animando) puxar(); }, 1500);
setInterval(() => {
  const r = document.getElementById('relogio');
  if (!r || !estado || estado.em !== 'jogando') return;
  const s = Math.max(0, (estado.segundos || 0) - 1);
  estado.segundos = s;
  r.textContent = s + 's';
  r.classList.toggle('curto', s <= 10);
}, 1000);

puxar();
</script>
</body>
</html>
