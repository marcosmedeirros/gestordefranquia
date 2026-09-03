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
 * folga pro tamanho da FBA: no `gemini-2.5-flash` são 250 perguntas por dia e
 * 10 por minuto, e o edital inteiro (~20 mil tokens) cabe tranquilo na janela.
 * Batendo no teto, `gemini-2.5-flash-lite` sobe pra 1.000 por dia — é só trocar
 * a variável de ambiente, sem mexer em código.
 *
 * O caminho da Anthropic continua aqui e é usado se a chave dela estiver
 * configurada e a do Gemini não. Quem escolhe é qual chave existe no ambiente,
 * não uma opção em tela: é decisão de quem administra o servidor.
 */
const EDITAL_IA_MODELO_GEMINI    = 'gemini-2.5-flash';
const EDITAL_IA_MODELO_ANTHROPIC = 'claude-opus-5';

/* Teto de resposta. Resposta de grupo de WhatsApp é curta — mas no Gemini o
   raciocínio do modelo sai DESTE mesmo orçamento, e o 2.5-flash não deixa
   desligar (o mínimo é "low"). Apertado demais, a resposta volta vazia com
   finishReason MAX_TOKENS: o modelo gastou tudo pensando. Por isso a folga. */
const EDITAL_IA_MAX_TOKENS = 1200;
const EDITAL_IA_MAX_TOKENS_GEMINI = 2400;
const EDITAL_IA_TIMEOUT    = 90;

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

/**
 * O que o modelo pode e não pode fazer com o edital.
 *
 * Escrito curto de propósito: cada regra aqui é uma coisa que dá errado no
 * grupo se faltar. O tom é o de um GM veterano respondendo no grupo, porque é
 * onde a resposta vai cair.
 */
function editalIaInstrucoes(string $league): string
{
    return implode("\n", [
        "Você responde dúvidas sobre o edital da liga {$league} da FBA Brasil, uma liga de fantasy de basquete.",
        "Quem pergunta é um GM, no grupo de WhatsApp da liga.",
        '',
        'REGRAS:',
        '- Responda SOMENTE com o que está no edital acima. Ele é a única fonte.',
        '- Cite o artigo entre parênteses no fim da frase que veio dele: (Art. 41).',
        '- Se a resposta não estiver no edital, diga exatamente isso e sugira falar com a organização. Não deduza, não complete com o que costuma ser praticado em outras ligas.',
        '- Se o edital for ambíguo no ponto perguntado, diga que é ambíguo e mostre o que ele diz.',
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

    if ($provedor === 'gemini') {
        return editalIaPerguntarGemini($league, $edital, $pergunta, $erro);
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
 * `thinking_level: low` é o mínimo que o 2.5-flash aceita — ele não desliga o
 * raciocínio, e o que ele pensa sai do MESMO orçamento da resposta. Sem baixar
 * pro mínimo e sem a folga no teto, a resposta chega vazia com
 * `finishReason: MAX_TOKENS`: o modelo gastou tudo pensando e não sobrou texto.
 *
 * Não há cache do edital como na Anthropic: no free tier o cache é implícito,
 * e não há custo por token pra economizar.
 *
 * @param callable $erro Fábrica do retorno de erro, do chamador.
 */
function editalIaPerguntarGemini(string $league, string $edital, string $pergunta, callable $erro): array
{
    $modelo = editalIaModelo('gemini');
    $url = 'https://generativelanguage.googleapis.com/v1beta/models/'
         . rawurlencode($modelo) . ':generateContent';

    $payload = [
        'system_instruction' => ['parts' => [
            ['text' => "EDITAL DA LIGA {$league} — FBA BRASIL\n\n" . $edital],
            ['text' => editalIaInstrucoes($league)],
        ]],
        'contents' => [
            ['role' => 'user', 'parts' => [['text' => $pergunta]]],
        ],
        'generationConfig' => [
            'maxOutputTokens' => EDITAL_IA_MAX_TOKENS_GEMINI,
            // Regulamento é leitura, não criação: o modelo deve repetir o que
            // está escrito, e não achar uma forma nova de dizer.
            'temperature'     => 0.2,
            // O v1beta não conhece `thinking_level` (a API rejeita com
            // "Cannot find field"): quem controla o raciocínio aqui é o
            // thinkingBudget, em tokens.
            'thinkingConfig'  => ['thinkingBudget' => 0],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => EDITAL_IA_TIMEOUT,
        // A chave vai no cabeçalho, e não na query: URL vaza em log de acesso,
        // de proxy e de erro.
        CURLOPT_HTTPHEADER     => [
            'content-type: application/json',
            'x-goog-api-key: ' . editalIaChaveGemini(),
        ],
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
    ]);
    $corpo  = curl_exec($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $falha  = curl_error($ch);
    curl_close($ch);

    if ($corpo === false || $falha !== '') {
        error_log('[edital_ia/gemini] curl: ' . $falha);
        return $erro('Não consegui consultar o edital agora. Tenta de novo em um minuto.');
    }

    $j = json_decode((string)$corpo, true);

    /* O teto diário é o erro esperado do free tier, e merece resposta própria:
       "tenta de novo em um minuto" mandaria a pessoa insistir à toa até o dia
       seguinte. */
    if ($status === 429) {
        error_log('[edital_ia/gemini] 429: ' . editalIaSemSegredo((string)$corpo));
        return $erro('O limite de consultas de hoje acabou. Tenta mais tarde, '
                   . 'ou pergunta pra organização.');
    }
    if ($status !== 200 || !is_array($j)) {
        error_log('[edital_ia/gemini] http ' . $status . ': ' . editalIaSemSegredo((string)$corpo));
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
        error_log('[edital_ia/gemini] resposta vazia, finishReason=' . $motivo);
        return $erro($motivo === 'MAX_TOKENS'
            ? 'A resposta ficou longa demais e foi cortada. Tenta uma pergunta mais específica.'
            : 'Vieram só linhas vazias. Tenta reformular a pergunta.');
    }

    return ['ok' => true, 'resposta' => $texto, 'erro' => null, 'uso' => $j['usageMetadata'] ?? null];
}

/**
 * O corpo do erro sem a chave dentro.
 *
 * O Google DEVOLVE a chave na mensagem de erro ("Consumer 'api_key:AQ...' has
 * been suspended"), e o log de erro do site nao e lugar de segredo: quem tem
 * acesso ao log passa a ter a chave. Some tambem o que parecer chave em
 * qualquer outro formato.
 */
function editalIaSemSegredo(string $texto, int $limite = 400): string
{
    $texto = preg_replace("/(api_key:)\s*\S+/i", "$1[oculta]", $texto);
    $texto = preg_replace("/\b(AIza|AQ\.)[A-Za-z0-9._\-]{10,}/", "[chave oculta]", $texto);
    return mb_substr($texto, 0, $limite);
}
