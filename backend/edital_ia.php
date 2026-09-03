<?php
/**
 * RESPONDER DÚVIDAS DO EDITAL, COM O EDITAL NA MÃO.
 *
 * O GM não pergunta "artigo 41" — ele pergunta "e se eu estourar o cap?". Isso
 * não é busca por palavra: é ler o edital inteiro e responder o que acontece.
 * Por isso aqui tem um modelo lendo o texto, e não um índice.
 *
 * A REGRA DA CASA É NÃO INVENTAR. O edital vai inteiro no system, a resposta
 * tem que citar o artigo, e quando a resposta não está lá o certo é dizer que
 * não está — um bot que chuta regra de liga é pior que um bot que não responde,
 * porque a pessoa age achando que está amparada.
 *
 * HTTP na unha, e não o SDK oficial: o `vendor/` deste projeto foi instalado à
 * mão no servidor e não é versionado, então uma dependência nova não chega lá
 * pelo git push que faz o deploy. Todo o resto do projeto que fala com fora
 * (WhatsApp, push, NBA) usa curl_init do mesmo jeito.
 */

require_once __DIR__ . '/edital_texto.php';

/**
 * DOIS PROVEDORES, E O GEMINI VEM PRIMEIRO.
 *
 * A liga não vai pagar por isso. O free tier do Gemini responde de graça e com
 * folga pro tamanho da FBA: o edital inteiro dá ~17,5 mil tokens de entrada e
 * cabe tranquilo na janela, e o limite diário é de centenas de perguntas.
 *
 * O modelo pode ser trocado pela variável EDITAL_IA_MODELO, sem deploy — e é
 * bom que dê, porque o Google aposenta modelo pra conta nova sem aviso: o
 * `gemini-2.5-flash` que estava aqui respondeu 404 dizendo "no longer
 * available to new users" no dia em que a chave nova foi criada.
 *
 * O caminho da Anthropic continua aqui e é usado se a chave dela estiver
 * configurada e a do Gemini não. Quem escolhe é qual chave existe no ambiente,
 * não uma opção em tela: é decisão de quem administra o servidor.
 */
const EDITAL_IA_MODELO_GEMINI    = 'gemini-3.1-flash-lite';
const EDITAL_IA_MODELO_ANTHROPIC = 'claude-opus-5';

/**
 * A fila de modelos, na ordem em que são tentados.
 *
 * O free tier vive dando 503 "high demand" — medido: numa mesma bateria de
 * testes o mesmo modelo respondeu em 2,6s e, no pedido seguinte, recusou. Um
 * bot que responde "tenta de novo" metade das vezes não serve, então o pedido
 * cai pro próximo da fila em vez de desistir.
 *
 * O primeiro é o mais rápido e o que melhor respondeu ao edital nos testes; os
 * outros entram só quando ele não está disponível.
 */
const EDITAL_IA_MODELOS_GEMINI = [
    'gemini-3.1-flash-lite',
    'gemini-3.5-flash-lite',
    'gemini-3.7-flash',
    'gemini-3.6-flash',
];

/**
 * TRAVA DE USO, do nosso lado.
 *
 * O Google não tem botão de "nunca cobrar": orçamento lá só dispara alerta por
 * e-mail, não corta o serviço. Então o corte é aqui — passou da conta do dia,
 * o bot para de perguntar e avisa, em vez de continuar gastando.
 *
 * Serve pras duas coisas: se um dia houver faturamento, é teto de gasto; sem
 * faturamento, evita queimar a cota gratuita de manhã e ficar sem à tarde.
 */
const EDITAL_IA_LIMITE_DIA = 150;

/* Teto de resposta. Resposta de grupo de WhatsApp é curta — mas no Gemini o
   raciocínio do modelo sai DESTE mesmo orçamento, e o 2.5-flash não deixa
   desligar (o mínimo é "low"). Apertado demais, a resposta volta vazia com
   finishReason MAX_TOKENS: o modelo gastou tudo pensando. Por isso a folga. */
