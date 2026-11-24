<?php
/**
 * MINHA TEMPORADA — um jogo do /games.
 *
 * Escolha um time da FBA e leve ele pela temporada: 82 jogos, um a um. Os
 * outros times da liga você acompanha pela tabela — é o pedido, e é o que
 * deixa a partida rápida: o motor resolve a noite inteira e para no seu jogo.
 *
 * ── O JOGO É ASSISTIDO, NÃO LIDO ─────────────────────────────────────
 * A primeira versão mostrava o placar final numa linha de tabela, e estava
 * certa e morta: "está muito feio, sem cara de jogo — o jogo tem que ser
 * simulado, o tempo passando ali e os pontos surgindo" (24/11/2026).
 *
 * Agora a partida é TRANSMITIDA. O motor simula de uma vez (dois décimos de
 * segundo) e guarda o lance a lance; a tela reproduz isso com o relógio
 * andando, o placar subindo e a narração entrando. Não é um "ao vivo" de
 * mentira: o resultado já existe e não muda se a aba fechar no meio — o que
 * a pessoa controla é a velocidade com que ela vê acontecer.
 *
 * ── A CARA É DE TRANSMISSÃO, de propósito ────────────────────────────
 * Segundo pedido na mesma direção (24/11): "quero um design de jogo bem
 * bonito, uma experiência legal, desde a página de escolher o time". A
 * linguagem visual daqui é de pacote gráfico de TV esportiva: tudo que é
 * título vive numa placa inclinada, os números grandes são Oswald itálico,
 * o fundo tem o círculo central de uma quadra sob um spotlight, e a cor do
 * time escolhido invade a tela. Nada disso é decoração aleatória — é o
 * vocabulário que a liga já conhece do 2K e das transmissões.
 *
 * ── O MOTOR É O DO SIMULADOR, A CASA É O /games ───────────────────────
 * Simulação por posses, classificação, play-in, playoffs e líderes vêm de
 * /simulador/src. Mas o jogo é daqui: tela própria, card no hub, save próprio
 * em games/storage/temporada. @see games/core/temporada.php
 */

require_once __DIR__ . '/../core/conexao.php';
require_once __DIR__ . '/../core/temporada.php';

$user = getUserSession();
if (!$user || (int) $user['id'] <= 0) {
    header('Location: /login.php?next=' . rawurlencode('/games/games/temporada.php'));
    exit;
}
$user_id = (int) $user['id'];
$erro = $_GET['e'] ?? null;

/* ── AS AÇÕES ────────────────────────────────────────────────────────
   Tudo POST e tudo termina em redirect: sem isso, o F5 depois de simular um
   jogo simularia outro, e a pessoa perderia partidas sem querer. */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string) ($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        $r = temporadaCriar($user_id, (string) ($_POST['liga'] ?? ''),
                            (int) ($_POST['fba_team_id'] ?? 0), (string) ($_POST['gm_nome'] ?? ''));
        header('Location: /games/games/temporada.php' . ($r['ok'] ? ''
            : '?liga=' . urlencode((string) ($_POST['liga'] ?? '')) . '&e=' . urlencode($r['erro'])));
        exit;
    }

    if ($acao === 'apagar') { temporadaApagar($user_id); header('Location: /games/games/temporada.php'); exit; }

    if (temporadaAbrir($user_id)) {
        /* ASSISTIR E SIMULAR SÃO O MESMO JOGO, dois jeitos de ver.
           O motor roda igual nos dois; o que muda é se a tela reproduz lance a
           lance ou vai direto pro resultado. Quem simula não fica sem nada:
           cai no box score, que é o jogo contado em números. */
        if ($acao === 'proximo' || $acao === 'simular') {
            $r = temporadaProximoJogo();
            $destino = $r['jogo']
                ? '?jogo=' . (int) $r['jogo'] . ($acao === 'proximo' ? '&vivo=1' : '&box=1')
                : '';
            header('Location: /games/games/temporada.php' . $destino);
            exit;
        }
        if ($acao === 'varios') {
            temporadaSimularVarios((int) ($_POST['n'] ?? 5));
            header('Location: /games/games/temporada.php'); exit;
        }
        /* SIMULAR A TEMPORADA REGULAR, e só ela. O motor para sozinho quando
           a fase vira play-in — e a tela seguinte mostra a TABELA FINAL, não
           o chaveamento: ver onde cada um terminou é o fecho da temporada, e
           os playoffs só aparecem quando a pessoa mandar. */
        if ($acao === 'regular') {
            $guarda = 0;
            while (League::phase() === 'regular' && $guarda++ < 3) League::simulateToEnd();
            header('Location: /games/games/temporada.php'); exit;
        }
        // O clique que vira a página: da tabela final pro chaveamento.
        if ($acao === 'abrir_playoffs') {
            Database::setMeta('playoffs_abertos', '1');
            header('Location: /games/games/temporada.php'); exit;
        }
    }
    header('Location: /games/games/temporada.php'); exit;
}

$tem = temporadaAbrir($user_id);

/* O PAGAMENTO É POR RECONCILIAÇÃO, não por gatilho: olha o chaveamento e paga
   a etapa que ainda não saiu. Roda a cada carregamento porque o time pode ter
   passado de fase em qualquer um dos botões de simular — pendurar o pagamento
   em cada um seria pendurar um esquecimento em cada um. */
