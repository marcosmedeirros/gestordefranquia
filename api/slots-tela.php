<?php
/**
 * O ESTADO DOS SLOTS DE TELA, pra tela se atualizar sozinha.
 *
 * Oito vagas, quem chega primeiro leva, e a venda dura uma hora: é uma
 * corrida, e numa corrida a página parada mente. Quem abriu a loja com seis
 * slots livres e foi buscar café volta e vê seis — clica, e o servidor
 * responde que acabou. A recusa está certa, mas a pessoa gastou a decisão
 * dela olhando pra um número velho.
 *
 * Então a loja e o dashboard consultam este endereço de tempos em tempos
 * enquanto a venda está aberta. Ele não decide nada — quem decide continua
 * sendo o slotsTelaComprar, dentro da transação. Aqui é só o retrato.
 *
 * GET /api/slots-tela.php?liga=NEXT
 */
require_once __DIR__ . '/../backend/auth.php';
require_once __DIR__ . '/../backend/db.php';
require_once __DIR__ . '/../backend/slots_tela.php';
require_once __DIR__ . '/../backend/helpers.php';

header('Content-Type: application/json; charset=utf-8');
// Retrato do instante: guardar isto em cache é o contrário do que ele serve.
header('Cache-Control: no-store');

$user = getUserSession();
if (!$user) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'erro' => 'Faça login.']);
    exit;
}

$pdo = db();

// A liga sai do TIME de quem está pedindo, e não do que veio na URL: o
// estado é o mesmo pra todo mundo, mas 'meu' (se o meu time já está na tela)
// não é — e ler o time do banco evita responder sobre um que não é dele.
$st = $pdo->prepare("SELECT id, league FROM teams WHERE user_id = ? LIMIT 1");
$st->execute([(int)$user['id']]);
$time = $st->fetch(PDO::FETCH_ASSOC);
if (!$time) {
    echo json_encode(['ok' => true, 'live' => null]);
    exit;
}

$estado = slotsTelaEstado($pdo, (string)$time['league'], (int)$time['id']);
if (!$estado['live']) {
    echo json_encode(['ok' => true, 'live' => null]);
    exit;
}

$lista = [];
foreach ($estado['lista'] as $s) {
    $lista[] = [
        'nome'  => trim((string)($s['time_nome'] ?? '')),
        'cheio' => trim(((string)($s['time_cidade'] ?? '')) . ' ' . ((string)($s['time_nome'] ?? ''))),
        'logo'  => getTeamPhoto($s['logo'] ?? null),
    ];
}

echo json_encode([
    'ok'       => true,
    'live'     => ['inicio' => $estado['live']['inicio'], 'hora' => slotsTelaHora($estado['live']['inicio'])],
    'motivo'   => $estado['motivo'],
    'aberta'   => $estado['aberta'],
    'vendidos' => $estado['vendidos'],
    'restam'   => $estado['restam'],
    'total'    => $estado['total'],
    'meu'      => $estado['meu'],
    'lista'    => $lista,
    'copiar'   => slotsTelaTextoCopiar($estado['lista']),
], JSON_UNESCAPED_UNICODE);