const EDITAL_IA_MAX_TOKENS = 1200;
const EDITAL_IA_MAX_TOKENS_GEMINI = 4000;
/* Desistir rapido e tentar outro vale mais que esperar.
   Medido: um modelo saudavel responde em 1 a 5 segundos; passando disso ele
   esta congestionado e vai estourar o timeout de qualquer jeito. Entao as
   primeiras tentativas sao curtas e so a ULTIMA ganha folga, que e quando nao
   ha mais pra quem recorrer. */
const EDITAL_IA_TIMEOUT          = 15;
const EDITAL_IA_TIMEOUT_ULTIMA   = 35;

/**
 * Uma chave, de onde ela estiver: variável de ambiente ou `config.php`.
 *
 * O ambiente vem primeiro, que é o certo. Mas na Hostinger definir variável de
 * ambiente pra PHP é passo de painel que se perde na próxima migração, e o
 * `backend/config.php` já existe lá, já guarda senha de banco e NÃO é
 * versionado (está no .gitignore) — é onde o resto do projeto guarda segredo.
 *
 * Nunca no config.sample nem no config.local: esses vão pro git.
 */
function editalIaSegredo(string $nome): string
{
    $doAmbiente = trim((string)(getenv($nome) ?: ''));
    if ($doAmbiente !== '') return $doAmbiente;

    try {
        require_once __DIR__ . '/helpers.php';
        $cfg = loadConfig();
        $ia = $cfg['ia'] ?? [];
        return trim((string)($ia[$nome] ?? ''));
    } catch (Throwable $e) {
        return '';
    }
}

function editalIaChaveGemini(): string
{
    return editalIaSegredo('GEMINI_API_KEY');
}

function editalIaChave(): string
{
    return editalIaSegredo('ANTHROPIC_API_KEY');
}

/** Qual provedor atende agora: 'gemini', 'anthropic' ou null. */
function editalIaProvedor(): ?string
{
    if (editalIaChaveGemini() !== '') return 'gemini';
    if (editalIaChave() !== '')       return 'anthropic';
    return null;
}

/** O modelo em uso, com a variável de ambiente podendo trocar sem deploy. */
function editalIaModelo(string $provedor): string
{
    $env = trim((string)(getenv('EDITAL_IA_MODELO') ?: ''));
    if ($env !== '') return $env;
    return $provedor === 'gemini' ? EDITAL_IA_MODELO_GEMINI : EDITAL_IA_MODELO_ANTHROPIC;
}

function editalIaLigada(): bool
{
    return editalIaProvedor() !== null;
}

/** Quantas perguntas cabem por dia. Zero ou negativo = sem trava. */
function editalIaLimiteDia(): int
{
    $env = trim((string)(getenv('EDITAL_IA_LIMITE_DIA') ?: ''));
    return $env !== '' ? (int)$env : EDITAL_IA_LIMITE_DIA;
}

function editalIaGarantirTabelaUso(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS edital_ia_uso (
            dia DATE NOT NULL PRIMARY KEY,
            chamadas INT NOT NULL DEFAULT 0,
            tokens_entrada BIGINT NOT NULL DEFAULT 0,
            tokens_saida BIGINT NOT NULL DEFAULT 0,
            atualizado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;");
    } catch (Throwable $e) {
        error_log('[edital_ia] tabela de uso: ' . $e->getMessage());
    }
    $feito = true;
}

