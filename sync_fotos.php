<?php
/**
 * Visita manual do admin — o mesmo processo do cron e do botão em Gestão,
 * só que com a página de resultado renderizada pra leitura direta.
 */
require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/nba_sync.php';

requireAuth();
$user = getUserSession();
$pdo  = db();
if (!hasAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    die('Acesso negado.');
}

echo "<h1>Sincronizador de fotos (NBA)</h1>";

$r = syncNbaPlayerPhotos($pdo);

if (!$r['ok']) {
    die('<p style="color:red"><b>Erro:</b> ' . htmlspecialchars($r['erro']) . '</p>');
}

echo "<h3>Processo concluído! {$r['atualizados']} de {$r['total_verificados']} jogador(es) sem foto foram atualizados.</h3>";
if (!empty($r['sem_correspondencia'])) {
    echo '<h4>Sem correspondência no cadastro da NBA:</h4><ul>';
    foreach ($r['sem_correspondencia'] as $item) {
        echo '<li>' . htmlspecialchars($item['nome']) . ' <span style="color:#999;">(' . htmlspecialchars($item['time']) . ')</span></li>';
    }
    echo '</ul>';
}
