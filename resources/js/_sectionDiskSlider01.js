import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import * as THREE from 'three';

const inputState = {
  last: performance.now(),
};
let inputListenersReady = false;

function markInput() {
  inputState.last = performance.now();
}

function ensureInputListeners() {
  if (inputListenersReady) {
    return;
  }
  inputListenersReady = true;
  window.addEventListener('wheel', markInput, { passive: true });
  window.addEventListener('touchstart', markInput, { passive: true });
  window.addEventListener('touchmove', markInput, { passive: true });
  window.addEventListener(
    'keydown',
    (event) => {
      const keys = [
        'ArrowDown',
        'ArrowUp',
        'PageDown',
        'PageUp',
        'Home',
        'End',
        ' ',
      ];
      if (keys.includes(event.key)) {
        markInput();
      }
    },
    { passive: true }
  );
}

const VERTEX_SHADER = `
  varying vec2 vUv;

  void main() {
    vUv = uv;
    gl_Position = projectionMatrix * modelViewMatrix * vec4(position, 1.0);
  }
`;

const FRAGMENT_SHADER = `
  varying vec2 vUv;

  uniform sampler2D uTexture0;
  uniform sampler2D uTexture1;
  uniform vec2 uResolution;
  uniform float uProgress;
  uniform float uRadius;
  uniform float uInnerRadius;
  uniform float uStrength;
  uniform float uRotation;
  uniform float uArcPadding;
  uniform float uTime;
  uniform float uIdleMix;
  uniform float uEdgeSoftness;
  uniform vec2 uMouse;
  uniform float uMouseStrength;
  uniform float uParallaxStrength;
  uniform float uNoiseStrength;
  uniform float uNoiseScale;
  uniform float uNoiseSpeed;
  uniform float uNoiseEdge;
  uniform float uMaskSinStrength;
  uniform float uMaskSinSpeed;
  uniform float uMaskSinFrequency;
  uniform float uMaskSoftness;
  uniform float uVignetteStrength;
  uniform float uVignettePower;
  uniform vec3 uEdgeColor;
  uniform float uEdgeMix;

  vec2 coverUv(vec2 uv, vec2 resolution) {
    float aspect = resolution.x / max(resolution.y, 1.0);
    vec2 centered = uv - 0.5;
    centered.x *= aspect;
    centered = clamp(centered, vec2(-0.5 * aspect, -0.5), vec2(0.5 * aspect, 0.5));
    centered.x /= aspect;
    return centered + 0.5;
  }

  float diskMask(vec2 centered, float radius, float softness) {
    float dist = length(centered);
    return smoothstep(radius, radius - softness, dist);
  }

  float ringMask(vec2 centered, float outerRadius, float innerRadius, float softness) {
    float dist = length(centered);
    float outer = smoothstep(outerRadius, outerRadius - softness, dist);
    float inner = smoothstep(innerRadius, innerRadius + softness, dist);
    return outer * inner;
  }

  float arcMask(float angle, float progress, float padding, float softness, float jitter) {
    float clamped = clamp(progress, 0.0, 1.0);
    if (clamped >= 0.9995) {
      return 1.0;
    }
    float sweep = clamped * (6.28318530718 - padding);
    float startAngle = angle - 1.57079632679;
    float wrapped = mod(-startAngle + 6.28318530718, 6.28318530718);
    float edge = sweep - wrapped + jitter;
    return smoothstep(-softness, softness, edge);
  }

  vec3 mod289(vec3 x) {
    return x - floor(x * (1.0 / 289.0)) * 289.0;
  }

  vec2 mod289(vec2 x) {
    return x - floor(x * (1.0 / 289.0)) * 289.0;
  }

  vec3 permute(vec3 x) {
    return mod289(((x * 34.0) + 1.0) * x);
  }

  float snoise(vec2 v) {
    const vec4 C = vec4(
      0.211324865405187,
      0.366025403784439,
      -0.577350269189626,
      0.024390243902439
    );

    vec2 i = floor(v + dot(v, C.yy));
    vec2 x0 = v - i + dot(i, C.xx);

    vec2 i1 = (x0.x > x0.y) ? vec2(1.0, 0.0) : vec2(0.0, 1.0);
    vec4 x12 = x0.xyxy + C.xxzz;
    x12.xy -= i1;

    i = mod289(i);
    vec3 p = permute(permute(i.y + vec3(0.0, i1.y, 1.0)) + i.x + vec3(0.0, i1.x, 1.0));

    vec3 m = max(0.5 - vec3(dot(x0, x0), dot(x12.xy, x12.xy), dot(x12.zw, x12.zw)), 0.0);
    m *= m;
    m *= m;

    vec3 x = 2.0 * fract(p * C.www) - 1.0;
    vec3 h = abs(x) - 0.5;
    vec3 ox = floor(x + 0.5);
    vec3 a0 = x - ox;

    m *= 1.79284291400159 - 0.85373472095314 * (a0 * a0 + h * h);

    vec3 g;
    g.x = a0.x * x0.x + h.x * x0.y;
    g.yz = a0.yz * x12.xz + h.yz * x12.yw;
    return 130.0 * dot(m, g);
  }

  float vignette(vec2 uv) {
    vec2 d = uv - 0.5;
    float dist = length(d);
    float vig = smoothstep(0.25, 0.9, dist);
    return pow(vig, uVignettePower);
  }

  void main() {
    vec2 uv = coverUv(vUv, uResolution);
    vec2 parallax = (uMouse - vec2(0.5)) * uParallaxStrength / uResolution;
    vec2 uvOutside = clamp(uv + parallax, 0.0, 1.0);
    float aspect = uResolution.x / max(uResolution.y, 1.0);
    vec2 centered = uv - 0.5;
    centered.x *= aspect;

    float dist = length(centered);
    float ring = ringMask(centered, uRadius, uInnerRadius, uEdgeSoftness);

    float angle = atan(centered.y, centered.x);
    float edgeFactor = smoothstep(0.0, uRadius, dist);
    float idleStrength = mix(0.25, 1.0, uIdleMix);

    vec2 normal = dist > 0.0005 ? centered / dist : vec2(0.0, 0.0);
    vec2 tangent = vec2(-normal.y, normal.x);
    float swirl = dot(normal, vec2(0.78, 0.62));
    float swirl2 = dot(normal, vec2(-0.34, 0.94));
    float wave = sin((dist * 18.0) - (uTime * 1.4) + (uRotation * 0.8) + (swirl * 6.2831853));
    float ripple = sin((dist * 28.0) + (uTime * 1.1) - (uRotation * 1.1) + (swirl2 * 6.2831853));
    vec2 flow = normal * wave * 0.018 + tangent * ripple * 0.012;
    float noiseTime = uTime * uNoiseSpeed;
    float noiseA = snoise(centered * uNoiseScale + noiseTime);
    float noiseB = snoise(centered * (uNoiseScale * 1.7) - noiseTime * 1.3);
    float noise = (noiseA * 0.65 + noiseB * 0.35);
    float mouseDist = length(uMouse - vec2(0.5));
    float mouseBoost = 1.0 + mouseDist * uMouseStrength;
    vec2 noiseOffset = (normal * noise * 0.02 + tangent * noise * 0.014) * uNoiseStrength * mouseBoost;

    vec2 offset = flow * uStrength * idleStrength * (0.35 + edgeFactor * 0.65) * ring;
    offset += noiseOffset * idleStrength * ring;

    vec4 base0 = texture2D(uTexture0, uvOutside);
    vec4 base1 = texture2D(uTexture1, uvOutside);
    vec4 distorted0 = texture2D(uTexture0, clamp(uv + offset, 0.0, 1.0));
    vec4 distorted1 = texture2D(uTexture1, clamp(uv + offset, 0.0, 1.0));

    float outsideMix = smoothstep(0.0, 1.0, uProgress);
    vec4 outsideColor = mix(base0, base1, outsideMix);

    float sinJitter = sin(angle * uMaskSinFrequency + (uTime * uMaskSinSpeed)) * uMaskSinStrength;
    float noiseJitter = noise * uNoiseEdge;
    float jitter = (sinJitter + noiseJitter) * (0.4 + edgeFactor * 0.6);
    float wipe = arcMask(angle, uProgress, uArcPadding, uMaskSoftness, jitter);
    vec4 insideColor = mix(distorted0, distorted1, wipe);

    vec4 color = mix(outsideColor, insideColor, ring);

    float vig = vignette(uv);
    color.rgb *= mix(1.0, 1.0 - vig, uVignetteStrength);

    float outerEdge = smoothstep(uRadius - uEdgeSoftness * 3.0, uRadius, dist);
    float innerEdge = 1.0 - smoothstep(uInnerRadius, uInnerRadius + uEdgeSoftness * 3.0, dist);
    float edgeMask = max(outerEdge, innerEdge) * ring;
    color.rgb = mix(color.rgb, uEdgeColor, edgeMask * uEdgeMix);

    gl_FragColor = color;
  }
`;

