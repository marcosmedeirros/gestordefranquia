<?php
// Arquivo de migrações do banco de dados
// Este arquivo deve ser executado uma única vez para preparar o banco

session_start();

// Verificar autenticação
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Apenas admin pode executar migrações
if ($_SESSION['nivel'] !== 'admin') {
    die('Acesso negado. Apenas administradores podem executar migrações.');
}

require_once 'conexao.php';

// Função para executar migrações
function executarMigracao($pdo, $nome, $sql) {
    try {
        $pdo->exec($sql);
        return ['sucesso' => true, 'mensagem' => "✓ Migração '$nome' executada com sucesso!"];
    } catch (PDOException $e) {
        return ['sucesso' => false, 'mensagem' => "✗ Erro na migração '$nome': " . $e->getMessage()];
    }
}

$resultados = [];

// Migração 1: Criar tabela usuario_avatars
$sql_avatar = "
CREATE TABLE IF NOT EXISTS usuario_avatars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL UNIQUE,
    color VARCHAR(50) DEFAULT 'default',
    hardware VARCHAR(50) DEFAULT 'none',
    clothing VARCHAR(50) DEFAULT 'none',
    footwear VARCHAR(50) DEFAULT 'none',
    elite VARCHAR(50) DEFAULT 'none',
    aura VARCHAR(50) DEFAULT 'none',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES usuarios(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
";

$resultados[] = executarMigracao($pdo, 'Criar tabela usuario_avatars', $sql_avatar);

// Se houver POST, executar as migrações
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['executar_migracao'])) {
    $migracao = $_POST['executar_migracao'];
    
    if ($migracao === 'avatar') {
        $resultado = executarMigracao($pdo, 'Criar tabela usuario_avatars', $sql_avatar);
        $resultados[] = $resultado;
    }
}

?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Migrações - Pikafumo Games</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <style>
        :root {
            --accent-green: #00d4ff;
            --accent-purple: #9d4edd;
            --dark-bg: #0a0e27;
            --card-bg: #1a1f3a;
        }
        
        body {
            background: var(--dark-bg);
            color: #fff;
            min-height: 100vh;
            padding: 20px;
        }
        
        .container {
            max-width: 600px;
            margin-top: 40px;
        }
        
        .card {
            background: var(--card-bg);
            border: 1px solid var(--accent-purple);
            border-radius: 12px;
        }
        
        .card-title {
            color: var(--accent-green);
            font-weight: bold;
        }
        
        .alert-success {
            background: rgba(0, 212, 255, 0.1);
            border-color: var(--accent-green);
            color: var(--accent-green);
        }
        
        .alert-danger {
            background: rgba(255, 100, 100, 0.1);
            border-color: #ff6464;
            color: #ff6464;
        }
        
        .btn-primary {
            background: var(--accent-purple);
            border-color: var(--accent-purple);
        }
        
        .btn-primary:hover {
            background: #7c2ff0;
            border-color: #7c2ff0;
        }
        
        .migration-item {
            padding: 15px;
            margin-bottom: 10px;
            background: rgba(157, 78, 221, 0.1);
            border-left: 4px solid var(--accent-purple);
            border-radius: 6px;
        }
        
        .status-ok {
            color: var(--accent-green);
        }
        
        .status-error {
            color: #ff6464;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="card-body">
                <h3 class="card-title">🚀 Migrações do Banco de Dados</h3>
                <p class="text-muted">Prepare seu banco de dados para as novas features</p>
                
                <?php if (!empty($resultados)): ?>
                    <div class="resultados mt-4">
                        <?php foreach ($resultados as $resultado): ?>
                            <div class="alert <?= $resultado['sucesso'] ? 'alert-success' : 'alert-danger' ?>" role="alert">
                                <?= $resultado['mensagem'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
                
                <div class="mt-4">
                    <h5 class="mb-3">Migrações Disponíveis:</h5>
                    
                    <div class="migration-item">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <strong>Avatar Cyber Block</strong>
                                <p class="text-muted small mb-0">Cria a tabela usuario_avatars para customização de avatares</p>
                            </div>
                            <form method="POST" class="d-inline">
                                <input type="hidden" name="executar_migracao" value="avatar">
                                <button type="submit" class="btn btn-sm btn-primary">Executar</button>
                            </form>
                        </div>
                    </div>
                </div>
                
                <hr class="my-4">
                
                <div class="text-end">
                    <a href="index.php" class="btn btn-outline-secondary">Voltar ao Dashboard</a>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
