import gsap from 'gsap';
import ScrollTrigger from 'gsap/ScrollTrigger';
import * as THREE from 'three';

export default function initSectionParticles01() {
  gsap.registerPlugin(ScrollTrigger);

  const sections = Array.from(document.querySelectorAll('.sectionParticles01'));
  if (sections.length === 0) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const palettes = buildParticlePalettes();

  sections.forEach((section) => {
    if (section.dataset.particlesInit === 'true') {
      return;
    }
    section.dataset.particlesInit = 'true';

    const canvas = section.querySelector('[data-particles-canvas]');
    if (!canvas) {
      return;
    }

    let renderer;
    try {
      renderer = new THREE.WebGLRenderer({
        canvas,
        antialias: true,
        alpha: true,
        powerPreference: 'high-performance',
      });
    } catch (error) {
      return;
    }

    const pixelRatio = Math.min(window.devicePixelRatio || 1, 2);
    renderer.setPixelRatio(pixelRatio);
    renderer.setClearColor(0x000000, 0);

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(45, 1, 0.1, 320);
    camera.position.set(0, 0, 72);

    const group = new THREE.Group();
    scene.add(group);

    const rect = section.getBoundingClientRect();
    const viewportWidth = rect.width || window.innerWidth || 1;
    const viewportHeight = rect.height || window.innerHeight || 1;
    const densityFactor = Math.max(
      0.7,
      Math.min(1.35, Math.sqrt((viewportWidth * viewportHeight) / (1200 * 800)))
    );

    const baseCount = Math.round(
      clampInt(section.dataset.particlesCount, 1400, 32000, 4200) * densityFactor
    );
    const bgCount = Math.round(
      clampInt(section.dataset.particlesBgCount, 0, 30000, 4200) * densityFactor
    );
    const count = Math.max(1, baseCount + bgCount);
    const size = clampFloat(section.dataset.particlesSize, 0.6, 3.5, 1.7);
    const depth = clampFloat(section.dataset.particlesDepth, 6, 44, 20);
    const speed = clampFloat(section.dataset.particlesSpeed, 0.2, 2.2, 0.75);
    const shapeRatioDefault = clampFloat(section.dataset.particlesShapeRatio, 0.3, 1, 0.68);
    const shapeScaleDefault = clampFloat(section.dataset.particlesShapeScale, 0.25, 1.2, 0.72);
    const stepVh = clampFloat(section.dataset.particlesStepVh, 80, 240, 140);

    const steps = collectSteps(section);
    if (steps.length === 0) {
      return;
    }

    const stepConfigs = steps.map((step, index) =>
      buildStepConfig(step, index, {
        shapeRatio: shapeRatioDefault,
        shapeScale: shapeScaleDefault,
      })
    );
    const earthStepIndex = stepConfigs.findIndex((config) => config.shapeKey === 'earth');
    const matrixStepIndex = stepConfigs.findIndex((config) => config.shapeKey === 'matrix');
    const blackHoleStepIndex = stepConfigs.findIndex((config) => config.shapeKey === 'blackhole');
    const matrixWordParticleScale =
      matrixStepIndex >= 0 ? stepConfigs[matrixStepIndex].wordParticleScale ?? 0.85 : 0.85;

    initSteps(stepConfigs);

    const material = createParticleMaterial(pixelRatio);
    const system = createParticleSystem({
      count,
      size,
      depth,
      material,
    });
    group.add(system.points);

    const pointer = new THREE.Vector2(0, 0);
    const pointerTarget = new THREE.Vector2(0, 0);
    const hoverWorld = new THREE.Vector3();
    const hoverLocal = new THREE.Vector3();
    const tempVector = new THREE.Vector3();

    let hoverActive = false;
    let hoverStrength = 0;
    let scrollProgress = 0;
    let states = [];
    let shapeCounts = [];
    let shapeMasks = [];
    let earthOutline = null;
    let matrixFall = null;
    let activePaletteIndex = -1;
    let activePaletteNextIndex = -1;
    let activePaletteMix = -1;
    let totalSegments = 1;
    let hoverRadius = 1;
    let hoverPush = 1;
    let textTravel = window.innerHeight * 0.5;
    let trigger = null;

    const updateStepVisibility = () => {
      const segments = totalSegments || Math.max(1, steps.length * 2);
      const shapeStage = scrollProgress * segments;
      const stepFloat = (shapeStage - 1) / 2;
      if (shapeCounts.length > 0 && palettes.length > 0) {
        const paletteFloat = Math.max(0, stepFloat);
        const paletteIndex = Math.max(
          0,
          Math.min(shapeCounts.length - 1, Math.floor(paletteFloat))
        );
        const nextIndex = Math.min(shapeCounts.length - 1, paletteIndex + 1);
        const rawMix = paletteFloat - paletteIndex;
        const mix = smoothstep(Math.max(0, Math.min(1, rawMix)));
        if (
          paletteIndex !== activePaletteIndex ||
          nextIndex !== activePaletteNextIndex ||
          Math.abs(mix - activePaletteMix) > 0.002
        ) {
          if (nextIndex === paletteIndex || mix <= 0.001) {
            applyPalette(
              system,
              palettes[paletteIndex % palettes.length],
              shapeCounts[paletteIndex],
              shapeMasks[paletteIndex]
            );
          } else {
            applyPaletteBlend(
              system,
              palettes[paletteIndex % palettes.length],
              palettes[nextIndex % palettes.length],
              mix,
              shapeCounts[paletteIndex],
              shapeMasks[paletteIndex],
              shapeCounts[nextIndex],
              shapeMasks[nextIndex]
            );
          }
          activePaletteIndex = paletteIndex;
          activePaletteNextIndex = nextIndex;
          activePaletteMix = mix;
        }
      }
      stepConfigs.forEach((config, index) => {
        const step = config.element;
        const delta = stepFloat - index;
        const distance = Math.abs(delta);
        const opacity = Math.max(0, 1 - distance * 1.4);
        const clamped = Math.max(-1, Math.min(1, delta));
        const shift = -clamped * textTravel;
        step.style.opacity = opacity.toFixed(3);
        step.style.transform = `translate3d(0, ${shift.toFixed(2)}px, 0)`;
        step.classList.toggle('is-active', opacity > 0.6);
        step.style.pointerEvents = opacity > 0.6 ? 'auto' : 'none';
      });
    };

    const getStageMeta = () => {
      const stage = scrollProgress * totalSegments;
      const index = Math.min(totalSegments - 1, Math.floor(stage));
      const stepIndex = Math.min(steps.length - 1, Math.floor(index / 2));
      let t = stage - index;
      const stepHold = stepConfigs[stepIndex]?.shapeHold ?? 0;
      if (index % 2 === 1 && stepHold > 0) {
        const hold = Math.max(0, Math.min(0.85, stepHold));
        if (t < hold) {
          t = 0;
        } else {
          t = (t - hold) / (1 - hold);
        }
      }
      t = smoothstep(t);
      const formation = index % 2 === 0 ? t : 1 - t;
      return { stage, index, t, formation, stepIndex };
    };

    const getMatrixStrength = (formation, stepIndex) => {
      if (matrixStepIndex < 0 || stepIndex !== matrixStepIndex) {
        return 0;
      }
      return Math.max(0, Math.min(1, (formation - 0.55) / 0.45));
    };

    const buildStates = (shapeClouds) => {
      const bounds = getViewBounds(camera);
      const scatterCount = steps.length + 1;
      const scatterStates = new Array(scatterCount).fill(null).map(() => {
        return createScatterState(count, bounds, depth);
      });

      activePaletteIndex = -1;
      activePaletteNextIndex = -1;
      activePaletteMix = -1;
      shapeCounts = [];
      shapeMasks = [];
      earthOutline = null;
      matrixFall = null;
      const shapeStates = shapeClouds.map((shapeCloud, index) => {
        const scatterState = scatterStates[index];
        const config = stepConfigs[index];
        const ratio = config.shapeRatio ?? shapeRatioDefault;
        const shapeCount = Math.max(1, Math.min(count, Math.floor(baseCount * ratio)));
        shapeCounts[index] = shapeCount;
        let scale = config.shapeScale ?? shapeScaleDefault;
        if (matrixStepIndex === index && config.matrixCover) {
          scale = Math.max(scale, getCoverScale(bounds, shapeCloud.aspect || 1));
        }
        if (matrixStepIndex === index) {
          const matrixFit = fitShapeToBounds(bounds, scale, shapeCloud.aspect || 1);
          const centerY = bounds.height * config.shapeOffsetY;
          const halfHeight = matrixFit.height * 0.5;
          matrixFall = {
            height: Math.max(0.1, matrixFit.height),
            top: centerY + halfHeight,
            bottom: centerY - halfHeight,
            speed: Math.max(0.5, config.matrixSpeed ?? 8),
          };
        }
        if (shapeCloud.mask) {
          const cloudMask = shapeCloud.mask;
          const cloudCount = Math.max(1, Math.floor(cloudMask.length));
          const mask = new Uint8Array(count);
          for (let i = 0; i < shapeCount; i += 1) {
            const value = cloudMask[i % cloudCount];
            mask[i] = typeof value === 'number' ? value : value ? 1 : 0;
          }
          shapeMasks[index] = mask;
        } else {
          shapeMasks[index] = null;
        }
        return createShapeState({
          count,
          shapeCount,
          shapeCloud,
          scatterState,
          bounds,
          depth,
          shapeScale: scale,
          shapeDepth: config.shapeDepth,
          shapeOffsetX: config.shapeOffsetX,
          shapeOffsetY: config.shapeOffsetY,
        });
      });

      if (earthStepIndex >= 0) {
        const earthConfig = stepConfigs[earthStepIndex];
        const earthCloud = shapeClouds[earthStepIndex];
        if (earthConfig && earthCloud) {
          const earthFit = fitShapeToBounds(
            bounds,
            earthConfig.shapeScale ?? shapeScaleDefault,
            earthCloud.aspect || 1
          );
          earthOutline = {
            centerX: bounds.width * earthConfig.shapeOffsetX,
            centerY: bounds.height * earthConfig.shapeOffsetY,
            radiusX: earthFit.width * 0.5,
            radiusY: earthFit.height * 0.5,
          };
        }
      }

      states = [];
      for (let i = 0; i < shapeStates.length; i += 1) {
        states.push(scatterStates[i]);
        states.push(shapeStates[i]);
      }
      states.push(scatterStates[shapeStates.length]);
      totalSegments = Math.max(1, states.length - 1);

      const maxDim = Math.max(bounds.width, bounds.height);
      hoverRadius = maxDim * 0.22;
      hoverPush = depth * 1.5;
    };

    const setViewportSize = () => {
      const vh = window.innerHeight * 0.01;
      const vw = window.innerWidth * 0.01;
      section.style.setProperty('--particles-vh', `${vh}px`);
      section.style.setProperty('--particles-vw', `${vw}px`);
    };

    setViewportSize();

    const resize = () => {
      setViewportSize();
      const rect = section.getBoundingClientRect();
      const width = Math.max(1, rect.width);
      const height = Math.max(1, rect.height);
      textTravel = Math.max(window.innerHeight, height) * 0.5;
      renderer.setSize(width, height, false);
      const nextPixelRatio = Math.min(window.devicePixelRatio || 1, 2);
      renderer.setPixelRatio(nextPixelRatio);
      material.uniforms.uPixelRatio.value = nextPixelRatio;
      camera.aspect = width / height;
      camera.updateProjectionMatrix();
      if (system.shapeClouds) {
        buildStates(system.shapeClouds);
      }
      trigger?.refresh();
    };

    const handlePointer = (event) => {
      const rect = section.getBoundingClientRect();
      const x = (event.clientX - rect.left) / rect.width;
      const y = (event.clientY - rect.top) / rect.height;
      pointerTarget.set((x - 0.5) * 2, (0.5 - y) * 2);
      hoverActive = true;
    };

    const handlePointerLeave = () => {
      pointerTarget.set(0, 0);
      hoverActive = false;
    };

    section.addEventListener('pointermove', handlePointer);
    section.addEventListener('pointerleave', handlePointerLeave);

    if (typeof ResizeObserver !== 'undefined') {
      const observer = new ResizeObserver(resize);
      observer.observe(section);
    } else {
      window.addEventListener('resize', resize);
    }

    loadShapeClouds(section, stepConfigs, Math.floor(baseCount * shapeRatioDefault)).then((shapeClouds) => {
      system.shapeClouds = shapeClouds;
      resize();
      buildStates(shapeClouds);
      updateStepVisibility();

      if (prefersReducedMotion) {
        scrollProgress = 0.25;
        renderFrame(0, getStageMeta());
        return;
      }

      trigger = ScrollTrigger.create({
        trigger: section,
        start: 'top top',
        end: () => `+=${steps.length * window.innerHeight * (stepVh / 100)}`,
        scrub: true,
        pin: true,
        invalidateOnRefresh: true,
        onUpdate: (self) => {
          scrollProgress = self.progress;
          updateStepVisibility();
        },
      });

      const clock = new THREE.Clock();
      let rafId = null;
      let isVisible = true;

      const animate = () => {
        if (!isVisible) {
          rafId = null;
          return;
        }

        const elapsed = clock.getElapsedTime();
        pointer.lerp(pointerTarget, 0.08);
        hoverStrength += ((hoverActive ? 1 : 0) - hoverStrength) * 0.08;
        const stageMeta = getStageMeta();
        const isMatrixStepActive = matrixStepIndex >= 0 && stageMeta.stepIndex === matrixStepIndex;
        if (isMatrixStepActive) {
          group.rotation.x += (0 - group.rotation.x) * 0.18;
          group.rotation.y += (0 - group.rotation.y) * 0.18;
          group.rotation.z += (0 - group.rotation.z) * 0.18;
        } else {
          const targetX = pointer.y * 0.35;
          const targetY = pointer.x * 0.6;
          group.rotation.x += (targetX - group.rotation.x) * 0.08;
          group.rotation.y += (targetY - group.rotation.y) * 0.08;
          group.rotation.z = elapsed * 0.04;
        }

        renderFrame(elapsed, stageMeta);

        rafId = requestAnimationFrame(animate);
      };

      const start = () => {
        if (rafId === null) {
          rafId = requestAnimationFrame(animate);
        }
      };

      if (typeof IntersectionObserver !== 'undefined') {
        const observer = new IntersectionObserver(
          (entries) => {
            entries.forEach((entry) => {
              isVisible = entry.isIntersecting;
              if (isVisible) {
                start();
              }
            });
          },
          { threshold: 0.1 }
        );
        observer.observe(section);
      }

      start();
    });

    function renderFrame(elapsed, stageMeta) {
      if (states.length < 2) {
        return;
      }
      const meta = stageMeta || getStageMeta();
      const index = meta.index;
      const t = meta.t;
      const formation = meta.formation;
      const stepIndex = meta.stepIndex;
      const from = states[index];
      const to = states[index + 1];
      const isMatrixStep = matrixStepIndex >= 0 && stepIndex === matrixStepIndex;
      const isBlackHoleStep = blackHoleStepIndex >= 0 && stepIndex === blackHoleStepIndex;
      let wobbleBase = 0.18;
      let wobbleRange = 0.82;
      if (isMatrixStep) {
        wobbleBase = 0.1;
        wobbleRange = 0.5;
      }
      if (isBlackHoleStep) {
        wobbleBase = 0.08;
        wobbleRange = 0.45;
      }
      const wobbleFactor = wobbleBase + (1 - formation) * wobbleRange;
      const matrixStrength = getMatrixStrength(formation, stepIndex);
      const matrixActive = matrixStrength > 0 && matrixFall && matrixStepIndex >= 0;
      const matrixHeight = matrixFall ? matrixFall.height : 0;
      const matrixTop = matrixFall ? matrixFall.top : 0;
      const matrixBottom = matrixFall ? matrixFall.bottom : 0;
      const matrixSpeed = matrixFall ? matrixFall.speed : 0;
      const matrixMask = matrixActive ? shapeMasks[matrixStepIndex] : null;

      let hasHover = hoverStrength > 0.02;
      if (hasHover) {
        tempVector.set(pointer.x, pointer.y, 0.5).unproject(camera);
        const dir = tempVector.sub(camera.position);
        const dirZ = dir.z;
        if (Math.abs(dirZ) < 0.0001) {
          hasHover = false;
        } else {
          const distance = -camera.position.z / dirZ;
          hoverWorld.copy(camera.position).add(dir.multiplyScalar(distance));
          hoverLocal.copy(hoverWorld);
          group.worldToLocal(hoverLocal);
        }
      }
      if (isBlackHoleStep) {
        hasHover = false;
      }

      const positions = system.positions;
      const scales = system.scales;
      const alphas = system.alphas;
      const baseAlphas = system.baseAlphas;
      const colorSeeds = system.colorSeeds;
      const drift = system.drift;
      const phase = system.phase;
      const driftScale = system.driftScale;
      const focusRange = system.focusRange;
      const earthActive = earthStepIndex >= 0 && stepIndex === earthStepIndex;
      const earthCount = earthActive ? shapeCounts[earthStepIndex] || 0 : 0;
      const earthBoost = earthActive ? formation : 0;
      const outline = earthActive ? earthOutline : null;

      for (let i = 0, idx = 0; i < system.count; i += 1, idx += 3) {
        const baseX = from[idx] + (to[idx] - from[idx]) * t;
        const baseY = from[idx + 1] + (to[idx + 1] - from[idx + 1]) * t;
        const baseZ = from[idx + 2] + (to[idx + 2] - from[idx + 2]) * t;

        const wobbleX = Math.sin(elapsed * speed + phase[idx]) * drift[idx] * driftScale * wobbleFactor;
        const wobbleY = Math.sin(elapsed * speed + phase[idx + 1]) * drift[idx + 1] * driftScale * wobbleFactor;
        const wobbleZ = Math.sin(elapsed * speed + phase[idx + 2]) * drift[idx + 2] * driftScale * wobbleFactor;

        const isMatrixWord = matrixMask ? matrixMask[i] === 2 : false;
        const wobbleScale = isMatrixWord ? 0.02 : 1;
        let posX = baseX + wobbleX * wobbleScale;
        let posY = baseY + wobbleY * wobbleScale;
        let posZ = baseZ + wobbleZ * wobbleScale;

        if (
          matrixActive &&
          matrixHeight > 0.001 &&
          i < (shapeCounts[matrixStepIndex] || 0) &&
          !isMatrixWord
        ) {
          const speedFactor = 0.35 + colorSeeds[i] * 1.5;
          const phaseOffset = (phase[idx + 1] / (Math.PI * 2)) * matrixHeight;
          const fallOffset = (elapsed * matrixSpeed * speedFactor + phaseOffset) % matrixHeight;
          posY -= fallOffset * matrixStrength;
          if (posY < matrixBottom) {
            posY += matrixHeight;
          } else if (posY > matrixTop) {
            posY -= matrixHeight;
          }
        }

        let focus = 1 - Math.min(1, Math.abs(posZ) / focusRange);
        focus = Math.pow(focus, 1.25);
        let scale = 0.7 + focus * 0.9;
        let alpha = baseAlphas[i] * (0.35 + focus * 0.85);

        if (isMatrixWord) {
          posZ *= 0.08;
          scale *= matrixWordParticleScale;
          alpha *= 1.25;
        }

        if (earthActive && outline && i < earthCount) {
          const dx = (posX - outline.centerX) / outline.radiusX;
          const dy = (posY - outline.centerY) / outline.radiusY;
          const radial = Math.sqrt(dx * dx + dy * dy);
          const rim = Math.max(0, (radial - 0.75) / 0.22);
          const rimBoost = Math.min(1, rim) * earthBoost;
          scale *= 1 + rimBoost * 0.28;
          alpha *= 1 + rimBoost * 0.55;
        }

        if (hasHover) {
          const dx = posX - hoverLocal.x;
          const dy = posY - hoverLocal.y;
          const dz = posZ - hoverLocal.z;
          const dist = Math.sqrt(dx * dx + dy * dy + dz * dz) || 1;
          if (dist < hoverRadius) {
            const influence = 1 - dist / hoverRadius;
            const eased = influence * influence;
            const inv = 1 / dist;
            posX += dx * inv * hoverPush * eased * 0.3;
            posY += dy * inv * hoverPush * eased * 0.3;
            posZ += dz * inv * hoverPush * eased + hoverPush * 0.2 * eased;
            scale *= 1 + hoverStrength * 1.2 * eased;
            alpha *= 1 + hoverStrength * 0.6 * eased;
          }
        }

        positions[idx] = posX;
        positions[idx + 1] = posY;
        positions[idx + 2] = posZ;
        scales[i] = scale;
        alphas[i] = Math.min(alpha, 1);
      }

      system.geometry.attributes.position.needsUpdate = true;
      system.geometry.attributes.aScale.needsUpdate = true;
      system.geometry.attributes.aAlpha.needsUpdate = true;

      renderer.render(scene, camera);
    }
  });
}

