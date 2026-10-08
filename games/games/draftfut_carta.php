<?php
/**
 * ── A CARTA DO FBA DRAFT ─────────────────────────────────────────────
 *
 * Pedido do Marcos (07/10/2026): "melhora as cartinhas, ta muito feio assim
 * mal da pra ler posicao, alem disso coloca a bandeira e se conseugir pegar
 * as fotos", "os desing das cartas padroes deixa sempre igual, ai vamos
 * colocar icons, herois, igual o fifa e cada um vai ter o seu design".
 *
 * ── UM DESENHO SÓ, DOIS TAMANHOS ─────────────────────────────────────
 *
 * A carta da escolha e o jogador no campinho eram dois HTML diferentes, e por
 * isso pareciam coisas diferentes. Aqui é a MESMA função: o campinho pede o
 * tamanho `mini`, que esconde o rodapé e encolhe a fonte, mas a cor, o selo e
 * a química vêm do mesmo lugar. Mexer no desenho da carta passou a ser mexer
 * num arquivo.
 *
 * ── TAMANHO É FIXO, E ISSO ERA UM BUG ────────────────────────────────
 *
 * "ta vindo de tamanhos diferentes": a grade mandava altura livre, então uma
 * carta com nome de duas linhas ficava mais alta que a vizinha. Agora a carta
 * tem `aspect-ratio` e três faixas de altura fixa — o nome que não cabe é
 * cortado com reticência, e a carta nunca cresce.
 */

/**
 * Desenha uma carta.
 *
 * @param array  $c      a carta (nome, pos, ovr, clube, liga, escudo, nac, foto, tipo)
 * @param array  $o      opções:
 *                       'quimica'  => 0..3 ou null (não mostra)
 *                       'rotulo'   => o rótulo da vaga ('LE', 'CA'); cai em pos
 *                       'fora'     => true quando está fora de posição
 *                       'mini'     => true pro campinho
 */
function dfutCartaHtml(array $c, array $o = []): string
{
    $tipo = draftFutTipo($c);
    $rot  = DFUT_TIPOS[$tipo]['rot'] ?? '';
    $esp  = draftFutQuimicaDoTipo($c) !== 'normal';   // ícone ou herói

    $mini    = !empty($o['mini']);
    $fora    = !empty($o['fora']);
    $quim    = $o['quimica'] ?? null;
    $posTxt  = (string)($o['rotulo'] ?? $c['pos']);
    $bandeira = draftFutBandeira((string)($c['nac'] ?? ''));
    /* Na carta grande cabe "R. Lewandowski"; na mini do campinho só cabe o
       nome de camisa, "Lewandowski". O nome inteiro fica no `title`. */
    $nome = $mini ? draftFutNomeCamisa((string)$c['nome']) : draftFutNomeCurto((string)$c['nome']);

    /* `dfc-selado` abre espaço no alto pra tarja da coleção. Sem isso a
       tarja comprida ("TIME DA SEMANA") subia por cima da bandeira. */
    $selo = $esp || in_array($tipo, ['totw', 'futuro'], true);
    $cls = 'dfc dfc-' . $tipo . ($mini ? ' dfc-mini' : '') . ($fora ? ' dfc-fora' : '')
         . ($selo && !$mini ? ' dfc-selado' : '');

    $h  = '<span class="' . $cls . '">';

    /* O selo só aparece em carta especial: Ouro escrito em toda carta de ouro
       é ruído, ÍCONE escrito numa só é informação. */
    if ($selo && !$mini) $h .= '<span class="dfc-selo">' . e($rot) . '</span>';
    if ($selo && $mini)  $h .= '<span class="dfc-selo-mini"></span>';

    /* ── Cabeça: OVR grande, posição grande embaixo, escudo e bandeira ── */
    $h .= '<span class="dfc-topo">';
    $h .=   '<span class="dfc-id">';
    $h .=     '<span class="dfc-ovr">' . (int)$c['ovr'] . '</span>';
    $h .=     '<span class="dfc-pos">' . e($posTxt) . ($fora ? '<i class="bi bi-exclamation-triangle-fill"></i>' : '') . '</span>';
    $h .=   '</span>';
    $h .=   '<span class="dfc-insig">';
    if ($bandeira !== '') {
        $h .= '<img class="dfc-band" src="' . e($bandeira) . '" alt="' . e((string)$c['nac']) . '" '
            . 'title="' . e((string)$c['nac']) . '" loading="lazy">';
    }
    if (!empty($c['escudo'])) {
        $h .= '<img class="dfc-esc" src="' . e((string)$c['escudo']) . '" alt="" '
            . 'title="' . e((string)$c['clube']) . '" loading="lazy">';
    }
    $h .=   '</span>';
    $h .= '</span>';

    /* ── Retrato. Sem foto, o escudo vira marca d'água: a carta continua
          cheia em vez de ter um buraco onde devia ter gente. ── */
    $h .= '<span class="dfc-retrato">';
    if (!empty($c['foto'])) {
        $h .= '<img class="dfc-foto" src="' . e((string)$c['foto']) . '" alt="" loading="lazy">';
    } elseif (!empty($c['escudo'])) {
        $h .= '<img class="dfc-marca" src="' . e((string)$c['escudo']) . '" alt="" loading="lazy">';
    } else {
        $h .= '<span class="dfc-mono">' . e(mb_substr((string)$c['nome'], 0, 1)) . '</span>';
    }
    $h .= '</span>';

    /* ── Rodapé: nome, clube e os três losangos da química ── */
    $h .= '<span class="dfc-pe">';
    $h .=   '<span class="dfc-nome">' . e($nome) . '</span>';
    if (!$mini) {
        $h .= '<span class="dfc-clube">' . e((string)$c['clube']) . '</span>';
    }
    if ($quim !== null) {
        $q = max(0, min(3, (int)$quim));
        $h .= '<span class="dfc-quim" title="Química: ' . $q . ' de 3">';
        for ($k = 1; $k <= 3; $k++) {
            $h .= '<i class="' . ($k <= $q ? 'on' : '') . '"></i>';
        }
        $h .= '</span>';
    }
    $h .= '</span>';

    return $h . '</span>';
}
