<?php
/**
 * ── O BOT CONVERSANDO COMO GENTE ──────────────────────────────────────
 *
 * Quando marcam o bot (@) ou respondem uma mensagem dele, ele responde como
 * alguém do grupo: curto, na língua da liga, sabendo com quem está falando.
 *
 * NÃO CONFUNDIR COM O /duvida (backend/edital_ia.php). Aquele é o professor do
 * edital: responde longo, sempre sobre regra, e foi desligado em 19/10/2026
 * justamente por isso. Este aqui é o oposto — duas linhas, resenha, e o assunto
 * é o que a pessoa trouxe. Os dois compartilham só o motor de chamada do
 * modelo (editalIaChamarGemini), que já tem fila de modelo, cota e timeout.
 *
 * O QUE FAZ ELE SOAR COMO ALGUÉM DO GRUPO são duas tabelas:
 *
 *   bot_personalidades  quem é cada GM — como escreve, do que fala, o que o
 *                       irrita, de que dá pra zoar. Uma linha por pessoa.
 *   bot_folclore        o que a liga inteira sabe: que "assalto" é elogio,
 *                       que "kitar" virou verbo por causa do Kevyn, que o
 *                       campeão atual é osso duro de zoar.
 *
 * As duas nascem de uma leitura dos chats e são reescritas por
 * cron/bot-perfis.php. O dono audita em marcos-dev.php.
 *
 * @see api/whatsapp-webhook.php  as portas (@ e resposta) que chamam isto
 * @see backend/edital_ia.php     o motor de modelo, compartilhado
 */

require_once __DIR__ . '/edital_ia.php';

/** Teto de caracteres da resposta. Passou disso, não é mais resenha, é textão. */
const PAPO_MAX_CARACTERES = 320;

/** Respostas de papo por grupo por hora. Trava de custo e de enxurrada. */
const PAPO_LIMITE_HORA = 25;

/**
 * As ligas em que o papo está ligado.
 *
 * Começou só na ELITE em 09/11/2026 — uma liga pra ver como o pessoal reagia — e
 * abriu pras outras três no mesmo dia. Tirar uma liga daqui é o jeito de calar o
 * bot num grupo sem mexer em mais nada: comando continua, conversa para.
 */
const PAPO_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

