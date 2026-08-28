<?php
/**
 * A LOJINHA: o que dá pra comprar com FBA Points, e o que o GM já tem.
 *
 * Duas moedas, e elas não são a mesma coisa. As MOEDAS saem dos minigames e
 * caem todo dia; os FBA POINTS saem de acertar palpite e de converter moeda,
 * e é com eles que se compra. Ter as duas é o que faz jogar Termo de manhã
 * valer alguma coisa pro time à noite.
 *
 * O CICLO DE UM ITEM tem três estados e não dois:
 *
 *   comprado  → está no inventário, ninguém encostou
 *   usado     → o GM resgatou; some do inventário e vira pedido pro admin
 *   atendido  → o admin aplicou (deu a badge, liberou o slot)
 *
 * O "usado" existe separado do "atendido" de propósito. Sem ele, o GM
 * clicava e o item sumia sem prova de que alguém ia aplicar — e "eu comprei
 * e nunca veio" é uma discussão que não dá pra resolver sem registro.
 */

const LOJA_MOEDAS_POR_PONTO = 10;   // 1000 moedas viram 100 FBA Points

/**
 * O catálogo.
 *
 * Mora em código e não em tabela porque preço de item de liga é REGRA, e
 * regra que muda sem deixar rastro é regra que ninguém consegue discutir
 * depois. Trocar um preço aqui é um commit com data e motivo.
 *
 * `unico` marca o que não faz sentido acumular — dá pra ter dois slots de
 * leilão, não faz sentido ter duas City Editions esperando na gaveta.
 */
function lojaCatalogo(): array
{
    return [
        'slot_leilao' => [
            'nome'  => 'Slot de leilão',
            'preco' => 500,
            'icone' => 'bi-hammer',
            'cor'   => '#3b82f6',
            'desc'  => 'Um slot a mais pra colocar jogador no leilão.',
        ],
        // Aplicado na hora da compra — ver lojaAplicarAutomatico().
        'slot_waiver' => [
            'nome'  => 'Slot extra de waiver',
            'preco' => 1500,
            'icone' => 'bi-arrow-repeat',
            'cor'   => '#22c55e',
            'desc'  => 'Uma dispensa a mais nesta temporada. Cai no seu time na hora, sem esperar aprovação.',
        ],
        'badge' => [
            'nome'  => 'Badge',
            'preco' => 3500,
            'icone' => 'bi-patch-check-fill',
            'cor'   => '#f59e0b',
            'desc'  => 'Uma badge para um jogador do seu elenco.',
            'limite' => ['qtd' => 2, 'por' => 'mes'],
        ],
        'slot_gleague' => [
            'nome'  => 'Slot extra de G-League',
            'preco' => 15000,
            'icone' => 'bi-people-fill',
            'cor'   => '#a855f7',
            'desc'  => 'Uma vaga a mais na sua G-League.',
            'limite' => ['qtd' => 1, 'por' => 'sempre'],
        ],
        'city_edition' => [
            'nome'  => 'City Edition',
            'preco' => 10000,
            'icone' => 'bi-palette-fill',
            'cor'   => '#fc0025',
            'desc'  => 'Um uniforme City Edition para a sua franquia.',
            'limite' => ['qtd' => 1, 'por' => 'sempre'],
        ],
    ];
}

if (!function_exists('lojaLimiteTexto')) {
    /**
     * Como o limite aparece escrito: "compra única", "2 por mês".
     *
     * A regra tem que estar na vitrine e não só no erro depois do clique —
     * descobrir que a badge era 2 por mês DEPOIS de juntar 7.000 pontos pra
     * comprar três é o tipo de surpresa que vira reclamação.
     */
    function lojaLimiteTexto(array $item): string
    {
        $lim = $item['limite'] ?? null;
        if (!$lim) return '';
        if ($lim['por'] === 'sempre') {
            return $lim['qtd'] === 1 ? 'compra única' : $lim['qtd'] . ' por conta';
        }
        return $lim['qtd'] === 1 ? '1 por mês' : $lim['qtd'] . ' por mês';
    }
}

