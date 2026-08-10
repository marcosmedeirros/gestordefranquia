<?php
/**
 * Ponte entre a fila do site e a Evolution API que roda na máquina do Marcos.
 *
 * Por que existe: a Evolution está num PC doméstico, atrás de IP residencial e
 * NAT — a Hostinger não tem como POSTar nela. Então o sentido é invertido: o
 * worker de lá (bot/whatsapp-local.php) PUXA a fila daqui, envia pela Evolution
 * em localhost e devolve o resultado.
 *
 * Vantagem sobre expor a Evolution num túnel: PC dormindo não gasta tentativa
 * nenhuma da fila — simplesmente ninguém veio buscar. E a Evolution nunca fica
 * exposta na internet, o que importa porque o número está pareado com o
 * celular pessoal dele.
 *
 * Autenticação: token em whatsapp_config.bot_token, gerado sozinho na primeira
 * execução do ensureWhatsAppTables(). NÃO usa sessão — quem chama é um script,
 * não um navegador.
 *
 *   GET  ?action=pendentes[&limite=50]   → o que está esperando pra sair
 *   POST  action=resultado               → { resultados: [{id, ok, erro}] }
 */
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp.php';

header('Content-Type: application/json; charset=utf-8');

function botResponder(int $status, array $dados): void
{
    http_response_code($status);
    echo json_encode($dados, JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = db();
ensureWhatsAppTables($pdo);

// ── Autenticação ────────────────────────────────────────────────────────
$enviado = '';
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
if (stripos($auth, 'Bearer ') === 0) {
    $enviado = trim(substr($auth, 7));
} else {
    $enviado = (string)($_GET['token'] ?? $_POST['token'] ?? '');
}

$esperado = (string)($pdo->query("SELECT bot_token FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: '');
// hash_equals: comparação em tempo constante, pra não vazar o token byte a byte.
if ($esperado === '' || $enviado === '' || !hash_equals($esperado, $enviado)) {
    botResponder(401, ['erro' => 'Token inválido']);
}

// Marca que o worker deu sinal de vida — serve pra saber se o PC está de pé.
// Só de minuto em minuto: agora o worker bate aqui de poucos em poucos
// segundos e não faz sentido gastar um UPDATE em cada batida.
$pdo->prepare("UPDATE whatsapp_config SET bot_visto_em = NOW()
               WHERE id = 1 AND (bot_visto_em IS NULL OR bot_visto_em < NOW() - INTERVAL 1 MINUTE)")
    ->execute();

$acao = $_GET['action'] ?? $_POST['action'] ?? '';

// ── Pendentes ───────────────────────────────────────────────────────────
if ($acao === 'pendentes') {
    // A janela de horário mora aqui, no servidor: o worker não precisa saber
    // de horário nenhum, e mudar o expediente não exige tocar na máquina dele.
    //
    // Fora da janela só sai 'comando': alguém digitou /cap no grupo e está
    // esperando resposta. A janela existe pra não despejar aviso automático de
    // madrugada, não pra deixar quem perguntou no vácuo.
    $naJanela = whatsappDentroDaJanela();
    $filtroTipo = $naJanela ? '' : " AND tipo = 'comando'";

    $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
    $st = $pdo->prepare("SELECT id, destino, texto FROM whatsapp_fila
                         WHERE enviado_em IS NULL
                           AND tentativas < " . WHATSAPP_MAX_TENTATIVAS . "
                           AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                           {$filtroTipo}
                         ORDER BY id ASC LIMIT $limite");
    $st->execute();

    botResponder(200, [
        'janela' => $naJanela,
        'inicio' => WHATSAPP_JANELA_INICIO,
        'fim'    => WHATSAPP_JANELA_FIM,
        // De quantos em quantos segundos o worker deve voltar. No expediente,
        // rápido — é quando alguém digita comando e fica olhando pro celular.
        // De madrugada, devagar: comando ainda é respondido, só sem pressa.
        'intervalo' => $naJanela ? 5 : 20,
        'mensagens' => $st->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ── Resultado ───────────────────────────────────────────────────────────
if ($acao === 'resultado') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $resultados = $corpo['resultados'] ?? [];
    if (!is_array($resultados)) botResponder(400, ['erro' => 'resultados inválido']);

    $okStmt = $pdo->prepare("UPDATE whatsapp_fila
                             SET enviado_em = NOW(), tentativas = tentativas + 1, ultimo_erro = NULL
                             WHERE id = ? AND enviado_em IS NULL");
    // Mesmo backoff do envio direto: falha não vira retentativa imediata.
    $falhaStmt = $pdo->prepare("UPDATE whatsapp_fila
                                SET tentativas = tentativas + 1,
                                    proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                                    ultimo_erro = ?
                                WHERE id = ? AND enviado_em IS NULL");

    $enviadas = 0; $falhas = 0;
    foreach ($resultados as $r) {
        $id = (int)($r['id'] ?? 0);
        if ($id <= 0) continue;
        if (!empty($r['ok'])) {
            $okStmt->execute([$id]);
            $enviadas++;
        } else {
            $tent = (int)($pdo->query("SELECT tentativas FROM whatsapp_fila WHERE id = $id")->fetchColumn() ?: 0);
            $espera = WHATSAPP_BACKOFF_MIN[min($tent, count(WHATSAPP_BACKOFF_MIN) - 1)];
            $falhaStmt->execute([$espera, mb_substr((string)($r['erro'] ?? 'falha no worker'), 0, 255), $id]);
            $falhas++;
        }
    }
    botResponder(200, ['enviadas' => $enviadas, 'falhas' => $falhas]);
}

botResponder(400, ['erro' => 'Ação desconhecida']);
