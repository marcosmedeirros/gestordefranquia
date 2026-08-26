<?php
function enc(array $t, string $pais='BRA', string $pos='ATA'): array {
    $ch=curl_init('http://localhost/games/games/copero.php');
    curl_setopt_array($ch,[CURLOPT_POST=>1,CURLOPT_RETURNTRANSFER=>1,
      CURLOPT_POSTFIELDS=>['carreira'=>json_encode(['nome'=>'T','pais'=>$pais,'posicao'=>$pos,'temporadas'=>$t])]]);
    $r=json_decode(curl_exec($ch),true); curl_close($ch);
    if(!($r['ok']??false)) return ['__erro'=>$r['erro']??'?'];
    return array_map(fn($c)=>is_array($c)?($c['id']??'?'):$c, $r['conquistas']??[]);
}
function carr(int $n, callable $m=null, array $pad=[]): array {
    $t=[]; for($i=0;$i<$n;$i++){ $x=array_replace(['idade'=>18+$i,'clube'=>'Flamengo','liga'=>'BR1',
      'ovr'=>min(95,55+$i*3),'valor'=>2e7,'jogos'=>35,'gols'=>12,'ast'=>6,'titulos'=>[]],$pad);
      if($m)$x=$m($x,$i); $t[]=$x; } return $t; }

// matagigante: continental com clube fraco. Vila Nova (BR2) tem força baixa.
$mata = carr(12, fn($x,$i)=>array_replace($x,['clube'=>'Vila Nova','liga'=>'BR2','titulos'=>$i===6?['cont']:[]]));
// yashin: goleiro com bola de ouro
$yash = carr(14, fn($x,$i)=>array_replace($x,['gols'=>0,'ast'=>0,'titulos'=>$i===8?['bola_ouro']:[]]));
// seis_conts: clubes de 5 continentes
$ligas = ['BR1','EN1','JP1','US1','EG1'];
$cinco = carr(15, fn($x,$i)=>array_replace($x,['liga'=>$GLOBALS['ligas'][$i%5],'clube'=>'C'.($i%5)]));
// completar: liga, copa, cont, mundial, copa do mundo e continental de selecao
$comp = carr(16, fn($x,$i)=>array_replace($x,['titulos'=>
  $i===5?['liga','copa','cont','mundial']:($i===8?['copa_mundo']:($i===10?['selecao_cont']:[]))]));

foreach ([['matagigante',$mata,'BRA','ATA'],['yashin',$yash,'BRA','GOL'],
          ['seis_conts',$cinco,'BRA','ATA'],['completar',$comp,'BRA','ATA']] as [$id,$t,$p,$pos]) {
    $c = enc($t,$p,$pos);
    if (isset($c['__erro'])) { printf("XX %-12s recusada: %s\n",$id,$c['__erro']); continue; }
    printf("%s %-12s %s\n", in_array($id,$c,true)?'  ':'XX', $id,
           in_array($id,$c,true)?'':'(saíram: '.implode(',',array_slice($c,0,8)).')');
}
