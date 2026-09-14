<?php
require_once __DIR__ . '/League.php';

/**
 * A próxima jogada do GM — o que o botão "Próxima" do dock mostra em toda tela.
 *
 * Uma regra só, em ordem de prioridade: o que trava a liga vem primeiro
 * (demissão, folha acima do teto na hora de passar de fase), depois o que
 * espera resposta (decisões recentes), o guia do save novo e, por fim, o passo
 * normal da fase (jogar, avançar, sortear, draftar, concluir a janela...).
 *
 * Cada passo traz um rótulo curto pro botão, uma frase dizendo o porquê e,
 * quando faz sentido, atalhos ("alts": simular a semana, pular a pré...).
 * "page" + "here": quando o GM já está na tela onde a ação acontece, o botão
 * troca o destino por uma âncora na própria página (ex.: a lista do draft).
 */
final class NextStep
{
    public static function resolve(string $page = 'home'): array
    {
        $phase = League::phase();
        $gmId  = (int) League::gmTeam();
        $s = self::pick($phase, $gmId);
        if ($s['clock'] === '') $s['clock'] = self::clock($phase);
        if ($s['kicker'] === '') $s['kicker'] = self::phaseName($phase);

        // Decisão velha não toma o botão, mas continua a um toque de distância.
        if ($gmId > 0 && !in_array($s['key'], ['decisions', 'fired', 'team'], true)) {
            $pending = count(League::pendingDecisions());
            if ($pending > 0) {
                $s['alts'][] = ['label' => $pending === 1 ? 'Decisão pendente' : "$pending decisões pendentes",
                                'href' => url('inbox') . '#decisoes', 'icon' => 'envelope'];
            }
        }

        if ($s['page'] !== '' && $s['page'] === $page && is_array($s['here'])) {
            $s = array_merge($s, $s['here']);
        }
        return $s;
    }

    public static function phaseName(string $phase): string
    {
        return [
            'regular' => 'Temporada regular', 'playin' => 'Play-in', 'playoffs' => 'Playoffs',
            'lottery' => 'Loteria do draft', 'draft' => 'Draft', 'freeagency' => 'Free agency',
            'preseason' => 'Pré-temporada', 'offseason' => 'Entressafra',
        ][$phase] ?? ucfirst($phase);
    }

    private static function pick(string $phase, int $gmId): array
    {
        if ($gmId <= 0) {
            return self::step('team', 'Escolher franquia', url('gmselect'), 'Escolha o time que você vai comandar.', 'people-fill');
        }
        if (League::isFired()) {
            return self::step('fired', 'Nova franquia', url('gmselect'),
                'Você foi demitido. A liga só continua quando você assumir outro time.', 'briefcase-fill',
                ['tone' => 'alert', 'kicker' => 'Demitido', 'page' => 'gmselect',
                 'here' => ['label' => 'Escolha abaixo', 'href' => '#franquias', 'hint' => 'Toque no time que você quer assumir.']]);
        }
        $day = League::currentDay();

        // Folha acima do teto trava a Trade Deadline (fora da carência).
        if ($phase === 'regular' && $day === Cap::deadlineDay() && Cap::gmBlockMessage('passar da deadline')) {
            return self::capBlock('Você está %s acima do teto. Troque ou dispense para passar da deadline.', 'Deadline travada');
        }

        // Decisões esperando resposta: responder é rápido e muda o vestiário.
        // Só as recentes tomam o botão; as velhas viram atalho em resolve().
        $dec = array_values(array_filter(League::pendingDecisions(), fn($d) => self::isCurrentDecision($d, $phase, $day)));
        if ($dec) {
            $n = count($dec);
            $next = self::phaseStep($phase, $gmId, $day);
            $alt = ['label' => 'Pular e ' . self::lcfirstMb($next['label']), 'href' => $next['href'], 'icon' => $next['icon'],
                    'busy' => $next['busy'], 'confirm' => $next['confirm']];
            return self::step('decisions', $n === 1 ? 'Responder decisão' : "Responder $n decisões", url('inbox') . '#decisoes',
                (string) $dec[0]['title'], 'envelope-exclamation-fill',
                ['kicker' => $n === 1 ? 'Decisão pendente' : 'Decisões pendentes', 'clock' => $next['clock'],
                 'alts' => array_merge([$alt], $next['alts']),
                 'page' => 'inbox', 'here' => ['label' => 'Responda abaixo', 'href' => '#decisoes']]);
        }

        // Guia do save novo: três passos, só na primeira pré-temporada.
        if ($phase === 'preseason' && League::season() <= 1 && Database::meta('guide_done') !== '1') {
            $skip = ['label' => 'Pular o guia', 'href' => url('home', ['action' => 'guide-skip']), 'icon' => 'x-lg'];
            if (Database::meta('guide_lineup') !== '1') {
                return self::step('guide-1', 'Conhecer o elenco', url('lineup'),
                    'Veja seus jogadores, quem é titular e quantos minutos cada um joga.', 'people-fill',
                    ['kicker' => 'Primeiros passos · 1 de 3', 'alts' => [$skip]]);
            }
            if (Database::meta('guide_cap') !== '1') {
                return self::step('guide-2', 'Ver a folha salarial', url('cap'),
                    'Confira quanto seu elenco custa e quanto espaço sobra no teto.', 'cash-coin',
                    ['kicker' => 'Primeiros passos · 2 de 3', 'alts' => [$skip]]);
            }
            $s = self::phaseStep($phase, $gmId, $day);
            $s['kicker'] = 'Primeiros passos · 3 de 3';
            $s['hint'] = 'Avance a pré-temporada: a cada dia a liga negocia e sua caixa de entrada recebe as novidades.';
            return $s;
        }

        return self::phaseStep($phase, $gmId, $day);
    }

