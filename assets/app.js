// Ausflugskiste – Frontend ohne Build-Schritt und ohne Abhängigkeiten.
// Routing über den URL-Hash (#/…), damit kein Server-Rewrite nötig ist.

"use strict";

// ---------------------------------------------------------------- Hilfsfunktionen

/** Erzeugt ein DOM-Element. Texte werden immer als Textknoten eingefügt (kein innerHTML). */
function h(tag, props, ...children) {
  const el = document.createElement(tag);
  for (const [key, value] of Object.entries(props ?? {})) {
    if (value == null || value === false) continue;
    if (key === "class") el.className = value;
    else if (key.startsWith("on")) el.addEventListener(key.slice(2), value);
    else if (["value", "checked", "disabled", "hidden", "selected"].includes(key)) el[key] = value;
    else el.setAttribute(key, value === true ? "" : value);
  }
  for (const child of children.flat(Infinity)) {
    if (child == null || child === false) continue;
    el.append(child instanceof Node ? child : document.createTextNode(String(child)));
  }
  return el;
}

// Icon-Pfade aus Lucide (https://lucide.dev, ISC-Lizenz)
const ICONS = {
  compass: '<path d="m16.24 7.76-1.804 5.411a2 2 0 0 1-1.265 1.265L7.76 16.24l1.804-5.411a2 2 0 0 1 1.265-1.265z"/><circle cx="12" cy="12" r="10"/>',
  add: '<path d="M5 12h14"/><path d="M12 5v14"/>',
  search: '<circle cx="11" cy="11" r="8"/><path d="m21 21-4.3-4.3"/>',
  user: '<circle cx="12" cy="12" r="10"/><circle cx="12" cy="10" r="3"/><path d="M7 20.662V19a2 2 0 0 1 2-2h6a2 2 0 0 1 2 2v1.662"/>',
  users: '<path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/>',
  pin: '<path d="M20 10c0 4.993-5.539 10.193-7.399 11.799a1 1 0 0 1-1.202 0C9.539 20.193 4 14.993 4 10a8 8 0 0 1 16 0"/><circle cx="12" cy="10" r="3"/>',
  star: '<polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/>',
  heart: '<path d="M19 14c1.49-1.46 3-3.21 3-5.5A5.5 5.5 0 0 0 16.5 3c-1.76 0-3 .5-4.5 2-1.5-1.5-2.74-2-4.5-2A5.5 5.5 0 0 0 2 8.5c0 2.3 1.5 4.05 3 5.5l7 7Z"/>',
  check: '<circle cx="12" cy="12" r="10"/><path d="m9 12 2 2 4-4"/>',
  tick: '<path d="M20 6 9 17l-5-5"/>',
  back: '<path d="m12 19-7-7 7-7"/><path d="M19 12H5"/>',
  prev: '<path d="m15 18-6-6 6-6"/>',
  next: '<path d="m9 18 6-6-6-6"/>',
  down: '<path d="m6 9 6 6 6-6"/>',
  pencil: '<path d="M21.174 6.812a1 1 0 0 0-3.986-3.987L3.842 16.174a2 2 0 0 0-.5.83l-1.321 4.352a.5.5 0 0 0 .623.622l4.353-1.32a2 2 0 0 0 .83-.497z"/><path d="m15 5 4 4"/>',
  trash: '<path d="M3 6h18"/><path d="M19 6v14c0 1-1 2-2 2H7c-1 0-2-1-2-2V6"/><path d="M8 6V4c0-1 1-2 2-2h4c1 0 2 1 2 2v2"/><line x1="10" x2="10" y1="11" y2="17"/><line x1="14" x2="14" y1="11" y2="17"/>',
  camera: '<path d="M14.5 4h-5L7 7H4a2 2 0 0 0-2 2v9a2 2 0 0 0 2 2h16a2 2 0 0 0 2-2V9a2 2 0 0 0-2-2h-3l-2.5-3z"/><circle cx="12" cy="13" r="3"/>',
  imagePlus: '<path d="M16 5h6"/><path d="M19 2v6"/><path d="M21 11.5V19a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h7.5"/><path d="m21 15-3.086-3.086a2 2 0 0 0-2.828 0L6 21"/><circle cx="9" cy="9" r="2"/>',
  key: '<path d="M2.586 17.414A2 2 0 0 0 2 18.828V21a1 1 0 0 0 1 1h3a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h1a1 1 0 0 0 1-1v-1a1 1 0 0 1 1-1h.172a2 2 0 0 0 1.414-.586l.814-.814a6.5 6.5 0 1 0-4-4z"/><circle cx="16.5" cy="7.5" r=".5" fill="currentColor"/>',
  fingerprint: '<path d="M12 10a2 2 0 0 0-2 2c0 1.02-.1 2.51-.26 4"/><path d="M14 13.12c0 2.38 0 6.38-1 8.88"/><path d="M17.29 21.02c.12-.6.43-2.3.5-3.02"/><path d="M2 12a10 10 0 0 1 18-6"/><path d="M2 16h.01"/><path d="M21.8 16c.2-2 .131-5.354 0-6"/><path d="M5 19.5C5.5 18 6 15 6 12a6 6 0 0 1 .34-2"/><path d="M8.65 22c.21-.66.45-1.32.57-2"/><path d="M9 6.8a6 6 0 0 1 9 5.2v2"/>',
  shield: '<path d="M20 13c0 5-3.5 7.5-7.66 8.95a1 1 0 0 1-.67-.01C7.5 20.5 4 18 4 13V6a1 1 0 0 1 1-1c2 0 4.5-1.2 6.24-2.72a1.17 1.17 0 0 1 1.52 0C14.51 3.81 17 5 19 5a1 1 0 0 1 1 1z"/><path d="m9 12 2 2 4-4"/>',
  lock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/>',
  unlock: '<rect width="18" height="11" x="3" y="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 9.9-1"/>',
  copy: '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
  dice: '<rect width="18" height="18" x="3" y="3" rx="2" ry="2"/><path d="M16 8h.01"/><path d="M8 8h.01"/><path d="M8 16h.01"/><path d="M16 16h.01"/><path d="M12 12h.01"/>',
  logout: '<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" x2="9" y1="12" y2="12"/>',
  close: '<path d="M18 6 6 18"/><path d="m6 6 12 12"/>',
  sliders: '<line x1="21" x2="14" y1="4" y2="4"/><line x1="10" x2="3" y1="4" y2="4"/><line x1="21" x2="12" y1="12" y2="12"/><line x1="8" x2="3" y1="12" y2="12"/><line x1="21" x2="16" y1="20" y2="20"/><line x1="12" x2="3" y1="20" y2="20"/><line x1="14" x2="14" y1="2" y2="6"/><line x1="8" x2="8" y1="10" y2="14"/><line x1="16" x2="16" y1="18" y2="22"/>',
  external: '<path d="M15 3h6v6"/><path d="M10 14 21 3"/><path d="M18 13v6a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h6"/>',
  link: '<path d="M10 13a5 5 0 0 0 7.54.54l3-3a5 5 0 0 0-7.07-7.07l-1.72 1.71"/><path d="M14 11a5 5 0 0 0-7.54-.54l-3 3a5 5 0 0 0 7.07 7.07l1.71-1.71"/>',
  tag: '<path d="M12.586 2.586A2 2 0 0 0 11.172 2H4a2 2 0 0 0-2 2v7.172a2 2 0 0 0 .586 1.414l8.704 8.704a2.426 2.426 0 0 0 3.42 0l6.58-6.58a2.426 2.426 0 0 0 0-3.42z"/><circle cx="7.5" cy="7.5" r=".5" fill="currentColor"/>',
  calendar: '<path d="M8 2v4"/><path d="M16 2v4"/><rect width="18" height="18" x="3" y="4" rx="2"/><path d="M3 10h18"/>',
  note: '<path d="M16 3H5a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V8Z"/><path d="M15 3v4a2 2 0 0 0 2 2h4"/>',
  smartphone: '<rect width="14" height="20" x="5" y="2" rx="2" ry="2"/><path d="M12 18h.01"/>',
  download: '<path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" x2="12" y1="15" y2="3"/>',
  alert: '<circle cx="12" cy="12" r="10"/><line x1="12" x2="12" y1="8" y2="12"/><line x1="12" x2="12.01" y1="16" y2="16"/>',
};

function icon(name, className = "") {
  const span = document.createElement("span");
  // Nur feste Konstanten aus ICONS, keine Benutzereingaben.
  span.innerHTML = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">${ICONS[name]}</svg>`;
  const svg = span.firstChild;
  svg.setAttribute("class", `icon ${className}`.trim());
  return svg;
}

class ApiError extends Error {
  constructor(status, message, code) {
    super(message);
    this.status = status;
    this.code = code;
  }
}

/** Ruft die PHP-API auf und liefert `data` zurück oder wirft einen ApiError. */
async function api(route, { method = "GET", body, query } = {}) {
  // Arrays werden als key[]=…&key[]=… übertragen, damit PHP sie als Liste erhält.
  const params = new URLSearchParams([
    ["r", route],
    ...Object.entries(query ?? {}).flatMap(([key, value]) => (Array.isArray(value) ? value.map((item) => [`${key}[]`, item]) : [[key, value]])),
  ]);
  const headers = { "X-Requested-With": "fetch" };
  if (body !== undefined && !(body instanceof FormData)) {
    headers["Content-Type"] = "application/json";
    body = JSON.stringify(body);
  }

  let response;
  try {
    response = await fetch(`api.php?${params}`, { method, headers, body, credentials: "same-origin" });
  } catch {
    throw new ApiError(0, "Keine Verbindung zum Server");
  }

  const result = await response.json().catch(() => null);
  if (!response.ok || !result?.success) {
    // Session abgelaufen: zur Anmeldung wechseln (fehlgeschlagene Logins ausgenommen).
    if (response.status === 401 && session.user && !["login", "passkey-login", "change-password"].includes(route)) {
      session.user = null;
      setTimeout(render);
    }
    // Der Admin hat ein neues Passwort vergeben, während die Sitzung lief.
    if (result?.error?.code === "PASSWORD_CHANGE_REQUIRED" && session.user && !session.user.mustChangePassword) {
      session.user.mustChangePassword = true;
      setTimeout(render);
    }
    throw new ApiError(response.status, result?.error?.message ?? "Unbekannter Fehler", result?.error?.code);
  }
  return result.data;
}

function go(path) {
  if (location.hash === `#${path}`) render();
  else location.hash = path;
}

function setTitle(title) {
  document.title = title ? `${title} · Ausflugskiste` : "Ausflugskiste";
}

/** Bindet ein Formular an einen async-Handler mit Lade- und Fehlerzustand. */
function bindForm(form, handler) {
  const message = h("p", { class: "error", hidden: true, role: "alert" });
  const actions = form.querySelector(".form-actions");
  if (actions) actions.before(message);
  else form.append(message);
  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    const button = form.querySelector('button[type="submit"]');
    const label = button ? [...button.childNodes] : [];
    message.hidden = true;
    if (button) {
      button.disabled = true;
      button.textContent = "Bitte warten …";
    }
    try {
      await handler(new FormData(form));
    } catch (error) {
      message.className = "error";
      message.textContent = error.message;
      message.hidden = false;
      message.scrollIntoView({ block: "nearest", behavior: "smooth" });
    } finally {
      if (button) {
        button.disabled = false;
        button.replaceChildren(...label);
      }
    }
  });
  return {
    success(text) {
      message.className = "success";
      message.textContent = text;
      message.hidden = false;
    },
  };
}

let fieldCounter = 0;

/** Beschriftetes Eingabefeld; ein optionaler Hinweis wird per aria-describedby mit dem Feld verknüpft. */
function field(label, input, { hint, optional } = {}) {
  const id = input.id || `field-${++fieldCounter}`;
  input.id = id;
  if (hint) input.setAttribute("aria-describedby", `${id}-hint`);
  return h(
    "div",
    { class: "field" },
    h("label", { for: id }, label, optional ? h("span", { class: "optional" }, " (optional)") : null),
    input,
    hint ? h("span", { id: `${id}-hint`, class: "hint" }, hint) : null,
  );
}

/** Überschrift mit Icon; `subtitle` ist ein Text oder ein fertiges Element. */
function sectionTitle(iconName, title, subtitle) {
  return h(
    "div",
    { class: "section-title" },
    h("span", { class: "section-icon" }, icon(iconName)),
    h("div", null, h("h2", null, title), subtitle instanceof Node ? subtitle : subtitle ? h("p", { class: "muted small" }, subtitle) : null),
  );
}

function backLink(href, label) {
  return h("a", { href, class: "back-link" }, icon("back", "sm"), label);
}

function isSafeUrl(url) {
  return /^https?:\/\//i.test(url ?? "");
}

/** Kurzform der Domain für Link-Buttons, z. B. „openstreetmap.org“. */
function linkHost(url) {
  try {
    return new URL(url).hostname.replace(/^www\./, "");
  } catch {
    return "";
  }
}

