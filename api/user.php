<?php
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/helpers.php';
require_once __DIR__ . '/../backend/auth.php';

$pdo = db();
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $user = getUserSession();
    if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);
    $user['phone_display'] = formatBrazilianPhone($user['phone'] ?? '');
    jsonResponse(200, ['user' => $user]);
}

if ($method === 'POST') {
    $user = getUserSession();
    if (!$user) jsonResponse(401, ['error' => 'Não autenticado']);

    $body = readJsonBody();
    $name = trim($body['name'] ?? $user['name']);
    $photoUrl = trim($body['photo_url'] ?? '');

    if ($name === '') {
        jsonResponse(422, ['error' => 'Nome é obrigatório.']);
    }

    // O telefone só é exigido quando o cliente está de fato editando o perfil.
    // Requisições que mexem apenas em aparência (cor/atalhos) não mandam o campo
    // e mantêm o telefone já salvo — inclusive quando ele está vazio.
    if (array_key_exists('phone', $body)) {
        $phoneRaw = trim((string)$body['phone']);
        if ($phoneRaw === '') {
            jsonResponse(422, ['error' => 'Telefone é obrigatório.']);
        }
        $phone = normalizeBrazilianPhone($phoneRaw);
        if (!$phone) {
            jsonResponse(422, ['error' => 'Telefone inválido. Informe DDD brasileiro ou código do país (apenas números).']);
        }
    } else {
        $phone = $user['phone'] ?? null;
    }

    // Salvar foto se vier como data URL
    if ($photoUrl && str_starts_with($photoUrl, 'data:image/')) {
        try {
            $commaPos = strpos($photoUrl, ',');
            $meta = substr($photoUrl, 0, $commaPos);
            $base64 = substr($photoUrl, $commaPos + 1);
            $mime = null;
            if (preg_match('/data:(image\/(png|jpeg|jpg|webp));base64/i', $meta, $m)) {
                $mime = strtolower($m[1]);
            }
            $ext = 'png';
            if ($mime === 'image/jpeg' || $mime === 'image/jpg') { $ext = 'jpg'; }
            if ($mime === 'image/webp') { $ext = 'webp'; }
            $binary = base64_decode($base64);
            if ($binary === false) { throw new Exception('Falha ao decodificar imagem.'); }

            $dirFs = __DIR__ . '/../img/users';
            if (!is_dir($dirFs)) { @mkdir($dirFs, 0775, true); }
            $filename = 'user-' . $user['id'] . '-' . time() . '.' . $ext;
            $fullPath = $dirFs . '/' . $filename;
            if (file_put_contents($fullPath, $binary) === false) {
                throw new Exception('Falha ao salvar imagem.');
            }
            $photoUrl = '/img/users/' . $filename;
        } catch (Exception $e) {
            $photoUrl = '';
        }
    } else {
        // Se não foi enviada nova foto, manter a atual
        $photoUrl = ($photoUrl !== '') ? $photoUrl : ($user['photo_url'] ?? null);
    }

    // Cor de destaque (opcional) — string vazia limpa e volta pro padrão.
    $accentColorRaw = array_key_exists('accent_color', $body) ? trim((string)$body['accent_color']) : null;
    $accentColor = $user['accent_color'] ?? null;
    if ($accentColorRaw !== null) {
        if ($accentColorRaw === '') {
            $accentColor = null;
        } elseif (isValidAccentColor($accentColorRaw)) {
            $accentColor = $accentColorRaw;
        }
    }

    // Atalhos do dashboard (opcional) — até 4 chaves válidas do catálogo.
    $shortcuts = $user['dashboard_shortcuts'] ?? null;
    if (array_key_exists('dashboard_shortcuts', $body) && is_array($body['dashboard_shortcuts'])) {
        $catalog = getShortcutCatalog();
        $keys = array_values(array_filter(array_map('strval', $body['dashboard_shortcuts']), fn($k) => isset($catalog[$k])));
        $keys = array_slice(array_unique($keys), 0, 4);
        $shortcuts = $keys ? implode(',', $keys) : null;
    }

    $stmt = $pdo->prepare('UPDATE users SET name = ?, photo_url = ?, phone = ?, accent_color = ?, dashboard_shortcuts = ? WHERE id = ?');
    $stmt->execute([$name, $photoUrl ?: null, $phone, $accentColor, $shortcuts, $user['id']]);

    // Atualizar sessão
    $updated = $user;
    $updated['name'] = $name;
    $updated['photo_url'] = $photoUrl ?: $user['photo_url'] ?? null;
    $updated['phone'] = $phone;
    $updated['accent_color'] = $accentColor;
    $updated['dashboard_shortcuts'] = $shortcuts;
    setUserSession($updated);

    jsonResponse(200, ['message' => 'Perfil atualizado.']);
}

jsonResponse(405, ['error' => 'Method not allowed']);
