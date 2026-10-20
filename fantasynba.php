<?php
/**
 * ── FANTASY NBA ──────────────────────────────────────────────────────
 *
 * A casa do fantasy: escalar, ver as rodadas, as ligas e o ranking. A conta
 * mora em backend/fantasy_nba.php; aqui é só a tela.
 *
 * ── A TELA NÃO SEGUE O PADRÃO DO SITE, E É DE PROPÓSITO ──────────────
 *
 * "Não precisa seguir padrões do site, mas criar uma página de fantasy em si
 * que fique bem legal e a pessoa acesse todo dia" (20/10/2026). Então: sem
 * menu lateral, sem hero de dashboard, cor própria (o laranja da bola, não o
 * vermelho da FBA) e tudo em quatro abas que cabem na largura de um celular.
 *
 * QUATRO ABAS, QUATRO PERGUNTAS:
 *   escalar  — "o que eu faço agora?"   (a rodada aberta, o mercado, o time)
 *   rodadas  — "como eu fui?"           (histórico, e o time dos outros)
 *   ligas    — "como vou contra eles?"  (as divisões da FBA e as de amigos)
 *   ranking  — "quem manda no jogo?"    (o acumulado de todas as rodadas)
 *
 * A aba vive na URL (?aba=), e não em JavaScript: assim o link de uma rodada
 * específica pode ser colado no grupo e abre onde deve.
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/fantasy_nba.php';
requireAuth();

$user    = getUserSession();
$user_id = (int)$user['id'];
$pdo     = db();
fnbaTabelas($pdo);
fnbaTabelasLigas($pdo);

$pdo->prepare('INSERT IGNORE INTO games_usuarios (id, nome, pontos) VALUES (?,?,0)')
    ->execute([$user_id, $user['name'] ?? 'GM']);
$moedas = (int)($pdo->query('SELECT pontos FROM games_usuarios WHERE id = ' . $user_id)->fetchColumn() ?: 0);

$erros = [];
$ok    = (string)($_GET['ok'] ?? '');
$aba   = (string)($_GET['aba'] ?? 'escalar');
/* LIGA E RANKING VIRARAM UMA ABA SÓ (20/10/2026): eram a mesma pergunta
   feita duas vezes — "quem está na frente" — e a pessoa tinha que adivinhar
   em qual das duas procurar a divisão dela. O recorte agora é um filtro
   dentro de Ranking. `ligas` continua aceito pra não quebrar link antigo. */
if ($aba === 'ligas') $aba = 'ranking';
if (!in_array($aba, ['escalar', 'rodadas', 'ranking'], true)) $aba = 'escalar';

/* ── OS POSTS ───────────────────────────────────────────────────────── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'escalar') {
        $rid = (int)($_POST['rodada'] ?? 0);
        $r = fnbaEscalar($pdo, $rid, $user_id,
                         array_map('intval', (array)($_POST['jogadores'] ?? [])),
                         (int)($_POST['capitao'] ?? 0));
        if ($r['ok']) { header('Location: /fantasynba.php?r=' . $rid . '&ok=escalado'); exit; }
        $erros = $r['erros'];
    }

    if ($acao === 'criar_liga') {
        $r = fnbaCriarLiga($pdo, $user_id, (string)($_POST['nome'] ?? ''));
        if ($r['ok']) { header('Location: /fantasynba.php?aba=ranking&ok=criada'); exit; }
        $erros[] = $r['erro']; $aba = 'ligas';
    }

    if ($acao === 'entrar_liga') {
        $r = fnbaEntrarNaLiga($pdo, $user_id, (string)($_POST['codigo'] ?? ''));
        if ($r['ok']) { header('Location: /fantasynba.php?aba=ranking&ok=entrou'); exit; }
        $erros[] = $r['erro']; $aba = 'ligas';
    }

    if ($acao === 'sair_liga') {
        fnbaSairDaLiga($pdo, $user_id, (int)($_POST['liga'] ?? 0));
        header('Location: /fantasynba.php?aba=ranking'); exit;
    }
}

$avisos = ['escalado' => 'Escalação salva. Boa sorte!', 'criada' => 'Liga criada — mande o código pra galera.',
           'entrou' => 'Pronto, você entrou na liga.'];
$msgOk = $avisos[$ok] ?? '';

/* ── A RODADA EM FOCO ───────────────────────────────────────────────── */
$proximas = fnbaProximasRodadas($pdo, 6);
$rodada   = null;
if (!empty($_GET['r'])) $rodada = fnbaRodadaPorId($pdo, (int)$_GET['r']);
if (!$rodada) $rodada = fnbaRodadaDoDia($pdo) ?: ($proximas[0] ?? null);

$aberta    = $rodada ? fnbaAberta($rodada) : false;
$escalacao = $rodada ? fnbaEscalacao($pdo, (int)$rodada['id'], $user_id) : [];
$mercado   = ($aba === 'escalar' && $rodada) ? fnbaMercado($pdo, (int)$rodada['id']) : [];

