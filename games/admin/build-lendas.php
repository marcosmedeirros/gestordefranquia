<?php
/**
 * Aplica o elenco curado do Build-A-Player.
 *
 * Roda pelo navegador (só admin do Games) ou por linha de comando:
 *   php games/admin/build-lendas.php
 *
 * É idempotente: rodar de novo só reescreve as mesmas notas.
 */

$ehCli = PHP_SAPI === 'cli';

if ($ehCli) {
    require_once dirname(__DIR__, 2) . '/backend/db.php';
    $pdo = db();
} else {
    if (session_status() === PHP_SESSION_NONE) session_start();
    require __DIR__ . '/../core/conexao.php';
    if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
    $st = $pdo->prepare("SELECT is_admin FROM games_usuarios WHERE id = ?");
    $st->execute([$_SESSION['user_id']]);
    if (!$st->fetchColumn()) { header('Location: /login.php'); exit; }
}

require_once dirname(__DIR__) . '/core/build_lendas.php';

$resultado = null;
$erro = null;
if ($ehCli || ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    try {
        $resultado = buildAplicarLendasCuradas($pdo);
    } catch (Throwable $e) {
        $erro = $e->getMessage();
    }
}

if ($ehCli) {
    echo $erro
        ? "erro: {$erro}\n"
        : "lendas={$resultado['lendas']} criadas_na_base={$resultado['criados']} notas_gravadas={$resultado['notas']}\n";
    exit;
}

$total = (int)$pdo->query("SELECT COUNT(*) FROM build_notas")->fetchColumn();
$curadas = (int)$pdo->query("SELECT COUNT(*) FROM build_notas WHERE ajustado_manual = 1")->fetchColumn();
$porGrupo = $pdo->query("SELECT posicao_grupo, COUNT(*) n FROM build_notas GROUP BY posicao_grupo")
                ->fetchAll(PDO::FETCH_KEY_PAIR);
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Lendas do Build-A-Player — Admin</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
body{font-family:system-ui,Arial,sans-serif;background:#0a0a0f;color:#e0e0e0;padding:24px;max-width:900px;margin:0 auto}
h1{font-size:20px;margin:0 0 6px}
p.sub{color:#8b8b96;font-size:13px;margin:0 0 20px}
.box{background:#14141a;border:1px solid rgba(255,255,255,.08);border-radius:12px;padding:18px;margin-bottom:16px}
.stats{display:flex;gap:24px;flex-wrap:wrap;margin-bottom:16px}
.stat b{display:block;font-size:22px;font-weight:800;color:#f59e0b}
.stat span{font-size:11px;color:#8b8b96}
button{background:#f59e0b;color:#17171d;border:0;border-radius:9px;padding:11px 20px;font-weight:800;cursor:pointer;font-size:14px}
.ok{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.35);color:#86efac;padding:12px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}
.err{background:rgba(239,68,68,.12);border:1px solid rgba(239,68,68,.35);color:#fca5a5;padding:12px 14px;border-radius:9px;margin-bottom:14px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:12px}
th,td{text-align:left;padding:6px 8px;border-bottom:1px solid rgba(255,255,255,.05)}
th{color:#8b8b96;font-size:10px;text-transform:uppercase;letter-spacing:.6px}
td.l{font-weight:800;text-align:center}
a{color:#f59e0b}
</style>
</head>
<body>
<h1>🏗️ Lendas do Build-A-Player</h1>
<p class="sub">Notas atribuídas à mão, uma a uma. Elas entram com <code>ajustado_manual</code>, então a sincronização automática nunca sobrescreve.</p>

<?php if ($erro): ?><div class="err"><b>Erro:</b> <?= htmlspecialchars($erro) ?></div><?php endif; ?>
<?php if ($resultado): ?>
<div class="ok">
    Pronto: <b><?= $resultado['notas'] ?></b> lendas com nota gravada
    (<?= $resultado['criados'] ?> precisaram ser criadas na base de jogadores).
</div>
<?php endif; ?>

<div class="box">
    <div class="stats">
        <div class="stat"><b><?= $total ?></b><span>com nota no jogo</span></div>
        <div class="stat"><b><?= $curadas ?></b><span>curadas à mão</span></div>
        <div class="stat"><b><?= (int)($porGrupo['GUARD'] ?? 0) ?></b><span>guards</span></div>
        <div class="stat"><b><?= (int)($porGrupo['BIG'] ?? 0) ?></b><span>bigs</span></div>
    </div>
    <form method="post">
        <button type="submit"><i class="bi bi-magic"></i> Aplicar elenco curado</button>
    </form>
    <p style="font-size:12px;color:#6b6b76;margin:12px 0 0">
        Pode rodar quantas vezes quiser — reescreve as mesmas notas.
        Depois, o jogo já funciona em <a href="/games/games/index.php?game=buildplayer">Build-A-Player</a>.
    </p>
</div>

<div class="box">
    <h2 style="font-size:14px;margin:0 0 12px">Elenco (<?= count(buildLendasCuradas()) ?>)</h2>
    <table>
        <tr>
            <th>Lenda</th><th>Time</th><th>Tipo</th>
            <?php foreach (buildAtributos() as $info): ?>
            <th style="text-align:center" title="<?= htmlspecialchars($info['label']) ?>"><?= mb_substr($info['label'], 0, 3) ?></th>
            <?php endforeach; ?>
            <th style="text-align:center">OVR</th>
        </tr>
        <?php foreach (buildLendasCuradas() as [$nome, $time, $grupo, $niveis]):
            $soma = 0; foreach ($niveis as $n) $soma += buildValorDaLetra((int)$n);
            $ovr = (int)round($soma / count($niveis)); ?>
        <tr>
            <td><?= htmlspecialchars($nome) ?></td>
            <td style="color:#8b8b96"><?= htmlspecialchars($time) ?></td>
            <td style="color:#8b8b96"><?= $grupo ?></td>
            <?php foreach ($niveis as $n): ?>
            <td class="l" style="color:<?= ['#ef4444','#ef4444','#f97316','#f59e0b','#f59e0b','#eab308','#84cc16','#22c55e','#22c55e','#06b6d4','#3b82f6','#a855f7'][$n] ?>"><?= BUILD_LETRAS[$n] ?></td>
            <?php endforeach; ?>
            <td class="l" style="color:#f59e0b"><?= $ovr ?></td>
        </tr>
        <?php endforeach; ?>
    </table>
</div>
</body>
</html>