function createParticleMaterial(pixelRatio) {
  return new THREE.ShaderMaterial({
    uniforms: {
      uPixelRatio: { value: pixelRatio },
    },
    vertexShader: `
      attribute float aSize;
      attribute float aScale;
      attribute float aAlpha;
      attribute vec3 color;
      uniform float uPixelRatio;
      varying float vAlpha;
      varying vec3 vColor;

      void main() {
        vColor = color;
        vAlpha = aAlpha;
        vec4 mvPosition = modelViewMatrix * vec4(position, 1.0);
        float size = aSize * aScale;
        gl_PointSize = size * (uPixelRatio * (165.0 / -mvPosition.z));
        gl_Position = projectionMatrix * mvPosition;
      }
    `,
    fragmentShader: `
      precision mediump float;
      varying float vAlpha;
      varying vec3 vColor;

      void main() {
        vec2 coord = gl_PointCoord - vec2(0.5);
        float dist = length(coord);
        float alpha = smoothstep(0.5, 0.0, dist);
        alpha = pow(alpha, 1.6);
        gl_FragColor = vec4(vColor, alpha * vAlpha);
      }
    `,
    transparent: true,
    depthWrite: false,
    blending: THREE.AdditiveBlending,
  });
}

function createParticleSystem({ count, size, depth, material }) {
  const positions = new Float32Array(count * 3);
  const colors = new Float32Array(count * 3);
  const sizes = new Float32Array(count);
  const scales = new Float32Array(count);
  const alphas = new Float32Array(count);
  const baseAlphas = new Float32Array(count);
  const colorSeeds = new Float32Array(count);
  const alphaSeeds = new Float32Array(count);
  const drift = new Float32Array(count * 3);
  const phase = new Float32Array(count * 3);

  for (let i = 0; i < count; i += 1) {
    const idx = i * 3;
    colors[idx] = 1;
    colors[idx + 1] = 1;
    colors[idx + 2] = 1;

    sizes[i] = size * (0.7 + Math.random() * 1.4);
    scales[i] = 1;
    colorSeeds[i] = Math.random();
    alphaSeeds[i] = 0.55 + Math.random() * 0.45;
    baseAlphas[i] = alphaSeeds[i];
    alphas[i] = baseAlphas[i];

    drift[idx] = Math.random() - 0.5;
    drift[idx + 1] = Math.random() - 0.5;
    drift[idx + 2] = Math.random() - 0.5;
    phase[idx] = Math.random() * Math.PI * 2;
    phase[idx + 1] = Math.random() * Math.PI * 2;
    phase[idx + 2] = Math.random() * Math.PI * 2;
  }

  const geometry = new THREE.BufferGeometry();
  geometry.setAttribute('position', new THREE.BufferAttribute(positions, 3));
  geometry.setAttribute('color', new THREE.BufferAttribute(colors, 3));
  geometry.setAttribute('aSize', new THREE.BufferAttribute(sizes, 1));
  geometry.setAttribute('aScale', new THREE.BufferAttribute(scales, 1));
  geometry.setAttribute('aAlpha', new THREE.BufferAttribute(alphas, 1));

  const points = new THREE.Points(geometry, material);
  points.frustumCulled = false;

  return {
    count,
    positions,
    colors,
    scales,
    alphas,
    baseAlphas,
    colorSeeds,
    alphaSeeds,
    drift,
    phase,
    geometry,
    points,
    driftScale: Math.max(0.05, depth * 0.12),
    focusRange: Math.max(0.1, depth * 2.2),
  };
}

