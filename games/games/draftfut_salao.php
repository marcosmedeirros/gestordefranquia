<?php
/**
 * ── O SALÃO DO FBA DRAFT ─────────────────────────────────────────────
 *
 * Pedido do Marcos (08/10/2026): "acho que precisa ter uma pagina inical no
 * draft com a opcao multiplayer, contra a maquina (chama de contra o bot) e o
 * top ranking de vitorias e os maiores confrotnos tb".
 *
 * Antes a primeira tela era a escolha de formação, o que empurrava todo mundo
 * pro modo contra o bot sem nem contar que existia outro. Aqui o jogo se
 * apresenta: os dois modos, quem mais ganha e os confrontos que pesaram.
 *
 * Espera $pdo, $user_id, $moedas, $vista, $duelo, $dueloFim.
 */
?>
<?php if ($vista === 'multi'): ?>

  <?php /* ── MULTIPLAYER: abrir ou entrar ──────────────────────────── */ ?>
  <div class="bloco">
    <div class="salao-topo">
      <h2>Multiplayer</h2>
      <a class="btn" href="?"><i class="bi bi-arrow-left"></i> Voltar</a>
    </div>
    <p class="sub">Você abre o duelo, manda o código, e o adversário entra. Cada um
       monta o time no seu tempo — quando os dois terminam, a partida sai sozinha
       e os dois veem a mesma narração.</p>

    <h3 class="salao-h3">Abrir um duelo</h3>
    <p class="sub">A aposta sai da sua conta agora e volta dobrada se você ganhar.
       Empate devolve a de cada um.</p>
    <div class="apostas">
      <?php foreach (DFD_APOSTAS as $v): ?>
        <form method="POST" style="margin:0">
          <input type="hidden" name="acao" value="duelo_criar">
          <input type="hidden" name="aposta" value="<?= (int)$v ?>">
          <button class="aposta-card" type="submit" <?= $moedas < $v ? 'disabled' : '' ?>>
            <b><?= (int)$v ?></b><small>moedas</small>
          </button>
        </form>
      <?php endforeach; ?>
      <?php /* OUTRO VALOR. Os cinco atalhos resolvem o caso comum; este campo
               resolve o resto, sem teto além do saldo. O `max` é só pra o
               navegador avisar antes de mandar — quem decide é o servidor. */ ?>
      <form method="POST" class="aposta-outro">
        <input type="hidden" name="acao" value="duelo_criar">
        <label for="apostaOutro">Outro valor</label>
        <input type="number" id="apostaOutro" name="aposta" min="1" max="<?= (int)$moedas ?>"
               step="1" placeholder="0" inputmode="numeric" required>
        <button class="btn pri" type="submit"><i class="bi bi-plus-lg"></i> Abrir</button>
      </form>
    </div>
    <p class="sub" style="margin:8px 0 0;font-size:12px">Você tem
       <b><?= (int)$moedas ?></b> moedas — dá pra apostar qualquer valor até isso.</p>

    <?php /* ── O CÓDIGO DIGITADO, SÓ ELE ───────────────────────────────
         Este campo era também a chegada do link: o `?cod=` vinha preenchido
         aqui e a pessoa confirmava. Desde 09/10/2026 o link ENTRA SOZINHO
         (@see draftfut.php, "O LINK ENTRA SOZINHO") e nunca mais passa por
         esta tela — quem clica já cai no duelo, e quem chega tarde recebe o
         aviso antes, no lugar de descobrir depois de apertar "Entrar".

         Então o que havia aqui de preenchimento e de "sala cheia" foi embora
         junto: era código que não rodava mais, dizendo o contrário do que o
         jogo faz. Ficou o caminho de quem recebeu as seis letras no grupo e
         prefere digitar. */ ?>
    <h3 class="salao-h3">Entrar com um código</h3>
    <form method="POST" class="entrar-cod">
      <input type="hidden" name="acao" value="duelo_entrar">
      <input type="text" name="codigo" maxlength="8" placeholder="A1B2C3" required
             autocomplete="off" spellcheck="false" autofocus>
      <button class="btn pri" type="submit"><i class="bi bi-box-arrow-in-right"></i> Entrar</button>
    </form>
    <p class="sub" style="margin-top:8px;font-size:12px">O código tem seis letras e números,
       sem I, L, O, 0 e 1 — ele é ditado no grupo, e essas se confundem.</p>
  </div>

