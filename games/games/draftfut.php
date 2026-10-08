<?php
/**
 * FBA DRAFT — o FUT Draft da liga.
 *
 * Escolhe a formação, monta onze cartas escolhendo uma entre cinco por vaga,
 * e joga uma partida narrada minuto a minuto valendo moedas. Contra a
 * máquina ou contra o time de outro GM.
 *
 * ── AINDA NÃO ESTÁ NO HUB, E ISSO É DE PROPÓSITO ─────────────────────
 *
 * Pedido do Marcos em 07/10/2026: "não coloca lá ainda, somente por link".
 * Então não existe card em games/index.clean.php — quem tem o endereço
 * entra, o resto da liga não vê. Pra publicar depois, é só acrescentar o
 * card; nada aqui depende disso.
 *
 * ── O DRAFT MORA NA SESSÃO, O TIME MORA NO BANCO ─────────────────────
 *
 * Enquanto a pessoa escolhe, o estado é da sessão: são onze passos num
 * minuto, e gravar cada clique no banco seria onze escritas pra uma coisa
 * que pode ser abandonada no meio. Quando o time fica pronto, aí sim ele é
 * gravado — porque a partir daí ele enfrenta gente, aparece pros outros e
 * precisa sobreviver ao navegador fechar.
 *
 * @see games/core/draftfut.php           formações, cartas e química
 * @see games/core/draftfut_partida.php   a partida minuto a minuto
 */

ini_set('display_errors', 1);
error_reporting(E_ALL);

require '../core/conexao.php';
require_once __DIR__ . '/../core/draftfut_partida.php';

if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$user_id = (int)$_SESSION['user_id'];

/* ── O QUE CUSTA E O QUE PAGA ────────────────────────────────────────
   A entrada existe pra a escolha ter peso: draft de graça vira reroll até
   sair o time perfeito. O prêmio por vitória cobre a entrada com folga, e
   o empate devolve quase tudo — perder um draft bem montado num 1x1 não
   pode doer mais que não ter jogado. */
const DF_ENTRADA = 50;
const DF_VITORIA = 150;
const DF_EMPATE  = 40;

