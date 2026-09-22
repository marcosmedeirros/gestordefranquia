<?php
/**
 * CARREIRA — o jogo de técnico, no estilo Brasfoot.
 *
 * Você assume um clube pequeno, cumpre a meta da diretoria pra não ser
 * demitido, compra e vende no mercado e vai subindo de clube conforme a
 * reputação cresce.
 *
 * O motor vive em games/core/: fut_motor (partida e tabela), fut_clubes_br
 * (os 98 clubes), fut_elencos (os jogadores), fut_mercado (preço e proposta) e
 * fut_carreira (o save e o laço do ano). Aqui é só a tela e o roteamento das
 * ações — a regra do jogo não mora neste arquivo, e não deve passar a morar.
 *
 * PRECISA DE LOGIN, ao contrário dos outros jogos do Games: uma carreira dura
 * temporadas e não faz sentido sem onde salvar. Quem não está logado vê o
 * convite pra entrar, e não um jogo que perde tudo ao fechar a aba.
 *
 * Os nomes de clube identificam dentro da simulação. O jogo não é afiliado,
 * patrocinado nem endossado por nenhum deles, não hospeda escudo, e os
 * jogadores dos clubes sem elenco real são personagens fictícios.
 */

session_start();
require_once __DIR__ . '/../../backend/db.php';
require_once __DIR__ . '/../core/fut_carreira.php';

$idUsuario = (int)($_SESSION['user_id'] ?? 0);
$pdo = db();

$erro = null;
$aviso = null;
$relatorio = null;

$estado = $idUsuario > 0 ? futCarreiraCarregar($pdo, $idUsuario) : null;

