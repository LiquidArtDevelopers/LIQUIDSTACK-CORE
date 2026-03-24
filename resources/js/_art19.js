import gsap from 'gsap';
import * as THREE from 'three';

const ART19_DEFAULTS = Object.freeze({
  distortion: 0.2,
  chroma: 0.8,
  damping: 0.992,
  dropRadius: 0.09,
  dropForce: 1.35,
  duration: 4.2,
  simResolution: 256,
  maxPixelRatio: 1.45,
  targetFps: 120,
  maxSteps: 3,
  edgeWidth: 0.074,
  softness: 0.055,
  ringGain: 2.5,
});

const activeInstances = [];

export default function initArt19() {
  const roots = Array.from(document.querySelectorAll('[data-art19]'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    if (root.dataset.art19Init === 'true') {
      return;
    }
    root.dataset.art19Init = 'true';

    const canvas = root.querySelector('[data-art19-canvas]');
    if (!canvas) {
      return;
    }

    const instance = createArt19Instance(root, canvas);
    if (instance) {
      activeInstances.push(instance);
    }
  });
}

if (import.meta.hot) {
  import.meta.hot.dispose(() => {
    activeInstances.forEach((instance) => instance.destroy());
    activeInstances.length = 0;
  });
}

function createArt19Instance(root, canvas) {
  const stage = root.querySelector('[data-art19-stage]');
  const hitArea = root.querySelector('[data-art19-hit]');
  const currentLayer = root.querySelector('[data-art19-layer-current]');
  const nextLayer = root.querySelector('[data-art19-layer-next]');

  if (!stage || !hitArea || !currentLayer || !nextLayer) {
    return null;
  }

  const slides = collectSlides(root);
  if (slides.length === 0) {
    root.classList.add('art19--fallback');
    return null;
  }

  let currentIndex = 0;
  hydrateCaptionLayer(currentLayer, slides[currentIndex]);
  gsap.set(currentLayer, { autoAlpha: 1, yPercent: 0 });
  gsap.set(nextLayer, { autoAlpha: 0, yPercent: 10 });

  if (slides.length < 2) {
    root.classList.add('art19--fallback');
    stage.style.backgroundImage = `url("${slides[0].src}")`;
    return null;
  }

  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: false,
      alpha: false,
      powerPreference: 'high-performance',
    });
  } catch (err) {
    root.classList.add('art19--fallback');
    stage.style.backgroundImage = `url("${slides[0].src}")`;
    return null;
  }

  const settings = {
    distortion: clamp(readNumber(root.dataset.art19Distortion, ART19_DEFAULTS.distortion), 0.05, 0.5),
    chroma: clamp(readNumber(root.dataset.art19Chroma, ART19_DEFAULTS.chroma), 0, 2),
    damping: clamp(readNumber(root.dataset.art19Damping, ART19_DEFAULTS.damping), 0.92, 0.999),
    dropRadius: clamp(readNumber(root.dataset.art19Radius, ART19_DEFAULTS.dropRadius), 0.02, 0.2),
    dropForce: clamp(readNumber(root.dataset.art19Force, ART19_DEFAULTS.dropForce), 0.2, 3),
    duration: clamp(readNumber(root.dataset.art19Duration, ART19_DEFAULTS.duration), 0.45, 8),
    simResolution: clamp(Math.round(readNumber(root.dataset.art19Sim, ART19_DEFAULTS.simResolution)), 96, 512),
  };

  const scene = new THREE.Scene();
  const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);

  const simSize = settings.simResolution;
  const simLength = simSize * simSize;
  let prevWave = new Float32Array(simLength);
  let currWave = new Float32Array(simLength);
  let nextWave = new Float32Array(simLength);
  let viewportAspect = 1;

  const displacementData = new Uint8Array(simLength * 4);
  for (let i = 0; i < simLength; i += 1) {
    const o = i * 4;
    displacementData[o] = 128;
    displacementData[o + 1] = 128;
    displacementData[o + 2] = 128;
    displacementData[o + 3] = 128;
  }

  const displacementTexture = new THREE.DataTexture(
    displacementData,
    simSize,
    simSize,
    THREE.RGBAFormat,
    THREE.UnsignedByteType
  );
  displacementTexture.minFilter = THREE.LinearFilter;
  displacementTexture.magFilter = THREE.LinearFilter;
  displacementTexture.wrapS = THREE.ClampToEdgeWrapping;
  displacementTexture.wrapT = THREE.ClampToEdgeWrapping;
  displacementTexture.needsUpdate = true;

  const uniforms = {
    uCurrent: { value: null },
    uNext: { value: null },
    uDisplacement: { value: displacementTexture },
    uOrigin: { value: new THREE.Vector2(0.5, 0.5) },
    uProgress: { value: 0 },
    uDistortion: { value: settings.distortion },
    uChroma: { value: settings.chroma },
    uViewportAspect: { value: 1 },
    uCurrentAspect: { value: 1 },
    uNextAspect: { value: 1 },
    uEdgeWidth: { value: ART19_DEFAULTS.edgeWidth },
    uSoftness: { value: ART19_DEFAULTS.softness },
    uRingGain: { value: ART19_DEFAULTS.ringGain },
  };

  const material = new THREE.ShaderMaterial({
    uniforms,
    vertexShader: `
      varying vec2 vUv;

      void main() {
        vUv = uv;
        gl_Position = vec4(position.xy, 0.0, 1.0);
      }
    `,
    fragmentShader: `
      precision highp float;

      varying vec2 vUv;

      uniform sampler2D uCurrent;
      uniform sampler2D uNext;
      uniform sampler2D uDisplacement;
      uniform vec2 uOrigin;
      uniform float uProgress;
      uniform float uDistortion;
      uniform float uChroma;
      uniform float uViewportAspect;
      uniform float uCurrentAspect;
      uniform float uNextAspect;
      uniform float uEdgeWidth;
      uniform float uSoftness;
      uniform float uRingGain;

      vec2 coverUv(vec2 uv, float texAspect) {
        vec2 ratio = vec2(
          min(uViewportAspect / texAspect, 1.0),
          min(texAspect / uViewportAspect, 1.0)
        );
        return uv * ratio + (1.0 - ratio) * 0.5;
      }

      vec3 sampleCover(sampler2D tex, float aspect, vec2 uv) {
        vec2 cUv = clamp(coverUv(uv, aspect), vec2(0.001), vec2(0.999));
        return texture2D(tex, cUv).rgb;
      }

      void main() {
        vec2 axis = uViewportAspect >= 1.0
          ? vec2(uViewportAspect, 1.0)
          : vec2(1.0, 1.0 / max(0.0001, uViewportAspect));

        vec2 waveUv = vUv;
        vec4 disp = texture2D(uDisplacement, clamp(waveUv, vec2(0.001), vec2(0.999)));
        vec2 normal = disp.ba * 2.0 - 1.0;
        float waveHeight = disp.r * 2.0 - 1.0;

        vec2 corner = max(uOrigin, vec2(1.0) - uOrigin) * axis;
        float maxRadius = length(corner) + uEdgeWidth * 12.0;
        float spacing = maxRadius * 0.145;
        float travel = uProgress * (maxRadius + spacing * 6.2);
        float radius1 = travel;
        float radius2 = travel - spacing;
        float radius3 = travel - spacing * 2.0;
        float radius4 = travel - spacing * 3.0;
        float radius5 = travel - spacing * 4.0;
        float radius6 = travel - spacing * 5.0;
        float radius7 = travel - spacing * 6.0;
        float radius8 = travel - spacing * 7.0;
        float dist = length((vUv - uOrigin) * axis);
        float distWarped = dist + waveHeight * (uEdgeWidth * 3.2);
        float ringWidth = max(0.0001, uEdgeWidth * (1.02 + abs(waveHeight) * 0.52));

        float ring1 = exp(-pow((distWarped - radius1) / ringWidth, 2.0)) * step(0.0, radius1);
        float ring2 = exp(-pow((distWarped - radius2) / (ringWidth * 1.03), 2.0)) * step(0.0, radius2);
        float ring3 = exp(-pow((distWarped - radius3) / (ringWidth * 1.08), 2.0)) * step(0.0, radius3);
        float ring4 = exp(-pow((distWarped - radius4) / (ringWidth * 1.13), 2.0)) * step(0.0, radius4);
        float ring5 = exp(-pow((distWarped - radius5) / (ringWidth * 1.18), 2.0)) * step(0.0, radius5);
        float ring6 = exp(-pow((distWarped - radius6) / (ringWidth * 1.24), 2.0)) * step(0.0, radius6);
        float ring7 = exp(-pow((distWarped - radius7) / (ringWidth * 1.30), 2.0)) * step(0.0, radius7);
        float ring8 = exp(-pow((distWarped - radius8) / (ringWidth * 1.38), 2.0)) * step(0.0, radius8);

        float packet = ring1 + ring2 * 0.95 + ring3 * 0.8 + ring4 * 0.68 + ring5 * 0.62 + ring6 * 0.56 + ring7 * 0.5 + ring8 * 0.44;

        float wakeCoord = max(0.0, radius2 - distWarped);
        float wakeDecay = exp(-wakeCoord / max(0.0001, uEdgeWidth * 12.8));
        float wakeWaves = 0.5 + 0.5 * sin(wakeCoord / max(0.0001, uEdgeWidth * 1.35) - uProgress * 19.0);
        float wake = wakeDecay * wakeWaves * step(0.0, radius2);

        float revealFront = radius2 + waveHeight * (uSoftness * 2.3) + (ring1 - ring3) * (uSoftness * 0.65);
        float reveal = step(0.0, radius2) * (1.0 - smoothstep(revealFront - (uSoftness * 2.45), revealFront + (uSoftness * 1.95), distWarped));
        float transitionBand = exp(-pow((distWarped - revealFront) / max(0.0001, uSoftness * 3.6), 2.0));
        reveal = clamp(reveal + wake * 0.09 + transitionBand * 0.12, 0.0, 1.0);

        float preEnergy = ring1 * 1.04 + ring2 * 0.68;
        float transitionEnergy = ring2 * 1.34 + wake * 0.95;
        float postEnergy = ring3 * 1.05 + ring4 * 0.95 + ring5 * 0.9 + ring6 * 0.82 + ring7 * 0.74 + ring8 * 0.66 + wake * 0.62;
        float ringEnergy = preEnergy + transitionEnergy + postEnergy;
        float waveEnergy = clamp(abs(waveHeight) * 2.35 + ringEnergy * 1.08, 0.0, 4.4);
        vec2 radial = dist > 0.0001 ? normalize((vUv - uOrigin) / axis) : vec2(0.0);
        vec2 waveFlow = (normal / axis) * (uDistortion * (0.17 + waveEnergy * uRingGain));
        waveFlow += (normal / axis) * waveHeight * (uDistortion * 0.42) * (0.95 + ringEnergy);
        waveFlow += (radial / axis) * waveHeight * (uDistortion * 0.2) * (0.6 + ring2 + ring3 + ring4 + ring5 + ring6 + ring7);
        float flowLen = length(waveFlow);
        if (flowLen > 0.13) {
          waveFlow *= 0.13 / flowLen;
        }

        vec2 fineWobble = (normal.yx * vec2(-1.0, 1.0) / axis) * (sin((distWarped - travel) * 66.0) * (ring2 + ring3 + ring4 + ring5 + ring6 + ring7) * uDistortion * 0.026);

        vec2 uvCurrent = vUv + waveFlow * (1.0 + preEnergy * 0.44 + transitionEnergy * 0.32) + fineWobble;
        vec2 uvNext = vUv + waveFlow * (0.87 + transitionEnergy * 0.28 + postEnergy * 0.45) - fineWobble * 0.7;

        vec2 direction = length(waveFlow) > 0.0001 ? normalize(waveFlow) : vec2(1.0, 0.0);
        float chromaOffset = uChroma * (ring2 * 0.0031 + ring3 * 0.00235 + ring4 * 0.00195 + ring5 * 0.0016 + ring6 * 0.00125 + ring7 * 0.00095 + abs(waveHeight) * 0.00125 + wake * 0.0008);

        vec3 currentChrom;
        currentChrom.r = sampleCover(uCurrent, uCurrentAspect, uvCurrent + direction * chromaOffset).r;
        currentChrom.g = sampleCover(uCurrent, uCurrentAspect, uvCurrent).g;
        currentChrom.b = sampleCover(uCurrent, uCurrentAspect, uvCurrent - direction * chromaOffset).b;

        vec3 nextChrom;
        nextChrom.r = sampleCover(uNext, uNextAspect, uvNext + direction * chromaOffset).r;
        nextChrom.g = sampleCover(uNext, uNextAspect, uvNext).g;
        nextChrom.b = sampleCover(uNext, uNextAspect, uvNext - direction * chromaOffset).b;

        vec3 color = mix(currentChrom, nextChrom, reveal);
        float tintMix = clamp((ring2 * 0.62 + ring3 * 0.5 + ring4 * 0.38 + ring5 * 0.3 + ring6 * 0.24 + ring7 * 0.18 + wake * 0.24) * uChroma, 0.0, 0.44);
        color = mix(color, color * vec3(1.0, 0.98, 0.9), tintMix);

        gl_FragColor = vec4(color, 1.0);
      }
    `,
    transparent: false,
    depthWrite: false,
    depthTest: false,
  });

  const quad = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), material);
  scene.add(quad);

  const loader = new THREE.TextureLoader();
  const slideTextures = [];
  const sizeVec = new THREE.Vector2();
  const transitionRenderTargets = [];
  let transitionRenderTargetIndex = 0;
  let ready = false;
  let destroyed = false;
  let rafId = 0;
  let captionTween = null;
  let lastFrameTime = performance.now();

  const transition = {
    active: false,
    startedAt: 0,
    from: 0,
    to: 0,
    progress: 0,
    originX: 0.5,
    originY: 0.5,
    pulseTimes: [],
    pulseStrengths: [],
    pulseIndex: 0,
    queuedOrigin: null,
  };

  const resizeObserver = new ResizeObserver(() => {
    syncSize();
  });
  resizeObserver.observe(stage);

  const cleanupController = new AbortController();
  const { signal } = cleanupController;

  hitArea.addEventListener(
    'pointerdown',
    (event) => {
      if (!ready) {
        return;
      }
      const rect = stage.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        return;
      }
      const nx = clamp((event.clientX - rect.left) / rect.width, 0, 1);
      const ny = clamp((event.clientY - rect.top) / rect.height, 0, 1);
      requestTransition(nx, 1 - ny);
    },
    { signal, passive: true }
  );

  syncSize();

  Promise.all(slides.map((slide) => loadTexture(loader, slide.src)))
    .then((textures) => {
      if (destroyed) {
        textures.forEach((texture) => texture.dispose());
        return;
      }

      textures.forEach((texture, index) => {
        texture.colorSpace = THREE.SRGBColorSpace;
        texture.minFilter = THREE.LinearMipmapLinearFilter;
        texture.magFilter = THREE.LinearFilter;
        texture.generateMipmaps = true;

        const texWidth = Math.max(1, texture.image?.naturalWidth || texture.image?.width || 1);
        const texHeight = Math.max(1, texture.image?.naturalHeight || texture.image?.height || 1);

        slideTextures[index] = {
          texture,
          aspect: texWidth / texHeight,
        };
      });

      applyActiveTextures(currentIndex, currentIndex);
      ready = true;
      renderLoop();
    })
    .catch(() => {
      root.classList.add('art19--fallback');
      stage.style.backgroundImage = `url("${slides[0].src}")`;
    });

  return {
    destroy() {
      destroyed = true;
      cancelAnimationFrame(rafId);
      cleanupController.abort();
      resizeObserver.disconnect();
      if (captionTween) {
        captionTween.kill();
      }

      slideTextures.forEach((entry) => entry?.texture?.dispose());
      transitionRenderTargets.forEach((target) => target?.dispose());
      displacementTexture.dispose();
      quad.geometry.dispose();
      material.dispose();
      renderer.dispose();
    },
  };

  function requestTransition(nx, ny) {
    if (transition.active) {
      const targetIndex = (transition.to + 1) % slides.length;
      const frameEntry = captureTransitionFrame();
      interruptTransitionState();
      startTransition(nx, ny, { toIndex: targetIndex, fromEntry: frameEntry });
      return;
    }

    startTransition(nx, ny);
  }

  function interruptTransitionState() {
    currentIndex = transition.to;
    transition.active = false;
    transition.progress = 0;
    transition.pulseIndex = 0;
    transition.queuedOrigin = null;
    uniforms.uProgress.value = 0;
    applyActiveTextures(currentIndex, currentIndex);

    if (captionTween) {
      captionTween.kill();
      captionTween = null;
    }
    hydrateCaptionLayer(currentLayer, slides[currentIndex]);
    gsap.set(currentLayer, { autoAlpha: 1, yPercent: 0 });
    gsap.set(nextLayer, { autoAlpha: 0, yPercent: 10 });
  }

  function startTransition(nx, ny, options = {}) {
    const targetIndex = Number.isInteger(options.toIndex)
      ? options.toIndex
      : (currentIndex + 1) % slides.length;
    const fromEntry = options.fromEntry ?? null;

    transition.active = true;
    transition.startedAt = performance.now();
    transition.progress = 0;
    transition.from = currentIndex;
    transition.to = targetIndex;
    transition.originX = nx;
    transition.originY = ny;
    const pulseFractions = [0, 0.22, 0.44, 0.66, 0.88, 1.08, 1.26, 1.46, 1.64];
    const microFractions = [0.12, 0.34, 0.56, 0.78, 1.0, 1.18, 1.34, 1.54, 1.72];
    transition.pulseTimes = pulseFractions.map((f) => settings.duration * f);
    transition.pulseStrengths = [1.25, 1.04, 0.88, 0.74, 0.62, 0.54, 0.46, 0.38, 0.31];
    microFractions.forEach((f, idx) => {
      transition.pulseTimes.push(settings.duration * f);
      transition.pulseStrengths.push(Math.max(0.1, 0.42 - idx * 0.045));
    });
    const zipped = transition.pulseTimes
      .map((time, idx) => ({ time, strength: transition.pulseStrengths[idx] }))
      .sort((a, b) => a.time - b.time);
    transition.pulseTimes = zipped.map((p) => p.time);
    transition.pulseStrengths = zipped.map((p) => p.strength);
    transition.pulseIndex = 0;
    uniforms.uOrigin.value.set(nx, ny);

    if (fromEntry) {
      const to = slideTextures[transition.to];
      uniforms.uCurrent.value = fromEntry.texture;
      uniforms.uCurrentAspect.value = fromEntry.aspect;
      uniforms.uNext.value = to.texture;
      uniforms.uNextAspect.value = to.aspect;
    } else {
      applyActiveTextures(transition.from, transition.to);
    }
    animateCaption(slides[transition.to]);
  }

  function finishTransition() {
    currentIndex = transition.to;
    transition.active = false;
    transition.progress = 0;
    uniforms.uProgress.value = 0;
    applyActiveTextures(currentIndex, currentIndex);

    hydrateCaptionLayer(currentLayer, slides[currentIndex]);
    gsap.set(currentLayer, { autoAlpha: 1, yPercent: 0 });
    gsap.set(nextLayer, { autoAlpha: 0, yPercent: 10 });

    if (transition.queuedOrigin) {
      const queued = transition.queuedOrigin;
      transition.queuedOrigin = null;
      startTransition(queued.x, queued.y);
    }
  }

  function animateCaption(nextSlide) {
    hydrateCaptionLayer(nextLayer, nextSlide);
    if (captionTween) {
      captionTween.kill();
    }

    captionTween = gsap.timeline();
    captionTween.to(currentLayer, {
      autoAlpha: 0,
      yPercent: -14,
      duration: 0.34,
      ease: 'power2.out',
    }, 0);
    captionTween.fromTo(nextLayer, {
      autoAlpha: 0,
      yPercent: 10,
    }, {
      autoAlpha: 1,
      yPercent: 0,
      duration: 0.46,
      ease: 'power2.out',
    }, 0.06);
  }

  function applyActiveTextures(fromIndex, toIndex) {
    const from = slideTextures[fromIndex];
    const to = slideTextures[toIndex];
    if (!from || !to) {
      return;
    }

    uniforms.uCurrent.value = from.texture;
    uniforms.uNext.value = to.texture;
    uniforms.uCurrentAspect.value = from.aspect;
    uniforms.uNextAspect.value = to.aspect;
  }

  function ensureTransitionRenderTarget() {
    renderer.getDrawingBufferSize(sizeVec);
    const width = Math.max(1, Math.floor(sizeVec.x));
    const height = Math.max(1, Math.floor(sizeVec.y));

    if (transitionRenderTargets.length === 0) {
      for (let i = 0; i < 2; i += 1) {
        const target = new THREE.WebGLRenderTarget(width, height, {
          minFilter: THREE.LinearFilter,
          magFilter: THREE.LinearFilter,
          depthBuffer: false,
          stencilBuffer: false,
        });
        target.texture.colorSpace = THREE.SRGBColorSpace;
        transitionRenderTargets.push(target);
      }
      return;
    }

    transitionRenderTargets.forEach((target) => {
      if (target.width !== width || target.height !== height) {
        target.setSize(width, height);
      }
    });
  }

  function getCaptureTarget() {
    if (transitionRenderTargets.length === 0) {
      return null;
    }

    const currentTex = uniforms.uCurrent.value;
    const nextTex = uniforms.uNext.value;
    const total = transitionRenderTargets.length;

    for (let i = 0; i < total; i += 1) {
      const idx = (transitionRenderTargetIndex + i) % total;
      const candidate = transitionRenderTargets[idx];
      if (candidate.texture !== currentTex && candidate.texture !== nextTex) {
        transitionRenderTargetIndex = (idx + 1) % total;
        return candidate;
      }
    }

    const fallback = transitionRenderTargets[transitionRenderTargetIndex];
    transitionRenderTargetIndex = (transitionRenderTargetIndex + 1) % total;
    return fallback;
  }

  function captureTransitionFrame() {
    ensureTransitionRenderTarget();
    const target = getCaptureTarget();
    if (!target) {
      return null;
    }

    const previousTarget = renderer.getRenderTarget();
    renderer.setRenderTarget(target);
    renderer.render(scene, camera);
    renderer.setRenderTarget(previousTarget);

    return {
      texture: target.texture,
      aspect: viewportAspect,
    };
  }

  function syncSize() {
    const width = Math.max(1, stage.clientWidth);
    const height = Math.max(1, stage.clientHeight);
    const pixelRatio = Math.min(window.devicePixelRatio || 1, ART19_DEFAULTS.maxPixelRatio);
    viewportAspect = width / height;

    renderer.setPixelRatio(pixelRatio);
    renderer.setSize(width, height, false);
    ensureTransitionRenderTarget();
    uniforms.uViewportAspect.value = width / height;
  }

  function renderLoop() {
    if (destroyed || !ready) {
      return;
    }

    const now = performance.now();
    const delta = Math.min((now - lastFrameTime) / 1000, 0.05);
    lastFrameTime = now;

    const targetFrame = 1 / ART19_DEFAULTS.targetFps;
    const simSteps = clamp(Math.round(delta / targetFrame), 1, ART19_DEFAULTS.maxSteps);

    if (transition.active) {
      const elapsedSec = (now - transition.startedAt) / 1000;
      const elapsed = elapsedSec / settings.duration;

      while (
        transition.pulseIndex < transition.pulseTimes.length &&
        elapsedSec >= transition.pulseTimes[transition.pulseIndex]
      ) {
        const strength = settings.dropForce * 0.28 * transition.pulseStrengths[transition.pulseIndex];
        addSplash(transition.originX, transition.originY, strength);
        transition.pulseIndex += 1;
      }

      transition.progress = elapsed;
      uniforms.uProgress.value = transition.progress;

      if (elapsed >= 2.05) {
        finishTransition();
      }
    }

    advanceWaves(simSteps);
    writeDisplacementTexture();

    renderer.render(scene, camera);
    rafId = requestAnimationFrame(renderLoop);
  }

  function advanceWaves(iterations) {
    const safeAspect = Math.max(0.0001, viewportAspect);
    const weightX = 1 / (safeAspect * safeAspect);
    const weightY = 1;
    const weightSum = weightX + weightY;

    for (let pass = 0; pass < iterations; pass += 1) {
      for (let y = 1; y < simSize - 1; y += 1) {
        const rowOffset = y * simSize;
        for (let x = 1; x < simSize - 1; x += 1) {
          const idx = rowOffset + x;
          const waveNeighbors =
            ((currWave[idx - 1] + currWave[idx + 1]) * weightX + (currWave[idx - simSize] + currWave[idx + simSize]) * weightY) /
            weightSum;
          const wave = (waveNeighbors - prevWave[idx]) * settings.damping;
          nextWave[idx] = wave;
        }
      }

      for (let x = 0; x < simSize; x += 1) {
        const topIdx = x;
        const nextTopIdx = simSize + x;
        nextWave[topIdx] = nextWave[nextTopIdx] * 0.993;

        const bottomIdx = (simSize - 1) * simSize + x;
        const prevBottomIdx = (simSize - 2) * simSize + x;
        nextWave[bottomIdx] = nextWave[prevBottomIdx] * 0.993;
      }
      for (let y = 0; y < simSize; y += 1) {
        const leftIdx = y * simSize;
        nextWave[leftIdx] = nextWave[leftIdx + 1] * 0.993;

        const rightIdx = y * simSize + (simSize - 1);
        nextWave[rightIdx] = nextWave[rightIdx - 1] * 0.993;
      }

      const swap = prevWave;
      prevWave = currWave;
      currWave = nextWave;
      nextWave = swap;
    }
  }

  function addSplash(nx, ny, strength) {
    addDrop(nx, ny, strength * 2.1);

    const inner = settings.dropRadius * 0.72;
    const outer = settings.dropRadius * 1.18;
    const ringSteps = 10;
    const safeAspect = Math.max(0.0001, viewportAspect);
    const xScale = 1 / safeAspect;

    for (let i = 0; i < ringSteps; i += 1) {
      const angle = (i / ringSteps) * Math.PI * 2;
      const cosA = Math.cos(angle);
      const sinA = Math.sin(angle);

      addDrop(clamp(nx + cosA * inner * xScale, 0, 1), clamp(ny + sinA * inner, 0, 1), -strength * 0.66);
      addDrop(clamp(nx + cosA * outer * xScale, 0, 1), clamp(ny + sinA * outer, 0, 1), strength * 0.46);
    }
  }

  function addDrop(nx, ny, strength) {
    const centerX = Math.floor(nx * (simSize - 1));
    const centerY = Math.floor(ny * (simSize - 1));
    const radius = Math.max(2, Math.floor(simSize * settings.dropRadius));
    const radiusSq = radius * radius;
    const safeAspect = Math.max(0.0001, viewportAspect);

    for (let y = -radius; y <= radius; y += 1) {
      const py = centerY + y;
      if (py <= 1 || py >= simSize - 1) {
        continue;
      }

      for (let x = -radius; x <= radius; x += 1) {
        const px = centerX + x;
        if (px <= 1 || px >= simSize - 1) {
          continue;
        }

        const distSq = (x * safeAspect) * (x * safeAspect) + y * y;
        if (distSq > radiusSq) {
          continue;
        }

        const dist = Math.sqrt(distSq) / radius;
        const falloff = Math.cos(dist * Math.PI * 0.5);
        const idx = py * simSize + px;
        currWave[idx] = clamp(currWave[idx] + falloff * strength * 0.2, -1.2, 1.2);
      }
    }
  }

  function writeDisplacementTexture() {
    const safeAspect = Math.max(0.0001, viewportAspect);

    for (let i = 0; i < simLength; i += 1) {
      const value = clamp(currWave[i], -1, 1);
      const x = i % simSize;
      const y = (i / simSize) | 0;
      const idxL = y * simSize + Math.max(0, x - 1);
      const idxR = y * simSize + Math.min(simSize - 1, x + 1);
      const idxB = Math.max(0, y - 1) * simSize + x;
      const idxT = Math.min(simSize - 1, y + 1) * simSize + x;

      const nx = clamp(((currWave[idxL] - currWave[idxR]) * 1.2) / safeAspect, -1, 1);
      const ny = clamp((currWave[idxB] - currWave[idxT]) * 1.2, -1, 1);

      const offset = i * 4;
      displacementData[offset] = Math.round((value * 0.5 + 0.5) * 255);
      displacementData[offset + 1] = 128;
      displacementData[offset + 2] = Math.round((nx * 0.5 + 0.5) * 255);
      displacementData[offset + 3] = Math.round((ny * 0.5 + 0.5) * 255);
    }

    displacementTexture.needsUpdate = true;
  }
}

