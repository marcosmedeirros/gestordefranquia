<?php
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';

header('Content-Type: application/json');
requireAuth(true); // Admin apenas

try {
    $db = getDbConnection();
    
    // Verifica se é upload de arquivo
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
        $draftId = (int)($_POST['draft_id'] ?? 0);
        
        if ($draftId <= 0) {
            throw new Exception('ID do draft inválido');
        }
        
        // Verifica se o draft existe
        $stmt = $db->prepare("SELECT id, year, league FROM drafts WHERE id = ?");
        $stmt->execute([$draftId]);
        $draft = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$draft) {
            throw new Exception('Draft não encontrado');
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
        
        // Insere os jogadores
        $db->beginTransaction();
        
        $stmt = $db->prepare("
            INSERT INTO draft_players (draft_id, name, position, age, ovr)
            VALUES (?, ?, ?, ?, ?)
        ");
        
        $inserted = 0;
        foreach ($players as $player) {
            $stmt->execute([
                $draftId,
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
            'message' => "{$inserted} jogadores importados com sucesso",
            'inserted' => $inserted,
            'draft' => $draft
        ]);
        
    } else {
        throw new Exception('Método não suportado');
    }
    
} catch (Exception $e) {
    if (isset($db) && $db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode(['error' => $e->getMessage()]);
}