// ─────────────────────────────────────────────────────────────────────
//  AÇÕES
// ─────────────────────────────────────────────────────────────────────
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';
    try {
        if ($acao === 'comecar') {
            $tecnico = trim((string)($_POST['tecnico'] ?? '')) ?: 'Técnico';
            $clube = (string)($_POST['clube'] ?? '');
            $disponiveis = futClubesParaComecar(10);
            if (!isset($disponiveis[$clube])) {
                $erro = 'Esse clube não está disponível para quem está começando.';
            } else {
                $estado = futCarreiraNova(mb_substr($tecnico, 0, 40), $clube);
                futCarreiraSalvar($pdo, $idUsuario, $estado);
            }
        }

        elseif ($estado && $acao === 'iniciar_temporada') {
            $estado['calendario'] = futCarreiraMontarCalendario($estado);
            $estado['fase'] = 'temporada';
            futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        elseif ($estado && $acao === 'jogar') {
            /* Quantas partidas de uma vez. O "jogar a rodada" de uma em uma é o
               ritmo do jogo, mas ninguém quer clicar 50 vezes pra ver o fim do
               ano — por isso existe o pular pra frente. */
            $quantas = max(1, min(50, (int)($_POST['quantas'] ?? 1)));
            for ($i = 0; $i < $quantas; $i++) {
                $r = futCarreiraJogarProxima($estado);
                if ($r['fim']) { $estado['fase'] = 'fim'; break; }
                $estado = $r['estado'];
            }
            futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        elseif ($estado && $acao === 'fechar_temporada') {
            $f = futCarreiraFecharTemporada($estado);
            $estado = $f['estado'];
            $relatorio = $f['relatorio'];
            $_SESSION['fut_relatorio'] = $relatorio;
            futCarreiraSalvar($pdo, $idUsuario, $estado);
        }

        elseif ($estado && $acao === 'comprar') {
            $r = futCarreiraComprar($estado, (string)($_POST['clube_vendedor'] ?? ''),
                                    (string)($_POST['jogador'] ?? ''), (float)($_POST['oferta'] ?? 0));
            $estado = $r['estado'];
            if ($r['ok']) { $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
            else $erro = $r['motivo'];
        }

        elseif ($estado && $acao === 'vender') {
            $r = futCarreiraVender($estado, (string)($_POST['jogador'] ?? ''),
                                   (float)($_POST['oferta'] ?? 0), (string)($_POST['comprador'] ?? 'um clube'));
            $estado = $r['estado'];
            if ($r['ok']) { $aviso = $r['motivo']; futCarreiraSalvar($pdo, $idUsuario, $estado); }
            else $erro = $r['motivo'];
        }

        elseif ($acao === 'recomecar') {
            futCarreiraApagar($pdo, $idUsuario);
            $estado = null;
        }
    } catch (Throwable $e) {
        error_log('[carreira] ' . $e->getMessage());
        $erro = 'Deu ruim aqui. Tenta de novo.';
    }

    /* POST-REDIRECT-GET: sem isso, o F5 depois de jogar uma rodada joga a
       rodada de novo, e o jogador perde partidas sem entender por quê. */
    if (!$erro) {
        $aba = $_POST['aba'] ?? ($_GET['aba'] ?? 'jogo');
        $q = $aviso ? '&msg=' . rawurlencode($aviso) : '';
        header('Location: carreira.php?aba=' . rawurlencode($aba) . $q);
        exit;
    }
}

if (!$aviso && !empty($_GET['msg'])) $aviso = mb_substr((string)$_GET['msg'], 0, 200);
if (!$relatorio && !empty($_SESSION['fut_relatorio'])) {
    $relatorio = $_SESSION['fut_relatorio'];
    unset($_SESSION['fut_relatorio']);
}

$aba = (string)($_GET['aba'] ?? 'jogo');
$meuClube = $estado ? futCarreiraMeuClube($estado) : null;
$clubesTodos = futClubesDoBrasil();

/** Escapa pra HTML. */
function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** O escudo do clube, ou um monograma quando não há imagem. */
function escudo(array $c, int $tam = 26): string
{
    $url = $c['escudo'] ?? '';
    if ($url !== '') {
        return '<img src="' . h($url) . '" alt="" width="' . $tam . '" height="' . $tam . '" loading="lazy" style="object-fit:contain;flex-shrink:0">';
    }
    $ini = mb_strtoupper(mb_substr($c['nome'] ?? '?', 0, 2));
    return '<span class="mono" style="width:' . $tam . 'px;height:' . $tam . 'px;font-size:' . round($tam * 0.38) . 'px">' . h($ini) . '</span>';
}

$proximo = null;
if ($estado && ($estado['fase'] ?? '') === 'temporada') {
    $cal = $estado['calendario'] ?? [];
    $i = (int)($estado['rodada'] ?? 0);
    $proximo = $cal[$i] ?? null;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Carreira — Técnico de Futebol</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#0a0a0c; --panel:#131316; --panel2:#1a1a1f; --panel3:#212127;
  --borda:#26262d; --borda2:#33333c;
  --txt:#f4f4f5; --txt2:#a1a1aa; --txt3:#71717a;
  --verde:#16a34a; --verde-claro:#22c55e; --vermelho:#ef4444;
  --amarelo:#f59e0b; --azul:#3b82f6;
}
*{box-sizing:border-box}
body{margin:0;background:var(--bg);color:var(--txt);font-family:'Inter',system-ui,-apple-system,sans-serif;
  font-size:14px;line-height:1.5;-webkit-font-smoothing:antialiased}
#app{max-width:1100px;margin:0 auto;padding:16px 14px 80px}
h1,h2,h3{margin:0;letter-spacing:-.4px}
button,input,select{font-family:inherit}
a{color:inherit}

.mono{display:inline-flex;align-items:center;justify-content:center;border-radius:7px;
  background:var(--panel3);border:1px solid var(--borda);font-weight:800;color:var(--txt2);flex-shrink:0}

/* ── Topo ───────────────────────────────────────────── */
.topo{display:flex;align-items:center;gap:10px;margin-bottom:14px;flex-wrap:wrap}
.marca{display:flex;align-items:center;gap:9px;font-weight:900;font-size:17px;letter-spacing:-.6px}
.marca i{color:var(--verde-claro)}
.voltar{display:inline-flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--borda);background:transparent;color:var(--txt2);text-decoration:none;flex-shrink:0}
.voltar:hover{border-color:var(--verde);color:var(--verde-claro)}

/* ── Cartão do clube ────────────────────────────────── */
.clube-card{background:linear-gradient(135deg,var(--panel2),var(--panel));border:1px solid var(--borda);
  border-radius:14px;padding:14px;margin-bottom:14px}
.clube-topo{display:flex;align-items:center;gap:12px}
.clube-nome{font-size:19px;font-weight:900;letter-spacing:-.5px;line-height:1.15}
.clube-sub{font-size:12px;color:var(--txt2)}
.fichas{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;margin-top:12px}
.ficha{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;padding:8px 9px;text-align:center}
.ficha .v{font-size:16px;font-weight:900;letter-spacing:-.5px;line-height:1.1}
.ficha .r{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.meta-linha{margin-top:10px;padding:9px 11px;border-radius:9px;background:rgba(245,158,11,.10);
  border:1px solid rgba(245,158,11,.28);font-size:12.5px;display:flex;gap:8px;align-items:flex-start}
.meta-linha i{color:var(--amarelo);margin-top:1px}

/* ── Abas ───────────────────────────────────────────── */
.abas{display:flex;gap:6px;margin-bottom:14px;overflow-x:auto;padding-bottom:2px;-webkit-overflow-scrolling:touch}
.abas a{flex:0 0 auto;padding:8px 14px;border-radius:9px;border:1px solid var(--borda);background:var(--panel);
  color:var(--txt2);text-decoration:none;font-size:13px;font-weight:700;white-space:nowrap}
.abas a.on{background:var(--verde);border-color:var(--verde);color:#fff}

/* ── Blocos ─────────────────────────────────────────── */
.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:13px;padding:14px;margin-bottom:12px}
.bloco h3{font-size:14px;font-weight:800;margin-bottom:10px;display:flex;align-items:center;gap:7px}
.bloco h3 i{color:var(--verde-claro)}

.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;border-radius:10px;
  border:1px solid var(--verde);background:var(--verde);color:#fff;font-weight:800;font-size:14px;cursor:pointer}
.btn:hover{background:var(--verde-claro)}
.btn.sec{background:transparent;color:var(--txt2);border-color:var(--borda2)}
.btn.sec:hover{color:var(--txt);border-color:var(--txt3)}
.btn.peq{padding:6px 11px;font-size:12px;border-radius:8px}
.btn:disabled{opacity:.45;cursor:not-allowed}

input[type=text],input[type=number],select{width:100%;padding:10px 12px;border-radius:9px;border:1px solid var(--borda2);
  background:var(--panel3);color:var(--txt);font-size:14px}
label{display:block;font-size:12px;color:var(--txt2);margin-bottom:5px;font-weight:600}

/* ── Tabelas ────────────────────────────────────────── */
.rolar{overflow-x:auto;-webkit-overflow-scrolling:touch;margin:0 -14px;padding:0 14px}
table{width:100%;border-collapse:collapse;font-size:13px;min-width:460px}
th{text-align:left;font-size:10.5px;text-transform:uppercase;letter-spacing:.5px;color:var(--txt3);
  padding:6px 7px;border-bottom:1px solid var(--borda);font-weight:700;white-space:nowrap}
td{padding:7px;border-bottom:1px solid var(--borda);white-space:nowrap}
tr:last-child td{border-bottom:0}
td.num,th.num{text-align:right;font-variant-numeric:tabular-nums}
tr.eu td{background:rgba(34,197,94,.10);font-weight:700}
.pos{display:inline-flex;align-items:center;justify-content:center;width:20px;height:20px;border-radius:6px;
  background:var(--panel3);font-size:11px;font-weight:800;color:var(--txt2)}
.pos.sobe{background:rgba(34,197,94,.20);color:var(--verde-claro)}
.pos.cai{background:rgba(239,68,68,.18);color:#fca5a5}

.ovr{display:inline-flex;align-items:center;justify-content:center;min-width:28px;padding:2px 6px;border-radius:6px;
  font-weight:800;font-size:12px;background:var(--panel3);border:1px solid var(--borda)}
.ovr.b{background:rgba(34,197,94,.18);border-color:rgba(34,197,94,.35);color:var(--verde-claro)}
.ovr.m{background:rgba(245,158,11,.15);border-color:rgba(245,158,11,.3);color:var(--amarelo)}
.tagpos{font-size:10px;font-weight:800;color:var(--txt3);letter-spacing:.4px}

/* ── Resultado da partida ───────────────────────────── */
.partida{display:flex;align-items:center;gap:10px;padding:10px 0;border-bottom:1px solid var(--borda)}
.partida:last-child{border-bottom:0}
.partida .comp{font-size:10px;color:var(--txt3);text-transform:uppercase;letter-spacing:.4px}
.partida .adv{font-weight:700;font-size:13.5px}
.placar{margin-left:auto;font-weight:900;font-size:15px;padding:4px 10px;border-radius:8px;
  background:var(--panel3);font-variant-numeric:tabular-nums;flex-shrink:0}
.placar.v{background:rgba(34,197,94,.18);color:var(--verde-claro)}
.placar.d{background:rgba(239,68,68,.16);color:#fca5a5}

.msg{padding:10px 12px;border-radius:10px;margin-bottom:12px;font-size:13px;display:flex;gap:8px;align-items:flex-start}
.msg.ok{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.3)}
.msg.err{background:rgba(239,68,68,.10);border:1px solid rgba(239,68,68,.3)}

.vazio{text-align:center;padding:26px 14px;color:var(--txt3);font-size:13px}

/* ── Celular ────────────────────────────────────────── */
@media (max-width:560px){
  #app{padding:12px 12px 80px}
  .fichas{grid-template-columns:repeat(2,1fr)}
  .clube-nome{font-size:17px}
  .ficha .v{font-size:15px}
  table{min-width:420px}
}
</style>
</head>
<body>
<div id="app">

  <div class="topo">
    <a href="../games.php" class="voltar" title="Voltar"><i class="bi bi-arrow-left"></i></a>
    <div class="marca"><i class="bi bi-trophy-fill"></i> Carreira</div>
  </div>

<?php if ($idUsuario <= 0): ?>
  <div class="bloco">
    <h3><i class="bi bi-person-lock"></i> Precisa entrar</h3>
    <p style="color:var(--txt2);margin:0 0 12px">
      A carreira dura temporadas e fica salva na sua conta. Entre na FBA pra começar a sua.
    </p>
    <a class="btn" href="../../login.php"><i class="bi bi-box-arrow-in-right"></i> Entrar</a>
  </div>

<?php elseif (!$estado): ?>
  <?php $disponiveis = futClubesParaComecar(10); ?>
  <div class="bloco">
    <h3><i class="bi bi-flag-fill"></i> Começar a carreira</h3>
    <p style="color:var(--txt2);margin:0 0 14px;font-size:13px">
      Você começa sem currículo, então os grandes ainda não te atendem. Cumpra as metas,
      ganhe reputação, e os clubes maiores vêm atrás.
    </p>
    <?php if ($erro): ?><div class="msg err"><i class="bi bi-exclamation-triangle"></i><?= h($erro) ?></div><?php endif; ?>
    <form method="post">
      <input type="hidden" name="acao" value="comecar">
      <div style="margin-bottom:12px">
        <label for="tecnico">Seu nome</label>
        <input type="text" id="tecnico" name="tecnico" maxlength="40" placeholder="Como você quer ser chamado" required>
      </div>
      <div style="margin-bottom:14px">
        <label for="clube">Clube</label>
        <select id="clube" name="clube" required>
          <?php foreach ($disponiveis as $nome => $c): ?>
            <option value="<?= h($nome) ?>"><?= h($nome) ?> — <?= h($c['div'] ?: 'estadual') ?>, força <?= (int)$c['forca'] ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <button class="btn" type="submit"><i class="bi bi-play-fill"></i> Assumir o clube</button>
    </form>
  </div>

<?php else: ?>
  <?php
    $campNac = futCarreiraCampanha($estado, futCarreiraNomeDaDivisao($clubesTodos[$estado['clube']]['div'] ?? ''));
    $posicao = futCarreiraMinhaPosicao($estado);
    $folha = futFolhaDoElenco($estado['elenco']);
  ?>

  <div class="clube-card">
    <div class="clube-topo">
      <?= escudo($clubesTodos[$estado['clube']] ?? ['nome' => $estado['clube']], 42) ?>
      <div style="min-width:0">
        <div class="clube-nome"><?= h($estado['clube']) ?></div>
        <div class="clube-sub">
          <?= h($estado['tecnico']['nome']) ?> ·
          <?= h($clubesTodos[$estado['clube']]['div'] ?? 'estadual') ?> ·
          temporada <?= (int)$estado['temporada'] ?> (<?= (int)$estado['ano'] ?>)
        </div>
      </div>
    </div>
    <div class="fichas">
      <div class="ficha"><div class="v"><?= number_format((float)$estado['caixa'], 1, ',', '.') ?></div><div class="r">caixa (mi)</div></div>
      <div class="ficha"><div class="v"><?= (int)$meuClube['forca'] ?></div><div class="r">força</div></div>
      <div class="ficha"><div class="v"><?= (int)$estado['tecnico']['reputacao'] ?></div><div class="r">reputação</div></div>
      <div class="ficha"><div class="v"><?= $posicao ? $posicao . 'º' : '—' ?></div><div class="r">posição</div></div>
    </div>
    <div class="meta-linha">
      <i class="bi bi-bullseye"></i>
      <div><strong>Meta da diretoria:</strong> <?= h($estado['meta']['texto'] ?? '—') ?>
      <?php if (($estado['falhas'] ?? 0) >= 1): ?>
        <span style="color:#fca5a5"> · você já falhou uma vez. Outra e está fora.</span>
      <?php endif; ?>
      </div>
    </div>
  </div>

  <?php if ($erro): ?><div class="msg err"><i class="bi bi-exclamation-triangle"></i><?= h($erro) ?></div><?php endif; ?>
  <?php if ($aviso): ?><div class="msg ok"><i class="bi bi-check-circle"></i><?= h($aviso) ?></div><?php endif; ?>

  <?php if ($relatorio): ?>
    <div class="bloco" style="border-color:<?= $relatorio['demitido'] ? 'rgba(239,68,68,.4)' : 'rgba(34,197,94,.4)' ?>">
      <h3><i class="bi bi-calendar-check"></i> Fim de temporada</h3>
      <p style="margin:0 0 10px;font-size:13.5px">
        Terminou em <strong><?= $relatorio['posicao'] ? $relatorio['posicao'] . 'º' : '—' ?></strong>.
        Meta: <?= h($relatorio['meta']['texto']) ?> —
        <strong style="color:<?= $relatorio['cumpriu'] ? 'var(--verde-claro)' : '#fca5a5' ?>">
          <?= $relatorio['cumpriu'] ? 'cumprida' : 'não cumprida' ?></strong>.
      </p>
      <?php if ($relatorio['titulo']): ?>
        <p style="margin:0 0 10px;color:var(--amarelo);font-weight:800">
          <i class="bi bi-trophy-fill"></i> <?= h($relatorio['titulo']) ?>!</p>
      <?php endif; ?>
      <div class="rolar"><table><tbody>
        <tr><td>Receita do ano</td><td class="num">+<?= number_format($relatorio['receita'], 1, ',', '.') ?></td></tr>
        <tr><td>Premiação</td><td class="num">+<?= number_format($relatorio['premio'], 1, ',', '.') ?></td></tr>
        <tr><td>Folha salarial</td><td class="num">−<?= number_format($relatorio['folha'], 1, ',', '.') ?></td></tr>
        <tr><td><strong>Caixa agora</strong></td><td class="num"><strong><?= number_format($relatorio['caixa'], 1, ',', '.') ?></strong></td></tr>
      </tbody></table></div>
      <?php if ($relatorio['demitido']): ?>
        <div class="msg err" style="margin-top:12px"><i class="bi bi-door-open"></i>
          Você foi demitido. Duas temporadas sem cumprir a meta.</div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="abas">
    <?php foreach (['jogo' => 'Partidas', 'elenco' => 'Elenco', 'tabela' => 'Tabela', 'mercado' => 'Mercado', 'carreira' => 'Carreira'] as $k => $rot): ?>
      <a href="?aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php // ── ABA: PARTIDAS ──────────────────────────────────────────── ?>
  <?php if ($aba === 'jogo'): ?>
    <?php if (($estado['fase'] ?? '') === 'mercado'): ?>
      <div class="bloco">
        <h3><i class="bi bi-calendar-plus"></i> Pré-temporada</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          Ajuste o elenco no mercado antes de começar. Depois que a temporada começar,
          o calendário corre até o fim do ano.
        </p>
        <form method="post"><input type="hidden" name="acao" value="iniciar_temporada">
          <button class="btn"><i class="bi bi-play-fill"></i> Começar a temporada</button>
        </form>
      </div>

    <?php elseif (($estado['fase'] ?? '') === 'fim'): ?>
      <div class="bloco">
        <h3><i class="bi bi-flag"></i> Temporada encerrada</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          Todos os jogos do ano acabaram. Feche a temporada pra ver o balanço e o que a diretoria decidiu.
        </p>
        <form method="post"><input type="hidden" name="acao" value="fechar_temporada">
          <button class="btn"><i class="bi bi-calendar-check"></i> Fechar a temporada</button>
        </form>
      </div>

    <?php elseif (($estado['fase'] ?? '') === 'desempregado'): ?>
      <div class="bloco">
        <h3><i class="bi bi-door-open"></i> Sem clube</h3>
        <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
          Você está desempregado. Recomece uma carreira pra voltar a dirigir.
        </p>
        <form method="post"><input type="hidden" name="acao" value="recomecar">
          <button class="btn sec"><i class="bi bi-arrow-repeat"></i> Nova carreira</button>
        </form>
      </div>

    <?php else: ?>
      <?php if ($proximo): ?>
        <div class="bloco">
          <h3><i class="bi bi-calendar-event"></i> Próxima partida</h3>
          <div style="display:flex;align-items:center;gap:11px;margin-bottom:12px">
            <?= escudo($clubesTodos[$proximo['adversario']] ?? ['nome' => $proximo['adversario']], 34) ?>
            <div style="min-width:0">
              <div style="font-weight:800;font-size:15px"><?= h($proximo['adversario']) ?></div>
              <div style="font-size:11.5px;color:var(--txt2)">
                <?= h($proximo['comp']) ?> · <?= h($proximo['fase'] ?? '') ?> ·
                <?= $proximo['casa'] ? 'em casa' : 'fora' ?>
              </div>
            </div>
          </div>
          <div style="display:flex;gap:8px;flex-wrap:wrap">
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="1">
              <button class="btn"><i class="bi bi-play-fill"></i> Jogar</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="5">
              <button class="btn sec"><i class="bi bi-fast-forward-fill"></i> Jogar 5</button>
            </form>
            <form method="post" style="display:inline">
              <input type="hidden" name="acao" value="jogar"><input type="hidden" name="quantas" value="50">
              <button class="btn sec"><i class="bi bi-skip-end-fill"></i> Até o fim</button>
            </form>
          </div>
          <div style="margin-top:10px;font-size:12px;color:var(--txt3)">
            Jogo <?= (int)$estado['rodada'] + 1 ?> de <?= count($estado['calendario'] ?? []) ?>
          </div>
        </div>
      <?php endif; ?>

      <div class="bloco">
        <h3><i class="bi bi-list-ul"></i> Últimos resultados</h3>
        <?php $ult = array_reverse(array_slice($estado['resultados'] ?? [], -12)); ?>
        <?php if (!$ult): ?><div class="vazio">Nenhum jogo ainda.</div><?php endif; ?>
        <?php foreach ($ult as $r): ?>
          <?php $cls = $r['meus'] > $r['deles'] ? 'v' : ($r['meus'] < $r['deles'] ? 'd' : ''); ?>
          <div class="partida">
            <?= escudo($clubesTodos[$r['adversario']] ?? ['nome' => $r['adversario']], 24) ?>
            <div style="min-width:0">
              <div class="comp"><?= h($r['comp']) ?> · <?= $r['casa'] ? 'casa' : 'fora' ?></div>
              <div class="adv"><?= h($r['adversario']) ?></div>
            </div>
            <div class="placar <?= $cls ?>"><?= (int)$r['meus'] ?>–<?= (int)$r['deles'] ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

  <?php // ── ABA: ELENCO ────────────────────────────────────────────── ?>
  <?php elseif ($aba === 'elenco'): ?>
    <div class="bloco">
      <h3><i class="bi bi-people-fill"></i> Elenco (<?= count($estado['elenco']) ?>)</h3>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:10px">
        Folha: <strong><?= number_format($folha, 2, ',', '.') ?> mi/ano</strong> ·
        Mínimo <?= FUT_ELENCO_MINIMO ?>, máximo <?= FUT_ELENCO_MAXIMO ?> jogadores
      </div>
      <div class="rolar"><table>
        <thead><tr>
          <th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Idade</th>
          <th class="num">Valor</th><th class="num">Salário</th><th></th>
        </tr></thead>
        <tbody>
        <?php
          $elenco = $estado['elenco'];
          usort($elenco, fn($a, $b) => $b['ovr'] <=> $a['ovr']);
          foreach ($elenco as $j):
            $v = futValorDeMercado((int)$j['ovr'], (int)$j['idade']);
            $s = futSalarioDe((int)$j['ovr'], (int)$j['idade']);
            $cls = $j['ovr'] >= 80 ? 'b' : ($j['ovr'] >= 70 ? 'm' : '');
        ?>
          <tr>
            <td><?= h($j['nome']) ?></td>
            <td><span class="tagpos"><?= h($j['pos']) ?></span></td>
            <td class="num"><span class="ovr <?= $cls ?>"><?= (int)$j['ovr'] ?></span></td>
            <td class="num"><?= (int)$j['idade'] ?></td>
            <td class="num"><?= number_format($v, 2, ',', '.') ?></td>
            <td class="num"><?= number_format($s, 2, ',', '.') ?></td>
            <td class="num">
              <form method="post" style="display:inline">
                <input type="hidden" name="acao" value="vender">
                <input type="hidden" name="aba" value="elenco">
                <input type="hidden" name="jogador" value="<?= h($j['nome']) ?>">
                <input type="hidden" name="oferta" value="<?= $v ?>">
                <input type="hidden" name="comprador" value="um clube interessado">
                <button class="btn sec peq" type="submit">Vender</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table></div>
    </div>

  <?php // ── ABA: TABELA ────────────────────────────────────────────── ?>
  <?php elseif ($aba === 'tabela'): ?>
    <?php $tab = futCarreiraTabelaNacional($estado); ?>
    <div class="bloco">
      <h3><i class="bi bi-table"></i> <?= h(futCarreiraNomeDaDivisao($clubesTodos[$estado['clube']]['div'] ?? '') ?: 'Classificação') ?></h3>
      <?php if (!$tab): ?>
        <div class="vazio">A tabela aparece depois da primeira rodada do nacional.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr><th></th><th>Clube</th><th class="num">P</th><th class="num">J</th>
            <th class="num">V</th><th class="num">E</th><th class="num">D</th><th class="num">SG</th></tr></thead>
          <tbody>
          <?php $i = 0; $total = count($tab); foreach ($tab as $nome => $l): $i++;
            $clsPos = $i <= 4 ? 'sobe' : ($i > $total - 4 ? 'cai' : ''); ?>
            <tr class="<?= $nome === $estado['clube'] ? 'eu' : '' ?>">
              <td><span class="pos <?= $clsPos ?>"><?= $i ?></span></td>
              <td><?= h($nome) ?></td>
              <td class="num"><strong><?= (int)$l['p'] ?></strong></td>
              <td class="num"><?= (int)$l['j'] ?></td>
              <td class="num"><?= (int)$l['v'] ?></td>
              <td class="num"><?= (int)$l['e'] ?></td>
              <td class="num"><?= (int)$l['d'] ?></td>
              <td class="num"><?= $l['sg'] > 0 ? '+' : '' ?><?= (int)$l['sg'] ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <?php // ── ABA: MERCADO ───────────────────────────────────────────── ?>
  <?php elseif ($aba === 'mercado'): ?>
    <?php
      $div = $clubesTodos[$estado['clube']]['div'] ?? '';
      /* O mercado mostra os clubes da sua divisão e da de baixo: é onde o
         clube realmente compra. Varrer os 98 deixaria a lista lenta e cheia
         de nome que não interessa a ninguém. */
      $ordem = ['BR1' => ['BR1', 'BR2'], 'BR2' => ['BR2', 'BR3'], 'BR3' => ['BR3', '']];
      $divs = $ordem[$div] ?? ['BR3', ''];
      $fonte = [];
      foreach ($divs as $d) {
        foreach (futClubesDoBrasil() as $c) if (($c['div'] ?? '') === $d && $c['nome'] !== $estado['clube']) $fonte[] = $c;
      }
      $fonte = array_slice($fonte, 0, 28);
      $lista = futMercadoDisponivel($fonte, (float)$estado['caixa'], 40, $estado['saidas'] ?? []);
    ?>
    <div class="bloco">
      <h3><i class="bi bi-cart-fill"></i> Mercado</h3>
      <div style="font-size:12px;color:var(--txt2);margin-bottom:10px">
        Caixa: <strong><?= number_format((float)$estado['caixa'], 2, ',', '.') ?> mi</strong>.
        O clube pede mais que o valor de tabela quando o jogador é importante pra ele.
      </div>
      <?php if (!$lista): ?>
        <div class="vazio">Nada no seu alcance agora. Venda alguém ou espere a próxima temporada.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr><th>Jogador</th><th>Pos</th><th class="num">OVR</th><th class="num">Idade</th>
            <th>Clube</th><th class="num">Pede</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($lista as $m):
            $cls = $m['ovr'] >= 80 ? 'b' : ($m['ovr'] >= 70 ? 'm' : '');
            $podePagar = $m['pedido'] <= (float)$estado['caixa']; ?>
            <tr>
              <td><?= h($m['nome']) ?></td>
              <td><span class="tagpos"><?= h($m['pos']) ?></span></td>
              <td class="num"><span class="ovr <?= $cls ?>"><?= (int)$m['ovr'] ?></span></td>
              <td class="num"><?= (int)$m['idade'] ?></td>
              <td style="font-size:12px;color:var(--txt2)"><?= h($m['clube']) ?></td>
              <td class="num"><?= number_format($m['pedido'], 2, ',', '.') ?></td>
              <td class="num">
                <form method="post" style="display:inline">
                  <input type="hidden" name="acao" value="comprar">
                  <input type="hidden" name="aba" value="mercado">
                  <input type="hidden" name="clube_vendedor" value="<?= h($m['clube']) ?>">
                  <input type="hidden" name="jogador" value="<?= h($m['nome']) ?>">
                  <input type="hidden" name="oferta" value="<?= $m['pedido'] ?>">
                  <button class="btn peq" type="submit" <?= $podePagar ? '' : 'disabled' ?>>Comprar</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

  <?php // ── ABA: CARREIRA ──────────────────────────────────────────── ?>
  <?php else: ?>
    <div class="bloco">
      <h3><i class="bi bi-person-badge"></i> <?= h($estado['tecnico']['nome']) ?></h3>
      <div class="fichas" style="grid-template-columns:repeat(3,1fr)">
        <div class="ficha"><div class="v"><?= count($estado['historico'] ?? []) ?></div><div class="r">temporadas</div></div>
        <div class="ficha"><div class="v"><?= count($estado['titulos'] ?? []) ?></div><div class="r">títulos</div></div>
        <div class="ficha"><div class="v"><?= (int)$estado['tecnico']['reputacao'] ?></div><div class="r">reputação</div></div>
      </div>
    </div>

    <?php if (!empty($estado['titulos'])): ?>
      <div class="bloco">
        <h3><i class="bi bi-trophy-fill"></i> Títulos</h3>
        <?php foreach ($estado['titulos'] as $t): ?>
          <div style="padding:6px 0;border-bottom:1px solid var(--borda)"><?= h($t) ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <div class="bloco">
      <h3><i class="bi bi-clock-history"></i> Histórico</h3>
      <?php if (empty($estado['historico'])): ?>
        <div class="vazio">Sua primeira temporada ainda está em andamento.</div>
      <?php else: ?>
        <div class="rolar"><table>
          <thead><tr><th>Ano</th><th>Clube</th><th>Competição</th><th class="num">Pos</th><th>Meta</th></tr></thead>
          <tbody>
          <?php foreach (array_reverse($estado['historico']) as $hst): ?>
            <tr>
              <td><?= (int)$hst['ano'] ?></td>
              <td><?= h($hst['clube']) ?></td>
              <td style="font-size:12px;color:var(--txt2)"><?= h($hst['comp'] ?: '—') ?></td>
              <td class="num"><?= $hst['posicao'] ? $hst['posicao'] . 'º' : '—' ?></td>
              <td><?= $hst['cumpriu']
                    ? '<span style="color:var(--verde-claro)">cumprida</span>'
                    : '<span style="color:#fca5a5">falhou</span>' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table></div>
      <?php endif; ?>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-arrow-repeat"></i> Recomeçar</h3>
      <p style="color:var(--txt2);font-size:13px;margin:0 0 12px">
        Apaga esta carreira e começa outra do zero. Não dá pra desfazer.
      </p>
      <form method="post" onsubmit="return confirm('Apagar esta carreira e começar outra?')">
        <input type="hidden" name="acao" value="recomecar">
        <button class="btn sec" type="submit">Apagar e recomeçar</button>
      </form>
    </div>
  <?php endif; ?>

<?php endif; ?>

</div>
</body>
</html>