$premiosNovos = $tem ? TemporadaPremios::reconciliar($user_id) : [];
$moedas = (int) ($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

/* A ELITE JÁ VEM ABERTA. É a divisão de cima e a que a liga pensa primeiro;
   abrir numa tela que não mostra time nenhum é um clique cobrado de todo
   mundo pra chegar onde quase todo mundo ia. */
$liga = strtoupper(trim((string) ($_GET['liga'] ?? '')));
if (!in_array($liga, TemporadaLiga::LIGAS, true)) $liga = 'ELITE';

$est    = $tem ? temporadaEstado() : null;
$jogoId = (int) ($_GET['jogo'] ?? 0);
$aoVivo = $tem && $jogoId && !empty($_GET['vivo']);
$verBox = $tem && $jogoId && !empty($_GET['box']);
$trans  = $tem && $jogoId ? temporadaJogoAoVivo($jogoId) : null;
if (!$trans) { $aoVivo = false; $verBox = false; $jogoId = 0; }

/** Cor válida ou a reserva. */
$cor = fn($c, $padrao = '#334155') => preg_match('/^#[0-9a-f]{6}$/i', trim((string) $c)) ? trim((string) $c) : $padrao;
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Minha Temporada · FBA</title>
<meta name="theme-color" content="#04060c">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Barlow+Condensed:ital,wght@0,500;0,600;0,700;1,600;1,700&family=Oswald:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
/* ═════════════════════════════════════════════════════════════════════
   O PACOTE GRÁFICO. Três regras valem pra página inteira:
   1. Título vive numa PLACA INCLINADA (skew -10°) — é a assinatura.
   2. Número grande é Oswald; texto de apoio é Inter; grito é Barlow
      Condensed itálico em caixa alta.
   3. A cor do time manda. O vermelho FBA é da casa; o resto da cor vem
      do escudo de quem está em quadra.
   ═════════════════════════════════════════════════════════════════════ */
:root{
  --bg:#04060c; --card:#0d1322; --card2:#141c30; --linha:#1f2a44; --linha2:#2b3a5e;
  --txt:#edf2fc; --txt2:#93a1bd; --txt3:#5b688a;
  --fba:#fc0025; --fba2:#a3001a;
  --ok:#2bd97c; --perda:#f8615a; --ouro:#f5c842;
}
*{box-sizing:border-box}
html,body{margin:0;background:var(--bg);color:var(--txt)}
body{font-family:Inter,system-ui,-apple-system,sans-serif;-webkit-font-smoothing:antialiased;
     min-height:100vh;overflow-x:hidden}

/* ── A QUADRA NO FUNDO ─────────────────────────────────────────────────
   Um spotlight descendo sobre o círculo central. Os anéis são gradientes
   radiais fixos (nenhuma imagem), e as listras diagonais quase invisíveis
   tiram o aspecto de cor chapada que fazia tudo parecer painel de admin. */
body::before{content:'';position:fixed;inset:0;z-index:-2;background:
  radial-gradient(1000px 540px at 50% -220px, rgba(252,0,37,.14), transparent 62%),
  radial-gradient(1300px 700px at 85% 115%, rgba(37,99,235,.08), transparent 55%),
  radial-gradient(circle 300px at 50% -40px, transparent 297px, rgba(255,255,255,.05) 298px, rgba(255,255,255,.05) 300px, transparent 301px),
  radial-gradient(circle 470px at 50% -40px, transparent 467px, rgba(255,255,255,.035) 468px, rgba(255,255,255,.035) 470px, transparent 471px),
  radial-gradient(circle 650px at 50% -40px, transparent 647px, rgba(255,255,255,.022) 648px, rgba(255,255,255,.022) 650px, transparent 651px),
  var(--bg)}
body::after{content:'';position:fixed;inset:0;z-index:-1;pointer-events:none;background:
  repeating-linear-gradient(115deg, transparent 0 52px, rgba(255,255,255,.012) 52px 54px)}

a{color:inherit;text-decoration:none}
button{font-family:inherit}
img{-webkit-user-drag:none}

/* ── topbar ── */
.topbar{display:flex;align-items:center;gap:13px;padding:11px 16px;position:sticky;top:0;z-index:40;
        background:rgba(4,6,12,.86);backdrop-filter:blur(12px);border-bottom:1px solid var(--linha)}
.topbar .voltar{width:37px;height:37px;display:grid;place-items:center;border-radius:11px;flex:none;
        background:var(--card2);color:var(--txt2);border:1px solid var(--linha);transition:.15s}
.topbar .voltar:hover{color:#fff;border-color:var(--linha2)}
.marca{display:flex;align-items:center;gap:10px;flex:1;min-width:0}
.marca .slab{background:linear-gradient(135deg,var(--fba),var(--fba2));transform:skewX(-10deg);
       padding:5px 13px;border-radius:3px;box-shadow:0 4px 16px -4px rgba(252,0,37,.55);flex:none}
.marca .slab b{display:inline-block;transform:skewX(10deg);font-family:'Barlow Condensed',sans-serif;
       font-style:italic;font-weight:700;font-size:17px;letter-spacing:1.5px;color:#fff}
.marca .resto{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
       font-size:21px;letter-spacing:1.6px;text-transform:uppercase;white-space:nowrap;
       overflow:hidden;text-overflow:ellipsis}
.saldo{display:flex;align-items:center;gap:7px;flex:none;transform:skewX(-10deg);
       background:linear-gradient(135deg,#2a230a,#1b1707);border:1px solid #f5c84245;
       padding:7px 14px;border-radius:4px;color:var(--ouro);font-weight:800;font-size:13.5px}
.saldo>*{transform:skewX(10deg)}

.wrap{max-width:1080px;margin:0 auto;padding:20px 14px 110px;position:relative}

/* ── títulos de seção: a placa inclinada ── */
.sec{display:flex;align-items:center;gap:13px;margin:30px 2px 14px}
.sec:first-child{margin-top:4px}
.sec .tag{background:linear-gradient(135deg,var(--fba),var(--fba2));transform:skewX(-10deg);
     padding:7px 17px;border-radius:3px;box-shadow:0 5px 20px -6px rgba(252,0,37,.6);flex:none}
.sec .tag span{display:inline-block;transform:skewX(10deg);font-family:'Barlow Condensed',sans-serif;
     font-style:italic;font-weight:700;font-size:17.5px;letter-spacing:1.6px;text-transform:uppercase;color:#fff}
.sec .tag.ouro{background:linear-gradient(135deg,#f5c842,#b98c12);box-shadow:0 5px 20px -6px rgba(245,200,66,.45)}
.sec .tag.ouro span{color:#201703}
.sec::after{content:'';flex:1;height:1px;background:linear-gradient(90deg,var(--linha2),transparent)}
.sec .sub{flex:none;font-size:11px;color:var(--txt3);font-weight:700;text-transform:uppercase;letter-spacing:1px;text-align:right}

/* ── painéis ── */
.painel{background:linear-gradient(175deg,rgba(255,255,255,.022),transparent 40%),var(--card);
        border:1px solid var(--linha);border-radius:14px;padding:16px;margin-bottom:14px}
.dim{color:var(--txt2);font-size:13.2px;line-height:1.65}

/* ── botões ── */
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;border:0;cursor:pointer;
     background:var(--card2);color:var(--txt);padding:12px 17px;border-radius:11px;
     font-weight:700;font-size:14px;border:1px solid var(--linha2);transition:.15s}
.btn:hover{background:#1b2541;transform:translateY(-1px)}
.btn-fba{background:linear-gradient(135deg,#ff1a3c,var(--fba2));border-color:transparent;color:#fff;
         box-shadow:0 8px 24px -8px rgba(252,0,37,.65)}
.btn-fraco{background:transparent;border:1px solid var(--linha);color:var(--txt2)}
.btn:disabled{opacity:.4;cursor:default;transform:none}
.linha-btns{display:flex;gap:9px;flex-wrap:wrap}

/* o CTA inclinado — botão de capa */
.cta{display:inline-block;border:0;cursor:pointer;transform:skewX(-10deg);border-radius:4px;
     background:linear-gradient(135deg,#ff1a3c,var(--fba2));padding:15px 30px;
     box-shadow:0 10px 32px -8px rgba(252,0,37,.65), inset 0 1px 0 rgba(255,255,255,.25);
     transition:.16s}
.cta>span{display:flex;align-items:center;gap:9px;transform:skewX(10deg);color:#fff;
     font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:19px;letter-spacing:1.4px;text-transform:uppercase;white-space:nowrap}
.cta:hover{filter:brightness(1.12);transform:skewX(-10deg) translateY(-2px)}
.cta:active{transform:skewX(-10deg) translateY(1px)}
.cta:disabled{background:#232c45;box-shadow:none;cursor:default;filter:none;transform:skewX(-10deg)}
.cta:disabled>span{color:var(--txt3)}
.cta.cinza{background:linear-gradient(135deg,#27334f,#1a2236);box-shadow:0 8px 24px -10px #0009}

/* ── escolha do time ─────────────────────────────────────────────── */
.capa{position:relative;border-radius:18px;overflow:hidden;border:1px solid var(--linha);
      background:linear-gradient(120deg,#160a12 0%,#0d1322 55%);padding:30px 24px 26px;margin-bottom:6px}
.capa::before{content:'';position:absolute;inset:0;background:
      radial-gradient(640px 300px at 12% -40%,rgba(252,0,37,.3),transparent 62%),
      repeating-linear-gradient(115deg,transparent 0 26px,rgba(255,255,255,.015) 26px 28px)}
.capa>*{position:relative}
.capa .eyebrow{font-size:10.5px;letter-spacing:2.4px;text-transform:uppercase;color:#ff5d76;font-weight:800}
.capa h1{margin:6px 0 10px;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
      font-size:clamp(38px,7vw,58px);line-height:.95;text-transform:uppercase;letter-spacing:.5px}
.capa h1 em{font-style:italic;color:transparent;-webkit-text-stroke:1.5px #ff3350}
.capa p{max-width:640px}
.capa .passos{display:flex;gap:18px;flex-wrap:wrap;margin-top:16px}
.capa .passo{display:flex;align-items:center;gap:8px;font-size:12.5px;color:var(--txt2);font-weight:600}
.capa .passo b{width:22px;height:22px;flex:none;display:grid;place-items:center;border-radius:6px;
      background:#fc002522;border:1px solid #fc002550;color:#ff5d76;font-size:11px}

.gmrow{display:flex;gap:10px;align-items:center;flex-wrap:wrap;margin-top:18px}
.inp{background:rgba(4,6,12,.75);border:1px solid var(--linha2);color:var(--txt);padding:13px 15px;
     border-radius:11px;font:inherit;font-weight:600;width:250px;max-width:100%}
.inp:focus{outline:none;border-color:#fc002580;box-shadow:0 0 0 3px #fc002522}

.ligas{display:flex;gap:8px;flex-wrap:wrap}
.ligatab{position:relative;transform:skewX(-10deg);border:1px solid var(--linha2);border-radius:4px;
     padding:10px 22px;background:var(--card);transition:.15s}
.ligatab>span{display:inline-block;transform:skewX(10deg);font-family:'Barlow Condensed',sans-serif;
     font-style:italic;font-weight:700;font-size:17px;letter-spacing:1.4px;color:var(--txt2)}
.ligatab:hover{border-color:#44557f}
.ligatab.on{background:linear-gradient(135deg,var(--fba),var(--fba2));border-color:transparent;
     box-shadow:0 6px 22px -6px rgba(252,0,37,.6)}
.ligatab.on>span{color:#fff}
.liga-desc{font-size:12px;color:var(--txt3);margin:10px 2px 0}

.times{display:grid;grid-template-columns:repeat(auto-fill,minmax(285px,1fr));gap:11px}
@keyframes carta{from{opacity:0;transform:translateY(14px)}to{opacity:1;transform:none}}
.timecard{position:relative;display:flex;gap:12px;align-items:center;cursor:pointer;overflow:hidden;
     background:linear-gradient(165deg,rgba(255,255,255,.03),transparent 45%),var(--card);
     border:1px solid var(--linha);border-radius:13px;padding:13px 13px 13px 16px;
     transition:border-color .15s, transform .15s, box-shadow .15s;
     animation:carta .45s backwards;animation-delay:calc(var(--i) * 16ms)}
.timecard::before{content:'';position:absolute;left:0;top:0;bottom:0;width:4px;background:var(--c)}
.timecard:hover{transform:translateY(-3px);border-color:var(--linha2);box-shadow:0 14px 30px -14px #000c}
.timecard input{position:absolute;opacity:0;pointer-events:none}
.timecard:has(input:checked){border-color:var(--fba);
     box-shadow:0 0 0 1px var(--fba), 0 12px 34px -12px rgba(252,0,37,.55)}
.timecard .check{position:absolute;top:0;right:0;width:30px;height:30px;display:grid;place-items:center;
     background:var(--fba);color:#fff;font-size:13px;border-radius:0 11px 0 11px;
     opacity:0;transform:translateY(-4px);transition:.16s}
.timecard:has(input:checked) .check{opacity:1;transform:none}
.timecard img.escudo,.timecard .es{width:52px;height:52px;border-radius:12px;object-fit:cover;flex:none;
     display:grid;place-items:center;font-weight:900;font-size:14px;color:#fff;
     filter:drop-shadow(0 4px 10px rgba(0,0,0,.5))}
.timecard .n{flex:1;min-width:0}
/* filhos em bloco de propósito: .n é <span> (cabe no <label>) e span é
   inline — altura de filho inline é ignorada e a barra sumia da tela. */
.timecard .n b{display:block;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:17px;letter-spacing:.4px;text-transform:uppercase;line-height:1.02;
     display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}
.timecard .n small{display:block;color:var(--txt3);font-size:11px;font-weight:600;margin-top:3px}
.barra{display:block;height:4px;border-radius:99px;background:#04060c;overflow:hidden;margin:7px 0 0}
.barra i{display:block;height:100%;background:var(--c);box-shadow:0 0 8px var(--c)}
.timecard .lado{flex:none;display:flex;flex-direction:column;align-items:center;gap:5px}
.timecard .ovr{font-family:Oswald,sans-serif;font-weight:600;font-size:23px;line-height:1;
     color:var(--txt);letter-spacing:.5px}
.timecard .ovr small{display:block;font-family:Inter,sans-serif;font-size:8px;font-weight:800;
     letter-spacing:1.5px;color:var(--txt3);text-align:center;margin-top:2px}
.paga{transform:skewX(-8deg);border-radius:3px;padding:3px 8px;font-size:10.5px;font-weight:800}
.paga>span{display:inline-block;transform:skewX(8deg)}
.paga.alta{background:#f5c84226;color:var(--ouro);border:1px solid #f5c84245}
.paga.max{background:#2bd97c22;color:var(--ok);border:1px solid #2bd97c45}
.paga.baixa{background:#1b2541;color:var(--txt3);border:1px solid var(--linha)}

/* a barra de confirmação que sobe quando um time é marcado */
.confirma{position:fixed;left:0;right:0;bottom:0;z-index:50;
     background:rgba(6,9,18,.92);backdrop-filter:blur(14px);border-top:1px solid var(--linha2);
     transform:translateY(110%);transition:transform .28s cubic-bezier(.2,.9,.3,1)}
.confirma.on{transform:none}
.confirma .dentro{max-width:1080px;margin:0 auto;display:flex;align-items:center;gap:13px;padding:12px 16px}
.confirma img,.confirma .es{width:46px;height:46px;border-radius:11px;object-fit:cover;flex:none;
     display:grid;place-items:center;font-weight:900;font-size:12px;background:#0007}
.confirma .quem{flex:1;min-width:0}
.confirma .quem small{display:block;font-size:9.5px;letter-spacing:2px;color:var(--txt3);font-weight:800;text-transform:uppercase}
.confirma .quem b{display:block;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:19px;text-transform:uppercase;letter-spacing:.6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.confirma .quem em{font-style:normal;color:var(--ouro);font-size:12px;font-weight:700}

/* ── hero do time (temporada em curso) ── */
.hero{position:relative;overflow:hidden;border:1px solid var(--linha);border-radius:18px;
      padding:22px 20px;margin-bottom:6px;background:var(--card)}
.hero::before{content:'';position:absolute;inset:0;background:
      linear-gradient(105deg,var(--time) -32%,rgba(13,19,34,.2) 52%),
      repeating-linear-gradient(115deg,transparent 0 30px,rgba(255,255,255,.014) 30px 32px)}
.hero .dagua{position:absolute;right:-48px;top:50%;transform:translateY(-50%) rotate(-11deg);
      width:255px;height:255px;object-fit:contain;opacity:.08;filter:saturate(.7)}
.hero>*{position:relative}
.hero-topo{display:flex;gap:16px;align-items:center}
.hero-escudo{width:82px;height:82px;border-radius:17px;object-fit:cover;flex:none;background:#0006;
     display:grid;place-items:center;font-weight:900;font-size:24px;border:1px solid #ffffff22;
     filter:drop-shadow(0 8px 20px rgba(0,0,0,.55))}
.hero .eyebrow{font-size:10px;text-transform:uppercase;letter-spacing:2.2px;color:#ffffffc0;font-weight:800}
.hero h1{margin:4px 0 5px;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:clamp(27px,5vw,38px);line-height:.98;text-transform:uppercase;letter-spacing:.4px}
.hero .campanha{font-size:13px;color:#ffffffd8;font-weight:600}
.hero .campanha b{font-family:Oswald,sans-serif;font-size:16px;letter-spacing:.5px}
.hero-num{display:flex;gap:9px;margin-top:17px}
.hero-num div{flex:1;background:rgba(0,0,0,.38);border:1px solid #ffffff14;border-radius:4px;
     padding:9px 8px;text-align:center;transform:skewX(-8deg)}
.hero-num div>*{display:block;transform:skewX(8deg)}
.hero-num b{font-family:Oswald,sans-serif;font-weight:600;font-size:21px;line-height:1.1}
.hero-num span{font-size:9px;text-transform:uppercase;letter-spacing:1.4px;color:#ffffff99;font-weight:800;margin-top:1px}
@media(max-width:560px){.hero h1{font-size:25px}.hero-escudo{width:62px;height:62px}.hero .dagua{width:180px;height:180px;opacity:.06}}

.faixa{display:flex;gap:5px;flex-wrap:wrap;margin-top:15px}
.chip{width:29px;height:29px;border-radius:4px;display:grid;place-items:center;font-size:11.5px;
      font-weight:900;border:1px solid transparent;transform:skewX(-8deg);transition:.13s}
.chip span{transform:skewX(8deg)}
.chip:hover{transform:skewX(-8deg) translateY(-2px)}
.chip.v{background:#2bd97c1e;color:var(--ok);border-color:#2bd97c3d}
.chip.d{background:#f8615a1a;color:var(--perda);border-color:#f8615a35}

/* ── duelo (próximo jogo) ── */
.duelo{position:relative;display:flex;align-items:stretch;justify-content:center;gap:0;
       border-radius:14px;overflow:hidden;border:1px solid var(--linha);margin-bottom:14px;
       background:linear-gradient(100deg,var(--cA) -55%,#0d1322 40%,#0d1322 60%,var(--cB) 155%)}
.duelo .lado{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:8px;
       text-align:center;padding:22px 10px 18px}
.duelo img,.duelo .es{width:72px;height:72px;border-radius:16px;object-fit:cover;
       display:grid;place-items:center;font-weight:900;font-size:17px;background:#0007;
       filter:drop-shadow(0 8px 22px rgba(0,0,0,.6))}
.duelo b{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;font-size:17px;
       letter-spacing:.5px;text-transform:uppercase;line-height:1.1}
.duelo small{color:var(--txt2);font-size:11.5px;font-weight:600}
.duelo .vs{align-self:center;flex:none;font-family:Oswald,sans-serif;font-weight:700;font-style:italic;
       font-size:30px;color:transparent;-webkit-text-stroke:1.3px #ffffff66;padding:0 6px;letter-spacing:1px}

/* ── TRANSMISSÃO ── */
.tv{border-radius:18px;overflow:hidden;border:1px solid var(--linha);margin-bottom:14px;background:var(--card)}
.tv-placar{display:grid;grid-template-columns:1fr auto 1fr;align-items:center;gap:6px;
     padding:24px 12px 18px;position:relative;
     background:radial-gradient(420px 190px at 14% 0%,var(--corA),transparent 72%),
                radial-gradient(420px 190px at 86% 0%,var(--corB),transparent 72%),
                #0a0f1d}
.tv-time{display:flex;flex-direction:column;align-items:center;gap:7px;min-width:0;transition:.2s}
.tv-time img,.tv-time .es{width:56px;height:56px;border-radius:14px;object-fit:cover;
     display:grid;place-items:center;font-weight:900;font-size:15px;background:#0007;
     border:1px solid #ffffff1f;filter:drop-shadow(0 6px 16px rgba(0,0,0,.55))}
.tv-time .ab{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-size:17px;font-weight:700;letter-spacing:1.2px}
.tv-time .pt{font-family:Oswald,sans-serif;font-size:58px;font-weight:600;line-height:.92;
     font-variant-numeric:tabular-nums;transition:transform .17s;text-shadow:0 4px 24px rgba(0,0,0,.5)}
.tv-time .pt.pulou{transform:scale(1.24);color:var(--ouro)}
@keyframes flash{0%{filter:brightness(1)}30%{filter:brightness(1.7)}100%{filter:brightness(1)}}
.tv-time.flash{animation:flash .5s}
.tv-meio{text-align:center;padding:9px 16px 11px;background:rgba(0,0,0,.42);border:1px solid #ffffff14;
     border-radius:4px;transform:skewX(-8deg)}
.tv-meio>*{transform:skewX(8deg)}
.tv-q{display:block;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-size:13px;
     letter-spacing:2.4px;color:var(--txt2);font-weight:700}
.tv-relogio{display:block;font-family:Oswald,sans-serif;font-size:30px;font-weight:600;
     font-variant-numeric:tabular-nums;letter-spacing:1.5px;line-height:1.1}
.tv-vivo{position:absolute;top:10px;left:12px;display:flex;align-items:center;gap:6px;
     font-size:10px;font-weight:800;letter-spacing:1.8px;color:#ff5d76}
@keyframes pulso{0%,100%{opacity:1}50%{opacity:.25}}
.tv-vivo i{width:7px;height:7px;border-radius:50%;background:var(--fba);animation:pulso 1.1s infinite}
.tv-etapa{position:absolute;top:10px;right:12px;font-size:10px;font-weight:800;letter-spacing:1.8px;color:var(--txt3)}
.tv-barra{height:3px;background:#04060c}
.tv-barra i{display:block;height:100%;background:linear-gradient(90deg,var(--corA),var(--fba),var(--corB));
     width:0;transition:width .2s linear}
.tv-ctrl{display:flex;gap:8px;align-items:center;padding:11px 13px;background:var(--card2);
     border-top:1px solid var(--linha);flex-wrap:wrap}
.vel{display:flex;gap:4px;background:#04060c;border-radius:4px;padding:3px;transform:skewX(-8deg)}
.vel button{background:transparent;border:0;color:var(--txt3);padding:6px 12px;border-radius:3px;
     font-size:12.5px;font-weight:800;cursor:pointer}
.vel button span{display:inline-block;transform:skewX(8deg)}
.vel button.on{background:var(--fba);color:#fff;box-shadow:0 2px 10px -2px rgba(252,0,37,.7)}
.tv-feed{padding:6px 0;max-height:335px;overflow:auto;background:#080c16}
.lance{display:flex;gap:11px;align-items:flex-start;padding:8px 15px;font-size:13.2px;
     border-left:3px solid transparent;animation:entra .2s ease-out}
@keyframes entra{from{opacity:0;transform:translateX(-7px)}to{opacity:1;transform:none}}
.lance .rl{font-family:Oswald,sans-serif;font-size:11px;color:var(--txt3);min-width:44px;
     font-variant-numeric:tabular-nums;padding-top:1.5px}
.lance .tx{flex:1;min-width:0;color:var(--txt2);line-height:1.45}
.lance.cesta{background:rgba(255,255,255,.025)}
.lance.cesta .tx{color:var(--txt);font-weight:600}
.lance.cesta3{background:rgba(245,200,66,.08)}
.lance.cesta3 .tx{color:#fff;font-weight:700}
.lance.evento{background:rgba(252,0,37,.07);border-left-color:var(--fba)}
.tv-fim{padding:20px 14px;text-align:center;border-top:1px solid var(--linha)}
.tv-fim .r{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:34px;letter-spacing:1.5px;text-transform:uppercase}
.tv-fim .r.ganhou{color:var(--ok);text-shadow:0 0 34px rgba(43,217,124,.4)}
.tv-fim .r.perdeu{color:var(--perda)}

/* ── tabelas ── */
table{width:100%;border-collapse:collapse;font-size:13.2px}
th{text-align:left;font-size:9.5px;text-transform:uppercase;letter-spacing:1.3px;color:var(--txt3);
   font-weight:800;padding:0 7px 8px}
td{padding:7px;border-top:1px solid var(--linha)}
tr.eu td{background:rgba(252,0,37,.08)}
tr.eu td:first-child{box-shadow:inset 3px 0 0 var(--fba)}
tr.corte td{border-bottom:1px dashed var(--linha2)}
.num2{text-align:right;font-variant-numeric:tabular-nums;font-family:Oswald,sans-serif;font-weight:500;font-size:13.5px}
.duas{display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:780px){.duas{grid-template-columns:1fr}}
.mini{width:23px;height:23px;border-radius:6px;object-fit:cover;vertical-align:-6px;margin-right:7px;
      display:inline-grid;place-items:center;font-size:9px;font-weight:900;background:#0007}
.seed{display:inline-grid;place-items:center;width:20px;height:20px;border-radius:5px;margin-right:6px;
      font-size:10.5px;font-weight:800;color:var(--txt3);background:#111a2e;vertical-align:-4px}
.seed.po{color:var(--ok);background:#2bd97c18}
.seed.pi{color:var(--ouro);background:#f5c84216}

/* ── corridas ── */
.corridas{display:grid;grid-template-columns:repeat(auto-fit,minmax(195px,1fr));gap:13px}
.corrida{background:var(--card2);border:1px solid var(--linha);border-radius:12px;padding:12px}
.corrida b.rot{display:inline-block;transform:skewX(-8deg);background:#f5c84220;border:1px solid #f5c84240;
     color:var(--ouro);border-radius:3px;padding:3px 10px;font-size:11px;font-weight:800;
     letter-spacing:1.4px;margin-bottom:10px}
.corrida b.rot span{display:inline-block;transform:skewX(8deg)}
/* DUAS LINHAS POR JOGADOR: nome em cima, números embaixo. Na mesma linha o
   cartão estreito cortava o nome em duas letras ("Pa...", "Bil...") e a
   corrida virava charada — os números cabiam, quem não cabia era o nome. */
.corrida .l{display:flex;gap:8px;align-items:flex-start;padding:5.5px 0;font-size:12.8px}
.corrida .l + .l{border-top:1px solid rgba(255,255,255,.04)}
.corrida .l .p{width:13px;flex:none;color:var(--txt3);font-weight:900;font-size:10.5px;
     font-family:Oswald,sans-serif;padding-top:2px}
.corrida .l .mini{flex:none;margin-top:1px}
.corrida .l .col{flex:1;min-width:0}
.corrida .l:first-of-type .nm{font-weight:700}
.corrida .l .nm{display:block;line-height:1.25;overflow:hidden;white-space:nowrap;text-overflow:ellipsis}
.corrida .l .vl{display:block;color:var(--txt3);font-size:11px;font-weight:700;
     font-variant-numeric:tabular-nums;margin-top:1.5px}

/* ── chaveamento ── */
.rodada{margin-bottom:17px}
.rodada>b{display:inline-block;transform:skewX(-8deg);background:var(--card2);border:1px solid var(--linha2);
     border-radius:3px;padding:4px 13px;font-size:11px;letter-spacing:1.6px;text-transform:uppercase;
     color:var(--txt2);font-weight:800;margin-bottom:9px}
.rodada>b span{display:inline-block;transform:skewX(8deg)}
.serie{display:flex;align-items:center;gap:9px;background:var(--card2);border:1px solid var(--linha);
     border-radius:11px;padding:10px 12px;margin-bottom:7px;font-size:13.2px}
.serie.eu{border-color:#fc002570;background:rgba(252,0,37,.06);box-shadow:0 0 22px -10px rgba(252,0,37,.5)}
.serie .t{flex:1;min-width:0;display:flex;align-items:center;gap:7px;font-weight:600}
.serie .t.out{color:var(--txt3);font-weight:500}
.serie img,.serie .es{width:26px;height:26px;border-radius:7px;object-fit:cover;flex:none;
     display:grid;place-items:center;font-size:9px;font-weight:900;background:#0007}
.serie .w{font-family:Oswald,sans-serif;font-size:19px;font-variant-numeric:tabular-nums;flex:none}
.serie .w.ganha{color:var(--ok)}
.serie .seed{margin:0}

/* ── o portão do fim da temporada regular ── */
.fim-regular{position:relative;overflow:hidden;text-align:center;border:1px solid var(--linha2);
     border-radius:18px;padding:34px 20px 30px;margin:18px 0;
     background:radial-gradient(560px 260px at 50% -70%,rgba(245,200,66,.22),transparent 70%),var(--card)}
.fim-regular .eyebrow{font-size:10.5px;letter-spacing:2.6px;text-transform:uppercase;color:var(--ouro);font-weight:800}
.fim-regular h2{margin:8px 0 6px;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:clamp(30px,6vw,46px);line-height:.96;text-transform:uppercase;letter-spacing:.5px}
.fim-regular h2 em{font-style:italic;color:transparent;-webkit-text-stroke:1.4px var(--ouro)}
.fim-regular form{margin-top:16px}

/* ── chaveamento por conferência ── */
.rodada-rot{margin:20px 0 10px}
.rodada-rot span{display:inline-block;transform:skewX(-8deg);background:var(--card2);
     border:1px solid var(--linha2);border-radius:3px;padding:5px 14px;font-size:11px;
     letter-spacing:1.7px;text-transform:uppercase;color:var(--txt2);font-weight:800}
.rodada-rot span{font-family:Inter,sans-serif}
.confs{gap:14px;margin-bottom:4px}
.conf-rot{margin-bottom:8px}
.conf-rot span{display:inline-block;transform:skewX(-8deg);border-radius:3px;padding:4px 13px;
     font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;font-size:14.5px;
     letter-spacing:1.6px;text-transform:uppercase}
.conf-rot.leste span{background:#3b82f61f;border:1px solid #3b82f648;color:#8cb6ff}
.conf-rot.oeste span{background:#f59e0b1c;border:1px solid #f59e0b45;color:#ffc766}

.final-fba{position:relative;overflow:hidden;border:1px solid #f5c84252;border-radius:18px;
     padding:22px 16px;margin-top:18px;text-align:center;
     background:radial-gradient(380px 200px at 18% -30%,var(--cA),transparent 70%),
                radial-gradient(380px 200px at 82% -30%,var(--cB),transparent 70%),
                radial-gradient(500px 240px at 50% -60%,rgba(245,200,66,.18),transparent 72%),var(--card)}
.final-fba.eu{box-shadow:0 0 44px -14px rgba(245,200,66,.55)}
.final-fba .eyebrow{font-size:10.5px;letter-spacing:2.4px;text-transform:uppercase;color:var(--ouro);font-weight:800}
.final-fba .confronto{display:flex;align-items:center;justify-content:center;gap:14px;margin-top:14px}
.final-fba .lado{flex:1;max-width:220px;min-width:0;display:flex;flex-direction:column;align-items:center;gap:8px}
.final-fba .lado.out{opacity:.55}
.final-fba img,.final-fba .es{width:64px;height:64px;border-radius:15px;object-fit:cover;
     display:grid;place-items:center;font-weight:900;font-size:15px;background:#0007;
     filter:drop-shadow(0 8px 20px rgba(0,0,0,.6))}
.final-fba b{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;font-size:16.5px;
     letter-spacing:.5px;text-transform:uppercase;line-height:1.15}
.placar-final{flex:none;display:flex;align-items:center;gap:9px;font-family:Oswald,sans-serif;
     font-size:42px;font-weight:600;font-variant-numeric:tabular-nums}
.placar-final em{font-style:normal;font-size:19px;color:var(--txt3)}
.placar-final .ganha{color:var(--ouro)}

/* ── a imagem de compartilhar do resumo ── */
#cardTemporada{width:100%;height:auto;border-radius:12px;border:1px solid var(--linha2);display:block}

/* ── avisos ── */
.aviso{border-radius:12px;padding:13px 15px;margin-bottom:14px;font-size:13.4px;line-height:1.6}
.aviso.ok{background:#f5c84216;border:1px solid #f5c84248}
.aviso.err{background:#f8615a16;border:1px solid #f8615a4d}

/* ── modais (box score e resumo) ── */
.modal-fundo{position:fixed;inset:0;z-index:60;background:rgba(2,4,9,.88);backdrop-filter:blur(6px);
     display:none;align-items:flex-start;justify-content:center;padding:24px 14px;overflow:auto}
.modal-fundo.aberto{display:flex}
.modal{position:relative;width:100%;max-width:520px;background:var(--card);border:1px solid var(--linha2);
     border-radius:18px;overflow:hidden;animation:sobe .3s cubic-bezier(.2,.9,.3,1);
     box-shadow:0 40px 90px -30px #000}
@keyframes sobe{from{opacity:0;transform:translateY(22px) scale(.96)}to{opacity:1;transform:none}}
.modal-x{position:absolute;top:12px;right:12px;z-index:2;width:33px;height:33px;border-radius:10px;
     background:#0008;border:1px solid #ffffff24;color:#fff;cursor:pointer;display:grid;place-items:center}
.modal-topo{text-align:center;padding:28px 20px 22px;position:relative;
     background:radial-gradient(420px 220px at 50% -70%,var(--c),transparent 75%),#0f1526}
.modal-topo .tacinha{width:58px;height:58px;margin:0 auto 10px;border-radius:50%;display:grid;place-items:center;
     background:#f5c84220;border:1px solid #f5c84252;color:var(--ouro);font-size:26px;
     box-shadow:0 0 44px -6px rgba(245,200,66,.45)}
.modal-topo .eyebrow{font-size:9.5px;letter-spacing:2.4px;text-transform:uppercase;color:#ffffffb0;font-weight:800}
.modal-topo h3{margin:6px 0 0;font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;
     font-size:29px;letter-spacing:.5px;line-height:1.08;text-transform:uppercase}
.modal-corpo{padding:18px}
.bloco-rot{display:block;font-size:10.5px;text-transform:uppercase;letter-spacing:1.5px;color:var(--ouro);
     font-weight:800;margin:20px 0 9px}
.bloco-rot:first-child{margin-top:0}
.meu-fim{display:flex;align-items:center;gap:11px;background:var(--card2);border:1px solid var(--linha);
     border-radius:13px;padding:12px}
.meu-fim.campeao{border-color:#f5c84272;background:#f5c8420e;box-shadow:0 0 34px -10px rgba(245,200,66,.5)}
.meu-fim img,.meu-fim .es{width:46px;height:46px;border-radius:12px;object-fit:cover;flex:none;
     display:grid;place-items:center;font-weight:900;font-size:13px}
.meu-fim b{display:block;font-size:14.5px}
.meu-fim small{color:var(--txt3);font-size:11.5px}
.meu-fim .selo{margin-left:auto;text-align:right;font-size:10.5px;font-weight:800;text-transform:uppercase;
     letter-spacing:.8px;color:var(--ouro);max-width:40%;line-height:1.4}
.premio{display:flex;gap:11px;align-items:flex-start;padding:8px 0;border-top:1px solid var(--linha)}
.premio:first-of-type{border-top:0}
.premio .pr{width:62px;flex:none;font-family:'Barlow Condensed',sans-serif;font-style:italic;
     font-size:14px;letter-spacing:1px;color:var(--ouro);font-weight:700;padding-top:2px}
.premio .pn{flex:1;min-width:0;font-size:13.4px;line-height:1.45}
.premio .pn small{font-size:11px;color:var(--txt3)}
.etapa{display:flex;align-items:center;gap:10px;padding:9px 0;border-top:1px solid var(--linha);font-size:13.3px}
.etapa:first-of-type{border-top:0}
.etapa.feita{color:var(--txt)} .etapa.feita i{color:var(--ok)}
.etapa.falta{color:var(--txt3)}
.etapa .v{margin-left:auto;font-weight:800;font-variant-numeric:tabular-nums;font-family:Oswald,sans-serif}
.etapa.feita .v{color:var(--ouro)}
.total{display:flex;align-items:center;margin-top:12px;padding-top:12px;border-top:1px solid var(--linha2);
     font-size:14px;font-weight:700}
.total b{margin-left:auto;color:var(--ouro);font-size:21px;font-family:Oswald,sans-serif;font-variant-numeric:tabular-nums}
.btn-resumo{background:#f5c84216;border:1px solid #f5c84248;color:var(--ouro);
     padding:9px 15px;border-radius:4px;font-weight:700;font-size:13px;cursor:pointer;
     display:inline-flex;align-items:center;gap:7px;margin-top:15px;transform:skewX(-8deg)}
.btn-resumo>*{transform:skewX(8deg)}

/* placar do box score */
.box-placar{display:flex;align-items:center;gap:10px;padding:24px 18px 18px;
     background:radial-gradient(320px 160px at 12% 0%,var(--cA),transparent 72%),
                radial-gradient(320px 160px at 88% 0%,var(--cB),transparent 72%),#0a0f1d}
.box-placar .bx{color:transparent;-webkit-text-stroke:1px #ffffff55;font-family:Oswald,sans-serif;
     font-style:italic;font-weight:700;font-size:21px;flex:none}
.box-placar .bt{flex:1;min-width:0;display:flex;flex-direction:column;align-items:center;gap:6px;text-align:center;opacity:.58}
.box-placar .bt.venceu{opacity:1}
.box-placar img,.box-placar .es{width:48px;height:48px;border-radius:12px;object-fit:cover;
     display:grid;place-items:center;font-weight:900;font-size:13px;background:#0007;border:1px solid #ffffff1c}
.box-placar b{font-family:'Barlow Condensed',sans-serif;font-style:italic;font-weight:700;font-size:14px;
     letter-spacing:.5px;text-transform:uppercase;line-height:1.2}
.box-placar .pts{font-family:Oswald,sans-serif;font-size:38px;font-weight:600;line-height:1;font-variant-numeric:tabular-nums}
.box-placar .bt.venceu .pts{color:var(--ouro)}
.modal-corpo table{margin-bottom:4px}
</style>
</head>
<body>

<header class="topbar">
  <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
  <div class="marca">
    <span class="slab"><b>FBA</b></span>
    <span class="resto">Minha Temporada</span>
  </div>
  <span class="saldo"><i class="bi bi-coin"></i><b><?= number_format($moedas, 0, ',', '.') ?></b></span>
</header>

<div class="wrap">

<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if ($premiosNovos): $tot = array_sum(array_column($premiosNovos, 'moedas')); ?>
  <div class="aviso ok">
    <b><i class="bi bi-coin"></i> +<?= number_format($tot, 0, ',', '.') ?> moedas</b><br>
    <?php foreach ($premiosNovos as $p): ?><?= e($p['rotulo']) ?> — +<?= (int) $p['moedas'] ?><br><?php endforeach; ?>
  </div>
<?php endif; ?>

<?php if (!$tem): /* ═════════════ ESCOLHA DO TIME ═════════════ */
  $times = TemporadaLiga::timesDaLiga($liga);
  $ovrs = array_map(fn($t) => (float) $t['ovr_medio'], $times);
  $piso = $ovrs ? min($ovrs) : 0; $topo = $ovrs ? max($ovrs) : 1;
?>
  <form method="post" id="formTemporada">
    <input type="hidden" name="acao" value="comecar">
    <input type="hidden" name="liga" value="<?= e($liga) ?>">

    <div class="capa">
      <div class="eyebrow">FBA Games apresenta</div>
      <h1>Minha <em>Temporada</em></h1>
      <p class="dim">
        Pegue um time da FBA e leve ele até o título. Você assiste a cada um dos 82 jogos —
        relógio correndo, placar subindo, lance a lance. Cada etapa de playoff paga moeda,
        e paga <b style="color:var(--ouro)">mais quanto pior for o elenco que você escolher</b>.
      </p>
      <div class="passos">
        <span class="passo"><b>1</b> Escolha a liga</span>
        <span class="passo"><b>2</b> Escolha o time</span>
        <span class="passo"><b>3</b> Bola ao alto</span>
      </div>
      <div class="gmrow">
        <input name="gm_nome" maxlength="40" placeholder="Seu nome de GM" class="inp" value="<?= e($user['name'] ?? '') ?>">
      </div>
    </div>

    <div class="sec"><div class="tag"><span>A liga</span></div></div>
    <div class="ligas">
      <?php foreach (TemporadaLiga::LIGAS as $l): ?>
        <a class="ligatab<?= $l === $liga ? ' on' : '' ?>" href="?liga=<?= $l ?>"><span><?= $l ?></span></a>
      <?php endforeach; ?>
    </div>
    <p class="liga-desc">
      <?= e([
        'ELITE'  => 'A divisão de cima: 32 times, os melhores elencos da FBA.',
        'NEXT'   => '30 times, um degrau abaixo da ELITE.',
        'RISE'   => '30 times em formação.',
        'ROOKIE' => '30 times, a porta de entrada da FBA.',
      ][$liga]) ?>
    </p>

    <div class="sec"><div class="tag"><span>O time</span></div>
      <span class="sub">do mais forte<br>pro mais fraco</span></div>
    <div class="times">
      <?php foreach ($times as $i => $t):
        $nome = trim((string) $t['city'] . ' ' . (string) $t['name']);
        $media = (float) $t['ovr_medio'];
        $forca = $topo > $piso ? (int) round(($media - $piso) / ($topo - $piso) * 100) : 50;
        $c = $cor($t['cor']);
        $logo = trim((string) $t['photo_url']);
        /* O multiplicador exato no cartão, não um adjetivo: "paga 2,4x" diz
           mais que "azarão". É a mesma conta do TemporadaPremios. */
        $mult = round(1 + (1 - $forca / 100) * 1.5, 1);
        $classeMult = $forca < 25 ? 'max' : ($forca < 55 ? 'alta' : 'baixa');
      ?>
      <label class="timecard" style="--c:<?= e($c) ?>;--i:<?= min($i, 24) ?>">
        <?php /* sem `required`: o rádio é invisível e campo obrigatório que o
                 navegador não consegue focar trava o envio em silêncio. Quem
                 segura o botão é o script da barra de confirmação. */ ?>
        <input type="radio" name="fba_team_id" value="<?= (int) $t['id'] ?>"
               data-nome="<?= e($nome) ?>" data-logo="<?= e($logo) ?>" data-mult="<?= $mult ?>" data-cor="<?= e($c) ?>">
        <span class="check"><i class="bi bi-check-lg"></i></span>
        <?php if ($logo): ?><img class="escudo" src="<?= e($logo) ?>" alt="" loading="lazy">
        <?php else: ?><span class="es" style="background:<?= e($c) ?>"><?= e(mb_strtoupper(mb_substr($nome, 0, 2))) ?></span><?php endif; ?>
        <span class="n">
          <b><?= e($nome) ?></b>
          <small><?= strtoupper((string) $t['conference']) === 'LESTE' ? 'Leste' : 'Oeste' ?> · teto <?= (int) $t['teto'] ?> · <?= (int) $t['jogadores'] ?> jogadores</small>
          <span class="barra"><i style="width:<?= max(4, $forca) ?>%"></i></span>
        </span>
        <span class="lado">
          <span class="ovr"><?= number_format($media, 1, ',', '') ?><small>OVR</small></span>
          <span class="paga <?= $classeMult ?>"><span>PAGA <?= number_format($mult, 1, ',', '') ?>x</span></span>
        </span>
      </label>
      <?php endforeach; ?>
    </div>
  </form>

  <?php /* A BARRA DE CONFIRMAÇÃO. Marcou um time, ela sobe do rodapé com o
           escudo e o botão — o botão está sempre ao alcance, em qualquer
           ponto da lista, sem precisar rolar de volta pra lugar nenhum. */ ?>
  <div class="confirma" id="confirmaBar">
    <div class="dentro">
      <img id="cbLogo" src="" alt="" style="display:none">
      <span class="es" id="cbEs" style="display:none"></span>
      <div class="quem">
        <small>Seu time</small>
        <b id="cbNome">—</b>
        <em id="cbMult"></em>
      </div>
      <button class="cta" type="submit" form="formTemporada" id="btComecar">
        <span><i class="bi bi-play-fill"></i> Começar</span>
      </button>
    </div>
  </div>

  <script>
    /* Enter no campo de nome dispara o envio implícito do formulário — e sem
       time marcado isso iria e voltaria do servidor só pra trazer um erro.
       Barra aqui mesmo: o jeito de começar é marcar um time. */
    document.getElementById('formTemporada').addEventListener('submit', ev => {
      if (!document.querySelector('input[name=fba_team_id]:checked')) ev.preventDefault();
    });
    const bar = document.getElementById('confirmaBar');
    const cbLogo = document.getElementById('cbLogo'), cbEs = document.getElementById('cbEs');
    document.querySelectorAll('input[name=fba_team_id]').forEach(r => r.addEventListener('change', () => {
      document.getElementById('cbNome').textContent = r.dataset.nome;
      document.getElementById('cbMult').textContent = 'cada etapa paga ' + r.dataset.mult.replace('.', ',') + 'x';
      if (r.dataset.logo) {
        cbLogo.src = r.dataset.logo; cbLogo.style.display = ''; cbEs.style.display = 'none';
      } else {
        cbEs.textContent = r.dataset.nome.slice(0, 2).toUpperCase();
        cbEs.style.background = r.dataset.cor;
        cbEs.style.display = 'grid'; cbLogo.style.display = 'none';
      }
      bar.classList.add('on');
    }));
  </script>

<?php elseif ($aoVivo): /* ═════════════ TRANSMISSÃO ═════════════ */
  $jg = $trans['jogo'];
  $tc = $trans['times'];
  $casaId = (int) $jg['home_id']; $foraId = (int) $jg['away_id'];
  $corCasa = $cor($tc[$casaId]['primary_color'] ?? '', '#3b82f6');
  $corFora = $cor($tc[$foraId]['primary_color'] ?? '', '#64748b');
  $euCasa  = $casaId === $est['gm_id'];
  $ganhei  = $euCasa ? (int) $jg['home_pts'] > (int) $jg['away_pts'] : (int) $jg['away_pts'] > (int) $jg['home_pts'];
  $etapa   = $jg['stage'] !== 'regular' ? strtoupper((string) $jg['stage']) : 'TEMPORADA REGULAR';
?>
  <div class="tv" style="--corA:<?= e($corFora) ?>55;--corB:<?= e($corCasa) ?>55">
    <div class="tv-placar">
      <span class="tv-vivo" id="tvVivo"><i></i>AO VIVO</span>
      <span class="tv-etapa"><?= e($etapa) ?></span>
      <?php foreach ([[$foraId, 'fora', $corFora], [null, null, null], [$casaId, 'casa', $corCasa]] as [$tid, $lado, $c]):
        if ($tid === null): ?>
          <div class="tv-meio">
            <span class="tv-q" id="tvQ">1º QUARTO</span>
            <span class="tv-relogio" id="tvRelogio">12:00</span>
          </div>
        <?php continue; endif;
        $t = $tc[$tid]; ?>
        <div class="tv-time" id="lado<?= $lado ?>">
          <?php if (!empty($t['logo_url'])): ?><img src="<?= e($t['logo_url']) ?>" alt="">
          <?php else: ?><span class="es" style="background:<?= e($c) ?>"><?= e($t['abbr']) ?></span><?php endif; ?>
          <span class="ab"><?= e($t['abbr']) ?></span>
          <span class="pt" id="pt<?= $lado ?>">0</span>
        </div>
      <?php endforeach; ?>
    </div>
    <div class="tv-barra"><i id="tvBarra"></i></div>

    <div class="tv-ctrl">
      <button class="btn" id="btPausar" style="padding:8px 14px"><i class="bi bi-pause-fill"></i> Pausar</button>
      <div class="vel">
        <button data-v="1"><span>1x</span></button><button data-v="2" class="on"><span>2x</span></button>
        <button data-v="4"><span>4x</span></button><button data-v="10"><span>10x</span></button>
      </div>
      <button class="btn btn-fraco" id="btPular" style="padding:8px 14px;margin-left:auto">Pular pro fim</button>
    </div>

    <div class="tv-feed" id="tvFeed"></div>

    <div class="tv-fim" id="tvFim" style="display:none">
      <div class="r <?= $ganhei ? 'ganhou' : 'perdeu' ?>"><?= $ganhei ? 'Vitória!' : 'Derrota' ?></div>
      <p class="dim" style="margin:4px 0 14px"><?= (int) $jg['ot'] > 0 ? (int) $jg['ot'] . ' prorrogação' . ((int) $jg['ot'] > 1 ? 'ões' : '') : e(ucfirst(mb_strtolower($etapa))) ?></p>
      <div class="linha-btns" style="justify-content:center">
        <form method="post"><button class="cta" name="acao" value="proximo"><span><i class="bi bi-fast-forward-fill"></i> Próximo jogo</span></button></form>
        <a class="btn" href="/games/games/temporada.php">Voltar à temporada</a>
      </div>
    </div>
  </div>

  <?php
  /* O BOX SCORE É O MESMO NOS DOIS CAMINHOS, então mora num só lugar: quem
     assistiu vê ele abrir quando o jogo acaba, quem só simulou cai direto
     nele. @see temporada_box.php */
  $boxJogo = $trans; $boxAbreSozinho = false; $boxVoltar = null;
  include __DIR__ . '/../core/temporada_box.php';
  ?>

  <script>
  /* ── A TRANSMISSÃO ──────────────────────────────────────────────────
     O jogo JÁ ACONTECEU: o motor simulou tudo e guardou o lance a lance.
     Aqui é reprodução — o relógio anda, o placar sobe e a narração entra no
     ritmo que a pessoa escolher. Fechar a aba no meio não muda o resultado.

     Cesta demora um pouco mais que rebote de propósito: é o lance que a
     pessoa veio ver, e passar reto por ele no mesmo tempo de um passe errado
     faz o jogo inteiro virar uma lista rolando. */
  const PBP   = <?= json_encode(array_values($trans['pbp']), JSON_UNESCAPED_UNICODE) ?>;
  const CASA  = <?= $casaId ?>;
  const Q_ROT = {Q1:'1º QUARTO', Q2:'2º QUARTO', Q3:'3º QUARTO', Q4:'4º QUARTO',
                 PR1:'PRORROGAÇÃO', PR2:'2ª PRORROGAÇÃO', PR3:'3ª PRORROGAÇÃO', FIM:'FIM'};
  const COR_LADO = {casa:'<?= e($corCasa) ?>', fora:'<?= e($corFora) ?>'};

  const $ = id => document.getElementById(id);
  const feed = $('tvFeed');
  let i = 0, vel = 2, rodando = true, timer = null;

  function pinta(ev) {
    $('tvQ').textContent = Q_ROT[ev.q] || ev.q || '';
    if (ev.clock) $('tvRelogio').textContent = ev.clock;
    for (const [el, v] of [[$('ptcasa'), ev.home_pts], [$('ptfora'), ev.away_pts]]) {
      const antes = +el.textContent;
      if (antes !== v) {
        el.textContent = v;
        el.classList.add('pulou');
        setTimeout(() => el.classList.remove('pulou'), 190);
      }
    }
    const cesta = ev.t === 'made' || ev.t === 'ft';
    const tres  = ev.t === 'made' && ev.pts === 3;
    const lado  = ev.team === CASA ? 'casa' : 'fora';
    if (cesta) {
      // O lado que pontuou pisca com a própria cor — dá pra acompanhar o
      // jogo só pelo placar, sem ler o feed.
      const bloco = $('lado' + lado);
      bloco.classList.remove('flash'); void bloco.offsetWidth; bloco.classList.add('flash');
    }
    const div = document.createElement('div');
    div.className = 'lance' + (tres ? ' cesta3' : cesta ? ' cesta' : '')
                  + (ev.t === 'injury' || ev.t === 'timeout' ? ' evento' : '');
    if (cesta && !tres) div.style.borderLeftColor = COR_LADO[lado];
    div.innerHTML = '<span class="rl">' + (ev.clock || '') + '</span><span class="tx"></span>';
    div.querySelector('.tx').textContent = ev.text || '';
    feed.prepend(div);
    /* O lance novo entra EM CIMA, e inserir em cima empurra o resto pra
       baixo sem mexer na rolagem — o que a pessoa via passava a ser um lance
       velho, e o novo ficava escondido acima. Volta pro topo a cada lance. */
    feed.scrollTop = 0;
    // O DOM não precisa guardar o jogo inteiro: 40 lances já é mais do que
    // alguém rola pra trás, e 500 nós deixam a animação travada no celular.
    while (feed.childElementCount > 40) feed.lastElementChild.remove();
    $('tvBarra').style.width = Math.round((i / PBP.length) * 100) + '%';
  }

  function passo() {
    if (i >= PBP.length) return fim();
    const ev = PBP[i++];
    pinta(ev);
    const base = (ev.t === 'made' || ev.t === 'ft') ? 320 : 170;
    if (rodando) timer = setTimeout(passo, Math.max(16, base / vel));
  }

  function fim() {
    rodando = false;
    clearTimeout(timer);
    const u = PBP[PBP.length - 1];
    if (u) { $('ptcasa').textContent = u.home_pts; $('ptfora').textContent = u.away_pts; }
    $('tvQ').textContent = 'FIM DE JOGO';
    $('tvRelogio').textContent = '0:00';
    $('tvBarra').style.width = '100%';
    $('tvFim').style.display = 'block';
    $('tvVivo').style.display = 'none';
    $('btPausar').disabled = true;
    $('btPular').disabled = true;
    // O jogo acabou: o box score é a próxima coisa que a pessoa quer ver.
    if (window.abrirBoxScore) setTimeout(window.abrirBoxScore, 650);
  }

  $('btPausar').onclick = () => {
    rodando = !rodando;
    $('btPausar').innerHTML = rodando
      ? '<i class="bi bi-pause-fill"></i> Pausar' : '<i class="bi bi-play-fill"></i> Continuar';
    if (rodando) passo();
    else clearTimeout(timer);
  };
  $('btPular').onclick = () => {
    // Mostra os últimos lances pra não terminar com o feed vazio.
    clearTimeout(timer);
    for (let k = Math.max(i, PBP.length - 12); k < PBP.length; k++) { i = k + 1; pinta(PBP[k]); }
    fim();
  };
  document.querySelectorAll('.vel button').forEach(b => b.onclick = () => {
    document.querySelectorAll('.vel button').forEach(x => x.classList.remove('on'));
    b.classList.add('on');
    vel = +b.dataset.v;
  });

  if (PBP.length) passo(); else fim();
  </script>

<?php else: /* ═════════════ A TEMPORADA ═════════════ */
  $meu = $est['time'];
  $corTime = $cor($meu['primary_color'] ?? '', '#3b82f6');
  $fase = $est['fase'];
  $faseRot = ['regular' => 'Temporada regular', 'playin' => 'Play-in',
              'playoffs' => 'Playoffs', 'offseason' => 'Temporada encerrada'][$fase] ?? $fase;
  $conq = TemporadaPremios::conquistadas();
  $pagas = TemporadaPremios::pagas();
  $meusJogos = temporadaMeusJogos($est['gm_id'], 14);
?>

  <div class="hero" style="--time:<?= e($corTime) ?>">
    <?php if (!empty($meu['logo_url'])): ?><img class="dagua" src="<?= e($meu['logo_url']) ?>" alt=""><?php endif; ?>
    <div class="hero-topo">
      <?php if (!empty($meu['logo_url'])): ?><img class="hero-escudo" src="<?= e($meu['logo_url']) ?>" alt="">
      <?php else: ?><span class="hero-escudo" style="background:<?= e($corTime) ?>"><?= e($meu['abbr']) ?></span><?php endif; ?>
      <div style="flex:1;min-width:0">
        <div class="eyebrow"><?= e($est['liga']) ?> · <?= e($faseRot) ?> · GM <?= e($est['gm_nome']) ?></div>
        <h1><?= e(temporadaNome($meu)) ?></h1>
        <div class="campanha">
          <b><?= (int) $meu['wins'] ?>–<?= (int) $meu['losses'] ?></b>
          <?php if ($est['posicao']): ?> · <?= $est['posicao'] ?>º no <?= $meu['conf'] === 'E' ? 'Leste' : 'Oeste' ?><?php endif; ?>
        </div>
      </div>
    </div>
    <div class="hero-num">
      <div><b><?= $est['jogados'] ?>/82</b><span>jogos</span></div>
      <div><b><?= $est['forca'] ?></b><span>força</span></div>
      <div><b><?= $est['rank'] ?>º</b><span>de <?= $est['times'] ?></span></div>
      <div><b style="color:var(--ouro)"><?= TemporadaPremios::multiplicador() ?>x</b><span>moedas</span></div>
    </div>
    <?php if ($meusJogos): ?>
    <div class="faixa">
      <?php foreach (array_reverse($meusJogos) as $g):
        $casa = (int) $g['home_id'] === $est['gm_id'];
        $v = (int) ($casa ? $g['home_pts'] : $g['away_pts']) > (int) ($casa ? $g['away_pts'] : $g['home_pts']);
      ?><a class="chip <?= $v ? 'v' : 'd' ?>" href="?jogo=<?= (int) $g['id'] ?>&vivo=1"
           title="<?= $v ? 'Vitória' : 'Derrota' ?> — rever"><span><?= $v ? 'V' : 'D' ?></span></a><?php endforeach; ?>
    </div>
    <?php endif; ?>
    <?php if ($fase === 'offseason'): ?>
      <button class="btn-resumo" id="abrirResumo"><i class="bi bi-trophy-fill"></i> <b>Ver o resumo da temporada</b></button>
    <?php endif; ?>
  </div>

  <?php
  /* ── O RITMO DA VIRADA DE FASE ────────────────────────────────────────
     A temporada regular acaba e a tela NÃO pula direto pro chaveamento: ela
     mostra a tabela final — ver onde cada um terminou é o fecho da história
     — e espera o clique em "Ir pros playoffs". Só então o chaveamento toma
     o lugar da tabela. O clique fica gravado no save (playoffs_abertos);
     offseason conta como aberto, senão um save antigo ficava preso no
     portão sem ter mais jogo nenhum pra destrancar. */
  $posTemporada    = in_array($fase, ['playin', 'playoffs', 'offseason'], true);
  $playoffsAbertos = $fase === 'offseason' || Database::meta('playoffs_abertos') === '1';
  $prox = $fase !== 'offseason' ? temporadaProximoAdversario($est['gm_id']) : null;
  ?>

  <?php if ($posTemporada && !$playoffsAbertos): /* ── O PORTÃO ── */ ?>
    <div class="fim-regular">
      <div class="eyebrow">82 jogos depois</div>
      <h2>Temporada regular<br><em>encerrada</em></h2>
      <p class="dim">A tabela final está aí embaixo. Quando quiser, a pós-temporada começa.</p>
      <form method="post"><button class="cta" name="acao" value="abrir_playoffs">
        <span><i class="bi bi-trophy-fill"></i> Ir pros playoffs</span></button></form>
    </div>
  <?php elseif ($fase !== 'offseason' && $prox):
    $euEmCasa = (int) $prox['home_id'] === $est['gm_id'];
    $pre = $euEmCasa ? 'away_' : 'home_';
    $advCor = $cor($prox[$pre . 'cor']);
  ?>
    <div class="sec"><div class="tag"><span><?= $fase === 'regular' ? 'Próximo jogo' : 'Jogo de ' . ($fase === 'playin' ? 'play-in' : 'playoffs') ?></span></div></div>
    <div class="duelo" style="--cA:<?= e($corTime) ?>44;--cB:<?= e($advCor) ?>44">
      <div class="lado">
        <?php if (!empty($meu['logo_url'])): ?><img src="<?= e($meu['logo_url']) ?>" alt="">
        <?php else: ?><span class="es" style="background:<?= e($corTime) ?>"><?= e($meu['abbr']) ?></span><?php endif; ?>
        <b><?= e(temporadaNome($meu)) ?></b>
        <small><?= (int) $meu['wins'] ?>–<?= (int) $meu['losses'] ?> · <?= $euEmCasa ? 'em casa' : 'fora' ?></small>
      </div>
      <span class="vs">VS</span>
      <div class="lado">
        <?php if (!empty($prox[$pre . 'logo'])): ?><img src="<?= e($prox[$pre . 'logo']) ?>" alt="">
        <?php else: ?><span class="es" style="background:<?= e($advCor) ?>"><?= e($prox[$pre . 'abbr']) ?></span><?php endif; ?>
        <b><?= e(temporadaNome($prox, $pre)) ?></b>
        <small><?= (int) $prox[$pre . 'v'] ?>–<?= (int) $prox[$pre . 'd'] ?></small>
      </div>
    </div>
    <?php /* DOIS JEITOS DE VER O MESMO JOGO: acompanhar lance a lance, ou ir
             direto ao box score. O motor roda igual — quem simula não está
             pulando o jogo, está pulando a transmissão. */ ?>
    <div class="linha-btns" style="justify-content:center">
      <form method="post"><button class="cta" name="acao" value="proximo">
        <span><i class="bi bi-play-circle-fill"></i> Assistir</span></button></form>
      <form method="post"><button class="cta cinza" name="acao" value="simular">
        <span><i class="bi bi-fast-forward-fill"></i> Simular</span></button></form>
    </div>
    <div class="linha-btns" style="justify-content:center;margin-top:11px">
      <form method="post"><input type="hidden" name="n" value="5">
        <button class="btn btn-fraco" name="acao" value="varios">Pular 5 jogos</button></form>
      <?php if ($fase === 'regular'): ?>
      <form method="post" onsubmit="return confirm('Simular todos os jogos que faltam da temporada regular? Você vai direto pra tabela final.')">
        <button class="btn btn-fraco" name="acao" value="regular">Simular temporada regular</button></form>
      <?php endif; ?>
    </div>
    <p class="dim" style="text-align:center;margin:10px 0 0;font-size:12px">
      <b>Simular</b> vai direto pro box score do jogo.
      <?php if ($fase === 'regular'): ?><b>Simular temporada regular</b> resolve tudo até a tabela final.<?php endif; ?>
    </p>
  <?php elseif ($fase !== 'offseason'): ?>
    <div class="painel">
      <p class="dim">Sem jogo marcado pro seu time agora.</p>
      <form method="post"><input type="hidden" name="n" value="5">
        <button class="cta" name="acao" value="varios"><span><i class="bi bi-fast-forward-fill"></i> Avançar</span></button></form>
    </div>
  <?php endif; ?>

  <?php /* ── CHAVEAMENTO NO LUGAR DA TABELA, POR CONFERÊNCIA ──────────
           Quando os playoffs abrem, a classificação deixa de ser a pergunta.
           E o chaveamento se lê como a liga pensa: Leste de um lado, Oeste
           do outro, e a final no meio. */
  $chave = ($posTemporada && $playoffsAbertos) ? temporadaChaveamento($est['gm_id']) : null;

  /* Um jogo/série vira HTML num helper só — o chaveamento inteiro é a mesma
     linha repetida, e duplicá-la por conferência dobraria o lugar de errar. */
  $desenhaSerie = function (array $s) use ($cor, $est) {
      $vH = (int) $s['winner_id'] === (int) $s['high_seed_id'];
      $vL = (int) $s['winner_id'] === (int) $s['low_seed_id'];
      $esc = fn($l, $c, $a) => $l ? '<img src="' . e($l) . '" alt="">'
          : '<span class="es" style="background:' . e($cor($c)) . '">' . e($a) . '</span>';
      echo '<div class="serie ' . ($s['eu'] ? 'eu' : '') . '">';
      echo '<span class="t ' . ($vL ? 'out' : '') . '"><span class="seed">' . (int) $s['high_seed'] . '</span>'
         . $esc($s['hlogo'], $s['hcor'], $s['ha']) . e(trim($s['hc'] . ' ' . $s['hn'])) . '</span>';
      echo '<span class="w ' . ($vH ? 'ganha' : '') . '">' . (int) $s['high_wins'] . '</span>'
         . '<span style="color:var(--txt3)">–</span>'
         . '<span class="w ' . ($vL ? 'ganha' : '') . '">' . (int) $s['low_wins'] . '</span>';
      echo '<span class="t ' . ($vH ? 'out' : '') . '" style="justify-content:flex-end">'
         . e(trim($s['ac'] . ' ' . $s['an'])) . $esc($s['alogo'], $s['acor'], $s['aa'])
         . '<span class="seed">' . (int) $s['low_seed'] . '</span></span>';
      echo '</div>';
  };

  if ($chave && ($chave['playin'] || $chave['rodadas'])): ?>
  <div class="sec"><div class="tag ouro"><span>Playoffs</span></div></div>

  <?php if ($chave['playin']):
    $piPorConf = ['E' => [], 'W' => []];
    foreach ($chave['playin'] as $g) $piPorConf[$g['conf'] === 'E' ? 'E' : 'W'][] = $g;
  ?>
    <div class="rodada-rot"><span>Play-in</span></div>
    <div class="duas confs">
      <?php foreach (['E' => 'Leste', 'W' => 'Oeste'] as $cf => $rotC): ?>
      <div>
        <div class="conf-rot <?= $cf === 'E' ? 'leste' : 'oeste' ?>"><span><?= $rotC ?></span></div>
        <?php foreach ($piPorConf[$cf] as $g):
          $eu = in_array($est['gm_id'], [(int) $g['home_id'], (int) $g['away_id']], true);
          $casaGanhou = (int) $g['home_pts'] > (int) $g['away_pts']; ?>
          <div class="serie <?= $eu ? 'eu' : '' ?>">
            <span class="t <?= $g['played'] && $casaGanhou ? 'out' : '' ?>">
              <?php if ($g['alogo']): ?><img src="<?= e($g['alogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($g['acor'])) ?>"><?= e($g['aa']) ?></span><?php endif; ?>
              <?= e(trim($g['ac'] . ' ' . $g['an'])) ?></span>
            <span class="w"><?= $g['played'] ? (int) $g['away_pts'] . '–' . (int) $g['home_pts'] : 'a jogar' ?></span>
            <span class="t <?= $g['played'] && !$casaGanhou ? 'out' : '' ?>" style="justify-content:flex-end">
              <?= e(trim($g['hc'] . ' ' . $g['hn'])) ?>
              <?php if ($g['hlogo']): ?><img src="<?= e($g['hlogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($g['hcor'])) ?>"><?= e($g['ha']) ?></span><?php endif; ?>
            </span>
          </div>
        <?php endforeach; if (!$piPorConf[$cf]): ?><p class="dim" style="font-size:12px">—</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>

  <?php $rotR = [1 => 'Primeira rodada', 2 => 'Semifinais de conferência', 3 => 'Finais de conferência'];
  foreach ($chave['rodadas'] as $r => $lista):
    if ($r === 4) continue;    // a final tem palco próprio, logo abaixo
    $porConf = ['E' => [], 'W' => []];
    foreach ($lista as $s) $porConf[strtoupper((string) $s['conf']) === 'E' ? 'E' : 'W'][] = $s;
  ?>
    <div class="rodada-rot"><span><?= e($rotR[$r] ?? ('Rodada ' . $r)) ?></span></div>
    <div class="duas confs">
      <?php foreach (['E' => 'Leste', 'W' => 'Oeste'] as $cf => $rotC): ?>
      <div>
        <div class="conf-rot <?= $cf === 'E' ? 'leste' : 'oeste' ?>"><span><?= $rotC ?></span></div>
        <?php foreach ($porConf[$cf] as $s) $desenhaSerie($s);
        if (!$porConf[$cf]): ?><p class="dim" style="font-size:12px">Aguardando a rodada anterior.</p><?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endforeach; ?>

  <?php if (!empty($chave['rodadas'][4])): $f = $chave['rodadas'][4][0];
    $vH = (int) $f['winner_id'] === (int) $f['high_seed_id'];
    $vL = (int) $f['winner_id'] === (int) $f['low_seed_id'];
  ?>
    <div class="final-fba <?= $f['eu'] ? 'eu' : '' ?>" style="--cA:<?= e($cor($f['hcor'])) ?>44;--cB:<?= e($cor($f['acor'])) ?>44">
      <div class="eyebrow"><i class="bi bi-trophy-fill"></i> Final da FBA · melhor de 7</div>
      <div class="confronto">
        <div class="lado <?= $vL ? 'out' : '' ?>">
          <?php if ($f['hlogo']): ?><img src="<?= e($f['hlogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($f['hcor'])) ?>"><?= e($f['ha']) ?></span><?php endif; ?>
          <b><?= e(trim($f['hc'] . ' ' . $f['hn'])) ?></b>
        </div>
        <div class="placar-final">
          <span class="<?= $vH ? 'ganha' : '' ?>"><?= (int) $f['high_wins'] ?></span>
          <em>×</em>
          <span class="<?= $vL ? 'ganha' : '' ?>"><?= (int) $f['low_wins'] ?></span>
        </div>
        <div class="lado <?= $vH ? 'out' : '' ?>">
          <?php if ($f['alogo']): ?><img src="<?= e($f['alogo']) ?>" alt=""><?php else: ?><span class="es" style="background:<?= e($cor($f['acor'])) ?>"><?= e($f['aa']) ?></span><?php endif; ?>
          <b><?= e(trim($f['ac'] . ' ' . $f['an'])) ?></b>
        </div>
      </div>
    </div>
  <?php endif; ?>

  <?php else: /* a tabela — da temporada em curso ou a FINAL, no portão */ ?>
  <div class="sec"><div class="tag"><span><?= $posTemporada ? 'Tabela final' : 'Classificação' ?></span></div>
    <span class="sub">1–6 direto<br>7–10 play-in</span></div>
  <div class="painel">
    <div class="duas">
      <?php foreach (['E' => 'Leste', 'W' => 'Oeste'] as $cf => $rot): ?>
      <div>
        <table>
          <thead><tr><th><?= $rot ?></th><th class="num2">V</th><th class="num2">D</th><th class="num2">%</th></tr></thead>
          <tbody>
          <?php /* A conferência INTEIRA: cortar em doze escondia justamente
                   quem está afundado — inclusive, às vezes, o próprio time
                   da pessoa. */
          foreach (League::standings($cf) as $i2 => $t):
            $meuT = (int) $t['id'] === $est['gm_id'];
            $pos = $i2 + 1;
            /* A zona diz mais que o número: verde entra direto, dourado vai
               ao play-in, o resto briga. O corte tracejado no 10º é a linha
               que a temporada inteira tenta cruzar. */
            $zona = $pos <= 6 ? 'po' : ($pos <= 10 ? 'pi' : '');
          ?>
            <tr class="<?= $meuT ? 'eu' : '' ?><?= $pos === 10 ? ' corte' : '' ?>">
              <td><span class="seed <?= $zona ?>"><?= $pos ?></span>
                <?php if (!empty($t['logo_url'])): ?><img class="mini" src="<?= e($t['logo_url']) ?>" alt="" loading="lazy">
                <?php else: ?><span class="mini" style="background:<?= e($cor($t['primary_color'])) ?>"><?= e(mb_substr($t['abbr'], 0, 2)) ?></span><?php endif; ?>
                <?= e(temporadaNome($t)) ?></td>
              <td class="num2"><?= (int) $t['wins'] ?></td>
              <td class="num2"><?= (int) $t['losses'] ?></td>
              <td class="num2"><?= number_format((float) $t['pct'], 3, ',', '') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <?php endif; ?>

  <?php $race = $est['jogados'] >= 5 ? League::awardRace() : null; if ($race): ?>
  <div class="sec"><div class="tag ouro"><span>Corrida pelos prêmios</span></div>
    <span class="sub">pts · reb · ast</span></div>
  <div class="corridas">
    <?php
    $pra = fn($p) => number_format((float) $p['ppg'], 1) . ' · ' . number_format((float) $p['rpg'], 1)
                   . ' · ' . number_format((float) $p['apg'], 1);
    $logos = temporadaLogos();
    // Os apelidos que a liga usa, não os nomes por extenso.
    foreach ([
      'mvp'   => ['MVP',  $pra],
      'mip'   => ['MIP',  fn($p) => '+' . number_format((float) $p['score'], 0) . '% s/ o OVR'],
      'sexto' => ['6H',   $pra],
      'dpoy'  => ['DPOY', fn($p) => number_format((float) $p['bpg'], 1) . ' toc · ' . number_format((float) $p['spg'], 1) . ' rb'],
      'roy'   => ['ROY',  $pra],
    ] as $k => [$rot, $val]): ?>
      <div class="corrida"><b class="rot"><span><?= e($rot) ?></span></b>
        <?php if (!empty($race[$k])): foreach (array_slice($race[$k], 0, 3) as $i2 => $p):
          $lg = $logos[$p['abbr']] ?? null; ?>
          <div class="l"><span class="p"><?= $i2 + 1 ?></span>
            <?php if (!empty($lg['logo'])): ?><img class="mini" src="<?= e($lg['logo']) ?>" alt="<?= e($p['abbr']) ?>" title="<?= e($p['abbr']) ?>" loading="lazy" style="margin:0">
            <?php else: ?><span class="mini" style="margin:0;background:<?= e($cor($lg['cor'] ?? '')) ?>"><?= e(mb_substr($p['abbr'], 0, 2)) ?></span><?php endif; ?>
            <span class="col">
              <span class="nm"><?= e($p['name']) ?></span>
              <span class="vl"><?= e($val($p)) ?></span>
            </span></div>
        <?php endforeach; else: ?>
          <div class="l" style="color:var(--txt3)">Poucos jogos ainda.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php /* O QUE CADA ETAPA PAGA NÃO OCUPA MAIS UM PAINEL: o multiplicador
           está no topo e a conta fechada sai no resumo de fim de temporada,
           que é quando ela vira notícia. */ ?>

  <div class="sec"><div class="tag" style="background:linear-gradient(135deg,#27334f,#1a2236);box-shadow:none"><span>Recomeçar</span></div></div>
  <div class="painel">
    <p class="dim" style="margin-top:0">Apagar esta temporada e escolher outro time. O que já foi pago em moeda fica —
      mas as etapas que faltavam somem junto.</p>
    <form method="post" onsubmit="return confirm('Apagar esta temporada e começar outra? Não dá pra desfazer.')">
      <button class="btn btn-fraco" name="acao" value="apagar"><i class="bi bi-trash3"></i> Apagar e escolher outro time</button>
    </form>
  </div>

  <?php /* O BOX SCORE DE QUEM MANDOU SIMULAR. Mesmo pop-up da transmissão —
           quem simulou não fica sem o jogo, fica sem a narração. */
  if ($verBox && $trans):
    $boxJogo = $trans; $boxAbreSozinho = true; $boxVoltar = '/games/games/temporada.php';
    include __DIR__ . '/../core/temporada_box.php';
  endif; ?>

  <?php /* ═════ O RESUMO DA TEMPORADA ═════
           Abre sozinho quando a última série acaba, e só uma vez — depois
           fica o botão lá em cima pra rever. É a hora em que campeão, vice,
           até onde você foi, os prêmios e a conta das moedas são UMA
           pergunta só; durante a temporada eram cinco telas separadas. */
  $resumo = temporadaResumo($est['gm_id']);
  if ($resumo):
    $abrirSozinho = !temporadaResumoVisto();
    if ($abrirSozinho) temporadaMarcarResumoVisto();
  ?>
  <div class="modal-fundo<?= $abrirSozinho ? ' aberto' : '' ?>" id="modalResumo">
    <div class="modal">
      <button class="modal-x" id="fecharResumo" aria-label="Fechar"><i class="bi bi-x-lg"></i></button>

      <div class="modal-topo" style="--c:<?= e($cor($resumo['campeao']['cor'] ?? '', '#f5c842')) ?>66">
        <div class="tacinha"><i class="bi bi-trophy-fill"></i></div>
        <div class="eyebrow"><?= e($est['liga']) ?> · Temporada encerrada</div>
        <?php if ($resumo['campeao']): ?>
          <h3><?= e($resumo['campeao']['nome']) ?></h3>
          <p class="dim" style="margin:4px 0 0">Campeão da FBA — venceu a final por <?= e($resumo['campeao']['serie']) ?></p>
          <?php if ($resumo['vice']): ?>
            <p class="dim" style="margin:3px 0 0;font-size:12px">Vice: <?= e($resumo['vice']['nome']) ?></p>
          <?php endif; ?>
        <?php else: ?>
          <h3>Temporada encerrada</h3>
        <?php endif; ?>
      </div>

      <div class="modal-corpo">
        <div class="meu-fim <?= $resumo['eu_campeao'] ? 'campeao' : '' ?>">
          <?php if (!empty($resumo['meu']['logo_url'])): ?>
            <img src="<?= e($resumo['meu']['logo_url']) ?>" alt="">
          <?php else: ?>
            <span class="es" style="background:<?= e($corTime) ?>"><?= e($resumo['meu']['abbr']) ?></span>
          <?php endif; ?>
          <div>
            <b><?= e(temporadaNome($resumo['meu'])) ?></b>
            <small><?= (int) $resumo['meu']['wins'] ?>–<?= (int) $resumo['meu']['losses'] ?> na temporada regular</small>
          </div>
          <span class="selo"><?= e($resumo['meu_fim']) ?></span>
        </div>

        <?php if ($resumo['premios']): ?>
        <b class="bloco-rot">Prêmios da temporada</b>
        <?php foreach ($resumo['premios'] as $p): ?>
          <div class="premio">
            <span class="pr"><?= e($p['premio']) ?></span>
            <?php if (!empty($p['logo'])): ?><img class="mini" src="<?= e($p['logo']) ?>" alt="<?= e($p['time']) ?>" loading="lazy" style="margin:2px 0 0">
            <?php else: ?><span class="mini" style="margin:2px 0 0;background:<?= e($cor($p['cor'] ?? '')) ?>"><?= e(mb_substr($p['time'], 0, 2)) ?></span><?php endif; ?>
            <span class="pn"><b><?= e($p['nome']) ?></b> <small><?= e($p['time']) ?></small><br>
              <small class="dim"><?= e($p['linha']) ?></small></span>
          </div>
        <?php endforeach; ?>
        <?php endif; ?>

        <b class="bloco-rot">Moedas da campanha <span style="color:var(--txt3);font-weight:600">· <?= TemporadaPremios::multiplicador() ?>x por ter pegado o <?= $est['rank'] ?>º elenco</span></b>
        <?php foreach ($resumo['moedas'] as $m): ?>
          <div class="etapa <?= $m['ganhou'] ? 'feita' : 'falta' ?>">
            <i class="bi bi-<?= $m['ganhou'] ? 'check-circle-fill' : 'x-circle' ?>"></i>
            <span><?= e($m['rotulo']) ?></span>
            <span class="v"><?= $m['ganhou'] ? '+' . number_format($m['valor'], 0, ',', '.') : '—' ?></span>
          </div>
        <?php endforeach; ?>
        <div class="total">
          <span>Total da temporada</span>
          <b><i class="bi bi-coin"></i> <?= number_format($resumo['total'], 0, ',', '.') ?></b>
        </div>

        <?php /* A IMAGEM DE COMPARTILHAR. O resumo desenhado num canvas
                 1080×1350, pronto pro grupo: campeão, sua campanha, os cinco
                 prêmios e as moedas. "Copiar" põe o PNG na área de
                 transferência; onde o navegador não deixa (Safari antigo,
                 http), sobra o Baixar. Os escudos entram com
                 crossOrigin=anonymous — os da FBA são da própria casa, e os
                 da ROOKIE (cdn da NBA) mandam CORS; quem falhar vira um
                 quadrado com a sigla, e o canvas nunca contamina. */ ?>
        <b class="bloco-rot">Compartilhar</b>
        <canvas id="cardTemporada" width="1080" height="1350"></canvas>
        <div class="linha-btns" style="margin-top:10px">
          <button class="btn btn-fba" id="btCopiarCard" style="flex:1"><i class="bi bi-clipboard"></i> Copiar imagem</button>
          <button class="btn btn-fraco" id="btBaixarCard" style="flex:1"><i class="bi bi-download"></i> Baixar</button>
        </div>

        <div class="linha-btns" style="margin-top:16px">
          <button class="btn" id="fecharResumo2" style="flex:1"><i class="bi bi-check-lg"></i> Fechar</button>
          <form method="post" style="flex:1" onsubmit="return confirm('Começar outra temporada com outro time?')">
            <button class="btn btn-fraco" name="acao" value="apagar" style="width:100%">Nova temporada</button>
          </form>
        </div>
      </div>
    </div>
  </div>
  <script>
    const mr = document.getElementById('modalResumo');
    const fechar = () => mr.classList.remove('aberto');
    document.getElementById('fecharResumo').onclick = fechar;
    document.getElementById('fecharResumo2').onclick = fechar;
    mr.onclick = ev => { if (ev.target === mr) fechar(); };
    document.addEventListener('keydown', ev => { if (ev.key === 'Escape') fechar(); });
    document.getElementById('abrirResumo')?.addEventListener('click', () => mr.classList.add('aberto'));

  /* ═════ A IMAGEM DO RESUMO ═════
     O cartão 1080×1350 desenhado no canvas com o mesmo pacote gráfico da
     página: quadra sob spotlight, placas inclinadas, Oswald nos números.
     Tudo client-side — nenhum GD no servidor, nenhuma dependência. */
  const CARD = <?= json_encode([
      'liga'    => $est['liga'],
      'campeao' => $resumo['campeao'],
      'vice'    => $resumo['vice'] ? $resumo['vice']['nome'] : null,
      'meu'     => [
          'nome' => temporadaNome($resumo['meu']),
          'abbr' => (string) $resumo['meu']['abbr'],
          'logo' => (string) ($resumo['meu']['logo_url'] ?? ''),
          'cor'  => $corTime,
          'v'    => (int) $resumo['meu']['wins'],
          'd'    => (int) $resumo['meu']['losses'],
          'fim'  => $resumo['meu_fim'],
          'sou'  => (bool) $resumo['eu_campeao'],
      ],
      'premios' => $resumo['premios'],
      'total'   => (int) $resumo['total'],
      'mult'    => TemporadaPremios::multiplicador(),
  ], JSON_UNESCAPED_UNICODE) ?>;

  const cv = document.getElementById('cardTemporada');
  const cx = cv.getContext('2d');
  const W = cv.width, H = cv.height;

  const carrega = src => new Promise(res => {
    if (!src) return res(null);
    const im = new Image();
    /* anonymous é o que deixa o canvas exportável: escudo da casa passa
       direto, o cdn da NBA (ROOKIE) manda CORS, e quem recusar simplesmente
       não carrega — cai no quadrado com a sigla, sem contaminar o canvas. */
    im.crossOrigin = 'anonymous';
    im.onload = () => res(im);
    im.onerror = () => res(null);
    im.src = src.startsWith('http') ? src : location.origin + src;
  });

  function placa(x, y, w, h, fill) {
    cx.save(); cx.translate(x, y); cx.transform(1, 0, -0.18, 1, 0, 0);
    cx.fillStyle = fill; cx.fillRect(0, 0, w, h); cx.restore();
  }
  function caixa(x, y, w, h, r) {
    cx.beginPath();
    cx.moveTo(x + r, y); cx.arcTo(x + w, y, x + w, y + h, r); cx.arcTo(x + w, y + h, x, y + h, r);
    cx.arcTo(x, y + h, x, y, r); cx.arcTo(x, y, x + w, y, r); cx.closePath();
  }
  function escudo(im, abbr, corT, x, y, s) {
    cx.save(); caixa(x, y, s, s, s * .22); cx.clip();
    if (im) { cx.drawImage(im, x, y, s, s); }
    else {
      cx.fillStyle = corT || '#334155'; cx.fillRect(x, y, s, s);
      cx.fillStyle = '#fff'; cx.font = '900 ' + (s * .34) + 'px Inter';
      cx.textAlign = 'center'; cx.textBaseline = 'middle';
      cx.fillText((abbr || '?').slice(0, 3), x + s / 2, y + s / 2 + s * .02);
    }
    cx.restore();
    cx.save(); caixa(x, y, s, s, s * .22); cx.strokeStyle = 'rgba(255,255,255,.18)'; cx.lineWidth = 2; cx.stroke(); cx.restore();
  }
  function brilho(x, y, r, corHex, alfa) {
    const g = cx.createRadialGradient(x, y, 0, x, y, r);
    g.addColorStop(0, corHex + Math.round(alfa * 255).toString(16).padStart(2, '0'));
    g.addColorStop(1, corHex + '00');
    cx.fillStyle = g; cx.fillRect(x - r, y - r, r * 2, r * 2);
  }

  async function desenhaCard() {
    try { await document.fonts.load('italic 700 70px "Barlow Condensed"');
          await document.fonts.load('600 80px Oswald');
          await document.fonts.load('800 28px Inter'); } catch (e) {}

    const camp = CARD.campeao;
    const [imCamp, imMeu] = await Promise.all([
      carrega(camp ? camp.logo : ''), carrega(CARD.meu.logo),
    ]);
    const imPremios = await Promise.all(CARD.premios.map(p => carrega(p.logo)));

    // fundo: a quadra sob o spotlight
    cx.fillStyle = '#05070d'; cx.fillRect(0, 0, W, H);
    brilho(W / 2, -140, 720, '#fc0025', .18);
    brilho(W / 2, -140, 430, '#f5c842', .10);
    cx.strokeStyle = 'rgba(255,255,255,.045)'; cx.lineWidth = 2;
    for (const r of [240, 380, 540]) { cx.beginPath(); cx.arc(W / 2, -60, r, 0, Math.PI * 2); cx.stroke(); }
    cx.strokeStyle = 'rgba(255,255,255,.012)'; cx.lineWidth = 1;
    for (let x = -H; x < W + H; x += 54) {
      cx.beginPath(); cx.moveTo(x, 0); cx.lineTo(x + H * .47, H); cx.stroke();
    }

    // cabeçalho
    placa(64, 58, 108, 52, '#fc0025');
    cx.fillStyle = '#fff'; cx.textAlign = 'left'; cx.textBaseline = 'alphabetic';
    cx.font = 'italic 700 34px "Barlow Condensed"'; cx.fillText('FBA', 88, 95);
    cx.font = 'italic 700 40px "Barlow Condensed"'; cx.fillText('MINHA TEMPORADA', 192, 97);
    cx.textAlign = 'right'; cx.fillStyle = '#95a1bd';
    cx.font = 'italic 700 36px "Barlow Condensed"'; cx.fillText(CARD.liga, W - 64, 97);

    // campeão
    cx.textAlign = 'center';
    cx.fillStyle = '#f5c842'; cx.font = '800 25px Inter';
    cx.fillText('C A M P E Ã O   D A   F B A', W / 2, 190);
    if (camp) {
      brilho(W / 2, 330, 260, '#f5c842', .16);
      escudo(imCamp, camp.nome, camp.cor, W / 2 - 95, 230, 190);
      cx.fillStyle = '#fff'; cx.font = 'italic 700 76px "Barlow Condensed"';
      cx.fillText(camp.nome.toUpperCase(), W / 2, 520, W - 140);
      cx.fillStyle = '#95a1bd'; cx.font = '600 27px Inter';
      let linhaV = 'venceu a final por ' + camp.serie + (CARD.vice ? '  ·  vice: ' + CARD.vice : '');
      cx.fillText(linhaV, W / 2, 566, W - 120);
    }

    // divisor
    cx.strokeStyle = 'rgba(255,255,255,.1)'; cx.lineWidth = 2;
    cx.beginPath(); cx.moveTo(72, 614); cx.lineTo(W - 72, 614); cx.stroke();

    // a minha campanha
    escudo(imMeu, CARD.meu.abbr, CARD.meu.cor, 72, 650, 104);
    cx.textAlign = 'left';
    cx.fillStyle = '#5b688a'; cx.font = '800 20px Inter'; cx.fillText('S U A   C A M P A N H A', 200, 678);
    cx.fillStyle = '#fff'; cx.font = 'italic 700 48px "Barlow Condensed"';
    cx.fillText(CARD.meu.nome.toUpperCase(), 200, 727, W - 290);
    cx.fillStyle = CARD.meu.sou ? '#f5c842' : '#95a1bd'; cx.font = '700 26px Inter';
    cx.fillText(CARD.meu.v + '–' + CARD.meu.d + '  ·  ' + CARD.meu.fim.toUpperCase(), 200, 766, W - 290);

    // prêmios
    placa(64, 816, 190, 46, '#f5c842');
    cx.fillStyle = '#201703'; cx.font = 'italic 700 29px "Barlow Condensed"'; cx.fillText('PRÊMIOS', 92, 849);
    let y = 905;
    for (let k = 0; k < CARD.premios.length; k++) {
      const p = CARD.premios[k];
      cx.fillStyle = '#f5c842'; cx.font = 'italic 700 30px "Barlow Condensed"';
      cx.fillText(p.premio, 72, y + 12);
      escudo(imPremios[k], p.time, p.cor, 182, y - 22, 48);
      cx.fillStyle = '#fff'; cx.font = '700 30px Inter';
      cx.fillText(p.nome, 248, y + 10, 560);
      cx.fillStyle = '#5b688a'; cx.font = '600 22px Inter';
      cx.textAlign = 'right'; cx.fillText(p.linha, W - 72, y + 9, 230);
      cx.textAlign = 'left';
      if (k < CARD.premios.length - 1) {
        cx.strokeStyle = 'rgba(255,255,255,.055)'; cx.lineWidth = 1;
        cx.beginPath(); cx.moveTo(72, y + 38); cx.lineTo(W - 72, y + 38); cx.stroke();
      }
      y += 76;
    }

    // moedas
    const yM = y + 26;
    caixa(64, yM, W - 128, 108, 16);
    cx.fillStyle = 'rgba(245,200,66,.08)'; cx.fill();
    cx.strokeStyle = 'rgba(245,200,66,.35)'; cx.lineWidth = 2; cx.stroke();
    cx.fillStyle = '#95a1bd'; cx.font = '800 22px Inter';
    cx.fillText('MOEDAS DA CAMPANHA  ·  ' + String(CARD.mult).replace('.', ',') + 'x', 100, yM + 64);
    cx.textAlign = 'right';
    cx.fillStyle = '#f5c842'; cx.font = '600 62px Oswald';
    cx.fillText('+' + CARD.total.toLocaleString('pt-BR'), W - 100, yM + 76);
    cx.textAlign = 'left';

    // rodapé
    cx.fillStyle = '#5b688a'; cx.font = '600 22px Inter'; cx.textAlign = 'center';
    cx.fillText('fbabrasil.com.br  ·  FBA Games', W / 2, H - 46);
    cx.textAlign = 'left';
  }
  desenhaCard();

  const btCopiar = document.getElementById('btCopiarCard');
  const btBaixar = document.getElementById('btBaixarCard');
  btCopiar.onclick = () => cv.toBlob(async b => {
    try {
      await navigator.clipboard.write([new ClipboardItem({ 'image/png': b })]);
      btCopiar.innerHTML = '<i class="bi bi-check-lg"></i> Copiada!';
      setTimeout(() => btCopiar.innerHTML = '<i class="bi bi-clipboard"></i> Copiar imagem', 2200);
    } catch (e) {
      alert('O navegador não deixou copiar direto — usa o Baixar que dá no mesmo.');
    }
  }, 'image/png');
  btBaixar.onclick = () => cv.toBlob(b => {
    const a = document.createElement('a');
    a.href = URL.createObjectURL(b);
    a.download = 'minha-temporada-' + CARD.liga.toLowerCase() + '.png';
    a.click();
    setTimeout(() => URL.revokeObjectURL(a.href), 4000);
  }, 'image/png');
  </script>
  <?php endif; ?>

<?php endif; ?>
</div>
</body>
</html>
