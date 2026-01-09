<?php
session_start();
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/migrations.php';

// Verificar se é admin
$user = getUserSession();
if (!$user || $user['user_type'] !== 'admin') {
    http_response_code(403);
    die('Acesso negado. Apenas administradores podem acessar esta página.');
}

$action = isset($_GET['action']) ? $_GET['action'] : 'status';
$result = null;

if ($action === 'run') {
    $result = runMigrations();
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Gerenciador de Schema - Admin</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        body { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); min-height: 100vh; padding: 20px; }
        .card { box-shadow: 0 8px 16px rgba(0,0,0,0.1); border: 0; }
        .badge-success { background: #28a745; }
        .badge-danger { background: #dc3545; }
        .status-box { padding: 20px; background: #f8f9fa; border-radius: 8px; margin: 15px 0; }
        .log-item { padding: 10px; border-left: 4px solid #667eea; margin: 5px 0; background: #fff; }
        .log-item.success { border-left-color: #28a745; }
        .log-item.error { border-left-color: #dc3545; }
    </style>
</head>
<body>
<div class="container mt-5">
    <div class="card">
        <div class="card-header bg-primary text-white">
            <h4 class="mb-0">📊 Gerenciador de Schema Automático</h4>
        </div>
        <div class="card-body">
            
            <?php if ($result): ?>
                <div class="alert <?php echo $result['success'] ? 'alert-success' : 'alert-danger'; ?>" role="alert">
                    <h5><?php echo $result['success'] ? '✓ Migrações executadas com sucesso!' : '✗ Houve erros'; ?></h5>
                    <p class="mb-0"><strong>Data:</strong> <?php echo $result['timestamp']; ?></p>
                    <p class="mb-0"><strong>Migrações executadas:</strong> <?php echo $result['executed']; ?></p>
                    <?php if ($result['errors']): ?>
                        <hr>
                        <h6>Erros encontrados:</h6>
                        <ul>
                            <?php foreach ($result['errors'] as $error): ?>
                                <li><?php echo htmlspecialchars($error); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>

            <div class="status-box">
                <h5>🔄 Verificação de Status</h5>
                <p>O sistema de migrações é executado automaticamente quando a aplicação inicia. Ele verifica e cria todas as tabelas e estruturas necessárias.</p>
                <p class="text-muted mb-0">Última verificação: Ao carregar qualquer página da aplicação</p>
            </div>

            <div class="status-box">
                <h5>📋 O que é verificado:</h5>
                <ul>
                    <li>✓ Tabela <code>leagues</code> - Ligas disponíveis</li>
                    <li>✓ Tabela <code>users</code> - Usuários e gestores</li>
                    <li>✓ Tabela <code>divisions</code> - Divisões das ligas</li>
                    <li>✓ Tabela <code>teams</code> - Times e seus dados</li>
                    <li>✓ Tabela <code>players</code> - Elencos dos times</li>
                    <li>✓ Tabela <code>picks</code> - Draft picks</li>
                    <li>✓ Tabela <code>drafts</code> - Drafts por ano/liga</li>
                    <li>✓ Tabela <code>draft_players</code> - Jogadores no draft</li>
                    <li>✓ Tabela <code>seasons</code> - Temporadas</li>
                    <li>✓ Tabela <code>awards</code> - Prêmios e reconhecimentos</li>
                    <li>✓ Tabela <code>playoff_results</code> - Resultados de playoffs</li>
                    <li>✓ Tabela <code>directives</code> - Diretrizes da liga</li>
                    <li>✓ Tabela <code>trades</code> - Trocas entre times</li>
                </ul>
            </div>

            <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                <a href="?action=run" class="btn btn-primary">
                    <span>🔄 Executar Migrações Agora</span>
                </a>
                <a href="/public/admin.html" class="btn btn-secondary">
                    ← Voltar ao Admin
                </a>
            </div>

            <hr class="my-4">

            <div class="alert alert-info">
                <h6>💡 Como funciona:</h6>
                <p class="mb-0">
                    Toda vez que qualquer página carrega, o sistema verifica automaticamente se todas as tabelas e colunas existem no banco de dados. 
                    Se alguma tabela ou coluna estiver faltando, ela é criada automaticamente. Isso garante que o schema esteja sempre atualizado, 
                    mesmo em caso de falhas anteriores ou quando novos campos são adicionados ao projeto.
                </p>
            </div>

        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
