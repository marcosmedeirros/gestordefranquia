<?php
/**
 * CLUBE FBA — o que a liga anda vendo, lendo e ouvindo.
 *
 * SAIU DE /games DE PROPÓSITO. Os jogos do Games são partida: entra, joga,
 * sai com um placar. Isto é o contrário — é acervo, cresce devagar e o valor
 * está em voltar depois pra ver o que os outros marcaram. Misturado com os
 * joguinhos ele seria mais um card entre trinta; aqui ele é a casa, e as
 * outras mídias entram como aba em vez de virar outro jogo solto.
 *
 * ── AS ABAS DE MÍDIA ─────────────────────────────────────────────────
 *
 * Séries está de pé. Livros, Filmes e Música aparecem DESLIGADAS e dizendo
 * que não chegaram, em vez de não aparecerem: quem abre a página entende na
 * hora que o Clube é maior que séries, e sabe o que vem. Prometer em cinza é
 * honesto; prometer em link que abre tela vazia não é.
 *
 * ── O QUE CADA ABA DE SÉRIES RESPONDE ────────────────────────────────
 *
 *   Catálogo  — o que existe pra marcar (e a busca que vai até o TMDB).
 *   Meu perfil— os meus números, o meu top e o ACERVO: a lista de tudo que
 *               eu marquei, com filtro, busca, ordem, a nota na própria
 *               linha e o X pra tirar.
 *   A liga    — os rankings da liga, o movimento e tudo que alguém daqui viu.
 *   Pessoas   — o diário de cada um, procurando pelo nome.
 *
 * A regra do jogo está em backend/series.php; aqui é tela e roteamento.
 *
 * @see backend/series.php            as regras e as consultas
 * @see backend/series_tmdb.php       a busca que sai do catálogo
 * @see games/core/series_importar_cli.php  de onde o catálogo vem
 * @see games/games/series.php        o endereço antigo, que só redireciona
 */

require_once __DIR__ . '/backend/auth.php';
require_once __DIR__ . '/backend/db.php';
require_once __DIR__ . '/backend/series.php';
require_once __DIR__ . '/backend/observador.php';
require_once __DIR__ . '/backend/clube_semana.php';   // álbum e filme da semana
require_once __DIR__ . '/backend/clube_livro.php';
require_once __DIR__ . '/backend/clube_livro_arquivos.php';    // o clube do livro, só pra quem pediu

/* PÁGINA DO APP, e não mais uma tela solta de /games: entra pelo login da FBA
   como qualquer outra, com menu lateral e a cor que a pessoa escolheu. Quem
   não está logado vai pro login — não existe mais a tela de "precisa entrar"
   dentro do Clube, porque ela contava a mesma coisa duas vezes. */
requireAuth();

$user = getUserSession();
$pdo  = db();
/* O cartão do time no menu. Vem por timeDaTela pra respeitar o modo
   observador, igual às outras telas. */
$team = timeDaTela($pdo, (int)$user['id']);

$idUsuario = (int)$user['id'];

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

/** A foto de alguém, com o fallback da casa. */
function clubeAvatar(?string $foto): string
{
    $foto = trim((string)$foto);
    return $foto !== '' ? $foto : '/img/default-avatar.png';
}

/**
 * UM "DA SEMANA" COMPLETO: o cartaz com a timeline de notas, a enquete de
 * sexta e o ranking da aba. É o mesmo bloco pra Música, Filmes e Clube do
 * Livro — três cópias disso divergiriam na primeira semana.
 */
function clubeBlocoSemana(PDO $pdo, string $tipo, int $idUsuario): void
{
    /* O bloco é função, e o admin é do arquivo: sem o global, a área de
       administração do livro nunca apareceria. */
    global $ehAdminClube;

    $d = clubeSemanaEstadoDoTipo($pdo, $tipo, $idUsuario);
    $i = $d['info']; $c = $d['cartaz']; $v = $d['enquete']; $rk = $d['ranking'];
    $plural = ['album' => 'álbuns', 'filme' => 'filmes', 'livro' => 'livros'][$tipo];
    ?>
    <div class="bloco">
      <h3 style="margin:0 0 10px"><i class="bi bi-<?= $i['ico'] ?>"></i> <?= h($i['rot']) ?></h3>

      <?php if ($c): ?>
        <div class="cartaz-rot">Em cartaz · <?= $tipo === 'livro'
            ? 'o livro de ' . h(clubeSemanaMes($c['semana']))
            : 'semana de ' . date('d/m', strtotime($c['semana'])) ?><?=
            $tipo === 'livro' && $c['genero_escolhido'] ? ' · ' . h($c['genero_escolhido']) : '' ?></div>
        <div class="cartaz-tit"><?= h($c['titulo']) ?></div>
        <div class="cartaz-sub"><?= h($c['autor']) ?><?= $c['ano'] ? ' · ' . (int)$c['ano'] : '' ?></div>

        <?php if ($tipo === 'livro'):
          /* O ARQUIVO DO LIVRO, em duas línguas (pedido da Agata, 09/10/2026).
             O link passa por clube-livro-arquivo.php e não pela pasta: o PDF
             é de quem está lendo junto, não de quem achar o endereço. */
          $arqs = clubeLivroArquivos($pdo, (int)$c['ciclo']);
          if ($arqs): ?>
          <div class="livro-arqs">
            <?php foreach (CLUBE_LIVRO_IDIOMAS as $idi => $meta): ?>
              <?php if (empty($arqs[$idi])) continue; ?>
              <a class="livro-arq" target="_blank" rel="noopener"
                 href="/clube-livro-arquivo.php?ciclo=<?= (int)$c['ciclo'] ?>&idioma=<?= h($idi) ?>">
                <i class="bi <?= h($meta['ico']) ?>"></i> <?= h($meta['rot']) ?>
                <small><?= h($arqs[$idi]['nome']) ?></small></a>
            <?php endforeach; ?>
          </div>
          <?php endif; ?>

          <?php if ($ehAdminClube): ?>
            <form class="livro-up" method="POST" enctype="multipart/form-data">
              <input type="hidden" name="acao" value="livro_arquivo">
              <input type="hidden" name="ciclo" value="<?= (int)$c['ciclo'] ?>">
              <div class="livro-up-l">
                <?php foreach (CLUBE_LIVRO_IDIOMAS as $idi => $meta): ?>
                  <label class="livro-up-c">
                    <span><i class="bi <?= h($meta['ico']) ?>"></i> <?= h($meta['rot']) ?>
                      <?= !empty($arqs[$idi]) ? '<b>· já tem</b>' : '' ?></span>
                    <input type="file" name="arq_<?= h($idi) ?>" accept=".pdf,.epub">
                  </label>
                <?php endforeach; ?>
              </div>
              <button type="submit" class="btn pri"><i class="bi bi-upload"></i> Subir arquivo</button>
              <p class="livro-up-nota">PDF ou EPUB, até <?= (int)(CLUBE_LIVRO_MAX / 1048576) ?>MB cada.
                 Só quem está no clube consegue baixar.</p>
            </form>
            <?php foreach ($arqs as $idi => $a): ?>
              <form method="POST" class="livro-arq-tirar"
                    data-confirmar="Tirar o arquivo em <?= h(CLUBE_LIVRO_IDIOMAS[$idi]['rot']) ?>?">
                <input type="hidden" name="acao" value="livro_arquivo_tirar">
                <input type="hidden" name="ciclo" value="<?= (int)$c['ciclo'] ?>">
                <input type="hidden" name="idioma" value="<?= h($idi) ?>">
                <button type="submit">tirar o <?= h(mb_strtolower(CLUBE_LIVRO_IDIOMAS[$idi]['rot'])) ?></button>
              </form>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>
        <?php if ($c['media'] !== null): ?>
          <div class="cartaz-med"><b><?= h($c['media']) ?></b>
            média da liga · <?= count($c['opinioes']) ?> na timeline</div>
        <?php endif; ?>

        <form class="opina" method="POST">
          <input type="hidden" name="acao" value="semana_opinar">
          <input type="hidden" name="ciclo" value="<?= (int)$c['ciclo'] ?>">
          <textarea name="texto" maxlength="1200"
            placeholder="Deu tempo de <?= h($i['verbo']) ?>? Nota e comentário entram na timeline."><?= h($c['minha']['texto'] ?? '') ?></textarea>
          <div class="opina-l">
            <select name="nota" aria-label="Sua nota">
              <option value="">sem nota</option>
              <?php for ($n = 10; $n >= 0; $n--): ?>
                <option value="<?= $n ?>" <?= $c['minha'] && $c['minha']['nota'] !== null && (int)$c['minha']['nota'] === $n ? 'selected' : '' ?>><?= $n ?></option>
              <?php endfor; ?>
            </select>
            <button type="submit" class="btn pri"><?= $c['minha'] ? 'Atualizar' : 'Entrar na timeline' ?></button>
          </div>
        </form>

        <?php if ($c['opinioes']): ?>
          <div class="opi">
            <?php foreach ($c['opinioes'] as $op): ?>
              <div class="opi-um">
                <img src="<?= h(clubeAvatar($op['photo_url'])) ?>" alt="">
                <div>
                  <div class="opi-cab"><b><?= h($op['name']) ?></b>
                    <?php if ($op['nota'] !== null): ?><span class="opi-nota"><?= (int)$op['nota'] ?></span><?php endif; ?>
                  </div>
                  <?php if (trim((string)$op['texto']) !== ''): ?>
                    <div class="opi-txt"><?= h($op['texto']) ?></div>
                  <?php endif; ?>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      <?php else: ?>
        <p style="color:var(--txt2);font-size:13.5px;margin:0">
          <?= $tipo === 'livro'
              /* O livro não sai mais na sexta: a lista é escolhida pelo clube e
                 a votação é aberta à mão (@see CLUBE_SEMANA_AUTO). Prometer a
                 sexta aqui seria marcar um encontro que não vai acontecer. */
              ? 'O primeiro sai da votação que o clube abrir aqui.'
              : 'O primeiro sai <b>sexta às 20h</b>, escolhido pelo voto da liga.' ?></p>
      <?php endif; ?>

      <?php if ($v): ?>
        <div class="sem-h4"><?= $v['etapa'] === 'genero'
            ? 'Enquete de hoje — que gênero o clube lê esta semana?'
            : 'Enquete de hoje' . ($tipo === 'livro' && $v['genero_escolhido'] ? ' — ' . h($v['genero_escolhido']) : '') ?></div>
        <div class="sem-prazo"><?= $v['etapa'] === 'genero'
              ? 'o gênero fecha ' . h($v['dia_fecha'] ?? 'hoje') . ' às ' . h($v['hora_vira'])
                . '; os livros, às ' . h($v['hora_fecha'])
              : 'fecha ' . h($v['dia_fecha'] ?? 'hoje') . ' às ' . h($v['hora_fecha']) ?>
          · <?= (int)$v['total'] ?> voto<?= (int)$v['total'] === 1 ? '' : 's' ?><?=
            $v['meu'] ? ' · o seu está marcado' : '' ?></div>
        <div class="vops">
          <?php foreach ($v['opcoes'] as $o):
              $pct = $v['total'] > 0 ? round(100 * $o['votos'] / $v['total']) : 0; ?>
            <form method="POST">
              <input type="hidden" name="acao" value="semana_votar">
              <input type="hidden" name="ciclo" value="<?= (int)$v['id'] ?>">
              <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
              <button type="submit" class="vop <?= (int)$v['meu'] === (int)$o['id'] ? 'on' : '' ?>"
                      style="--pct:<?= $pct ?>%">
                <i class="vop-bar"></i>
                <span class="vop-tit"><?= h($o['titulo']) ?>
                  <?php if (trim((string)$o['autor']) !== ''): ?>
                    <small><?= h($o['autor']) ?><?= $o['ano'] ? ' · ' . (int)$o['ano'] : '' ?></small>
                  <?php endif; ?></span>
                <span class="vop-n"><?= (int)$o['votos'] ?></span>
              </button>
            </form>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <?php if ($tipo === 'livro' && $ehAdminClube):
          /* ABRIR A VOTAÇÃO DO LIVRO À MÃO. A lista é escolhida fora — o
             sorteio automático trouxe o livro 5 de uma saga e saiu de cena
             por isso (@see CLUBE_SEMANA_AUTO). */ ?>
          <div class="sem-h4">Abrir a votação do livro</div>
          <form class="livro-abrir" method="POST">
            <input type="hidden" name="acao" value="livro_votacao_abrir">
            <textarea name="obras" rows="6" required
              placeholder="Um por linha:&#10;Duna — Frank Herbert&#10;A Revolução dos Bichos — George Orwell&#10;Neuromancer — William Gibson"></textarea>
            <div class="livro-abrir-l">
              <label>Aberta por
                <select name="horas">
                  <option value="24">1 dia</option>
                  <option value="48">2 dias</option>
                  <option value="72" selected>3 dias</option>
                  <option value="168">1 semana</option>
                </select>
              </label>
              <button type="submit" class="btn pri"><i class="bi bi-ui-checks"></i> Abrir votação</button>
            </div>
            <p class="livro-up-nota">Título e autor separados por travessão. Sem autor,
               a linha inteira vira o título.</p>
          </form>
        <?php endif; ?>
        <div class="sem-h4">Próxima enquete</div>
        <p style="color:var(--txt3);font-size:12.5px;margin:0">
          <?= $tipo === 'livro'
              ? 'O livro é escolhido pelo clube e a votação é aberta aqui quando a lista estiver pronta.'
              : 'Sexta-feira, das 9h às 20h. Às 20h a enquete fecha e sai o da semana.' ?></p>
      <?php endif; ?>
    </div>

    <?php if ($rk['top']): ?>
      <div class="bloco" style="margin-top:14px">
        <h3 style="margin:0 0 10px"><i class="bi bi-trophy-fill"></i> Top 5 <?= h($plural) ?> da liga</h3>
        <div class="estante">
          <?php foreach ($rk['top'] as $n => $r): ?>
            <div class="estante-um">
              <span><b><?= $n + 1 ?>º</b> <?= h($r['titulo']) ?> <small>— <?= h($r['autor']) ?></small></span>
              <small><b><?= h($r['media']) ?></b> · <?= (int)$r['notas'] ?> nota<?= (int)$r['notas'] === 1 ? '' : 's' ?></small>
            </div>
          <?php endforeach; ?>
        </div>
        <?php if ($rk['piores']): ?>
          <div class="sem-h4">Os piores, com carinho</div>
          <div class="estante">
            <?php foreach ($rk['piores'] as $r): ?>
              <div class="estante-um">
                <span>💀 <?= h($r['titulo']) ?> <small>— <?= h($r['autor']) ?></small></span>
                <small><b><?= h($r['media']) ?></b> · <?= (int)$r['notas'] ?> nota<?= (int)$r['notas'] === 1 ? '' : 's' ?></small>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>
    <?php
}

/**
 * AS MÍDIAS DO CLUBE.
 *
 * `ok` é o que separa aba de promessa. Ligar uma é trocar false por true
 * depois que a tela dela existir — e a ordem aqui é a ordem na barra.
 */
const CLUBE_MIDIAS = [
    'series' => ['rot' => 'Séries',         'ico' => 'projector-fill',    'ok' => true],
    /* MÚSICA E FILMES são o "da semana" de cada uma (07/10/2026, segunda
       versão do desenho — a primeira tinha uma aba "Da Semana" com os dois
       juntos e durou uma tarde): enquete toda sexta das 9h às 20h, o mais
       votado vira o da semana, timeline de notas e o ranking da aba. */
    'musica' => ['rot' => 'Música',         'ico' => 'music-note-beamed', 'ok' => true],
    'filmes' => ['rot' => 'Filmes',         'ico' => 'film',              'ok' => true],
    /* O CLUBE DO LIVRO só aparece na barra pra quem entrou ("uma aba que só
       aparece para quem clicou lá que queria"). O convite fica nas abas de
       Música e Filmes. Lá a sexta tem duas etapas: gênero de manhã,
       livros do gênero à tarde. */
    'livro'  => ['rot' => 'Clube do Livro', 'ico' => 'book-half',         'ok' => true],
];

