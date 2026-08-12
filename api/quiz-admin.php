<?php
/**
 * Administração do quiz: banco de perguntas, rodada em aberto e apuração.
 *
 * Só admin global — o quiz é do grupo principal, não de uma liga.
 */
header('Content-Type: application/json; charset=utf-8');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/quiz.php';

$user = getUserSession();
if (!$user || ($user['user_type'] ?? 'jogador') !== 'admin') {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Apenas admin geral']);
    exit;
}

$pdo = db();
quizGarantirTabelas($pdo);

function qaErro(int $http, string $msg): void {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}
function qaCorpo(): array { return json_decode(file_get_contents('php://input'), true) ?: []; }

/**
 * Barra quem manda pergunta fora do horário em que o bot entrega.
 *
 * O site só enfileira; quem entrega é o worker na máquina do Marcos, e ele
 * respeita uma janela (08:45–18:00). Sem esta trava, "enviar agora" às 18:18
 * respondia "enviada" e criava uma rodada que fecha em 30 minutos, mas cuja
 * pergunta só ia chegar no grupo às 08:45 do dia seguinte — vencida antes de
 * ser lida, e com resultado apurado sobre zero votos.
 */
function qaExigirJanela(): void
{
    if (whatsappDentroDaJanela()) return;
    qaErro(409, 'Fora do horário de envio do bot (' . WHATSAPP_JANELA_INICIO . ' às '
              . WHATSAPP_JANELA_FIM . '). A pergunta ficaria parada na fila até amanhã e '
              . 'venceria antes de alguém ver. Use "Salvar na fila" — ela sai no sorteio das 10:30.');
}

$acao = $_GET['action'] ?? qaCorpo()['action'] ?? 'estado';

