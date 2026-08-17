<?php
/**
 * Calendário das ligas: live, prazo de FA, deadline, draft e o que mais a
 * organização marcar.
 *
 * Existe porque essas datas viviam só no grupo do WhatsApp, e quem entrava
 * depois — ou perdia a mensagem — não tinha onde consultar. "Que dia fecha o
 * FA?" era pergunta recorrente sem resposta consultável.
 *
 * Quem marca é admin da liga; quem lê é todo mundo. Por padrão a pessoa vê a
 * liga dela, e pode ligar as outras num filtro.
 */

/**
 * A cor de cada liga, definida pela organização.
 *
 * Um lugar só: a página, o filtro e o card do admin leem daqui. Espalhar
 * hexadecimal por três arquivos é como o rótulo do jogador na trade perdeu a
 * idade de um lado só.
 *
 * ELITE usa o mesmo vermelho da marca (--red).
 */
const CALENDARIO_CORES = [
    'ELITE'  => '#fc0025',
    'NEXT'   => '#22c55e',
    'RISE'   => '#3b82f6',
    'ROOKIE' => '#f59e0b',
];

/**
 * Os tipos de evento, com ícone e rótulo.
 *
 * A lista é fechada de propósito: com texto livre, "Deadline" e "deadline" e
 * "Trade Deadline" virariam três coisas e o filtro por tipo deixaria de
 * funcionar. 'outro' cobre o que não estava previsto.
 */
const CALENDARIO_TIPOS = [
    'live'        => ['rotulo' => 'Live',            'icone' => 'bi-broadcast'],
    'fa_abre'     => ['rotulo' => 'Abre o FA',       'icone' => 'bi-door-open'],
    'fa_fecha'    => ['rotulo' => 'Fecha o FA',      'icone' => 'bi-door-closed'],
    'dl_abre'     => ['rotulo' => 'Abre o deadline', 'icone' => 'bi-unlock'],
    'dl_fecha'    => ['rotulo' => 'Fecha o deadline','icone' => 'bi-lock-fill'],
    'draft'       => ['rotulo' => 'Draft',           'icone' => 'bi-trophy-fill'],
    'loteria'     => ['rotulo' => 'Loteria',         'icone' => 'bi-shuffle'],
    'temporada'   => ['rotulo' => 'Temporada',       'icone' => 'bi-calendar-range'],
    'outro'       => ['rotulo' => 'Evento',          'icone' => 'bi-calendar-event'],
];

/** As ligas que o calendário conhece, na ordem em que aparecem. */
const CALENDARIO_LIGAS = ['ELITE', 'NEXT', 'RISE', 'ROOKIE'];

function calendarioCor(?string $liga): string
{
    return CALENDARIO_CORES[strtoupper(trim((string)$liga))] ?? '#94a3b8';
}

function calendarioTipoValido(?string $t): bool
{
    return $t !== null && isset(CALENDARIO_TIPOS[$t]);
}

function calendarioRotuloTipo(?string $t): string
{
    return calendarioTipoValido($t) ? CALENDARIO_TIPOS[$t]['rotulo'] : 'Evento';
}

function calendarioIconeTipo(?string $t): string
{
    return calendarioTipoValido($t) ? CALENDARIO_TIPOS[$t]['icone'] : 'bi-calendar-event';
}

