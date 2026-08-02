const CUSTOM_CONSENT_COOKIE = "cookie_custom";
const CUSTOM_LANGUAGE_COOKIE = "cookie_custom_lang";

export function createLatestLanguageRequestTracker() {
  let latestRequest = 0;

  return Object.freeze({
    next() {
      latestRequest += 1;
      return latestRequest;
    },
    isCurrent(requestToken) {
      return Number.isSafeInteger(requestToken)
        && requestToken > 0
        && requestToken === latestRequest;
    },
  });
}

export async function loadLanguageCatalogs(fetchCatalog, routes, language) {
  if (typeof fetchCatalog !== "function") {
    throw new TypeError("language_catalog_fetch_unavailable");
  }

  const normalizedRoutes = [...new Set(
    (Array.isArray(routes) ? routes : [])
      .filter((route) => typeof route === "string")
      .map((route) => route.trim())
      .filter((route) => route !== "")
  )];
  if (normalizedRoutes.length === 0) {
    throw new TypeError("language_catalog_route_missing");
  }

  return Promise.all(normalizedRoutes.map(async (route) => {
    const response = await fetchCatalog("/languages", {
      body: new URLSearchParams({ route, lang: language }),
      method: "POST",
      headers: {
        "Content-Type": "application/x-www-form-urlencoded;charset=UTF-8",
      },
    });
    if (!response?.ok) {
      throw new Error(`language_catalog_http_${response?.status ?? 0}`);
    }

    const catalog = JSON.parse(await response.text());
    if (!catalog || typeof catalog !== "object" || Array.isArray(catalog)) {
      throw new Error("language_catalog_invalid");
    }

    return catalog;
  }));
}

export function persistLanguagePreference(cookieLAD, language) {
  if (
    !cookieLAD
    || typeof cookieLAD.getCookie !== "function"
    || typeof cookieLAD.setCookie !== "function"
  ) {
    return false;
  }

  try {
    if (cookieLAD.getCookie(CUSTOM_CONSENT_COOKIE) !== "true") {
      if (typeof cookieLAD.deleteCookie === "function") {
        cookieLAD.deleteCookie(CUSTOM_LANGUAGE_COOKIE);
      }

      return false;
    }

    cookieLAD.setCookie(CUSTOM_LANGUAGE_COOKIE, language, 90);
    return true;
  } catch {
    return false;
  }
}

export function resolveLanguageNavigationHref(rawHref, currentHref) {
  const href = typeof rawHref === "string" ? rawHref.trim() : "";

  if (href === "" || href.startsWith("#")) {
    return null;
  }

  try {
    const currentUrl = new URL(currentHref);
    const targetUrl = new URL(href, currentUrl);

    if (
      !["http:", "https:"].includes(targetUrl.protocol)
      || targetUrl.origin !== currentUrl.origin
    ) {
      return null;
    }

    return targetUrl.href;
  } catch {
    return null;
  }
}

export function navigateToLanguageHref(windowRef, rawHref) {
  const targetHref = resolveLanguageNavigationHref(
    rawHref,
    windowRef?.location?.href ?? ""
  );

  if (
    targetHref === null
    || typeof windowRef?.location?.assign !== "function"
  ) {
    return false;
  }

  windowRef.location.assign(targetHref);
  return true;
}