function clampFloat(value, min, max, fallback) {
  const parsed = Number.parseFloat(value);
  if (!Number.isFinite(parsed)) {
    return fallback;
  }
  return Math.min(max, Math.max(min, parsed));
}

function parseColor(value, fallback = '#ffffff') {
  const color = new THREE.Color();
  try {
    color.set(value || fallback);
  } catch (error) {
    color.set(fallback);
  }
  return color;
}

function initPointerParallax(section) {
  const maxShift = clampFloat(section.dataset.diskParallaxShift, 0, 40, 0);
  if (!Number.isFinite(maxShift) || maxShift <= 0) {
    return;
  }

  let currentX = 0;
  let currentY = 0;
  let targetX = 0;
  let targetY = 0;
  let rafId = null;

  const update = () => {
    const ease = 0.08;
    currentX += (targetX - currentX) * ease;
    currentY += (targetY - currentY) * ease;

    section.style.setProperty('--disk-bg-x', `${currentX.toFixed(2)}px`);
    section.style.setProperty('--disk-bg-y', `${currentY.toFixed(2)}px`);

    if (Math.abs(targetX - currentX) < 0.1 && Math.abs(targetY - currentY) < 0.1) {
      currentX = targetX;
      currentY = targetY;
      section.style.setProperty('--disk-bg-x', `${currentX.toFixed(2)}px`);
      section.style.setProperty('--disk-bg-y', `${currentY.toFixed(2)}px`);
      rafId = null;
      return;
    }

    rafId = requestAnimationFrame(update);
  };

  const queueUpdate = () => {
    if (rafId === null) {
      rafId = requestAnimationFrame(update);
    }
  };

  const onMove = (event) => {
    if (event.pointerType && event.pointerType !== 'mouse') {
      return;
    }
    const rect = section.getBoundingClientRect();
    if (!rect.width || !rect.height) {
      return;
    }
    const relX = (event.clientX - rect.left) / rect.width;
    const relY = (event.clientY - rect.top) / rect.height;
    const offsetX = (relX - 0.5) * 2;
    const offsetY = (relY - 0.5) * 2;
    targetX = offsetX * maxShift;
    targetY = offsetY * maxShift;
    queueUpdate();
  };

  const onLeave = () => {
    targetX = 0;
    targetY = 0;
    queueUpdate();
  };

  section.addEventListener('pointermove', onMove);
  section.addEventListener('pointerleave', onLeave);
}

