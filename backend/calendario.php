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
            -- Repetição guardada como REGRA, não como 52 linhas: mudar o
            -- horário da live é uma edição, não cinquenta, e desmarcar a
            -- repetição não deixa cópias órfãs pra trás.
            repete VARCHAR(10) NOT NULL DEFAULT 'nao',
            repete_ate DATE NULL,
            criado_por INT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            atualizado_em TIMESTAMP NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_liga_inicio (league, inicio),
            INDEX idx_inicio (inicio)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        // Bancos criados antes da repetição existir.
        $cols = array_column($pdo->query("SHOW COLUMNS FROM calendario_eventos")->fetchAll(PDO::FETCH_ASSOC), 'Field');
        if (!in_array('repete', $cols, true)) {
            $pdo->exec("ALTER TABLE calendario_eventos ADD COLUMN repete VARCHAR(10) NOT NULL DEFAULT 'nao'");
        }
        if (!in_array('repete_ate', $cols, true)) {
            $pdo->exec("ALTER TABLE calendario_eventos ADD COLUMN repete_ate DATE NULL");
        }
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

    $repete = (string)($e['repete'] ?? 'nao');
    if (!calendarioRepeteValido($repete)) $repete = 'nao';

    $repeteAte = trim((string)($e['repete_ate'] ?? ''));
    if ($repete === 'nao') {
        // Some com a data de fim junto: sobrando, ela reapareceria preenchida
        // se alguém religasse a repetição depois, com um valor que ninguém
        // escolheu naquele momento.
        $repeteAte = null;
    } elseif ($repeteAte !== '') {
        if (!strtotime($repeteAte)) return [false, null, 'A data até quando repete é inválida.'];
        if (strtotime($repeteAte . ' 23:59:59') < strtotime($inicio)) {
            return [false, null, 'A repetição não pode terminar antes do primeiro evento.'];
        }
        $repeteAte = date('Y-m-d', strtotime($repeteAte));
    } else {
        $repeteAte = null;
    }

    return [true, [
        'league' => $liga, 'tipo' => $tipo, 'titulo' => $titulo,
        'inicio' => date('Y-m-d H:i:s', strtotime($inicio)),
        'fim' => $fim ? date('Y-m-d H:i:s', strtotime($fim)) : null,
        'dia_inteiro' => $diaInteiro ? 1 : 0,
        'link' => $link, 'descricao' => $desc,
        'repete' => $repete, 'repete_ate' => $repeteAte,
    ], ''];
}

/** As repetições que existem. */
const CALENDARIO_REPETE = [
    'nao'     => 'Não repete',
    'semanal' => 'Toda semana',
    'mensal'  => 'Todo mês',
];

function calendarioRepeteValido(?string $r): bool
{
    return $r !== null && isset(CALENDARIO_REPETE[$r]);
}

/**
 * As ocorrências de um evento dentro de um intervalo.
 *
 * Evento sem repetição devolve ele mesmo (se cair no intervalo). Com
 * repetição, devolve uma cópia por ocorrência, cada uma com as datas
 * deslocadas e o mesmo id — a tela precisa saber qual evento é pra abrir a
 * edição, e editar a série inteira é o que a pessoa espera de "toda semana".
 *
 * Mês que não tem o dia é PULADO, não empurrado. Um evento de dia 31 marcado
 * "todo mês" não acontece em fevereiro — e empurrar pro dia 28 mudaria a data
 * de um prazo sem ninguém ter pedido. Silêncio é melhor que uma data
 * inventada.
 *
 * O intervalo é sempre limitado por quem chama, então repetição sem data de
 * fim não vira laço infinito.
 */
