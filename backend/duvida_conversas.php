<?php
/**
 * O QUE FOI PERGUNTADO, E NÃO SÓ O QUE FOI RESPONDIDO.
 *
 * A `whatsapp_fila` guarda o texto da resposta, quem pediu e o nome do
 * comando — mas não a pergunta. Então "o bot respondeu errado" era um beco:
 * dava pra ler a resposta e não dava pra saber o que tinha sido perguntado.
 * Medido em produção: 11% das respostas do /duvida (29 de 274) diziam "não
 * consegui", "não achei" ou "não sei", e nenhuma delas era investigável.
 *
 * Aqui ficam os dois lados, juntos.
 *
 * ── E É TAMBÉM A MEMÓRIA DA CONVERSA ────────────────────────────────────
 *
 * Cada /duvida era uma pergunta solta. Alguém perguntava quem liderava a
 * liga, ele respondia, e "e o segundo?" chegava sem contexto nenhum — o bot
 * não sabia que estava no meio de uma conversa, porque não existia conversa,
 * existiam mensagens avulsas.
 *
 * As últimas trocas daqui voltam pro modelo como turnos anteriores. É a
 * mesma tabela porque é o mesmo dado: guardar a pergunta pra auditar e
 * guardar a pergunta pra lembrar seria escrever duas vezes a mesma linha.
 *
 * ── O RECORTE É POR PESSOA, DENTRO DO GRUPO ─────────────────────────────
 *
 * O histórico é de (grupo + quem falou), e não do grupo inteiro. Num grupo de
 * trinta GMs, três conversas acontecem ao mesmo tempo — juntar tudo faria o
 * "e o segundo?" de um cair em cima da pergunta de outro.
 */

/** Quantos minutos uma conversa continua sendo a mesma conversa. */
const DUVIDA_CONVERSA_JANELA = 30;

/** Quantas trocas anteriores voltam pro modelo. */
const DUVIDA_CONVERSA_TURNOS = 4;

function duvidaConversasTabela(PDO $pdo): void
{
    static $feito = false;
    if ($feito) return;
    $feito = true;
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS duvida_conversas (
            id INT AUTO_INCREMENT PRIMARY KEY,
            liga VARCHAR(20) NOT NULL,
            grupo_jid VARCHAR(120) NOT NULL,
            quem_jid VARCHAR(120) NULL,
            quem_nome VARCHAR(80) NULL,
            gatilho VARCHAR(20) NOT NULL DEFAULT 'comando',
            pergunta TEXT NOT NULL,
            resposta MEDIUMTEXT NULL,
            criado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            -- A busca é sempre 'as ultimas trocas desta pessoa neste grupo'.
            INDEX idx_fio (grupo_jid, quem_jid, criado_em),
            INDEX idx_quando (criado_em)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (Throwable $e) {
        error_log('[duvida/conversas] tabela: ' . $e->getMessage());
    }
}

/**
 * Guarda a troca. Best-effort: falhar aqui não pode derrubar a resposta que
 * já foi produzida — o GM perderia a resposta por causa do log dela.
 */
function duvidaConversaGravar(PDO $pdo, string $liga, string $grupoJid, string $quemJid,
                              ?string $quemNome, string $pergunta, string $resposta,
                              string $gatilho = 'comando'): void
{
    if (trim($pergunta) === '') return;
    try {
        duvidaConversasTabela($pdo);
        $pdo->prepare('INSERT INTO duvida_conversas
                         (liga, grupo_jid, quem_jid, quem_nome, gatilho, pergunta, resposta)
                       VALUES (?,?,?,?,?,?,?)')
            ->execute([
                mb_substr($liga, 0, 20),
                mb_substr($grupoJid, 0, 120),
                mb_substr($quemJid, 0, 120) ?: null,
                $quemNome !== null ? mb_substr($quemNome, 0, 80) : null,
                mb_substr($gatilho, 0, 20),
                mb_substr($pergunta, 0, 4000),
                mb_substr($resposta, 0, 8000),
            ]);
    } catch (Throwable $e) {
        error_log('[duvida/conversas] gravar: ' . $e->getMessage());
    }
}

/**
 * As últimas trocas desta pessoa neste grupo, da mais antiga pra mais nova.
 *
 * Só dentro da janela: conversa de ontem não é contexto de hoje, e emendar
 * "e o segundo?" numa pergunta de duas semanas atrás seria pior que não ter
 * memória nenhuma.
 *
 * @return array<int, array{pergunta:string, resposta:string}>
 */
function duvidaConversaHistorico(PDO $pdo, string $grupoJid, string $quemJid,
                                 int $minutos = DUVIDA_CONVERSA_JANELA,
                                 int $turnos = DUVIDA_CONVERSA_TURNOS): array
{
    if ($grupoJid === '' || $quemJid === '') return [];
    try {
        duvidaConversasTabela($pdo);
        // DESC no banco pra pegar as últimas, e a ordem se inverte no PHP:
        // o modelo lê a conversa na ordem em que ela aconteceu.
        $st = $pdo->prepare('SELECT pergunta, resposta FROM duvida_conversas
                              WHERE grupo_jid = ? AND quem_jid = ?
                                AND resposta IS NOT NULL AND resposta <> \'\'
                                AND criado_em > NOW() - INTERVAL ? MINUTE
                           ORDER BY id DESC LIMIT ' . max(1, $turnos));
        $st->execute([$grupoJid, $quemJid, $minutos]);
        return array_reverse($st->fetchAll(PDO::FETCH_ASSOC) ?: []);
    } catch (Throwable $e) {
        error_log('[duvida/conversas] historico: ' . $e->getMessage());
        return [];
    }
}
