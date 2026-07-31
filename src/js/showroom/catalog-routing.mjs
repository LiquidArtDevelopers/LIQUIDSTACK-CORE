const HANDLER_KEY = '__liquidstackShowroomLanguageHandler';

export const resolveShowroomBasePath = (path, activeCategory = 'index') => {
  const rawPath = typeof path === 'string' ? path : '';
  const pathOnly = rawPath.split(/[?#]/, 1)[0];
  let normalized = `/${pathOnly.replace(/^\/+|\/+$/g, '')}`;

  if (normalized === '/') {
    return normalized;
  }

  if (
    activeCategory !== 'index'
    && /^[a-z0-9-]+$/.test(activeCategory)
  ) {
    const suffix = `/${activeCategory}`;
    if (normalized.endsWith(suffix)) {
      normalized = normalized.slice(0, -suffix.length) || '/';
    }
  }

  return normalized;
};

export const resolveShowroomLink = (
  basePath,
  targetCategory = 'index',
) => {
  const normalizedBase = resolveShowroomBasePath(basePath);
  if (targetCategory === 'index') {
    return normalizedBase;
  }

  if (!/^[a-z0-9-]+$/.test(targetCategory)) {
    return normalizedBase;
  }

  return `${normalizedBase === '/' ? '' : normalizedBase}/${targetCategory}`;
};

export const updateShowroomLinks = (
  path,
  documentRef = document,
) => {
  const activeCategory =
    documentRef.body?.dataset.showroomCategory ?? 'index';
  const basePath = resolveShowroomBasePath(path, activeCategory);

  documentRef.querySelectorAll('[data-showroom-link]').forEach((link) => {
    const targetCategory = link.dataset.showroomLink ?? 'index';
    link.setAttribute(
      'href',
      resolveShowroomLink(basePath, targetCategory),
    );
  });
};

export const installShowroomLanguageLinks = (
  windowRef = window,
  documentRef = document,
) => {
  const previousHandler = windowRef[HANDLER_KEY];
  if (typeof previousHandler === 'function') {
    windowRef.removeEventListener('app:languagechange', previousHandler);
  }

  const handler = (event) => {
    const targetPath =
      event?.detail?.path ?? windowRef.location?.pathname ?? '/';
    updateShowroomLinks(targetPath, documentRef);
  };

  windowRef.addEventListener('app:languagechange', handler);
  windowRef[HANDLER_KEY] = handler;

  return () => {
    windowRef.removeEventListener('app:languagechange', handler);
    if (windowRef[HANDLER_KEY] === handler) {
      delete windowRef[HANDLER_KEY];
    }
  };
};
