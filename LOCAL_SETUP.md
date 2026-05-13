# Rodar localmente

Este guia explica como subir o projeto localmente com Docker (recomendado).

## Requisitos
- Docker Desktop instalado e em execucao
- Dump dos bancos em `bancos_local_test/`
  - `u289267434_fbabrasilbanco.sql`
  - `u289267434_gamesfba.sql`

## Passo a passo (Docker)
1) Suba os containers e recrie o volume do banco:

```
docker compose down -v
docker compose up -d --build
```

2) Abra no navegador:

```
http://localhost:8080
```

3) Se quiser verificar os bancos:

```
docker compose exec -T db mariadb -u root -proot -e "SHOW DATABASES;"
```

## Observacoes
- O arquivo `backend/config.local.php` e usado automaticamente em ambiente local.
- O Docker importa os dumps de `bancos_local_test` na primeira subida do banco.

No final, os arquivos vao pro git e depois pro hostinger.
