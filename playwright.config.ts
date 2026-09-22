import { defineConfig } from "@playwright/test";

export default defineConfig({
  testDir: "tests/e2e",
  // The static Python server is deliberately minimal and serial; one browser
  // avoids module-fetch connection resets while retaining a real browser test.
  fullyParallel: false,
  workers: 1,
  retries: 0,
  reporter: "list",
  use: {
    baseURL: "http://127.0.0.1:4173",
    headless: true,
  },
  webServer: {
    command: "python3 -m http.server 4173 --bind 127.0.0.1",
    url: "http://127.0.0.1:4173",
    reuseExistingServer: true,
  },
});
