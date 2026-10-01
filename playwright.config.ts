import { defineConfig } from '@playwright/test';

export default defineConfig({
  testDir: './e2e',
  timeout: 30_000,
  retries: 0,
  use: {
    baseURL: process.env.E2E_BASE_URL || 'http://localhost:8000',
    headless: true,
    screenshot: 'only-on-failure',
    trace: 'on-first-retry',
  },
  projects: [
    // Signs in once and saves the session. Specs that need a signed-in user
    // pull it in with `test.use({ storageState: AUTH_FILE })` instead of
    // logging in themselves — `/login` allows five attempts a minute, and one
    // login per test would exhaust that by the sixth.
    {
      name: 'setup',
      testMatch: /auth\.setup\.ts/,
    },
    {
      name: 'chromium',
      use: { browserName: 'chromium' },
      testIgnore: /auth\.setup\.ts/,
      dependencies: ['setup'],
    },
  ],
});
