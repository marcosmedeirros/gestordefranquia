<?php
header('Content-Type: application/json');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';

requireAuth();

$pdo = db();

ensureHallOfFameTable($pdo);

try {
    echo json_encode(['success' => true, 'groups' => getHallOfFameGrouped($pdo)]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Erro ao carregar Hall da Fama']);
}
