import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export default function initArtScatter01() {
  gsap.registerPlugin(ScrollTrigger);

  const roots = Array.from(document.querySelectorAll('.artScatter01'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    const textEl = root.querySelector('[data-scatter-text]');
    if (!textEl) {
      return;
    }

    const originalText = textEl.dataset.scatterOriginal || textEl.textContent.trim();
    if (!textEl.dataset.scatterOriginal) {
      textEl.dataset.scatterOriginal = originalText;
    }

    if (!textEl.dataset.scatterSplit) {
      splitWords(textEl, originalText);
      textEl.dataset.scatterSplit = 'true';
    }

    const words = Array.from(textEl.querySelectorAll('.word'));
    if (words.length === 0) {
      return;
    }

    const maxX = parseInt(root.dataset.scatterX || '500', 10);
    const maxY = parseInt(root.dataset.scatterY || '500', 10);
    const maxRotate = parseInt(root.dataset.scatterRotate || '35', 10);

    if (textEl._scatterTween) {
      textEl._scatterTween.scrollTrigger?.kill();
      textEl._scatterTween.kill();
    }

    const scaleMin = parseFloat(root.dataset.scatterScaleMin || '0.5');
    const scaleMax = parseFloat(root.dataset.scatterScaleMax || '4.5');
    const durationMin = parseFloat(root.dataset.scatterDurationMin || '0.6');
    const durationMax = parseFloat(root.dataset.scatterDurationMax || '1.6');
    const offsetMax = parseFloat(root.dataset.scatterOffsetMax || '0.6');
    const pinDistance = parseInt(root.dataset.scatterPin || '220', 4);

    words.forEach((word) => {
      const x = gsap.utils.random(-maxX, maxX, 1);
      const y = gsap.utils.random(-maxY, maxY, 1);
      // const rotation = gsap.utils.random(-maxRotate, maxRotate, 1);
      const scale = gsap.utils.random(scaleMin, scaleMax, 0.01);
      // gsap.set(word, { x, y, rotation, scale, opacity: 0 });
      gsap.set(word, { x, y, scale, opacity: 0 });
    });

    const timeline = gsap.timeline({
      scrollTrigger: {
        trigger: root,
        start: 'top top',
        end: `+=${pinDistance}%`,
        scrub: true,
        pin: true,
        anticipatePin: 1,
        pinSpacing: true,
      },
    });

    words.forEach((word) => {
      const start = gsap.utils.random(0, offsetMax, 0.01);
      const duration = gsap.utils.random(durationMin, durationMax, 0.01);
      timeline.to(
        word,
        {
          x: 0,
          y: 0,
          rotation: 0,
          scale: 1,
          opacity: 1,
          ease: 'power3.out',
          duration,
        },
        start
      );
    });

    textEl._scatterTween = timeline;
  });

  function splitWords(el, text) {
    el.setAttribute('aria-label', text);
    const words = text.split(/\s+/).filter(Boolean);
    const frag = document.createDocumentFragment();

    words.forEach((word, index) => {
      const span = document.createElement('span');
      span.className = 'word';
      span.textContent = word;
      span.setAttribute('aria-hidden', 'true');
      frag.appendChild(span);
      if (index !== words.length - 1) {
        frag.appendChild(document.createTextNode(' '));
      }
    });

    el.textContent = '';
    el.appendChild(frag);
  }
}
