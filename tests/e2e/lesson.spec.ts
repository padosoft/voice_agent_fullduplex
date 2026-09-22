import { expect, test } from "@playwright/test";

test("three goals update the Surface and finish the fake session", async ({ page }) => {
  await page.goto("/examples/fake-lesson.html");

  await expect(page.locator("body")).toHaveAttribute("data-status", "completed");
  await expect(page.locator("[data-goal][data-completed='true']")).toHaveCount(3);
  await expect(page.locator("#tool-results")).toHaveText("6");
  await expect(page.locator("#text-turns")).toHaveText("2");
  await expect(page.locator("#usage-records")).toHaveText("1");
});

test("Gemini and xAI compatible browser transports preserve transcript, usage, and Laravel tools", async ({ page }) => {
  await page.goto("/examples/provider-contracts.html");

  await expect(page.locator("body")).toHaveAttribute("data-gemini", "completed");
  await expect(page.locator("body")).toHaveAttribute("data-xai", "completed");
  await expect(page.locator("#messages")).toHaveText("4");
  await expect(page.locator("#usage")).toHaveText("2");
  await expect(page.locator("#tools")).toHaveText("2");
});
