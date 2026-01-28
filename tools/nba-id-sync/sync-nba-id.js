import 'dotenv/config';
import pg from 'pg';

const { Pool } = pg;

const TABLE = process.env.PLAYERS_TABLE || 'players';
const NAME_COL = process.env.NAME_COLUMN || 'name';
const NBA_ID_COL = process.env.NBA_ID_COLUMN || 'nba_player_id';

const API_KEY = process.env.BALLDONTLIE_API_KEY;
const SLEEP_MS = Number(process.env.SLEEP_MS || 400);
const BATCH_SIZE = Number(process.env.BATCH_SIZE || 100);
const DRY_RUN = String(process.env.DRY_RUN || '0') === '1';

if (!API_KEY) {
  console.warn('Aviso: BALLDONTLIE_API_KEY não definido. Se a API exigir chave no seu ambiente, adicione no .env (veja .env.example).');
}

function sleep(ms) {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

function buildPool() {
  const databaseUrl = process.env.DATABASE_URL;
  if (databaseUrl) {
    return new Pool({ connectionString: databaseUrl });
  }
  return new Pool({
    host: process.env.PGHOST,
    port: process.env.PGPORT ? Number(process.env.PGPORT) : undefined,
    database: process.env.PGDATABASE,
    user: process.env.PGUSER,
    password: process.env.PGPASSWORD,
  });
}

async function fetchBalldontlieIdByName(name) {
  const url = `https://api.balldontlie.io/v1/players?search=${encodeURIComponent(name)}`;

  const headers = {
    'Accept': 'application/json',
  };
  if (API_KEY) {
    headers['Authorization'] = API_KEY;
  }

  const res = await fetch(url, {
    method: 'GET',
    headers,
  });

  if (!res.ok) {
    const text = await res.text().catch(() => '');
    throw new Error(`balldontlie status=${res.status} body=${text.slice(0, 200)}`);
  }

  const json = await res.json();
  const first = json?.data?.[0];
  return first?.id ?? null;
}

async function main() {
  const pool = buildPool();

  const client = await pool.connect();
  try {
    console.log(`DRY_RUN=${DRY_RUN} | SLEEP_MS=${SLEEP_MS} | BATCH_SIZE=${BATCH_SIZE}`);
    console.log(`Tabela: ${TABLE} | Colunas: ${NAME_COL}, ${NBA_ID_COL}`);

    let offset = 0;
    let totalUpdated = 0;
    let totalNotFound = 0;

    while (true) {
      const selectSql = `
        SELECT id, ${NAME_COL} AS name
        FROM ${TABLE}
        WHERE ${NBA_ID_COL} IS NULL
        ORDER BY id ASC
        LIMIT $1 OFFSET $2
      `;
      const { rows } = await client.query(selectSql, [BATCH_SIZE, offset]);
      if (!rows.length) break;

      for (const row of rows) {
        const playerId = row.id;
        const name = String(row.name || '').trim();
        if (!name) {
          console.log(`[${playerId}] nome vazio - pulando`);
          totalNotFound++;
          continue;
        }

        process.stdout.write(`[${playerId}] ${name} -> `);

        let nbaId = null;
        try {
          nbaId = await fetchBalldontlieIdByName(name);
        } catch (e) {
          console.log(`erro na API (${e.message})`);
          await sleep(SLEEP_MS);
          continue;
        }

        if (!nbaId) {
          console.log('não encontrado');
          totalNotFound++;
          await sleep(SLEEP_MS);
          continue;
        }

        console.log(nbaId);

        if (!DRY_RUN) {
          const updateSql = `UPDATE ${TABLE} SET ${NBA_ID_COL} = $1 WHERE id = $2`;
          await client.query(updateSql, [nbaId, playerId]);
        }

        totalUpdated++;
        await sleep(SLEEP_MS);
      }

      offset += rows.length;
    }

    console.log(`\nResumo:`);
    console.log(`- Atualizados: ${totalUpdated}`);
    console.log(`- Não encontrados: ${totalNotFound}`);

    if (DRY_RUN) {
      console.log('\n(DRY_RUN=1) Nenhum UPDATE foi executado.');
    }
  } finally {
    client.release();
    await pool.end();
  }
}

main().catch((err) => {
  console.error('Falha geral:', err);
  process.exit(1);
});