function calendarioOcorrencias(array $ev, string $de, string $ate): array
{
    $repete = (string)($ev['repete'] ?? 'nao');
    $iniTs = strtotime((string)$ev['inicio']);
    $fimTs = !empty($ev['fim']) ? strtotime((string)$ev['fim']) : null;
    if ($iniTs === false) return [];

    // A duração acompanha cada ocorrência: uma live de 2h na semana que vem
    // continua tendo 2h.
    $duracao = $fimTs !== null ? max(0, $fimTs - $iniTs) : null;

    $deTs  = strtotime($de);
    $ateTs = strtotime($ate);

    if (!calendarioRepeteValido($repete) || $repete === 'nao') {
        $fimEfetivo = $fimTs ?? $iniTs;
        return ($iniTs <= $ateTs && $fimEfetivo >= $deTs) ? [$ev] : [];
    }

    // Repetição acaba onde o admin disse, ou no fim do intervalo pedido.
    $limite = !empty($ev['repete_ate'])
        ? min($ateTs, strtotime((string)$ev['repete_ate'] . ' 23:59:59'))
        : $ateTs;

    $out = [];
    $hora = date('H:i:s', $iniTs);
    $diaDoMes = (int)date('j', $iniTs);

    if ($repete === 'semanal') {
        // Anda de 7 em 7 a partir do início, pulando direto pra perto do
        // intervalo em vez de iterar semana a semana desde a data original.
        $passo = 7 * 86400;
        $t = $iniTs;
        if ($t < $deTs) {
            $saltos = (int)floor(($deTs - $t) / $passo);
            $t += $saltos * $passo;
        }
        for (; $t <= $limite; $t += $passo) {
            if ($t < $iniTs) continue;
            $fimOc = $duracao !== null ? $t + $duracao : $t;
            if ($fimOc < $deTs) continue;
            $out[] = ['inicio' => $t, 'fim' => $duracao !== null ? $fimOc : null];
        }
    } else { // mensal
        $ano = (int)date('Y', $iniTs);
        $mes = (int)date('n', $iniTs);
        // Começa no mês do evento, ou no mês do início do intervalo se for
        // depois — sem varrer anos de meses que ninguém pediu.
        if ($iniTs < $deTs) {
            $ano = (int)date('Y', $deTs);
            $mes = (int)date('n', $deTs);
        }
        for ($i = 0; $i < 400; $i++) {
            // checkdate rejeita 31/02: o mês simplesmente não tem a data.
            if (checkdate($mes, $diaDoMes, $ano)) {
                $t = strtotime(sprintf('%04d-%02d-%02d %s', $ano, $mes, $diaDoMes, $hora));
                if ($t > $limite) break;
                if ($t >= $iniTs) {
                    $fimOc = $duracao !== null ? $t + $duracao : $t;
                    if ($fimOc >= $deTs) $out[] = ['inicio' => $t, 'fim' => $duracao !== null ? $fimOc : null];
                }
            } else {
                // Mês curto: confere se já passou do limite pra não girar à toa.
                $t = strtotime(sprintf('%04d-%02d-01 00:00:00', $ano, $mes));
                if ($t > $limite) break;
            }
            if (++$mes > 12) { $mes = 1; $ano++; }
        }
    }

    return array_map(function ($oc) use ($ev) {
        $copia = $ev;
        $copia['inicio'] = date('Y-m-d H:i:s', $oc['inicio']);
        $copia['fim'] = $oc['fim'] !== null ? date('Y-m-d H:i:s', $oc['fim']) : null;
        // Marca que é ocorrência de uma série: a tela avisa que editar mexe
        // em todas, em vez de a pessoa descobrir depois.
        $copia['da_serie'] = true;
        return $copia;
    }, $out);
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
        // Evento que repete começa ANTES da janela e mesmo assim aparece nela
        // — filtrar por inicio <= $ate valeria, mas o COALESCE(fim,inicio) >=
        // $de cortaria a série toda. Por isso os que repetem entram por outro
        // caminho e são filtrados na expansão.
        $st = $pdo->prepare("SELECT * FROM calendario_eventos
                             WHERE league IN ($ph)
                               AND (
                                     (repete = 'nao' AND inicio <= ? AND COALESCE(fim, inicio) >= ?)
                                  OR (repete <> 'nao' AND inicio <= ?
                                      AND (repete_ate IS NULL OR repete_ate >= ?))
                                   )
                             ORDER BY inicio ASC, id ASC");
        $st->execute(array_merge($ligas, [$ate, $de, $ate, substr($de, 0, 10)]));

        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $ev) {
            foreach (calendarioOcorrencias($ev, $de, $ate) as $oc) $out[] = $oc;
        }
        usort($out, fn($a, $b) => strcmp($a['inicio'], $b['inicio']) ?: ((int)$a['id'] <=> (int)$b['id']));
        return $out;
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

    $limite = max(1, min(50, $limite));
    // Um ano à frente é o horizonte: cobre o "todo mês" e não obriga a varrer
    // repetição sem fim. Quem marca evento pra depois disso vê no calendário.
    $agora = date('Y-m-d H:i:s');
    $ate   = date('Y-m-d 23:59:59', strtotime('+1 year'));

    $eventos = calendarioEventos($pdo, $ligas, $agora, $ate);
    return array_slice($eventos, 0, $limite);
}
