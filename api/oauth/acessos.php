<?php
/**
 * Quem está conectado no MCP, e o botão de cortar.
 *
 * A tela de autorização promete que dá pra cancelar depois; é aqui. Mostra os
 * aplicativos que receberam acesso, quando usaram pela última vez, e revoga
 * com um clique. Só admin geral — é a mesma porta da autorização.
 *
 * Endereço: /api/oauth/acessos.php
 */

require_once __DIR__ . '/../../backend/mcp_oauth.php';

$pdo = db();
$user = getUserSession();
if (!$user) {
    header('Location: /login.php?next=' . urlencode('/api/oauth/acessos.php'));
    exit;
}
if (!hasGlobalAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    echo 'Só o administrador geral.';
    exit;
}

mcpOauthGarantirTabelas($pdo);
$recado = '';

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)($_SESSION['mcp_acessos_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        $recado = 'A página ficou aberta tempo demais. Recarregue e tente de novo.';
    } elseif (!empty($_POST['revogar'])) {
        $clientId = (string)$_POST['revogar'];
        $pdo->prepare("UPDATE mcp_oauth_tokens SET revogado = 1 WHERE client_id = ?")->execute([$clientId]);
        $pdo->prepare("DELETE FROM mcp_oauth_codes WHERE client_id = ?")->execute([$clientId]);
        mcpRegistrar($pdo, 'oauth/revogar', ['client_id' => $clientId], false, true, 'acesso revogado');
        $recado = 'Acesso revogado. O aplicativo vai pedir autorização de novo na próxima vez.';
    }
}
$_SESSION['mcp_acessos_csrf'] = bin2hex(random_bytes(16));

$st = $pdo->query("SELECT c.client_id, c.client_name, c.criado_em,
                          COUNT(CASE WHEN t.revogado = 0 AND t.expira_em > NOW() THEN 1 END) AS vivos,
                          MAX(t.usado_em) AS ultimo_uso, MAX(u.name) AS dono
                     FROM mcp_oauth_clients c
                LEFT JOIN mcp_oauth_tokens t ON t.client_id = c.client_id
                LEFT JOIN users u ON u.id = t.user_id
                 GROUP BY c.client_id, c.client_name, c.criado_em
                 ORDER BY c.criado_em DESC");
$clientes = $st->fetchAll(PDO::FETCH_ASSOC);

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
?><!doctype html>
<html lang="pt-br">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Acessos do MCP · FBA</title>
<style>
:root{color-scheme:dark}
body{margin:0;background:#0d0d10;color:#e8e8ea;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px}
.caixa{max-width:720px;margin:0 auto}
h1{font-size:22px;margin:0 0 4px}
.sub{color:#9a9aa6;font-size:13.5px;margin:0 0 22px}
.recado{background:#12241a;border:1px solid #1f4a31;color:#8fe0ac;border-radius:10px;padding:10px 12px;font-size:14px;margin-bottom:16px}
.item{background:#16161b;border:1px solid #26262e;border-radius:14px;padding:16px 18px;margin-bottom:12px;
      display:flex;gap:14px;align-items:center;flex-wrap:wrap}
.item h2{font-size:16px;margin:0 0 2px;font-weight:600}
.meta{color:#8a8a95;font-size:12.5px;margin:0}
.cresce{flex:1;min-width:200px}
.tag{font-size:11.5px;padding:3px 9px;border-radius:99px;font-weight:600}
.on{background:#123021;color:#7fd6a0}.off{background:#24242c;color:#8a8a95}
button{border:0;border-radius:9px;padding:9px 14px;font-size:14px;font-weight:600;cursor:pointer;
       background:#2a1214;color:#f0a0a8;font-family:inherit}
button:hover{background:#3a181b}
.vazio{color:#75757f;font-size:14px}
</style>
</head>
<body>
<div class="caixa">
  <h1>Acessos do MCP</h1>
  <p class="sub">Aplicativos que podem conversar com a liga em seu nome.</p>

  <?php if ($recado): ?><div class="recado"><?= $e($recado) ?></div><?php endif; ?>

  <?php if (!$clientes): ?>
    <p class="vazio">Nenhum aplicativo conectado ainda.</p>
  <?php endif; ?>

  <?php foreach ($clientes as $c): $ativo = (int)$c['vivos'] > 0; ?>
    <div class="item">
      <div class="cresce">
        <h2><?= $e($c['client_name'] ?: 'aplicativo sem nome') ?></h2>
        <p class="meta">
          registrado em <?= $e(substr((string)$c['criado_em'], 0, 16)) ?>
          <?= $c['dono'] ? ' · autorizado por ' . $e($c['dono']) : '' ?>
          <?= $c['ultimo_uso'] ? ' · último uso ' . $e(substr((string)$c['ultimo_uso'], 0, 16)) : ' · nunca usou' ?>
        </p>
      </div>
      <span class="tag <?= $ativo ? 'on' : 'off' ?>"><?= $ativo ? 'conectado' : 'sem acesso' ?></span>
      <?php if ($ativo): ?>
        <form method="post" onsubmit="return confirm('Cortar o acesso deste aplicativo?')">
          <input type="hidden" name="csrf" value="<?= $e($_SESSION['mcp_acessos_csrf']) ?>">
          <input type="hidden" name="revogar" value="<?= $e($c['client_id']) ?>">
          <button type="submit">Revogar</button>
        </form>
      <?php endif; ?>
    </div>
  <?php endforeach; ?>
</div>
</body>
</html>
