<?php
/**
 * Abrir e fechar as fases da liga: trades, free agency e dispensas.
 *
 * O interruptor é uma coluna em league_settings, e sempre foi fácil de virar.
 * O que não é simples são os EFEITOS: fechar trades cancela as propostas
 * pendentes, e abrir ou fechar qualquer uma delas manda push pra liga inteira.
 * Isso morava dentro do endpoint da tela do admin, então quem quisesse abrir a
 * FA de outro lugar — o bot, um cron, uma tela nova — teria que copiar a
 * regra. Regra copiada é regra que diverge: foi exatamente assim que o
 * "times atualizados" do admin passou meses contando diferente da aba Times.
 *
 * Aqui os efeitos ficam num lugar só. A tela do admin grava os toggles junto
 * com o resto da configuração, no UPDATE em lote dela, e chama
 * faseLigaAplicarEfeitos() depois; quem só quer virar uma fase chama
 * faseLigaDefinir(), que faz as duas coisas.
 */

require_once __DIR__ . '/helpers.php';

/** As três fases e a coluna de cada uma. O nome curto é o que o bot aceita. */
const FASES_LIGA = [
    'trades'    => ['coluna' => 'trades_enabled',  'nome' => 'Trades',       'push' => 'trades'],
    'fa'        => ['coluna' => 'fa_enabled',      'nome' => 'Free Agency',  'push' => 'free_agency'],
    'dispensas' => ['coluna' => 'waivers_enabled', 'nome' => 'Dispensas',    'push' => 'waivers'],
];

/** Aceita apelidos: "troca", "freeagency", "waivers"… Devolve a chave ou null. */
function faseLigaNormalizar(string $fase): ?string
{
    $f = mb_strtolower(trim($fase));
    $mapa = [
        'trade' => 'trades', 'trades' => 'trades', 'troca' => 'trades', 'trocas' => 'trades',
        'fa' => 'fa', 'freeagency' => 'fa', 'free' => 'fa', 'agencia' => 'fa', 'agência' => 'fa',
        'dispensa' => 'dispensas', 'dispensas' => 'dispensas', 'waiver' => 'dispensas', 'waivers' => 'dispensas',
    ];
    return $mapa[$f] ?? null;
}

/** Como está cada fase da liga agora. */
function faseLigaEstado(PDO $pdo, string $liga): array
{
    $st = $pdo->prepare("SELECT COALESCE(trades_enabled,1) AS trades_enabled,
                                COALESCE(fa_enabled,1) AS fa_enabled,
                                COALESCE(waivers_enabled,1) AS waivers_enabled,
                                fechar_trades_em, fechar_fa_em
                           FROM league_settings WHERE league = ?");
    $st->execute([$liga]);
    $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];

    $out = [];
    foreach (FASES_LIGA as $chave => $f) {
        $out[$chave] = [
            'nome'   => $f['nome'],
            'aberta' => (int)($r[$f['coluna']] ?? 1) === 1,
        ];
    }
    $out['_agendado'] = [
        'trades' => $r['fechar_trades_em'] ?? null,
        'fa'     => $r['fechar_fa_em'] ?? null,
    ];
    return $out;
}

/**
 * O que acontece ALÉM de virar o interruptor.
 *
 * Fechar trades cancela o que estava em aberto: proposta pendente numa janela
 * fechada é uma troca que ninguém pode aceitar e que ninguém lembra de
 * recusar. E os dois lados avisam a liga, porque fase que abre sem aviso é
 * fase que metade perde.
 *
 * Chamado pela tela do admin (depois do UPDATE em lote dela) e por
 * faseLigaDefinir(). É aqui que o comportamento mora — mudou aqui, mudou em
 * todo lugar que abre ou fecha fase.
 */
