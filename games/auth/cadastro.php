<?php
// cadastro.php
require '../core/conexao.php';

if ($_SERVER["REQUEST_METHOD"] == "POST") {
    $nome = $_POST['nome'];
    $email = $_POST['email'];
    $senha = $_POST['senha'];

    if(empty($nome) || empty($email) || empty($senha)){
        die("Preencha todos os campos. <a href='registrar.php'>Voltar</a>");
    }

    // Criptografa a senha antes de salvar
    $senhaHash = password_hash($senha, PASSWORD_DEFAULT);

    try {
        $sql = "INSERT INTO usuarios (nome, email, senha) VALUES (:nome, :email, :senha)";
        $stmt = $pdo->prepare($sql);
        
        $stmt->bindParam(':nome', $nome);
        $stmt->bindParam(':email', $email);
        $stmt->bindParam(':senha', $senhaHash);
        
        if ($stmt->execute()) {
            // SUCESSO: Redireciona via JavaScript para o Login (index.php)
            echo "<script>
                    alert('Conta criada com sucesso! Faça login para começar.');
                    window.location.href = 'index.php';
                  </script>";
        } else {
            echo "Erro ao cadastrar.";
        }
        
    } catch (PDOException $e) {
        if ($e->getCode() == 23000) {
            echo "<script>
                    alert('Erro: Este email já está cadastrado.');
                    window.history.back();
                  </script>";
        } else {
            echo "Erro no banco: " . $e->getMessage();
        }
    }
}
?>
