# nba-id-sync (PostgreSQL + balldontlie)

Script para preencher `players.nba_player_id` no PostgreSQL, buscando pelo nome na API do balldontlie.

## API Key (opcional)

Crie um arquivo `.env` nesta pasta (ou exporte variáveis no ambiente). Se no seu ambiente a API exigir chave, defina:

- `BALLDONTLIE_API_KEY=...`

> Você encontra um modelo em `.env.example`.

## Configuração do banco

Você pode usar **DATABASE_URL** (recomendado):

- `DATABASE_URL=postgresql://USER:PASSWORD@HOST:5432/DBNAME`

Ou usar as variáveis separadas (`PGHOST`, `PGPORT`, `PGDATABASE`, `PGUSER`, `PGPASSWORD`).

## Como roda

1) Instale dependências:

```bash
npm install
```

2) Dry-run (não faz UPDATE):

```bash
DRY_RUN=1 npm run sync
```

3) Rodar valendo (faz UPDATE):

```bash
npm run sync
```

## Ajustes úteis

- `SLEEP_MS` (default `400`): delay entre requisições.
- `BATCH_SIZE` (default `100`): quantos players buscar por vez.

## Observações

- O script pega o **primeiro resultado** retornado pela API.
- Se a API limitar, aumente `SLEEP_MS`.
- Se sua tabela/colunas tiverem outro nome, você pode ajustar via env:
  - `PLAYERS_TABLE`, `NAME_COLUMN`, `NBA_ID_COLUMN`
