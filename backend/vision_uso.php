<?php
/**
 * OS ENVIOS DE IMAGEM POR TEMPORADA — quanto cada time já usou e como zerar.
 *
 * A leitura por foto (estatísticas e skills) tem um limite de envios por
 * temporada, contado em vision_skill_usage por time e pela temporada EM ABERTO
 * da liga. Por isso o contador só voltava a zero quando a temporada nova
 * nascia, no avançar.
 *
 * O problema (16/09/2026): a liga define os playoffs e registra a pontuação,
 * e é nessa hora que o GM quer mandar as estatísticas da temporada — mas a
 * temporada ainda é a mesma, os envios dela já foram gastos e o upload fica
 * bloqueado até o admin avançar. Agora o registro da pontuação final zera o
 * contador da liga, e a Central de correções tem um botão pra zerar na mão.
 */

/** A temporada em que o contador está correndo: a última em aberto da liga. */
function visionTemporadaDoUso(PDO $pdo, string $liga): ?int
{
    $st = $pdo->prepare("SELECT id FROM seasons WHERE league = ? AND status <> 'completed' ORDER BY id DESC LIMIT 1");
    $st->execute([strtoupper(trim($liga))]);
    $id = $st->fetchColumn();
    return $id === false ? null : (int)$id;
}

function visionGarantirTabela(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vision_skill_usage (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        team_id    INT NOT NULL,
        season_id  INT,
        count      INT NOT NULL DEFAULT 0,
        UNIQUE KEY uq_team_season (team_id, season_id)
    )");
}

/**
 * Zera os envios da temporada em aberto — da liga inteira ou de um time.
 * Apagar a linha é o mesmo que zerar: quem envia grava com upsert.
 *
 * @return int quantos times tinham envio contado
 */
function visionZerarEnvios(PDO $pdo, string $liga, ?int $teamId = null): int
{
    visionGarantirTabela($pdo);
    $temporada = visionTemporadaDoUso($pdo, $liga);
    $sql = "DELETE vu FROM vision_skill_usage vu
              JOIN teams t ON t.id = vu.team_id
             WHERE t.league = ? AND vu.season_id <=> ?";
    $args = [strtoupper(trim($liga)), $temporada];
    if ($teamId !== null) { $sql .= ' AND vu.team_id = ?'; $args[] = $teamId; }
    $st = $pdo->prepare($sql);
    $st->execute($args);
    return $st->rowCount();
}

/**
 * Zera ao registrar a pontuação final — só no PRIMEIRO registro da temporada.
 *
 * O mesmo botão serve pra corrigir um registro, e cada correção devolveria os
 * envios de novo: um admin arrumando um vice duas vezes dava oito envios a
 * todo mundo. A marca em vision_envios_zerados garante uma vez por temporada;
 * zerar de novo, se precisar, é pela Central de correções.
 *
 * @return int|null times zerados, ou null quando essa temporada já tinha zerado
 */
function visionZerarNoRegistroDaPontuacao(PDO $pdo, string $liga, int $seasonId): ?int
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS vision_envios_zerados (
        season_id INT NOT NULL PRIMARY KEY,
        zerado_em TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    $st = $pdo->prepare('INSERT IGNORE INTO vision_envios_zerados (season_id) VALUES (?)');
    $st->execute([$seasonId]);
    if ($st->rowCount() === 0) return null;
    return visionZerarEnvios($pdo, $liga);
}

/** Os times com envio contado na temporada em aberto, pra Central de correções. */
function visionEnviosDaLiga(PDO $pdo, string $liga): array
{
    visionGarantirTabela($pdo);
    $st = $pdo->prepare("SELECT t.id, TRIM(CONCAT(COALESCE(t.city,''),' ',t.name)) nome, vu.count
                           FROM vision_skill_usage vu JOIN teams t ON t.id = vu.team_id
                          WHERE t.league = ? AND vu.season_id <=> ? AND vu.count > 0
                       ORDER BY vu.count DESC, nome");
    $st->execute([strtoupper(trim($liga)), visionTemporadaDoUso($pdo, $liga)]);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}
