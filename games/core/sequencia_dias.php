<?php
/**
 * CORE/SEQUENCIA_DIAS.PHP
 * Funções para gerenciar sequência de dias nos jogos (Termo, Memória, etc)
 */

/**
 * Obter sequência atual do usuário para um jogo
 */
function obterSequenciaDias($pdo, $user_id, $jogo) {
    try {
        $stmt = $pdo->prepare("SELECT sequencia_atual, ultima_jogada FROM usuario_sequencias_dias WHERE user_id = :uid AND jogo = :jogo");
        $stmt->execute([':uid' => $user_id, ':jogo' => $jogo]);
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$result) {
            return ['sequencia_atual' => 0, 'ultima_jogada' => null];
        }
        
        return $result;
    } catch (PDOException $e) {
        return ['sequencia_atual' => 0, 'ultima_jogada' => null];
    }
}

/**
 * Atualizar sequência quando o usuário ganha
 * Se jogou ontem ou hoje, incrementa. Se não jogou ontem, zera.
 */
function atualizarSequenciaDias($pdo, $user_id, $jogo, $vitoria) {
    try {
        $hoje = date('Y-m-d');
        
        // Obter sequência atual
        $stmt = $pdo->prepare("SELECT sequencia_atual, ultima_jogada FROM usuario_sequencias_dias WHERE user_id = :uid AND jogo = :jogo");
        $stmt->execute([':uid' => $user_id, ':jogo' => $jogo]);
        $resultado = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$resultado) {
            // Primeira vez jogando
            if ($vitoria) {
                $stmt = $pdo->prepare("INSERT INTO usuario_sequencias_dias (user_id, jogo, sequencia_atual, ultima_jogada) VALUES (:uid, :jogo, 1, :hoje)");
                $stmt->execute([':uid' => $user_id, ':jogo' => $jogo, ':hoje' => $hoje]);
                return 1;
            }
            return 0;
        }
        
        if (!$vitoria) {
            // Perdeu = zera sequência
            $stmt = $pdo->prepare("UPDATE usuario_sequencias_dias SET sequencia_atual = 0, ultima_jogada = :hoje WHERE user_id = :uid AND jogo = :jogo");
            $stmt->execute([':uid' => $user_id, ':jogo' => $jogo, ':hoje' => $hoje]);
            return 0;
        }
        
        // Vitória - verificar se jogou ontem ou hoje
        $ultima_jogada = $resultado['ultima_jogada'];
        $ontem = date('Y-m-d', strtotime('-1 day'));
        
        if ($ultima_jogada === $hoje) {
            // Já jogou hoje, mantém a sequência
            return $resultado['sequencia_atual'];
        } elseif ($ultima_jogada === $ontem) {
            // Jogou ontem, incrementa sequência
            $nova_sequencia = $resultado['sequencia_atual'] + 1;
            $stmt = $pdo->prepare("UPDATE usuario_sequencias_dias SET sequencia_atual = :seq, ultima_jogada = :hoje WHERE user_id = :uid AND jogo = :jogo");
            $stmt->execute([':seq' => $nova_sequencia, ':uid' => $user_id, ':jogo' => $jogo, ':hoje' => $hoje]);
            return $nova_sequencia;
        } else {
            // Pulou dias, zera sequência
            $stmt = $pdo->prepare("UPDATE usuario_sequencias_dias SET sequencia_atual = 1, ultima_jogada = :hoje WHERE user_id = :uid AND jogo = :jogo");
            $stmt->execute([':uid' => $user_id, ':jogo' => $jogo, ':hoje' => $hoje]);
            return 1;
        }
        
    } catch (PDOException $e) {
        error_log("Erro ao atualizar sequência: " . $e->getMessage());
        return 0;
    }
}

?>
