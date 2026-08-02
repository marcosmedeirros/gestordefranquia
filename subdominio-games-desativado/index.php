<?php
/**
 * Página única do subdomínio antigo games.fbabrasil.com.br.
 *
 * O games agora vive DENTRO do fbabrasil.com.br (mesmo banco, mesmo login).
 * Qualquer acesso ao subdomínio — qualquer URL — cai aqui e só daqui sai
 * clicando pro site principal. O .htaccess ao lado reescreve tudo pra este
 * arquivo; nada do app antigo fica acessível.
 */
http_response_code(410); // Gone: avisa buscadores que o endereço antigo acabou
header('Cache-Control: no-store');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<meta name="robots" content="noindex">
<title>Games mudou de casa — FBA Brasil</title>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;600;800&display=swap" rel="stylesheet">
<style>
    :root { --red: #fc0025; --bg: #07070a; --panel: #101013; --border: rgba(255,255,255,.08); --text: #f0f0f3; --text-2: #868690; }
    * { box-sizing: border-box; margin: 0; padding: 0; }
    body {
        font-family: 'Montserrat', sans-serif; background:
            radial-gradient(900px 400px at 15% 10%, color-mix(in srgb, var(--red) 14%, transparent), transparent 55%),
            radial-gradient(800px 380px at 85% 90%, color-mix(in srgb, var(--red) 7%, transparent), transparent 55%),
            var(--bg);
        color: var(--text); min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 24px;
    }
    .card {
        max-width: 460px; width: 100%; background: var(--panel); border: 1px solid var(--border);
        border-radius: 16px; padding: 40px 32px; text-align: center; box-shadow: 0 18px 40px rgba(0,0,0,.4);
    }
    .logo {
        width: 56px; height: 56px; border-radius: 14px; background: var(--red); color: #fff;
        font-weight: 800; font-size: 20px; display: inline-flex; align-items: center; justify-content: center; margin-bottom: 18px;
    }
    h1 { font-size: 22px; font-weight: 800; margin-bottom: 10px; }
    p { font-size: 14px; color: var(--text-2); line-height: 1.6; margin-bottom: 26px; }
    a.btn {
        display: inline-block; background: var(--red); color: #fff; text-decoration: none;
        font-weight: 600; font-size: 14px; padding: 13px 28px; border-radius: 10px; transition: filter .2s;
    }
    a.btn:hover { filter: brightness(1.1); }
    .hint { margin-top: 18px; font-size: 12px; color: var(--text-2); }
    .hint b { color: var(--text); }
</style>
</head>
<body>
    <div class="card">
        <div class="logo">FBA</div>
        <h1>O Games mudou de casa 🏀</h1>
        <p>Os minigames e as apostas agora vivem dentro do <b style="color:var(--text)">fbabrasil.com.br</b>, com o mesmo login do site. Este endereço foi desativado.</p>
        <a class="btn" href="https://fbabrasil.com.br/games.php">Ir para o Games</a>
        <div class="hint">Use a conta do <b>FBA Manager</b> — não precisa criar outra.</div>
    </div>
</body>
</html>
