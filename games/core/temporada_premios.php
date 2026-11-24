<?php
require_once __DIR__ . '/temporada_motor.php';
require_once __DIR__ . '/temporada_liga.php';

/**
 * AS MOEDAS DA TEMPORADA, pagas por etapa e escaladas pela força do time.
 *
 * A graça do jogo é escolher um time ruim e levá-lo longe. Então o prêmio não
 * pode ser o mesmo pro favorito e pro lanterna: quem pega o pior elenco da
 * liga e chega ao título ganha duas vezes e meia o que ganha quem confirma o
 * favoritismo. A força é medida uma vez, no começo, e congelada — senão
 * bastaria escolher o pior time e esperar o site atualizar o elenco.
 *
 * ── RECONCILIAÇÃO, NÃO GATILHO ────────────────────────────────────────
 * Ninguém chama isto "quando o time passa de fase". Isto OLHA o chaveamento e
 * paga o que ainda não foi pago, e roda em qualquer carregamento de página do
 * save. É de propósito: o jogo tem muitos caminhos pra avançar (dia a dia,
 * simular a rodada, simular até o fim) e pendurar o pagamento em cada um
 * deles seria pendurar um esquecimento em cada um deles. Aqui, se a etapa
 * aconteceu, ela é paga na próxima vez que a pessoa olhar a tela — e paga uma
 * vez só, porque o que já saiu fica anotado em `fba_pagos`.
 */
class TemporadaPremios
{
    /** etapa => [moedas na força máxima, rótulo, rodada vencida] */
    public const ETAPAS = [
        'playoffs' => [150, 'Classificou aos playoffs', 0],
        'r1'       => [250, 'Passou da primeira rodada', 1],
        'r2'       => [350, 'Passou das semifinais de conferência', 2],
        'conf'     => [500, 'Campeão da conferência', 3],
        'titulo'   => [750, 'CAMPEÃO DA FBA', 4],
    ];

    /** Quanto o pior elenco da liga ganha a mais que o melhor. */
    private const BONUS_AZARAO = 1.5;   // força 0 paga 2,5x; força 100 paga 1x

    /** É um save do modo FBA? Fora dele nada aqui roda. */
    public static function ativo(): bool
    {
        return Database::meta('modo') === 'fba';
    }

    /**
     * A força do time do GM na escala da própria liga, de 0 (pior elenco) a
     * 100 (melhor). Medida pelos OITO primeiros, que são os que o motor põe
     * em quadra — média de elenco inteiro premiaria quem tem banco longo e
     * nenhum titular, que não é quem ganha jogo.
     *
     * Gravada no save na criação e nunca mais recalculada.
     */
    public static function calibrar(int $gmTeamId): int
    {
        $db = Database::conn();
        $linhas = $db->query(
            "SELECT team_id, ovr FROM players WHERE retired = 0 AND team_id IS NOT NULL ORDER BY team_id, ovr DESC"
        )->fetchAll();

        $topo = [];
        foreach ($linhas as $l) {
            $t = (int) $l['team_id'];
            if (count($topo[$t] ?? []) >= 8) continue;
            $topo[$t][] = (int) $l['ovr'];
        }
        $media = [];
        foreach ($topo as $t => $ovrs) $media[$t] = array_sum($ovrs) / max(1, count($ovrs));
        if (!$media) return 50;

        $pior = min($media);
        $melhor = max($media);
        $meu = $media[$gmTeamId] ?? (($pior + $melhor) / 2);

        $forca = $melhor > $pior ? (int) round(($meu - $pior) / ($melhor - $pior) * 100) : 50;
        $forca = max(0, min(100, $forca));

        arsort($media);
        $posicao = array_search($gmTeamId, array_keys($media), true);

        Database::setMeta('fba_forca', (string) $forca);
        Database::setMeta('fba_rank', (string) (($posicao === false ? 0 : $posicao) + 1));
        Database::setMeta('fba_times', (string) count($media));
        return $forca;
    }

    /** O multiplicador do azarão: 1x pro melhor elenco, 2,5x pro pior. */
    public static function multiplicador(): float
    {
        $forca = (int) Database::meta('fba_forca', '50');
        return round(1 + (1 - $forca / 100) * self::BONUS_AZARAO, 2);
    }