try {
    if ($acao === 'estado') {
        $tot = $pdo->query("SELECT
            COUNT(*) total,
            SUM(tipo='certa') certas,
            SUM(tipo='votos') votos,
            SUM(usada_em IS NULL AND ativa=1) inéditas,
            SUM(usada_em IS NOT NULL) usadas,
            SUM(ativa=0) inativas
            FROM bot_quiz_perguntas")->fetch(PDO::FETCH_ASSOC);

        $st = $pdo->query("SELECT r.id, r.aberta_em, r.fecha_em, r.grupo_jid,
                                  p.texto, p.tipo, p.op1, p.op2, p.op3, p.op4,
                                  (SELECT COUNT(*) FROM bot_quiz_votos v WHERE v.rodada_id = r.id) votos
                           FROM bot_quiz_rodadas r JOIN bot_quiz_perguntas p ON p.id = r.pergunta_id
                           WHERE r.fechada_em IS NULL ORDER BY r.id DESC LIMIT 1");
        $aberta = $st->fetch(PDO::FETCH_ASSOC) ?: null;

        $ultimas = $pdo->query("SELECT r.id, r.fechada_em, r.vencedora, p.texto, p.tipo,
                                       (SELECT COUNT(*) FROM bot_quiz_votos v WHERE v.rodada_id = r.id) votos
                                FROM bot_quiz_rodadas r JOIN bot_quiz_perguntas p ON p.id = r.pergunta_id
                                WHERE r.fechada_em IS NOT NULL
                                ORDER BY r.fechada_em DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC);

        // Os grupos onde o bot fala, pro seletor da pergunta.
        require_once dirname(__DIR__) . '/backend/whatsapp.php';
        $principal = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id=1")->fetchColumn() ?: ''));
        $grupos = [];
        foreach (whatsappGruposDeComando($pdo) as $jid => $g) {
            $grupos[] = ['jid' => $jid, 'nome' => $g['nome'], 'liga' => $g['liga'],
                         'principal' => ($jid === $principal)];
        }

        // O site só enfileira; quem entrega é o worker na máquina do Marcos,
        // dentro de uma janela de horário. "Mandei e não chegou no grupo" é
        // quase sempre isto, e sem mostrar aqui só se descobre olhando a fila.
        $pendentes = 0;
        try {
            $pendentes = (int)$pdo->query("SELECT COUNT(*) FROM whatsapp_fila
                                           WHERE tipo = 'quiz' AND enviado_em IS NULL")->fetchColumn();
        } catch (Throwable $e) { /* fila pode não existir ainda */ }

        echo json_encode(['success' => true, 'contagem' => $tot, 'aberta' => $aberta,
                          'ultimas' => $ultimas, 'premio' => BOT_QUIZ_PREMIO, 'grupos' => $grupos,
                          'envio' => [
                              'ligado'    => whatsappAtivo($pdo),
                              'na_janela' => whatsappDentroDaJanela(),
                              'inicio'    => WHATSAPP_JANELA_INICIO,
                              'fim'       => WHATSAPP_JANELA_FIM,
                              'pendentes' => $pendentes,
                          ]]);
        exit;
    }

    /**
     * O que está de fato no servidor. Existe porque "não funcionou" sem mais
     * nada obriga a adivinhar: arquivo que não subiu, tabela que não criou e
     * constante que sumiu dão todos o mesmo sintoma na tela.
     */
    if ($acao === 'diagnostico') {
        $semente = dirname(__DIR__) . '/backend/seeds/quiz_perguntas.php';
        $linhas = [];
        $linhas['php'] = PHP_VERSION;
        $linhas['quiz.php'] = is_file(dirname(__DIR__) . '/backend/quiz.php') ? 'existe' : 'NÃO EXISTE';
        $linhas['BOT_QUIZ_PREMIO'] = defined('BOT_QUIZ_PREMIO') ? (string)BOT_QUIZ_PREMIO : 'NÃO DEFINIDA';
        $linhas['arquivo de perguntas'] = is_file($semente)
            ? 'existe (' . number_format(filesize($semente) / 1024, 1) . ' KB)'
            : 'NÃO EXISTE em ' . $semente;
        if (is_file($semente)) {
            $s = @require $semente;
            $linhas['perguntas no arquivo'] = is_array($s) ? count($s) . '' : 'formato inesperado';
        }
        foreach (['bot_quiz_perguntas', 'bot_quiz_rodadas', 'bot_quiz_votos'] as $t) {
            try {
                $linhas['tabela ' . $t] = $pdo->query("SHOW TABLES LIKE '$t'")->fetch()
                    ? $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn() . ' linha(s)'
                    : 'NÃO EXISTE';
            } catch (Throwable $e) { $linhas['tabela ' . $t] = 'erro: ' . $e->getMessage(); }
        }
        try {
            $pdo->query("SELECT grupo_jid, premio FROM bot_quiz_perguntas LIMIT 0");
            $linhas['colunas grupo_jid/premio'] = 'existem';
        } catch (Throwable $e) { $linhas['colunas grupo_jid/premio'] = 'FALTAM'; }
        $linhas['grupo principal'] = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id=1")->fetchColumn() ?: '')) ?: 'NÃO CONFIGURADO';

        // O Quiz do Dia do FBA Games mora no mesmo banco e disputava esses
        // nomes. Se ele reaparecer aqui como "tabela do bot", a colisão voltou.
        $linhas['quiz_perguntas (do FBA Games)'] = $pdo->query("SHOW TABLES LIKE 'quiz_perguntas'")->fetch()
            ? ($pdo->query("SHOW COLUMNS FROM quiz_perguntas LIKE 'pergunta'")->fetch()
                ? 'é a do Quiz do Dia, como deve ser' : 'ATENÇÃO: tem cara de tabela do bot')
            : 'não existe';

        echo json_encode(['success' => true, 'diagnostico' => $linhas]);
        exit;
    }

    /**
     * Os grupos onde o bot fala.
     *
     * Não é config do quiz: é do bot inteiro — é essa tabela que decide onde
     * ele aceita /comando e de qual liga o grupo é. O endpoint que grava isso
     * existia em api/whatsapp-bot.php desde sempre, mas ninguém nunca chamou:
     * nem tela, nem script. Resultado, a tabela ficou vazia e o bot só
     * atendia no grupo principal.
     */
    if ($acao === 'grupos_salvar') {
        $c = qaCorpo();
        $jid  = trim((string)($c['jid'] ?? ''));
        $nome = trim((string)($c['nome'] ?? ''));
        $liga = strtoupper(trim((string)($c['liga'] ?? '')));

        // Só grupo. Um número individual aqui abriria o bot pra conversa
        // privada, e aí qualquer um consulta o banco da liga no PV.
        if (!str_ends_with($jid, '@g.us')) {
            qaErro(400, 'O JID precisa terminar em @g.us — é o identificador do grupo, não de pessoa.');
        }
        if ($nome === '') qaErro(400, 'Dê um nome ao grupo.');
        if ($liga !== '' && !in_array($liga, ['ELITE','NEXT','RISE','ROOKIE'], true)) {
            qaErro(400, 'Liga inválida.');
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_grupos_comando (
            jid VARCHAR(120) PRIMARY KEY,
            nome VARCHAR(120) NULL,
            liga ENUM('ELITE','NEXT','RISE','ROOKIE') NULL,
            ativo TINYINT(1) NOT NULL DEFAULT 1,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->prepare("INSERT INTO whatsapp_grupos_comando (jid, nome, liga, ativo) VALUES (?,?,?,1)
                       ON DUPLICATE KEY UPDATE nome=VALUES(nome), liga=VALUES(liga), ativo=1")
            ->execute([$jid, mb_substr($nome, 0, 120), $liga !== '' ? $liga : null]);

        echo json_encode(['success' => true, 'message' => 'Grupo salvo. O bot passa a atender nele.']);
        exit;
    }

    /**
     * Grupos de onde já chegou mensagem, mas que não estão cadastrados.
     *
     * É a resposta pro "de onde eu tiro o JID": ninguém digita um id de 18
     * dígitos, e ele não aparece na interface do WhatsApp. Basta alguém falar
     * no grupo uma vez que ele cai aqui, com autor e trecho da última
     * mensagem pra dar pra reconhecer qual é.
     */
    if ($acao === 'grupos_vistos') {
        require_once dirname(__DIR__) . '/backend/whatsapp.php';
        $jaTem = array_keys(whatsappGruposDeComando($pdo));
        try {
            $vistos = $pdo->query("SELECT jid, ultimo_autor, ultima_mensagem, mensagens, visto_em
                                   FROM whatsapp_grupos_vistos ORDER BY visto_em DESC LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            // A tabela só nasce quando o webhook recebe a primeira mensagem.
            $vistos = [];
        }
        $novos = array_values(array_filter($vistos, fn($v) => !in_array($v['jid'], $jaTem, true)));
        echo json_encode(['success' => true, 'vistos' => $novos]);
        exit;
    }

    if ($acao === 'grupos_remover') {
        $jid = trim((string)(qaCorpo()['jid'] ?? ''));
        if ($jid === '') qaErro(400, 'jid obrigatório');
        $pdo->prepare("DELETE FROM whatsapp_grupos_comando WHERE jid = ?")->execute([$jid]);
        echo json_encode(['success' => true, 'message' => 'Grupo removido.']);
        exit;
    }

    if ($acao === 'listar') {
        $tipo = $_GET['tipo'] ?? '';
        $busca = trim((string)($_GET['q'] ?? ''));
        $sql = "SELECT id, tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao,
                       grupo_jid, premio, ativa, usada_em
                FROM bot_quiz_perguntas WHERE 1";
        $args = [];
        if (in_array($tipo, ['certa','votos'], true)) { $sql .= " AND tipo = ?"; $args[] = $tipo; }
        if ($busca !== '') { $sql .= " AND texto LIKE ?"; $args[] = '%' . $busca . '%'; }

        // A lista é a FILA do que ainda vai sair, então o padrão esconde o que
        // já foi ao ar. Pergunta usada não volta ao sorteio; ela vira histórico,
        // e histórico misturado com fila deixa 188 linhas onde importam 12.
        $estado = $_GET['estado'] ?? 'disponiveis';
        if ($estado === 'usadas')          $sql .= " AND usada_em IS NOT NULL";
        elseif ($estado !== 'todas')       $sql .= " AND usada_em IS NULL";

        // As já usadas ordenam pela ida ao ar, da mais recente — na fila o id
        // decrescente serve, mas no histórico a data é o que se procura.
        $sql .= $estado === 'usadas' ? " ORDER BY usada_em DESC LIMIT 300" : " ORDER BY id DESC LIMIT 300";
        $st = $pdo->prepare($sql);
        $st->execute($args);
        echo json_encode(['success' => true, 'perguntas' => $st->fetchAll(PDO::FETCH_ASSOC)]);
        exit;
    }

    // 'salvar_e_enviar' é o mesmo caminho do 'salvar', e de propósito: uma
    // validação separada pra pergunta que vai ao ar na hora seria justamente a
    // que ninguém lembraria de atualizar.
    if ($acao === 'salvar' || $acao === 'salvar_e_enviar') {
        $enviarAgora = ($acao === 'salvar_e_enviar');
        $c = qaCorpo();
        $id    = (int)($c['id'] ?? 0);
        $tipo  = in_array($c['tipo'] ?? '', ['certa','votos'], true) ? $c['tipo'] : 'certa';
        $texto = trim((string)($c['texto'] ?? ''));
        $ops   = array_map(fn($o) => trim((string)$o), (array)($c['opcoes'] ?? []));
        $cat   = trim((string)($c['categoria'] ?? '')) ?: null;
        $exp   = trim((string)($c['explicacao'] ?? '')) ?: null;

        if ($texto === '') qaErro(400, 'Escreva a pergunta.');
        if (count($ops) !== 4 || count(array_filter($ops, fn($o) => $o !== '')) !== 4) {
            qaErro(400, 'Preencha as quatro opções.');
        }
        if (count(array_unique($ops)) !== 4) qaErro(400, 'As quatro opções precisam ser diferentes.');

        // Na de resposta certa a opção é obrigatória. Sem ela o bot apuraria
        // como se fosse voto, e a pergunta que TEM resposta viraria enquete.
        $correta = null;
        if ($tipo === 'certa') {
            $correta = (int)($c['correta'] ?? 0);
            if ($correta < 1 || $correta > 4) qaErro(400, 'Marque qual das quatro é a resposta certa.');
        }

        // Grupo e prêmio ficam NULL quando não escolhidos — aí a pergunta usa
        // o padrão do dia em que for sorteada, em vez de congelar o de hoje.
        require_once dirname(__DIR__) . '/backend/whatsapp.php';
        $grupo = trim((string)($c['grupo_jid'] ?? ''));
        if ($grupo !== '' && !isset(whatsappGruposDeComando($pdo)[$grupo])) {
            qaErro(400, 'Esse grupo não está cadastrado — o bot não fala nele.');
        }
        $grupo = $grupo !== '' ? $grupo : null;

        $premio = (int)($c['premio'] ?? 0);
        if ($premio < 0 || $premio > 100000) qaErro(400, 'Prêmio fora da faixa (0 a 100.000).');
        $premio = $premio > 0 ? $premio : null;

        // Enviar na hora: as travas vêm ANTES de gravar. Deixar a pergunta
        // nascer e só então descobrir que o bot está desligado deixaria ela no
        // banco parecendo que foi ao ar.
        if ($enviarAgora) {
            $destino = $grupo ?: trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
            if ($destino === '') qaErro(409, 'Não há grupo principal configurado, e a pergunta não escolheu um.');
            if (!whatsappAtivo($pdo)) qaErro(409, 'O bot está desligado — ligue antes de enviar.');
            qaExigirJanela();
            if (quizRodadaAberta($pdo, $destino)) {
                qaErro(409, 'Já tem uma pergunta no ar nesse grupo. Espere fechar, ou use "Apurar agora".');
            }
        }

        if ($id > 0) {
            $pdo->prepare("UPDATE bot_quiz_perguntas SET tipo=?, categoria=?, texto=?,
                           op1=?, op2=?, op3=?, op4=?, correta=?, explicacao=?, grupo_jid=?, premio=? WHERE id=?")
                ->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp, $grupo, $premio, $id]);
            $salvaId = $id;
            $recado  = 'Pergunta atualizada.';
        } else {
            $pdo->prepare("INSERT INTO bot_quiz_perguntas (tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao, grupo_jid, premio, criada_por)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)")
                ->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp, $grupo, $premio, (int)$user['id']]);
            $salvaId = (int)$pdo->lastInsertId();
            $recado  = 'Pergunta criada.';
        }

        if ($enviarAgora) {
            // Reeditar e reenviar uma que já foi ao ar é decisão do admin, mas
            // o sorteio não pode encostar nela de novo: quem já respondeu sabe
            // a resposta.
            $aberta = quizAbrirPergunta($pdo, $salvaId, $destino);
            if ($aberta === null) qaErro(500, 'A pergunta foi salva, mas não deu pra abrir a rodada.');
            if (!whatsappEnfileirar($pdo, $aberta['grupo'], $aberta['texto'], true, 'quiz')) {
                qaErro(500, 'A pergunta foi salva e a rodada aberta, mas a fila do bot recusou a mensagem.');
            }
            $nome = whatsappGruposDeComando($pdo)[$aberta['grupo']]['nome'] ?? 'grupo principal';
            echo json_encode(['success' => true, 'id' => $salvaId,
                'message' => 'Pergunta enviada para ' . $nome . '. Fecha em '
                           . BOT_QUIZ_MINUTOS . ' minutos.']);
            exit;
        }

        echo json_encode(['success' => true, 'id' => $salvaId, 'message' => $recado]);
        exit;
    }

    if ($acao === 'alternar') {
        $id = (int)(qaCorpo()['id'] ?? 0);
        if (!$id) qaErro(400, 'id obrigatório');
        $pdo->prepare("UPDATE bot_quiz_perguntas SET ativa = 1 - ativa WHERE id = ?")->execute([$id]);
        $st = $pdo->prepare("SELECT ativa FROM bot_quiz_perguntas WHERE id = ?");
        $st->execute([$id]);
        $ativa = (int)$st->fetchColumn();
        echo json_encode(['success' => true, 'ativa' => $ativa,
            'message' => $ativa ? 'Voltou pro sorteio.' : 'Fora do sorteio.']);
        exit;
    }

    if ($acao === 'excluir') {
        $id = (int)(qaCorpo()['id'] ?? 0);
        if (!$id) qaErro(400, 'id obrigatório');
        // Rodada guarda o resultado do dia; apagar a pergunta levaria junto.
        $st = $pdo->prepare("SELECT COUNT(*) FROM bot_quiz_rodadas WHERE pergunta_id = ?");
        $st->execute([$id]);
        if ((int)$st->fetchColumn() > 0) {
            qaErro(409, 'Essa pergunta já foi ao ar — desative em vez de apagar, senão o resultado daquele dia some junto.');
        }
        $pdo->prepare("DELETE FROM bot_quiz_perguntas WHERE id = ?")->execute([$id]);
        echo json_encode(['success' => true, 'message' => 'Pergunta apagada.']);
        exit;
    }

    /** Carrega o banco inicial. Rodar de novo não duplica. */
    if ($acao === 'popular') {
        // Cada tropeço daqui vira uma mensagem que diz o que fazer. Antes tudo
        // caía no "Erro interno" genérico do catch lá embaixo, e não havia como
        // saber se o arquivo não subiu, se veio vazio ou se o banco recusou.
        $caminho = dirname(__DIR__) . '/backend/seeds/quiz_perguntas.php';
        if (!is_file($caminho)) {
            qaErro(500, 'O arquivo de perguntas não está no servidor (backend/seeds/quiz_perguntas.php). '
                      . 'Provavelmente o deploy não subiu a pasta nova — confira se ela existe na Hostinger.');
        }
        if (!is_readable($caminho)) {
            qaErro(500, 'O arquivo de perguntas existe mas não dá pra ler — é permissão de arquivo.');
        }

        $sementes = require $caminho;
        if (!is_array($sementes) || !$sementes) {
            qaErro(500, 'O arquivo de perguntas veio vazio ou em formato inesperado. '
                      . 'Se ele subiu truncado, refaça o deploy do backend/seeds/quiz_perguntas.php.');
        }

        $existe = $pdo->prepare("SELECT COUNT(*) FROM bot_quiz_perguntas WHERE texto = ?");
        $ins = $pdo->prepare("INSERT INTO bot_quiz_perguntas (tipo, categoria, texto, op1, op2, op3, op4, correta, explicacao)
                              VALUES (?,?,?,?,?,?,?,?,?)");
        $novas = 0; $puladas = 0;
        foreach ($sementes as $s) {
            [$tipo, $cat, $texto, $ops, $correta, $exp] = $s + [null, null, null, null, null, null];
            $existe->execute([$texto]);
            if ((int)$existe->fetchColumn() > 0) { $puladas++; continue; }
            $ins->execute([$tipo, $cat, $texto, $ops[0], $ops[1], $ops[2], $ops[3], $correta, $exp]);
            $novas++;
        }
        echo json_encode(['success' => true, 'novas' => $novas, 'puladas' => $puladas,
            'message' => $novas . ' pergunta(s) adicionadas'
                       . ($puladas ? ', ' . $puladas . ' já existiam' : '') . '.']);
        exit;
    }

    /**
     * Desfaz a rodada aberta: some do ar e a pergunta volta pra fila.
     *
     * Não é o mesmo que apurar. Apurar encerra e paga; isto é pra quando a
     * pergunta não devia ter saído — mandada fora do horário e presa na fila,
     * ou com erro de digitação percebido tarde demais. Os votos vão junto pelo
     * ON DELETE CASCADE, e a pergunta perde o `usada_em` pra voltar ao sorteio.
     */
    if ($acao === 'cancelar_rodada') {
        $r = $pdo->query("SELECT id, pergunta_id, grupo_jid, aberta_em FROM bot_quiz_rodadas
                          WHERE fechada_em IS NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        if (!$r) qaErro(409, 'Não há rodada aberta pra cancelar.');

        // A mensagem só some se ainda NÃO saiu. Depois de entregue, apagar a
        // linha da fila não desfaz nada no grupo — e sumir com o registro
        // deixaria o admin achando que ninguém viu a pergunta.
        $del = $pdo->prepare("DELETE FROM whatsapp_fila
                              WHERE destino = ? AND tipo = 'quiz' AND enviado_em IS NULL
                                AND created_at >= ?");
        $del->execute([$r['grupo_jid'], $r['aberta_em']]);
        $retirada = $del->rowCount();

        $votos = (int)$pdo->query("SELECT COUNT(*) FROM bot_quiz_votos WHERE rodada_id = " . (int)$r['id'])->fetchColumn();

        $pdo->prepare("UPDATE bot_quiz_perguntas SET usada_em = NULL WHERE id = ?")->execute([(int)$r['pergunta_id']]);
        $pdo->prepare("DELETE FROM bot_quiz_rodadas WHERE id = ?")->execute([(int)$r['id']]);

        echo json_encode(['success' => true, 'message' =>
            $retirada
                ? 'Cancelada antes de sair — a mensagem foi tirada da fila e a pergunta voltou pro sorteio.'
                : 'Cancelada, e a pergunta voltou pro sorteio. Mas a mensagem JÁ tinha ido pro grupo'
                  . ($votos ? " ({$votos} voto(s) descartado(s))" : '') . ' — avise por lá.']);
        exit;
    }

    /** Fecha a rodada aberta na hora, sem esperar o prazo. */
    if ($acao === 'apurar_agora') {
        $st = $pdo->query("SELECT id, grupo_jid FROM bot_quiz_rodadas WHERE fechada_em IS NULL ORDER BY id DESC LIMIT 1");
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) qaErro(409, 'Não há rodada aberta.');
        $txt = quizFechar($pdo, (int)$r['id']);
        if ($txt === null) qaErro(409, 'Não deu pra apurar.');
        whatsappEnfileirar($pdo, $r['grupo_jid'], $txt, true, 'quiz');
        echo json_encode(['success' => true, 'message' => 'Apurado e postado no grupo.']);
        exit;
    }

    /** Manda a pergunta do dia agora, fora do horário. */
    if ($acao === 'abrir_agora') {
        $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
        if ($grupo === '') qaErro(409, 'Sem grupo principal configurado.');
        qaExigirJanela();
        $aberta = quizAbrir($pdo, $grupo);
        if ($aberta === null) {
            // Sem reciclagem, "acabaram as inéditas" virou um fim de linha
            // normal — e dizer só "não deu" mandaria procurar defeito onde não
            // tem. A contagem separa os dois casos.
            $restam = (int)$pdo->query("SELECT COUNT(*) FROM bot_quiz_perguntas
                                        WHERE ativa = 1 AND usada_em IS NULL")->fetchColumn();
            qaErro(409, $restam === 0
                ? 'Acabaram as perguntas que ainda não foram ao ar. Cadastre novas — as já usadas não voltam.'
                : 'Já há uma pergunta no ar nesse grupo.');
        }
        if (!whatsappEnfileirar($pdo, $aberta['grupo'], $aberta['texto'], true, 'quiz')) qaErro(409, 'O bot está desligado.');
        echo json_encode(['success' => true, 'message' => 'Pergunta postada no grupo.']);
        exit;
    }

    qaErro(400, 'Ação desconhecida.');
} catch (Throwable $e) {
    error_log('[quiz-admin] ' . $acao . ': ' . $e->getMessage());
    http_response_code(500);
    // A mensagem real vai junto. Esta tela é só do admin geral, e "erro
    // interno" sozinho obriga a ir catar log no servidor pra descobrir se
    // faltou uma coluna, uma tabela ou permissão.
    echo json_encode(['success' => false,
        'error' => 'Erro interno: ' . $e->getMessage()]);
}
