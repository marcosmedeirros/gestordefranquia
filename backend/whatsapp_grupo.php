<?php
/**
 * AÇÕES NO GRUPO: dar e tirar admin de alguém.
 *
 * Nasceu pro leilão. O Gameplay fica restrito a administradores durante a
 * cerimônia, e quem está leiloando precisa responder ✅ ou ❌ ali — então o bot
 * promove o dono do leilão quando ele abre e rebaixa quando fecha.
 *
 * POR QUE UMA FILA, e não uma chamada direta: a Hostinger não alcança a
 * Evolution. Quem fala com ela é o worker, que vem buscar trabalho de poucos em
 * poucos segundos. Mensagem já funciona assim; aqui é a mesma ideia com outro
 * tipo de tarefa.
 *
 * REBAIXA SÓ QUEM O BOT PROMOVEU. Quem já era admin do grupo antes continua
 * admin depois — o bot não tem como saber por que aquela pessoa é admin, e
 * tirar o cargo de alguém que ele não deu é estrago que ninguém pediu.
 *
 * E REBAIXA MESMO QUE O LEILÃO TERMINE MAL: a varredura passa pelas promoções
 * que ficaram para trás e enfileira o demote. Sem ela, um encerramento que
 * falhou no meio deixaria a pessoa com poder de mexer no grupo pra sempre.
 */

require_once __DIR__ . '/db.php';

/** Quanto tempo uma promoção pode ficar de pé antes da varredura derrubar. */
const WA_GRUPO_PROMOCAO_MAX_MIN = 60;

function waGrupoTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS whatsapp_grupo_acoes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            grupo_jid VARCHAR(120) NOT NULL,
            participante VARCHAR(80) NOT NULL,
            acao ENUM('promote','demote') NOT NULL,
            origem VARCHAR(40) NULL,
            status ENUM('pendente','feita','falhou') NOT NULL DEFAULT 'pendente',
            tentativas INT NOT NULL DEFAULT 0,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            executado_em DATETIME NULL,
            erro VARCHAR(255) NULL,
            INDEX idx_pendente (status, id),
            INDEX idx_quem (grupo_jid, participante, acao, status)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    } catch (Throwable $e) {
        error_log('[wa-grupo] tabela: ' . $e->getMessage());
    }
}

/** Enfileira uma ação. Devolve false quando não há o que fazer. */
function waGrupoEnfileirar(PDO $pdo, string $grupoJid, string $participante, string $acao, ?string $origem = null): bool
{
    if (!str_ends_with($grupoJid, '@g.us') || $participante === '') return false;
    if (!in_array($acao, ['promote', 'demote'], true)) return false;

    waGrupoTabela($pdo);
    try {
        // Mesma ação já esperando pra mesma pessoa: não empilha.
        $st = $pdo->prepare("SELECT 1 FROM whatsapp_grupo_acoes
                              WHERE grupo_jid = ? AND participante = ? AND acao = ? AND status = 'pendente' LIMIT 1");
        $st->execute([$grupoJid, $participante, $acao]);
        if ($st->fetchColumn()) return true;

        $pdo->prepare('INSERT INTO whatsapp_grupo_acoes (grupo_jid, participante, acao, origem) VALUES (?,?,?,?)')
            ->execute([$grupoJid, $participante, $acao, $origem ? mb_substr($origem, 0, 40) : null]);
        return true;
    } catch (Throwable $e) {
        error_log('[wa-grupo] enfileirar: ' . $e->getMessage());
        return false;
    }
}

/** Dá admin. */
function waGrupoPromover(PDO $pdo, string $grupoJid, string $participante, ?string $origem = null): bool
{
    return waGrupoEnfileirar($pdo, $grupoJid, $participante, 'promote', $origem);
}

/**
 * Tira o admin — SÓ se foi o bot que deu.
 *
 * A promoção tem que estar registrada como feita e ainda sem rebaixamento. Sem
 * essa conferência, um leilão aberto por quem já era admin terminaria tirando
 * o cargo dele.
 */
function waGrupoRebaixar(PDO $pdo, string $grupoJid, string $participante, ?string $origem = null): bool
{
    waGrupoTabela($pdo);
    try {
        $st = $pdo->prepare("SELECT id FROM whatsapp_grupo_acoes
                              WHERE grupo_jid = ? AND participante = ? AND acao = 'promote' AND status = 'feita'
                                AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM whatsapp_grupo_acoes) d
                                                 WHERE d.grupo_jid = whatsapp_grupo_acoes.grupo_jid
                                                   AND d.participante = whatsapp_grupo_acoes.participante
                                                   AND d.acao = 'demote' AND d.id > whatsapp_grupo_acoes.id)
                           ORDER BY id DESC LIMIT 1");
        $st->execute([$grupoJid, $participante]);
        if (!$st->fetchColumn()) return false;   // o bot não promoveu: não mexe
    } catch (Throwable $e) {
        error_log('[wa-grupo] conferir promoção: ' . $e->getMessage());
        return false;
    }

    return waGrupoEnfileirar($pdo, $grupoJid, $participante, 'demote', $origem);
}

