<?php
/**
 * Quiz do bot — uma pergunta por dia no grupo, quatro opções, voto por comando.
 *
 * Dois tipos de pergunta:
 *
 *   'certa'  tem resposta certa. Ganha quem acertou.
 *   'votos'  não tem. Ganha quem votou na opção mais votada — é o "quem foi
 *            melhor", em que o prêmio empurra a pessoa a convencer o grupo
 *            antes de votar, e é isso que gera a discussão.
 *
 * O voto entra CALADO. Doze pessoas votando são doze comandos, e o bot tem um
 * freio de 12 por minuto por grupo — se cada voto tivesse resposta, o quiz
 * derrubaria o próprio bot. O bot fala duas vezes: ao abrir e ao apurar.
 *
 * Tudo aqui é prefixado com bot_ porque o FBA Games já tem o Quiz do Dia dele
 * (games/games/quizdodia.php), no MESMO banco, com tabelas chamadas
 * quiz_perguntas e quiz_votos. São jogos diferentes — cinco opções e só
 * opinião lá, quatro opções e resposta certa aqui — e disputar o nome fez o
 * CREATE TABLE IF NOT EXISTS virar silenciosamente um no-op: as tabelas já
 * existiam, com as colunas do outro jogo. Nada do quiz do bot gravava.
 */

require_once __DIR__ . '/whatsapp.php';

/** Quanto vale acertar, em moedas do FBA Games. */
const BOT_QUIZ_PREMIO = 100;

/**
 * A que horas de Brasília a pergunta do dia sai, e por quanto tempo fica em pé.
 *
 * Os dois moram aqui porque quatro lugares precisam deles: o cron, que decide
 * se já é hora; a rodada, que grava o prazo; a tela do admin, que promete o
 * horário; e a mensagem do grupo, que anuncia. Escritos à mão em cada um, uma
 * mudança de horário deixaria três versões da verdade circulando.
 *
 * Hoje: abre 10:30, fecha 10:40.
 *
 * Mexeu aqui? O agendamento na Hostinger tem que andar junto — são duas
 * entradas, e a de apuração precisa cair DEPOIS do fechamento. Ver o
 * cabeçalho do cron/quiz.php.
 */
const BOT_QUIZ_HORA    = '10:30';
const BOT_QUIZ_MINUTOS = 10;

/** A que horas a rodada aberta hoje fecha, em HH:MM. */
function quizHoraDoFechamento(): string
{
    return date('H:i', strtotime('today ' . BOT_QUIZ_HORA) + BOT_QUIZ_MINUTOS * 60);
}

/**
 * Em que grupo o quiz do dia sai.
 *
 * Tem escolha própria, separada do grupo_principal, porque os dois querem
 * coisas diferentes: o principal recebe o abraço e os avisos de trade e é o
 * grupo "oficial" da liga; o quiz é brincadeira e vive melhor no chat off. Sem
 * essa separação, apontar o quiz pro chat off levava o resto junto.
 *
 * Vazio = usa o principal, que é o comportamento de quem nunca configurou.
 */
function quizGrupoDoQuiz(PDO $pdo): string
{
    quizGarantirTabelas($pdo);
    $c = $pdo->query("SELECT quiz_grupo, grupo_principal FROM whatsapp_config WHERE id = 1")
             ->fetch(PDO::FETCH_ASSOC) ?: [];
    return trim((string)($c['quiz_grupo'] ?? '')) ?: trim((string)($c['grupo_principal'] ?? ''));
}