$jogosDaRodada = [];
if ($rodada) {
    $st = $pdo->prepare("SELECT g.*, tc.sigla AS casa, tf.sigla AS fora, tc.logo AS logo_casa, tf.logo AS logo_fora
                           FROM fnba_jogos g
                      LEFT JOIN fnba_times tc ON tc.id = g.casa_id
                      LEFT JOIN fnba_times tf ON tf.id = g.fora_id
                          WHERE g.rodada_id = ? ORDER BY g.inicio");
    $st->execute([(int)$rodada['id']]);
    $jogosDaRodada = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/* ── O QUE CADA ABA PRECISA ─────────────────────────────────────────── */
$minhasRodadas = ($aba === 'rodadas') ? fnbaMinhasRodadas($pdo, $user_id, 20) : [];
$verRodada     = null; $verQuem = []; $verTime = []; $verDe = 0;
if ($aba === 'rodadas' && !empty($_GET['ver'])) {
    $verRodada = fnbaRodadaPorId($pdo, (int)$_GET['ver']);
    if ($verRodada) {
        $verQuem = fnbaQuemEscalou($pdo, (int)$verRodada['id']);
        $verDe   = (int)($_GET['de'] ?? $user_id);
        $verTime = fnbaEscalacaoDe($pdo, (int)$verRodada['id'], $verDe) ?? [];
    }
}

$minhasLigas = ($aba === 'ranking') ? fnbaMinhasLigas($pdo, $user_id) : [];
$minhaDiv    = fnbaDivisaoDoUsuario($pdo, $user_id);

$rankAba = 'semana'; $rankLista = []; $rankTitulo = ''; $rankSub = ''; $campeoes = [];
$semana  = null;
if ($aba === 'ranking') {
    /* A SEMANA É O PADRÃO, e não o geral: é ela que paga FBA Points e é por
       ela que a pessoa volta. O geral vira consulta, não manchete. */
    $rankAba = (string)($_GET['f'] ?? 'semana');
    $semana  = fnbaSemanaDe($pdo);

    if ($rankAba === 'campeoes') {
        $campeoes   = fnbaCampeoesDaSemana($pdo, 40);
        $rankTitulo = 'Campeões da semana';
    } elseif (str_starts_with($rankAba, 'liga:')) {
        $lid = (int)substr($rankAba, 5);
        $rankLista = fnbaRankingGeral($pdo, null, $lid);
        $st = $pdo->prepare('SELECT nome FROM fnba_ligas WHERE id = ?'); $st->execute([$lid]);
        $rankTitulo = (string)$st->fetchColumn();
        $rankSub = 'soma de todas as rodadas';
    } elseif (in_array(strtoupper($rankAba), FNBA_DIVISOES, true)) {
        $rankLista  = fnbaRankingGeral($pdo, strtoupper($rankAba));
        $rankTitulo = 'Divisão ' . strtoupper($rankAba);
        $rankSub = 'soma de todas as rodadas';
    } elseif ($rankAba === 'geral') {
        $rankLista  = fnbaRankingGeral($pdo);
        $rankTitulo = 'Geral';
        $rankSub = 'soma de todas as rodadas';
    } else {
        $rankAba    = 'semana';
        $rankLista  = fnbaTabelaDaSemana($pdo, $semana['inicio'], $semana['fim']);
        $rankTitulo = 'Semana ' . (new DateTimeImmutable($semana['inicio']))->format('d/m')
                    . ' a ' . (new DateTimeImmutable($semana['fim']))->format('d/m');
        $rankSub = 'vale FBA Points — fecha domingo à meia-noite';
    }
}

$e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$escalados = array_column($escalacao, 'jogador_id');
$capAtual  = 0;
foreach ($escalacao as $x) if ((int)$x['capitao'] === 1) $capAtual = (int)$x['jogador_id'];
$diaSem = ['Sun'=>'Dom','Mon'=>'Seg','Tue'=>'Ter','Wed'=>'Qua','Thu'=>'Qui','Fri'=>'Sex','Sat'=>'Sáb'];
$url = fn(array $p) => '/fantasynba.php?' . http_build_query($p);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
<meta name="theme-color" content="#0b0c10">
<title>Fantasy NBA</title>
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Barlow+Condensed:wght@600;700;800&family=Inter:wght@400;500;600;700;800;900&display=swap" rel="stylesheet">
<style>
/* ══ A CARA DO JOGO ══════════════════════════════════════════════════
   Laranja de bola e azul de quadra, não o vermelho da FBA: aqui dentro a
   liga é a NBA. O Barlow Condensed nos números é o que dá cara de placar. */
:root{
  --lar:#f97316; --lar-2:#fb923c; --azul:#3b82f6; --verde:#22c55e; --ouro:#fbbf24;
  --bg:#0b0c10; --sup:#13141a; --sup-2:#1a1c24; --sup-3:#23252f;
  --bd:rgba(255,255,255,.07); --bd-2:rgba(255,255,255,.13);
  --tx:#f4f5f7; --tx-2:#9ca0ab; --tx-3:#6b6f7a;
  --r:16px; --r-s:11px; --t:180ms; --ease:cubic-bezier(.2,.8,.2,1);
  --num:'Barlow Condensed',system-ui,sans-serif;
}
:root[data-theme="light"]{
  --bg:#f4f6fa; --sup:#fff; --sup-2:#f1f4f9; --sup-3:#e6eaf2;
  --bd:#e4e8f0; --bd-2:#d4dae6; --tx:#10131a; --tx-2:#5a6072; --tx-3:#767c8c;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--tx);font-family:Inter,system-ui,sans-serif;
  -webkit-font-smoothing:antialiased}
a{color:inherit}
.num{font-family:var(--num);font-weight:800;letter-spacing:.3px}

/* ── Topo ───────────────────────────────────────────────────────── */
.topo{position:sticky;top:0;z-index:50;background:color-mix(in srgb,var(--bg) 88%,transparent);
  backdrop-filter:blur(12px);border-bottom:1px solid var(--bd)}
.topo-in{max-width:1240px;margin:0 auto;padding:11px 16px;display:flex;align-items:center;gap:12px}
.voltar{display:grid;place-items:center;width:34px;height:34px;border-radius:10px;flex:none;
  background:var(--sup-2);border:1px solid var(--bd-2);color:var(--tx-2);text-decoration:none}
.voltar:hover{color:var(--tx)}
.marca{font-family:var(--num);font-size:21px;font-weight:800;letter-spacing:.5px}
.marca b{color:var(--lar)}
.saldo{margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:999px;
  background:linear-gradient(135deg,rgba(251,191,36,.16),rgba(249,115,22,.1));
  border:1px solid rgba(251,191,36,.3);font-size:13px;font-weight:700;color:var(--ouro);white-space:nowrap}

/* ── Abas ───────────────────────────────────────────────────────── */
.abas{max-width:1240px;margin:0 auto;padding:0 16px;display:flex;gap:4px;overflow-x:auto;
  scrollbar-width:none}
.abas::-webkit-scrollbar{display:none}
.abas a{flex:none;padding:13px 16px;font-size:13.5px;font-weight:700;color:var(--tx-3);
  text-decoration:none;border-bottom:2px solid transparent;white-space:nowrap;display:flex;align-items:center;gap:7px}
.abas a:hover{color:var(--tx-2)}
.abas a.on{color:var(--lar);border-bottom-color:var(--lar)}

.wrap{max-width:1240px;margin:0 auto;padding:18px 16px 60px}
.aviso{border-radius:12px;padding:12px 15px;font-size:13.5px;margin-bottom:14px;border:1px solid}
.aviso.ok{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.3);color:#86efac}
.aviso.err{background:rgba(239,68,68,.1);border-color:rgba(239,68,68,.3);color:#fca5a5}
.aviso.nd{background:var(--sup);border-color:var(--bd-2);color:var(--tx-2)}

.card{background:var(--sup);border:1px solid var(--bd);border-radius:var(--r);padding:16px;min-width:0}
.ct{font-size:12px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;color:var(--tx-2);
  margin-bottom:13px;display:flex;align-items:center;gap:8px}
.ct i{color:var(--lar);font-size:14px}
.ct .dir{margin-left:auto;font-size:11px;font-weight:600;letter-spacing:0;text-transform:none;color:var(--tx-3)}

/* ── A faixa da rodada ──────────────────────────────────────────── */
.rodada{background:linear-gradient(135deg,rgba(249,115,22,.12),rgba(59,130,246,.07));
  border:1px solid rgba(249,115,22,.22);border-radius:var(--r);padding:15px 18px;margin-bottom:14px}
.rodada .linha1{display:flex;align-items:center;gap:14px;flex-wrap:wrap}
.rodada .data{font-family:var(--num);font-size:25px;font-weight:800;line-height:1}
.rodada .qt{font-size:12.5px;color:var(--tx-2)}
.prazo{margin-left:auto;display:inline-flex;align-items:center;gap:7px;padding:8px 15px;border-radius:999px;
  font-weight:700;font-size:13px;white-space:nowrap;
  background:rgba(34,197,94,.14);border:1px solid rgba(34,197,94,.32);color:#86efac}
.prazo.fec{background:rgba(239,68,68,.12);border-color:rgba(239,68,68,.3);color:#fca5a5}
.jogos{display:flex;gap:6px;flex-wrap:wrap;margin-top:11px}
.jg{display:inline-flex;align-items:center;gap:5px;padding:5px 9px;border-radius:8px;background:rgba(0,0,0,.22);
  border:1px solid var(--bd);font-size:11.5px;color:var(--tx-2)}
:root[data-theme="light"] .jg{background:var(--sup-2)}
.jg img{width:17px;height:17px;object-fit:contain}
.jg b{color:var(--tx)}
.jg.fim{opacity:.6}

/* O seletor de dia */
.dias{display:flex;gap:7px;overflow-x:auto;padding-bottom:4px;margin-bottom:14px;scrollbar-width:none}
.dias::-webkit-scrollbar{display:none}
.dia{flex:none;min-width:68px;padding:9px 12px;border-radius:12px;background:var(--sup);
  border:1px solid var(--bd);text-decoration:none;text-align:center}
.dia span{display:block;font-size:10.5px;color:var(--tx-3);text-transform:uppercase;font-weight:700}
.dia b{display:block;font-family:var(--num);font-size:19px;color:var(--tx-2);line-height:1.15}
.dia.on{background:rgba(249,115,22,.14);border-color:rgba(249,115,22,.4)}
.dia.on b,.dia.on span{color:var(--lar)}
.dia.lock{opacity:.5}

/* ── Grade escalar ──────────────────────────────────────────────── */
.grade{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:14px;align-items:start}
/* minmax(0,1fr) e nao 1fr: `1fr` vale `minmax(auto,1fr)`, e o `auto` se
   recusa a encolher abaixo do min-content do filho — com um nome de
   jogador comprido na lista, a coluna ficava maior que a tela e a
   pagina inteira rolava de lado no celular. */
@media(max-width:1000px){ .grade{grid-template-columns:minmax(0,1fr)} }

.filtros{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:11px}
.busca{flex:1;min-width:140px;padding:11px 14px;border-radius:11px;background:var(--sup-2);
  border:1px solid var(--bd-2);color:var(--tx);font-size:13.5px;font-family:inherit}
.busca::placeholder{color:var(--tx-3)}
.chip{padding:10px 15px;border-radius:11px;background:var(--sup-2);border:1px solid var(--bd-2);
  color:var(--tx-2);cursor:pointer;font-size:12.5px;font-weight:700;font-family:inherit}
.chip.on{background:rgba(249,115,22,.16);border-color:rgba(249,115,22,.42);color:var(--lar-2)}

.lista{max-height:min(60vh,600px);overflow-y:auto;display:flex;flex-direction:column;gap:5px;
  -webkit-overflow-scrolling:touch;padding-right:3px}
.lin{display:flex;align-items:center;gap:10px;padding:8px 10px;border-radius:11px;background:var(--sup-2);
  border:1px solid transparent;cursor:pointer;transition:border-color var(--t)}
.lin:hover{border-color:var(--bd-2)}
.lin.in{background:rgba(34,197,94,.1);border-color:rgba(34,197,94,.32)}
.lin.off{opacity:.38}
.lin img{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--sup-3);flex:none}
.lin .i{flex:1;min-width:0}
.lin .i b{display:block;font-size:13.5px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.lin .i span{font-size:11.5px;color:var(--tx-2)}
.tag{font-size:10px;font-weight:800;padding:3px 7px;border-radius:6px;background:var(--sup-3);color:var(--tx-2);flex:none}
.tag.G{background:rgba(59,130,246,.2);color:#93c5fd}
.tag.F{background:rgba(34,197,94,.2);color:#86efac}
.tag.C{background:rgba(168,85,247,.2);color:#d8b4fe}
.vlr{font-family:var(--num);font-size:17px;color:var(--ouro);flex:none;width:52px;text-align:right}

/* ── A QUADRA ────────────────────────────────────────────────────────
   O time montado em cima do desenho da quadra, e não só numa lista: é o
   que faz a escalação parecer escalação. As cinco vagas ficam onde a
   posição joga — os dois armadores no perímetro, os alas nas pontas, o
   pivô no garrafão —, então dá pra ver o time inteiro de relance.

   A quadra é meia quadra, vista de cima, desenhada em CSS puro: nenhuma
   imagem pra carregar e ela acompanha o tema claro sem segunda versão. */
.quadra{position:relative;width:100%;aspect-ratio:1/1.08;border-radius:14px;margin-bottom:12px;
  background:linear-gradient(170deg,#1d1710,#241c12);
  border:2px solid rgba(255,255,255,.14);overflow:hidden}
:root[data-theme="light"] .quadra{background:linear-gradient(170deg,#e8d5b5,#dec7a0)}
/* As linhas: garrafão, círculo do lance livre, arco de 3 e a cesta. */
.quadra .garrafa{position:absolute;top:0;left:50%;transform:translateX(-50%);
  width:34%;height:34%;border:2px solid rgba(255,255,255,.22);border-top:none}
.quadra .circ{position:absolute;top:34%;left:50%;transform:translate(-50%,-50%);
  width:22%;aspect-ratio:1;border:2px solid rgba(255,255,255,.22);border-radius:50%}
.quadra .arco{position:absolute;top:-28%;left:50%;transform:translateX(-50%);
  width:86%;aspect-ratio:1;border:2px solid rgba(255,255,255,.2);border-radius:50%;
  border-top-color:transparent;border-left-color:transparent;border-right-color:transparent}
.quadra .cesta{position:absolute;top:5%;left:50%;transform:translateX(-50%);
  width:13%;height:3px;background:rgba(249,115,22,.85);border-radius:2px}
:root[data-theme="light"] .quadra .garrafa,
:root[data-theme="light"] .quadra .circ,
:root[data-theme="light"] .quadra .arco{border-color:rgba(0,0,0,.2)}

/* As cinco vagas. O top/left de cada uma é a posição em quadra. */
.vaga{position:absolute;transform:translate(-50%,-50%);width:92px;text-align:center;cursor:pointer}
.vaga[data-s="C"] {top:24%; left:50%}
.vaga[data-s="F1"]{top:45%; left:19%}
.vaga[data-s="F2"]{top:45%; left:81%}
.vaga[data-s="G1"]{top:74%; left:31%}
.vaga[data-s="G2"]{top:74%; left:69%}
@media(max-width:420px){ .vaga{width:74px} }
.vaga .cam{width:54px;height:54px;margin:0 auto;border-radius:50%;background:rgba(0,0,0,.45);
  border:2px dashed rgba(255,255,255,.3);display:grid;place-items:center;overflow:hidden;
  color:rgba(255,255,255,.5);font-family:var(--num);font-size:19px}
:root[data-theme="light"] .vaga .cam{background:rgba(255,255,255,.6);border-color:rgba(0,0,0,.25);color:rgba(0,0,0,.4)}
.vaga.full .cam{border-style:solid;border-color:var(--lar);background:var(--sup-2)}
.vaga.cap .cam{border-color:var(--ouro);box-shadow:0 0 0 3px rgba(251,191,36,.25)}
.vaga .cam img{width:100%;height:100%;object-fit:cover}
.vaga .nm{margin-top:5px;font-size:11px;font-weight:700;color:#fff;text-shadow:0 1px 3px rgba(0,0,0,.8);
  white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
:root[data-theme="light"] .vaga .nm{color:#10131a;text-shadow:0 1px 2px rgba(255,255,255,.8)}
.vaga .sub{font-size:10px;color:rgba(255,255,255,.72);text-shadow:0 1px 3px rgba(0,0,0,.8)}
:root[data-theme="light"] .vaga .sub{color:#4b5160;text-shadow:none}
.vaga .estrela{position:absolute;top:-4px;right:12px;width:20px;height:20px;border-radius:50%;
  background:var(--ouro);color:#1a1200;display:grid;place-items:center;font-size:10px}
.vaga .folga{color:#fca5a5}

.slots{display:flex;flex-direction:column;gap:7px}
.slot{display:flex;align-items:center;gap:10px;padding:9px;border-radius:12px;background:var(--sup-2);
  border:1px dashed var(--bd-2);min-height:56px}
.slot.full{border-style:solid}
.slot.cap{background:linear-gradient(135deg,rgba(251,191,36,.12),transparent);border-color:rgba(251,191,36,.35)}
.slot img{width:36px;height:36px;border-radius:50%;object-fit:cover;background:var(--sup-3);flex:none}
.slot .i{flex:1;min-width:0}
.slot .i b{display:block;font-size:13px;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.slot .i span{font-size:11px;color:var(--tx-2)}
.slot .vazio{flex:1;font-size:12.5px;color:var(--tx-3)}
.mini{width:29px;height:29px;border-radius:9px;border:1px solid var(--bd-2);background:var(--sup-3);
  color:var(--tx-2);cursor:pointer;display:grid;place-items:center;font-size:12px;flex:none}
.mini:hover{color:var(--tx)}
.mini.on{background:rgba(251,191,36,.2);border-color:rgba(251,191,36,.45);color:var(--ouro)}

.orc{display:flex;align-items:center;gap:11px;margin-top:13px;padding-top:13px;border-top:1px solid var(--bd)}
.orc .b{flex:1;height:7px;border-radius:4px;background:var(--sup-3);overflow:hidden}
.orc .b i{display:block;height:100%;background:linear-gradient(90deg,var(--verde),#4ade80);transition:width var(--t)}
.orc.x .b i{background:linear-gradient(90deg,#ef4444,#f87171)}
.orc b{font-family:var(--num);font-size:17px;white-space:nowrap}
.orc.x b{color:#f87171}
.salvar{width:100%;margin-top:12px;padding:15px;border-radius:12px;border:none;cursor:pointer;
  background:linear-gradient(135deg,var(--lar),#ea580c);color:#fff;font-weight:800;font-size:14.5px;
  font-family:inherit;letter-spacing:.2px}
.salvar:disabled{opacity:.35;cursor:not-allowed;background:var(--sup-3);color:var(--tx-3)}

/* ── Listas de resultado ────────────────────────────────────────── */
.tab{display:flex;flex-direction:column;gap:3px}
.tr{display:flex;align-items:center;gap:11px;padding:10px 12px;border-radius:10px;font-size:13.5px;
  text-decoration:none;color:inherit}
.tr:nth-child(odd){background:var(--sup-2)}
.tr.eu{background:rgba(249,115,22,.13);box-shadow:inset 0 0 0 1px rgba(249,115,22,.3)}
.tr .p{width:26px;font-family:var(--num);font-size:16px;color:var(--tx-3);flex:none}
.tr .p.m1{color:var(--ouro)} .tr .p.m2{color:#cbd5e1} .tr .p.m3{color:#d97706}
.tr .n{flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;font-weight:600}
.tr .n small{display:block;font-weight:400;font-size:11px;color:var(--tx-3)}
.tr .v{font-family:var(--num);font-size:17px;flex:none}
.tr .mo{font-size:11.5px;color:var(--ouro);flex:none}
.vazio{padding:28px 16px;text-align:center;color:var(--tx-3);font-size:13px}

.grid2{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,290px),1fr));gap:14px}
.kpi{display:flex;gap:16px;flex-wrap:wrap;margin-bottom:13px}
.kpi>div{flex:1;min-width:96px}
.kpi .k{font-family:var(--num);font-size:30px;line-height:1}
.kpi .l{font-size:11px;color:var(--tx-2);margin-top:2px}

.pill{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:999px;font-size:12.5px;
  font-weight:700;text-decoration:none;background:var(--sup-2);border:1px solid var(--bd-2);color:var(--tx-2)}
.pill.on{background:rgba(249,115,22,.15);border-color:rgba(249,115,22,.4);color:var(--lar-2)}
.pills{display:flex;gap:7px;flex-wrap:wrap;margin-bottom:13px}

.frm{display:flex;gap:8px;flex-wrap:wrap;margin-top:10px}
.frm input{flex:1;min-width:130px;padding:11px 14px;border-radius:11px;background:var(--sup-2);
  border:1px solid var(--bd-2);color:var(--tx);font-size:13.5px;font-family:inherit}
.frm button{padding:11px 18px;border-radius:11px;border:none;cursor:pointer;background:var(--lar);
  color:#fff;font-weight:700;font-size:13.5px;font-family:inherit}
.cod{font-family:var(--num);font-size:19px;letter-spacing:2px;color:var(--lar);
  background:rgba(249,115,22,.1);padding:3px 11px;border-radius:8px;border:1px dashed rgba(249,115,22,.35)}

.regra{font-size:11.5px;line-height:1.65;color:var(--tx-3);margin-top:13px;padding-top:12px;border-top:1px solid var(--bd)}
.regra b{color:var(--tx-2)}
</style>
</head>
<body>

<header class="topo">
  <div class="topo-in">
    <a class="voltar" href="/games.php" title="Voltar aos games"><i class="bi bi-arrow-left"></i></a>
    <div class="marca">FANTASY <b>NBA</b></div>
    <span class="saldo"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
  </div>
  <nav class="abas">
    <a href="<?= $url(['aba' => 'escalar']) ?>" class="<?= $aba === 'escalar' ? 'on' : '' ?>"><i class="bi bi-clipboard-check"></i> Escalar</a>
    <a href="<?= $url(['aba' => 'rodadas']) ?>" class="<?= $aba === 'rodadas' ? 'on' : '' ?>"><i class="bi bi-calendar3"></i> Rodadas</a>
    <a href="<?= $url(['aba' => 'ranking']) ?>" class="<?= $aba === 'ranking' ? 'on' : '' ?>"><i class="bi bi-trophy"></i> Ranking e ligas</a>
  </nav>
</header>

<div class="wrap">

<?php if ($msgOk): ?><div class="aviso ok"><?= $e($msgOk) ?></div><?php endif; ?>
<?php foreach ($erros as $x): ?><div class="aviso err"><?= $e($x) ?></div><?php endforeach; ?>

<?php /* ══════════════ ESCALAR ══════════════ */ ?>
<?php if ($aba === 'escalar'): ?>

  <?php if (!$rodada): ?>
    <div class="aviso nd"><b>Nenhuma rodada no horizonte.</b> A NBA não tem jogo marcado pros próximos
      dias, ou o calendário ainda não foi importado. Volte mais tarde.</div>
  <?php else: ?>

    <?php if (count($proximas) > 1): ?>
    <div class="dias">
      <?php foreach ($proximas as $p):
        $d = new DateTimeImmutable($p['dia']);
        $sel = (int)$p['id'] === (int)$rodada['id'];
        $trancada = !fnbaAberta($p);
      ?>
        <a class="dia <?= $sel ? 'on' : '' ?> <?= $trancada ? 'lock' : '' ?>" href="<?= $url(['r' => $p['id']]) ?>">
          <span><?= $diaSem[$d->format('D')] ?? '' ?></span>
          <b><?= $d->format('d/m') ?></b>
          <span><?= $trancada ? 'fechada' : (int)$p['jogos'] . ' jogos' ?></span>
        </a>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <?php $d = new DateTimeImmutable($rodada['dia']); ?>
    <div class="rodada">
      <div class="linha1">
        <div>
          <div class="data"><?= $diaSem[$d->format('D')] ?? '' ?>, <?= $d->format('d/m') ?></div>
          <div class="qt"><?= (int)$rodada['jogos'] ?> jogo<?= $rodada['jogos'] == 1 ? '' : 's' ?> na NBA</div>
        </div>
        <span class="prazo <?= $aberta ? '' : 'fec' ?>">
          <i class="bi bi-<?= $aberta ? 'unlock' : 'lock-fill' ?>"></i>
          <?= $aberta ? 'Escale até ' . substr((string)$rodada['fecha_em'], 11, 5) : 'Escalação fechada' ?>
        </span>
      </div>
      <div class="jogos">
        <?php foreach ($jogosDaRodada as $g): ?>
          <span class="jg <?= $g['estado'] === 'post' ? 'fim' : '' ?>">
            <?php if ($g['logo_fora']): ?><img src="<?= $e($g['logo_fora']) ?>" alt=""><?php endif; ?>
            <b><?= $e($g['fora']) ?></b>
            <?= $g['estado'] === 'post' ? (int)$g['placar_fora'] . '–' . (int)$g['placar_casa'] : '@' ?>
            <b><?= $e($g['casa']) ?></b>
            <?php if ($g['logo_casa']): ?><img src="<?= $e($g['logo_casa']) ?>" alt=""><?php endif; ?>
            <?= $g['estado'] === 'post' ? '' : '· ' . substr((string)$g['inicio'], 11, 5) ?>
          </span>
        <?php endforeach; ?>
      </div>
    </div>

    <form method="POST" id="frm">
    <input type="hidden" name="acao" value="escalar">
    <input type="hidden" name="rodada" value="<?= (int)$rodada['id'] ?>">
    <div class="grade">
      <div class="card">
        <div class="ct"><i class="bi bi-people-fill"></i> Mercado <span class="dir" id="cnt"></span></div>
        <div class="filtros">
          <input class="busca" id="q" type="search" placeholder="Buscar jogador ou time…" autocomplete="off">
          <button type="button" class="chip on" id="soJoga" data-joga="1">Em quadra hoje</button>
          <button type="button" class="chip" data-p="">Todos</button>
          <button type="button" class="chip" data-p="G">G</button>
          <button type="button" class="chip" data-p="F">F</button>
          <button type="button" class="chip" data-p="C">C</button>
        </div>
        <div class="lista" id="lista"></div>
      </div>

      <div class="card">
        <div class="ct"><i class="bi bi-clipboard-check-fill"></i> Seu time <span class="dir"><?= FNBA_ELENCO ?> jogadores</span></div>
        <div class="quadra" id="quadra">
          <span class="arco"></span><span class="garrafa"></span>
          <span class="circ"></span><span class="cesta"></span>
        </div>
        <div class="slots" id="slots"></div>
        <div class="orc" id="orc">
          <div class="b"><i id="bar" style="width:0%"></i></div>
          <b id="txt">F$ 0,0 / <?= (int)FNBA_ORCAMENTO ?></b>
        </div>
        <?php if ($aberta): ?>
          <button class="salvar" id="btn" type="submit" disabled>Salvar escalação</button>
        <?php else: ?>
          <div class="aviso nd" style="margin:12px 0 0">Esta rodada já fechou. Escolha outro dia acima.</div>
        <?php endif; ?>
        <p class="regra">
          <b>Pontua:</b> ponto 1 · rebote 1,2 · assistência 1,5 · roubo 3 · toco 3 · <b>erro −1</b>.
          O <b>capitão</b> (estrela) vale o dobro.<br>
          <b>Formação:</b> 2 armadores, 2 alas, 1 pivô, dentro de F$ <?= (int)FNBA_ORCAMENTO ?>.
          Troque quantas vezes quiser até o primeiro jogo.
        </p>
      </div>
    </div>
    </form>
  <?php endif; ?>

<?php /* ══════════════ RODADAS ══════════════ */ ?>
<?php elseif ($aba === 'rodadas'): ?>

  <?php if ($verRodada):
    $d = new DateTimeImmutable($verRodada['dia']); ?>
    <a class="pill" href="<?= $url(['aba' => 'rodadas']) ?>" style="margin-bottom:13px"><i class="bi bi-arrow-left"></i> Todas as rodadas</a>
    <div class="grid2">
      <div class="card">
        <div class="ct"><i class="bi bi-list-ol"></i> <?= $d->format('d/m') ?> — quem escalou
          <span class="dir"><?= count($verQuem) ?></span></div>
        <div class="tab">
          <?php foreach ($verQuem as $i => $q): ?>
            <a class="tr <?= (int)$q['user_id'] === $verDe ? 'eu' : '' ?>"
               href="<?= $url(['aba'=>'rodadas','ver'=>$verRodada['id'],'de'=>$q['user_id']]) ?>">
              <span class="p <?= $i < 3 ? 'm' . ($i+1) : '' ?>"><?= $q['posicao'] ? (int)$q['posicao'] . 'º' : '–' ?></span>
              <span class="n"><?= $e($q['nome'] ?? 'GM') ?></span>
              <span class="v"><?= $q['pontos'] !== null ? number_format((float)$q['pontos'], 1, ',', '.') : '…' ?></span>
            </a>
          <?php endforeach; ?>
          <?php if (!$verQuem): ?><div class="vazio">Ninguém escalou nessa rodada.</div><?php endif; ?>
        </div>
      </div>
      <div class="card">
        <div class="ct"><i class="bi bi-person-badge"></i> O time
          <span class="dir"><?= $verDe === $user_id ? 'seu' : 'de outro cartola' ?></span></div>
        <?php if ($verTime === null || !$verTime): ?>
          <div class="vazio">Sem escalação pra mostrar — ou a rodada ainda está aberta.</div>
        <?php else: ?>
          <div class="slots">
            <?php foreach ($verTime as $j): ?>
              <div class="slot full <?= (int)$j['capitao'] ? 'cap' : '' ?>">
                <span class="tag <?= $e($j['pos']) ?>"><?= $e($j['pos']) ?></span>
                <?php if ($j['foto']): ?><img src="<?= $e($j['foto']) ?>" alt="" loading="lazy"><?php endif; ?>
                <span class="i"><b><?= $e($j['nome']) ?></b>
                  <span><?= $e($j['sigla'] ?? '') ?><?= (int)$j['capitao'] ? ' · capitão (2×)' : '' ?></span></span>
                <span class="vlr"><?= $j['fp'] !== null ? number_format((float)$j['fp'] * ((int)$j['capitao'] ? 2 : 1), 1, ',', '.') : '–' ?></span>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    </div>

  <?php else: ?>
    <div class="card">
      <div class="ct"><i class="bi bi-calendar3"></i> Suas rodadas <span class="dir">clique pra ver os times</span></div>
      <div class="tab">
        <?php foreach ($minhasRodadas as $r):
          $d = new DateTimeImmutable($r['dia']);
          $jogou = $r['pontos'] !== null; ?>
          <a class="tr" href="<?= $url(['aba'=>'rodadas','ver'=>$r['id']]) ?>">
            <span class="p"><?= $d->format('d/m') ?></span>
            <span class="n">
              <?= $r['status'] === 'apurada' ? 'Encerrada' : ($r['status'] === 'fechada' ? 'Em jogo' : 'Aberta') ?>
              <small><?= (int)$r['jogos'] ?> jogos<?= (int)$r['cartolas'] ? ' · ' . (int)$r['cartolas'] . ' cartolas' : '' ?></small>
            </span>
            <?php if ((int)$r['moedas'] > 0): ?><span class="mo">+<?= (int)$r['moedas'] ?></span><?php endif; ?>
            <span class="v" style="color:<?= $jogou ? 'var(--verde)' : 'var(--tx-3)' ?>">
              <?= $jogou ? number_format((float)$r['pontos'], 1, ',', '.') : '—' ?></span>
            <?php if ($r['posicao']): ?><span class="mo" style="color:var(--tx-3)"><?= (int)$r['posicao'] ?>º</span><?php endif; ?>
          </a>
        <?php endforeach; ?>
        <?php if (!$minhasRodadas): ?><div class="vazio">Nenhuma rodada ainda.</div><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>

<?php /* ══════════════ RANKING E LIGAS ══════════════ */ ?>
<?php else: ?>

  <div class="pills">
    <a class="pill <?= $rankAba === 'semana' ? 'on' : '' ?>" href="<?= $url(['aba'=>'ranking','f'=>'semana']) ?>">
      <i class="bi bi-calendar-week"></i> Semana</a>
    <a class="pill <?= $rankAba === 'geral' ? 'on' : '' ?>" href="<?= $url(['aba'=>'ranking','f'=>'geral']) ?>">
      <i class="bi bi-globe"></i> Geral</a>
    <a class="pill <?= $rankAba === 'campeoes' ? 'on' : '' ?>" href="<?= $url(['aba'=>'ranking','f'=>'campeoes']) ?>">
      <i class="bi bi-award"></i> Campeões</a>
    <?php foreach (FNBA_DIVISOES as $dv): ?>
      <a class="pill <?= strtoupper((string)$rankAba) === $dv ? 'on' : '' ?>" href="<?= $url(['aba'=>'ranking','f'=>$dv]) ?>">
        <?= $dv ?><?= $minhaDiv === $dv ? ' ·' : '' ?></a>
    <?php endforeach; ?>
    <?php foreach ($minhasLigas as $l): ?>
      <a class="pill <?= $rankAba === 'liga:' . $l['id'] ? 'on' : '' ?>" href="<?= $url(['aba'=>'ranking','f'=>'liga:'.$l['id']]) ?>">
        <i class="bi bi-people-fill"></i> <?= $e($l['nome']) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($rankAba === 'campeoes'): ?>
    <div class="card">
      <div class="ct"><i class="bi bi-award-fill"></i> Campeões da semana
        <span class="dir"><?= count($campeoes) ?> semanas</span></div>
      <div class="tab">
        <?php foreach ($campeoes as $c):
          $ini = new DateTimeImmutable($c['inicio']); $fim = new DateTimeImmutable($c['fim']); ?>
          <div class="tr <?= (int)$c['user_id'] === $user_id ? 'eu' : '' ?>">
            <span class="p m1"><i class="bi bi-trophy-fill"></i></span>
            <span class="n"><?= $e($c['nome'] ?? 'GM') ?>
              <small><?= $ini->format('d/m') ?> a <?= $fim->format('d/m') ?> ·
                <?= (int)$c['rodadas'] ?> rodadas · <?= (int)$c['cartolas'] ?> cartolas</small></span>
            <span class="mo">+<?= (int)$c['fba_points'] ?> FBA</span>
            <span class="v"><?= number_format((float)$c['pontos'], 1, ',', '.') ?></span>
          </div>
        <?php endforeach; ?>
        <?php if (!$campeoes): ?>
          <div class="vazio">Nenhuma semana fechada ainda. A primeira fecha no domingo à meia-noite.</div>
        <?php endif; ?>
      </div>
      <p class="regra">Toda <b>segunda a domingo</b> é uma temporada curta. Quem somar mais pontos
        na semana leva <b>500 FBA Points</b>; o segundo 300, o terceiro 100, e o quarto e o quinto 50.
        É o maior prêmio recorrente da casa — pra comparar, uma aposta certa paga 75.</p>
    </div>

  <?php else: ?>
    <?php
    $meu = null; $minhaPos = 0;
    foreach ($rankLista as $i2 => $r) if ((int)$r['user_id'] === $user_id) { $meu = $r; $minhaPos = $i2 + 1; }
    $ehSemana = $rankAba === 'semana';
    ?>
    <?php if ($meu): ?>
    <div class="card" style="margin-bottom:14px">
      <div class="kpi">
        <div><div class="k" style="color:var(--lar)"><?= $minhaPos ?>º</div><div class="l">sua posição</div></div>
        <div><div class="k"><?= number_format((float)$meu['total'], 1, ',', '.') ?></div><div class="l">pontos</div></div>
        <div><div class="k" style="color:var(--verde)"><?= number_format((float)$meu['total'] / max(1, (int)$meu['rodadas']), 1, ',', '.') ?></div><div class="l">média por rodada</div></div>
        <?php if ($ehSemana && isset(FNBA_PREMIO_SEMANA[$minhaPos])): ?>
          <div><div class="k" style="color:var(--ouro)"><?= FNBA_PREMIO_SEMANA[$minhaPos] ?></div><div class="l">FBA Points se fechar assim</div></div>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <div class="grid2">
      <div class="card">
        <div class="ct"><i class="bi bi-<?= $ehSemana ? 'calendar-week-fill' : 'trophy-fill' ?>"></i>
          <?= $e($rankTitulo) ?>
          <span class="dir"><?= $rankSub ?></span></div>
        <div class="tab">
          <?php foreach ($rankLista as $i2 => $r):
            $premio = $ehSemana ? (FNBA_PREMIO_SEMANA[$i2 + 1] ?? 0) : 0; ?>
            <div class="tr <?= (int)$r['user_id'] === $user_id ? 'eu' : '' ?>">
              <span class="p <?= $i2 < 3 ? 'm' . ($i2+1) : '' ?>"><?= $i2 + 1 ?>º</span>
              <span class="n"><?= $e($r['nome'] ?? 'GM') ?>
                <small><?= (int)$r['rodadas'] ?> rodada<?= $r['rodadas'] == 1 ? '' : 's' ?> ·
                  média <?= number_format((float)$r['total'] / max(1, (int)$r['rodadas']), 1, ',', '.') ?></small></span>
              <?php if ($premio): ?><span class="mo"><?= $premio ?> FBA</span><?php endif; ?>
              <span class="v"><?= number_format((float)$r['total'], 1, ',', '.') ?></span>
            </div>
          <?php endforeach; ?>
          <?php if (!$rankLista): ?>
            <div class="vazio"><?= $ehSemana
              ? 'Ninguém pontuou nesta semana ainda. A primeira rodada apurada abre a tabela.'
              : 'Nenhuma rodada apurada ainda.' ?></div>
          <?php endif; ?>
        </div>
        <?php if ($ehSemana): ?>
          <p class="regra">Vale de <b>segunda a domingo</b>. Fecha quando o domingo acaba e paga
            <b>500 / 300 / 100 / 50 / 50 FBA Points</b> aos cinco primeiros.</p>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="ct"><i class="bi bi-people-fill"></i> Ligas de amigos <span class="dir">até 5</span></div>
        <div class="tab">
          <?php foreach ($minhasLigas as $l): ?>
            <div class="tr">
              <span class="n"><?= $e($l['nome']) ?>
                <small><?= (int)$l['membros'] ?> membros<?= (int)$l['dono_id'] === $user_id ? ' · você criou' : '' ?></small></span>
              <span class="cod"><?= $e($l['codigo']) ?></span>
              <?php if ((int)$l['dono_id'] !== $user_id): ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="acao" value="sair_liga">
                  <input type="hidden" name="liga" value="<?= (int)$l['id'] ?>">
                  <button class="mini" type="submit" title="Sair da liga"><i class="bi bi-box-arrow-right"></i></button>
                </form>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
          <?php if (!$minhasLigas): ?><div class="vazio">Você ainda não está em nenhuma liga de amigos.</div><?php endif; ?>
        </div>
        <form method="POST" class="frm">
          <input type="hidden" name="acao" value="criar_liga">
          <input name="nome" placeholder="Nome da liga nova" maxlength="60" required>
          <button type="submit">Criar</button>
        </form>
        <form method="POST" class="frm">
          <input type="hidden" name="acao" value="entrar_liga">
          <input name="codigo" placeholder="Código de convite" maxlength="6" required style="text-transform:uppercase">
          <button type="submit">Entrar</button>
        </form>
        <p class="regra">As <b>divisões da FBA</b> já existem sozinhas: quem tem time na ELITE disputa
          com a ELITE, sem entrar em nada. As <b>ligas de amigos</b> são suas — crie e mande o código.
          Os dois recortes somam todas as rodadas; só a <b>Semana</b> paga FBA Points.</p>
      </div>
    </div>
  <?php endif; ?>
<?php endif; ?>

</div>

<?php if ($aba === 'escalar' && $rodada): ?>
<script src="/js/popups.js?v=<?= @filemtime(__DIR__ . '/js/popups.js') ?: 1 ?>"></script>
<script>
/* O montador de time. Valida aqui pra avisar enquanto a pessoa monta; o
   servidor confere de novo, porque validação de tela é conveniência e não
   regra. @see fnbaValidar */
(function(){
  const M = <?= json_encode(array_map(fn($j) => [
        'id'=>(int)$j['id'], 'n'=>$j['nome'], 'p'=>$j['pos'], 'c'=>(float)$j['preco'],
        'm'=>(float)$j['media_fp'], 't'=>$j['sigla'] ?? '', 'f'=>$j['foto'] ?? '',
        /* joga e contra decidem o que a tela mostra primeiro: numa terca de
           3 jogos, 414 dos 517 estao de folga. @see fnbaMercado */
        'joga'=>(bool)($j['joga'] ?? true), 'contra'=>$j['contra'] ?? '',
      ], $mercado), JSON_UNESCAPED_UNICODE) ?>;
  const FORM = <?= json_encode(FNBA_FORMACAO) ?>, ORC = <?= (int)FNBA_ORCAMENTO ?>,
        N = <?= FNBA_ELENCO ?>, ABERTA = <?= $aberta ? 'true' : 'false' ?>,
        ROT = {G:'armador', F:'ala', C:'pivô'};
  let sel = <?= json_encode(array_map('intval', $escalados)) ?>, cap = <?= (int)$capAtual ?>,
      fp = '', q = '', soJoga = true;
  const by = {}; M.forEach(j => by[j.id] = j);
  const $ = s => document.querySelector(s);
  const custo = () => sel.reduce((s,i) => s + (by[i]?.c || 0), 0);
  const nPos = p => sel.filter(i => by[i]?.p === p).length;
  const cabe = j => sel.includes(j.id) ||
        (sel.length < N && nPos(j.p) < (FORM[j.p]||0) && custo() + j.c <= ORC + .001);

  function toggle(id){
    if (!ABERTA) return;
    const k = sel.indexOf(id);
    if (k >= 0) { sel.splice(k,1); if (cap === id) cap = 0; }
    else { if (!cabe(by[id])) return; sel.push(id); if (!cap) cap = id; }
    tudo();
  }
  /* AS CINCO VAGAS DA QUADRA, na ordem em que elas aparecem no desenho.
     A chave (G1, F1, C…) é o que liga cada jogador ao seu lugar. */
  function vagas(){
    const ord = [];
    Object.entries(FORM).forEach(([p,n]) => {
      const d = sel.filter(i => by[i]?.p === p);
      for (let k=0;k<n;k++) ord.push({p, slot: n > 1 ? p + (k+1) : p, id:d[k]||null});
    });
    return ord;
  }
  function quadra(){
    const q = document.getElementById('quadra');
    q.querySelectorAll('.vaga').forEach(v => v.remove());
    vagas().forEach(s => {
      const j = s.id ? by[s.id] : null;
      const d = document.createElement('div');
      d.className = 'vaga' + (j ? ' full' : '') + (j && cap === j.id ? ' cap' : '');
      d.dataset.s = s.slot;
      if (j) d.dataset.x = j.id;
      d.innerHTML = j
        ? `<div class="cam">${j.f?`<img src="${j.f}" alt="" loading="lazy">`:j.p}</div>
           ${cap===j.id?'<span class="estrela"><i class="bi bi-star-fill"></i></span>':''}
           <div class="nm">${j.n.split(' ').slice(-1)[0]}</div>
           <div class="sub ${j.joga===false?'folga':''}">${j.joga===false?'de folga':(j.contra||j.t)}</div>`
        : `<div class="cam">${s.p}</div><div class="sub">${ROT[s.p]}</div>`;
      q.appendChild(d);
    });
  }
  function slots(){
    const ord = vagas();
    $('#slots').innerHTML = ord.map(s => {
      const j = s.id ? by[s.id] : null;
      if (!j) return `<div class="slot"><span class="tag ${s.p}">${s.p}</span>
        <span class="vazio">Escolha um ${ROT[s.p]}</span></div>`;
      const c = cap === j.id;
      return `<div class="slot full ${c?'cap':''}"><span class="tag ${j.p}">${j.p}</span>
        ${j.f?`<img src="${j.f}" alt="" loading="lazy">`:''}
        <span class="i"><b>${j.n}</b><span>${j.t} · ${j.m.toFixed(1)} méd${c?' · capitão 2×':''}</span></span>
        <span class="vlr">${j.c.toFixed(1)}</span>
        ${ABERTA?`<button type="button" class="mini ${c?'on':''}" data-c="${j.id}" title="Capitão"><i class="bi bi-star-fill"></i></button>
        <button type="button" class="mini" data-x="${j.id}" title="Tirar"><i class="bi bi-x-lg"></i></button>`:''}
      </div>`;
    }).join('');
  }
  function merc(){
    const t = q.trim().toLowerCase();
    const v = M.filter(j => (!fp || j.p === fp) && (!soJoga || j.joga !== false) &&
      (!t || j.n.toLowerCase().includes(t) || (j.t||'').toLowerCase().includes(t)));
    $('#cnt').textContent = v.length + (soJoga ? ' em quadra' : ' de ' + M.length);
    $('#lista').innerHTML = v.length ? v.slice(0,120).map(j => {
      const i = sel.includes(j.id);
      return `<div class="lin ${i?'in':''} ${!i&&!cabe(j)?'off':''}" data-id="${j.id}">
        ${j.f?`<img src="${j.f}" alt="" loading="lazy">`:'<img alt="">'}
        <span class="tag ${j.p}">${j.p}</span>
        <span class="i"><b>${j.n}</b><span>${j.joga===false?'<b style="color:#fca5a5">de folga hoje</b>':(j.contra||j.t)} · ${j.m.toFixed(1)} méd</span></span>
        <span class="vlr">${j.c.toFixed(1)}</span></div>`;
    }).join('') : '<div class="vazio">Ninguém com esse nome.</div>';
  }
  function orc(){
    const c = custo();
    $('#bar').style.width = Math.min(100, c/ORC*100) + '%';
    $('#txt').textContent = 'F$ ' + c.toFixed(1).replace('.',',') + ' / ' + ORC;
    $('#orc').classList.toggle('x', c > ORC + .001);
    const b = $('#btn');
    if (b) {
      const pronto = sel.length === N && c <= ORC + .001 && cap;
      b.disabled = !pronto;
      b.textContent = pronto ? 'Salvar escalação' : `Faltam ${N - sel.length} jogador(es)`;
    }
  }
  function tudo(){
    quadra(); slots(); merc(); orc();
    const f = $('#frm');
    f.querySelectorAll('input[name="jogadores[]"],input[name="capitao"]').forEach(i => i.remove());
    sel.forEach(id => { const i = document.createElement('input');
      i.type='hidden'; i.name='jogadores[]'; i.value=id; f.appendChild(i); });
    const c = document.createElement('input'); c.type='hidden'; c.name='capitao'; c.value=cap; f.appendChild(c);
  }
  $('#lista').addEventListener('click', ev => {
    const l = ev.target.closest('.lin'); if (l) toggle(+l.dataset.id);
  });
  $('#slots').addEventListener('click', ev => {
    const c = ev.target.closest('[data-c]'); if (c) { cap = +c.dataset.c; tudo(); return; }
    const x = ev.target.closest('[data-x]'); if (x) toggle(+x.dataset.x);
  });
  /* NA QUADRA, O CLIQUE PROMOVE A CAPITÃO em vez de tirar: tirar é o botão
     da lista logo abaixo, e um toque errado no desenho não pode desfazer a
     escalação inteira sem aviso. */
  $('#quadra').addEventListener('click', ev => {
    const v = ev.target.closest('.vaga[data-x]');
    if (!v || !ABERTA) return;
    cap = +v.dataset.x; tudo();
  });
  $('#q').addEventListener('input', ev => { q = ev.target.value; merc(); });
  document.querySelectorAll('.chip').forEach(b => b.addEventListener('click', () => {
    document.querySelectorAll('.chip').forEach(o => o.classList.remove('on'));
    b.classList.add('on');
    soJoga = b.dataset.joga === '1';
    fp = b.dataset.p || '';
    merc();
  }));
  tudo();
})();
</script>
<?php endif; ?>
</body>
</html>
