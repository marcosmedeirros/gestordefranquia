<?php
/**
 * Script de Verificação de Duplicatas
 * Acesse via: /check-duplicates.php
 * 
 * Verifica:
 * 1. Jogadores com mesmo nome na mesma liga
 * 2. Picks duplicadas (mesmo ano/rodada/time original)
 */

require_once __DIR__ . '/backend/db.php';

// Apenas admin pode acessar
require_once __DIR__ . '/backend/auth.php';
requireAuth();
$user = getUserSession();
if (($user['user_type'] ?? 'jogador') !== 'admin') {
    die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$pdo = db();

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verificação de Duplicatas - FBA Manager</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link rel="stylesheet" href="/css/styles.css">
</head>
<body>
    <div class="container py-5">
        <div class="mb-4">
            <a href="/admin.php" class="btn btn-outline-light btn-sm">
                <i class="bi bi-arrow-left me-2"></i>Voltar ao Admin
            </a>
        </div>

        <h1 class="text-white mb-4">
            <i class="bi bi-shield-exclamation text-orange me-2"></i>
            Verificação de Duplicatas
        </h1>

        <!-- 1. JOGADORES DUPLICADOS -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white">
                    <i class="bi bi-people-fill text-orange me-2"></i>
                    Jogadores com Nome Duplicado na Mesma Liga
                </h5>
                <small class="text-light-gray">Cada jogador deve ser único por liga</small>
            </div>
            <div class="card-body">
                <?php
                $stmtPlayers = $pdo->query("
                    SELECT 
                        p.name AS player_name,
                        t.league,
                        COUNT(*) AS quantidade,
                        GROUP_CONCAT(CONCAT(tc.city, ' ', tc.name) SEPARATOR ', ') AS times
                    FROM players p
                    JOIN teams t ON p.team_id = t.id
                    LEFT JOIN teams tc ON p.team_id = tc.id
                    GROUP BY p.name, t.league
                    HAVING quantidade > 1
                    ORDER BY quantidade DESC, p.name
                ");
                $duplicatePlayers = $stmtPlayers->fetchAll();
                
                if (count($duplicatePlayers) === 0): ?>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle me-2"></i>
                        Nenhum jogador duplicado encontrado! ✅
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong><?= count($duplicatePlayers) ?> jogadores duplicados encontrados!</strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>Nome do Jogador</th>
                                    <th>Liga</th>
                                    <th>Quantidade</th>
                                    <th>Times</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($duplicatePlayers as $row): ?>
                                <tr>
                                    <td class="fw-bold text-orange"><?= htmlspecialchars($row['player_name']) ?></td>
                                    <td><?= htmlspecialchars($row['league']) ?></td>
                                    <td>
                                        <span class="badge bg-danger"><?= $row['quantidade'] ?>x</span>
                                    </td>
                                    <td class="text-light-gray"><?= htmlspecialchars($row['times']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 2. PICKS DUPLICADAS -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white">
                    <i class="bi bi-calendar-check text-orange me-2"></i>
                    Picks Duplicadas (Mesmo Ano/Rodada/Time Original)
                </h5>
                <small class="text-light-gray">Só pode existir UMA pick de cada ano/rodada por time original</small>
            </div>
            <div class="card-body">
                <?php
                $stmtPicks = $pdo->query("
                    SELECT 
                        p.season_year,
                        p.round,
                        orig.city AS original_city,
                        orig.name AS original_name,
                        COUNT(*) AS quantidade,
                        GROUP_CONCAT(CONCAT(curr.city, ' ', curr.name) SEPARATOR ', ') AS donos_atuais,
                        GROUP_CONCAT(p.id SEPARATOR ', ') AS pick_ids
                    FROM picks p
                    JOIN teams orig ON p.original_team_id = orig.id
                    JOIN teams curr ON p.team_id = curr.id
                    GROUP BY p.season_year, p.round, p.original_team_id
                    HAVING quantidade > 1
                    ORDER BY p.season_year, p.round
                ");
                $duplicatePicks = $stmtPicks->fetchAll();
                
                if (count($duplicatePicks) === 0): ?>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle me-2"></i>
                        Nenhuma pick duplicada encontrada! ✅
                    </div>
                <?php else: ?>
                    <div class="alert alert-danger">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong><?= count($duplicatePicks) ?> picks duplicadas encontradas!</strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>Ano</th>
                                    <th>Rodada</th>
                                    <th>Time Original</th>
                                    <th>Quantidade</th>
                                    <th>Donos Atuais</th>
                                    <th>IDs</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($duplicatePicks as $row): ?>
                                <tr>
                                    <td class="fw-bold"><?= htmlspecialchars($row['season_year']) ?></td>
                                    <td>
                                        <span class="badge bg-orange"><?= $row['round'] ?>ª</span>
                                    </td>
                                    <td class="text-orange"><?= htmlspecialchars($row['original_city'] . ' ' . $row['original_name']) ?></td>
                                    <td>
                                        <span class="badge bg-danger"><?= $row['quantidade'] ?>x</span>
                                    </td>
                                    <td class="text-light-gray"><?= htmlspecialchars($row['donos_atuais']) ?></td>
                                    <td class="text-muted" style="font-size: 0.85rem;"><?= htmlspecialchars($row['pick_ids']) ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 3. PICKS ÓRFÃS -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white">
                    <i class="bi bi-question-circle text-orange me-2"></i>
                    Picks Órfãs (Sem Time Original ou Dono)
                </h5>
            </div>
            <div class="card-body">
                <?php
                $stmtOrphan = $pdo->query("
                    SELECT 
                        p.id,
                        p.season_year,
                        p.round,
                        p.original_team_id,
                        p.team_id,
                        CASE 
                            WHEN p.original_team_id IS NULL THEN 'SEM TIME ORIGINAL'
                            WHEN p.team_id IS NULL THEN 'SEM DONO ATUAL'
                            ELSE 'OK'
                        END AS status
                    FROM picks p
                    WHERE p.original_team_id IS NULL OR p.team_id IS NULL
                ");
                $orphanPicks = $stmtOrphan->fetchAll();
                
                if (count($orphanPicks) === 0): ?>
                    <div class="alert alert-success mb-0">
                        <i class="bi bi-check-circle me-2"></i>
                        Nenhuma pick órfã encontrada! ✅
                    </div>
                <?php else: ?>
                    <div class="alert alert-warning">
                        <i class="bi bi-exclamation-triangle me-2"></i>
                        <strong><?= count($orphanPicks) ?> picks órfãs encontradas!</strong>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-dark table-striped">
                            <thead>
                                <tr>
                                    <th>ID</th>
                                    <th>Ano</th>
                                    <th>Rodada</th>
                                    <th>original_team_id</th>
                                    <th>team_id</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($orphanPicks as $row): ?>
                                <tr>
                                    <td><?= $row['id'] ?></td>
                                    <td><?= htmlspecialchars($row['season_year']) ?></td>
                                    <td><?= $row['round'] ?></td>
                                    <td><?= $row['original_team_id'] ?? '<span class="text-danger">NULL</span>' ?></td>
                                    <td><?= $row['team_id'] ?? '<span class="text-danger">NULL</span>' ?></td>
                                    <td>
                                        <span class="badge bg-warning text-dark"><?= $row['status'] ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- 4. RESUMO POR LIGA -->
        <div class="card bg-dark-panel border-orange mb-4">
            <div class="card-header bg-dark border-bottom border-orange">
                <h5 class="mb-0 text-white">
                    <i class="bi bi-bar-chart text-orange me-2"></i>
                    Resumo Geral por Liga
                </h5>
            </div>
            <div class="card-body">
                <?php
                $stmtSummary = $pdo->query("
                    SELECT 
                        t.league,
                        COUNT(DISTINCT p.id) AS total_jogadores,
                        COUNT(DISTINCT p.name) AS nomes_unicos,
                        COUNT(DISTINCT p.id) - COUNT(DISTINCT p.name) AS nomes_duplicados
                    FROM players p
                    JOIN teams t ON p.team_id = t.id
                    GROUP BY t.league
                    ORDER BY t.league
                ");
                $summary = $stmtSummary->fetchAll();
                ?>
                <div class="table-responsive">
                    <table class="table table-dark">
                        <thead>
                            <tr>
                                <th>Liga</th>
                                <th>Total de Jogadores</th>
                                <th>Nomes Únicos</th>
                                <th>Nomes Duplicados</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($summary as $row): ?>
                            <tr>
                                <td class="fw-bold text-orange"><?= htmlspecialchars($row['league']) ?></td>
                                <td><?= $row['total_jogadores'] ?></td>
                                <td><?= $row['nomes_unicos'] ?></td>
                                <td>
                                    <?php if ($row['nomes_duplicados'] > 0): ?>
                                        <span class="badge bg-danger"><?= $row['nomes_duplicados'] ?></span>
                                    <?php else: ?>
                                        <span class="badge bg-success">0</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="text-center mt-4">
            <a href="/admin.php" class="btn btn-orange">
                <i class="bi bi-arrow-left me-2"></i>Voltar ao Painel Admin
            </a>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
