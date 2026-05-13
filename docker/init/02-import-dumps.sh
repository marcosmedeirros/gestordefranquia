#!/bin/sh
set -e

if [ -f /docker-dumps/u289267434_fbabrasilbanco.sql ]; then
  echo "[init] Importing main database dump"
  sed 's/u289267434_fbabrasilbanco/fba/g' /docker-dumps/u289267434_fbabrasilbanco.sql | mariadb -u root -proot fba
fi

if [ -f /docker-dumps/u289267434_gamesfba.sql ]; then
  echo "[init] Importing games database dump"
  sed 's/u289267434_gamesfba/games_db/g' /docker-dumps/u289267434_gamesfba.sql | mariadb -u root -proot games_db
fi