/**
 * A REDE: promoções de pé há tempo demais são desfeitas.
 *
 * Roda no mesmo pulso do worker. O prazo é generoso de propósito — um leilão
 * que se arrasta ainda precisa do dono podendo responder —, mas nenhum cargo
 * fica de pé além dele por esquecimento do sistema.
 */
function waGrupoVarredura(PDO $pdo): int
{
    waGrupoTabela($pdo);
    try {
        $st = $pdo->prepare("SELECT a.grupo_jid, a.participante, a.origem
                               FROM whatsapp_grupo_acoes a
                              WHERE a.acao = 'promote' AND a.status = 'feita'
                                AND a.executado_em < DATE_SUB(NOW(), INTERVAL ? MINUTE)
                                AND NOT EXISTS (SELECT 1 FROM (SELECT * FROM whatsapp_grupo_acoes) d
                                                 WHERE d.grupo_jid = a.grupo_jid AND d.participante = a.participante
                                                   AND d.acao = 'demote' AND d.id > a.id)");
        $st->execute([WA_GRUPO_PROMOCAO_MAX_MIN]);
        $n = 0;
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $p) {
            if (waGrupoEnfileirar($pdo, $p['grupo_jid'], $p['participante'], 'demote', $p['origem'])) {
                error_log('[wa-grupo] varredura rebaixou ' . $p['participante'] . ' em ' . $p['grupo_jid']);
                $n++;
            }
        }
        return $n;
    } catch (Throwable $e) {
        error_log('[wa-grupo] varredura: ' . $e->getMessage());
        return 0;
    }
}

/** O que o worker tem pra fazer agora. */
function waGrupoPendentes(PDO $pdo, int $limite = 10): array
{
    waGrupoTabela($pdo);
    try {
        $limite = max(1, min(50, $limite));
        $st = $pdo->query("SELECT id, grupo_jid, participante, acao FROM whatsapp_grupo_acoes
                            WHERE status = 'pendente' AND tentativas < 5
                         ORDER BY id ASC LIMIT {$limite}");
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[wa-grupo] pendentes: ' . $e->getMessage());
        return [];
    }
}

/** O worker devolve o que conseguiu fazer. */
function waGrupoResultado(PDO $pdo, array $resultados): int
{
    waGrupoTabela($pdo);
    $n = 0;
    foreach ($resultados as $r) {
        $id = (int)($r['id'] ?? 0);
        if (!$id) continue;
        try {
            if (!empty($r['ok'])) {
                $pdo->prepare("UPDATE whatsapp_grupo_acoes SET status = 'feita', executado_em = NOW(), erro = NULL
                                WHERE id = ? AND status = 'pendente'")->execute([$id]);
            } else {
                /* Falha não desiste na primeira: a Evolution cai, o grupo
                   responde devagar. Na quinta, para de tentar e fica o
                   registro — promoção que não aconteceu não precisa de
                   rebaixamento, e demote que falhou cinco vezes é coisa pra
                   olhar na mão. */
                $pdo->prepare("UPDATE whatsapp_grupo_acoes
                                  SET tentativas = tentativas + 1,
                                      erro = ?,
                                      status = IF(tentativas + 1 >= 5, 'falhou', 'pendente')
                                WHERE id = ?")
                    ->execute([mb_substr((string)($r['erro'] ?? 'erro'), 0, 255), $id]);
            }
            $n++;
        } catch (Throwable $e) {
            error_log('[wa-grupo] resultado: ' . $e->getMessage());
        }
    }
    return $n;
}
