import { execSync } from 'node:child_process';
import { chromium, type FullConfig } from '@playwright/test';
import { ADMIN_STATE } from './admin';

/**
 * Seed the three mode domains/templates and clear the config cache before the
 * E2E run. Requires the dev stack to be up (docker compose) — the WordPress
 * container and its MariaDB. Fails loudly with guidance if seeding can't run.
 */
export default async function globalSetup(config: FullConfig): Promise<void> {
  const cmd = process.env.CMP_SEED_CMD ||
    'docker compose exec -T db mariadb -uroot -proot wordpress < e2e/seed.sql';
  try {
    execSync(cmd, { stdio: 'inherit', shell: '/bin/bash' });
  } catch (err) {
    throw new Error(
      `E2E seed failed. Is the dev stack running (docker compose up -d)?\n` +
      `Command: ${cmd}\n${(err as Error).message}`
    );
  }

  // One admin login for all admin tests: WordPress keeps a user's sessions in
  // one meta value, so parallel logins of the same user overwrite each other's
  // session and some tests end up logged out (wp-login.php?reauth=1).
  const user = process.env.CMP_ADMIN_USER;
  const pass = process.env.CMP_ADMIN_PASS;
  if (user && pass) {
    const browser = await chromium.launch();
    const page = await browser.newPage({ baseURL: config.projects[0].use.baseURL });
    await page.goto('/wp-login.php');
    await page.fill('#user_login', user);
    await page.fill('#user_pass', pass);
    await Promise.all([page.waitForURL(/\/wp-admin\//), page.click('#wp-submit')]);
    await page.context().storageState({ path: ADMIN_STATE }); // gitignored
    await browser.close();
  }
}
