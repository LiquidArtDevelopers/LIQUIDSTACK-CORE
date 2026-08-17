const RESPONSIVE_CANDIDATES = [
  ["bgMobile", "inlineBackgroundMobileDescriptor"],
  ["bgTablet", "inlineBackgroundTabletDescriptor"],
  ["bgDesktop", "inlineBackgroundDesktopDescriptor"],
];

const validDescriptor = (value) =>
  /^(?:[1-9][0-9]{0,4}w|[1-9][0-9]*(?:\.[0-9]+)?x)$/.test(value);

/**
 * Projects the four-value inline background contract onto a real picture.
 * The component declares its source selector and candidate descriptors.
 */
export function applyInlineResponsivePicture(container, target) {
  if (
    !container
    || !target
    || String(target.tagName || "").toUpperCase() !== "IMG"
    || typeof container.querySelector !== "function"
    || typeof target.setAttribute !== "function"
  ) {
    return false;
  }

  const sourceSelector = String(
    container.dataset?.inlineBackgroundPictureSource || "",
  ).trim();
  if (sourceSelector === "") {
    return false;
  }

  let source;
  try {
    source = container.querySelector(sourceSelector);
  } catch (error) {
    return false;
  }
  if (!source || typeof source.setAttribute !== "function") {
    return false;
  }

  const candidates = [];
  for (const [urlKey, descriptorKey] of RESPONSIVE_CANDIDATES) {
    const url = String(target.dataset?.[urlKey] || "").trim();
    const descriptor = String(container.dataset?.[descriptorKey] || "").trim();
    if (url === "" || !validDescriptor(descriptor)) {
      return false;
    }

    candidates.push(`${url} ${descriptor}`);
  }

  const fallback = String(target.dataset?.bgFallback || "").trim();
  if (fallback === "") {
    return false;
  }

  source.setAttribute("srcset", candidates.join(", "));
  target.setAttribute("src", fallback);

  return true;
}