function applyPalette(system, palette, shapeCount, mask) {
  if (!palette || !system) {
    return;
  }
  const { colors, colorSeeds, alphaSeeds, baseAlphas, alphas, geometry } = system;
  const shapeColors = palette.shape;
  const bgColors = palette.background;
  const landColors = palette.land;
  const oceanColors = palette.ocean;
  if (!shapeColors?.length || !bgColors?.length) {
    return;
  }
  const shapeAlpha = palette.shapeAlpha ?? 1;
  const landAlpha = palette.landAlpha ?? shapeAlpha;
  const oceanAlpha = palette.oceanAlpha ?? shapeAlpha;
  const rimAlpha = palette.rimAlpha ?? (shapeAlpha + 0.6);
  const bgAlpha = palette.bgAlpha ?? 0.55;
  const temp = new THREE.Color();

  for (let i = 0, idx = 0; i < system.count; i += 1, idx += 3) {
    const seed = colorSeeds[i];
    const inShape = i < shapeCount;
    const isLand = inShape && mask ? mask[i] === 1 : null;
    const isRim = inShape && mask ? mask[i] === 2 : false;
    const paletteColors =
      inShape && isRim && palette.rim?.length
        ? palette.rim
        : inShape && isLand !== null && landColors?.length && oceanColors?.length
        ? isLand
          ? landColors
          : oceanColors
        : inShape
          ? shapeColors
          : bgColors;
    const baseIndex = Math.floor(seed * paletteColors.length) % paletteColors.length;
    const nextIndex = (baseIndex + 1) % paletteColors.length;
    const mix = (seed * 1.7) % 1;
    if (!inShape && seed < 0.06) {
      const accent = shapeColors[Math.floor(seed * shapeColors.length) % shapeColors.length];
      temp.copy(accent).lerp(paletteColors[baseIndex], 0.35);
    } else {
      temp.copy(paletteColors[baseIndex]).lerp(paletteColors[nextIndex], mix);
    }
    colors[idx] = temp.r;
    colors[idx + 1] = temp.g;
    colors[idx + 2] = temp.b;

    const alphaBoost = !inShape && seed < 0.08 ? 0.3 : 0;
    const landBoost = inShape && isLand ? palette.landBoost ?? 0.28 : 0;
    const shapeBase = inShape
      ? isRim
        ? rimAlpha
        : isLand !== null && landColors?.length && oceanColors?.length
          ? isLand
            ? landAlpha
            : oceanAlpha
          : shapeAlpha
      : bgAlpha;
    const rimBoost = isRim ? 0.25 : 0;
    baseAlphas[i] = alphaSeeds[i] * (shapeBase + (inShape ? landBoost + rimBoost : alphaBoost));
    alphas[i] = baseAlphas[i];
  }

  geometry.attributes.color.needsUpdate = true;
  geometry.attributes.aAlpha.needsUpdate = true;
}

function applyPaletteBlend(
  system,
  paletteA,
  paletteB,
  mix,
  shapeCountA,
  maskA,
  shapeCountB,
  maskB
) {
  if (!paletteA || !paletteB || !system) {
    return;
  }
  const { colors, colorSeeds, alphaSeeds, baseAlphas, alphas, geometry } = system;
  const shapeColorsA = paletteA.shape;
  const bgColorsA = paletteA.background;
  const landColorsA = paletteA.land;
  const oceanColorsA = paletteA.ocean;
  const shapeColorsB = paletteB.shape;
  const bgColorsB = paletteB.background;
  const landColorsB = paletteB.land;
  const oceanColorsB = paletteB.ocean;
  if (!shapeColorsA?.length || !bgColorsA?.length || !shapeColorsB?.length || !bgColorsB?.length) {
    return;
  }
  const shapeAlphaA = paletteA.shapeAlpha ?? 1;
  const landAlphaA = paletteA.landAlpha ?? shapeAlphaA;
  const oceanAlphaA = paletteA.oceanAlpha ?? shapeAlphaA;
  const rimAlphaA = paletteA.rimAlpha ?? (shapeAlphaA + 0.6);
  const bgAlphaA = paletteA.bgAlpha ?? 0.55;
  const shapeAlphaB = paletteB.shapeAlpha ?? 1;
  const landAlphaB = paletteB.landAlpha ?? shapeAlphaB;
  const oceanAlphaB = paletteB.oceanAlpha ?? shapeAlphaB;
  const rimAlphaB = paletteB.rimAlpha ?? (shapeAlphaB + 0.6);
  const bgAlphaB = paletteB.bgAlpha ?? 0.55;
  const tempA = new THREE.Color();
  const tempB = new THREE.Color();
  const invMix = 1 - mix;

  for (let i = 0, idx = 0; i < system.count; i += 1, idx += 3) {
    const seed = colorSeeds[i];

    const inShapeA = i < shapeCountA;
    const isLandA = inShapeA && maskA ? maskA[i] === 1 : null;
    const isRimA = inShapeA && maskA ? maskA[i] === 2 : false;
    const paletteColorsA =
      inShapeA && isRimA && paletteA.rim?.length
        ? paletteA.rim
        : inShapeA && isLandA !== null && landColorsA?.length && oceanColorsA?.length
        ? isLandA
          ? landColorsA
          : oceanColorsA
        : inShapeA
          ? shapeColorsA
          : bgColorsA;
    const baseIndexA = Math.floor(seed * paletteColorsA.length) % paletteColorsA.length;
    const nextIndexA = (baseIndexA + 1) % paletteColorsA.length;
    const mixA = (seed * 1.7) % 1;
    if (!inShapeA && seed < 0.06) {
      const accent = shapeColorsA[Math.floor(seed * shapeColorsA.length) % shapeColorsA.length];
      tempA.copy(accent).lerp(paletteColorsA[baseIndexA], 0.35);
    } else {
      tempA.copy(paletteColorsA[baseIndexA]).lerp(paletteColorsA[nextIndexA], mixA);
    }
    const alphaBoostA = !inShapeA && seed < 0.08 ? 0.3 : 0;
    const landBoostA = inShapeA && isLandA ? paletteA.landBoost ?? 0.28 : 0;
    const shapeBaseA = inShapeA
      ? isRimA
        ? rimAlphaA
        : isLandA !== null && landColorsA?.length && oceanColorsA?.length
          ? isLandA
            ? landAlphaA
            : oceanAlphaA
          : shapeAlphaA
      : bgAlphaA;
    const rimBoostA = isRimA ? 0.25 : 0;
    const alphaFactorA = shapeBaseA + (inShapeA ? landBoostA + rimBoostA : alphaBoostA);

    const inShapeB = i < shapeCountB;
    const isLandB = inShapeB && maskB ? maskB[i] === 1 : null;
    const isRimB = inShapeB && maskB ? maskB[i] === 2 : false;
    const paletteColorsB =
      inShapeB && isRimB && paletteB.rim?.length
        ? paletteB.rim
        : inShapeB && isLandB !== null && landColorsB?.length && oceanColorsB?.length
        ? isLandB
          ? landColorsB
          : oceanColorsB
        : inShapeB
          ? shapeColorsB
          : bgColorsB;
    const baseIndexB = Math.floor(seed * paletteColorsB.length) % paletteColorsB.length;
    const nextIndexB = (baseIndexB + 1) % paletteColorsB.length;
    const mixB = (seed * 1.7) % 1;
    if (!inShapeB && seed < 0.06) {
      const accent = shapeColorsB[Math.floor(seed * shapeColorsB.length) % shapeColorsB.length];
      tempB.copy(accent).lerp(paletteColorsB[baseIndexB], 0.35);
    } else {
      tempB.copy(paletteColorsB[baseIndexB]).lerp(paletteColorsB[nextIndexB], mixB);
    }
    const alphaBoostB = !inShapeB && seed < 0.08 ? 0.3 : 0;
    const landBoostB = inShapeB && isLandB ? paletteB.landBoost ?? 0.28 : 0;
    const shapeBaseB = inShapeB
      ? isRimB
        ? rimAlphaB
        : isLandB !== null && landColorsB?.length && oceanColorsB?.length
          ? isLandB
            ? landAlphaB
            : oceanAlphaB
          : shapeAlphaB
      : bgAlphaB;
    const rimBoostB = isRimB ? 0.25 : 0;
    const alphaFactorB = shapeBaseB + (inShapeB ? landBoostB + rimBoostB : alphaBoostB);

    colors[idx] = tempA.r * invMix + tempB.r * mix;
    colors[idx + 1] = tempA.g * invMix + tempB.g * mix;
    colors[idx + 2] = tempA.b * invMix + tempB.b * mix;
    baseAlphas[i] = alphaSeeds[i] * (alphaFactorA * invMix + alphaFactorB * mix);
    alphas[i] = baseAlphas[i];
  }

  geometry.attributes.color.needsUpdate = true;
  geometry.attributes.aAlpha.needsUpdate = true;
}