function dfTabelas(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_times (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        nome VARCHAR(60) NOT NULL,
        formacao VARCHAR(12) NOT NULL,
        time_json TEXT NOT NULL,
        forca INT NOT NULL,
        quimica INT NOT NULL,
        vitorias INT NOT NULL DEFAULT 0,
        empates INT NOT NULL DEFAULT 0,
        derrotas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_df_user (id_usuario),
        INDEX idx_df_forca (forca)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->exec("CREATE TABLE IF NOT EXISTS draftfut_partidas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        time_casa INT NOT NULL,
        time_fora INT NULL,
        fora_json TEXT NULL,
        semente INT NOT NULL,
        gols_casa INT NOT NULL,
        gols_fora INT NOT NULL,
        moedas INT NOT NULL DEFAULT 0,
        criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_dfp_casa (time_casa)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}
dfTabelas($pdo);

/** O saldo de moedas de quem está jogando. */
function dfMoedas(PDO $pdo, int $uid): int
{
    $st = $pdo->prepare('SELECT pontos FROM games_usuarios WHERE id = ?');
    $st->execute([$uid]);
    return (int)$st->fetchColumn();
}

/** Mexe no saldo. Negativo cobra, positivo paga. Nunca deixa abaixo de zero. */
function dfMoedasMexer(PDO $pdo, int $uid, int $delta): void
{
    $pdo->prepare('UPDATE games_usuarios SET pontos = GREATEST(0, pontos + ?) WHERE id = ?')
        ->execute([$delta, $uid]);
}

$msg = null; $erro = null;
$d = &$_SESSION['draftfut'];

/* ═══════════════════════════ AÇÕES ══════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');

    if ($acao === 'comecar') {
        $formacao = (string)($_POST['formacao'] ?? '');
        if (!isset(DFUT_FORMACOES[$formacao])) {
            $erro = 'Formação inválida.';
        } elseif (dfMoedas($pdo, $user_id) < DF_ENTRADA) {
            $erro = 'Você precisa de ' . DF_ENTRADA . ' moedas pra entrar no draft.';
        } else {
            dfMoedasMexer($pdo, $user_id, -DF_ENTRADA);
            $d = ['formacao' => $formacao, 'time' => array_fill(0, DFUT_TOTAL, null),
                  'usados' => [], 'aberta' => null, 'opcoes' => null, 'resultado' => null];
            $msg = 'Boa sorte! Entrada de ' . DF_ENTRADA . ' moedas paga.';
        }
    }

    /* ABRIR UMA VAGA é o que sorteia as cartas dela. O jogo não manda mais
       a ordem: quem escolhe por onde começar é quem está jogando, como no
       FIFA. As cartas ficam guardadas na sessão até a escolha, senão um F5
       daria cinco cartas novas e o draft viraria roleta. */
    if ($acao === 'abrir' && $d && empty($d['resultado'])) {
        $v = (int)($_POST['vaga'] ?? -1);
        if ($v >= 0 && $v < DFUT_TOTAL && empty($d['time'][$v])) {
            $d['aberta'] = $v;
            $preenchidas = count(array_filter($d['time']));
            [$lo, $hi] = draftFutFaixa($preenchidas);
            $d['opcoes'] = draftFutOpcoes(draftFutPosDaVaga($d['formacao'], $v), $d['usados'], $lo, $hi);
        }
    }

    if ($acao === 'escolher' && $d && $d['opcoes'] && $d['aberta'] !== null) {
        $i = (int)($_POST['carta'] ?? -1);
        if (isset($d['opcoes'][$i])) {
            $c = $d['opcoes'][$i];
            $d['time'][$d['aberta']] = $c;
            $d['usados'][] = $c['nome'];
            $d['aberta'] = null;
            $d['opcoes'] = null;
        }
    }

    /* TROCAR DOIS DE LUGAR — é o escalar. Vale entre campo e banco também,
       que é como se faz substituição antes do jogo. A química é recalculada
       sozinha na hora de desenhar, então não há nada a atualizar aqui. */
    if ($acao === 'trocar' && $d && empty($d['resultado'])) {
        $a = (int)($_POST['a'] ?? -1); $b = (int)($_POST['b'] ?? -1);
        if ($a >= 0 && $b >= 0 && $a < DFUT_TOTAL && $b < DFUT_TOTAL && $a !== $b) {
            $tmp = $d['time'][$a]; $d['time'][$a] = $d['time'][$b]; $d['time'][$b] = $tmp;
        }
    }

    if ($acao === 'jogar' && $d && draftFutCampoCheio($d)) {
        $formacao = $d['formacao'];
        $time = $d['time'];
        $forca = draftFutForcaDoTime($formacao, $time);
        $quim  = draftFutQuimica($formacao, $time)['total'];

        $nome = trim((string)($_POST['nome'] ?? '')) ?: 'Time de ' . $user_id;
        $pdo->prepare('INSERT INTO draftfut_times (id_usuario, nome, formacao, time_json, forca, quimica)
                       VALUES (?,?,?,?,?,?)')
            ->execute([$user_id, mb_substr($nome, 0, 60), $formacao,
                       json_encode($time, JSON_UNESCAPED_UNICODE), $forca, $quim]);
        $meuId = (int)$pdo->lastInsertId();

        /* O ADVERSÁRIO: o time de outro GM quando existe algum de força
           parecida, senão a máquina. Assim o PvP acontece sem ninguém
           precisar estar online — o time do outro já está salvo. */
        $modo = (string)($_POST['modo'] ?? 'maquina');
        $adv = null; $advId = null;
        if ($modo === 'pvp') {
            $st = $pdo->prepare('SELECT * FROM draftfut_times
                                  WHERE id_usuario <> ? AND ABS(forca - ?) <= 6
                               ORDER BY RAND() LIMIT 1');
            $st->execute([$user_id, $forca]);
            if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
                $advId = (int)$r['id'];
                $adv = ['nome' => $r['nome'], 'formacao' => $r['formacao'],
                        'time' => json_decode($r['time_json'], true), 'forca' => (int)$r['forca']];
            }
        }
        $semente = random_int(1, 2000000000);
        if (!$adv) {
            $adv = draftFutAdversarioDaMaquina($forca, $semente);
            $modo = 'maquina';
        }

        $p = draftFutPartida(['nome' => $nome, 'formacao' => $formacao, 'time' => $time, 'forca' => $forca],
                             $adv, $semente);

        $premio = $p['placar'][0] > $p['placar'][1] ? DF_VITORIA
                : ($p['placar'][0] === $p['placar'][1] ? DF_EMPATE : 0);
        if ($premio) dfMoedasMexer($pdo, $user_id, $premio);

        $col = $p['placar'][0] > $p['placar'][1] ? 'vitorias'
             : ($p['placar'][0] === $p['placar'][1] ? 'empates' : 'derrotas');
        $pdo->prepare("UPDATE draftfut_times SET $col = $col + 1 WHERE id = ?")->execute([$meuId]);
        if ($advId) {
            $inv = $col === 'vitorias' ? 'derrotas' : ($col === 'derrotas' ? 'vitorias' : 'empates');
            $pdo->prepare("UPDATE draftfut_times SET $inv = $inv + 1 WHERE id = ?")->execute([$advId]);
        }

        $pdo->prepare('INSERT INTO draftfut_partidas (time_casa, time_fora, fora_json, semente, gols_casa, gols_fora, moedas)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([$meuId, $advId, $advId ? null : json_encode($adv, JSON_UNESCAPED_UNICODE),
                       $semente, $p['placar'][0], $p['placar'][1], $premio]);

        $d['resultado'] = ['partida' => $p, 'adv' => $adv, 'modo' => $modo,
                           'premio' => $premio, 'forca' => $forca, 'quimica' => $quim, 'nome' => $nome];
    }

    if ($acao === 'novo') { $d = null; }

    header('Location: /games/games/draftfut.php' . ($msg ? '?m=' . urlencode($msg) : ($erro ? '?e=' . urlencode($erro) : '')));
    exit;
}

$msg = $_GET['m'] ?? null;
$erro = $_GET['e'] ?? null;

/** Os onze de campo estão todos preenchidos? É o que libera jogar. */
function draftFutCampoCheio(array $d): bool
{
    for ($i = 0; $i < DFUT_VAGAS; $i++) if (empty($d['time'][$i])) return false;
    return true;
}

$moedas = dfMoedas($pdo, $user_id);
$emDraft = $d && empty($d['resultado']);
$pronto  = $d && draftFutCampoCheio($d) && empty($d['resultado']);
$fim     = $d && !empty($d['resultado']);
$quim = $d ? draftFutQuimica($d['formacao'], $d['time']) : ['total' => 0, 'jogadores' => []];
$aberta = $d['aberta'] ?? null;

function e($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="pt-BR" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>FBA Draft</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800&family=Oswald:wght@500;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css">
<style>
:root{
  --bg:#07070a; --panel:#101013; --panel2:#16161a; --panel3:#1c1c21;
  --borda:rgba(255,255,255,.08); --borda2:rgba(255,255,255,.14);
  --txt:#f0f0f3; --txt2:#9a9aa4; --txt3:#70707a;
  --verde:#22c55e; --amarelo:#f5c518; --vermelho:#fc0025; --azul:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Montserrat',sans-serif;padding-bottom:50px}
a{color:inherit}
.topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap;
  padding:14px 18px;border-bottom:1px solid var(--borda);background:var(--panel)}
.topo h1{margin:0;font-family:'Oswald',sans-serif;font-size:22px;letter-spacing:.5px}
.topo h1 span{color:var(--vermelho)}
.saldo{background:var(--panel3);border:1px solid var(--borda2);border-radius:999px;
  padding:6px 14px;font-weight:700;font-size:13px}
.saldo i{color:var(--amarelo)}
.wrap{max-width:1040px;margin:0 auto;padding:18px}
.aviso{padding:10px 14px;border-radius:10px;margin-bottom:14px;font-size:13.5px;font-weight:600}
.aviso.ok{background:rgba(34,197,94,.12);color:var(--verde);border:1px solid rgba(34,197,94,.3)}
.aviso.err{background:rgba(252,0,37,.12);color:#ff5c7a;border:1px solid rgba(252,0,37,.3)}
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;padding:18px;margin-bottom:16px}
.bloco h2{margin:0 0 4px;font-family:'Oswald',sans-serif;font-size:19px;letter-spacing:.4px}
.bloco p.sub{margin:0 0 14px;color:var(--txt2);font-size:13.5px}
.btn{display:inline-flex;align-items:center;gap:7px;border:1px solid var(--borda2);background:var(--panel3);
  color:var(--txt);font:inherit;font-weight:700;font-size:13px;border-radius:10px;padding:10px 16px;cursor:pointer}
.btn:hover{border-color:var(--vermelho)}
.btn.pri{background:var(--vermelho);border-color:var(--vermelho);color:#fff}
.btn:disabled{opacity:.45;cursor:not-allowed}

/* Formações */
.forms{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.form-card{background:var(--panel2);border:1px solid var(--borda);border-radius:12px;padding:14px;
  text-align:center;cursor:pointer;font:inherit;color:var(--txt)}
.form-card:hover{border-color:var(--vermelho)}
.form-card b{display:block;font-family:'Oswald',sans-serif;font-size:21px;letter-spacing:1px}
.form-card small{color:var(--txt3);font-size:11.5px}

/* Cartas */
.cartas{display:grid;grid-template-columns:repeat(auto-fit,minmax(150px,1fr));gap:12px}
.carta{background:linear-gradient(160deg,#2a2a33,#15151a);border:1px solid var(--borda2);
  border-radius:14px;padding:14px 12px;text-align:center;cursor:pointer;font:inherit;color:var(--txt);
  display:flex;flex-direction:column;align-items:center;gap:6px;transition:transform .12s}
.carta:hover{transform:translateY(-4px);border-color:var(--amarelo)}
.carta .ovr{font-family:'Oswald',sans-serif;font-size:30px;line-height:1;color:var(--amarelo)}
.carta .pos{font-size:10.5px;font-weight:800;letter-spacing:1px;color:var(--txt2)}
.carta img{width:34px;height:34px;object-fit:contain}
.carta .nm{font-weight:700;font-size:13px;line-height:1.2}
.carta .cl{font-size:11px;color:var(--txt3)}
.carta .lg{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px}

/* Escalação */
.escal{display:flex;flex-direction:column;gap:6px;margin-top:4px}
.esc-l{display:grid;grid-template-columns:44px 1fr auto auto;gap:10px;align-items:center;
  background:var(--panel2);border:1px solid var(--borda);border-radius:9px;padding:7px 11px;font-size:13px}
.esc-l .rot{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.5px}
.esc-l .nm{font-weight:600;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.esc-l .nm small{color:var(--txt3);font-weight:400}
.esc-l .ov{font-family:'Oswald',sans-serif;font-size:16px;color:var(--amarelo)}
.esc-l .qm{font-size:11px;font-weight:700;padding:2px 7px;border-radius:999px}
.qm.alta{background:rgba(34,197,94,.15);color:var(--verde)}
.qm.media{background:rgba(245,197,24,.15);color:var(--amarelo)}
.qm.baixa{background:rgba(252,0,37,.15);color:#ff5c7a}
.esc-l.vazia{opacity:.35}

.nums{display:flex;gap:18px;flex-wrap:wrap;margin:12px 0}
.num{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;padding:9px 16px;text-align:center}
.num b{display:block;font-family:'Oswald',sans-serif;font-size:24px;line-height:1}
.num small{color:var(--txt3);font-size:11px;text-transform:uppercase;letter-spacing:.5px}

/* Partida */
.placar{display:flex;align-items:center;justify-content:center;gap:18px;margin:6px 0 16px;flex-wrap:wrap}
.placar .t{text-align:center;min-width:130px}
.placar .t b{display:block;font-size:14px}
.placar .t small{color:var(--txt3);font-size:11px}
.placar .g{font-family:'Oswald',sans-serif;font-size:46px;line-height:1}
.narra{display:flex;flex-direction:column;gap:7px;max-height:420px;overflow-y:auto;padding-right:4px}
.lance{display:grid;grid-template-columns:40px 1fr;gap:10px;align-items:start;font-size:13.5px;
  padding:7px 10px;border-radius:8px;background:var(--panel2);border-left:3px solid var(--borda2);
  opacity:0;transform:translateY(6px);animation:ent .25s forwards}
@keyframes ent{to{opacity:1;transform:none}}
.lance .m{font-family:'Oswald',sans-serif;color:var(--txt3);font-size:13px}
.lance.gol{border-left-color:var(--verde);background:rgba(34,197,94,.1);font-weight:700}
.lance.defesa{border-left-color:var(--azul)}
.lance.perdeu{border-left-color:var(--amarelo)}
.lance.fim{border-left-color:var(--vermelho);font-weight:700}
.lance .pl{font-family:'Oswald',sans-serif;color:var(--verde);margin-left:6px}
/* ── O CAMPINHO ───────────────────────────────────────────────────── */
.campo-topo{display:flex;align-items:center;justify-content:space-between;gap:12px;flex-wrap:wrap}
.quim-barra{display:flex;align-items:center;gap:8px;font-size:12px;color:var(--txt2)}
.quim-barra b{font-family:'Oswald',sans-serif;font-size:17px;color:var(--txt)}
.qb-trilho{display:block;width:110px;height:7px;border-radius:99px;background:var(--panel3);overflow:hidden}
.qb-fill{display:block;height:100%;border-radius:99px;
  background:linear-gradient(90deg,var(--vermelho),var(--amarelo),var(--verde));
  transition:width .5s cubic-bezier(.2,.8,.2,1)}
.campo{position:relative;width:100%;aspect-ratio:68/95;max-height:540px;margin:10px auto 0;
  background:
    repeating-linear-gradient(180deg,#16331d 0 7%,#143019 7% 14%);
  border:2px solid rgba(255,255,255,.18);border-radius:10px;overflow:hidden}
.linha-meio{position:absolute;left:0;right:0;top:50%;height:2px;background:rgba(255,255,255,.18)}
.circulo{position:absolute;left:50%;top:50%;width:26%;aspect-ratio:1;transform:translate(-50%,-50%);
  border:2px solid rgba(255,255,255,.18);border-radius:50%}
.area{position:absolute;left:22%;width:56%;height:14%;border:2px solid rgba(255,255,255,.18)}
.area-cima{top:0;border-top:none}
.area-baixo{bottom:0;border-bottom:none}

.slot{position:absolute;transform:translate(-50%,-50%);width:66px;cursor:pointer;
  display:flex;flex-direction:column;align-items:center;gap:1px;
  background:linear-gradient(160deg,rgba(42,42,51,.96),rgba(18,18,24,.96));
  border:1px solid var(--borda2);border-radius:9px;padding:5px 3px;
  transition:transform .15s,border-color .15s,box-shadow .15s;
  animation:slotEnt .3s backwards}
@keyframes slotEnt{from{opacity:0;transform:translate(-50%,-50%) scale(.6)}}
.slot:hover{transform:translate(-50%,-50%) scale(1.09);border-color:var(--amarelo);z-index:5}
.slot.vazio{background:rgba(0,0,0,.42);border-style:dashed;border-color:rgba(255,255,255,.3)}
.slot.aberta{border-color:var(--amarelo);box-shadow:0 0 0 2px rgba(245,197,24,.4)}
.slot.sel{border-color:var(--azul);box-shadow:0 0 0 2px rgba(59,130,246,.5)}
.slot.lenda{border-color:#d4af37;background:linear-gradient(160deg,#4a3a12,#241a06)}
.slot .s-ovr{font-family:'Oswald',sans-serif;font-size:17px;line-height:1;color:var(--amarelo)}
.slot.lenda .s-ovr{color:#ffd966}
.slot .s-esc{width:15px;height:15px;object-fit:contain}
.slot .s-nome{font-size:9px;font-weight:700;line-height:1.1;text-align:center;
  max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.slot .s-rot{font-size:7.5px;font-weight:800;letter-spacing:.4px;color:var(--txt3)}
.slot .s-mais{font-size:19px;line-height:1;color:var(--txt3)}
.slot .s-quim{width:22px;height:3px;border-radius:99px;margin-top:1px}
.s-quim.alta{background:var(--verde)}
.s-quim.media{background:var(--amarelo)}
.s-quim.baixa{background:var(--vermelho)}

.banco-tit{margin:16px 0 8px;font-family:'Oswald',sans-serif;font-size:15px;letter-spacing:.4px}
.banco-tit span{color:var(--txt3);font-size:12px;font-family:'Montserrat',sans-serif}
.banco{display:grid;grid-template-columns:repeat(auto-fit,minmax(74px,1fr));gap:7px}
.banco-s{position:static;transform:none;width:auto}
.banco-s:hover{transform:scale(1.06)}
@keyframes slotEnt2{from{opacity:0;transform:scale(.7)}}
.banco-s{animation:slotEnt2 .3s backwards}

/* Cartas: entram em cascata */
.cartas-bloco{animation:sobe .3s}
@keyframes sobe{from{opacity:0;transform:translateY(10px)}}
.carta{animation:cartaEnt .35s backwards}
@keyframes cartaEnt{from{opacity:0;transform:translateY(16px) rotateX(18deg)}}
.carta.lenda{background:linear-gradient(160deg,#5a4616,#2a1d06);border-color:#d4af37;position:relative}
.carta.lenda .ovr{color:#ffd966}
.selo-lenda{position:absolute;top:7px;left:7px;font-size:8px;font-weight:800;letter-spacing:1px;
  background:#d4af37;color:#1a1304;padding:2px 6px;border-radius:99px}

/* Placar que anda com a narração */
.placar .g{transition:transform .2s}
.placar .g.pulsa{transform:scale(1.22);color:var(--verde)}
.relogio{text-align:center;font-family:'Oswald',sans-serif;font-size:14px;color:var(--txt3);
  letter-spacing:1px;margin-bottom:10px}
.lance.intervalo{border-left-color:var(--amarelo);background:rgba(245,197,24,.08);font-weight:700}
@media (max-width:620px){
  .campo{max-height:none}
  .slot{width:54px;padding:4px 2px}
  .slot .s-ovr{font-size:15px}
  .slot .s-nome{font-size:8px}
  .banco{grid-template-columns:repeat(4,1fr)}
}
@media (max-width:620px){
  .wrap{padding:12px}
  .esc-l{grid-template-columns:38px 1fr auto auto;gap:7px;font-size:12px}
  .placar .g{font-size:36px}
}
</style>
</head>
<body>

<div class="topo">
  <h1>FBA <span>Draft</span></h1>
  <div style="display:flex;gap:10px;align-items:center">
    <span class="saldo"><i class="bi bi-coin"></i> <?= number_format($moedas, 0, ',', '.') ?></span>
    <a class="btn" href="/games/"><i class="bi bi-arrow-left"></i> Games</a>
  </div>
</div>

<div class="wrap">
<?php if ($msg): ?><div class="aviso ok"><?= e($msg) ?></div><?php endif; ?>
<?php if ($erro): ?><div class="aviso err"><?= e($erro) ?></div><?php endif; ?>

<?php if (!$d): ?>
  <?php /* ── 1. ESCOLHER A FORMAÇÃO ─────────────────────────────── */ ?>
  <div class="bloco">
    <h2>Escolha a formação</h2>
    <p class="sub">Depois de começar você não troca mais — e as cinco cartas de cada vaga
       dependem dela. Entrada: <b><?= DF_ENTRADA ?> moedas</b>. Vitória paga
       <b><?= DF_VITORIA ?></b>, empate <b><?= DF_EMPATE ?></b>.</p>
    <div class="forms">
      <?php foreach (DFUT_FORMACOES as $nome => $vagas): ?>
        <form method="POST">
          <input type="hidden" name="acao" value="comecar">
          <input type="hidden" name="formacao" value="<?= e($nome) ?>">
          <button class="form-card" type="submit" <?= $moedas < DF_ENTRADA ? 'disabled' : '' ?>>
            <b><?= e($nome) ?></b>
            <small><?= e(implode(' · ', array_slice(array_unique(array_column($vagas, 0)), 0, 5))) ?></small>
          </button>
        </form>
      <?php endforeach; ?>
    </div>
    <?php if ($moedas < DF_ENTRADA): ?>
      <p class="sub" style="margin-top:12px;color:#ff5c7a">
        Você tem <?= $moedas ?> moedas e a entrada custa <?= DF_ENTRADA ?>.</p>
    <?php endif; ?>
  </div>

<?php elseif ($emDraft): ?>
  <?php if ($aberta !== null && $d['opcoes']):
    $natural = draftFutPosDaVaga($d['formacao'], $aberta); ?>
    <div class="bloco cartas-bloco">
      <h2>Escolha pra <?= e(draftFutRotuloDaVaga($d['formacao'], $aberta)) ?></h2>
      <p class="sub">Cinco cartas. A química muda conforme quem já está em campo.</p>
      <div class="cartas">
        <?php foreach ($d['opcoes'] as $i => $c): ?>
          <form method="POST" style="margin:0">
            <input type="hidden" name="acao" value="escolher">
            <input type="hidden" name="carta" value="<?= $i ?>">
            <button class="carta<?= !empty($c['lenda']) ? ' lenda' : '' ?>" type="submit"
                    style="animation-delay:<?= $i * 70 ?>ms">
              <?php if (!empty($c['lenda'])): ?><span class="selo-lenda">LENDA</span><?php endif; ?>
              <span class="ovr"><?= (int)$c['ovr'] ?></span>
              <span class="pos"><?= e($c['pos']) ?><?= $c['pos'] !== $natural ? ' ⚠' : '' ?></span>
              <?php if ($c['escudo']): ?><img src="<?= e($c['escudo']) ?>" alt="" loading="lazy"><?php endif; ?>
              <span class="nm"><?= e($c['nome']) ?></span>
              <span class="cl"><?= e($c['clube']) ?></span>
              <span class="lg"><?= e($c['liga']) ?> · <?= e(draftFutPais($c['liga'])) ?></span>
            </button>
          </form>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>
  <?php include __DIR__ . '/draftfut_campo.php'; ?>

<?php elseif ($pronto): ?>
  <div class="bloco">
    <h2>Time pronto</h2>
    <p class="sub">Dá o nome e escolhe o adversário.</p>
    <div class="nums">
      <div class="num"><b><?= draftFutForcaDoTime($d['formacao'], $d['time']) ?></b><small>Força</small></div>
      <div class="num"><b><?= $quim['total'] ?></b><small>Química</small></div>
      <div class="num"><b><?= e($d['formacao']) ?></b><small>Formação</small></div>
    </div>
    <form method="POST" style="display:flex;gap:10px;flex-wrap:wrap;align-items:center">
      <input type="hidden" name="acao" value="jogar">
      <input type="text" name="nome" maxlength="60" placeholder="Nome do seu time" required
             style="background:var(--panel3);border:1px solid var(--borda2);border-radius:10px;
                    color:var(--txt);padding:10px 13px;font:inherit;font-size:13px;flex:1;min-width:180px">
      <button class="btn pri" type="submit" name="modo" value="maquina">
        <i class="bi bi-cpu"></i> Contra a máquina</button>
      <button class="btn" type="submit" name="modo" value="pvp">
        <i class="bi bi-people-fill"></i> Contra outro GM</button>
    </form>
    <p class="sub" style="margin:10px 0 0;font-size:12px">
      Sem nenhum time de GM na sua faixa de força, o jogo cai na máquina — e avisa.</p>
  </div>
  <?php include __DIR__ . '/draftfut_campo.php'; ?>

<?php else:
  $r = $d['resultado']; $p = $r['partida']; ?>
  <div class="bloco">
    <h2>Partida</h2>
    <div class="relogio" id="relogio">0'</div>
    <div class="placar">
      <div class="t"><b><?= e($r['nome']) ?></b><small>força <?= $r['forca'] ?> · química <?= $r['quimica'] ?></small></div>
      <?php /* O PLACAR COMEÇA EM 0x0 E ANDA COM A NARRAÇÃO. Ele vinha pronto,
           com os lances contando depois o que já estava escrito em cima —
           era ler a última página antes do livro. Agora o gol aparece no
           minuto em que acontece. */ ?>
      <div class="g"><span id="gc">0</span> <span style="color:var(--txt3)">x</span> <span id="gf">0</span></div>
      <div class="t"><b><?= e($r['adv']['nome']) ?></b><small>força <?= $r['adv']['forca'] ?>
        · <?= $r['modo'] === 'pvp' ? 'outro GM' : 'máquina' ?></small></div>
    </div>
    <div class="narra" id="narra"></div>
    <div id="fecho" style="display:none">
      <div style="text-align:center;margin:14px 0">
        <?php if ($r['premio'] > 0): ?>
          <span class="aviso ok" style="display:inline-block"><i class="bi bi-coin"></i>
            +<?= $r['premio'] ?> moedas</span>
        <?php else: ?>
          <span class="aviso err" style="display:inline-block">Sem prêmio dessa vez.</span>
        <?php endif; ?>
      </div>
      <form method="POST" style="text-align:center"><input type="hidden" name="acao" value="novo">
        <button class="btn pri" type="submit"><i class="bi bi-arrow-repeat"></i> Novo draft</button></form>
    </div>
    <div style="margin-top:14px;text-align:center">
      <button class="btn" type="button" id="pular">Pular pro fim</button>
    </div>
  </div>
  <?php include __DIR__ . '/draftfut_campo.php'; ?>
  <script>
  /* A PARTIDA PASSA, ELA NÃO É LIDA. O relógio anda, o placar vira no
     minuto do gol e o prêmio só aparece no apito final — quem está vendo
     não sabe o resultado até ele acontecer. "Pular pro fim" existe pra
     quem já viu essa parte. */
  const LANCES = <?= json_encode($p['lances'], JSON_UNESCAPED_UNICODE) ?>;
  const alvo = document.getElementById('narra');
  const elGc = document.getElementById('gc'), elGf = document.getElementById('gf');
  const elRel = document.getElementById('relogio'), elG = document.querySelector('.placar .g');
  let i = 0, timer = null;

  function desenha(l, animar){
    const d = document.createElement('div');
    d.className = 'lance ' + l.tipo;
    if (!animar) d.style.animation = 'none';
    d.innerHTML = `<span class="m">${l.min}'</span><span>${l.texto}</span>`;
    alvo.appendChild(d);
    alvo.scrollTop = alvo.scrollHeight;

    elRel.textContent = l.min + "'";
    if (l.casa !== undefined) {
      const virou = elGc.textContent != l.casa || elGf.textContent != l.fora;
      elGc.textContent = l.casa; elGf.textContent = l.fora;
      if (virou && animar) { elG.classList.add('pulsa'); setTimeout(() => elG.classList.remove('pulsa'), 240); }
    }
    if (l.tipo === 'fim') document.getElementById('fecho').style.display = '';
  }

  function passo(){
    if (i >= LANCES.length) { clearInterval(timer); return; }
    desenha(LANCES[i++], true);
  }
  timer = setInterval(passo, 850);
  passo();
  document.getElementById('pular').onclick = () => {
    clearInterval(timer);
    while (i < LANCES.length) desenha(LANCES[i++], false);
  };
  </script>
<?php endif; ?>
</div>
<script>
/* ── O CAMPO: clicar abre a vaga, dois cliques trocam ────────────────
   Vaga vazia abre as cartas. Vaga cheia entra em modo "selecionada", e o
   clique seguinte troca os dois de lugar — é assim que se escala sem
   arrastar, que no celular nunca funciona direito. */
(function(){
  const campo = document.getElementById('campo');
  if (!campo) return;
  const todos = document.querySelectorAll('.slot');
  let sel = null;

  function post(campos){
    const f = document.createElement('form');
    f.method = 'POST';
    for (const [k,v] of Object.entries(campos)) {
      const i = document.createElement('input');
      i.type = 'hidden'; i.name = k; i.value = v;
      f.appendChild(i);
    }
    document.body.appendChild(f); f.submit();
  }

  todos.forEach(s => s.addEventListener('click', () => {
    const vaga = s.dataset.vaga, tem = s.dataset.tem === '1';

    if (sel !== null && sel !== vaga) { post({acao:'trocar', a:sel, b:vaga}); return; }
    if (sel === vaga) { s.classList.remove('sel'); sel = null; return; }
    if (!tem) { post({acao:'abrir', vaga}); return; }

    document.querySelectorAll('.slot.sel').forEach(o => o.classList.remove('sel'));
    s.classList.add('sel'); sel = vaga;
  }));
})();
</script>
</body>
</html>
