<?php
// LIGA O MOSTRADOR DE ERROS (Remova em produção)
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

// termo.php - O JOGO DIÁRIO DA FIRMA (DARK MODE 🧩🌙)
// session_start já foi chamado em games/index.php
require '../core/conexao.php';

// --- CONFIGURACOES ---
$modo = (($_GET['modo'] ?? 'normal') === 'dueto') ? 'dueto' : 'normal';
$BASE_PONTOS_VITORIA = 100;
$pointsMultiplier = getGamePointsMultiplier($pdo, 'termo');
$PONTOS_VITORIA = $BASE_PONTOS_VITORIA * $pointsMultiplier;
$PONTOS_VITORIA_DUETO = 200 * $pointsMultiplier;
$MAX_TENTATIVAS = 6;
$MAX_TENTATIVAS_DUETO = 7;

// Garantir colunas de sequência
try {
    $hasStreak = $pdo->query("SHOW COLUMNS FROM termo_historico LIKE 'streak_count'")->rowCount() > 0;
    if (!$hasStreak) {
        $pdo->exec("ALTER TABLE termo_historico ADD COLUMN streak_count INT DEFAULT 0 AFTER pontos_ganhos");
    }
} catch (Exception $e) {}

// Garantir tabela dueto
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS termo_dueto_historico (
        id INT AUTO_INCREMENT PRIMARY KEY,
        id_usuario INT NOT NULL,
        data_jogo DATE NOT NULL,
        ganhou_1 TINYINT(1) DEFAULT 0,
        ganhou_2 TINYINT(1) DEFAULT 0,
        tentativas INT DEFAULT 0,
        pontos_ganhos INT DEFAULT 0,
        streak_count INT DEFAULT 0,
        palavras_tentadas TEXT,
        UNIQUE KEY uk_dueto_user_date (id_usuario, data_jogo)
    )");
    // Garantir coluna streak em tabelas já existentes
    $hasDuetoStreak = $pdo->query("SHOW COLUMNS FROM termo_dueto_historico LIKE 'streak_count'")->rowCount() > 0;
    if (!$hasDuetoStreak) {
        $pdo->exec("ALTER TABLE termo_dueto_historico ADD COLUMN streak_count INT DEFAULT 0 AFTER pontos_ganhos");
    }
} catch (Exception $e) {}

// 1. Segurança
if (!isset($_SESSION['user_id'])) { header("Location: ../auth/login.php"); exit; }
$user_id = $_SESSION['user_id'];


// --- 2. DADOS DO USUÁRIO (PARA O HEADER) ---
try {
    $stmtMe = $pdo->prepare("SELECT nome, pontos, is_admin FROM usuarios WHERE id = :id");
    $stmtMe->execute([':id' => $user_id]);
    $meu_perfil = $stmtMe->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Erro perfil: " . $e->getMessage());
}

// --- FUNÇÕES AUXILIARES ---
function calcularCores(string $chute, string $correto): array {
    $resultado = array_fill(0, 5, '');
    $lc = str_split($correto);
    $lch = str_split($chute);
    $cnt = array_count_values($lc);
    for ($i = 0; $i < 5; $i++) {
        if ($lch[$i] == $lc[$i]) { $resultado[$i] = 'G'; $cnt[$lch[$i]]--; $lch[$i] = null; }
    }
    for ($i = 0; $i < 5; $i++) {
        if ($lch[$i] === null) continue;
        if (strpos($correto, $lch[$i]) !== false && ($cnt[$lch[$i]] ?? 0) > 0) {
            $resultado[$i] = 'Y'; $cnt[$lch[$i]]--;
        } else { $resultado[$i] = 'X'; }
    }
    return $resultado;
}

function removerAcentos($string) {
    $s = mb_strtoupper($string, 'UTF-8');
    $map = [
        'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A',
        'É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E',
        'Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
        'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O',
        'Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U',
        'Ç'=>'C'
    ];
    return strtr($s, $map);
}

