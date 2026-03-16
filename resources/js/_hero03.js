import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';

export default function initHero03() {
  gsap.registerPlugin(ScrollTrigger);

  const roots = Array.from(document.querySelectorAll('.hero03'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    const columns = Array.from(root.querySelectorAll('.hero03-col'));
    if (columns.length === 0) {
      return;
    }

    const images = columns.map((col) => col.querySelector('img')).filter(Boolean);

    const prefersReduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (prefersReduced) {
      gsap.set(images, { clearProps: 'transform' });
      return;
    }

    const scaleTarget = parseFloat(root.dataset.hero03Scale || '1.08');
    const shiftTarget = parseFloat(root.dataset.hero03Shift || '2.5');
    const shiftYMin = parseFloat(root.dataset.hero03ShiftYMin || '-6');
    const shiftYMax = parseFloat(root.dataset.hero03ShiftYMax || '-12');
    const scrubValue = root.dataset.hero03Scrub ? parseFloat(root.dataset.hero03Scrub) : 0.8;
    const pinLength = parseFloat(root.dataset.hero03Pin || '120');
    const curtainMax = parseFloat(root.dataset.hero03CurtainMax || '-100');
    const frontShift = root.dataset.hero03FrontShift
      ? parseFloat(root.dataset.hero03FrontShift)
      : null;
    const frontSpeed = root.dataset.hero03FrontSpeed
      ? parseFloat(root.dataset.hero03FrontSpeed)
      : 0.35;
    const frontFade = root.dataset.hero03FrontFade ? parseFloat(root.dataset.hero03FrontFade) : 1;
    const bgOpacity = root.dataset.hero03BgOpacity ? parseFloat(root.dataset.hero03BgOpacity) : 0.3;
    const bgShift = root.dataset.hero03BgShift ? parseFloat(root.dataset.hero03BgShift) : -6;
    const brandOpacity = root.dataset.hero03BrandOpacity ? parseFloat(root.dataset.hero03BrandOpacity) : 1;
    const mouseEnabled = root.dataset.hero03Mouse !== 'false';
    const mouseBg = root.dataset.hero03MouseBg ? parseFloat(root.dataset.hero03MouseBg) : 18;
    const mouseBrand = root.dataset.hero03MouseBrand ? parseFloat(root.dataset.hero03MouseBrand) : 8;

    const foreground = root.querySelector('[data-hero03-foreground]');
    const frontCols = Array.from(root.querySelectorAll('.hero03-front-col'));
    const frontTexts = frontCols.map((col) => col.querySelector('.hero03-front-text')).filter(Boolean);
    const backdrop = root.querySelector('.hero03-backdrop');
    const backdropImg = backdrop ? backdrop.querySelector('img') : null;
    const brand = root.querySelector('.hero03-brand');

    const colCount = columns.length;
    const curtainStagger = root.dataset.hero03CurtainStagger
      ? parseFloat(root.dataset.hero03CurtainStagger)
      : 0.06;
    const curtainOrder = gsap.utils.shuffle(Array.from({ length: colCount }, (_, i) => i));

    columns.forEach((col, index) => {
      const orderIndex = curtainOrder.indexOf(index);
      col.dataset.hero03Order = String(orderIndex);
      if (frontCols[index]) {
        frontCols[index].dataset.hero03Order = String(orderIndex);
      }
      if (!col.dataset.hero03CurtainY) {
        const isLast = index === Math.floor(colCount / 2);
        const target = isLast ? curtainMax : curtainMax - gsap.utils.random(5, 20, 1);
        col.dataset.hero03CurtainY = String(target);
      }
    });

    const media = root.querySelector('.hero03-media');
    const gapValue = media ? (getComputedStyle(media).columnGap || getComputedStyle(media).gap) : '0px';
    const gapPx = gapValue ? parseFloat(gapValue) || 0 : 0;
    const colWidth = columns[0] ? columns[0].getBoundingClientRect().width : 0;
    const totalWidth = colWidth * colCount + gapPx * (colCount - 1);
    const stepPercent = totalWidth > 0 ? ((colWidth + gapPx) / totalWidth) * 100 : 100 / colCount;

    const setFrontOffsets = () => {
      const updatedColWidth = columns[0] ? columns[0].getBoundingClientRect().width : 0;
      const updatedTotalWidth = updatedColWidth * colCount + gapPx * (colCount - 1);
      const stepPx = updatedColWidth + gapPx;

      frontTexts.forEach((textEl, index) => {
        if (!textEl) return;
        textEl.style.transform = `translateX(${-index * stepPx}px)`;
        textEl.style.width = `${updatedTotalWidth}px`;
      });
    };

    images.forEach((img, index) => {
      if (!img.dataset.hero03BaseX) {
        const baseX = -index * stepPercent;
        img.dataset.hero03BaseX = String(baseX);
      }
      if (!img.dataset.hero03ShiftY) {
        const shift = gsap.utils.random(shiftYMin, shiftYMax, 1);
        img.dataset.hero03ShiftY = String(shift);
      }
    });

    frontCols.forEach((col, index) => {
      const target = columns[index]?.dataset.hero03CurtainY || '-90';
      col.dataset.hero03CurtainY = target;
    });

    setFrontOffsets();

    gsap.set(columns, { yPercent: 0 });
    gsap.set(frontCols, { yPercent: 0 });
    gsap.set(frontTexts, { yPercent: 0 });
    gsap.set(images, {
      scale: 1,
      xPercent: (i, el) => parseFloat(el.dataset.hero03BaseX || '0'),
      yPercent: 0,
    });
    if (foreground) {
      gsap.set(foreground, { yPercent: 0, opacity: 1 });
    }
    if (backdrop) {
      gsap.set(backdrop, { opacity: 0 });
    }
    if (backdropImg) {
      gsap.set(backdropImg, { yPercent: 0 });
    }
    if (brand) {
      gsap.set(brand, { opacity: 0 });
    }

    if (mouseEnabled && (backdropImg || brand)) {
      const bgX = backdropImg
        ? gsap.quickTo(backdropImg, 'x', { duration: 0.6, ease: 'power3.out' })
        : null;
      const bgY = backdropImg
        ? gsap.quickTo(backdropImg, 'y', { duration: 0.6, ease: 'power3.out' })
        : null;
      const brandX = brand
        ? gsap.quickTo(brand, 'x', { duration: 0.6, ease: 'power3.out' })
        : null;
      const brandY = brand
        ? gsap.quickTo(brand, 'y', { duration: 0.6, ease: 'power3.out' })
        : null;

      const handleMove = (event) => {
        const rect = root.getBoundingClientRect();
        if (!rect.width || !rect.height) return;
        const relX = (event.clientX - rect.left) / rect.width - 0.5;
        const relY = (event.clientY - rect.top) / rect.height - 0.5;

        if (bgX) bgX(relX * mouseBg);
        if (bgY) bgY(relY * mouseBg);
        if (brandX) brandX(relX * mouseBrand);
        if (brandY) brandY(relY * mouseBrand);
      };

      const resetMove = () => {
        if (bgX) bgX(0);
        if (bgY) bgY(0);
        if (brandX) brandX(0);
        if (brandY) brandY(0);
      };

      root.addEventListener('mousemove', handleMove);
      root.addEventListener('mouseleave', resetMove);
    }

    const timeline = gsap.timeline({
      scrollTrigger: {
        trigger: root,
        start: 'top top',
        end: `+=${pinLength}%`,
        scrub: scrubValue,
        pin: true,
        pinSpacing: true,
        anticipatePin: 1,
        invalidateOnRefresh: true,
      },
    });

    const curtainStaggerFn = (index, target) => {
      const orderIndex = target?.dataset?.hero03Order
        ? parseFloat(target.dataset.hero03Order)
        : 0;
      return orderIndex * curtainStagger;
    };

    timeline.to(
      columns,
      {
        yPercent: (i, el) => parseFloat(el.dataset.hero03CurtainY || '-90'),
        ease: 'none',
        stagger: curtainStaggerFn,
      },
      0
    );

    timeline.to(
      frontCols,
      {
        yPercent: (i, el) => parseFloat(el.dataset.hero03CurtainY || '-90'),
        ease: 'none',
        stagger: curtainStaggerFn,
      },
      0
    );

    if (frontTexts.length > 0) {
      timeline.to(
        frontTexts,
        {
          yPercent: (i, el) => {
            if (typeof frontShift === 'number' && !Number.isNaN(frontShift)) {
              return frontShift;
            }
            const parent = el.closest('.hero03-front-col');
            const base = parent?.dataset?.hero03CurtainY || curtainMax;
            return parseFloat(base) * frontSpeed;
          },
          opacity: frontFade,
          ease: 'none',
        },
        0
      );
    }

    if (foreground) {
      timeline.to(
        foreground,
        {
          opacity: frontFade,
          ease: 'none',
        },
        0
      );
    }

    if (backdrop) {
      timeline.to(
        backdrop,
        {
          opacity: bgOpacity,
          ease: 'none',
        },
        0
      );
    }
    if (backdropImg) {
      timeline.to(
        backdropImg,
        {
          yPercent: bgShift,
          ease: 'none',
        },
        0
      );
    }

    if (brand) {
      timeline.to(
        brand,
        {
          opacity: brandOpacity,
          ease: 'none',
        },
        0
      );
    }

    timeline.to(
      images,
      {
        scale: scaleTarget,
        xPercent: (i, el) => parseFloat(el.dataset.hero03BaseX || '0') + (i % 2 === 0 ? shiftTarget : -shiftTarget),
        yPercent: (i, el) => parseFloat(el.dataset.hero03ShiftY || '-8'),
        ease: 'none',
        stagger: { each: 0.06, from: 'random' },
      },
      0
    );
  });
}
