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
// A idade vem pronta do servidor: comparar o carimbo com o relógio local
// depende do fuso desta máquina, e o PHP de linha de comando do Windows
// assume Europe/Berlin quando ninguém manda nada — 5 horas de erro.
$entrada = $diag['ultima_entrada'] ?? null;
$seg = $diag['ultima_entrada_seg'] ?? null;
if ($seg === null) {
    echo "entrada   : nunca chegou mensagem de grupo\n";
} else {
    $min = (int)round($seg / 60);
    echo "entrada   : última mensagem recebida há {$min} min ({$entrada})"
       . ($min >= 25 ? "  <<< o vigia vai reconectar a Evolution" : "") . "\n";
}

// ── O worker está de pé? ─────────────────────────────────────────────
//
// A pergunta certa é "o site viu o worker agora há pouco?", não "a tarefa
// desta máquina está rodando?". Desde 17/08 o bot vive numa VPS Oracle, e a
// versão antiga deste script gritava que estava tudo parado só porque olhava
// o Windows daqui — enquanto o bot mandava mensagem normalmente de lá.
//
// bot_visto_em é carimbado a cada consulta à fila, então recente = vivo,
// rode ele onde rodar.
$visto = $diag['bot_visto_em'] ?? null;
$vistoSeg = $diag['bot_visto_seg'] ?? null;
if ($vistoSeg === null && $visto) {
    // Site antigo: cai no relógio local, que pode estar em outro fuso. Só
    // serve pra dizer "faz muito tempo", não pra medir com precisão.
    $vistoSeg = max(0, time() - strtotime($visto . ' America/Sao_Paulo'));
}
$workerVivo = $vistoSeg !== null && $vistoSeg < 180;
$worker = $vistoSeg === null
    ? 'nunca deu sinal'
    : ($workerVivo ? "de pé (sinal há {$vistoSeg}s)" : "SEM SINAL há " . (int)round($vistoSeg / 60) . " min");
echo "worker    : {$worker}\n";

// A Evolution roda na MESMA máquina do worker. Se o worker está vivo e não é
// esta máquina, não faz sentido cobrar um container local que nem deveria
// existir aqui.
$evoRemota = $workerVivo;

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
                      : ($c === 0
                          ? ($evoRemota
                              ? 'roda junto com o worker, em outra máquina'
                              : 'não respondeu (container parado?)')
                          : "HTTP {$c}");
}
echo "evolution : {$evo}\n";

// A recepção é a prova de que a Evolution está viva onde quer que esteja: se
// mensagem entrou agora há pouco, o socket dela está de pé.
$recepcaoOk = $seg !== null && $seg < 25 * 60;
$evoOk = $evo === 'open' || ($evoRemota && $recepcaoOk);

$pronto = !empty($diag['ativo']) && !empty($diag['dentro_da_janela']) && $workerVivo && $evoOk;
echo "\n" . ($pronto ? "Tudo de pé — o que entrar na fila sai."
                     : "Atenção: algo acima não está de pé; a fila pode ficar parada.") . "\n";
exit(0);
