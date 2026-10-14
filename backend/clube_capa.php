<?php
/**
 * A CAPA DO CLUBE — as consultas que juntam as quatro mídias numa tela só.
 *
 * ── POR QUE ELA EXISTE ───────────────────────────────────────────────
 *
 * O Clube abria em Séries e as outras mídias eram abas ao lado. O resultado
 * está no banco: 43 pessoas mexeram em séries, 21 votaram no álbum ou no
 * filme da semana e só 8 deram nota em alguma coisa. Quem entra fica onde
 * caiu — pedido do Marcos (14/10/2026): "o pessoal tá entrando mas ficando
 * só em séries, tem como unificar tudo, criar a página do clube sendo tudo
 * junto".
 *
 * Então a porta passa a ser uma capa que mostra as quatro ao mesmo tempo: o
 * que está em cartaz, o que está em votação, as pesquisas abertas e o que a
 * liga fez nos últimos dias. As abas continuam existindo — é lá que mora o
 * catálogo de 3 mil séries e o acervo de cada um —, mas ninguém mais precisa
 * descobrir que elas existem pra saber que o Clube tem música.
 *
 * Aqui só há LEITURA e junção. Quem sabe as regras continua sendo
 * backend/clube_semana.php (o da semana), backend/series.php (as séries) e
 * backend/clube_livro.php (o clube do livro) — esta camada não decide nada,
 * só pergunta às três e põe lado a lado.
 *
 * @see clube.php                  a tela
 * @see includes/clube-capa.php    o desenho da capa
 */

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/clube_semana.php';
require_once __DIR__ . '/series.php';

/**
 * O MOVIMENTO DO CLUBE INTEIRO: séries e obras da semana na mesma lista.
 *
 * São duas consultas e não um UNION: as duas tabelas não têm as mesmas
 * colunas nem o mesmo sentido de "nota", e um UNION com dez COALESCE custaria
 * mais pra ler do que o merge de duas listas curtas. Cada lado traz o dobro
 * do que vai aparecer, porque o corte é feito depois de misturar — senão o
 * lado mais movimentado (séries, sempre) comeria a lista inteira.
 *
 * @return array<int,array{tipo:string,quem:string,foto:?string,user_id:int,
 *                         verbo:string,titulo:string,nota:?int,texto:?string,
 *                         quando:string,link:string,ico:string}>
 */
function clubeCapaMovimento(PDO $pdo, int $quantos = 14): array
{
    $quantos = max(1, min(40, $quantos));
    $itens = [];

    /* ── SÉRIES ──────────────────────────────────────────────────────── */
    try {
        seriesGarantirTabelas($pdo);
        $st = $pdo->prepare("SELECT u.estado, u.nota, u.comentario, u.atualizado_em,
                                    s.id AS serie_id, s.titulo,
                                    u.user_id, us.photo_url, COALESCE(us.name,'Alguém') AS quem
                               FROM series_usuario u
                               JOIN series s ON s.id = u.serie_id
                          LEFT JOIN users us ON us.id = u.user_id
                           ORDER BY u.atualizado_em DESC LIMIT " . ($quantos * 2));
        $st->execute();
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $verbo = ['quero' => 'quer ver', 'assistindo' => 'está vendo',
                      'assistida' => 'assistiu'][$l['estado']] ?? 'marcou';
            $itens[] = [
                'tipo'   => 'serie',
                'ico'    => 'projector-fill',
                'quem'   => (string)$l['quem'],
                'foto'   => $l['photo_url'] ?? null,
                'user_id'=> (int)$l['user_id'],
                'verbo'  => $verbo,
                'titulo' => (string)$l['titulo'],
                'nota'   => $l['nota'] === null ? null : (int)$l['nota'],
                'texto'  => trim((string)($l['comentario'] ?? '')) ?: null,
                'quando' => (string)$l['atualizado_em'],
                'link'   => '/clube.php?midia=series&aba=catalogo&q=' . rawurlencode((string)$l['titulo']),
            ];
        }
    } catch (Throwable $e) {
        error_log('[clube-capa] movimento de séries: ' . $e->getMessage());
    }

    /* ── ÁLBUM, FILME E LIVRO ────────────────────────────────────────── */
    try {
        clubeSemanaTabelas($pdo);
        /* A opinião vale pela ÚLTIMA mexida: quem deu nota na sexta e
           escreveu o recado no domingo mexeu no domingo, e é assim que ela
           entra na ordem. COALESCE porque `atualizado_em` só existe depois
           da segunda vez. */
        $st = $pdo->prepare("SELECT c.tipo, op.nota, op.texto,
                                    COALESCE(op.atualizado_em, op.criado_em) AS quando,
                                    o.titulo, op.user_id, us.photo_url,
                                    COALESCE(us.name,'Alguém') AS quem
                               FROM clube_semana_opinioes op
                               JOIN clube_semana_ciclos c ON c.id = op.ciclo_id
                               JOIN clube_semana_opcoes o ON o.id = c.vencedor_opcao_id
                          LEFT JOIN users us ON us.id = op.user_id
                           ORDER BY quando DESC LIMIT " . ($quantos * 2));
        $st->execute();
        $verbos = ['album' => 'ouviu', 'filme' => 'assistiu', 'livro' => 'leu'];
        $icos   = ['album' => 'vinyl-fill', 'filme' => 'film', 'livro' => 'book-half'];
        $midias = ['album' => 'musica', 'filme' => 'filmes', 'livro' => 'livro'];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
            $itens[] = [
                'tipo'   => (string)$l['tipo'],
                'ico'    => $icos[$l['tipo']] ?? 'dot',
                'quem'   => (string)$l['quem'],
                'foto'   => $l['photo_url'] ?? null,
                'user_id'=> (int)$l['user_id'],
                'verbo'  => $verbos[$l['tipo']] ?? 'opinou sobre',
                'titulo' => (string)$l['titulo'],
                'nota'   => $l['nota'] === null ? null : (int)$l['nota'],
                'texto'  => trim((string)($l['texto'] ?? '')) ?: null,
                'quando' => (string)$l['quando'],
                'link'   => '/clube.php?midia=' . ($midias[$l['tipo']] ?? 'musica'),
            ];
        }
    } catch (Throwable $e) {
        error_log('[clube-capa] movimento das obras: ' . $e->getMessage());
    }

    usort($itens, fn($a, $b) => strcmp((string)$b['quando'], (string)$a['quando']));
    return array_slice($itens, 0, $quantos);
}

