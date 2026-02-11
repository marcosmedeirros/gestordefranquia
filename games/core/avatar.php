<?php
/**
 * CORE/AVATAR.PHP - Sistema de Avatar "Cyber Block"
 * Gerencia customização e renderização de avatares dos usuários
 */

// ===== DEFINIÇÃO DE COMPONENTES DE AVATAR =====

$AVATAR_COMPONENTES = [
    'colors' => [
        'default' => ['nome' => 'Branco Padrão', 'primary' => '#e0e0e0', 'secondary' => '#9e9e9e', 'rarity' => 'common', 'preco' => 0],
        'neon_blue' => ['nome' => 'Azul Neon', 'primary' => '#00d4ff', 'secondary' => '#0099cc', 'rarity' => 'common', 'preco' => 100],
        'neon_pink' => ['nome' => 'Rosa Neon', 'primary' => '#ff006e', 'secondary' => '#c2185b', 'rarity' => 'common', 'preco' => 100],
        'neon_green' => ['nome' => 'Verde Neon', 'primary' => '#39ff14', 'secondary' => '#2daa0e', 'rarity' => 'rare', 'preco' => 300],
        'cyberpunk_purple' => ['nome' => 'Roxo Cyber', 'primary' => '#9d4edd', 'secondary' => '#5a189a', 'rarity' => 'rare', 'preco' => 300],
        'gold_legacy' => ['nome' => 'Dourado Relíquia', 'primary' => '#ffd700', 'secondary' => '#cc9900', 'rarity' => 'epic', 'preco' => 800],
        'void_black' => ['nome' => 'Void Preto', 'primary' => '#0a0e27', 'secondary' => '#1a1a2e', 'rarity' => 'epic', 'preco' => 800],
        'plasma_orange' => ['nome' => 'Laranja Plasma', 'primary' => '#ff6b35', 'secondary' => '#ff3700', 'rarity' => 'legendary', 'preco' => 2000],
    ],
    'hardware' => [
        'none' => ['nome' => 'Nenhum', 'rarity' => 'common', 'preco' => 0],
        'antenna_dish' => ['nome' => 'Antena Parabólica', 'rarity' => 'common', 'preco' => 150],
        'pixel_crown' => ['nome' => 'Coroa de Pixel', 'rarity' => 'rare', 'preco' => 400],
        'robot_ears' => ['nome' => 'Orelhas Robóticas', 'rarity' => 'rare', 'preco' => 400],
        'halo' => ['nome' => 'Auréola Neon', 'rarity' => 'epic', 'preco' => 1000],
        'spiky_hair' => ['nome' => 'Cabelo Espinhoso', 'rarity' => 'common', 'preco' => 150],
        'visor_tech' => ['nome' => 'Visor Tecnológico', 'rarity' => 'epic', 'preco' => 1000],
        'crown_mythic' => ['nome' => 'Coroa Mítica', 'rarity' => 'mythic', 'preco' => 5000],
    ],
    'clothing' => [
        'none' => ['nome' => 'Nenhum', 'rarity' => 'common', 'preco' => 0],
        'tuxedo' => ['nome' => 'Terno Dev', 'rarity' => 'common', 'preco' => 200],
        'spartan_armor' => ['nome' => 'Armadura Spartan', 'rarity' => 'rare', 'preco' => 500],
        'cloak' => ['nome' => 'Manto', 'rarity' => 'rare', 'preco' => 500],
        'cyber_vest' => ['nome' => 'Colete Cyber', 'rarity' => 'epic', 'preco' => 1200],
        'matrix_coat' => ['nome' => 'Casaco Matrix', 'rarity' => 'legendary', 'preco' => 2500],
        'void_armor' => ['nome' => 'Armadura Void', 'rarity' => 'mythic', 'preco' => 6000],
    ],
    'footwear' => [
        'none' => ['nome' => 'Nenhum', 'rarity' => 'common', 'preco' => 0],
        'sneakers' => ['nome' => 'Tênis Desportivos', 'rarity' => 'common', 'preco' => 100],
        'mag_boots' => ['nome' => 'Botas Magnéticas', 'rarity' => 'rare', 'preco' => 450],
        'jet_thrusters' => ['nome' => 'Propulsores a Jato', 'rarity' => 'epic', 'preco' => 1100],
        'hover_pads' => ['nome' => 'Placas Flutuantes', 'rarity' => 'legendary', 'preco' => 2300],
        'infinity_boots' => ['nome' => 'Botas Infinitas', 'rarity' => 'mythic', 'preco' => 5500],
    ],
    'elite' => [
        'none' => ['nome' => 'Nenhum', 'rarity' => 'common', 'preco' => 0],
        'light_sword' => ['nome' => 'Espada de Luz', 'rarity' => 'epic', 'preco' => 1300],
        'arm_cannon' => ['nome' => 'Canhão de Braço', 'rarity' => 'epic', 'preco' => 1300],
        'pet_drone' => ['nome' => 'Drone de Estimação', 'rarity' => 'rare', 'preco' => 600],
        'magic_orb' => ['nome' => 'Orbe Mágica', 'rarity' => 'legendary', 'preco' => 2700],
        'plasma_rifle' => ['nome' => 'Rifle de Plasma', 'rarity' => 'legendary', 'preco' => 2700],
        'infinity_gauntlet' => ['nome' => 'Manopla Infinita', 'rarity' => 'mythic', 'preco' => 7000],
    ],
    'aura' => [
        'none' => ['nome' => 'Nenhuma', 'color' => 'transparent', 'rarity' => 'common', 'preco' => 0],
        'data_flow' => ['nome' => 'Data Flow', 'color' => '#00d4ff', 'rarity' => 'common', 'preco' => 250],
        'overload' => ['nome' => 'Sobrecarga', 'color' => '#ff0000', 'rarity' => 'rare', 'preco' => 550],
        'divine' => ['nome' => 'Divino', 'color' => '#ffd700', 'rarity' => 'epic', 'preco' => 1400],
        'void_aura' => ['nome' => 'Aura Void', 'color' => '#9d4edd', 'rarity' => 'legendary', 'preco' => 2800],
        'chaos' => ['nome' => 'Caótico', 'color' => '#ff006e', 'rarity' => 'legendary', 'preco' => 2800],
        'cosmic' => ['nome' => 'Cósmica', 'color' => '#00ffff', 'rarity' => 'mythic', 'preco' => 6500],
    ]
];