function formatDate(iso) {
  const date = new Date(iso);
  return Number.isNaN(date.getTime()) ? "" : date.toLocaleDateString("de-DE", { day: "numeric", month: "long", year: "numeric" });
}

function plural(count, one, many) {
  return `${count} ${count === 1 ? one : many}`;
}

/** Liest einen Wert aus localStorage, ohne in privaten Fenstern zu scheitern. */
function stored(key, value) {
  try {
    if (value === undefined) return localStorage.getItem(key);
    localStorage.setItem(key, value);
  } catch {
    return null;
  }
  return value;
}

// ---------------------------------------------------------------- Rückmeldungen und Dialoge

function toast(message, type = "info") {
  const el = h(
    "div",
    { class: `toast${type === "error" ? " error" : ""}`, role: type === "error" ? "alert" : "status" },
    icon(type === "error" ? "alert" : "tick", "sm"),
    message,
  );
  const container = document.getElementById("toasts");
  container.append(el);
  // Höchstens zwei Meldungen gleichzeitig, die älteste weicht.
  while (container.children.length > 2) container.firstElementChild.remove();
  setTimeout(() => {
    el.classList.add("leaving");
    setTimeout(() => el.remove(), 250);
  }, type === "error" ? 5000 : 3000);
}

/**
 * Modaler Dialog mit Bestätigen/Abbrechen. `onConfirm` darf async sein; wirft er, bleibt der
 * Dialog offen und zeigt den Fehler. Liefert das Ergebnis von `onConfirm` (bzw. true) oder null.
 */
function dialog({ title, text, content, confirmLabel = "OK", cancelLabel = "Abbrechen", danger = false, onConfirm }) {
  return new Promise((resolve) => {
    const error = h("p", { class: "error", hidden: true, role: "alert" });
    const confirmButton = h("button", { type: "submit", class: danger ? "btn danger solid" : "btn" }, confirmLabel);
    const el = h("dialog", { "aria-labelledby": "dialog-title" });
    const form = h(
      "form",
      { method: "dialog" },
      h("h2", { id: "dialog-title" }, title),
      text ? h("p", { class: "muted" }, text) : null,
      content,
      error,
      h(
        "div",
        { class: "dialog-actions" },
        cancelLabel === null ? null : h("button", { type: "button", class: "btn secondary", onclick: () => el.close() }, cancelLabel),
        confirmButton,
      ),
    );
    el.append(form);
    let result = null;
    form.addEventListener("submit", async (event) => {
      event.preventDefault();
      confirmButton.disabled = true;
      try {
        result = onConfirm ? ((await onConfirm(new FormData(form))) ?? true) : true;
        el.close();
      } catch (failure) {
        error.textContent = failure.message;
        error.hidden = false;
        confirmButton.disabled = false;
      }
    });
    el.addEventListener("close", () => {
      el.remove();
      resolve(result);
    });
    document.body.append(el);
    el.showModal();
  });
}

function confirmDialog(title, text, confirmLabel) {
  return dialog({ title, text, confirmLabel, danger: true }).then(Boolean);
}

/** Kleiner Button nur mit Icon; `title` ist der Tooltip, falls er kürzer sein soll als `label`. */
function iconButton(iconName, label, onclick, variant = "ghost", title = label) {
  return h("button", { type: "button", class: `btn ${variant} small icon-only`, "aria-label": label, title, onclick }, icon(iconName, "sm"));
}

/** Button, der `text` in die Zwischenablage kopiert; `onFail` läuft, bevor die Fehlermeldung erscheint. */
function copyButton(text, { className = "btn secondary small", onFail } = {}) {
  const button = h("button", {
    type: "button",
    class: className,
    onclick: async () => {
      try {
        await navigator.clipboard.writeText(text);
        button.replaceChildren(icon("tick", "sm"), "Kopiert");
      } catch {
        onFail?.();
        toast("Kopieren nicht möglich – bitte markieren und manuell kopieren", "error");
      }
    },
  }, icon("copy", "sm"), "Kopieren");
  return button;
}

/** Sperrt den Button, solange `action` läuft. */
async function whileBusy(button, action) {
  button.disabled = true;
  try {
    await action();
  } finally {
    button.disabled = false;
  }
}

/** Zeigt ein vom Server erzeugtes Passwort einmalig an, mit Kopier-Button. */
function passwordDialog({ title, username, password }) {
  const input = h("input", { type: "text", class: "password-box", value: password, readonly: true, "aria-label": "Passwort", onfocus: (event) => event.target.select() });
  return dialog({
    title,
    text: `Gib ${username} dieses Passwort weiter. Es wird nur jetzt angezeigt; bei der nächsten Anmeldung muss ${username} ein eigenes Passwort festlegen.`,
    content: h("div", { class: "password-row" }, input, copyButton(password, { className: "btn secondary", onFail: () => input.focus() })),
    confirmLabel: "Fertig",
    cancelLabel: null,
  });
}

/** Fragt einen Text ab, z. B. einen neuen Namen. `save` speichert ihn und darf scheitern. */
function promptDialog({ title, label, value, maxlength, save }) {
  const input = h("input", { type: "text", name: "value", value, required: true, maxlength: String(maxlength) });
  setTimeout(() => input.select());
  return dialog({ title, content: field(label, input), confirmLabel: "Speichern", onConfirm: (data) => save(String(data.get("value")).trim()) });
}

// ---------------------------------------------------------------- Passkeys

function toBase64Url(buffer) {
  let binary = "";
  for (const byte of new Uint8Array(buffer)) binary += String.fromCharCode(byte);
  return btoa(binary).replace(/\+/g, "-").replace(/\//g, "_").replace(/=+$/, "");
}

function fromBase64Url(text) {
  return Uint8Array.from(atob(text.replace(/-/g, "+").replace(/_/g, "/")), (char) => char.charCodeAt(0));
}

function supportsPasskeys() {
  return Boolean(session.passkeys && window.isSecureContext && window.PublicKeyCredential && navigator.credentials);
}

/** Vorschlag für den Namen eines Passkeys; bekannte Passwortmanager erkennt der Server selbst. */
function deviceName() {
  const ua = navigator.userAgent;
  const platform = [[/iPhone/, "iPhone"], [/iPad/, "iPad"], [/Android/, "Android"], [/Mac OS X/, "Mac"], [/Windows/, "Windows"], [/CrOS/, "ChromeOS"], [/Linux/, "Linux"]]
    .find(([pattern]) => pattern.test(ua));
  return platform ? `Passkey auf ${platform[1]}` : "Passkey";
}

/** Übersetzt Browser-Fehler der WebAuthn-API in verständliche Meldungen. */
function passkeyError(error, creating) {
  if (error instanceof ApiError) return error;
  if (error.name === "NotAllowedError" || error.name === "AbortError") {
    const cancelled = new Error(creating ? "Das Anlegen des Passkeys wurde abgebrochen." : "Die Anmeldung mit Passkey wurde abgebrochen.");
    cancelled.cancelled = true;
    cancelled.aborted = error.name === "AbortError";
    return cancelled;
  }
  if (error.name === "InvalidStateError") return new Error("Für dein Konto ist auf diesem Gerät bzw. in diesem Passwortmanager bereits ein Passkey gespeichert.");
  if (error.name === "SecurityError") return new Error("Passkeys funktionieren nur über HTTPS unter einem Domainnamen (oder auf localhost).");
  return new Error(`Passkey-Fehler: ${error.message}`);
}

async function registerPasskey() {
  const options = await api("passkeys/options", { method: "POST" });
  const publicKey = {
    ...options,
    challenge: fromBase64Url(options.challenge),
    user: { ...options.user, id: fromBase64Url(options.user.id) },
    excludeCredentials: options.excludeCredentials.map((entry) => ({ ...entry, id: fromBase64Url(entry.id) })),
  };
  let credential;
  try {
    credential = await navigator.credentials.create({ publicKey });
  } catch (error) {
    throw passkeyError(error, true);
  }
  const { response } = credential;
  return api("passkeys", {
    method: "POST",
    body: {
      clientDataJSON: toBase64Url(response.clientDataJSON),
      attestationObject: toBase64Url(response.attestationObject),
      transports: response.getTransports?.() ?? [],
      fallbackName: deviceName(),
    },
  });
}

/** Meldet mit einem Passkey an. Mit `conditional` erscheinen Passkeys im Autofill des Benutzernamens. */
async function loginWithPasskey({ conditional = false, signal } = {}) {
  const options = await api("passkey-login/options", { method: "POST" });
  let credential;
  try {
    credential = await navigator.credentials.get({
      publicKey: { ...options, challenge: fromBase64Url(options.challenge) },
      mediation: conditional ? "conditional" : "optional",
      signal,
    });
  } catch (error) {
    throw passkeyError(error, false);
  }
  const { response } = credential;
  try {
    return await api("passkey-login", {
      method: "POST",
      body: {
        id: toBase64Url(credential.rawId),
        clientDataJSON: toBase64Url(response.clientDataJSON),
        authenticatorData: toBase64Url(response.authenticatorData),
        signature: toBase64Url(response.signature),
        userHandle: response.userHandle ? toBase64Url(response.userHandle) : null,
      },
    });
  } catch (error) {
    // Den gelöschten Passkey auch im Passwortmanager ausblenden, sofern der Browser das kann.
    if (error.code === "UNKNOWN_PASSKEY") forgetPasskey(toBase64Url(credential.rawId));
    throw error;
  }
}

function forgetPasskey(credentialId) {
  PublicKeyCredential.signalUnknownCredential?.({ rpId: location.hostname, credentialId }).catch(() => undefined);
}

let passkeyAbort = null;

function abortPasskeyRequest() {
  passkeyAbort?.abort();
  passkeyAbort = null;
}

function passkeyBanner() {
  const banner = h(
    "div",
    { class: "banner", role: "region", "aria-label": "Passkey einrichten" },
    h("span", { class: "section-icon" }, icon("key")),
    h(
      "div",
      { class: "banner-text" },
      h("strong", null, "Schneller anmelden mit Passkey"),
      h("span", { class: "muted small" }, "Künftig per Fingerabdruck, Gesichtserkennung oder Geräte-PIN anmelden – ganz ohne Passwort."),
    ),
    h(
      "div",
      { class: "row" },
      h("button", { type: "button", class: "btn ghost small", onclick: dismiss }, "Später"),
      h("button", {
        type: "button",
        class: "btn small",
        onclick: (event) => whileBusy(event.currentTarget, async () => {
          try {
            await registerPasskey();
            toast("Passkey gespeichert – ab jetzt geht die Anmeldung ohne Passwort.");
            banner.remove();
          } catch (error) {
            if (!error.cancelled) toast(error.message, "error");
          }
        }),
      }, icon("key", "sm"), "Einrichten"),
    ),
  );
  function dismiss() {
    stored(`passkeyHintDismissed:${session.user.id}`, "1");
    banner.remove();
  }
  return banner;
}

// ---------------------------------------------------------------- Zwei-Faktor-Anmeldung (TOTP)

/** Eingabefeld für den sechsstelligen Code; Handys schlagen ihn per Autofill aus SMS/App vor. */
function totpInput(props = {}) {
  return h("input", { type: "text", name: "code", inputmode: "numeric", autocomplete: "one-time-code", autocapitalize: "none", spellcheck: "false", maxlength: "20", required: true, class: "code-input", ...props });
}

/** Zeigt neue Wiederherstellungscodes einmalig an, zum Kopieren oder Herunterladen. */
function recoveryCodesDialog(codes) {
  const text = `Ausflugskiste – Wiederherstellungscodes für ${session.user.username}\nJeder Code funktioniert genau einmal.\n\n${codes.join("\n")}\n`;
  const downloadButton = h("button", {
    type: "button",
    class: "btn secondary small",
    onclick: () => {
      const link = h("a", { href: URL.createObjectURL(new Blob([text], { type: "text/plain" })), download: "ausflugskiste-wiederherstellungscodes.txt" });
      link.click();
      setTimeout(() => URL.revokeObjectURL(link.href), 1000);
    },
  }, icon("download", "sm"), "Herunterladen");
  return dialog({
    title: "Wiederherstellungscodes",
    text: "Falls du keinen Zugriff auf deine Authenticator-App hast, meldest du dich mit einem dieser Codes an – jeder funktioniert genau einmal. Bewahre sie sicher auf, z. B. im Passwortmanager oder ausgedruckt. Sie werden nur jetzt angezeigt.",
    content: [h("ol", { class: "code-list" }, codes.map((code) => h("li", null, code))), h("div", { class: "row" }, copyButton(text), downloadButton)],
    confirmLabel: "Ich habe sie gesichert",
    cancelLabel: null,
  });
}

/** Einrichtung: QR-Code scannen (oder Schlüssel abtippen), dann einen Code zur Bestätigung eingeben. */
async function setupTotp() {
  const { secret, uri } = await api("totp/setup", { method: "POST" });
  const grouped = secret.match(/.{1,4}/g).join(" ");
  const result = await dialog({
    title: "Zwei-Faktor-Anmeldung einrichten",
    content: [
      h("p", { class: "muted" }, "1. Scanne den QR-Code mit einer Authenticator-App, z. B. Aegis, 2FAS, Google oder Microsoft Authenticator – oder mit deinem Passwortmanager."),
      h("div", { class: "qr-box" }, qrCode(uri, "QR-Code für die Authenticator-App")),
      h(
        "details",
        { class: "disclosure" },
        h("summary", null, "QR-Code lässt sich nicht scannen?", icon("down", "sm")),
        h(
          "div",
          { class: "totp-manual" },
          h("a", { href: uri, class: "btn secondary small" }, icon("smartphone", "sm"), "In App auf diesem Gerät öffnen"),
          h("span", { class: "muted small" }, "Oder diesen Schlüssel in der App eingeben (zeitbasiert, 6 Stellen):"),
          h("code", { class: "totp-secret", "aria-label": "Geheimer Schlüssel" }, grouped),
          copyButton(secret),
        ),
      ),
      field("2. Code aus der App eingeben", totpInput({ placeholder: "123 456" })),
    ],
    confirmLabel: "Aktivieren",
    onConfirm: (data) => api("totp", { method: "POST", body: { code: data.get("code") } }),
  });
  if (!result) return false;
  await recoveryCodesDialog(result.recoveryCodes);
  return true;
}

/** Fragt einen aktuellen Code (oder Wiederherstellungscode) ab, bevor etwas an der 2FA geändert wird. */
function totpCodeDialog({ title, text, confirmLabel, danger, action }) {
  return dialog({
    title,
    text,
    content: field("Code aus der App", totpInput({ autofocus: true }), { hint: "Oder einen Wiederherstellungscode." }),
    confirmLabel,
    danger,
    onConfirm: (data) => action(String(data.get("code"))),
  });
}

function totpSection(status) {
  const container = h("div", { class: "field" });

  function render() {
    const low = status.enabled && status.recoveryCodesLeft <= 3;
    // replaceChildren macht aus null den Text „null“ – bedingte Teile vorher herausfiltern
    container.replaceChildren(...[
      h(
        "div",
        { class: "card-header" },
        h(
          "div",
          null,
          h("strong", null, "Zwei-Faktor-Anmeldung", status.enabled ? h("span", { class: "badge-role admin" }, "Aktiv") : null),
          h("span", { class: "muted small" }, "Bei der Anmeldung mit Passwort zusätzlich ein Code aus einer Authenticator-App. Mit Passkey nicht nötig – der ist schon zwei Faktoren in einem."),
        ),
      ),
      !status.enabled && !session.totp
        ? h("p", { class: "hint" }, "Die Zwei-Faktor-Anmeldung ist auf diesem Server nicht verfügbar (PHP-Erweiterung openssl fehlt).")
        : null,
      status.enabled
        ? h(
            "p",
            { class: low ? "error" : "muted small" },
            status.recoveryCodesLeft === 0
              ? "Keine Wiederherstellungscodes mehr übrig – erzeuge neue."
              : `Noch ${plural(status.recoveryCodesLeft, "Wiederherstellungscode", "Wiederherstellungscodes")} übrig.`,
          )
        : null,
      h(
        "div",
        { class: "row" },
        status.enabled
          ? [
              h("button", { type: "button", class: "btn secondary small", onclick: renewCodes }, "Neue Wiederherstellungscodes"),
              h("button", { type: "button", class: "btn danger small", onclick: disable }, "Abschalten"),
            ]
          : session.totp ? h("button", { type: "button", class: "btn secondary", onclick: enable }, icon("smartphone", "sm"), "Einrichten") : null,
      ),
    ].filter(Boolean));
  }

  function enable(event) {
    return whileBusy(event.currentTarget, async () => {
      try {
        if (await setupTotp()) {
          Object.assign(status, await api("totp"));
          session.user.totpEnabled = true;
          render();
          toast("Zwei-Faktor-Anmeldung ist aktiv");
        }
      } catch (error) {
        toast(error.message, "error");
      }
    });
  }

  async function renewCodes() {
    const result = await totpCodeDialog({
      title: "Neue Wiederherstellungscodes",
      text: "Die bisherigen Codes werden dabei ungültig.",
      confirmLabel: "Erzeugen",
      action: (code) => api("totp/recovery-codes", { method: "POST", body: { code } }),
    });
    if (!result) return;
    status.recoveryCodesLeft = result.recoveryCodes.length;
    render();
    await recoveryCodesDialog(result.recoveryCodes);
  }

  async function disable() {
    const done = await totpCodeDialog({
      title: "Zwei-Faktor-Anmeldung abschalten?",
      text: "Danach genügt zur Anmeldung wieder dein Passwort. Den Eintrag in der Authenticator-App kannst du anschließend löschen.",
      confirmLabel: "Abschalten",
      danger: true,
      action: (code) => api("totp", { method: "DELETE", body: { code } }),
    });
    if (!done) return;
    Object.assign(status, { enabled: false, recoveryCodesLeft: 0 });
    session.user.totpEnabled = false;
    render();
    toast("Zwei-Faktor-Anmeldung abgeschaltet");
  }

  render();
  return container;
}

// ---------------------------------------------------------------- QR-Code
// Kleiner QR-Encoder (Byte-Modus, Fehlerkorrektur M) für den Einrichtungslink der Authenticator-App,
// nach dem Verfahren von Project Nayuki (https://www.nayuki.io/page/qr-code-generator-library, MIT-Lizenz).

const QR_ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26, 30, 22, 22, 24, 24, 28, 28, 26, 26, 26, 26, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28, 28];
const QR_BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5, 5, 8, 9, 9, 10, 10, 11, 13, 14, 16, 17, 17, 18, 20, 21, 23, 25, 26, 28, 29, 31, 33, 35, 37, 38, 40, 43, 45, 47, 49];