<?php else: ?>

  <?php /* ── O SALÃO ───────────────────────────────────────────────── */ ?>
  <div class="bloco">
    <h2>FBA Draft</h2>
    <p class="sub">Monte um time escolhendo uma carta entre cinco por vaga e jogue a
       partida minuto a minuto. Química, lenda, coleção — o draft do FIFA, com os
       nomes de verdade.</p>
    <div class="modos">
      <a class="modo-card" href="?v=bot">
        <i class="bi bi-cpu"></i>
        <b>Contra o bot</b>
        <small>Time aleatório na sua faixa de força · entrada <?= DF_ENTRADA ?> moedas,
                   vitória paga <?= DF_VITORIA ?> e empate <?= DF_EMPATE ?></small>
      </a>
      <a class="modo-card" href="?v=multi">
        <i class="bi bi-people-fill"></i>
        <b>Multiplayer</b>
        <small>Abra um duelo, mande o código e aposte de <?= min(DFD_APOSTAS) ?> a <?= max(DFD_APOSTAS) ?> moedas</small>
      </a>
    </div>
  </div>

  <?php
  /* O ranking e os confrontos são leitura, e leitura que pode falhar sem
     levar a página junto: se a consulta estourar, o salão continua jogável. */
  $rank = [];
  $confrontos = [];
  try { $rank = dfdRankingVitorias($pdo, 10); } catch (Throwable $e) { error_log('[draftfut] ranking: ' . $e->getMessage()); }
  try { $confrontos = dfdMaioresConfrontos($pdo, 6); } catch (Throwable $e) { error_log('[draftfut] confrontos: ' . $e->getMessage()); }
  ?>

  <div class="bloco">
    <h2><i class="bi bi-trophy-fill" style="color:var(--amarelo)"></i> Top vitórias</h2>
    <p class="sub">Soma o que cada GM fez contra o bot e contra gente.</p>
    <?php if (!$rank): ?>
      <p class="sub" style="margin:0">Ninguém jogou ainda. Seja o primeiro.</p>
    <?php else: ?>
      <div class="rank-lista">
        <?php foreach ($rank as $i => $r): ?>
          <div class="rank-l<?= (int)$r['uid'] === $user_id ? ' eu' : '' ?>">
            <span class="rk-pos"><?= $i + 1 ?></span>
            <span class="rk-gm"><?= e($r['gm']) ?></span>
            <span class="rk-nums">
              <b><?= (int)$r['v'] ?></b>V · <?= (int)$r['e'] ?>E · <?= (int)$r['d'] ?>D
              <?php if ((int)$r['duelos']): ?>
                <small><?= (int)$r['duelos'] ?> duelo<?= (int)$r['duelos'] > 1 ? 's' : '' ?></small>
              <?php endif; ?>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php $topForca = []; try { $topForca = dfdTopForca($pdo, 5); } catch (Throwable $e) { error_log('[draftfut] top forca: ' . $e->getMessage()); } ?>
  <div class="bloco">
    <h2><i class="bi bi-lightning-charge-fill" style="color:var(--amarelo)"></i> Times mais fortes</h2>
    <p class="sub">Os cinco melhores drafts já montados, de qualquer modo — contra o bot
       ou em duelo.</p>
    <?php if (!$topForca): ?>
      <p class="sub" style="margin:0">Nenhum time montado ainda.</p>
    <?php else: ?>
      <div class="rank-lista">
        <?php foreach ($topForca as $i => $t): ?>
          <div class="rank-l<?= (int)$t['uid'] === $user_id ? ' eu' : '' ?>">
            <span class="rk-pos"><?= $i + 1 ?></span>
            <span class="rk-gm"><?= e((string)($t['nome'] ?: 'Time sem nome')) ?></span>
            <span class="rk-nums">
              <b><?= (int)$t['forca'] ?></b> força · <?= (int)$t['quimica'] ?> quím.
              <small><?= $t['modo'] === 'duelo' ? 'duelo' : 'bot' ?></small>
            </span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="bloco">
    <h2><i class="bi bi-fire" style="color:var(--vermelho)"></i> Maiores confrontos</h2>
    <p class="sub">Os duelos de força somada mais alta — um 1x0 entre dois times de 90
       pesa mais que um 5x4 entre dois de 76.</p>
    <?php if (!$confrontos): ?>
      <p class="sub" style="margin:0">Nenhum duelo concluído ainda.</p>
    <?php else: ?>
      <div class="confrontos">
        <?php foreach ($confrontos as $c): ?>
          <div class="conf-l">
            <span class="cf-time<?= (int)$c['id_vencedor'] === (int)$c['id_criador'] ? ' venceu' : '' ?>">
              <?= e((string)($c['nome_criador'] ?: 'Criador')) ?>
              <small><?= (int)$c['forca_criador'] ?></small>
            </span>
            <span class="cf-placar"><?= (int)$c['gols_criador'] ?> <i>x</i> <?= (int)$c['gols_desafiado'] ?></span>
            <span class="cf-time<?= (int)$c['id_vencedor'] === (int)$c['id_desafiado'] ? ' venceu' : '' ?>">
              <?= e((string)($c['nome_desafiado'] ?: 'Desafiado')) ?>
              <small><?= (int)$c['forca_desafiado'] ?></small>
            </span>
            <span class="cf-peso"><?= (int)$c['peso'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

<?php endif; ?>
