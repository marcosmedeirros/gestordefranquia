<?php
/**
 * FECHAMENTO AGENDADO: o admin marca a data e a hora, e na hora fecha sozinho.
 *
 * Três coisas fecham por aqui, e cada uma já tinha o seu liga/desliga
 * espalhado: as táticas em `tactic_edit_windows.manual_closed`, as trades em
 * `league_settings.trades_enabled` e a free agency em `league_settings
 * .fa_enabled`. O que faltava era o RELÓGIO — até agora alguém tinha que
 * estar no admin, acordado, no minuto certo.
 *
 * O agendamento não é um estado novo: quando a hora chega, ele mexe
 * exatamente no mesmo campo que o botão mexe. Quem lê "trades estão
 * abertas?" continua lendo `trades_enabled` e não precisa saber que existe
 * agendamento nenhum — foi de propósito, porque são oito arquivos lendo
 * essas flags e duplicar a regra em todos era garantir que uma cópia ficaria
 * pra trás.
 *
 * O CAMPO SE LIMPA ao fechar, a pedido: um horário que já passou não é
 * informação, é entulho, e deixar ele lá faria a próxima janela nascer com
 * uma data velha no formulário.
 */

// Sem require do db.php: quem chama passa o $pdo, e o db.php agora carrega
// ESTE arquivo — as duas pontas se exigirem vira ida e volta sem motivo.

const AGENDA_CAMPOS = [
    // campo na league_settings   => [o que fecha, coluna/tabela do estado]
    'fechar_taticas_em'  => 'taticas',
    'fechar_trades_em'   => 'trades',
    'fechar_fa_em'       => 'fa',
];

if (!function_exists('agendaGarantirColunas')) {
    /** As três colunas de horário. Idempotente: roda quantas vezes quiser. */
    function agendaGarantirColunas(PDO $pdo): void
    {
        foreach (array_keys(AGENDA_CAMPOS) as $col) {
            try {
                $pdo->exec("ALTER TABLE league_settings ADD COLUMN {$col} DATETIME NULL");
            } catch (Throwable $e) {
                // Já existe. É o caso normal.
            }
        }
    }
}

