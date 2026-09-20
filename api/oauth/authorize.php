<?php
/**
 * A TELA ONDE O DONO APROVA — o único ponto do fluxo com gente olhando.
 *
 * Chega aqui o app do Claude pedindo acesso. A gente confere o pedido, exige
 * que quem está no navegador seja o admin geral da FBA e mostra, em português,
 * o que está sendo liberado — inclusive que quatro ferramentas escrevem no
 * banco na hora. Aprovou, sai um código de uso único; recusou, o app é avisado
 * e não recebe nada.
 *
 * Erro de redirect_uri ou de client_id NÃO volta pro app: se o endereço de
 * retorno não é de confiança, mandar o erro pra ele é entregar informação a
 * quem forjou o pedido. Esses dois aparecem aqui mesmo, na tela.
 */

require_once __DIR__ . '/../../backend/mcp_oauth.php';

$pdo = db();
mcpOauthLimpar($pdo);

function auErroNaTela(string $titulo, string $detalhe): void
{
    http_response_code(400);
    auPagina($titulo, '<p class="erro">' . htmlspecialchars($detalhe) . '</p>');
    exit;
}

function auVoltarComErro(string $redirect, string $erro, ?string $state): void
{
    $sep = str_contains($redirect, '?') ? '&' : '?';
    $url = $redirect . $sep . http_build_query(array_filter([
        'error' => $erro, 'state' => $state,
    ], fn($v) => $v !== null && $v !== ''));
    header('Location: ' . $url);
    exit;
}

function auPagina(string $titulo, string $corpo): void
{
    echo '<!doctype html><html lang="pt-br"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . htmlspecialchars($titulo) . ' · FBA</title>'
       . '<style>'
       . ':root{color-scheme:dark}'
       . 'body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;'
       . 'background:#0d0d10;color:#e8e8ea;font:16px/1.55 system-ui,-apple-system,Segoe UI,Roboto,sans-serif;padding:24px}'
       . '.cartao{width:100%;max-width:440px;background:#16161b;border:1px solid #26262e;border-radius:16px;padding:28px}'
       . 'h1{font-size:20px;margin:0 0 6px}'
       . '.sub{color:#9a9aa6;font-size:13.5px;margin:0 0 20px}'
       . 'ul{margin:0 0 20px;padding-left:18px;color:#c9c9d2;font-size:14px}'
       . 'li{margin:4px 0}'
       . '.aviso{background:#2a1c10;border:1px solid #5a3a16;color:#f0c088;border-radius:10px;'
       . 'padding:10px 12px;font-size:13px;margin:0 0 20px}'
       . '.erro{background:#2a1214;border:1px solid #5c2126;color:#f0a0a8;border-radius:10px;padding:12px;font-size:14px}'
       . '.linha{display:flex;gap:10px;margin-top:4px}'
       . 'button,a.botao{flex:1;display:block;text-align:center;text-decoration:none;border:0;border-radius:10px;'
       . 'padding:12px 16px;font-size:15px;font-weight:600;cursor:pointer;font-family:inherit}'
       . '.sim{background:#e03131;color:#fff}.nao{background:#24242c;color:#c9c9d2}'
       . '.pe{color:#75757f;font-size:12px;margin-top:18px}'
       . '</style></head><body><div class="cartao">'
       . '<h1>' . htmlspecialchars($titulo) . '</h1>' . $corpo
       . '<p class="pe">FBA · fbabrasil.com.br</p></div></body></html>';
}

// ── O pedido ────────────────────────────────────────────────────────────
$clientId  = (string)($_REQUEST['client_id'] ?? '');
$redirect  = (string)($_REQUEST['redirect_uri'] ?? '');
$state     = isset($_REQUEST['state']) ? (string)$_REQUEST['state'] : null;
$resposta  = (string)($_REQUEST['response_type'] ?? '');
$desafio   = (string)($_REQUEST['code_challenge'] ?? '');
$metodo    = (string)($_REQUEST['code_challenge_method'] ?? '');
$escopo    = trim((string)($_REQUEST['scope'] ?? MCP_OAUTH_ESCOPO)) ?: MCP_OAUTH_ESCOPO;

