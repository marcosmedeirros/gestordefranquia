<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json');
requireAuth(true); // Admin apenas

try {
    $db = db();
    
    // Verifica se é upload de arquivo
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $seasonId = (int)($_POST['season_id'] ?? 0);
        
        if ($seasonId <= 0) {
            throw new Exception('ID da temporada inválido');
        }
        
        // Verifica se a temporada existe
        $stmt = $db->prepare("SELECT id, league, season_number, year FROM seasons WHERE id = ?");
        $stmt->execute([$seasonId]);
        $season = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$season) {
            throw new Exception('Temporada não encontrada');
        }
        
        $file = $_FILES['csv_file'];
        
        // Validações do arquivo
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception('Erro no upload do arquivo');
        }
        
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            throw new Exception('Arquivo deve ser CSV');
        }
        
        // Lê o arquivo CSV
        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('Não foi possível ler o arquivo');
        }
        
        $players = [];
        $lineNumber = 0;
        $header = null;
        
        while (($row = fgetcsv($handle, 1000, ',')) !== false) {
            $lineNumber++;
            
            // Primeira linha é o cabeçalho
            if ($lineNumber === 1) {
                $header = array_map('trim', array_map('strtolower', $row));
                continue;
            }
            
            // Pula linhas vazias
            if (empty(array_filter($row))) {
                continue;
            }
            
            // Mapeia os dados usando o cabeçalho
            $data = array_combine($header, $row);
            
            // Validações
            $name = trim($data['nome'] ?? $data['name'] ?? '');
            $position = trim($data['posicao'] ?? $data['posição'] ?? $data['position'] ?? '');
            $age = (int)($data['idade'] ?? $data['age'] ?? 0);
            $ovr = (int)($data['ovr'] ?? $data['overall'] ?? 0);
            
            if (empty($name)) {
                throw new Exception("Linha {$lineNumber}: Nome é obrigatório");
            }
            
            if (empty($position)) {
                throw new Exception("Linha {$lineNumber}: Posição é obrigatória");
            }
            
            if ($age < 18 || $age > 50) {
                throw new Exception("Linha {$lineNumber}: Idade inválida ({$age})");
            }
            
            if ($ovr < 40 || $ovr > 99) {
                throw new Exception("Linha {$lineNumber}: OVR inválido ({$ovr})");
            }
            
            $players[] = [
                'name' => $name,
                'position' => $position,
                'age' => $age,
                'ovr' => $ovr
            ];
        }
        
        fclose($handle);
        
        if (empty($players)) {
            throw new Exception('Nenhum jogador válido encontrado no arquivo');
        }
        
        // Verificar duplicatas antes de inserir
        $duplicates = [];
        foreach ($players as $player) {
            $checkStmt = $db->prepare("SELECT id FROM draft_pool WHERE season_id = ? AND LOWER(name) = LOWER(?)");
            $checkStmt->execute([$seasonId, $player['name']]);
            if ($checkStmt->fetch()) {
                $duplicates[] = $player['name'];
            }
        }
        
        if (!empty($duplicates)) {
            throw new Exception('Jogadores já existem nesta temporada: ' . implode(', ', $duplicates));
        }
        
        // Insere os jogadores no draft_pool da temporada
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            INSERT INTO draft_pool (season_id, name, position, age, ovr, draft_status)
            VALUES (?, ?, ?, ?, ?, 'available')
        ");
        
        $inserted = 0;
        foreach ($players as $player) {
            $stmt->execute([
                $seasonId,
                $player['name'],
                $player['position'],
                $player['age'],
                $player['ovr']
            ]);
            $inserted++;
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => "{$inserted} jogadores importados com sucesso para a Temporada {$season['season_number']}",
            'inserted' => $inserted,
            'season' => $season
        ]);
        
    } else {
        throw new Exception('Método não suportado');
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
} catch (Throwable $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(500);
    echo json_encode([
        'error' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ]);
}