if (!function_exists('papoGarantirTabelas')) {
    /**
     * As duas tabelas, criadas na primeira chamada. Idempotente.
     *
     * `resumo` é o que vai pro prompt e precisa caber nele: três ou quatro
     * linhas, não o dossiê inteiro. `zoeira` é separado de propósito — é a
     * única parte que o bot pode usar pra alfinetar, e deixar num campo à
     * parte torna possível auditar só ela.
     */
    function papoGarantirTabelas(PDO $pdo): void
    {
        static $feito = false;
        if ($feito || $pdo->inTransaction()) return;
        $feito = true;

        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_personalidades (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            user_id       INT NULL,
            liga          VARCHAR(20) NOT NULL,
            apelido_zap   VARCHAR(60) NOT NULL,
            time_nome     VARCHAR(80) NULL,
            resumo        TEXT NOT NULL,
            zoeira        TEXT NULL,
            fonte         VARCHAR(20) NOT NULL DEFAULT 'seed',
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_papo_pessoa (liga, apelido_zap),
            INDEX idx_papo_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        /* O PAPO É POR GRUPO, NÃO POR LIGA.
           Cada liga tem quatro grupos — Chat Off, Gameplay, Trade Block e ADM — e
           conversa fiada só cabe no primeiro. Bot entrando na brincadeira no Trade
           Block atrapalha quem está anunciando troca, e no ADM é pior ainda.
           A coluna fica no cadastro de grupos pra ligar e desligar sem deploy. */
        try {
            if (!$pdo->query("SHOW COLUMNS FROM whatsapp_grupos_comando LIKE 'papo'")->fetch()) {
                $pdo->exec("ALTER TABLE whatsapp_grupos_comando ADD COLUMN papo TINYINT(1) NOT NULL DEFAULT 0");
            }
        } catch (Throwable $e) {
            error_log('[papo] coluna papo: ' . $e->getMessage());
        }

        $pdo->exec("CREATE TABLE IF NOT EXISTS bot_folclore (
            id            INT AUTO_INCREMENT PRIMARY KEY,
            liga          VARCHAR(20) NOT NULL DEFAULT 'FBA',
            assunto       VARCHAR(80) NOT NULL,
            fato          VARCHAR(400) NOT NULL,
            fonte         VARCHAR(20) NOT NULL DEFAULT 'seed',
            atualizado_em DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            ativo         TINYINT(1) NOT NULL DEFAULT 1,
            UNIQUE KEY uq_folclore (liga, assunto)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('papoLigado')) {
    /** O papo vale nesta liga? */
    function papoLigado(?string $liga): bool
    {
        return $liga !== null && in_array(strtoupper(trim($liga)), PAPO_LIGAS, true);
    }
}

if (!function_exists('papoGrupoLiberado')) {
    /**
     * Este grupo recebe conversa, ou só comando?
     *
     * Duas chaves, e as duas precisam estar abertas: a liga (PAPO_LIGAS, no
     * código) e o grupo (`whatsapp_grupos_comando.papo`, no banco). A primeira
     * é pra desligar uma liga inteira num deploy; a segunda é pra escolher em
     * qual dos quatro grupos da liga ele conversa — e só o Chat Off nasce
     * ligado, porque Gameplay, Trade Block e ADM têm assunto marcado.
     */
    function papoGrupoLiberado(PDO $pdo, string $grupoJid, ?string $liga): bool
    {
        if (!papoLigado($liga) || $grupoJid === '') return false;
        papoGarantirTabelas($pdo);
        try {
            $st = $pdo->prepare("SELECT COALESCE(papo,0) FROM whatsapp_grupos_comando WHERE jid = ? AND ativo = 1");
            $st->execute([$grupoJid]);
            return (int)$st->fetchColumn() === 1;
        } catch (Throwable $e) {
            // Sem saber, o bot fica quieto: conversa onde não devia é pior que silêncio.
            error_log('[papo] grupo liberado: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('papoPerfilDe')) {
    /**
     * O perfil de quem mandou a mensagem, pelo telefone.
     *
     * Usa a MESMA busca dos comandos "meus" (wcAcharPeloTelefone): número
     * inteiro primeiro, sufixo de 8 dígitos como rede de segurança. Se o bot
     * reconhece o número pra dizer "seu time é o X", tem que reconhecer aqui
     * também — senão ele trata o GM como estranho no próprio grupo.
     *
     * @return array|null ['nome','time','resumo','zoeira'] ou null se não achou
     */
    function papoPerfilDe(PDO $pdo, string $digitos, string $liga): ?array
    {
        if ($digitos === '') return null;
        papoGarantirTabelas($pdo);

        $gm = null;
        try {
            $achados = wcAcharPeloTelefone(wcGmsComTelefone($pdo), $digitos);
            foreach ($achados as $a) {
                if (strtoupper((string)$a['league']) === strtoupper($liga)) { $gm = $a; break; }
            }
            $gm = $gm ?: ($achados[0] ?? null);
        } catch (Throwable $e) {
            error_log('[papo] telefone: ' . $e->getMessage());
        }
        if (!$gm) return null;

        $st = $pdo->prepare("SELECT resumo, zoeira FROM bot_personalidades
                              WHERE ativo = 1 AND liga = ? AND (user_id = ? OR apelido_zap = ?)
                           ORDER BY (user_id = ?) DESC LIMIT 1");
        $st->execute([strtoupper($liga), (int)$gm['user_id'], (string)$gm['gm'], (int)$gm['user_id']]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];

        return [
            'nome'   => (string)$gm['gm'],
            'time'   => trim((string)$gm['city'] . ' ' . (string)$gm['name']),
            'resumo' => (string)($p['resumo'] ?? ''),
            'zoeira' => (string)($p['zoeira'] ?? ''),
        ];
    }
}

if (!function_exists('papoFolclore')) {
    /** As piadas e gírias que a liga inteira entende, pro prompt. */
    function papoFolclore(PDO $pdo, string $liga): string
    {
        papoGarantirTabelas($pdo);
        try {
            $st = $pdo->prepare("SELECT assunto, fato FROM bot_folclore
                                  WHERE ativo = 1 AND liga IN ('FBA', ?)
                               ORDER BY liga = 'FBA' DESC, id LIMIT 30");
            $st->execute([strtoupper($liga)]);
            $linhas = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $linhas[] = '- ' . $r['assunto'] . ': ' . $r['fato'];
            }
            return implode("\n", $linhas);
        } catch (Throwable $e) {
            error_log('[papo] folclore: ' . $e->getMessage());
            return '';
        }
    }
}

if (!function_exists('papoDentroDoLimite')) {
    /**
     * O grupo já gastou a cota de papo da hora?
     *
     * Conta por GRUPO e não global: um grupo animado não pode calar o bot nos
     * outros. Mesma lição do freio de comandos por minuto, que já era global e
     * teve que virar por grupo.
     */
    function papoDentroDoLimite(PDO $pdo, string $grupoJid): bool
    {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM whatsapp_fila
                                  WHERE comando = 'papo' AND destino = ?
                                    AND created_at > NOW() - INTERVAL 1 HOUR");
            $st->execute([$grupoJid]);
            return (int)$st->fetchColumn() < PAPO_LIMITE_HORA;
        } catch (Throwable $e) {
            return true;   // na dúvida, deixa falar: o freio é proteção, não regra
        }
    }
}

if (!function_exists('papoInstrucoes')) {
    /**
     * O prompt. É aqui que mora a personalidade.
     *
     * O tom foi decidido pelo dono da liga em 09/11/2026: o bot entra na
     * zoeira, no mesmo nível do grupo, sem filtro de educação. O grupo se
     * trata assim entre si e um bot cerimonioso ali soa como intruso.
     *
     * AS TRÊS TRAVAS QUE FICARAM não são sobre tom, são sobre estrago:
     *
     *  1. Assunto pessoal fica fora. Saúde, família, religião, política,
     *     dinheiro. A zoeira é de basquete e de liga — é o que o grupo faz.
     *  2. Acusação não resolvida não se repete. Tem desentendimento no grupo
     *     em que um acusou o outro e não houve veredito; o bot repetir isso
     *     não é piada, é espalhar.
     *  3. Não inventa fato. Se não sabe quem ganhou, quem trocou o quê ou o
     *     que diz a regra, ele diz que não sabe. Bot que chuta número vira
     *     fonte de briga — e aí quem apanha é a organização.
     */
    function papoInstrucoes(array $quem, string $folclore): string
    {
        $l = [];
        $l[] = 'Você é o FBA Bot, e faz parte deste grupo de WhatsApp de uma liga de fantasy de basquete (a FBA).';
        $l[] = 'Você NÃO é um assistente. Você é mais um na conversa: responde curto, na mesma língua do grupo,';
        $l[] = 'e entra na brincadeira. O pessoal se trata no deboche e espera o mesmo de você.';
        $l[] = '';
        $l[] = 'COMO RESPONDER:';
        $l[] = '- Uma ou duas linhas. Nunca mais que isso. Sem lista, sem título, sem "posso ajudar".';
        $l[] = '- Português do grupo: minúscula, abreviação, gíria. Nada de linguagem de atendimento.';
        $l[] = '- Pode alfinetar, pode ironizar, pode rir da cara de quem te marcou. É o esperado aqui.';
        $l[] = '- Emoji quase nunca. No máximo um, e só quando a frase pedir mesmo.';
        $l[] = '  Nada de fechar toda resposta com o mesmo emoji — repetir 🤡 ou 😂 em toda fala';
        $l[] = '  é o jeito mais rápido de parecer robô. Na dúvida, não use nenhum.';
        $l[] = '- Se a pessoa perguntou algo de verdade, responde de verdade — curto, e depois zoa se tiver graça.';
        $l[] = '';
        $l[] = 'O QUE NÃO FAZER (isto vale mais que o tom):';
        $l[] = '- VOCÊ NÃO TEM OS DADOS DA LIGA NESTA CONVERSA. Não sabe quem foi campeão, quem trocou o que,';
        $l[] = '  quanto vale um jogador, classificação nem número nenhum. Perguntaram isso? Diga que não';
        $l[] = '  está com isso na mão e mande o comando: /jogador, /time, /cap, /trocas, /classificacao, /picks.';
        $l[] = '  Pode zoar no mesmo fôlego, mas o fato tem que ficar com o comando.';
        $l[] = '  Enrolar é pior que admitir: responder "foi a panela de sempre que ganhou" soa como informação,';
        $l[] = '  e numa liga onde panela é acusação isso vira briga de verdade. Não chute, não insinue, não sugira.';
        $l[] = '- Não fale de assunto pessoal de ninguém: saúde, família, religião, política, dinheiro. Zoeira é de basquete.';
        $l[] = '- Não repita acusação de desonestidade feita por um GM contra outro. Briga de grupo não é seu assunto.';
        $l[] = '- Não revele nem comente que você tem anotação sobre as pessoas. Você simplesmente conhece o pessoal.';

        if ($folclore !== '') {
            $l[] = '';
            $l[] = 'O QUE A LIGA INTEIRA ENTENDE (use à vontade, é a língua da casa):';
            $l[] = $folclore;
        }

        $l[] = '';
        $l[] = 'QUEM TE MARCOU AGORA:';
        $l[] = '- Nome: ' . ($quem['nome'] ?: 'não reconheci quem é');
        if (!empty($quem['time']))   $l[] = '- Time: ' . $quem['time'];
        if (!empty($quem['resumo'])) $l[] = '- Como ele é: ' . $quem['resumo'];
        if (!empty($quem['zoeira'])) $l[] = '- Dá pra pegar no pé dele por: ' . $quem['zoeira'];
        if (empty($quem['nome'])) {
            $l[] = '- Você não reconheceu o número. Trate como alguém do grupo mesmo assim, sem perguntar quem é.';
        }

        return implode("\n", $l);
    }
}

if (!function_exists('papoTextoCitado')) {
    /** O texto da mensagem que foi respondida, se houver. */
    function papoTextoCitado(array $m): string
    {
        foreach (wcContextos($m) as $ctx) {
            $citada = $ctx['quotedMessage'] ?? null;
            if (is_array($citada)) {
                $t = trim(wcTextoDaMensagem($citada));
                if ($t !== '') return $t;
            }
        }
        return '';
    }
}

if (!function_exists('papoResponderamAoPapo')) {
    /**
     * Responderam uma resposta de PAPO do bot?
     *
     * Responder qualquer mensagem do bot não pode valer: a saída de um /time é
     * uma tabela, e o grupo comenta tabela entre si o tempo todo — o bot entrava
     * na brincadeira achando que era com ele. Foi um dos motivos de a conversa ter
     * sido desligada em outubro.
     *
     * O /duvida resolvia isso com um selo 💬 no começo da resposta. Aqui não dá:
     * um selo na fala do bot denuncia que é modo especial, e a graça é justamente
     * parecer gente. Então a pergunta é feita ao contrário — o texto citado é uma
     * resposta de papo que ESTE bot deu neste grupo nas últimas horas? A fila já
     * guarda isso (`comando = 'papo'`), é só perguntar a ela.
     */
    function papoResponderamAoPapo(PDO $pdo, array $m, array $idsDoBot, string $grupoJid): bool
    {
        if (!$idsDoBot) return false;

        $citado = papoTextoCitado($m);
        if ($citado === '') return false;

        // Confere que a mensagem citada é mesmo do bot, e não de outra pessoa.
        $doBot = false;
        foreach (wcContextos($m) as $ctx) {
            foreach (['participantPn', 'participantAlt', 'participant', 'remoteJid'] as $k) {
                foreach ($idsDoBot as $id) {
                    if (wcMesmoNumero(wcDigitos($ctx[$k] ?? ''), $id)) { $doBot = true; break 3; }
                }
            }
        }
        if (!$doBot) return false;

        try {
            // Pelos primeiros 60 caracteres: o WhatsApp corta a citação longa,
            // então o texto que volta é um prefixo do que o bot mandou.
            $inicio = mb_substr($citado, 0, 60);
            $st = $pdo->prepare("SELECT 1 FROM whatsapp_fila
                                  WHERE comando = 'papo' AND destino = ?
                                    AND created_at > NOW() - INTERVAL 6 HOUR
                                    AND texto LIKE CONCAT(?, '%') LIMIT 1");
            $st->execute([$grupoJid, $inicio]);
            return (bool)$st->fetchColumn();
        } catch (Throwable $e) {
            error_log('[papo] citada: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('papoResponder')) {
    /**
     * A resposta do bot a uma menção ou a um "respondeu o bot".
     *
     * Devolve null quando é pra ficar quieto — e ficar quieto é resposta
     * legítima: liga desligada, cota estourada, modelo fora do ar. O grupo
     * seguir conversando sem o bot é melhor que o bot pedir desculpa.
     *
     * @param string      $texto   a mensagem, já sem o "@numero" do bot
     * @param string      $citada  a mensagem do bot que ele respondeu, se houver
     */
    function papoResponder(PDO $pdo, string $liga, string $texto, string $digitos,
                           string $grupoJid, string $citada = ''): ?string
    {
        $texto = trim($texto);
        if ($texto === '' || !papoLigado($liga)) return null;
        if (!editalIaLigada()) return null;
        if (!papoDentroDoLimite($pdo, $grupoJid)) {
            error_log('[papo] cota da hora estourada no grupo ' . $grupoJid);
            return null;
        }

        $liga = strtoupper(trim($liga));
        $quem = papoPerfilDe($pdo, $digitos, $liga) ?: ['nome' => '', 'time' => '', 'resumo' => '', 'zoeira' => ''];

        $pergunta = $texto;
        if ($citada !== '') {
            // O bot respondendo quem respondeu ele: sem isso a frase fica solta.
            $pergunta = "(respondendo esta mensagem sua: \"" . mb_substr($citada, 0, 300) . "\")\n" . $texto;
        }

        $payload = [
            'systemInstruction' => ['parts' => [['text' => papoInstrucoes($quem, papoFolclore($pdo, $liga))]]],
            'contents' => [['role' => 'user', 'parts' => [['text' => $pergunta]]]],
            'generationConfig' => [
                'temperature'     => 1.0,   // é conversa, não consulta: resposta igual todo dia não tem graça
                'maxOutputTokens' => 220,
            ],
        ];

        $erro = function (string $m) { error_log('[papo] ' . $m); return $m; };
        [$ok, $j] = editalIaChamarGemini($pdo, $payload, $erro);
        if (!$ok || !is_array($j)) return null;

        $saida = trim((string)($j['candidates'][0]['content']['parts'][0]['text'] ?? ''));
        if ($saida === '') return null;

        /* O MODELO ÀS VEZES ESQUECE QUE É PRA SER CURTO. O corte é no último
           fim de frase antes do teto — cortar no meio da palavra entrega que
           tem um limite ali, e aí a resposta parece defeito em vez de recado. */
        if (mb_strlen($saida) > PAPO_MAX_CARACTERES) {
            $corte = mb_substr($saida, 0, PAPO_MAX_CARACTERES);
            $ponto = max(mb_strrpos($corte, '. ') ?: 0, mb_strrpos($corte, '! ') ?: 0, mb_strrpos($corte, '? ') ?: 0);
            $saida = $ponto > 40 ? trim(mb_substr($corte, 0, $ponto + 1)) : trim($corte);
        }

        return $saida;
    }
}