function buildParticlePalettes() {
  const parse = (list) => list.map((hex) => new THREE.Color(hex));
  return [
    {
      shape: parse(['#6fd1ff', '#ffffff', '#2f7eff']),
      background: parse(['#172538', '#20344b', '#28405c', '#1d4a6a']),
      shapeAlpha: 1.22,
      bgAlpha: 0.52,
    },
    {
      shape: parse(['#19ff8f', '#6bffb6', '#d3ffef']),
      rim: parse(['#00ff6a', '#7dffc1', '#e3fff3']),
      background: parse(['#050c08', '#08130c', '#0a1a11', '#0e2416']),
      shapeAlpha: 1.42,
      rimAlpha: 2,
      bgAlpha: 0.28,
    },
    {
      shape: parse(['#ffd7a1', '#ffb066', '#fff1d1']),
      rim: parse(['#ffffff', '#ffe6b8', '#ffb978']),
      background: parse(['#05070c', '#0a1018', '#111923', '#1a2633']),
      shapeAlpha: 1.28,
      rimAlpha: 1.7,
      bgAlpha: 0.32,
    },
  ];
}

function collectSteps(section) {
  const steps = Array.from(section.querySelectorAll('[data-particles-step]'));
  if (steps.length > 0) {
    return steps;
  }
  const fallback = section.querySelector('.sectionParticles01-content');
  return fallback ? [fallback] : [];
}

function buildStepConfig(step, index, defaults) {
  const shapeKey = (step.dataset.particlesShape || '').toLowerCase();
  const alignRaw = step.dataset.particlesAlign || (index % 2 === 0 ? 'left' : 'right');
  const align = alignRaw.toLowerCase() === 'right' ? 'right' : 'left';
  const shapeScale = clampFloat(step.dataset.particlesShapeScale, 0.2, 1.3, defaults.shapeScale);
  const shapeRatio = clampFloat(step.dataset.particlesShapeRatio, 0.3, 1, defaults.shapeRatio);
  const shapeDepth = clampFloat(step.dataset.particlesShapeDepth, 0.2, 3, 0.7);
  const shapeDepthJitter = clampFloat(step.dataset.particlesShapeDepthJitter, 0.05, 0.9, 0.32);
  const matrixSpeed = clampFloat(step.dataset.particlesMatrixSpeed, 0.5, 14, 8);
  const matrixCover = step.dataset.particlesMatrixCover !== 'false';
  const shapeHold = clampFloat(step.dataset.particlesShapeHold, 0, 0.85, 0);

  const offsetXRaw = parseFloat(step.dataset.particlesShapeOffsetX || '');
  const offsetYRaw = parseFloat(step.dataset.particlesShapeOffsetY || '');
  const defaultOffsetX = align === 'left' ? 0.22 : -0.22;
  const shapeOffsetX = Number.isNaN(offsetXRaw)
    ? defaultOffsetX
    : Math.max(-0.45, Math.min(0.45, offsetXRaw));
  const shapeOffsetY = Number.isNaN(offsetYRaw) ? 0 : Math.max(-0.35, Math.min(0.35, offsetYRaw));

  const shapeMode = step.dataset.particlesShapeMode || 'fill';
  const shapeThreshold = clampInt(step.dataset.particlesShapeThreshold, 0, 255, 25);
  const shapeEdgeThreshold = clampInt(step.dataset.particlesShapeEdgeThreshold, 0, 255, 36);
  const shapeMinBrightness = clampInt(step.dataset.particlesShapeMinBrightness, 0, 255, 8);
  const wordParticleScale = clampFloat(step.dataset.particlesMatrixWordParticleScale, 0.5, 1.2, 0.85);

  return {
    element: step,
    shapeKey,
    align,
    shapeScale,
    shapeRatio,
    shapeDepth,
    shapeDepthJitter,
    shapeOffsetX,
    shapeOffsetY,
    shapeHold,
    shapeMode,
    shapeThreshold,
    shapeEdgeThreshold,
    shapeMinBrightness,
    matrixSpeed,
    matrixCover,
    wordParticleScale,
  };
}

function initSteps(stepConfigs) {
  stepConfigs.forEach((config, index) => {
    const step = config.element;
    step.classList.add('sectionParticles01-step', `sectionParticles01-step--${config.align}`);
    step.style.opacity = index === 0 ? '1' : '0';
    step.classList.toggle('is-active', index === 0);
    step.style.pointerEvents = index === 0 ? 'auto' : 'none';
  });
}

function getViewBounds(camera) {
  const distance = Math.abs(camera.position.z);
  const vFov = THREE.MathUtils.degToRad(camera.fov);
  const height = 2 * Math.tan(vFov / 2) * distance;
  const width = height * camera.aspect;
  return { width, height };
}

function getCoverScale(bounds, aspect) {
  const safeAspect = aspect && Number.isFinite(aspect) ? aspect : 1;
  const viewportAspect = bounds.height > 0 ? bounds.width / bounds.height : 1;
  if (!Number.isFinite(viewportAspect) || viewportAspect <= 0) {
    return 1;
  }
  return Math.max(viewportAspect / safeAspect, safeAspect / viewportAspect);
}

function createScatterState(count, bounds, depth) {
  const state = new Float32Array(count * 3);
  const radiusX = bounds.width * 0.6;
  const radiusY = bounds.height * 0.6;
  const radiusZ = depth * 2.2;
  const boxWidth = bounds.width * 1.2;
  const boxHeight = bounds.height * 1.2;
  const boxDepth = depth * 2.6;

  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    if (Math.random() < 0.6) {
      state[idx] = (Math.random() - 0.5) * boxWidth;
      state[idx + 1] = (Math.random() - 0.5) * boxHeight;
      state[idx + 2] = (Math.random() - 0.5) * boxDepth;
    } else {
      const u = Math.random();
      const v = Math.random();
      const theta = u * Math.PI * 2;
      const phi = Math.acos(2 * v - 1);
      const r = Math.cbrt(Math.random());
      const sinPhi = Math.sin(phi);
      state[idx] = Math.cos(theta) * sinPhi * radiusX * r;
      state[idx + 1] = Math.cos(phi) * radiusY * r;
      state[idx + 2] = Math.sin(theta) * sinPhi * radiusZ * r;
    }
  }

  return state;
}

function createShapeState({
  count,
  shapeCount,
  shapeCloud,
  scatterState,
  bounds,
  depth,
  shapeScale,
  shapeDepth,
  shapeOffsetX,
  shapeOffsetY,
}) {
  const state = new Float32Array(count * 3);
  const cloudPoints = shapeCloud.points || shapeCloud;
  const aspect = shapeCloud.aspect || 1;
  const fitted = fitShapeToBounds(bounds, shapeScale, aspect);
  const shapeWidth = fitted.width;
  const shapeHeight = fitted.height;
  const depthScale =
    typeof shapeCloud.depthScale === 'number' ? shapeCloud.depthScale : Math.max(0.2, shapeDepth ?? 0.7);
  const shapeDepthValue = depth * depthScale;
  const cloudCount = Math.max(1, Math.floor(cloudPoints.length / 3));
  const offsetX = bounds.width * shapeOffsetX;
  const offsetY = bounds.height * shapeOffsetY;

  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    if (i < shapeCount) {
      const source = (i % cloudCount) * 3;
      state[idx] = cloudPoints[source] * shapeWidth + offsetX;
      state[idx + 1] = cloudPoints[source + 1] * shapeHeight + offsetY;
      state[idx + 2] = cloudPoints[source + 2] * shapeDepthValue;
    } else {
      state[idx] = scatterState[idx];
      state[idx + 1] = scatterState[idx + 1];
      state[idx + 2] = scatterState[idx + 2];
    }
  }

  return state;
}

function fitShapeToBounds(bounds, shapeScale, aspect) {
  const maxWidth = bounds.width * shapeScale;
  const maxHeight = bounds.height * shapeScale;
  let width = maxWidth;
  let height = width / aspect;
  if (height > maxHeight) {
    height = maxHeight;
    width = height * aspect;
  }
  return { width, height };
}