/**
 * O VERBO DO FEED.
 *
 * SERIES_ESTADOS tem os rótulos do botão ("Assistida"), que servem pra
 * escolher e não pra contar: "Marco Assistida Breaking Bad" não é frase.
 * O feed conta o que a pessoa fez, então usa verbo.
 */
const CLUBE_VERBO = ['quero' => 'quer ver', 'assistindo' => 'está vendo', 'assistida' => 'assistiu'];

$midia = (string)($_GET['midia'] ?? 'series');
/* A aba "Da Semana" durou uma tarde (07/10/2026): quem guardou o link cai
   na Música, que herdou o álbum da semana. */
if ($midia === 'semana') $midia = 'musica';
if (!isset(CLUBE_MIDIAS[$midia]) || !CLUBE_MIDIAS[$midia]['ok']) {
    if (!isset(CLUBE_MIDIAS[$midia])) $midia = 'series';
}

/* ── DA SEMANA E CLUBE DO LIVRO SÃO FORMULÁRIO, NÃO JSON ─────────────
   Votar, opinar, entrar: uma ação por visita, sem lugar na tela a perder —
   POST, grava, redireciona. O JSON fica pras séries, onde quem marca cinco
   coisas seguidas não pode recarregar a página a cada clique. */
$acoesForm = ['semana_votar', 'semana_opinar', 'livro_entrar', 'livro_sair', 'livro_votar',
              'livro_enquete', 'livro_enquete_fechar',
              /* A enquete do livro e os arquivos dele: o livro saiu do sorteio
                 automático em 09/10/2026 (@see CLUBE_SEMANA_AUTO). */
              'livro_votacao_abrir', 'livro_arquivo', 'livro_arquivo_tirar'];
$ehAdminClube = (($user['user_type'] ?? '') === 'admin');
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST'
        && in_array((string)($_POST['acao'] ?? ''), $acoesForm, true)) {
    $acao = (string)$_POST['acao'];
    $volta = '?midia=musica';

    if ($acao === 'semana_votar') {
        /* O votar devolve o TIPO do ciclo, e é dele que sai a aba de volta:
           confiar num campo do formulário deixaria o redirect na mão de quem
           edita HTML. */
        $tipo = clubeSemanaVotar($pdo, $idUsuario, (int)($_POST['ciclo'] ?? 0), (int)($_POST['opcao'] ?? 0));
        if ($tipo !== null) $volta = '?midia=' . CLUBE_SEMANA_TIPOS[$tipo]['midia'];
    } elseif ($acao === 'semana_opinar') {
        $nota = ($_POST['nota'] ?? '') === '' ? null : (int)$_POST['nota'];
        $tipo = clubeSemanaOpinar($pdo, $idUsuario, (int)($_POST['ciclo'] ?? 0), $nota,
                                  (string)($_POST['texto'] ?? ''));
        if ($tipo !== null) $volta = '?midia=' . CLUBE_SEMANA_TIPOS[$tipo]['midia'];
    } elseif ($acao === 'livro_entrar') {
        clubeLivroEntrar($pdo, $idUsuario);
        $volta = '?midia=livro';
    } elseif ($acao === 'livro_sair') {
        clubeLivroSair($pdo, $idUsuario);
    } elseif (clubeLivroEhMembro($pdo, $idUsuario) || $ehAdminClube) {
        /* Daqui pra baixo é coisa de dentro do clube: quem não é membro não
           vota nem resenha, mesmo forjando o POST. */
        $volta = '?midia=livro';
        if ($acao === 'livro_votar') {
            clubeLivroVotar($pdo, $idUsuario, (int)($_POST['enquete'] ?? 0), (int)($_POST['opcao'] ?? 0));
        } elseif ($acao === 'livro_enquete' && $ehAdminClube) {
            clubeLivroCriarEnquete($pdo, $idUsuario, (string)($_POST['pergunta'] ?? ''),
                                   preg_split('/\r?\n/', (string)($_POST['opcoes'] ?? '')),
                                   !empty($_POST['multi']));
        } elseif ($acao === 'livro_enquete_fechar' && $ehAdminClube) {
            clubeLivroFecharEnquete($pdo, (int)($_POST['enquete'] ?? 0));

        } elseif ($acao === 'livro_votacao_abrir' && $ehAdminClube) {
            /* A VOTAÇÃO DO LIVRO, COM A LISTA ESCOLHIDA FORA. Um título por
               linha, no formato "Título — Autor"; sem o travessão, a linha
               inteira vira o título, que é o que acontece com quadrinho e
               com obra sem autor único. */
            $linhas = preg_split('/\r?\n/', (string)($_POST['obras'] ?? ''));
            $obras = [];
            foreach ($linhas as $l) {
                $l = trim($l);
                if ($l === '') continue;
                $p = preg_split('/\s+[—–-]\s+/u', $l, 2);
                $obras[] = [mb_substr(trim($p[0]), 0, 190), mb_substr(trim($p[1] ?? ''), 0, 140), null];
            }
            $horas = max(1, min(720, (int)($_POST['horas'] ?? 24)));
            if (count($obras) >= 2) {
                clubeSemanaAbrirVotacaoDeLivro($pdo, $obras, $horas);
            }

        } elseif ($acao === 'livro_arquivo' && $ehAdminClube) {
            $cid = (int)($_POST['ciclo'] ?? 0);
            foreach (array_keys(CLUBE_LIVRO_IDIOMAS) as $idi) {
                if (!isset($_FILES['arq_' . $idi])) continue;
                $err = clubeLivroGuardarArquivo($pdo, $cid, $idi, $_FILES['arq_' . $idi]);
                if ($err !== '') { $volta .= '&erro=' . rawurlencode($err); break; }
            }

        } elseif ($acao === 'livro_arquivo_tirar' && $ehAdminClube) {
            clubeLivroApagarArquivo($pdo, (int)($_POST['ciclo'] ?? 0),
                                    (string)($_POST['idioma'] ?? ''));
        }
    }
    header('Location: /clube.php' . $volta);
    exit;
}

/* ── AS AÇÕES RESPONDEM JSON ──────────────────────────────────────────
   A tela nunca recarrega pra marcar uma série: quem está varrendo a grade
   marcando cinco coisas perderia o lugar a cada clique. */