function qrRawModules(version) {
  let result = (16 * version + 128) * version + 64;
  if (version >= 2) {
    const align = Math.floor(version / 7) + 2;
    result -= (25 * align - 10) * align - 55;
    if (version >= 7) result -= 36;
  }
  return result;
}

function qrDataCodewords(version) {
  return Math.floor(qrRawModules(version) / 8) - QR_ECC_PER_BLOCK[version] * QR_BLOCKS[version];
}

function gfMultiply(x, y) {
  let z = 0;
  for (let i = 7; i >= 0; i--) {
    z = (z << 1) ^ ((z >>> 7) * 0x11d);
    z ^= ((y >>> i) & 1) * x;
  }
  return z;
}

function reedSolomon(data, degree) {
  const divisor = new Array(degree).fill(0);
  divisor[degree - 1] = 1;
  let root = 1;
  for (let i = 0; i < degree; i++) {
    for (let j = 0; j < degree; j++) {
      divisor[j] = gfMultiply(divisor[j], root);
      if (j + 1 < degree) divisor[j] ^= divisor[j + 1];
    }
    root = gfMultiply(root, 0x02);
  }
  const result = new Array(degree).fill(0);
  for (const byte of data) {
    const factor = byte ^ result.shift();
    result.push(0);
    divisor.forEach((coefficient, i) => (result[i] ^= gfMultiply(coefficient, factor)));
  }
  return result;
}