function collectSlides(root) {
  const items = Array.from(root.querySelectorAll('[data-art19-slide]'));
  return items
    .map((item, index) => {
      const image = item.querySelector('.art19-dataImage');
      if (!image || !image.getAttribute('src')) {
        return null;
      }

      const title = item.querySelector('.art19-dataTitle')?.textContent?.trim() || '';
      const subtitle = item.querySelector('.art19-dataSub')?.textContent?.trim() || '';
      const label = item.querySelector('.art19-dataIndex')?.textContent?.trim() || String(index + 1).padStart(2, '0');

      return {
        src: image.currentSrc || image.src,
        title,
        subtitle,
        label,
      };
    })
    .filter(Boolean);
}

function hydrateCaptionLayer(layer, slide) {
  const label = layer.querySelector('[data-art19-caption-index]');
  const title = layer.querySelector('[data-art19-caption-title]');
  const subtitle = layer.querySelector('[data-art19-caption-sub]');

  if (label) {
    label.textContent = slide.label;
  }
  if (title) {
    title.textContent = slide.title;
  }
  if (subtitle) {
    subtitle.textContent = slide.subtitle;
  }
}

function loadTexture(loader, src) {
  return new Promise((resolve, reject) => {
    loader.load(src, resolve, undefined, reject);
  });
}

function readNumber(value, fallback) {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}