async function loadShapeClouds(section, stepConfigs, count) {
  const generators = {
    sphere: generateSphereShape,
    ring: generateRingShape,
    ribbon: generateRibbonShape,
    blob: generateBlobShape,
    cube: generateCubeWireShape,
    rocket: generateRocketShape,
    earth: generateEarthShape,
    blackhole: generateBlackHoleShape,
  };

  const defaults = [generateRingShape, generateSphereShape, generateRibbonShape, generateBlobShape];

  const shapePromises = stepConfigs.map(async (config, index) => {
    const step = config.element;
    const shapeSrc = step.dataset.particlesShapeSrc;
    const shapeText = step.dataset.particlesShapeText;
    const shapeKey = (step.dataset.particlesShape || '').toLowerCase();
    const shapeSelector = step.dataset.particlesShapeSelector;
    const earthMapSrc = step.dataset.particlesEarthMapSrc;
    const earthMapSrc2 = step.dataset.particlesEarthMapSrc2;
    const fallback = defaults[index % defaults.length];

    const options = {
      mode: config.shapeMode,
      threshold: config.shapeThreshold,
      edgeThreshold: config.shapeEdgeThreshold,
      minBrightness: config.shapeMinBrightness,
      depthJitter: config.shapeDepthJitter,
    };

    try {
      if (shapeKey === 'blackhole') {
        const blackHoleOptions = {
          diskInner: clampFloat(step.dataset.particlesBhDiskInner, 0.18, 0.7, 0.34),
          diskOuter: clampFloat(step.dataset.particlesBhDiskOuter, 0.25, 1.2, 0.74),
          diskThickness: clampFloat(step.dataset.particlesBhDiskThickness, 0.02, 0.25, 0.08),
          halo: clampFloat(step.dataset.particlesBhHalo, 0, 0.6, 0.26),
          rim: clampFloat(step.dataset.particlesBhRim, 0.05, 0.35, 0.16),
          tilt: clampFloat(step.dataset.particlesBhTilt, -45, 45, 18),
        };
        return generateBlackHoleShape(count, blackHoleOptions);
      }

      if (shapeKey === 'matrix') {
        let wordImage = null;
        const matrixWordSrc = step.dataset.particlesMatrixWordSrc;
        if (matrixWordSrc) {
          try {
            wordImage = await loadImage(matrixWordSrc);
          } catch (error) {
            wordImage = null;
          }
        }
        const matrixOptions = {
          glyphs: step.dataset.particlesMatrixGlyphs,
          cols: clampInt(step.dataset.particlesMatrixCols, 10, 420, 48),
          rows: clampInt(step.dataset.particlesMatrixRows, 12, 320, 52),
          density: clampFloat(step.dataset.particlesMatrixDensity, 0.25, 2.5, 0.8),
          columnDensity: clampFloat(step.dataset.particlesMatrixColumnDensity, 0.2, 2.5, Number.NaN),
          noiseDensity: clampFloat(step.dataset.particlesMatrixNoiseDensity, 0, 2.5, Number.NaN),
          columnFill: clampFloat(step.dataset.particlesMatrixColumnFill, 0.3, 1.8, 1),
          columnAlpha: clampFloat(step.dataset.particlesMatrixColumnAlpha, 0.5, 2.2, 1),
          bgCols: clampInt(step.dataset.particlesMatrixBgCols, 10, 420, 0),
          bgRows: clampInt(step.dataset.particlesMatrixBgRows, 12, 320, 0),
          bgDensity: clampFloat(step.dataset.particlesMatrixBgDensity, 0.2, 2.5, Number.NaN),
          bgColumnDensity: clampFloat(step.dataset.particlesMatrixBgColumnDensity, 0.2, 2.5, Number.NaN),
          bgNoiseDensity: clampFloat(step.dataset.particlesMatrixBgNoiseDensity, 0, 2.5, Number.NaN),
          bgColumnFill: clampFloat(step.dataset.particlesMatrixBgColumnFill, 0.3, 1.8, Number.NaN),
          bgColumnAlpha: clampFloat(step.dataset.particlesMatrixBgColumnAlpha, 0.5, 2.2, Number.NaN),
          bgRowSpacing: clampInt(step.dataset.particlesMatrixBgRowSpacing, 1, 6, 0),
          rowSpacing: clampInt(step.dataset.particlesMatrixRowSpacing, 1, 6, 2),
          fontFamily: step.dataset.particlesMatrixFont || step.dataset.particlesShapeFont || 'monospace',
          fontWeight: clampInt(step.dataset.particlesMatrixWeight, 300, 900, 700),
          fontScale: clampFloat(step.dataset.particlesMatrixFontScale, 0.4, 1.2, 0.68),
          word: step.dataset.particlesMatrixWord,
          wordScale: clampFloat(step.dataset.particlesMatrixWordScale, 0.12, 0.6, 0.26),
          wordRatio: clampFloat(step.dataset.particlesMatrixWordRatio, 0.2, 0.7, 0.45),
          wordDensity: clampFloat(step.dataset.particlesMatrixWordDensity, 0.2, 1.4, 1),
          wordCount: parseInt(step.dataset.particlesMatrixWordCount || '', 10),
          wordWeight: clampInt(step.dataset.particlesMatrixWordWeight, 300, 900, 700),
          wordFont: step.dataset.particlesMatrixWordFont,
          wordImageScale: clampFloat(step.dataset.particlesMatrixWordImageScale, 0.18, 0.8, 0.38),
          wordImageOffsetX: clampFloat(step.dataset.particlesMatrixWordImageOffsetX, -0.4, 0.4, 0),
          wordImageOffsetY: clampFloat(step.dataset.particlesMatrixWordImageOffsetY, -0.4, 0.4, 0),
          wordImageBoost: clampFloat(step.dataset.particlesMatrixWordImageBoost, 1, 10, 6),
          wordImageStep: clampInt(step.dataset.particlesMatrixWordImageStep, 1, 4, 1),
          wordImageStack: step.dataset.particlesMatrixWordImageStack === 'true',
          wordImageAutoSplit: step.dataset.particlesMatrixWordImageAutoSplit === 'true',
          wordImageSplit: clampFloat(step.dataset.particlesMatrixWordImageSplit, -0.25, 0.25, 0),
          wordImageLineWidth: clampFloat(step.dataset.particlesMatrixWordImageLineWidth, 0.35, 0.9, 0.55),
          wordImageLineHeight: clampFloat(step.dataset.particlesMatrixWordImageLineHeight, 0.2, 0.6, 0.32),
          wordImageLineGap: clampFloat(step.dataset.particlesMatrixWordImageLineGap, 0.15, 0.5, 0.26),
          wordLetterGap: clampFloat(step.dataset.particlesMatrixWordLetterGap, 0, 0.2, 0),
          wordLetterGapThreshold: clampFloat(step.dataset.particlesMatrixWordLetterGapThreshold, 0.05, 0.6, 0.22),
          wordParticleScale: clampFloat(step.dataset.particlesMatrixWordParticleScale, 0.5, 1.2, 0.85),
          wordImage,
          threshold: config.shapeThreshold,
          minBrightness: config.shapeMinBrightness,
          depthJitter: config.shapeDepthJitter,
          depthScale: config.shapeDepth,
        };
        return generateMatrixShape(count, matrixOptions);
      }

      if (shapeKey === 'earth') {
        const earthOptions = {
          oceanProbability: clampFloat(step.dataset.particlesEarthOcean, 0, 1, 0.35),
          mapThreshold: clampInt(step.dataset.particlesEarthMapThreshold, 0, 255, 40),
          rimFraction: clampFloat(step.dataset.particlesEarthRim, 0.05, 0.4, 0.22),
          rimThickness: clampFloat(step.dataset.particlesEarthRimThickness, 0.004, 0.15, 0.028),
          radius: clampFloat(step.dataset.particlesEarthRadius, 0.3, 0.7, 0.52),
          surfaceThickness: clampFloat(step.dataset.particlesEarthThickness, 0.004, 0.16, 0.02),
        };
        if (earthMapSrc) {
          const mapImg = await loadImage(earthMapSrc);
          earthOptions.mapData = getImageDataFromImage(mapImg);
        }
        if (earthMapSrc2) {
          const mapImg2 = await loadImage(earthMapSrc2);
          earthOptions.mapData2 = getImageDataFromImage(mapImg2);
        }
        return generateEarthShape(count, earthOptions);
      }

      if (shapeSrc) {
        const img = await loadImage(shapeSrc);
        return sampleImageShape(img, count, options);
      }

      if (shapeSelector) {
        const element = section.querySelector(shapeSelector);
        if (element) {
          const img = await elementToImage(element);
          if (img) {
            return sampleImageShape(img, count, options);
          }
        }
      }

      if (shapeText) {
        return sampleTextShape(shapeText, count, step, options);
      }

      if (shapeKey && generators[shapeKey]) {
        return generators[shapeKey](count);
      }
    } catch (error) {
      return fallback(count);
    }

    return fallback(count);
  });

  return Promise.all(shapePromises);
}

function generateSphereShape(count) {
  const points = new Float32Array(count * 3);
  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    const u = Math.random();
    const v = Math.random();
    const theta = u * Math.PI * 2;
    const phi = Math.acos(2 * v - 1);
    const radius = Math.pow(Math.random(), 0.65) * 0.5;
    const sinPhi = Math.sin(phi);
    points[idx] = radius * sinPhi * Math.cos(theta);
    points[idx + 1] = radius * Math.cos(phi);
    points[idx + 2] = radius * sinPhi * Math.sin(theta) * 0.8;
  }
  return { points, aspect: 1 };
}

function generateRingShape(count) {
  const points = new Float32Array(count * 3);
  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    const angle = Math.random() * Math.PI * 2;
    const tube = Math.random() * Math.PI * 2;
    const ringRadius = 0.32 + Math.random() * 0.12;
    const tubeRadius = 0.06 + Math.random() * 0.08;
    const radius = ringRadius + tubeRadius * Math.cos(tube);
    points[idx] = Math.cos(angle) * radius;
    points[idx + 1] = Math.sin(angle) * radius;
    points[idx + 2] = Math.sin(tube) * tubeRadius * 1.2;
  }
  return { points, aspect: 1 };
}

function generateRibbonShape(count) {
  const points = new Float32Array(count * 3);
  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    const t = Math.random() * Math.PI * 2;
    const y = (Math.random() - 0.5) * 0.9;
    const radius = 0.18 + Math.sin(t * 2) * 0.06;
    points[idx] = Math.cos(t + y * 1.4) * radius * 2.2;
    points[idx + 1] = y;
    points[idx + 2] = Math.sin(t + y * 1.4) * radius;
  }
  return { points, aspect: 1 };
}

function generateBlobShape(count) {
  const points = new Float32Array(count * 3);
  const mask = new Uint8Array(count);
  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    const angle = Math.random() * Math.PI * 2;
    const radius = 0.2 + Math.sin(angle * 3) * 0.08 + Math.random() * 0.12;
    points[idx] = Math.cos(angle) * radius * 1.8;
    points[idx + 1] = Math.sin(angle) * radius * 1.2;
    points[idx + 2] = (Math.random() - 0.5) * 0.2;
  }
  return { points, aspect: 1 };
}

function generateCubeWireShape(count) {
  const points = new Float32Array(count * 3);
  const half = 0.48;
  const vertices = [
    [-half, -half, -half],
    [half, -half, -half],
    [half, half, -half],
    [-half, half, -half],
    [-half, -half, half],
    [half, -half, half],
    [half, half, half],
    [-half, half, half],
  ];
  const edges = [
    [0, 1],
    [1, 2],
    [2, 3],
    [3, 0],
    [4, 5],
    [5, 6],
    [6, 7],
    [7, 4],
    [0, 4],
    [1, 5],
    [2, 6],
    [3, 7],
  ];

  for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
    const edge = edges[Math.floor(Math.random() * edges.length)];
    const a = vertices[edge[0]];
    const b = vertices[edge[1]];
    const t = Math.random();
    const jitterX = (Math.random() - 0.5) * 0.01;
    const jitterY = (Math.random() - 0.5) * 0.01;
    const jitterZ = (Math.random() - 0.5) * 0.01;
    points[idx] = a[0] + (b[0] - a[0]) * t + jitterX;
    points[idx + 1] = a[1] + (b[1] - a[1]) * t + jitterY;
    points[idx + 2] = a[2] + (b[2] - a[2]) * t + jitterZ;
  }

  return { points, aspect: 1, depthScale: 3.2 };
}

function generateCanvasShape(count, draw, options) {
  const size = 640;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return generateBlobShape(count);
  }

  ctx.clearRect(0, 0, size, size);
  ctx.fillStyle = '#ffffff';
  ctx.strokeStyle = '#ffffff';
  draw(ctx, size);

  const result = sampleCanvasShape(canvas, count, options);
  if (options?.depthScale) {
    return { ...result, depthScale: options.depthScale };
  }
  return result;
}

function generateRocketShape(count) {
  const result = generateCanvasShape(
    count,
    (ctx, size) => {
      const cx = size * 0.5;
      const top = size * 0.12;
      const bodyWidth = size * 0.22;
      const noseHeight = size * 0.12;
      const bodyHeight = size * 0.52;
      const bottom = top + noseHeight + bodyHeight;

      ctx.beginPath();
      ctx.moveTo(cx, top);
      ctx.lineTo(cx + bodyWidth * 0.5, top + noseHeight);
      ctx.lineTo(cx - bodyWidth * 0.5, top + noseHeight);
      ctx.closePath();
      ctx.fill();

      ctx.fillRect(cx - bodyWidth * 0.5, top + noseHeight, bodyWidth, bodyHeight);

      const baseRadius = bodyWidth * 0.28;
      ctx.beginPath();
      ctx.arc(cx, bottom + baseRadius * 0.6, baseRadius, 0, Math.PI * 2);
      ctx.fill();

      const finHeight = bodyWidth * 0.7;
      const finWidth = bodyWidth * 0.6;
      ctx.beginPath();
      ctx.moveTo(cx - bodyWidth * 0.5, bottom - bodyWidth * 0.1);
      ctx.lineTo(cx - bodyWidth * 0.5 - finWidth, bottom + finHeight);
      ctx.lineTo(cx - bodyWidth * 0.5, bottom + finHeight * 0.6);
      ctx.closePath();
      ctx.fill();

      ctx.beginPath();
      ctx.moveTo(cx + bodyWidth * 0.5, bottom - bodyWidth * 0.1);
      ctx.lineTo(cx + bodyWidth * 0.5 + finWidth, bottom + finHeight);
      ctx.lineTo(cx + bodyWidth * 0.5, bottom + finHeight * 0.6);
      ctx.closePath();
      ctx.fill();
    },
    { threshold: 6, minBrightness: 4, depthJitter: 0.45, depthScale: 1.2 }
  );
}

