import gsap from 'gsap';

const ROOT_SELECTOR = '.artAccordion02';
const TRIGGER_SELECTOR = '.artAccordion02-trigger';
const PANEL_SELECTOR = '.artAccordion02-panel';
const ITEM_SELECTOR = '.artAccordion02-item';
const READY_ATTRIBUTE = 'artAccordion02Ready';
const activeInstances = new Map();

function collectRoots(scope) {
  const roots = [];

  if (scope?.matches?.(ROOT_SELECTOR)) {
    roots.push(scope);
  }

  scope?.querySelectorAll?.(ROOT_SELECTOR).forEach((root) => roots.push(root));

  return roots;
}

function findControlledPanel(root, trigger) {
  const panelId = trigger.getAttribute('aria-controls');
  if (!panelId) {
    return null;
  }

  return Array.from(root.querySelectorAll(PANEL_SELECTOR))
    .find((panel) => panel.id === panelId) || null;
}

function prefersReducedMotion() {
  return window.matchMedia('(prefers-reduced-motion: reduce)').matches;
}

function updateState(trigger, panel, expanded) {
  const item = trigger.closest(ITEM_SELECTOR);

  trigger.setAttribute('aria-expanded', String(expanded));
  item?.classList.toggle('is-open', expanded);

  if (expanded) {
    panel.removeAttribute('aria-hidden');
  } else {
    panel.setAttribute('aria-hidden', 'true');
  }
}

function revealPanel(trigger, panel) {
  const wasHidden = panel.hidden;

  gsap.killTweensOf(panel);
  panel.hidden = false;
  updateState(trigger, panel, true);

  if (prefersReducedMotion()) {
    gsap.set(panel, {
      clearProps: 'height,opacity,overflow,visibility',
    });
    return;
  }

  if (wasHidden) {
    gsap.set(panel, {
      autoAlpha: 0,
      height: 0,
      overflow: 'hidden',
    });
  } else {
    gsap.set(panel, { overflow: 'hidden' });
  }

  gsap.to(panel, {
    autoAlpha: 1,
    height: 'auto',
    duration: 0.36,
    ease: 'power2.out',
    overwrite: true,
    onComplete: () => {
      if (trigger.getAttribute('aria-expanded') !== 'true') {
        return;
      }

      gsap.set(panel, {
        clearProps: 'height,opacity,overflow,visibility',
      });
    },
  });
}

function concealPanel(trigger, panel, immediate = false) {
  gsap.killTweensOf(panel);
  updateState(trigger, panel, false);

  if (immediate || prefersReducedMotion()) {
    panel.hidden = true;
    panel.setAttribute('aria-hidden', 'true');
    gsap.set(panel, {
      clearProps: 'height,opacity,overflow,visibility',
    });
    return;
  }

  if (panel.hidden) {
    panel.setAttribute('aria-hidden', 'true');
    return;
  }

  gsap.set(panel, {
    height: panel.getBoundingClientRect().height,
    overflow: 'hidden',
  });

  gsap.to(panel, {
    autoAlpha: 0,
    height: 0,
    duration: 0.28,
    ease: 'power2.inOut',
    overwrite: true,
    onComplete: () => {
      if (trigger.getAttribute('aria-expanded') !== 'false') {
        return;
      }

      panel.hidden = true;
      panel.setAttribute('aria-hidden', 'true');
      gsap.set(panel, {
        clearProps: 'height,opacity,overflow,visibility',
      });
    },
  });
}

function setExpanded(root, trigger, expanded, immediate = false) {
  const panel = findControlledPanel(root, trigger);
  if (!panel) {
    return;
  }

  if (expanded) {
    revealPanel(trigger, panel);
    return;
  }

  concealPanel(trigger, panel, immediate);
}

function initInstance(root) {
  const existingInstance = activeInstances.get(root);
  if (existingInstance) {
    return existingInstance;
  }

  root.querySelectorAll(TRIGGER_SELECTOR).forEach((trigger) => {
    setExpanded(root, trigger, false, true);
  });

  const cleanupController = new AbortController();

  root.addEventListener('click', (event) => {
    const trigger = event.target.closest(TRIGGER_SELECTOR);
    if (!trigger || !root.contains(trigger)) {
      return;
    }

    const expanded = trigger.getAttribute('aria-expanded') === 'true';
    setExpanded(root, trigger, !expanded);
  }, { signal: cleanupController.signal });

  root.dataset[READY_ATTRIBUTE] = 'true';

  const instance = {
    root,
    destroy() {
      cleanupController.abort();

      root.querySelectorAll(PANEL_SELECTOR).forEach((panel) => {
        gsap.killTweensOf(panel);
        gsap.set(panel, {
          clearProps: 'height,opacity,overflow,visibility',
        });

        const trigger = Array.from(
          root.querySelectorAll(TRIGGER_SELECTOR)
        ).find((candidate) => (
          candidate.getAttribute('aria-controls') === panel.id
        ));

        if (trigger) {
          panel.hidden = false;
          updateState(trigger, panel, true);
        }
      });

      delete root.dataset[READY_ATTRIBUTE];
      activeInstances.delete(root);
    },
  };

  activeInstances.set(root, instance);

  return instance;
}

export default function initArtAccordion02(scope = document) {
  const roots = collectRoots(scope);
  return roots.map(initInstance);
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    activeInstances.forEach((instance) => instance.destroy());
    activeInstances.clear();
  });
}
