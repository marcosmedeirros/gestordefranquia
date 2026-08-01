<?php
/**
 * Preview de link (Open Graph) para páginas que exigem login.
 *
 * O problema: mandar qualquer link da FBA no WhatsApp mostrava sempre
 * "FBA Manager - Login". É que o robô que gera o preview não tem sessão, então
 * o requireAuth() mandava ele pro /login.php — e o preview virava o da tela de
 * login, sem relação nenhuma com a página compartilhada.
 *
 * Aqui o robô é reconhecido e recebe uma página só com as meta tags da página
 * de verdade. Gente continua indo pro login normalmente: ninguém vê conteúdo
 * sem estar logado, só o título e a descrição — que é o que o preview mostra.
 */

/** O agente é um robô de preview de link (WhatsApp, Telegram, Discord…)? */
function ehRoboDePreview(): bool
{
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    if ($ua === '') return false;

    $robos = [
        'WhatsApp', 'Telegram', 'TelegramBot', 'Discordbot', 'Slackbot',
        'facebookexternalhit', 'Facebot', 'Twitterbot', 'LinkedInBot',
        'SkypeUriPreview', 'redditbot', 'Applebot', 'iframely',
        'Google-InspectionTool', 'vkShare', 'W3C_Validator', 'Embedly',
    ];
    foreach ($robos as $r) {
        if (stripos($ua, $r) !== false) return true;
    }
    return false;
}

/**
 * Título e descrição de cada página, pelo nome do arquivo.
 * Só entra aqui o que faz sentido alguém compartilhar.
 */
function metaDaPagina(string $arquivo): array
{
    $mapa = [
        'legends-draft.php'    => ['Draft de Lendas', 'Acompanhe o Draft de Lendas da FBA ao vivo — quem escolheu quem, pick a pick.'],
        'drafts.php'           => ['Draft', 'A sala do Draft da FBA: ordem das escolhas, relógio e quem está na vez.'],
        'initdraftselecao.php' => ['Draft Inicial', 'A sala do Draft Inicial da FBA — a montagem dos elencos do zero.'],
        'draft-players.php'    => ['Classe do Draft', 'Os prospectos disponíveis na próxima classe de draft da FBA.'],
        'trades.php'           => ['Trades', 'Propostas de troca da liga: recebidas, enviadas e o histórico.'],
        'trade-simulator.php'  => ['Simulador de Trocas', 'Monte uma troca e veja se ela fecha antes de propor.'],
        'trade-list.php'       => ['Lista de Trocas', 'Jogadores que os GMs marcaram como disponíveis para troca.'],
        'players.php'          => ['Jogadores', 'Todos os jogadores da FBA — busque, filtre e compare.'],
        'player.php'           => ['Jogador', 'Ficha do jogador na FBA: atributos, time e histórico.'],
        'teams.php'            => ['Times', 'Os times da FBA e seus elencos.'],
        'team-public-page.php' => ['Página do Time', 'A página pública do time na FBA.'],
        'my-roster.php'        => ['Meu Elenco', 'Gerencie o elenco do seu time na FBA.'],
        'picks.php'            => ['Picks', 'As escolhas de draft de cada time da FBA.'],
        'tabela.php'           => ['Tabela', 'A classificação e o chaveamento dos playoffs da FBA.'],
        'rankings.php'         => ['Rankings', 'O ranking geral das ligas da FBA.'],
        'estatisticas.php'     => ['Estatísticas', 'Os líderes de estatística da FBA.'],
        'timeline.php'         => ['Timeline', 'O que está acontecendo na FBA agora.'],
        'mercado.php'          => ['Mercado', 'O mural de rumores e negociações da FBA.'],
        'free-agency.php'      => ['Free Agency', 'Os agentes livres da FBA e as disputas abertas.'],
        'leilao.php'           => ['Leilão', 'Os leilões de jogadores abertos na FBA.'],
        'dispensas.php'        => ['Dispensas', 'Os jogadores dispensados e a janela para reivindicar.'],
        'lottery.php'          => ['Loteria', 'O sorteio da ordem do draft da FBA.'],
        'games.php'            => ['Games', 'Os minigames e as apostas da FBA.'],
        'history.php'          => ['Histórico', 'A história das temporadas da FBA.'],
        'hall-da-fama.php'     => ['Hall da Fama', 'Os imortais da FBA.'],
        'mundo-fba.php'        => ['Mundo FBA', 'Uma visão geral de todas as ligas da FBA.'],
        'thepathetic.php'      => ['The Pathetic', 'O jornal da FBA.'],
        'retrospectiva.php'    => ['Retrospectiva', 'O resumo da sua temporada na FBA.'],
        'team-history.php'     => ['História do Time', 'A trajetória da franquia na FBA.'],
        'tatica.php'           => ['Tática', 'A tática do seu time na FBA.'],
        'dashboard.php'        => ['FBA Manager', 'A liga de fantasy basketball FBA Brasil.'],
    ];

    return $mapa[$arquivo] ?? ['FBA Manager', 'FBA Brasil — a liga de fantasy basketball.'];
}

/**
 * Responde ao robô com as meta tags da página e encerra.
 * Não faz nada se quem pediu não for um robô de preview.
 */
function responderPreviewSeRobo(): void
{
    if (!ehRoboDePreview()) return;

    $arquivo = basename($_SERVER['PHP_SELF'] ?? '');
    [$titulo, $descricao] = metaDaPagina($arquivo);

    $esquema = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host    = $_SERVER['HTTP_HOST'] ?? 'fbabrasil.com.br';
    $url     = $esquema . '://' . $host . ($_SERVER['REQUEST_URI'] ?? '/');
    $imagem  = $esquema . '://' . $host . '/img/icons/icon-512.png';

    $t = htmlspecialchars($titulo . ' — FBA Manager', ENT_QUOTES, 'UTF-8');
    $d = htmlspecialchars($descricao, ENT_QUOTES, 'UTF-8');
    $u = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
    $i = htmlspecialchars($imagem, ENT_QUOTES, 'UTF-8');

    header('Content-Type: text/html; charset=UTF-8');
    echo <<<HTML
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<title>{$t}</title>
<meta name="description" content="{$d}">
<meta property="og:site_name" content="FBA Manager">
<meta property="og:type" content="website">
<meta property="og:title" content="{$t}">
<meta property="og:description" content="{$d}">
<meta property="og:url" content="{$u}">
<meta property="og:image" content="{$i}">
<meta property="og:locale" content="pt_BR">
<meta name="twitter:card" content="summary">
<meta name="twitter:title" content="{$t}">
<meta name="twitter:description" content="{$d}">
<meta name="twitter:image" content="{$i}">
</head>
<body><p>{$t}</p></body>
</html>
HTML;
    exit;
}