function generateMatrixShape(count, options = {}) {
  const glyphsRaw =
    typeof options.glyphs === 'string' && options.glyphs.trim().length > 0
      ? options.glyphs
      : '01ABCDEFGHIJKLMNOPQRSTUVWXYZ$%&*+-';
  const glyphs = glyphsRaw.replace(/\s+/g, '');
  const glyphPool = glyphs.length > 0 ? glyphs : '01';
  const cols = clampInt(options.cols, 10, 420, 48);
  const rows = clampInt(options.rows, 12, 320, 52);
  const density = clampFloat(options.density, 0.2, 2.5, 0.8);
  const columnDensity = Number.isFinite(options.columnDensity) ? options.columnDensity : density;
  const noiseDensity = Number.isFinite(options.noiseDensity) ? options.noiseDensity : density;
  const columnFill = clampFloat(options.columnFill, 0.3, 1.8, 1);
  const columnAlpha = clampFloat(options.columnAlpha, 0.5, 2.2, 1);
  const bgCols = options.bgCols > 0 ? options.bgCols : cols;
  const bgRows = options.bgRows > 0 ? options.bgRows : rows;
  const bgDensity = Number.isFinite(options.bgDensity) ? options.bgDensity : density;
  const bgColumnDensity = Number.isFinite(options.bgColumnDensity) ? options.bgColumnDensity : columnDensity;
  const bgNoiseDensity = Number.isFinite(options.bgNoiseDensity) ? options.bgNoiseDensity : noiseDensity;
  const bgColumnFill = Number.isFinite(options.bgColumnFill) ? options.bgColumnFill : columnFill;
  const bgColumnAlpha = Number.isFinite(options.bgColumnAlpha) ? options.bgColumnAlpha : columnAlpha;
  const bgRowSpacing = options.bgRowSpacing > 0 ? options.bgRowSpacing : options.rowSpacing;
  const fontFamily = options.fontFamily || 'monospace';
  const fontWeight = clampInt(options.fontWeight, 300, 900, 700);
  const fontScale = clampFloat(options.fontScale, 0.4, 1.2, 0.68);
  const wordText = typeof options.word === 'string' ? options.word.trim() : '';
  const wordScale = clampFloat(options.wordScale, 0.12, 0.6, 0.26);
  const wordWeight = clampInt(options.wordWeight, 300, 900, 700);
  const wordFont = options.wordFont || fontFamily;
  const wordImage = options.wordImage || null;
  const hasWordImage = !!wordImage;
  const hasWordText = wordText.length > 0;
  const densityFactor = Number.isFinite(options.wordDensity) ? options.wordDensity : 1;
  const baseWordRatio = hasWordImage || hasWordText
    ? clampFloat(options.wordRatio, 0.2, 0.7, hasWordImage ? 0.45 : 0.25)
    : 0;
  const wordRatio = baseWordRatio > 0
    ? clampFloat(baseWordRatio * densityFactor, 0.15, 0.6, baseWordRatio)
    : 0;
  let wordCount = hasWordImage || hasWordText ? Math.max(140, Math.floor(count * wordRatio)) : 0;
  if (Number.isFinite(options.wordCount) && options.wordCount > 0) {
    wordCount = Math.max(60, Math.min(count - 1, Math.floor(options.wordCount)));
  }
  const bgCount = Math.max(1, count - wordCount);

  const background = generateCanvasShape(
    bgCount,
    (ctx, size) => {
      const colWidth = size / bgCols;
      const rowHeight = size / bgRows;
      const fontSize = Math.max(6, Math.floor(Math.min(colWidth, rowHeight) * fontScale));
      ctx.textAlign = 'center';
      ctx.textBaseline = 'middle';
      ctx.font = `${fontWeight} ${fontSize}px ${fontFamily}`;

      const activeCols = Math.max(1, Math.min(bgCols, Math.floor(bgCols * bgColumnDensity)));
      const columns = new Set();
      while (columns.size < activeCols) {
        columns.add(Math.floor(Math.random() * bgCols));
      }

      const rowSpacing = clampInt(bgRowSpacing, 1, 6, 2);
      columns.forEach((col) => {
        const head = Math.floor(Math.random() * bgRows);
        const length = Math.max(
          6,
          Math.min(bgRows, Math.floor(bgRows * (0.4 + Math.random() * 0.35) * bgColumnFill))
        );
        for (let i = 0; i < length; i += 1) {
          const row = (head + i * rowSpacing) % bgRows;
          const t = length > 1 ? i / (length - 1) : 0;
          const alpha = Math.min(
            1,
            ((1 - t) * 0.85 + 0.12 + (i === 0 ? 0.18 : 0)) * bgColumnAlpha
          );
          const glyph = glyphPool[Math.floor(Math.random() * glyphPool.length)];
          const x = colWidth * (col + 0.5);
          const y = rowHeight * (row + 0.5);
          ctx.globalAlpha = alpha;
          ctx.fillText(glyph, x, y);
        }
      });

      const noiseFactor = Math.max(0, Math.min(1, bgNoiseDensity * 0.08));
      for (let col = 0; col < bgCols; col += 1) {
        for (let row = 0; row < bgRows; row += 1) {
          if (Math.random() > noiseFactor) {
            continue;
          }
          const glyph = glyphPool[Math.floor(Math.random() * glyphPool.length)];
          const x = colWidth * (col + 0.5);
          const y = rowHeight * (row + 0.5);
          ctx.globalAlpha = 0.12 + Math.random() * 0.28;
          ctx.fillText(glyph, x, y);
        }
      }
      ctx.globalAlpha = 1;
    },
    {
      threshold: options.threshold ?? 10,
      minBrightness: options.minBrightness ?? 4,
      depthJitter: options.depthJitter ?? 0.18,
      depthScale: options.depthScale ?? 1.1,
    }
  );

  let wordShape = null;
  if (wordCount > 0 && hasWordImage) {
    const boost = (options.wordImageBoost ?? 6) * densityFactor;
    wordShape = sampleImageShape(wordImage, wordCount, {
      threshold: 1,
      minBrightness: 0,
      depthJitter: (options.depthJitter ?? 0.18) * 0.12,
      mode: 'fill',
      sampleMode: 'even',
      scale: options.wordImageScale ?? 0.38,
      offsetX: options.wordImageOffsetX ?? 0,
      offsetY: options.wordImageOffsetY ?? 0,
      sampleBoost: boost,
      sampleStep: options.wordImageStep ?? 1,
      keepCanvasBounds: true,
    });

    if (wordShape && options.wordImageStack) {
      const pts = wordShape.points;
      let minX = Infinity;
      let maxX = -Infinity;
      let minY = Infinity;
      let maxY = -Infinity;

      for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
        const x = pts[idx];
        const y = pts[idx + 1];
        minX = Math.min(minX, x);
        maxX = Math.max(maxX, x);
        minY = Math.min(minY, y);
        maxY = Math.max(maxY, y);
      }

      const centerX = (minX + maxX) * 0.5;
      const centerY = (minY + maxY) * 0.5;
      for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
        pts[idx] -= centerX;
        pts[idx + 1] -= centerY;
      }
      minX -= centerX;
      maxX -= centerX;
      minY -= centerY;
      maxY -= centerY;

      let split = clampFloat(options.wordImageSplit, -0.25, 0.25, 0);
      if (options.wordImageAutoSplit && maxX > minX) {
        const bins = 48;
        const counts = new Array(bins).fill(0);
        const span = maxX - minX;
        for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
          const x = pts[idx];
          const t = (x - minX) / span;
          const b = Math.max(0, Math.min(bins - 1, Math.floor(t * bins)));
          counts[b] += 1;
        }
        const start = Math.floor(bins * 0.3);
        const end = Math.floor(bins * 0.7);
        let best = Infinity;
        let bestIndex = -1;
        for (let i = start; i <= end; i += 1) {
          if (counts[i] < best) {
            best = counts[i];
            bestIndex = i;
          }
        }
        if (bestIndex >= 0) {
          split = minX + ((bestIndex + 0.5) / bins) * span;
        }
      }

      let leftMinX = Infinity;
      let leftMaxX = -Infinity;
      let leftMinY = Infinity;
      let leftMaxY = -Infinity;
      let rightMinX = Infinity;
      let rightMaxX = -Infinity;
      let rightMinY = Infinity;
      let rightMaxY = -Infinity;

      for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
        const x = pts[idx];
        const y = pts[idx + 1];
        if (x < split) {
          leftMinX = Math.min(leftMinX, x);
          leftMaxX = Math.max(leftMaxX, x);
          leftMinY = Math.min(leftMinY, y);
          leftMaxY = Math.max(leftMaxY, y);
        } else {
          rightMinX = Math.min(rightMinX, x);
          rightMaxX = Math.max(rightMaxX, x);
          rightMinY = Math.min(rightMinY, y);
          rightMaxY = Math.max(rightMaxY, y);
        }
      }

      if (Number.isFinite(leftMinX) && Number.isFinite(rightMinX)) {
        const leftWidth = Math.max(0.0001, leftMaxX - leftMinX);
        const rightWidth = Math.max(0.0001, rightMaxX - rightMinX);
        const leftHeight = Math.max(0.0001, leftMaxY - leftMinY);
        const rightHeight = Math.max(0.0001, rightMaxY - rightMinY);
        const lineWidth = clampFloat(options.wordImageLineWidth, 0.35, 0.9, 0.55);
        const lineHeight = clampFloat(options.wordImageLineHeight, 0.2, 0.6, 0.32);
        const lineGap = clampFloat(options.wordImageLineGap, 0.15, 0.5, 0.26);
        const offsetX = clampFloat(options.wordImageOffsetX, -0.5, 0.5, 0);
        const offsetY = clampFloat(options.wordImageOffsetY, -0.5, 0.5, 0);

        for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
          const x = pts[idx];
          const y = pts[idx + 1];
          if (x < split) {
            const nx = (x - leftMinX) / leftWidth - 0.5;
            const ny = (y - leftMinY) / leftHeight - 0.5;
            pts[idx] = nx * lineWidth + offsetX;
            pts[idx + 1] = ny * lineHeight + lineGap + offsetY;
          } else {
            const nx = (x - rightMinX) / rightWidth - 0.5;
            const ny = (y - rightMinY) / rightHeight - 0.5;
            pts[idx] = nx * lineWidth + offsetX;
            pts[idx + 1] = ny * lineHeight - lineGap + offsetY;
          }
        }

        const letterGap = clampFloat(options.wordLetterGap, 0, 0.2, 0);
        if (letterGap > 0) {
          const gapThreshold = clampFloat(options.wordLetterGapThreshold, 0.05, 0.6, 0.22);
          const gapSize = lineWidth * letterGap;
          const bins = 64;

          const applyGap = (isTop) => {
            let minLineX = Infinity;
            let maxLineX = -Infinity;
            let count = 0;
            for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
              const y = pts[idx + 1];
              if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
                const x = pts[idx];
                minLineX = Math.min(minLineX, x);
                maxLineX = Math.max(maxLineX, x);
                count += 1;
              }
            }
            if (!Number.isFinite(minLineX) || count < 10) {
              return;
            }
            const span = Math.max(0.0001, maxLineX - minLineX);
            const counts = new Array(bins).fill(0);
            for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
              const y = pts[idx + 1];
              if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
                const x = pts[idx];
                const t = (x - minLineX) / span;
                const b = Math.max(0, Math.min(bins - 1, Math.floor(t * bins)));
                counts[b] += 1;
              }
            }
            const avg = count / bins;
            const threshold = avg * gapThreshold;
            let offset = 0;
            const offsets = new Array(bins).fill(0);
            for (let i = 0; i < bins; i += 1) {
              if (counts[i] < threshold) {
                offset += gapSize;
              }
              offsets[i] = offset;
            }
            const totalOffset = offset;
            for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
              const y = pts[idx + 1];
              if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
                const x = pts[idx];
                const t = (x - minLineX) / span;
                const b = Math.max(0, Math.min(bins - 1, Math.floor(t * bins)));
                pts[idx] = x + offsets[b] - totalOffset * 0.5;
              }
            }
          };

          applyGap(true);
          applyGap(false);
        }
      }
    } else if (wordShape) {
      const letterGap = clampFloat(options.wordLetterGap, 0, 0.2, 0);
      if (letterGap > 0) {
        const gapThreshold = clampFloat(options.wordLetterGapThreshold, 0.05, 0.6, 0.22);
        const pts = wordShape.points;
        const bins = 64;
        const offsetY = 0;

        const applyGap = (isTop) => {
          let minLineX = Infinity;
          let maxLineX = -Infinity;
          let count = 0;
          for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
            const y = pts[idx + 1];
            if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
              const x = pts[idx];
              minLineX = Math.min(minLineX, x);
              maxLineX = Math.max(maxLineX, x);
              count += 1;
            }
          }
          if (!Number.isFinite(minLineX) || count < 10) {
            return;
          }
          const span = Math.max(0.0001, maxLineX - minLineX);
          const gapSize = span * letterGap;
          const counts = new Array(bins).fill(0);
          for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
            const y = pts[idx + 1];
            if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
              const x = pts[idx];
              const t = (x - minLineX) / span;
              const b = Math.max(0, Math.min(bins - 1, Math.floor(t * bins)));
              counts[b] += 1;
            }
          }
          const avg = count / bins;
          const threshold = avg * gapThreshold;
          let offset = 0;
          const offsets = new Array(bins).fill(0);
          for (let i = 0; i < bins; i += 1) {
            if (counts[i] < threshold) {
              offset += gapSize;
            }
            offsets[i] = offset;
          }
          const totalOffset = offset;
          for (let i = 0, idx = 0; i < wordCount; i += 1, idx += 3) {
            const y = pts[idx + 1];
            if ((isTop && y >= offsetY) || (!isTop && y < offsetY)) {
              const x = pts[idx];
              const t = (x - minLineX) / span;
              const b = Math.max(0, Math.min(bins - 1, Math.floor(t * bins)));
              pts[idx] = x + offsets[b] - totalOffset * 0.5;
            }
          }
        };

        applyGap(true);
        applyGap(false);
      }
    }
  } else if (wordText && wordCount > 0) {
    wordShape = generateCanvasShape(
      wordCount,
      (ctx, size) => {
        const wordSize = Math.max(18, Math.floor(size * wordScale));
        const lines = wordText.split(/\s+/).filter(Boolean);
        const lineHeight = wordSize * 1.05;
        const startY = size * 0.5 - ((lines.length - 1) * lineHeight) * 0.5;
        ctx.font = `${wordWeight} ${wordSize}px ${wordFont}`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.globalAlpha = 1;
        ctx.lineWidth = Math.max(2, Math.round(wordSize * 0.06));
        ctx.strokeStyle = '#ffffff';
        lines.forEach((line, index) => {
          const y = startY + index * lineHeight;
          ctx.strokeText(line, size * 0.5, y);
          ctx.fillText(line, size * 0.5, y);
        });
      },
      {
        threshold: 2,
        minBrightness: 0,
        depthJitter: (options.depthJitter ?? 0.18) * 0.35,
      }
    );
  }

  const output = new Float32Array(count * 3);
  const mask = new Uint8Array(count);
  let cursor = 0;
  if (wordShape) {
    output.set(wordShape.points, 0);
    for (let i = 0; i < wordCount; i += 1) {
      mask[i] = 2;
    }
    cursor = wordCount * 3;
  }
  output.set(background.points, cursor);

  const wordAspect = hasWordImage && wordImage
    ? wordImage.width / wordImage.height || 1
    : 1;
  return {
    points: output,
    aspect: Math.max(bgCols / bgRows, wordAspect),
    depthScale: options.depthScale ?? 1.1,
    mask,
  };
}

