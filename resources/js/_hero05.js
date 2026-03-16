import gsap from 'gsap';
import * as THREE from 'three';

const HERO05_DEFAULTS = Object.freeze({
  distortion: 0.18,
  chroma: 1.2,
  damping: 0.985,
  dropRadius: 0.08,
  dropForce: 1.35,
  simResolution: 256,
  maxPixelRatio: 1.35,
  targetFps: 120,
  maxSimSteps: 3,
  maxUvShift: 0.038,
});

const activeInstances = [];

export default function initHero05() {
  const roots = Array.from(document.querySelectorAll('[data-hero05]'));
  if (roots.length === 0) {
    return;
  }

  roots.forEach((root) => {
    if (root.dataset.hero05Init === 'true') {
      return;
    }
    root.dataset.hero05Init = 'true';

    const canvas = root.querySelector('[data-hero05-canvas]');
    if (!canvas) {
      return;
    }

    const instance = createHero05Instance(root, canvas);
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

function createHero05Instance(root, canvas) {
  let renderer;
  try {
    renderer = new THREE.WebGLRenderer({
      canvas,
      antialias: false,
      alpha: true,
      powerPreference: 'high-performance',
    });
  } catch (err) {
    root.classList.add('hero05--fallback');
    return null;
  }

  const settings = {
    distortion: clamp(readNumber(root.dataset.hero05Distortion, HERO05_DEFAULTS.distortion), 0.02, 0.35),
    chroma: clamp(readNumber(root.dataset.hero05Chroma, HERO05_DEFAULTS.chroma), 0.0, 3.0),
    damping: clamp(readNumber(root.dataset.hero05Damping, HERO05_DEFAULTS.damping), 0.9, 0.9995),
    dropRadius: clamp(readNumber(root.dataset.hero05Radius, HERO05_DEFAULTS.dropRadius), 0.02, 0.18),
    dropForce: clamp(readNumber(root.dataset.hero05Force, HERO05_DEFAULTS.dropForce), 0.2, 3.0),
    simResolution: clamp(Math.round(readNumber(root.dataset.hero05Sim, HERO05_DEFAULTS.simResolution)), 96, 512),
    text: (root.dataset.hero05Text || 'Liquid Matrix').trim() || 'Liquid Matrix',
  };

  const scene = new THREE.Scene();
  const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0, 1);

  const baseCanvas = document.createElement('canvas');
  const baseCtx = baseCanvas.getContext('2d', { alpha: false });

  const baseTexture = new THREE.CanvasTexture(baseCanvas);
  baseTexture.minFilter = THREE.LinearFilter;
  baseTexture.magFilter = THREE.LinearFilter;
  baseTexture.wrapS = THREE.ClampToEdgeWrapping;
  baseTexture.wrapT = THREE.ClampToEdgeWrapping;

  const simSize = settings.simResolution;
  const simLength = simSize * simSize;

  let prevWave = new Float32Array(simLength);
  let currWave = new Float32Array(simLength);
  let nextWave = new Float32Array(simLength);

  const displacementData = new Uint8Array(simLength * 4);
  for (let i = 0; i < simLength; i += 1) {
    const offset = i * 4;
    displacementData[offset] = 128;
    displacementData[offset + 1] = 128;
    displacementData[offset + 2] = 128;
    displacementData[offset + 3] = 128;
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
    uBase: { value: baseTexture },
    uDisplacement: { value: displacementTexture },
    uTime: { value: 0 },
    uIntensity: { value: 0 },
    uChroma: { value: settings.chroma },
    uChromaPulse: { value: 0 },
    uTexel: { value: new THREE.Vector2(1 / simSize, 1 / simSize) },
    uMaxShift: { value: HERO05_DEFAULTS.maxUvShift },
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

      uniform sampler2D uBase;
      uniform sampler2D uDisplacement;
      uniform float uTime;
      uniform float uIntensity;
      uniform float uChroma;
      uniform float uChromaPulse;
      uniform vec2 uTexel;
      uniform float uMaxShift;

      vec3 sampleBase(vec2 uv) {
        return texture2D(uBase, clamp(uv, vec2(0.001), vec2(0.999))).rgb;
      }

      void main() {
        vec4 disp = texture2D(uDisplacement, vUv);
        vec2 normalXY = disp.ba * 2.0 - 1.0;
        float nz = sqrt(max(0.0001, 1.0 - dot(normalXY, normalXY)));
        vec3 normal = normalize(vec3(normalXY, nz));

        vec2 dUvRaw = normal.xy * normal.z * (uIntensity * 0.42 + 0.02);
        float rawLen = length(dUvRaw);
        vec2 dUv = rawLen > uMaxShift ? dUvRaw * (uMaxShift / rawLen) : dUvRaw;
        vec2 uv = vUv + dUv;

        float st = smoothstep(0.0, 0.22, length(dUv) * 1.35);
        float chromaAmount = uChroma * uChromaPulse * st * (0.001 + st * 0.012);
        vec2 chromaDir = rawLen > 0.00001 ? normalize(dUv) : vec2(0.0);
        float wobble = sin(uTime * 1.7 + disp.r * 24.0) * 0.0012 * st * uChromaPulse;

        vec3 color;
        color.r = sampleBase(uv + chromaDir * chromaAmount + vec2(wobble, 0.0)).r;
        color.g = sampleBase(uv).g;
        color.b = sampleBase(uv - chromaDir * chromaAmount - vec2(wobble, 0.0)).b;

        vec3 blurSample = vec3(0.0);
        blurSample += sampleBase(uv + vec2(uTexel.x, 0.0));
        blurSample += sampleBase(uv - vec2(uTexel.x, 0.0));
        blurSample += sampleBase(uv + vec2(0.0, uTexel.y));
        blurSample += sampleBase(uv - vec2(0.0, uTexel.y));
        blurSample *= 0.25;
        color = mix(color, blurSample, st * 0.28);

        vec3 lightDir = normalize(vec3(-0.2, 0.45, 1.0));
        float spec = pow(max(0.0, dot(normal, lightDir)), 18.0) * 0.22;
        float fresnel = pow(1.0 - max(0.0, normal.z), 3.0) * 0.2;
        color += vec3(spec + fresnel);
        color = mix(color, vec3(0.96), st * 0.12);

        gl_FragColor = vec4(color, 1.0);
      }
    `,
    transparent: false,
    depthWrite: false,
    depthTest: false,
  });

  const quad = new THREE.Mesh(new THREE.PlaneGeometry(2, 2), material);
  scene.add(quad);

  const pointer = {
    x: 0.5,
    y: 0.5,
    px: 0.5,
    py: 0.5,
    speed: 0,
    active: false,
  };
  let chromaPulse = 0;

  const intro = { value: 0 };
  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (prefersReducedMotion) {
    intro.value = settings.distortion * 0.6;
    gsap.set(canvas, { autoAlpha: 1 });
  } else {
    gsap.set(canvas, { autoAlpha: 0 });
    gsap.to(canvas, { autoAlpha: 1, duration: 0.7, ease: 'power2.out' });
    gsap.to(intro, { value: settings.distortion, duration: 1.1, ease: 'power2.out' });
  }

  let rafId = 0;
  let destroyed = false;
  let elapsedTime = 0;
  let lastFrameTime = performance.now();

  const cleanupController = new AbortController();
  const { signal } = cleanupController;

  const resizeObserver = new ResizeObserver(() => {
    syncSize();
  });
  resizeObserver.observe(root);

  root.addEventListener(
    'pointermove',
    (event) => {
      const rect = root.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        return;
      }

      const nx = clamp((event.clientX - rect.left) / rect.width, 0, 1);
      const ny = clamp((event.clientY - rect.top) / rect.height, 0, 1);
      const dx = nx - pointer.px;
      const dy = ny - pointer.py;
      const speed = Math.hypot(dx, dy);

      pointer.px = nx;
      pointer.py = ny;
      pointer.x = nx;
      pointer.y = 1 - ny;
      pointer.speed = clamp(speed * 65, 0.08, 1.6);
      pointer.active = true;
      chromaPulse = Math.min(1, chromaPulse + pointer.speed * 0.5);
    },
    { signal, passive: true }
  );

  root.addEventListener(
    'pointerdown',
    (event) => {
      const rect = root.getBoundingClientRect();
      if (rect.width <= 0 || rect.height <= 0) {
        return;
      }

      const nx = clamp((event.clientX - rect.left) / rect.width, 0, 1);
      const ny = clamp((event.clientY - rect.top) / rect.height, 0, 1);
      pointer.px = nx;
      pointer.py = ny;
      pointer.x = nx;
      pointer.y = 1 - ny;
      pointer.speed = Math.max(pointer.speed, 0.55);
      pointer.active = true;

      addSplash(pointer.x, pointer.y, settings.dropForce * 0.24);
      chromaPulse = 1;
    },
    { signal, passive: true }
  );

  root.addEventListener(
    'pointerleave',
    () => {
      pointer.active = false;
    },
    { signal, passive: true }
  );

  syncSize();
  drawBaseTexture();
  if (document.fonts && typeof document.fonts.ready?.then === 'function') {
    document.fonts.ready.then(() => {
      if (!destroyed) {
        drawBaseTexture();
      }
    });
  }

  renderLoop();

  return {
    destroy() {
      destroyed = true;
      cancelAnimationFrame(rafId);
      cleanupController.abort();
      resizeObserver.disconnect();
      quad.geometry.dispose();
      material.dispose();
      baseTexture.dispose();
      displacementTexture.dispose();
      renderer.dispose();
    },
  };

  function renderLoop() {
    if (destroyed) {
      return;
    }

    const now = performance.now();
    const delta = Math.min((now - lastFrameTime) / 1000, 0.05);
    lastFrameTime = now;
    elapsedTime += delta;

    const targetFrame = prefersReducedMotion ? 1 / 60 : 1 / HERO05_DEFAULTS.targetFps;
    const iterations = prefersReducedMotion
      ? 1
      : clamp(Math.round(delta / targetFrame), 1, HERO05_DEFAULTS.maxSimSteps);

    advanceWaves(iterations);
    writeDisplacementTexture();

    uniforms.uTime.value = elapsedTime;
    uniforms.uIntensity.value = intro.value;
    uniforms.uChroma.value = settings.chroma;
    chromaPulse *= Math.exp(-delta * 10.5);
    uniforms.uChromaPulse.value = clamp(chromaPulse, 0, 1);

    renderer.render(scene, camera);
    rafId = requestAnimationFrame(renderLoop);
  }

  function syncSize() {
    const width = Math.max(1, root.clientWidth);
    const height = Math.max(1, root.clientHeight);
    const pixelRatio = Math.min(window.devicePixelRatio || 1, HERO05_DEFAULTS.maxPixelRatio);

    renderer.setPixelRatio(pixelRatio);
    renderer.setSize(width, height, false);

    drawBaseTexture();
  }

  function drawBaseTexture() {
    if (!baseCtx) {
      return;
    }

    const dpr = renderer.getPixelRatio();
    const width = Math.max(8, Math.floor(root.clientWidth * dpr));
    const height = Math.max(8, Math.floor(root.clientHeight * dpr));

    if (baseCanvas.width !== width || baseCanvas.height !== height) {
      baseCanvas.width = width;
      baseCanvas.height = height;
    }

    baseCtx.setTransform(1, 0, 0, 1, 0, 0);
    baseCtx.clearRect(0, 0, width, height);
    baseCtx.fillStyle = '#ffffff';
    baseCtx.fillRect(0, 0, width, height);

    const grad = baseCtx.createRadialGradient(
      width * 0.5,
      height * 0.45,
      width * 0.08,
      width * 0.5,
      height * 0.5,
      Math.max(width, height) * 0.7
    );
    grad.addColorStop(0, '#ffffff');
    grad.addColorStop(1, '#f4f5f7');
    baseCtx.fillStyle = grad;
    baseCtx.fillRect(0, 0, width, height);

    baseCtx.globalAlpha = 0.06;
    baseCtx.fillStyle = '#adb3bd';
    const blobs = 22;
    for (let i = 0; i < blobs; i += 1) {
      const radiusX = width * (0.05 + ((i * 17) % 29) * 0.0016);
      const radiusY = height * (0.03 + ((i * 13) % 23) * 0.0018);
      const x = (width * ((i * 97) % 101)) / 100;
      const y = (height * ((i * 67 + 19) % 101)) / 100;
      baseCtx.beginPath();
      baseCtx.ellipse(x, y, radiusX, radiusY, (i % 9) * 0.17, 0, Math.PI * 2);
      baseCtx.fill();
    }

    baseCtx.globalAlpha = 0.11;
    baseCtx.strokeStyle = '#c7cbd1';
    const waveBands = 10;
    for (let i = 0; i < waveBands; i += 1) {
      const y = (height / (waveBands + 1)) * (i + 1);
      const amp = height * 0.02 * (1 + (i % 3) * 0.4);
      baseCtx.lineWidth = Math.max(1, width * 0.0018);
      baseCtx.beginPath();
      baseCtx.moveTo(-width * 0.1, y);
      baseCtx.bezierCurveTo(
        width * 0.15,
        y - amp,
        width * 0.35,
        y + amp,
        width * 0.55,
        y
      );
      baseCtx.bezierCurveTo(
        width * 0.72,
        y - amp * 0.9,
        width * 0.9,
        y + amp * 0.6,
        width * 1.1,
        y - amp * 0.15
      );
      baseCtx.stroke();
    }
    baseCtx.globalAlpha = 1;

    const lines = splitText(settings.text);
    const maxWidthRatio = lines.length > 1 ? 0.84 : 0.9;
    let fontSize = Math.min(height * 0.29, width * (lines.length > 1 ? 0.28 : 0.22));

    baseCtx.fillStyle = '#17191d';
    baseCtx.textAlign = 'center';
    baseCtx.textBaseline = 'middle';
    baseCtx.font = `700 ${fontSize}px antton, poppins, sans-serif`;
    while (baseCtx.measureText(lines[0]).width > width * maxWidthRatio && fontSize > 12) {
      fontSize -= 2;
      baseCtx.font = `700 ${fontSize}px antton, poppins, sans-serif`;
    }

    const lineHeight = fontSize * 0.95;
    const centerY = height * 0.5;
    if (lines.length === 1) {
      baseCtx.fillText(lines[0], width * 0.5, centerY);
    } else {
      baseCtx.fillText(lines[0], width * 0.5, centerY - lineHeight * 0.52);
      baseCtx.fillText(lines[1], width * 0.5, centerY + lineHeight * 0.52);
    }

    baseTexture.needsUpdate = true;
  }

  function advanceWaves(iterations) {
    if (pointer.active) {
      const speedCurve = 1 - Math.exp(-pointer.speed * 1.8);
      const impulse = settings.dropForce * speedCurve * (0.95 + iterations * 0.18);
      addDrop(pointer.x, pointer.y, impulse);
      pointer.speed *= Math.pow(0.82, iterations);
      if (pointer.speed < 0.03) {
        pointer.active = false;
      }
    }

    for (let pass = 0; pass < iterations; pass += 1) {
      for (let y = 1; y < simSize - 1; y += 1) {
        const rowOffset = y * simSize;
        for (let x = 1; x < simSize - 1; x += 1) {
          const i = rowOffset + x;
          const wave =
            ((currWave[i - 1] + currWave[i + 1] + currWave[i - simSize] + currWave[i + simSize]) * 0.5 - prevWave[i]) *
            settings.damping;
          nextWave[i] = wave;
        }
      }

      for (let x = 0; x < simSize; x += 1) {
        nextWave[x] = 0;
        nextWave[(simSize - 1) * simSize + x] = 0;
      }
      for (let y = 0; y < simSize; y += 1) {
        nextWave[y * simSize] = 0;
        nextWave[y * simSize + (simSize - 1)] = 0;
      }

      const swap = prevWave;
      prevWave = currWave;
      currWave = nextWave;
      nextWave = swap;
    }
  }

  function addDrop(nx, ny, strength) {
    const centerX = Math.floor(nx * (simSize - 1));
    const centerY = Math.floor(ny * (simSize - 1));
    const radius = Math.max(2, Math.floor(simSize * settings.dropRadius));
    const radiusSq = radius * radius;

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
        const distSq = x * x + y * y;
        if (distSq > radiusSq) {
          continue;
        }

        const dist = Math.sqrt(distSq) / radius;
        const falloff = Math.cos(dist * Math.PI * 0.5);
        const idx = py * simSize + px;
        currWave[idx] = clamp(currWave[idx] + falloff * strength * 0.17, -1.2, 1.2);
      }
    }
  }

  function addSplash(nx, ny, strength) {
    addDrop(nx, ny, strength * 2.4);

    const inner = settings.dropRadius * 0.62;
    const outer = settings.dropRadius * 1.08;
    const ringSteps = 12;
    for (let i = 0; i < ringSteps; i += 1) {
      const angle = (i / ringSteps) * Math.PI * 2;
      const cosA = Math.cos(angle);
      const sinA = Math.sin(angle);

      addDrop(
        clamp(nx + cosA * inner, 0, 1),
        clamp(ny + sinA * inner, 0, 1),
        -strength * 0.72
      );
      addDrop(
        clamp(nx + cosA * outer, 0, 1),
        clamp(ny + sinA * outer, 0, 1),
        strength * 0.48
      );
    }
  }

  function writeDisplacementTexture() {
    for (let i = 0; i < simLength; i += 1) {
      const value = clamp(currWave[i], -1, 1);
      const x = i % simSize;
      const y = (i / simSize) | 0;
      const idxL = y * simSize + Math.max(0, x - 1);
      const idxR = y * simSize + Math.min(simSize - 1, x + 1);
      const idxB = Math.max(0, y - 1) * simSize + x;
      const idxT = Math.min(simSize - 1, y + 1) * simSize + x;

      const nx = clamp((currWave[idxL] - currWave[idxR]) * 1.2, -1, 1);
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

function splitText(text) {
  const words = text.split(/\s+/).filter(Boolean);
  if (words.length <= 1) {
    return [text];
  }
  if (words.length === 2) {
    return [words[0], words[1]];
  }

  const half = Math.ceil(words.length / 2);
  return [words.slice(0, half).join(' '), words.slice(half).join(' ')];
}

function readNumber(value, fallback) {
  const parsed = Number.parseFloat(value);
  return Number.isFinite(parsed) ? parsed : fallback;
}

function clamp(value, min, max) {
  return Math.max(min, Math.min(max, value));
}
