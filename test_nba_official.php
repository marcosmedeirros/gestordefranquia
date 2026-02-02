<?php
require_once 'backend/nba_official.php';

echo "=== Testando API Oficial da NBA ===\n\n";

echo "1. Buscando todos os jogadores...\n";
$data = nbaOfficialFetchAllPlayers(true, '2024-25');

if ($data) {
    $totalPlayers = count($data['resultSets'][0]['rowSet'] ?? []);
    echo "✅ Sucesso! Total de jogadores ativos: $totalPlayers\n\n";
    
    // Mostrar primeiros 5 jogadores
    echo "Primeiros 5 jogadores:\n";
    $headers = $data['resultSets'][0]['headers'];
    $rows = array_slice($data['resultSets'][0]['rowSet'], 0, 5);
    
    $personIdIdx = array_search('PERSON_ID', $headers);
    $displayNameIdx = array_search('DISPLAY_FIRST_LAST', $headers);
    
    foreach ($rows as $row) {
        echo "  - ID: {$row[$personIdIdx]} | Nome: {$row[$displayNameIdx]}\n";
    }
    echo "\n";
} else {
    echo "❌ Erro ao buscar jogadores\n\n";
    exit(1);
}

echo "2. Testando busca por nome específico...\n";
$testNames = [
    'LeBron James',
    'Stephen Curry',
    'Kevin Durant',
    'Luka Doncic',
    'Giannis Antetokounmpo'
];

foreach ($testNames as $name) {
    echo "Buscando: $name\n";
    $result = nbaOfficialFetchPlayerIdByName($name);
    
    if ($result) {
        echo "  ✅ ID: {$result['id']} | Nome oficial: {$result['name']}\n";
    } else {
        echo "  ❌ Não encontrado\n";
    }
}

echo "\n=== Teste concluído ===\n";
