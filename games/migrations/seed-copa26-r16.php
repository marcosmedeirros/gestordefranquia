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

// Monta mapa de nome → id a partir do copa26_teams (opcional, para o JOIN funcionar)
$teamMap = [];
try {
    foreach ($pdo->query("SELECT id, name FROM copa26_teams")->fetchAll(PDO::FETCH_ASSOC) as $t) {
        $teamMap[mb_strtolower(trim($t['name']))] = (int)$t['id'];
    }
} catch (Exception $e) {}

function tid(string $name, array &$map): ?int {
    return $map[mb_strtolower(trim($name))] ?? null;
}

$jogos = [
    // data          hora    home                 away
    ['2026-06-28', '16:00', 'África do Sul',     'Canadá'],
    ['2026-06-29', '14:00', 'Brasil',            'Japão'],
    ['2026-06-29', '17:30', 'Alemanha',          'Paraguai'],
    ['2026-06-29', '22:00', 'Holanda',           'Marrocos'],
    ['2026-06-30', '14:00', 'Noruega',           'Costa do Marfim'],
    ['2026-06-30', '18:00', 'França',            'Suécia'],
    ['2026-06-30', '22:00', 'México',            'Equador'],
    ['2026-07-01', '13:00', 'Inglaterra',        'RD Congo'],
    ['2026-07-01', '17:00', 'Bélgica',           'Senegal'],
    ['2026-07-01', '21:00', 'Estados Unidos',    'Bósnia'],
    ['2026-07-02', '16:00', 'Espanha',           'Áustria'],
    ['2026-07-02', '20:00', 'Portugal',          'Croácia'],
    ['2026-07-03', '00:00', 'Suíça',             'Argélia'],
    ['2026-07-03', '15:00', 'Austrália',         'Egito'],
    ['2026-07-03', '19:00', 'Argentina',         'Cabo Verde'],
    ['2026-07-03', '22:30', 'Colômbia',          'Gana'],
];

$st = $pdo->prepare("INSERT INTO copa26_matches
    (match_date, match_time, home_team_id, away_team_id, home_name, away_name, phase)
    VALUES (?, ?, ?, ?, ?, ?, 'r16')");

$ok = 0;
$erros = [];
foreach ($jogos as [$date, $time, $home, $away]) {
    try {
        $st->execute([$date, $time, tid($home, $teamMap), tid($away, $teamMap), $home, $away]);
        $ok++;
    } catch (Exception $e) {
        $erros[] = "$home × $away: " . $e->getMessage();
    }
}

echo "<pre>";
echo "✅ $ok jogos inseridos.\n";
if ($erros) {
    echo "❌ Erros:\n";
    foreach ($erros as $e) echo "  - $e\n";
}
echo "</pre>";
