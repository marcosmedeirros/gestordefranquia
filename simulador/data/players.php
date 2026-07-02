<?php
/**
 * Jogadores reais da NBA (núcleo de cada elenco) com atributos estilo 2K.
 * Atributos 0-99: ovr(geral) ins(interior) mid(meia) thr(3pts) pmk(passe) reb(rebote) def(defesa) ath(atletismo)
 * pos: PG/SG/SF/PF/C
 * O instalador completa cada elenco com role players gerados até 13 jogadores.
 *
 * Os elencos refletem aproximadamente a temporada base da NBA e podem ser editados aqui.
 */
return [
 'BOS'=>[
  ['name'=>'Jayson Tatum','pos'=>'SF','age'=>26,'ht'=>203,'ovr'=>95,'ins'=>85,'mid'=>88,'thr'=>87,'pmk'=>82,'reb'=>80,'def'=>85,'ath'=>88],
  ['name'=>'Jaylen Brown','pos'=>'SG','age'=>28,'ht'=>198,'ovr'=>90,'ins'=>86,'mid'=>83,'thr'=>80,'pmk'=>74,'reb'=>72,'def'=>84,'ath'=>90],
  ['name'=>'Jrue Holiday','pos'=>'PG','age'=>34,'ht'=>193,'ovr'=>85,'ins'=>78,'mid'=>80,'thr'=>82,'pmk'=>84,'reb'=>70,'def'=>90,'ath'=>80],
  ['name'=>'Kristaps Porzingis','pos'=>'C','age'=>29,'ht'=>221,'ovr'=>86,'ins'=>84,'mid'=>82,'thr'=>83,'pmk'=>62,'reb'=>84,'def'=>86,'ath'=>78],
  ['name'=>'Derrick White','pos'=>'SG','age'=>30,'ht'=>193,'ovr'=>84,'ins'=>74,'mid'=>80,'thr'=>83,'pmk'=>80,'reb'=>66,'def'=>86,'ath'=>80],
  ['name'=>'Al Horford','pos'=>'C','age'=>38,'ht'=>206,'ovr'=>80,'ins'=>76,'mid'=>76,'thr'=>80,'pmk'=>72,'reb'=>80,'def'=>83,'ath'=>66],
 ],
 'NYK'=>[
  ['name'=>'Jalen Brunson','pos'=>'PG','age'=>28,'ht'=>188,'ovr'=>91,'ins'=>84,'mid'=>88,'thr'=>83,'pmk'=>86,'reb'=>64,'def'=>74,'ath'=>78],
  ['name'=>'Julius Randle','pos'=>'PF','age'=>30,'ht'=>203,'ovr'=>86,'ins'=>86,'mid'=>80,'thr'=>78,'pmk'=>78,'reb'=>85,'def'=>76,'ath'=>82],
  ['name'=>'Mikal Bridges','pos'=>'SF','age'=>28,'ht'=>198,'ovr'=>85,'ins'=>78,'mid'=>82,'thr'=>83,'pmk'=>74,'reb'=>68,'def'=>87,'ath'=>85],
  ['name'=>'OG Anunoby','pos'=>'SF','age'=>27,'ht'=>201,'ovr'=>84,'ins'=>78,'mid'=>78,'thr'=>82,'pmk'=>64,'reb'=>72,'def'=>89,'ath'=>86],
  ['name'=>'Karl-Anthony Towns','pos'=>'C','age'=>29,'ht'=>213,'ovr'=>88,'ins'=>86,'mid'=>84,'thr'=>85,'pmk'=>70,'reb'=>88,'def'=>78,'ath'=>80],
  ['name'=>'Josh Hart','pos'=>'SG','age'=>29,'ht'=>193,'ovr'=>81,'ins'=>76,'mid'=>72,'thr'=>74,'pmk'=>78,'reb'=>84,'def'=>82,'ath'=>82],
 ],
 'PHI'=>[
  ['name'=>'Joel Embiid','pos'=>'C','age'=>30,'ht'=>213,'ovr'=>95,'ins'=>92,'mid'=>88,'thr'=>82,'pmk'=>78,'reb'=>90,'def'=>88,'ath'=>82],
  ['name'=>'Tyrese Maxey','pos'=>'PG','age'=>24,'ht'=>188,'ovr'=>89,'ins'=>82,'mid'=>84,'thr'=>85,'pmk'=>84,'reb'=>62,'def'=>74,'ath'=>88],
  ['name'=>'Paul George','pos'=>'SF','age'=>34,'ht'=>203,'ovr'=>88,'ins'=>80,'mid'=>85,'thr'=>85,'pmk'=>80,'reb'=>74,'def'=>85,'ath'=>82],
  ['name'=>'Kelly Oubre Jr.','pos'=>'SF','age'=>29,'ht'=>198,'ovr'=>79,'ins'=>78,'mid'=>74,'thr'=>76,'pmk'=>62,'reb'=>72,'def'=>78,'ath'=>86],
 ],
 'BKN'=>[
  ['name'=>'Cam Thomas','pos'=>'SG','age'=>23,'ht'=>191,'ovr'=>82,'ins'=>80,'mid'=>82,'thr'=>80,'pmk'=>70,'reb'=>60,'def'=>66,'ath'=>82],
  ['name'=>'Nic Claxton','pos'=>'C','age'=>25,'ht'=>211,'ovr'=>82,'ins'=>82,'mid'=>66,'thr'=>50,'pmk'=>64,'reb'=>84,'def'=>86,'ath'=>88],
  ['name'=>'Dennis Schroder','pos'=>'PG','age'=>31,'ht'=>185,'ovr'=>80,'ins'=>76,'mid'=>78,'thr'=>76,'pmk'=>82,'reb'=>58,'def'=>74,'ath'=>82],
  ['name'=>'Cameron Johnson','pos'=>'SF','age'=>28,'ht'=>203,'ovr'=>80,'ins'=>72,'mid'=>78,'thr'=>84,'pmk'=>66,'reb'=>70,'def'=>74,'ath'=>78],
 ],
 'TOR'=>[
  ['name'=>'Scottie Barnes','pos'=>'SF','age'=>23,'ht'=>203,'ovr'=>86,'ins'=>82,'mid'=>78,'thr'=>74,'pmk'=>82,'reb'=>82,'def'=>84,'ath'=>88],
  ['name'=>'RJ Barrett','pos'=>'SG','age'=>24,'ht'=>198,'ovr'=>83,'ins'=>82,'mid'=>78,'thr'=>76,'pmk'=>74,'reb'=>70,'def'=>72,'ath'=>84],
  ['name'=>'Immanuel Quickley','pos'=>'PG','age'=>25,'ht'=>188,'ovr'=>82,'ins'=>74,'mid'=>80,'thr'=>82,'pmk'=>80,'reb'=>62,'def'=>76,'ath'=>82],
  ['name'=>'Jakob Poeltl','pos'=>'C','age'=>29,'ht'=>216,'ovr'=>81,'ins'=>82,'mid'=>62,'thr'=>40,'pmk'=>68,'reb'=>84,'def'=>84,'ath'=>74],
 ],
 'CHI'=>[
  ['name'=>'Zach LaVine','pos'=>'SG','age'=>29,'ht'=>196,'ovr'=>85,'ins'=>82,'mid'=>84,'thr'=>84,'pmk'=>74,'reb'=>66,'def'=>68,'ath'=>92],
  ['name'=>'Nikola Vucevic','pos'=>'C','age'=>34,'ht'=>208,'ovr'=>83,'ins'=>82,'mid'=>82,'thr'=>78,'pmk'=>72,'reb'=>86,'def'=>72,'ath'=>66],
  ['name'=>'Coby White','pos'=>'PG','age'=>24,'ht'=>196,'ovr'=>82,'ins'=>76,'mid'=>80,'thr'=>82,'pmk'=>78,'reb'=>62,'def'=>68,'ath'=>84],
  ['name'=>'Josh Giddey','pos'=>'PG','age'=>22,'ht'=>203,'ovr'=>81,'ins'=>76,'mid'=>72,'thr'=>72,'pmk'=>86,'reb'=>80,'def'=>70,'ath'=>78],
 ],
 'CLE'=>[
  ['name'=>'Donovan Mitchell','pos'=>'SG','age'=>28,'ht'=>185,'ovr'=>91,'ins'=>84,'mid'=>86,'thr'=>86,'pmk'=>80,'reb'=>68,'def'=>76,'ath'=>90],
  ['name'=>'Darius Garland','pos'=>'PG','age'=>25,'ht'=>185,'ovr'=>86,'ins'=>78,'mid'=>82,'thr'=>84,'pmk'=>88,'reb'=>58,'def'=>70,'ath'=>80],
  ['name'=>'Evan Mobley','pos'=>'PF','age'=>23,'ht'=>211,'ovr'=>87,'ins'=>84,'mid'=>76,'thr'=>70,'pmk'=>72,'reb'=>86,'def'=>90,'ath'=>86],
  ['name'=>'Jarrett Allen','pos'=>'C','age'=>26,'ht'=>208,'ovr'=>84,'ins'=>84,'mid'=>62,'thr'=>40,'pmk'=>64,'reb'=>88,'def'=>84,'ath'=>84],
 ],
 'DET'=>[
  ['name'=>'Cade Cunningham','pos'=>'PG','age'=>23,'ht'=>198,'ovr'=>87,'ins'=>82,'mid'=>82,'thr'=>80,'pmk'=>88,'reb'=>74,'def'=>74,'ath'=>80],
  ['name'=>'Jaden Ivey','pos'=>'SG','age'=>22,'ht'=>193,'ovr'=>81,'ins'=>80,'mid'=>76,'thr'=>78,'pmk'=>74,'reb'=>64,'def'=>70,'ath'=>90],
  ['name'=>'Jalen Duren','pos'=>'C','age'=>21,'ht'=>206,'ovr'=>81,'ins'=>84,'mid'=>58,'thr'=>40,'pmk'=>62,'reb'=>86,'def'=>78,'ath'=>86],
  ['name'=>'Tobias Harris','pos'=>'PF','age'=>32,'ht'=>203,'ovr'=>80,'ins'=>80,'mid'=>80,'thr'=>78,'pmk'=>70,'reb'=>76,'def'=>74,'ath'=>74],
 ],
 'IND'=>[
  ['name'=>'Tyrese Haliburton','pos'=>'PG','age'=>24,'ht'=>196,'ovr'=>90,'ins'=>78,'mid'=>84,'thr'=>86,'pmk'=>94,'reb'=>66,'def'=>72,'ath'=>82],
  ['name'=>'Pascal Siakam','pos'=>'PF','age'=>30,'ht'=>203,'ovr'=>88,'ins'=>86,'mid'=>82,'thr'=>76,'pmk'=>78,'reb'=>82,'def'=>80,'ath'=>86],
  ['name'=>'Myles Turner','pos'=>'C','age'=>28,'ht'=>211,'ovr'=>83,'ins'=>80,'mid'=>76,'thr'=>80,'pmk'=>62,'reb'=>82,'def'=>86,'ath'=>78],
  ['name'=>'Bennedict Mathurin','pos'=>'SG','age'=>22,'ht'=>196,'ovr'=>81,'ins'=>80,'mid'=>78,'thr'=>78,'pmk'=>66,'reb'=>70,'def'=>68,'ath'=>86],
 ],
 'MIL'=>[
  ['name'=>'Giannis Antetokounmpo','pos'=>'PF','age'=>30,'ht'=>211,'ovr'=>96,'ins'=>96,'mid'=>80,'thr'=>68,'pmk'=>82,'reb'=>90,'def'=>90,'ath'=>97],
  ['name'=>'Damian Lillard','pos'=>'PG','age'=>34,'ht'=>188,'ovr'=>90,'ins'=>80,'mid'=>86,'thr'=>90,'pmk'=>86,'reb'=>62,'def'=>66,'ath'=>80],
  ['name'=>'Brook Lopez','pos'=>'C','age'=>36,'ht'=>213,'ovr'=>82,'ins'=>80,'mid'=>74,'thr'=>80,'pmk'=>58,'reb'=>78,'def'=>86,'ath'=>64],
  ['name'=>'Khris Middleton','pos'=>'SF','age'=>33,'ht'=>201,'ovr'=>83,'ins'=>78,'mid'=>86,'thr'=>82,'pmk'=>80,'reb'=>72,'def'=>76,'ath'=>72],
 ],
 'ATL'=>[
  ['name'=>'Trae Young','pos'=>'PG','age'=>26,'ht'=>185,'ovr'=>88,'ins'=>76,'mid'=>84,'thr'=>86,'pmk'=>94,'reb'=>58,'def'=>62,'ath'=>78],
  ['name'=>'Jalen Johnson','pos'=>'PF','age'=>23,'ht'=>203,'ovr'=>84,'ins'=>84,'mid'=>74,'thr'=>72,'pmk'=>78,'reb'=>84,'def'=>80,'ath'=>90],
  ['name'=>'Dejounte Murray','pos'=>'SG','age'=>28,'ht'=>196,'ovr'=>85,'ins'=>80,'mid'=>82,'thr'=>80,'pmk'=>84,'reb'=>76,'def'=>82,'ath'=>84],
  ['name'=>'Clint Capela','pos'=>'C','age'=>30,'ht'=>208,'ovr'=>80,'ins'=>82,'mid'=>56,'thr'=>40,'pmk'=>56,'reb'=>86,'def'=>80,'ath'=>82],
 ],
 'CHA'=>[
  ['name'=>'LaMelo Ball','pos'=>'PG','age'=>23,'ht'=>198,'ovr'=>86,'ins'=>76,'mid'=>80,'thr'=>82,'pmk'=>90,'reb'=>72,'def'=>66,'ath'=>84],
  ['name'=>'Brandon Miller','pos'=>'SF','age'=>22,'ht'=>206,'ovr'=>83,'ins'=>78,'mid'=>80,'thr'=>82,'pmk'=>72,'reb'=>72,'def'=>74,'ath'=>84],
  ['name'=>'Miles Bridges','pos'=>'PF','age'=>26,'ht'=>198,'ovr'=>81,'ins'=>82,'mid'=>76,'thr'=>76,'pmk'=>68,'reb'=>76,'def'=>72,'ath'=>88],
 ],
 'MIA'=>[
  ['name'=>'Jimmy Butler','pos'=>'SF','age'=>35,'ht'=>201,'ovr'=>89,'ins'=>86,'mid'=>82,'thr'=>74,'pmk'=>82,'reb'=>76,'def'=>88,'ath'=>82],
  ['name'=>'Bam Adebayo','pos'=>'C','age'=>27,'ht'=>206,'ovr'=>87,'ins'=>84,'mid'=>80,'thr'=>62,'pmk'=>76,'reb'=>86,'def'=>90,'ath'=>86],
  ['name'=>'Tyler Herro','pos'=>'SG','age'=>25,'ht'=>196,'ovr'=>84,'ins'=>78,'mid'=>84,'thr'=>86,'pmk'=>76,'reb'=>66,'def'=>66,'ath'=>78],
 ],
 'ORL'=>[
  ['name'=>'Paolo Banchero','pos'=>'PF','age'=>22,'ht'=>208,'ovr'=>88,'ins'=>86,'mid'=>82,'thr'=>76,'pmk'=>80,'reb'=>82,'def'=>78,'ath'=>86],
  ['name'=>'Franz Wagner','pos'=>'SF','age'=>23,'ht'=>206,'ovr'=>86,'ins'=>84,'mid'=>80,'thr'=>78,'pmk'=>80,'reb'=>76,'def'=>82,'ath'=>84],
  ['name'=>'Jalen Suggs','pos'=>'PG','age'=>23,'ht'=>196,'ovr'=>82,'ins'=>76,'mid'=>78,'thr'=>78,'pmk'=>78,'reb'=>66,'def'=>86,'ath'=>86],
  ['name'=>'Wendell Carter Jr.','pos'=>'C','age'=>25,'ht'=>208,'ovr'=>79,'ins'=>80,'mid'=>70,'thr'=>68,'pmk'=>62,'reb'=>84,'def'=>78,'ath'=>74],
 ],
 'WAS'=>[
  ['name'=>'Jordan Poole','pos'=>'SG','age'=>25,'ht'=>193,'ovr'=>80,'ins'=>76,'mid'=>80,'thr'=>82,'pmk'=>76,'reb'=>60,'def'=>62,'ath'=>82],
  ['name'=>'Kyle Kuzma','pos'=>'PF','age'=>29,'ht'=>206,'ovr'=>81,'ins'=>80,'mid'=>78,'thr'=>76,'pmk'=>70,'reb'=>76,'def'=>70,'ath'=>80],
  ['name'=>'Bilal Coulibaly','pos'=>'SG','age'=>20,'ht'=>201,'ovr'=>78,'ins'=>74,'mid'=>72,'thr'=>72,'pmk'=>68,'reb'=>70,'def'=>82,'ath'=>88],
 ],
 'DEN'=>[
  ['name'=>'Nikola Jokic','pos'=>'C','age'=>29,'ht'=>211,'ovr'=>98,'ins'=>92,'mid'=>88,'thr'=>82,'pmk'=>96,'reb'=>92,'def'=>80,'ath'=>72],
  ['name'=>'Jamal Murray','pos'=>'PG','age'=>27,'ht'=>188,'ovr'=>86,'ins'=>80,'mid'=>84,'thr'=>84,'pmk'=>84,'reb'=>64,'def'=>72,'ath'=>80],
  ['name'=>'Aaron Gordon','pos'=>'PF','age'=>29,'ht'=>203,'ovr'=>84,'ins'=>84,'mid'=>74,'thr'=>74,'pmk'=>72,'reb'=>80,'def'=>84,'ath'=>92],
  ['name'=>'Michael Porter Jr.','pos'=>'SF','age'=>26,'ht'=>208,'ovr'=>83,'ins'=>78,'mid'=>80,'thr'=>86,'pmk'=>62,'reb'=>80,'def'=>72,'ath'=>82],
 ],
 'MIN'=>[
  ['name'=>'Anthony Edwards','pos'=>'SG','age'=>23,'ht'=>193,'ovr'=>92,'ins'=>86,'mid'=>84,'thr'=>84,'pmk'=>78,'reb'=>72,'def'=>82,'ath'=>96],
  ['name'=>'Rudy Gobert','pos'=>'C','age'=>32,'ht'=>216,'ovr'=>85,'ins'=>82,'mid'=>56,'thr'=>40,'pmk'=>56,'reb'=>90,'def'=>94,'ath'=>80],
  ['name'=>'Julius Randle','pos'=>'PF','age'=>30,'ht'=>203,'ovr'=>85,'ins'=>86,'mid'=>80,'thr'=>76,'pmk'=>76,'reb'=>84,'def'=>74,'ath'=>82],
  ['name'=>'Mike Conley','pos'=>'PG','age'=>37,'ht'=>185,'ovr'=>80,'ins'=>70,'mid'=>78,'thr'=>82,'pmk'=>84,'reb'=>56,'def'=>74,'ath'=>70],
 ],
 'OKC'=>[
  ['name'=>'Shai Gilgeous-Alexander','pos'=>'PG','age'=>26,'ht'=>198,'ovr'=>95,'ins'=>90,'mid'=>90,'thr'=>82,'pmk'=>86,'reb'=>70,'def'=>84,'ath'=>88],
  ['name'=>'Chet Holmgren','pos'=>'C','age'=>22,'ht'=>216,'ovr'=>86,'ins'=>82,'mid'=>78,'thr'=>80,'pmk'=>70,'reb'=>82,'def'=>90,'ath'=>82],
  ['name'=>'Jalen Williams','pos'=>'SF','age'=>23,'ht'=>198,'ovr'=>86,'ins'=>84,'mid'=>82,'thr'=>80,'pmk'=>80,'reb'=>72,'def'=>84,'ath'=>86],
  ['name'=>'Luguentz Dort','pos'=>'SG','age'=>25,'ht'=>193,'ovr'=>80,'ins'=>74,'mid'=>74,'thr'=>78,'pmk'=>62,'reb'=>68,'def'=>88,'ath'=>84],
 ],
 'POR'=>[
  ['name'=>'Anfernee Simons','pos'=>'SG','age'=>25,'ht'=>193,'ovr'=>82,'ins'=>76,'mid'=>82,'thr'=>84,'pmk'=>76,'reb'=>60,'def'=>62,'ath'=>84],
  ['name'=>'Deandre Ayton','pos'=>'C','age'=>26,'ht'=>213,'ovr'=>82,'ins'=>84,'mid'=>76,'thr'=>56,'pmk'=>64,'reb'=>86,'def'=>76,'ath'=>82],
  ['name'=>'Scoot Henderson','pos'=>'PG','age'=>21,'ht'=>191,'ovr'=>79,'ins'=>78,'mid'=>72,'thr'=>72,'pmk'=>82,'reb'=>62,'def'=>66,'ath'=>88],
  ['name'=>'Shaedon Sharpe','pos'=>'SG','age'=>21,'ht'=>198,'ovr'=>80,'ins'=>80,'mid'=>76,'thr'=>76,'pmk'=>66,'reb'=>68,'def'=>66,'ath'=>92],
 ],
 'UTA'=>[
  ['name'=>'Lauri Markkanen','pos'=>'PF','age'=>27,'ht'=>213,'ovr'=>85,'ins'=>82,'mid'=>82,'thr'=>84,'pmk'=>66,'reb'=>80,'def'=>72,'ath'=>80],
  ['name'=>'Collin Sexton','pos'=>'PG','age'=>25,'ht'=>185,'ovr'=>81,'ins'=>82,'mid'=>78,'thr'=>78,'pmk'=>76,'reb'=>58,'def'=>64,'ath'=>84],
  ['name'=>'Walker Kessler','pos'=>'C','age'=>23,'ht'=>213,'ovr'=>80,'ins'=>82,'mid'=>54,'thr'=>40,'pmk'=>54,'reb'=>88,'def'=>88,'ath'=>80],
 ],
 'GSW'=>[
  ['name'=>'Stephen Curry','pos'=>'PG','age'=>36,'ht'=>188,'ovr'=>93,'ins'=>80,'mid'=>88,'thr'=>99,'pmk'=>88,'reb'=>62,'def'=>68,'ath'=>78],
  ['name'=>'Draymond Green','pos'=>'PF','age'=>34,'ht'=>198,'ovr'=>82,'ins'=>74,'mid'=>66,'thr'=>66,'pmk'=>86,'reb'=>82,'def'=>92,'ath'=>76],
  ['name'=>'Andrew Wiggins','pos'=>'SF','age'=>29,'ht'=>201,'ovr'=>82,'ins'=>80,'mid'=>78,'thr'=>78,'pmk'=>66,'reb'=>72,'def'=>80,'ath'=>88],
  ['name'=>'Jonathan Kuminga','pos'=>'PF','age'=>22,'ht'=>203,'ovr'=>81,'ins'=>84,'mid'=>72,'thr'=>72,'pmk'=>64,'reb'=>74,'def'=>74,'ath'=>92],
 ],
 'LAC'=>[
  ['name'=>'Kawhi Leonard','pos'=>'SF','age'=>33,'ht'=>201,'ovr'=>90,'ins'=>86,'mid'=>88,'thr'=>84,'pmk'=>76,'reb'=>76,'def'=>90,'ath'=>82],
  ['name'=>'James Harden','pos'=>'PG','age'=>35,'ht'=>196,'ovr'=>85,'ins'=>78,'mid'=>80,'thr'=>82,'pmk'=>90,'reb'=>72,'def'=>68,'ath'=>74],
  ['name'=>'Norman Powell','pos'=>'SG','age'=>31,'ht'=>193,'ovr'=>82,'ins'=>80,'mid'=>80,'thr'=>84,'pmk'=>66,'reb'=>60,'def'=>72,'ath'=>82],
  ['name'=>'Ivica Zubac','pos'=>'C','age'=>27,'ht'=>213,'ovr'=>81,'ins'=>84,'mid'=>62,'thr'=>40,'pmk'=>62,'reb'=>86,'def'=>80,'ath'=>72],
 ],
 'LAL'=>[
  ['name'=>'LeBron James','pos'=>'SF','age'=>40,'ht'=>206,'ovr'=>92,'ins'=>88,'mid'=>84,'thr'=>80,'pmk'=>92,'reb'=>80,'def'=>78,'ath'=>84],
  ['name'=>'Anthony Davis','pos'=>'PF','age'=>31,'ht'=>208,'ovr'=>93,'ins'=>90,'mid'=>84,'thr'=>74,'pmk'=>72,'reb'=>88,'def'=>92,'ath'=>88],
  ['name'=>'Austin Reaves','pos'=>'SG','age'=>26,'ht'=>196,'ovr'=>83,'ins'=>78,'mid'=>80,'thr'=>82,'pmk'=>82,'reb'=>66,'def'=>70,'ath'=>74],
  ['name'=>'Rui Hachimura','pos'=>'PF','age'=>26,'ht'=>203,'ovr'=>80,'ins'=>80,'mid'=>78,'thr'=>78,'pmk'=>62,'reb'=>74,'def'=>72,'ath'=>82],
 ],
 'PHX'=>[
  ['name'=>'Kevin Durant','pos'=>'SF','age'=>36,'ht'=>211,'ovr'=>93,'ins'=>88,'mid'=>92,'thr'=>88,'pmk'=>80,'reb'=>76,'def'=>80,'ath'=>82],
  ['name'=>'Devin Booker','pos'=>'SG','age'=>28,'ht'=>196,'ovr'=>91,'ins'=>84,'mid'=>90,'thr'=>86,'pmk'=>84,'reb'=>66,'def'=>72,'ath'=>82],
  ['name'=>'Bradley Beal','pos'=>'SG','age'=>31,'ht'=>193,'ovr'=>84,'ins'=>82,'mid'=>84,'thr'=>82,'pmk'=>78,'reb'=>66,'def'=>68,'ath'=>80],
  ['name'=>'Jusuf Nurkic','pos'=>'C','age'=>30,'ht'=>211,'ovr'=>79,'ins'=>80,'mid'=>70,'thr'=>58,'pmk'=>72,'reb'=>86,'def'=>74,'ath'=>64],
 ],
 'SAC'=>[
  ['name'=>"De'Aaron Fox",'pos'=>'PG','age'=>27,'ht'=>191,'ovr'=>88,'ins'=>86,'mid'=>82,'thr'=>80,'pmk'=>84,'reb'=>62,'def'=>76,'ath'=>94],
  ['name'=>'Domantas Sabonis','pos'=>'C','age'=>28,'ht'=>211,'ovr'=>88,'ins'=>86,'mid'=>78,'thr'=>72,'pmk'=>84,'reb'=>92,'def'=>72,'ath'=>74],
  ['name'=>'DeMar DeRozan','pos'=>'SF','age'=>35,'ht'=>198,'ovr'=>85,'ins'=>84,'mid'=>90,'thr'=>72,'pmk'=>80,'reb'=>68,'def'=>72,'ath'=>76],
 ],
 'DAL'=>[
  ['name'=>'Luka Doncic','pos'=>'PG','age'=>25,'ht'=>201,'ovr'=>96,'ins'=>88,'mid'=>88,'thr'=>86,'pmk'=>94,'reb'=>82,'def'=>72,'ath'=>80],
  ['name'=>'Kyrie Irving','pos'=>'PG','age'=>32,'ht'=>188,'ovr'=>89,'ins'=>86,'mid'=>88,'thr'=>88,'pmk'=>86,'reb'=>62,'def'=>72,'ath'=>84],
  ['name'=>'Klay Thompson','pos'=>'SG','age'=>34,'ht'=>198,'ovr'=>82,'ins'=>72,'mid'=>82,'thr'=>88,'pmk'=>62,'reb'=>66,'def'=>72,'ath'=>72],
  ['name'=>'Daniel Gafford','pos'=>'C','age'=>26,'ht'=>208,'ovr'=>80,'ins'=>84,'mid'=>56,'thr'=>40,'pmk'=>54,'reb'=>82,'def'=>82,'ath'=>88],
 ],
 'HOU'=>[
  ['name'=>'Alperen Sengun','pos'=>'C','age'=>22,'ht'=>208,'ovr'=>86,'ins'=>86,'mid'=>78,'thr'=>66,'pmk'=>84,'reb'=>86,'def'=>74,'ath'=>74],
  ['name'=>'Jalen Green','pos'=>'SG','age'=>23,'ht'=>193,'ovr'=>83,'ins'=>82,'mid'=>80,'thr'=>78,'pmk'=>72,'reb'=>64,'def'=>68,'ath'=>94],
  ['name'=>'Fred VanVleet','pos'=>'PG','age'=>30,'ht'=>185,'ovr'=>83,'ins'=>72,'mid'=>78,'thr'=>82,'pmk'=>84,'reb'=>60,'def'=>82,'ath'=>76],
  ['name'=>'Amen Thompson','pos'=>'SF','age'=>22,'ht'=>201,'ovr'=>82,'ins'=>82,'mid'=>70,'thr'=>64,'pmk'=>78,'reb'=>78,'def'=>84,'ath'=>94],
 ],
 'MEM'=>[
  ['name'=>'Ja Morant','pos'=>'PG','age'=>25,'ht'=>188,'ovr'=>89,'ins'=>88,'mid'=>82,'thr'=>76,'pmk'=>88,'reb'=>66,'def'=>70,'ath'=>96],
  ['name'=>'Jaren Jackson Jr.','pos'=>'PF','age'=>25,'ht'=>206,'ovr'=>86,'ins'=>82,'mid'=>78,'thr'=>80,'pmk'=>62,'reb'=>76,'def'=>92,'ath'=>82],
  ['name'=>'Desmond Bane','pos'=>'SG','age'=>26,'ht'=>196,'ovr'=>85,'ins'=>80,'mid'=>82,'thr'=>86,'pmk'=>78,'reb'=>72,'def'=>76,'ath'=>78],
 ],
 'NOP'=>[
  ['name'=>'Zion Williamson','pos'=>'PF','age'=>24,'ht'=>198,'ovr'=>88,'ins'=>94,'mid'=>78,'thr'=>64,'pmk'=>78,'reb'=>80,'def'=>74,'ath'=>92],
  ['name'=>'Brandon Ingram','pos'=>'SF','age'=>27,'ht'=>203,'ovr'=>86,'ins'=>82,'mid'=>86,'thr'=>80,'pmk'=>80,'reb'=>70,'def'=>72,'ath'=>80],
  ['name'=>'CJ McCollum','pos'=>'SG','age'=>33,'ht'=>191,'ovr'=>82,'ins'=>78,'mid'=>84,'thr'=>82,'pmk'=>78,'reb'=>62,'def'=>66,'ath'=>76],
  ['name'=>'Herbert Jones','pos'=>'SF','age'=>26,'ht'=>198,'ovr'=>80,'ins'=>74,'mid'=>72,'thr'=>76,'pmk'=>66,'reb'=>70,'def'=>90,'ath'=>84],
 ],
 'SAS'=>[
  ['name'=>'Victor Wembanyama','pos'=>'C','age'=>21,'ht'=>224,'ovr'=>93,'ins'=>86,'mid'=>82,'thr'=>80,'pmk'=>74,'reb'=>88,'def'=>96,'ath'=>86],
  ['name'=>'Devin Vassell','pos'=>'SG','age'=>24,'ht'=>196,'ovr'=>82,'ins'=>76,'mid'=>82,'thr'=>82,'pmk'=>72,'reb'=>66,'def'=>78,'ath'=>82],
  ['name'=>'Chris Paul','pos'=>'PG','age'=>39,'ht'=>183,'ovr'=>81,'ins'=>68,'mid'=>80,'thr'=>80,'pmk'=>92,'reb'=>58,'def'=>78,'ath'=>66],
  ['name'=>'Jeremy Sochan','pos'=>'PF','age'=>21,'ht'=>203,'ovr'=>79,'ins'=>78,'mid'=>70,'thr'=>68,'pmk'=>72,'reb'=>76,'def'=>82,'ath'=>84],
 ],
];
