<?php
session_start();
require __DIR__ . '/../core/conexao.php';
if (!isset($_SESSION['user_id'])) { http_response_code(403); die('Não autorizado'); }
$stmt = $pdo->prepare("SELECT is_admin FROM usuarios WHERE id=?");
$stmt->execute([(int)$_SESSION['user_id']]);
$u = $stmt->fetch(PDO::FETCH_ASSOC);
if (empty($u['is_admin'])) { http_response_code(403); die('Apenas admin'); }

// Verifica se já existem jogos r16
$existing = (int)$pdo->query("SELECT COUNT(*) FROM copa26_matches WHERE phase='r16'")->fetchColumn();
if ($existing > 0) {
    die("Já existem {$existing} jogos r16 cadastrados. Nada foi inserido.");
}

$jogos = [
    ['2026-06-28', '16:00',  5,  6],  // J73: África do Sul × Canadá
    ['2026-06-29', '14:00', 17, 18],  // J76: Brasil × Japão
    ['2026-06-29', '17:30',  1,  2],  // J74: Alemanha × Paraguai
    ['2026-06-29', '22:00',  7,  8],  // J75: Holanda × Marrocos
    ['2026-06-30', '14:00', 20, 19],  // J78: Noruega × C. do Marfim
    ['2026-06-30', '18:00',  3,  4],  // J77: França × Suécia
    ['2026-06-30', '22:00', 21, 22],  // J79: México × Equador
    ['2026-07-01', '13:00', 23, 24],  // J80: Inglaterra × RD Congo
    ['2026-07-01', '17:00', 15, 16],  // J82: Bélgica × Senegal
    ['2026-07-01', '21:00', 13, 14],  // J81: EUA × Bósnia
    ['2026-07-02', '16:00', 11, 12],  // J84: Espanha × Áustria
    ['2026-07-02', '20:00',  9, 10],  // J83: Portugal × Croácia
    ['2026-07-03', '00:00', 29, 30],  // J85: Suíça × Argélia
    ['2026-07-03', '15:00', 27, 28],  // J88: Austrália × Egito
    ['2026-07-03', '19:00', 25, 26],  // J86: Argentina × Cabo Verde
    ['2026-07-03', '22:30', 31, 32],  // J87: Colômbia × Gana
];

$st = $pdo->prepare("INSERT INTO copa26_matches (match_date, match_time, home_team_id, away_team_id, phase) VALUES (?,?,?,?,'r16')");
foreach ($jogos as $j) {
    $st->execute($j);
}

echo "✅ " . count($jogos) . " jogos da fase de 16avos cadastrados com sucesso!";
