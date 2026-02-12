<?php
// core/funcoes.php - A INTELIGÊNCIA DO SISTEMA 🧠

/**
 * Recalcula as odds usando "Média Ponderada" (Weighted Probability)
 * Este método é mais estável e evita que as odds oscilem loucamente.
 * * @param PDO $pdo Conexão com o banco
 * @param int $evento_id ID do evento
 * @return bool
 */
function recalcularOdds($pdo, $evento_id) {
    
    // --- CALIBRAGEM DO ALGORITMO ---
    
    // PESO DO DINHEIRO (0.0 a 1.0)
    // Base mais conservadora para evitar "derreter" favoritos.
    $peso_dinheiro_base = 0.12;
    $peso_dinheiro_max = 0.18;
    $escala_dinheiro = 500; // volume em R$ p/ o peso começar a crescer

    // MARGEM DA CASA
    // 0.90 = 10% de lucro pra casa.
    $margem_casa = 0.90; 

    // LIMITES DE DESVIO DA ODD INICIAL
    // Controla quanto a odd pode se afastar da inicial.
    $desvio_favorito = 0.20; // favorito: no máximo -20%
    $desvio_underdog = 0.30; // azarão: no máximo +30%

    try {
        // 1. Busca dados atuais
        $stmtOps = $pdo->prepare("SELECT id, odd, odd_inicial FROM opcoes WHERE evento_id = ?");
        $stmtOps->execute([$evento_id]);
        $opcoes = $stmtOps->fetchAll(PDO::FETCH_ASSOC);

        if (!$opcoes) return false;

        // 2. Coleta o volume TOTAL de dinheiro real no evento
        $total_dinheiro_evento = 0;
        $dados_opcoes = [];

        foreach ($opcoes as $op) {
            $stmtSoma = $pdo->prepare("SELECT SUM(valor) as total FROM palpites WHERE opcao_id = ?");
            $stmtSoma->execute([$op['id']]);
            $soma_real = $stmtSoma->fetch()['total'] ?? 0;
            
            // Garante a âncora na odd inicial
            $odd_base = (!empty($op['odd_inicial']) && $op['odd_inicial'] > 0) ? $op['odd_inicial'] : $op['odd'];
            
            $dados_opcoes[] = [
                'id' => $op['id'],
                'dinheiro' => $soma_real,
                'odd_inicial' => $odd_base,
                // Probabilidade Inicial (ex: Odd 2.0 = 50% ou 0.5)
                'prob_inicial' => (1 / $odd_base)
            ];

            $total_dinheiro_evento += $soma_real;
        }

        // 3. O Cálculo da Nova Odd
        $stmtUpdate = $pdo->prepare("UPDATE opcoes SET odd = :nova_odd WHERE id = :id");
        $stmtFixInicial = $pdo->prepare("UPDATE opcoes SET odd_inicial = :odd_atual WHERE id = :id AND (odd_inicial IS NULL OR odd_inicial = 0)");

    $novas_odds = [];

    foreach ($dados_opcoes as $dado) {
            
            // A. Calcula a "Opinião do Dinheiro" (Probabilidade Real)
            if ($total_dinheiro_evento > 0) {
                if ($dado['dinheiro'] == 0) {
                    // --- PROTEÇÃO CONTRA ZEBRA (NOVO!) ---
                    // Se ninguém apostou aqui, não assumimos 0% (que jogaria a odd p/ 5.00).
                    // Assumimos metade da probabilidade original. 
                    // Isso mantém a odd estável perto da inicial.
                    $prob_dinheiro = $dado['prob_inicial'] / 2;
                } else {
                    $prob_dinheiro = $dado['dinheiro'] / $total_dinheiro_evento;
                }
            } else {
                // Se não tem dinheiro nenhum no evento, mantém neutro
                $prob_dinheiro = $dado['prob_inicial'];
            }

            // B. O "CABO DE GUERRA" (Weighted Average) ⚖️
            // Mistura a Probabilidade Inicial com a Probabilidade do Dinheiro
            // Peso dinâmico aumenta conforme o volume total do evento.
            $peso_dinheiro = $peso_dinheiro_base;
            if ($total_dinheiro_evento > 0) {
                $ratio = log(1 + $total_dinheiro_evento) / log(1 + $escala_dinheiro);
                if ($ratio > 1) $ratio = 1;
                $peso_dinheiro = $peso_dinheiro_base + (($peso_dinheiro_max - $peso_dinheiro_base) * $ratio);
            }

            $nova_probabilidade = ($dado['prob_inicial'] * (1 - $peso_dinheiro)) + ($prob_dinheiro * $peso_dinheiro);

            // C. Converte Probabilidade em Odd e aplica Margem
            if ($nova_probabilidade == 0) $nova_probabilidade = 0.01; 
            $nova_odd = (1 / $nova_probabilidade) * $margem_casa;

            // --- TRAVAS DE SEGURANÇA ---
            if ($nova_odd < 1.10) $nova_odd = 1.10;
            if ($nova_odd > 5.00) $nova_odd = 5.00;

            // Limita desvio em relação à odd inicial
            $odd_inicial = $dado['odd_inicial'];
            $limite_min = $odd_inicial * (1 - $desvio_favorito);
            $limite_max = $odd_inicial * (1 + $desvio_underdog);
            if ($nova_odd < $limite_min) $nova_odd = $limite_min;
            if ($nova_odd > $limite_max) $nova_odd = $limite_max;
            // ---------------------------

            $novas_odds[$dado['id']] = $nova_odd;
        }

        // 4. Garante que o favorito inicial continue favorito
        $ordenadas_inicial = $dados_opcoes;
        usort($ordenadas_inicial, function ($a, $b) {
            if ($a['odd_inicial'] == $b['odd_inicial']) {
                return $a['id'] <=> $b['id'];
            }
            return $a['odd_inicial'] <=> $b['odd_inicial'];
        });

        $ultima_odd = null;
        foreach ($ordenadas_inicial as $dado) {
            $odd = $novas_odds[$dado['id']];
            if ($ultima_odd !== null && $odd <= $ultima_odd) {
                $odd = $ultima_odd + 0.01;
            }
            if ($odd < 1.10) $odd = 1.10;
            if ($odd > 5.00) $odd = 5.00;

            $novas_odds[$dado['id']] = $odd;
            $ultima_odd = $odd;
        }

        // 5. Atualiza no banco
        foreach ($dados_opcoes as $dado) {
            $stmtUpdate->execute([':nova_odd' => $novas_odds[$dado['id']], ':id' => $dado['id']]);

            // Correção de segurança para odd_inicial
            if (empty($dado['odd_inicial'])) {
               $stmtFixInicial->execute([':odd_atual' => $novas_odds[$dado['id']], ':id' => $dado['id']]);
            }
        }

        return true;

    } catch (Exception $e) {
        return false;
    }
}
?>