function ensureCalendarioTables(PDO $pdo): void
{
    static $ok = false;
    if ($ok) return;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS calendario_eventos (
            id INT AUTO_INCREMENT PRIMARY KEY,
            league VARCHAR(10) NOT NULL,
            tipo VARCHAR(24) NOT NULL DEFAULT 'outro',
            titulo VARCHAR(140) NOT NULL,
            -- Data e hora separadas de 'dia_inteiro' porque marcar 'fecha o FA'
            -- às 23:59 não é a mesma coisa que marcar o dia: no segundo caso a
            -- hora seria inventada e apareceria na tela como se fosse regra.
            inicio DATETIME NOT NULL,
            fim DATETIME NULL,
            dia_inteiro TINYINT(1) NOT NULL DEFAULT 0,
            link VARCHAR(500) NULL,
            descricao TEXT NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_liga_inicio (league, inicio),
            INDEX idx_inicio (inicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $ok = true;
    } catch (Throwable $e) {
        error_log('[calendario] tabelas: ' . $e->getMessage());
    }
}

/**
 * Valida o que veio da tela. Devolve [ok, dados, erro].
 *
 * O link é conferido de verdade: campo de link que aceita qualquer texto vira
 * "vai ser no zoom" gravado como URL, e a tela mostra um botão que não leva a
 * lugar nenhum.
 */
function calendarioValidar(array $e): array
{
    $liga = strtoupper(trim((string)($e['league'] ?? '')));
    if (!in_array($liga, CALENDARIO_LIGAS, true)) {
        return [false, null, 'Liga inválida.'];
    }

    $titulo = trim((string)($e['titulo'] ?? ''));
    if ($titulo === '') return [false, null, 'O evento precisa de um título.'];
    if (mb_strlen($titulo) > 140) $titulo = mb_substr($titulo, 0, 140);

    $tipo = (string)($e['tipo'] ?? 'outro');
    if (!calendarioTipoValido($tipo)) $tipo = 'outro';

    $diaInteiro = !empty($e['dia_inteiro']);

    $inicio = trim((string)($e['inicio'] ?? ''));
    if ($inicio === '') return [false, null, 'O evento precisa de uma data.'];
    // Aceita 'YYYY-MM-DD' e 'YYYY-MM-DDTHH:MM' (o que o <input> manda).
    $inicio = str_replace('T', ' ', $inicio);
    if (strlen($inicio) === 10) { $inicio .= ' 00:00:00'; $diaInteiro = true; }
    if (strlen($inicio) === 16) $inicio .= ':00';
    if (!strtotime($inicio)) return [false, null, 'Data de início inválida.'];

    $fim = trim((string)($e['fim'] ?? ''));
    if ($fim !== '') {
        $fim = str_replace('T', ' ', $fim);
        if (strlen($fim) === 10) $fim .= ' 23:59:00';
        if (strlen($fim) === 16) $fim .= ':00';
        if (!strtotime($fim)) return [false, null, 'Data de fim inválida.'];
        if (strtotime($fim) < strtotime($inicio)) {
            return [false, null, 'O fim não pode ser antes do início.'];
        }
    } else {
        $fim = null;
    }

    $link = trim((string)($e['link'] ?? ''));
    if ($link !== '') {
        if (!preg_match('#^https?://#i', $link)) $link = 'https://' . $link;
        if (!filter_var($link, FILTER_VALIDATE_URL) || mb_strlen($link) > 500) {
            return [false, null, 'O link não parece um endereço válido.'];
        }
    } else {
        $link = null;
    }

    $desc = trim((string)($e['descricao'] ?? ''));
    if ($desc === '') $desc = null;

    return [true, [
        'league' => $liga, 'tipo' => $tipo, 'titulo' => $titulo,
        'inicio' => date('Y-m-d H:i:s', strtotime($inicio)),
        'fim' => $fim ? date('Y-m-d H:i:s', strtotime($fim)) : null,
        'dia_inteiro' => $diaInteiro ? 1 : 0,
        'link' => $link, 'descricao' => $desc,
    ], ''];
}

/**
 * Eventos de um intervalo, das ligas pedidas.
 *
 * Evento com fim entra em todo dia que ele cobre — um deadline aberto de 10 a
 * 20 tem que aparecer no dia 15, senão quem olha o dia 15 acha que não há nada
 * acontecendo.
 */
function calendarioEventos(PDO $pdo, array $ligas, string $de, string $ate): array
{
    ensureCalendarioTables($pdo);
    $ligas = array_values(array_intersect(
        array_map(fn($l) => strtoupper(trim((string)$l)), $ligas),
        CALENDARIO_LIGAS
    ));
    if (!$ligas) return [];

    try {
        $ph = implode(',', array_fill(0, count($ligas), '?'));
        $st = $pdo->prepare("SELECT * FROM calendario_eventos
                             WHERE league IN ($ph)
                               AND inicio <= ?
                               AND COALESCE(fim, inicio) >= ?
                             ORDER BY inicio ASC, id ASC");
        $st->execute(array_merge($ligas, [$ate, $de]));
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[calendario] eventos: ' . $e->getMessage());
        return [];
    }
}

/** Os próximos N eventos a partir de agora, pras ligas pedidas. */
function calendarioProximos(PDO $pdo, array $ligas, int $limite = 8): array
{
    ensureCalendarioTables($pdo);
    $ligas = array_values(array_intersect(
        array_map(fn($l) => strtoupper(trim((string)$l)), $ligas),
        CALENDARIO_LIGAS
    ));
    if (!$ligas) return [];

    try {
        $ph = implode(',', array_fill(0, count($ligas), '?'));
        $limite = max(1, min(50, $limite));
        $st = $pdo->prepare("SELECT * FROM calendario_eventos
                             WHERE league IN ($ph) AND COALESCE(fim, inicio) >= NOW()
                             ORDER BY inicio ASC LIMIT {$limite}");
        $st->execute($ligas);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[calendario] proximos: ' . $e->getMessage());
        return [];
    }
}