function generateBlackHoleShape(count, options = {}) {
  const points = new Float32Array(count * 3);
  const mask = new Uint8Array(count);
  const diskInner = clampFloat(options.diskInner, 0.18, 0.7, 0.34);
  let diskOuter = clampFloat(options.diskOuter, 0.25, 1.2, 0.74);
  if (diskOuter <= diskInner) {
    diskOuter = diskInner + 0.12;
  }
  const diskThickness = clampFloat(options.diskThickness, 0.02, 0.25, 0.08);
  const halo = clampFloat(options.halo, 0, 0.6, 0.26);
  const rimFraction = clampFloat(options.rim, 0.05, 0.35, 0.16);
  const tilt = clampFloat(options.tilt, -60, 60, 18) * (Math.PI / 180);
  const sinTilt = Math.sin(tilt);
  const cosTilt = Math.cos(tilt);

  const rimCount = Math.max(1, Math.floor(count * rimFraction));
  let diskCount = Math.max(1, Math.floor(count * 0.6));
  let haloCount = count - rimCount - diskCount;
  if (haloCount < 0) {
    haloCount = 0;
    diskCount = Math.max(1, count - rimCount);
  }

  let filled = 0;
  for (let i = 0; i < rimCount && filled < count; i += 1, filled += 1) {
    const angle = Math.random() * Math.PI * 2;
    const radius = diskInner * (0.92 + Math.random() * 0.08);
    const z = (Math.random() - 0.5) * diskThickness * 0.25;
    let x = Math.cos(angle) * radius;
    let y = Math.sin(angle) * radius;
    const zTilt = y * sinTilt + z * cosTilt;
    y = y * cosTilt - z * sinTilt;
    const idx = filled * 3;
    points[idx] = x;
    points[idx + 1] = y;
    points[idx + 2] = zTilt;
    mask[filled] = 2;
  }

  for (let i = 0; i < diskCount && filled < count; i += 1, filled += 1) {
    const angle = Math.random() * Math.PI * 2;
    const radius = diskInner + (diskOuter - diskInner) * Math.pow(Math.random(), 1.6);
    const thickness = diskThickness * (0.35 + Math.random() * 0.65);
    const z = (Math.random() - 0.5) * thickness;
    let x = Math.cos(angle) * radius;
    let y = Math.sin(angle) * radius;
    const zTilt = y * sinTilt + z * cosTilt;
    y = y * cosTilt - z * sinTilt;
    const idx = filled * 3;
    points[idx] = x;
    points[idx + 1] = y;
    points[idx + 2] = zTilt;
    mask[filled] = 1;
  }

  const haloMin = diskOuter * 0.85;
  const haloMax = diskOuter * (1 + halo);
  for (let i = 0; i < haloCount && filled < count; i += 1, filled += 1) {
    const u = Math.random();
    const v = Math.random();
    const theta = u * Math.PI * 2;
    const phi = Math.acos(2 * v - 1);
    const radius = haloMin + Math.random() * (haloMax - haloMin);
    const sinPhi = Math.sin(phi);
    let x = Math.cos(theta) * sinPhi * radius;
    let y = Math.cos(phi) * radius;
    const z = Math.sin(theta) * sinPhi * radius;
    const zTilt = y * sinTilt + z * cosTilt;
    y = y * cosTilt - z * sinTilt;
    const idx = filled * 3;
    points[idx] = x;
    points[idx + 1] = y;
    points[idx + 2] = zTilt;
  }

  return { points, aspect: 1, depthScale: 2.6, mask };
}

function generateEarthShape(count, options = {}) {
  const points = new Float32Array(count * 3);
  const mask = new Uint8Array(count);
  const oceanProbability = clampFloat(options.oceanProbability, 0, 1, 0.35);
  const mapData = options.mapData;
  const mapData2 = options.mapData2;
  const mapThreshold = clampInt(options.mapThreshold, 0, 255, 40);
  const radiusBase = clampFloat(options.radius, 0.3, 0.7, 0.5);
  const thickness = clampFloat(options.surfaceThickness, 0.005, 0.12, 0.02);
  const rimFraction = clampFloat(options.rimFraction, 0.05, 0.4, 0.22);
  const rimThickness = clampFloat(options.rimThickness, 0.004, 0.15, 0.028);
  const rimCount = Math.min(count, Math.floor(count * rimFraction));

  let filled = 0;
  for (let i = 0; i < rimCount; i += 1) {
    const angle = Math.random() * Math.PI * 2;
    const radial = radiusBase + thickness * 0.9 + (Math.random() - 0.5) * rimThickness;
    const z = (Math.random() - 0.5) * rimThickness * 0.5;
    const idx = filled * 3;
    points[idx] = Math.cos(angle) * radial;
    points[idx + 1] = Math.sin(angle) * radial;
    points[idx + 2] = z;
    mask[filled] = 2;
    filled += 1;
  }

  let attempts = 0;
  const maxAttempts = Math.max(count * 30, 2000);

  while (filled < count && attempts < maxAttempts) {
    attempts += 1;
    const u = Math.random();
    const v = Math.random();
    const theta = u * Math.PI * 2;
    const phi = Math.acos(2 * v - 1);

    const mapLand = mapData ? isEarthMapLand(mapData, u, v, mapThreshold) : false;
    const mapLand2 = mapData2 ? isEarthMapLand(mapData2, u, v, mapThreshold) : false;
    const isLand = mapData || mapData2 ? mapLand || mapLand2 : isEarthNoiseLand(u, v);

    if (!isLand && Math.random() > oceanProbability) {
      continue;
    }

    const radius = radiusBase + (Math.random() - 0.5) * thickness;
    const sinPhi = Math.sin(phi);
    const idx = filled * 3;
    points[idx] = radius * sinPhi * Math.cos(theta);
    points[idx + 1] = radius * Math.cos(phi);
    points[idx + 2] = radius * sinPhi * Math.sin(theta);
    mask[filled] = isLand ? 1 : 0;
    filled += 1;
  }

  if (filled < count) {
    for (let i = filled; i < count; i += 1) {
      const u = Math.random();
      const v = Math.random();
      const theta = u * Math.PI * 2;
      const phi = Math.acos(2 * v - 1);
      const radius = radiusBase + (Math.random() - 0.5) * thickness;
      const sinPhi = Math.sin(phi);
      const idx = i * 3;
      points[idx] = radius * sinPhi * Math.cos(theta);
      points[idx + 1] = radius * Math.cos(phi);
      points[idx + 2] = radius * sinPhi * Math.sin(theta);
      const landFallback = mapData || mapData2
        ? (mapData ? isEarthMapLand(mapData, u, v, mapThreshold) : false) ||
          (mapData2 ? isEarthMapLand(mapData2, u, v, mapThreshold) : false)
        : false;
      mask[i] = landFallback ? 1 : 0;
    }
  }

  return { points, aspect: 1, depthScale: 2.8, mask };
}

