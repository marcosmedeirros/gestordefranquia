<?php
/**
 * Migration: Corrigir anos zerados nas temporadas
 * Fórmula: season.year = sprints.start_year + seasons.season_number - 1
 */

require_once __DIR__ . '/backend/db.php';

echo "<h2>Corrigindo anos das temporadas</h2>";

try {
    $pdo = db();
    
    // 1. Verificar sprints sem start_year
    echo "<h3>1. Verificando sprints sem start_year...</h3>";
    $stmt = $pdo->query("SELECT id, league, sprint_number, start_year FROM sprints WHERE start_year IS NULL OR start_year = 0");
    $sprints = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($sprints) > 0) {
        echo "<p>Encontrados " . count($sprints) . " sprints sem start_year:</p>";
        
        // Definir start_year padrão por liga
        $defaultYears = [
            'ELITE' => 2016,
            'NEXT' => 2017,
            'RISE' => 2018,
            'ROOKIE' => 2019
        ];
        
        foreach ($sprints as $sprint) {
            $startYear = $defaultYears[$sprint['league']] ?? 2020;
            $pdo->prepare("UPDATE sprints SET start_year = ? WHERE id = ?")->execute([$startYear, $sprint['id']]);
            echo "<li>Sprint #{$sprint['id']} ({$sprint['league']}) -> start_year = {$startYear}</li>";
        }
        echo "<p style='color: green;'>✅ Sprints corrigidos!</p>";
    } else {
        echo "<p style='color: green;'>✅ Todos os sprints já têm start_year definido.</p>";
    }
    
    // 2. Corrigir seasons.year baseado na fórmula
    echo "<h3>2. Corrigindo seasons.year...</h3>";
    $stmt = $pdo->query("
        SELECT s.id, s.season_number, s.year, s.league, sp.start_year,
               (sp.start_year + s.season_number - 1) as expected_year
        FROM seasons s
        INNER JOIN sprints sp ON sp.id = s.sprint_id
        WHERE s.year IS NULL OR s.year = 0 OR s.year != (sp.start_year + s.season_number - 1)
    ");
    $seasons = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($seasons) > 0) {
        echo "<p>Encontradas " . count($seasons) . " temporadas com ano incorreto:</p>";
        echo "<ul>";
        foreach ($seasons as $season) {
            $expectedYear = $season['expected_year'];
            $pdo->prepare("UPDATE seasons SET year = ? WHERE id = ?")->execute([$expectedYear, $season['id']]);
            echo "<li>Temporada #{$season['id']} ({$season['league']} T{$season['season_number']}): {$season['year']} -> {$expectedYear}</li>";
        }
        echo "</ul>";
        echo "<p style='color: green;'>✅ Temporadas corrigidas!</p>";
    } else {
        echo "<p style='color: green;'>✅ Todos os anos das temporadas estão corretos.</p>";
    }
    
    // 3. Mostrar resumo
    echo "<h3>3. Resumo atual:</h3>";
    $stmt = $pdo->query("
        SELECT s.league, s.season_number, s.year, sp.start_year as sprint_start
        FROM seasons s
        INNER JOIN sprints sp ON sp.id = s.sprint_id
        ORDER BY s.league, s.season_number
    ");
    $all = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1' cellpadding='5'>";
    echo "<tr><th>Liga</th><th>Temporada</th><th>Ano</th><th>Sprint Start</th></tr>";
    foreach ($all as $row) {
        echo "<tr><td>{$row['league']}</td><td>{$row['season_number']}</td><td>{$row['year']}</td><td>{$row['sprint_start']}</td></tr>";
    }
    echo "</table>";
    
    echo "<h2 style='color: green;'>✅ Migration concluída!</h2>";
    
} catch (Exception $e) {
    echo "<p style='color: red;'>❌ Erro: " . htmlspecialchars($e->getMessage()) . "</p>";
}
