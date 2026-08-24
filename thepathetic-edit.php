<?php
/**
 * A REDAÇÃO DO THE PATHETIC
 *
 * Era uma caixa de HTML: o editor colava a página inteira e a matéria
 * seguinte apagava a anterior. Agora cada notícia é uma linha — título,
 * grau, foto, quem assina e o texto — e o grau decide o tamanho dela na capa.
 *
 * A caixa de HTML antiga não foi jogada fora: ela virou "Do arquivo", no pé
 * do jornal, e continua editável aqui embaixo.
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/helpers.php';
require_once __DIR__ . '/backend/pathetic.php';
requireAuth();

$user = getUserSession();
$ehAdminGeral = ($user['user_type'] ?? 'jogador') === 'admin';

// A REDAÇÃO É DE TODO MUNDO. Qualquer conta logada entra, escreve, publica em
// qualquer grau e mexe na matéria de qualquer um. É uma liga de gente que se
// conhece, e prender quem pode escrever a uma lista de e-mails deixava o
// jornal na mão de duas pessoas. O requireAuth() lá em cima é o portão.
//
// ESCREVER NO "ARQUIVO" CONTINUA SENDO DE ADMIN, e é a única exceção. Não é
// hierarquia editorial: é segurança. Aquele bloco sai CRU nas duas páginas do
// jornal (é o que ele sempre foi, a caixa de HTML que se colava), e
// /thepathetic.php não pede login e mora na mesma origem do app. Um
// <img onerror> gravado ali roda no navegador de quem abrir — inclusive de um
// admin logado, com o cookie de sessão dele. Quem escreve nesse campo pode,
// na prática, agir como admin.
//
// A matéria não tem esse problema: o texto dela é escapado em
// patheticTextoHtml(). Por isso o colunista escreve à vontade; o que ele não
// tem é a caixa de HTML livre.
$podeMexerNoArquivo = $ehAdminGeral;
$pdo = db();
patheticGarantirTabela($pdo);

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS site_pages (
        page_key VARCHAR(60) NOT NULL PRIMARY KEY,
        content MEDIUMTEXT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    )");
} catch (Exception $e) {}

$flash = null; $flashType = 'success';

/**
 * Guarda de escrita: todo POST tem que trazer o token que ESTA sessão emitiu.
 *
 * Sem isso, um link numa mensagem qualquer poderia despublicar a manchete de
 * quem estivesse logado — o navegador manda o cookie de sessão sozinho, e o
 * servidor não tem como saber que o clique não foi na nossa tela.
 */
