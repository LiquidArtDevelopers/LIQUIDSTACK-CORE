import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export default function initArtScale01() {
  gsap.registerPlugin(ScrollTrigger);

  const roots = Array.from(document.querySelectorAll('.artScale01'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    const media = root.querySelector('.artScale01-media');
    const textBlock = root.querySelector('.artScale01-text');
    if (!media) {
      return;
    }

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) {
      gsap.set(media, { scale: 1, autoAlpha: 1, clearProps: 'transform' });
      return;
    }

    gsap.set(media, { scale: 0.15, autoAlpha: 0 });
    if (textBlock) {
      gsap.set(textBlock, { yPercent: 25 });
    }

    const timeline = gsap.timeline({
      scrollTrigger: {
        trigger: root,
        start: 'top top',
        end: '+=200%',
        scrub: true,
        pin: true,
        pinSpacing: true,
        pinType: 'transform',
        anticipatePin: 1,
        invalidateOnRefresh: true,
      },
    });

    timeline.to(media, { scale: 1, autoAlpha: 1, ease: 'none' }, 0);

    if (textBlock) {
      timeline.to(textBlock, { yPercent: 0, ease: 'none' }, 0);
    }
  });
}