/** Liefert die Module des QR-Codes als Matrix [y][x] (true = dunkel). */
function qrMatrix(text) {
  const bytes = new TextEncoder().encode(text);
  let version = 1;
  while (4 + (version < 10 ? 8 : 16) + bytes.length * 8 > qrDataCodewords(version) * 8) {
    if (++version > 40) throw new Error("Text zu lang für einen QR-Code");
  }

  // Daten: Modus „Byte“, Länge, Inhalt, Abschluss und Füllbytes
  const bits = [];
  const push = (value, length) => {
    for (let i = length - 1; i >= 0; i--) bits.push((value >>> i) & 1);
  };
  push(0b0100, 4);
  push(bytes.length, version < 10 ? 8 : 16);
  for (const byte of bytes) push(byte, 8);
  const capacity = qrDataCodewords(version) * 8;
  push(0, Math.min(4, capacity - bits.length));
  push(0, (8 - (bits.length % 8)) % 8);
  for (let pad = 0xec; bits.length < capacity; pad ^= 0xec ^ 0x11) push(pad, 8);
  const data = [];
  for (let i = 0; i < bits.length; i += 8) data.push(parseInt(bits.slice(i, i + 8).join(""), 2));

  // In Blöcke teilen, Fehlerkorrektur anhängen und verschränken
  const blockCount = QR_BLOCKS[version];
  const eccLength = QR_ECC_PER_BLOCK[version];
  const rawCodewords = Math.floor(qrRawModules(version) / 8);
  const shortBlocks = blockCount - (rawCodewords % blockCount);
  const shortLength = Math.floor(rawCodewords / blockCount);
  const blocks = [];
  for (let i = 0, offset = 0; i < blockCount; i++) {
    const block = data.slice(offset, offset + shortLength - eccLength + (i < shortBlocks ? 0 : 1));
    offset += block.length;
    const ecc = reedSolomon(block, eccLength);
    if (i < shortBlocks) block.push(0);
    blocks.push(block.concat(ecc));
  }
  const codewords = [];
  for (let i = 0; i < blocks[0].length; i++) {
    blocks.forEach((block, j) => {
      if (i !== shortLength - eccLength || j >= shortBlocks) codewords.push(block[i]);
    });
  }

  // Funktionsmuster: Suchmuster, Timing, Ausrichtung, Versionsinfo
  const size = version * 4 + 17;
  const modules = Array.from({ length: size }, () => new Array(size).fill(false));
  const reserved = Array.from({ length: size }, () => new Array(size).fill(false));
  const set = (x, y, dark) => {
    modules[y][x] = dark;
    reserved[y][x] = true;
  };
  for (let i = 0; i < size; i++) {
    set(6, i, i % 2 === 0);
    set(i, 6, i % 2 === 0);
  }
  for (const [cx, cy] of [[3, 3], [size - 4, 3], [3, size - 4]]) {
    for (let dy = -4; dy <= 4; dy++) {
      for (let dx = -4; dx <= 4; dx++) {
        const distance = Math.max(Math.abs(dx), Math.abs(dy));
        if (cx + dx >= 0 && cx + dx < size && cy + dy >= 0 && cy + dy < size) set(cx + dx, cy + dy, distance !== 2 && distance !== 4);
      }
    }
  }
  if (version > 1) {
    const count = Math.floor(version / 7) + 2;
    const step = version === 32 ? 26 : Math.ceil((version * 4 + 4) / (count * 2 - 2)) * 2;
    const positions = [6];
    for (let pos = size - 7; positions.length < count; pos -= step) positions.splice(1, 0, pos);
    positions.forEach((x, i) => positions.forEach((y, j) => {
      if ((i === 0 && j === 0) || (i === 0 && j === count - 1) || (i === count - 1 && j === 0)) return;
      for (let dy = -2; dy <= 2; dy++) for (let dx = -2; dx <= 2; dx++) set(x + dx, y + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
    }));
  }
  function drawFormat(mask) {
    // Fehlerkorrektur M hat die Formatbits 00.
    let rem = mask;
    for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
    const format = ((mask << 10) | rem) ^ 0x5412;
    const bit = (i) => ((format >>> i) & 1) === 1;
    for (let i = 0; i <= 5; i++) set(8, i, bit(i));
    set(8, 7, bit(6));
    set(8, 8, bit(7));
    set(7, 8, bit(8));
    for (let i = 9; i < 15; i++) set(14 - i, 8, bit(i));
    for (let i = 0; i < 8; i++) set(size - 1 - i, 8, bit(i));
    for (let i = 8; i < 15; i++) set(8, size - 15 + i, bit(i));
    set(8, size - 8, true);
  }
  drawFormat(0);
  if (version >= 7) {
    let rem = version;
    for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1f25);
    const info = (version << 12) | rem;
    for (let i = 0; i < 18; i++) {
      const dark = ((info >>> i) & 1) === 1;
      set(size - 11 + (i % 3), Math.floor(i / 3), dark);
      set(Math.floor(i / 3), size - 11 + (i % 3), dark);
    }
  }

  // Daten im Zickzack von unten rechts einsetzen
  let index = 0;
  for (let right = size - 1; right >= 1; right -= 2) {
    if (right === 6) right = 5;
    for (let vert = 0; vert < size; vert++) {
      for (let j = 0; j < 2; j++) {
        const x = right - j;
        const y = ((right + 1) & 2) === 0 ? size - 1 - vert : vert;
        if (!reserved[y][x] && index < codewords.length * 8) {
          modules[y][x] = ((codewords[index >>> 3] >>> (7 - (index & 7))) & 1) === 1;
          index++;
        }
      }
    }
  }

  // Maske mit den wenigsten Strafpunkten wählen (Läufe, 2×2-Blöcke, Hell-Dunkel-Verhältnis)
  const MASKS = [
    (x, y) => (x + y) % 2 === 0,
    (x, y) => y % 2 === 0,
    (x) => x % 3 === 0,
    (x, y) => (x + y) % 3 === 0,
    (x, y) => (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0,
    (x, y) => ((x * y) % 2) + ((x * y) % 3) === 0,
    (x, y) => (((x * y) % 2) + ((x * y) % 3)) % 2 === 0,
    (x, y) => (((x + y) % 2) + ((x * y) % 3)) % 2 === 0,
  ];
  const applyMask = (mask) => {
    for (let y = 0; y < size; y++) for (let x = 0; x < size; x++) if (!reserved[y][x] && MASKS[mask](x, y)) modules[y][x] = !modules[y][x];
  };
  function penalty() {
    let score = 0;
    let dark = 0;
    for (let a = 0; a < size; a++) {
      let rowRun = 1;
      let colRun = 1;
      for (let b = 1; b <= size; b++) {
        if (b < size && modules[a][b] === modules[a][b - 1]) rowRun++;
        else {
          if (rowRun >= 5) score += rowRun - 2;
          rowRun = 1;
        }
        if (b < size && modules[b][a] === modules[b - 1][a]) colRun++;
        else {
          if (colRun >= 5) score += colRun - 2;
          colRun = 1;
        }
      }
      for (let b = 0; b < size; b++) {
        if (modules[a][b]) dark++;
        if (a + 1 < size && b + 1 < size && modules[a][b] === modules[a][b + 1] && modules[a][b] === modules[a + 1][b] && modules[a][b] === modules[a + 1][b + 1]) score += 3;
      }
    }
    return score + Math.floor(Math.abs(dark * 20 - size * size * 10) / (size * size)) * 10;
  }
  let best = 0;
  let bestScore = Infinity;
  for (let mask = 0; mask < 8; mask++) {
    applyMask(mask);
    drawFormat(mask);
    const score = penalty();
    if (score < bestScore) [best, bestScore] = [mask, score];
    applyMask(mask);
  }
  applyMask(best);
  drawFormat(best);
  return modules;
}

/** QR-Code als SVG, immer dunkel auf hell (auch im dunklen Design), mit Ruhezone. */
function qrCode(text, label) {
  const modules = qrMatrix(text);
  const size = modules.length + 8;
  const ns = "http://www.w3.org/2000/svg";
  const svg = document.createElementNS(ns, "svg");
  svg.setAttribute("viewBox", `0 0 ${size} ${size}`);
  svg.setAttribute("class", "qr");
  svg.setAttribute("role", "img");
  svg.setAttribute("aria-label", label);
  svg.setAttribute("shape-rendering", "crispEdges");
  const background = document.createElementNS(ns, "rect");
  background.setAttribute("width", size);
  background.setAttribute("height", size);
  background.setAttribute("fill", "#fff");
  let d = "";
  modules.forEach((row, y) => row.forEach((dark, x) => {
    if (dark) d += `M${x + 4} ${y + 4}h1v1h-1z`;
  }));
  const path = document.createElementNS(ns, "path");
  path.setAttribute("d", d);
  path.setAttribute("fill", "#000");
  svg.append(background, path);
  return svg;
}

// ---------------------------------------------------------------- Ziele: Bausteine

function hueClass(text) {
  let sum = 0;
  for (const char of text) sum = (sum * 31 + char.codePointAt(0)) >>> 0;
  return `hue-${sum % 6}`;
}

/** Titelbild (erstes Foto) oder farbiger Platzhalter mit Anfangsbuchstaben. */
function cover(destination, ...extra) {
  const image = destination.images[0];
  return h(
    "div",
    { class: image ? "cover" : `cover ${hueClass(destination.name)}` },
    image
      ? h("img", { src: image.url, alt: "", loading: "lazy", decoding: "async" })
      : h("span", { class: "initial", "aria-hidden": "true" }, ([...destination.name.trim()][0] ?? "?").toUpperCase()),
    extra,
  );
}

function addressLine(address) {
  return address ? h("p", { class: "meta" }, icon("pin", "sm"), h("span", null, address)) : null;
}

function stars(rating) {
  return h(
    "span",
    { class: "stars", role: "img", "aria-label": rating ? `${rating} von 5 Sternen` : "Noch nicht bewertet" },
    [1, 2, 3, 4, 5].map((value) => icon("star", value <= (rating ?? 0) ? "sm on" : "sm")),
  );
}

/** Speichert ein Ziel mit geänderten Feldern; die API erwartet beim PUT alle Felder. */
async function saveDestination(destination, changes) {
  const { name, address, googleMapsLink, description, rating, favorite, visited, privateNotes } = destination;
  const payload = { name, address, googleMapsLink, description, rating, favorite, visited, privateNotes, tagIds: destination.tags.map((tag) => tag.id), ...changes };
  Object.assign(destination, await api(`destinations/${destination.id}`, { method: "PUT", body: payload }));
}

const FLAGS = {
  favorite: { label: "Favorit", icon: "heart", compactIcon: "heart", class: "fav", on: "Zu Favoriten hinzugefügt", off: "Aus Favoriten entfernt" },
  visited: { label: "Besucht", icon: "check", compactIcon: "tick", class: "visited", on: "Als besucht markiert", off: "Als nicht besucht markiert" },
};

/** Umschalter für Favorit/Besucht, speichert sofort und setzt bei Fehlern zurück. */
function flagButton(destination, flag, { compact = false, onChange } = {}) {
  const def = FLAGS[flag];
  const button = h("button", {
    type: "button",
    class: compact ? `flag-btn ${def.class}` : `toggle ${def.class}`,
    "aria-label": compact ? `${def.label}: ${destination.name}` : null,
    title: compact ? def.label : null,
  });
  function paint() {
    button.setAttribute("aria-pressed", String(destination[flag]));
    button.replaceChildren(icon(compact ? def.compactIcon : def.icon, flag === "favorite" ? "heart" : ""));
    if (!compact) button.append(def.label);
  }
  button.addEventListener("click", async (event) => {
    event.preventDefault();
    event.stopPropagation();
    const value = !destination[flag];
    destination[flag] = value;
    paint();
    onChange?.();
    button.disabled = true;
    try {
      await saveDestination(destination, { [flag]: value });
      toast(value ? def.on : def.off);
    } catch (error) {
      destination[flag] = !value;
      paint();
      onChange?.();
      toast(error.message, "error");
    } finally {
      button.disabled = false;
    }
  });
  paint();
  return button;
}

function destinationCard(destination, onChange) {
  const href = `#/destinations/${encodeURIComponent(destination.id)}`;
  return h(
    "article",
    { class: "dest-card" },
    cover(destination, destination.images.length ? h("span", { class: "photo-count" }, icon("camera", "sm"), destination.images.length) : null),
    h(
      "div",
      { class: "dest-flags" },
      flagButton(destination, "favorite", { compact: true, onChange }),
      flagButton(destination, "visited", { compact: true, onChange }),
    ),
    h(
      "div",
      { class: "dest-body" },
      h("h3", null, h("a", { href, class: "dest-link" }, destination.name)),
      addressLine(destination.address),
      destination.tags.length
        ? h("div", { class: "tags" }, destination.tags.slice(0, 3).map((tag) => h("span", { class: "tag" }, tag.name)), destination.tags.length > 3 ? h("span", { class: "tag" }, `+${destination.tags.length - 3}`) : null)
        : null,
      h("div", { class: "dest-meta" }, stars(destination.rating), destination.googleMapsLink ? h("span", null, linkHost(destination.googleMapsLink)) : null),
    ),
  );
}

// ---------------------------------------------------------------- Seiten: Übersicht

/** „Wohin heute?“: schlägt ein zufälliges, bevorzugt noch nicht besuchtes Ziel aus der aktuellen Auswahl vor. */
function randomDestinationDialog(destinations) {
  const open = destinations.filter((d) => !d.visited);
  const pool = open.length ? open : destinations;
  const preview = h("div", { class: "pick" });
  let current = null;
  function roll() {
    const candidates = pool.length > 1 ? pool.filter((d) => d !== current) : pool;
    current = candidates[Math.floor(Math.random() * candidates.length)];
    preview.replaceChildren(
      cover(current),
      h(
        "div",
        { class: "pick-body" },
        h("h3", null, current.name),
        addressLine(current.address),
        stars(current.rating),
      ),
    );
  }
  roll();
  dialog({
    title: "Wohin heute?",
    text: open.length ? "Ein Griff in die Kiste – ein noch nicht besuchtes Ziel aus deiner aktuellen Auswahl:" : "Hier warst du schon überall – wie wäre es mit einem Wiedersehen?",
    content: [preview, pool.length > 1 ? h("button", { type: "button", class: "btn ghost small reroll", onclick: roll }, icon("dice", "sm"), "Nochmal würfeln") : null],
    confirmLabel: "Ansehen",
    cancelLabel: "Schließen",
  }).then((confirmed) => confirmed && go(`/destinations/${encodeURIComponent(current.id)}`));
}

const listState = { search: "", segment: "all", minRating: 0, tags: new Set(), sort: "new", showFilters: false, scrollY: 0 };

const SEGMENTS = [
  ["all", "Alle", () => true],
  ["favorite", "Favoriten", (d) => d.favorite],
  ["open", "Noch offen", (d) => !d.visited],
  ["visited", "Besucht", (d) => d.visited],
];

const SORTS = {
  new: ["Neueste zuerst", (a, b) => b.createdAt.localeCompare(a.createdAt)],
  updated: ["Zuletzt geändert", (a, b) => b.updatedAt.localeCompare(a.updatedAt)],
  name: ["Name A–Z", (a, b) => a.name.localeCompare(b.name, "de")],
  rating: ["Beste Bewertung", (a, b) => (b.rating ?? 0) - (a.rating ?? 0) || a.name.localeCompare(b.name, "de")],
};

// ---------------------------------------------------------------- Bilder verkleinern

const MAX_IMAGE_SIDE = 2560;
const SHRINK_ABOVE_BYTES = 1.5 * 1024 * 1024;
const JPEG_QUALITY = 0.85;

/**
 * Verkleinert große Fotos vor dem Upload per Canvas (längste Seite höchstens MAX_IMAGE_SIDE).
 * PNG bleibt PNG (Transparenz), alles andere wird JPEG; GIFs bleiben unverändert (Animation).
 * Klappt etwas nicht oder wird die Datei nicht kleiner, wird das Original hochgeladen.
 */
async function shrinkImage(file) {
  if (!["image/jpeg", "image/png", "image/webp"].includes(file.type) || !window.createImageBitmap) return file;
  try {
    const bitmap = await createImageBitmap(file, { imageOrientation: "from-image" });
    const scale = Math.min(1, MAX_IMAGE_SIDE / Math.max(bitmap.width, bitmap.height));
    if (scale === 1 && file.size <= SHRINK_ABOVE_BYTES) {
      bitmap.close();
      return file;
    }
    const canvas = document.createElement("canvas");
    canvas.width = Math.round(bitmap.width * scale);
    canvas.height = Math.round(bitmap.height * scale);
    const context = canvas.getContext("2d");
    const type = file.type === "image/png" ? "image/png" : "image/jpeg";
    if (type === "image/jpeg") {
      // JPEG kennt keine Transparenz – sonst würden transparente WebP-Bereiche schwarz.
      context.fillStyle = "#fff";
      context.fillRect(0, 0, canvas.width, canvas.height);
    }
    context.imageSmoothingQuality = "high";
    context.drawImage(bitmap, 0, 0, canvas.width, canvas.height);
    bitmap.close();
    const blob = await new Promise((resolve) => canvas.toBlob(resolve, type, JPEG_QUALITY));
    if (!blob || blob.size >= file.size) return file;
    const name = type === file.type ? file.name : file.name.replace(/\.\w+$/, "") + ".jpg";
    return new File([blob], name, { type, lastModified: file.lastModified });
  } catch {
    return file;
  }
}

async function dashboardPage({ focusSearch = false } = {}) {
  setTitle("Ziele");
  const wantsHint = supportsPasskeys() && !stored(`passkeyHintDismissed:${session.user.id}`);
  const [destinations, passkeys] = await Promise.all([api("destinations"), wantsHint ? api("passkeys").catch(() => null) : null]);

  // Nur Tags anbieten, die bei eigenen Zielen vorkommen; verschwundene aus der Auswahl entfernen.
  const tagNames = [...new Set(destinations.flatMap((d) => d.tags.map((tag) => tag.name)))].sort((a, b) => a.localeCompare(b, "de"));
  for (const name of listState.tags) if (!tagNames.includes(name)) listState.tags.delete(name);

  const header = h(
    "div",
    { class: "page-header" },
    h("div", null, h("p", { class: "eyebrow" }, `Hallo, ${session.user.username}`), h("h1", null, "Deine Ausflugsziele")),
    destinations.length ? h("a", { href: "#/new", class: "btn desktop-only" }, icon("add", "sm"), "Neues Ziel") : null,
  );
  const banner = wantsHint && passkeys?.length === 0 ? passkeyBanner() : null;

  if (destinations.length === 0) {
    return [
      header,
      banner,
      h(
        "div",
        { class: "empty" },
        h("span", { class: "section-icon" }, icon("compass", "lg")),
        h("h2", null, "Deine Kiste ist noch leer"),
        h("p", null, "Leg dein erstes Ausflugsziel hinein – mit Fotos, Bewertung und Notizen."),
        h("a", { href: "#/new", class: "btn" }, icon("add", "sm"), "Erstes Ziel anlegen"),
      ),
    ];
  }

  const results = h("div", { class: "grid" });
  let visible = destinations;
  const pickButton = h("button", { type: "button", class: "btn secondary small", onclick: () => randomDestinationDialog(visible) }, icon("dice", "sm"), "Wohin heute?");
  const resultCount = h("span", { "aria-live": "polite" });
  const segmentBar = h("div", { class: "segments", role: "group", "aria-label": "Ziele filtern" });
  const filterBadge = h("span", { class: "badge" });
  const filterButton = h("button", {
    type: "button",
    class: "btn secondary filter-button",
    "aria-controls": "filter-panel",
    onclick: () => {
      listState.showFilters = !listState.showFilters;
      update();
    },
  }, icon("sliders", "sm"), h("span", { class: "label" }, "Filter"), filterBadge);

  const search = h("input", {
    type: "search",
    placeholder: "Ziele durchsuchen …",
    "aria-label": "Ziele durchsuchen",
    value: listState.search,
    autofocus: focusSearch,
    oninput: () => {
      listState.search = search.value;
      update();
    },
  });

  function renderSegments() {
    segmentBar.replaceChildren(
      ...SEGMENTS.map(([key, label, test]) =>
        h(
          "button",
          {
            type: "button",
            class: "segment",
            "aria-pressed": String(listState.segment === key),
            onclick: () => {
              listState.segment = key;
              update();
            },
          },
          label,
          h("span", { class: "count" }, destinations.filter(test).length),
        ),
      ),
    );
  }

  const ratingButtons = h("div", { class: "tags", role: "group", "aria-label": "Mindestbewertung" });
  const tagButtons = h("div", { class: "tags", role: "group", "aria-label": "Tags" });
  function pill(label, pressed, onclick) {
    return h("button", { type: "button", class: "tag", "aria-pressed": String(pressed), onclick }, label);
  }
  function renderFilters() {
    ratingButtons.replaceChildren(
      ...[0, 2, 3, 4, 5].map((value) =>
        pill(value === 0 ? "Alle" : value === 5 ? "★ 5" : `★ ${value}+`, listState.minRating === value, () => {
          listState.minRating = value;
          update();
        }),
      ),
    );
    tagButtons.replaceChildren(
      ...tagNames.map((name) =>
        pill(name, listState.tags.has(name), () => {
          if (listState.tags.has(name)) listState.tags.delete(name);
          else listState.tags.add(name);
          update();
        }),
      ),
    );
  }

  const resetButton = h("button", {
    type: "button",
    class: "btn ghost small",
    onclick: () => {
      Object.assign(listState, { search: "", segment: "all", minRating: 0 });
      listState.tags.clear();
      search.value = "";
      update();
    },
  }, "Zurücksetzen");

  const filterPanel = h(
    "div",
    { class: "card filter-panel", id: "filter-panel" },
    h("div", { class: "field" }, h("span", null, "Bewertung", resetButton), ratingButtons),
    tagNames.length ? h("div", { class: "field" }, h("span", null, "Tags", h("span", { class: "hint" }, "Ziel muss alle gewählten haben")), tagButtons) : null,
  );

  const sortSelect = h(
    "select",
    {
      "aria-label": "Sortierung",
      onchange: () => {
        listState.sort = sortSelect.value;
        update();
      },
    },
    Object.entries(SORTS).map(([key, [label]]) => h("option", { value: key, selected: listState.sort === key }, label)),
  );

  function matches(destination, inSegment, query) {
    if (!inSegment(destination)) return false;
    if ((destination.rating ?? 0) < listState.minRating) return false;
    for (const name of listState.tags) if (!destination.tags.some((tag) => tag.name === name)) return false;
    if (query) {
      const text = [destination.name, destination.address, destination.description, ...destination.tags.map((tag) => tag.name)].filter(Boolean).join("\n");
      if (!text.toLocaleLowerCase("de").includes(query)) return false;
    }
    return true;
  }

  function filtersActive() {
    return listState.search.trim() !== "" || listState.segment !== "all" || listState.minRating > 0 || listState.tags.size > 0;
  }

  function update() {
    const query = listState.search.trim().toLocaleLowerCase("de");
    const [, , inSegment] = SEGMENTS.find(([key]) => key === listState.segment);
    visible = destinations.filter((d) => matches(d, inSegment, query)).sort(SORTS[listState.sort][1]);
    pickButton.hidden = visible.length === 0;
    const extraFilters = (listState.minRating > 0 ? 1 : 0) + listState.tags.size;
    const filtered = filtersActive();

    renderSegments();
    renderFilters();
    filterPanel.hidden = !listState.showFilters;
    filterButton.setAttribute("aria-expanded", String(listState.showFilters));
    filterBadge.textContent = extraFilters;
    filterBadge.hidden = extraFilters === 0;
    resetButton.disabled = !filtered;
    resultCount.textContent = filtered ? `${plural(visible.length, "Treffer", "Treffer")} von ${destinations.length}` : plural(visible.length, "Ziel", "Ziele");

    results.replaceChildren(...visible.map((d) => destinationCard(d, renderSegments)));
    if (visible.length === 0) {
      results.append(
        h(
          "div",
          { class: "empty" },
          h("span", { class: "section-icon" }, icon("search", "lg")),
          h("h2", null, "Keine passenden Ziele"),
          h("p", null, "Versuche einen anderen Suchbegriff oder weniger Filter."),
          h("button", { type: "button", class: "btn secondary", onclick: () => resetButton.click() }, "Filter zurücksetzen"),
        ),
      );
    }
  }
  update();

  return [
    header,
    banner,
    h(
      "div",
      { class: "toolbar" },
      h("div", { class: "search-row" }, h("div", { class: "search-box" }, icon("search", "sm"), search), filterButton),
      segmentBar,
      filterPanel,
    ),
    h("div", { class: "result-bar" }, resultCount, h("div", { class: "row" }, pickButton, sortSelect)),
    results,
  ];
}

// ---------------------------------------------------------------- Seiten: Detail

function openLightbox(destination, startIndex, onDelete) {
  let index = startIndex;
  const image = h("img", { alt: "" });
  const counter = h("span", { class: "counter", "aria-live": "polite" });
  const openLink = h("a", { class: "btn ghost small icon-only", target: "_blank", rel: "noopener", "aria-label": "Original öffnen", title: "Original öffnen" }, icon("external", "sm"));
  const prev = h("button", { type: "button", class: "btn ghost icon-only lightbox-nav prev", "aria-label": "Vorheriges Foto", onclick: () => show(index - 1) }, icon("prev"));
  const next = h("button", { type: "button", class: "btn ghost icon-only lightbox-nav next", "aria-label": "Nächstes Foto", onclick: () => show(index + 1) }, icon("next"));

  const el = h(
    "dialog",
    { class: "lightbox", "aria-label": `Fotos von ${destination.name}` },
    h(
      "div",
      { class: "lightbox-bar" },
      counter,
      openLink,
      h("button", {
        type: "button",
        class: "btn danger small",
        onclick: async () => {
          const current = destination.images[index];
          if (!(await confirmDialog("Foto löschen?", "Das Foto wird endgültig entfernt.", "Löschen"))) return;
          try {
            await api(`images/${current.id}`, { method: "DELETE" });
            destination.images.splice(index, 1);
            onDelete();
            toast("Foto gelöscht");
            if (destination.images.length === 0) el.close();
            else show(Math.min(index, destination.images.length - 1));
          } catch (error) {
            toast(error.message, "error");
          }
        },
      }, icon("trash", "sm"), "Löschen"),
      h("button", { type: "button", class: "btn ghost small icon-only", "aria-label": "Schließen", autofocus: true, onclick: () => el.close() }, icon("close")),
    ),
    h("div", { class: "lightbox-stage" }, image, prev, next),
  );

  function show(newIndex) {
    const count = destination.images.length;
    index = (newIndex + count) % count;
    image.src = destination.images[index].url;
    openLink.href = destination.images[index].url;
    counter.textContent = `${index + 1} / ${count}`;
    prev.hidden = next.hidden = count < 2;
  }

  el.addEventListener("keydown", (event) => {
    if (event.key === "ArrowLeft") show(index - 1);
    if (event.key === "ArrowRight") show(index + 1);
  });
  // Wischen auf Touch-Geräten
  let startX = null;
  el.addEventListener("pointerdown", (event) => (startX = event.clientX));
  el.addEventListener("pointerup", (event) => {
    if (startX !== null && Math.abs(event.clientX - startX) > 60 && destination.images.length > 1) show(index + (event.clientX < startX ? 1 : -1));
    startX = null;
  });
  el.addEventListener("close", () => el.remove());

  document.body.append(el);
  show(index);
  el.showModal();
}

async function detailPage(id) {
  const destination = await api(`destinations/${id}`);
  setTitle(destination.name);

  const hero = h("div", { class: "hero" });
  function renderHero() {
    hero.replaceChildren(
      cover(destination),
      h(
        "div",
        { class: "hero-title" },
        h("h1", null, destination.name),
        addressLine(destination.address),
      ),
    );
  }
  renderHero();

  // Fotos: Galerie mit Upload-Feld, das auch Drag & Drop annimmt
  const gallery = h("div", { class: "gallery" });
  const photoCount = h("span", { class: "muted small" });
  const uploadLabel = h("span", null, "Fotos hinzufügen");
  const upload = h("input", {
    type: "file",
    accept: "image/jpeg,image/png,image/gif,image/webp",
    multiple: true,
    onchange: () => uploadFiles([...upload.files]),
  });
  const dropzone = h("label", { class: "dropzone" }, icon("imagePlus"), uploadLabel, upload);

  async function uploadFiles(files) {
    const images = files.filter((file) => file.type.startsWith("image/"));
    if (images.length === 0) return;
    dropzone.classList.add("busy");
    let uploaded = 0;
    for (const [number, file] of images.entries()) {
      uploadLabel.textContent = `Lade ${number + 1} von ${images.length} …`;
      const body = new FormData();
      body.append("destinationId", destination.id);
      body.append("file", await shrinkImage(file));
      try {
        destination.images.push(await api("images", { method: "POST", body }));
        uploaded++;
        renderGallery();
      } catch (error) {
        toast(`${file.name}: ${error.message}`, "error");
      }
    }
    if (uploaded) toast(uploaded === 1 ? "Foto hinzugefügt" : `${uploaded} Fotos hinzugefügt`);
    upload.value = "";
    uploadLabel.textContent = "Fotos hinzufügen";
    dropzone.classList.remove("busy");
  }

  for (const type of ["dragenter", "dragover"]) {
    dropzone.addEventListener(type, (event) => {
      event.preventDefault();
      dropzone.classList.add("dragover");
    });
  }
  dropzone.addEventListener("dragleave", () => dropzone.classList.remove("dragover"));
  dropzone.addEventListener("drop", (event) => {
    event.preventDefault();
    dropzone.classList.remove("dragover");
    uploadFiles([...event.dataTransfer.files]);
  });

  function renderGallery() {
    photoCount.textContent = destination.images.length ? plural(destination.images.length, "Foto", "Fotos") : "";
    gallery.replaceChildren(
      ...destination.images.map((image, index) =>
        h(
          "button",
          { type: "button", class: "thumb", "aria-label": `Foto ${index + 1} vergrößern`, onclick: () => openLightbox(destination, index, renderGallery) },
          h("img", { src: image.url, alt: "", loading: "lazy", decoding: "async" }),
        ),
      ),
      dropzone,
    );
    renderHero();
  }
  renderGallery();

  async function remove() {
    if (!(await confirmDialog(`„${destination.name}“ löschen?`, "Das Ziel wird mit allen Fotos und Notizen entfernt.", "Löschen"))) return;
    try {
      await api(`destinations/${destination.id}`, { method: "DELETE" });
      toast(`„${destination.name}“ gelöscht`);
      go("/");
    } catch (error) {
      toast(error.message, "error");
    }
  }

  const editHref = `#/destinations/${encodeURIComponent(destination.id)}/edit`;
  const mapUrl = destination.address ? `https://www.openstreetmap.org/search?query=${encodeURIComponent(destination.address)}` : null;

  return [
    backLink("#/", "Alle Ziele"),
    hero,
    h(
      "div",
      { class: "detail-layout" },
      h(
        "div",
        { class: "detail-main" },
        h(
          "section",
          { class: "card" },
          h("div", { class: "card-header" }, h("h2", null, "Über diesen Ort"), stars(destination.rating)),
          destination.description ? h("p", { class: "pre" }, destination.description) : h("p", { class: "muted" }, "Noch keine Beschreibung. ", h("a", { href: editHref, class: "success" }, "Jetzt ergänzen")),
          destination.tags.length ? h("div", { class: "tags" }, destination.tags.map((tag) => h("span", { class: "tag" }, icon("tag", "sm"), tag.name))) : null,
        ),
        h("section", { class: "card" }, h("div", { class: "card-header" }, h("h2", null, "Fotos"), photoCount), gallery),
        destination.privateNotes
          ? h("section", { class: "card" }, sectionTitle("note", "Private Notizen", "Nur für dich sichtbar"), h("p", { class: "pre note" }, destination.privateNotes))
          : null,
      ),
      h(
        "aside",
        { class: "detail-side" },
        h(
          "section",
          { class: "card" },
          h("div", { class: "row" }, flagButton(destination, "favorite"), flagButton(destination, "visited")),
          h(
            "div",
            { class: "facts" },
            destination.address
              ? h("div", { class: "fact" }, icon("pin", "sm"), h("div", null, h("span", { class: "pre" }, destination.address), h("a", { href: mapUrl, target: "_blank", rel: "noreferrer" }, "Auf Karte zeigen")))
              : null,
            isSafeUrl(destination.googleMapsLink)
              ? h("div", { class: "fact" }, icon("link", "sm"), h("div", null, h("a", { href: destination.googleMapsLink, target: "_blank", rel: "noreferrer", title: destination.googleMapsLink }, linkHost(destination.googleMapsLink) || "Link öffnen")))
              : null,
            h("div", { class: "fact" }, icon("calendar", "sm"), h("div", null, h("span", { class: "muted small" }, `Hinzugefügt am ${formatDate(destination.createdAt)}`))),
          ),
          isSafeUrl(destination.googleMapsLink)
            ? h("a", { href: destination.googleMapsLink, target: "_blank", rel: "noreferrer", class: "btn" }, icon("external", "sm"), "Link öffnen", h("span", { class: "link-host" }, linkHost(destination.googleMapsLink)))
            : null,
          h("a", { href: editHref, class: "btn secondary" }, icon("pencil", "sm"), "Bearbeiten"),
          h("button", { type: "button", class: "btn danger", onclick: remove }, icon("trash", "sm"), "Löschen"),
        ),
      ),
    ),
  ];
}

// ---------------------------------------------------------------- Seiten: Formular

function ratingInput(value) {
  const label = h("span", { class: "muted small" });
  const starsGroup = h("span", { class: "rating-stars" });
  // Umgekehrte Reihenfolge, damit CSS mit „~“ die niedrigeren Sterne einfärben kann.
  for (const count of [5, 4, 3, 2, 1]) {
    const id = `rating-${count}`;
    const text = plural(count, "Stern", "Sterne");
    starsGroup.append(
      h("input", { type: "radio", name: "rating", id, value: String(count), checked: value === count, onchange: update }),
      h("label", { for: id, title: text }, icon("star"), h("span", { class: "sr-only" }, text)),
    );
  }
  const clear = h("button", {
    type: "button",
    class: "btn ghost small",
    onclick: () => {
      for (const input of starsGroup.querySelectorAll("input")) input.checked = false;
      update();
    },
  }, "Keine");
  function update() {
    const checked = starsGroup.querySelector("input:checked");
    label.textContent = checked ? `${checked.value} von 5` : "Noch nicht bewertet";
    clear.hidden = !checked;
  }
  update();
  return h("fieldset", { class: "rating-input" }, h("legend", null, "Bewertung"), starsGroup, label, clear);
}

function switchRow(name, label, iconName, checked) {
  return h("label", { class: "switch-row" }, h("span", null, icon(iconName, "sm"), label), h("input", { type: "checkbox", role: "switch", class: "switch", name, checked }));
}

function destinationForm(destination, tags) {
  const selected = new Set(destination?.tags.map((tag) => tag.id) ?? []);
  const tagList = h("div", { class: "tags", role: "group", "aria-label": "Tags auswählen" });

  function renderTags() {
    tagList.replaceChildren(
      ...tags.map((tag) =>
        h(
          "button",
          {
            type: "button",
            class: "tag",
            "aria-pressed": String(selected.has(tag.id)),
            onclick: () => {
              if (selected.has(tag.id)) selected.delete(tag.id);
              else selected.add(tag.id);
              renderTags();
            },
          },
          selected.has(tag.id) ? icon("tick", "sm") : null,
          tag.name,
        ),
      ),
    );
    if (tags.length === 0) tagList.append(h("span", { class: "muted small" }, "Noch keine Tags vorhanden – lege unten den ersten an."));
  }
  renderTags();

  const newTag = h("input", { type: "text", placeholder: "Neuer Tag, z. B. Wandern", "aria-label": "Neuer Tag", maxlength: "50" });
  const tagError = h("p", { class: "error", hidden: true, role: "alert" });
  async function addTag() {
    const name = newTag.value.trim();
    if (!name) return;
    tagError.hidden = true;
    // Gibt es den Tag schon, wird er einfach ausgewählt.
    const existing = tags.find((tag) => tag.name.toLocaleLowerCase("de") === name.toLocaleLowerCase("de"));
    try {
      const tag = existing ?? (await api("tags", { method: "POST", body: { name } }));
      if (!existing) {
        tags.push(tag);
        tags.sort((a, b) => a.name.localeCompare(b.name, "de"));
      }
      selected.add(tag.id);
      newTag.value = "";
      renderTags();
    } catch (error) {
      tagError.textContent = error.message;
      tagError.hidden = false;
    }
  }
  newTag.addEventListener("keydown", (event) => {
    if (event.key === "Enter") {
      event.preventDefault();
      addTag();
    }
  });

  const cancelHref = destination ? `#/destinations/${encodeURIComponent(destination.id)}` : "#/";
  const form = h(
    "form",
    null,
    h(
      "section",
      { class: "card" },
      h("h2", null, "Grunddaten"),
      field("Name", h("input", { type: "text", name: "name", value: destination?.name ?? "", required: true, maxlength: "200", autofocus: !destination, placeholder: "z. B. Burgruine am See" })),
      field("Adresse", h("input", { type: "text", name: "address", value: destination?.address ?? "", maxlength: "500", autocomplete: "street-address" }), { optional: true }),
      field(
        "Link",
        h("input", { type: "url", name: "googleMapsLink", value: destination?.googleMapsLink ?? "", placeholder: "https://…" }),
        { optional: true, hint: "Wo man mehr erfährt oder den Weg findet – z. B. Google Maps, OpenStreetMap, Komoot oder die Webseite des Ausflugsziels." },
      ),
    ),
    h(
      "section",
      { class: "card" },
      h("h2", null, "Bewertung & Status"),
      ratingInput(destination?.rating ?? null),
      h("div", { class: "form-grid two" }, switchRow("favorite", "Favorit", "heart", destination?.favorite ?? false), switchRow("visited", "Schon besucht", "check", destination?.visited ?? false)),
    ),
    h(
      "section",
      { class: "card" },
      h("h2", null, "Beschreibung & Notizen"),
      field("Beschreibung", h("textarea", { name: "description", value: destination?.description ?? "", maxlength: "5000", placeholder: "Was macht den Ort besonders?" }), { optional: true }),
      field("Private Notizen", h("textarea", { name: "privateNotes", value: destination?.privateNotes ?? "", maxlength: "5000", placeholder: "Öffnungszeiten, Parkplatz, Tipps …" }), { optional: true, hint: "Nur für dich sichtbar." }),
    ),
    h(
      "section",
      { class: "card" },
      h("h2", null, "Tags"),
      tagList,
      h("div", { class: "row" }, newTag, h("button", { type: "button", class: "btn secondary small", onclick: addTag }, icon("add", "sm"), "Hinzufügen")),
      tagError,
    ),
    h(
      "div",
      { class: "form-actions" },
      h("a", { href: cancelHref, class: "btn secondary" }, "Abbrechen"),
      h("button", { type: "submit", class: "btn" }, icon("tick", "sm"), destination ? "Änderungen speichern" : "Ziel speichern"),
    ),
  );

  bindForm(form, async (data) => {
    const payload = {
      name: data.get("name"),
      address: data.get("address"),
      googleMapsLink: data.get("googleMapsLink"),
      description: data.get("description"),
      rating: data.get("rating") ? Number(data.get("rating")) : null,
      favorite: data.has("favorite"),
      visited: data.has("visited"),
      privateNotes: data.get("privateNotes"),
      tagIds: [...selected],
    };
    const saved = destination
      ? await api(`destinations/${destination.id}`, { method: "PUT", body: payload })
      : await api("destinations", { method: "POST", body: payload });
    toast(destination ? "Änderungen gespeichert" : "Ziel angelegt – füge jetzt Fotos hinzu");
    go(`/destinations/${saved.id}`);
  });

  return form;
}

async function newPage() {
  setTitle("Neues Ziel");
  const tags = await api("tags");
  return [
    backLink("#/", "Alle Ziele"),
    h("div", { class: "page-header" }, h("div", null, h("p", { class: "eyebrow" }, "Neu"), h("h1", null, "Neues Ausflugsziel"))),
    destinationForm(null, tags),
  ];
}

async function editPage(id) {
  const [destination, tags] = await Promise.all([api(`destinations/${id}`), api("tags")]);
  setTitle(`${destination.name} bearbeiten`);
  return [
    backLink(`#/destinations/${encodeURIComponent(destination.id)}`, destination.name),
    h("div", { class: "page-header" }, h("div", null, h("p", { class: "eyebrow" }, "Bearbeiten"), h("h1", null, destination.name))),
    destinationForm(destination, tags),
  ];
}

// ---------------------------------------------------------------- Seiten: Profil

/** Listeneintrag eines Passkeys mit Name, Datum und Aktions-Buttons. */
function passkeyItem(passkey, ...actions) {
  return h(
    "li",
    null,
    h("span", { class: "list-icon" }, icon("key", "sm")),
    h(
      "div",
      { class: "grow" },
      h("span", null, passkey.name),
      h("span", { class: "muted small" }, `Angelegt am ${formatDate(passkey.createdAt)}`, passkey.lastUsedAt ? ` · zuletzt genutzt am ${formatDate(passkey.lastUsedAt)}` : " · noch nicht genutzt"),
    ),
    actions,
  );
}

function passkeySection(passkeys) {
  const list = h("ul", { class: "list" });
  const addButton = h("button", { type: "button", class: "btn secondary", onclick: add }, icon("add", "sm"), "Passkey hinzufügen");

  async function rename(passkey) {
    const renamed = await promptDialog({
      title: "Passkey umbenennen",
      label: "Name",
      value: passkey.name,
      maxlength: 60,
      save: (name) => api(`passkeys/${passkey.id}`, { method: "PUT", body: { name } }),
    });
    if (renamed) {
      Object.assign(passkey, renamed);
      renderList();
    }
  }

  async function remove(passkey) {
    const confirmed = await confirmDialog(
      "Passkey entfernen?",
      `Mit „${passkey.name}“ kannst du dich danach nicht mehr anmelden. Dein Passwort bleibt gültig.`,
      "Entfernen",
    );
    if (!confirmed) return;
    try {
      const result = await api(`passkeys/${passkey.id}`, { method: "DELETE" });
      forgetPasskey(result.credentialId);
      passkeys.splice(passkeys.indexOf(passkey), 1);
      renderList();
      toast("Passkey entfernt");
    } catch (error) {
      toast(error.message, "error");
    }
  }

  function renderList() {
    list.replaceChildren(
      ...passkeys.map((passkey) =>
        passkeyItem(
          passkey,
          iconButton("pencil", `${passkey.name} umbenennen`, () => rename(passkey), "ghost", "Umbenennen"),
          iconButton("trash", `${passkey.name} entfernen`, () => remove(passkey), "danger", "Entfernen"),
        ),
      ),
    );
    list.hidden = passkeys.length === 0;
  }

  function add() {
    return whileBusy(addButton, async () => {
      try {
        passkeys.push(await registerPasskey());
        renderList();
        toast("Passkey gespeichert");
      } catch (error) {
        if (!error.cancelled) toast(error.message, "error");
      }
    });
  }

  renderList();
  return h(
    "div",
    { class: "field" },
    h("div", { class: "card-header" }, h("div", null, h("strong", null, "Passkeys"), h("span", { class: "muted small" }, "Anmelden per Fingerabdruck, Gesicht oder Geräte-PIN – sicherer als ein Passwort und nicht abfischbar."))),
    list,
    supportsPasskeys()
      ? h("div", null, addButton)
      : h("p", { class: "hint" }, session.passkeys
        ? "Dein Browser unterstützt hier keine Passkeys. Sie benötigen eine HTTPS-Verbindung (oder localhost)."
        : "Passkeys sind auf diesem Server nicht verfügbar (PHP-Erweiterung openssl fehlt)."),
  );
}

/** Benutzerverwaltung für Admins: anlegen (mit erzeugtem Passwort), Passwort zurücksetzen, Passkeys entfernen, sperren, löschen. */
function usersCard(users) {
  const list = h("ul", { class: "list" });
  const count = h("p", { class: "muted small" });

  function details(entry) {
    return [
      `Seit ${formatDate(entry.createdAt)}`,
      plural(entry.destinationCount, "Ziel", "Ziele"),
      entry.passkeyCount ? plural(entry.passkeyCount, "Passkey", "Passkeys") : null,
      entry.totpEnabled ? "2FA aktiv" : null,
      entry.mustChangePassword ? "Passwort noch nicht geändert" : null,
      entry.disabled ? `gesperrt seit ${formatDate(entry.disabledAt)}` : null,
    ].filter(Boolean).join(" · ");
  }

  function renderList() {
    users.sort((a, b) => a.username.localeCompare(b.username, "de"));
    const disabled = users.filter((entry) => entry.disabled).length;
    count.textContent = plural(users.length, "Zugang", "Zugänge") + (disabled ? `, davon ${disabled} gesperrt` : "");
    list.replaceChildren(
      ...users.map((entry) =>
        h(
          "li",
          { class: entry.disabled ? "is-disabled" : null },
          h("span", { class: "list-icon" }, entry.disabled ? icon("lock", "sm") : entry.username[0]?.toUpperCase()),
          h(
            "div",
            { class: "grow" },
            h(
              "span",
              { class: "user-name" },
              entry.username,
              h("span", { class: `badge-role${entry.role === "ADMIN" ? " admin" : ""}` }, entry.role === "ADMIN" ? "Admin" : "Benutzer"),
              entry.disabled ? h("span", { class: "badge-role locked" }, "Gesperrt") : null,
            ),
            h("span", { class: "muted small" }, details(entry)),
          ),
          entry.id === session.user.id
            ? h("span", { class: "muted small" }, "Du")
            : h(
                "span",
                { class: "row user-actions" },
                iconButton("key", `Neues Passwort für ${entry.username}`, () => resetPassword(entry)),
                iconButton("fingerprint", `Passkeys von ${entry.username}`, () => managePasskeys(entry)),
                entry.totpEnabled ? iconButton("smartphone", `Zwei-Faktor-Anmeldung von ${entry.username} abschalten`, () => resetTotp(entry)) : null,
                iconButton(entry.disabled ? "unlock" : "lock", `${entry.username} ${entry.disabled ? "entsperren" : "sperren"}`, () => toggleLock(entry)),
                iconButton("trash", `${entry.username} löschen`, () => remove(entry), "danger"),
              ),
        ),
      ),
    );
  }

  async function resetPassword(entry) {
    const result = await dialog({
      title: `Neues Passwort für ${entry.username}?`,
      text: "Das bisherige Passwort funktioniert danach nicht mehr. Passkeys bleiben gültig.",
      confirmLabel: "Passwort erzeugen",
      onConfirm: () => api(`users/${entry.id}/password`, { method: "POST" }),
    });
    if (!result) return;
    entry.mustChangePassword = true;
    renderList();
    await passwordDialog({ title: `Neues Passwort für ${entry.username}`, username: entry.username, password: result.password });
  }

  // Passkeys eines Benutzers ansehen und einzeln entfernen, z. B. nach einem verlorenen Gerät.
  async function managePasskeys(entry) {
    let passkeys;
    try {
      passkeys = await api(`users/${entry.id}/passkeys`);
    } catch (error) {
      toast(error.message, "error");
      return;
    }
    const list = h("ul", { class: "list" });
    const empty = h("p", { class: "muted" }, `${entry.username} hat keine Passkeys.`);

    function renderPasskeys() {
      list.replaceChildren(
        ...passkeys.map((passkey) =>
          passkeyItem(
            passkey,
            iconButton("trash", `${passkey.name} entfernen`, async () => {
              const confirmed = await confirmDialog(
                "Passkey entfernen?",
                `${entry.username} kann sich mit „${passkey.name}“ danach nicht mehr anmelden. Das Passwort und andere Passkeys bleiben gültig.`,
                "Entfernen",
              );
              if (!confirmed) return;
              try {
                await api(`users/${entry.id}/passkeys/${passkey.id}`, { method: "DELETE" });
                passkeys.splice(passkeys.indexOf(passkey), 1);
                entry.passkeyCount = passkeys.length;
                renderPasskeys();
                renderList();
                toast("Passkey entfernt");
              } catch (error) {
                toast(error.message, "error");
              }
            }, "danger"),
          ),
        ),
      );
      list.hidden = passkeys.length === 0;
      empty.hidden = passkeys.length > 0;
    }

    renderPasskeys();
    await dialog({ title: `Passkeys von ${entry.username}`, content: [list, empty], confirmLabel: "Schließen", cancelLabel: null });
  }

  // Nach einem verlorenen Handy ohne Wiederherstellungscodes: 2FA abschalten, damit das Passwort wieder genügt.
  async function resetTotp(entry) {
    const done = await dialog({
      title: `Zwei-Faktor-Anmeldung von ${entry.username} abschalten?`,
      text: `${entry.username} kann sich danach allein mit dem Passwort anmelden und die Zwei-Faktor-Anmeldung im Profil neu einrichten. Tu das nur, wenn du sicher bist, dass die Anfrage wirklich von ${entry.username} kommt.`,
      confirmLabel: "Abschalten",
      danger: true,
      onConfirm: () => api(`users/${entry.id}/totp`, { method: "DELETE" }),
    });
    if (!done) return;
    entry.totpEnabled = false;
    renderList();
    toast(`Zwei-Faktor-Anmeldung von ${entry.username} abgeschaltet`);
  }

  async function toggleLock(entry) {
    const disabled = !entry.disabled;
    if (disabled && !(await confirmDialog(
      `${entry.username} sperren?`,
      `${entry.username} kann sich nicht mehr anmelden und wird sofort abgemeldet. Ziele und Fotos bleiben erhalten; entsperren ist jederzeit möglich.`,
      "Sperren",
    ))) return;
    try {
      Object.assign(entry, await api(`users/${entry.id}`, { method: "PUT", body: { disabled } }));
      renderList();
      toast(disabled ? `${entry.username} gesperrt` : `${entry.username} entsperrt`);
    } catch (error) {
      toast(error.message, "error");
    }
  }

  async function remove(entry) {
    const input = h("input", { type: "text", name: "confirm", autocomplete: "off", autocapitalize: "none", spellcheck: "false", required: true });
    const deleted = await dialog({
      title: `${entry.username} löschen?`,
      text: entry.destinationCount
        ? `Der Zugang wird mit ${plural(entry.destinationCount, "Ziel", "Zielen")}, allen Fotos und Passkeys endgültig gelöscht. Wenn die Daten erhalten bleiben sollen, sperre den Zugang stattdessen.`
        : "Der Zugang wird samt Passkeys endgültig gelöscht.",
      content: field(`Zur Bestätigung „${entry.username}“ eingeben`, input),
      confirmLabel: "Endgültig löschen",
      danger: true,
      onConfirm: async (data) => {
        if (String(data.get("confirm")).trim() !== entry.username) throw new Error("Der Benutzername stimmt nicht überein");
        await api(`users/${entry.id}`, { method: "DELETE" });
      },
    });
    if (!deleted) return;
    users.splice(users.indexOf(entry), 1);
    renderList();
    toast(`${entry.username} gelöscht`);
  }

  const userForm = h(
    "form",
    null,
    field("Benutzername", h("input", { type: "text", name: "username", autocomplete: "off", autocapitalize: "none", spellcheck: "false", minlength: "3", maxlength: "64", required: true }), {
      hint: "Ein sicheres Passwort wird automatisch erzeugt und einmalig angezeigt.",
    }),
    switchRow("admin", "Administratorrechte", "shield", false),
    h("div", null, h("button", { type: "submit", class: "btn" }, icon("add", "sm"), "Benutzer anlegen")),
  );
  bindForm(userForm, async (data) => {
    const created = await api("users", { method: "POST", body: { username: data.get("username"), role: data.has("admin") ? "ADMIN" : "USER" } });
    const { password, ...entry } = created;
    users.push(entry);
    renderList();
    userForm.reset();
    await passwordDialog({ title: `Zugang für ${entry.username} angelegt`, username: entry.username, password });
  });

  renderList();
  return h(
    "section",
    { class: "card" },
    sectionTitle("users", "Benutzer", count),
    list,
    h("details", { class: "disclosure" }, h("summary", null, "Neuen Benutzer anlegen", icon("down", "sm")), userForm),
  );
}

async function profilePage() {
  setTitle("Profil");
  const user = session.user;
  const isAdmin = user.role === "ADMIN";
  const [tags, users, passkeys, totp] = await Promise.all([api("tags"), isAdmin ? api("users") : [], api("passkeys"), api("totp")]);

  const passwordForm = h(
    "form",
    null,
    field("Aktuelles Passwort", h("input", { type: "password", name: "currentPassword", autocomplete: "current-password", required: true })),
    h(
      "div",
      { class: "form-grid two" },
      field("Neues Passwort", h("input", { type: "password", name: "newPassword", autocomplete: "new-password", minlength: "6", required: true })),
      field("Wiederholen", h("input", { type: "password", name: "repeatPassword", autocomplete: "new-password", minlength: "6", required: true })),
    ),
    h("div", null, h("button", { type: "submit", class: "btn" }, "Passwort ändern")),
  );
  const passwordStatus = bindForm(passwordForm, async (data) => {
    if (data.get("newPassword") !== data.get("repeatPassword")) throw new Error("Die neuen Passwörter stimmen nicht überein");
    await api("change-password", { method: "POST", body: { currentPassword: data.get("currentPassword"), newPassword: data.get("newPassword") } });
    passwordForm.reset();
    passwordStatus.success("Passwort wurde geändert.");
  });

  async function renameTag(tag) {
    const saved = await promptDialog({
      title: "Tag umbenennen",
      label: "Name",
      value: tag.name,
      maxlength: 50,
      save: (name) => api(`tags/${tag.id}`, { method: "PUT", body: { name } }),
    });
    if (saved) {
      toast("Tag umbenannt");
      render();
    }
  }

  async function deleteTag(tag) {
    if (!(await confirmDialog(`Tag „${tag.name}“ löschen?`, "Er wird bei allen Zielen entfernt.", "Löschen"))) return;
    try {
      await api(`tags/${tag.id}`, { method: "DELETE" });
      toast("Tag gelöscht");
      render();
    } catch (error) {
      toast(error.message, "error");
    }
  }

  const tagSection = h(
    "section",
    { class: "card" },
    sectionTitle("tag", "Tags", isAdmin ? "Tags gelten für alle Benutzer." : "Neue Tags legst du beim Bearbeiten eines Ziels an."),
    tags.length === 0 ? h("p", { class: "muted" }, "Noch keine Tags vorhanden.") : null,
    isAdmin
      ? h(
          "ul",
          { class: "list" },
          tags.map((tag) =>
            h(
              "li",
              null,
              h("div", { class: "grow" }, h("span", null, tag.name)),
              iconButton("pencil", `${tag.name} umbenennen`, () => renameTag(tag), "ghost", "Umbenennen"),
              iconButton("trash", `${tag.name} löschen`, () => deleteTag(tag), "danger", "Löschen"),
            ),
          ),
        )
      : h("div", { class: "tags" }, tags.map((tag) => h("span", { class: "tag" }, tag.name))),
  );

  return [
    h(
      "section",
      { class: "card profile-head" },
      h("span", { class: "avatar", "aria-hidden": "true" }, user.username[0]?.toUpperCase()),
      h("div", null, h("h1", null, user.username), h("span", { class: `badge-role${isAdmin ? " admin" : ""}` }, isAdmin ? "Administrator" : "Benutzer")),
      h("button", { type: "button", class: "btn secondary", onclick: logout }, icon("logout", "sm"), "Abmelden"),
    ),
    h(
      "section",
      { class: "card" },
      sectionTitle("shield", "Anmeldung & Sicherheit"),
      passkeySection(passkeys),
      totpSection(totp),
      h("details", { class: "disclosure" }, h("summary", null, h("span", { class: "row" }, icon("lock", "sm"), "Passwort ändern"), icon("down", "sm")), passwordForm),
    ),
    tagSection,
    isAdmin ? usersCard(users) : null,
    appFooter(),
    updateNote(isAdmin),
  ];
}

// Hinweis auf eine neuere Version auf GitHub; lädt nachträglich, damit das Profil nicht auf GitHub wartet.
function updateNote(isAdmin) {
  const note = h("p", { class: "footer-note" }, "Suche nach neuer Version …");
  api("version")
    .then(({ installed, latest }) => {
      if (!latest) return note.replaceChildren("Die neueste Version auf GitHub konnte nicht abgerufen werden.");
      if (compareVersions(latest, installed) <= 0) return note.replaceChildren("Das ist die neueste Version.");
      note.replaceChildren(
        `Version ${latest} ist auf GitHub erschienen. `,
        isAdmin ? h("a", { href: `${session.sourceUrl}/releases/latest`, target: "_blank", rel: "noopener" }, "Zum Release") : "Dein Admin kann sie einspielen.",
      );
    })
    .catch(() => note.replaceChildren());
  return note;
}

/** SemVer ohne Vorabversionen vergleichen: < 0, 0 oder > 0 */
function compareVersions(a, b) {
  const pa = a.split(".").map(Number);
  const pb = b.split(".").map(Number);
  for (let i = 0; i < 3; i++) {
    const diff = (pa[i] || 0) - (pb[i] || 0);
    if (diff) return diff;
  }
  return 0;
}

// Version und Link zum Quelltext (von der AGPL für Nutzer über das Netzwerk verlangt).
function appFooter() {
  return h(
    "p",
    { class: "footer-note" },
    session.version ? `Ausflugskiste ${session.version} · ` : "Ausflugskiste · ",
    h("a", { href: session.sourceUrl, target: "_blank", rel: "noopener" }, "Quelltext"),
    " · Lizenz: AGPL-3.0",
  );
}

async function logout() {
  await api("logout", { method: "POST" }).catch(() => undefined);
  session.user = null;
  toast("Du wurdest abgemeldet");
  go("/login");
}

// ---------------------------------------------------------------- Seiten: Anmeldung und Einrichtung

function signedIn(user) {
  session.user = user;
  session.needsSetup = false;
  document.getElementById("toasts").replaceChildren();
  toast(`Hallo, ${user.username}!`);
  go("/");
}

function authPage({ setup }) {
  setTitle(setup ? "Einrichtung" : "Anmelden");
  const passkeys = !setup && supportsPasskeys();
  const passkeyError = h("p", { class: "error", hidden: true, role: "alert" });

  const form = h(
    "form",
    null,
    field("Benutzername", h("input", { type: "text", name: "username", autocomplete: passkeys ? "username webauthn" : "username", required: true, autocapitalize: "none", spellcheck: "false", autofocus: true })),
    field("Passwort", h("input", { type: "password", name: "password", autocomplete: setup ? "new-password" : "current-password", required: true, minlength: setup ? "6" : null })),
    setup ? field("Passwort wiederholen", h("input", { type: "password", name: "repeatPassword", autocomplete: "new-password", required: true })) : null,
    h("button", { type: "submit", class: passkeys ? "btn secondary block" : "btn block" }, setup ? "Zugang anlegen" : "Mit Passwort anmelden"),
  );
  bindForm(form, async (data) => {
    if (setup && data.get("password") !== data.get("repeatPassword")) throw new Error("Die Passwörter stimmen nicht überein");
    abortPasskeyRequest();
    const result = await api(setup ? "setup" : "login", { method: "POST", body: { username: data.get("username"), password: data.get("password") } });
    if (result.totpRequired) card.replaceChildren(totpLoginForm(() => card.replaceChildren(...passwordStep)));
    else signedIn(result.user);
  });

  async function passkeyLogin(conditional) {
    abortPasskeyRequest();
    passkeyAbort = new AbortController();
    passkeyError.hidden = true;
    try {
      signedIn((await loginWithPasskey({ conditional, signal: passkeyAbort.signal })).user);
    } catch (error) {
      if (error.aborted || (conditional && error.cancelled)) return;
      passkeyError.textContent = error.message;
      passkeyError.hidden = false;
    }
  }

  // Passkeys zusätzlich im Autofill des Benutzernamens anbieten (Conditional UI).
  if (passkeys) {
    PublicKeyCredential.isConditionalMediationAvailable?.().then((available) => available && passkeyLogin(true)).catch(() => undefined);
  }

  const passwordStep = passkeys
    ? [
        h("button", { type: "button", class: "btn block", onclick: (event) => whileBusy(event.currentTarget, () => passkeyLogin(false)) }, icon("key", "sm"), "Mit Passkey anmelden"),
        passkeyError,
        h("div", { class: "divider" }, "oder mit Passwort"),
        form,
      ]
    : [form];
  const card = h("div", { class: "card" }, passwordStep);

  return h(
    "div",
    { class: "auth" },
    h(
      "div",
      { class: "auth-head" },
      h("img", { src: "icon.svg", alt: "" }),
      h("h1", null, setup ? "Willkommen!" : "Willkommen zurück"),
      h("p", { class: "muted" }, setup ? "Es gibt noch keine Benutzer. Lege den ersten Zugang an – er erhält Administratorrechte." : "Melde dich an, um deine Ausflugsziele zu sehen."),
    ),
    card,
    setup ? h("p", { class: "muted small" }, "Nach der Einrichtung kannst du zusätzlich einen Passkey anlegen.") : null,
    appFooter(),
  );
}

/** Zweiter Schritt nach dem Passwort: Code aus der Authenticator-App oder ein Wiederherstellungscode. */
function totpLoginForm(back) {
  let recovery = false;
  const input = totpInput({ autofocus: true });
  const label = h("label", { for: "totp-code" });
  input.id = "totp-code";
  const intro = h("p", { class: "muted small" });
  const switchButton = h("button", { type: "button", class: "btn ghost small", onclick: () => toggle(!recovery) });
  function toggle(value) {
    recovery = value;
    label.textContent = recovery ? "Wiederherstellungscode" : "Code aus der App";
    intro.textContent = recovery
      ? "Gib einen deiner Wiederherstellungscodes ein, z. B. „k7mp-x9qr“. Jeder funktioniert nur einmal."
      : "Öffne deine Authenticator-App und gib den sechsstelligen Code für die Ausflugskiste ein.";
    switchButton.textContent = recovery ? "Doch Code aus der App verwenden" : "Kein Zugriff auf die App?";
    input.inputMode = recovery ? "text" : "numeric";
    input.autocomplete = recovery ? "off" : "one-time-code";
    input.placeholder = recovery ? "xxxx-xxxx" : "123 456";
    input.value = "";
    input.focus();
  }

  const form = h(
    "form",
    null,
    sectionTitle("smartphone", "Zwei-Faktor-Anmeldung", intro),
    h("div", { class: "field" }, label, input),
    h("button", { type: "submit", class: "btn block" }, "Anmelden"),
    h("div", { class: "row spread" }, h("button", { type: "button", class: "btn ghost small", onclick: back }, icon("back", "sm"), "Zurück"), switchButton),
  );
  bindForm(form, async (data) => {
    try {
      const result = await api("login/totp", { method: "POST", body: { code: data.get("code") } });
      signedIn(result.user);
      if (result.recoveryCodesLeft !== null) {
        toast(result.recoveryCodesLeft
          ? `Wiederherstellungscode verbraucht – noch ${result.recoveryCodesLeft} übrig.`
          : "Das war dein letzter Wiederherstellungscode. Erzeuge im Profil neue.", result.recoveryCodesLeft > 3 ? "info" : "error");
      }
    } catch (error) {
      if (error.code === "TOTP_EXPIRED") {
        back();
        toast(error.message, "error");
        return;
      }
      input.select();
      throw error;
    }
  });
  toggle(false);
  setTimeout(() => input.focus());
  return form;
}

/** Nach einem vom Admin vergebenen Passwort: eigenes Passwort festlegen, bevor es weitergeht. */
function changePasswordPage() {
  setTitle("Neues Passwort");
  const form = h(
    "form",
    null,
    // Für Passwortmanager, damit sie das neue Passwort dem richtigen Benutzer zuordnen.
    h("input", { type: "text", name: "username", value: session.user.username, autocomplete: "username", hidden: true, readonly: true }),
    field("Neues Passwort", h("input", { type: "password", name: "newPassword", autocomplete: "new-password", minlength: "6", required: true, autofocus: true }), { hint: "Mindestens 6 Zeichen." }),
    field("Wiederholen", h("input", { type: "password", name: "repeatPassword", autocomplete: "new-password", minlength: "6", required: true })),
    h("button", { type: "submit", class: "btn block" }, "Passwort speichern"),
  );
  bindForm(form, async (data) => {
    if (data.get("newPassword") !== data.get("repeatPassword")) throw new Error("Die Passwörter stimmen nicht überein");
    await api("change-password", { method: "POST", body: { newPassword: data.get("newPassword") } });
    session.user.mustChangePassword = false;
    toast("Passwort gespeichert");
    go("/");
  });

  return h(
    "div",
    { class: "auth" },
    h(
      "div",
      { class: "auth-head" },
      h("img", { src: "icon.svg", alt: "" }),
      h("h1", null, "Eigenes Passwort festlegen"),
      h("p", { class: "muted" }, `Hallo ${session.user.username}, dein Passwort wurde von einem Administrator vergeben. Lege bitte ein eigenes fest, das nur du kennst.`),
    ),
    h("div", { class: "card" }, form),
    h("button", { type: "button", class: "btn ghost auth-logout", onclick: logout }, icon("logout", "sm"), "Abmelden"),
    appFooter(),
  );
}

// ---------------------------------------------------------------- Router

const session = { user: null, needsSetup: false, passkeys: false, totp: false, version: null, sourceUrl: null, loaded: false };

const routes = [
  [/^\/$/, "dashboard", () => dashboardPage()],
  [/^\/search$/, "dashboard", () => dashboardPage({ focusSearch: true })],
  [/^\/new$/, "new", newPage, "narrow"],
  [/^\/profile$/, "profile", profilePage, "narrow"],
  [/^\/destinations\/([^/]+)$/, "dashboard", detailPage],
  [/^\/destinations\/([^/]+)\/edit$/, "dashboard", editPage, "narrow"],
];

let renderId = 0;
let previousPath = null;

async function render() {
  const current = ++renderId;
  const app = document.getElementById("app");
  const topbar = document.getElementById("topbar");
  const path = location.hash.replace(/^#/, "") || "/";

  abortPasskeyRequest();
  for (const open of document.querySelectorAll("dialog[open]")) open.close();
  // Scrollposition der Übersicht merken, um sie beim Zurückkehren wiederherzustellen.
  if (previousPath === "/") listState.scrollY = window.scrollY;
  const returning = path === "/" && previousPath?.startsWith("/destinations/");
  const firstRender = previousPath === null;
  previousPath = path;

  if (!session.loaded) {
    try {
      Object.assign(session, await api("me"), { loaded: true });
    } catch (error) {
      app.replaceChildren(h("div", { class: "card" }, h("h1", null, "Die App konnte nicht geladen werden"), h("p", { class: "muted" }, error.message)));
      return;
    }
  }

  let view;
  let active = null;
  let layout = "";
  if (session.needsSetup) {
    view = () => authPage({ setup: true });
    layout = "centered";
  } else if (!session.user) {
    view = () => authPage({ setup: false });
    layout = "centered";
  } else if (session.user.mustChangePassword) {
    view = changePasswordPage;
    layout = "centered";
  } else if (path === "/login") {
    go("/");
    return;
  } else {
    const match = routes.find(([pattern]) => pattern.test(path));
    if (match) {
      const [pattern, name, page, pageLayout = ""] = match;
      const args = path.match(pattern).slice(1).map(decodeURIComponent);
      view = () => page(...args);
      active = name;
      layout = pageLayout;
    } else {
      view = () => h("div", { class: "empty" }, h("h1", null, "Seite nicht gefunden"), h("a", { href: "#/", class: "btn secondary" }, "Zur Übersicht"));
    }
  }

  topbar.hidden = !session.user || session.user.mustChangePassword;
  for (const link of topbar.querySelectorAll("nav a")) {
    link.classList.toggle("active", link.dataset.route === active);
    if (link.dataset.route === active) link.setAttribute("aria-current", "page");
    else link.removeAttribute("aria-current");
  }

  // Ladeanzeige nur, wenn die Seite spürbar lange braucht.
  const slow = setTimeout(() => {
    if (current === renderId) app.replaceChildren(h("div", { class: "loading", role: "status" }, h("span", { class: "spinner" }), h("span", { class: "sr-only" }, "Lade …")));
  }, 250);

  try {
    const content = await view();
    if (current !== renderId) return;
    app.className = `page ${layout}`.trim();
    app.replaceChildren(...[content].flat().filter(Boolean));
    window.scrollTo(0, returning ? listState.scrollY : 0);
    const autofocus = app.querySelector("[autofocus]");
    if (autofocus) autofocus.focus({ preventScroll: true });
    else if (!firstRender) app.focus({ preventScroll: true });
  } catch (error) {
    if (current !== renderId) return;
    if (error.status === 401) return; // api() zeigt bereits die Anmeldung
    app.className = "page";
    app.replaceChildren(
      h(
        "div",
        { class: "empty" },
        h("span", { class: "section-icon" }, icon("alert", "lg")),
        h("h1", null, error.status === 404 ? "Nicht gefunden" : "Etwas ist schiefgelaufen"),
        h("p", null, error.message),
        h("a", { href: "#/", class: "btn secondary" }, "Zur Übersicht"),
      ),
    );
  } finally {
    clearTimeout(slow);
  }
}

for (const placeholder of document.querySelectorAll("[data-icon]")) {
  placeholder.replaceWith(icon(placeholder.dataset.icon));
}
window.addEventListener("hashchange", render);
render();
