<?php
require_once __DIR__ . '/backend/db.php';

try {
    $pdo = db();
    
    // Ler e executar o script SQL
    $sql = file_get_contents(__DIR__ . '/sql/diretrizes.sql');
    
    // Dividir por ponto e vírgula e executar cada comando
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignorar erro se tabela já existe
                if (strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }
    
    echo "<h1>Migração de Diretrizes executada com sucesso!</h1>";
    echo "<p>Tabelas criadas:</p>";
    echo "<ul>";
    echo "<li>directive_deadlines - Prazos de envio configurados pelo admin</li>";
    echo "<li>team_directives - Diretrizes enviadas pelos times</li>";
    echo "</ul>";
    echo "<p><a href='/'>Voltar ao Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<h1>Erro na migração</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