// ===== SISTEMA DE LOOT BOXES =====

$LOOT_BOXES = [
    'basica' => [
        'nome' => 'Caixa Bolicheiro',
        'descricao' => 'Chances comuns de itens básicos',
        'preco' => 20,
        'icon' => '📦',
        'cor' => '#888888',
        'chance_comum' => 85,
        'chance_rara' => 14,
        'chance_epica' => 1,
        'chance_lendaria' => 0,
        'chance_mitica' => 0,
    ],
    'top' => [
        'nome' => 'Caixa Pnip',
        'descricao' => 'Melhores chances de itens raros',
        'preco' => 30,
        'icon' => '⭐',
        'cor' => '#ffd700',
        'chance_comum' => 50,
        'chance_rara' => 35,
        'chance_epica' => 13,
        'chance_lendaria' => 2,
        'chance_mitica' => 0,
    ],
    'premium' => [
        'nome' => 'Caixa PDSA',
        'descricao' => 'Chances altas de itens lendários',
        'preco' => 40,
        'icon' => '💎',
        'cor' => '#ff0099',
        'chance_comum' => 20,
        'chance_rara' => 30,
        'chance_epica' => 30,
        'chance_lendaria' => 18,
        'chance_mitica' => 2,
    ],
];

// ===== FUNÇÕES DE AVATAR =====

