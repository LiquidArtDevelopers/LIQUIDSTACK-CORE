import gsap from 'gsap';

const splitText = (element) => {
  if (!element || element.dataset.split === 'true') {
    return;
  }

  const text = element.textContent || '';
  element.textContent = '';

  const words = text.trim().split(/\s+/).filter(Boolean);
  let letterIndex = 0;

  words.forEach((word, wordIndex) => {
    const wordWrap = document.createElement('span');
    wordWrap.className = 'artHeroScroll01-splitWord';
    wordWrap.dataset.word = String(wordIndex);

    Array.from(word).forEach((char) => {
      const span = document.createElement('span');
      span.dataset.letter = String(letterIndex);
      span.textContent = char;
      wordWrap.appendChild(span);
      letterIndex += 1;
    });

    element.appendChild(wordWrap);
    if (wordIndex < words.length - 1) {
      element.appendChild(document.createTextNode(' '));
    }
  });

  element.dataset.split = 'true';
};

const prepareMainWord = (heading) => {
  if (!heading) {
    return null;
  }

  const existing = heading.querySelector('.js-hero-main-split');
  if (existing) {
    return existing;
  }

  const text = heading.textContent || '';
  const words = text.trim().split(/\s+/).filter(Boolean);
  if (words.length === 0) {
    return null;
  }

  const lastWord = words.pop();
  const before = words.join(' ');
  const accent = '<span class="artHeroScroll01-accent artHeroScroll01-split js-hero-main-split">' + lastWord + '</span>';
  heading.innerHTML = before ? before + ' ' + accent : accent;
  return heading.querySelector('.js-hero-main-split');
};

const playSplit = (element) => {
  if (!element || element.dataset.animating === 'true') {
    return;
  }

  splitText(element);
  const letters = Array.from(element.querySelectorAll('[data-letter]'));
  if (letters.length === 0) {
    return;
  }

  element.dataset.animating = 'true';
  gsap.killTweensOf(letters);
  gsap.set(letters, { y: 0, opacity: 1 });

  const timeline = gsap.timeline({
    onComplete: () => {
      element.dataset.animating = 'false';
    },
  });

  timeline.to(letters, {
    y: -24,
    opacity: 0,
    duration: 0.45,
    ease: 'power2.out',
    stagger: 0.05,
  });

  timeline.to(letters, {
    y: 0,
    opacity: 1,
    duration: 0.3,
    ease: 'power2.out',
    stagger: 0.04,
  }, '+=0.2');
};

const initSplits = (section) => {
  const heading = section.querySelector('.artHeroScroll01-head h2, .artHeroScroll01-head h3, .artHeroScroll01-head h4');
  const mainWord = prepareMainWord(heading);
  if (mainWord) {
    splitText(mainWord);
  }

  const items = Array.from(section.querySelectorAll('[data-hero-item]'));
  items.forEach((item) => {
    const title = item.querySelector('.artHeroScroll01-title');
    if (!title) {
      return;
    }
    splitText(title);
    item.addEventListener('mouseenter', () => playSplit(title));
  });

  if (!section.dataset.heroLoop) {
    section.dataset.heroLoop = 'true';
    setInterval(() => {
      const target = prepareMainWord(heading);
      playSplit(target);
    }, 5000);
  }
};

export default function initArtHeroScroll01() {
  const sections = Array.from(document.querySelectorAll('[data-hero-scroll]'));
  if (sections.length === 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  sections.forEach((section) => {
    if (section.dataset.heroInit === 'true') {
      return;
    }
    section.dataset.heroInit = 'true';

    if (prefersReducedMotion) {
      return;
    }

    initSplits(section);
  });
}

