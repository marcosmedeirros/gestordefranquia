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
    $marcou = $pdo->prepare("UPDATE whatsapp_config SET bot_visto_em = NOW()
                             WHERE id = 1 AND (bot_visto_em IS NULL OR bot_visto_em < NOW() - INTERVAL 1 MINUTE)");
    $marcou->execute();

    /**
     * Fecha rodada de quiz que já venceu, de carona no batimento do worker.
     *
     * O cron do quiz roda numa janela estreita — a hora da pergunta do dia.
     * Quiz mandado à mão fora dela abria, esperava os dez minutos e ficava
     * aberto pra sempre: o resultado só sairia na execução da manhã seguinte,
     * junto com a pergunta nova. Foi o que aconteceu no primeiro teste real.
     *
     * O worker bate aqui de poucos em poucos segundos, então ele é o relógio
     * que já existe. Só que uma vez por minuto: o UPDATE acima já usa essa
     * cadência pela mesma razão, e varrer a tabela a cada 5s não muda nada
     * pra quem espera o resultado.
     */
    if ($marcou->rowCount() > 0) {
        try {
            require_once dirname(__DIR__) . '/backend/quiz.php';
            foreach (quizFecharVencidas($pdo) as [$grupo, $texto]) {
                whatsappEnfileirar($pdo, $grupo, $texto, true, 'manual');
                error_log('[quiz] rodada apurada pelo batimento do worker, grupo ' . $grupo);
            }
        } catch (Throwable $e) {
            // Apurar é carona: se falhar, a fila do bot segue normal.
            error_log('[quiz] apurar no batimento: ' . $e->getMessage());
        }
    }
}

// ── Pendentes ───────────────────────────────────────────────────────────
if ($acao === 'pendentes') {
    // A janela de horário mora aqui, no servidor: o worker não precisa saber
    // de horário nenhum, e mudar o expediente não exige tocar na máquina dele.
    //
    // Fora da janela só sai o que uma pessoa pediu agora — a lista está em
    // whatsappFiltroForaDaJanela(), junto da regra do outro leitor da fila.
    $naJanela = whatsappDentroDaJanela(null, $pdo);
    $filtroTipo = $naJanela ? '' : whatsappFiltroForaDaJanela();

    // Reserva em vez de SELECT solto: dois workers sobrepostos pegavam a
    // mesma linha e a mensagem saía duas vezes. Agora só um leva.
    $limite = max(1, min(200, (int)($_GET['limite'] ?? 50)));
    $pendentes = whatsappReservar($pdo, $limite, $filtroTipo);
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
// ── Nomes dos grupos ────────────────────────────────────────────────────
//   POST  action=nomes { grupos: [{jid, nome}] }
//
// O worker lê os nomes na Evolution (que a Hostinger não alcança) e manda
// pra cá. Separado do action=grupos de propósito: aquele SUBSTITUI a lista
// de grupos que aceitam comando, e usar ele pra nome cadastraria todo grupo
// do celular como grupo de comando — o oposto do que a lista serve.
//
// Aqui só o nome é gravado, e só em quem o bot já viu falar.
if ($acao === 'nomes') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') botResponder(405, ['erro' => 'use POST']);
    $corpo = json_decode(file_get_contents('php://input'), true);
    $grupos = $corpo['grupos'] ?? null;
    if (!is_array($grupos)) botResponder(400, ['erro' => 'grupos inválido']);

    $st = $pdo->prepare("UPDATE whatsapp_grupos_vistos SET nome = ? WHERE jid = ?");
    $gravados = 0;
    foreach (array_slice($grupos, 0, 300) as $g) {
        $jid  = trim((string)($g['jid'] ?? ''));
        $nome = mb_substr(trim((string)($g['nome'] ?? '')), 0, 120);
        if ($jid === '' || !str_ends_with($jid, '@g.us') || $nome === '') continue;
        $st->execute([$nome, $jid]);
        $gravados += $st->rowCount();
    }
    botResponder(200, ['ok' => true, 'atualizados' => $gravados]);
}

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

    // Quando chegou a última mensagem DE FORA. É o único jeito de saber, do
    // terminal, se o webhook da Evolution está chegando aqui: fila parada e
    // sem erro tem duas causas opostas — ninguém falou nada, ou a recepção
    // morreu e o site nem ficou sabendo. Já aconteceu de a Evolution reportar
    // 'open' com o socket de recepção morto, e nada aqui denunciava.
    //
    // A fonte é whatsapp_grupos_vistos: o webhook carimba visto_em a cada
    // mensagem de grupo, com ou sem o arquivo do painel ligado. Só a hora e o
    // grupo — não depende de gravar conteúdo de conversa pra responder
    // "quando foi a última vez que entrou alguma coisa aqui".
    $ultimaEntrada = null;
    try {
        $ultimaEntrada = $pdo->query("SELECT MAX(visto_em) FROM whatsapp_grupos_vistos")->fetchColumn() ?: null;
    } catch (Throwable $e) { /* tabela ainda não existe */ }

    botResponder(200, [
        'ativo'          => (bool)($cfg['ativo'] ?? 0),
        'grupo_definido' => $grupo !== '',
        'grupo_fim'      => $grupo === '' ? null : '…' . mb_substr($grupo, -12),
        'bot_visto_em'   => $cfg['bot_visto_em'] ?? null,
        'grupos_de_comando' => count(whatsappGruposDeComando($pdo)),
        'dentro_da_janela' => whatsappDentroDaJanela(null, $pdo),
        'plantao'        => whatsappPlantao($pdo),
        'ultima_entrada' => $ultimaEntrada,
        'fila'           => array_map('intval', $fila ?: []),
        'ultimo_erro'    => $ultimoErro ?: null,
    ]);
}