function obterCustomizacaoAvatar($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("SELECT color, hardware, clothing, footwear, elite, aura FROM usuario_avatars WHERE user_id = :uid");
        $stmt->execute([':uid' => $user_id]);
        $avatar = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$avatar) {
            return [
                'color' => 'default',
                'hardware' => 'none',
                'clothing' => 'none',
                'footwear' => 'none',
                'elite' => 'none',
                'aura' => 'none'
            ];
        }
        
        return $avatar;
    } catch (PDOException $e) {
        return [
            'color' => 'default',
            'hardware' => 'none',
            'clothing' => 'none',
            'footwear' => 'none',
            'elite' => 'none',
            'aura' => 'none'
        ];
    }
}

function usuarioPossuiItem($pdo, $user_id, $categoria, $item_id) {
    // Itens livres por padrão
    if (($categoria === 'colors' && $item_id === 'default') || ($categoria !== 'colors' && $item_id === 'none')) return true;
    try {
        $stmt = $pdo->prepare("SELECT 1 FROM usuario_inventario WHERE user_id = :uid AND categoria = :cat AND item_id = :iid LIMIT 1");
        $stmt->execute([':uid' => $user_id, ':cat' => $categoria, ':iid' => $item_id]);
        return (bool)$stmt->fetchColumn();
    } catch (PDOException $e) {
        return false;
    }
}