if (!function_exists('lojaJaComprou')) {
    /**
     * Quantos deste item o GM já comprou dentro da janela do limite.
     *
     * Conta o inventário INTEIRO, usado ou não. O 'unico' antigo só olhava o
     * que estava guardado, então bastava resgatar o uniforme pra poder
     * comprar outro — o que é o contrário de compra única.
     *
     * A janela do mês é o mês do CALENDÁRIO e não "últimos 30 dias": é o que
     * a pessoa entende por "2 por mês", e é o que dá pra conferir olhando um
     * calendário em vez de contar dias pra trás.
     */
    function lojaJaComprou(PDO $pdo, int $userId, string $itemKey, array $lim): int
    {
        $sql = "SELECT COUNT(*) FROM loja_inventario WHERE id_usuario = ? AND item_key = ?";
        if (($lim['por'] ?? '') === 'mes') {
            $sql .= " AND YEAR(comprado_em) = YEAR(NOW()) AND MONTH(comprado_em) = MONTH(NOW())";
        }
        $st = $pdo->prepare($sql);
        $st->execute([$userId, $itemKey]);
        return (int)$st->fetchColumn();
    }
}

if (!function_exists('lojaLimites')) {
    /**
     * Quanto ainda cabe de cada item, pra tela desenhar antes do clique.
     *
     * Uma consulta só pro catálogo inteiro: uma por item seria uma ida ao
     * banco por card, e a aba da loja abre junto com o resto da /games.
     *
     * @return array<string, array{restam:int, texto:string, esgotou:bool}>
     */
    function lojaLimites(PDO $pdo, int $userId): array
    {
        $out = [];
        try {
            lojaGarantirTabela($pdo);
            $st = $pdo->prepare("SELECT item_key,
                                        COUNT(*) AS total,
                                        SUM(YEAR(comprado_em) = YEAR(NOW())
                                        AND MONTH(comprado_em) = MONTH(NOW())) AS no_mes
                                   FROM loja_inventario
                                  WHERE id_usuario = ?
                                  GROUP BY item_key");
            $st->execute([$userId]);
            $contagem = [];
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $r) {
                $contagem[$r['item_key']] = ['total' => (int)$r['total'], 'mes' => (int)$r['no_mes']];
            }

            foreach (lojaCatalogo() as $chave => $item) {
                $lim = $item['limite'] ?? null;
                if (!$lim) continue;
                $ja = $lim['por'] === 'mes'
                    ? ($contagem[$chave]['mes'] ?? 0)
                    : ($contagem[$chave]['total'] ?? 0);
                $restam = max(0, (int)$lim['qtd'] - $ja);
                $out[$chave] = [
                    'restam'  => $restam,
                    'texto'   => lojaLimiteTexto($item),
                    'esgotou' => $restam === 0,
                    // A tela precisa saber a janela pra decidir se conta faz
                    // sentido: "resta 1" ao lado de "compra única" é a mesma
                    // informação dita duas vezes.
                    'por'     => $lim['por'],
                ];
            }
        } catch (Throwable $e) {
            error_log('[loja] limites: ' . $e->getMessage());
        }
        return $out;
    }
}