    /**
     * Decisão ainda "quente": da temporada atual, feita nos últimos 7 dias de
     * jogo. Proposta de troca vence na deadline (é o que o texto dela promete).
     * Fora da temporada (loteria, draft, free agency, entressafra) nenhuma
     * decisão antiga segura o botão — o evento da fase vem primeiro.
     */
    private static function isCurrentDecision(array $d, string $phase, int $day): bool
    {
        if ((int) $d['season'] !== League::season()) return false;
        if ($phase === 'preseason') return true;
        if (!in_array($phase, ['regular', 'playin', 'playoffs'], true)) return false;
        if (($d['type'] ?? '') === 'trade_offer' && ($phase !== 'regular' || $day > Cap::deadlineDay())) return false;
        return $day - (int) $d['day'] <= 7;
    }

    private static function phaseStep(string $phase, int $gmId, int $day): array
    {
        if (in_array($phase, ['regular', 'playin', 'playoffs'], true)) {
            $gg = League::gmGameOnDay($day);
            if ($gg && empty($gg['played'])) {
                $gmAbbr = (string) (League::team($gmId)['abbr'] ?? '');
                $home = (string) ($gg['home_abbr'] ?? '') === $gmAbbr;
                $opp = $home ? (string) $gg['away_abbr'] : (string) $gg['home_abbr'];
                $series = $phase === 'playoffs' ? League::seriesStatus((int) ($gg['series_id'] ?? 0)) : null;
                return self::step('play', ($home ? 'Jogar vs ' : 'Jogar @ ') . $opp, url('game', ['id' => $gg['id'], 'live' => 1]),
                    $series && !empty($series['label']) ? (string) $series['label'] : 'Seu jogo de hoje: comande ao vivo ou simule.',
                    'play-fill',
                    ['alts' => array_merge([[
                        'label' => 'Simular meu jogo', 'href' => url('home', ['action' => 'sim-game-ai', 'id' => $gg['id']]),
                        'icon' => 'lightning-charge-fill', 'busy' => 'Simulando seu jogo',
                    ]], self::shortcuts($phase))]);
            }
            $label = $phase === 'regular' ? 'Avançar o dia' : ($phase === 'playin' ? 'Avançar o play-in' : 'Avançar os playoffs');
            return self::step('advance', $label, url('home', ['action' => 'advance']), self::advanceHint($phase, $gmId, $day),
                'fast-forward-fill', ['busy' => 'Simulando o dia', 'alts' => self::shortcuts($phase)]);
        }

        if ($phase === 'preseason') {
            $pd = League::preseasonDay();
            $tot = League::PRESEASON_DAYS;
            if ($pd >= $tot && Cap::gmBlockMessage('começar a temporada')) {
                return self::capBlock('Você está %s acima do teto. A temporada só começa com a folha dentro do teto.', 'Temporada travada');
            }
            $alts = $pd >= $tot ? [] : [[
                'label' => 'Pular a pré-temporada', 'href' => url('home', ['action' => 'preseason-finish']), 'icon' => 'skip-end-fill',
                'busy' => 'Encerrando a pré-temporada',
                'confirm' => ['text' => 'Encerrar a pré-temporada e começar a temporada agora?', 'title' => 'Pré-temporada', 'ok' => 'Começar'],
            ]];
            return self::step('preseason', $pd >= $tot ? 'Começar a temporada' : 'Avançar o dia', url('home', ['action' => 'preseason-advance']),
                $pd >= $tot ? 'Último dia da pré-temporada: a temporada regular começa agora.'
                            : "Dia $pd de $tot. A cada dia a liga negocia, contrata e manda notícias.",
                'fast-forward-fill', ['busy' => 'Avançando a pré-temporada', 'alts' => $alts]);
        }

        if ($phase === 'lottery') {
            return self::step('lottery', 'Sortear a loteria', url('lottery'),
                'O sorteio define a ordem das primeiras escolhas do draft.', 'dice-5-fill',
                ['page' => 'lottery', 'here' => ['label' => 'Sorteie abaixo', 'href' => '#loteria',
                 'hint' => 'Revele as posições e depois abra o draft.']]);
        }

        if ($phase === 'draft') {
            require_once __DIR__ . '/Offseason.php';
            $st = Offseason::draftState();
            $n = (int) $st['pick'] + 1;
            if (!empty($st['is_user'])) {
                return self::step('draft', "Escolher a pick #$n", url('draftroom'),
                    'Você está na vez no draft. Escolha um calouro.', 'mortarboard-fill',
                    ['page' => 'draftroom', 'here' => ['label' => 'Escolha na lista', 'href' => '#board',
                     'hint' => 'Toque em Draftar no calouro que você quer levar.']]);
            }
            return self::step('draft', 'Ir para o draft', url('draftroom'), 'O draft está em andamento.', 'mortarboard-fill');
        }

        if ($phase === 'freeagency') {
            if (Cap::gmBlockMessage('começar a temporada')) {
                return self::capBlock('Você está %s acima do teto. A temporada só começa com a folha dentro do teto.', 'Temporada travada');
            }
            return self::step('fa', 'Concluir a free agency', url('home', ['action' => 'finish-fa']),
                'Contrate quem quiser antes. Ao concluir, a liga completa os elencos e a temporada começa.', 'check2-circle',
                ['busy' => 'Montando os elencos',
                 'confirm' => ['text' => 'Encerrar a free agency e começar a temporada?', 'title' => 'Free agency', 'ok' => 'Começar temporada'],
                 'alts' => [['label' => 'Ver agentes livres', 'href' => url('freeagency'), 'icon' => 'person-plus-fill']]]);
        }

        if ($phase === 'offseason') {
            if (Database::meta('champion_id') && Database::meta('awards_seen') !== (string) League::season()) {
                return self::step('awards', 'Ver a premiação', url('awards'),
                    'O campeão, o MVP e todos os prêmios da temporada.', 'trophy-fill');
            }
            return self::step('next-season', 'Próxima temporada', url('home', ['action' => 'next-season']),
                'Entressafra: evolução dos jogadores, aposentadorias, loteria, draft e free agency.', 'arrow-repeat',
                ['busy' => 'Rodando a entressafra',
                 'confirm' => ['text' => 'Rodar a entressafra e começar a próxima temporada?', 'title' => 'Entressafra', 'ok' => 'Começar']]);
        }

        return self::step('home', 'Ir para a central', url('home'), '', 'house-door-fill');
    }

