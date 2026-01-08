<?php
function loadConfig(): array
{
    static $config = null;
    if (is_array($config)) {
        return $config;
    }

    $configFile = __DIR__ . '/config.php';
    if (!file_exists($configFile)) {
        http_response_code(500);
        echo json_encode(['error' => 'Config file missing.']);
        exit;
    }

    $config = require $configFile;
    return $config;
}

function jsonResponse(int $status, array $payload): void
{
    http_response_code($status);
    header('Content-Type: application/json');
    echo json_encode($payload);
    exit;
}

function requireMethod(string $method): void
{
    if ($_SERVER['REQUEST_METHOD'] !== $method) {
        jsonResponse(405, ['error' => 'Method not allowed']);
    }
}

function readJsonBody(): array
{
    $raw = file_get_contents('php://input');
    $data = json_decode($raw, true) ?: [];
    return $data;
}

function sendVerificationEmail(string $email, string $token): bool
{
    $config = loadConfig();
    $verifyUrl = rtrim($config['mail']['verify_base_url'], '?') . '?token=' . urlencode($token);
    $subject = 'Verifique seu e-mail (FBA)';
    $message = "Clique para verificar seu e-mail: {$verifyUrl}";
    $headers = 'From: ' . $config['mail']['from'];
    // On Hostinger, ensure mail() is permitted; replace with SMTP if needed.
    return mail($email, $subject, $message, $headers);
}

function sendPasswordResetEmail(string $email, string $token, string $name): bool
{
    $config = loadConfig();
    $resetUrl = 'https://marcosmedeiros.page/reset-password.php?token=' . urlencode($token);
    $subject = 'Recuperação de Senha - FBA Manager';
    
    $message = "
Olá {$name},

Recebemos uma solicitação para redefinir sua senha do FBA Manager Control.

Clique no link abaixo para criar uma nova senha:
{$resetUrl}

Este link expira em 1 hora.

Se você não solicitou esta alteração, ignore este e-mail.

Atenciosamente,
Equipe FBA Manager
    ";
    
    $headers = 'From: ' . $config['mail']['from'];
    return mail($email, $subject, $message, $headers);
}

function topEightCap(PDO $pdo, int $teamId): int
{
    $stmt = $pdo->prepare('SELECT SUM(ovr) as cap FROM (
        SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8
    ) as ranked');
    $stmt->execute([$teamId]);
    $row = $stmt->fetch();
    return (int) ($row['cap'] ?? 0);
}

function capWithCandidate(PDO $pdo, int $teamId, int $candidateOvr): int
{
    $stmt = $pdo->prepare('SELECT ovr FROM players WHERE team_id = ? ORDER BY ovr DESC LIMIT 8');
    $stmt->execute([$teamId]);
    $ovrs = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $ovrs[] = $candidateOvr;
    rsort($ovrs, SORT_NUMERIC);
    $slice = array_slice($ovrs, 0, 8);
    return array_sum($slice);
}
