import configScss from "../../scss/_config.scss?raw";

const STYLE_ID = "dev-inline-editor-style";
const DEFAULT_COLOR_OPTIONS = [
  { label: "$color00 · Blanco", value: "rgb(255, 255, 255)" },
  { label: "$color01 · Gris oscuro", value: "#272727" },
  { label: "$color02 · Azul", value: "#5285c5" },
  { label: "$color03 · Azul claro", value: "#E9F5FF" },
  { label: "$color04 · Azul marino", value: "#092F64" },
  { label: "$color05 · Malva", value: "#b7b8ec" },
  { label: "$colorERROR · Rojo", value: "#DD7676" },
  { label: "$colorOK · Verde", value: "#6DE063" },
];

const COLOR_CONFIG_PATHS = [
  "/src/scss/_config.scss",
  "/scss/_config.scss",
  "./src/scss/_config.scss",
];

const getBundledScssConfig = () =>
  typeof configScss === "string" && configScss.trim().length > 0
    ? configScss
    : null;

let colorOptionsCache = null;

const parseScssColors = (scssContent) => {
  if (typeof scssContent !== "string") {
    return [];
  }

  const results = [];
  const regex = /\$color([\w-]+)\s*:\s*([^;]+);/gi;
  let match = regex.exec(scssContent);

  while (match) {
    results.push({
      label: `$color${match[1]}`,
      value: match[2].trim(),
    });
    match = regex.exec(scssContent);
  }

  return results;
};

const fetchConfigColors = async () => {
  for (const path of COLOR_CONFIG_PATHS) {
    try {
      const response = await fetch(new URL(path, window.location.origin), {
        cache: "reload",
      });

      if (response.ok) {
        return response.text();
      }
    } catch (error) {
      // ignore fetch failures and try the next path
    }
  }
  return null;
};

const getColorOptions = async () => {
  if (Array.isArray(colorOptionsCache)) {
    return colorOptionsCache;
  }

  const bundledConfig = getBundledScssConfig();
  const inlineParsed = parseScssColors(bundledConfig);

  if (inlineParsed.length > 0) {
    colorOptionsCache = inlineParsed;
    return inlineParsed;
  }

  const scssContent = await fetchConfigColors();
  const parsed = parseScssColors(scssContent);

  if (parsed.length > 0) {
    colorOptionsCache = parsed;
    return parsed;
  }

  colorOptionsCache = DEFAULT_COLOR_OPTIONS;
  return DEFAULT_COLOR_OPTIONS;
};

