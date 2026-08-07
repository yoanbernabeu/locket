import { defineConfig, devices } from '@playwright/test';

/**
 * Les tests de bout en bout démarrent leur propre serveur, sur une base dédiée,
 * pour ne jamais toucher aux données de développement.
 *
 * Chromium ET WebKit : WebKit est la seule façon de voir venir une régression
 * Safari avant qu'elle n'atteigne l'iPhone d'un grand-parent, et c'est justement
 * la plateforme la plus contraignante pour une PWA.
 */
export default defineConfig({
  testDir: './tests-e2e',
  fullyParallel: false, // les parcours partagent une instance unique
  workers: 1,
  timeout: 60_000,
  expect: { timeout: 15_000 },
  reporter: [['list']],

  use: {
    baseURL: 'http://127.0.0.1:8001',
    trace: 'retain-on-failure',
    screenshot: 'only-on-failure',
    locale: 'fr-FR',
    timezoneId: 'Europe/Paris',
    permissions: ['microphone'],
  },

  projects: [
    { name: 'chromium', use: { ...devices['Desktop Chrome'] } },
    { name: 'webkit', use: { ...devices['Desktop Safari'] } },
  ],

  webServer: {
    command: 'php -S 127.0.0.1:8001 -t public public/index.php',
    url: 'http://127.0.0.1:8001/health',
    reuseExistingServer: !process.env.CI,
    timeout: 60_000,
    env: {
      APP_ENV: 'test',
      DATABASE_URL: 'sqlite:///%kernel.project_dir%/var/locket_e2e.db',
    },
  },
});
