<?php
/**
 * O abraço do dia: às 15h, um GM sorteado leva um abraço no grupo principal.
 *
 * Agendar na Hostinger uma vez por dia, às 15:00:
 *   0 15 * * *  /usr/bin/php /home/USUARIO/domains/fbabrasil.com.br/public_html/cron/whatsapp-abraco.php
 *
 * Se o cron rodar mais de uma vez no mesmo dia (retentativa, agendamento
 * duplicado, execução manual), o segundo disparo não faz nada: a marca do dia
 * fica em app_flags. Ninguém leva dois abraços.
 *
 * Quem entra no sorteio: GM com time. Vale todas as ligas — o grupo principal é
 * de todo mundo, não de uma liga só.
 *
 * A mensagem sai pela fila normal, então respeita a janela de horário e o
 * worker local a entrega. Às 15h a janela está aberta (08:45–18:00), mas se um
 * dia o expediente mudar, o abraço espera em vez de sumir.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/whatsapp.php';

$pdo = db();
ensureWhatsAppTables($pdo);

$hoje = (new DateTimeImmutable('now', new DateTimeZone('America/Sao_Paulo')))->format('Y-m-d');
$marca = 'abraco_do_dia_' . $hoje;

// A marca é gravada ANTES de enfileirar: se duas execuções entrarem juntas, a
// segunda esbarra na chave primária e sai. Enfileirar primeiro deixaria brecha
// pras duas mandarem.
try {
    $pdo->prepare("INSERT INTO app_flags (flag, applied_at) VALUES (?, NOW())")->execute([$marca]);
} catch (Throwable $e) {
    echo "abraço de {$hoje} já foi enviado\n";
    exit(0);
}

$cfg = $pdo->query("SELECT grupo_principal FROM whatsapp_config WHERE id = 1")->fetch(PDO::FETCH_ASSOC) ?: [];
$grupo = trim((string)($cfg['grupo_principal'] ?? ''));
if ($grupo === '') {
    fwrite(STDERR, "grupo principal não configurado\n");
    exit(1);
}

// Sem GROUP BY de propósito: com ONLY_FULL_GROUP_BY ligado (padrão do MySQL 5.7
// pra cima) selecionar colunas do time agrupando por usuário é erro, e eu não
// controlo a configuração da hospedagem. O EXISTS também deixa o sorteio justo
// — GM com time em duas ligas entraria duas vezes num JOIN direto e teria o
// dobro de chance.
//
// ORDER BY RAND() é ruim em tabela grande; aqui são algumas dezenas de GMs.
$candidatos = $pdo->query("
    SELECT u.id, u.name, u.phone
    FROM users u
    WHERE u.name IS NOT NULL AND u.name <> ''
      AND EXISTS (SELECT 1 FROM teams t WHERE t.user_id = u.id)
    ORDER BY RAND()
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);

if (!$candidatos) {
    fwrite(STDERR, "nenhum GM com time pra sortear\n");
    exit(1);
}

// Sortear de novo quem levou o último faria parecer defeito, não sorte. É o
// único desvio do aleatório puro — e some se só houver um candidato.
//
// Pego o último abraço pelo id, não por data: depender de "onde estava ontem"
// quebra calado se a fila for limpa ou se o cron pular um dia.
$idAnterior = (int)($pdo->query("SELECT user_id FROM whatsapp_fila
                                 WHERE tipo = 'abraco' AND user_id IS NOT NULL
                                 ORDER BY id DESC LIMIT 1")->fetchColumn() ?: 0);

$sorteado = null;
foreach ($candidatos as $c) {
    if ((int)$c['id'] !== $idAnterior) { $sorteado = $c; break; }
}
$sorteado = $sorteado ?: $candidatos[0];

// O time vem depois, já com o sorteado decidido. Quem tem mais de um, mostra o
// da liga mais alta.
$stTime = $pdo->prepare("SELECT city, name AS team_name, league FROM teams
                         WHERE user_id = ?
                         ORDER BY FIELD(league,'ELITE','NEXT','RISE','ROOKIE') LIMIT 1");
$stTime->execute([(int)$sorteado['id']]);
$timeDele = $stTime->fetch(PDO::FETCH_ASSOC) ?: [];
$sorteado['league'] = $timeDele['league'] ?? '';

$time = trim(trim((string)($timeDele['city'] ?? '')) . ' ' . trim((string)($timeDele['team_name'] ?? '')));
$telefone = preg_replace('/\D+/', '', (string)($sorteado['phone'] ?? ''));

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
$mencoes = null;
if (strlen($telefone) >= 10) {
    $alvo = '@' . $telefone;
    $mencoes = [$telefone];
} else {
    $alvo = $sorteado['name'];
}

$texto = sprintf($modelo, $alvo) . "\n_" . $time . ' · ' . $sorteado['league'] . '_';

$ok = whatsappEnfileirar($pdo, $grupo, $texto, true, 'abraco', (int)$sorteado['id'], $mencoes);
if (!$ok) {
    // A flag do dia já está gravada, mas sem mensagem na fila. Apago pra que a
    // próxima execução possa tentar de novo em vez de pular o dia calado.
    $pdo->prepare("DELETE FROM app_flags WHERE flag = ?")->execute([$marca]);
    fwrite(STDERR, "não consegui enfileirar (bot desligado?)\n");
    exit(1);
}

echo "abraço de {$hoje}: {$sorteado['name']} ({$time})" . ($mencoes ? ' — com menção' : ' — sem telefone, nome puro') . "\n";