    /** Folha travando a liga: o botão fica vermelho e leva pra Folha & Cap. */
    private static function capBlock(string $hintFmt, string $kicker): array
    {
        $c = Cap::gmCompliance();
        return self::step('cap', 'Regularizar a folha', url('cap'),
            sprintf($hintFmt, Cap::m((int) ($c['excess'] ?? 0))), 'exclamation-octagon-fill',
            ['tone' => 'alert', 'kicker' => $kicker, 'page' => 'cap',
             'here' => ['label' => 'Ajuste abaixo', 'href' => '#folha', 'hint' => 'Dispense ou negocie jogadores até ficar dentro do teto.']]);
    }

    private static function advanceHint(string $phase, int $gmId, int $day): string
    {
        $parts = [];
        $up = League::upcomingGames($gmId, 1);
        $next = $up[0] ?? null;
        if ($next) {
            $parts[] = 'Próximo jogo: ' . (!empty($next['is_home']) ? 'vs ' : '@ ') . ($next['opp_abbr'] ?? '') . ' · ' . League::dateLabel((int) $next['day']);
        } elseif ($phase !== 'regular') {
            $parts[] = 'Seu time não joga nesta data.';
        }
        if ($phase === 'regular') {
            $dl = Cap::deadlineDay();
            if ($day < $dl) $parts[] = 'deadline em ' . ($dl - $day) . ($dl - $day === 1 ? ' dia' : ' dias');
        }
        return $parts ? implode(' · ', $parts) : 'Siga para a próxima data.';
    }