if ($idUsuario > 0 && $_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    $acao  = (string)($_POST['acao'] ?? '');
    $serie = (int)($_POST['serie'] ?? 0);

    /* MARCAR E AVALIAR JÁ VOLTAM COM A LISTA DA LIGA.

       Antes a tela dava a nota e logo em seguida pedia a ficha inteira só
       pra redesenhar "o que a liga diz" — duas viagens pro que cabe numa.
       A consulta é pequena e o servidor já está com o banco na mão aqui. */
    if ($acao === 'marcar') {
        echo json_encode(seriesMarcar($pdo, $idUsuario, $serie, (string)($_POST['estado'] ?? ''))
            + ['perfil' => seriesPerfil($pdo, $idUsuario),
               'quem'   => seriesQuemMarcou($pdo, $serie)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'avaliar') {
        $n = $_POST['nota'] === '' ? null : (int)$_POST['nota'];
        echo json_encode(seriesAvaliar($pdo, $idUsuario, $serie, $n)
            + ['perfil' => seriesPerfil($pdo, $idUsuario),
               'quem'   => seriesQuemMarcou($pdo, $serie)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'favoritar') {
        echo json_encode(seriesFavoritar($pdo, $idUsuario, $serie)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* O X DA LISTA. Vem com o perfil de volta porque tirar muda os quatro
       números do topo, e eles não podem mentir até o próximo F5. */
    if ($acao === 'tirar') {
        echo json_encode(seriesTirar($pdo, $idUsuario, $serie)
            + ['perfil' => seriesPerfil($pdo, $idUsuario)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if ($acao === 'comentar') {
        echo json_encode(seriesComentar($pdo, $idUsuario, $serie, (string)($_POST['texto'] ?? ''))
            + ['quem' => seriesQuemMarcou($pdo, $serie)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode(['ok' => false, 'erro' => 'Ação desconhecida.']);
    exit;
}

/* A busca também é JSON: uma consulta por pausa na digitação. */
if ($idUsuario > 0 && isset($_GET['json'])) {
    header('Content-Type: application/json; charset=utf-8');
    if ($_GET['json'] === 'busca') {
        echo json_encode(['series' => seriesBuscar($pdo, (string)($_GET['q'] ?? ''), $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* ── A BUSCA QUE VAI ALÉM DO CATÁLOGO ─────────────────────────────
       Separada da busca local de propósito. A local responde na hora e é o
       caso de quase toda tecla digitada; esta fala com o TMDB e pode levar
       dois segundos. Juntas num endpoint só, toda busca pagaria o preço da
       minoria — e a tela não teria como avisar que está procurando fora.

       A página só chega aqui quando a local devolveu pouca coisa. */
    if ($_GET['json'] === 'busca_fora') {
        require_once __DIR__ . '/backend/series_tmdb.php';
        $termo = (string)($_GET['q'] ?? '');
        $novas = seriesProcurarNoTmdb($pdo, $termo);
        echo json_encode(['novas' => $novas, 'series' => seriesBuscar($pdo, $termo, $idUsuario)],
                         JSON_UNESCAPED_UNICODE);
        exit;
    }
    /* A FICHA VEM COM A LIGA DENTRO. Duas chamadas pra abrir um popup
       deixariam a lista de recados piscando depois do resto; e quem abre a
       ficha abre justamente pra ver o que os outros disseram. */
    if ($_GET['json'] === 'serie') {
        $id = (int)($_GET['id'] ?? 0);
        echo json_encode(['serie' => seriesUma($pdo, $id, $idUsuario),
                          'quem'  => seriesQuemMarcou($pdo, $id)], JSON_UNESCAPED_UNICODE);
        exit;
    }
    echo json_encode([]);
    exit;
}

/* Decide se a aba do Clube do Livro existe pra esta pessoa. Admin vê sempre:
   alguém precisa conseguir entrar lá pra definir o livro antes do primeiro
   membro chegar. */
$souDoLivro = clubeLivroEhMembro($pdo, $idUsuario);

$aba = (string)($_GET['aba'] ?? 'catalogo');
/* A ABA "MINHAS SÉRIES" VIROU O ACERVO DO PERFIL em 30/09/2026. Eram duas
   telas pra mesma pergunta, e a do perfil ficou melhor: tem filtro, busca,
   ordem e o X. O endereço antigo continua chegando em algum lugar certo. */
if ($aba === 'minhas') $aba = 'perfil';
$catalogo = $idUsuario > 0 ? seriesQuantasNoCatalogo($pdo) : 0;
$perfil   = $idUsuario > 0 ? seriesPerfil($pdo, $idUsuario) : null;

/* Quem eu estou olhando na aba Pessoas. Zero = a lista. */
$vendo  = (int)($_GET['u'] ?? 0);
$pessoa = $vendo > 0 ? seriesQuemE($pdo, $vendo) : null;
if (!$pessoa) $vendo = 0;

/* ── OS FILTROS DO ACERVO ─────────────────────────────────────────────
   Vão na URL, e não em estado de JavaScript, porque "minhas assistidas por
   nota" é uma tela que dá pra guardar nos favoritos e mandar pra alguém. */
$cat         = (string)($_GET['cat'] ?? '');
if (!isset(SERIES_ESTADOS[$cat])) $cat = '';
$termoAcervo = trim((string)($_GET['ac'] ?? ''));
$termoPessoa = trim((string)($_GET['nome'] ?? ''));
$ordem       = (string)($_GET['ord'] ?? 'movimento');
$qInicial    = (string)($_GET['q'] ?? '');
?>
<!DOCTYPE html>
<html lang="pt-BR">
<head>
<meta charset="UTF-8">
<script>document.documentElement.dataset.theme = localStorage.getItem('fba-theme') || 'dark';</script>
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<title>Clube FBA</title>
<link rel="manifest" href="/manifest.json?v=3">
<meta name="theme-color" content="#fc0025">
<link rel="icon" type="image/png" href="/img/fba-logo.png?v=3">
<link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css">
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@400;500;600;700;800&family=Inter:wght@400;600;700;800;900&family=Barlow+Condensed:wght@600;700&display=swap" rel="stylesheet">
<style>
/* ── OS TOKENS DO APP ────────────────────────────────────────────────
   Os mesmos do calendário, do cap e das outras telas internas: é o que faz
   o menu lateral e a topbar aparecerem do jeito certo, e o que traz o tema
   claro de graça. */
:root{
  --red:#fc0025;
  --red-2:color-mix(in srgb, var(--red) 85%, white);
  --red-soft:color-mix(in srgb, var(--red) 10%, transparent);
  --red-glow:color-mix(in srgb, var(--red) 18%, transparent);
  --bg:#07070a; --panel:#101013; --panel-2:#16161a; --panel-3:#1c1c21;
  --border:rgba(255,255,255,.06); --border-md:rgba(255,255,255,.10);
  --border-red:color-mix(in srgb, var(--red) 22%, transparent);
  --text:#f0f0f3; --text-2:#868690; --text-3:#7d7d85;
  --green:#22c55e; --amber:#f59e0b; --blue:#3b82f6;
  --sidebar-w:260px;
  --font:'Montserrat',sans-serif;
  --radius:14px; --radius-sm:10px; --radius-xs:6px;
  --ease:cubic-bezier(.2,.8,.2,1); --t:200ms;
}
:root[data-theme="light"]{
  --bg:#f6f7fb; --panel:#fff; --panel-2:#f2f4f8; --panel-3:#e9edf4;
  --border:#e3e6ee; --border-md:#d7dbe6; --text:#12141a; --text-2:#5b6172; --text-3:#6b7080;
}
/* 4,6 e 4,3 de contraste no lugar de 1,39 e 1,94 — e continuam lendo como
   "dourado do IMDb" e "verde da liga", que é o que o número precisa dizer. */
:root[data-theme="light"]{ --imdb-txt:#a16207; --fba-txt:#15803d; }
</style>

<?php /* Barra lateral, topbar, main e hero — o mesmo shell das outras telas. */ ?>
<?php include __DIR__ . '/includes/shell-css.php'; ?>

<style>
/* ── OS NOMES ANTIGOS DO CLUBE, APONTANDO PRO APP ────────────────────
   A tela nasceu com paleta própria (--txt, --borda, --acento...) porque era
   um jogo do /games e vivia sozinha. Agora que ela é página do app, quem
   manda são os tokens de cima — e este bloco é a ponte, num lugar só, em vez
   de trezentas trocas espalhadas pelo arquivo.

   O QUE ISSO GANHA: --acento passa a ser --red, que é a variável que o
   includes/accent-color.php sobrescreve com a cor que a PESSOA escolheu. O
   roxo era do Clube; agora o detalhe é de quem está olhando. E o tema claro
   passa a funcionar aqui também, porque os tokens trocam com ele. */
:root{
  --panel2:var(--panel-2); --panel3:var(--panel-3);
  --borda:var(--border); --borda2:var(--border-md);
  --txt:var(--text); --txt2:var(--text-2); --txt3:var(--text-3);
  --acento:var(--red);
  --acento-2:color-mix(in srgb, var(--red) 45%, #000);
  /* Estas duas NÃO seguem a pessoa: o dourado é a marca do IMDb e o verde é
     a nota da liga. Se virassem a cor escolhida, as três notas da ficha
     ficariam iguais e a ficha perderia justamente o que ela conta.

     MAS CADA UMA TEM DUAS VERSÕES, e não é capricho: o mesmo dourado que
     brilha no selo sobre o pôster (fundo preto) dá 1,39 de contraste como
     TEXTO num painel claro — some. As `-txt` são as que pousam em painel e
     escurecem no tema claro; as outras só aparecem sobre imagem ou como
     fundo, onde o tom vivo é o certo. */
  --imdb:#f5c518; --fba:#22c55e;
  --imdb-txt:var(--imdb); --fba-txt:var(--fba);
  --display:'Barlow Condensed','Inter',system-ui,sans-serif;
}
/* O conteúdo do Clube fala Inter; o menu e a topbar seguem na Montserrat
   das outras telas, pra não virar uma página com duas caras. */
.content{font-family:Inter,system-ui,sans-serif;font-size:15px;line-height:1.5}
#app{max-width:1180px;margin:0 auto;padding:0 0 40px}
/* A BUSCA MORA NO HERO. A página tinha barra própria — voltar, marca e busca
   — porque era tela solta de /games. Com o menu lateral, voltar e marca
   viraram repetição do que já está na esquerda; sobrou a busca. */
.busca{position:relative;display:flex;align-items:center;width:min(340px,100%);
  flex-shrink:0}
.busca > .bi{position:absolute;left:11px;color:var(--txt3);font-size:13px;pointer-events:none}
.busca input{width:100%;padding:9px 12px 9px 33px;border-radius:11px;border:1px solid var(--borda);
  background:var(--panel);color:var(--txt);font-size:13.5px}
.busca input:focus{outline:none;border-color:var(--acento)}

/* ── A BARRA DAS MÍDIAS ─────────────────────────────────────────────
   Ela é o que diz que o Clube é maior que séries. As desligadas ficam
   visíveis e apagadas: promessa em cinza, não em link que abre nada. */
/* CENTRALIZADAS, mas com SAFE: as duas barras rolam de lado quando não cabem,
   e centro puro num container que rola empurra o começo pra fora do alcance
   — no celular a primeira aba ficaria inacessível. `safe center` centraliza
   quando sobra espaço e volta pro começo quando falta. */
.midias{display:flex;justify-content:safe center;gap:6px;margin-bottom:14px;
  overflow-x:auto;scrollbar-width:none;padding-bottom:2px}
.midias::-webkit-scrollbar{display:none}
.midias a,.midias span{display:flex;align-items:center;gap:6px;padding:8px 13px;border-radius:11px;
  font-size:13px;font-weight:700;text-decoration:none;white-space:nowrap;
  border:1px solid var(--borda);background:var(--panel);color:var(--txt2)}
.midias a:hover{border-color:var(--borda2);color:var(--txt)}
.midias a.on{border-color:var(--acento);background:color-mix(in srgb,var(--acento) 14%,transparent);color:var(--acento)}
.midias span{opacity:.4;cursor:default}
.midias span .breve{font-size:9.5px;font-weight:800;text-transform:uppercase;letter-spacing:.5px;
  padding:1px 5px;border-radius:5px;background:var(--panel3);color:var(--txt3)}

.abas{display:flex;justify-content:safe center;gap:4px;margin-bottom:16px;
  overflow-x:auto;scrollbar-width:none}
.abas::-webkit-scrollbar{display:none}
.abas a{padding:8px 13px;border-radius:10px;font-size:13px;font-weight:700;color:var(--txt2);
  text-decoration:none;white-space:nowrap}
.abas a.on{background:var(--panel2);color:var(--txt)}

/* ── A GRADE DE PÔSTERES ────────────────────────────────────────────
   O pôster é o que faz achar a série: ninguém lê trinta títulos, mas todo
   mundo reconhece a capa de longe. Por isso ele é o card inteiro, e o texto
   entra por baixo. */
.grade{display:grid;grid-template-columns:repeat(auto-fill,minmax(132px,1fr));gap:13px}
.card{background:none;border:0;padding:0;text-align:left;color:inherit;cursor:pointer;
  display:flex;flex-direction:column;gap:6px}
.capa{position:relative;aspect-ratio:2/3;border-radius:11px;overflow:hidden;
  background:var(--panel2);border:1px solid var(--borda)}
.capa img{width:100%;height:100%;object-fit:cover;display:block}
.capa .vazia{width:100%;height:100%;display:flex;align-items:center;justify-content:center;
  color:var(--txt3);font-size:26px}
.card:hover .capa{border-color:var(--acento)}
.selo{position:absolute;top:6px;left:6px;display:flex;align-items:center;gap:3px;
  padding:2px 6px;border-radius:7px;font-size:10.5px;font-weight:800;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.selo.imdb{color:var(--imdb)}
.marca-estado{position:absolute;top:6px;right:6px;width:23px;height:23px;border-radius:7px;
  display:flex;align-items:center;justify-content:center;font-size:12px;
  background:rgba(0,0,0,.72);backdrop-filter:blur(3px)}
.marca-estado.assistida{color:var(--fba)}
.marca-estado.assistindo{color:var(--acento)}
.marca-estado.quero{color:var(--txt2)}
.minha-nota{position:absolute;bottom:6px;right:6px;padding:2px 7px;border-radius:7px;
  font-size:11.5px;font-weight:900;background:var(--acento);color:#fff}
.c-tit{font-size:12.5px;font-weight:700;line-height:1.25;overflow:hidden;
  display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.c-ano{font-size:11px;color:var(--txt3);font-variant-numeric:tabular-nums}

.bloco{background:var(--panel);border:1px solid var(--borda);border-radius:14px;
  padding:15px;margin-bottom:13px}
.bloco h3{margin:0 0 12px;font-family:var(--display);font-size:17px;font-weight:700;
  letter-spacing:.3px;display:flex;align-items:center;gap:8px;text-transform:uppercase}
.bloco h3 i{color:var(--acento)}
.bloco h3 .conta{margin-left:auto;font-family:Inter;font-size:12px;color:var(--txt3);
  text-transform:none;letter-spacing:0;font-weight:600}
.vazio{color:var(--txt3);font-size:13px;padding:14px 0;text-align:center}

.fichas{display:grid;grid-template-columns:repeat(4,1fr);gap:12px}
.ficha{text-align:center}
.ficha .v{font-family:var(--display);font-size:28px;font-weight:700;line-height:1}
.ficha .r{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:3px}

/* ── O POPUP DO JOGO, nunca o do navegador ──────────────────────────
   Vale pra tudo: a ficha da série, a confirmação e o recado de erro. */
/* ACIMA DO MENU LATERAL (z-index 300) e da topbar. Com o 60 de antes, a
   ficha abria POR BAIXO da barra da esquerda no desktop. */
.fundo{position:fixed;inset:0;background:rgba(0,0,0,.72);backdrop-filter:blur(4px);z-index:400;
  display:flex;align-items:center;justify-content:center;padding:16px}
.fundo[hidden]{display:none}
.pop{width:100%;max-width:640px;max-height:88vh;overflow-y:auto;background:var(--panel);
  border:1px solid var(--borda2);border-radius:16px;padding:16px;animation:sobe .18s ease}
@keyframes sobe{from{opacity:0;transform:translateY(10px) scale(.98)}to{opacity:1;transform:none}}
.pop-topo{display:flex;gap:14px;margin-bottom:14px}
.pop-capa{width:122px;flex-shrink:0;border-radius:11px;overflow:hidden;background:var(--panel2);
  border:1px solid var(--borda);aspect-ratio:2/3}
.pop-capa img{width:100%;height:100%;object-fit:cover;display:block}
.pop-tit{font-family:var(--display);font-size:25px;font-weight:700;line-height:1.1;letter-spacing:.2px}
.pop-sub{font-size:12px;color:var(--txt3);margin-top:3px}
.pop-gen{font-size:11.5px;color:var(--txt2);margin-top:7px}
.pop-sin{font-size:13px;color:var(--txt2);line-height:1.5;margin:12px 0}

.notas{display:flex;gap:8px;margin-top:11px;flex-wrap:wrap}
.nota-caixa{flex:1;min-width:80px;padding:8px 10px;border-radius:10px;background:var(--panel3);
  border:1px solid var(--borda);text-align:center}
.nota-caixa .n{font-family:var(--display);font-size:22px;font-weight:700;line-height:1}
.nota-caixa .r{font-size:9.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;margin-top:2px}
.nota-caixa.imdb .n{color:var(--imdb-txt)}
.nota-caixa.fba .n{color:var(--fba-txt)}
.nota-caixa.eu .n{color:var(--acento)}

.rot{font-size:10.5px;color:var(--txt3);text-transform:uppercase;letter-spacing:.5px;
  font-weight:700;margin:14px 0 6px;display:flex;align-items:center;gap:8px}
.rot .conta{margin-left:auto;font-weight:600;letter-spacing:0;text-transform:none;font-size:11px}
.estados{display:flex;gap:6px;flex-wrap:wrap}
.est{flex:1;min-width:92px;padding:9px 8px;border-radius:10px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12px;font-weight:700;cursor:pointer;
  display:flex;align-items:center;justify-content:center;gap:5px}
.est:hover{border-color:var(--borda2);color:var(--txt)}
.est.on{border-color:var(--acento);background:color-mix(in srgb,var(--acento) 14%,transparent);color:var(--acento)}
.notinhas{display:flex;gap:4px;flex-wrap:wrap}
.notinha{width:34px;height:34px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt2);font-size:12.5px;font-weight:800;cursor:pointer}
.notinha:hover{border-color:var(--borda2);color:var(--txt)}
.notinha.on{background:var(--acento);border-color:var(--acento);color:#fff}
.notinha:disabled{opacity:.35;cursor:not-allowed}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:7px;padding:9px 14px;
  border-radius:11px;border:1px solid var(--borda2);background:var(--panel3);color:var(--txt);
  font-size:13px;font-weight:700;cursor:pointer;text-decoration:none}
.btn.pri{background:var(--acento);border-color:var(--acento);color:#fff}
.btn.fav.on{background:var(--imdb);border-color:var(--imdb);color:#111}
.btn:disabled{opacity:.45;cursor:not-allowed}
.pop-acoes{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;flex-wrap:wrap}
/* #ef4444 e nao um vermelho claro: o recado de erro tambem aparece no tema
   claro, onde rosa-palido em fundo branco nao se le. */
.recado{font-size:12.5px;color:#ef4444;font-weight:600;margin-top:9px;min-height:17px}

/* ── O RECADO DE UMA LINHA ──────────────────────────────────────────
   Curto de propósito: o lugar dele é embaixo do pôster, ao lado de outros
   vinte. Resenha de três parágrafos ninguém leria. */
/* LARGURA CHEIA, e não `flex:1`. O campo era um flex item dentro de uma div
   que NÃO era flex container: o flex:1 não valia nada e o <textarea> caía no
   tamanho padrão dele (20 colunas, ~170px), espremido ao lado do botão. */
.caixa-recado textarea{display:block;width:100%;min-height:62px;padding:10px 12px;
  border-radius:11px;border:1px solid var(--borda);background:var(--panel3);color:var(--txt);
  font:13px/1.45 Inter,system-ui,sans-serif;
  /* Cresce sozinho conforme a pessoa escreve (o JS ajusta a altura), então
     não precisa da alça de arrastar nem de barra de rolagem — as duas
     apareciam num campo de duas linhas e davam cara de formulário quebrado. */
  resize:none;overflow:hidden}
.caixa-recado textarea:focus{outline:none;border-color:var(--acento)}
.caixa-recado textarea:disabled{opacity:.4}
.pe-recado{display:flex;align-items:center;justify-content:space-between;gap:10px;
  margin-top:5px;font-size:10.5px;color:var(--txt3);min-height:15px}
.sobra{font-variant-numeric:tabular-nums}
.situacao{display:inline-flex;align-items:center;gap:5px}
.situacao.ok{color:var(--fba-txt)}
.situacao.ruim{color:#ef4444;font-weight:600}

/* ── O QUE A LIGA DIZ ───────────────────────────────────────────────── */
.diz{display:flex;flex-direction:column;gap:9px}
.diz-um{display:flex;gap:9px;align-items:flex-start;padding:9px 10px;border-radius:11px;
  background:var(--panel3);border:1px solid var(--borda)}
.av{width:28px;height:28px;border-radius:50%;object-fit:cover;flex-shrink:0;background:var(--panel2)}
.av.g{width:52px;height:52px}
.diz-cab{display:flex;align-items:center;gap:7px;flex-wrap:wrap;font-size:12.5px}
.diz-cab b{font-weight:700}
.diz-cab .oq{color:var(--txt3);font-size:11.5px}
.pino{padding:1px 6px;border-radius:5px;font-size:9.5px;font-weight:800;letter-spacing:.4px;
  background:var(--panel);border:1px solid var(--borda);color:var(--txt3);text-transform:uppercase}
.nota-pino{padding:1px 7px;border-radius:6px;font-size:11.5px;font-weight:900;
  background:var(--acento);color:#fff;font-variant-numeric:tabular-nums}
.diz-txt{font-size:13px;color:var(--txt2);line-height:1.45;margin-top:4px}
.diz-eu{border-color:var(--acento-2);background:color-mix(in srgb,var(--acento) 9%,transparent)}

/* ── O TOP DO PERFIL ────────────────────────────────────────────────── */
/* O TOP TEM DEZ e a grade não pode fixar cinco colunas: numa tela estreita
   elas espremem o pôster até ele não valer mais como capa. Auto-fill põe
   quantas couberem, que é o mesmo comportamento da grade do catálogo. */
.top-lista{display:grid;grid-template-columns:repeat(auto-fill,minmax(112px,1fr));gap:10px}
.top-lista .pos{position:absolute;bottom:6px;left:6px;width:21px;height:21px;border-radius:6px;
  background:var(--imdb);color:#111;font-size:11px;font-weight:900;
  display:flex;align-items:center;justify-content:center}

.linha-liga{display:flex;align-items:flex-start;gap:10px;padding:9px 0;
  border-bottom:1px solid var(--borda);font-size:13px}
.linha-liga:last-child{border-bottom:0}
.linha-liga > img.capinha{width:32px;height:48px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2);cursor:pointer}
.linha-liga .corpo{flex:1;min-width:0}
.linha-liga .n{font-weight:900;color:var(--acento);font-variant-numeric:tabular-nums;flex-shrink:0}

/* ── OS TRÊS RANKINGS ───────────────────────────────────────────────
   Lado a lado porque a pergunta é comparativa: o que a liga gostou, o que a
   liga viu e o que a liga ainda quer ver dizem coisas diferentes juntos. */
.tri{display:grid;grid-template-columns:repeat(auto-fit,minmax(272px,1fr));gap:13px;
  margin-bottom:13px}
.tri .bloco{margin-bottom:0}
.rk{display:flex;align-items:center;gap:9px;padding:6px 0;border-bottom:1px solid var(--borda);
  cursor:pointer;background:none;border-left:0;border-right:0;border-top:0;width:100%;
  text-align:left;color:inherit;font:inherit}
.rk:last-child{border-bottom:0}
.rk:hover .rk-t{color:var(--acento)}
.rk .rk-p{width:19px;font-family:var(--display);font-size:17px;font-weight:700;color:var(--txt3);
  text-align:center;flex-shrink:0}
.rk img{width:27px;height:40px;object-fit:cover;border-radius:5px;flex-shrink:0;background:var(--panel2)}
.rk .rk-t{flex:1;min-width:0;font-size:12.5px;font-weight:600;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.rk .rk-v{font-family:var(--display);font-size:18px;font-weight:700;flex-shrink:0;
  font-variant-numeric:tabular-nums}
.rk .rk-v small{font-family:Inter;font-size:9.5px;font-weight:700;color:var(--txt3);
  text-transform:uppercase;letter-spacing:.4px;margin-left:2px}
.rk.nota .rk-v{color:var(--fba-txt)}
.rk.vistas .rk-v{color:var(--acento)}
.rk.querem .rk-v{color:var(--imdb-txt)}

/* ── A LISTA DE TUDO QUE A LIGA VIU ─────────────────────────────────── */
.ordena{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:11px}
.ordena a{padding:5px 10px;border-radius:8px;font-size:11.5px;font-weight:700;
  text-decoration:none;color:var(--txt3);border:1px solid var(--borda);background:var(--panel3)}
.ordena a.on{color:var(--acento);border-color:var(--acento);background:color-mix(in srgb,var(--acento) 12%,transparent)}
.linha-serie{display:flex;align-items:center;gap:11px;padding:9px 0;width:100%;
  border:0;border-bottom:1px solid var(--borda);background:none;color:inherit;
  text-align:left;font:inherit;cursor:pointer}
.linha-serie:last-child{border-bottom:0}
.linha-serie img{width:34px;height:51px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2)}
.linha-serie .ls-c{flex:1;min-width:0}
/* PRECISA SER BLOCO: é um <span>, e em elemento inline o overflow:hidden
   não vale — o título comprido furava a linha e dava rolagem lateral na
   página inteira no celular. */
.linha-serie .ls-t{display:block;font-size:13.5px;font-weight:700;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.linha-serie:hover .ls-t{color:var(--acento)}
.linha-serie .ls-s{font-size:11.5px;color:var(--txt3);display:flex;gap:9px;flex-wrap:wrap;margin-top:2px}
.linha-serie .ls-s i{font-size:10px}
.linha-serie .ls-n{font-family:var(--display);font-size:22px;font-weight:700;color:var(--fba-txt);
  flex-shrink:0;font-variant-numeric:tabular-nums;min-width:34px;text-align:right}

/* ── AS PESSOAS DO CLUBE ────────────────────────────────────────────── */
.gente{display:grid;grid-template-columns:repeat(auto-fill,minmax(230px,1fr));gap:11px}
.gente a{display:flex;gap:11px;align-items:center;padding:11px;border-radius:12px;
  border:1px solid var(--borda);background:var(--panel3);text-decoration:none}
.gente a:hover{border-color:var(--acento)}
.gente .nm{font-size:13.5px;font-weight:700;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.gente .sb{font-size:11.5px;color:var(--txt3);margin-top:2px;font-variant-numeric:tabular-nums}
.procura-nome{display:flex;gap:8px;margin-bottom:13px}
.procura-nome input{flex:1;min-width:0;padding:9px 12px;border-radius:11px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt);font-size:13.5px}
.procura-nome input:focus{outline:none;border-color:var(--acento)}

.cab-pessoa{display:flex;align-items:center;gap:13px;margin-bottom:14px}
.cab-pessoa .nm{font-family:var(--display);font-size:26px;font-weight:700;line-height:1.05}
.cab-pessoa .sb{font-size:12px;color:var(--txt3);margin-top:2px}

/* ── A PROMESSA DAS OUTRAS MÍDIAS ───────────────────────────────────── */
.breve-caixa{text-align:center;padding:34px 16px}
/* FILHO DIRETO: sem o ">", este tamanho pegava também o ícone de dentro
   do botão e inchava o botão inteiro. */
.breve-caixa > i{font-size:38px;color:var(--acento);opacity:.55}
.breve-caixa h2{font-family:var(--display);font-size:25px;font-weight:700;margin:12px 0 6px;
  letter-spacing:.3px}
.breve-caixa p{color:var(--txt2);font-size:13.5px;margin:0 auto;max-width:430px;line-height:1.55}

/* ── O ACERVO DO PERFIL ─────────────────────────────────────────────
   Lista e não grade: aqui a pergunta é "qual eu dei 10?" e "tira essa", e as
   duas se respondem lendo uma coluna de cima a baixo. Pôster vira miniatura,
   porque quem chegou até aqui já sabe o que marcou. */
.filtros{display:flex;gap:5px;flex-wrap:wrap;margin-bottom:10px}
.filtros a{display:flex;align-items:center;gap:6px;padding:7px 12px;border-radius:10px;
  font-size:12.5px;font-weight:700;text-decoration:none;color:var(--txt2);
  border:1px solid var(--borda);background:var(--panel3)}
.filtros a:hover{border-color:var(--borda2);color:var(--txt)}
.filtros a.on{border-color:var(--acento);background:color-mix(in srgb,var(--acento) 14%,transparent);color:var(--acento)}
.filtros .qt{font-size:10.5px;font-weight:800;padding:0 5px;border-radius:5px;
  background:rgba(0,0,0,.3);color:var(--txt3);font-variant-numeric:tabular-nums}
.filtros a.on .qt{color:var(--acento)}

.acervo{display:flex;flex-direction:column}
.ac-um{display:flex;align-items:center;gap:11px;padding:9px 0;
  border-bottom:1px solid var(--borda)}
.ac-um:last-child{border-bottom:0}
.ac-um.saindo{opacity:.25;transition:opacity .18s}
.ac-um > img{width:34px;height:51px;object-fit:cover;border-radius:6px;flex-shrink:0;
  background:var(--panel2);cursor:pointer}
.ac-c{flex:1;min-width:0}
.ac-t{font-size:13.5px;font-weight:700;cursor:pointer;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap}
.ac-t:hover{color:var(--acento)}
.ac-t span{color:var(--txt3);font-weight:600;font-size:11.5px}
.ac-s{font-size:11.5px;color:var(--txt3);display:flex;gap:8px;flex-wrap:wrap;
  align-items:center;margin-top:3px}
.ac-s .pino.assistida{color:var(--fba-txt);border-color:rgba(34,197,94,.35)}
.ac-s .pino.assistindo{color:var(--acento);border-color:color-mix(in srgb,var(--acento) 40%,transparent)}
.ac-s .pino.top{color:var(--imdb-txt);border-color:rgba(245,197,24,.4)}
.ac-r{color:var(--txt2);font-style:italic;overflow:hidden;text-overflow:ellipsis;
  white-space:nowrap;max-width:100%}
.ac-n{flex-shrink:0;width:58px;padding:7px 6px;border-radius:9px;border:1px solid var(--borda);
  background:var(--panel3);color:var(--txt);font:700 13px Inter,system-ui,sans-serif;
  text-align:center;cursor:pointer}
.ac-n:focus{outline:none;border-color:var(--acento)}
.ac-n:disabled{opacity:.35;cursor:not-allowed}
.ac-n.deu{border-color:var(--fba-txt);color:var(--fba-txt)}
/* Os dois botões da ponta têm o mesmo tamanho de propósito: são um par de
   ações da linha, e um maior que o outro pediria pra ser clicado primeiro. */
.ac-top,.ac-x{flex-shrink:0;width:32px;height:32px;border-radius:9px;
  border:1px solid var(--borda);background:var(--panel3);color:var(--txt3);cursor:pointer;
  display:flex;align-items:center;justify-content:center;font-size:13px}
.ac-x:hover{border-color:#ef4444;color:#ef4444;background:rgba(239,68,68,.1)}
.ac-top:hover{border-color:var(--imdb);color:var(--imdb)}
/* DOURADA QUANDO ESTÁ NO TOP — a mesma cor da posição no perfil e do botão
   na ficha, pra ser a mesma coisa nos três lugares. */
.ac-top.on{border-color:var(--imdb);background:var(--imdb);color:#111}
.ac-top:disabled{opacity:.3;cursor:not-allowed}
.ac-top:disabled:hover{border-color:var(--borda);color:var(--txt3)}

/* O POPUP DE CONFIRMAR é o do jogo, nunca o confirm() do navegador. */
.pop.pergunta{max-width:400px}
.pop.pergunta h4{font-family:var(--display);font-size:21px;font-weight:700;margin:0 0 8px;
  letter-spacing:.2px}
.pop.pergunta p{color:var(--txt2);font-size:13.5px;margin:0;line-height:1.5}
.btn.perigo{background:#ef4444;border-color:#ef4444;color:#fff}

@media (max-width:992px){
  /* No celular o hero empilha, e a busca passa a ocupar a linha inteira —
     meia largura ao lado de um título que quebrou em duas linhas era um
     campo estreito demais pra digitar nome de série. */
  .busca{width:100%}
}
@media (max-width:560px){
  .fichas{grid-template-columns:repeat(2,1fr);row-gap:14px}
  .grade{grid-template-columns:repeat(auto-fill,minmax(104px,1fr));gap:10px}
  .pop-topo{flex-direction:column}
  .pop-capa{width:100%;max-width:170px;margin:0 auto}
  .cab-pessoa .nm{font-size:22px}
  /* No celular o recado empurraria a nota e o X pra fora da linha. Ele
     continua na ficha, que é onde se escreve. */
  .ac-r{display:none}
  .ac-um{gap:9px}
  /* TÍTULO EM DUAS LINHAS no lugar do "…". Numa linha só, a reticência
     comia sempre o ano — que vem depois do título — e às vezes metade do
     nome. Duas linhas cabem de sobra e não empurram nada. */
  .ac-t,.linha-serie .ls-t{white-space:normal;display:-webkit-box;
    -webkit-line-clamp:2;-webkit-box-orient:vertical}
}
/* ── DA SEMANA E CLUBE DO LIVRO ──────────────────────────────────────
   Vocabulário próprio e pequeno; o resto (bloco, abas, btn, vazio) é o da
   página. Os cartões dividem a linha no desktop e empilham no celular. */
.sem-cols{display:grid;grid-template-columns:repeat(auto-fit,minmax(min(100%,340px),1fr));gap:14px}
.cartaz-rot{font-size:11px;letter-spacing:.8px;text-transform:uppercase;color:var(--txt3);margin-bottom:6px}
.cartaz-tit{font-size:22px;font-weight:800;line-height:1.15}
.cartaz-sub{color:var(--txt2);font-size:13.5px;margin-top:3px}
.cartaz-med{display:inline-flex;align-items:center;gap:6px;margin-top:10px;font-size:12.5px;color:var(--txt2)}
.cartaz-med b{font-size:16px;color:var(--txt)}
.vops{display:flex;flex-direction:column;gap:7px;margin-top:10px}
.vops form{margin:0}
.vop{width:100%}
.vop{position:relative;display:flex;align-items:center;gap:10px;width:100%;text-align:left;
     background:var(--panel3);border:1px solid var(--borda);border-radius:10px;
     padding:9px 12px;cursor:pointer;color:var(--txt);font:inherit;overflow:hidden}
.vop:hover{border-color:var(--acento)}
.vop.on{border-color:var(--acento);box-shadow:0 0 0 1px var(--acento) inset}
.vop-bar{position:absolute;inset:0;width:var(--pct,0%);background:var(--acento);opacity:.12;pointer-events:none}
.vop-tit{position:relative;flex:1;min-width:0}
.vop-tit small{display:block;color:var(--txt3);font-size:11.5px}
.vop-n{position:relative;font-size:12px;color:var(--txt3);white-space:nowrap}
.vop.on .vop-n{color:var(--acento);font-weight:700}
.opina{display:flex;flex-direction:column;gap:8px;margin-top:12px}
.opina textarea{background:var(--panel3);border:1px solid var(--borda);border-radius:10px;
     color:var(--txt);padding:9px 11px;font:inherit;font-size:13px;resize:vertical;min-height:64px}
.opina-l{display:flex;gap:8px;align-items:center;flex-wrap:wrap}
.opina select{background:var(--panel3);border:1px solid var(--borda);border-radius:8px;
     color:var(--txt);padding:7px 9px;font:inherit;font-size:13px}
.opi{display:flex;flex-direction:column;gap:10px;margin-top:12px}
.opi-um{display:flex;gap:10px;align-items:flex-start}
.opi-um img{width:30px;height:30px;border-radius:50%;object-fit:cover;flex:none}
.opi-cab{font-size:12.5px;color:var(--txt2)}
.opi-cab b{color:var(--txt)}
.opi-nota{display:inline-block;background:var(--panel3);border:1px solid var(--borda);
     border-radius:7px;padding:1px 7px;font-size:11.5px;font-weight:700;margin-left:6px}
.opi-txt{font-size:13.5px;color:var(--txt);margin-top:2px;white-space:pre-wrap;word-break:break-word}
.sem-h4{margin:16px 0 4px;font-size:13px;letter-spacing:.4px;text-transform:uppercase;color:var(--txt3)}
/* O ARQUIVO DO LIVRO. Os dois idiomas lado a lado, porque a pergunta de
   quem chega e "tem em portugues?" — e com as duas vagas a tela responde
   isso antes de a pessoa ler nome de arquivo. */
.livro-arqs{display:flex;gap:8px;flex-wrap:wrap;margin:12px 0 0}
.livro-arq{display:inline-flex;align-items:center;gap:7px;text-decoration:none;
  background:var(--panel-2);border:1px solid var(--border);border-radius:9px;
  padding:8px 12px;color:var(--text);font-size:13px;font-weight:600}
.livro-arq:hover{border-color:var(--border-md)}
.livro-arq small{color:var(--txt3);font-weight:400;font-size:11.5px;
  max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.livro-up{margin-top:14px;padding-top:14px;border-top:1px solid var(--border)}
.livro-up-l{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:10px}
.livro-up-c{flex:1;min-width:180px;display:flex;flex-direction:column;gap:5px;
  font-size:12.5px;color:var(--text-2)}
.livro-up-c input[type=file]{font-size:12px;color:var(--txt3)}
.livro-up-c b{color:var(--verde,#22c55e);font-weight:600}
.livro-up-nota{margin:8px 0 0;font-size:11.5px;color:var(--txt3)}
.livro-arq-tirar{display:inline-block;margin:6px 8px 0 0}
.livro-arq-tirar button{background:none;border:none;color:var(--txt3);
  font-size:11.5px;text-decoration:underline;cursor:pointer;padding:0;font-family:inherit}
.livro-abrir textarea{width:100%;background:var(--panel-2);border:1px solid var(--border);
  border-radius:9px;color:var(--text);padding:10px 12px;font:inherit;font-size:13px;
  resize:vertical;margin-bottom:10px}
.livro-abrir-l{display:flex;gap:10px;align-items:center;flex-wrap:wrap}
.livro-abrir-l label{font-size:12.5px;color:var(--text-2);display:flex;gap:6px;align-items:center}
.livro-abrir-l select{background:var(--panel-2);border:1px solid var(--border);
  border-radius:8px;color:var(--text);padding:7px 10px;font:inherit;font-size:12.5px}

.sem-prazo{font-size:12px;color:var(--txt3);margin-bottom:4px}
.convite{display:flex;gap:14px;align-items:center;flex-wrap:wrap;justify-content:space-between}
.convite p{margin:0;color:var(--txt2);font-size:13.5px}
.estante{display:flex;flex-direction:column;gap:7px}
.estante-um{display:flex;justify-content:space-between;gap:10px;font-size:13.5px;
     border-bottom:1px dashed var(--borda);padding-bottom:7px}
.estante-um small{color:var(--txt3)}
.gente-livro{display:flex;flex-wrap:wrap;gap:8px}
.gente-livro span{display:inline-flex;align-items:center;gap:6px;background:var(--panel3);
     border:1px solid var(--borda);border-radius:999px;padding:4px 11px 4px 5px;font-size:12.5px}
.gente-livro img{width:22px;height:22px;border-radius:50%;object-fit:cover}
.adm-form{display:flex;flex-direction:column;gap:8px}
.adm-form input[type=text],.adm-form textarea{background:var(--panel3);border:1px solid var(--borda);
     border-radius:10px;color:var(--txt);padding:9px 11px;font:inherit;font-size:13px}
.sair-livro{margin-top:18px;text-align:right}
.sair-livro button{background:none;border:none;color:var(--txt3);font-size:12px;cursor:pointer;
     text-decoration:underline;padding:0}
<?php include __DIR__ . '/includes/accent-color.php'; ?>
</style>
</head>
<body>

<?php include __DIR__ . '/includes/sidebar.php'; ?>
<div class="sb-overlay" id="sbOverlay"></div>

<header class="topbar">
  <button class="menu-btn" id="menuBtn"><i class="bi bi-list"></i></button>
  <div class="topbar-title">FBA <em>Clube</em></div>
</header>

<main class="main">
  <div class="dash-hero">
    <div>
      <div class="dash-eyebrow">Clube FBA</div>
      <h1 class="dash-title"><?= h(CLUBE_MIDIAS[$midia]['rot']) ?></h1>
      <p class="dash-sub">O que a liga anda vendo, lendo e ouvindo.</p>
    </div>
    <?php if ($midia === 'series' && $catalogo > 0): ?>
      <?php /* A BUSCA FICA NO CABEÇALHO, ao lado do título: é a primeira
           coisa que se faz num catálogo de mil e seiscentas séries. */ ?>
      <div class="busca">
        <i class="bi bi-search"></i>
        <input type="search" id="busca" autocomplete="off" spellcheck="false"
               value="<?= h($qInicial) ?>"
               placeholder="Buscar série" aria-label="Buscar série">
      </div>
    <?php endif; ?>
  </div>

<div class="content">
<div id="app">
  <?php /* A BARRA DAS MÍDIAS VEM ANTES DE TUDO, inclusive antes do aviso de
       login: ela é o que explica o que esta página é. */ ?>
  <div class="midias">
    <?php foreach (CLUBE_MIDIAS as $k => $m): ?>
      <?php /* A aba do livro é de quem pediu: pra quem não entrou, ela nem
           existe — o convite mora na aba Da Semana. */ ?>
      <?php if ($k === 'livro' && !$souDoLivro && !$ehAdminClube) continue; ?>
      <?php if ($m['ok']): ?>
        <a href="?midia=<?= $k ?>" class="<?= $midia === $k ? 'on' : '' ?>">
          <i class="bi bi-<?= $m['ico'] ?>"></i> <?= h($m['rot']) ?></a>
      <?php else: ?>
        <span><i class="bi bi-<?= $m['ico'] ?>"></i> <?= h($m['rot']) ?>
          <span class="breve">em breve</span></span>
      <?php endif; ?>
    <?php endforeach; ?>
  </div>

<?php if ($midia === 'musica' || $midia === 'filmes'): ?>
  <?php /* ── MÚSICA E FILMES: o da semana de cada uma ─────────────────── */ ?>
  <?php clubeBlocoSemana($pdo, $midia === 'musica' ? 'album' : 'filme', $idUsuario); ?>

  <?php if (!$souDoLivro): ?>
    <?php /* O convite do livro mora aqui: a aba dele só existe pra quem aceitou. */ ?>
    <div class="bloco convite" style="margin-top:14px">
      <p><b><i class="bi bi-book-half"></i> Clube do Livro</b> — um livro por mês, escolhido
        na primeira sexta (gênero de manhã, livros à tarde), timeline de notas. A aba
        só aparece pra quem entra.</p>
      <form method="POST" style="margin:0">
        <input type="hidden" name="acao" value="livro_entrar">
        <button type="submit" class="btn pri"><i class="bi bi-plus-lg"></i> Quero participar</button>
      </form>
    </div>
  <?php endif; ?>

<?php elseif ($midia === 'livro'): ?>
  <?php if (!$souDoLivro && !$ehAdminClube): ?>
    <?php /* Chegou pela URL sem ter entrado: o convite vale aqui também. */ ?>
    <div class="bloco">
      <div class="breve-caixa">
        <i class="bi bi-book-half"></i>
        <h2>Clube do Livro</h2>
        <p>Um livro por mês, escolhido na primeira sexta: o gênero de manhã e os
           livros dele à tarde. Timeline de notas, ranking, e a aba passa a ser
           sua quando você entra.</p>
        <form method="POST" style="margin-top:14px">
          <input type="hidden" name="acao" value="livro_entrar">
          <button type="submit" class="btn pri"><i class="bi bi-plus-lg"></i> Quero participar</button>
        </form>
      </div>
    </div>
  <?php else:
    $enquetesLivro = clubeLivroEnquetes($pdo, $idUsuario);
    $membrosLivro = clubeLivroMembros($pdo); ?>

    <?php clubeBlocoSemana($pdo, 'livro', $idUsuario); ?>

    <?php foreach ($enquetesLivro as $e): ?>
      <div class="bloco" style="margin-top:14px">
        <h3 style="margin:0 0 4px"><i class="bi bi-ui-checks"></i> <?= h($e['pergunta']) ?></h3>
        <div class="sem-prazo">
          <?= $e['status'] === 'aberta' ? 'aberta' : 'encerrada' ?>
          · <?= (int)$e['pessoas'] ?> pessoa<?= (int)$e['pessoas'] === 1 ? '' : 's' ?> votando<?=
            $e['multi'] ? ' · marque quantos quiser' : '' ?></div>
        <div class="vops">
          <?php $tot = max(1, (int)$e['total']);
          foreach ($e['opcoes'] as $o): $pct = round(100 * $o['votos'] / $tot); ?>
            <?php if ($e['status'] === 'aberta'): ?>
              <form method="POST">
                <input type="hidden" name="acao" value="livro_votar">
                <input type="hidden" name="enquete" value="<?= (int)$e['id'] ?>">
                <input type="hidden" name="opcao" value="<?= (int)$o['id'] ?>">
                <button type="submit" class="vop <?= in_array((int)$o['id'], $e['meus'], true) ? 'on' : '' ?>"
                        style="--pct:<?= $pct ?>%">
                  <i class="vop-bar"></i>
                  <span class="vop-tit"><?= h($o['texto']) ?></span>
                  <span class="vop-n"><?= (int)$o['votos'] ?></span>
                </button>
              </form>
            <?php else: ?>
              <div class="vop" style="--pct:<?= $pct ?>%;cursor:default">
                <i class="vop-bar"></i>
                <span class="vop-tit"><?= h($o['texto']) ?></span>
                <span class="vop-n"><?= (int)$o['votos'] ?></span>
              </div>
            <?php endif; ?>
          <?php endforeach; ?>
        </div>
        <?php if ($ehAdminClube && $e['status'] === 'aberta'): ?>
          <form method="POST" style="margin-top:10px;text-align:right">
            <input type="hidden" name="acao" value="livro_enquete_fechar">
            <input type="hidden" name="enquete" value="<?= (int)$e['id'] ?>">
            <button type="submit" class="btn">Encerrar enquete</button>
          </form>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

    <div class="bloco" style="margin-top:14px">
      <h3 style="margin:0 0 10px"><i class="bi bi-people-fill"></i> Quem está no clube
        <span style="color:var(--txt3);font-weight:400;font-size:13px">· <?= count($membrosLivro) ?></span></h3>
      <div class="gente-livro">
        <?php foreach ($membrosLivro as $m): ?>
          <span><img src="<?= h(clubeAvatar($m['photo_url'])) ?>" alt=""><?= h($m['name']) ?></span>
        <?php endforeach; ?>
        <?php if (!$membrosLivro): ?>
          <span style="border:none;background:none;color:var(--txt3)">ninguém ainda — você pode ser quem abre a porta</span>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($ehAdminClube): ?>
      <div class="bloco" style="margin-top:14px">
        <h3 style="margin:0 0 10px"><i class="bi bi-sliders"></i> Direção do clube</h3>
        <form class="adm-form" method="POST" style="max-width:480px">
          <input type="hidden" name="acao" value="livro_enquete">
          <div class="sem-h4" style="margin-top:0">Nova enquete avulsa</div>
          <input type="text" name="pergunta" placeholder="Pergunta" required maxlength="200">
          <textarea name="opcoes" required placeholder="Uma opção por linha"></textarea>
          <label style="font-size:13px;color:var(--txt2)">
            <input type="checkbox" name="multi" value="1"> cada pessoa pode marcar várias</label>
          <div><button type="submit" class="btn pri">Criar enquete</button></div>
        </form>
      </div>
    <?php endif; ?>

    <?php if ($souDoLivro): ?>
      <div class="sair-livro">
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="livro_sair">
          <button type="submit">sair do clube do livro (notas e votos ficam; voltar recupera tudo)</button>
        </form>
      </div>
    <?php endif; ?>
  <?php endif; ?>

<?php elseif (!CLUBE_MIDIAS[$midia]['ok']): ?>
  <?php /* Só chega aqui quem digitou a mídia na URL: o link não existe. */ ?>
  <div class="bloco">
    <div class="breve-caixa">
      <i class="bi bi-<?= CLUBE_MIDIAS[$midia]['ico'] ?>"></i>
      <h2><?= h(CLUBE_MIDIAS[$midia]['rot']) ?> ainda não abriu</h2>
      <p>Esta aba está no plano do Clube, mas não tem tela ainda. Séries é o que
         está de pé — comece por lá.</p>
      <p style="margin-top:14px"><a class="btn pri" href="?midia=series">
        <i class="bi bi-projector-fill"></i> Ir pras séries</a></p>
    </div>
  </div>

<?php elseif ($catalogo === 0): ?>
  <?php /* CATÁLOGO VAZIO É ESTADO, NÃO ERRO. Quem abrir antes de a importação
       rodar merece saber o que falta em vez de uma grade em branco. */ ?>
  <div class="bloco">
    <h3><i class="bi bi-hourglass-split"></i> O catálogo ainda não chegou</h3>
    <p style="color:var(--txt2);margin:0 0 6px">
      As séries entram por uma importação que roda na mão, uma vez:
    </p>
    <pre style="background:var(--panel3);border:1px solid var(--borda);border-radius:10px;
                padding:11px;font-size:12px;overflow-x:auto;color:var(--txt2)">php games/core/series_importar_cli.php --chave=SUA_CHAVE_TMDB --gravar</pre>
    <p style="color:var(--txt3);font-size:12.5px;margin:0">
      A chave é gratuita e sai em dois minutos em themoviedb.org → Configurações → API.
    </p>
  </div>

<?php else: ?>
  <div class="abas">
    <?php foreach (['catalogo' => 'Catálogo', 'perfil' => 'Meu perfil',
                    'liga' => 'A liga', 'pessoas' => 'Pessoas'] as $k => $rot): ?>
      <a href="?midia=series&amp;aba=<?= $k ?>" class="<?= $aba === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
    <?php endforeach; ?>
  </div>

  <?php if ($aba === 'catalogo'): ?>
    <div class="bloco">
      <h3><i class="bi bi-collection-play-fill"></i> <span id="tituloGrade">Mais populares</span></h3>
      <div class="grade" id="grade"></div>
      <div class="vazio" id="gradeFora" hidden><i class="bi bi-arrow-repeat"></i>
        Procurando fora do catálogo…</div>
      <div class="vazio" id="gradeVazia" hidden>Nenhuma série com esse nome.</div>
    </div>

  <?php elseif ($aba === 'perfil'): ?>
    <?= diarioDe($pdo, $perfil, null) ?>

    <?php /* ── O ACERVO ──────────────────────────────────────────────────
         A tela de gerenciar, e não só de olhar. Três grades gigantes em ordem
         de "mexi por último" dava pra ver e não dava pra achar: aqui entra o
         filtro por categoria, a busca no que é meu, a ordem (inclusive por
         nota, que é a pergunta mais comum e a mais chata na grade de pôster),
         a nota editável na própria linha e o X. */ ?>
    <div class="bloco">
      <?php $acervo = seriesMeuAcervo($pdo, $idUsuario, $cat, $termoAcervo, $ordem); ?>
      <h3><i class="bi bi-collection-fill"></i> Meu acervo
        <span class="conta" id="acConta"><?= count($acervo) ?></span></h3>

      <div class="filtros">
        <?php
        $catContas = ['' => (int)$perfil['assistidas'] + (int)$perfil['assistindo'] + (int)$perfil['quero'],
                      'quero' => (int)$perfil['quero'], 'assistindo' => (int)$perfil['assistindo'],
                      'assistida' => (int)$perfil['assistidas']];
        $catRot = ['' => 'Todas'] + SERIES_ESTADOS;
        foreach ($catRot as $k => $rot):
            $liga = '?midia=series&aba=perfil' . ($k !== '' ? '&cat=' . $k : '')
                  . ($termoAcervo !== '' ? '&ac=' . urlencode($termoAcervo) : '')
                  . ($ordem !== 'movimento' ? '&ord=' . urlencode($ordem) : '');
        ?>
          <a href="<?= h($liga) ?>" class="<?= $cat === (string)$k ? 'on' : '' ?>">
            <?= h($rot) ?> <span class="qt"><?= (int)$catContas[$k] ?></span></a>
        <?php endforeach; ?>
      </div>

      <form class="procura-nome" method="get">
        <input type="hidden" name="midia" value="series">
        <input type="hidden" name="aba" value="perfil">
        <?php if ($cat !== ''): ?><input type="hidden" name="cat" value="<?= h($cat) ?>"><?php endif; ?>
        <?php if ($ordem !== 'movimento'): ?><input type="hidden" name="ord" value="<?= h($ordem) ?>"><?php endif; ?>
        <input type="search" name="ac" value="<?= h($termoAcervo) ?>"
               placeholder="Procurar nas minhas" aria-label="Procurar nas minhas séries"
               autocomplete="off">
        <button class="btn pri" type="submit"><i class="bi bi-search"></i></button>
        <?php if ($termoAcervo !== ''): ?>
          <a class="btn" href="?midia=series&amp;aba=perfil<?= $cat !== '' ? '&amp;cat=' . h($cat) : '' ?>"
             title="Limpar"><i class="bi bi-x-lg"></i></a>
        <?php endif; ?>
      </form>

      <div class="ordena">
        <?php
        $ordens = ['movimento' => 'Mais recentes', 'nota' => 'Maior nota', 'notaasc' => 'Menor nota',
                   'titulo' => 'A–Z', 'imdb' => 'IMDb'];
        /* A ordem do acervo e a da aba A liga dividem o mesmo `ord` na URL de
           propósito: são abas diferentes, o valor de uma nunca chega na outra,
           e um segundo nome de parâmetro só daria link mais comprido. */
        foreach ($ordens as $k => $rot):
            $liga = '?midia=series&aba=perfil' . ($cat !== '' ? '&cat=' . $cat : '')
                  . ($termoAcervo !== '' ? '&ac=' . urlencode($termoAcervo) : '')
                  . '&ord=' . $k;
        ?>
          <a href="<?= h($liga) ?>"
             class="<?= ($ordem === $k || ($k === 'movimento' && !isset($ordens[$ordem]))) ? 'on' : '' ?>">
            <?= h($rot) ?></a>
        <?php endforeach; ?>
      </div>

      <?php if (!$acervo): ?>
        <div class="vazio"><?= $termoAcervo !== ''
            ? 'Nenhuma série sua com esse nome.'
            : 'Nada nessa categoria ainda — marque no catálogo.' ?></div>
      <?php else: ?>
        <div class="acervo" id="acervo">
          <?php foreach ($acervo as $s): ?>
            <div class="ac-um" data-linha="<?= (int)$s['id'] ?>">
              <?php $u = seriesPoster($s['poster']); ?>
              <img src="<?= h($u ?: '') ?>" alt="" loading="lazy" data-serie="<?= (int)$s['id'] ?>">
              <div class="ac-c">
                <div class="ac-t" data-serie="<?= (int)$s['id'] ?>"><?= h($s['titulo']) ?>
                  <span><?= h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                                         $s['ano_fim'] ? (int)$s['ano_fim'] : null,
                                         !empty($s['em_exibicao']))) ?></span></div>
                <div class="ac-s">
                  <span class="pino <?= h($s['estado']) ?>"><?= h(SERIES_ESTADOS[$s['estado']] ?? '') ?></span>
                  <?php /* O pino existe SEMPRE, escondido quando ela não está no
                       top: pôr e tirar acontece sem recarregar a página, e o JS
                       precisa de um lugar fixo pra escrever a posição. */ ?>
                  <span class="pino top"<?= empty($s['favorita']) ? ' hidden' : '' ?>>
                    <i class="bi bi-star-fill"></i> <?= (int)$s['favorita'] ?>º</span>
                  <?php if ($s['nota_imdb'] !== null): ?>
                    <span style="color:var(--imdb-txt)"><i class="bi bi-star-fill"></i>
                      <?= h(number_format((float)$s['nota_imdb'], 1, ',', '')) ?></span>
                  <?php endif; ?>
                  <?php if (!empty($s['meu_recado'])): ?>
                    <span class="ac-r">“<?= h($s['meu_recado']) ?>”</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php /* A NOTA SE EDITA AQUI, sem abrir a ficha: quem entrou pra
                   arrumar as notas vai mexer em dez de uma vez, e dez popups
                   seriam dez esperas. Quem só pôs na fila não avalia — o campo
                   vem travado em vez de recusar depois do clique. */ ?>
              <select class="ac-n" data-nota-de="<?= (int)$s['id'] ?>"
                      aria-label="Sua nota para <?= h($s['titulo']) ?>"
                      <?= $s['estado'] === 'quero' ? 'disabled title="Marque como assistida pra dar nota"' : '' ?>>
                <option value=""<?= $s['minha_nota'] === null ? ' selected' : '' ?>>—</option>
                <?php for ($n = 10; $n >= 1; $n--): ?>
                  <option value="<?= $n ?>"<?= (int)$s['minha_nota'] === $n ? ' selected' : '' ?>><?= $n ?></option>
                <?php endfor; ?>
              </select>
              <?php /* A ESTRELA MORAVA SÓ NA FICHA. Pra montar o top era abrir
                   dez séries, uma a uma — e é justamente aqui, na lista das
                   assistidas ordenada por nota, que dá pra escolher as dez. */ ?>
              <button class="ac-top<?= !empty($s['favorita']) ? ' on' : '' ?>"
                      data-top="<?= (int)$s['id'] ?>"
                      <?= $s['estado'] !== 'assistida'
                          ? 'disabled title="Só série assistida entra no top"'
                          : 'title="' . (!empty($s['favorita']) ? 'Tirar do' : 'Pôr no')
                            . ' Top ' . SERIES_TOP . '"' ?>>
                <i class="bi bi-star<?= !empty($s['favorita']) ? '-fill' : '' ?>"></i></button>
              <button class="ac-x" data-tirar="<?= (int)$s['id'] ?>"
                      data-titulo="<?= h($s['titulo']) ?>"
                      title="Tirar do meu perfil" aria-label="Tirar do meu perfil">
                <i class="bi bi-x-lg"></i></button>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php elseif ($aba === 'pessoas'): ?>
    <?php if ($vendo > 0): ?>
      <?php /* O DIÁRIO DE OUTRA PESSOA é o mesmo do meu, e sai da mesma função:
           duas versões divergiriam no primeiro ajuste, e a de visita seria a
           que ninguém olha. */ ?>
      <div class="bloco">
        <div class="cab-pessoa">
          <img class="av g" src="<?= h(clubeAvatar($pessoa['photo_url'])) ?>" alt=""
               onerror="this.src='/img/default-avatar.png'">
          <div style="min-width:0">
            <div class="nm"><?= h($pessoa['nome']) ?></div>
            <div class="sb"><?= $pessoa['league'] ? h($pessoa['league']) . ' · ' : '' ?>
              <?= $vendo === $idUsuario ? 'o seu diário' : 'o diário no Clube' ?></div>
          </div>
          <a class="btn" href="?midia=series&amp;aba=pessoas" style="margin-left:auto">
            <i class="bi bi-arrow-left"></i> Pessoas</a>
        </div>
      </div>
      <?= diarioDe($pdo, seriesPerfil($pdo, $vendo), $vendo) ?>

    <?php else: ?>
      <div class="bloco">
        <h3><i class="bi bi-people-fill"></i> Quem está no Clube</h3>
        <?php /* A BUSCA DE PESSOA RECARREGA A PÁGINA, e é de propósito: nome de
             gente se digita inteiro e uma vez, não letra por letra como título
             de série — e o resultado é um link que dá pra mandar no grupo. */ ?>
        <form class="procura-nome" method="get">
          <input type="hidden" name="midia" value="series">
          <input type="hidden" name="aba" value="pessoas">
          <input type="search" name="nome" value="<?= h($termoPessoa) ?>"
                 placeholder="Procurar pelo nome" aria-label="Procurar pessoa pelo nome"
                 autocomplete="off">
          <button class="btn pri" type="submit"><i class="bi bi-search"></i> Procurar</button>
        </form>

        <?php $gente = seriesPessoas($pdo, $termoPessoa); ?>
        <?php if (!$gente): ?>
          <div class="vazio">
            <?= $termoPessoa !== ''
                ? 'Ninguém com esse nome marcou série ainda.'
                : 'Ninguém marcou nada ainda. Seja o primeiro.' ?>
          </div>
        <?php else: ?>
          <div class="gente">
            <?php foreach ($gente as $g): ?>
              <a href="?midia=series&amp;aba=pessoas&amp;u=<?= (int)$g['id'] ?>">
                <img class="av g" src="<?= h(clubeAvatar($g['photo_url'])) ?>" alt=""
                     onerror="this.src='/img/default-avatar.png'">
                <div style="min-width:0">
                  <div class="nm"><?= h($g['nome']) ?></div>
                  <div class="sb"><?= (int)$g['assistidas'] ?> assistidas
                    <?php if ($g['nota_media'] !== null): ?>
                      · média <?= h(number_format((float)$g['nota_media'], 1, ',', '')) ?>
                    <?php endif; ?>
                  </div>
                  <div class="sb"><?= (int)$g['assistindo'] ?> vendo · <?= (int)$g['quero'] ?> na fila</div>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

  <?php else: ?>
    <?php /* ── A LIGA ───────────────────────────────────────────────────
         Três rankings, o movimento e, embaixo, tudo que alguém daqui já viu.
         A ordem é a da pergunta: primeiro o que a liga achou, depois quem
         fez o quê, e por último a lista pra procurar uma série específica. */ ?>
    <div class="tri">
      <?php
      $rankings = [
          'nota'   => ['Mais bem avaliadas', 'star-fill',     'nota'],
          'vistas' => ['Mais assistidas',    'eye-fill',      'pessoas'],
          'querem' => ['Mais na fila',       'bookmark-fill', 'pessoas'],
      ];
      foreach ($rankings as $tipo => [$titulo, $ico, $unidade]):
          $lista = seriesRankingDaLiga($pdo, $tipo, 10);
      ?>
        <div class="bloco">
          <h3><i class="bi bi-<?= $ico ?>"></i> <?= h($titulo) ?></h3>
          <?php if (!$lista): ?>
            <div class="vazio"><?= $tipo === 'nota'
                ? 'Ainda não tem série com ' . SERIES_RANKING_MIN . ' notas da liga.'
                : 'Ninguém marcou nada ainda.' ?></div>
          <?php else: ?>
            <?php foreach ($lista as $i => $s): ?>
              <button class="rk <?= $tipo ?>" data-serie="<?= (int)$s['id'] ?>">
                <span class="rk-p"><?= $i + 1 ?></span>
                <?php $u = seriesPoster($s['poster']); ?>
                <img src="<?= h($u ?: '') ?>" alt="" loading="lazy">
                <span class="rk-t"><?= h($s['titulo']) ?></span>
                <span class="rk-v"><?= $tipo === 'nota'
                      ? h(number_format((float)$s['valor'], 1, ',', ''))
                      : (int)$s['valor'] ?><?php if ($tipo !== 'nota'): ?><small>
                      <?= (int)$s['valor'] === 1 ? 'pessoa' : 'pessoas' ?></small><?php endif; ?></span>
              </button>
            <?php endforeach; ?>
            <?php if ($tipo === 'nota'): ?>
              <div style="font-size:11px;color:var(--txt3);margin-top:9px">
                Entra com <?= SERIES_RANKING_MIN ?> notas ou mais — uma nota só não é média de ninguém.
              </div>
            <?php endif; ?>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-activity"></i> O que a liga andou fazendo</h3>
      <?php $mov = seriesMovimentoDaLiga($pdo, 25); ?>
      <?php if (!$mov): ?>
        <div class="vazio">Ninguém marcou nada ainda. Seja o primeiro.</div>
      <?php else: ?>
        <?php foreach ($mov as $m): ?>
          <div class="linha-liga">
            <?php $u = seriesPoster($m['poster']); ?>
            <img class="capinha" src="<?= h($u ?: '') ?>" alt="" loading="lazy"
                 data-serie="<?= (int)$m['id'] ?>">
            <div class="corpo">
              <div class="diz-cab">
                <img class="av" src="<?= h(clubeAvatar($m['photo_url'])) ?>" alt=""
                     onerror="this.src='/img/default-avatar.png'">
                <b><?= h($m['quem']) ?></b>
                <span class="oq"><?= h(CLUBE_VERBO[$m['estado']] ?? '') ?></span>
                <b style="color:var(--acento)"><?= h($m['titulo']) ?></b>
              </div>
              <?php if (!empty($m['comentario'])): ?>
                <div class="diz-txt">“<?= h($m['comentario']) ?>”</div>
              <?php endif; ?>
            </div>
            <?php if ($m['nota'] !== null): ?><span class="n"><?= (int)$m['nota'] ?></span><?php endif; ?>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

    <div class="bloco">
      <?php $tudo = seriesQueALigaTocou($pdo, $ordem, 120); ?>
      <h3><i class="bi bi-list-ul"></i> Tudo que a liga já viu
        <span class="conta"><?= count($tudo) ?></span></h3>
      <?php /* SÓ O QUE ALGUÉM DAQUI TOCOU. O catálogo tem mil e seiscentas e
           listá-las aqui seria repetir a aba Catálogo com outro nome — o que
           esta lista tem de próprio é o recorte da liga. */ ?>
      <div class="ordena">
        <?php foreach (['movimento' => 'Mais recentes', 'nota' => 'Nota da liga',
                        'pessoas' => 'Mais gente', 'titulo' => 'A–Z'] as $k => $rot): ?>
          <a href="?midia=series&amp;aba=liga&amp;ord=<?= $k ?>"
             class="<?= $ordem === $k ? 'on' : '' ?>"><?= h($rot) ?></a>
        <?php endforeach; ?>
      </div>
      <?php if (!$tudo): ?>
        <div class="vazio">Ninguém marcou nada ainda.</div>
      <?php else: ?>
        <?php foreach ($tudo as $s): ?>
          <button class="linha-serie" data-serie="<?= (int)$s['id'] ?>">
            <?php $u = seriesPoster($s['poster']); ?>
            <img src="<?= h($u ?: '') ?>" alt="" loading="lazy">
            <span class="ls-c">
              <span class="ls-t"><?= h($s['titulo']) ?>
                <span style="color:var(--txt3);font-weight:600;font-size:11.5px">
                  <?= h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                                   $s['ano_fim'] ? (int)$s['ano_fim'] : null,
                                   !empty($s['em_exibicao']))) ?></span>
              </span>
              <span class="ls-s">
                <span><i class="bi bi-people-fill"></i> <?= (int)$s['pessoas'] ?></span>
                <span><i class="bi bi-star-fill"></i> <?= (int)$s['avaliacoes'] ?>
                  <?= (int)$s['avaliacoes'] === 1 ? 'nota' : 'notas' ?></span>
                <?php if ((int)$s['recados'] > 0): ?>
                  <span><i class="bi bi-chat-fill"></i> <?= (int)$s['recados'] ?>
                    <?= (int)$s['recados'] === 1 ? 'recado' : 'recados' ?></span>
                <?php endif; ?>
                <?php if ($s['nota_imdb'] !== null): ?>
                  <span style="color:var(--imdb-txt)"><i class="bi bi-star-fill"></i>
                    <?= h(number_format((float)$s['nota_imdb'], 1, ',', '')) ?> IMDb</span>
                <?php endif; ?>
              </span>
            </span>
            <span class="ls-n"><?= $s['nota_fba'] !== null
                ? h(number_format((float)$s['nota_fba'], 1, ',', '')) : '—' ?></span>
          </button>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>
</div><!-- #app -->
</div><!-- .content -->
</main>

<?php
/**
 * O cartão de uma série na grade.
 *
 * Mora numa função porque a grade nasce em vários lugares — catálogo, as três
 * listas de "minhas", o top do perfil e o perfil de outra pessoa — e escrito
 * em cada um ia divergir no primeiro ajuste de layout.
 */
function cartaoDeSerie(array $s, bool $comPosicao = false): string
{
    $url = seriesPoster($s['poster']);
    $ico = ['assistida' => 'check-lg', 'assistindo' => 'play-fill', 'quero' => 'bookmark-fill'];

    $html = '<button class="card" data-serie="' . (int)$s['id'] . '">'
          . '<span class="capa">'
          . ($url ? '<img src="' . h($url) . '" alt="" loading="lazy">'
                  : '<span class="vazia"><i class="bi bi-tv"></i></span>');

    if ($s['nota_imdb'] !== null) {
        $html .= '<span class="selo imdb"><i class="bi bi-star-fill"></i>'
               . number_format((float)$s['nota_imdb'], 1, ',', '') . '</span>';
    }
    if (!empty($s['estado'])) {
        $html .= '<span class="marca-estado ' . h($s['estado']) . '">'
               . '<i class="bi bi-' . ($ico[$s['estado']] ?? 'dot') . '"></i></span>';
    }
    if (!empty($s['minha_nota'])) {
        $html .= '<span class="minha-nota">' . (int)$s['minha_nota'] . '</span>';
    }
    if ($comPosicao && !empty($s['favorita'])) {
        $html .= '<span class="pos">' . (int)$s['favorita'] . '</span>';
    }

    return $html . '</span>'
         . '<span class="c-tit">' . h($s['titulo']) . '</span>'
         . '<span class="c-ano">'
         . h(seriesAnos($s['ano_inicio'] ? (int)$s['ano_inicio'] : null,
                        $s['ano_fim'] ? (int)$s['ano_fim'] : null, !empty($s['em_exibicao'])))
         . '</span></button>';
}

/**
 * O DIÁRIO DE UMA PESSOA — os números, o top e as listas.
 *
 * Serve pro meu perfil e pra visita, e é a mesma função nos dois: o perfil de
 * outro não pode ser uma versão empobrecida do meu, porque é ele que responde
 * "o que o cara andou vendo", que é a pergunta que traz gente pra cá.
 *
 * @param int|null $de null = sou eu; as listas por estado só entram na visita,
 *                     porque no meu caso a aba "Minhas séries" já as mostra.
 */
function diarioDe(PDO $pdo, array $perfil, ?int $de): string
{
    $meu = $de === null;
    ob_start();
    ?>
    <div class="bloco">
      <h3><i class="bi bi-person-badge-fill"></i> <?= $meu ? 'Meu diário' : 'Os números' ?></h3>
      <div class="fichas">
        <div class="ficha"><div class="v" <?= $meu ? 'id="pAssistidas"' : '' ?>><?= (int)$perfil['assistidas'] ?></div><div class="r">assistidas</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pAssistindo"' : '' ?>><?= (int)$perfil['assistindo'] ?></div><div class="r">assistindo</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pQuero"' : '' ?>><?= (int)$perfil['quero'] ?></div><div class="r">quero ver</div></div>
        <div class="ficha"><div class="v" <?= $meu ? 'id="pNota"' : '' ?>><?= $perfil['nota_media'] !== null
              ? number_format((float)$perfil['nota_media'], 1, ',', '') : '—' ?></div>
          <div class="r"><?= $meu ? 'sua nota média' : 'nota média' ?></div></div>
      </div>
    </div>

    <div class="bloco">
      <h3><i class="bi bi-star-fill"></i> Top <?= SERIES_TOP ?></h3>
      <?php /* A grade e o aviso existem OS DOIS, um escondido: a estrela do
           acervo põe e tira sem recarregar, e o JS precisa dos dois lugares
           prontos pra trocar qual aparece. */ ?>
      <div class="vazio" id="topVazio"<?= $perfil['top'] ? ' hidden' : '' ?>><?= $meu
          ? 'Toque na estrela de uma série assistida, aqui embaixo ou na ficha.'
          : 'Não montou o top ainda.' ?></div>
      <div class="top-lista" id="topLista"<?= $perfil['top'] ? '' : ' hidden' ?>><?php
        foreach ($perfil['top'] as $s) echo cartaoDeSerie($s, true); ?></div>
    </div>

    <?php if (!$meu): ?>
      <?php foreach (SERIES_ESTADOS as $chave => $rotulo): ?>
        <?php $lista = seriesMinhas($pdo, $de, $chave); ?>
        <div class="bloco">
          <h3>
            <i class="bi bi-<?= $chave === 'assistida' ? 'check-circle-fill'
                              : ($chave === 'assistindo' ? 'play-circle-fill' : 'bookmark-fill') ?>"></i>
            <?= h($rotulo) ?>
            <span class="conta"><?= count($lista) ?></span>
          </h3>
          <?php if (!$lista): ?>
            <div class="vazio">Nada aqui.</div>
          <?php else: ?>
            <div class="grade"><?php foreach ($lista as $s) echo cartaoDeSerie($s); ?></div>
          <?php endif; ?>
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
    <?php
    return (string)ob_get_clean();
}
?>

<?php if ($idUsuario > 0 && $midia === 'series' && $catalogo > 0): ?>
<?php /* ── A FICHA DA SÉRIE ─────────────────────────────────────────────
     Um popup e não uma página: marcar é um gesto de meio segundo, e ir e
     voltar de página a cada série perderia o lugar na grade. */ ?>
<div class="fundo" id="popSerie" hidden>
  <div class="pop">
    <div class="pop-topo">
      <div class="pop-capa" id="fCapa"></div>
      <div style="min-width:0;flex:1">
        <div class="pop-tit" id="fTitulo"></div>
        <div class="pop-sub" id="fSub"></div>
        <div class="pop-gen" id="fGen"></div>
        <div class="notas">
          <div class="nota-caixa imdb"><div class="n" id="fImdb">—</div><div class="r">IMDb</div></div>
          <div class="nota-caixa fba"><div class="n" id="fFba">—</div><div class="r" id="fFbaR">FBA</div></div>
          <div class="nota-caixa eu"><div class="n" id="fEu">—</div><div class="r">você</div></div>
        </div>
      </div>
    </div>

    <div class="pop-sin" id="fSinopse"></div>

    <div class="rot">Onde essa entra</div>
    <div class="estados" id="fEstados">
      <?php foreach (SERIES_ESTADOS as $k => $rot): ?>
        <button class="est" data-estado="<?= h($k) ?>"><?= h($rot) ?></button>
      <?php endforeach; ?>
    </div>

    <div class="rot" id="fRotNota">Sua nota</div>
    <div class="notinhas" id="fNotas">
      <?php for ($n = 1; $n <= 10; $n++): ?>
        <button class="notinha" data-nota="<?= $n ?>"><?= $n ?></button>
      <?php endfor; ?>
    </div>

    <?php /* NOTA É UM NÚMERO E NÚMERO NÃO CONTA POR QUE. "8" pode ser
         decepção de quem esperava 10 e euforia de quem esperava 5 — e é essa
         frase que faz a lista da liga valer a leitura. */ ?>
    <div class="rot" id="fRotRecado">Seu recado</div>
    <?php /* SEM BOTÃO DE SALVAR: ele salva sozinho quando a pessoa para de
         digitar. Um recado de uma linha não merece um passo a mais, e botão
         de salvar é justamente o passo que se esquece — quem escrevia e
         fechava a ficha perdia o que tinha escrito sem nem saber. */ ?>
    <div class="caixa-recado">
      <textarea id="fRecadoTxt" maxlength="280" rows="2"
                placeholder="Uma linha sobre ela — o que te pegou, o que te irritou."></textarea>
      <div class="pe-recado">
        <span class="situacao" id="fSituacao"></span>
        <span><span id="fSobra">280</span> sobrando</span>
      </div>
    </div>

    <div class="rot">O que a liga diz <span class="conta" id="fQuantos"></span></div>
    <div class="diz" id="fDiz"></div>

    <div class="recado" id="fRecado"></div>

    <div class="pop-acoes">
      <?php /* O BOTÃO DIZ O DESTINO, não o sentimento: "Favorita" não contava
           que existe uma lista, nem que ela tem tamanho. */ ?>
      <button class="btn fav" id="fFav"><i class="bi bi-star"></i>
        <span>Top <?= SERIES_TOP ?></span></button>
      <button class="btn pri" data-fechar>Pronto</button>
    </div>
  </div>
</div>

<?php /* O POPUP DE CONFIRMAR. Tirar do perfil apaga nota, recado e a vaga no
     top junto — é pouco pra pedir F5 de volta e demais pra fazer calado. */ ?>
<div class="fundo" id="popPergunta" hidden>
  <div class="pop pergunta">
    <h4 id="pqTit">Tirar do seu perfil?</h4>
    <p id="pqTxt"></p>
    <div class="pop-acoes">
      <button class="btn" data-nao>Deixa</button>
      <button class="btn perigo" id="pqSim"><i class="bi bi-x-lg"></i> Tirar</button>
    </div>
  </div>
</div>

<script>
(function () {
  var POSTER = <?= json_encode(SERIES_IMG_BASE . SERIES_POSTER_G) ?>;
  var ESTADOS = <?= json_encode(SERIES_ESTADOS, JSON_UNESCAPED_UNICODE) ?>;
  var VERBO = <?= json_encode(CLUBE_VERBO, JSON_UNESCAPED_UNICODE) ?>;
  var ICONE = {assistida: 'check-lg', assistindo: 'play-fill', quero: 'bookmark-fill'};
  var TOP = <?= SERIES_TOP ?>;
  var EU = <?= (int)$idUsuario ?>;
  var LIMITE_RECADO = 280;

  var pop = document.getElementById('popSerie');
  var grade = document.getElementById('grade');
  var atual = null;                       // a série aberta no popup
  var quem = [];                          // quem da liga já mexeu nela

  function $(id) { return document.getElementById(id); }
  function num(v) { return v === null || v === undefined || v === '' ? null : Number(v); }
  function umaCasa(v) { return v === null ? '—' : String(v).replace('.', ','); }
  function escapa(t) {
    return String(t).replace(/[&<>"]/g, function (c) {
      return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c];
    });
  }
  function avatar(u) { return u && String(u).trim() !== '' ? u : '/img/default-avatar.png'; }

  /* ── O cartão, igual ao do PHP ──────────────────────────────────────
     Ele nasce nos dois lados: no servidor, pras grades que já vêm prontas, e
     aqui, pra busca que troca a grade sem recarregar. */
  function cartao(s, comPosicao) {
    var capa = s.poster
      ? '<img src="' + POSTER.replace('w500', 'w342') + s.poster + '" alt="" loading="lazy">'
      : '<span class="vazia"><i class="bi bi-tv"></i></span>';
    var selo = s.nota_imdb !== null
      ? '<span class="selo imdb"><i class="bi bi-star-fill"></i>' + umaCasa(s.nota_imdb) + '</span>' : '';
    var est = s.estado
      ? '<span class="marca-estado ' + s.estado + '"><i class="bi bi-' + (ICONE[s.estado] || 'dot') + '"></i></span>' : '';
    var minha = s.minha_nota ? '<span class="minha-nota">' + s.minha_nota + '</span>' : '';
    var pos = (comPosicao && s.favorita) ? '<span class="pos">' + s.favorita + '</span>' : '';
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    return '<button class="card" data-serie="' + s.id + '"><span class="capa">'
         + capa + selo + est + minha + pos + '</span>'
         + '<span class="c-tit">' + escapa(s.titulo) + '</span>'
         + '<span class="c-ano">' + ano + '</span></button>';
  }

  /* ── A busca ────────────────────────────────────────────────────────
     Uma consulta por PAUSA na digitação, não por tecla: quem escreve "breaking
     bad" dispararia doze idas ao servidor e jogaria onze fora. */
  var busca = document.getElementById('busca');
  var relogio = null;
  if (busca && grade) {
    busca.addEventListener('input', function () {
      clearTimeout(relogio);
      /* `true` = pode sair pro TMDB. Só a digitação de uma pessoa abre essa
         porta; nenhuma atualização automática de tela passa por aqui. */
      relogio = setTimeout(function () { carregarGrade(true); }, 220);
    });
  } else if (busca) {
    /* FORA DO CATÁLOGO A BUSCA VIRA UM ATALHO. A caixa fica no topo em todas
       as abas porque procurar série é o gesto mais comum da página; onde não
       tem grade pra trocar, o Enter leva pro catálogo já procurando. */
    busca.addEventListener('keydown', function (e) {
      if (e.key !== 'Enter') return;
      e.preventDefault();
      location.href = '?midia=series&aba=catalogo&q=' + encodeURIComponent(busca.value.trim());
    });
  }

  /* O CATÁLOGO TEM FUNDO FALSO. O importador trouxe as mil e seiscentas mais
     conhecidas; quem procurar a série obscura que só ele assiste não acharia.
     Então: a busca local responde na hora e, se veio pouca coisa, a página
     pergunta ao TMDB — e a partir daí aquela série existe pra liga inteira.

     O limite é POUCO, não ZERO: quem digita "dark" acha um punhado de coisas
     com "dark" no nome e mesmo assim quer a série alemã. */
  var MINIMO_LOCAL = 5;
  var buscaEmCurso = 0;

  /* OS TERMOS QUE JÁ FORAM PERGUNTADOS AO TMDB nesta visita. Apagar uma
     letra e escrever de novo repetia a ida à internet inteira pra trazer
     exatamente o que ela já tinha trazido. */
  var jaProcurouFora = {};

  /**
   * @param {boolean} podeSairFora só a digitação de uma pessoa abre a porta
   *   do TMDB. Marcar, avaliar e favoritar redesenham a grade e NÃO passam
   *   por lá: a série que acabou de ser marcada já está no catálogo — foi de
   *   lá que ela abriu — e a viagem custava segundos por nada.
   */
  function carregarGrade(podeSairFora) {
    if (!grade) return;
    var q = busca ? busca.value.trim() : '';
    var meuTurno = ++buscaEmCurso;

    fetch(location.pathname + '?json=busca&q=' + encodeURIComponent(q))
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (meuTurno !== buscaEmCurso) return;    // já digitaram outra coisa
        var lista = pintarLista(d.series || [], q);

        if (podeSairFora && !jaProcurouFora[q] && q.length >= 3 && lista.length < MINIMO_LOCAL) {
          jaProcurouFora[q] = true;
          $('gradeFora').hidden = false;
          $('gradeVazia').hidden = true;
          fetch(location.pathname + '?json=busca_fora&q=' + encodeURIComponent(q))
            .then(function (r) { return r.json(); })
            .then(function (d2) {
              if (meuTurno !== buscaEmCurso) return;
              $('gradeFora').hidden = true;
              pintarLista(d2.series || [], q);
            })
            .catch(function () { $('gradeFora').hidden = true; });
        }
      })
      .catch(function () {});
  }

  function pintarLista(lista, q) {
    grade.innerHTML = lista.map(cartao).join('');
    $('gradeVazia').hidden = lista.length > 0;
    $('tituloGrade').textContent = q ? 'Resultados de "' + q + '"' : 'Mais populares';
    return lista;
  }
  /* A primeira carga sai do catálogo local e pronto: se a página abriu com
     um termo na URL que acha pouco, quem quiser o TMDB digita uma letra. */
  if (grade) carregarGrade(<?= $qInicial !== '' ? 'true' : 'false' ?>);

  /* ── Abrir a ficha ──────────────────────────────────────────────────
     Qualquer coisa com data-serie abre: o cartão da grade, a linha do
     ranking, a linha da lista da liga e o pôster do feed. */
  document.addEventListener('click', function (e) {
    var c = e.target.closest('[data-serie]');
    if (!c) return;
    abrir(Number(c.dataset.serie));
  });

  function abrir(id) {
    salvarRecado();          // grava o da ficha anterior antes de trocar
    fetch(location.pathname + '?json=serie&id=' + id)
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.serie) return;
        atual = d.serie;
        quem = d.quem || [];
        pintar();
        pop.hidden = false;
      });
  }

  function pintar() {
    var s = atual;
    $('fCapa').innerHTML = s.poster
      ? '<img src="' + POSTER + s.poster + '" alt="">'
      : '<span class="vazia" style="display:flex;align-items:center;justify-content:center;height:100%"><i class="bi bi-tv"></i></span>';
    $('fTitulo').textContent = s.titulo;

    var partes = [];
    var ano = s.ano_inicio ? (s.em_exibicao == 1 ? s.ano_inicio + '–'
             : (s.ano_fim && s.ano_fim != s.ano_inicio ? s.ano_inicio + '–' + s.ano_fim : s.ano_inicio)) : '';
    if (ano) partes.push(ano);
    if (s.temporadas) partes.push(s.temporadas + (s.temporadas == 1 ? ' temporada' : ' temporadas'));
    if (s.episodios) partes.push(s.episodios + ' episódios');
    if (s.titulo_original && s.titulo_original !== s.titulo) partes.push(s.titulo_original);
    $('fSub').textContent = partes.join(' · ');
    $('fGen').textContent = s.generos || '';
    $('fSinopse').textContent = s.sinopse || 'Sem sinopse no catálogo.';

    $('fImdb').textContent = umaCasa(num(s.nota_imdb));
    $('fFba').textContent  = umaCasa(num(s.nota_fba));
    $('fFbaR').textContent = Number(s.votos_fba) > 0
      ? 'FBA · ' + s.votos_fba + (Number(s.votos_fba) === 1 ? ' nota' : ' notas') : 'FBA';
    $('fEu').textContent   = s.minha_nota ? s.minha_nota : '—';

    document.querySelectorAll('#fEstados .est').forEach(function (b) {
      b.classList.toggle('on', b.dataset.estado === s.estado);
    });

    /* SÓ QUEM ASSISTIU AVALIA — e a tela diz por quê antes de a pessoa
       tentar, em vez de recusar depois do clique. O recado segue a nota: quem
       só pôs na fila não tem o que contar ainda. */
    var podeAvaliar = s.estado === 'assistida' || s.estado === 'assistindo';
    document.querySelectorAll('#fNotas .notinha').forEach(function (b) {
      b.disabled = !podeAvaliar;
      b.classList.toggle('on', podeAvaliar && Number(b.dataset.nota) === Number(s.minha_nota));
    });
    $('fRotNota').textContent = podeAvaliar
      ? 'Sua nota' : 'Sua nota — marque como assistida pra poder dar';

    var txt = $('fRecadoTxt');
    txt.value = s.meu_recado || '';
    txt.disabled = !podeAvaliar;
    /* O que já está no banco. É com ele que o autosave compara pra não
       mandar requisição quando a pessoa só abriu a ficha e fechou. */
    recadoNoBanco = txt.value;
    $('fRotRecado').textContent = podeAvaliar
      ? 'Seu recado' : 'Seu recado — marque como assistida pra poder escrever';
    situacao('');
    contarSobra();

    pintarQuem();

    var fav = $('fFav');
    fav.classList.toggle('on', !!s.favorita);
    fav.querySelector('i').className = s.favorita ? 'bi bi-star-fill' : 'bi bi-star';
    fav.querySelector('span').textContent = s.favorita
      ? s.favorita + 'º no seu top' : 'Top ' + TOP;
    $('fRecado').textContent = '';
  }

  /* ── O QUE A LIGA DIZ ───────────────────────────────────────────────
     É o que transforma a ficha de uma página de catálogo numa conversa. Quem
     escreveu vem primeiro (o servidor já ordena assim), e a minha linha fica
     marcada pra eu achar o meu recado sem ler a lista inteira. */
  function pintarQuem() {
    var alvo = $('fDiz');
    $('fQuantos').textContent = quem.length
      ? quem.length + (quem.length === 1 ? ' pessoa' : ' pessoas') : '';
    if (!quem.length) {
      alvo.innerHTML = '<div class="vazio">Ninguém da liga marcou essa ainda.</div>';
      return;
    }
    alvo.innerHTML = quem.map(function (p) {
      var eu = Number(p.user_id) === EU;
      var nota = p.nota !== null && p.nota !== undefined
        ? '<span class="nota-pino">' + p.nota + '</span>' : '';
      var txt = p.comentario
        ? '<div class="diz-txt">“' + escapa(p.comentario) + '”</div>' : '';
      return '<div class="diz-um' + (eu ? ' diz-eu' : '') + '">'
           + '<img class="av" src="' + escapa(avatar(p.photo_url)) + '" alt=""'
           + ' onerror="this.src=\'/img/default-avatar.png\'">'
           + '<div style="flex:1;min-width:0">'
           + '<div class="diz-cab"><b>' + escapa(eu ? 'Você' : p.nome) + '</b>'
           + '<span class="oq">' + (VERBO[p.estado] || '') + '</span>'
           + (p.league ? '<span class="pino">' + escapa(p.league) + '</span>' : '')
           + '</div>' + txt + '</div>' + nota + '</div>';
    }).join('');
  }

  /* ── As ações ───────────────────────────────────────────────────────── */
  function manda(corpo) {
    return fetch(location.pathname, {method: 'POST', body: new URLSearchParams(corpo)})
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { $('fRecado').textContent = d.erro || 'Não deu.'; return null; }
        $('fRecado').textContent = '';
        atualizarPerfil(d.perfil);
        return d;
      })
      .catch(function () { $('fRecado').textContent = 'Sem resposta do servidor.'; return null; });
  }

  document.getElementById('fEstados').addEventListener('click', function (e) {
    var b = e.target.closest('.est');
    if (!b || !atual) return;
    manda({acao: 'marcar', serie: atual.id, estado: b.dataset.estado}).then(function (d) {
      if (!d) return;
      atual.estado = d.estado;
      /* Sair da lista leva nota, recado e favorita junto — o servidor já fez
         isso, e a tela tem que contar a mesma história. */
      if (!d.estado || d.estado === 'quero') {
        atual.minha_nota = null; atual.meu_recado = null; atual.favorita = null;
      }
      /* pintar() relê o campo do que está em `atual`, e é ele que acerta o
         recadoNoBanco — sem passar por aqui, o autosave acharia que o texto
         apagado pelo servidor ainda estava lá. */
      if (d.quem) quem = d.quem;
      pintar(); atualizarCartao();
    });
  });

  document.getElementById('fNotas').addEventListener('click', function (e) {
    var b = e.target.closest('.notinha');
    if (!b || b.disabled || !atual) return;
    manda({acao: 'avaliar', serie: atual.id, nota: b.dataset.nota}).then(function (d) {
      if (!d) return;
      atual.minha_nota = d.nota;
      atual.nota_fba = d.media;
      atual.votos_fba = d.votos;
      if (d.quem) quem = d.quem;
      pintar(); atualizarCartao();
    });
  });

  /* ── O RECADO, QUE SALVA SOZINHO ─────────────────────────────────────
     Botão de salvar é o passo que se esquece: quem escrevia a frase e
     fechava a ficha perdia tudo sem nem perceber que tinha perdido. Agora
     ele grava quando a pessoa para de digitar, e a linha embaixo diz em que
     pé está — sem isso, "salvou sozinho" é promessa que não dá pra conferir. */
  var caixa = $('fRecadoTxt');
  var relogioRecado = null;
  var recadoNoBanco = '';

  caixa.addEventListener('input', function () {
    contarSobra();
    if (caixa.disabled) return;
    situacao('');
    /* 900ms: menos que isso manda requisição no meio de uma palavra; muito
       mais e a pessoa fecha a ficha antes de o texto sair. O blur e o fechar
       cobrem o resto, então nada depende só do relógio. */
    clearTimeout(relogioRecado);
    relogioRecado = setTimeout(salvarRecado, 900);
  });
  caixa.addEventListener('blur', salvarRecado);

  function contarSobra() {
    $('fSobra').textContent = Math.max(0, LIMITE_RECADO - caixa.value.length);
    /* Cresce com o texto. O campo nasce com duas linhas porque é o tamanho de
       um recado; quem escrever quatro não deve ter que rolar dentro de uma
       caixinha. */
    caixa.style.height = 'auto';
    /* A BORDA ENTRA NA CONTA. Com box-sizing:border-box o `height` inclui
       padding e borda, mas o scrollHeight só inclui o padding — sem somar os
       2px das duas bordas, a última linha ficava cortada por baixo. */
    var borda = caixa.offsetHeight - caixa.clientHeight;
    caixa.style.height = Math.min(caixa.scrollHeight + borda, 200) + 'px';
  }

  function situacao(como, texto) {
    var e = $('fSituacao');
    e.className = 'situacao' + (como === 'ok' ? ' ok' : (como === 'ruim' ? ' ruim' : ''));
    e.innerHTML = como === 'salvando' ? '<i class="bi bi-arrow-repeat"></i> salvando…'
                : como === 'ok'       ? '<i class="bi bi-check-lg"></i> salvo'
                : como === 'ruim'     ? escapa(texto || 'não deu pra salvar')
                : '';
  }

  function salvarRecado() {
    clearTimeout(relogioRecado);
    if (!atual || caixa.disabled) return;

    var texto = caixa.value;
    if (texto === recadoNoBanco) return;      // nada mudou desde a última vez

    /* De QUAL série é este texto. Entre mandar e voltar a pessoa pode ter
       aberto outra ficha, e aí o recado dela não pode receber a confirmação
       do anterior. */
    var deQual = atual.id;
    situacao('salvando');

    fetch(location.pathname, {
      method: 'POST',
      /* keepalive: fechar a ficha pode recarregar a página no mesmo instante,
         e uma requisição comum morreria com a navegação. Com ele o navegador
         entrega mesmo assim — é a diferença entre "quase sempre salva" e
         "salva". */
      keepalive: true,
      body: new URLSearchParams({acao: 'comentar', serie: deQual, texto: texto})
    })
      .then(function (r) { return r.json(); })
      .then(function (d) {
        if (!d.ok) { situacao('ruim', d.erro); return; }
        mexi = true;
        if (!atual || atual.id != deQual) return;   // já trocou de ficha
        recadoNoBanco = texto;
        atual.meu_recado = d.comentario;
        situacao('ok');
        /* A minha linha em "o que a liga diz" muda junto. Remendar o array
           aqui em vez de perguntar de novo economiza uma ida ao servidor a
           cada pausa na digitação — e é um campo só, que eu acabei de
           mandar. Se por algum motivo eu não estiver na lista, aí sim vale
           perguntar. */
        if (d.quem) { quem = d.quem; pintarQuem(); }
      })
      .catch(function () { situacao('ruim', 'sem resposta do servidor'); });
  }

  document.getElementById('fFav').addEventListener('click', function () {
    if (!atual) return;
    manda({acao: 'favoritar', serie: atual.id}).then(function (d) {
      if (!d) return;
      atual.favorita = d.favorita;
      pintar();
    });
  });

  /**
   * SÓ O CARTÃO QUE MUDOU.
   *
   * Dar uma nota disparava a busca inteira de novo — e, com um termo que
   * acha pouco no catálogo, isso ia até o TMDB. Quatro requisições, uma
   * delas de segundos, pra trocar um número num pôster. O que muda no
   * cartão é o selo de estado e a nota, e os dois estão em `atual`.
   */
  function atualizarCartao() {
    if (!grade || !atual) return;
    var velho = grade.querySelector('.card[data-serie="' + atual.id + '"]');
    if (!velho) return;
    var molde = document.createElement('div');
    molde.innerHTML = cartao(atual);
    velho.replaceWith(molde.firstChild);
  }

  /* O perfil muda a cada marca, e o número no topo do perfil não pode mentir
     até o próximo F5. */
  function atualizarPerfil(p) {
    if (!p) return;
    var mapa = {pAssistidas: p.assistidas, pAssistindo: p.assistindo, pQuero: p.quero};
    Object.keys(mapa).forEach(function (id) { if ($(id)) $(id).textContent = mapa[id]; });
    if ($('pNota')) $('pNota').textContent = p.nota_media === null ? '—' : umaCasa(p.nota_media);
  }

  /* ── Fechar ─────────────────────────────────────────────────────────── */
  var mexi = false;                       // marquei, avaliei ou escrevi algo?
  pop.addEventListener('click', function (e) {
    if (e.target === pop || e.target.hasAttribute('data-fechar')) fechar();
  });
  document.addEventListener('keydown', function (e) {
    if (e.key !== 'Escape') return;
    /* A pergunta está por cima da ficha: o Escape fecha a de cima. */
    if (pq && !pq.hidden) { pq.hidden = true; pqQuando = null; return; }
    if (!pop.hidden) fechar();
  });
  function fechar() {
    /* O QUE ESTÁ NO CAMPO VAI ANTES DE FECHAR. Sem isto, quem digitasse e
       fechasse em menos de um segundo perdia a frase — que é justamente o
       problema que o autosave veio resolver. */
    salvarRecado();
    pop.hidden = true;
    atual = null;
    quem = [];
    /* AS LISTAS DO SERVIDOR FICAM VELHAS depois de marcar: o top do perfil, os
       rankings da liga e a lista de tudo. Quem está nelas vê o certo ao
       fechar, sem precisar apertar F5 — e só recarrega se de fato mexeu. */
    if (!grade && mexi) location.reload();
  }
  ['fEstados', 'fNotas', 'fFav'].forEach(function (id) {
    $(id).addEventListener('click', function () { mexi = true; });
  });

  /* ── O ACERVO: a nota na linha e o X ────────────────────────────────
     Duas ações que não passam pela ficha, porque quem entrou aqui entrou pra
     arrumar em lote: dez notas seriam dez popups, e tirar seria abrir a ficha
     pra desmarcar três vezes. */
  var acervo = $('acervo');
  if (acervo) {
    acervo.addEventListener('change', function (e) {
      var sel = e.target.closest('.ac-n');
      if (!sel) return;
      var linha = sel.closest('.ac-um');
      sel.disabled = true;
      fetch(location.pathname, {
        method: 'POST',
        body: new URLSearchParams({acao: 'avaliar', serie: sel.dataset.notaDe, nota: sel.value})
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          sel.disabled = false;
          if (!d.ok) { avisar('Não deu', d.erro || 'Tente de novo.'); return; }
          /* O servidor pode dizer outra coisa: clicar na mesma nota limpa. */
          sel.value = d.nota === null || d.nota === undefined ? '' : d.nota;
          sel.classList.toggle('deu', sel.value !== '');
          atualizarPerfil(d.perfil);
          /* Um pisca verde e pronto: recarregar a lista inteira jogaria a
             pessoa de volta pro topo no meio da arrumação. */
          linha.animate([{background: 'rgba(34,197,94,.16)'}, {background: 'transparent'}],
                        {duration: 600});
        })
        .catch(function () { sel.disabled = false; avisar('Sem resposta', 'O servidor não respondeu.'); });
    });

    /* ── A ESTRELA DA LINHA ──────────────────────────────────────────
       Tirar uma do meio do top faz as de baixo subirem, então NÃO dá pra
       remendar só a linha clicada: o servidor devolve o perfil inteiro, e é
       dele que as posições de todas as linhas são reescritas. Sem isso, tirar
       a 3ª deixaria 4º, 5º… mentindo na tela até o próximo F5. */
    acervo.addEventListener('click', function (e) {
      var b = e.target.closest('.ac-top');
      if (!b || b.disabled) return;
      e.stopPropagation();                      // não abre a ficha junto
      b.disabled = true;
      fetch(location.pathname, {
        method: 'POST',
        body: new URLSearchParams({acao: 'favoritar', serie: b.dataset.top})
      })
        .then(function (r) { return r.json(); })
        .then(function (d) {
          b.disabled = false;
          if (!d.ok) { avisar('Não deu', d.erro || 'Tente de novo.'); return; }
          pintarTopDoAcervo(d.perfil);
        })
        .catch(function () { b.disabled = false; avisar('Sem resposta', 'O servidor não respondeu.'); });
    });

    /**
     * Reescreve estrela e posição de TODAS as linhas — e a grade do topo da
     * página, que é a mesma informação vista de outro jeito. Duas telas do
     * mesmo top, uma delas desatualizada, é pior que não ter a segunda.
     */
    function pintarTopDoAcervo(perfil) {
      if (!perfil) return;
      var onde = {};
      (perfil.top || []).forEach(function (s) { onde[s.id] = s.favorita; });

      var lista = $('topLista'), aviso = $('topVazio');
      if (lista) {
        lista.innerHTML = (perfil.top || []).map(function (s) { return cartao(s, true); }).join('');
        lista.hidden = !(perfil.top || []).length;
        if (aviso) aviso.hidden = !lista.hidden;
      }

      [].forEach.call(acervo.querySelectorAll('.ac-um'), function (linha) {
        var pos = onde[linha.dataset.linha];
        var pino = linha.querySelector('.pino.top');
        var bot  = linha.querySelector('.ac-top');
        if (pino) {
          pino.hidden = !pos;
          if (pos) pino.innerHTML = '<i class="bi bi-star-fill"></i> ' + pos + 'º';
        }
        if (bot && !bot.disabled) {
          bot.classList.toggle('on', !!pos);
          bot.querySelector('i').className = 'bi bi-star' + (pos ? '-fill' : '');
          bot.title = (pos ? 'Tirar do' : 'Pôr no') + ' Top ' + TOP;
        }
      });
    }

    acervo.addEventListener('click', function (e) {
      var x = e.target.closest('.ac-x');
      if (!x) return;
      e.stopPropagation();                      // não abre a ficha junto
      var linha = x.closest('.ac-um');
      pergunta('Tirar do seu perfil?',
               '“' + x.dataset.titulo + '” sai da sua lista, e a nota, o recado e a vaga '
               + 'no top vão com ela. Dá pra marcar de novo depois.',
               function () {
        linha.classList.add('saindo');
        fetch(location.pathname, {
          method: 'POST',
          body: new URLSearchParams({acao: 'tirar', serie: x.dataset.tirar})
        })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (!d.ok) {
              linha.classList.remove('saindo');
              avisar('Não deu', d.erro || 'Tente de novo.');
              return;
            }
            linha.remove();
            atualizarPerfil(d.perfil);
            var conta = $('acConta');
            if (conta) conta.textContent = acervo.querySelectorAll('.ac-um').length;
            if (!acervo.querySelectorAll('.ac-um').length) location.reload();
          })
          .catch(function () {
            linha.classList.remove('saindo');
            avisar('Sem resposta', 'O servidor não respondeu.');
          });
      });
    });
  }

  /* ── PERGUNTAR E AVISAR, sempre no popup do jogo ─────────────────────
     confirm() e alert() do navegador não usam a cara da página, não dão pra
     escrever em português de gente e no celular vêm com o nome do site em
     cima. O jogo inteiro é assim; isto aqui segue a casa. */
  var pq = $('popPergunta'), pqQuando = null;
  function pergunta(titulo, texto, quando) {
    $('pqTit').textContent = titulo;
    $('pqTxt').textContent = texto;
    $('pqSim').hidden = false;
    $('pqSim').innerHTML = '<i class="bi bi-x-lg"></i> Tirar';
    pq.querySelector('[data-nao]').textContent = 'Deixa';
    pqQuando = quando;
    pq.hidden = false;
  }
  function avisar(titulo, texto) {
    $('pqTit').textContent = titulo;
    $('pqTxt').textContent = texto;
    $('pqSim').hidden = true;
    pq.querySelector('[data-nao]').textContent = 'Fechar';
    pqQuando = null;
    pq.hidden = false;
  }
  pq.addEventListener('click', function (e) {
    if (e.target === pq || e.target.hasAttribute('data-nao')) { pq.hidden = true; pqQuando = null; }
    if (e.target.closest('#pqSim')) {
      pq.hidden = true;
      var f = pqQuando; pqQuando = null;
      if (f) f();
    }
  });

})();
</script>
<?php endif; ?>

<?php /* O botão de menu do celular e o de tema, os mesmos de todas as telas.
     O tema.js FALTAVA: o botão "Modo claro" nasce no sidebar.php e quem o
     faz funcionar é este arquivo. Sem ele a página lia o tema salvo ao
     carregar — então quem tinha trocado em outra tela via o claro aqui —
     mas clicar no botão DENTRO do Clube não fazia nada. */ ?>
<script src="/js/popups.js"></script>
<script src="/js/sidebar.js"></script>
<script src="/js/tema.js"></script>
</body>
</html>
