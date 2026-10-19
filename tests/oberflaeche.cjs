// Browsertest der Oberfläche (Playwright aus der Testumgebung, ohne node_modules im Projekt).
// Wird von tests/run.sh aufgerufen: node tests/oberflaeche.cjs <basis-url> <session-cookie>
// Gibt ein JSON-Objekt mit den Befunden aus, die run.sh mit pruefe auswertet.
"use strict";

const path = require("path");
const { execSync } = require("child_process");
const { chromium } = require(path.join(execSync("npm root -g").toString().trim(), "playwright"));

const [base, session] = process.argv.slice(2);

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
    await page.getByText("Zwei-Faktor-Anmeldung", { exact: true }).waitFor({ timeout: 10000 });
    const text = await page.locator("#app").innerText();
    result.profileLoaded = true;
    result.profileNull = (text.match(/null|undefined/g) || []).length;
    result.totpSetupButton = await page.getByRole("button", { name: "Einrichten" }).count();
    result.errors = errors;
  } catch (error) {
    result.failure = error.message.split("\n")[0];
  } finally {
    await browser.close();
  }
  console.log(JSON.stringify(result));
})();
