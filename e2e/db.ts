import { execSync } from 'node:child_process';

/**
 * Runs a read-only SQL query against the dev stack's database (same assumption
 * as the seed in global-setup.ts). Override with CMP_DB_QUERY_CMD, which receives
 * the SQL on stdin.
 */
export const queryDb = (sql: string): string[][] => {
  const cmd =
    process.env.CMP_DB_QUERY_CMD || 'docker compose exec -T db mariadb -uroot -proot wordpress -N -B';
  const out = execSync(cmd, { input: sql, shell: '/bin/bash' }).toString().trim();
  return out ? out.split('\n').map((line) => line.split('\t')) : [];
};
