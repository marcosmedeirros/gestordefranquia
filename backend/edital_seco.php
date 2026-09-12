<?php
/**
 * /edital — CONSULTA SECA AO EDITAL DA LIGA.
 *
 * O /duvida conversa, consulta o banco e usa o guia do app. O /edital é o
 * contrário, a pedido do dono da liga: só o que está escrito no edital da
 * liga do grupo, do jeito que está escrito, e só o pedaço que foi perguntado.
 *
 *   /edital o que acontece se ficar abaixo do cap
 *   → OVR Cap abaixo do piso técnico (Art. 26, II c/c §2º) - Perda da próxima
 *     pick própria de 1ª rodada, de forma irrecorrível, já na 1ª ocorrência.
 *
 * A 2ª ocorrência só sai se perguntarem da 2ª. Pergunta de sim/não começa com
 * "Sim." ou "Não." ("perde pick por punição e cai na Stepien?" → Não.).
 *
 * Nada de dados do app, memória, personalidade ou ferramenta: é uma ida só ao
 * modelo, com o edital e as regras de resposta.
 */

require_once __DIR__ . '/edital_texto.php';
require_once __DIR__ . '/edital_ia.php';

function editalSecoInstrucoes(string $liga): string
{
    return implode("\n", [
        "Você responde perguntas sobre o EDITAL da liga {$liga} da FBA Brasil, usando SOMENTE o texto do edital acima.",
        '',
        'REGRAS:',
        '1. Só o que está escrito no edital. Não use conhecimento de fora, dados do app, regras de outra liga nem bom senso pra completar o que o edital não diz.',
        '2. Responda SÓ o que foi perguntado. Se a pergunta é "o que acontece se X", dê a consequência da situação perguntada e pare — não liste reincidência, 2ª ocorrência, exceções ou casos seguintes que ninguém perguntou. Se perguntarem da 2ª ocorrência, aí responda a 2ª.',
        '3. Formato de consequência: "<situação como o edital chama> (<artigo, inciso e parágrafo como no edital>) - <consequência>."',
        '   Exemplo: OVR Cap abaixo do piso técnico (Art. 26, II c/c §2º) - Perda da próxima pick própria de 1ª rodada, de forma irrecorrível, já na 1ª ocorrência.',
        '4. Pergunta de sim ou não: comece com "Sim." ou "Não." e, na mesma linha, a regra e o artigo que sustentam a resposta.',
        '5. Sempre cite o artigo entre parênteses. Nunca invente número de artigo.',
        "6. Se o edital não trata do assunto, responda exatamente: \"O edital da {$liga} não trata disso.\" Não deduza.",
        '7. Sem saudação, sem introdução, sem conclusão, sem emoji. No máximo 3 linhas. Pode usar *negrito* do WhatsApp.',
    ]);
}

/** @return array{ok:bool, resposta:?string, erro:?string} */
function editalSecoPerguntar(PDO $pdo, string $liga, string $pergunta): array
{
    $erro = fn(string $m) => ['ok' => false, 'resposta' => null, 'erro' => $m];
    $liga = strtoupper(trim($liga)) ?: 'ELITE';

    if (editalIaProvedor() !== 'gemini') return $erro('A consulta ao edital ainda não foi ligada aqui.');

    $pergunta = trim($pergunta);
    if (mb_strlen($pergunta) < 5)   return $erro('Escreve a pergunta junto do comando. Ex.: /edital o que acontece se ficar abaixo do cap');
    if (mb_strlen($pergunta) > 400) return $erro('Pergunta muito longa — resume em uma frase.');

    $edital = editalTexto($pdo, $liga);
    if ($edital === null) return $erro("A {$liga} não tem edital cadastrado no site.");
    $emprestado = editalTextoProprio($pdo, $liga) === null ? (EDITAL_HERDA_DE[$liga] ?? null) : null;

    $limite = editalIaLimiteDia();
    if ($limite > 0 && editalIaUsoDeHoje($pdo) >= $limite) return $erro('Cheguei no meu limite por hoje. Até amanhã!');

    $payload = [
        'system_instruction' => ['parts' => [
            ['text' => "EDITAL DA LIGA {$liga}" . ($emprestado ? " (a {$liga} usa o edital da {$emprestado})" : '') . " — FBA BRASIL\n\n" . $edital],
            ['text' => editalSecoInstrucoes($liga)],
        ]],
        'contents' => [['role' => 'user', 'parts' => [['text' => $pergunta]]]],
        'generationConfig' => [
            'maxOutputTokens' => EDITAL_IA_MAX_TOKENS_GEMINI,
            // Leitura de regra: nada de criatividade.
            'temperature' => 0.1,
        ],
    ];

    [$ok, $j, $err] = editalIaChamarGemini($pdo, $payload, $erro);
    if (!$ok) return $err;

    $texto = '';
    foreach (($j['candidates'][0]['content']['parts'] ?? []) as $parte) {
        if (!empty($parte['thought'])) continue;   // raciocínio do modelo não vai pro grupo
        $texto .= (string)($parte['text'] ?? '');
    }
    $texto = trim($texto);
    if ($texto === '') return $erro('Não consegui ler o edital agora. Tenta de novo em um minuto.');

    if ($emprestado) $texto .= "\n\n_A {$liga} usa o edital da {$emprestado}._";
    return ['ok' => true, 'resposta' => $texto, 'erro' => null];
}