if (!function_exists('agendaAplicarPendentes')) {
    /**
     * Fecha o que já venceu e limpa o horário.
     *
     * Roda uma vez por requisição, pendurada no db(). Não tem cron por trás
     * de propósito: cron nesta hospedagem já falhou em silêncio antes, e um
     * fechamento que depende de cron que ninguém agendou é um fechamento que
     * não acontece. O custo é um SELECT numa tabela de quatro linhas — e ele
     * é a porta: sem nada vencido, nada mais roda.
     *
     * @return array<int,array{league:string,o_que:string}> o que fechou agora
     */
    function agendaAplicarPendentes(PDO $pdo): array
    {
        static $jaRodou = false;
        if ($jaRodou) return [];
        $jaRodou = true;

        // A CONDIÇÃO DE VENCIMENTO MORA NO SQL, uma por campo.
        //
        // A primeira versão pegava a LINHA que tivesse qualquer coisa vencida
        // e depois fechava todo campo preenchido dela. Resultado, medido: com
        // trades e táticas vencidas e a free agency marcada pra dali a duas
        // horas, a free agency fechou junto. Com o \`WHERE\` em cada UPDATE
        // isso não tem como acontecer de novo — não existe caminho no código
        // que feche um campo sem checar o relógio dele.
        //
        // E fica tudo em SQL de propósito: a comparação acontece no mesmo
        // fuso da conexão (-03:00, definido no db()), sem passar por PHP no
        // meio. Foi por causa desse ida-e-volta de fuso que o primeiro teste
        // deste arquivo mentiu.
        $porta = 'SELECT 1 FROM league_settings
                   WHERE (fechar_taticas_em IS NOT NULL AND fechar_taticas_em <= NOW())
                      OR (fechar_trades_em  IS NOT NULL AND fechar_trades_em  <= NOW())
                      OR (fechar_fa_em      IS NOT NULL AND fechar_fa_em      <= NOW())
                   LIMIT 1';
        try {
            $temAlgo = (bool)$pdo->query($porta)->fetchColumn();
        } catch (Throwable $e) {
            // Coluna ainda não existe (deploy novo, migração não rodou).
            // Cria e tenta de novo — uma vez só, porque $jaRodou já travou.
            agendaGarantirColunas($pdo);
            try { $temAlgo = (bool)$pdo->query($porta)->fetchColumn(); }
            catch (Throwable $e2) { return []; }
        }
        if (!$temAlgo) return [];

        $fechou = [];

        // Quem fechou, pra registrar no log e pra saber quais ligas mexer na
        // tabela de táticas. Lido ANTES dos UPDATEs, que limpam os campos.
        $vencidas = static function (string $campo) use ($pdo): array {
            $st = $pdo->query("SELECT league FROM league_settings
                                WHERE {$campo} IS NOT NULL AND {$campo} <= NOW()");
            return $st->fetchAll(PDO::FETCH_COLUMN) ?: [];
        };

        foreach ($vencidas('fechar_trades_em') as $liga) $fechou[] = ['league' => $liga, 'o_que' => 'trades'];
        $pdo->exec('UPDATE league_settings SET trades_enabled = 0, fechar_trades_em = NULL
                     WHERE fechar_trades_em IS NOT NULL AND fechar_trades_em <= NOW()');

        foreach ($vencidas('fechar_fa_em') as $liga) $fechou[] = ['league' => $liga, 'o_que' => 'free agency'];
        $pdo->exec('UPDATE league_settings SET fa_enabled = 0, fechar_fa_em = NULL
                     WHERE fechar_fa_em IS NOT NULL AND fechar_fa_em <= NOW()');

        $taticas = $vencidas('fechar_taticas_em');
        if ($taticas) {
            // A janela de tática mora em OUTRA tabela, e a liga pode não ter
            // linha lá ainda — quem nunca mexeu na janela não tem.
            require_once __DIR__ . '/tatica_janela.php';
            taticaGarantirTabelaJanela($pdo);
            $ins = $pdo->prepare('INSERT INTO tactic_edit_windows (league, manual_closed) VALUES (?, 1)
                                  ON DUPLICATE KEY UPDATE manual_closed = 1');
            foreach ($taticas as $liga) {
                $ins->execute([$liga]);
                $fechou[] = ['league' => $liga, 'o_que' => 'táticas'];
            }
            $pdo->exec('UPDATE league_settings SET fechar_taticas_em = NULL
                         WHERE fechar_taticas_em IS NOT NULL AND fechar_taticas_em <= NOW()');
        }

        foreach ($fechou as $f) {
            error_log("[agenda] {$f['o_que']} fechou sozinho na {$f['league']} (horário agendado)");
        }

        return $fechou;
    }
}

if (!function_exists('agendaDaLiga')) {
    /**
     * Os três horários de uma liga, prontos pro formulário.
     *
     * Devolve no formato do `datetime-local` (sem segundos, com T no meio),
     * que é o que o input do navegador entende — converter na tela daria a
     * mesma conta escrita duas vezes, uma em PHP e outra em JS.
     *
     * @return array{fechar_taticas_em:?string,fechar_trades_em:?string,fechar_fa_em:?string}
     */
    function agendaDaLiga(PDO $pdo, string $league): array
    {
        $vazio = ['fechar_taticas_em' => null, 'fechar_trades_em' => null, 'fechar_fa_em' => null];
        try {
            $st = $pdo->prepare('SELECT fechar_taticas_em, fechar_trades_em, fechar_fa_em
                                   FROM league_settings WHERE league = ?');
            $st->execute([$league]);
            $l = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return $vazio;
        }
        if (!$l) return $vazio;

        $fmt = static function ($v) {
            if (empty($v)) return null;
            $ts = strtotime((string)$v);
            return $ts ? date('Y-m-d\TH:i', $ts) : null;
        };
        return [
            'fechar_taticas_em' => $fmt($l['fechar_taticas_em']),
            'fechar_trades_em'  => $fmt($l['fechar_trades_em']),
            'fechar_fa_em'      => $fmt($l['fechar_fa_em']),
        ];
    }
}

if (!function_exists('agendaNormalizar')) {
    /**
     * O que veio do formulário virando DATETIME — ou NULL pra desmarcar.
     *
     * Recusa data no PASSADO em vez de aceitar e fechar no mesmo segundo:
     * marcar 14h quando já são 15h quase sempre é dedo errado no dia, e
     * fechar a liga inteira por causa disso é caro demais pra ser silencioso.
     *
     * @return array{ok:bool,valor:?string,erro:?string}
     */
    function agendaNormalizar($bruto): array
    {
        if ($bruto === null || trim((string)$bruto) === '') {
            return ['ok' => true, 'valor' => null, 'erro' => null];
        }
        $ts = strtotime(str_replace('T', ' ', trim((string)$bruto)));
        if ($ts === false) {
            return ['ok' => false, 'valor' => null, 'erro' => 'Data ou hora inválida.'];
        }
        // Um minuto de folga: o admin marca "agora" e o request leva segundos.
        if ($ts < time() - 60) {
            return ['ok' => false, 'valor' => null,
                    'erro' => 'Esse horário já passou — se a ideia é fechar agora, use o botão.'];
        }
        return ['ok' => true, 'valor' => date('Y-m-d H:i:00', $ts), 'erro' => null];
    }
}
