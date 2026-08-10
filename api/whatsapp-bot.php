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

$acao = $_GET['action'] ?? $_POST['action'] ?? '';

// Marca que o worker deu sinal de vida — serve pra saber se o PC está de pé.
//
// Só em 'pendentes', que é o que apenas o worker chama. Marcar em toda
// requisição autenticada fazia o próprio 'diagnostico' carimbar a hora e
// responder que o worker estava vivo — inclusive quando ele estava parado.
// Um medidor que mente quando você olha pra ele não serve pra nada.
//
// E só de minuto em minuto: o worker bate aqui de poucos em poucos segundos,
// não faz sentido gastar um UPDATE em cada batida.
if ($acao === 'pendentes') {
    $pdo->prepare("UPDATE whatsapp_config SET bot_visto_em = NOW()
                   WHERE id = 1 AND (bot_visto_em IS NULL OR bot_visto_em < NOW() - INTERVAL 1 MINUTE)")
        ->execute();
}

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
    $st = $pdo->prepare("SELECT id, destino, texto, mencoes FROM whatsapp_fila
                         WHERE enviado_em IS NULL
                           AND tentativas < " . WHATSAPP_MAX_TENTATIVAS . "
                           AND (proxima_tentativa IS NULL OR proxima_tentativa <= NOW())
                           {$filtroTipo}
                         ORDER BY id ASC LIMIT $limite");
    $st->execute();
    $pendentes = $st->fetchAll(PDO::FETCH_ASSOC);
    // Guardado como JSON no banco; o worker recebe já como lista.
    foreach ($pendentes as &$p) {
        $p['mencoes'] = $p['mencoes'] ? (json_decode((string)$p['mencoes'], true) ?: []) : [];
    }
    unset($p);

    botResponder(200, [
        'janela' => $naJanela,
        'inicio' => WHATSAPP_JANELA_INICIO,
        'fim'    => WHATSAPP_JANELA_FIM,
        // De quantos em quantos segundos o worker deve voltar. No expediente,
        // rápido — é quando alguém digita comando e fica olhando pro celular.
        // De madrugada, devagar: comando ainda é respondido, só sem pressa.
        'intervalo' => $naJanela ? 5 : 20,
        'mensagens' => $pendentes,
    ]);
}

// ── Grupos onde o bot aceita comando ────────────────────────────────────
//   GET  ?action=grupos                 → a lista
//   POST  action=grupos { grupos: [{jid, nome, liga, ativo}] }  → substitui
//
// Fica aqui, atrás do bot_token, e não no whatsapp-admin.php, porque não há
// tela de admin pra WhatsApp: o admin.php nunca chegou a chamar aquele
// endpoint. Assim dá pra cadastrar grupo sem subir código — que é o ponto,
// já que id de grupo privado não deve entrar no repositório.
if ($acao === 'grupos') {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $corpo = json_decode(file_get_contents('php://input'), true);
        $grupos = $corpo['grupos'] ?? null;
        if (!is_array($grupos)) botResponder(400, ['erro' => 'grupos inválido']);

        $LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];
        $limpos = [];
        foreach ($grupos as $g) {
            $jid = trim((string)($g['jid'] ?? ''));
            // Só grupo: @g.us. Número individual aqui abriria o bot pra DM.
            if ($jid === '' || !str_ends_with($jid, '@g.us')) continue;
            $liga = strtoupper(trim((string)($g['liga'] ?? '')));
            $limpos[$jid] = [
                'jid'   => $jid,
                'nome'  => mb_substr(trim((string)($g['nome'] ?? '')), 0, 120) ?: null,
                'liga'  => in_array($liga, $LIGAS, true) ? $liga : null,
                'ativo' => array_key_exists('ativo', (array)$g) ? (int)!empty($g['ativo']) : 1,
            ];
        }

        $pdo->beginTransaction();
        try {
            $pdo->exec("DELETE FROM whatsapp_grupos_comando");
            $ins = $pdo->prepare("INSERT INTO whatsapp_grupos_comando (jid, nome, liga, ativo) VALUES (?,?,?,?)");
            foreach ($limpos as $g) $ins->execute([$g['jid'], $g['nome'], $g['liga'], $g['ativo']]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            botResponder(500, ['erro' => 'Falha ao salvar: ' . $e->getMessage()]);
        }
        botResponder(200, ['salvos' => count($limpos)]);
    }

    botResponder(200, [
        'grupos' => $pdo->query("SELECT jid, nome, liga, ativo FROM whatsapp_grupos_comando ORDER BY nome")
                        ->fetchAll(PDO::FETCH_ASSOC),
    ]);
}

// ── Diagnóstico ─────────────────────────────────────────────────────────
// A corrente tem cinco elos (flag ligada, grupo definido, webhook apontado,
// worker vivo, fila escoando) e quando o bot "não responde" o sintoma é o
// mesmo para qualquer um deles. Isto diz qual elo está frouxo sem precisar
// abrir o banco.
if ($acao === 'diagnostico') {
    $cfg = $pdo->query("SELECT grupo_principal, ativo, bot_visto_em FROM whatsapp_config WHERE id = 1")
               ->fetch(PDO::FETCH_ASSOC) ?: [];
    $grupo = trim((string)($cfg['grupo_principal'] ?? ''));

    $fila = $pdo->query("SELECT
            COUNT(*) total,
            SUM(enviado_em IS NULL) pendentes,
            SUM(enviado_em IS NOT NULL) enviadas,
            SUM(tipo = 'comando') comandos
        FROM whatsapp_fila")->fetch(PDO::FETCH_ASSOC);

    $ultimoErro = $pdo->query("SELECT ultimo_erro FROM whatsapp_fila
                               WHERE ultimo_erro IS NOT NULL ORDER BY id DESC LIMIT 1")->fetchColumn();

    botResponder(200, [
        'ativo'          => (bool)($cfg['ativo'] ?? 0),
        'grupo_definido' => $grupo !== '',
        // Só o final do JID: o suficiente pra conferir, sem despejar o
        // identificador do grupo numa resposta HTTP.
        'grupo_fim'      => $grupo === '' ? null : '…' . mb_substr($grupo, -12),
        'bot_visto_em'   => $cfg['bot_visto_em'] ?? null,
        'grupos_de_comando' => count(whatsappGruposDeComando($pdo)),
        'dentro_da_janela' => whatsappDentroDaJanela(),
        'fila'           => array_map('intval', $fila ?: []),
        'ultimo_erro'    => $ultimoErro ?: null,
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
