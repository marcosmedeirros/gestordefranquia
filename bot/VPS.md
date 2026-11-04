# O bot na VPS Oracle

Desde 17/08/2026 o bot do WhatsApp roda numa VPS da Oracle, 24/7, e não mais
no PC do Marcos. O que mudou na prática: o PC pode desligar, dormir ou
reiniciar sem derrubar o bot.

```
instance-20260817-0900   163.176.122.157   Oracle Linux 9.8   ARM (aarch64)
3 OCPU · 16 GB · acesso: ssh -i ~/.ssh/oracle-fba.key opc@163.176.122.157
```

## O que roda lá

| peça | como | onde |
|---|---|---|
| Evolution API + Postgres | Docker Compose, `restart: unless-stopped` | `~/fba-bot/evolution/` |
| Worker da fila | systemd `fba-bot.service`, `Restart=always` | `~/fba-bot/whatsapp-local.php` |
| Relay do webhook (recebe) | systemd `fba-webhook-receber.service`, `php -S 172.18.0.1:9099` | `~/fba-bot/relay/receber.php` |
| Relay do webhook (envia) | systemd `fba-webhook-enviar.service` | `~/fba-bot/relay/enviar.php`, fila em `relay/spool/` |
| Config do worker | fora do git, `chmod 600` | `~/fba-bot/whatsapp-local.config.php` |
| Chave da Evolution | fora do git, `chmod 600` | `~/fba-bot/evolution/.env` |

## Duas decisões que valem explicar

**A Evolution escuta só em `127.0.0.1:8081`.** No compose local a porta é
aberta pra máquina toda; aqui não. O número do WhatsApp está pareado com um
celular de verdade e a Evolution não tem autenticação além da apikey — deixá-la
alcançável de fora seria oferecer o número a quem varrer a faixa da Oracle.
Nada precisa entrar: o worker puxa a fila do site e a Evolution manda o webhook
pra fora. As duas pontas são de saída.

**O worker é `Restart=always`, não agendador.** Ele roda uma rodada de ~55s e
sai; o systemd sobe outra. A diferença pro Agendador de Tarefas do Windows é
que o systemd **nunca** sobe uma segunda instância enquanto a primeira vive —
e worker sobreposto já fez o bot mandar tudo duplicado (15/08/2026).

**O webhook da Evolution NÃO vai direto pro site: passa pelo relay
(`bot/relay/`).** A Evolution dispara um POST pro site a cada mensagem de grupo,
sem fila e sem limite de ritmo, e repete 10x quando o site recusa. A Hostinger
limita requisição dinâmica por IP (responde **429**, vazio, do LiteSpeed): o uso
normal já ficava colado no limite e qualquer rajada — a Evolution religando e
despejando o que acumulou — passava dele. Aí os retries somavam carga e o
bloqueio se sustentava sozinho; o bot ficava mudo até alguém parar tudo na mão.
Caiu assim em 03/11/2026 às 15:01 e em 04/11/2026 às 13:26.

O relay resolve isso sem mudar nada no site: a Evolution entrega a uma porta
local (ela recebe 200 sempre, então nunca faz retry), o evento vai pra disco, e
o `enviar.php` junta o que estiver esperando **num POST só** (o
`api/whatsapp-webhook.php` já aceita uma lista em `data`) e manda no máximo um a
cada 2,5 s. Se o site disser 429/5xx, o evento fica no disco e o relay recua
(15s, 30s... 5 min) e tenta de novo — nada se perde. Evento com mais de 30 min é
descartado (responder comando velho só atrapalha). Lote que o site rejeita é
separado um a um até achar o culpado.

A URL do webhook mora no banco da Evolution (`"Webhook".url`, = `http://172.18.0.1:9099/`,
o IP da ponte do Docker). Pra voltar ao jeito antigo: `UPDATE "Webhook" SET url =
'https://fbabrasil.com.br/api/whatsapp-webhook.php'` e reiniciar a Evolution.
Se a rede `evolution_default` for recriada com outra faixa de IP, o IP do
`fba-webhook-receber.service` e essa URL têm de mudar juntos.

## Comandos do dia a dia

```bash
ssh -i ~/.ssh/oracle-fba.key opc@163.176.122.157

systemctl status fba-bot            # o worker está de pé?
journalctl -u fba-webhook-enviar -f # o relay: "ok: N evento(s) em 1 POST" é o normal
curl -s http://172.18.0.1:9099/     # relay vivo? diz quantos eventos esperam
tail -f ~/fba-bot/whatsapp-local.log
sudo docker ps                      # Evolution e Postgres
sudo docker logs fba-evolution --tail 50

sudo systemctl restart fba-bot      # reiniciar o worker
cd ~/fba-bot/evolution && sudo docker compose restart evolution
```

De qualquer máquina, sem SSH:

```bash
php bot/plantao.php                 # estado geral (vê a VPS pelo site)
php bot/plantao.php sempre|off|4    # liga/desliga a janela de horário
```

## Se precisar voltar pro PC

A sessão pareada vive em dois lugares: o banco `evolution` do Postgres e o
volume `evolution_instances`. Migrar os dois evita ler QR code de novo — foi
assim que ela veio pra cá, sem tocar no celular:

```bash
# na origem, com a Evolution PARADA (o banco fica de pé)
docker stop fba-evolution
docker exec fba-evolution-db pg_dump -U evo -d evolution --clean --if-exists > evo.sql
docker run --rm -v evolution_evolution_instances:/d -v "$PWD":/out alpine tar czf /out/instances.tgz -C /d .

# no destino, também com a Evolution parada
docker exec -i fba-evolution-db psql -U evo -d evolution < evo.sql
docker run --rm -v evolution_evolution_instances:/d -v "$PWD":/in alpine \
  sh -c 'rm -rf /d/* && tar xzf /in/instances.tgz -C /d'
docker compose up -d
```

**As duas nunca podem rodar ao mesmo tempo** — são a mesma sessão do WhatsApp,
e duas instâncias brigando derrubam a conexão. Por isso os containers do PC
ficaram com `--restart=no` e a tarefa `FBA WhatsApp Worker` do Windows está
desabilitada. Ligar o bot de volta no PC exige desfazer as duas coisas.

## Uma pedra que essa máquina tem

Oracle Linux vem com SELinux ativo. Um serviço do systemd **não** consegue
redirecionar `StandardOutput` pra um arquivo dentro de `/home` — falha com
`209/STDOUT`, e a mensagem não diz "SELinux". O `fba-bot.service` manda a
saída pro journal; quem escreve `whatsapp-local.log` é o próprio worker, e
isso funciona porque quem escreve é o processo, não o systemd.
