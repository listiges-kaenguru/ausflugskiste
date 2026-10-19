// Browsertest der Oberfläche (Playwright aus der Testumgebung, ohne node_modules im Projekt).
// Wird von tests/run.sh aufgerufen: node tests/oberflaeche.cjs <basis-url> <session-cookie> [nur-profil]
// Gibt ein JSON-Objekt mit den Befunden aus, die run.sh mit pruefe auswertet.
"use strict";

const path = require("path");
const { execSync } = require("child_process");
const { chromium } = require(path.join(execSync("npm root -g").toString().trim(), "playwright"));

const [base, session, mode] = process.argv.slice(2);

(async () => {
  const browser = await chromium.launch();
  const result = {};
  try {
    const context = await browser.newContext();
    await context.addCookies([{ name: "session", value: session, url: base }]);
    const page = await context.newPage();
    const errors = [];
    page.on("pageerror", (error) => errors.push(error.message));

    await page.goto(`${base}/#/profile`);
    await page.locator("strong", { hasText: "Zwei-Faktor-Anmeldung" }).waitFor({ timeout: 10000 });
    const text = await page.locator("#app").innerText();
    result.profileLoaded = true;
    result.profileNull = (text.match(/null|undefined/g) || []).length;
    result.totpSetupButton = await page.getByRole("button", { name: "Einrichten" }).count();
    result.totpActive = await page.getByText("Aktiv", { exact: true }).count();
    if (mode !== "nur-profil") {
      await exportTest(page, result);
      await toggleTest(page, result);
    }
    result.errors = errors;
  } catch (error) {
    result.failure = error.message.split("\n")[0];
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify(result));
})();

// Knopf „Ziele exportieren“ im Profil: lädt eine JSON-Datei mit den eigenen Zielen herunter
async function exportTest(page, result) {
  const link = page.getByRole("link", { name: "Ziele exportieren" });
  if ((await link.count()) !== 1) {
    result.exportDownload = null;
    return;
  }
  const [download] = await Promise.all([page.waitForEvent("download", { timeout: 10000 }), link.click()]);
  const fs = require("fs");
  const content = JSON.parse(fs.readFileSync(await download.path(), "utf8"));
  result.exportDownload = { filename: download.suggestedFilename(), format: content.format, names: content.destinations.map((d) => d.name) };
}

// Umschalter Favorit auf der Karte: welche Anfrage geht raus?
async function toggleTest(page, result) {
  await page.goto(`${base}/#/`);
  const toggle = page.getByRole("button", { name: "Favorit: Patch-Ziel" });
  await toggle.waitFor({ timeout: 10000 });
  const [request] = await Promise.all([
    page.waitForRequest((r) => (new URL(r.url()).searchParams.get("r") ?? "").startsWith("destinations/") && r.method() !== "GET"),
    toggle.click(),
  ]);
  await request.response();
  result.toggleRequest = { method: request.method(), body: request.postDataJSON() };
}
