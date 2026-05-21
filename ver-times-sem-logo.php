<?php
$host   = 'localhost';
$dbname = 'u289267434_fbabrasilbanco';
$user   = 'u289267434_fbabrasilbanco';
$pass   = 'Fbabrasil@2025';

try {
    $pdo = new PDO("mysql:host=$host;dbname=$dbname;charset=utf8mb4", $user, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
} catch (PDOException $e) {
    die("Erro: " . $e->getMessage());
}

$times = $pdo->query("SELECT id, name, league FROM teams WHERE photo_url IS NULL OR photo_url = '' ORDER BY league, name")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Times sem logo</title>
<style>
body { font-family: sans-serif; background: #0d0d0f; color: #f0f0f3; padding: 24px; }
h2 { color: #fc0025; }
table { width: 100%; border-collapse: collapse; }
th { text-align: left; padding: 8px 12px; background: #1c1c21; font-size: 12px; color: #868690; }
td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,.06); font-size: 13px; }
.badge { display: inline-block; background: #1c1c21; border: 1px solid rgba(255,255,255,.1); border-radius: 6px; padding: 2px 8px; font-size: 11px; color: #868690; }
</style>
</head>
<body>
<h2>Times sem logo — <?= count($times) ?> encontrados</h2>
<table>
  <tr><th>ID</th><th>Time</th><th>Liga</th></tr>
  <?php foreach ($times as $t): ?>
  <tr>
    <td style="color:#868690"><?= $t['id'] ?></td>
    <td><?= htmlspecialchars($t['name']) ?></td>
    <td><span class="badge"><?= htmlspecialchars($t['league']) ?></span></td>
  </tr>
  <?php endforeach; ?>
</table>
</body>
</html>
