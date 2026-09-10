import { expect, test } from "@playwright/test";

test("three goals update the Surface and finish the fake session", async ({ page }) => {
  await page.goto("/examples/fake-lesson.html");

  await expect(page.locator("body")).toHaveAttribute("data-status", "completed");
  await expect(page.locator("[data-goal][data-completed='true']")).toHaveCount(3);
  await expect(page.locator("#tool-results")).toHaveText("6");
});