function faseLigaAplicarEfeitos(PDO $pdo, string $liga, string $fase, bool $abriu): void
{
    if ($fase === 'trades' && !$abriu) {
        foreach (['trades', 'multi_trades'] as $tabela) {
            try {
                $pdo->prepare("UPDATE {$tabela} SET status = 'cancelled' WHERE league = ? AND status = 'pending'")
                    ->execute([$liga]);
            } catch (Throwable $e) {
                error_log('[fases] cancelar ' . $tabela . ': ' . $e->getMessage());
            }
        }
    }

    $avisos = [
        'trades' => [
            true  => ['🔄 Trades abertas na ' . $liga, 'A janela de trocas está no ar. Bora negociar.', '/trades.php'],
            false => ['🔒 Trades fechadas na ' . $liga, 'A janela de trocas foi encerrada. As pendentes foram canceladas.', '/trades.php'],
        ],
        'fa' => [
            true  => ['💰 Free Agency aberta na ' . $liga, 'A janela de propostas está no ar. Corra pros free agents!', '/free-agency.php'],
            false => ['🔒 Free Agency fechada na ' . $liga, 'A janela de propostas foi encerrada.', '/free-agency.php'],
        ],
        'dispensas' => [
            true  => ['📋 Dispensas abertas na ' . $liga, 'Dá pra dar lance em quem foi dispensado.', '/dispensas.php'],
            false => ['🔒 Dispensas fechadas na ' . $liga, 'A janela de dispensas foi encerrada.', '/dispensas.php'],
        ],
    ];

    if (!isset($avisos[$fase])) return;
    [$titulo, $corpo, $url] = $avisos[$fase][$abriu];

    try {
        require_once __DIR__ . '/push.php';
        sendPushToLeague($pdo, $liga, ['title' => $titulo, 'body' => $corpo, 'url' => $url],
                         FASES_LIGA[$fase]['push']);
    } catch (Throwable $e) {
        error_log('[fases] push: ' . $e->getMessage());
    }
}

/**
 * Vira a fase e aplica os efeitos.
 *
 * @return array{mudou:bool, aberta:bool, texto:string}
 */
function faseLigaDefinir(PDO $pdo, string $liga, string $fase, bool $abrir): array
{
    if (!isset(FASES_LIGA[$fase])) {
        return ['mudou' => false, 'aberta' => false, 'texto' => 'Fase desconhecida.'];
    }
    $coluna = FASES_LIGA[$fase]['coluna'];
    $nome   = FASES_LIGA[$fase]['nome'];

    $antes = faseLigaEstado($pdo, $liga)[$fase]['aberta'];

    // Liga sem linha em league_settings existe — a primeira configuração dela
    // pode nunca ter sido salva.
    $st = $pdo->prepare('SELECT id FROM league_settings WHERE league = ?');
    $st->execute([$liga]);
    if ($st->fetchColumn()) {
        $pdo->prepare("UPDATE league_settings SET {$coluna} = ? WHERE league = ?")
            ->execute([$abrir ? 1 : 0, $liga]);
    } else {
        $pdo->prepare("INSERT INTO league_settings (league, {$coluna}) VALUES (?, ?)")
            ->execute([$liga, $abrir ? 1 : 0]);
    }

    /* Já estava assim? Não avisa a liga de novo. O push repetido é pior que
       inútil: ensina a ignorar o aviso de fase, que é justamente o que não
       pode ser ignorado. */
    if ($antes === $abrir) {
        return ['mudou' => false, 'aberta' => $abrir,
                'texto' => "{$nome} da {$liga} já " . ($abrir ? 'estava aberta.' : 'estava fechada.')];
    }

    faseLigaAplicarEfeitos($pdo, $liga, $fase, $abrir);

    return ['mudou' => true, 'aberta' => $abrir,
            'texto' => ($abrir ? "✅ *{$nome}* aberta na *{$liga}*." : "🔒 *{$nome}* fechada na *{$liga}*.")
                     . ($fase === 'trades' && !$abrir ? "\n_As propostas pendentes foram canceladas._" : '')];
}