function initMouseControl(stage) {
  const mouse = {
    x: 0.5,
    y: 0.5,
    tx: 0.5,
    ty: 0.5,
  };

  if (!stage) {
    return mouse;
  }

  const onMove = (event) => {
    if (event.pointerType && event.pointerType !== 'mouse') {
      return;
    }
    const rect = stage.getBoundingClientRect();
    if (!rect.width || !rect.height) {
      return;
    }
    const relX = (event.clientX - rect.left) / rect.width;
    const relY = (event.clientY - rect.top) / rect.height;
    mouse.tx = Math.max(0, Math.min(1, relX));
    mouse.ty = Math.max(0, Math.min(1, relY));
  };

  const onLeave = () => {
    mouse.tx = 0.5;
    mouse.ty = 0.5;
  };

  stage.addEventListener('pointermove', onMove, { passive: true });
  stage.addEventListener('pointerleave', onLeave, { passive: true });

  return mouse;
}

function resolveInnerRadius(section, outerRadius) {
  const innerValue = Number.parseFloat(section.dataset.diskInner || '');
  const ratioValue = Number.parseFloat(section.dataset.diskInnerRatio || '');
  const ratio = Number.isFinite(ratioValue) ? ratioValue : 0.62;
  let innerRadius = Number.isFinite(innerValue) ? innerValue : outerRadius * ratio;
  const minThickness = 0.08;
  const maxInner = Math.max(0.12, outerRadius - minThickness);
  innerRadius = Math.min(maxInner, Math.max(0.18, innerRadius));
  return innerRadius;
}

