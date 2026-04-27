<?php
session_start();
require '../core/conexao.php';
require_once '../core/nba-players-db.php';

if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }

$stmt = $pdo->prepare("SELECT is_admin, nome FROM usuarios WHERE id = :id");
$stmt->execute([':id' => $_SESSION['user_id']]);
$user = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$user || $user['is_admin'] != 1) { die("Acesso negado."); }

nba_ensure_tables($pdo);

$msg = ''; $msgType = 'success';

// ── AÇÕES POST ────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = $_POST['acao'] ?? '';

    if ($acao === 'add_player') {
        $name  = trim($_POST['name'] ?? '');
        $teams = trim($_POST['teams'] ?? '');
        $country = strtoupper(trim($_POST['country'] ?? 'USA'));
        $ach   = trim($_POST['achievements'] ?? '');
        if ($name) {
            try {
                $pdo->prepare("INSERT INTO nba_players_custom (name,teams,country,achievements) VALUES (?,?,?,?)
                    ON DUPLICATE KEY UPDATE teams=VALUES(teams), country=VALUES(country), achievements=VALUES(achievements), active=1")
                    ->execute([$name, $teams, $country, $ach]);
                $msg = "Jogador \"$name\" salvo.";
            } catch (Exception $e) { $msg = 'Erro: '.$e->getMessage(); $msgType='danger'; }
        }
    }

    if ($acao === 'toggle_player') {
        $id  = (int)($_POST['player_id'] ?? 0);
        $val = (int)($_POST['active'] ?? 0);
        $pdo->prepare("UPDATE nba_players_custom SET active=? WHERE id=?")->execute([$val, $id]);
        $msg = 'Status atualizado.';
    }

    if ($acao === 'delete_player') {
        $id = (int)($_POST['player_id'] ?? 0);
        $pdo->prepare("DELETE FROM nba_players_custom WHERE id=?")->execute([$id]);
        $msg = 'Jogador removido.';
    }

    if ($acao === 'add_puzzle') {
        $grupos = [];
        $cores  = ['green','yellow','red','purple'];
        $ok = true;
        for ($i = 0; $i < 4; $i++) {
            $label  = trim($_POST["g{$i}_label"] ?? '');
            $dica   = trim($_POST["g{$i}_dica"]  ?? '');
            $cor    = $cores[$i];
            $jogStr = trim($_POST["g{$i}_jogadores"] ?? '');
            $jogs   = array_values(array_filter(array_map('trim', explode(',', $jogStr))));
            if (!$label || count($jogs) < 4) { $ok = false; break; }
            $grupos[] = ['cor'=>$cor,'label'=>$label,'dica'=>$dica,'jogadores'=>array_slice($jogs,0,4)];
        }
        if ($ok) {
            try {
                $pdo->prepare("INSERT INTO conexoes_puzzles_custom (grupos) VALUES (?)")
                    ->execute([json_encode($grupos, JSON_UNESCAPED_UNICODE)]);
                $msg = 'Puzzle criado!';
            } catch (Exception $e) { $msg='Erro: '.$e->getMessage(); $msgType='danger'; }
        } else {
            $msg = 'Preencha todos os 4 grupos com label + 4 jogadores.'; $msgType='warning';
        }
    }

    if ($acao === 'toggle_puzzle') {
        $id  = (int)($_POST['puzzle_id'] ?? 0);
        $val = (int)($_POST['active'] ?? 0);
        $pdo->prepare("UPDATE conexoes_puzzles_custom SET active=? WHERE id=?")->execute([$val, $id]);
        $msg = 'Puzzle atualizado.';
    }

    if ($acao === 'delete_puzzle') {
        $id = (int)($_POST['puzzle_id'] ?? 0);
        $pdo->prepare("DELETE FROM conexoes_puzzles_custom WHERE id=?")->execute([$id]);
        $msg = 'Puzzle removido.';
    }
}