    /** Quanto vale cada etapa pra ESTE save, já com o multiplicador. */
    public static function tabela(): array
    {
        $mult = self::multiplicador();
        $out = [];
        foreach (self::ETAPAS as $k => [$base, $rotulo, $rodada]) {
            $out[$k] = ['moedas' => (int) round($base * $mult), 'rotulo' => $rotulo, 'rodada' => $rodada];
        }
        return $out;
    }

    /**
     * O que o time do GM já conquistou, lido do chaveamento.
     *
     * A chave é a RODADA vencida (0 = só se classificou, 4 = título), que é
     * como a tabela de etapas se liga ao bracket. Separado do pagamento de
     * propósito: a tela usa isto pra mostrar o progresso, e dá pra conferir
     * sem creditar moeda nenhuma.
     *
     * @return array<int, true>
     */
    public static function conquistadas(): array
    {
        $gm = (int) Database::meta('gm_team', '0');
        if ($gm <= 0) return [];

        $series = Database::conn()
            ->query("SELECT round, high_seed_id, low_seed_id, winner_id FROM playoff_series")
            ->fetchAll();

        $out = [];
        foreach ($series as $s) {
            $joguei = (int) $s['high_seed_id'] === $gm || (int) $s['low_seed_id'] === $gm;
            if (!$joguei) continue;
            // Estar numa série da 1ª rodada já é ter se classificado.
            if ((int) $s['round'] === 1) $out[0] = true;
            if ((int) $s['winner_id'] === $gm) $out[(int) $s['round']] = true;
        }
        return $out;
    }

    /** Etapas já pagas neste save. */
    public static function pagas(): array
    {
        $j = json_decode((string) Database::meta('fba_pagos', '[]'), true);
        return is_array($j) ? $j : [];
    }

    /**
     * Olha o chaveamento, paga o que falta e devolve o que acabou de sair.
     *
     * Nunca lança: ficar sem a moeda é chato, mas derrubar a tela do jogo por
     * causa dela seria pior.
     *
     * @return array<int, array{etapa:string,rotulo:string,moedas:int}>
     */
    public static function reconciliar(int $siteUserId): array
    {
        if (!self::ativo() || $siteUserId <= 0) return [];

        try {
            $gm = (int) Database::meta('gm_team', '0');
            if ($gm <= 0) return [];

            $conquistadas = self::conquistadas();
            if (!$conquistadas) return [];

            $pagas = self::pagas();
            $tabela = self::tabela();
            $novas = [];
            foreach ($tabela as $etapa => $info) {
                if (in_array($etapa, $pagas, true)) continue;
                if (empty($conquistadas[$info['rodada']])) continue;
                $novas[] = ['etapa' => $etapa, 'rotulo' => $info['rotulo'], 'moedas' => $info['moedas']];
            }
            if (!$novas) return [];

            $total = array_sum(array_column($novas, 'moedas'));
            if (!self::creditar($siteUserId, $total)) return [];

            // Só anota DEPOIS de a moeda entrar: se o crédito falhou, a etapa
            // continua pendente e sai na próxima vez que a pessoa abrir a tela.
            Database::setMeta('fba_pagos', json_encode(array_merge($pagas, array_column($novas, 'etapa'))));
            return $novas;
        } catch (Throwable $e) {
            error_log('[fba-premios] ' . $e->getMessage());
            return [];
        }
    }

    /**
     * Põe a moeda na conta do Games.
     *
     * `games_usuarios.id` é o mesmo id do `users` do site (ver
     * games/core/conexao.php), então o id da sessão serve direto. O perfil
     * nasce no primeiro acesso ao Games; quem nunca entrou lá ainda não tem
     * linha, e aí ela é criada a partir do cadastro — o mesmo INSERT que o
     * games faz, pra não deixar a moeda cair no vazio.
     */
    private static function creditar(int $userId, int $moedas): bool
    {
        if ($moedas <= 0) return false;
        try {
            $pdo = TemporadaLiga::fbaConn();
            $pdo->prepare(
                "INSERT IGNORE INTO games_usuarios (id, nome, email, league)
                 SELECT id, name, email, COALESCE(league, 'ROOKIE') FROM users WHERE id = ?"
            )->execute([$userId]);
            $ok = $pdo->prepare("UPDATE games_usuarios SET pontos = pontos + ? WHERE id = ?")
                      ->execute([$moedas, $userId]);
            return $ok && $pdo->query("SELECT ROW_COUNT()")->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('[fba-premios] creditar: ' . $e->getMessage());
            return false;
        }
    }
}