const parseHexColor = (value) => {
  const hex = value.replace(/^#/, "");
  if (hex.length === 3) {
    const [r, g, b] = hex.split("");
    return [r, g, b].map((part) => parseInt(part.repeat(2), 16));
  }
  if (hex.length === 6) {
    return [hex.slice(0, 2), hex.slice(2, 4), hex.slice(4, 6)].map((part) =>
      parseInt(part, 16)
    );
  }
  return null;
};

const parseRgbColor = (value) => {
  const match = value.match(/rgb\s*\(\s*(\d+)\s*,\s*(\d+)\s*,\s*(\d+)\s*\)/i);
  if (!match) {
    return null;
  }
  return match.slice(1, 4).map((part) => Number(part));
};

const toRgbTuple = (value) => {
  if (typeof value !== "string") {
    return null;
  }
  const trimmed = value.trim();
  return parseHexColor(trimmed) || parseRgbColor(trimmed) || null;
};

const getContrastTextColor = (background) => {
  const rgb = toRgbTuple(background);
  if (!rgb) {
    return "#0f172a";
  }
  const [r, g, b] = rgb;
  const luminance = (0.299 * r + 0.587 * g + 0.114 * b) / 255;
  return luminance > 0.6 ? "#0f172a" : "#f8fafc";
};

const toBool = (value) => {
  if (typeof value === "boolean") {
    return value;
  }
  if (typeof value === "number") {
    return value === 1;
  }
  if (typeof value === "string") {
    return ["1", "true", "on", "yes"].includes(value.toLowerCase());
  }
  return false;
};

const getGlobalConfig = () => {
  const raw = window.__APP_CONFIG__ || {};
  const htmlLang = document.documentElement.lang;
  return {
    devMode: toBool(raw.devMode),
    lang: raw.lang || htmlLang || "",
    defaultLang: raw.defaultLang || "",
    route: raw.route || null,
    multiLang: toBool(raw.multiLang),
    simplifiedDefault: toBool(raw.simplifiedDefault),
  };
};

const normalizeForDom = (value) => {
  if (value && typeof value === "object" && !Array.isArray(value)) {
    return value;
  }
  return {
    text: value == null ? "" : String(value),
  };
};

const valueToString = (value) => {
  if (value == null) {
    return "";
  }
  if (typeof value === "string") {
    return value;
  }
  if (typeof value === "number" || typeof value === "boolean") {
    return String(value);
  }
  if (typeof value === "object") {
    if (Object.prototype.hasOwnProperty.call(value, "text")) {
      return valueToString(value.text);
    }
    return JSON.stringify(value);
  }
  return "";
};

const ensureStyles = () => {
  if (document.getElementById(STYLE_ID)) {
    return;
  }

  const style = document.createElement("style");
  style.id = STYLE_ID;
  style.textContent = `
    .dev-inline-editor-backdrop {
      position: fixed;
      inset: 0;
      background: rgba(17, 24, 39, 0.45);
      backdrop-filter: blur(2px);
      display: flex;
      align-items: center;
      justify-content: center;
      padding: 1.5rem;
      z-index: 9999;
    }
    .dev-inline-editor-modal {
      background: #fff;
      background-image: none;
      color: #0f172a;
      width: min(480px, 100%);
      max-height: calc(100vh - 3rem);
      border-radius: 0.75rem;
      box-shadow: 0 25px 50px -12px rgba(15, 23, 42, 0.45);
      overflow: hidden;
      display: flex;
      flex-direction: column;
      font-family: "Inter", system-ui, -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
    }
    .dev-inline-editor-form {
      display: flex;
      flex-direction: column;
      flex: 1 1 auto;
      min-height: 0;
    }
    .dev-inline-editor-header {
      padding: 1rem 1.5rem 0.75rem;
      border-bottom: 1px solid rgba(148, 163, 184, 0.35);
    }
    .dev-inline-editor-header h3 {
      margin: 0;
      font-size: 1rem;
      line-height: 1.4;
      font-weight: 600;
    }
    .dev-inline-editor-header p {
      margin: 0.35rem 0 0;
      font-size: 0.85rem;
      color: #475569;
    }
    .dev-inline-editor-body {
      padding: 1rem 1.5rem;
      overflow-y: auto;
      flex: 1 1 auto;
    }
    .dev-inline-editor-div {
      padding: 0.75rem 0;
      border-bottom: 1px solid rgba(148, 163, 184, 0.2);
    }
    .dev-inline-editor-div:last-of-type {
      border-bottom: none;
      padding-bottom: 0;
    }
    .dev-inline-editor-div h4 {
      margin: 0 0 0.2rem;
      font-size: 0.9rem;
      font-weight: 600;
      color: #111827;
    }
    .dev-inline-editor-div h5 {
      margin: 0.75rem 0 0.4rem;
      font-size: 0.82rem;
      font-weight: 600;
      color: #0f172a;
      text-transform: uppercase;
      letter-spacing: 0.04em;
    }
    .dev-inline-editor-div p {
      margin: 0 0 0.75rem;
      font-size: 0.8rem;
      color: #475569;
    }
    .dev-inline-editor-attributes {
      margin-top: 0.75rem;
      padding-top: 0.75rem;
      border-top: 1px solid rgba(148, 163, 184, 0.2);
    }
    .dev-inline-editor-field {
      display: flex;
      flex-direction: column;
      gap: 0.35rem;
      margin-bottom: 1rem;
    }
    .dev-inline-editor-toolbar {
      display: flex;
      flex-wrap: wrap;
      gap: 0.35rem;
      margin: 0.25rem 0 0.15rem;
    }
    .dev-inline-editor-toolbar button,
    .dev-inline-editor-toolbar select {
      border-radius: 0.4rem;
      border: 1px solid rgba(148, 163, 184, 0.5);
      background: rgba(248, 250, 252, 0.9);
      color: #0f172a;
      padding: 0.35rem 0.65rem;
      font-size: 0.85rem;
      font-weight: 600;
      cursor: pointer;
      transition: background 0.15s ease, border-color 0.15s ease, box-shadow 0.15s ease;
    }
    .dev-inline-editor-toolbar button:hover,
    .dev-inline-editor-toolbar select:hover {
      background: #fff;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
    }
    .dev-inline-editor-toolbar button:active,
    .dev-inline-editor-toolbar select:active {
      transform: translateY(1px);
    }
    .dev-inline-editor-field label {
      font-size: 0.85rem;
      font-weight: 500;
      color: #1f2937;
    }
    .dev-inline-editor-field textarea,
    .dev-inline-editor-field input {
      border: 1px solid rgba(148, 163, 184, 0.6);
      border-radius: 0.5rem;
      padding: 0.6rem 0.75rem;
      font-size: 0.95rem;
      font-family: inherit;
      color: inherit;
      transition: border-color 0.15s ease, box-shadow 0.15s ease;
      background: rgba(248, 250, 252, 0.9);
    }
    .dev-inline-editor-field textarea:focus,
    .dev-inline-editor-field input:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
      background: #fff;
    }
    .dev-inline-editor-footer {
      padding: 0.85rem 1.5rem 1.25rem;
      display: flex;
      gap: 0.75rem;
      justify-content: flex-end;
      border-top: 1px solid rgba(148, 163, 184, 0.35);
    }
    .dev-inline-editor-footer button {
      border-radius: 999px;
      border: none;
      padding: 0.55rem 1.3rem;
      font-size: 0.9rem;
      font-weight: 600;
      cursor: pointer;
      transition: transform 0.1s ease, box-shadow 0.1s ease, background 0.2s ease;
    }
    .dev-inline-editor-footer button.dev-cancel {
      background: rgba(148, 163, 184, 0.2);
      color: #1f2937;
    }
    .dev-inline-editor-footer button.dev-cancel:hover {
      background: rgba(148, 163, 184, 0.35);
    }
    .dev-inline-editor-footer button.dev-submit {
      background: #2563eb;
      color: #fff;
      box-shadow: 0 10px 20px -10px rgba(37, 99, 235, 0.6);
    }
    .dev-inline-editor-footer button.dev-submit:hover {
      background: #1d4ed8;
    }
    .dev-inline-editor-footer button:active {
      transform: translateY(1px);
    }
    .dev-inline-editor-error {
      margin: 0 0 1rem;
      padding: 0.75rem 0.95rem;
      border-radius: 0.5rem;
      background: rgba(248, 113, 113, 0.15);
      color: #b91c1c;
      font-size: 0.85rem;
    }
  `;

  document.head.appendChild(style);
};

const fetchJson = async (route, lang) => {
  if (!route || !lang) {
    return null;
  }

  const body = new URLSearchParams({
    route,
    lang,
  });

  const response = await fetch("/languages", {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded; charset=utf-8",
    },
    body,
  });

  if (!response.ok) {
    throw new Error(`Error ${response.status} al obtener ${route}`);
  }

  const text = await response.text();
  if (!text) {
    return {};
  }

  try {
    return JSON.parse(text);
  } catch (error) {
    throw new Error(`No se pudo interpretar el JSON de ${route}`);
  }
};