// ── LEITURA ───────────────────────────────────────────────────────────────────
$customPlayers = $pdo->query("SELECT * FROM nba_players_custom ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$customPuzzles = $pdo->query("SELECT * FROM conexoes_puzzles_custom ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

$totalSeed   = count($NBA_PLAYERS_SEED);
$totalCustom = count(array_filter($customPlayers, fn($p) => $p['active']));
$totalAll    = $totalSeed + $totalCustom;
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>NBA Manager — Admin</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<style>
:root{--bg:#07070a;--panel:#101013;--panel2:#16161a;--border:rgba(255,255,255,.08);--red:#fc0025;--text:#f0f0f3;--text2:#868690;--font:'Segoe UI',sans-serif}
*{box-sizing:border-box}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;padding:20px}
.topbar{display:flex;align-items:center;gap:12px;margin-bottom:24px}
.back-btn{display:flex;align-items:center;justify-content:center;width:36px;height:36px;border-radius:8px;border:1px solid var(--border);background:var(--panel2);color:var(--text2);text-decoration:none;font-size:15px;transition:.2s}
.back-btn:hover{border-color:var(--red);color:var(--red)}
h1{font-size:20px;font-weight:700;margin:0}
.stat-card{background:var(--panel);border:1px solid var(--border);border-radius:10px;padding:16px;text-align:center}
.stat-card .num{font-size:28px;font-weight:800;color:var(--red)}
.stat-card .lbl{font-size:12px;color:var(--text2);margin-top:2px}
.panel{background:var(--panel);border:1px solid var(--border);border-radius:12px;padding:20px;margin-bottom:20px}
.panel h2{font-size:16px;font-weight:700;margin:0 0 16px;display:flex;align-items:center;gap:8px}
.form-label{color:var(--text2);font-size:12px;margin-bottom:4px}
.form-control,.form-select{background:var(--panel2);border:1px solid var(--border);color:var(--text);border-radius:8px;font-size:13px}
.form-control:focus,.form-select:focus{background:var(--panel2);border-color:var(--red);color:var(--text);box-shadow:none}
.form-control::placeholder{color:var(--text2)}
.btn-red{background:var(--red);border:none;color:#fff;border-radius:8px;padding:8px 18px;font-size:13px;font-weight:600;transition:.2s}
.btn-red:hover{background:#d40020;color:#fff}
.btn-ghost{background:transparent;border:1px solid var(--border);color:var(--text2);border-radius:8px;padding:6px 14px;font-size:12px;transition:.2s}
.btn-ghost:hover{border-color:var(--red);color:var(--red)}
.table-dark-custom{width:100%;border-collapse:collapse;font-size:13px}
.table-dark-custom th{color:var(--text2);font-size:11px;font-weight:600;text-transform:uppercase;letter-spacing:.05em;padding:8px 10px;border-bottom:1px solid var(--border)}
.table-dark-custom td{padding:8px 10px;border-bottom:1px solid rgba(255,255,255,.04);vertical-align:middle}
.badge-country{display:inline-block;padding:2px 7px;border-radius:4px;font-size:11px;font-weight:600;background:rgba(255,255,255,.08);color:var(--text)}
.badge-award{display:inline-block;padding:2px 6px;border-radius:4px;font-size:10px;font-weight:600;background:rgba(252,0,37,.12);color:var(--red);margin:1px}
.inactive{opacity:.45}
.puzzle-card{background:var(--panel2);border:1px solid var(--border);border-radius:10px;padding:14px;margin-bottom:10px}
.puzzle-card .g-badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:11px;font-weight:700;margin:2px}
.g-green{background:#16a34a;color:#fff}
.g-yellow{background:#d97706;color:#fff}
.g-red{background:#dc2626;color:#fff}
.g-purple{background:#7c3aed;color:#fff}
.search-bar{background:var(--panel2);border:1px solid var(--border);border-radius:8px;padding:8px 12px;color:var(--text);width:100%;font-size:13px;margin-bottom:12px}
.search-bar::placeholder{color:var(--text2)}
.search-bar:focus{outline:none;border-color:var(--red)}
.tabs{display:flex;gap:4px;margin-bottom:16px}
.tab-btn{background:var(--panel2);border:1px solid var(--border);color:var(--text2);border-radius:8px;padding:8px 16px;font-size:13px;cursor:pointer;transition:.2s}
.tab-btn.active,.tab-btn:hover{border-color:var(--red);color:var(--red)}
.tab-pane{display:none}.tab-pane.active{display:block}
</style>
</head>
<body>

<div class="topbar">
    <a href="dashboard.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <h1><i class="bi bi-database-fill me-2" style="color:var(--red)"></i>NBA Manager</h1>
</div>

<?php if ($msg): ?>
<div class="alert alert-<?= $msgType ?> alert-dismissible fade show mb-3" role="alert">
    <?= htmlspecialchars($msg) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-4">
    <div class="col-4"><div class="stat-card"><div class="num"><?= $totalSeed ?></div><div class="lbl">Jogadores base</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="num"><?= count($customPlayers) ?></div><div class="lbl">Customizados no DB</div></div></div>
    <div class="col-4"><div class="stat-card"><div class="num"><?= $totalAll ?></div><div class="lbl">Total ativo</div></div></div>
</div>

<!-- Tabs -->
<div class="tabs">
    <button class="tab-btn active" data-tab="players">Jogadores</button>
    <button class="tab-btn" data-tab="puzzles">Conexões Puzzles</button>
</div>

<!-- ── TAB: JOGADORES ─────────────────────────────────────────────────────── -->
<div class="tab-pane active" id="tab-players">

    <div class="panel">
        <h2><i class="bi bi-person-plus-fill" style="color:var(--red)"></i> Adicionar Jogador</h2>
        <form method="post">
            <input type="hidden" name="acao" value="add_player">
            <div class="row g-2">
                <div class="col-md-4">
                    <label class="form-label">Nome completo *</label>
                    <input type="text" name="name" class="form-control" placeholder="Ex: Nikola Jokic" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Times (siglas separadas por vírgula)</label>
                    <input type="text" name="teams" class="form-control" placeholder="DEN,LAL,BOS">
                </div>
                <div class="col-md-2">
                    <label class="form-label">País (código 3 letras)</label>
                    <input type="text" name="country" class="form-control" placeholder="USA" maxlength="6" value="USA">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Conquistas (separadas por vírgula)</label>
                    <input type="text" name="achievements" class="form-control" placeholder="MVP,CHAMPION,ALLSTAR">
                </div>
            </div>
            <div class="mt-2" style="font-size:11px;color:var(--text2)">
                Conquistas válidas: MVP · CHAMPION · ALLSTAR · FINALS_MVP · DPOY · SCORING · ROY · SIXTHMAN
            </div>
            <button type="submit" class="btn-red mt-3"><i class="bi bi-plus-lg me-1"></i>Salvar Jogador</button>
        </form>
    </div>

    <div class="panel">
        <h2><i class="bi bi-table" style="color:var(--red)"></i> Jogadores Customizados no DB (<?= count($customPlayers) ?>)</h2>
        <?php if (empty($customPlayers)): ?>
            <p style="color:var(--text2);font-size:13px">Nenhum jogador customizado ainda. Os <?= $totalSeed ?> do banco base já estão disponíveis automaticamente.</p>
        <?php else: ?>
            <input class="search-bar" id="searchPlayers" placeholder="Filtrar por nome, time ou país..." oninput="filterPlayers(this.value)">
            <table class="table-dark-custom" id="playersTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Nome</th>
                        <th>Times</th>
                        <th>País</th>
                        <th>Conquistas</th>
                        <th>Status</th>
                        <th>Ações</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($customPlayers as $p): ?>
                <tr class="player-row <?= $p['active'] ? '' : 'inactive' ?>" data-search="<?= strtolower($p['name'].' '.$p['teams'].' '.$p['country']) ?>">
                    <td style="color:var(--text2)"><?= $p['id'] ?></td>
                    <td><strong><?= htmlspecialchars($p['name']) ?></strong></td>
                    <td style="color:var(--text2)"><?= htmlspecialchars($p['teams']) ?></td>
                    <td><span class="badge-country"><?= htmlspecialchars($p['country']) ?></span></td>
                    <td>
                        <?php foreach (array_filter(explode(',', $p['achievements'])) as $aw): ?>
                            <span class="badge-award"><?= trim($aw) ?></span>
                        <?php endforeach; ?>
                    </td>
                    <td><span style="color:<?= $p['active'] ? '#22c55e' : 'var(--text2)' ?>;font-size:12px"><?= $p['active'] ? 'Ativo' : 'Inativo' ?></span></td>
                    <td style="white-space:nowrap">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="acao" value="toggle_player">
                            <input type="hidden" name="player_id" value="<?= $p['id'] ?>">
                            <input type="hidden" name="active" value="<?= $p['active'] ? 0 : 1 ?>">
                            <button type="submit" class="btn-ghost"><?= $p['active'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover permanentemente?')">
                            <input type="hidden" name="acao" value="delete_player">
                            <input type="hidden" name="player_id" value="<?= $p['id'] ?>">
                            <button type="submit" class="btn-ghost" style="color:#f87171;border-color:#f8717140"><i class="bi bi-trash"></i></button>
                        </form>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<!-- ── TAB: PUZZLES ───────────────────────────────────────────────────────── -->
<div class="tab-pane" id="tab-puzzles">

    <div class="panel">
        <h2><i class="bi bi-puzzle-fill" style="color:var(--red)"></i> Criar Puzzle Conexões</h2>
        <form method="post">
            <input type="hidden" name="acao" value="add_puzzle">
            <div style="font-size:12px;color:var(--text2);margin-bottom:14px">
                Cada puzzle tem 4 grupos (verde, amarelo, vermelho, roxo). Coloque exatamente 4 jogadores por grupo, separados por vírgula. Os nomes devem ser iguais aos usados no Box NBA.
            </div>

            <?php
            $gColors = ['green'=>['label'=>'Verde (fácil)','cls'=>'g-green'],'yellow'=>['label'=>'Amarelo','cls'=>'g-yellow'],'red'=>['label'=>'Vermelho','cls'=>'g-red'],'purple'=>['label'=>'Roxo (difícil)','cls'=>'g-purple']];
            $gi = 0;
            foreach ($gColors as $cor => $meta): ?>
            <div class="puzzle-card mb-3">
                <div class="d-flex align-items-center gap-2 mb-2">
                    <span class="g-badge <?= $meta['cls'] ?>"><?= $meta['label'] ?></span>
                </div>
                <div class="row g-2">
                    <div class="col-md-4">
                        <label class="form-label">Label do grupo *</label>
                        <input type="text" name="g<?= $gi ?>_label" class="form-control" placeholder="Ex: Brasileiros na NBA" required>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">Dica (opcional)</label>
                        <input type="text" name="g<?= $gi ?>_dica" class="form-control" placeholder="Breve descrição do grupo">
                    </div>
                    <div class="col-md-4">
                        <label class="form-label">4 Jogadores (separados por vírgula) *</label>
                        <input type="text" name="g<?= $gi ?>_jogadores" class="form-control" placeholder="Jogador A, Jogador B, Jogador C, Jogador D" required>
                    </div>
                </div>
            </div>
            <?php $gi++; endforeach; ?>

            <button type="submit" class="btn-red"><i class="bi bi-plus-lg me-1"></i>Criar Puzzle</button>
        </form>
    </div>

    <div class="panel">
        <h2><i class="bi bi-collection-fill" style="color:var(--red)"></i> Puzzles Customizados no DB (<?= count($customPuzzles) ?>)</h2>
        <?php if (empty($customPuzzles)): ?>
            <p style="color:var(--text2);font-size:13px">Nenhum puzzle customizado ainda. O jogo usa os <?= 21 ?> puzzles base automaticamente.</p>
        <?php else: ?>
            <?php foreach ($customPuzzles as $cp):
                $grupos = json_decode($cp['grupos'], true) ?: [];
                $gCls = ['green'=>'g-green','yellow'=>'g-yellow','red'=>'g-red','purple'=>'g-purple'];
            ?>
            <div class="puzzle-card <?= $cp['active'] ? '' : 'inactive' ?>">
                <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-2">
                    <div>
                        <span style="font-size:12px;color:var(--text2)">Puzzle #<?= $cp['id'] ?></span>
                        <span style="font-size:11px;color:<?= $cp['active'] ? '#22c55e' : 'var(--text2)' ?>;margin-left:8px"><?= $cp['active'] ? '● Ativo' : '○ Inativo' ?></span>
                    </div>
                    <div style="display:flex;gap:6px">
                        <form method="post" style="display:inline">
                            <input type="hidden" name="acao" value="toggle_puzzle">
                            <input type="hidden" name="puzzle_id" value="<?= $cp['id'] ?>">
                            <input type="hidden" name="active" value="<?= $cp['active'] ? 0 : 1 ?>">
                            <button type="submit" class="btn-ghost" style="font-size:11px"><?= $cp['active'] ? 'Desativar' : 'Ativar' ?></button>
                        </form>
                        <form method="post" style="display:inline" onsubmit="return confirm('Remover este puzzle?')">
                            <input type="hidden" name="acao" value="delete_puzzle">
                            <input type="hidden" name="puzzle_id" value="<?= $cp['id'] ?>">
                            <button type="submit" class="btn-ghost" style="color:#f87171;border-color:#f8717140;font-size:11px"><i class="bi bi-trash"></i></button>
                        </form>
                    </div>
                </div>
                <div class="row g-2">
                    <?php foreach ($grupos as $g): ?>
                    <div class="col-md-3">
                        <div style="background:rgba(255,255,255,.04);border-radius:8px;padding:8px">
                            <div class="mb-1"><span class="g-badge <?= $gCls[$g['cor']] ?? '' ?>"><?= htmlspecialchars($g['label']) ?></span></div>
                            <div style="font-size:11px;color:var(--text2)"><?= implode(', ', array_map('htmlspecialchars', $g['jogadores'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
document.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
        document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
        document.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
        btn.classList.add('active');
        document.getElementById('tab-' + btn.dataset.tab).classList.add('active');
    });
});

function filterPlayers(q) {
    q = q.toLowerCase();
    document.querySelectorAll('.player-row').forEach(row => {
        row.style.display = row.dataset.search.includes(q) ? '' : 'none';
    });
}
</script>
</body>
</html>
