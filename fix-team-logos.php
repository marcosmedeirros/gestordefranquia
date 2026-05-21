<?php
// fix-team-logos.php
// Roda UMA VEZ no servidor para atualizar photo_url dos times que têm arquivo em /img/teams mas não têm logo no banco.
// Após usar, DELETE este arquivo do servidor.

$host   = 'localhost';
$dbname = 'u289267434_fbabrasilbanco';
$user   = 'u289267434_fbabrasilbanco';
$pass   = 'Fbabrasil@2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Erro de conexão: " . $e->getMessage());
}

$dir = __DIR__ . '/img/teams';
$files = glob("$dir/team-*.*");

// Para cada team_id, pega o arquivo com maior timestamp (mais recente)
$byId = [];
foreach ($files as $f) {
    $base = basename($f);
    if (preg_match('/^team-(\d+)-(\d+)\.(jpg|jpeg|png|webp)$/i', $base, $m)) {
        $id = (int)$m[1];
        $ts = (int)$m[2];
        if (!isset($byId[$id]) || $ts > $byId[$id]['ts']) {
            $byId[$id] = ['ts' => $ts, 'file' => $base];
        }
    }
}

$executar = isset($_POST['executar']);
$atualizados = 0;
$preview = [];

foreach ($byId as $teamId => $info) {
    $stmt = $pdo->prepare("SELECT id, name, league, photo_url FROM teams WHERE id = ?");
    $stmt->execute([$teamId]);
    $team = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$team) continue;

    $novaUrl = '/img/teams/' . $info['file'];

    if (empty($team['photo_url'])) {
        $preview[] = ['id' => $teamId, 'name' => $team['name'], 'league' => $team['league'], 'arquivo' => $info['file'], 'url' => $novaUrl, 'status' => 'sem_logo'];
        if ($executar) {
            $pdo->prepare("UPDATE teams SET photo_url = ? WHERE id = ?")->execute([$novaUrl, $teamId]);
            $atualizados++;
        }
    } else {
        $preview[] = ['id' => $teamId, 'name' => $team['name'], 'league' => $team['league'], 'arquivo' => $info['file'], 'url' => $novaUrl, 'status' => 'ja_tem'];
    }
}

$semLogo = array_filter($preview, fn($r) => $r['status'] === 'sem_logo');
$jaTem   = array_filter($preview, fn($r) => $r['status'] === 'ja_tem');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fix Team Logos</title>
<style>
body { font-family: sans-serif; background: #0d0d0f; color: #f0f0f3; padding: 24px; }
h2 { color: #fc0025; }
table { width: 100%; border-collapse: collapse; margin-bottom: 24px; }
th { text-align: left; padding: 8px 12px; background: #1c1c21; font-size: 12px; color: #868690; }
td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,.06); font-size: 13px; }
.ok  { color: #22c55e; font-weight: 700; }
.warn { color: #f59e0b; font-weight: 700; }
.info { background: rgba(59,130,246,.1); border: 1px solid rgba(59,130,246,.2); color: #60a5fa; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.success { background: rgba(34,197,94,.1); border: 1px solid rgba(34,197,94,.2); color: #4ade80; padding: 12px 16px; border-radius: 8px; margin-bottom: 16px; }
.logo { width: 32px; height: 32px; border-radius: 50%; object-fit: cover; }
button { background: #fc0025; color: #fff; border: none; padding: 12px 28px; font-size: 14px; font-weight: 700; border-radius: 8px; cursor: pointer; }
button:hover { opacity: .85; }
</style>
</head>
<body>
<h2>Fix Team Logos</h2>

<?php if ($executar): ?>
<div class="success">✅ <?= $atualizados ?> time(s) atualizado(s) com sucesso. <strong>Delete este arquivo do servidor agora.</strong></div>
<?php endif; ?>

<div class="info">
  Arquivos encontrados em <code>/img/teams</code>: <strong><?= count($byId) ?></strong> times &nbsp;|&nbsp;
  Sem logo no banco: <strong><?= count($semLogo) ?></strong> &nbsp;|&nbsp;
  Já têm logo: <strong><?= count($jaTem) ?></strong>
</div>

<?php if (!empty($semLogo)): ?>
<h3 style="color:#f59e0b">Times sem logo que serão atualizados (<?= count($semLogo) ?>)</h3>
<table>
  <tr><th>Logo</th><th>ID</th><th>Time</th><th>Liga</th><th>Arquivo</th></tr>
  <?php foreach ($semLogo as $r): ?>
  <tr>
    <td><img class="logo" src="<?= htmlspecialchars($r['url']) ?>" onerror="this.style.display='none'"></td>
    <td><?= $r['id'] ?></td>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td><?= htmlspecialchars($r['league']) ?></td>
    <td style="color:#868690;font-size:11px"><?= htmlspecialchars($r['arquivo']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>

<?php if (!$executar): ?>
<form method="POST">
  <button type="submit" name="executar" value="1">Atualizar <?= count($semLogo) ?> time(s) no banco</button>
</form>
<?php endif; ?>

<?php else: ?>
<div class="info">✅ Todos os times com arquivo já têm <code>photo_url</code> preenchido.</div>
<?php endif; ?>

<?php if (!empty($jaTem)): ?>
<h3 style="color:#868690;margin-top:32px">Times que já têm logo (<?= count($jaTem) ?>)</h3>
<table>
  <tr><th>Logo</th><th>ID</th><th>Time</th><th>Liga</th></tr>
  <?php foreach ($jaTem as $r): ?>
  <tr>
    <td><img class="logo" src="<?= htmlspecialchars($r['url']) ?>" onerror="this.style.display='none'"></td>
    <td><?= $r['id'] ?></td>
    <td><?= htmlspecialchars($r['name']) ?></td>
    <td><?= htmlspecialchars($r['league']) ?></td>
  </tr>
  <?php endforeach; ?>
</table>
<?php endif; ?>

</body>
</html>