// --- LÓGICA DO DIA ---
// Usa wordlist gerada pelo léxico pt-br se disponível
$_wl = __DIR__ . '/../core/wordlist5.php';
if (file_exists($_wl)) {
    require $_wl;
    $dicionario = $wordlist5 ?? [];
} else {
    // Fallback enquanto o wordlist não for gerado
$dicionario = [
    // A
    'ABRIR','ABUSO','ACABA','ACABO','ACASO','ACATA','ACATO','ACENA','ACENO','ACIMA',
    'ACODE','ACUSA','ADEUS','ADOTA','AFETO','AGITA','AGORA','AGUDO','AINDA','AJUDA',
    'ALADO','ALEGA','ALOJA','ALTAS','ALTOS','ALUNO','AMADO','AMARA','AMAVA','AMIGA',
    'AMIGO','AMORA','AMPLO','AMUAR','ANCAS','ANDAR','ANEIS','ANIME','ANTES','APELA',
    'APOIA','ARDOR','ARENA','ARMAS','ARTES','AROMA','ASSAR','ATADO','ATIRA','ATROZ',
    'AUTOR','AVARO','AVISO','AXILA','AZEDO',
    // B
    'BACIA','BAILE','BAIXA','BAIXO','BALAO','BALDO','BAMBA','BAMBU','BANDA','BANDO',
    'BANCO','BANHO','BARCO','BARRO','BATER','BEBEM','BEIJO','BELGA','BELAS','BELOS',
    'BENZE','BERCO','BESTA','BICHO','BIRRA','BIZCO','BOCAL','BOLSA','BRASA','BRAVO',
    'BREVE','BRIGA','BRUTA','BRUTO','BURLA','BURRO','BUSTO',
    // C
    'CABER','CABRA','CACOS','CAGAR','CAIXA','CALCA','CALDO','CALHA','CALMA','CALMO',
    'Canas','CANOA','CANTO','CAPAZ','CAPIM','CARAS','CARGO','CARNE','CARRO','CARTA',
    'CASAS','CASCO','CAUSA','CEDER','CEIFA','CELAS','CERCA','CERCO','CERTO','CERVO',
    'CHATA','CHATO','CHAVE','CHEGA','CHEIO','CHEFE','CHOCA','CHORA','CHUVA','CINCO',
    'CISMA','CLARO','CLUBE','COBRA','COCAR','COISA','COLHE','COMUM','CONTA','COPOS',
    'COPIA','COQUE','CORAL','CORDA','CORES','COROA','CORPO','CORTE','COSTA','COURO',
    'COXIA','CRAVO','CREDO','CREIO','CREME','CRIAR','CRIME','CRUEL','CRUZA','CUECA',
    'CULPA','CULTO','CUJAS','CUJOS','CURAR','CURTO','CURVA',
    // D
    'DAMAS','DANCA','DARES','DARIA','DEITO','DELAS','DELES','DENSO','DENTE','DESDE',
    'DIABO','DIETA','DIGAM','DIGNO','DISSE','DITAS','DITOS','DOCIL','DOCES','DOIDA',
    'DOIDO','DONAS','DONOS','DORSO','DUPLO','DUROS',
    // E
    'EIRAS','EMITE','ENOJA','ENTRE','EPOCA','ERRAR','ERROS','ESPIA','ESTAR','ETAPA',
    'ETICA','EVITA','EXAME','EXIBE','EXITO','EXPOE','EXTRA',
    // F
    'FACAO','FAINA','FALAR','FALSA','FALSO','FALTA','FARDA','FARDO','FARTA','FARTO',
    'FATAL','FATOR','FAVOR','FECHA','FEDOR','FEIAS','FEIOS','FEITO','FELIZ','FENDA',
    'FEREM','FERIR','FERVE','FEIXE','FIADA','FIADO','FICAR','FIGOS','FILHO','FILMA',
    'FILME','FIRMA','FIXAR','FLORA','FLUIR','FLUXO','FOCAR','FOLHA','FONTE','FORMA',
    'FORTE','FOTOS','FRACA','FRACO','FRADE','FRACO','FREIA','FREIO','FRETE','FRITO',
    'FRUTO','FUGAS','FUGIR','FUNDA','FUNDO','FUROS',
    // G
    'GAFES','GANHA','GARRA','GASES','GATOS','GELAR','GEMEM','GEMER','GENTE','GERAL',
    'GIROS','GLOSA','GOTAS','GRATO','GREVE','GRIPE','GRUPO','GUETO','GUIAR',
    // H
    'HABIL','HEROI','HINOS','HONRA','HORAS','HOTEL','HUMOR',
    // I
    'IDEAL','IDEIA','IGUAL','IMITA','IMUNE','INCHA','INIBE','INTRO','IRMAS','IRMOS',
    'ISOLA',
    // J
    'JANTA','JARRA','JESUS','JOGAR','JOVEM','JUIZO','JUNCO','JUNTO','JUNTA','JURAR',
    'JUROS','JUSTO',
    // L
    'LABOR','LADOS','LAIVO','LAMAS','LANCE','LAPSO','LARES','LARGO','LATIM','LAUDO',
    'LAVAR','LEBRE','LEIAS','LEITE','LEMOS','LENTO','LEOES','LESMA','LETRA','LEVAR',
    'LIDER','LIDAR','LIGAR','LICOR','LIMAO','LIMPO','LINCE','LINDA','LINDO','LINHA',
    'LISTA','LIVRE','LOCAL','LOBOS','LONGO','LOUVA','LOUCO','LUGAR','LUSCO','LUTAR',
    // M
    'MACHO','MAIOR','MALHA','MALTA','MAMAE','MAMAO','MANDA','MANGA','MANIA','MANSO',
    'MANTO','MARES','MARCA','MARRA','MASSA','MATAR','MEIOS','MEDIR','MENOR','MESMO',
    'METAL','MEXAM','MEXER','MEXIA','MIDIA','MINHA','MIRRA','MIUDO','MOEDA','MOLDA',
    'MOLDE','MOLHO','MONGE','MONTE','MORAL','MORDO','MORAR','MORTE','MOSTO','MOTIM',
    'MUITO','MUGIR','MULTA','MUNDO',
    // N
    'NADAR','NEGAM','NEGAR','NEGRO','NETOS','NIVEL','NOBRE','NOITE','NOIVA','NOIVO',
    'NOMES','NORMA','NOSSA','NOSSO','NOTAR','NOTAS','NUVEM',
    // O
    'OBRAS','OBTER','ODEIA','ODIAR','ODIOS','OFEGA','OLHAR','OLHOS','OMITA','OMITE',
    'ONDAS','OPINA','ORDEM','OTIMO','OUTRO','OVULO',
    // P
    'PADRE','PAIOL','PAPEL','PAPAI','PAPOS','PARCO','PARAR','PAUTA','PASSO','PAGAR',
    'BAIXA','PEDAL','PEDEM','PEDIR','PELOS','PENAS','PENSO','PEOES','PERDA','PERTO',
    'PESAR','PIANO','PIADA','PILAR','PILHA','PINTA','PINTO','PIORA','PIQUE','PISAR',
    'PISCA','PLACA','PLANO','PLENA','PLENO','POBRE','PODAR','PODES','PODIA','POEMA',
    'PODER','POLOS','POLVO','POMBA','POMBO','PONTO','PONTE','PORTA','POSSO','POTRO',
    'PRAGA','PRAIA','PRATA','PRAZO','PRECO','PRESA','PRESO','PRIMO','PROBO','PROLE',
    'PULSA','PULAR','PULSO',
    // Q
    'QUASE','QUERO','QUOTA',
    // R
    'RABOS','RAIOS','RAIVA','RAMPA','RAMOS','RAPAZ','RASGO','RAZAO','REAIS','RECAI',
    'RECUA','REDUZ','REINA','REINO','RENDA','REUNE','REZAR','RICOS','RIGOR','RITMO',
    'RODAM','RODAR','RODAS','ROLAM','ROLAR','ROLHA','ROSTO','ROUBO','ROUBA','RUMOR',
    'RUMOS','RUSSA','RUSSO',
    // S
    'SABER','SABIA','SABIO','SACAM','SACOA','SACOU','SACAR','SAGAZ','SAIRE','SALDO',
    'SALTA','SALVO','SAMBA','SANAR','SANTA','SANTO','SAQUE','SAUDE','SECAM','SECAR',
    'SEGUE','SENHA','SENSO','SERIO','SERVO','SETOR','SETAS','SIGLA','SINAL','SIRVO',
    'SOBEM','SOBRA','SOBRE','SOCIO','SOFRE','SOLAR','SOLTA','SOLTO','SOLOS','SONHO',
    'SORTE','SUBIR','SUCRE','SUECA','SUECO','SUPOE','SURGE','SURJA','SURTE','SUAVE',
    'SUMIR','SUTIL',
    // T
    'TALCO','TALHA','TAPAM','TAPAS','TARDE','TECAM','TECEM','TECER','TECLA','TECLE',
    'TELAO','TEMAS','TEMER','TEMPO','TENDE','TENDO','TENHO','TENTA','TENSO','TERRA',
    'TESTE','TEXTO','TIMAO','TIRAR','TIREM','TIRAS','TIREI','TOCAM','TOCAR','TOLAS',
    'TOLOS','TOMAR','TOMBE','TOPAM','TORCE','TORGA','TORNO','TOTAL','TRAEM','TRAGO',
    'TRAPO','TRAIR','TRATO','TREVO','TRIBO','TRIPA','TROCA','TROVA','TUCUM','TUMOR',
    'TUNAR','TURBO','TURMA',
    // U
    'ULTRA','UNIAO','UNICA','UNICO','URGEM','URGIR','USADA','USADO','USUAL','USURA',
    // V
    'VAGAM','VAGAR','VAGAS','VAGEM','VALER','VALOR','VARAL','VARIA','VARIE','VAPOR',
    'VASTO','VEDAS','VELAS','VELHO','VELOZ','VENAM','VENDE','VENDI','VENDO','VENHA',
    'VENHO','VERBO','VERDE','VIELA','VIGOR','VIOLA','VIRAR','VIRES','VIREI','VISAM',
    'VISAR','VISEI','VISAO','VISTA','VISTO','VIVAS','VIVOS','VOCAL','VOCES','VOLTA',
    'VOLTO','VOTAR','VOVOS','VORAZ','VULGO',
    // Z
    'ZANGA','ZANGE','ZINCO','ZONAS',
];
$dicionario = array_values(array_unique(array_map('strtoupper', $dicionario)));
} // fim do else (fallback)

