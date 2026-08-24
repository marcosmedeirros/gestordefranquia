<?php
/**
 * OS PEDIDOS DA LOJINHA: o que os GMs resgataram e ainda não foi aplicado.
 *
 * A loja não aplica nada sozinha, e isso é escolha: dar um slot de waiver ou
 * uma badge mexe em regra de liga, e cada uma dessas regras mora num sistema
 * diferente. Aplicar automático exigiria eu adivinhar o limite de cada um —
 * e limite adivinhado errado é reclamação que aparece semanas depois.
 *
 * Então o GM resgata, o item sai do inventário dele, e cai aqui. Quem aplica
 * é gente, e a tela só guarda o quê, de quem e quando — que é justamente o
 * que falta quando alguém diz "comprei e nunca veio".
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/loja.php';

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    exit('Apenas administradores.');
}

$msg = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['atender'])) {
    $ok = lojaAtender($pdo, (int)$_POST['atender'], (int)$user['id']);
    $msg = $ok ? 'Marcado como aplicado.' : 'Esse pedido já tinha sido atendido.';
    // Redireciona pra o F5 não reenviar o mesmo "atender".
    $_SESSION['loja_flash'] = $msg;
    header('Location: /loja-pedidos.php' . (!empty($_GET['tudo']) ? '?tudo=1' : ''));
    exit;
}
if (!empty($_SESSION['loja_flash'])) { $msg = $_SESSION['loja_flash']; unset($_SESSION['loja_flash']); }

$verTudo = !empty($_GET['tudo']);
$pedidos = lojaFilaDoAdmin($pdo, $verTudo);
$cat     = lojaCatalogo();
$naFila  = count(array_filter($pedidos, fn($p) => empty($p['atendido_em'])));
$esc     = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="theme-color" content="#fc0025">
<title>Pedidos da Loja — FBA Manager</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;700;800;900&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
  :root { --red:#fc0025; --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
          --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
          --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85; --green:#22c55e; --amber:#f59e0b;
          --font:'Montserrat',sans-serif; --radius:14px; --radius-sm:10px; }
  :root[data-theme="light"] { --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
          --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5a6070; --text-3:#7a8092; }
  *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text);font-family:var(--font);font-size:14px}
  .wrap{max-width:900px;margin:0 auto;padding:24px 18px 60px}
  .topo{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .topo h1{font-size:20px;font-weight:800;margin:0}
  .topo a{color:var(--text-3);text-decoration:none;font-size:13px}
  .topo a:hover{color:var(--text)}
  .sub{color:var(--text-3);font-size:13px;margin-bottom:20px;max-width:64ch;line-height:1.5}
  .aviso{background:color-mix(in srgb,var(--green) 10%,transparent);border:1px solid color-mix(in srgb,var(--green) 30%,transparent);
         color:var(--green);border-radius:var(--radius-sm);padding:11px 15px;font-size:13px;margin-bottom:16px}
  .filtros{display:flex;gap:7px;margin-bottom:16px}
  .filtros a{background:var(--panel-2);border:1px solid var(--border-md);color:var(--text-2);
             border-radius:20px;padding:6px 14px;font-size:12.5px;font-weight:700;text-decoration:none}
  .filtros a.on{background:color-mix(in srgb,var(--red) 10%,transparent);border-color:color-mix(in srgb,var(--red) 22%,transparent);color:var(--red)}
  .ped{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius-sm);
       padding:14px 16px;margin-bottom:9px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .ped.feito{opacity:.55}
  .ped .ico{width:38px;height:38px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:17px;flex:none}
  .ped .quem{flex:1;min-width:180px}
  .ped .quem b{display:block;font-size:14px;font-weight:700}
  .ped .quem span{font-size:12px;color:var(--text-3)}
  .ped .item{font-size:13px;font-weight:700;min-width:150px}
  .ped .item i{display:block;font-style:normal;font-size:11.5px;color:var(--text-3);font-weight:600}
  .btn{background:var(--green);border:0;color:#fff;font-family:var(--font);font-size:12.5px;font-weight:700;
       border-radius:var(--radius-sm);padding:9px 16px;cursor:pointer}
  .btn:hover{filter:brightness(1.1)}
  .selo{font-size:11px;font-weight:700;padding:4px 11px;border-radius:20px;
        background:color-mix(in srgb,var(--green) 12%,transparent);color:var(--green)}
  .vazio{text-align:center;padding:52px 20px;color:var(--text-3);background:var(--panel);
         border:1px solid var(--border);border-radius:var(--radius-sm)}
  .vazio i{font-size:32px;display:block;margin-bottom:11px;opacity:.5}
  @media (max-width:620px){ .ped{gap:10px} .ped .item{min-width:0;width:100%} .btn{width:100%} }
</style>
</head>
<body>
<div class="wrap">
  <div class="topo">
    <a href="/admin.php" title="Voltar ao admin"><i class="bi bi-arrow-left"></i></a>
    <h1>Pedidos da Loja</h1>
  </div>
  <div class="sub">
    O que os GMs resgataram e ainda não foi aplicado. A loja não mexe nas regras
    sozinha — quem dá a badge, libera o slot ou faz o uniforme é você. Marcar como
    aplicado é o registro de que foi feito.
  </div>

  <?php if ($msg): ?><div class="aviso"><i class="bi bi-check-circle-fill"></i> <?= $esc($msg) ?></div><?php endif; ?>

  <div class="filtros">
    <a class="<?= $verTudo ? '' : 'on' ?>" href="/loja-pedidos.php">Na fila<?= $naFila ? ' (' . $naFila . ')' : '' ?></a>
    <a class="<?= $verTudo ? 'on' : '' ?>" href="/loja-pedidos.php?tudo=1">Tudo</a>
  </div>

  <?php if (empty($pedidos)): ?>
    <div class="vazio">
      <i class="bi bi-inbox"></i>
      <p><?= $verTudo ? 'Ninguém resgatou nada ainda.' : 'Nada na fila. Tudo que foi resgatado já está aplicado.' ?></p>
    </div>
  <?php else: ?>
    <?php foreach ($pedidos as $p): $it = $cat[$p['item_key']] ?? null; ?>
    <div class="ped <?= $p['atendido_em'] ? 'feito' : '' ?>">
      <div class="ico" style="color:<?= $esc($it['cor'] ?? '#7d7d85') ?>;background:color-mix(in srgb, <?= $esc($it['cor'] ?? '#7d7d85') ?> 12%, transparent)">
        <i class="bi <?= $esc($it['icone'] ?? 'bi-box') ?>"></i>
      </div>
      <div class="quem">
        <b><?= $esc($p['gm']) ?></b>
        <span><?= $esc($p['time'] ?: 'sem time') ?><?= $p['league'] ? ' · ' . $esc($p['league']) : '' ?></span>
      </div>
      <div class="item">
        <?= $esc($it['nome'] ?? $p['item_key']) ?>
        <i>resgatado em <?= date('d/m/Y \à\s H:i', strtotime($p['usado_em'])) ?></i>
      </div>
      <?php if ($p['atendido_em']): ?>
        <span class="selo">aplicado em <?= date('d/m', strtotime($p['atendido_em'])) ?></span>
      <?php else: ?>
        <form method="POST" style="margin:0">
          <input type="hidden" name="atender" value="<?= (int)$p['id'] ?>">
          <button class="btn" type="submit"><i class="bi bi-check2"></i> Marcar aplicado</button>
        </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  <?php endif; ?>
</div>
</body>
</html>
