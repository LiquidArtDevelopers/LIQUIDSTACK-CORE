import { execFileSync } from "node:child_process";

const SKIP_VALUES = new Set(["1", "true"]);

export const resolveLanguageUpdate = (file) => {
  const normalized = String(file ?? "").replace(/\\/g, "/");
  if (!normalized.toLowerCase().endsWith(".php")) {
    return null;
  }

  const lowerPath = normalized.toLowerCase();
  if (/(?:^|\/)app\/includes\//.test(lowerPath)) {
    return {
      file: normalized,
      slug: "global",
    };
  }

  if (!/(?:^|\/)app\/views\//.test(lowerPath)) {
    return null;
  }

  const filename = normalized.split("/").pop() ?? "";
  if (filename.replace(/\.php$/i, "").replace(/^_+/, "") === "") {
    return null;
  }

  return {
    file: normalized,
    // El updater resuelve este nombre de vista contra routes/get.php. Así
    // `_showroom.php` puede hidratar `templates` en AIWA y `showroom` en un
    // stack que conserve ese content, sin codificar aliases del proyecto.
    slug: filename,
  };
};

export const createUpdateLanguagesPlugin = (env = {}, options = {}) => {
  const skipFlag = String(env.LANG_SKIP_UPDATE ?? "").toLowerCase();
  const shouldSkipUpdate = SKIP_VALUES.has(skipFlag);
  const logger = options.logger ?? console;
  const runUpdate = options.runUpdate ?? ((slug) => {
    execFileSync(
      options.phpBinary ?? "php",
      [options.scriptPath ?? "App/tools/update-languages.php", slug],
      {
        cwd: options.cwd ?? process.cwd(),
        stdio: "inherit",
      },
    );
  });

  return {
    name: "update-languages",
    handleHotUpdate({ file }) {
      const update = resolveLanguageUpdate(file);
      if (!update) {
        return;
      }

      if (shouldSkipUpdate) {
        logger.log(
          `[update-languages] Omitido para ${update.file}: LANG_SKIP_UPDATE está activo`,
        );
        return;
      }

      runUpdate(update.slug);
    },
  };
};

export default createUpdateLanguagesPlugin;
