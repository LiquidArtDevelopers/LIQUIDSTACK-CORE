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
    .dev-inline-editor-field input,
    .dev-inline-editor-field > select {
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
    .dev-inline-editor-field input:focus,
    .dev-inline-editor-field > select:focus {
      outline: none;
      border-color: #2563eb;
      box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.2);
      background: #fff;
    }
    .dev-inline-editor-field-help {
      margin: -0.05rem 0 0;
      color: #64748b;
      font-size: 0.78rem;
      line-height: 1.4;
    }
    .dev-inline-editor-icon-preview {
      min-height: 3.5rem;
      border: 1px solid rgba(148, 163, 184, 0.35);
      border-radius: 0.5rem;
      padding: 0.65rem 0.75rem;
      display: flex;
      align-items: center;
      gap: 0.75rem;
      background: rgba(248, 250, 252, 0.72);
      color: #475569;
      font-size: 0.8rem;
    }
    .dev-inline-editor-icon-preview img {
      width: 2.25rem;
      height: 2.25rem;
      flex: 0 0 auto;
      object-fit: contain;
    }
    .dev-inline-editor-icon-preview img[hidden] {
      display: none;
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

const pendingVideoReloads = new WeakSet();

const scheduleVideoReload = (element) => {
  if (!(element instanceof Element)) {
    return;
  }

  const video = element.tagName === "VIDEO"
    ? element
    : element.closest("video");

  if (!(video instanceof HTMLVideoElement) || pendingVideoReloads.has(video)) {
    return;
  }

  pendingVideoReloads.add(video);
  queueMicrotask(() => {
    pendingVideoReloads.delete(video);
    if (video.isConnected && !video.hidden) {
      video.load();
    }
  });
};

const decodeLocalMediaPath = (input) => {
  let decoded = valueToString(input).trim();

  for (let pass = 0; pass < 5; pass += 1) {
    let nextDecoded;
    try {
      nextDecoded = decodeURIComponent(decoded);
    } catch {
      return null;
    }

    if (nextDecoded === decoded) {
      break;
    }

    decoded = nextDecoded;
  }

  return decoded;
};

const isValidLocalMediaPath = (input, extensionsCsv) => {
  const value = valueToString(input).trim();
  if (!value) {
    return true;
  }

  const decoded = decodeLocalMediaPath(value);
  if (
    decoded === null
    || /[\u0000-\u001F\u007F\\]/.test(decoded)
    || /%(?:00|2e|2f|5c)/i.test(decoded)
    || decoded.includes("..")
    || decoded.startsWith("//")
    || /^[a-z][a-z0-9+.-]*:/i.test(decoded)
  ) {
    return false;
  }

  const allowedExtensions = extensionsCsv
    .split(",")
    .map((extension) => extension.trim().toLowerCase())
    .filter(Boolean);
  const path = decoded.replace(/^\/+/, "");
  const filename = path.split("/").pop() || "";
  const extension = filename.includes(".")
    ? filename.split(".").pop().toLowerCase()
    : "";

  return allowedExtensions.includes(extension);
};

const parseInlineJsonObject = (element, attribute) => {
  if (!(element instanceof Element)) {
    return {};
  }

  const rawValue = element.getAttribute(attribute);
  if (!rawValue) {
    return {};
  }

  try {
    const parsed = JSON.parse(rawValue);
    return parsed && typeof parsed === "object" && !Array.isArray(parsed)
      ? parsed
      : {};
  } catch {
    return {};
  }
};

const getInlineFieldMeta = (element) =>
  parseInlineJsonObject(element, "data-inline-field-meta");

const getInlineAttributeTargets = (element) =>
  parseInlineJsonObject(element, "data-inline-attribute-targets");

const normalizeInlineYoutubeUrl = (input) => {
  const value = valueToString(input).trim();
  if (!value) {
    return "";
  }

  let videoId = /^[A-Za-z0-9_-]{11}$/.test(value) ? value : "";

  if (!videoId) {
    let candidate = value;
    if (candidate.startsWith("//")) {
      candidate = `https:${candidate}`;
    } else if (!/^https?:\/\//i.test(candidate)) {
      candidate = `https://${candidate.replace(/^\/+/, "")}`;
    }

    let url;
    try {
      url = new URL(candidate);
    } catch {
      return "";
    }

    if (
      url.protocol !== "https:"
      || url.username
      || url.password
      || url.port
    ) {
      return "";
    }

    let host = url.hostname.toLowerCase().replace(/\.$/, "");
    host = host.replace(/^(www|m)\./, "");
    const segments = url.pathname.split("/").filter(Boolean);

    if (host === "youtu.be") {
      [videoId = ""] = segments;
    } else if (["youtube.com", "youtube-nocookie.com"].includes(host)) {
      if (segments[0] === "watch") {
        videoId = url.searchParams.get("v") || "";
      } else if (["embed", "shorts", "live"].includes(segments[0])) {
        videoId = segments[1] || "";
      }
    }
  }

  return /^[A-Za-z0-9_-]{11}$/.test(videoId)
    ? `https://www.youtube-nocookie.com/embed/${videoId}`
    : "";
};

const validateSelectEntries = (entries) => {
  entries.forEach((entry) => {
    const fieldMeta = entry.fieldMeta && typeof entry.fieldMeta === "object"
      ? entry.fieldMeta
      : {};

    Object.entries(fieldMeta).forEach(([field, meta]) => {
      if (
        !meta
        || meta.controlType !== "select"
        || !Array.isArray(meta.options)
        || !Object.prototype.hasOwnProperty.call(entry.values, field)
      ) {
        return;
      }

      const allowedValues = new Set(
        meta.options.map((option) => String(option?.value ?? "")),
      );
      const value = String(entry.values[field] ?? "");
      if (!allowedValues.has(value)) {
        throw new Error(`El valor de ${field} no pertenece a las opciones permitidas.`);
      }
    });
  });
};

const validateVideoResourceEntries = (entries) => {
  const groups = new Map();

  entries.forEach((entry) => {
    const root = entry.element instanceof Element
      ? entry.element.closest("[data-inline-video]")
      : null;

    if (!root) {
      return;
    }

    if (!groups.has(root)) {
      groups.set(root, []);
    }
    groups.get(root).push(entry);
  });

  groups.forEach((videoEntries, root) => {
    const configEntry = videoEntries.find((entry) => entry.element === root);
    const type = String(
      configEntry?.values?.type ?? root.dataset.videoType ?? "",
    ).toLowerCase();

    if (!["youtube", "local"].includes(type)) {
      throw new Error("El tipo de vídeo debe ser youtube o local.");
    }

    const findBySuffix = (suffix) =>
      videoEntries.find((entry) => String(entry.key || "").endsWith(suffix));

    if (type === "youtube") {
      const youtubeEntry = findBySuffix("_youtube");
      const consentEntry = findBySuffix("_consent");
      const youtubeInput = valueToString(youtubeEntry?.values?.src).trim();
      const normalizedYoutubeUrl = normalizeInlineYoutubeUrl(youtubeInput);

      if (!youtubeEntry || !normalizedYoutubeUrl) {
        throw new Error("Indica un ID o una URL HTTPS válida de YouTube.");
      }
      if (!valueToString(youtubeEntry.values.title).trim()) {
        throw new Error("El vídeo de YouTube necesita un title descriptivo.");
      }
      if (!valueToString(youtubeEntry.values.playLabel).trim()) {
        throw new Error(
          "El vídeo de YouTube necesita un texto accesible para reproducirlo.",
        );
      }
      if (!valueToString(consentEntry?.values?.text).trim()) {
        throw new Error("El vídeo de YouTube necesita un mensaje de consentimiento.");
      }

      youtubeEntry.values.src = normalizedYoutubeUrl;
      return;
    }

    const videoEntry = findBySuffix("_video");
    const webmEntry = findBySuffix("_webm");
    const mp4Entry = findBySuffix("_mp4");
    const captionsEntry = findBySuffix("_captions");
    const webmSrc = valueToString(webmEntry?.values?.src).trim();
    const mp4Src = valueToString(mp4Entry?.values?.src).trim();

    if (!valueToString(videoEntry?.values?.title).trim()) {
      throw new Error("El vídeo local necesita un title descriptivo.");
    }
    if (!webmSrc && !mp4Src) {
      throw new Error("El vídeo local necesita al menos una fuente WebM o MP4.");
    }

    const captionsSrc = valueToString(captionsEntry?.values?.src).trim();
    if (captionsSrc) {
      const trackKind = valueToString(captionsEntry?.values?.kind).trim();
      const trackLang = valueToString(captionsEntry?.values?.srclang).trim();
      const trackLabel = valueToString(captionsEntry?.values?.label).trim();

      if (
        !["captions", "subtitles", "descriptions", "chapters", "metadata"]
          .includes(trackKind)
      ) {
        throw new Error("El tipo de pista del vídeo local no es válido.");
      }
      if (!/^[A-Za-z]{2,3}(?:-[A-Za-z0-9]{2,8})*$/.test(trackLang)) {
        throw new Error("El idioma de la pista debe usar un código válido.");
      }
      if (!trackLabel) {
        throw new Error("La pista del vídeo local necesita una etiqueta.");
      }
    }
  });
};

const validateLocalMediaEntries = (entries) => {
  const activeVideoTypes = new Map();

  entries.forEach((entry) => {
    const root = entry.element instanceof Element
      ? entry.element.closest("[data-inline-video]")
      : null;

    if (!root || activeVideoTypes.has(root)) {
      return;
    }

    const configEntry = entries.find((candidate) => candidate.element === root);
    activeVideoTypes.set(
      root,
      String(configEntry?.values?.type ?? root.dataset.videoType ?? "").toLowerCase(),
    );
  });

  entries.forEach((entry) => {
    const videoRoot = entry.element instanceof Element
      ? entry.element.closest("[data-inline-video]")
      : null;

    if (videoRoot && activeVideoTypes.get(videoRoot) !== "local") {
      return;
    }

    const extensions = entry.element instanceof Element
      ? entry.element.getAttribute("data-inline-local-extensions")
      : "";

    if (!extensions) {
      return;
    }

    ["src", "poster"].forEach((field) => {
      if (
        Object.prototype.hasOwnProperty.call(entry.values, field)
        && !isValidLocalMediaPath(entry.values[field], extensions)
      ) {
        throw new Error(
          `El campo ${field} debe usar una ruta local con extensión permitida: ${extensions}.`,
        );
      }
    });
  });
};

const applyValuesToElement = (element, rawValues, config) => {
  if (!element) {
    return;
  }

  const values = normalizeForDom(rawValues);
  const origin = window.location.origin;
  const attributeTargets = getInlineAttributeTargets(element);

  Object.entries(values).forEach(([attribute, rawValue]) => {
    const value = typeof rawValue === "string" ? rawValue : "";
    const mappedTarget = String(attributeTargets[attribute] ?? "");

    if (/^data-[a-z0-9-]+$/i.test(mappedTarget)) {
      if (value) {
        element.setAttribute(mappedTarget, value);
      } else {
        element.removeAttribute(mappedTarget);
      }
      return;
    }

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
      case "src":
      case "poster": {
        const mappedAttribute = attribute === "src"
          ? element.getAttribute("data-inline-src-target")
          : "";
        const targetAttribute = mappedAttribute
          && /^data-[a-z0-9-]+$/i.test(mappedAttribute)
          ? mappedAttribute
          : attribute;

        if (!value) {
          element.removeAttribute(targetAttribute);
          break;
        }
        if (isExternalHref(value)) {
          element.setAttribute(targetAttribute, value);
          break;
        }
        const normalized = value.replace(/^\//, "");
        element.setAttribute(
          targetAttribute,
          `${origin.replace(/\/$/, "")}/${normalized}`,
        );
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

  if (
    element.tagName === "SOURCE"
    || element.tagName === "TRACK"
    || (
      element.tagName === "VIDEO"
      && Object.prototype.hasOwnProperty.call(values, "poster")
    )
  ) {
    scheduleVideoReload(element);
  }
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
  controlType = "",
  options = [],
  helpText = "",
  rows = null,
}) => {
  const fieldWrapper = document.createElement("div");
  fieldWrapper.className = "dev-inline-editor-field";

  const labelEl = document.createElement("label");
  labelEl.htmlFor = `dev-inline-${entryId}-${group ?? "main"}-${groupIndex}-${name}`;
  labelEl.textContent = labelText ?? name;

  const isLong = typeof value === "string" && value.length > 80;
  const hasBreaks = typeof value === "string" && /[\n\r]/.test(value);
  let control;
  if (controlType === "select") {
    control = document.createElement("select");
    options.forEach((option) => {
      const optionElement = document.createElement("option");
      optionElement.value = String(option?.value ?? "");
      optionElement.textContent = String(option?.label ?? option?.value ?? "");
      control.appendChild(optionElement);
    });
  } else if (
    controlType === "textarea"
    || isLong
    || hasBreaks
    || name === "text"
    || name === "content"
  ) {
    control = document.createElement("textarea");
  } else {
    control = document.createElement("input");
  }

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
    control.rows = Number.isInteger(rows)
      ? Math.min(16, Math.max(3, rows))
      : Math.min(8, Math.max(3, Math.ceil((value?.length || 0) / 60)));
  }

  fieldWrapper.appendChild(labelEl);
  if (helpText) {
    const help = document.createElement("span");
    help.className = "dev-inline-editor-field-help";
    help.textContent = helpText;
    fieldWrapper.appendChild(help);
  }
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

  if (control instanceof HTMLSelectElement && options.length) {
    const preview = document.createElement("div");
    preview.className = "dev-inline-editor-icon-preview";

    const previewImage = document.createElement("img");
    previewImage.alt = "";
    previewImage.setAttribute("aria-hidden", "true");

    const previewText = document.createElement("span");
    preview.appendChild(previewImage);
    preview.appendChild(previewText);

    const updatePreview = () => {
      const selected = options.find((option) => String(option?.value ?? "") === control.value);
      const previewSrc = String(selected?.preview ?? "");
      previewImage.hidden = previewSrc === "";
      if (previewSrc === "") {
        previewImage.removeAttribute("src");
      } else {
        previewImage.src = previewSrc;
      }
      previewText.textContent = String(
        selected?.description
        ?? selected?.label
        ?? selected?.value
        ?? "",
      );
    };

    control.addEventListener("change", updatePreview);
    updatePreview();
    fieldWrapper.appendChild(preview);
  }

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
const INLINE_EDITOR_HANDLER_KEY = "__liquidStackInlineEditorDblClickHandler";

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
      const fieldMeta = entry.fieldMeta?.[name] || {};
      section.appendChild(
        createField({
          entryId: entryIndex,
          name,
          value,
          group: "main",
          label: fieldMeta.label ?? name,
          enableRichText: supportsText && name === "text",
          controlType: fieldMeta.controlType ?? "",
          options: fieldMeta.options ?? [],
          helpText: fieldMeta.helpText ?? "",
          rows: fieldMeta.rows ?? null,
          dataset: fieldMeta.dataset ?? {},
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
    if (
      target instanceof HTMLTextAreaElement
      && target.dataset.multiline === "true"
    ) {
      if (event.ctrlKey || event.metaKey) {
        event.preventDefault();
        if (!submitBtn.disabled) {
          submitBtn.click();
        }
      }
      return;
    }

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
      fieldMeta: entry.fieldMeta || {},
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
      validateSelectEntries(payload);
      validateVideoResourceEntries(payload);
      validateLocalMediaEntries(payload);
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

  const handleInlineEditorLanguageChange = (event) => {
    rehydrateConfig(event?.detail || {});
  };
  let isOpen = false;

  const handleInlineEditorAnchorClick = (event) => {
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
  };

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
        fieldMeta: getInlineFieldMeta(element),
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
          fieldMeta: getInlineFieldMeta(element),
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

  const saveBatchValues = async ({ scope, updates, lang = config.lang }) => {
    const response = await fetch("/languages/update", {
      method: "POST",
      headers: {
        "Content-Type": "application/json",
      },
      body: JSON.stringify({
        scope,
        lang,
        route: config.route,
        updates,
      }),
    });

    if (!response.ok) {
      const message = await response.text();
      throw new Error(message || "No se pudieron guardar los cambios");
    }

    const result = await response.json();
    const savedUpdates = Array.isArray(result.updates) ? result.updates : [];

    if (lang === config.lang) {
      savedUpdates.forEach((entry) => {
        if (!entry?.key) {
          return;
        }
        setCacheValue(result.scope || scope, entry.key, entry.data);
      });
    }

    return {
      scope: result.scope || scope,
      updates: savedUpdates,
    };
  };

  const parseCollectionIconOptions = (collection) => {
    const rawOptions = collection.dataset.inlineIconOptions || "[]";

    try {
      const parsed = JSON.parse(rawOptions);
      if (!Array.isArray(parsed)) {
        return [];
      }

      return parsed
        .filter((option) => option && typeof option === "object")
        .map((option) => {
          const value = String(option.value ?? "");
          const label = String(option.label ?? value);
          let description = label;

          if (value === "default") {
            description = "Usa el marcador predeterminado del recurso o de su contexto.";
          } else if (value === "none") {
            description = "Oculta el marcador decorativo de todos los elementos.";
          }

          return {
            value,
            label,
            preview: String(option.preview ?? ""),
            description,
          };
        });
    } catch (error) {
      console.warn("Inline editor: no se pudo leer el catálogo de iconos.", error);
      return [];
    }
  };

  const applyCollectionIcon = (collection, token, options) => {
    const selected = options.find((option) => option.value === token);
    const preview = String(selected?.preview ?? "");

    collection.dataset.markerIcon = token;
    if (preview && token !== "default" && token !== "none") {
      const safePreview = preview.replace(/["\\\n\r]/g, "");
      collection.style.setProperty(
        "--moduleList01-marker-mask",
        `url("${safePreview}")`,
      );
    } else {
      collection.style.removeProperty("--moduleList01-marker-mask");
    }
  };

  const normalizeBackgroundUrl = (value) => {
    const rawValue = valueToString(value).trim();
    if (!rawValue) {
      return "";
    }
    if (
      isExternalHref(rawValue)
      || rawValue.startsWith("data:")
      || rawValue.startsWith("blob:")
    ) {
      return rawValue;
    }
    return `${window.location.origin.replace(/\/$/, "")}/${rawValue.replace(/^\/+/, "")}`;
  };

  const applyResponsiveBackground = (target) => {
    if (!(target instanceof HTMLElement)) {
      return;
    }

    const responsiveUrl = window.innerWidth < 800
      ? target.dataset.bgMobile
      : (window.innerWidth < 1400
        ? target.dataset.bgTablet
        : target.dataset.bgDesktop);
    const activeUrl = responsiveUrl || target.dataset.bgFallback || "";

    if (!activeUrl) {
      target.style.removeProperty("background-image");
      return;
    }

    const safeUrl = activeUrl.replace(/["\\\n\r]/g, "");
    target.style.setProperty(
      "background-image",
      `url("${safeUrl}")`,
      "important",
    );
  };

  const getBackgroundDefinitions = (container) => {
    const definitions = [
      {
        datasetKey: "inlineBackgroundMobileKey",
        variant: "mobile",
        targetDataset: "bgMobile",
        label: "Imagen móvil",
      },
      {
        datasetKey: "inlineBackgroundTabletKey",
        variant: "tablet",
        targetDataset: "bgTablet",
        label: "Imagen tablet",
      },
      {
        datasetKey: "inlineBackgroundDesktopKey",
        variant: "desktop",
        targetDataset: "bgDesktop",
        label: "Imagen escritorio",
      },
      {
        datasetKey: "inlineBackgroundFallbackKey",
        variant: "fallback",
        targetDataset: "bgFallback",
        label: "Imagen de respaldo",
      },
      {
        datasetKey: "inlineBackgroundImageKey",
        variant: "image",
        targetDataset: "",
        label: "Imagen de fondo",
      },
    ];

    return definitions
      .map((definition) => ({
        ...definition,
        key: String(container.dataset[definition.datasetKey] || "").trim(),
      }))
      .filter((definition) => definition.key !== "");
  };

  const openBackgroundEditor = async (container) => {
    const targetSelector = String(container.dataset.inlineBackgroundTarget || "").trim();
    let target = container;

    if (targetSelector) {
      try {
        target = container.querySelector(targetSelector);
      } catch (error) {
        throw new Error("El selector del fondo editable no es válido.");
      }
    }

    if (!(target instanceof Element)) {
      throw new Error("No se encontró el elemento visual del fondo.");
    }

    const definitions = getBackgroundDefinitions(container);
    if (!definitions.length) {
      throw new Error("El fondo no tiene claves de idioma configuradas.");
    }

    const entries = [];
    const missingKeys = [];

    for (const definition of definitions) {
      const resolved = await resolveValues(definition.key, target);
      if (!resolved) {
        missingKeys.push(definition.key);
        continue;
      }

      const fields = resolved.type === "object"
        ? cloneFields(resolved.fields)
        : { src: String(resolved.fields.text ?? "") };

      entries.push({
        key: definition.key,
        scope: resolved.scope,
        fields,
        valueType: resolved.type,
        element: target,
        related: [],
        backgroundDefinition: definition,
        fieldMeta: {
          src: {
            label: definition.label,
            helpText: "Ruta relativa dentro de assets o URL absoluta.",
          },
          alt: {
            label: "Texto alternativo",
          },
          title: {
            label: "Título de la imagen",
          },
        },
      });
    }

    if (missingKeys.length || entries.length !== definitions.length) {
      const suffix = missingKeys.length ? `: ${missingKeys.join(", ")}` : "";
      throw new Error(`Faltan claves de idioma para este fondo${suffix}.`);
    }

    showModal({
      entries,
      onSubmit: async (payloadEntries) => {
        const updatesByScope = new Map();
        const nextValues = new Map();

        payloadEntries.forEach((payloadEntry, index) => {
          const entry = entries[index];
          const values = payloadEntry.values || {};
          const nextValue = entry.valueType === "object"
            ? values
            : String(values.src ?? "");
          const scope = entry.scope || config.route;

          if (!scope) {
            throw new Error("No se pudo resolver el archivo de idioma del fondo.");
          }
          if (!updatesByScope.has(scope)) {
            updatesByScope.set(scope, []);
          }
          updatesByScope.get(scope).push({
            key: entry.key,
            values: nextValue,
          });
          nextValues.set(entry.key, nextValue);
        });

        const savedByKey = new Map();
        for (const [scope, updates] of updatesByScope.entries()) {
          const result = await saveBatchValues({ scope, updates });
          result.updates.forEach((saved) => {
            savedByKey.set(saved.key, saved.data);
          });
        }

        entries.forEach((entry) => {
          const savedValue = savedByKey.has(entry.key)
            ? savedByKey.get(entry.key)
            : nextValues.get(entry.key);
          const definition = entry.backgroundDefinition;

          if (definition.variant === "image") {
            const imageValue = entry.valueType === "object"
              ? savedValue
              : { src: savedValue };
            applyValuesToElement(target, imageValue, config);
            return;
          }

          const sourceValue = savedValue
            && typeof savedValue === "object"
            && !Array.isArray(savedValue)
            ? savedValue.src
            : savedValue;
          const normalizedUrl = normalizeBackgroundUrl(sourceValue);

          if (definition.targetDataset) {
            target.dataset[definition.targetDataset] = normalizedUrl;
          }
        });

        if (definitions.some((definition) => definition.variant !== "image")) {
          applyResponsiveBackground(target);
        }
      },
      onClose: () => {
        isOpen = false;
      },
    });
  };

  const openLineCollectionEditor = async (collection) => {
    const itemElements = Array.from(
      collection.querySelectorAll("[data-inline-collection-item][data-lang]"),
    );

    if (!itemElements.length) {
      throw new Error("La lista no contiene elementos editables.");
    }

    const itemEntries = [];
    const missingKeys = [];

    for (const element of itemElements) {
      const key = element.getAttribute("data-lang");
      if (!key) {
        continue;
      }

      const resolved = await resolveValues(key, element);
      if (!resolved) {
        missingKeys.push(key);
        continue;
      }

      itemEntries.push({
        key,
        scope: resolved.scope,
        fields: cloneFields(resolved.fields),
        valueType: resolved.type,
        element,
      });
    }

    if (missingKeys.length || itemEntries.length !== itemElements.length) {
      const suffix = missingKeys.length ? `: ${missingKeys.join(", ")}` : "";
      throw new Error(`Faltan claves de idioma para esta lista${suffix}.`);
    }

    const iconKey = collection.dataset.inlineIconKey || "";
    const iconResolved = iconKey
      ? await resolveValues(iconKey, collection)
      : null;
    const iconOptions = parseCollectionIconOptions(collection);
    const allowedIcons = new Set(iconOptions.map((option) => option.value));

    let iconToken = collection.dataset.markerIcon || "default";
    if (iconResolved) {
      if (iconResolved.type === "object") {
        iconToken = String(
          iconResolved.fields.value
          ?? iconResolved.fields.text
          ?? iconToken,
        );
      } else {
        iconToken = String(iconResolved.fields.text ?? iconToken);
      }
    }
    if (!allowedIcons.has(iconToken)) {
      iconToken = allowedIcons.has("default")
        ? "default"
        : (iconOptions[0]?.value ?? "");
    }

    const collectionKey = collection.dataset.inlineCollectionKey
      || iconKey.replace(/_marker_icon$/, "")
      || itemEntries[0].key;
    const itemCount = itemEntries.length;
    const itemTexts = itemEntries.map((entry) => String(entry.fields.text ?? ""));
    const primaryScope = itemEntries[0].scope || config.route;
    const iconScope = primaryScope;

    const entry = {
      key: collectionKey,
      scope: primaryScope,
      fields: {
        items: itemTexts.join("\n"),
        icon: iconToken,
      },
      fieldMeta: {
        items: {
          label: `Elementos de la lista (${itemCount})`,
          controlType: "textarea",
          rows: Math.max(4, itemCount),
          helpText: `Una línea corresponde a un <li>. Mantén exactamente ${itemCount} líneas.`,
          dataset: {
            multiline: "true",
          },
        },
        icon: {
          label: "Icono de la lista",
          controlType: "select",
          options: iconOptions,
          helpText: "Se aplica a todos los elementos y se mantiene igual en todos los idiomas.",
        },
      },
      element: collection,
      related: [],
    };

    showModal({
      entries: [entry],
      onSubmit: async (payloadEntries) => {
        const values = payloadEntries[0]?.values || {};
        const normalizedText = String(values.items ?? "").replace(/\r\n?/g, "\n");
        const lines = normalizedText.split("\n");

        if (lines.length !== itemCount) {
          throw new Error(
            `La lista necesita exactamente ${itemCount} líneas; has introducido ${lines.length}.`,
          );
        }

        const normalizedLines = lines.map((line) => line.trim());
        if (normalizedLines.some((line) => line === "")) {
          throw new Error("Cada línea debe contener el texto de un elemento.");
        }

        const nextIcon = String(values.icon ?? iconToken);
        if (!allowedIcons.has(nextIcon)) {
          throw new Error("El icono seleccionado no pertenece al catálogo permitido.");
        }

        const updatesByScope = new Map();
        const registerUpdate = (scope, update) => {
          const resolvedScope = scope || config.route;
          if (!resolvedScope) {
            throw new Error("No se pudo resolver el archivo de idioma de la lista.");
          }
          if (!updatesByScope.has(resolvedScope)) {
            updatesByScope.set(resolvedScope, []);
          }
          updatesByScope.get(resolvedScope).push(update);
        };

        itemEntries.forEach((itemEntry, index) => {
          const nextValue = itemEntry.valueType === "object"
            ? { ...itemEntry.fields, text: normalizedLines[index] }
            : normalizedLines[index];

          registerUpdate(itemEntry.scope, {
            key: itemEntry.key,
            values: nextValue,
          });
        });

        let iconUpdate = null;
        if (iconKey) {
          let nextIconValue = nextIcon;
          if (iconResolved?.type === "object") {
            nextIconValue = { ...iconResolved.fields };
            if (Object.prototype.hasOwnProperty.call(nextIconValue, "value")) {
              nextIconValue.value = nextIcon;
            } else if (Object.prototype.hasOwnProperty.call(nextIconValue, "text")) {
              nextIconValue.text = nextIcon;
            } else {
              nextIconValue.value = nextIcon;
            }
          }

          iconUpdate = {
            key: iconKey,
            values: nextIconValue,
          };
          registerUpdate(iconScope, iconUpdate);
        }

        const savedByKey = new Map();
        for (const [scope, updates] of updatesByScope.entries()) {
          const result = await saveBatchValues({ scope, updates });
          result.updates.forEach((saved) => {
            savedByKey.set(saved.key, saved.data);
          });
        }

        if (iconUpdate) {
          const alternateLanguages = Array.from(
            document.querySelectorAll(".btn_idioma[id]"),
          )
            .map((button) => String(button.id || "").trim())
            .filter((lang, index, languages) => (
              lang !== ""
              && lang !== config.lang
              && languages.indexOf(lang) === index
            ));

          for (const lang of alternateLanguages) {
            await saveBatchValues({
              scope: iconScope,
              updates: [iconUpdate],
              lang,
            });
          }
        }

        itemEntries.forEach((itemEntry, index) => {
          const fallbackValue = itemEntry.valueType === "object"
            ? { ...itemEntry.fields, text: normalizedLines[index] }
            : normalizedLines[index];
          const savedValue = savedByKey.has(itemEntry.key)
            ? savedByKey.get(itemEntry.key)
            : fallbackValue;
          applyValuesToElement(itemEntry.element, savedValue, config);
        });

        applyCollectionIcon(collection, nextIcon, iconOptions);
      },
      onClose: () => {
        isOpen = false;
      },
    });
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

    if (event.target instanceof Element) {
      // Opt-in group for composite resources whose editable fields are siblings.
      const group = event.target.closest("[data-inline-group]");
      if (group) {
        register(group);
        group.querySelectorAll("[data-lang]").forEach((descendant) => {
          register(descendant);
        });
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

  const handleInlineEditorDoubleClick = async (event) => {
    if (!event.ctrlKey || isOpen) {
      return;
    }

    const collection = event.target instanceof Element
      ? event.target.closest('[data-inline-collection="lines"]')
      : null;

    if (collection) {
      event.preventDefault();
      event.stopPropagation();
      isOpen = true;

      try {
        await openLineCollectionEditor(collection);
      } catch (error) {
        console.error(error);
        alert(error instanceof Error ? error.message : String(error));
        isOpen = false;
      }
      return;
    }

    const targets = collectLanguageElements(event);
    const background = !targets.length && event.target instanceof Element
      ? event.target.closest("[data-inline-background]")
      : null;

    if (background) {
      event.preventDefault();
      event.stopPropagation();
      isOpen = true;

      try {
        await openBackgroundEditor(background);
      } catch (error) {
        console.error(error);
        alert(error instanceof Error ? error.message : String(error));
        isOpen = false;
      }
      return;
    }

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
          fieldMeta: resolved.fieldMeta || {},
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
  };

  const previousHandlers = window[INLINE_EDITOR_HANDLER_KEY];
  if (typeof previousHandlers === "function") {
    // Compatibilidad con inicializaciones anteriores durante HMR.
    document.removeEventListener("dblclick", previousHandlers);
  } else if (previousHandlers && typeof previousHandlers === "object") {
    if (typeof previousHandlers.doubleClick === "function") {
      document.removeEventListener("dblclick", previousHandlers.doubleClick);
    }
    if (typeof previousHandlers.anchorClick === "function") {
      document.removeEventListener("click", previousHandlers.anchorClick, true);
    }
    if (typeof previousHandlers.languageChange === "function") {
      window.removeEventListener(
        "app:languagechange",
        previousHandlers.languageChange,
      );
    }
  }

  window[INLINE_EDITOR_HANDLER_KEY] = {
    doubleClick: handleInlineEditorDoubleClick,
    anchorClick: handleInlineEditorAnchorClick,
    languageChange: handleInlineEditorLanguageChange,
  };
  document.addEventListener("dblclick", handleInlineEditorDoubleClick);
  document.addEventListener("click", handleInlineEditorAnchorClick, true);
  window.addEventListener(
    "app:languagechange",
    handleInlineEditorLanguageChange,
  );
}
