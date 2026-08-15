<?php
/**
 * Liga e desliga o plantão do bot pela linha de comando.
 *
 *   php bot/plantao.php            → mostra como está
 *   php bot/plantao.php sempre     → ignora a janela de horário até desligarem
 *   php bot/plantao.php 4          → libera por 4 horas
 *   php bot/plantao.php off        → volta pra janela 08:45–18:00
 *
 * Fala com o site por HTTP, autenticado pelo bot_token — o mesmo caminho do
 * worker. Não usa MySQL remoto de propósito: a Hostinger só aceita conexão de
 * IP liberado na mão, e IP residencial muda.
 *
 * Além de mexer no plantão, confere o que faz o bot funcionar de verdade: o
 * worker de pé nesta máquina e a Evolution respondendo. Plantão ligado com o
 * PC dormindo não manda mensagem nenhuma.
 */

$cfgPath = __DIR__ . '/whatsapp-local.config.php';
if (!is_file($cfgPath)) {
    fwrite(STDERR, "Falta {$cfgPath}. Rode: php bot/configurar.php\n");
    exit(1);
}
$cfg = require $cfgPath;
$site  = rtrim((string)($cfg['site_url'] ?? ''), '/');
$token = (string)($cfg['bot_token'] ?? '');
if ($site === '' || $token === '') {
    fwrite(STDERR, "site_url ou bot_token vazio em {$cfgPath}.\n");
    exit(1);
}

$modo = strtolower(trim((string)($argv[1] ?? '')));
if ($modo === '-h' || $modo === '--help') {
    fwrite(STDOUT, "uso: php bot/plantao.php [sempre|off|1..12]\n");
    exit(0);
}

/** GET/POST no site com o token do bot. */
function chamar(string $url, string $token, array $post = null): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_TIMEOUT        => 20,
    ]);
    if ($post !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($post));
    }
    $body = curl_exec($ch);
    $erro = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($body === false) return ['code' => 0, 'json' => null, 'raw' => $erro];
    return ['code' => $code, 'json' => json_decode((string)$body, true), 'raw' => (string)$body];
}

$api = $site . '/api/whatsapp-bot.php';

// ── Muda, se pediram ─────────────────────────────────────────────────
if ($modo !== '') {
    $r = chamar($api . '?action=plantao', $token, ['modo' => $modo]);
    if ($r['code'] !== 200) {
        $msg = $r['json']['erro'] ?? ($r['raw'] ?: 'sem resposta');
        fwrite(STDERR, "Não deu pra mudar o plantão (HTTP {$r['code']}): {$msg}\n");
        exit(1);
    }
}

// ── Como está agora ──────────────────────────────────────────────────
$d = chamar($api . '?action=diagnostico', $token);
if ($d['code'] !== 200) {
    fwrite(STDERR, "Site não respondeu o diagnóstico (HTTP {$d['code']}).\n");
    exit(1);
}
$diag = $d['json'] ?? [];
$plantao = $diag['plantao'] ?? ['sempre' => false, 'ate' => null];

$estado = !empty($plantao['sempre']) ? 'SEMPRE ATIVO (janela de horário não vale)'
        : (!empty($plantao['ate'])   ? 'plantão até ' . $plantao['ate']
        : 'janela normal 08:45–18:00');

echo "plantão   : {$estado}\n";
echo "bot       : " . (!empty($diag['ativo']) ? 'ligado' : 'DESLIGADO na Central da Liga') . "\n";
echo "sai agora : " . (!empty($diag['dentro_da_janela']) ? 'tudo' : 'só comando e mensagem manual') . "\n";
$fila = $diag['fila'] ?? [];
echo "fila      : " . (int)($fila['pendentes'] ?? 0) . " pendente(s)\n";

// A recepção é o lado que quebra calado: a Evolution já reportou 'open' com o
// socket de entrada morto, e nada no site denunciava.
$entrada = $diag['ultima_entrada'] ?? null;
if ($entrada === null) {
    echo "entrada   : arquivo do painel desligado, não dá pra saber\n";
} else {
    $min = (int)round((time() - strtotime($entrada)) / 60);
    echo "entrada   : última mensagem recebida há {$min} min ({$entrada})"
       . ($min > 60 ? "  <<< suspeito, veja se a Evolution ainda recebe" : "") . "\n";
}

// ── O que realmente faz o bot funcionar: esta máquina ────────────────
$worker = 'não deu pra checar';
if (stripos(PHP_OS_FAMILY, 'Windows') !== false) {
    $saida = @shell_exec('powershell -NoProfile -Command "(Get-ScheduledTask -TaskName \'FBA WhatsApp Worker\' -ErrorAction SilentlyContinue).State" 2>&1');
    $s = trim((string)$saida);
    $worker = $s === '' ? 'tarefa não encontrada' : $s;
}
echo "worker    : {$worker}\n";

$evoUrl = rtrim((string)($cfg['evolution_url'] ?? ''), '/');
$evo = 'sem evolution_url';
if ($evoUrl !== '') {
    $ch = curl_init($evoUrl . '/instance/connectionState/' . rawurlencode((string)($cfg['evolution_instancia'] ?? 'fba')));
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 6,
        CURLOPT_HTTPHEADER => ['apikey: ' . (string)($cfg['evolution_api_key'] ?? '')]]);
    $b = curl_exec($ch);
    $c = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $j = json_decode((string)$b, true);
    $estadoEvo = $j['instance']['state'] ?? ($j['state'] ?? null);
    $evo = $c === 200 ? ($estadoEvo ?: 'respondeu, estado desconhecido')
                      : ($c === 0 ? 'não respondeu (container parado?)' : "HTTP {$c}");
}
echo "evolution : {$evo}\n";

$pronto = !empty($diag['ativo']) && !empty($diag['dentro_da_janela'])
       && stripos($worker, 'Running') !== false && $evo === 'open';
echo "\n" . ($pronto ? "Tudo de pé — o que entrar na fila sai."
                     : "Atenção: algo acima não está de pé; a fila pode ficar parada.") . "\n";
exit($pronto ? 0 : 0);