$cliente = $clientId !== '' ? mcpOauthCliente($pdo, $clientId) : null;
if (!$cliente) {
    auErroNaTela('Pedido não reconhecido', 'Esse aplicativo não está registrado aqui. Adicione o conector de novo pelo Claude.');
}
if ($redirect === '' || !mcpOauthRedirectValido($cliente, $redirect)) {
    auErroNaTela('Endereço de retorno inválido', 'O endereço para onde o aplicativo quer voltar não confere com o que ele registrou.');
}
if ($resposta !== 'code')        auVoltarComErro($redirect, 'unsupported_response_type', $state);
if ($metodo !== 'S256')          auVoltarComErro($redirect, 'invalid_request', $state);
if (strlen($desafio) < 43)       auVoltarComErro($redirect, 'invalid_request', $state);

// ── Quem está no navegador ──────────────────────────────────────────────
$user = getUserSession();
if (!$user) {
    $volta = '/api/oauth/authorize.php?' . http_build_query(array_filter([
        'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => $state,
        'response_type' => 'code', 'code_challenge' => $desafio,
        'code_challenge_method' => $metodo, 'scope' => $escopo,
    ], fn($v) => $v !== null && $v !== ''));
    header('Location: /login.php?next=' . urlencode($volta));
    exit;
}

/* Só o admin geral. As ferramentas de escrita aplicam direto no banco da liga
   inteira: um GM que aprovasse isso no Claude dele teria mão no time dos
   outros. Quem não é admin vê o porquê, e não um erro seco. */
if (!hasGlobalAdminAccess($pdo, (int)$user['id'])) {
    http_response_code(403);
    auPagina('Só o dono da liga', '<p class="erro">Você entrou como <b>'
        . htmlspecialchars((string)($user['name'] ?? 'usuário'))
        . '</b>. Este conector mexe na liga inteira, então só a conta de administrador geral pode liberá-lo.</p>');
    exit;
}

// ── Decisão ─────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!hash_equals((string)($_SESSION['mcp_oauth_csrf'] ?? ''), (string)($_POST['csrf'] ?? ''))) {
        auErroNaTela('Sessão expirou', 'A página ficou aberta tempo demais. Volte ao Claude e peça a conexão de novo.');
    }
    if (($_POST['decisao'] ?? '') !== 'aprovar') {
        auVoltarComErro($redirect, 'access_denied', $state);
    }

    $codigo = mcpOauthCriarCodigo($pdo, $clientId, (int)$user['id'], $redirect, $desafio, $escopo);
    mcpRegistrar($pdo, 'oauth/authorize', ['client' => $cliente['client_name'], 'user' => (int)$user['id']],
                 false, true, 'autorização concedida');

    $sep = str_contains($redirect, '?') ? '&' : '?';
    header('Location: ' . $redirect . $sep . http_build_query(array_filter([
        'code' => $codigo, 'state' => $state,
    ], fn($v) => $v !== null && $v !== '')));
    exit;
}

$_SESSION['mcp_oauth_csrf'] = bin2hex(random_bytes(16));
$campos = array_filter([
    'client_id' => $clientId, 'redirect_uri' => $redirect, 'state' => $state,
    'response_type' => 'code', 'code_challenge' => $desafio,
    'code_challenge_method' => $metodo, 'scope' => $escopo,
    'csrf' => $_SESSION['mcp_oauth_csrf'],
], fn($v) => $v !== null && $v !== '');

$escondidos = '';
foreach ($campos as $k => $v) {
    $escondidos .= '<input type="hidden" name="' . htmlspecialchars($k) . '" value="' . htmlspecialchars((string)$v) . '">';
}

auPagina('Liberar o acesso à FBA?',
    '<p class="sub"><b>' . htmlspecialchars((string)$cliente['client_name']) . '</b> quer conversar com a liga '
  . 'em nome de <b>' . htmlspecialchars((string)($user['name'] ?? '')) . '</b>.</p>'
  . '<ul>'
  . '<li>Ler elenco, cap, picks, trocas, moedas e classificação das quatro ligas</li>'
  . '<li>Mover pick e jogador entre times</li>'
  . '<li>Ajustar moedas de Free Agency</li>'
  . '<li>Rodar comandos no banco (com as travas do MCP)</li>'
  . '</ul>'
  . '<p class="aviso">As quatro últimas <b>aplicam na hora</b>, sem nova confirmação. '
  . 'Tudo fica registrado, e você corta o acesso quando quiser em '
  . '<b>/api/oauth/acessos.php</b>.</p>'
  . '<form method="post" class="linha">' . $escondidos
  . '<button type="submit" name="decisao" value="recusar" class="nao">Agora não</button>'
  . '<button type="submit" name="decisao" value="aprovar" class="sim">Liberar</button>'
  . '</form>');