/**
 * OS NÚMEROS DA CAPA — o tamanho do acervo e quanta gente está dentro.
 *
 * "Gente no Clube" conta quem FEZ alguma coisa em qualquer mídia, sem somar
 * três contagens: quem marcou série e votou no álbum é uma pessoa só, e um
 * número inflado seria a primeira coisa que alguém da liga desmentiria.
 */
function clubeCapaNumeros(PDO $pdo): array
{
    $n = ['series' => 0, 'gente' => 0, 'obras' => 0, 'livro' => 0];
    try {
        $n['series'] = (int)$pdo->query('SELECT COUNT(*) FROM series')->fetchColumn();
        $n['gente']  = (int)$pdo->query(
            "SELECT COUNT(*) FROM (
                 SELECT user_id FROM series_usuario
                 UNION SELECT user_id FROM clube_semana_votos
                 UNION SELECT user_id FROM clube_semana_opinioes
             ) t")->fetchColumn();
        $n['obras']  = (int)$pdo->query("SELECT COUNT(*) FROM clube_semana_ciclos
                                          WHERE status = 'definido'")->fetchColumn();
        $n['livro']  = (int)$pdo->query('SELECT COUNT(*) FROM clube_livro_membros')->fetchColumn();
    } catch (Throwable $e) {
        error_log('[clube-capa] números: ' . $e->getMessage());
    }
    return $n;
}

/**
 * AS SÉRIES PRA VITRINE DA CAPA.
 *
 * `vendo` primeiro porque é a pergunta do momento — o que a liga está
 * assistindo agora é assunto de grupo; `vistas` é acervo e não muda de uma
 * semana pra outra. Se ninguém estiver assistindo nada, a vitrine cai pro
 * acervo em vez de sumir: capa com buraco parece página quebrada.
 *
 * @return array{rot:string,itens:array}
 */
function clubeCapaVitrineSeries(PDO $pdo, int $quantas = 12): array
{
    $vendo = seriesRankingDaLiga($pdo, 'vendo', $quantas);
    if (count($vendo) >= 4) {
        return ['rot' => 'A liga está vendo', 'itens' => $vendo];
    }
    $vistas = seriesRankingDaLiga($pdo, 'vistas', $quantas);
    if ($vistas) return ['rot' => 'As mais vistas da liga', 'itens' => $vistas];
    return ['rot' => 'A liga está vendo', 'itens' => $vendo];
}
