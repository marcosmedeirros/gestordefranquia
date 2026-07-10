<?php
// Header padrão compartilhado por TODAS as páginas dentro de /site/ (site, games, pathetic
// e cada jogo individual). Estilos com prefixo "fbanav-" pra não colidir com o CSS próprio
// de cada jogo. Basta dar include deste arquivo logo após a tag body.
?>
<style>
.fbanav-wrap {
  position: sticky; top: 0; z-index: 999;
  backdrop-filter: blur(14px);
  -webkit-backdrop-filter: blur(14px);
  background: rgba(10,10,10,0.82);
  border-bottom: 1px solid rgba(255,255,255,.08);
  font-family: "Inter", -apple-system, "Helvetica Neue", Arial, sans-serif;
}
.fbanav-inner {
  max-width: 1440px; margin: 0 auto; padding-inline: clamp(20px, 4vw, 64px);
  display: flex; align-items: center; justify-content: space-between; height: 68px; gap: 20px;
}
.fbanav-logo { display: flex; align-items: center; gap: 10px; flex-shrink: 0; }
.fbanav-logo img { height: 36px; width: auto; display: block; }
.fbanav-links { display: flex; gap: 28px; align-items: center; }
.fbanav-links a {
  font-size: 13px; font-weight: 500; letter-spacing: 0.04em; text-transform: uppercase;
  color: #a0a0a0; text-decoration: none; transition: color .2s; white-space: nowrap;
}
.fbanav-links a:hover { color: #f5f5f5; }
.fbanav-cta {
  display: inline-flex; align-items: center; gap: 8px;
  padding: 10px 18px;
  background: #E63946;
  color: white !important;
  font-weight: 600; font-size: 13px; letter-spacing: 0.08em; text-transform: uppercase;
  text-decoration: none;
  clip-path: polygon(8px 0, 100% 0, calc(100% - 8px) 100%, 0 100%);
  transition: background .2s;
  flex-shrink: 0;
}
.fbanav-cta:hover { background: #b1232f; }
@media (max-width: 880px) { .fbanav-links { display: none; } }
</style>
<nav class="fbanav-wrap">
  <div class="fbanav-inner">
    <a href="/site/site.php" class="fbanav-logo"><img src="/img/fba-logo-web.png" alt="FBA" /></a>
    <div class="fbanav-links">
      <a href="/site/site.php#about">FBA</a>
      <a href="/site/site.php#divisions">Divisões</a>
      <a href="/site/site.php#how">Como funciona</a>
      <a href="/site/gamesfba.php">Games</a>
      <a href="/site/pathetic.php">The Pathetic</a>
    </div>
    <a href="/login.php" class="fbanav-cta">Jogar</a>
  </div>
</nav>