if (empty($_SESSION['pathetic_token'])) {
    $_SESSION['pathetic_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['pathetic_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $acao = (string)($_POST['acao'] ?? '');
    $ok   = hash_equals($token, (string)($_POST['token'] ?? ''));

    if (!$ok) {
        $flash = 'Sessão expirada. Abra a página de novo e tente outra vez.';
        $flashType = 'danger';
    } else try {
        switch ($acao) {

        // ── Escrever / editar ────────────────────────────────────────
        case 'salvar':
            $id       = (int)($_POST['id'] ?? 0);
            $titulo   = trim((string)($_POST['titulo'] ?? ''));
            $chapeu   = trim((string)($_POST['chapeu'] ?? ''));
            $grau     = patheticGrauValido($_POST['grau'] ?? '');
            $resumo   = trim((string)($_POST['resumo'] ?? ''));
            $texto    = trim((string)($_POST['texto'] ?? ''));
            // A capa vem só de arquivo agora. O campo de URL saiu da tela: uma
            // matéria que depende de imagem hospedada fora quebra no dia em que
            // o outro site sair do ar, e ninguém percebe até alguém abrir.
            // O ramo continua aqui pra não invalidar as matérias antigas que já
            // guardaram URL — o que ele não faz mais é receber URL nova.
            $fotoUrl  = '';
            $credito  = trim((string)($_POST['foto_credito'] ?? ''));
            $publicar = !empty($_POST['publicar']);

            if ($titulo === '') throw new RuntimeException('A notícia precisa de um título.');

            $antiga = $id > 0 ? patheticUma($pdo, $id, true) : null;
            if ($id > 0 && !$antiga) throw new RuntimeException('Essa notícia não existe mais.');

            // A URL colada só é aceita se for http(s): um `javascript:` no src
            // de uma <img> não roda, mas o mesmo campo pode virar link amanhã.
            if ($fotoUrl !== '' && !preg_match('#^https?://#i', $fotoUrl)) {
                throw new RuntimeException('A URL da foto precisa começar com http:// ou https://.');
            }

            if ($id > 0) {
                $pdo->prepare("UPDATE pathetic_noticias
                               SET titulo=?, chapeu=?, grau=?, resumo=?, texto=?, foto_credito=?
                               WHERE id=?")
                    ->execute([$titulo, $chapeu ?: null, $grau, $resumo ?: null, $texto ?: null, $credito ?: null, $id]);
            } else {
                $pdo->prepare("INSERT INTO pathetic_noticias
                               (titulo, chapeu, grau, resumo, texto, foto_credito, autor_id, autor_nome)
                               VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$titulo, $chapeu ?: null, $grau, $resumo ?: null, $texto ?: null, $credito ?: null,
                               (int)$user['id'], trim((string)($user['name'] ?? 'Redação')) ?: 'Redação']);
                $id = (int)$pdo->lastInsertId();
            }

            // A foto vem depois do INSERT porque o nome do arquivo usa o id —
            // é o que garante que duas notícias nunca briguem pelo mesmo nome.
            $fotoAtual = (string)($antiga['foto'] ?? '');
            // O ERRO DE FOTO NÃO DERRUBA A NOTÍCIA. Ele derrubava: o INSERT
            // já tinha sido commitado quando a exceção subia, o formulário era
            // redesenhado em branco (porque veio de ?nova=1 e não há $editando)
            // e o editor redigitava tudo — nascendo um segundo rascunho com o
            // mesmo texto. A cada foto recusada, mais um fantasma na lista.
            //
            // A notícia é o trabalho; a foto é um anexo. Salva o texto, avisa
            // que a foto não entrou, e a pessoa troca a foto editando.
            $avisoDaFoto = null;
            if (!empty($_FILES['foto']['name'])) {
                [$caminho, $erroFoto] = patheticSalvarFoto($_FILES['foto'], $id);
                if ($erroFoto) $avisoDaFoto = $erroFoto;
                if ($caminho) {
                    patheticApagarFoto($fotoAtual);
                    $pdo->prepare("UPDATE pathetic_noticias SET foto=? WHERE id=?")->execute([$caminho, $id]);
                    $fotoAtual = $caminho;
                }
            } elseif ($fotoUrl !== '' && $fotoUrl !== $fotoAtual) {
                patheticApagarFoto($fotoAtual);
                $pdo->prepare("UPDATE pathetic_noticias SET foto=? WHERE id=?")->execute([$fotoUrl, $id]);
                $fotoAtual = $fotoUrl;
            } elseif (!empty($_POST['tirar_foto'])) {
                patheticApagarFoto($fotoAtual);
                $pdo->prepare("UPDATE pathetic_noticias SET foto=NULL WHERE id=?")->execute([$id]);
            }

            $flash = $antiga ? 'Notícia atualizada.' : 'Notícia criada.';

            // O CHECKBOX É UM INTERRUPTOR, e agora liga E desliga. Ele nascia
            // marcado quando a notícia estava no ar e o texto de ajuda dizia
            // "sem marcar, ela fica de rascunho" — mas só o ramo positivo
            // existia: desmarcar e salvar deixava a notícia no ar do mesmo
            // jeito, sem dizer nada.
            if ($publicar) {
                $pdo->prepare("UPDATE pathetic_noticias
                               SET publicada=1, publicada_em=COALESCE(publicada_em, NOW())
                               WHERE id=?")->execute([$id]);
                $flash .= ' Publicada.';
                $flash .= patheticTextoDoAviso(patheticAvisarGrupo($pdo, patheticUma($pdo, $id, true) ?: []), $flashType);
            } elseif ($antiga && !empty($antiga['publicada'])) {
                $pdo->prepare("UPDATE pathetic_noticias SET publicada=0 WHERE id=?")->execute([$id]);
                $flash .= ' Tirada do ar — voltou a ser rascunho.';
            }

            if ($avisoDaFoto) { $flash .= ' A foto não entrou: ' . $avisoDaFoto; $flashType = 'warning'; }
            break;

        // ── Publicar / tirar do ar ───────────────────────────────────
        case 'publicar':
            $id = (int)($_POST['id'] ?? 0);
            $pdo->prepare("UPDATE pathetic_noticias
                           SET publicada=1, publicada_em=COALESCE(publicada_em, NOW())
                           WHERE id=?")->execute([$id]);
            $flash = 'Notícia publicada.';
            $flash .= patheticTextoDoAviso(patheticAvisarGrupo($pdo, patheticUma($pdo, $id, true) ?: []), $flashType);
            break;

        // Reenviar o aviso de uma notícia que já está no ar. Existe porque o
        // WhatsApp pode estar desligado na hora de publicar, e sem isto a
        // única saída era abrir a matéria e salvar de novo — coisa que
        // ninguém faz, porque nada avisava que o envio tinha falhado.
        case 'avisar':
            $r = patheticAvisarGrupo($pdo, patheticUma($pdo, (int)($_POST['id'] ?? 0), true) ?: []);
            $flash = $r === 'ok' ? 'Aviso enviado no grupo.' : 'Nada foi enviado.';
            $flash .= patheticTextoDoAviso($r, $flashType);
            break;

        case 'despublicar':
            $pdo->prepare("UPDATE pathetic_noticias SET publicada=0 WHERE id=?")
                ->execute([(int)($_POST['id'] ?? 0)]);
            $flash = 'Notícia tirada do ar. Ela continua salva como rascunho.';
            break;

        // A galeria da matéria: subir e tirar foto do meio do texto.
        case 'add_foto': {
            $id = (int)($_POST['id'] ?? 0);

            // MATÉRIA AINDA NÃO EXISTE: cria como rascunho agora. É o que faz
            // "adicionar imagem a qualquer momento" ser verdade — antes a
            // galeria só aparecia depois de salvar, e quem queria uma foto no
            // segundo parágrafo tinha que salvar, voltar e procurar o lugar.
            // O que já estava escrito na tela vai junto, senão o rascunho
            // nasceria vazio e a pessoa perderia o texto ao ser redirecionada.
            if ($id <= 0) {
                $tit = trim((string)($_POST['rascunho_titulo'] ?? ''));
                $pdo->prepare("INSERT INTO pathetic_noticias
                               (titulo, chapeu, grau, resumo, texto, autor_id, autor_nome, publicada)
                               VALUES (?,?,?,?,?,?,?,0)")
                    ->execute([
                        $tit !== '' ? mb_substr($tit, 0, 180) : 'Rascunho sem título',
                        mb_substr(trim((string)($_POST['rascunho_chapeu'] ?? '')), 0, 60) ?: null,
                        'noticia',
                        mb_substr(trim((string)($_POST['rascunho_resumo'] ?? '')), 0, 400) ?: null,
                        (string)($_POST['rascunho_texto'] ?? '') ?: null,
                        (int)$user['id'],
                        trim((string)($user['name'] ?? 'Redação')) ?: 'Redação',
                    ]);
                $id = (int)$pdo->lastInsertId();
            }

            if (!patheticUma($pdo, $id, true)) throw new RuntimeException('Essa notícia não existe.');
            $erro = patheticAdicionarFoto($pdo, $id, $_FILES['foto_galeria'] ?? [], (string)($_POST['legenda'] ?? ''));
            if ($erro) throw new RuntimeException($erro);
            $flash = 'Foto adicionada à galeria.';
            $_SESSION['pathetic_volta'] = '/thepathetic-edit.php?editar=' . $id;
            break;
        }

        case 'tirar_foto': {
            $id = (int)($_POST['id'] ?? 0);
            patheticRemoverFoto($pdo, (int)($_POST['foto'] ?? 0), $id);
            $flash = 'Foto removida. Se ela estava no texto, a marca some sozinha.';
            $_SESSION['pathetic_volta'] = '/thepathetic-edit.php?editar=' . $id;
            break;
        }

        // Moderar: apagar um comentário de qualquer notícia. Quem edita o
        // jornal responde pelo que aparece embaixo dele.
        case 'apagar_comentario':
            patheticApagarComentario($pdo, (int)($_POST['comentario'] ?? 0), (int)$user['id'], true);
            $flash = 'Comentário apagado.';
            break;

        case 'apagar':
            $id = (int)($_POST['id'] ?? 0);
            $n = patheticUma($pdo, $id, true);
            if ($n) {
                patheticApagarFoto($n['foto'] ?? '');
                $pdo->prepare("DELETE FROM pathetic_noticias WHERE id=?")->execute([$id]);
                $flash = 'Notícia apagada.';
            }
            break;

        // ── O arquivo (a caixa de HTML antiga) ───────────────────────
        case 'arquivo':
            // O conteúdo daqui sai sem escape em duas páginas públicas — ver
            // o comentário do $podeMexerNoArquivo lá em cima.
            if (!$podeMexerNoArquivo) {
                throw new RuntimeException('Só o admin geral edita o bloco do arquivo.');
            }
            $conteudo = (string)($_POST['content'] ?? '');
            $pdo->prepare("INSERT INTO site_pages (page_key, content) VALUES ('thepathetic', ?)
                           ON DUPLICATE KEY UPDATE content = ?, updated_at = CURRENT_TIMESTAMP")
                ->execute([$conteudo, $conteudo]);
            $flash = 'Arquivo salvo.';
            break;
        }
    } catch (Throwable $e) {
        $flash = $e instanceof RuntimeException ? $e->getMessage() : 'Erro ao salvar. Veja o log.';
        $flashType = 'danger';
        if (!($e instanceof RuntimeException)) error_log('[pathetic-edit] ' . $e->getMessage());
    }

    // Redireciona depois de escrever (PRG): sem isto, dar F5 na tela de
    // sucesso reenvia o POST e publica a mesma notícia de novo.
    if ($flashType !== 'danger') {
        // O TIPO viaja junto com o texto. Sem isto, um aviso amarelo ("o
        // grupo não foi avisado") chegava do outro lado do redirect pintado
        // de verde, dizendo sucesso.
        $_SESSION['pathetic_flash'] = $flash;
        $_SESSION['pathetic_flash_tipo'] = $flashType;
        // Mexer na galeria devolve pra MATÉRIA, e não pra lista: quem acabou
        // de subir uma foto vai inseri-la no texto agora, não daqui a pouco.
        $volta = $_SESSION['pathetic_volta'] ?? '/thepathetic-edit.php';
        unset($_SESSION['pathetic_volta']);
        header('Location: ' . $volta);
        exit;
    }
}

if (!empty($_SESSION['pathetic_flash'])) {
    $flash = $_SESSION['pathetic_flash'];
    $flashType = $_SESSION['pathetic_flash_tipo'] ?? 'success';
    unset($_SESSION['pathetic_flash'], $_SESSION['pathetic_flash_tipo']);
}

// ── O que a tela mostra ──────────────────────────────────────────────
$editandoId = isset($_GET['editar']) ? (int)$_GET['editar'] : 0;
$editando   = $editandoId > 0 ? patheticUma($pdo, $editandoId, true) : null;
$novaMateria = isset($_GET['nova']) || $editando;

$todas = [];
try {
    $todas = $pdo->query("SELECT * FROM pathetic_noticias
                          ORDER BY publicada ASC, COALESCE(publicada_em, criada_em) DESC, id DESC")
                 ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { error_log('[pathetic-edit] listar: ' . $e->getMessage()); }

// ── OS FILTROS DA LISTA ────────────────────────────────────────────
// Com a redação aberta a todo mundo, a lista deixa de ser "as minhas cinco
// matérias" e vira o arquivo do jornal inteiro, com vários autores. Sem filtro,
// achar a própria matéria de ontem custa uma rolagem.
//
// Filtram em PHP e não no navegador porque $todas já está na mão: filtrar aqui
// é uma linha, e no cliente seria markup escondido que ainda pesa na página.
$fGrau  = (string)($_GET['grau']  ?? '');
$fAutor = (string)($_GET['autor'] ?? '');
$fBusca = trim((string)($_GET['b'] ?? ''));
if (!isset(PATHETIC_GRAU_INFO[$fGrau])) $fGrau = '';

// A lista de autores sai das matérias que existem, e não de uma tabela de
// usuários: quem nunca escreveu não precisa aparecer no filtro.
$autores = [];
foreach ($todas as $n) {
    $a = trim((string)$n['autor_nome']);
    if ($a !== '') $autores[$a] = true;
}
$autores = array_keys($autores);
sort($autores, SORT_NATURAL | SORT_FLAG_CASE);

$filtradas = array_values(array_filter($todas, function ($n) use ($fGrau, $fAutor, $fBusca) {
    if ($fGrau !== ''  && $n['grau'] !== $fGrau) return false;
    if ($fAutor !== '' && trim((string)$n['autor_nome']) !== $fAutor) return false;
    if ($fBusca !== '') {
        $palheiro = mb_strtolower(($n['titulo'] ?? '') . ' ' . ($n['chapeu'] ?? '')
                  . ' ' . ($n['resumo'] ?? '') . ' ' . ($n['texto'] ?? ''));
        if (mb_strpos($palheiro, mb_strtolower($fBusca)) === false) return false;
    }
    return true;
}));
$temFiltro = ($fGrau !== '' || $fAutor !== '' || $fBusca !== '');

$rascunhos  = array_values(array_filter($filtradas, fn($n) => empty($n['publicada'])));
$noAr       = array_values(array_filter($filtradas, fn($n) => !empty($n['publicada'])));

// A fila da moderação: os comentários mais recentes do jornal inteiro.
$comentariosRecentes = patheticComentariosRecentes($pdo, 60);

// Curtidas e comentários de tudo que a lista mostra, numa consulta só. As
// views já vêm na própria linha da notícia.
$socialDaLista = patheticContagens($pdo, array_column($filtradas, 'id'));

$currentContent = '';
try {
    $st = $pdo->prepare("SELECT content FROM site_pages WHERE page_key = 'thepathetic' LIMIT 1");
    $st->execute();
    $currentContent = (string)($st->fetchColumn() ?: '');
} catch (Exception $e) {}

$team = null;
try {
    $stmtT = $pdo->prepare('SELECT * FROM teams WHERE user_id = ? LIMIT 1');
    $stmtT->execute([$user['id']]);
    $team = $stmtT->fetch() ?: null;
} catch (Exception $e) {}

$esc = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
    <meta charset="UTF-8">
    <script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0">
    <title>The Pathetic — Editor · FBA Manager</title>

    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="/css/styles.css">

    <style>
        :root {
            --red:#fc0025; --red-soft:color-mix(in srgb, var(--red) 10%, transparent); --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
            --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
            --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10); --border-red:color-mix(in srgb, var(--red) 22%, transparent);
            --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
            --green:#22c55e;
            --sidebar-w:260px; --font:'Montserrat', sans-serif;
            --radius:14px; --radius-sm:10px; --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
        }
        :root[data-theme="light"] {
            --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
            --border:#e3e6ee; --border-md:#d7dbe6; --border-red:color-mix(in srgb, var(--red) 18%, transparent);
            --text:#111217; --text-2:#5b6270; --text-3:#657080;
        }
        *,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
        html,body{height:100%;}
        body{font-family:var(--font);background:var(--bg);color:var(--text);-webkit-font-smoothing:antialiased;}
        .app{display:flex;min-height:100vh;}

        .sidebar{position:fixed;top:0;left:0;width:260px;height:100vh;background:var(--panel);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:300;transition:transform var(--t) var(--ease);overflow-y:auto;scrollbar-width:none;}
        .sidebar::-webkit-scrollbar{display:none;}
        .sb-brand{padding:22px 18px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:12px;flex-shrink:0;}
        .sb-logo{width:34px;height:34px;border-radius:9px;background:var(--red);display:flex;align-items:center;justify-content:center;font-weight:800;font-size:13px;color:#fff;flex-shrink:0;}
        .sb-brand-text{font-weight:700;font-size:15px;line-height:1.1;}
        .sb-brand-text span{display:block;font-size:11px;font-weight:400;color:var(--text-2);}
        .sb-team{margin:14px 14px 0;background:var(--panel-2);border:1px solid var(--border);border-radius:var(--radius-sm);padding:14px;display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-team img{width:40px;height:40px;border-radius:9px;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-team-name{font-size:13px;font-weight:600;color:var(--text);line-height:1.2;}
        .sb-team-league{font-size:11px;color:var(--red);font-weight:600;}
        .sb-nav{flex:1;padding:12px 10px 8px;}
        .sb-section{font-size:10px;font-weight:600;letter-spacing:1.2px;text-transform:uppercase;color:var(--text-3);padding:12px 10px 6px;}
        .sb-nav a { font-family:'Inter',sans-serif;display:flex;align-items:center;gap:10px;padding:10px 10px;border-radius:var(--radius-sm);color:var(--text-2);font-size:13px;font-weight:500;text-decoration:none;margin-bottom:2px;transition:all var(--t) var(--ease);}
        .sb-nav a i{font-size:15px;width:18px;text-align:center;flex-shrink:0;}
        .sb-nav a:hover{background:var(--panel-2);color:var(--text);}
        .sb-nav a.active{background:var(--red-soft);color:var(--red);font-weight:600;}
        .sb-nav a.active i{color:var(--red);}
        .sb-theme-toggle{margin:0 14px 12px;padding:8px 10px;border-radius:10px;border:1px solid var(--border);background:var(--panel-2);color:var(--text);display:flex;align-items:center;justify-content:center;gap:8px;font-size:12px;font-weight:600;cursor:pointer;transition:all var(--t) var(--ease);}
        .sb-theme-toggle:hover{border-color:var(--border-red);color:var(--red);}
        .sb-footer{padding:12px 14px;border-top:1px solid var(--border);display:flex;align-items:center;gap:10px;flex-shrink:0;}
        .sb-avatar{width:30px;height:30px;border-radius:50%;object-fit:cover;border:1px solid var(--border-md);flex-shrink:0;}
        .sb-username{font-size:12px;font-weight:500;color:var(--text);flex:1;min-width:0;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
        .sb-logout{width:26px;height:26px;border-radius:7px;background:transparent;border:1px solid var(--border);color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:12px;cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex-shrink:0;}
        .sb-logout:hover{background:var(--red-soft);border-color:var(--red);color:var(--red);}

        .topbar{display:none;position:fixed;top:0;left:0;right:0;height:54px;background:var(--panel);border-bottom:1px solid var(--border);align-items:center;padding:0 16px;gap:12px;z-index:240;}
        .topbar-title{font-weight:700;font-size:15px;flex:1;}
        .topbar-title em{color:var(--red);font-style:normal;}
        .menu-btn{width:34px;height:34px;border-radius:9px;background:var(--panel-2);border:1px solid var(--border);color:var(--text);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:17px;}
        .sb-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);backdrop-filter:blur(4px);z-index:250;}
        .sb-overlay.show{display:block;}

        .main{margin-left:var(--sidebar-w);min-height:100vh;width:calc(100% - var(--sidebar-w));display:flex;flex-direction:column;}
        .page-hero{padding:32px 32px 0;display:flex;align-items:flex-start;justify-content:space-between;gap:16px;flex-wrap:wrap;}
        .page-eyebrow{font-size:11px;font-weight:600;letter-spacing:1.4px;text-transform:uppercase;color:var(--red);margin-bottom:4px;}
        .page-title{font-size:26px;font-weight:800;line-height:1.1;}
        .page-sub{font-size:13px;color: var(--text);margin-top:4px;}
        .content{padding:24px 32px 40px;flex:1;}

        .bc{background:var(--panel);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
        .bc-head{padding:16px 18px 14px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:8px;}
        .bc-title{font-size:13px;font-weight:700;display:flex;align-items:center;gap:8px;}
        .bc-title i{color:var(--red);font-size:15px;}
        .bc-body{padding:18px;}

        .flash{border-radius:var(--radius-sm);padding:12px 16px;font-size:13px;font-weight:500;display:flex;align-items:center;gap:10px;}
        .flash.success{background:rgba(34,197,94,.10);border:1px solid rgba(34,197,94,.25);color:var(--green);}
        .flash.danger{background:var(--red-soft);border:1px solid var(--border-red);color:var(--red);}
        /* O amarelo do "publicou, mas o aviso não saiu": não é erro (a notícia
           está no ar) nem sucesso limpo (o grupo não soube). */
        .flash.warning{background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);color:#b45309;}
        :root:not([data-theme="light"]) .flash.warning{color:#f59e0b;}

        .btn-save{background:var(--red);border:none;color:#fff;font-family:var(--font);font-size:13px;font-weight:700;padding:10px 28px;border-radius:var(--radius-sm);cursor:pointer;transition:filter var(--t) var(--ease);}
        .btn-save:hover{filter:brightness(1.1);}
        .btn-outline{background:transparent;border:1px solid var(--border-md);color:var(--text);font-family:var(--font);font-size:13px;font-weight:600;padding:10px 20px;border-radius:var(--radius-sm);cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;display:inline-flex;align-items:center;gap:6px;}
        .btn-outline:hover{border-color:var(--border-red);color:var(--red);}

        .html-area{width:100%;min-height:440px;resize:vertical;font-family:monospace;font-size:13px;background:var(--panel-2);border:1px solid var(--border-md);border-radius:var(--radius-sm);padding:14px;color:var(--text);outline:none;transition:border-color var(--t);line-height:1.6;}
        .html-area:focus{border-color:var(--red);}

        @media(max-width:992px){
            :root{--sidebar-w:0px;}
            .sidebar{transform:translateX(-260px);}
            .sidebar.open{transform:translateX(0);}
            .main{margin-left:0;width:100%;padding-top:54px;}
            .topbar{display:flex;}
            .page-hero,.content{padding-left:16px;padding-right:16px;}
            .page-hero{padding-top:18px;}
        }
        a{color:inherit;}
        /* ── A REDAÇÃO ────────────────────────────────────────────────
           Duas colunas no desktop: o texto à esquerda, onde o olho fica, e
           as decisões (grau, foto, publicar) à direita, onde a mão vai só
           quando a matéria está pronta. No celular vira uma coluna e a
           ordem do HTML já é a ordem certa — escreve, escolhe, publica. */
        /* O EDITOR CENTRALIZADO. Numa tela de 1600 os dois blocos esticavam
           de ponta a ponta e a linha de texto passava de 140 caracteres — o
           olho perde o começo da linha seguinte muito antes disso. Com o teto
           e a margem automática, a coluna de escrita fica na medida e o
           conjunto no meio da tela. */
        .red-grade{display:grid;grid-template-columns:minmax(0,1.55fr) minmax(0,1fr);gap:16px;
          align-items:start;max-width:1180px;margin:0 auto}
        .red-col{display:flex;flex-direction:column;gap:16px;min-width:0}
        @media(max-width:1100px){.red-grade{grid-template-columns:1fr}}

        .cp{display:block;margin-bottom:16px}
        .cp:last-child{margin-bottom:0}
        .cp > span{display:block;font-size:12px;font-weight:700;margin-bottom:6px;color:var(--text)}
        .cp > span em{font-style:normal;font-weight:500;color:var(--text-3);font-size:11px;margin-left:5px}
        .cp input[type=text],.cp input[type=url],.cp textarea,.cp input[type=file]{
          width:100%;background:var(--panel-2);border:1px solid var(--border-md);
          border-radius:var(--radius-sm);padding:10px 12px;color:var(--text);
          font-family:var(--font);font-size:13.5px;outline:none;transition:border-color var(--t)}
        .cp textarea{resize:vertical;line-height:1.6}
        .cp input:focus,.cp textarea:focus{border-color:var(--red)}
        .cp small{display:block;font-size:11.5px;color:var(--text-3);margin-top:6px;line-height:1.45}
        /* O texto da matéria em serifa: é como ela vai sair no jornal, e
           escrever no mesmo tipo em que se lê evita a surpresa do "ficou
           diferente do que eu vi". */
        .txt-materia{font-family:Georgia,'Times New Roman',serif !important;font-size:15px !important}

        /* ── A BARRA DE FORMATAR ───────────────────────────────────────
           Age sobre a SELEÇÃO: marca o que está selecionado, e clicar de novo
           desmarca. É por isso que "Texto normal" existe como botão próprio —
           tirar três marcas de um trecho, uma a uma, é trabalho de editor de
           código, não de quem está escrevendo matéria. */
        .barra-txt{display:flex;align-items:center;gap:4px;flex-wrap:wrap;
          background:var(--panel-2);border:1px solid var(--border-md);border-bottom:none;
          border-radius:var(--radius-sm) var(--radius-sm) 0 0;padding:7px 8px}
        .barra-txt button{background:transparent;border:1px solid transparent;color:var(--text-2);
          font-family:var(--font);font-size:12.5px;font-weight:600;padding:5px 10px;
          border-radius:7px;cursor:pointer;transition:all var(--t) var(--ease);
          display:inline-flex;align-items:center;gap:5px;line-height:1}
        .barra-txt button:hover{background:var(--panel-3);color:var(--text)}
        .barra-txt button.ativo{background:var(--red-soft);border-color:var(--border-red);color:var(--red)}
        .barra-txt button b{font-size:14px}
        .barra-txt button i{font-style:italic;font-size:14px;font-family:Georgia,serif}
        .barra-txt button .bi{font-style:normal;font-family:inherit}
        .barra-fio{width:1px;height:18px;background:var(--border);margin:0 3px}

        /* O aviso de rascunho recuperado e o contador de tamanho. */
        .aviso-rascunho{display:flex;align-items:center;gap:10px;flex-wrap:wrap;
          background:rgba(245,158,11,.10);border:1px solid rgba(245,158,11,.30);
          border-radius:var(--radius-sm);padding:11px 14px;margin-bottom:14px;font-size:13px}
        .aviso-rascunho span{flex:1;min-width:180px;color:var(--text)}
        .aviso-rascunho button{font-size:12px;font-weight:700;padding:6px 13px;border-radius:7px;
          border:1px solid var(--border-md);background:var(--panel-2);color:var(--text);cursor:pointer}
        .aviso-rascunho .ar-usar{background:var(--red);border-color:var(--red);color:#fff}
        .contador-txt{font-size:11.5px;color:var(--text-3);margin-top:6px;text-align:right}

        /* O segundo botão é uma AÇÃO, não uma alternativa apagada: quem salva
           rascunho está fazendo uma escolha, não desistindo de publicar. Por
           isso ele tem contorno e peso, e não o cinza de "cancelar" — que é o
           terceiro, e esse sim é discreto. */
        .btn-rascunho{width:100%;background:var(--panel-2);border:1px solid var(--border-md);
          color:var(--text);font-family:var(--font);font-size:13px;font-weight:700;
          padding:10px 20px;border-radius:var(--radius-sm);cursor:pointer;
          display:inline-flex;align-items:center;justify-content:center;gap:7px;
          transition:all var(--t) var(--ease)}
        .btn-rascunho:hover{border-color:var(--border-red);color:var(--red)}
        .btn-save{display:inline-flex;align-items:center;justify-content:center;gap:7px}
        .nota-publicar{font-size:11.5px;color:var(--text-3);margin:2px 0 0;line-height:1.4;text-align:center}

        /* Cancelar sai do contorno e vira texto. Com os dois botoes de salvar,
           um terceiro contorno igual ao de rascunho poria tres coisas do mesmo
           peso empilhadas — e cancelar nao e uma forma de salvar. */
        .btn-cancelar{display:block;width:100%;text-align:center;background:none;border:0;
          padding:8px 4px;font-size:12.5px;font-weight:600;color:var(--text-3);
          text-decoration:none;transition:color var(--t) var(--ease)}
        .btn-cancelar:hover{color:var(--text)}

        /* ── FILTROS DA LISTA ──────────────────────────────────────── */
        .filtros{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:14px}
        .filtros input[type=search]{flex:1;min-width:200px}
        .filtros input,.filtros select{background:var(--panel-2);border:1px solid var(--border-md);
          border-radius:var(--radius-sm);padding:9px 12px;color:var(--text);
          font-family:var(--font);font-size:13px;outline:none;transition:border-color var(--t)}
        .filtros input:focus,.filtros select:focus{border-color:var(--red)}
        .filtros .btn-save{padding:9px 20px}
        .filtros .btn-outline{padding:9px 16px}
        .resultado-filtro{font-size:12.5px;color:var(--text-3);margin:-6px 0 16px}
        .resultado-filtro b{color:var(--text)}
        /* Leituras, curtidas e comentários de cada matéria, na mesma linha de
           quem escreveu. Os ícones separam os três sem precisar de rótulo. */
        .desempenho{display:inline-flex;align-items:center;gap:4px;margin-left:8px;
          padding-left:9px;border-left:1px solid var(--border);color:var(--text-3);
          font-variant-numeric:tabular-nums}
        .desempenho .bi{font-size:10px;margin-left:5px}
        .desempenho .bi:first-child{margin-left:0}

        /* ── COMENTÁRIO EM UMA LINHA ───────────────────────────────────
           O painel serve pra varrer o que foi dito e apagar o que não devia
           ter sido. Cada comentário ocupava três linhas com o texto inteiro:
           vinte deles empurravam a redação pra fora da tela. Agora é uma
           linha, e o texto completo está no title e a um clique na matéria. */
        .coment-linha{display:flex;align-items:center;gap:10px;padding:8px 18px;
          border-top:1px solid var(--border);font-size:12.5px;min-width:0}
        .coment-linha:first-child{border-top:none}
        .coment-linha > b{flex:0 0 auto;max-width:120px;font-weight:700;color:var(--text);
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .coment-linha .cl-txt{flex:1;min-width:0;color:var(--text-2);
          white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .coment-linha .cl-onde{flex:0 0 auto;max-width:170px;color:var(--text-3);
          text-decoration:none;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;
          border-bottom:1px dotted var(--border-md)}
        .coment-linha .cl-onde:hover{color:var(--red);border-color:var(--red)}
        .coment-linha time{flex:0 0 auto;color:var(--text-3);font-size:11px;white-space:nowrap}
        .coment-linha form{flex:0 0 auto;display:flex}
        .coment-linha .cl-x{width:24px;height:24px;border-radius:6px;border:1px solid transparent;
          background:transparent;color:var(--text-3);cursor:pointer;font-size:10px;
          display:flex;align-items:center;justify-content:center;transition:all var(--t) var(--ease)}
        .coment-linha .cl-x:hover{border-color:var(--red);color:var(--red)}
        @media(max-width:760px){
          /* Em tela estreita não cabe tudo numa linha: a matéria e a hora saem,
             porque quem modera precisa de QUEM disse e O QUE disse. */
          .coment-linha .cl-onde,.coment-linha time{display:none}
          .coment-linha{padding:9px 14px}
        }
        /* O campo encosta na barra: os dois viram uma peça só. */
        .cp .txt-materia{border-top-left-radius:0;border-top-right-radius:0}

        /* ── A GALERIA DA MATÉRIA ──────────────────────────────────── */
        .galeria{display:grid;grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:9px}
        .gal-item{position:relative;border:1px solid var(--border);border-radius:9px;overflow:hidden;
          background:var(--panel-2)}
        .gal-item img{width:100%;aspect-ratio:4/3;object-fit:cover;display:block}
        .gal-n{position:absolute;top:5px;left:5px;background:var(--red);color:#fff;
          font-size:11px;font-weight:800;border-radius:5px;padding:1px 6px;font-family:var(--font)}
        .gal-acoes{display:flex;gap:4px;padding:5px}
        .gal-acoes button{flex:1;font-size:10.5px;font-weight:700;padding:4px 2px;border-radius:6px;
          border:1px solid var(--border);background:var(--panel-3);color:var(--text-2);cursor:pointer;
          transition:all var(--t) var(--ease)}
        .gal-acoes button:hover{border-color:var(--border-md);color:var(--text)}
        .gal-acoes .gal-tirar:hover{border-color:var(--red);color:var(--red)}
        .gal-vazia{grid-column:1/-1;padding:20px;text-align:center;color:var(--text-3);
          font-size:12.5px;border:1px dashed var(--border-md);border-radius:9px}

        .graus{display:flex;flex-direction:column;gap:8px}
        .grau{display:flex;align-items:flex-start;gap:10px;padding:11px 12px;border-radius:var(--radius-sm);
          border:1px solid var(--border);background:var(--panel-2);cursor:pointer;
          transition:border-color var(--t),background var(--t);position:relative}
        .grau:hover{border-color:var(--border-md)}
        .grau.on{border-color:var(--red);background:var(--red-soft)}
        .grau input{position:absolute;opacity:0;pointer-events:none}
        .grau > i{width:10px;height:10px;border-radius:3px;flex:none;margin-top:4px}
        .grau span{flex:1;min-width:0}
        .grau b{display:block;font-size:13.5px;font-weight:700;margin-bottom:3px}
        .grau small{display:block;font-size:11.5px;color:var(--text-3);line-height:1.45}
        .avisa{position:absolute;top:9px;right:10px;font-style:normal;font-size:10px;font-weight:700;
          letter-spacing:.4px;color:var(--green);display:inline-flex;align-items:center;gap:4px}

        .previa{border:1px solid var(--border-md);border-radius:var(--radius-sm);overflow:hidden;
          background:var(--panel-2);margin-bottom:12px;aspect-ratio:16/9}
        .previa img{width:100%;height:100%;object-fit:cover;display:block}
        .previa-nova{margin-top:-4px}

        .checa{display:flex;align-items:flex-start;gap:9px;cursor:pointer;font-size:13px;color:var(--text-2)}
        .checa input{margin-top:2px;accent-color:var(--red);width:16px;height:16px;flex:none}
        .checa.forte{font-size:14px;font-weight:600;color:var(--text)}
        .checa.forte small{display:block;font-size:11.5px;font-weight:400;color:var(--text-3);margin-top:3px}

        .cont{font-size:11px;font-weight:700;background:var(--panel-3);color:var(--text-2);
          border-radius:20px;padding:1px 8px;margin-left:2px}

        /* ── A LISTA DE NOTÍCIAS ───────────────────────────────────── */
        .linha{display:flex;align-items:center;gap:13px;padding:12px 18px;border-top:1px solid var(--border)}
        .linha:first-child{border-top:none}
        .linha-foto{width:64px;height:44px;border-radius:8px;overflow:hidden;flex:none;
          background:var(--panel-3);display:flex;align-items:center;justify-content:center;color:var(--text-3)}
        .linha-foto img{width:100%;height:100%;object-fit:cover}
        .linha-txt{flex:1;min-width:0}
        .linha-cima{display:flex;align-items:center;gap:7px;margin-bottom:4px;flex-wrap:wrap}
        .linha-txt b{display:block;font-size:14px;font-weight:700;line-height:1.3;
          overflow:hidden;text-overflow:ellipsis;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
        .linha-txt small{font-size:11.5px;color:var(--text-3)}
        .tag{font-size:10px;font-weight:800;letter-spacing:.7px;text-transform:uppercase;
          border-radius:3px;padding:2px 7px}
        .tag-manchete{background:var(--red);color:#fff}
        .tag-destaque{background:rgba(245,158,11,.15);color:#f59e0b;box-shadow:inset 0 0 0 1px rgba(245,158,11,.35)}
        .tag-noticia{background:var(--panel-3);color:var(--text-3)}
        .tag-chapeu{font-size:10.5px;font-weight:700;letter-spacing:.6px;text-transform:uppercase;color:var(--red)}
        .tag-whats{font-size:11px;color:var(--green)}

        .linha-acoes{display:flex;align-items:center;gap:5px;flex:none}
        .linha-acoes form{display:contents}
        .ac{width:32px;height:32px;border-radius:8px;background:var(--panel-2);border:1px solid var(--border);
          color:var(--text-2);display:flex;align-items:center;justify-content:center;font-size:13px;
          cursor:pointer;transition:all var(--t) var(--ease);text-decoration:none;flex:none}
        .ac:hover{border-color:var(--border-md);color:var(--text)}
        .ac-ok:hover{border-color:var(--green);color:var(--green)}
        .ac-perigo:hover{border-color:var(--red);color:var(--red)}
        .ac-whats{color:var(--green)}
        .ac-whats:hover{border-color:var(--green);color:var(--green)}

        details.bc > summary::-webkit-details-marker{display:none}
        details.bc[open] > summary{border-bottom:1px solid var(--border)}

        @media(max-width:560px){
            .linha{flex-wrap:wrap;padding:12px 14px}
            .linha-foto{width:52px;height:38px}
            .linha-acoes{width:100%;justify-content:flex-end;padding-top:2px}
            .avisa{position:static;margin-top:6px;display:flex}
            .grau{flex-wrap:wrap}
        }
    <?php include __DIR__ . '/includes/accent-color.php'; ?>
    </style>
</head>
<body>
<div class="app">

    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <div class="sb-overlay" id="sbOverlay"></div>
    <header class="topbar">
        <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
        <div class="topbar-title">FBA <em>Manager</em></div>
    </header>

    <main class="main">
        <div class="page-hero">
            <div>
                <div class="page-eyebrow">The Pathetic · Redação</div>
                <h1 class="page-title"><?= $novaMateria ? ($editando ? 'Editar notícia' : 'Nova notícia') : 'Redação — The Pathetic' ?></h1>
                <p class="page-sub"><?= $novaMateria
                    ? 'O grau decide o tamanho dela na capa. Manchete e destaque avisam o grupo ao publicar.'
                    : 'Escreva, publique e edite. A redação é de todo mundo — o que sai, sai assinado.' ?></p>
            </div>
            <div style="padding-top:4px;display:flex;gap:8px;flex-wrap:wrap">
                <?php if ($novaMateria): ?>
                    <a class="btn-outline" href="/thepathetic-edit.php"><i class="bi bi-arrow-left"></i> Voltar</a>
                <?php else: ?>
                    <a class="btn-outline" href="/thepathetic.php" target="_blank" rel="noopener">
                        <i class="bi bi-box-arrow-up-right"></i> Ver o jornal
                    </a>
                    <a class="btn-save" style="text-decoration:none;display:inline-flex;align-items:center;gap:7px"
                       href="/thepathetic-edit.php?nova=1"><i class="bi bi-plus-lg"></i> Escrever notícia</a>
                <?php endif; ?>
            </div>
        </div>

        <div class="content">
            <?php if ($flash): ?>
            <div class="flash <?= $flashType==='danger'?'danger':($flashType==='warning'?'warning':'success') ?>" style="margin-bottom:18px">
                <i class="bi bi-<?= $flashType==='danger'?'exclamation-circle-fill':($flashType==='warning'?'exclamation-triangle-fill':'check-circle-fill') ?>"></i>
                <?= $esc($flash) ?>
            </div>
            <?php endif; ?>

<?php if ($novaMateria): /* ═══════════ ESCREVER ═══════════ */
    $v = $editando ?: ['id'=>0,'titulo'=>'','chapeu'=>'','grau'=>'noticia','resumo'=>'','texto'=>'',
                       'foto'=>'','foto_credito'=>'','publicada'=>0];
    $fotoSrc = patheticSrcFoto($v['foto'] ?? '');
?>
            <form method="post" enctype="multipart/form-data" class="redacao">
                <input type="hidden" name="token" value="<?= $esc($token) ?>">
                <input type="hidden" name="acao" value="salvar">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">

                <div class="red-grade">
                  <div class="red-col">

                    <div class="bc">
                        <div class="bc-head"><div class="bc-title"><i class="bi bi-type"></i> A notícia</div></div>
                        <div class="bc-body">
                            <label class="cp">
                                <span>Chapéu <em>opcional</em></span>
                                <input type="text" name="chapeu" maxlength="60" value="<?= $esc($v['chapeu']) ?>"
                                       placeholder="Ex: Mercado · Draft · Bastidores">
                                <small>A palavra em vermelho acima do título. Diz do que a notícia é.</small>
                            </label>

                            <label class="cp">
                                <span>Título <em>obrigatório</em></span>
                                <input type="text" name="titulo" maxlength="180" required value="<?= $esc($v['titulo']) ?>"
                                       placeholder="O que aconteceu, em uma frase">
                                <small>Sai em caixa-alta na capa. Frase curta bate mais forte que frase completa.</small>
                            </label>

                            <label class="cp">
                                <span>Linha fina <em>opcional</em></span>
                                <textarea name="resumo" maxlength="400" rows="2"
                                          placeholder="A segunda frase — o que o título não coube."><?= $esc($v['resumo']) ?></textarea>
                                <small>Aparece embaixo do título na capa e no card. Sem ela, o começo do texto entra no lugar.</small>
                            </label>

                            <label class="cp">
                                <span>Texto</span>
                                <div class="barra-txt" id="barraTxt" role="toolbar" aria-label="Formatar">
                                    <button type="button" data-marca="*" title="Negrito (Ctrl+B)"><b>B</b></button>
                                    <button type="button" data-marca="_" title="Itálico (Ctrl+I)"><i>I</i></button>
                                    <button type="button" data-limpar="1" title="Voltar a texto normal">Texto normal</button>
                                    <span class="barra-fio"></span>
                                    <button type="button" data-linha="## " title="Intertítulo">Título</button>
                                    <button type="button" data-linha="> " title="Citação">&ldquo; &rdquo;</button>
                                    <button type="button" data-linha="- " title="Lista"><i class="bi bi-list-ul"></i></button>
                                    <span class="barra-fio"></span>
                                    <button type="button" id="btnLink" title="Link (Ctrl+K)"><i class="bi bi-link-45deg"></i> Link</button>
                                    <button type="button" id="btnInserirFoto" title="Inserir foto da galeria no cursor">
                                        <i class="bi bi-image"></i> Inserir foto
                                    </button>
                                </div>
                                <textarea name="texto" rows="16" class="txt-materia" id="campoTexto"
                                          placeholder="Escreva normal. Linha em branco separa parágrafo.&#10;&#10;*negrito* e _itálico_ funcionam, como no WhatsApp."><?= $esc($v['texto']) ?></textarea>
                                <small>Texto puro, não HTML. Linha em branco = parágrafo novo. <b>*negrito*</b> e <i>_itálico_</i> funcionam.</small>
                            </label>
                        </div>
                    </div>

                  </div>
                  <div class="red-col red-lado">

                    <div class="bc">
                        <div class="bc-head"><div class="bc-title"><i class="bi bi-bar-chart-steps"></i> Grau</div></div>
                        <div class="bc-body">
                            <div class="graus">
                                <?php foreach (PATHETIC_GRAUS as $g): $info = PATHETIC_GRAU_INFO[$g]; ?>
                                <label class="grau <?= $v['grau'] === $g ? 'on' : '' ?>">
                                    <input type="radio" name="grau" value="<?= $g ?>" <?= $v['grau'] === $g ? 'checked' : '' ?>>
                                    <i style="background:<?= $info['cor'] ?>"></i>
                                    <span>
                                        <b><?= $esc($info['rotulo']) ?></b>
                                        <small><?= $esc($info['nota']) ?></small>
                                    </span>
                                    <?php if (in_array($g, PATHETIC_GRAUS_QUE_AVISAM, true)): ?>
                                        <em class="avisa" title="Se o WhatsApp estiver ligado e com grupo configurado"><i class="bi bi-whatsapp"></i> avisa o grupo</em>
                                    <?php endif; ?>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    </div>

                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-image"></i> Foto de capa</div>
                            <span style="font-size:11px;color:var(--text-3)">A que abre a matéria</span>
                        </div>
                        <div class="bc-body">
                            <?php if ($fotoSrc !== ''): ?>
                                <div class="previa"><img src="<?= $esc($fotoSrc) ?>" alt=""></div>
                                <label class="checa" style="margin-bottom:12px">
                                    <input type="checkbox" name="tirar_foto" value="1"> Tirar a foto desta notícia
                                </label>
                            <?php endif; ?>

                            <label class="cp">
                                <span>Enviar do aparelho</span>
                                <input type="file" name="foto" accept="image/jpeg,image/png,image/webp,image/gif" id="fotoArquivo">
                                <small>JPG, PNG, WEBP ou GIF, até <?= (int)(PATHETIC_MAX_FOTO/1024/1024) ?> MB.</small>
                            </label>
                            <div class="previa previa-nova" id="previaNova" hidden><img alt=""></div>

                            <label class="cp">
                                <span>Legenda / crédito <em>opcional</em></span>
                                <input type="text" name="foto_credito" maxlength="120" value="<?= $esc($v['foto_credito']) ?>"
                                       placeholder="Ex: Divulgação / FBA">
                            </label>
                        </div>
                    </div>

                    <?php $galeria = (int)$v['id'] > 0 ? patheticFotos($pdo, (int)$v['id']) : []; ?>
                    <div class="bc">
                        <div class="bc-head">
                            <div class="bc-title"><i class="bi bi-images"></i> Fotos no meio do texto</div>
                            <span style="font-size:11px;color:var(--text-3)">Até <?= PATHETIC_MAX_FOTOS ?></span>
                        </div>
                        <div class="bc-body">
                            <div class="galeria">
                                <?php if (!$galeria): ?>
                                    <div class="gal-vazia">Nenhuma foto ainda. Envie uma aqui embaixo e depois use
                                        <b>Inserir foto</b> na barra do texto.</div>
                                <?php endif; ?>
                                <?php foreach ($galeria as $g): ?>
                                    <div class="gal-item" data-n="<?= (int)$g['n'] ?>">
                                        <span class="gal-n"><?= (int)$g['n'] ?></span>
                                        <img src="<?= $esc(patheticSrcFoto($g['caminho'])) ?>" alt="">
                                        <div class="gal-acoes">
                                            <button type="button" onclick="inserirFotoNoTexto(<?= (int)$g['n'] ?>)">Inserir</button>
                                            <button type="button" class="gal-tirar"
                                                    onclick="tirarFoto(<?= (int)$g['id'] ?>)">Tirar</button>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                            <p style="font-size:11.5px;color:var(--text-3);margin:11px 0 0;line-height:1.45">
                                A foto entra no texto como <code>[foto:2]</code>, num parágrafo só dela.
                                Tirar a foto daqui some com ela da matéria — a marca no texto é ignorada.
                            </p>
                        </div>
                    </div>

                    <div class="bc">
                        <div class="bc-head"><div class="bc-title"><i class="bi bi-plus-square"></i> Enviar foto pra galeria</div></div>
                        <div class="bc-body">
                            <label class="cp">
                                <span>Arquivo</span>
                                <input type="file" name="foto_galeria" accept="image/jpeg,image/png,image/webp,image/gif" form="formGaleria">
                            </label>
                            <label class="cp">
                                <span>Legenda <em>opcional</em></span>
                                <input type="text" name="legenda" maxlength="160" form="formGaleria"
                                       placeholder="Ex: O técnico na coletiva de terça">
                            </label>
                            <button type="submit" class="btn-save" style="width:100%" form="formGaleria">Enviar foto</button>
                            <?php if ((int)$v['id'] <= 0): ?>
                                <p style="font-size:11.5px;color:var(--text-3);margin:10px 0 0;line-height:1.45">
                                    A matéria vira rascunho sozinha ao enviar a primeira foto — a foto
                                    precisa de uma matéria pra ficar guardada. O que você já escreveu vai junto.
                                </p>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="bc">
                        <div class="bc-body" style="display:flex;flex-direction:column;gap:11px">
                            <?php
                              // DOIS BOTÕES, e não um botão mais uma caixinha.
                              //
                              // "Publicar agora" marcado + "Criar notícia" eram duas
                              // ideias no mesmo controle: pra saber o que ia acontecer,
                              // a pessoa tinha que olhar a caixinha ANTES de ler o
                              // botão. Agora cada botão diz o resultado dele, e o par
                              // muda conforme o estado — numa matéria que já está no
                              // ar, "salvar rascunho" seria mentira: o que aquele
                              // clique faz é tirá-la do ar.
                              $noAr = !empty($v['publicada']);
                            ?>
                            <?php if ($noAr): ?>
                                <button type="submit" name="publicar" value="1" class="btn-save" id="btnPrincipal" style="width:100%">
                                    <i class="bi bi-check2"></i> Salvar e manter no ar
                                </button>
                                <button type="submit" name="publicar" value="0" class="btn-rascunho"
                                        data-confirmar="Tirar esta matéria do ar? Ela volta a ser rascunho e some do jornal.">
                                    <i class="bi bi-eye-slash"></i> Tirar do ar
                                </button>
                            <?php else: ?>
                                <button type="submit" name="publicar" value="1" class="btn-save" id="btnPrincipal" style="width:100%">
                                    <i class="bi bi-send"></i> Publicar
                                </button>
                                <button type="submit" name="publicar" value="0" class="btn-rascunho">
                                    <i class="bi bi-file-earmark-text"></i> Salvar rascunho
                                </button>
                                <p class="nota-publicar">Rascunho fica guardado e ninguém vê até você publicar.</p>
                            <?php endif; ?>
                            <a class="btn-cancelar" href="/thepathetic-edit.php">Cancelar</a>
                        </div>
                    </div>

                  </div>
                </div>
            </form>

            <?php if ($novaMateria): ?>
            <!-- Os formulários da galeria vivem FORA do formulário da matéria:
                 HTML não aninha formulário, e os campos lá em cima chegam aqui pelo
                 atributo form="formGaleria". Assim enviar uma foto não arrasta
                 junto o texto da matéria — e não salva por acidente o que ainda
                 estava sendo escrito. -->
            <form id="formGaleria" method="post" enctype="multipart/form-data" hidden>
                <input type="hidden" name="token" value="<?= $esc($token) ?>">
                <input type="hidden" name="acao" value="add_foto">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <!-- Numa matéria que ainda não existe, estes campos viajam junto
                     pra o rascunho automático nascer com o que já foi escrito.
                     Preenchidos por JS na hora do envio, e não pelo PHP: o valor
                     que importa é o da TELA, não o do banco. -->
                <input type="hidden" name="rascunho_titulo" value="">
                <input type="hidden" name="rascunho_chapeu" value="">
                <input type="hidden" name="rascunho_resumo" value="">
                <input type="hidden" name="rascunho_texto"  value="">
            </form>
            <form id="formTirarFoto" method="post" hidden>
                <input type="hidden" name="token" value="<?= $esc($token) ?>">
                <input type="hidden" name="acao" value="tirar_foto">
                <input type="hidden" name="id" value="<?= (int)$v['id'] ?>">
                <input type="hidden" name="foto" id="fotoParaTirar" value="">
            </form>
            <script>
              async function tirarFoto(id){
                if (!await confirmarSite("Tirar esta foto da matéria? O arquivo é apagado.")) return;
                document.getElementById("fotoParaTirar").value = id;
                document.getElementById("formTirarFoto").submit();
              }
            </script>
            <?php endif; ?>

<?php else: /* ═══════════ A LISTA ═══════════ */ ?>

            <?php if ($todas): ?>
            <form class="filtros" method="get" action="/thepathetic-edit.php">
                <input type="search" name="b" value="<?= $esc($fBusca) ?>"
                       placeholder="Buscar por título ou palavra no texto" aria-label="Buscar matéria">
                <select name="grau" aria-label="Filtrar por tipo">
                    <option value="">Todos os tipos</option>
                    <?php foreach (PATHETIC_GRAUS as $g): ?>
                        <option value="<?= $g ?>" <?= $fGrau === $g ? 'selected' : '' ?>>
                            <?= $esc(PATHETIC_GRAU_INFO[$g]['rotulo']) ?></option>
                    <?php endforeach; ?>
                </select>
                <select name="autor" aria-label="Filtrar por quem escreveu">
                    <option value="">Todos os autores</option>
                    <?php foreach ($autores as $a): ?>
                        <option value="<?= $esc($a) ?>" <?= $fAutor === $a ? 'selected' : '' ?>><?= $esc($a) ?></option>
                    <?php endforeach; ?>
                </select>
                <button type="submit" class="btn-save">Filtrar</button>
                <?php if ($temFiltro): ?>
                    <a class="btn-outline" href="/thepathetic-edit.php">Limpar</a>
                <?php endif; ?>
            </form>
            <?php if ($temFiltro): ?>
                <p class="resultado-filtro">
                    <b><?= count($filtradas) ?></b> de <?= count($todas) ?>
                    <?= count($todas) === 1 ? 'matéria' : 'matérias' ?>
                    <?php if ($fAutor !== ''): ?> · por <b><?= $esc($fAutor) ?></b><?php endif; ?>
                    <?php if ($fGrau !== ''): ?> · <b><?= $esc(PATHETIC_GRAU_INFO[$fGrau]['rotulo']) ?></b><?php endif; ?>
                    <?php if ($fBusca !== ''): ?> · contendo "<b><?= $esc($fBusca) ?></b>"<?php endif; ?>
                </p>
            <?php endif; ?>
            <?php endif; ?>

            <?php if ($temFiltro && !$filtradas): ?>
                <div class="bc"><div class="bc-body" style="text-align:center;padding:34px 20px;color:var(--text-3)">
                    Nenhuma matéria com esses filtros.
                </div></div>
            <?php endif; ?>

            <?php if (!$todas): ?>
                <div class="bc"><div class="bc-body" style="text-align:center;padding:52px 20px">
                    <i class="bi bi-newspaper" style="font-size:36px;color:var(--text-3);display:block;margin-bottom:14px"></i>
                    <div style="font-size:16px;font-weight:700;margin-bottom:6px">Nenhuma notícia ainda</div>
                    <p style="font-size:13px;color:var(--text-3);margin-bottom:18px">
                        A primeira que você escrever já abre o jornal.
                    </p>
                    <a class="btn-save" style="text-decoration:none" href="/thepathetic-edit.php?nova=1">Escrever a primeira</a>
                </div></div>
            <?php endif; ?>

            <?php
              $blocos = [];
              if ($rascunhos) $blocos[] = ['Rascunhos', 'bi-pencil', $rascunhos, 'Ninguém vê até você publicar.'];
              if ($noAr)      $blocos[] = ['No ar', 'bi-broadcast', $noAr, 'Da mais recente para a mais antiga.'];
              foreach ($blocos as [$titulo, $icone, $lista, $nota]):
            ?>
            <div class="bc" style="margin-bottom:16px">
                <div class="bc-head">
                    <div class="bc-title"><i class="bi <?= $icone ?>"></i> <?= $esc($titulo) ?> <span class="cont"><?= count($lista) ?></span></div>
                    <span style="font-size:11px;color:var(--text-3)"><?= $esc($nota) ?></span>
                </div>
                <div class="bc-body" style="padding:0">
                    <?php foreach ($lista as $n): $f = patheticSrcFoto($n['foto']); ?>
                    <div class="linha">
                        <div class="linha-foto"><?php if ($f !== ''): ?><img src="<?= $esc($f) ?>" alt="" loading="lazy"><?php else: ?><i class="bi bi-image"></i><?php endif; ?></div>
                        <div class="linha-txt">
                            <div class="linha-cima">
                                <span class="tag tag-<?= $esc($n['grau']) ?>"><?= $esc(PATHETIC_GRAU_INFO[$n['grau']]['rotulo'] ?? $n['grau']) ?></span>
                                <?php if (trim((string)$n['chapeu']) !== ''): ?><span class="tag-chapeu"><?= $esc($n['chapeu']) ?></span><?php endif; ?>
                                <?php if (!empty($n['avisou_whats'])): ?><span class="tag-whats" title="O grupo já foi avisado desta notícia"><i class="bi bi-whatsapp"></i></span><?php endif; ?>
                            </div>
                            <b><?= $esc($n['titulo']) ?></b>
                            <small>
                                <?= $esc($n['autor_nome']) ?> · <?= $esc(patheticQuando($n['publicada_em'] ?: $n['criada_em'])) ?>
                                <?php // O desempenho fica ao lado de quem escreveu: é o par que
                                      // interessa a quem abre esta lista. Rascunho não mostra
                                      // número nenhum — ninguém leu o que não foi publicado.
                                      $sc = $socialDaLista[(int)$n['id']] ?? [];
                                      $vw = (int)($n['views'] ?? 0);
                                      if (!empty($n['publicada'])): ?>
                                    <span class="desempenho">
                                        <i class="bi bi-eye-fill"></i> <?= $vw ?>
                                        <i class="bi bi-heart-fill"></i> <?= (int)($sc['curtidas'] ?? 0) ?>
                                        <i class="bi bi-chat-fill"></i> <?= (int)($sc['comentarios'] ?? 0) ?>
                                    </span>
                                <?php endif; ?>
                            </small>
                        </div>
                        <div class="linha-acoes">
                            <a class="ac" href="/thepathetic-edit.php?editar=<?= (int)$n['id'] ?>" title="Editar"><i class="bi bi-pencil"></i></a>
                            <?php if (!empty($n['publicada'])): ?>
                                <a class="ac" href="/thepathetic.php?n=<?= (int)$n['id'] ?>" target="_blank" rel="noopener" title="Ver no jornal"><i class="bi bi-box-arrow-up-right"></i></a>
                                <?php if (empty($n['avisou_whats']) && in_array($n['grau'], PATHETIC_GRAUS_QUE_AVISAM, true)): ?>
                                <form method="post" title="O grupo ainda não foi avisado desta notícia">
                                    <input type="hidden" name="token" value="<?= $esc($token) ?>">
                                    <input type="hidden" name="acao" value="avisar">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="ac ac-whats" title="Avisar o grupo agora"><i class="bi bi-whatsapp"></i></button>
                                </form>
                                <?php endif; ?>
                                <form method="post" data-confirmar="Tirar esta notícia do ar? Ela continua salva como rascunho.">
                                    <input type="hidden" name="token" value="<?= $esc($token) ?>">
                                    <input type="hidden" name="acao" value="despublicar">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="ac" title="Tirar do ar"><i class="bi bi-eye-slash"></i></button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="token" value="<?= $esc($token) ?>">
                                    <input type="hidden" name="acao" value="publicar">
                                    <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                    <button class="ac ac-ok" title="Publicar"><i class="bi bi-send"></i></button>
                                </form>
                            <?php endif; ?>
                            <form method="post" data-confirmar="Apagar &quot;<?= $esc($n['titulo']) ?>&quot;? Não tem volta.">
                                <input type="hidden" name="token" value="<?= $esc($token) ?>">
                                <input type="hidden" name="acao" value="apagar">
                                <input type="hidden" name="id" value="<?= (int)$n['id'] ?>">
                                <button class="ac ac-perigo" title="Apagar"><i class="bi bi-trash"></i></button>
                            </form>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endforeach; ?>

            <?php if ($comentariosRecentes): ?>
            <div class="bc" style="margin-top:22px">
                <div class="bc-head">
                    <div class="bc-title"><i class="bi bi-chat-left-text"></i> Comentários <span class="cont"><?= count($comentariosRecentes) ?></span></div>
                    <span style="font-size:11px;color:var(--text-3)">Do mais recente. Apagar não avisa quem escreveu.</span>
                </div>
                <div class="bc-body" style="padding:0">
                    <?php foreach ($comentariosRecentes as $c): ?>
                    <?php
                      // O comentário inteiro numa linha só. Antes cada um ocupava
                      // três linhas com o texto completo, e vinte comentários
                      // empurravam o resto da redação pra fora da tela — num
                      // painel que serve pra VARRER, não pra ler. O texto inteiro
                      // está a um clique, na matéria.
                      $txt = trim(preg_replace('/\s+/u', ' ', (string)$c['texto']));
                      $curto = mb_strlen($txt) > 110 ? mb_substr($txt, 0, 109) . '…' : $txt;
                    ?>
                    <div class="coment-linha">
                        <b><?= $esc($c['autor_nome']) ?></b>
                        <span class="cl-txt" title="<?= $esc($txt) ?>"><?= $esc($curto) ?></span>
                        <?php if ($c['titulo'] !== null): ?>
                            <a class="cl-onde" href="/thepathetic.php?n=<?= (int)$c['noticia_id'] ?>#conversa"
                               target="_blank" rel="noopener"
                               title="<?= $esc($c['titulo']) ?>"><?= $esc(mb_substr($c['titulo'], 0, 26)) ?><?= mb_strlen($c['titulo']) > 26 ? '…' : '' ?></a>
                        <?php else: ?>
                            <span class="cl-onde" style="color:var(--red)">apagada</span>
                        <?php endif; ?>
                        <time><?= $esc(patheticQuando($c['criado_em'])) ?></time>
                        <form method="post" data-confirmar="Apagar este comentário?">
                            <input type="hidden" name="token" value="<?= $esc($token) ?>">
                            <input type="hidden" name="acao" value="apagar_comentario">
                            <input type="hidden" name="comentario" value="<?= (int)$c['id'] ?>">
                            <button class="cl-x" title="Apagar comentário"><i class="bi bi-x-lg"></i></button>
                        </form>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($podeMexerNoArquivo): ?>
            <details class="bc" style="margin-top:22px">
                <summary class="bc-head" style="cursor:pointer;list-style:none">
                    <div class="bc-title"><i class="bi bi-archive"></i> Do arquivo — o HTML antigo</div>
                    <span style="font-size:11px;color:var(--text-3)">Sai no pé do jornal. Deixe vazio pra sumir.</span>
                </summary>
                <div class="bc-body">
                    <form method="post">
                        <input type="hidden" name="token" value="<?= $esc($token) ?>">
                        <input type="hidden" name="acao" value="arquivo">
                        <textarea name="content" class="html-area" style="min-height:220px"
                                  placeholder="HTML livre — o que estava aqui antes das notícias."><?= $esc($currentContent) ?></textarea>
                        <div style="display:flex;justify-content:flex-end;margin-top:12px">
                            <button type="submit" class="btn-save">Salvar arquivo</button>
                        </div>
                    </form>
                </div>
            </details>
            <?php endif; ?>

<?php endif; ?>
        </div>
    </main>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
    const sidebar   = document.getElementById('sidebar');
    const overlay   = document.getElementById('sbOverlay');
    const menuBtn   = document.getElementById('menuBtn');
    const themeToggle = document.getElementById('themeToggle');

    menuBtn?.addEventListener('click', () => { sidebar.classList.toggle('open'); overlay.classList.toggle('show'); });
    overlay?.addEventListener('click', () => { sidebar.classList.remove('open'); overlay.classList.remove('show'); });

    function applyTheme(t) {
        document.documentElement.dataset.theme = t;
        localStorage.setItem('fba-theme', t);
        const i = themeToggle.querySelector('i'), s = themeToggle.querySelector('span');
        if (i) i.className = t==='dark'?'bi bi-moon':'bi bi-sun';
        if (s) s.textContent = t==='dark'?'Modo escuro':'Modo claro';
    }
    applyTheme(localStorage.getItem('fba-theme')||'dark');
    themeToggle.addEventListener('click', () => applyTheme(document.documentElement.dataset.theme==='dark'?'light':'dark'));

/* ═══════════════════════════════════════════════════════════════════
       O QUE PROTEGE QUEM ESTÁ ESCREVENDO
       ═══════════════════════════════════════════════════════════════════

       Três coisas, e todas existem por causa do mesmo medo: perder o texto.
       Uma matéria de vinte minutos que some porque a aba fechou é o tipo de
       coisa que faz alguém não voltar a escrever. */
    (function () {
      const form  = document.querySelector('form.redacao');
      const campo = document.getElementById('campoTexto');
      if (!form || !campo) return;

      const id = (form.querySelector('[name=id]') || {}).value || '0';
      const chave = 'pathetic_rascunho_' + id;
      const campos = ['titulo', 'chapeu', 'resumo', 'texto'];
      const pega = (n) => form.querySelector('[name=' + n + ']');

      /* ── 1. RASCUNHO SALVO SOZINHO ──────────────────────────────────
         No navegador, não no servidor: salvar no servidor a cada tecla criaria
         versão a cada vírgula, e o texto ainda não é uma matéria — é rascunho
         de quem está pensando. Some sozinho quando a matéria é salva de
         verdade. */
      const aviso = document.createElement('div');
      aviso.className = 'aviso-rascunho';
      aviso.hidden = true;
      form.prepend(aviso);

      let ultimo = '';
      function guardar() {
        const dados = {};
        campos.forEach(n => { const c = pega(n); if (c) dados[n] = c.value; });
        const json = JSON.stringify(dados);
        if (json === ultimo) return;
        ultimo = json;
        try {
          localStorage.setItem(chave, JSON.stringify({ dados, em: Date.now() }));
          marcarSujo(true);
        } catch (e) { /* aba anônima, cota cheia: o texto continua na tela */ }
      }

      // Tem rascunho de uma sessão anterior? Oferece, não impõe: sobrescrever
      // o que a pessoa abriu pra editar seria pior que perder o rascunho.
      try {
        const cru = localStorage.getItem(chave);
        if (cru) {
          const { dados, em } = JSON.parse(cru);
          const mudou = campos.some(n => { const c = pega(n); return c && dados[n] && dados[n] !== c.value; });
          if (mudou) {
            const quando = new Date(em).toLocaleString('pt-BR', { day: '2-digit', month: '2-digit', hour: '2-digit', minute: '2-digit' });
            aviso.hidden = false;
            aviso.innerHTML = '<span>Você tem um rascunho não salvo de <b>' + quando + '</b>.</span>' +
              '<button type="button" class="ar-usar">Recuperar</button>' +
              '<button type="button" class="ar-descartar">Descartar</button>';
            aviso.querySelector('.ar-usar').onclick = () => {
              campos.forEach(n => { const c = pega(n); if (c && dados[n] !== undefined) c.value = dados[n]; });
              aviso.hidden = true; contar();
            };
            aviso.querySelector('.ar-descartar').onclick = () => {
              localStorage.removeItem(chave); aviso.hidden = true;
            };
          }
        }
      } catch (e) {}

      setInterval(guardar, 4000);
      campos.forEach(n => pega(n)?.addEventListener('input', () => { sujo = true; }));

      /* ── 2. AVISO AO SAIR COM COISA NÃO SALVA ───────────────────────
         O navegador só deixa avisar se a pessoa interagiu com a página, e o
         texto do alerta é dele, não nosso — é assim em todos os navegadores
         desde 2019, e tentar customizar não funciona. */
      let sujo = false;
      function marcarSujo(v) { sujo = v; }
      form.addEventListener('submit', () => {
        sujo = false;
        try { localStorage.removeItem(chave); } catch (e) {}
      });
      window.addEventListener('beforeunload', (ev) => {
        if (!sujo) return;
        ev.preventDefault();
        ev.returnValue = '';
      });

      /* ── 3. O TAMANHO DA MATÉRIA ────────────────────────────────────
         Palavras e minutos de leitura, do mesmo jeito que a página do jornal
         calcula (200 palavras por minuto). Quem escreve não tem noção de
         tamanho olhando pra uma caixa de texto — e uma matéria de 90 palavras
         num card de manchete fica com um buraco embaixo do título. */
      const contador = document.createElement('div');
      contador.className = 'contador-txt';
      campo.insertAdjacentElement('afterend', contador);
      function contar() {
        const t = campo.value.trim();
        const palavras = t ? (t.match(/\S+/g) || []).length : 0;
        const min = Math.max(1, Math.round(palavras / 200));
        const fotos = (campo.value.match(/\[foto:\d+\]/g) || []).length;
        contador.textContent = palavras + (palavras === 1 ? ' palavra' : ' palavras')
          + ' · ' + min + ' min de leitura'
          + (fotos ? ' · ' + fotos + (fotos === 1 ? ' foto' : ' fotos') + ' no texto' : '')
          + (palavras && palavras < 60 ? ' · curta pra uma matéria' : '');
      }
      campo.addEventListener('input', contar);
      contar();

      /* Ctrl+S salva. É o reflexo de quem escreve, e sem isto o navegador
         abre a caixa de "salvar página" — que é o oposto do que a pessoa
         quis. */
      document.addEventListener('keydown', (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === 's') {
          ev.preventDefault();
          // PELO BOTÃO PRINCIPAL, e não pelo formulário solto. Os botões de
          // publicar e de rascunho carregam name=publicar com valores
          // diferentes: um submit sem botão não manda campo nenhum, e o
          // servidor leria isso como "não publicar" — Ctrl+S numa matéria no
          // ar a tiraria do ar sem avisar.
          const principal = document.getElementById('btnPrincipal');
          if (principal && form.requestSubmit) form.requestSubmit(principal);
          else if (principal) principal.click();
          else form.submit();
        }
      });
    })();

/* ═══════════════════════════════════════════════════════════════════
       A BARRA DE FORMATAR
       ═══════════════════════════════════════════════════════════════════

       Ela age sobre a SELEÇÃO, e é um interruptor: selecionar "seis
       jogadores" e clicar em B escreve *seis jogadores*; clicar de novo com o
       mesmo trecho selecionado tira as estrelas.

       O campo continua sendo texto puro, com as marcas à vista. Foi escolha,
       não limitação: um editor que guarda HTML reabriria o buraco de XSS que
       esta página levou tempo pra fechar — o bloco do "arquivo" é justamente
       o que sobrou dessa era e por isso hoje só admin mexe nele.

       Quem prefere digitar *assim* continua digitando: a barra escreve o
       mesmo que o dedo escreveria. */
    (function () {
      const campo = document.getElementById('campoTexto');
      const barra = document.getElementById('barraTxt');
      if (!campo || !barra) return;

      /* Um passo só no desfazer. O jeito antigo (mexer no .value direto)
         apagava a pilha inteira de Ctrl+Z: a pessoa clicava em negrito por
         engano e não tinha volta. execCommand("insertText") é obsoleto pra
         quase tudo, mas é a única forma que ainda entra no histórico nativo
         do textarea — e se o navegador recusar, o fallback escreve direto. */
      function escrever(texto, ini, fim) {
        campo.focus();
        campo.setSelectionRange(ini, fim);
        let ok = false;
        try { ok = document.execCommand('insertText', false, texto); } catch (e) { ok = false; }
        if (!ok) {
          campo.value = campo.value.slice(0, ini) + texto + campo.value.slice(fim);
        }
      }

      /* Aplica ou tira a marca do trecho selecionado. */
      function marcar(marca) {
        const v = campo.value;
        let ini = campo.selectionStart, fim = campo.selectionEnd;

        // Sem seleção, marca a palavra debaixo do cursor: é o que a pessoa
        // quer em 90% dos casos, e evita o "cliquei e não aconteceu nada".
        if (ini === fim) {
          const antes = v.slice(0, ini).search(/[^\s]*$/);
          const depois = ini + (v.slice(ini).match(/^[^\s]*/) || [''])[0].length;
          if (depois > antes) { ini = antes; fim = depois; }
        }
        if (ini === fim) return;

        const dentro = v.slice(ini, fim);
        const m = marca;

        // Já marcado? Então o clique é pra TIRAR — nos dois formatos: com a
        // marca dentro da seleção (*isto*) e com ela em volta (*|isto|*).
        if (dentro.length > 2 * m.length && dentro.startsWith(m) && dentro.endsWith(m)) {
          const limpo = dentro.slice(m.length, -m.length);
          escrever(limpo, ini, fim);
          campo.setSelectionRange(ini, ini + limpo.length);
          return atualizar();
        }
        if (v.slice(ini - m.length, ini) === m && v.slice(fim, fim + m.length) === m) {
          escrever(dentro, ini - m.length, fim + m.length);
          campo.setSelectionRange(ini - m.length, ini - m.length + dentro.length);
          return atualizar();
        }

        escrever(m + dentro + m, ini, fim);
        campo.setSelectionRange(ini + m.length, ini + m.length + dentro.length);
        atualizar();
      }

      /* Tira TODAS as marcas do trecho — o "voltar a texto normal". */
      function limpar() {
        let ini = campo.selectionStart, fim = campo.selectionEnd;
        if (ini === fim) {
          // Sem seleção, limpa a linha inteira: é o gesto de "essa linha aqui
          // volta a ser texto".
          const v = campo.value;
          ini = v.lastIndexOf('\n', Math.max(0, ini - 1)) + 1;
          const p = v.indexOf('\n', fim);
          fim = p === -1 ? v.length : p;
        }
        if (ini === fim) return;
        const limpo = campo.value.slice(ini, fim)
          .replace(/^(#{1,3}\s+|>\s*)/gm, '')
          .replace(/(?<![\w*])\*(?=\S)([^*\r\n]+?)(?<=\S)\*(?![\w*])/g, '$1')
          .replace(/(?<![\w_])_(?=\S)([^_\r\n]+?)(?<=\S)_(?![\w_])/g, '$1');
        escrever(limpo, ini, fim);
        campo.setSelectionRange(ini, ini + limpo.length);
        atualizar();
      }

      /* Prefixo de linha: intertítulo e citação. Interruptor também. */
      function prefixar(prefixo) {
        const v = campo.value;
        const ini = v.lastIndexOf('\n', Math.max(0, campo.selectionStart - 1)) + 1;
        let fim = v.indexOf('\n', campo.selectionEnd);
        if (fim === -1) fim = v.length;

        const linhas = v.slice(ini, fim).split('\n');
        const jaTem = linhas.every(l => l.startsWith(prefixo));
        const novas = linhas.map(l => jaTem
          ? l.slice(prefixo.length)
          : prefixo + l.replace(/^(#{1,3}\s+|>\s*)/, ''));
        const texto = novas.join('\n');
        escrever(texto, ini, fim);
        campo.setSelectionRange(ini, ini + texto.length);
        atualizar();
      }

      /* Acende o botão do formato que a seleção já tem. Sem isto a barra não
         diz em que estado o texto está, e "clicar de novo pra tirar" vira
         adivinhação. */
      function atualizar() {
        const v = campo.value, ini = campo.selectionStart, fim = campo.selectionEnd;
        const sel = v.slice(ini, fim);
        const linhaIni = v.lastIndexOf('\n', Math.max(0, ini - 1)) + 1;
        const linha = v.slice(linhaIni, v.indexOf('\n', ini) === -1 ? v.length : v.indexOf('\n', ini));

        barra.querySelectorAll('button[data-marca]').forEach(b => {
          const m = b.dataset.marca;
          const cercado = sel.length > 2 * m.length && sel.startsWith(m) && sel.endsWith(m);
          const porFora  = v.slice(ini - m.length, ini) === m && v.slice(fim, fim + m.length) === m;
          b.classList.toggle('ativo', ini !== fim && (cercado || porFora));
        });
        barra.querySelectorAll('button[data-linha]').forEach(b => {
          b.classList.toggle('ativo', linha.startsWith(b.dataset.linha.trim()));
        });
      }

      barra.addEventListener('click', (ev) => {
        const b = ev.target.closest('button');
        if (!b) return;
        ev.preventDefault();
        if (b.dataset.marca)  return marcar(b.dataset.marca);
        if (b.dataset.limpar) return limpar();
        if (b.dataset.linha)  return prefixar(b.dataset.linha);
      });

      // Ctrl+B e Ctrl+I, porque é o que a mão faz sozinha.
      campo.addEventListener('keydown', (ev) => {
        if (!(ev.ctrlKey || ev.metaKey)) return;
        const k = ev.key.toLowerCase();
        if (k === 'b') { ev.preventDefault(); marcar('*'); }
        if (k === 'i') { ev.preventDefault(); marcar('_'); }
      });

      ['keyup', 'mouseup', 'select', 'focus', 'input'].forEach(e =>
        campo.addEventListener(e, atualizar));
      atualizar();

      /* INSERIR FOTO: escreve a marca no cursor, em parágrafo próprio.
         O botão da galeria manda o número; este aqui é o atalho pra primeira
         foto que ainda não foi usada no texto. */
      window.inserirFotoNoTexto = function (n) {
        const v = campo.value;
        let pos = campo.selectionStart;
        // Sobe pro fim da linha anterior se o cursor estiver no meio de uma:
        // a marca precisa de um parágrafo só pra ela.
        const marca = '[foto:' + n + ']';
        const antes = v.slice(0, pos);
        const depois = v.slice(pos);
        const pre  = antes && !antes.endsWith('\n\n') ? (antes.endsWith('\n') ? '\n' : '\n\n') : '';
        const pos2 = depois && !depois.startsWith('\n\n') ? (depois.startsWith('\n') ? '\n' : '\n\n') : '';
        const texto = pre + marca + pos2;
        escrever(texto, pos, pos);
        const p = pos + texto.length;
        campo.setSelectionRange(p, p);
        campo.focus();
      };

      /* LINK. Pede o endereço e escreve [texto](url). Se havia seleção, ela
         vira o texto do link; se não, o próprio endereço aparece. Só http e
         https entram — o renderizador recusa o resto, e avisar aqui é melhor
         que deixar a pessoa descobrir que o link sumiu depois de publicar. */
      async function linkar() {
        const ini = campo.selectionStart, fim = campo.selectionEnd;
        const sel = campo.value.slice(ini, fim).trim();
        let url = await perguntarSite("Endereço do link:", "https://");
        if (url === null) return;
        url = url.trim();
        if (!url || url === "https://") return;
        if (!/^(https?:\/\/|\/)/i.test(url)) {
          if (/^[\w.-]+\.[a-z]{2,}/i.test(url)) url = "https://" + url;
          else { alert("O link precisa começar com http:// ou https://."); return; }
        }
        const texto = sel || url;
        const marca = "[" + texto + "](" + url + ")";
        escrever(marca, ini, fim);
        // Cursor no fim do link, pronto pra continuar a frase.
        const p2 = ini + marca.length;
        campo.setSelectionRange(p2, p2);
        campo.focus();
      }
      document.getElementById("btnLink")?.addEventListener("click", linkar);

      /* Ctrl+K, que é o atalho de link em todo lugar. */
      campo.addEventListener("keydown", (ev) => {
        if ((ev.ctrlKey || ev.metaKey) && ev.key.toLowerCase() === "k") {
          ev.preventDefault(); linkar();
        }
      });

      /* O RASCUNHO AUTOMÁTICO leva o que está na TELA.

         Numa matéria que ainda não existe, enviar foto cria o rascunho no
         servidor — e sem estes campos ele nasceria vazio, jogando fora o que
         a pessoa já tinha escrito. Copiados no submit, e não pelo PHP, porque
         o valor que vale é o de agora. */
      const formGal = document.getElementById("formGaleria");
      formGal?.addEventListener("submit", () => {
        [["titulo","rascunho_titulo"],["chapeu","rascunho_chapeu"],
         ["resumo","rascunho_resumo"],["texto","rascunho_texto"]].forEach(([de, para]) => {
          const origem = document.querySelector("form.redacao [name=" + de + "]");
          const destino = formGal.querySelector("[name=" + para + "]");
          if (origem && destino) destino.value = origem.value;
        });
        // O rascunho local some: a matéria vai existir no servidor agora.
        try { localStorage.removeItem(chave); } catch (e) {}
      });
      const btnFoto = document.getElementById('btnInserirFoto');
      btnFoto?.addEventListener('click', () => {
        const usadas = (campo.value.match(/\[foto:(\d+)\]/g) || [])
          .map(x => parseInt(x.replace(/\D/g, ''), 10));
        const todas = [...document.querySelectorAll('.gal-item')].map(e => parseInt(e.dataset.n, 10));
        if (!todas.length) {
          alert('Nenhuma foto na galeria ainda. Envie uma foto primeiro, aqui embaixo.');
          return;
        }
        const livre = todas.find(n => !usadas.includes(n)) ?? todas[0];
        window.inserirFotoNoTexto(livre);
      });
    })();

    // A prévia da foto antes de enviar: sem ela a pessoa só descobre que
    // escolheu o arquivo errado depois de salvar a notícia.
    const fotoInput = document.getElementById('fotoArquivo');
    const previaNova = document.getElementById('previaNova');
    fotoInput?.addEventListener("change", () => {
      const f = fotoInput.files && fotoInput.files[0];
      if (!f) { previaNova.hidden = true; return; }
      const img = previaNova.querySelector("img");
      // revokeObjectURL no anterior: cada escolha cria uma URL nova, e sem
      // soltar a antiga o navegador segura o arquivo inteiro na memória.
      if (img.dataset.url) URL.revokeObjectURL(img.dataset.url);
      const url = URL.createObjectURL(f);
      img.dataset.url = url; img.src = url; previaNova.hidden = false;
    });
</script>
</body>
</html>