$seed = floor(time() / 86400);
srand($seed);
$indice_do_dia = rand(0, count($dicionario) - 1);
$PALAVRA_DO_DIA = $dicionario[$indice_do_dia];

// Dueto: duas palavras exclusivas (diferentes entre si e da palavra do Normal)
srand($seed + 7919);
$indice_d1 = rand(0, count($dicionario) - 1);
while ($indice_d1 === $indice_do_dia) $indice_d1 = ($indice_d1 + 1) % count($dicionario);
$PALAVRA_D1 = $dicionario[$indice_d1];

srand($seed + 15973);
$indice_d2 = rand(0, count($dicionario) - 1);
while ($indice_d2 === $indice_do_dia || $indice_d2 === $indice_d1) $indice_d2 = ($indice_d2 + 1) % count($dicionario);
$PALAVRA_D2 = $dicionario[$indice_d2];

// --- VERIFICAÇÃO DE ESTADO ---
$hoje = date('Y-m-d');
try {
    $stmtStatus = $pdo->prepare("SELECT * FROM termo_historico WHERE id_usuario = :uid AND data_jogo = :dt");
    $stmtStatus->execute([':uid' => $user_id, ':dt' => $hoje]);
    $dados_jogo = $stmtStatus->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("<div class='alert alert-danger'>Erro Crítico: Tabela 'termo_historico' incompleta.</div>");
}

$chutes_realizados = [];
if ($dados_jogo && !empty($dados_jogo['palavras_tentadas'])) {
    $chutes_realizados = json_decode($dados_jogo['palavras_tentadas'], true) ?? [];
}

$streak_atual = 0;
try {
    $stmtStreak = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = :uid ORDER BY data_jogo DESC LIMIT 1");
    $stmtStreak->execute([':uid' => $user_id]);
    $rowStreak = $stmtStreak->fetch(PDO::FETCH_ASSOC);
    if ($rowStreak) {
        $streak_atual = (int)($rowStreak['streak_count'] ?? 0);
    }
} catch (PDOException $e) {
    $streak_atual = 0;
}

$update_streak = function () use ($pdo, $user_id, $hoje, &$streak_atual) {
    $stmtToday = $pdo->prepare("SELECT streak_count FROM termo_historico WHERE id_usuario = :uid AND data_jogo = :hoje LIMIT 1");
    $stmtToday->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $todayRow = $stmtToday->fetch(PDO::FETCH_ASSOC);
    if ($todayRow && (int)($todayRow['streak_count'] ?? 0) > 0) {
        $streak_atual = (int)$todayRow['streak_count'];
        return;
    }

    $stmtPrev = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_historico WHERE id_usuario = :uid AND data_jogo < :hoje ORDER BY data_jogo DESC LIMIT 1");
    $stmtPrev->execute([':uid' => $user_id, ':hoje' => $hoje]);
    $prev = $stmtPrev->fetch(PDO::FETCH_ASSOC);
    $yesterday = date('Y-m-d', strtotime($hoje . ' -1 day'));
    $nova_streak = ($prev && $prev['data_jogo'] === $yesterday) ? ((int)$prev['streak_count'] + 1) : 1;
    $pdo->prepare("UPDATE termo_historico SET streak_count = :streak WHERE id_usuario = :uid AND data_jogo = :hoje")
        ->execute([':streak' => $nova_streak, ':uid' => $user_id, ':hoje' => $hoje]);
    $streak_atual = $nova_streak;
};

$jogo_finalizado = false;
$venceu_hoje = false;

if ($dados_jogo) {
    if ($dados_jogo['ganhou'] == 1) {
        $jogo_finalizado = true;
        $venceu_hoje = true;
    } elseif (count($chutes_realizados) >= $MAX_TENTATIVAS) {
        $jogo_finalizado = true;
        $venceu_hoje = false;
    }
}

// --- DUETO: estado ---
$dados_dueto = null;
$chutes_dueto = [];
$ganhou_1 = $ganhou_2 = false;
$dueto_finalizado = false;
$streak_dueto = 0;
try {
    $stmtD = $pdo->prepare("SELECT * FROM termo_dueto_historico WHERE id_usuario = :uid AND data_jogo = :dt");
    $stmtD->execute([':uid' => $user_id, ':dt' => $hoje]);
    $dados_dueto = $stmtD->fetch(PDO::FETCH_ASSOC);
} catch (Exception $e) {}
if ($dados_dueto) {
    $chutes_dueto = json_decode($dados_dueto['palavras_tentadas'], true) ?? [];
    $ganhou_1 = (bool)$dados_dueto['ganhou_1'];
    $ganhou_2 = (bool)$dados_dueto['ganhou_2'];
}
$dueto_finalizado = ($ganhou_1 && $ganhou_2) || count($chutes_dueto) >= $MAX_TENTATIVAS_DUETO;
try {
    $stmtSD = $pdo->prepare("SELECT streak_count FROM termo_dueto_historico WHERE id_usuario = :uid ORDER BY data_jogo DESC LIMIT 1");
    $stmtSD->execute([':uid' => $user_id]);
    $rowSD = $stmtSD->fetch(PDO::FETCH_ASSOC);
    if ($rowSD) $streak_dueto = (int)($rowSD['streak_count'] ?? 0);
} catch (Exception $e) {}