// ── Plantão ─────────────────────────────────────────────────────────────
//
// Mesmo liga/desliga do painel, só que autenticado por token em vez de
// sessão. Existe porque o painel exige navegador logado, e ligar o bot é
// justamente o tipo de coisa que se quer fazer do terminal — inclusive de uma
// máquina cujo IP não está liberado no MySQL remoto da Hostinger.
//
// O token já dá acesso à fila inteira; mandar no plantão não amplia o
// estrago de um vazamento.
if ($acao === 'plantao') {
    $modo = $_GET['modo'] ?? $_POST['modo'] ?? '';
    if (!in_array((string)$modo, ['sempre', 'off'], true) && (int)$modo <= 0) {
        botResponder(422, ['erro' => "modo deve ser 'sempre', 'off' ou um número de horas (1..12)"]);
    }
    $p = whatsappDefinirPlantao($pdo, is_numeric($modo) ? (int)$modo : (string)$modo);
    botResponder(200, ['ok' => true] + $p + ['dentro_da_janela' => whatsappDentroDaJanela(null, $pdo)]);
}

// ── Resultado ───────────────────────────────────────────────────────────
if ($acao === 'resultado') {
    $corpo = json_decode(file_get_contents('php://input'), true);
    $resultados = $corpo['resultados'] ?? [];
    if (!is_array($resultados)) botResponder(400, ['erro' => 'resultados inválido']);

    $okStmt = $pdo->prepare("UPDATE whatsapp_fila
                             SET enviado_em = NOW(), tentativas = tentativas + 1, ultimo_erro = NULL,
                                 reservado_por = NULL, reservado_ate = NULL
                             WHERE id = ? AND enviado_em IS NULL");
    // Mesmo backoff do envio direto: falha não vira retentativa imediata.
    $falhaStmt = $pdo->prepare("UPDATE whatsapp_fila
                                SET tentativas = tentativas + 1,
                                    proxima_tentativa = DATE_ADD(NOW(), INTERVAL ? MINUTE),
                                    ultimo_erro = ?,
                                    reservado_por = NULL, reservado_ate = NULL
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