function collectSlides(section) {
  return Array.from(section.querySelectorAll('[data-disk-slide]'))
    .map((slide) => {
      const src = slide.getAttribute('data-disk-slide-src') || '';
      const kicker = slide.getAttribute('data-disk-kicker') || '';
      const title = slide.getAttribute('data-disk-title') || '';
      return {
        element: slide,
        src,
        kicker,
        title,
      };
    })
    .filter((slide) => slide.src !== '');
}

function updateProgressUi(progressValue, ringEl, options = {}) {
  if (!ringEl) {
    return;
  }
  if (ringEl.hasAttribute('pathLength')) {
    ringEl.removeAttribute('pathLength');
  }
  const circumference =
    Number.parseFloat(ringEl.dataset.circumference || '') ||
    (Number.isFinite(Number.parseFloat(ringEl.getAttribute('r') || '0'))
      ? 2 * Math.PI * Number.parseFloat(ringEl.getAttribute('r') || '0')
      : 0);
  if (!Number.isFinite(circumference) || circumference <= 0) {
    return;
  }
  const clamped = Math.max(0, Math.min(1, progressValue));
  const isFull = clamped >= 0.9995;
  let dash = circumference * clamped;
  let gap = circumference - dash;
  if (isFull) {
    dash = circumference;
    gap = 0;
  } else {
    dash = Math.max(0.0001, dash);
    gap = Math.max(0.0001, gap);
  }
  let offset = 0;
  if (!isFull && options.lockEnd === true) {
    offset = dash;
  }
  ringEl.style.strokeDasharray = `${dash} ${gap}`;
  ringEl.style.strokeDashoffset = `${offset}`;
  if (options.lockEnd === true) {
    ringEl.style.transition = 'none';
  } else {
    ringEl.style.transition = '';
  }
  ringEl.style.opacity = clamped === 0 ? '0' : '1';
}

function getNextIndex(index, total) {
  return total > 0 ? (index + 1) % total : 0;
}

function easeInOutQuint(t) {
  if (t < 0.5) {
    return 16 * t * t * t * t * t;
  }
  return 1 - Math.pow(-2 * t + 2, 5) / 2;
}

function easeOutQuint(t) {
  return 1 - Math.pow(1 - t, 5);
}

function inverseEase(easeFn, p) {
  const clamped = Math.max(0, Math.min(1, p));
  let low = 0;
  let high = 1;
  for (let i = 0; i < 24; i += 1) {
    const mid = (low + high) / 2;
    if (easeFn(mid) < clamped) {
      low = mid;
    } else {
      high = mid;
    }
  }
  return (low + high) / 2;
}

function updateCenterContent(slides, baseIndex, progress, titleEl, kickerEl) {
  if (!titleEl && !kickerEl) {
    return;
  }
  const nextIndex = getNextIndex(baseIndex, slides.length);
  const activeIndex = progress >= 0.5 ? nextIndex : baseIndex;
  const activeSlide = slides[activeIndex];
  if (!activeSlide) {
    return;
  }
  if (titleEl) {
    titleEl.textContent = activeSlide.title || '';
  }
  if (kickerEl) {
    kickerEl.textContent = activeSlide.kicker || '';
    kickerEl.style.display = activeSlide.kicker ? '' : 'none';
  }
}

