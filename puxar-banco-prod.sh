#!/usr/bin/env bash
#
# Traz o banco de produção e recria o local a partir dele.
#
#   ./puxar-banco-prod.sh            # baixa e importa
#   ./puxar-banco-prod.sh --so-baixar # só baixa, não mexe no local
#
# O MySQL da Hostinger só aceita conexão de dentro do servidor, então o dump é
# gerado LÁ por SSH e vem comprimido. As credenciais saem do próprio
# backend/config.php do site — nada de senha escrita aqui.
#
# Importar DESCARTA o banco local. É o ponto, mas é destrutivo.
set -euo pipefail

SSH_ALVO="u289267434@149.62.37.58"
SSH_PORTA=65002
REMOTO="~/domains/fbabrasil.com.br/public_html"
DESTINO="bancos_local_test/fba.sql.gz"
CONTAINER_DB="gestordefranquia-db-1"

echo "→ gerando o dump no servidor"
ssh -p "$SSH_PORTA" "$SSH_ALVO" "
  cd $REMOTO
  CFG=\$(php -r '\$c = require \"backend/config.php\"; echo \$c[\"db\"][\"name\"].\"|\".\$c[\"db\"][\"user\"].\"|\".\$c[\"db\"][\"pass\"];')
  DB=\$(echo \"\$CFG\" | cut -d'|' -f1); US=\$(echo \"\$CFG\" | cut -d'|' -f2); PW=\$(echo \"\$CFG\" | cut -d'|' -f3)
  # --single-transaction: dump consistente sem travar a liga durante a cópia.
  mysqldump --single-transaction --quick --no-tablespaces -u\"\$US\" -p\"\$PW\" \"\$DB\" 2>/dev/null | gzip -6 > /tmp/fba.sql.gz
  ls -lh /tmp/fba.sql.gz | awk '{print \"  gerado: \" \$5}'
"

echo "→ baixando"
mkdir -p bancos_local_test
scp -P "$SSH_PORTA" -q "$SSH_ALVO:/tmp/fba.sql.gz" "$DESTINO"
ssh -p "$SSH_PORTA" "$SSH_ALVO" 'rm -f /tmp/fba.sql.gz'
echo "  em $DESTINO ($(du -h "$DESTINO" | cut -f1))"

if [ "${1:-}" = "--so-baixar" ]; then
    echo "pronto (não mexi no banco local)"
    exit 0
fi

if ! docker ps --format '{{.Names}}' | grep -qx "$CONTAINER_DB"; then
    echo "O container $CONTAINER_DB não está de pé. Rode: docker compose up -d" >&2
    exit 1
fi

echo "→ recriando o banco local"
docker exec -i "$CONTAINER_DB" mariadb -u root -proot \
    -e "DROP DATABASE IF EXISTS fba; CREATE DATABASE fba CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;"

# O dump do mysqldump não traz CREATE DATABASE/USE, e as chaves vêm dentro do
# CREATE TABLE — sem o problema do export do phpMyAdmin, onde a tabela fica sem
# chave primária durante toda a importação.
#
# A primeira linha vem com `/*M!999999\- enable the sandbox mode */`, uma
# diretiva do MariaDB 11.8 (produção) que o cliente 11.3 do container não
# entende e reclama logo no começo. Como é só uma dica pro cliente, sai fora.
gunzip -c "$DESTINO" \
  | sed '1{/enable the sandbox mode/d}' \
  | docker exec -i "$CONTAINER_DB" mariadb -u root -proot fba

echo "→ conferindo"
docker exec -i "$CONTAINER_DB" mariadb -u root -proot fba -e "
    SELECT COUNT(*) AS tabelas FROM information_schema.tables WHERE table_schema='fba';
    SELECT league, COUNT(*) AS times FROM teams GROUP BY league;
    SELECT COUNT(*) AS picks FROM picks;"

echo
echo "Pronto. http://localhost:8080 · diagnósticos em /marcos-dev.php"
