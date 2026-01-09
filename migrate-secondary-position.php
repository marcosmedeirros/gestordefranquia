<?php
require_once __DIR__ . '/backend/db.php';

try {
    $pdo = db();
    
    // Ler e executar o script SQL
    $sql = file_get_contents(__DIR__ . '/sql/add_secondary_position.sql');
    
    // Dividir por ponto e vírgula e executar cada comando
    $statements = array_filter(array_map('trim', explode(';', $sql)));
    
    foreach ($statements as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignorar erro se coluna/tabela já existe
                if (strpos($e->getMessage(), 'Duplicate column') === false && 
                    strpos($e->getMessage(), 'already exists') === false) {
                    throw $e;
                }
            }
        }
    }
    
    echo "<h1>Migração executada com sucesso!</h1>";
    echo "<p>Campos adicionados:</p>";
    echo "<ul>";
    echo "<li>secondary_position em players</li>";
    echo "<li>seasons_in_league em players</li>";
    echo "<li>Tabela waivers criada</li>";
    echo "<li>current_cycle em teams</li>";
    echo "<li>cycle em trades</li>";
    echo "<li>max_trades em league_settings</li>";
    echo "</ul>";
    echo "<p><a href='/'>Voltar ao Dashboard</a></p>";
    
} catch (Exception $e) {
    echo "<h1>Erro na migração</h1>";
    echo "<p>" . htmlspecialchars($e->getMessage()) . "</p>";
}