// --- API DE VALIDAÇÃO ---
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['chute'])) {
    header('Content-Type: application/json');

    $apenas_validar = isset($_POST['validar_somente']);
    $chute_cru = $_POST['chute'];
    $chute     = removerAcentos($chute_cru);

    // ── DUETO ──────────────────────────────────────────────────────────────
    if ($modo === 'dueto') {
        if ($dueto_finalizado && !$apenas_validar) {
            echo json_encode(['erro' => 'Jogo finalizado para hoje.']); exit;
        }
        if (strlen($chute) != 5) {
            echo json_encode(['erro' => 'A palavra deve ter 5 letras.']); exit;
        }
        if (!$apenas_validar && !in_array($chute, $dicionario)) {
            echo json_encode(['erro' => 'Palavra não encontrada no dicionário.']); exit;
        }

        $c1 = removerAcentos($PALAVRA_D1);
        $c2 = removerAcentos($PALAVRA_D2);
        $cores_1 = calcularCores($chute, $c1);
        $cores_2 = calcularCores($chute, $c2);

        if ($apenas_validar) {
            echo json_encode(['cores_1'=>$cores_1,'cores_2'=>$cores_2,'fim_jogo'=>false,'pontos'=>0]); exit;
        }

        $now_g1 = $ganhou_1 || ($chute === $c1);
        $now_g2 = $ganhou_2 || ($chute === $c2);
        $chutes_dueto[] = $chute;
        $num = count($chutes_dueto);
        $fim = ($now_g1 && $now_g2) || $num >= $MAX_TENTATIVAS_DUETO;
        $pts = 0;

        try {
            $pdo->beginTransaction();
            if (!$dados_dueto) {
                $pdo->prepare("INSERT INTO termo_dueto_historico (id_usuario,data_jogo,ganhou_1,ganhou_2,tentativas,palavras_tentadas) VALUES (:u,:d,:g1,:g2,:t,:j)")
                    ->execute([':u'=>$user_id,':d'=>$hoje,':g1'=>(int)$now_g1,':g2'=>(int)$now_g2,':t'=>$num,':j'=>json_encode($chutes_dueto)]);
            } else {
                $pdo->prepare("UPDATE termo_dueto_historico SET ganhou_1=:g1,ganhou_2=:g2,tentativas=:t,palavras_tentadas=:j WHERE id=:id")
                    ->execute([':g1'=>(int)$now_g1,':g2'=>(int)$now_g2,':t'=>$num,':j'=>json_encode($chutes_dueto),':id'=>$dados_dueto['id']]);
            }
            if ($fim && $now_g1 && $now_g2) {
                $pts = $PONTOS_VITORIA_DUETO;
                $pdo->prepare("UPDATE termo_dueto_historico SET pontos_ganhos=:p WHERE id_usuario=:u AND data_jogo=:d")
                    ->execute([':p'=>$pts,':u'=>$user_id,':d'=>$hoje]);
                $pdo->prepare("UPDATE usuarios SET pontos=pontos+:p WHERE id=:u")
                    ->execute([':p'=>$pts,':u'=>$user_id]);
                // Streak dueto
                $stmtPD = $pdo->prepare("SELECT data_jogo, streak_count FROM termo_dueto_historico WHERE id_usuario=:u AND data_jogo < :d ORDER BY data_jogo DESC LIMIT 1");
                $stmtPD->execute([':u'=>$user_id,':d'=>$hoje]);
                $prevD = $stmtPD->fetch(PDO::FETCH_ASSOC);
                $yest  = date('Y-m-d', strtotime($hoje . ' -1 day'));
                $novaSD = ($prevD && $prevD['data_jogo'] === $yest) ? ((int)$prevD['streak_count'] + 1) : 1;
                $pdo->prepare("UPDATE termo_dueto_historico SET streak_count=:s WHERE id_usuario=:u AND data_jogo=:d")
                    ->execute([':s'=>$novaSD,':u'=>$user_id,':d'=>$hoje]);
            }
            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }

        echo json_encode([
            'cores_1'   => $cores_1,
            'cores_2'   => $cores_2,
            'ganhou_1'  => $now_g1,
            'ganhou_2'  => $now_g2,
            'fim_jogo'  => $fim,
            'pontos'    => $pts,
            'palavra_1' => $fim ? $PALAVRA_D1 : null,
            'palavra_2' => $fim ? $PALAVRA_D2 : null,
        ]);
        exit;
    }
    // ── FIM DUETO ──────────────────────────────────────────────────────────

    if ($jogo_finalizado && !$apenas_validar) {
        echo json_encode(['erro' => 'Jogo finalizado para hoje.']);
        exit;
    }

    $correto = removerAcentos($PALAVRA_DO_DIA);
    
    if (strlen($chute) != 5) {
        echo json_encode(['erro' => 'A palavra deve ter 5 letras.']);
        exit;
    }

    if (!$apenas_validar && !in_array($chute, $dicionario)) {
        echo json_encode(['erro' => 'Palavra não encontrada no dicionário.']);
        exit;
    }

    // Lógica de Cores
    $resultado = array_fill(0, 5, '');
    $letras_correto = str_split($correto);
    $letras_chute = str_split($chute);
    $contagem = array_count_values($letras_correto);
    
    for ($i = 0; $i < 5; $i++) {
        if ($letras_chute[$i] == $letras_correto[$i]) {
            $resultado[$i] = 'G';
            $contagem[$letras_chute[$i]]--; 
            $letras_chute[$i] = null; 
        }
    }
    for ($i = 0; $i < 5; $i++) {
        if ($letras_chute[$i] === null) continue; 
        if (strpos($correto, $letras_chute[$i]) !== false && ($contagem[$letras_chute[$i]] ?? 0) > 0) {
            $resultado[$i] = 'Y';
            $contagem[$letras_chute[$i]]--;
        } else {
            $resultado[$i] = 'X';
        }
    }

    if ($apenas_validar) {
        echo json_encode([
            'cores' => $resultado,
            'ganhou' => ($chute === $correto),
            'fim_jogo' => false,
            'pontos' => 0
        ]);
        exit;
    }

    // --- SALVAMENTO NO BANCO ---
    $ganhou_rodada = ($chute === $correto);
    $chutes_realizados[] = $chute;
    $json_chutes = json_encode($chutes_realizados);
    $num_tentativas = count($chutes_realizados);

    if (!$dados_jogo) {
        $stmt = $pdo->prepare("INSERT INTO termo_historico (id_usuario, data_jogo, ganhou, tentativas, pontos_ganhos, palavras_tentadas) VALUES (:uid, :dt, 0, :t, 0, :json)");
        $stmt->execute([':uid' => $user_id, ':dt' => $hoje, ':t' => $num_tentativas, ':json' => $json_chutes]);
    } else {
        $stmt = $pdo->prepare("UPDATE termo_historico SET tentativas = :t, palavras_tentadas = :json WHERE id = :id");
        $stmt->execute([':t' => $num_tentativas, ':json' => $json_chutes, ':id' => $dados_jogo['id']]);
    }

    if ($ganhou_rodada) {
        try {
            $pdo->beginTransaction();

            $pdo->prepare("UPDATE termo_historico SET ganhou = 1, pontos_ganhos = :pts WHERE id_usuario = :uid AND data_jogo = :dt")
                ->execute([':pts' => $PONTOS_VITORIA, ':uid' => $user_id, ':dt' => $hoje]);

            $pdo->prepare("UPDATE usuarios SET pontos = pontos + :pts WHERE id = :uid")
                ->execute([':pts' => $PONTOS_VITORIA, ':uid' => $user_id]);

            // Atualizar sequência quando finaliza o dia
            $update_streak();

            $pdo->commit();
        } catch (Exception $e) { $pdo->rollBack(); }
    }

    $acabou = ($ganhou_rodada || $num_tentativas >= $MAX_TENTATIVAS);

    if ($acabou && !$ganhou_rodada) {
        try {
            $update_streak();
        } catch (Exception $e) {
        }
    }

    echo json_encode([
        'cores' => $resultado,
        'ganhou' => $ganhou_rodada,
        'fim_jogo' => $acabou,
        'pontos' => $ganhou_rodada ? $PONTOS_VITORIA : 0,
        'palavra_correta' => $acabou ? $PALAVRA_DO_DIA : null
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1,maximum-scale=1,user-scalable=no">
<title>Termo · FBA</title>
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;600;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --bg:#07070a;--panel:#101013;--panel2:#16161a;--panel3:#1c1c21;
  --border:rgba(255,255,255,.07);--border2:rgba(255,255,255,.14);
  --red:#fc0025;--red-soft:rgba(252,0,37,.12);--red-glow:rgba(252,0,37,.25);
  --text:#f0f0f3;--text2:#868690;--text3:#3c3c44;
  --amber:#f59e0b;--font:'Poppins',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0}
body{font-family:var(--font);background:var(--bg);color:var(--text);min-height:100vh;-webkit-font-smoothing:antialiased;overflow-x:hidden}

.topbar{display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 14px;background:var(--panel);border-bottom:1px solid var(--border);position:sticky;top:0;z-index:50}
.topbar-left{display:flex;align-items:center;gap:10px}
.back-btn{display:flex;align-items:center;justify-content:center;width:32px;height:32px;border-radius:9px;border:1px solid var(--border);background:transparent;color:var(--text2);text-decoration:none;font-size:14px;transition:.2s;flex-shrink:0}
.back-btn:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}
.game-title{font-size:15px;font-weight:800;color:var(--text)}
.game-title span{color:var(--red)}
.daily-badge{display:inline-flex;align-items:center;gap:4px;font-size:8px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;padding:2px 8px;border-radius:999px;background:var(--red-soft);border:1px solid var(--red-glow);color:var(--red);margin-left:6px}
.topbar-right{display:flex;align-items:center;gap:6px}
.chip{display:flex;align-items:center;gap:4px;padding:4px 10px;border-radius:20px;background:var(--panel2);border:1px solid var(--border);font-size:11px;font-weight:700;color:var(--text);white-space:nowrap}
.chip.fire{border-color:rgba(245,158,11,.3)!important;color:var(--amber)!important}

.main{max-width:520px;margin:0 auto;padding:16px 12px 60px}
.main.dueto-main{max-width:860px}

/* Abas de modo */
.mode-tabs{display:flex;gap:5px;margin-left:6px}
.mode-tab{padding:3px 11px;border-radius:20px;border:1px solid var(--border);background:transparent;color:var(--text2);font-family:var(--font);font-size:10px;font-weight:700;text-decoration:none;transition:.2s;letter-spacing:.4px}
.mode-tab.active{background:var(--red-soft);border-color:var(--red-glow);color:var(--red)}

/* Dueto layout */
.dueto-wrap{display:flex;gap:14px;justify-content:center;align-items:flex-start}
.dueto-col{flex:1;max-width:300px;display:flex;flex-direction:column;align-items:center}
.dueto-label{font-size:10px;font-weight:700;letter-spacing:.8px;text-transform:uppercase;color:var(--text2);margin-bottom:6px;display:flex;align-items:center;gap:5px}
.dueto-label.solved{color:#4ade80}
.dueto-board{display:grid;grid-template-rows:repeat(7,1fr);gap:4px;width:100%}
.dueto-board .row-termo{gap:4px}
.dueto-board .tile{font-size:clamp(.85rem,3.5vw,1.2rem)}
@media(max-width:540px){.dueto-wrap{gap:6px}.dueto-board .tile{font-size:clamp(.7rem,3vw,1rem)}}

.msg-area{padding:10px 14px;border-radius:10px;font-size:13px;font-weight:600;text-align:center;margin-bottom:10px;transition:.2s}
.msg-area.hidden{display:none}
.msg-area.warning{background:rgba(245,158,11,.12);border:1px solid rgba(245,158,11,.3);color:var(--amber)}
.msg-area.success{background:rgba(34,197,94,.12);border:1px solid rgba(34,197,94,.3);color:#4ade80}
.msg-area.danger{background:var(--red-soft);border:1px solid var(--red-glow);color:#f87171}

.board{display:grid;grid-template-rows:repeat(6,1fr);gap:5px;width:min(310px,90vw);margin:20px auto}
.row-termo{display:grid;grid-template-columns:repeat(5,1fr);gap:5px}
.tile{width:100%;aspect-ratio:1;border:2px solid var(--border2);background:var(--panel2);display:flex;justify-content:center;align-items:center;font-size:clamp(1.3rem,6vw,1.8rem);font-weight:800;color:var(--text);font-family:var(--font);border-radius:4px;user-select:none}
.tile.active{border-color:rgba(255,255,255,.3)}
.tile.correct{background:#538d4e;border-color:#538d4e;color:#fff}
.tile.present{background:#b59f3b;border-color:#b59f3b;color:#fff}
.tile.absent{background:#3a3a3c;border-color:#3a3a3c;color:#fff}
.tile.selected{border-color:var(--amber);box-shadow:0 0 0 2px rgba(245,158,11,.25);cursor:text}

.keyboard{width:100%;max-width:500px;margin:12px auto 0;display:flex;flex-direction:column;gap:6px;padding:0 8px}
.key-row{display:flex;justify-content:center;gap:5px}
.key{background:var(--panel2);border:1px solid var(--border2);color:var(--text);border-radius:6px;height:50px;min-width:0;flex:1;font-family:var(--font);font-weight:700;font-size:13px;cursor:pointer;transition:background .15s}
.key:active{opacity:.7}
.key-enter,.key-back{flex:1.5;font-size:10px}
.key.correct{background:#538d4e!important;border-color:#538d4e!important;color:#fff!important}
.key.present{background:#b59f3b!important;border-color:#b59f3b!important;color:#fff!important}
.key.absent{background:#3a3a3c!important;border-color:#3a3a3c!important;color:#fff!important}

.result-card{background:var(--panel);border:1px solid var(--border2);border-radius:18px;padding:28px 20px;text-align:center;margin:16px 0}
.result-icon{font-size:3.2rem;display:block;margin-bottom:10px}
.result-title{font-size:20px;font-weight:800;margin-bottom:6px}
.result-sub{font-size:13px;color:var(--text2);margin-bottom:16px;line-height:1.5}
.result-grid{display:flex;flex-direction:column;gap:4px;align-items:center;margin-bottom:20px}
.result-row{display:flex;gap:4px}
.result-sq{width:26px;height:26px;border-radius:4px}
.btn-back{display:inline-flex;align-items:center;justify-content:center;gap:8px;padding:11px 24px;border-radius:12px;background:var(--panel2);border:1px solid var(--border2);color:var(--text2);font-family:var(--font);font-size:13px;font-weight:700;text-decoration:none;transition:all .2s;width:100%}
.btn-back:hover{border-color:var(--red);color:var(--red);background:var(--red-soft)}

::-webkit-scrollbar{width:4px}::-webkit-scrollbar-track{background:transparent}::-webkit-scrollbar-thumb{background:var(--border2);border-radius:2px}
</style>
</head>
<body>

<div class="topbar">
  <div class="topbar-left">
    <a href="../games.php" class="back-btn"><i class="bi bi-arrow-left"></i></a>
    <span class="game-title">📝 <span>Termo</span><span class="daily-badge"><i class="bi bi-calendar3"></i>Diário</span></span>
    <div class="mode-tabs">
      <a href="index.php?game=termo" class="mode-tab <?= $modo === 'normal' ? 'active' : '' ?>">Normal</a>
      <a href="index.php?game=termo&modo=dueto" class="mode-tab <?= $modo === 'dueto' ? 'active' : '' ?>">Dueto</a>
    </div>
  </div>
  <div class="topbar-right">
    <?php $streak_show = $modo === 'dueto' ? $streak_dueto : $streak_atual; ?>
    <?php if ($streak_show > 0): ?>
    <div class="chip fire"><i class="bi bi-fire"></i><?= $streak_show ?></div>
    <?php endif; ?>
    <div class="chip"><i class="bi bi-coin" style="color:var(--amber)"></i><?= number_format($meu_perfil['pontos'],0,',','.') ?></div>
  </div>
</div>

<div class="main <?= $modo === 'dueto' ? 'dueto-main' : '' ?>">
  <div id="msg-area" class="msg-area hidden"></div>

<?php if ($modo === 'normal'): ?>
  <?php if($jogo_finalizado): ?>
  <div class="result-card" style="border-color:<?= $venceu_hoje ? 'rgba(83,141,78,.4)' : 'rgba(255,255,255,.1)' ?>">
    <span class="result-icon"><?= $venceu_hoje ? '🎉' : '😔' ?></span>
    <div class="result-title" style="color:<?= $venceu_hoje ? '#4ade80' : 'var(--text2)' ?>"><?= $venceu_hoje ? 'Acertou!' : 'Não foi dessa vez' ?></div>
    <div class="result-sub">
      <?php if($venceu_hoje): ?>
        Acertou em <strong style="color:var(--text)"><?= count($chutes_realizados) ?></strong> tentativa<?= count($chutes_realizados) !== 1 ? 's' : '' ?> · <strong style="color:#4ade80">+<?= $PONTOS_VITORIA ?> moedas</strong>
      <?php else: ?>
        A palavra era: <strong style="color:var(--text);font-size:15px"><?= $PALAVRA_DO_DIA ?></strong>
      <?php endif; ?>
    </div>
    <div class="result-grid">
      <?php foreach($chutes_realizados as $chute):
        $lc=str_split(removerAcentos($PALAVRA_DO_DIA));$lch=str_split($chute);
        $cnt=array_count_values($lc);$res=array_fill(0,5,'absent');
        for($i=0;$i<5;$i++){if($lch[$i]==$lc[$i]){$res[$i]='correct';$cnt[$lch[$i]]--;$lch[$i]=null;}}
        for($i=0;$i<5;$i++){if($lch[$i]!==null&&strpos(removerAcentos($PALAVRA_DO_DIA),$lch[$i])!==false&&($cnt[$lch[$i]]??0)>0){$res[$i]='present';$cnt[$lch[$i]]--;}}
      ?><div class="result-row"><?php foreach($res as $r):$c=($r=='correct')?'#538d4e':(($r=='present')?'#b59f3b':'#3a3a3c');?><div class="result-sq" style="background:<?=$c?>"></div><?php endforeach;?></div><?php endforeach;?>
    </div>
    <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i>Voltar aos Jogos</a>
  </div>
  <?php else: ?>
  <div class="board" id="board">
    <?php for($r=0;$r<6;$r++): ?>
    <div class="row-termo" id="row-<?=$r?>">
      <?php for($c=0;$c<5;$c++): ?><div class="tile" id="tile-<?=$r?>-<?=$c?>"></div><?php endfor; ?>
    </div>
    <?php endfor; ?>
  </div>
  <div class="keyboard">
    <div class="key-row"><button class="key">Q</button><button class="key">W</button><button class="key">E</button><button class="key">R</button><button class="key">T</button><button class="key">Y</button><button class="key">U</button><button class="key">I</button><button class="key">O</button><button class="key">P</button></div>
    <div class="key-row"><button class="key">A</button><button class="key">S</button><button class="key">D</button><button class="key">F</button><button class="key">G</button><button class="key">H</button><button class="key">J</button><button class="key">K</button><button class="key">L</button><button class="key">Ç</button></div>
    <div class="key-row"><button class="key key-enter" id="enter-btn">ENTER</button><button class="key">Z</button><button class="key">X</button><button class="key">C</button><button class="key">V</button><button class="key">B</button><button class="key">N</button><button class="key">M</button><button class="key key-back" id="back-btn">⌫</button></div>
  </div>
  <?php endif; ?>

<?php else: /* DUETO */ ?>
  <?php if($dueto_finalizado): ?>
  <?php $venceu_dueto = $ganhou_1 && $ganhou_2; ?>
  <div class="result-card" style="border-color:<?= $venceu_dueto ? 'rgba(83,141,78,.4)' : 'rgba(255,255,255,.1)' ?>;max-width:500px;margin:0 auto">
    <span class="result-icon"><?= $venceu_dueto ? '🎉' : '😔' ?></span>
    <div class="result-title" style="color:<?= $venceu_dueto ? '#4ade80' : 'var(--text2)' ?>">
      <?= $venceu_dueto ? 'Acertou as duas!' : ($ganhou_1 || $ganhou_2 ? 'Acertou uma!' : 'Não foi dessa vez') ?>
    </div>
    <div class="result-sub">
      <?php if($venceu_dueto): ?>
        <strong style="color:#4ade80">+<?= $dados_dueto['pontos_ganhos'] ?? $PONTOS_VITORIA_DUETO ?> moedas</strong> em <?= count($chutes_dueto) ?> tentativas
      <?php else: ?>
        Palavra 1: <strong style="color:var(--text)"><?= $PALAVRA_D1 ?></strong> <?= $ganhou_1 ? '✅' : '❌' ?><br>
        Palavra 2: <strong style="color:var(--text)"><?= $PALAVRA_D2 ?></strong> <?= $ganhou_2 ? '✅' : '❌' ?>
      <?php endif; ?>
    </div>
    <a href="../games.php" class="btn-back"><i class="bi bi-arrow-left"></i>Voltar aos Jogos</a>
  </div>
  <?php else: ?>
  <div class="dueto-wrap">
    <?php foreach ([[$PALAVRA_D1, $ganhou_1, 0], [$PALAVRA_D2, $ganhou_2, 1]] as [$pw, $gw, $bi]): ?>
    <div class="dueto-col">
      <div class="dueto-label <?= $gw ? 'solved' : '' ?>" id="dlabel-<?= $bi ?>">
        <?= $gw ? '✅ ' : '' ?>Palavra <?= $bi + 1 ?>
      </div>
      <div class="dueto-board">
        <?php for($r=0;$r<7;$r++): ?>
        <div class="row-termo" id="drow-<?=$bi?>-<?=$r?>">
          <?php for($c=0;$c<5;$c++): ?><div class="tile" id="tile-<?=$bi?>-<?=$r?>-<?=$c?>"></div><?php endfor; ?>
        </div>
        <?php endfor; ?>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <div class="keyboard" style="margin-top:16px">
    <div class="key-row"><button class="key">Q</button><button class="key">W</button><button class="key">E</button><button class="key">R</button><button class="key">T</button><button class="key">Y</button><button class="key">U</button><button class="key">I</button><button class="key">O</button><button class="key">P</button></div>
    <div class="key-row"><button class="key">A</button><button class="key">S</button><button class="key">D</button><button class="key">F</button><button class="key">G</button><button class="key">H</button><button class="key">J</button><button class="key">K</button><button class="key">L</button><button class="key">Ç</button></div>
    <div class="key-row"><button class="key key-enter" id="enter-btn">ENTER</button><button class="key">Z</button><button class="key">X</button><button class="key">C</button><button class="key">V</button><button class="key">B</button><button class="key">N</button><button class="key">M</button><button class="key key-back" id="back-btn">⌫</button></div>
  </div>
  <?php endif; ?>
<?php endif; ?>
</div>

<?php if(!$jogo_finalizado): ?>
<script>
(function(){
    const historicoChutes = <?= json_encode($chutes_realizados) ?>;
    let currentRow = historicoChutes.length;
    let selectedTile = 0;
    const maxRows = 6, maxTiles = 5;
    let gameOver = false;
    let guess = ['', '', '', '', ''];

    function updateTileSelection() {
        for (let i = 0; i < maxTiles; i++) {
            const t = document.getElementById(`tile-${currentRow}-${i}`);
            if (t) t.classList.toggle('selected', i === selectedTile);
        }
    }

    function bindRowClicks() {
        for (let i = 0; i < maxTiles; i++) {
            const t = document.getElementById(`tile-${currentRow}-${i}`);
            if (t) t.onclick = () => { selectedTile = i; updateTileSelection(); };
        }
    }

    if (historicoChutes.length < maxRows) {
        updateTileSelection();
        bindRowClicks();
    }

    function restoreState() {
        historicoChutes.forEach((palavra, index) => {
            fetch('index.php?game=termo', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: `chute=${palavra}&tentativa=${index + 1}&validar_somente=1`
            })
            .then(res => res.json())
            .then(data => {
                for (let i = 0; i < 5; i++) {
                    const tile = document.getElementById(`tile-${index}-${i}`);
                    tile.innerText = palavra[i];
                    tile.classList.remove('active', 'selected');
                    if (data.cores[i] === 'G') tile.classList.add('correct');
                    else if (data.cores[i] === 'Y') tile.classList.add('present');
                    else tile.classList.add('absent');
                }
                updateKeyboard(palavra, data.cores);
            });
        });
    }
    if (historicoChutes.length > 0) restoreState();

    document.addEventListener('keydown', e => {
        if (gameOver) return;
        const key = e.key.toUpperCase();
        if (key === 'ENTER') submitGuess();
        else if (key === 'BACKSPACE') deleteLetter();
        else if (key.length === 1 && /^[A-ZÇ]$/.test(key)) addLetter(key);
    });

    document.querySelectorAll('.key').forEach(btn => {
        btn.addEventListener('click', () => {
            if (gameOver) return;
            if (btn.id === 'enter-btn') { submitGuess(); return; }
            if (btn.id === 'back-btn') { deleteLetter(); return; }
            addLetter(btn.innerText);
        });
    });

    function addLetter(letter) {
        const tile = document.getElementById(`tile-${currentRow}-${selectedTile}`);
        if (!tile) return;
        tile.innerText = letter;
        tile.classList.add('active');
        guess[selectedTile] = letter;
        if (selectedTile < maxTiles - 1) selectedTile++;
        updateTileSelection();
    }

    function deleteLetter() {
        if (guess[selectedTile]) {
            const tile = document.getElementById(`tile-${currentRow}-${selectedTile}`);
            tile.innerText = '';
            tile.classList.remove('active');
            guess[selectedTile] = '';
        } else if (selectedTile > 0) {
            selectedTile--;
            const tile = document.getElementById(`tile-${currentRow}-${selectedTile}`);
            tile.innerText = '';
            tile.classList.remove('active');
            guess[selectedTile] = '';
        }
        updateTileSelection();
    }

    function submitGuess() {
        if (guess.some(l => !l)) { showMessage('Preencha todas as letras!'); return; }
        const guessStr = guess.join('');
        fetch('index.php?game=termo', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: `chute=${guessStr}&tentativa=${currentRow + 1}`
        })
        .then(res => res.json())
        .then(data => {
            if (data.erro) { showMessage(data.erro); return; }
            updateBoard(data.cores);
            updateKeyboard(guessStr, data.cores);
            if (data.fim_jogo) {
                gameOver = true;
                for (let i = 0; i < maxTiles; i++) {
                    const t = document.getElementById(`tile-${currentRow}-${i}`);
                    if (t) { t.onclick = null; t.classList.remove('selected'); }
                }
                const msg = data.ganhou ? `Parabéns! +${data.pontos} moedas 🎉` : `A palavra era: ${data.palavra_correta}`;
                showMessage(msg, data.ganhou ? 'success' : 'danger');
                setTimeout(() => location.reload(), 3200);
            } else {
                currentRow++;
                selectedTile = 0;
                guess = ['', '', '', '', ''];
                updateTileSelection();
                bindRowClicks();
            }
        });
    }

    function updateBoard(cores) {
        for (let i = 0; i < 5; i++) {
            const tile = document.getElementById(`tile-${currentRow}-${i}`);
            tile.classList.remove('active', 'selected');
            setTimeout(() => {
                if (cores[i] === 'G') tile.classList.add('correct');
                else if (cores[i] === 'Y') tile.classList.add('present');
                else tile.classList.add('absent');
            }, i * 150);
        }
    }

    function updateKeyboard(chuteAtual, cores) {
        setTimeout(() => {
            for (let i = 0; i < 5; i++) {
                const letra = chuteAtual[i], cor = cores[i];
                const keyBtn = Array.from(document.querySelectorAll('.key')).find(k => k.innerText === letra);
                if (keyBtn) {
                    if (cor === 'G') { keyBtn.classList.add('correct'); keyBtn.classList.remove('present'); }
                    else if (cor === 'Y' && !keyBtn.classList.contains('correct')) keyBtn.classList.add('present');
                    else if (cor === 'X' && !keyBtn.classList.contains('correct') && !keyBtn.classList.contains('present')) keyBtn.classList.add('absent');
                }
            }
        }, 500);
    }

    function showMessage(msg, type = 'warning') {
        const area = document.getElementById('msg-area');
        area.className = `msg-area ${type}`;
        area.innerText = msg;
        setTimeout(() => area.classList.add('hidden'), 3500);
    }
})();
</script>
<?php endif; ?>

<?php if ($modo === 'dueto' && !$dueto_finalizado): ?>
<script>
(function(){
    const CHUTES_PREV  = <?= json_encode($chutes_dueto) ?>;
    const GANHOU_INIT  = [<?= json_encode($ganhou_1) ?>, <?= json_encode($ganhou_2) ?>];
    const MAX_ROWS     = 7;
    const maxTiles     = 5;
    let currentRow     = CHUTES_PREV.length;
    let selectedTile   = 0;
    let gameOver       = false;
    let guess          = ['','','','',''];
    let ganhouStatus   = [...GANHOU_INIT];
    const keyPrio      = {G:3, Y:2, X:1};
    const keyState     = {};

    function tileEl(b, r, c) { return document.getElementById(`tile-${b}-${r}-${c}`); }

    function updateTileSelection() {
        for (let i = 0; i < maxTiles; i++) {
            for (let b = 0; b < 2; b++) {
                const t = tileEl(b, currentRow, i);
                if (t) t.classList.toggle('selected', i === selectedTile && !ganhouStatus[b]);
            }
        }
    }

    function bindRowClicks() {
        for (let i = 0; i < maxTiles; i++) {
            for (let b = 0; b < 2; b++) {
                const t = tileEl(b, currentRow, i);
                if (t) t.onclick = () => { selectedTile = i; updateTileSelection(); };
            }
        }
    }

    if (currentRow < MAX_ROWS) { updateTileSelection(); bindRowClicks(); }

    function applyCoresToBoard(b, rowIdx, palavra, cores) {
        for (let i = 0; i < maxTiles; i++) {
            const t = tileEl(b, rowIdx, i);
            if (!t) continue;
            t.innerText = palavra[i];
            t.classList.remove('active','selected');
            setTimeout(() => {
                if (cores[i]==='G') t.classList.add('correct');
                else if (cores[i]==='Y') t.classList.add('present');
                else t.classList.add('absent');
            }, rowIdx === currentRow ? i * 150 : 0);
        }
        updateKeyColors(palavra, cores);
    }

    function updateKeyColors(palavra, cores) {
        for (let i = 0; i < maxTiles; i++) {
            const l = palavra[i], c = cores[i];
            if ((keyPrio[c] || 0) > (keyPrio[keyState[l]] || 0)) keyState[l] = c;
        }
        renderKeyboard();
    }

    function renderKeyboard() {
        document.querySelectorAll('.key').forEach(btn => {
            const l = btn.innerText.trim();
            const c = keyState[l];
            btn.classList.remove('correct','present','absent');
            if (c==='G') btn.classList.add('correct');
            else if (c==='Y') btn.classList.add('present');
            else if (c==='X') btn.classList.add('absent');
        });
    }

    function restoreState() {
        CHUTES_PREV.forEach((palavra, rowIdx) => {
            fetch(`index.php?game=termo&modo=dueto`, {
                method: 'POST',
                headers: {'Content-Type':'application/x-www-form-urlencoded'},
                body: `chute=${palavra}&validar_somente=1`
            }).then(r => r.json()).then(data => {
                applyCoresToBoard(0, rowIdx, palavra, data.cores_1);
                applyCoresToBoard(1, rowIdx, palavra, data.cores_2);
                if (GANHOU_INIT[0] && rowIdx === CHUTES_PREV.length - 1)
                    markBoardSolved(0);
                if (GANHOU_INIT[1] && rowIdx === CHUTES_PREV.length - 1)
                    markBoardSolved(1);
            });
        });
    }
    if (CHUTES_PREV.length > 0) restoreState();

    function markBoardSolved(b) {
        const lbl = document.getElementById(`dlabel-${b}`);
        if (lbl && !lbl.classList.contains('solved')) {
            lbl.classList.add('solved');
            lbl.innerHTML = `✅ Palavra ${b+1}`;
        }
        for (let r = currentRow; r < MAX_ROWS; r++)
            for (let i = 0; i < maxTiles; i++) {
                const t = tileEl(b, r, i);
                if (t) { t.onclick = null; t.classList.remove('selected'); }
            }
    }

    document.addEventListener('keydown', e => {
        if (gameOver) return;
        const key = e.key.toUpperCase();
        if (key === 'ENTER') submitGuess();
        else if (key === 'BACKSPACE') deleteLetter();
        else if (key.length === 1 && /^[A-ZÇ]$/.test(key)) addLetter(key);
    });

    document.querySelectorAll('.key').forEach(btn => {
        btn.addEventListener('click', () => {
            if (gameOver) return;
            if (btn.id === 'enter-btn') { submitGuess(); return; }
            if (btn.id === 'back-btn')  { deleteLetter(); return; }
            addLetter(btn.innerText);
        });
    });

    function addLetter(letter) {
        for (let b = 0; b < 2; b++) {
            const t = tileEl(b, currentRow, selectedTile);
            if (t && !ganhouStatus[b]) { t.innerText = letter; t.classList.add('active'); }
        }
        guess[selectedTile] = letter;
        if (selectedTile < maxTiles - 1) selectedTile++;
        updateTileSelection();
    }

    function deleteLetter() {
        if (guess[selectedTile]) {
            for (let b = 0; b < 2; b++) {
                const t = tileEl(b, currentRow, selectedTile);
                if (t && !ganhouStatus[b]) { t.innerText = ''; t.classList.remove('active'); }
            }
            guess[selectedTile] = '';
        } else if (selectedTile > 0) {
            selectedTile--;
            for (let b = 0; b < 2; b++) {
                const t = tileEl(b, currentRow, selectedTile);
                if (t && !ganhouStatus[b]) { t.innerText = ''; t.classList.remove('active'); }
            }
            guess[selectedTile] = '';
        }
        updateTileSelection();
    }

    function submitGuess() {
        if (guess.some(l => !l)) { showMsgD('Preencha todas as letras!'); return; }
        const guessStr = guess.join('');
        fetch(`index.php?game=termo&modo=dueto`, {
            method: 'POST',
            headers: {'Content-Type':'application/x-www-form-urlencoded'},
            body: `chute=${guessStr}`
        }).then(r => r.json()).then(data => {
            if (data.erro) { showMsgD(data.erro); return; }
            applyCoresToBoard(0, currentRow, guessStr, data.cores_1);
            applyCoresToBoard(1, currentRow, guessStr, data.cores_2);
            if (data.ganhou_1 && !ganhouStatus[0]) { ganhouStatus[0] = true; setTimeout(() => markBoardSolved(0), 800); }
            if (data.ganhou_2 && !ganhouStatus[1]) { ganhouStatus[1] = true; setTimeout(() => markBoardSolved(1), 800); }
            if (data.fim_jogo) {
                gameOver = true;
                const ambas = data.ganhou_1 && data.ganhou_2;
                const msg = ambas
                    ? `Acertou as duas! +${data.pontos} moedas 🎉`
                    : `Fim de jogo! ${data.palavra_1} / ${data.palavra_2}`;
                setTimeout(() => { showMsgD(msg, ambas ? 'success' : 'danger'); }, 900);
                setTimeout(() => location.reload(), 4200);
            } else {
                currentRow++;
                selectedTile = 0;
                guess = ['','','','',''];
                updateTileSelection();
                bindRowClicks();
            }
        });
    }

    function showMsgD(msg, type = 'warning') {
        const area = document.getElementById('msg-area');
        area.className = `msg-area ${type}`;
        area.innerText = msg;
        setTimeout(() => area.classList.add('hidden'), 3500);
    }
})();
</script>
<?php endif; ?>
</body>
</html>
