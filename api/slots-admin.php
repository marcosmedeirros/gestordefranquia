<?php
/**
 * ABRIR AS VAGAS DE TELA NA MÃO.
 *
 * A venda abre sozinha uma hora antes da live e isso continua valendo. Este
 * endereço existe pro que o relógio não cobre: a live que mudou de horário em
 * cima da hora, ou a liga que quer soltar as vagas antes por outro motivo.
 *
 * Só admin — e só das ligas que a pessoa administra, como no resto do painel.
 *
 *   GET  ?action=estado           as ligas do admin, com a próxima live de cada
 *   POST {action:'abrir',   liga} abre a venda da próxima live agora
 *   POST {action:'cancelar',liga} desfaz, e a venda volta a seguir o relógio
 */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

require_once dirname(__DIR__) . '/backend/auth.php';
require_once dirname(__DIR__) . '/backend/db.php';
require_once dirname(__DIR__) . '/backend/helpers.php';
require_once dirname(__DIR__) . '/backend/slots_tela.php';

$user = getUserSession();
if (!$user) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$pdo = db();
$ehGlobal = ($user['user_type'] ?? 'jogador') === 'admin';
$ligas    = $ehGlobal ? ['ELITE','NEXT','RISE','ROOKIE'] : getAdminLeagues($pdo, (int)$user['id']);
if (!$ligas) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Acesso negado']);
    exit;
}

$corpo = json_decode(file_get_contents('php://input'), true) ?: [];
$acao  = $_GET['action'] ?? $corpo['action'] ?? 'estado';

function slErro(int $http, string $msg): void {
    http_response_code($http);
    echo json_encode(['success' => false, 'error' => $msg]);
    exit;
}

try {
    if ($acao === 'estado') {
        $out = [];
        foreach ($ligas as $liga) {
            $e = slotsTelaEstado($pdo, $liga);
            $out[] = [
                'liga'     => $liga,
                'live'     => $e['live'] ? [
                    'data'   => $e['live']['data'],
                    'inicio' => $e['live']['inicio'],
                    'hora'   => slotsTelaHora($e['live']['inicio']),
                    'titulo' => $e['live']['titulo'] ?? null,
                ] : null,
                'aberta'   => $e['aberta'],
                'motivo'   => $e['motivo'],
                'abre_em'  => $e['abre_em'],
                'na_mao'   => $e['na_mao'] ?? null,
                'vendidos' => $e['vendidos'],
                'restam'   => $e['restam'],
                'total'    => $e['total'],
                'preco'    => $e['preco'],
            ];
        }
        echo json_encode(['success' => true, 'ligas' => $out], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if ($acao === 'abrir' || $acao === 'cancelar') {
        $liga = strtoupper(trim((string)($corpo['liga'] ?? '')));
        if (!in_array($liga, $ligas, true)) slErro(403, 'Você não administra a ' . ($liga ?: 'liga') . '.');

        $r = $acao === 'abrir'
            ? slotsTelaAbrirAgora($pdo, $liga, (int)$user['id'])
            : slotsTelaCancelarAbertura($pdo, $liga);
        if (!$r['ok']) slErro(409, $r['erro']);

        // A confirmação vem do estado recalculado, não do que acabou de gravar:
        // dizer "abriu" sem conferir esconderia o caso da live que já começou.
        $e = slotsTelaEstado($pdo, $liga);
        echo json_encode([
            'success' => true,
            'aberta'  => $e['aberta'],
            'motivo'  => $e['motivo'],
            'message' => $acao === 'abrir'
                ? ($e['aberta']
                    ? 'Vagas abertas na ' . $liga . ' para a live de ' . slotsTelaHora($r['live']['inicio']) . '.'
                    : 'Gravado, mas a venda segue fechada (' . $e['motivo'] . ').')
                : 'A ' . $liga . ' voltou a abrir sozinha, uma hora antes da live.',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    slErro(400, 'Ação desconhecida.');
} catch (Throwable $e) {
    error_log('[slots-admin] ' . $e->getMessage());
    slErro(500, 'Erro interno.');
}
