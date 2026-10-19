const { defineConfig } = require('@playwright/test');
const path = require('node:path');
if (!process.env.E2E_BASE_URL || !process.env.E2E_ARTIFACT_DIR) {
  throw new Error('Set E2E_BASE_URL and E2E_ARTIFACT_DIR. See README.md.');
}
module.exports = defineConfig({
  testDir: '.', testMatch: '*.spec.js', fullyParallel: false, workers: 1,
  retries: 0, timeout: 45000,
  outputDir: path.join(process.env.E2E_ARTIFACT_DIR, 'test-results'),
  reporter: [['list'], ['json', { outputFile: path.join(process.env.E2E_ARTIFACT_DIR, 'results.json') }]],
  use: { baseURL: process.env.E2E_BASE_URL, viewport: { width: 1280, height: 900 },
    browserName: 'chromium', screenshot: 'only-on-failure', trace: 'retain-on-failure' },
});
