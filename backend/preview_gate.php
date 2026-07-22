<?php
/**
 * Gate de acesso por link para funcionalidades ainda nao liberadas na liga.
 *
 * As paginas protegidas (Salary Cap e Loteria da ELITE) nao aparecem em nenhum
 * menu e so abrem quando a URL traz o token: ex. /cap.php?preview=<token>
 * Depois do primeiro acesso o token fica na sessao, entao a navegacao interna
 * da propria pagina continua funcionando sem repetir o parametro.
 */

const PREVIEW_TOKEN = 'fba-preview-2026';

/** Marca a feature como liberada nesta sessao se o token veio na URL. */
function previewUnlock(string $feature): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $token = $_GET['preview'] ?? '';
    if (is_string($token) && hash_equals(PREVIEW_TOKEN, $token)) {
        $_SESSION['preview_features'][$feature] = true;
    }
}

/** A feature esta liberada para esta sessao? */
function previewActive(string $feature): bool
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    return !empty($_SESSION['preview_features'][$feature]);
}

/**
 * Libera pelo token e, se nao estiver liberada, manda o visitante embora.
 * Sem token a pagina se comporta como se nao existisse.
 */
function requirePreview(string $feature): void
{
    previewUnlock($feature);
    if (!previewActive($feature)) {
        http_response_code(404);
        header('Location: /dashboard.php');
        exit;
    }
}