    private static function shortcuts(string $phase): array
    {
        if ($phase === 'regular') {
            return [
                ['label' => 'Simular 1 semana', 'href' => url('home', ['action' => 'sim-days', 'n' => 7]), 'icon' => 'calendar-week',
                 'busy' => 'Simulando a semana',
                 'confirm' => ['text' => 'Simular 7 dias? Seus jogos serão simulados automaticamente.', 'title' => 'Simular semana', 'ok' => 'Simular']],
                ['label' => 'Simular até os playoffs', 'href' => url('home', ['action' => 'sim-season']), 'icon' => 'skip-end-fill',
                 'busy' => 'Simulando a temporada',
                 'confirm' => ['text' => 'Simular toda a temporada regular de uma vez? Seus jogos serão simulados automaticamente.', 'title' => 'Simular temporada', 'ok' => 'Simular']],
            ];
        }
        if ($phase === 'playin' || $phase === 'playoffs') {
            return [
                ['label' => 'Simular a rodada', 'href' => url('home', ['action' => 'sim-round']), 'icon' => 'skip-end-fill',
                 'busy' => 'Simulando a rodada',
                 'confirm' => ['text' => 'Simular todos os jogos desta rodada, inclusive os seus?', 'title' => 'Simular rodada', 'ok' => 'Simular']],
            ];
        }
        return [];
    }

    private static function clock(string $phase): string
    {
        if ($phase === 'regular') return 'Dia ' . League::currentDay() . '/' . (League::totalDays() ?: 82);
        if ($phase === 'preseason') return 'Dia ' . League::preseasonDay() . '/' . League::PRESEASON_DAYS;
        return '';
    }

    /** "Jogar vs UTA" → "jogar vs UTA" (só a primeira letra; siglas ficam). */
    private static function lcfirstMb(string $s): string
    {
        return mb_strtolower(mb_substr($s, 0, 1)) . mb_substr($s, 1);
    }

    private static function step(string $key, string $label, string $href, string $hint, string $icon, array $o = []): array
    {
        return array_merge([
            'key' => $key, 'label' => $label, 'href' => $href, 'hint' => $hint, 'icon' => $icon,
            'tone' => 'go', 'kicker' => '', 'clock' => '', 'busy' => '', 'confirm' => null,
            'alts' => [], 'page' => '', 'here' => null,
        ], $o);
    }
}
