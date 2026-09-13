<?php
/**
 * Avatar dos jogadores sem foto real.
 *
 * Antes servia um retrato desenhado da pasta faces/ — o pessoal ria das caras.
 * Agora é um card limpo, estilo jogo: fundo nas cores do time, silhueta
 * (tom de pele estável por jogador), camisa com número e a posição. Sem cara,
 * sem risada, e o mesmo jogador sempre sai igual.
 *
 *   /face.php?id=42&name=Marcus+Walker&pos=PG&c=E4002B
 *
 * `c` é a cor do time (hex, com ou sem #). Sem ela, o card sai neutro.
 * Cache de 1 dia no browser — o avatar é 100% derivado da URL.
 */

$id   = max(1, (int) ($_GET['id'] ?? 1));
$name = trim((string) ($_GET['name'] ?? ''));
$pos  = strtoupper(trim((string) ($_GET['pos'] ?? '')));
$cor  = trim((string) ($_GET['c'] ?? ''));
$cor  = preg_match('/^#?([0-9a-fA-F]{6})$/', $cor, $m) ? '#' . strtolower($m[1]) : '#2b2f3a';
if (!in_array($pos, ['PG', 'SG', 'SF', 'PF', 'C'], true)) $pos = '';

$h = crc32($name . '|' . $id);
$skins = ['#8d5524', '#a86b3c', '#c68642', '#e0ac69', '#f1c27d', '#6b4423'];
$skin  = $skins[$h % count($skins)];
$num   = (($h >> 4) % 55) + 1; // 1..55, como número de camisa

// iniciais: primeira letra do primeiro e do último nome
$parts = preg_split('/\s+/', $name) ?: [];
$ini = strtoupper(mb_substr($parts[0] ?? 'F', 0, 1) . (count($parts) > 1 ? mb_substr(end($parts), 0, 1) : ''));

/** Escurece/clareia um hex (fator -1..1). */
$shade = function (string $hex, float $f): string {
    $r = hexdec(substr($hex, 1, 2)); $g = hexdec(substr($hex, 3, 2)); $b = hexdec(substr($hex, 5, 2));
    $mix = fn($v) => (int) round($f < 0 ? $v * (1 + $f) : $v + (255 - $v) * $f);
    return sprintf('#%02x%02x%02x', max(0, min(255, $mix($r))), max(0, min(255, $mix($g))), max(0, min(255, $mix($b))));
};
$dark  = $shade($cor, -0.55);
$light = $shade($cor, 0.25);
// contraste do texto no fundo escuro e na camisa clara
$jersey = '#f5f5f7';
$e = fn($s) => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

$svg = <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 260 260" width="260" height="260">
  <defs>
    <linearGradient id="bg" x1="0" y1="0" x2="0" y2="1">
      <stop offset="0" stop-color="{$light}"/>
      <stop offset="1" stop-color="{$dark}"/>
    </linearGradient>
    <clipPath id="clip"><rect width="260" height="260" rx="28"/></clipPath>
  </defs>
  <g clip-path="url(#clip)">
    <rect width="260" height="260" fill="url(#bg)"/>
    <circle cx="130" cy="120" r="118" fill="#000" opacity="0.14"/>
    <!-- ombros e camisa -->
    <path d="M28 272 C28 204 74 178 130 178 C186 178 232 204 232 272 Z" fill="{$jersey}"/>
    <path d="M100 178 L130 214 L160 178 C150 186 110 186 100 178 Z" fill="{$cor}"/>
    <rect x="28" y="255" width="204" height="10" fill="{$cor}" opacity="0.9"/>
    <!-- pescoço e cabeça -->
    <rect x="114" y="150" width="32" height="34" rx="10" fill="{$skin}"/>
    <circle cx="130" cy="114" r="46" fill="{$skin}"/>
    <path d="M84 110 C84 78 104 62 130 62 C156 62 176 78 176 110 C170 96 154 88 130 88 C106 88 90 96 84 110 Z" fill="#1c1c1c" opacity="0.92"/>
    <!-- número e posição -->
    <text x="130" y="244" text-anchor="middle" font-family="Barlow Condensed, Arial Narrow, Arial, sans-serif" font-size="40" font-weight="800" fill="{$cor}">{$num}</text>
    <text x="236" y="30" text-anchor="end" font-family="Arial, sans-serif" font-size="15" font-weight="800" letter-spacing="1" fill="#ffffff" opacity="0.9">{$e($pos)}</text>
    <text x="24" y="30" text-anchor="start" font-family="Arial, sans-serif" font-size="15" font-weight="800" letter-spacing="1" fill="#ffffff" opacity="0.7">{$e($ini)}</text>
  </g>
</svg>
SVG;

header('Content-Type: image/svg+xml; charset=utf-8');
header('Cache-Control: public, max-age=86400');
echo $svg;
exit;
