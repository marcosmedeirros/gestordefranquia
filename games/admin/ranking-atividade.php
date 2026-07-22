<?php
/**
 * ranking-atividade.php
 * Ranking de quem mais "fez coisas" na plataforma (acessos, jogos, apostas, tigrinho).
 * Junta todas as tabelas *_historico + usuario_acessos + palpites + tigrinho_historico.
 */
session_start();
require '../core/conexao.php';
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome FROM usuarios WHERE id = ?");
$stmt->execute([$_SESSION['user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$u || empty($u['is_admin'])) { header("Location: ../auth/login.php"); exit; }

$hiddenEmailLower = 'medeirros99@gmail.com';

// Filtro opcional de ano (padrão: geral, todos os anos)
$ano = isset($_GET['ano']) && $_GET['ano'] !== '' ? (int)$_GET['ano'] : null;

function anoWhere(string $coluna, ?int $ano): string {
    return $ano ? "AND YEAR($coluna) = $ano" : "";
}

// Cada fonte: [tabela, coluna_usuario, coluna_data]
$fontes = [
    ['usuario_acessos',      'user_id',     'data_acesso'],
    ['dino_historico',       'id_usuario',  'created_at'],
    ['flappy_historico',     'id_usuario',  'data_jogo'],
    ['termo_historico',      'id_usuario',  'data_jogo'],
    ['termo_dueto_historico','id_usuario',  'data_jogo'],
    ['memoria_historico',    'id_usuario',  'data_jogo'],
    ['conexoes_historico',   'id_usuario',  'data_jogo'],
    ['boxnba_historico',     'id_usuario',  'data_jogo'],
    ['grade_historico',      'id_usuario',  'data_jogo'],
    ['bomba_historico',      'id_usuario',  'data_jogo'],
    ['quemsoueu_basquete',   'id_usuario',  'data_jogo'],
    ['quemsoueu_futebol',    'id_usuario',  'data_jogo'],
    ['palpites',             'id_usuario',  'data_palpite'],
    ['tigrinho_historico',   'id_usuario',  'created_at'],
];

$totais = []; // user_id => ['acessos'=>0, 'jogos'=>0, 'apostas'=>0, 'tigrinho'=>0, 'total'=>0]
$detalhePorTabela = []; // tabela => [user_id => count]
$erros = [];

foreach ($fontes as [$tabela, $colUser, $colData]) {
    try {
        $sql = "SELECT $colUser AS uid, COUNT(*) AS c FROM $tabela WHERE 1=1 " . anoWhere($colData, $ano) . " GROUP BY $colUser";
        $rows = $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $mapa = [];
        foreach ($rows as $r) {
            $uid = (int)$r['uid'];
            $c = (int)$r['c'];
            $mapa[$uid] = $c;
            if (!isset($totais[$uid])) $totais[$uid] = ['acessos'=>0,'jogos'=>0,'apostas'=>0,'tigrinho'=>0,'total'=>0];
            if ($tabela === 'usuario_acessos') $totais[$uid]['acessos'] += $c;
            elseif ($tabela === 'palpites') $totais[$uid]['apostas'] += $c;
            elseif ($tabela === 'tigrinho_historico') $totais[$uid]['tigrinho'] += $c;
            else $totais[$uid]['jogos'] += $c;
        }
        $detalhePorTabela[$tabela] = $mapa;
    } catch (PDOException $e) {
        $erros[] = "$tabela: " . $e->getMessage();
    }
}

// Nomes dos usuários (exclui a conta oculta)
$usuarios = $pdo->query("SELECT id, nome, email FROM usuarios")->fetchAll(PDO::FETCH_ASSOC);
$nomes = [];
foreach ($usuarios as $us) {
    if (strtolower($us['email']) === $hiddenEmailLower) continue;
    $nomes[(int)$us['id']] = $us['nome'];
}

$ranking = [];
foreach ($totais as $uid => $t) {
    if (!isset($nomes[$uid])) continue; // usuário oculto ou removido
    $t['total'] = $t['acessos'] + $t['jogos'] + $t['apostas'] + $t['tigrinho'];
    $ranking[] = ['uid'=>$uid, 'nome'=>$nomes[$uid]] + $t;
}
usort($ranking, fn($a,$b) => $b['total'] <=> $a['total']);

$top = array_slice($ranking, 0, 15);

// Texto formatado pra WhatsApp
$medalhas = ['🥇','🥈','🥉'];
$tituloAno = $ano ? "de $ano" : "geral (desde sempre)";
$texto = "🏆 *RANKING DE ATIVIDADE $tituloAno* 🏆\n";
$texto .= "_quem mais jogou, apostou e apareceu por aqui_\n\n";
foreach ($top as $i => $r) {
    $pos = $i + 1;
    $marca = $medalhas[$i] ?? "{$pos}º";
    $texto .= "{$marca} *{$r['nome']}* — {$r['total']} ações\n";
    $texto .= "     🎮 {$r['jogos']} jogos · 🎲 {$r['apostas']} apostas · 🎰 {$r['tigrinho']} tigrinho · 📅 {$r['acessos']} acessos\n";
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>Ranking de Atividade — Admin</title>
<style>
body{font-family:system-ui,Arial,sans-serif;background:#0a0a0f;color:#e0e0e0;padding:24px;max-width:900px;margin:0 auto}
h2{color:#f59e0b}
table{width:100%;border-collapse:collapse;margin-top:16px}
th,td{padding:8px 10px;border-bottom:1px solid #2a2a35;text-align:left;font-size:14px}
th{color:#818cf8}
tr:hover{background:#15151c}
.pos{width:36px;text-align:center;font-weight:bold}
textarea{width:100%;height:280px;background:#111;color:#e0e0e0;border:1px solid #333;border-radius:8px;padding:12px;font-family:monospace;font-size:13px;margin-top:8px}
button{background:#22c55e;color:#0a0a0f;border:none;padding:10px 18px;border-radius:8px;font-weight:bold;cursor:pointer;margin-top:8px}
button:hover{opacity:.9}
.filtros{margin:12px 0}
.filtros a{color:#818cf8;margin-right:14px;text-decoration:none}
.filtros a.ativo{color:#f59e0b;font-weight:bold}
.err{color:#ef4444;font-size:12px}
</style>
</head>
<body>
<h2>🏆 Ranking de Atividade</h2>
<div class="filtros">
    <a href="?" class="<?= $ano===null?'ativo':'' ?>">Geral (desde sempre)</a>
    <a href="?ano=<?= date('Y') ?>" class="<?= $ano===(int)date('Y')?'ativo':'' ?>"><?= date('Y') ?></a>
    <a href="?ano=<?= date('Y')-1 ?>" class="<?= $ano===(int)date('Y')-1?'ativo':'' ?>"><?= date('Y')-1 ?></a>
</div>

<?php foreach ($erros as $e): ?><p class="err">⚠️ <?= htmlspecialchars($e) ?></p><?php endforeach; ?>

<table>
<tr><th class="pos">#</th><th>Nome</th><th>Total</th><th>Jogos</th><th>Apostas</th><th>Tigrinho</th><th>Acessos</th></tr>
<?php foreach ($top as $i => $r): ?>
<tr>
    <td class="pos"><?= $i+1 ?></td>
    <td><?= htmlspecialchars($r['nome']) ?></td>
    <td><strong><?= $r['total'] ?></strong></td>
    <td><?= $r['jogos'] ?></td>
    <td><?= $r['apostas'] ?></td>
    <td><?= $r['tigrinho'] ?></td>
    <td><?= $r['acessos'] ?></td>
</tr>
<?php endforeach; ?>
</table>

<h3 style="color:#f59e0b;margin-top:28px">📋 Texto pra mandar no WhatsApp</h3>
<textarea id="txtWpp" readonly><?= htmlspecialchars($texto) ?></textarea>
<br>
<button onclick="copiarTexto()">Copiar texto</button>
<span id="copiado" style="color:#22c55e;margin-left:10px;display:none">Copiado! ✅</span>

<script>
function copiarTexto() {
    const ta = document.getElementById('txtWpp');
    ta.select();
    navigator.clipboard.writeText(ta.value).then(() => {
        const s = document.getElementById('copiado');
        s.style.display = 'inline';
        setTimeout(() => s.style.display = 'none', 2000);
    });
}
</script>
</body>
</html>