function getImageDataFromImage(image) {
  const maxSize = 512;
  const scale = Math.min(1, maxSize / Math.max(image.width, image.height));
  const width = Math.max(1, Math.floor(image.width * scale));
  const height = Math.max(1, Math.floor(image.height * scale));
  const canvas = document.createElement('canvas');
  canvas.width = width;
  canvas.height = height;
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) {
    return null;
  }
  ctx.clearRect(0, 0, width, height);
  ctx.drawImage(image, 0, 0, width, height);
  const data = ctx.getImageData(0, 0, width, height).data;
  return { data, width, height };
}

function isEarthMapLand(mapData, u, v, threshold) {
  if (!mapData) {
    return false;
  }
  const x = Math.min(mapData.width - 1, Math.max(0, Math.floor(u * mapData.width)));
  const y = Math.min(mapData.height - 1, Math.max(0, Math.floor(v * mapData.height)));
  const idx = (y * mapData.width + x) * 4;
  const alpha = mapData.data[idx + 3];
  if (alpha < threshold) {
    return false;
  }
  const brightness = (mapData.data[idx] + mapData.data[idx + 1] + mapData.data[idx + 2]) / 3;
  return brightness >= threshold;
}

function isEarthNoiseLand(u, v) {
  const n1 = fbm(u * 3.2, v * 2.8);
  const n2 = fbm(u * 7.4, v * 6.1);
  const n = n1 * 0.7 + n2 * 0.3;
  const lat = Math.abs(v - 0.5) * 2;
  const latMask = 1 - lat * lat;
  return n * latMask > 0.38;
}

function fbm(x, y) {
  let value = 0;
  let amplitude = 0.55;
  let frequency = 1;
  for (let i = 0; i < 4; i += 1) {
    value += valueNoise(x * frequency, y * frequency) * amplitude;
    amplitude *= 0.5;
    frequency *= 2;
  }
  return value;
}

function valueNoise(x, y) {
  const xi = Math.floor(x);
  const yi = Math.floor(y);
  const xf = x - xi;
  const yf = y - yi;
  const r00 = hash2(xi, yi);
  const r10 = hash2(xi + 1, yi);
  const r01 = hash2(xi, yi + 1);
  const r11 = hash2(xi + 1, yi + 1);
  const u = smoothstep(xf);
  const v = smoothstep(yf);
  const x1 = lerp(r00, r10, u);
  const x2 = lerp(r01, r11, u);
  return lerp(x1, x2, v);
}

function hash2(x, y) {
  const n = Math.sin(x * 127.1 + y * 311.7) * 43758.5453123;
  return n - Math.floor(n);
}

function lerp(a, b, t) {
  return a + (b - a) * t;
}

function loadImage(src) {
  return new Promise((resolve, reject) => {
    const img = new Image();
    img.crossOrigin = 'anonymous';
    img.onload = () => resolve(img);
    img.onerror = reject;
    img.src = src;
  });
}

async function elementToImage(element) {
  if (element.tagName === 'IMG') {
    const src = element.currentSrc || element.src;
    if (!src) {
      return null;
    }
    return loadImage(src);
  }

  if (element.tagName === 'SVG') {
    const serializer = new XMLSerializer();
    const markup = serializer.serializeToString(element);
    const src = `data:image/svg+xml;charset=utf-8,${encodeURIComponent(markup)}`;
    return loadImage(src);
  }

  return null;
}

function sampleTextShape(text, count, step, options) {
  const size = 640;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return generateBlobShape(count);
  }

  const fontFamily = step.dataset.particlesShapeFont || 'poppins, Arial, sans-serif';
  ctx.clearRect(0, 0, size, size);
  ctx.fillStyle = '#ffffff';
  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.font = `700 ${Math.round(size * 0.4)}px ${fontFamily}`;
  ctx.fillText(text, size / 2, size / 2);

  return sampleCanvasShape(canvas, count, options);
}

function sampleImageShape(image, count, options) {
  const size = 640;
  const canvas = document.createElement('canvas');
  canvas.width = size;
  canvas.height = size;
  const ctx = canvas.getContext('2d');
  if (!ctx) {
    return generateBlobShape(count);
  }

  ctx.clearRect(0, 0, size, size);
  const aspect = image.width / image.height || 1;
  let drawWidth = size;
  let drawHeight = size;
  if (aspect > 1) {
    drawHeight = size / aspect;
  } else {
    drawWidth = size * aspect;
  }
  const scale = clampFloat(options?.scale, 0.1, 1, 1);
  drawWidth *= scale;
  drawHeight *= scale;
  const offsetX = (size - drawWidth) * 0.5 + size * clampFloat(options?.offsetX, -0.5, 0.5, 0);
  const offsetY = (size - drawHeight) * 0.5 + size * clampFloat(options?.offsetY, -0.5, 0.5, 0);
  ctx.drawImage(image, offsetX, offsetY, drawWidth, drawHeight);

  return sampleCanvasShape(canvas, count, options);
}

function sampleCanvasShape(canvas, count, options) {
  const ctx = canvas.getContext('2d', { willReadFrequently: true });
  if (!ctx) {
    return generateBlobShape(count);
  }

  const { width, height } = canvas;
  const data = ctx.getImageData(0, 0, width, height).data;
  const points = [];
  let minX = width;
  let minY = height;
  let maxX = 0;
  let maxY = 0;
  const threshold = clampInt(options?.threshold, 0, 255, 25);
  const edgeThreshold = clampInt(options?.edgeThreshold, 0, 255, 36);
  const minBrightness = clampInt(options?.minBrightness, 0, 255, 8);
  const mode = options?.mode === 'edges' ? 'edges' : 'fill';
  const depthJitter = clampFloat(options?.depthJitter, 0.05, 0.9, 0.32);
  const boost = clampFloat(options?.sampleBoost, 0.5, 10, 1);
  const targetPoints = Math.max(count * (mode === 'edges' ? 4 : 3) * boost, 9000);
  const stepOverride = clampInt(options?.sampleStep, 1, 8, 0);
  const step = stepOverride > 0
    ? stepOverride
    : Math.max(1, Math.floor(Math.sqrt((width * height) / targetPoints)));

  for (let y = 0; y < height; y += step) {
    for (let x = 0; x < width; x += step) {
      const index = (y * width + x) * 4;
      const alpha = data[index + 3];
      if (alpha < threshold) {
        continue;
      }
      const brightness = (data[index] + data[index + 1] + data[index + 2]) / 3;
      if (brightness < minBrightness) {
        continue;
      }

      if (mode === 'edges') {
        const rightIndex = (y * width + Math.min(width - 1, x + 1)) * 4;
        const downIndex = (Math.min(height - 1, y + 1) * width + x) * 4;
        const rightBrightness =
          (data[rightIndex] + data[rightIndex + 1] + data[rightIndex + 2]) / 3;
        const downBrightness =
          (data[downIndex] + data[downIndex + 1] + data[downIndex + 2]) / 3;
        const diff = Math.abs(brightness - rightBrightness) + Math.abs(brightness - downBrightness);
        const alphaDiff = Math.abs(alpha - data[rightIndex + 3]) + Math.abs(alpha - data[downIndex + 3]);
        if (diff < edgeThreshold && alphaDiff < edgeThreshold) {
          continue;
        }
      }

      points.push({ x, y });
      if (x < minX) {
        minX = x;
      }
      if (x > maxX) {
        maxX = x;
      }
      if (y < minY) {
        minY = y;
      }
      if (y > maxY) {
        maxY = y;
      }
    }
  }

  if (points.length === 0) {
    return generateBlobShape(count);
  }

  if (options?.keepCanvasBounds) {
    minX = 0;
    minY = 0;
    maxX = width - 1;
    maxY = height - 1;
  }

  const boxWidth = Math.max(1, maxX - minX + 1);
  const boxHeight = Math.max(1, maxY - minY + 1);
  const sampleMode = options?.sampleMode === 'even' ? 'even' : 'random';
  const output = new Float32Array(count * 3);
  if (sampleMode === 'even') {
    if (points.length >= count) {
      const stride = points.length / count;
      const offset = Math.random() * stride;
      for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
        const pickIndex = Math.min(points.length - 1, Math.floor(offset + i * stride));
        const pick = points[pickIndex];
        const nx = (pick.x - minX) / boxWidth - 0.5;
        const ny = 0.5 - (pick.y - minY) / boxHeight;
        output[idx] = nx;
        output[idx + 1] = ny;
        output[idx + 2] = (Math.random() - 0.5) * depthJitter;
      }
    } else {
      for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
        const pick = points[i % points.length];
        const nx = (pick.x - minX) / boxWidth - 0.5;
        const ny = 0.5 - (pick.y - minY) / boxHeight;
        output[idx] = nx;
        output[idx + 1] = ny;
        output[idx + 2] = (Math.random() - 0.5) * depthJitter;
      }
    }
  } else {
    for (let i = 0, idx = 0; i < count; i += 1, idx += 3) {
      const pick = points[Math.floor(Math.random() * points.length)];
      const nx = (pick.x - minX) / boxWidth - 0.5;
      const ny = 0.5 - (pick.y - minY) / boxHeight;
      output[idx] = nx;
      output[idx + 1] = ny;
      output[idx + 2] = (Math.random() - 0.5) * depthJitter;
    }
  }

  return { points: output, aspect: boxWidth / boxHeight || 1 };
}

function clampInt(value, min, max, fallback) {
  const parsed = parseInt(value, 10);
  if (Number.isNaN(parsed)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, parsed));
}

function clampFloat(value, min, max, fallback) {
  const parsed = parseFloat(value);
  if (Number.isNaN(parsed)) {
    return fallback;
  }
  return Math.max(min, Math.min(max, parsed));
}

function smoothstep(value) {
  const t = Math.max(0, Math.min(1, value));
  return t * t * (3 - 2 * t);
}
