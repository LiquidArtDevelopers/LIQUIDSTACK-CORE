import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export default function initArtMarquee01() {
  gsap.registerPlugin(ScrollTrigger);

  const roots = Array.from(document.querySelectorAll('.artMarquee01'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    const marquees = Array.from(root.querySelectorAll('[data-marquee]'));
    if (marquees.length === 0) {
      return;
    }

    marquees.forEach((marquee) => {
      const track = marquee.querySelector('.marquee-track');
      const group = marquee.querySelector('.marquee-group');
      if (!track || !group) {
        return;
      }

      if (track.children.length < 2) {
        track.appendChild(group.cloneNode(true));
      }

      const speed = parseFloat(marquee.dataset.marqueeSpeed || '20');
      const baseDirection = parseInt(marquee.dataset.direction || '1', 10);
      const baseScale = baseDirection >= 0 ? 1 : -1;

      const tween = gsap.to(track, {
        xPercent: -50,
        duration: speed,
        repeat: -1,
        ease: 'none',
      });

      tween.timeScale(baseScale);

      ScrollTrigger.create({
        trigger: root,
        start: 'top bottom',
        end: 'bottom top',
        onUpdate: (self) => {
          const direction = self.direction === 1 ? 1 : -1;
          tween.timeScale(baseScale * direction);
        },
      });
    });
  });
}
