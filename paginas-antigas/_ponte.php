<?php
/**
 * A PONTE QUE FAZ AS PÁGINAS ANTIGAS ABRIREM DE NOVO.
 *
 * Elas foram movidas da raiz pra cá inteiras, com os `require_once __DIR__ .
 * '/backend/...'` que tinham lá. Da raiz aquilo achava o backend; daqui o
 * __DIR__ é esta pasta, e a página morria no primeiro require com "No such
 * file or directory" — que foi o erro que o Marcos viu em 10/11/2026.
 *
 * A saída NÃO foi editar as 43 páginas. Elas estão aqui pra ficarem como
 * eram: mexer nos arquivos seria apagar justamente o que se quer olhar. Em
 * vez disso, esta pasta ganhou um `backend/` e um `includes/` de mentira, com
 * um arquivo por nome pedido, e cada um deles só chama o de verdade lá em
 * cima. @see paginas-antigas/backend/auth.php
 *
 * ISTO É UM MUSEU, E A PLACA DIZ ISSO. Duas travas, porque a página abre com
 * a sessão real e fala com as APIs de hoje:
 *
 *   1. SÓ ADMIN ENTRA. O que está aqui é de uma versão antiga da liga; GM
 *      caindo aqui por um link velho veria regra que não vale mais e cobraria
 *      a administração por ela.
 *   2. POST NÃO PASSA. Formulário antigo mandando campo antigo pra rotina de
 *      hoje é o tipo de escrita que ninguém revisa — e que só aparece semanas
 *      depois, como dado torto que "sempre esteve lá".
 *
 * O que esta ponte NÃO consegue impedir: o JavaScript dessas páginas chama
 * `/api/...` por caminho absoluto, então os botões falam com as APIs ATUAIS,
 * com as validações de hoje. É pra olhar, não pra operar.
 */

if (defined('PAGINAS_ANTIGAS_PONTE')) return;
define('PAGINAS_ANTIGAS_PONTE', true);

/** A raiz de verdade do site, uma pasta acima desta. */
function antigasRaiz(): string
{
    return dirname(__DIR__);
}

require_once antigasRaiz() . '/backend/auth.php';
require_once antigasRaiz() . '/backend/db.php';

/* O portão roda ANTES de a página fazer qualquer coisa: o shim de auth.php é
   o primeiro require de todas elas. */
$__antigasUser = function_exists('getUserSession') ? getUserSession() : null;

if (!$__antigasUser) {
    header('Location: /login.php');
    exit;
}

try {
    $__antigasAdmin = hasAdminAccess(db(), (int)$__antigasUser['id']);
} catch (Throwable $e) {
    error_log('[paginas-antigas] portao: ' . $e->getMessage());
    $__antigasAdmin = false;
}

if (!$__antigasAdmin) {
    http_response_code(403);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><meta charset="utf-8"><title>Página antiga</title>'
       . '<style>body{font-family:system-ui,sans-serif;background:#0b0b0e;color:#eee;'
       . 'display:flex;min-height:100vh;align-items:center;justify-content:center;margin:0;padding:24px}'
       . 'div{max-width:38ch;text-align:center;line-height:1.5}a{color:#fc0025}</style>'
       . '<div><h1 style="font-size:19px">Isto é uma página antiga</h1>'
       . '<p>Ficou guardada só como lembrança de uma versão passada do site, e não vale mais '
       . 'como regra. <a href="/dashboard.php">Voltar pro FBA Manager</a>.</p></div>';
    exit;
}

/* ESCRITA NÃO PASSA. As páginas são de uma versão anterior do banco; um POST
   delas chegaria numa rotina que hoje espera outros campos. */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
    http_response_code(405);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Página antiga: só dá pra olhar. Envio desligado de propósito.";
    exit;
}

/*
 * A FAIXA, INJETADA DEPOIS DO <body>.
 *
 * Imprimir aqui sairia antes do <!doctype> e quebraria o HTML. O buffer
 * resolve: a página roda inteira, e a faixa entra na primeira tag <body> que
 * aparecer. Página sem <body> (um JSON, um redirect) sai intacta.
 */
ob_start(function (string $html): string {
    if (stripos($html, '<body') === false) return $html;

    $faixa = '<div style="position:sticky;top:0;z-index:99999;background:#7c2d12;color:#fff;'
           . 'font:600 12.5px/1.4 system-ui,sans-serif;padding:9px 16px;text-align:center;'
           . 'border-bottom:1px solid #ea580c">'
           . 'PÁGINA ANTIGA — guardada só como lembrança. Não vale como regra, e os botões '
           . 'daqui falam com o site de hoje. '
           . '<a href="/dashboard.php" style="color:#fed7aa">Voltar ao FBA Manager</a></div>';

    return preg_replace('/(<body[^>]*>)/i', '$1' . $faixa, $html, 1) ?? $html;
});
