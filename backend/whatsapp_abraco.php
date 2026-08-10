<?php
/**
 * O abraço do dia: sorteia um GM e enfileira um abraço no grupo principal.
 *
 * Separado do cron porque agora tem dois gatilhos — o agendamento das 15h e o
 * botão "Disparar abraço" da aba Gestão. Sorteio, trava de repetição e menção
 * moram aqui, uma vez só.
 */

require_once __DIR__ . '/whatsapp.php';

const ABRACO_HORA = 15;   // horário de Brasília

/**
 * @param bool $forcar Ignora o horário E a marca do dia. É o botão do admin:
 *                     ele clicou porque quer agora, mesmo que já tenha saído.
 * @return array ['enviado' => bool, 'motivo' => string, 'nome' => ?string, ...]
 */
function enviarAbracoDoDia(PDO $pdo, bool $forcar = false): array
{
    ensureWhatsAppTables($pdo);

    $agora = new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo'));

    // Antes das 15h em Brasília não faz nada — mesmo que o relógio do servidor
    // diga outra coisa. Depois das 15h manda, o que cobre execução perdida.
    if (!$forcar && (int)$agora->format('G') < ABRACO_HORA) {
        return ['enviado' => false, 'motivo' => 'fora_de_hora'];
    }

    $hoje = $agora->format('Y-m-d');
    $marca = 'abraco_do_dia_' . $hoje;

    // A marca é gravada ANTES de enfileirar: se duas execuções entrarem juntas,
    // a segunda esbarra na chave primária e sai. Enfileirar primeiro deixaria
    // brecha pras duas mandarem.
    if (!$forcar) {
        try {
            $pdo->prepare("INSERT INTO app_flags (flag, applied_at) VALUES (?, NOW())")->execute([$marca]);
        } catch (Throwable $e) {
            return ['enviado' => false, 'motivo' => 'ja_foi_hoje'];
        }
    }

    $grupo = trim((string)($pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetchColumn() ?: ''));
    if ($grupo === '') {
        if (!$forcar) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
        return ['enviado' => false, 'motivo' => 'sem_grupo'];
    }

    // Sem GROUP BY de propósito: com ONLY_FULL_GROUP_BY ligado (padrão do MySQL
    // 5.7 pra cima) selecionar colunas do time agrupando por usuário é erro, e
    // eu não controlo a configuração da hospedagem. O EXISTS também deixa o
    // sorteio justo — GM com time em duas ligas entraria duas vezes num JOIN
    // direto e teria o dobro de chance.
    $candidatos = $pdo->query("
        SELECT u.id, u.name, u.phone
        FROM users u
        WHERE u.name IS NOT NULL AND u.name <> ''
          AND EXISTS (SELECT 1 FROM teams t WHERE t.user_id = u.id)
        ORDER BY RAND()
        LIMIT 10
    ")->fetchAll(PDO::FETCH_ASSOC);

    if (!$candidatos) {
        if (!$forcar) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
        return ['enviado' => false, 'motivo' => 'sem_candidatos'];
    }

    // Sortear de novo quem levou o último faria parecer defeito, não sorte. É o
    // único desvio do aleatório puro — e some se só houver um candidato.
    //
    // Pego o último abraço pelo id, não por data: depender de "onde estava
    // ontem" quebra calado se a fila for limpa ou se o cron pular um dia.
    $idAnterior = (int)($pdo->query("SELECT user_id FROM whatsapp_fila
                                     WHERE tipo = 'abraco' AND user_id IS NOT NULL
                                     ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);

    $sorteado = null;
    foreach ($candidatos as $c) {
        if ((int)$c['id'] !== $idAnterior) { $sorteado = $c; break; }
    }
    $sorteado = $sorteado ?: $candidatos[0];

    // O time vem depois, já com o sorteado decidido. Quem tem mais de um, mostra
    // o da liga mais alta.
    $stTime = $pdo->prepare("SELECT city, name AS team_name, league FROM teams
                             WHERE user_id = ?
                             ORDER BY FIELD(league,'ELITE','NEXT','RISE','ROOKIE') LIMIT 1");
    $stTime->execute([(int)$sorteado['id']]);
    $timeDele = $stTime->fetch(PDO::FETCH_ASSOC) ?: [];
    $time = trim(trim((string)($timeDele['city'] ?? '')) . ' ' . trim((string)($timeDele['team_name'] ?? '')));

    $abracos = [
        'Passando pra dar um abraço no %s 🤗',
        'O abraço de hoje vai pro %s 🤗',
        'Alguém segura, que hoje o abraço é do %s 🤗',
        'Sorteio do dia: abraço apertado no %s 🤗',
        'Hoje quem leva abraço é o %s 🤗',
    ];
    $modelo = $abracos[random_int(0, count($abracos) - 1)];

    // A etiqueta do WhatsApp só aparece se o @numero estiver NO TEXTO e o número
    // também for na lista de menções. Sem telefone cadastrado, vai o nome puro —
    // menos legal, mas melhor que não mandar.
    $telefone = preg_replace('/\D+/', '', (string)($sorteado['phone'] ?? ''));
    $mencoes = null;
    if (strlen($telefone) >= 10) {
        $alvo = '@' . $telefone;
        $mencoes = [$telefone];
    } else {
        $alvo = $sorteado['name'];
    }

    $texto = sprintf($modelo, $alvo) . "\n_" . $time . ' · ' . ($timeDele['league'] ?? '') . '_';

    $ok = whatsappEnfileirar($pdo, $grupo, $texto, true, 'abraco', (int)$sorteado['id'], $mencoes);
    if (!$ok) {
        // A flag do dia já está gravada, mas sem mensagem na fila. Apago pra que
        // a próxima execução tente de novo em vez de pular o dia calado.
        if (!$forcar) $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
        return ['enviado' => false, 'motivo' => 'bot_desligado'];
    }

    return [
        'enviado'  => true,
        'motivo'   => 'ok',
        'nome'     => $sorteado['name'],
        'time'     => $time,
        'com_mencao' => $mencoes !== null,
    ];
}