const buildHomeUrl = (origin, config) => {
  if (!config.multiLang || !config.lang) {
    return origin;
  }

  if (config.lang === config.defaultLang && config.simplifiedDefault) {
    return origin;
  }

  return `${origin.replace(/\/$/, "")}/${config.lang}`;
};

const isExternalHref = (value = "") => /^(https?:)?\/\//i.test(value) || /^(mailto:|tel:)/i.test(value);

const applyValuesToElement = (element, rawValues, config) => {
  if (!element) {
    return;
  }

  const values = normalizeForDom(rawValues);
  const origin = window.location.origin;

  Object.entries(values).forEach(([attribute, rawValue]) => {
    const value = typeof rawValue === "string" ? rawValue : "";

    switch (attribute) {
      case "text":
        element.innerHTML = value;
        break;
      case "content":
        if ("content" in element) {
          element.content = value;
        }
        element.setAttribute("content", value);
        break;
      case "href": {
        if (!element.hasAttribute("href")) {
          element.setAttribute("href", value);
          break;
        }

        if (!value) {
          element.setAttribute("href", buildHomeUrl(origin, config));
          break;
        }

        if (isExternalHref(value)) {
          element.setAttribute("href", value);
          break;
        }

        const normalized = value.replace(/^\//, "");
        const base = buildHomeUrl(origin, config);
        const prefix = base === origin ? origin : base;
        const url = `${prefix.replace(/\/$/, "")}/${normalized}`;
        element.setAttribute("href", url);
        break;
      }
      case "src": {
        if (!value) {
          element.removeAttribute("src");
          break;
        }
        if (isExternalHref(value)) {
          element.setAttribute("src", value);
          break;
        }
        const normalized = value.replace(/^\//, "");
        element.setAttribute("src", `${origin.replace(/\/$/, "")}/${normalized}`);
        break;
      }
      default: {
        element.setAttribute(attribute, value);
        if (attribute in element) {
          try {
            element[attribute] = value;
          } catch (error) {
            // ignore assignment errors for read-only props
          }
        }
        break;
      }
    }
  });
};

const createField = ({
  entryId,
  name,
  value,
  label: labelText,
  group,
  groupIndex = 0,
  dataset = {},
  enableRichText = false,
}) => {
  const fieldWrapper = document.createElement("div");
  fieldWrapper.className = "dev-inline-editor-field";

  const labelEl = document.createElement("label");
  labelEl.htmlFor = `dev-inline-${entryId}-${group ?? "main"}-${groupIndex}-${name}`;
  labelEl.textContent = labelText ?? name;

  const isLong = typeof value === "string" && value.length > 80;
  const hasBreaks = typeof value === "string" && /[\n\r]/.test(value);
  const control = (isLong || hasBreaks || name === "text" || name === "content")
    ? document.createElement("textarea")
    : document.createElement("input");

  const controlNameParts = [
    `entry-${entryId}`,
    group ? `${group}-${groupIndex}` : "main",
    name,
  ];

  control.name = controlNameParts.join("__");
  control.id = `dev-inline-${entryId}-${group ?? "main"}-${groupIndex}-${name}`;
  control.dataset.entryId = String(entryId);
  control.dataset.entryField = name;
  if (group) {
    control.dataset.entryGroup = group;
    control.dataset.entryGroupIndex = String(groupIndex);
  } else {
    control.dataset.entryGroup = "main";
    control.dataset.entryGroupIndex = "0";
  }
  Object.entries(dataset).forEach(([key, val]) => {
    control.dataset[key] = String(val);
  });
  control.value = value ?? "";
  if (control instanceof HTMLTextAreaElement) {
    control.rows = Math.min(8, Math.max(3, Math.ceil((value?.length || 0) / 60)));
  }

  fieldWrapper.appendChild(labelEl);
  if (enableRichText && (control instanceof HTMLTextAreaElement || control instanceof HTMLInputElement)) {
    const toolbar = document.createElement("div");
    toolbar.className = "dev-inline-editor-toolbar";

    const insertContent = (before, after = "", placeholder = "") => {
      const currentValue = control.value ?? "";
      const start = typeof control.selectionStart === "number" ? control.selectionStart : currentValue.length;
      const end = typeof control.selectionEnd === "number" ? control.selectionEnd : start;
      const selected = currentValue.slice(start, end) || placeholder;
      const nextValue = `${currentValue.slice(0, start)}${before}${selected}${after}${currentValue.slice(end)}`;

      control.value = nextValue;
      const selectionStart = start + before.length;
      const selectionEnd = selectionStart + selected.length;
      if (typeof control.setSelectionRange === "function") {
        control.focus();
        control.setSelectionRange(selectionStart, selectionEnd);
      }
    };

    const addButton = (text, title, handler) => {
      const button = document.createElement("button");
      button.type = "button";
      button.textContent = text;
      if (title) {
        button.title = title;
      }
      button.addEventListener("click", handler);
      toolbar.appendChild(button);
    };

    addButton("B", "Negrita (<b>)", () => insertContent("<b>", "</b>", "texto"));
    addButton("U", "Subrayado (<u>)", () => insertContent("<u>", "</u>", "texto"));
    addButton("br", "Salto de línea (<br>)", () => insertContent("<br>", ""));

    const colorSelect = document.createElement("select");
    colorSelect.className = "dev-inline-editor-color";

    const populateColorSelect = (options) => {
      colorSelect.innerHTML = "";

      const defaultOption = document.createElement("option");
      defaultOption.value = "";
      defaultOption.textContent = "Color";
      colorSelect.appendChild(defaultOption);

      options.forEach((option) => {
        const opt = document.createElement("option");
        opt.value = option.value;
        opt.textContent = `▇ ${option.label}`;
        opt.style.backgroundColor = option.value;
        opt.style.color = getContrastTextColor(option.value);
        opt.style.paddingLeft = "0.75em";
        opt.style.textShadow = "0 0 2px rgba(0,0,0,0.25)";
        colorSelect.appendChild(opt);
      });
    };

    populateColorSelect(DEFAULT_COLOR_OPTIONS);
    colorSelect.disabled = true;

    getColorOptions()
      .then((options) => populateColorSelect(options))
      .catch(() => populateColorSelect(DEFAULT_COLOR_OPTIONS))
      .finally(() => {
        colorSelect.disabled = false;
      });

    colorSelect.addEventListener("change", (event) => {
      const target = event.target;
      const selectedValue = target instanceof HTMLSelectElement ? target.value : "";
      if (!selectedValue) {
        return;
      }
      insertContent(`<span style="color: ${selectedValue};">`, "</span>", "texto");
      colorSelect.value = "";
    });

    toolbar.appendChild(colorSelect);
    fieldWrapper.appendChild(toolbar);
  }
  fieldWrapper.appendChild(control);

  return fieldWrapper;
};

const normalizeSrcsetComponent = (value, origin) => {
  const trimmed = valueToString(value).trim();
  if (!trimmed) {
    return "";
  }

  const parts = trimmed.split(/\s+/);
  const url = parts[0];
  if (!url) {
    return "";
  }

  const rest = parts.slice(1).join(" ");
  if (isExternalHref(url) || url.startsWith("data:")) {
    return rest ? `${url} ${rest}` : url;
  }

  const normalizedUrl = `${origin.replace(/\/$/, "")}/${url.replace(/^\//, "")}`;
  return rest ? `${normalizedUrl} ${rest}` : normalizedUrl;
};

const formatSrcsetLabel = (suffix) => {
  const match = suffix.match(/srcset(\d+)/i);
  if (!match) {
    return suffix;
  }
  const index = Number(match[1]);
  const displayIndex = Number.isFinite(index) ? index : suffix;
  return `srcset #${displayIndex}`;
};

const parseRelatedSuffix = (suffix) => {
  const lower = suffix.toLowerCase();

  if (/^srcset\d+$/i.test(suffix)) {
    const match = suffix.match(/srcset(\d+)/i);
    const order = match ? Number(match[1]) || 0 : 0;
    return {
      attribute: "srcset",
      order,
      label: formatSrcsetLabel(suffix),
    };
  }

  if (lower === "srcset") {
    return { attribute: "srcset", label: "srcset" };
  }

  if (lower === "sizes") {
    return { attribute: "sizes", label: "sizes" };
  }

  if (["width", "height", "loading", "decoding"].includes(lower)) {
    return { attribute: lower, label: lower };
  }

  if (lower === "media") {
    return { attribute: "media", label: "media" };
  }

  if (lower === "type") {
    return { attribute: "type", label: "type" };
  }

  if (lower === "src") {
    return { attribute: "src", label: "src" };
  }

  return { attribute: null, label: suffix };
};

const VOID_ELEMENTS = new Set([
  "AREA",
  "BASE",
  "BR",
  "COL",
  "EMBED",
  "HR",
  "IMG",
  "INPUT",
  "LINK",
  "META",
  "PARAM",
  "SOURCE",
  "TRACK",
  "WBR",
]);

const elementSupportsText = (element) => {
  if (!(element instanceof Element)) {
    return true;
  }
  return !VOID_ELEMENTS.has(element.tagName);
};

const cloneFields = (fields = {}) => Object.fromEntries(Object.entries(fields));

const COMPOUND_WITH_DESCENDANTS = new Set(["A", "VIDEO"]);

const showModal = ({
  entries,
  onSubmit,
  onClose,
}) => {
  ensureStyles();

  const backdrop = document.createElement("div");
  backdrop.className = "dev-inline-editor-backdrop";
  backdrop.setAttribute("role", "presentation");

  const modal = document.createElement("div");
  modal.className = "dev-inline-editor-modal";
  modal.setAttribute("role", "dialog");
  modal.setAttribute("aria-modal", "true");

  const header = document.createElement("div");
  header.className = "dev-inline-editor-header";
  const title = document.createElement("h3");
  if (entries.length === 1) {
    title.textContent = `Editar: ${entries[0].key}`;
  } else {
    title.textContent = `Editar ${entries.length} elementos`;
  }
  const subtitle = document.createElement("p");
  if (entries.length === 1) {
    const scope = entries[0].scope === "global" ? "global" : entries[0].scope;
    subtitle.textContent = scope ? `Fuente: ${scope}` : "";
  } else {
    subtitle.textContent = "Selecciona y guarda para aplicar los cambios.";
  }
  header.appendChild(title);
  if (subtitle.textContent) {
    header.appendChild(subtitle);
  }

  const form = document.createElement("form");
  form.className = "dev-inline-editor-form";

  const body = document.createElement("div");
  body.className = "dev-inline-editor-body";

  const errorBox = document.createElement("div");
  errorBox.className = "dev-inline-editor-error";
  errorBox.setAttribute("role", "alert");
  errorBox.setAttribute("aria-live", "polite");
  errorBox.style.display = "none";
  body.appendChild(errorBox);

  entries.forEach((entry, entryIndex) => {
    const section = document.createElement("div");
    section.className = "dev-inline-editor-div";

    const sectionTitle = document.createElement("h4");
    sectionTitle.textContent = entry.key;
    section.appendChild(sectionTitle);

    if (entry.scope) {
      const sectionScope = document.createElement("p");
      sectionScope.textContent = entry.scope === "global"
        ? "Fuente: global"
        : `Fuente: ${entry.scope}`;
      section.appendChild(sectionScope);
    }

    const supportsText = elementSupportsText(entry.element);

    Object.entries(entry.fields).forEach(([name, value]) => {
      if (!supportsText && name === "text") {
        return;
      }
      section.appendChild(
        createField({
          entryId: entryIndex,
          name,
          value,
          group: "main",
          label: name,
          enableRichText: supportsText && name === "text",
        }),
      );
    });

    if (Array.isArray(entry.related) && entry.related.length) {
      const attributesWrapper = document.createElement("div");
      attributesWrapper.className = "dev-inline-editor-attributes";

      const attributesHeading = document.createElement("h5");
      attributesHeading.textContent = "Atributos HTML";
      attributesWrapper.appendChild(attributesHeading);

      entry.related.forEach((relatedEntry, relatedIndex) => {
        const fields = Object.entries(relatedEntry.fields);
        const displayName = relatedEntry.meta?.label || relatedEntry.suffix;

        fields.forEach(([fieldName, fieldValue]) => {
          const multiple = fields.length > 1;
          const fieldLabel = multiple ? `${displayName} · ${fieldName}` : displayName;
          attributesWrapper.appendChild(
            createField({
              entryId: entryIndex,
              name: fieldName,
              value: fieldValue,
              label: fieldLabel,
              group: "related",
              groupIndex: relatedIndex,
              dataset: {
                relatedKey: relatedEntry.key,
                relatedType: relatedEntry.type,
                relatedSuffix: relatedEntry.suffix,
              },
            }),
          );
        });
      });

      section.appendChild(attributesWrapper);
    }

    body.appendChild(section);
  });

  const footer = document.createElement("div");
  footer.className = "dev-inline-editor-footer";

  const cancelBtn = document.createElement("button");
  cancelBtn.type = "button";
  cancelBtn.textContent = "Cancelar";
  cancelBtn.className = "dev-cancel";

  const submitBtn = document.createElement("button");
  submitBtn.type = "submit";
  submitBtn.textContent = "Guardar";
  submitBtn.className = "dev-submit";

  footer.appendChild(cancelBtn);
  footer.appendChild(submitBtn);

  modal.appendChild(header);
  form.appendChild(body);
  form.appendChild(footer);
  modal.appendChild(form);
  backdrop.appendChild(modal);
  document.body.appendChild(backdrop);

  const close = () => {
    document.body.removeChild(backdrop);
    document.removeEventListener("keydown", onKeydown);
    if (typeof onClose === "function") {
      onClose();
    }
  };

  const onKeydown = (event) => {
    if (event.key === "Escape") {
      event.preventDefault();
      close();
    }
  };

  document.addEventListener("keydown", onKeydown);

  cancelBtn.addEventListener("click", () => {
    close();
  });

  let pointerDownOnBackdrop = false;

  backdrop.addEventListener("pointerdown", (event) => {
    pointerDownOnBackdrop = event.target === backdrop;
  });

  backdrop.addEventListener("pointercancel", () => {
    pointerDownOnBackdrop = false;
  });

  backdrop.addEventListener("click", (event) => {
    if (event.target === backdrop && pointerDownOnBackdrop) {
      close();
    }
    pointerDownOnBackdrop = false;
  });

  form.addEventListener("keydown", (event) => {
    if (event.key !== "Enter") {
      return;
    }

    const target = event.target;
    if (event.shiftKey && target instanceof HTMLTextAreaElement) {
      return;
    }

    event.preventDefault();
    if (!submitBtn.disabled) {
      submitBtn.click();
    }
  });

  form.addEventListener("submit", async (event) => {
    event.preventDefault();
    errorBox.style.display = "none";

    const formData = new FormData(form);
    const valuesByEntry = entries.map((entry) => ({
      main: cloneFields(entry.fields),
      related: Array.isArray(entry.related)
        ? entry.related.map((relatedEntry) => cloneFields(relatedEntry.fields))
        : [],
    }));

    for (const [name, value] of formData.entries()) {
      const parts = String(name).split("__");
      if (parts.length !== 3) {
        continue;
      }

      const entryMatch = /^entry-(\d+)$/.exec(parts[0]);
      if (!entryMatch) {
        continue;
      }

      const entryIndex = Number(entryMatch[1]);
      if (Number.isNaN(entryIndex) || !valuesByEntry[entryIndex]) {
        continue;
      }

      const [groupName, rawGroupIndex] = parts[1].split("-");
      const group = groupName || "";
      const groupIndex = Number(rawGroupIndex || "0");
      const field = parts[2];

      if (group === "main") {
        valuesByEntry[entryIndex].main[field] = value;
        continue;
      }

      if (group === "related") {
        if (!Number.isInteger(groupIndex) || groupIndex < 0) {
          continue;
        }

        const target = valuesByEntry[entryIndex].related[groupIndex];
        if (!target) {
          continue;
        }

        target[field] = value;
      }
    }

    const payload = entries.map((entry, index) => ({
      key: entry.key,
      scope: entry.scope,
      element: entry.element,
      valueType: entry.valueType,
      values: valuesByEntry[index].main,
      related: Array.isArray(entry.related)
        ? entry.related.map((relatedEntry, relatedIndex) => ({
          key: relatedEntry.key,
          valueType: relatedEntry.type,
          values: valuesByEntry[index].related[relatedIndex] || {},
          meta: relatedEntry.meta,
        }))
        : [],
    }));

    try {
      submitBtn.disabled = true;
      await onSubmit(payload);
      close();
    } catch (error) {
      submitBtn.disabled = false;
      errorBox.textContent = error instanceof Error ? error.message : String(error);
      errorBox.style.display = "block";
    }
  });
};

const createCache = () => ({
  global: null,
  route: null,
});

const prepareEntryForForm = (entry) => {
  if (entry && typeof entry === "object" && !Array.isArray(entry)) {
    return {
      fields: Object.fromEntries(Object.entries(entry)),
      type: "object",
    };
  }
  return {
    fields: {
      text: entry == null ? "" : String(entry),
    },
    type: "scalar",
  };
};

export default function initInlineEditor() {
  const config = { ...getGlobalConfig() };
  if (!config.devMode) {
    return;
  }

  if (!config.lang) {
    console.warn("Inline editor: no se pudo detectar el idioma actual.");
    return;
  }

  const cache = createCache();

  const getCacheForScope = (scope) => (scope === "global" ? cache.global : cache.route);

  const setCacheValue = (scope, key, value) => {
    if (scope === "global") {
      cache.global = cache.global || {};
      cache.global[key] = value;
      return;
    }
    cache.route = cache.route || {};
    cache.route[key] = value;
  };

  const buildRelatedEntries = (baseKey, scopeData, element) => {
    if (!scopeData || typeof scopeData !== "object") {
      return [];
    }

    const prefix = `${baseKey}_`;
    const related = [];

    Object.entries(scopeData).forEach(([candidateKey, candidateValue]) => {
      if (!candidateKey.startsWith(prefix)) {
        return;
      }

      const suffix = candidateKey.slice(prefix.length);
      if (!suffix) {
        return;
      }

      const prepared = prepareEntryForForm(candidateValue);
      const meta = { ...parseRelatedSuffix(suffix), baseKey, key: candidateKey };

      related.push({
        key: candidateKey,
        suffix,
        fields: prepared.fields,
        type: prepared.type,
        meta,
      });
    });

    related.sort((a, b) => a.key.localeCompare(b.key));
    return related;
  };

  const applyRelatedAttribute = ({ element, scope, key, meta }) => {
    if (!(element instanceof Element) || !meta || !meta.attribute) {
      return;
    }

    const source = getCacheForScope(scope);
    if (!source) {
      return;
    }

    if (meta.attribute === "srcset") {
      const prefix = `${meta.baseKey}_srcset`;
      const origin = window.location.origin;

      const enumerated = Object.entries(source)
        .filter(([candidateKey]) => candidateKey.startsWith(prefix) && /srcset\d+$/i.test(candidateKey.slice(prefix.length)))
        .map(([candidateKey, candidateValue]) => {
          const match = candidateKey.match(/srcset(\d+)/i);
          const order = match ? Number(match[1]) || 0 : 0;
          return { order, value: normalizeSrcsetComponent(candidateValue, origin) };
        })
        .filter((item) => Boolean(item.value));

      if (enumerated.length) {
        enumerated.sort((a, b) => a.order - b.order);
        const enumeratedValue = enumerated.map((item) => item.value).join(", ");
        if (enumeratedValue) {
          element.setAttribute("srcset", enumeratedValue);
          if ("srcset" in element) {
            try {
              element.srcset = enumeratedValue;
            } catch (error) {
              // ignore read-only assignments
            }
          }
        } else {
          element.removeAttribute("srcset");
          if ("srcset" in element) {
            try {
              element.srcset = "";
            } catch (error) {
              // ignore read-only assignments
            }
          }
        }
        return;
      }

      const rawSrcset = source[key];
      const srcsetValue = valueToString(rawSrcset).trim();
      if (!srcsetValue) {
        element.removeAttribute("srcset");
        if ("srcset" in element) {
          try {
            element.srcset = "";
          } catch (error) {
            // ignore read-only assignments
          }
        }
        return;
      }

      const normalized = srcsetValue
        .split(",")
        .map((part) => normalizeSrcsetComponent(part, origin))
        .filter(Boolean)
        .join(", ");

      const finalSrcset = normalized || srcsetValue;
      element.setAttribute("srcset", finalSrcset);
      if ("srcset" in element) {
        try {
          element.srcset = finalSrcset;
        } catch (error) {
          // ignore read-only assignments
        }
      }
      return;
    }

    const rawValue = source[key];
    const value = valueToString(rawValue).trim();

    if (!value) {
      element.removeAttribute(meta.attribute);
      if (meta.attribute in element) {
        try {
          element[meta.attribute] = "";
        } catch (error) {
          // ignore read-only assignments
        }
      }
      return;
    }

    if (meta.attribute === "src") {
      if (isExternalHref(value)) {
        element.setAttribute("src", value);
      } else {
        const normalized = value.replace(/^\//, "");
        element.setAttribute("src", `${window.location.origin.replace(/\/$/, "")}/${normalized}`);
      }
    } else {
      element.setAttribute(meta.attribute, value);
    }

    if (meta.attribute in element) {
      try {
        element[meta.attribute] = value;
      } catch (error) {
        // ignore read-only assignments
      }
    }
  };

  const resetCache = () => {
    cache.global = null;
    cache.route = null;
  };

  const rehydrateConfig = (detail = {}) => {
    const fresh = getGlobalConfig();
    const newLang = fresh.lang || detail.lang || detail.language;
    const newRoute =
      fresh.route ?? detail.route ?? detail.scope ?? config.route ?? null;

    let shouldReset = false;

    if (newLang && newLang !== config.lang) {
      config.lang = newLang;
      shouldReset = true;
    }

    if (newRoute !== config.route) {
      config.route = newRoute;
      shouldReset = true;
    }

    if (fresh.defaultLang || detail.defaultLang) {
      config.defaultLang = fresh.defaultLang || detail.defaultLang;
    }

    config.multiLang = fresh.multiLang;
    config.simplifiedDefault = fresh.simplifiedDefault;

    if (shouldReset) {
      resetCache();
    }
  };

  window.addEventListener("app:languagechange", (event) => {
    rehydrateConfig(event?.detail || {});
  });
  let isOpen = false;

  document.addEventListener(
    "click",
    (event) => {
      if (!event.ctrlKey) {
        return;
      }
      if (!(event.target instanceof Element)) {
        return;
      }

      const langElement = event.target.closest("[data-lang]");
      if (!langElement) {
        return;
      }

      const anchor = event.target.closest("a");
      if (!anchor) {
        return;
      }

      const related = anchor.contains(langElement) || langElement.contains(anchor);
      if (!related) {
        return;
      }

      event.preventDefault();
      event.stopPropagation();
    },
    true,
  );

  const loadScope = async (scope) => {
    if (!scope) {
      return null;
    }

    if (scope === "global") {
      if (!cache.global) {
        cache.global = await fetchJson("global", config.lang);
      }
      return cache.global;
    }

    if (!cache.route) {
      cache.route = await fetchJson(scope, config.lang);
    }
    return cache.route;
  };

  const resolveValues = async (key, element) => {
    const globalData = await loadScope("global");
    if (globalData && Object.prototype.hasOwnProperty.call(globalData, key)) {
      const prepared = prepareEntryForForm(globalData[key]);
      return {
        scope: "global",
        fields: prepared.fields,
        type: prepared.type,
        related: buildRelatedEntries(key, globalData, element),
      };
    }

    if (config.route) {
      const routeData = await loadScope(config.route);
      if (routeData && Object.prototype.hasOwnProperty.call(routeData, key)) {
        const prepared = prepareEntryForForm(routeData[key]);
        return {
          scope: config.route,
          fields: prepared.fields,
          type: prepared.type,
          related: buildRelatedEntries(key, routeData, element),
        };
      }
    }

    return null;
  };

  const saveValues = async ({ key, scope, values, element, valueType, applyMeta }) => {
    const payloadValues = valueType === "object"
      ? values
      : values.text ?? "";

    const response = await fetch("/languages/update", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        key,
        scope,
        lang: config.lang,
        route: config.route,
        values: payloadValues,
      }),
    });

    if (!response.ok) {
      const message = await response.text();
      throw new Error(message || "No se pudo guardar la traducción");
    }

    const result = await response.json();
    const updatedRawValue = Object.prototype.hasOwnProperty.call(result, "data")
      ? result.data
      : payloadValues;

    setCacheValue(result.scope || scope, key, updatedRawValue);

    if (applyMeta) {
      applyRelatedAttribute({
        element,
        scope: result.scope || scope,
        key,
        meta: applyMeta,
      });
    } else {
      applyValuesToElement(element, updatedRawValue, config);
    }
  };

  const collectLanguageElements = (event) => {
    const elements = [];
    const seen = new Set();
    const register = (element) => {
      if (!(element instanceof Element)) {
        return;
      }
      if (!element.hasAttribute("data-lang")) {
        return;
      }
      if (seen.has(element)) {
        return;
      }
      elements.push(element);
      seen.add(element);
    };

    if (typeof event.composedPath === "function") {
      const path = event.composedPath();
      for (const item of path) {
        if (!(item instanceof Element)) {
          continue;
        }
        register(item);
        if (item === document.body) {
          break;
        }
      }
    }

    if (!elements.length && event.target instanceof Element) {
      const fallback = event.target.closest("[data-lang]");
      if (fallback) {
        register(fallback);
      }
    }

    for (let index = 0; index < elements.length; index += 1) {
      const element = elements[index];
      if (!COMPOUND_WITH_DESCENDANTS.has(element.tagName)) {
        continue;
      }

      const descendants = element.querySelectorAll("[data-lang]");
      descendants.forEach((descendant) => {
        register(descendant);
      });
    }

    return elements;
  };

  document.addEventListener("dblclick", async (event) => {
    if (!event.ctrlKey || isOpen) {
      return;
    }

    const targets = collectLanguageElements(event);
    if (!targets.length) {
      return;
    }

    event.preventDefault();
    event.stopPropagation();
    isOpen = true;

    try {
      const entries = [];
      const missingKeys = [];

      for (const element of targets) {
        const key = element.getAttribute("data-lang");
        if (!key) {
          continue;
        }

        const resolved = await resolveValues(key, element);
        if (!resolved) {
          missingKeys.push(key);
          continue;
        }

        entries.push({
          key,
          scope: resolved.scope,
          fields: resolved.fields,
          valueType: resolved.type,
          element,
          related: resolved.related || [],
        });
      }

      if (!entries.length) {
        const missing = missingKeys.length ? ` (${missingKeys.join(", ")})` : "";
        throw new Error(`No se encontraron datos para las claves seleccionadas${missing}.`);
      }

      if (missingKeys.length) {
        console.warn(
          "Inline editor: no se encontraron datos para las claves omitidas:",
          missingKeys,
        );
      }

      showModal({
        entries,
        onSubmit: async (payloadEntries) => {
          for (const entry of payloadEntries) {
            await saveValues({
              key: entry.key,
              scope: entry.scope,
              values: entry.values,
              element: entry.element,
              valueType: entry.valueType,
            });

            if (Array.isArray(entry.related)) {
              for (const relatedEntry of entry.related) {
                if (!relatedEntry || !relatedEntry.key) {
                  continue;
                }

                await saveValues({
                  key: relatedEntry.key,
                  scope: entry.scope,
                  values: relatedEntry.values,
                  element: entry.element,
                  valueType: relatedEntry.valueType,
                  applyMeta: relatedEntry.meta,
                });
              }
            }
          }
        },
        onClose: () => {
          isOpen = false;
        },
      });
    } catch (error) {
      console.error(error);
      alert(error instanceof Error ? error.message : String(error));
      isOpen = false;
      return;
    }
  });
}