function salvarCustomizacaoAvatar($pdo, $user_id, $color, $hardware, $clothing, $footwear, $elite, $aura) {
    // Valida cada item contra o inventário
    $color = usuarioPossuiItem($pdo, $user_id, 'colors', $color) ? $color : 'default';
    $hardware = usuarioPossuiItem($pdo, $user_id, 'hardware', $hardware) ? $hardware : 'none';
    $clothing = usuarioPossuiItem($pdo, $user_id, 'clothing', $clothing) ? $clothing : 'none';
    $footwear = usuarioPossuiItem($pdo, $user_id, 'footwear', $footwear) ? $footwear : 'none';
    $elite = usuarioPossuiItem($pdo, $user_id, 'elite', $elite) ? $elite : 'none';
    $aura = usuarioPossuiItem($pdo, $user_id, 'aura', $aura) ? $aura : 'none';
    try {
        $stmt = $pdo->prepare("
            INSERT INTO usuario_avatars (user_id, color, hardware, clothing, footwear, elite, aura) 
            VALUES (:uid, :color, :hw, :cloth, :foot, :elite, :aura)
            ON DUPLICATE KEY UPDATE 
                color = :color, hardware = :hw, clothing = :cloth, footwear = :foot, elite = :elite, aura = :aura
        ");
        $stmt->execute([
            ':uid' => $user_id,
            ':color' => $color,
            ':hw' => $hardware,
            ':cloth' => $clothing,
            ':foot' => $footwear,
            ':elite' => $elite,
            ':aura' => $aura
        ]);
        return true;
    } catch (PDOException $e) {
        return false;
    }
}

function renderizarAvatarSVG($customizacao, $size = 100) {
    global $AVATAR_COMPONENTES;
    $color = $AVATAR_COMPONENTES['colors'][$customizacao['color']] ?? $AVATAR_COMPONENTES['colors']['default'];
    $aura = $AVATAR_COMPONENTES['aura'][$customizacao['aura']] ?? $AVATAR_COMPONENTES['aura']['none'];
    $primary = htmlspecialchars($color['primary']);
    $secondary = htmlspecialchars($color['secondary']);
    $aura_color = htmlspecialchars($aura['color']);
    $height = $size * 1.4;
    $svg = "<svg viewBox=\"0 0 100 120\" width=\"{$size}\" height=\"{$height}\" xmlns=\"http://www.w3.org/2000/svg\">";
    $svg .= "<defs><linearGradient id=\"heroGrad\" x1=\"0%\" y1=\"0%\" x2=\"100%\" y2=\"100%\"><stop offset=\"0%\" style=\"stop-color:rgba(255,255,255,0.15);stop-opacity:1\" /><stop offset=\"100%\" style=\"stop-color:rgba(0,0,0,0.3);stop-opacity:1\" /></linearGradient></defs>";
    $svg .= "<ellipse cx=\"50\" cy=\"115\" rx=\"35\" ry=\"8\" fill=\"black\" opacity=\"0.3\" />";
    $svg .= "<rect x=\"20\" y=\"20\" width=\"60\" height=\"85\" rx=\"12\" fill=\"{$primary}\" stroke=\"#000\" stroke-width=\"4\"/>";
    $svg .= "<rect x=\"20\" y=\"20\" width=\"60\" height=\"85\" rx=\"12\" fill=\"url(#heroGrad)\"/>";
    $svg .= "<rect x=\"5\" y=\"40\" width=\"15\" height=\"45\" rx=\"6\" fill=\"{$secondary}\" stroke=\"#000\" stroke-width=\"4\"/>";
    $svg .= "<g id=\"layer-clothes\"></g><g id=\"layer-shoes\"></g>";
    $svg .= "<rect x=\"30\" y=\"30\" width=\"45\" height=\"30\" rx=\"8\" fill=\"#0f172a\" stroke=\"#000\" stroke-width=\"3\"/>";
    $svg .= "<g id=\"eyes\"><rect x=\"38\" y=\"41\" width=\"8\" height=\"8\" rx=\"2\" fill=\"#818cf8\"/><rect x=\"54\" y=\"41\" width=\"8\" height=\"8\" rx=\"2\" fill=\"#818cf8\"/></g>";
    // Clothes
    $clothing = $customizacao['clothing'] ?? 'none';
    if ($clothing === 'tuxedo') {
        $svg .= "<rect x=\"20\" y=\"60\" width=\"60\" height=\"45\" rx=\"2\" fill=\"#1e293b\" stroke=\"black\" stroke-width=\"2\"/><path d=\"M40,60 L50,85 L60,60\" fill=\"white\"/><rect x=\"47\" y=\"60\" width=\"6\" height=\"25\" fill=\"#ef4444\"/>";
    } elseif (in_array($clothing, ['spartan_armor','cyber_vest','void_armor'])) {
        $svg .= "<rect x=\"15\" y=\"55\" width=\"70\" height=\"30\" rx=\"4\" fill=\"#475569\" stroke=\"black\" stroke-width=\"2\"/><circle cx=\"50\" cy=\"70\" r=\"8\" fill=\"#3b82f6\" opacity=\"0.8\"/>";
    } elseif (in_array($clothing, ['cloak','matrix_coat'])) {
        $svg .= "<path d=\"M20,30 L5,110 L95,110 L80,30 Z\" fill=\"#7c3aed\" opacity=\"0.4\" stroke=\"black\" stroke-width=\"2\"/>";
    }
    // Shoes
    $foot = $customizacao['footwear'] ?? 'none';
    if ($foot === 'sneakers') {
        $svg .= "<rect x=\"25\" y=\"105\" width=\"20\" height=\"10\" rx=\"3\" fill=\"#ef4444\" stroke=\"black\" stroke-width=\"2\"/><rect x=\"55\" y=\"105\" width=\"20\" height=\"10\" rx=\"3\" fill=\"#ef4444\" stroke=\"black\" stroke-width=\"2\"/><rect x=\"25\" y=\"112\" width=\"20\" height=\"4\" fill=\"white\"/><rect x=\"55\" y=\"112\" width=\"20\" height=\"4\" fill=\"white\"/>";
    } elseif (in_array($foot, ['jet_thrusters','hover_pads'])) {
        $svg .= "<rect x=\"25\" y=\"105\" width=\"15\" height=\"8\" rx=\"2\" fill=\"#94a3b8\" stroke=\"black\" stroke-width=\"2\"/><rect x=\"60\" y=\"105\" width=\"15\" height=\"8\" rx=\"2\" fill=\"#94a3b8\" stroke=\"black\" stroke-width=\"2\"/><path d=\"M25,113 L32,125 L40,113\" fill=\"#fbbf24\"/><path d=\"M60,113 L67,125 L75,113\" fill=\"#fbbf24\"/>";
    } elseif ($foot === 'mag_boots') {
        $svg .= "<rect x=\"24\" y=\"104\" width=\"22\" height=\"11\" rx=\"2\" fill=\"#64748b\" stroke=\"black\" stroke-width=\"2\"/><rect x=\"54\" y=\"104\" width=\"22\" height=\"11\" rx=\"2\" fill=\"#64748b\" stroke=\"black\" stroke-width=\"2\"/>";
    }
    // Elite
    $elite = $customizacao['elite'] ?? 'none';
    if (in_array($elite, ['arm_cannon','plasma_rifle'])) {
        $svg .= "<rect x=\"75\" y=\"60\" width=\"20\" height=\"10\" rx=\"2\" fill=\"#334155\" stroke=\"black\" stroke-width=\"2\"/><rect x=\"70\" y=\"65\" width=\"8\" height=\"15\" fill=\"#1e293b\" stroke=\"black\" stroke-width=\"2\"/>";
    } elseif ($elite === 'light_sword') {
        $svg .= "<rect x=\"75\" y=\"40\" width=\"6\" height=\"50\" rx=\"2\" fill=\"#3b82f6\" stroke=\"#fff\" stroke-width=\"1\"/><rect x=\"70\" y=\"85\" width=\"16\" height=\"6\" fill=\"#1e293b\"/>";
    } elseif ($elite === 'pet_drone') {
        $svg .= "<rect x=\"85\" y=\"20\" width=\"15\" height=\"15\" rx=\"8\" fill=\"#facc15\" stroke=\"black\" stroke-width=\"2\"/><circle cx=\"92\" cy=\"27\" r=\"3\" fill=\"#000\"/>";
    } elseif ($elite === 'magic_orb') {
        $svg .= "<circle cx=\"85\" cy=\"55\" r=\"6\" fill=\"#a78bfa\" stroke=\"#000\" stroke-width=\"2\"/>";
    } elseif ($elite === 'infinity_gauntlet') {
        $svg .= "<rect x=\"70\" y=\"70\" width=\"12\" height=\"12\" rx=\"2\" fill=\"#d97706\" stroke=\"#000\" stroke-width=\"2\"/>";
    }
    // Hardware (emoji simples no topo, colado na cabeça)
    $hw = $customizacao['hardware'] ?? 'none';
    if ($hw === 'pixel_crown' || $hw === 'crown_mythic') {
        $svg .= "<text x=\"50\" y=\"22\" text-anchor=\"middle\" font-size=\"20\">👑</text>";
    } elseif ($hw === 'antenna_dish') {
        $svg .= "<text x=\"50\" y=\"22\" text-anchor=\"middle\" font-size=\"20\">📡</text>";
    } elseif ($hw === 'halo') {
        $svg .= "<text x=\"50\" y=\"20\" text-anchor=\"middle\" font-size=\"20\">✨</text>";
    } elseif ($hw === 'robot_ears') {
        $svg .= "<text x=\"50\" y=\"22\" text-anchor=\"middle\" font-size=\"20\">👂</text>";
    } elseif ($hw === 'spiky_hair') {
        $svg .= "<text x=\"50\" y=\"22\" text-anchor=\"middle\" font-size=\"20\">⚡</text>";
    } elseif ($hw === 'visor_tech') {
        $svg .= "<text x=\"50\" y=\"22\" text-anchor=\"middle\" font-size=\"20\">🔍</text>";
    }
    $svg .= "</svg>";
    return $svg;
}

function avatarHTML($customizacao, $size = 'mini', $classe_extra = '') {
    $tamanhos = [
        'micro' => 24,
        'mini' => 48,
        'medio' => 120,
        'full' => 256
    ];
    
    $px = $tamanhos[$size] ?? 48;
    $altura = $px * 1.4;
    
    $svg = renderizarAvatarSVG($customizacao, $px);
    
    return "<div class=\"avatar-container avatar-{$size} {$classe_extra}\" style=\"width: {$px}px; height: {$altura}px;\">{$svg}</div>";
}

// ===== FUNÇÕES DE LOOT BOXES =====

function abrirLootBox($pdo, $user_id, $tipo_caixa) {
    global $LOOT_BOXES, $AVATAR_COMPONENTES;
    
    // Criar diretório de logs se não existir
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
    
    $logFile = $logDir . '/loot_boxes.log';
    $timestamp = date('Y-m-d H:i:s');
    $logMsg = "[$timestamp] User: $user_id | Caixa: $tipo_caixa\n";
    @file_put_contents($logFile, $logMsg, FILE_APPEND | LOCK_EX);
    
    if (!isset($LOOT_BOXES[$tipo_caixa])) {
        $msg = "❌ Tipo de caixa inválido: $tipo_caixa";
        @file_put_contents($logFile, "  $msg\n", FILE_APPEND | LOCK_EX);
        return ['sucesso' => false, 'mensagem' => 'Tipo de caixa inválido'];
    }
    
    $caixa = $LOOT_BOXES[$tipo_caixa];
    
    try {
        // Verificar saldo
        $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $usuario = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$usuario) {
            @file_put_contents($logFile, "  ❌ Usuário não encontrado\n", FILE_APPEND | LOCK_EX);
            return ['sucesso' => false, 'mensagem' => 'Usuário não encontrado'];
        }
        
        @file_put_contents($logFile, "  Saldo: {$usuario['pontos']} pts | Custo: {$caixa['preco']} pts\n", FILE_APPEND | LOCK_EX);
        
        if ($usuario['pontos'] < $caixa['preco']) {
            $msg = "❌ Saldo insuficiente";
            @file_put_contents($logFile, "  $msg\n", FILE_APPEND | LOCK_EX);
            return ['sucesso' => false, 'mensagem' => 'Pontos insuficientes'];
        }
        
        // Gerar raridade e escolher item compatível
        $raridade = gerarRaridadeAleatoria($caixa);
        @file_put_contents($logFile, "  Raridade sorteada: $raridade\n", FILE_APPEND | LOCK_EX);
        
        $candidatos = [];
        foreach ($AVATAR_COMPONENTES as $catNome => $lista) {
            if ($catNome === 'aura') continue;
            foreach ($lista as $id => $item) {
                if (($item['rarity'] ?? '') === $raridade && ($item['preco'] ?? 0) > 0) {
                    $candidatos[] = ['categoria' => $catNome, 'id' => $id, 'item' => $item];
                }
            }
        }
        
        if (empty($candidatos)) {
            @file_put_contents($logFile, "  ❌ Nenhum item disponível na raridade\n", FILE_APPEND | LOCK_EX);
            return ['sucesso' => false, 'mensagem' => 'Nenhum item disponível'];
        }
        
        $picked = $candidatos[array_rand($candidatos)];
        $categoria_nome = $picked['categoria'];
        $item_id = $picked['id'];
        $item = $picked['item'];
        
        @file_put_contents($logFile, "  Item escolhido: $categoria_nome/$item_id ({$item['nome']})\n", FILE_APPEND | LOCK_EX);
        
        // Iniciar transação
        if (!$pdo->inTransaction()) $pdo->beginTransaction();

        // Descontar pontos
        $stmt = $pdo->prepare("UPDATE usuarios SET pontos = pontos - :preco WHERE id = :id AND pontos >= :preco");
        $stmt->execute([':preco' => $caixa['preco'], ':id' => $user_id]);
        
        if ($stmt->rowCount() === 0) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            @file_put_contents($logFile, "  ❌ Erro ao debitar pontos\n", FILE_APPEND | LOCK_EX);
            return ['sucesso' => false, 'mensagem' => 'Erro ao processar pontos'];
        }

        // Inserir item no inventário
        $stmt = $pdo->prepare(
            "INSERT INTO usuario_inventario (user_id, categoria, item_id, nome_item, raridade, data_obtencao) VALUES (:uid, :cat, :iid, :nome, :rar, NOW())"
        );

        try {
            $stmt->execute([
                ':uid' => $user_id,
                ':cat' => $categoria_nome,
                ':iid' => $item_id,
                ':nome' => $item['nome'],
                ':rar' => $raridade
            ]);
            @file_put_contents($logFile, "  Item inserido no inventário\n", FILE_APPEND | LOCK_EX);
            $item_duplicado = false;
        } catch (PDOException $e) {
            // Se der erro de duplicata (unique constraint), ganhar 10 moedas
            if (strpos($e->getMessage(), 'Duplicate') !== false || strpos($e->getMessage(), 'UNIQUE') !== false) {
                @file_put_contents($logFile, "  ⚠️ Item duplicado detectado! Adicionando 10 moedas bônus\n", FILE_APPEND | LOCK_EX);
                $item_duplicado = true;
                
                // Adicionar 10 moedas pelo item duplicado
                $stmtBonus = $pdo->prepare("UPDATE usuarios SET pontos = pontos + 10 WHERE id = :id");
                $stmtBonus->execute([':id' => $user_id]);
                @file_put_contents($logFile, "  ✨ +10 moedas adicionadas por item duplicado\n", FILE_APPEND | LOCK_EX);
            } else {
                if ($pdo->inTransaction()) $pdo->rollBack();
                $erro = $e->getMessage();
                @file_put_contents($logFile, "  ❌ Erro ao inserir item: $erro\n", FILE_APPEND | LOCK_EX);
                error_log("Erro ao inserir item: $erro");
                throw $e;
            }
        }

        if ($pdo->inTransaction()) $pdo->commit();
        @file_put_contents($logFile, "  Transação commitada\n", FILE_APPEND | LOCK_EX);

        // Buscar pontos atuais
        $stmt = $pdo->prepare("SELECT pontos FROM usuarios WHERE id = :id");
        $stmt->execute([':id' => $user_id]);
        $usuarioAtual = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $pontos_finais = $usuarioAtual ? (int)$usuarioAtual['pontos'] : 0;
        @file_put_contents($logFile, "  ✅ Sucesso! Pontos restantes: $pontos_finais\n", FILE_APPEND | LOCK_EX);

        return [
            'sucesso' => true,
            'mensagem' => $item_duplicado ? '🔄 Item duplicado! +10 moedas bônus!' : 'Item obtido!',
            'categoria' => $categoria_nome,
            'item_id' => $item_id,
            'item_nome' => $item['nome'],
            'raridade' => $raridade,
            'pontos_restantes' => $pontos_finais,
            'duplicado' => $item_duplicado ?? false
        ];
        
    } catch (PDOException $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro = $e->getMessage();
        @file_put_contents($logFile, "  ❌ PDOException: $erro\n", FILE_APPEND | LOCK_EX);
        error_log("abrirLootBox PDOException: $erro");
        return ['sucesso' => false, 'mensagem' => 'Erro ao abrir caixa'];
    } catch (Exception $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $erro = $e->getMessage();
        @file_put_contents($logFile, "  ❌ Exception: $erro\n", FILE_APPEND | LOCK_EX);
        error_log("abrirLootBox Exception: $erro");
        return ['sucesso' => false, 'mensagem' => 'Erro desconhecido'];
    }
}

function gerarRaridadeAleatoria($caixa) {
    $rand = mt_rand(1, 100);
    
    if ($rand <= $caixa['chance_comum']) {
        return 'common';
    } elseif ($rand <= $caixa['chance_comum'] + $caixa['chance_rara']) {
        return 'rare';
    } elseif ($rand <= $caixa['chance_comum'] + $caixa['chance_rara'] + $caixa['chance_epica']) {
        return 'epic';
    } elseif ($rand <= $caixa['chance_comum'] + $caixa['chance_rara'] + $caixa['chance_epica'] + $caixa['chance_lendaria']) {
        return 'legendary';
    } else {
        return 'mythic';
    }
}

function obterInventario($pdo, $user_id) {
    try {
        $stmt = $pdo->prepare("
            SELECT categoria, item_id, nome_item, raridade, data_obtencao
            FROM usuario_inventario
            WHERE user_id = :uid
            ORDER BY data_obtencao DESC
        ");
        $stmt->execute([':uid' => $user_id]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        return [];
    }
}

?>