/** Quantas perguntas já foram feitas hoje. */
function editalIaUsoDeHoje(PDO $pdo): int
{
    editalIaGarantirTabelaUso($pdo);
    try {
        $st = $pdo->prepare('SELECT chamadas FROM edital_ia_uso WHERE dia = CURDATE()');
        $st->execute();
        return (int)($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * Marca mais uma pergunta no contador do dia.
 *
 * Conta a CHAMADA, e não o sucesso: pedido que falhou no meio do caminho
 * também consumiu cota lá do outro lado.
 */
function editalIaRegistrarUso(PDO $pdo, int $entrada = 0, int $saida = 0): void
{
    editalIaGarantirTabelaUso($pdo);
    try {
        $pdo->prepare('INSERT INTO edital_ia_uso (dia, chamadas, tokens_entrada, tokens_saida)
                       VALUES (CURDATE(), 1, ?, ?)
                       ON DUPLICATE KEY UPDATE chamadas = chamadas + 1,
                                               tokens_entrada = tokens_entrada + VALUES(tokens_entrada),
                                               tokens_saida = tokens_saida + VALUES(tokens_saida)')
            ->execute([$entrada, $saida]);
    } catch (Throwable $e) {
        error_log('[edital_ia] registrar uso: ' . $e->getMessage());
    }
}

/**
 * COMO A LIGA ESTÁ CONFIGURADA HOJE, lido do banco.
 *
 * O edital é um PDF: ele congela no dia em que foi escrito, e a liga continua
 * andando. A ROOKIE é o exemplo — o edital dela fala em 10 temporadas por
 * sprint, e no app são 15. Sem estes números o bot repetia o PDF com toda a
 * confiança do mundo e mandava o GM planejar a franquia errado.
 *
 * Aqui vai o que o SISTEMA diz, que é o que de fato acontece quando o GM
 * clica. Onde os dois discordam, manda este bloco.
 */
function editalIaFatosDoApp(PDO $pdo, string $league): string
{
    // EDITAL_LIGAS_SEM_MOEDA mora no guia, que não é carregado por este
    // arquivo — sem isto a função morria no meio e o bloco saía vazio.
    require_once __DIR__ . '/edital_guia.php';

    $l = [];
    try {
        $st = $pdo->prepare('SELECT c.max_seasons, s.cap_min, s.cap_max, s.cap_mode,
                                    s.max_trades, s.trades_enabled, s.fa_enabled,
                                    (SELECT COUNT(*) FROM teams t WHERE t.league = c.league) AS times
                               FROM league_sprint_config c
                          LEFT JOIN league_settings s ON s.league = c.league
                              WHERE c.league = ?');
        $st->execute([$league]);
        $c = $st->fetch(PDO::FETCH_ASSOC);
        if (!$c) return '';

        $l[] = "COMO A {$league} ESTÁ CONFIGURADA NO APP HOJE";
        $l[] = '(dados lidos do sistema agora; onde isto discordar do edital, VALE ISTO)';
        $l[] = '';
        $l[] = "- Times na liga: {$c['times']}";
        $l[] = "- Temporadas por sprint: {$c['max_seasons']}";

        /* O cap muda de natureza entre as ligas, e confundir os dois é o erro
           mais comum de quem vem de outra liga. Fora da ELITE ele soma os
           CAP_TOP_N melhores, e não o elenco inteiro — quem acha que conta
           todo mundo calcula errado quanto tem de espaço. O número sai da
           constante que o app usa de verdade pra somar. */
        require_once __DIR__ . '/helpers.php';
        $l[] = ($c['cap_mode'] ?? '') === 'salary'
            ? "- Cap: por SALÁRIO (folha do elenco), de {$c['cap_min']} a {$c['cap_max']}"
            : "- Cap: soma do OVR dos " . CAP_TOP_N . " MELHORES do elenco (não é o elenco todo), "
              . "de {$c['cap_min']} a {$c['cap_max']}";

        $l[] = "- Trocas por temporada: {$c['max_trades']}";
        $l[] = '- Trocas agora: ' . (!empty($c['trades_enabled']) ? 'ABERTAS' : 'FECHADAS');
        $l[] = '- Free agency agora: ' . (!empty($c['fa_enabled']) ? 'ABERTA' : 'FECHADA');
        $l[] = '- Moedas: ' . (in_array($league, EDITAL_LIGAS_SEM_MOEDA, true)
            ? 'a liga NÃO usa moedas (o edital ainda fala delas; não valem mais)'
            : 'a liga usa moedas na free agency e no leilão');

        $st = $pdo->prepare("SELECT sp.sprint_number, MAX(se.season_number) AS temp
                               FROM sprints sp LEFT JOIN seasons se ON se.sprint_id = sp.id
                              WHERE sp.league = ? AND sp.status = 'active'
                           GROUP BY sp.sprint_number LIMIT 1");
        $st->execute([$league]);
        if ($s = $st->fetch(PDO::FETCH_ASSOC)) {
            $l[] = "- Momento: sprint {$s['sprint_number']}, temporada {$s['temp']} de {$c['max_seasons']}";
        }
    } catch (Throwable $e) {
        error_log('[edital_ia] fatos do app: ' . $e->getMessage());
        return '';
    }
    return implode("\n", $l);
}

/**
 * OS DETALHES DE OPERAÇÃO QUE NÃO ESTÃO EM LUGAR NENHUM.
 *
 * Nem no edital (que fala da regra, não da tela) nem no banco (que guarda o
 * estado, não o macete). É o que o GM veterano sabe e o novato descobre
 * perguntando no grupo — que é exatamente o que este bot existe pra evitar.
 *
 * Lista pra crescer: cada dúvida que aparecer duas vezes no grupo vira linha
 * aqui. Só entra o que foi conferido no código, e não o que se supõe.
 */
function editalIaDetalhesDoApp(): string
{
    return implode("\n", [
        'DETALHES DE COMO O APP FUNCIONA NA PRÁTICA',
        '(não está no edital; é o funcionamento real das telas)',
        '',
        '- Aposentar jogador: a opção só aparece pra quem tem MAIS DE 35 anos, ou seja,',
        '  36 em diante. Não aparecendo, é porque o atleta está com menos que isso',
        '  cadastrado — corrija a idade dele em Meu Elenco (editar jogador) e a opção surge.',
        '  O sistema recusa aposentadoria de quem tem 35 ou menos, então não adianta insistir.',
    ]);
}

/** As telas do site, pro bot saber ONDE se faz cada coisa. */
function editalIaComoUsarOApp(): string
{
    try {
        require_once __DIR__ . '/edital_guia.php';
        $l = ['ONDE SE FAZ CADA COISA NO SITE (fbabrasil.com.br)', ''];
        foreach (editalPaginas() as $grupo => $telas) {
            foreach ($telas as [$url, $nome, $desc]) $l[] = "- {$nome} ({$url}): {$desc}";
        }
        return implode("\n", $l);
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * O que o modelo pode e não pode fazer.
 *
 * Escrito curto de propósito: cada regra aqui é uma coisa que dá errado no
 * grupo se faltar. O tom é o de um GM veterano respondendo no grupo, porque é
 * onde a resposta vai cair — e não o de quem lê regulamento em voz alta.
 */
function editalIaInstrucoes(string $league): string
{
    return implode("\n", [
        "Você é o assistente da FBA Brasil, uma liga de fantasy de basquete no NBA 2K.",
        "Quem pergunta é um GM da liga {$league}, no grupo de WhatsApp. Você ajuda ele a",
        "entender as regras E a usar o site — as duas coisas, porque metade das dúvidas",
        "é 'onde eu faço isso?' e não 'o que diz a regra?'.",
        '',
        'O QUE VALE, EM ORDEM:',
        '1. Os dados do app (bloco "COMO A LIGA ESTÁ CONFIGURADA"). São o que acontece de verdade.',
        '2. O edital, pro que os dados não cobrem — que é a maior parte.',
        '- O edital é um PDF antigo e tem ponto desatualizado. Quando ele discordar dos dados do',
        '  app, vale o app: responda o número certo e avise que o edital ainda está com o antigo.',
        '- Fora isso, não invente. Não sabendo, diga que não sabe e mande falar com a organização.',
        '  Chutar regra de liga é pior que não responder: a pessoa age achando que está amparada.',
        '',
        'COMO FALAR:',
        '- Como um GM veterano explicando pro novato, não como advogado lendo o regulamento.',
        '- Traduza o juridiquês. "Sanção pecuniária progressiva" vira "a multa aumenta a cada vez".',
        '- Diga onde se faz a coisa quando fizer sentido: "isso é na aba Trades".',
        '- Cite o artigo entre parênteses só quando for regra que gera punição ou dúvida: (Art. 41).',
        '  Não precisa citar artigo pra explicar como usar uma tela.',
        '',
        'FORMATO:',
        '- Português do Brasil, direto, no máximo 6 linhas.',
        '- Sem saudação e sem "espero ter ajudado".',
        '- WhatsApp: *negrito* com um asterisco só. Nada de markdown de título, nada de tabela.',
        '- Quando a resposta tiver passos ou condições, use hífen no começo da linha.',
    ]);
}

/**
 * Manda a pergunta pro modelo com o edital no contexto.
 *
 * @return array{ok:bool,resposta:?string,erro:?string,uso:?array}
 */
function editalIaPerguntar(PDO $pdo, string $league, string $pergunta): array
{
    $erro = fn(string $m) => ['ok' => false, 'resposta' => null, 'erro' => $m, 'uso' => null];

    $provedor = editalIaProvedor();
    if ($provedor === null) return $erro('A consulta ao edital ainda não foi ligada aqui.');

    $pergunta = trim($pergunta);
    if (mb_strlen($pergunta) < 5)  return $erro('Escreve a dúvida junto do comando. Ex.: /edital posso trocar jogador emprestado?');
    if (mb_strlen($pergunta) > 500) return $erro('Pergunta muito longa — resume em uma frase.');

    $edital = editalTexto($pdo, $league);
    if ($edital === null) return $erro("Não achei o edital da {$league} pra consultar.");

    /* A TRAVA VEM ANTES DE GASTAR. Conferir depois de perguntar não seria
       trava nenhuma: o pedido já teria saído. */
    $limite = editalIaLimiteDia();
    if ($limite > 0 && editalIaUsoDeHoje($pdo) >= $limite) {
        return $erro('O limite de consultas de hoje já foi usado. '
                   . 'Amanhã volta, ou pergunta pra organização.');
    }

    if ($provedor === 'gemini') {
        return editalIaPerguntarGemini($pdo, $league, $edital, $pergunta, $erro);
    }

    $chave = editalIaChave();

    /* O EDITAL VAI EM BLOCO PRÓPRIO E CACHEADO.
       Ele é o mesmo texto em toda pergunta e responde por quase todo o custo
       do pedido; sem o cache, cada dúvida no grupo paga o edital inteiro de
       novo. O bloco das instruções vem DEPOIS, e é o último ponto de cache:
       tudo que varia (a pergunta) fica fora do prefixo cacheado. */
    $payload = [
        'model'      => editalIaModelo('anthropic'),
        'max_tokens' => EDITAL_IA_MAX_TOKENS,
        // Dúvida de regulamento não pede raciocínio longo, e o bot responde no
        // meio de uma conversa: esforço baixo é resposta boa e rápida.
        'output_config' => ['effort' => 'low'],
        'system' => [
            [
                'type' => 'text',
                'text' => "EDITAL DA LIGA {$league} — FBA BRASIL\n\n" . $edital,
                'cache_control' => ['type' => 'ephemeral'],
            ],
            [
                'type' => 'text',
                'text' => editalIaInstrucoes($league),
                'cache_control' => ['type' => 'ephemeral'],
            ],
        ],
        'messages' => [
            ['role' => 'user', 'content' => $pergunta],
        ],
    ];

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => EDITAL_IA_TIMEOUT,
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-api-key: ' . $chave,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $corpo  = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falha  = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $falha !== '') {
        error_log('[edital_ia] curl: ' . $falha);
        return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
    }

    $j = json_decode((string)$corpo, true);
    if ($status !== 200 || !is_array($j)) {
        error_log('[edital_ia] http ' . $status . ': ' . mb_substr((string)$corpo, 0, 400));
        return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
    }

    /* Recusa do modelo chega com HTTP 200 e stop_reason "refusal" — o content
       vem vazio, então ler o texto sem conferir isto devolveria string vazia
       como se fosse resposta. */
    if (($j['stop_reason'] ?? '') === 'refusal') {
        return $erro('Não consegui responder essa. Fala com a organização.');
    }

    $texto = '';
    foreach (($j['content'] ?? []) as $bloco) {
        if (($bloco['type'] ?? '') === 'text') $texto .= $bloco['text'];
    }
    $texto = trim($texto);
    if ($texto === '') return $erro('Vieram só linhas vazias. Tenta reformular a pergunta.');

    return ['ok' => true, 'resposta' => $texto, 'erro' => null, 'uso' => $j['usage'] ?? null];
}

/**
 * A mesma pergunta, pelo Gemini.
 *
 * O formato é outro: o system vai em `system_instruction`, a pergunta em
 * `contents`, e o teto de saída em `generationConfig.maxOutputTokens`.
 *
 * TENTA MAIS DE UM MODELO. O free tier responde 503 "high demand" com
 * frequência — medido: o mesmo modelo respondeu em 2,6s e recusou o pedido
 * seguinte. Como a recusa é do modelo e não da chave, o pedido cai pro próximo
 * da fila; só depois de todos recusarem é que o GM recebe um "não deu".
 *
 * Não há cache do edital como na Anthropic: no free tier o cache é implícito e
 * não há custo por token pra economizar.
 *
 * @param callable $erro Fábrica do retorno de erro, do chamador.
 */
function editalIaPerguntarGemini(PDO $pdo, string $league, string $edital, string $pergunta, callable $erro): array
{
    /* Três blocos, nesta ordem: o edital (o grosso), o que o app diz hoje, e
       as instruções por último. O que manda vem depois do que é consultado —
       assim a regra de precedência é lida com os dois já na mão. */
    $partes = [['text' => "EDITAL DA LIGA {$league} — FBA BRASIL\n\n" . $edital]];

    $fatos = editalIaFatosDoApp($pdo, $league);
    if ($fatos !== '') $partes[] = ['text' => $fatos];

    $telas = editalIaComoUsarOApp();
    if ($telas !== '') $partes[] = ['text' => $telas];

    $partes[] = ['text' => editalIaDetalhesDoApp()];

    $partes[] = ['text' => editalIaInstrucoes($league)];

    $payload = [
        'system_instruction' => ['parts' => $partes],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $pergunta]]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => EDITAL_IA_MAX_TOKENS_GEMINI,
            // Regulamento é leitura, não criação: o modelo deve repetir o que
            // está escrito, e não achar uma forma nova de dizer.
            'temperature'     => 0.2,

            /* SEM thinkingConfig, de propósito.
               Testado contra a API: `thinking_level` não existe no v1beta, e
               `thinkingConfig.thinkingBudget = 0` é recusado com 400 pelos
               modelos 3.x — neles o raciocínio não desliga. Como ele sai do
               mesmo orçamento da resposta, o teto acima é generoso: apertado,
               o modelo gasta tudo pensando e devolve texto vazio. */
        ],
    ];
    $corpoJson = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    // O modelo escolhido à mão manda; senão, a fila padrão.
    $escolhido = trim((string)(getenv('EDITAL_IA_MODELO') ?: ''));
    $fila = $escolhido !== '' ? [$escolhido] : EDITAL_IA_MODELOS_GEMINI;

    $ultimoErro = null;
    foreach ($fila as $i => $modelo) {
        // O primeiro tem o tempo todo; os seguintes são plano B e não podem
        // deixar o GM esperando um minuto e meio cada.
        $ultima  = ($i === count($fila) - 1);
        $timeout = $ultima ? EDITAL_IA_TIMEOUT_ULTIMA : EDITAL_IA_TIMEOUT;

        /* CONTA ANTES DE GASTAR, e não depois.
           A chamada pode levar dezenas de segundos, e nesse tempo a conexão
           com o MySQL cai — medido: depois de um pedido de 40s, tanto gravar
           quanto ler o contador falhavam calados e ele voltava a zero. Contar
           antes também é o que faz a trava valer: a cota é reservada, não
           registrada no fim.

           Conta a CHAMADA, e não o sucesso: pedido que falhou no meio do
           caminho consumiu cota do outro lado do mesmo jeito. */
        editalIaRegistrarUso($pdo);

        $ch = curl_init('https://generativelanguage.googleapis.com/v1beta/models/'
                      . rawurlencode($modelo) . ':generateContent');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            // A chave vai no cabeçalho, e não na query: URL vaza em log de
            // acesso, de proxy e de erro.
            CURLOPT_HTTPHEADER     => [
                'content-type: application/json',
                'x-goog-api-key: ' . editalIaChaveGemini(),
            ],
            CURLOPT_POSTFIELDS => $corpoJson,
        ]);
        $corpo  = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $falha  = curl_error($ch);
        curl_close($ch);

        if ($corpo === false || $falha !== '') {
            error_log("[edital_ia/gemini] {$modelo} curl: " . $falha);
            $ultimoErro = 'Não consegui consultar o edital agora. Tenta de novo em um minuto.';
            continue;
        }

        $j = json_decode((string)$corpo, true);

        /* O teto diário é o erro esperado do free tier, e merece resposta
           própria: "tenta de novo em um minuto" mandaria a pessoa insistir à
           toa até o dia seguinte. Não adianta trocar de modelo — a cota é da
           chave. */
        if ($status === 429) {
            error_log("[edital_ia/gemini] {$modelo} 429: " . editalIaSemSegredo((string)$corpo));
            return $erro('O limite de consultas de hoje acabou. Tenta mais tarde, '
                       . 'ou pergunta pra organização.');
        }

        // 503 é fila do modelo, e 404 é modelo que saiu do ar pra contas novas:
        // nos dois casos o próximo da fila pode atender.
        if ($status === 503 || $status === 404 || $status >= 500) {
            error_log("[edital_ia/gemini] {$modelo} http {$status}, tentando o próximo");
            $ultimoErro = 'Não consegui consultar o edital agora. Tenta de novo em um minuto.';
            continue;
        }

        if ($status !== 200 || !is_array($j)) {
            error_log("[edital_ia/gemini] {$modelo} http {$status}: " . editalIaSemSegredo((string)$corpo));
            return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
        }

        // Bloqueio por filtro de conteúdo vem com 200 e sem candidato nenhum.
        if (!empty($j['promptFeedback']['blockReason'])) {
            return $erro('Não consegui responder essa. Fala com a organização.');
        }

        $cand = $j['candidates'][0] ?? null;
        $texto = '';
        foreach (($cand['content']['parts'] ?? []) as $parte) {
            if (isset($parte['text'])) $texto .= $parte['text'];
        }
        $texto = trim($texto);

        if ($texto === '') {
            $motivo = (string)($cand['finishReason'] ?? '');
            error_log("[edital_ia/gemini] {$modelo} resposta vazia, finishReason=" . $motivo);
            return $erro($motivo === 'MAX_TOKENS'
                ? 'A resposta ficou longa demais e foi cortada. Tenta uma pergunta mais específica.'
                : 'Vieram só linhas vazias. Tenta reformular a pergunta.');
        }

        return ['ok' => true, 'resposta' => $texto, 'erro' => null,
                'uso' => ($j['usageMetadata'] ?? []) + ['modelo' => $modelo]];
    }

    return $erro($ultimoErro ?? 'Não consegui consultar o edital agora.');
}

/**
 * O corpo do erro sem a chave dentro.
 *
 * O Google DEVOLVE a chave na mensagem de erro ("Consumer 'api_key:AQ...' has
 * been suspended"), e o log de erro do site não é lugar de segredo: quem tem
 * acesso ao log passa a ter a chave. Some também o que parecer chave em
 * qualquer outro formato.
 */
function editalIaSemSegredo(string $texto, int $limite = 400): string
{
    $texto = preg_replace('/(api_key:)\s*\S+/i', '$1[oculta]', $texto);
    $texto = preg_replace('/\b(AIza|AQ\.)[A-Za-z0-9._\-]{10,}/', '[chave oculta]', $texto);
    return mb_substr($texto, 0, $limite);
}
