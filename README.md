# FBA (Franchise Basketball Association)

Projeto PHP + JavaScript para gerenciar franquias da NBA (FBA) com cadastro de usuários, times, jogadores, drafts, divisões e picks. Inclui frontend estilizado (HTML/CSS/JS), APIs em PHP e script SQL do banco.

## Estrutura
- public/ — frontend e APIs
	- index.html — central do GM (painel de ações)
	- teams.html — visão de times/elencos com CAP e flag de troca
	- api/ — endpoints em PHP
	- js/app.js — chamadas fetch + render de elencos
	- css/styles.css — tema e componentes
- backend/ — config e utilitários
	- config.php (ou config.sample.php)
	- db.php, helpers.php
- sql/schema.sql — criação do banco

## Subir no Hostinger (MySQL + PHP)
1) Crie um banco MySQL e usuário. Anote host, nome, usuário e senha.
2) Faça upload dos arquivos (public deve ficar acessível como raiz do site).
3) Copie backend/config.sample.php para backend/config.php e ajuste credenciais + e-mail de envio.
4) Importe sql/schema.sql no banco.
5) Ajuste a URL de verificação em config.php (`mail.verify_base_url`) para seu domínio (ex.: https://seudominio.com/api/verify.php?token=).

## Endpoints principais (POST salvo onde indicado)
- /api/register.php — cria usuário e envia e-mail de verificação
- /api/login.php — login (retorna dados básicos)
- /api/verify.php?token=... — confirma e-mail
- /api/team.php — GET lista times; POST cria time e gera picks (1ª/2ª rodada ano atual)
- /api/players.php — GET lista; POST adiciona jogador com checagem de CAP (máx 648; mín recomendado 618) e flag available_for_trade
- /api/divisions.php — GET/POST divisões
- /api/drafts.php — GET/POST drafts e prospects
- /api/picks.php — GET picks por time (opcional team_id)
- /api/rosters.php — GET times com GM, fotos, CAP e elenco completo

Campos novos no schema (sql/schema.sql):
- users.photo_url
- teams.photo_url
- players.available_for_trade (0/1)

## CAP
- CAP = soma dos 8 maiores OVRs do time.
- Limites: mínimo recomendado 618, máximo 648. A API bloqueia inserções que ultrapassam 648.

## Rodando localmente (XAMPP)
1) Coloque o projeto em htdocs (c:/xampp/htdocs/GMFBA).
2) Crie o banco `fba` e importe sql/schema.sql.
3) Ajuste backend/config.php com usuário/senha do MySQL local.
4) Abra http://localhost/GMFBA/public para usar a central do GM ou http://localhost/GMFBA/public/teams.html para ver elencos.

## Próximos passos sugeridos
- Implementar autenticação por sessão/JWT e proteção dos endpoints.
- Adicionar CRUD completo para picks, trades e histórico de campeões.
- Substituir mail() por SMTP autenticado (PHPMailer) se necessário no Hostinger.