function markSlideStates(slides, baseIndex, progress) {
  const nextIndex = getNextIndex(baseIndex, slides.length);
  const activeIndex = progress >= 0.5 ? nextIndex : baseIndex;
  slides.forEach((slide, index) => {
    const isActive = index === activeIndex;
    slide.element.classList.toggle('is-active', isActive);
    slide.element.setAttribute('aria-hidden', String(!isActive));
  });
}

function loadTextures(renderer, slides) {
  const loader = new THREE.TextureLoader();
  const promises = slides.map(
    (slide) =>
      new Promise((resolve, reject) => {
        loader.load(
          slide.src,
          (texture) => {
            texture.colorSpace = THREE.SRGBColorSpace;
            texture.minFilter = THREE.LinearFilter;
            texture.magFilter = THREE.LinearFilter;
            texture.generateMipmaps = false;
            resolve(texture);
          },
          undefined,
          () => reject(new Error(`No se pudo cargar la textura: ${slide.src}`))
        );
      })
  );

  renderer.outputColorSpace = THREE.SRGBColorSpace;

  return Promise.all(promises);
}

export default function initSectionDiskSlider01() {
  gsap.registerPlugin(ScrollTrigger);

  const sections = Array.from(document.querySelectorAll('[data-disk-slider]'));
  if (sections.length === 0) {
    return;
  }
  ensureInputListeners();

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  sections.forEach((section) => {
    if (section.dataset.diskSliderInit === 'true') {
      return;
    }
    section.dataset.diskSliderInit = 'true';

    const slides = collectSlides(section);
    if (slides.length < 2) {
      section.classList.add('is-static');
      return;
    }

    if (prefersReducedMotion) {
      section.classList.add('is-reduced-motion');
      markSlideStates(slides, 0, 0);
      return;
    }

    const centerTitle = section.querySelector('[data-disk-center-title]');
    const centerKicker = section.querySelector('[data-disk-center-kicker]');
    const progressRing = section.querySelector('[data-disk-progress-ring]');
    const overlayDisk = section.querySelector('.sectionDiskSlider01-overlayDisk');
    const canvas = section.querySelector('[data-disk-canvas]');
    const stage = section.querySelector('.sectionDiskSlider01-stage');
    if (!canvas || !stage) {
      section.classList.add('is-static');
      return;
    }
    const mouseState = initMouseControl(stage);

    let renderer;
    try {
      renderer = new THREE.WebGLRenderer({
        canvas,
        antialias: true,
        alpha: true,
        powerPreference: 'high-performance',
      });
    } catch (error) {
      section.classList.add('is-static');
      return;
    }

    const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x05070d, 1);

    const scene = new THREE.Scene();
    const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);

    const geometry = new THREE.PlaneGeometry(2, 2, 1, 1);
    const initialRadius = clampFloat(section.dataset.diskRadius, 0.4, 0.64, 0.48);
    const initialInnerRadius = resolveInnerRadius(section, initialRadius);
    const uniforms = {
      uTexture0: { value: null },
      uTexture1: { value: null },
      uResolution: { value: new THREE.Vector2(1, 1) },
      uProgress: { value: 0 },
      uRadius: {
        value: initialRadius,
      },
      uInnerRadius: { value: initialInnerRadius },
      uStrength: {
        value: clampFloat(section.dataset.diskStrength, 0.2, 1.4, 0.68),
      },
      uRotation: { value: 0 },
      uArcPadding: { value: 0 },
      uTime: { value: 0 },
      uIdleMix: { value: 1 },
      uEdgeSoftness: {
        value: clampFloat(section.dataset.diskEdgeSoftness, 0.001, 0.02, 0.0035),
      },
      uMouse: { value: new THREE.Vector2(0.5, 0.5) },
      uMouseStrength: {
        value: clampFloat(section.dataset.diskMouseStrength, 0, 2, 0.5),
      },
      uParallaxStrength: {
        value: clampFloat(section.dataset.diskParallaxShift, 0, 40, 14),
      },
      uNoiseStrength: {
        value: clampFloat(section.dataset.diskNoiseStrength, 0, 2, 0.55),
      },
      uNoiseScale: {
        value: clampFloat(section.dataset.diskNoiseScale, 0.5, 8, 2.6),
      },
      uNoiseSpeed: {
        value: clampFloat(section.dataset.diskNoiseSpeed, 0, 3, 0.55),
      },
      uNoiseEdge: {
        value: clampFloat(section.dataset.diskNoiseEdge, 0, 1.5, 0.45),
      },
      uMaskSinStrength: {
        value: clampFloat(section.dataset.diskMaskSinStrength, 0, 1, 0.35),
      },
      uMaskSinSpeed: {
        value: clampFloat(section.dataset.diskMaskSinSpeed, 0, 4, 1),
      },
      uMaskSinFrequency: {
        value: clampFloat(section.dataset.diskMaskSinFrequency, 0.5, 6, 2.4),
      },
      uMaskSoftness: {
        value: clampFloat(section.dataset.diskMaskSoftness, 0.01, 0.4, 0.08),
      },
      uVignetteStrength: {
        value: clampFloat(section.dataset.diskVignetteStrength, 0, 1, 0.32),
      },
      uVignettePower: {
        value: clampFloat(section.dataset.diskVignettePower, 0.5, 3, 1.35),
      },
      uEdgeColor: {
        value: parseColor(section.dataset.diskEdgeColor, '#dce8ff'),
      },
      uEdgeMix: {
        value: clampFloat(section.dataset.diskEdgeMix, 0, 1, 0.28),
      },
    };

    const material = new THREE.ShaderMaterial({
      uniforms,
      vertexShader: VERTEX_SHADER,
      fragmentShader: FRAGMENT_SHADER,
    });

    const mesh = new THREE.Mesh(geometry, material);
    scene.add(mesh);

    let textures = [];
    let currentIndex = 0;
    let scrollRaw = 0;
    let lastRaw = 0;
    let wasIdle = false;
    let outerRadius = uniforms.uRadius.value;
    let innerRadius = uniforms.uInnerRadius.value;
    let autoIndex = 0;
    let autoPhase = 0;
    let autoProgress = 0;
    let autoHold = 0;
    let autoMode = 'forward';
    let lastBaseIndex = 0;
    let scrollOffset = 0;
    let rewindStartProgress = 0;
    let rewindDuration = 0;
    let lastScrollRaw = 0;
    let lastScrollTime = performance.now();

    const updateSize = () => {
      const rect = stage.getBoundingClientRect();
      const width = Math.max(1, rect.width);
      const height = Math.max(1, rect.height);
      renderer.setSize(width, height, false);
      uniforms.uResolution.value.set(width, height);
    };

    const syncDiskSize = () => {
      if (!overlayDisk) {
        return;
      }
      const rect = stage.getBoundingClientRect();
      const minSide = Math.max(1, Math.min(rect.width, rect.height));
      outerRadius = clampFloat(section.dataset.diskRadius, 0.4, 0.64, 0.48);
      innerRadius = resolveInnerRadius(section, outerRadius);
      uniforms.uRadius.value = outerRadius;
      uniforms.uInnerRadius.value = innerRadius;
      const edgeSoftness = clampFloat(
        section.dataset.diskEdgeSoftness,
        0.001,
        0.02,
        Math.max(0.001, 1.25 / minSide)
      );
      uniforms.uEdgeSoftness.value = edgeSoftness;
      const diameter = Math.round(minSide * outerRadius * 2);
      section.style.setProperty('--disk-size', `${diameter}px`);
    };

    const setSlidePair = (baseIndex) => {
      const total = textures.length;
      if (total === 0) {
        return;
      }
      const safeBase = ((baseIndex % total) + total) % total;
      const safeNext = getNextIndex(safeBase, total);
      currentIndex = safeBase;
      uniforms.uTexture0.value = textures[safeBase];
      uniforms.uTexture1.value = textures[safeNext];
    };

    const stepVh = clampFloat(section.dataset.diskStepVh, 80, 240, 100);
    const scrollPower = clampFloat(section.dataset.diskScrollPower, 0.6, 3, 1.6);

    const syncProgressRadius = () => {
      if (!progressRing || !overlayDisk) {
        return;
      }
      const rect = overlayDisk.getBoundingClientRect();
      const size = Math.max(1, Math.min(rect.width, rect.height));
      const ratio = outerRadius > 0 ? innerRadius / outerRadius : 0.62;
      const scaleValue = Number.parseFloat(
        getComputedStyle(section).getPropertyValue('--disk-bg-scale')
      );
      const scale = Number.isFinite(scaleValue) ? Math.max(0.9, Math.min(1.2, scaleValue)) : 1;
      const radiusPx = Math.max(2, size * 0.5 * ratio * scale);
      const viewRadius = (radiusPx / size) * 100;
      progressRing.setAttribute('r', viewRadius.toFixed(3));
      progressRing.dataset.circumference = (2 * Math.PI * radiusPx).toFixed(3);
    };

    let trigger = null;
    const setupScrollTrigger = () => {
      trigger?.kill();
      const totalSlides = textures.length;
      const segments = Math.max(1, totalSlides);
      const power = Math.max(0.1, scrollPower);
      const endDistance = window.innerHeight * (stepVh / 100) * segments * power;

      trigger = ScrollTrigger.create({
        trigger: section,
        start: 'top top',
        end: `+=${endDistance}`,
        pin: true,
        scrub: true,
        invalidateOnRefresh: true,
        onUpdate: (self) => {
          const maxRaw = segments - 0.0001;
          const progress = self.progress;
          scrollRaw = Math.min(maxRaw, Math.max(0, progress * segments));
          if (Math.abs(scrollRaw - lastScrollRaw) > 0.00005 || Math.abs(self.getVelocity()) > 4) {
            lastScrollTime = performance.now();
            lastScrollRaw = scrollRaw;
          }
        },
        onEnter: () => {
          lastRaw = scrollRaw;
          scrollOffset = 0;
          autoHold = 0;
          autoMode = 'manual';
          wasIdle = false;
        },
        onEnterBack: () => {
          lastRaw = scrollRaw;
          scrollOffset = 0;
          autoHold = 0;
          autoMode = 'manual';
          wasIdle = false;
        },
        onRefresh: () => {
          updateSize();
          syncDiskSize();
          syncProgressRadius();
        },
      });
    };

    const clock = new THREE.Clock();
    const autoTravel = 1.2;
    const autoRewind = 3;
    const autoHoldDelay = clampFloat(section.dataset.diskHoldDelay, 0, 20, 4);

    const renderLoop = () => {
      const delta = Math.min(0.05, clock.getDelta());
      uniforms.uTime.value += delta;
      const mouseEase = 0.08;
      mouseState.x += (mouseState.tx - mouseState.x) * mouseEase;
      mouseState.y += (mouseState.ty - mouseState.y) * mouseEase;
      uniforms.uMouse.value.set(mouseState.x, mouseState.y);

      const segments = Math.max(1, textures.length);
      const lastActivity = Math.max(inputState.last, lastScrollTime);
      const idleFor = (performance.now() - lastActivity) / 1000;
      const isIdle = idleFor > 0.08;

      if (isIdle) {
        if (!wasIdle) {
          autoIndex = Math.min(segments - 1, Math.max(0, Math.floor(lastRaw)));
          autoProgress = Math.max(0, Math.min(1, lastRaw - autoIndex));
          autoMode = autoProgress >= 0.5 ? 'forward' : 'rewind';
          autoHold = 0;
          if (autoProgress <= 0 && autoMode === 'rewind') {
            autoMode = 'hold-start';
            autoHold = autoHoldDelay;
          }
          if (autoMode === 'rewind') {
            rewindStartProgress = Math.max(0, Math.min(0.5, autoProgress));
            const rewindScale = rewindStartProgress > 0 ? rewindStartProgress / 0.5 : 0;
            rewindDuration = autoRewind * Math.max(0.2, rewindScale);
            autoPhase = 0;
          } else {
            autoPhase = inverseEase(easeInOutQuint, autoProgress);
          }
        }

        if (autoMode === 'hold-start' || autoMode === 'hold-end') {
          autoHold = Math.max(0, autoHold - delta);
          if (autoHold === 0) {
            if (autoMode === 'hold-end') {
              autoIndex = (autoIndex + 1) % segments;
            }
            autoPhase = 0;
            autoProgress = 0;
            autoMode = 'forward';
          }
        } else if (autoMode === 'forward') {
          autoPhase = Math.min(1, autoPhase + delta / autoTravel);
          autoProgress = easeInOutQuint(autoPhase);
          if (autoPhase >= 1) {
            autoProgress = 1;
            autoHold = autoHoldDelay;
            autoMode = 'hold-end';
          }
        } else if (autoMode === 'rewind') {
          const duration = rewindDuration > 0 ? rewindDuration : autoRewind;
          autoPhase = Math.min(1, autoPhase + delta / duration);
          autoProgress = rewindStartProgress * (1 - easeOutQuint(autoPhase));
          if (autoPhase >= 1) {
            autoProgress = 0;
            autoHold = autoHoldDelay;
            autoMode = 'hold-start';
          }
        }
      } else {
        const manualRaw = Math.max(0, Math.min(segments, scrollRaw + scrollOffset));
        autoIndex = Math.min(segments - 1, Math.floor(manualRaw));
        autoProgress = Math.max(0, Math.min(1, manualRaw - autoIndex));
        autoHold = 0;
        autoMode = 'manual';
        autoPhase = inverseEase(easeInOutQuint, autoProgress);
      }
      wasIdle = isIdle;

      if (isIdle) {
        scrollOffset = lastRaw - scrollRaw;
      }
      const rawTarget = isIdle ? autoIndex + autoProgress : scrollRaw + scrollOffset;
      const clampedTarget = Math.min(segments, Math.max(0, rawTarget));
      const rawDiff = clampedTarget - lastRaw;
      lastRaw = clampedTarget;

      const rawForSlides = Math.min(segments, Math.max(0, lastRaw));
      const rawForRing = rawForSlides;
      const baseIndex = Math.min(segments - 1, Math.floor(rawForSlides));
      const localProgress = rawForSlides - baseIndex;
      const baseChanged = baseIndex !== lastBaseIndex;

      if (baseIndex !== currentIndex || uniforms.uTexture0.value === null) {
        setSlidePair(baseIndex);
      }

      const progressTarget = localProgress;
      if (baseChanged || isIdle) {
        uniforms.uProgress.value = progressTarget;
      } else {
        const progressEase = Math.min(1, delta * 8.6);
        uniforms.uProgress.value += (progressTarget - uniforms.uProgress.value) * progressEase;
      }
      lastBaseIndex = baseIndex;

      uniforms.uIdleMix.value = isIdle ? 0.38 : 1;
      uniforms.uRotation.value += Math.abs(rawDiff) * Math.PI * 1.05 + delta * 0.35;

      let ringProgress = segments > 0 ? rawForRing / segments : 0;
      let ringOptions = {};
      if (isIdle && autoMode === 'hold-end' && autoIndex === segments - 1 && autoHoldDelay > 0) {
        ringProgress = Math.max(0, Math.min(1, autoHold / autoHoldDelay));
        ringOptions = { lockEnd: true };
      }
      updateProgressUi(ringProgress, progressRing, ringOptions);

      markSlideStates(slides, currentIndex, uniforms.uProgress.value);
      updateCenterContent(slides, currentIndex, uniforms.uProgress.value, centerTitle, centerKicker);
      renderer.render(scene, camera);
    };

    loadTextures(renderer, slides)
      .then((loadedTextures) => {
        textures = loadedTextures;
        textures.forEach((texture) => {
          renderer.initTexture?.(texture);
        });
        section.classList.add('is-ready');
        updateSize();
        syncDiskSize();
        syncProgressRadius();
        setSlidePair(0);
        markSlideStates(slides, 0, 0);
        updateCenterContent(slides, 0, 0, centerTitle, centerKicker);
        setupScrollTrigger();
        renderer.setAnimationLoop(renderLoop);
      })
      .catch(() => {
        renderer.dispose();
        section.classList.add('is-static');
      });

    const handleResize = () => {
      updateSize();
      syncDiskSize();
      syncProgressRadius();
      trigger?.refresh();
    };

    window.addEventListener('resize', handleResize);
  });
}