if (!function_exists('lojaGarantirTabela')) {
    /**
     * Cria a tabela se ela não existir — uma vez por requisição.
     *
     * O static existe porque agora TODA leitura chama isto, e mandar um
     * CREATE TABLE IF NOT EXISTS por chamada é ida ao banco de graça numa
     * página que já faz dezenas.
     */
    function lojaGarantirTabela(PDO $pdo): void
    {
        static $feito = false;
        if ($feito) return;
        $feito = true;
        $pdo->exec("CREATE TABLE IF NOT EXISTS loja_inventario (
            id           INT AUTO_INCREMENT PRIMARY KEY,
            id_usuario   INT NOT NULL,
            item_key     VARCHAR(40) NOT NULL,
            preco_pago   INT NOT NULL,
            comprado_em  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            usado_em     DATETIME NULL,
            atendido_em  DATETIME NULL,
            atendido_por INT NULL,
            obs          VARCHAR(255) NULL,
            INDEX idx_dono (id_usuario, usado_em),
            INDEX idx_fila (usado_em, atendido_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    }
}

if (!function_exists('lojaInventario')) {
    /**
     * O que o GM tem na mão — só o que ainda não foi usado.
     *
     * GARANTE A TABELA E ENGOLE O ERRO, as duas coisas, e isso não é excesso:
     * só as funções de ESCRITA criavam a tabela, e a /games chama esta aqui
     * antes de qualquer um comprar. Em produção, onde ninguém tinha comprado
     * nada ainda, a tabela não existia e a página inteira morria — os
     * minigames, as apostas e o ranking caíam junto por causa de um
     * inventário vazio.
     *
     * O catch é a segunda trava: se o CREATE falhar por permissão, a loja
     * aparece vazia e o resto da página continua de pé. Nenhuma parte desta
     * página vale derrubar as outras três.
     */
    function lojaInventario(PDO $pdo, int $userId): array
    {
        try {
            lojaGarantirTabela($pdo);
            $st = $pdo->prepare("SELECT id, item_key, preco_pago, comprado_em
                                   FROM loja_inventario
                                  WHERE id_usuario = ? AND usado_em IS NULL
                                  ORDER BY comprado_em ASC");
            $st->execute([$userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[loja] inventario: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('lojaPedidos')) {
    /** O que o GM já resgatou, esperando ou não o admin. Mesma proteção. */
    function lojaPedidos(PDO $pdo, int $userId, int $limite = 30): array
    {
        try {
            lojaGarantirTabela($pdo);
            $st = $pdo->prepare("SELECT id, item_key, usado_em, atendido_em
                                   FROM loja_inventario
                                  WHERE id_usuario = ? AND usado_em IS NOT NULL
                                  ORDER BY usado_em DESC LIMIT {$limite}");
            $st->execute([$userId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[loja] pedidos: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('lojaComprar')) {
    /**
     * Compra um item, tirando os FBA Points na mesma transação.
     *
     * O desconto e a entrega andam juntos de propósito: se o INSERT falhasse
     * depois de um UPDATE já comitado, o GM pagava e não recebia — e é o
     * tipo de erro que ninguém consegue provar depois.
     *
     * O saldo é conferido DENTRO do UPDATE (`WHERE fba_points >= ?`) e não
     * num SELECT antes. Dois cliques rápidos em duas abas passariam pelos
     * dois SELECTs antes de qualquer UPDATE rodar, e o GM levaria dois itens
     * pelo preço de um; com a condição no próprio UPDATE, o segundo não
     * altera linha nenhuma e a compra é recusada.
     *
     * @return array{ok:bool,erro:?string,saldo:?int}
     */
    function lojaComprar(PDO $pdo, int $userId, string $itemKey): array
    {
        $cat = lojaCatalogo();
        if (!isset($cat[$itemKey])) return ['ok' => false, 'erro' => 'Esse item não existe.', 'saldo' => null];
        $item = $cat[$itemKey];

        lojaGarantirTabela($pdo);

        try {
            $pdo->beginTransaction();

            // Trava a linha do GM antes de qualquer conta. O limite conferido
            // FORA da transação tem a mesma falha que o saldo tinha: duas abas
            // clicando junto passam pelas duas contagens antes de qualquer
            // INSERT existir, e as duas veem "ainda cabe" — o GM leva três
            // badges num mês de limite dois. Com o FOR UPDATE, a segunda
            // espera a primeira terminar e enxerga o INSERT dela.
            $lock = $pdo->prepare("SELECT fba_points FROM games_usuarios WHERE id = ? FOR UPDATE");
            $lock->execute([$userId]);
            if ($lock->fetchColumn() === false) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => 'Perfil de games não encontrado.', 'saldo' => null];
            }

            $lim = $item['limite'] ?? null;
            if ($lim) {
                $ja = lojaJaComprou($pdo, $userId, $itemKey, $lim);
                if ($ja >= (int)$lim['qtd']) {
                    $pdo->rollBack();
                    $erro = $lim['por'] === 'sempre'
                        ? ($lim['qtd'] === 1
                            ? $item['nome'] . ' é compra única — você já comprou o seu.'
                            : 'Você já comprou os ' . $lim['qtd'] . ' ' . $item['nome'] . ' que cabem por conta.')
                        : 'Você já comprou ' . $ja . ' ' . $item['nome'] . ' este mês. O limite é '
                          . $lim['qtd'] . ' por mês — dá pra comprar de novo no dia 1º.';
                    return ['ok' => false, 'erro' => $erro, 'saldo' => null];
                }
            }

            $up = $pdo->prepare("UPDATE games_usuarios SET fba_points = fba_points - ?
                                  WHERE id = ? AND fba_points >= ?");
            $up->execute([$item['preco'], $userId, $item['preco']]);
            if ($up->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => 'FBA Points insuficientes.', 'saldo' => null];
            }

            $pdo->prepare("INSERT INTO loja_inventario (id_usuario, item_key, preco_pago)
                           VALUES (?, ?, ?)")->execute([$userId, $itemKey, $item['preco']]);
            $inventarioId = (int)$pdo->lastInsertId();

            /*
             * ITEM QUE O SISTEMA SABE APLICAR NÃO PASSA PELO ADMIN.
             *
             * O ciclo comprado→usado→atendido existe pro que precisa de mão
             * humana (dar uma badge a um jogador, montar uma City Edition). O
             * slot de waiver não precisa: é somar 1 num contador. Fazer o GM
             * resgatar e depois esperar alguém aprovar era fila pra nada.
             *
             * Aplicado aqui dentro da transação: se o slot não subir, a compra
             * inteira volta atrás e o GM não fica sem os pontos e sem o slot.
             */
            $aplicado = lojaAplicarAutomatico($pdo, $userId, $itemKey, $inventarioId);

            $st = $pdo->prepare("SELECT fba_points FROM games_usuarios WHERE id = ?");
            $st->execute([$userId]);
            $saldo = (int)$st->fetchColumn();

            $pdo->commit();
            return ['ok' => true, 'erro' => null, 'saldo' => $saldo, 'aplicado' => $aplicado];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Não deu pra concluir a compra.', 'saldo' => null];
        }
    }
}

if (!function_exists('lojaUsar')) {
    /**
     * Resgata um item: some do inventário e entra na fila do admin.
     *
     * O WHERE carrega o dono E o "ainda não usado". Sem o dono, um id chutado
     * gastaria item dos outros; sem o "não usado", clicar duas vezes rápido
     * mandaria dois pedidos do mesmo item.
     *
     * @return array{ok:bool,erro:?string}
     */
    function lojaUsar(PDO $pdo, int $userId, int $inventarioId): array
    {
        lojaGarantirTabela($pdo);
        $up = $pdo->prepare("UPDATE loja_inventario SET usado_em = NOW()
                              WHERE id = ? AND id_usuario = ? AND usado_em IS NULL");
        $up->execute([$inventarioId, $userId]);
        if ($up->rowCount() === 0) {
            return ['ok' => false, 'erro' => 'Esse item não está mais no seu inventário.'];
        }
        return ['ok' => true, 'erro' => null];
    }
}

if (!function_exists('lojaConverter')) {
    /**
     * Moeda vira FBA Point. Dez por um.
     *
     * Converte só o que fecha: pedir 1.005 moedas gasta 1.000 e devolve 100
     * pontos, em vez de comer as cinco que sobraram. A conta do troco é feita
     * ANTES do UPDATE justamente pra o desconto ser exatamente o que virou
     * ponto — descontar o pedido e creditar o arredondado é como se some
     * moeda no caminho.
     *
     * @return array{ok:bool,erro:?string,moedas:?int,pontos:?int}
     */
    function lojaConverter(PDO $pdo, int $userId, int $moedas): array
    {
        if ($moedas < LOJA_MOEDAS_POR_PONTO) {
            return ['ok' => false, 'erro' => 'O mínimo é ' . LOJA_MOEDAS_POR_PONTO . ' moedas.', 'moedas' => null, 'pontos' => null];
        }
        $pontos = intdiv($moedas, LOJA_MOEDAS_POR_PONTO);
        $custo  = $pontos * LOJA_MOEDAS_POR_PONTO;

        try {
            $pdo->beginTransaction();
            // Mesma razão da compra: o saldo é conferido dentro do UPDATE.
            $up = $pdo->prepare("UPDATE games_usuarios
                                    SET pontos = pontos - ?, fba_points = fba_points + ?
                                  WHERE id = ? AND pontos >= ?");
            $up->execute([$custo, $pontos, $userId, $custo]);
            if ($up->rowCount() === 0) {
                $pdo->rollBack();
                return ['ok' => false, 'erro' => 'Moedas insuficientes.', 'moedas' => null, 'pontos' => null];
            }
            $st = $pdo->prepare("SELECT pontos, fba_points FROM games_usuarios WHERE id = ?");
            $st->execute([$userId]);
            $saldo = $st->fetch(PDO::FETCH_ASSOC) ?: ['pontos' => 0, 'fba_points' => 0];
            $pdo->commit();
            return ['ok' => true, 'erro' => null,
                    'moedas' => (int)$saldo['pontos'], 'pontos' => (int)$saldo['fba_points'],
                    'convertidas' => $custo, 'ganhos' => $pontos];
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            return ['ok' => false, 'erro' => 'Não deu pra converter agora.', 'moedas' => null, 'pontos' => null];
        }
    }
}

if (!function_exists('lojaFilaDoAdmin')) {
    /** Os resgates esperando alguém aplicar. */
    function lojaFilaDoAdmin(PDO $pdo, bool $incluirAtendidos = false, int $limite = 100): array
    {
        lojaGarantirTabela($pdo);
        $onde = $incluirAtendidos ? 'i.usado_em IS NOT NULL' : 'i.usado_em IS NOT NULL AND i.atendido_em IS NULL';
        $st = $pdo->query("
            SELECT i.id, i.item_key, i.usado_em, i.atendido_em, i.id_usuario,
                   g.nome AS gm, g.league,
                   CONCAT(t.city, ' ', t.name) AS time
              FROM loja_inventario i
              JOIN games_usuarios g ON g.id = i.id_usuario
              LEFT JOIN teams t ON t.user_id = i.id_usuario
             WHERE {$onde}
             ORDER BY i.usado_em ASC
             LIMIT {$limite}");
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('lojaAtender')) {
    /** O admin marca o pedido como aplicado. */
    function lojaAtender(PDO $pdo, int $inventarioId, int $adminId): bool
    {
        lojaGarantirTabela($pdo);
        $up = $pdo->prepare("UPDATE loja_inventario SET atendido_em = NOW(), atendido_por = ?
                              WHERE id = ? AND usado_em IS NOT NULL AND atendido_em IS NULL");
        $up->execute([$adminId, $inventarioId]);
        return $up->rowCount() > 0;
    }
}

if (!function_exists('lojaAplicarAutomatico')) {
    /**
     * Aplica na hora o que o sistema sabe aplicar sozinho.
     *
     * Hoje os dois slots — waiver e G-League. Cada um é um número no time, e
     * somar 1 não depende de ninguém. Marca o item como usado E atendido no mesmo passo —
     * ele nunca chega a existir no inventário nem na fila do admin, porque
     * mostrar "esperando aprovação" pra algo que já valeu seria mentira.
     *
     * Itens que precisam de mão humana (badge num jogador, City Edition)
     * continuam no caminho normal: entram no inventário e esperam o resgate.
     *
     * @return bool se aplicou (o chamador usa pra avisar na tela)
     */
    function lojaAplicarAutomatico(PDO $pdo, int $userId, string $itemKey, int $inventarioId): bool
    {
        if (!in_array($itemKey, ['slot_waiver', 'slot_gleague'], true)) return false;

        $st = $pdo->prepare('SELECT id FROM teams WHERE user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $teamId = (int)$st->fetchColumn();
        // Sem time não dá pra aplicar. O item fica no inventário e segue o
        // caminho antigo — melhor esperar o admin do que perder a compra.
        if ($teamId <= 0) return false;

        if ($itemKey === 'slot_waiver') {
            waiverGarantirColunaExtra($pdo);
            $pdo->prepare('UPDATE teams SET waivers_extra = COALESCE(waivers_extra, 0) + 1 WHERE id = ?')
                ->execute([$teamId]);
        } else {
            gleagueGarantirColunaExtra($pdo);
            $pdo->prepare('UPDATE teams SET gleague_extra = COALESCE(gleague_extra, 0) + 1 WHERE id = ?')
                ->execute([$teamId]);
        }

        $pdo->prepare("UPDATE loja_inventario
                          SET usado_em = NOW(), atendido_em = NOW()
                        WHERE id = ? AND id_usuario = ?")->execute([$inventarioId, $userId]);
        return true;
    }
}

if (!function_exists('waiverGarantirColunaExtra')) {
    /**
     * `waivers_extra`: quantas dispensas a MAIS o time comprou nesta temporada.
     *
     * Coluna separada de `waivers_used` de propósito. Dava pra "dar" o slot
     * descontando 1 do usado, mas aí o número perde o significado — ninguém
     * mais saberia quantas o time realmente gastou, e o admin que mexesse no
     * usado apagaria a compra sem perceber.
     *
     * Zera junto com waivers_used na virada da temporada: o item vale por uma
     * temporada, como está escrito na loja.
     */
    function waiverGarantirColunaExtra(PDO $pdo): void
    {
        static $ok = false;
        if ($ok) return;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM teams LIKE 'waivers_extra'")->fetch()) {
                $pdo->exec('ALTER TABLE teams ADD COLUMN waivers_extra INT NOT NULL DEFAULT 0');
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('[loja/waivers_extra] ' . $e->getMessage());
        }
    }
}

if (!function_exists('gleagueGarantirColunaExtra')) {
    /**
     * `gleague_extra`: vagas de G-League a mais que o time comprou.
     *
     * Diferente do slot de waiver, este NÃO zera na virada da temporada: a
     * loja marca o item como compra única e não diz "vale por uma temporada".
     * Quem pagou 15.000 leva a vaga pra sempre.
     */
    function gleagueGarantirColunaExtra(PDO $pdo): void
    {
        static $ok = false;
        if ($ok) return;
        try {
            if (!$pdo->query("SHOW COLUMNS FROM teams LIKE 'gleague_extra'")->fetch()) {
                $pdo->exec('ALTER TABLE teams ADD COLUMN gleague_extra INT NOT NULL DEFAULT 0');
            }
            $ok = true;
        } catch (Throwable $e) {
            error_log('[loja/gleague_extra] ' . $e->getMessage());
        }
    }
}