function quizGarantirTabelas(PDO $pdo): void
{
    static $pronto = false;
    if ($pronto) return;
    $pronto = true;

    quizDesfazerColisao($pdo);

    // Onde o quiz sai, separado do grupo_principal. A tabela é do WhatsApp e
    // não do quiz, mas a coluna nasce aqui porque é aqui que ela é usada — e
    // porque o ensureWhatsAppTables não roda em toda página que abre o quiz.
    try {
        if (!$pdo->query("SHOW COLUMNS FROM whatsapp_config LIKE 'quiz_grupo'")->fetch()) {
            $pdo->exec("ALTER TABLE whatsapp_config ADD COLUMN quiz_grupo VARCHAR(120) NULL AFTER grupo_principal");
        }
    } catch (Throwable $e) {
        error_log('[quiz] coluna quiz_grupo: ' . $e->getMessage());
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_quiz_perguntas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tipo ENUM('certa','votos') NOT NULL DEFAULT 'certa',
        categoria VARCHAR(40) NULL,
        texto VARCHAR(400) NOT NULL,
        op1 VARCHAR(120) NOT NULL,
        op2 VARCHAR(120) NOT NULL,
        op3 VARCHAR(120) NOT NULL,
        op4 VARCHAR(120) NOT NULL,
        -- 1 a 4 nas de resposta certa; NULL nas de voto.
        correta TINYINT NULL,
        explicacao VARCHAR(300) NULL,
        -- Vazios = o padrão (grupo principal, BOT_QUIZ_PREMIO). Guardar NULL em vez
        -- de copiar o padrão faz a pergunta acompanhar quando o padrão mudar,
        -- em vez de ficar presa ao valor do dia em que foi escrita.
        grupo_jid VARCHAR(120) NULL,
        premio INT NULL,
        criada_por INT NULL,
        ativa TINYINT(1) NOT NULL DEFAULT 1,
        usada_em DATETIME NULL,
        criada_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_qp_uso (ativa, usada_em)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Colunas que nasceram depois: a tabela pode já existir sem elas.
    foreach (['grupo_jid' => "VARCHAR(120) NULL AFTER explicacao",
              'premio'    => "INT NULL AFTER grupo_jid"] as $col => $tipo) {
        if (!$pdo->query("SHOW COLUMNS FROM bot_quiz_perguntas LIKE '$col'")->fetch()) {
            $pdo->exec("ALTER TABLE bot_quiz_perguntas ADD COLUMN $col $tipo");
        }
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_quiz_rodadas (
        id INT AUTO_INCREMENT PRIMARY KEY,
        pergunta_id INT NOT NULL,
        grupo_jid VARCHAR(120) NOT NULL,
        aberta_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        fecha_em DATETIME NOT NULL,
        fechada_em DATETIME NULL,
        vencedora TINYINT NULL,
        -- Congelado na abertura: se alguém mudar o prêmio da pergunta no meio
        -- da rodada, paga o que foi anunciado no grupo, não o novo.
        premio INT NOT NULL DEFAULT 100,
        -- Uma rodada aberta por grupo. O UNIQUE nao serve aqui (fechada_em
        -- NULL repete), entao quem garante e a consulta de abertura.
        INDEX idx_qr_aberta (grupo_jid, fechada_em),
        CONSTRAINT fk_qr_pergunta FOREIGN KEY (pergunta_id) REFERENCES bot_quiz_perguntas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS bot_quiz_votos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        rodada_id INT NOT NULL,
        telefone VARCHAR(20) NOT NULL,
        user_id INT NULL,
        opcao TINYINT NOT NULL,
        votado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        -- Um voto por pessoa por rodada, e o primeiro é o que vale. Este
        -- UNIQUE nao e so um indice: e ele que RECUSA o segundo voto, sem
        -- precisar de SELECT antes — e sem SELECT nao ha brecha entre ler e
        -- gravar, que num grupo e o tempo de mandar /1 e /2 seguidos.
        UNIQUE KEY uk_qv (rodada_id, telefone),
        CONSTRAINT fk_qv_rodada FOREIGN KEY (rodada_id) REFERENCES bot_quiz_rodadas(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/**
 * Apaga o rastro da versão que disputava o nome com o Quiz do Dia do games.
 *
 * Duas sujeiras diferentes, e a segunda é a que machuca:
 *
 *   1. quiz_perguntas ganhou duas colunas minhas (grupo_jid, premio) por um
 *      ALTER que rodou achando que a tabela era minha. São nulas e o games
 *      insere com lista de colunas explícita, então não quebraram nada — mas
 *      ficam lá confundindo quem for ler a tabela depois.
 *
 *   2. quiz_rodadas nasceu com uma FOREIGN KEY apontando pra quiz_perguntas.
 *      Essa É um problema de verdade: com ON DELETE CASCADE, apagar uma
 *      pergunta do Quiz do Dia levaria junto rodadas do bot, e enquanto a FK
 *      existir o InnoDB também trava um DROP da tabela do games.
 *
 * Só mexe no que dá pra provar que é meu: a tabela do games tem a coluna
 * `pergunta`, a minha tem `op1`. Sem essa prova, não encosta.
 */
function quizDesfazerColisao(PDO $pdo): void
{
    $existe = fn(string $t) => (bool)$pdo->query("SHOW TABLES LIKE '$t'")->fetch();
    $temCol = fn(string $t, string $c) => (bool)$pdo->query("SHOW COLUMNS FROM `$t` LIKE '$c'")->fetch();

    try {
        // quiz_rodadas é só minha — o Quiz do Dia não tem tabela com esse nome.
        // Se ela existe, é resto da versão errada, e nunca chegou a rodar (as
        // consultas que a alimentavam batiam na tabela do outro jogo).
        if ($existe('quiz_rodadas')) {
            $pdo->exec("DROP TABLE IF EXISTS quiz_rodadas");
            error_log('[quiz] quiz_rodadas removida — era resto da colisão de nome');
        }

        // quiz_perguntas: só limpa se for a do games (tem `pergunta`) e as
        // colunas forem as minhas. Se tiver `op1`, a tabela é a minha e quem
        // está sem tabela é o games — aí deixa quieto, não é hora de decidir
        // isso dentro de um garantir-tabelas.
        if ($existe('quiz_perguntas') && $temCol('quiz_perguntas', 'pergunta')) {
            foreach (['grupo_jid', 'premio'] as $col) {
                if ($temCol('quiz_perguntas', $col)) {
                    $pdo->exec("ALTER TABLE quiz_perguntas DROP COLUMN `$col`");
                    error_log("[quiz] coluna $col removida de quiz_perguntas (era do quiz do bot)");
                }
            }
        }
    } catch (Throwable $e) {
        // Limpeza não pode derrubar o quiz. Se falhar, o pior caso é uma
        // coluna sobrando numa tabela que não é minha.
        error_log('[quiz] limpeza da colisão: ' . $e->getMessage());
    }
}

/** O telefone só em dígitos, do jeito que users.phone guarda. */
function quizTelefoneDoJid(string $jid): string
{
    return preg_replace('/\D+/', '', explode('@', $jid)[0] ?? '');
}

/**
 * A rodada aberta de um grupo, se houver.
 *
 * O `vencida` é calculado no SQL de propósito. Comparando em PHP, os dois
 * lados viriam de relógios diferentes — o prazo é gravado com o NOW() do
 * MySQL e seria comparado com o time() do PHP. Hoje eles batem porque o
 * db.php fixa o fuso da conexão, mas o dia em que desencontrarem os votos
 * param de contar sem erro nenhum, e não há como descobrir olhando log.
 */
function quizRodadaAberta(PDO $pdo, string $grupoJid): ?array
{
    quizGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT r.*, p.tipo, p.texto, p.op1, p.op2, p.op3, p.op4, p.correta, p.explicacao,
                                (r.fecha_em <= NOW()) AS vencida
                         FROM bot_quiz_rodadas r JOIN bot_quiz_perguntas p ON p.id = r.pergunta_id
                         WHERE r.grupo_jid = ? AND r.fechada_em IS NULL
                         ORDER BY r.id DESC LIMIT 1");
    $st->execute([$grupoJid]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/**
 * Registra o voto. Devolve true se entrou.
 *
 * Um voto por pessoa, e o primeiro é o que vale — votar de novo não troca
 * nada. Trocar parecia gentileza, mas num grupo em que todo mundo vê o que os
 * outros mandam, ela vira estratégia: dá pra esperar a discussão virar e
 * corrigir o voto em cima do prazo, e aí o prêmio para de ser de quem sabia e
 * passa a ser de quem esperou.
 *
 * Não responde nada a quem votou — quem chama é o webhook, e a resposta vazia
 * é o que mantém o quiz dentro do freio de comandos. Quem votar duas vezes não
 * ouve nada; a regra sai escrita na mensagem que abre a rodada.
 */
function quizVotar(PDO $pdo, string $grupoJid, string $deQuem, int $opcao): bool
{
    if ($opcao < 1 || $opcao > 4) return false;
    $rodada = quizRodadaAberta($pdo, $grupoJid);
    if (!$rodada) return false;
    if (!empty($rodada['vencida'])) return false;

    $telefone = quizTelefoneDoJid($deQuem);
    if ($telefone === '') return false;

    // Liga o voto ao GM quando o telefone bate com o cadastro. Sem achar, o
    // voto ainda conta pra apuração — só não rende moeda, porque não há
    // conta pra creditar.
    $userId = null;
    try {
        $st = $pdo->prepare("SELECT id FROM users WHERE REGEXP_REPLACE(phone,'[^0-9]','') = ? LIMIT 1");
        $st->execute([$telefone]);
        $userId = $st->fetchColumn() ?: null;
    } catch (Throwable $e) { /* sem phone cadastrado: segue sem user */ }

    // INSERT seco: quem garante o voto único é a UNIQUE (rodada_id, telefone).
    // Conferir com um SELECT antes deixaria brecha entre a leitura e a
    // gravação — e num grupo dá pra mandar /1 e /2 no mesmo segundo.
    try {
        $pdo->prepare("INSERT INTO bot_quiz_votos (rodada_id, telefone, user_id, opcao)
                       VALUES (?,?,?,?)")
            ->execute([(int)$rodada['id'], $telefone, $userId, $opcao]);
        return true;
    } catch (PDOException $e) {
        // 23000 é a UNIQUE estourando: a pessoa já votou, e o primeiro vale.
        if ($e->getCode() === '23000') return false;
        throw $e;
    }
}

/**
 * Fecha a rodada, credita as moedas e devolve o texto do resultado.
 * Devolve null se não havia o que fechar.
 */
function quizFechar(PDO $pdo, int $rodadaId): ?string
{
    quizGarantirTabelas($pdo);
    $st = $pdo->prepare("SELECT r.*, p.tipo, p.texto, p.op1, p.op2, p.op3, p.op4, p.correta, p.explicacao
                         FROM bot_quiz_rodadas r JOIN bot_quiz_perguntas p ON p.id = r.pergunta_id
                         WHERE r.id = ? AND r.fechada_em IS NULL");
    $st->execute([$rodadaId]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;

    // O premio e o da RODADA, congelado na abertura.
    $premio = (int)($r['premio'] ?? 0) ?: BOT_QUIZ_PREMIO;

    $st = $pdo->prepare("SELECT opcao, COUNT(*) n FROM bot_quiz_votos WHERE rodada_id = ? GROUP BY opcao");
    $st->execute([$rodadaId]);
    $contagem = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $v) $contagem[(int)$v['opcao']] = (int)$v['n'];
    $totalVotos = array_sum($contagem);

    if ($r['tipo'] === 'certa') {
        $vencedora = (int)$r['correta'];
    } else {
        // Mais votada. Empate: fica a de número menor — regra chata, mas dita
        // na abertura. Sortear deixaria o grupo achando que houve roubo.
        $vencedora = 1;
        foreach ([2, 3, 4] as $o) if ($contagem[$o] > $contagem[$vencedora]) $vencedora = $o;
    }

    // Credita quem acertou. Voto sem GM identificado conta na apuração mas não
    // rende moeda — não há conta pra creditar.
    $st = $pdo->prepare("SELECT user_id, telefone FROM bot_quiz_votos WHERE rodada_id = ? AND opcao = ?");
    $st->execute([$rodadaId, $vencedora]);
    $ganhadores = $st->fetchAll(PDO::FETCH_ASSOC);

    $nomes = [];
    $credita = $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?");
    $nomeDe  = $pdo->prepare("SELECT name FROM users WHERE id = ?");
    foreach ($ganhadores as $g) {
        if (empty($g['user_id'])) continue;
        try {
            $credita->execute([$premio, (int)$g['user_id']]);
            $nomeDe->execute([(int)$g['user_id']]);
            $n = $nomeDe->fetchColumn();
            if ($n) $nomes[] = $n;
        } catch (Throwable $e) {
            error_log('[quiz] creditar ' . $g['user_id'] . ': ' . $e->getMessage());
        }
    }

    $pdo->prepare("UPDATE bot_quiz_rodadas SET fechada_em = NOW(), vencedora = ? WHERE id = ?")
        ->execute([$vencedora, $rodadaId]);

    // ── O texto do resultado ─────────────────────────────────────────────
    $ops = [1 => $r['op1'], 2 => $r['op2'], 3 => $r['op3'], 4 => $r['op4']];
    $barra = function (int $n) use ($totalVotos): string {
        if ($totalVotos === 0) return '';
        $blocos = (int)round($n / $totalVotos * 10);
        return str_repeat('█', $blocos) . str_repeat('░', 10 - $blocos);
    };

    $txt = ($r['tipo'] === 'certa' ? "✅ *Resposta* — " : "🏆 *Resultado* — ")
         . $r['texto'] . "\n\n";
    foreach ($ops as $i => $op) {
        $marca = $i === $vencedora ? '👉 ' : '     ';
        $txt .= sprintf("%s*%d.* %s\n     %s %d voto%s\n", $marca, $i, $op,
                        $barra($contagem[$i]), $contagem[$i], $contagem[$i] === 1 ? '' : 's');
    }

    if ($totalVotos === 0) {
        return $txt . "\nNinguém votou desta vez.";
    }

    if (!empty($r['explicacao'])) $txt .= "\n_" . $r['explicacao'] . "_\n";

    $txt .= "\n";
    if ($nomes) {
        $txt .= count($nomes) === 1
            ? '*' . $nomes[0] . '* levou *' . $premio . "* moedas."
            : '*' . count($nomes) . '* acertaram e levaram *' . $premio . "* moedas cada:\n"
              . implode(' · ', $nomes);
    } else {
        $txt .= $r['tipo'] === 'certa'
            ? 'Ninguém acertou desta vez.'
            : 'Ninguém votou na vencedora.';
    }
    return $txt;
}

/** Fecha tudo que já passou do prazo. Devolve [[grupo_jid, texto], ...]. */
function quizFecharVencidas(PDO $pdo): array
{
    quizGarantirTabelas($pdo);
    $st = $pdo->query("SELECT id, grupo_jid FROM bot_quiz_rodadas
                       WHERE fechada_em IS NULL AND fecha_em <= NOW()");
    $saida = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $txt = quizFechar($pdo, (int)$r['id']);
        if ($txt !== null) $saida[] = [$r['grupo_jid'], $txt];
    }
    return $saida;
}

/**
 * Sorteia a pergunta do dia e abre a rodada. Devolve o texto a postar, ou null.
 *
 * Pergunta que já foi ao ar NÃO volta. Antes havia um plano B que reciclava as
 * mais antigas quando as inéditas acabassem, pra o quiz nunca parar — mas
 * repetir uma pergunta é pior do que ficar um dia sem: quem já respondeu sabe
 * a resposta, e o prêmio vira sorteio pra quem tem memória boa. Acabou o
 * banco, o quiz cala e o admin cadastra mais.
 */
function quizAbrir(PDO $pdo, string $grupoPadrao): ?array
{
    quizGarantirTabelas($pdo);

    $id = (int)$pdo->query("SELECT id FROM bot_quiz_perguntas
                            WHERE ativa = 1 AND usada_em IS NULL
                            ORDER BY RAND() LIMIT 1")->fetchColumn();
    if (!$id) return null;

    return quizAbrirPergunta($pdo, $id, $grupoPadrao);
}

/**
 * Abre a rodada de UMA pergunta escolhida a dedo.
 *
 * É por aqui que passa tanto o sorteio do dia quanto o "criar e enviar agora"
 * da tela do admin — a montagem do texto e a marcação de usada ficam num lugar
 * só, senão a pergunta enviada à mão sairia com outra cara e, pior, poderia
 * escapar de ser marcada e voltar no sorteio de amanhã.
 */
function quizAbrirPergunta(PDO $pdo, int $perguntaId, string $grupoPadrao): ?array
{
    quizGarantirTabelas($pdo);

    $st = $pdo->prepare("SELECT * FROM bot_quiz_perguntas WHERE id = ?");
    $st->execute([$perguntaId]);
    $p = $st->fetch(PDO::FETCH_ASSOC);
    if (!$p) return null;

    // A pergunta escolhe o grupo; sem escolha, vai pro padrão.
    $grupoJid = trim((string)($p['grupo_jid'] ?? '')) ?: $grupoPadrao;
    if ($grupoJid === '') return null;

    // Já tem rodada aberta NESSE grupo? Não empilha — mas a pergunta não é
    // marcada como usada, então ela continua na fila pra amanhã.
    if (quizRodadaAberta($pdo, $grupoJid)) return null;

    $premio = (int)($p['premio'] ?? 0) ?: BOT_QUIZ_PREMIO;
    $fecha = date('Y-m-d H:i:s', time() + BOT_QUIZ_MINUTOS * 60);
    $pdo->prepare("INSERT INTO bot_quiz_rodadas (pergunta_id, grupo_jid, fecha_em, premio) VALUES (?,?,?,?)")
        ->execute([(int)$p['id'], $grupoJid, $fecha, $premio]);
    $pdo->prepare("UPDATE bot_quiz_perguntas SET usada_em = NOW() WHERE id = ?")->execute([(int)$p['id']]);

    $cabeca = $p['tipo'] === 'certa'
        ? "🧠 *QUIZ DO DIA*"
        : "🔥 *QUEM FOI MELHOR?*";
    $regra = $p['tipo'] === 'certa'
        ? 'Responda com */1*, */2*, */3* ou */4*.'
        : 'Responda com */1* a */4*. Vence a mais votada — quem votar nela leva as moedas.';

    $texto = "{$cabeca}\n\n{$p['texto']}\n\n"
           . "*1.* {$p['op1']}\n*2.* {$p['op2']}\n*3.* {$p['op3']}\n*4.* {$p['op4']}\n\n"
           . "{$regra}\n"
           // A hora do fechamento, não "em 30 min": quem lê a mensagem às
           // 10:47 quer saber que tem 13 minutos, e a conta é do texto fazer.
           // E a regra do voto único vai junto: ela precisa ser lida ANTES de
           // alguém votar, porque quem votar duas vezes não recebe aviso.
           . '_Vale ' . $premio . ' moedas no FBA Games · um voto por pessoa, '
           . 'o primeiro é o que conta · resultado às ' . date('H:i', strtotime($fecha)) . '._';

    // Devolve o grupo junto: quem chama não sabia pra onde ia, porque quem
    // decide isso é a pergunta.
    return ['grupo' => $grupoJid, 'texto' => $texto];
}
