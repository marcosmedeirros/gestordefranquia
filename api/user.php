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

    // Nascimento e cidade/estado. Os três são OPCIONAIS e seguem o mesmo
    // desenho do telefone acima: só mudam quando a chave vem no corpo, senão
    // ficam como estão. A tela de aparência não manda esses campos, e sem
    // isso salvar a cor apagaria o endereço.
    $temPessoal = array_key_exists('birth_date', $body)
               || array_key_exists('city', $body)
               || array_key_exists('state', $body)
               || array_key_exists('international', $body);

    $nascimento = $user['birth_date'] ?? null;
    if (array_key_exists('birth_date', $body)) {
        $bruto = trim((string)$body['birth_date']);
        if ($bruto === '') {
            $nascimento = null;  // limpar é uma escolha válida
        } else {
            $nascimento = normalizarNascimento($bruto);
            if ($nascimento === null) {
                jsonResponse(422, ['error' => 'Data de nascimento inválida. Confira o dia, o mês e o ano.']);
            }
        }
    }

    $cidade = $user['city'] ?? null;
    if (array_key_exists('city', $body)) {
        // O limite bate com o VARCHAR(80) da coluna: sem o corte, um nome
        // colado grande demais viraria erro de banco em vez de aviso na tela.
        $cidade = mb_substr(trim((string)$body['city']), 0, 80) ?: null;
    }

    $estado = $user['state'] ?? null;
    $pais   = $user['country'] ?? null;

    // "Moro fora do Brasil" manda no par estado/país, e é ele que decide qual
    // dos dois vale. Sem esse ramo, o cliente teria que mandar state='EX' na
    // mão e nada impediria uma conta com UF do Brasil E país preenchido —
    // duas respostas diferentes pra mesma pergunta, guardadas juntas.
    if (!empty($body['international'])) {
        $paisBruto = trim((string)($body['country'] ?? ''));
        if ($paisBruto === '') {
            jsonResponse(422, ['error' => 'Diga em que país você mora.']);
        }
        $estado = UF_EXTERIOR;
        $pais   = mb_substr($paisBruto, 0, 60);

    } elseif (array_key_exists('state', $body) || array_key_exists('international', $body)) {
        $ufBruta = trim((string)($body['state'] ?? ''));
        $estado = $ufBruta === '' ? null : normalizarUF($ufBruta);
        if ($ufBruta !== '' && $estado === null) {
            jsonResponse(422, ['error' => 'Estado inválido.']);
        }
        // Desmarcar o check apaga o país. Guardado, ele voltaria a aparecer
        // sozinho no dia que alguém marcasse o check de novo.
        if ($estado !== UF_EXTERIOR) $pais = null;
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

    // Notificações desligadas (opcional) — array vazio significa "quero tudo".
    $notifOff = null;
    $temNotif = array_key_exists('notif_off', $body) && is_array($body['notif_off']);
    if ($temNotif) {
        $catalogo = getNotifCatalog();
        $chaves = array_values(array_unique(array_filter(
            array_map('strval', $body['notif_off']),
            fn($k) => isset($catalogo[$k])
        )));
        $notifOff = $chaves ? implode(',', $chaves) : null;
    }

    // Opt-in do WhatsApp — separado das preferências de tipo de propósito:
    // o canal é mais invasivo, então ligar push não liga WhatsApp junto.
    $temOptin = array_key_exists('whatsapp_optin', $body);
    if ($temOptin) {
        // Garante a coluna antes do UPDATE (ela nasce com a integração, que
        // pode nunca ter sido aberta neste banco).
        require_once __DIR__ . '/../backend/whatsapp.php';
        ensureWhatsAppTables($pdo);
    }

    $sql    = 'UPDATE users SET name = ?, photo_url = ?, phone = ?, accent_color = ?, dashboard_shortcuts = ?';
    $params = [$name, $photoUrl ?: null, $phone, $accentColor, $shortcuts];
    if ($temNotif) {
        $sql     .= ', notif_off = ?';
        $params[] = $notifOff;
    }
    if ($temOptin) {
        $sql     .= ', whatsapp_optin = ?';
        $params[] = !empty($body['whatsapp_optin']) ? 1 : 0;
    }
    if ($temPessoal) {
        $sql     .= ', birth_date = ?, city = ?, state = ?, country = ?';
        $params[] = $nascimento;
        $params[] = $cidade;
        $params[] = $estado;
        $params[] = $pais;
    }
    $sql     .= ' WHERE id = ?';
    $params[] = $user['id'];

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    // Atualizar sessão
    $updated = $user;
    $updated['name'] = $name;
    $updated['photo_url'] = $photoUrl ?: $user['photo_url'] ?? null;
    $updated['phone'] = $phone;
    $updated['accent_color'] = $accentColor;
    $updated['dashboard_shortcuts'] = $shortcuts;
    if ($temNotif) $updated['notif_off'] = $notifOff;
    if ($temPessoal) {
        $updated['birth_date'] = $nascimento;
        $updated['city']       = $cidade;
        $updated['state']      = $estado;
        $updated['country']    = $pais;
    }
    setUserSession($updated);

    jsonResponse(200, ['message' => 'Perfil atualizado.']);
}

jsonResponse(405, ['error' => 'Method not allowed']);
