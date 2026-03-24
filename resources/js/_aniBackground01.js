import * as THREE from 'three';
import gsap from 'gsap';
import { BokehPass } from 'three/addons/postprocessing/BokehPass.js';

const TAU = Math.PI * 2;
const MOBILE_BP = 768;
const TABLET_BP = 1200;
const CASIO_WEEKDAY_SHORT = Object.freeze(['SU', 'MO', 'TU', 'WE', 'TH', 'FR', 'SA']);
const CASIO_CLASSIC_DEFAULT_LAYOUT = Object.freeze({
  dayRect: { x: 0.08, y: 0.09, width: 0.3, height: 0.2 },
  dateRect: { x: 0.56, y: 0.09, width: 0.36, height: 0.2 },
  timeRect: { x: 0.06, y: 0.36, width: 0.88, height: 0.56 },
});
const BACK_TO_FUTURE_DEFAULT_LAYOUT = Object.freeze({
  rows: [
    { x: 0, y: 0, width: 1, height: 0.158 }, // DESTINATION TIME (futuro)
    { x: 0, y: 0.411, width: 1, height: 0.158 }, // PRESENT TIME (dinamico)
    { x: 0, y: 0.842, width: 1, height: 0.158 }, // LAST TIME DEPARTED (pasado)
  ],
  columns: {
    monthRect: { x: 0.001, y: 0, width: 0.176, height: 1 },
    dayRect: { x: 0.216, y: 0, width: 0.112, height: 1 },
    yearRect: { x: 0.365, y: 0, width: 0.241, height: 1 },
    ampmRect: { x: 0.657, y: 0.03, width: 0.08, height: 0.94 },
    hourRect: { x: 0.771, y: 0, width: 0.111, height: 1 },
    minuteRect: { x: 0.905, y: 0, width: 0.095, height: 1 },
    colonRect: { x: 0.892, y: 0.2, width: 0.009, height: 0.6 },
  },
  periodDots: {
    am: { x: 0.7148, y: 0.4842 },
    pm: { x: 0.7148, y: 0.6007 },
  },
});
const BACK_TO_FUTURE_STATIC = Object.freeze({
  future: { year: 2026, month: 9, day: 13, hour24: 10, minute: 0 },
  past: { year: 2022, month: 9, day: 13, hour24: 7, minute: 0 },
});
// Config global del recurso por clase .aniBackground01.
// Rangos recomendados:
// - countDesktop/countTablet/countMobile: 8 - 500
// - digitalRatio: 0 - 0.45
// - opacityMin: 0.2 - 1
// - opacityMax: 0.22 - 1
// - sizeDesktop/sizeTablet/sizeMobile: 0.6 - 2.4
// - sizeFactor: 0.7 - 1.35 (multiplicador global para pruebas rápidas)
// - pointerRadiusFactor: 0.6 - 2.4 (radio de influencia del raton)
// - pointerForceFactor: 0.4 - 3.5 (intensidad del empuje tipo particulas)
// - pointerLerp: 0.08 - 0.5 (velocidad de reaccion hacia/desde el raton)
// - hoverRepel: true/false (empuje por hover sin click)
// - physicsSpring: 0.005 - 0.12 (atraccion inercial al objetivo)
// - physicsDamping: 0.7 - 0.98 (amortiguacion de velocidad)
// - collisionBounce: 0 - 1.4 (rebote entre relojes)
// - collisionRadiusFactor: 0.14 - 0.42 (radio de colision relativo al tamano)
// - maxVelocity: 12 - 120 (limite de velocidad por frame normalizado)
// - enableDragThrow: true/false (arrastrar reloj y soltar con inercia)
// - dragInertiaBoost: 0.4 - 2.4 (multiplicador del impulso al soltar)
// - throwFreeFlightSeconds: 0.2 - 12 (tiempo sin "goma elastica" tras soltar)
// - throwDamping: 0.88 - 0.998 (frenado inercial del reloj lanzado)
// - dofEnabled: true/false (activa profundidad de campo)
// - dofFocusZ: -300 - 300 (plano de foco en Z del mundo)
// - dofAperture: 0.000005 - 0.002 (intensidad del desenfoque)
// - dofMaxBlur: 0.001 - 0.02 (tope de blur)
// - tiltMaxDeg: 0 - 40
// Puedes sobrescribir desde JS con:
// - initAniBackground01({ ... })
// - window.aniBackground01Config = { ... } (antes de inicializar)
const ANI_BACKGROUND01_DEFAULTS = Object.freeze({
  countDesktop: 500,
  countTablet: 180,
  countMobile: 96,
  digitalRatio: 0.12,
  opacityMin: 1,
  opacityMax: 1,
  sizeDesktop: 1,
  sizeTablet: 1,
  sizeMobile: 1.22,
  sizeFactor: 1,
  pointerRadiusFactor: 1.35,
  pointerForceFactor: 1.9,
  pointerLerp: 0.3,
  hoverRepel: false,
  physicsSpring: 0.03,
  physicsDamping: 0.86,
  collisionBounce: 0.62,
  collisionRadiusFactor: 0.26,
  maxVelocity: 54,
  enableDragThrow: true,
  dragInertiaBoost: 1.35,
  throwFreeFlightSeconds: 2.8,
  throwDamping: 0.94,
  dofEnabled: true,
  dofFocusZ: 120,
  dofAperture: 0.00004,
  dofMaxBlur: 0.006,
  tiltMaxDeg: 40,
  maxClocks: 500,
});

export default function initAniBackground01(config = {}) {
  const roots = Array.from(document.querySelectorAll('.aniBackground01'));
  if (roots.length === 0) {
    return;
  }

  const sharedConfig = resolveAniBackground01Config(config);
  roots.forEach((root) => mountAniBackground01(root, sharedConfig));
}

function mountAniBackground01(root, config = ANI_BACKGROUND01_DEFAULTS) {
  if (typeof root._aniBackgroundCleanup === 'function') {
    root._aniBackgroundCleanup();
  }

  const canvas = ensureBackgroundCanvas(root);
  if (!canvas) {
    return;
  }

  const prefersReducedMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  const tier = getViewportTier(window.innerWidth);
  const settings = buildSettings(tier, prefersReducedMotion, config);

  const renderer = new THREE.WebGLRenderer({
    canvas,
    alpha: true,
    antialias: tier === 'desktop',
    powerPreference: 'high-performance',
  });
  renderer.setClearColor(0x000000, 0);
  renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, tier === 'mobile' ? 1.2 : 1.6));

  const scene = new THREE.Scene();
  const camera = new THREE.OrthographicCamera(-1, 1, 1, -1, 0.1, 2000);
  camera.position.z = 600;

  const world = new THREE.Group();
  scene.add(world);

  const layerGroups = [new THREE.Group(), new THREE.Group(), new THREE.Group()];
  layerGroups.forEach((layer) => world.add(layer));
  let dofState = null;
  if (settings.dofEnabled) {
    try {
      const bokehPass = new BokehPass(scene, camera, {
        focus: Math.max(1, camera.position.z - settings.dofFocusZ),
        aperture: settings.dofAperture,
        maxblur: settings.dofMaxBlur,
      });
      // BokehShader viene en modo perspectiva por defecto; para esta escena ortografica lo desactivamos.
      bokehPass.materialBokeh.defines.PERSPECTIVE_CAMERA = 0;
      bokehPass.materialBokeh.needsUpdate = true;
      const colorTarget = new THREE.WebGLRenderTarget(1, 1, {
        minFilter: THREE.LinearFilter,
        magFilter: THREE.LinearFilter,
        type: THREE.HalfFloatType,
      });
      dofState = {
        bokehPass,
        colorTarget,
        depthMaterialCache: new WeakMap(),
        swappedMeshes: [],
        hiddenObjects: [],
      };
    } catch (_) {
      dofState = null;
    }
  }

  const clockColor = parseColorVar(root, '--ani-clock-color', '#dde8ff');
  const accentColor = parseColorVar(root, '--ani-clock-accent', '#ffffff');

  const analogClocks = [];
  const digitalClocks = [];
  const clockMeta = [];
  const clockMetaByLayer = [[], [], []];
  const caseTextureLoader = new THREE.TextureLoader();
  const caseTextureCache = new Map();
  const disposables = {
    geometries: new Set(),
    materials: new Set(),
    textures: new Set(),
    tweens: [],
  };
  const collisionViewport = {
    minX: 0,
    maxX: 0,
    minY: 0,
    maxY: 0,
    padding: 0,
  };

  populateClocks();
  applyViewportSize();
  playIntro();

  let frameId = 0;
  let lastSecond = -1;
  let lastFrameTime = window.performance.now();
  let continuousAngles = null;

  const pointerState = {
    active: false,
    x: 0,
    y: 0,
    targetX: 0,
    targetY: 0,
    radius:
      (tier === 'mobile' ? 90 : tier === 'tablet' ? 130 : 180) * settings.pointerRadiusFactor,
  };
  const dragState = {
    active: false,
    pointerId: null,
    meta: null,
    offsetX: 0,
    offsetY: 0,
    lastX: 0,
    lastY: 0,
    lastTime: 0,
  };

  const renderWithDof = () => {
    if (!dofState) {
      renderer.render(scene, camera);
      return;
    }

    const { bokehPass, colorTarget, depthMaterialCache, swappedMeshes, hiddenObjects } = dofState;
    swappedMeshes.length = 0;
    hiddenObjects.length = 0;

    const getDepthMaterial = (sourceMaterial) => {
      const baseMaterial = Array.isArray(sourceMaterial) ? sourceMaterial[0] : sourceMaterial;
      if (!baseMaterial) {
        return null;
      }
      if (depthMaterialCache.has(baseMaterial)) {
        return depthMaterialCache.get(baseMaterial);
      }
      const depthMaterial = new THREE.MeshDepthMaterial({
        depthPacking: THREE.RGBADepthPacking,
        blending: THREE.NoBlending,
      });
      depthMaterial.side = baseMaterial.side ?? THREE.DoubleSide;
      depthMaterial.alphaTest = Math.max(baseMaterial.alphaTest || 0, 0.02);
      depthMaterial.map = baseMaterial.map || null;
      depthMaterial.alphaMap = baseMaterial.alphaMap || null;
      depthMaterial.transparent = false;
      depthMaterial.depthTest = true;
      depthMaterial.depthWrite = true;
      depthMaterialCache.set(baseMaterial, depthMaterial);
      disposables.materials.add(depthMaterial);
      return depthMaterial;
    };

    renderer.setRenderTarget(colorTarget);
    renderer.clear();
    renderer.render(scene, camera);

    scene.traverse((object3D) => {
      if (!object3D.visible) {
        return;
      }
      if (object3D.isMesh) {
        const depthMaterial = getDepthMaterial(object3D.material);
        if (depthMaterial) {
          swappedMeshes.push({ object3D, material: object3D.material });
          object3D.material = depthMaterial;
        }
        return;
      }
      // Oculta temporalmente lineas/sprites para que no contaminen el depth target.
      if (object3D.isLine || object3D.isPoints || object3D.isSprite) {
        hiddenObjects.push(object3D);
        object3D.visible = false;
      }
    });

    renderer.setRenderTarget(bokehPass.renderTargetDepth);
    renderer.clear();
    renderer.render(scene, camera);

    for (let i = 0; i < swappedMeshes.length; i++) {
      const entry = swappedMeshes[i];
      entry.object3D.material = entry.material;
    }
    for (let i = 0; i < hiddenObjects.length; i++) {
      hiddenObjects[i].visible = true;
    }

    bokehPass.uniforms.tColor.value = colorTarget.texture;
    bokehPass.uniforms.nearClip.value = camera.near;
    bokehPass.uniforms.farClip.value = camera.far;
    renderer.setRenderTarget(null);
    renderer.clear();
    bokehPass.fsQuad.render(renderer);
  };

  const animate = () => {
    frameId = window.requestAnimationFrame(animate);
    const frameNow = window.performance.now();
    const deltaTime = Math.min(0.05, (frameNow - lastFrameTime) / 1000 || 0.016);
    lastFrameTime = frameNow;

    const now = new Date();
    const seconds = now.getSeconds() + now.getMilliseconds() / 1000;
    const minutes = now.getMinutes() + seconds / 60;
    const hours = (now.getHours() % 12) + minutes / 60;
    const rawSecondAngle = -((seconds / 60) * TAU);
    const rawMinuteAngle = -((minutes / 60) * TAU);
    const rawHourAngle = -((hours / 12) * TAU);
    if (!continuousAngles) {
      continuousAngles = {
        second: rawSecondAngle,
        minute: rawMinuteAngle,
        hour: rawHourAngle,
      };
    } else {
      continuousAngles.second = unwrapClockAngle(continuousAngles.second, rawSecondAngle);
      continuousAngles.minute = unwrapClockAngle(continuousAngles.minute, rawMinuteAngle);
      continuousAngles.hour = unwrapClockAngle(continuousAngles.hour, rawHourAngle);
    }
    const secondAngle = continuousAngles.second;
    const minuteAngle = continuousAngles.minute;
    const hourAngle = continuousAngles.hour;
    const timeNow = window.performance.now() * 0.001;

    analogClocks.forEach((clock) => {
      if (clock.hourTo && clock.minuteTo && clock.secondTo) {
        clock.hourTo(hourAngle);
        clock.minuteTo(minuteAngle);
        clock.secondTo(secondAngle);
        return;
      }

      clock.hourPivot.rotation.z = hourAngle;
      clock.minutePivot.rotation.z = minuteAngle;
      clock.secondPivot.rotation.z = secondAngle;
    });

    if (now.getSeconds() !== lastSecond) {
      const blinkColon = now.getSeconds() % 2 === 0;
      digitalClocks.forEach((clock) => {
        const updateType = drawDigitalTime(clock, now, blinkColon);
        const shouldPulse = clock.pulseOnDigits ?? !clock.useSeconds;
        if (updateType === 'digits' && shouldPulse) {
          pulseDigitalClock(clock);
        }
      });
      lastSecond = now.getSeconds();
    }

    if (!settings.reducedMotion) {
      const frameScale = THREE.MathUtils.clamp(deltaTime * 60, 0.6, 1.6);
      pointerState.x += (pointerState.targetX - pointerState.x) * settings.pointerLerp;
      pointerState.y += (pointerState.targetY - pointerState.y) * settings.pointerLerp;

      clockMeta.forEach((meta) => {
        meta.fallY -= meta.fallSpeed * deltaTime;
        if (meta.fallY < meta.rainMinY) {
          meta.fallY = meta.rainMaxY + randomBetween(0, meta.rainWrapJitter);
          meta.y = meta.fallY;
          meta.x = meta.baseX;
          meta.vx *= 0.35;
          meta.vy *= 0.35;
        }

        const wobbleX = Math.sin(timeNow * meta.motionSpeed + meta.motionPhase) * meta.driftX;
        const wobbleY = Math.cos(timeNow * meta.motionSpeed * 0.84 + meta.motionPhase) * meta.driftY;

        const targetX = meta.baseX + wobbleX;
        const targetY = meta.fallY + wobbleY;

        if (meta.isDragging) {
          meta.x = meta.dragTargetX;
          meta.y = meta.dragTargetY;
          // Mantiene continuidad vertical al soltar y evita saltos bruscos.
          meta.fallY = meta.y - wobbleY;
          meta.baseX = meta.x - wobbleX;
          meta.baseY = meta.fallY;
          meta.repelX *= 0.7;
          meta.repelY *= 0.7;
          return;
        }

        if (meta.freeFlightTime > 0) {
          meta.freeFlightTime = Math.max(0, meta.freeFlightTime - deltaTime);
          const flightDamping = Math.pow(settings.throwDamping, frameScale);
          meta.vx *= flightDamping;
          meta.vy *= flightDamping;

          meta.x += meta.vx * frameScale;
          meta.y += meta.vy * frameScale;

          // Reancla la trayectoria para que al terminar la inercia no vuelva "con goma elastica".
          meta.fallY = meta.y - wobbleY;
          meta.baseX = meta.x - wobbleX;
          meta.baseY = meta.fallY;
          meta.repelX *= 0.85;
          meta.repelY *= 0.85;
          return;
        }

        let pointerForceX = 0;
        let pointerForceY = 0;
        if (settings.hoverRepel && pointerState.active) {
          const dx = targetX - pointerState.x;
          const dy = targetY - pointerState.y;
          const distance = Math.hypot(dx, dy);
          if (distance < pointerState.radius) {
            const safeDistance = Math.max(distance, 0.001);
            const influence = 1 - safeDistance / pointerState.radius;
            const push = influence * influence * meta.repelForce;
            pointerForceX = (dx / safeDistance) * push;
            pointerForceY = (dy / safeDistance) * push;
          }
        }

        meta.repelX += (pointerForceX - meta.repelX) * settings.pointerLerp;
        meta.repelY += (pointerForceY - meta.repelY) * settings.pointerLerp;

        const desiredX = targetX + meta.repelX;
        const desiredY = targetY + meta.repelY;
        const spring = settings.physicsSpring * frameScale;
        const damping = Math.pow(settings.physicsDamping, frameScale);

        meta.vx += (desiredX - meta.x) * spring;
        meta.vy += (desiredY - meta.y) * spring;
        meta.vx *= damping;
        meta.vy *= damping;

        const speed = Math.hypot(meta.vx, meta.vy);
        if (speed > settings.maxVelocity) {
          const clamp = settings.maxVelocity / Math.max(speed, 0.001);
          meta.vx *= clamp;
          meta.vy *= clamp;
        }

        meta.x += meta.vx * frameScale;
        meta.y += meta.vy * frameScale;
      });

      resolveClockCollisions();

      clockMeta.forEach((meta) => {
        meta.group.position.x = meta.x;
        meta.group.position.y = meta.y;
        const wobbleRotation =
          meta.baseRotation +
          Math.sin(timeNow * meta.motionSpeed * 0.42 + meta.motionPhase) * meta.rotationRange;
        meta.group.rotation.z = THREE.MathUtils.clamp(
          wobbleRotation,
          -settings.tiltMaxRad,
          settings.tiltMaxRad
        );
      });
    }

    renderWithDof();
  };

  animate();

  let resizeTimer = 0;
  const handleResize = () => {
    window.clearTimeout(resizeTimer);
    resizeTimer = window.setTimeout(() => {
      mountAniBackground01(root, config);
    }, 150);
  };
  window.addEventListener('resize', handleResize, { passive: true });

  const pointerHandlers = setupPointerRainRepel();

  root._aniBackgroundCleanup = () => {
    window.cancelAnimationFrame(frameId);
    window.clearTimeout(resizeTimer);
    window.removeEventListener('resize', handleResize);

    if (pointerHandlers) {
      if (dragState.active && dragState.pointerId !== null) {
        try {
          root.releasePointerCapture(dragState.pointerId);
        } catch (_) {}
      }
      root.removeEventListener('pointerdown', pointerHandlers.onDown);
      root.removeEventListener('pointermove', pointerHandlers.onMove);
      root.removeEventListener('pointerleave', pointerHandlers.onLeave);
      root.removeEventListener('pointerup', pointerHandlers.onUp);
      root.removeEventListener('pointercancel', pointerHandlers.onCancel);
      window.removeEventListener('pointerup', pointerHandlers.onWindowUp);
    }

    analogClocks.forEach((clock) => {
      clock.hourTo?.tween?.kill();
      clock.minuteTo?.tween?.kill();
      clock.secondTo?.tween?.kill();
    });

    digitalClocks.forEach((clock) => {
      if (clock.pulseTween) {
        clock.pulseTween.kill();
      }
      if (clock.alphaTween) {
        clock.alphaTween.kill();
      }
    });

    disposables.tweens.forEach((tween) => tween.kill());
    disposables.geometries.forEach((geometry) => geometry.dispose());
    disposables.materials.forEach((material) => material.dispose());
    disposables.textures.forEach((texture) => texture.dispose());
    if (dofState) {
      dofState.colorTarget.dispose();
      dofState.bokehPass.dispose();
      dofState.depthMaterialCache = new WeakMap();
    }
    renderer.dispose();
    renderer.forceContextLoss();

    while (scene.children.length > 0) {
      scene.remove(scene.children[0]);
    }

    delete root._aniBackgroundCleanup;
  };

  function applyViewportSize() {
    const width = Math.max(root.clientWidth, 1);
    const height = Math.max(root.clientHeight, 1);
    const edgeDeadZoneX = width * 0.06;
    const edgeDeadZoneY = height * 0.06;

    collisionViewport.minX = -width * 0.5 + edgeDeadZoneX;
    collisionViewport.maxX = width * 0.5 - edgeDeadZoneX;
    collisionViewport.minY = -height * 0.5 + edgeDeadZoneY;
    collisionViewport.maxY = height * 0.5 - edgeDeadZoneY;
    collisionViewport.padding = Math.max(20, Math.min(width, height) * 0.035);

    renderer.setSize(width, height, false);
    camera.left = -width / 2;
    camera.right = width / 2;
    camera.top = height / 2;
    camera.bottom = -height / 2;
    camera.aspect = width / height;
    camera.updateProjectionMatrix();
    if (dofState) {
      const pixelRatio = renderer.getPixelRatio();
      const targetWidth = Math.max(1, Math.round(width * pixelRatio));
      const targetHeight = Math.max(1, Math.round(height * pixelRatio));
      dofState.colorTarget.setSize(targetWidth, targetHeight);
      dofState.bokehPass.setSize(targetWidth, targetHeight);
      dofState.bokehPass.uniforms.focus.value = Math.max(1, camera.position.z - settings.dofFocusZ);
      dofState.bokehPass.uniforms.aperture.value = settings.dofAperture;
      dofState.bokehPass.uniforms.maxblur.value = settings.dofMaxBlur;
    }

    clockMeta.forEach((meta) => {
      const xSpan = width * 0.9;
      const ySpan = height * 1.04;
      meta.baseX = meta.anchorX * xSpan;
      meta.baseY = meta.anchorY * ySpan;
      meta.rainMinY = -ySpan - meta.rainBuffer;
      meta.rainMaxY = ySpan + meta.rainBuffer;
      meta.fallY = meta.baseY;
      meta.x = meta.baseX;
      meta.y = meta.fallY;
      meta.vx = 0;
      meta.vy = 0;
      meta.isDragging = false;
      meta.dragTargetX = meta.x;
      meta.dragTargetY = meta.y;
      meta.freeFlightTime = 0;
      meta.group.position.x = meta.x;
      meta.group.position.y = meta.y;
    });
  }

  function populateClocks() {
    const analogPresets = expandPresetVariants(getAnalogPresets(), [0.74, 1, 1.24]);
    const digitalPresets = expandPresetVariants(getDigitalPresets(), [0.82, 1, 1.18]);
    const analogQueue = buildBalancedPresetQueue(settings.clockCount, analogPresets);
    const allowDigital = digitalPresets.length > 0 && settings.digitalRatio > 0;

    if (analogQueue.length === 0 && !allowDigital) {
      return;
    }

    const layerConfig = [
      {
        z: -120,
        zJitter: 20,
        sizeMin: 42,
        sizeMax: 92,
        driftMin: 0.8,
        driftMax: 2.2,
        rotation: 0.02,
        opacityMul: 1,
        fallMin: 10,
        fallMax: 22,
      },
      {
        z: -20,
        zJitter: 18,
        sizeMin: 72,
        sizeMax: 154,
        driftMin: 1.2,
        driftMax: 3.0,
        rotation: 0.035,
        opacityMul: 1,
        fallMin: 18,
        fallMax: 34,
      },
      {
        z: 80,
        zJitter: 16,
        sizeMin: 112,
        sizeMax: 238,
        driftMin: 1.8,
        driftMax: 4.6,
        rotation: 0.05,
        opacityMul: 1,
        fallMin: 28,
        fallMax: 52,
      },
    ];

    for (let i = 0; i < settings.clockCount; i++) {
      const layerIndex = pickLayerIndex();
      const layer = layerConfig[layerIndex];
      const anchor = randomAnchor();
      const useDigital = allowDigital && Math.random() < settings.digitalRatio;
      const preset = useDigital
        ? pickPresetWeighted(digitalPresets)
        : analogQueue[i] || analogQueue[i % analogQueue.length] || pickPresetWeighted(analogPresets);

      if (!preset) {
        continue;
      }

      const alpha = randomBetween(settings.opacityMin, settings.opacityMax) * layer.opacityMul;
      const ink = clockColor.clone().lerp(accentColor, Math.random() * 0.18);
      const accent = accentColor.clone().lerp(clockColor, Math.random() * 0.26);

      const clock = useDigital
        ? buildDigitalClock(preset, ink, accent, alpha)
        : buildAnalogClock(preset, ink, accent, alpha);

      const sizeScale = tier === 'mobile' ? 0.72 : tier === 'tablet' ? 0.86 : 1;
      const presetBoost = preset.sizeBoost || 1;
      let pixelSize = randomBetween(layer.sizeMin, layer.sizeMax) * sizeScale * presetBoost * settings.sizeMultiplier;
      const viewportRatio =
        tier === 'mobile'
          ? preset.minViewportWidthRatioMobile ?? preset.minViewportWidthRatio ?? 0
          : tier === 'tablet'
            ? preset.minViewportWidthRatioTablet ?? preset.minViewportWidthRatio ?? 0
            : preset.minViewportWidthRatioDesktop ?? preset.minViewportWidthRatio ?? 0;

      if (viewportRatio > 0) {
        const viewportWidth = Math.max(root.clientWidth, window.innerWidth || 0, 1);
        const widthUnits = Math.max(preset.caseImageWidth || preset.width || 1, 0.1);
        const requiredScale = (viewportWidth * viewportRatio) / widthUnits;
        pixelSize = Math.max(pixelSize, requiredScale);
      }
      clock.group.scale.set(pixelSize, pixelSize, 1);
      // Profundidad ligada al tamano: mas pequeno = mas lejos (mas blur), mas grande = mas cerca (en foco).
      const depthSizeMin =
        (tier === 'mobile' ? 38 : tier === 'tablet' ? 52 : 58) * settings.sizeMultiplier;
      const depthSizeMax =
        (tier === 'mobile' ? 150 : tier === 'tablet' ? 190 : 250) * settings.sizeMultiplier;
      const depthT = THREE.MathUtils.clamp(
        (pixelSize - depthSizeMin) / Math.max(depthSizeMax - depthSizeMin, 1),
        0,
        1
      );
      const nearZ = 130;
      const farZ = -260;
      const zBySize = THREE.MathUtils.lerp(farZ, nearZ, depthT);
      const zJitter = THREE.MathUtils.lerp(18, 8, depthT);
      clock.group.position.z = zBySize + randomBetween(-zJitter, zJitter);

      layerGroups[layerIndex].add(clock.group);

      const meta = {
        group: clock.group,
        layerIndex,
        anchorX: anchor.x,
        anchorY: anchor.y,
        baseX: 0,
        baseY: 0,
        motionSpeed: randomBetween(0.35, 0.9),
        motionPhase: Math.random() * TAU,
        driftX: randomBetween(layer.driftMin, layer.driftMax),
        driftY: randomBetween(layer.driftMin, layer.driftMax) * 0.8,
        rotationRange: randomBetween(0.003, layer.rotation),
        baseRotation: randomBetween(-settings.tiltMaxRad, settings.tiltMaxRad),
        fallSpeed: randomBetween(layer.fallMin, layer.fallMax),
        rainBuffer: randomBetween(20, 120),
        rainWrapJitter: randomBetween(10, 90),
        fallY: 0,
        rainMinY: 0,
        rainMaxY: 0,
        x: 0,
        y: 0,
        vx: randomBetween(-0.18, 0.18),
        vy: randomBetween(-0.18, 0.18),
        radius: Math.max(10, pixelSize * settings.collisionRadiusFactor),
        mass: Math.max(0.55, pixelSize * 0.012),
        isDragging: false,
        dragTargetX: 0,
        dragTargetY: 0,
        freeFlightTime: 0,
        repelForce: randomBetween(24, 68) * settings.pointerForceFactor,
        repelX: 0,
        repelY: 0,
      };
      clockMeta.push(meta);
      clockMetaByLayer[layerIndex].push(meta);

      if (clock.type === 'analog') {
        analogClocks.push(clock);
      } else {
        digitalClocks.push(clock);
      }
    }
  }

  function buildAnalogClock(preset, ink, accent, alpha) {
    const group = new THREE.Group();
    const handInk = preset.handColor ? new THREE.Color(preset.handColor) : ink;
    const handAccent = new THREE.Color(preset.secondHandColor || '#000000');

    const strokeMaterial = createLineMaterial(ink, alpha);
    const detailMaterial = createLineMaterial(accent, Math.min(alpha * 1.05, 1));
    const fillMaterial = createFillMaterial(ink, Math.min(alpha * 1.05, 1));

    const hasImageCase = addCaseImage(group, preset, alpha);
    if (!hasImageCase) {
      addCaseShape(group, preset, strokeMaterial, fillMaterial);
      addTickMarks(group, preset, detailMaterial);
      addAnalogExtras(group, preset, detailMaterial);
    }

    const hourPivot = new THREE.Group();
    const minutePivot = new THREE.Group();
    const secondPivot = new THREE.Group();
    hourPivot.position.z = 0.0012;
    minutePivot.position.z = 0.0024;
    secondPivot.position.z = 0.0036;

    hourPivot.add(createClockHand(preset, 'hour', handInk, handAccent, alpha));
    minutePivot.add(createClockHand(preset, 'minute', handInk, handAccent, alpha));
    secondPivot.add(createClockHand(preset, 'second', handInk, handAccent, alpha));

    group.add(hourPivot);
    group.add(minutePivot);
    group.add(secondPivot);
    const centerColor = new THREE.Color(preset.centerColor || '#000000');
    const centerDot = createCenterDot(centerColor, Math.min(alpha * 1.08, 1), preset.centerRadius);
    centerDot.position.z = 0.0048;
    group.add(centerDot);

    const analogClock = { type: 'analog', group, hourPivot, minutePivot, secondPivot };

    if (!settings.reducedMotion) {
      analogClock.hourTo = gsap.quickTo(hourPivot.rotation, 'z', {
        duration: preset.hourEase ?? 0.24,
        ease: 'power2.out',
      });
      analogClock.minuteTo = gsap.quickTo(minutePivot.rotation, 'z', {
        duration: preset.minuteEase ?? 0.2,
        ease: 'power2.out',
      });
      analogClock.secondTo = gsap.quickTo(secondPivot.rotation, 'z', {
        duration: preset.secondEase ?? 0.11,
        ease: 'power1.out',
      });
    }

    return analogClock;
  }

  function buildDigitalClock(preset, ink, accent, alpha) {
    const group = new THREE.Group();
    const strokeMaterial = createLineMaterial(ink, alpha);
    const detailMaterial = createLineMaterial(accent, Math.min(alpha * 1.03, 1));
    const fillMaterial = createFillMaterial(ink, Math.min(alpha * 1.03, 1));
    const hasImageCase = addCaseImage(group, preset, alpha);

    if (!hasImageCase) {
      if (preset.bodyShape === 'capsule') {
        addRoundedRectOutline(group, preset.width, preset.height, preset.height * 0.48, strokeMaterial);
        addRoundedRectFill(group, preset.width * 0.96, preset.height * 0.96, preset.height * 0.44, fillMaterial);
      } else if (preset.bodyShape === 'rounded') {
        addRoundedRectOutline(group, preset.width, preset.height, preset.cornerRadius || 0.14, strokeMaterial);
        addRoundedRectFill(
          group,
          preset.width * 0.96,
          preset.height * 0.96,
          Math.max((preset.cornerRadius || 0.14) - 0.02, 0.05),
          fillMaterial
        );
      } else {
        addRectOutline(group, preset.width, preset.height, strokeMaterial);
        addFillRect(group, preset.width * 0.96, preset.height * 0.96, fillMaterial);
      }

      if (preset.feet) {
        addLeg(group, -preset.width * 0.26, -preset.height * 0.55, 0.14, detailMaterial);
        addLeg(group, preset.width * 0.26, -preset.height * 0.55, 0.14, detailMaterial);
      }

      if (preset.topBar) {
        addRectOutline(
          group,
          preset.width * 0.35,
          preset.height * 0.12,
          detailMaterial,
          0,
          preset.height * 0.58
        );
      }

      if (preset.sideButtons) {
        addRectOutline(group, 0.12, 0.08, detailMaterial, preset.width * 0.57, 0.12);
        addRectOutline(group, 0.1, 0.06, detailMaterial, preset.width * 0.57, -0.08);
      }

      if (preset.strap) {
        addWatchStrap(group, preset.width * 0.34, preset.height * 0.88, detailMaterial, preset.strapTicks || 5);
      }

      if (preset.screenFrame) {
        addRoundedRectOutline(
          group,
          preset.width * 0.74,
          preset.height * 0.48,
          preset.height * 0.1,
          detailMaterial
        );
      }
    }

    const textCanvas = document.createElement('canvas');
    textCanvas.width = preset.textCanvasWidth || 512;
    textCanvas.height = preset.textCanvasHeight || 200;
    const textCtx = textCanvas.getContext('2d');

    const textTexture = new THREE.CanvasTexture(textCanvas);
    textTexture.minFilter = THREE.LinearFilter;
    textTexture.magFilter = THREE.LinearFilter;
    textTexture.generateMipmaps = false;
    textTexture.needsUpdate = true;
    disposables.textures.add(textTexture);

    const textMat = new THREE.MeshBasicMaterial({
      map: textTexture,
      transparent: true,
      opacity: Math.min(alpha * 1.06, 1),
      depthTest: true,
      depthWrite: false,
    });
    disposables.materials.add(textMat);

    const screenRect = resolveDigitalScreenRect(preset);
    const textGeo = new THREE.PlaneGeometry(screenRect.width, screenRect.height);
    disposables.geometries.add(textGeo);

    const textMesh = new THREE.Mesh(textGeo, textMat);
    textMesh.position.x = screenRect.offsetX;
    textMesh.position.y = screenRect.offsetY;
    textMesh.position.z = hasImageCase ? 0.015 : 0.004;
    group.add(textMesh);

    const digitalClock = {
      type: 'digital',
      family: preset.family || preset.name,
      group,
      canvas: textCanvas,
      ctx: textCtx,
      texture: textTexture,
      color: toCssColor(new THREE.Color(preset.textColor || toCssColor(accent))),
      useSeconds: preset.showSeconds,
      segmentDisplay: Boolean(preset.segmentDisplay),
      segmentOnColor: preset.segmentOnColor || toCssColor(accent),
      segmentOffColor: preset.segmentOffColor || 'rgba(200, 220, 240, 0.16)',
      pulseOnDigits: preset.pulseOnDigits,
      blinkMainColon: Boolean(preset.blinkMainColon),
      casioLayout: preset.casioLayout || null,
      btfLayout: preset.btfLayout || null,
      dayLabels:
        Array.isArray(preset.dayLabels) && preset.dayLabels.length === 7
          ? preset.dayLabels
          : CASIO_WEEKDAY_SHORT,
      segmentLayout: null,
      lastDigitsFrame: '',
      lastColonState: null,
      lastFrame: '',
      textMesh,
      pulseTween: null,
      alphaTween: null,
    };

    drawDigitalTime(digitalClock, new Date(), true);

    return digitalClock;
  }

  function resolveDigitalScreenRect(preset) {
    const fallback = {
      width: preset.width * 0.78,
      height: preset.height * 0.34,
      offsetX: 0,
      offsetY: 0.02,
    };

    if (!preset.caseImage || !preset.screenRect) {
      return fallback;
    }

    const imageWidth = Math.max(2, Number(preset.caseImagePixelWidth) || 0);
    const imageHeight = Math.max(2, Number(preset.caseImagePixelHeight) || 0);
    const left = Number(preset.screenRect.left);
    const right = Number(preset.screenRect.right);
    const top = Number(preset.screenRect.top);
    const bottom = Number(preset.screenRect.bottom);

    if (![left, right, top, bottom].every(Number.isFinite)) {
      return fallback;
    }

    const widthNorm = Math.abs((right - left) / (imageWidth - 1));
    const heightNorm = Math.abs((bottom - top) / (imageHeight - 1));
    if (widthNorm <= 0 || heightNorm <= 0) {
      return fallback;
    }

    const centerXNorm = ((left + right) * 0.5) / (imageWidth - 1);
    const centerYNorm = ((top + bottom) * 0.5) / (imageHeight - 1);

    const casePlane = resolveImagePlaneSize(
      null,
      preset.caseImageWidth || preset.width || 1,
      preset.caseImageHeight || preset.height || 1,
      preset.caseImageAspect
    );
    const pivotX = THREE.MathUtils.clamp(preset.caseImageAnchorX ?? 0.5, 0.05, 0.95);
    const pivotY = THREE.MathUtils.clamp(preset.caseImageAnchorY ?? 0.5, 0.05, 0.95);

    return {
      width: Math.max(casePlane.width * widthNorm, 0.08),
      height: Math.max(casePlane.height * heightNorm, 0.06),
      offsetX: casePlane.width * (centerXNorm - pivotX) + (preset.caseImageOffsetX || 0),
      offsetY: casePlane.height * (pivotY - centerYNorm) + (preset.caseImageOffsetY || 0),
    };
  }

  function setupPointerRainRepel() {
    if (settings.reducedMotion) {
      return null;
    }

    const toWorldPoint = (event) => {
      const rect = root.getBoundingClientRect();
      if (!rect.width || !rect.height) {
        return null;
      }
      const relX = (event.clientX - rect.left) / rect.width;
      const relY = (event.clientY - rect.top) / rect.height;
      return {
        x: (relX - 0.5) * rect.width,
        y: (0.5 - relY) * rect.height,
      };
    };

    const pickClockUnderPointer = (x, y) => {
      let picked = null;
      let pickedZ = -Infinity;
      let pickedDistance = Infinity;
      const pickFactor = 1.08;

      for (let i = 0; i < clockMeta.length; i++) {
        const meta = clockMeta[i];
        const dx = x - meta.x;
        const dy = y - meta.y;
        const distance = Math.hypot(dx, dy);
        const pickRadius = meta.radius * pickFactor;
        if (distance > pickRadius) {
          continue;
        }

        const z = meta.group.position.z || 0;
        if (z > pickedZ || (Math.abs(z - pickedZ) < 0.001 && distance < pickedDistance)) {
          picked = meta;
          pickedZ = z;
          pickedDistance = distance;
        }
      }

      return picked;
    };

    const endDrag = (event) => {
      if (!dragState.active) {
        return;
      }
      if (event && dragState.pointerId !== null && event.pointerId !== dragState.pointerId) {
        return;
      }

      const meta = dragState.meta;
      if (meta) {
        meta.isDragging = false;
        const throwBoost = settings.enableDragThrow ? settings.dragInertiaBoost : 0;
        meta.vx = THREE.MathUtils.clamp(meta.vx * throwBoost, -settings.maxVelocity, settings.maxVelocity);
        meta.vy = THREE.MathUtils.clamp(meta.vy * throwBoost, -settings.maxVelocity, settings.maxVelocity);
        meta.freeFlightTime = settings.enableDragThrow ? settings.throwFreeFlightSeconds : 0;
      }

      if (dragState.pointerId !== null) {
        try {
          root.releasePointerCapture(dragState.pointerId);
        } catch (_) {}
      }

      dragState.active = false;
      dragState.pointerId = null;
      dragState.meta = null;
      dragState.offsetX = 0;
      dragState.offsetY = 0;
      dragState.lastTime = 0;
    };

    const onMove = (event) => {
      const world = toWorldPoint(event);
      if (!world) {
        return;
      }

      if (settings.hoverRepel) {
        pointerState.active = true;
        pointerState.targetX = world.x;
        pointerState.targetY = world.y;
      } else if (!dragState.active) {
        pointerState.active = false;
        pointerState.targetX = 0;
        pointerState.targetY = 0;
      }

      if (!dragState.active || !dragState.meta || event.pointerId !== dragState.pointerId) {
        return;
      }

      const targetX = world.x + dragState.offsetX;
      const targetY = world.y + dragState.offsetY;
      const now = window.performance.now();
      const dt = Math.max(0.001, (now - dragState.lastTime) / 1000);
      const rawVx = (targetX - dragState.lastX) / dt / 60;
      const rawVy = (targetY - dragState.lastY) / dt / 60;

      dragState.meta.vx = THREE.MathUtils.lerp(dragState.meta.vx, rawVx, 0.45);
      dragState.meta.vy = THREE.MathUtils.lerp(dragState.meta.vy, rawVy, 0.45);
      dragState.meta.dragTargetX = targetX;
      dragState.meta.dragTargetY = targetY;

      dragState.lastX = targetX;
      dragState.lastY = targetY;
      dragState.lastTime = now;
    };

    const onDown = (event) => {
      if (!settings.enableDragThrow) {
        return;
      }
      if (dragState.active) {
        endDrag(event);
      }

      const world = toWorldPoint(event);
      if (!world) {
        return;
      }

      const picked = pickClockUnderPointer(world.x, world.y);
      if (!picked) {
        return;
      }

      dragState.active = true;
      dragState.pointerId = event.pointerId;
      dragState.meta = picked;
      dragState.offsetX = picked.x - world.x;
      dragState.offsetY = picked.y - world.y;
      dragState.lastX = picked.x;
      dragState.lastY = picked.y;
      dragState.lastTime = window.performance.now();

      picked.isDragging = true;
      picked.dragTargetX = picked.x;
      picked.dragTargetY = picked.y;
      picked.freeFlightTime = 0;
      picked.vx = 0;
      picked.vy = 0;
      if (!settings.hoverRepel) {
        pointerState.active = false;
        pointerState.targetX = 0;
        pointerState.targetY = 0;
      }

      try {
        root.setPointerCapture(event.pointerId);
      } catch (_) {}

      event.preventDefault();
    };

    const onLeave = () => {
      if (dragState.active) {
        return;
      }
      pointerState.active = false;
      pointerState.targetX = 0;
      pointerState.targetY = 0;
    };

    const onUp = (event) => {
      endDrag(event);
    };

    const onCancel = (event) => {
      endDrag(event);
    };

    const onWindowUp = (event) => {
      endDrag(event);
    };

    root.addEventListener('pointerdown', onDown);
    root.addEventListener('pointermove', onMove);
    root.addEventListener('pointerleave', onLeave);
    root.addEventListener('pointerup', onUp);
    root.addEventListener('pointercancel', onCancel);
    window.addEventListener('pointerup', onWindowUp);

    return { onDown, onMove, onLeave, onUp, onCancel, onWindowUp };
  }

  function resolveClockCollisions() {
    const overlapSlop = 1.2;
    const correctionPercent = 0.52;
    const minBounceSpeed = 0.24;
    const contactDamping = 0.9;
    const tangentFriction = 0.14;
    const dynamicBounceSpeed = 2.2;
    const passiveDamping = 0.82;
    const passiveMaxSpeed = 2.8;
    const passiveVelocityBlend = 0.82;
    const dynamicMaxSpeed = settings.maxVelocity;

    const clampMetaSpeed = (meta, maxSpeed) => {
      const speed = Math.hypot(meta.vx, meta.vy);
      if (speed <= maxSpeed) {
        return;
      }
      const factor = maxSpeed / Math.max(speed, 0.0001);
      meta.vx *= factor;
      meta.vy *= factor;
    };
    const isInsideCollisionViewport = (meta) => {
      const pad = collisionViewport.padding + meta.radius;
      return (
        meta.x >= collisionViewport.minX - pad &&
        meta.x <= collisionViewport.maxX + pad &&
        meta.y >= collisionViewport.minY - pad &&
        meta.y <= collisionViewport.maxY + pad
      );
    };

    for (let layerIndex = 0; layerIndex < clockMetaByLayer.length; layerIndex++) {
      const layerMetas = clockMetaByLayer[layerIndex];
      if (!layerMetas || layerMetas.length < 2) {
        continue;
      }

      layerMetas.sort((a, b) => a.x - b.x);

      for (let i = 0; i < layerMetas.length - 1; i++) {
        const metaA = layerMetas[i];
        if (!isInsideCollisionViewport(metaA)) {
          continue;
        }
        const radiusA = metaA.radius;

        for (let j = i + 1; j < layerMetas.length; j++) {
          const metaB = layerMetas[j];
          if (!isInsideCollisionViewport(metaB)) {
            continue;
          }
          const radiusB = metaB.radius;
          const maxDistance = radiusA + radiusB;
          const dx = metaB.x - metaA.x;

          if (dx > maxDistance) {
            break;
          }

          const dy = metaB.y - metaA.y;
          const distanceSquared = dx * dx + dy * dy;
          const minDistanceSquared = maxDistance * maxDistance;

          if (distanceSquared >= minDistanceSquared) {
            continue;
          }

          let distance = Math.sqrt(distanceSquared);
          let nx = 0;
          let ny = 0;

          if (distance < 0.0001) {
            const angle = ((i * 92821 + j * 68917) % 360) * (Math.PI / 180);
            nx = Math.cos(angle);
            ny = Math.sin(angle);
            distance = 0.0001;
          } else {
            nx = dx / distance;
            ny = dy / distance;
          }

          const overlap = maxDistance - distance;
          const invMassA = metaA.isDragging ? 0 : 1 / Math.max(metaA.mass, 0.001);
          const invMassB = metaB.isDragging ? 0 : 1 / Math.max(metaB.mass, 0.001);
          const invMassTotal = invMassA + invMassB;
          if (invMassTotal <= 0) {
            continue;
          }

          const correctedOverlap = Math.max(overlap - overlapSlop, 0) * correctionPercent;
          const correctionA = (correctedOverlap * invMassA) / invMassTotal;
          const correctionB = (correctedOverlap * invMassB) / invMassTotal;

          metaA.x -= nx * correctionA;
          metaA.y -= ny * correctionA;
          metaB.x += nx * correctionB;
          metaB.y += ny * correctionB;

          const relativeVx = metaB.vx - metaA.vx;
          const relativeVy = metaB.vy - metaA.vy;
          const normalVelocity = relativeVx * nx + relativeVy * ny;

          if (normalVelocity >= 0) {
            continue;
          }

          const speedA = Math.hypot(metaA.vx, metaA.vy);
          const speedB = Math.hypot(metaB.vx, metaB.vy);
          const allowElasticBounce =
            metaA.isDragging ||
            metaB.isDragging ||
            dragState.active ||
            speedA > dynamicBounceSpeed ||
            speedB > dynamicBounceSpeed;

          if (!allowElasticBounce) {
            // En movimiento ambiente, igualamos velocidad para evitar micro-rebote en bucle.
            const totalMass = Math.max(metaA.mass + metaB.mass, 0.001);
            const avgVx = (metaA.vx * metaA.mass + metaB.vx * metaB.mass) / totalMass;
            const avgVy = (metaA.vy * metaA.mass + metaB.vy * metaB.mass) / totalMass;

            metaA.vx = THREE.MathUtils.lerp(metaA.vx, avgVx, passiveVelocityBlend) * passiveDamping;
            metaA.vy = THREE.MathUtils.lerp(metaA.vy, avgVy, passiveVelocityBlend) * passiveDamping;
            metaB.vx = THREE.MathUtils.lerp(metaB.vx, avgVx, passiveVelocityBlend) * passiveDamping;
            metaB.vy = THREE.MathUtils.lerp(metaB.vy, avgVy, passiveVelocityBlend) * passiveDamping;
            clampMetaSpeed(metaA, passiveMaxSpeed);
            clampMetaSpeed(metaB, passiveMaxSpeed);

            // Desplaza levemente la trayectoria base para no reentrar en contacto en el frame siguiente.
            const pathNudge = Math.min(overlap * 0.18, 1.6);
            const nudgeA = invMassA / invMassTotal;
            const nudgeB = invMassB / invMassTotal;
            if (!metaA.isDragging && metaA.freeFlightTime <= 0) {
              metaA.baseX -= nx * pathNudge * nudgeA;
              metaA.fallY -= ny * pathNudge * nudgeA;
            }
            if (!metaB.isDragging && metaB.freeFlightTime <= 0) {
              metaB.baseX += nx * pathNudge * nudgeB;
              metaB.fallY += ny * pathNudge * nudgeB;
            }
            continue;
          }

          if (normalVelocity > -minBounceSpeed) {
            // Evita la resonancia cuando dos relojes se solapan levemente durante la lluvia.
            const settleImpulse = (-(contactDamping) * normalVelocity) / invMassTotal;
            metaA.vx -= settleImpulse * nx * invMassA;
            metaA.vy -= settleImpulse * ny * invMassA;
            metaB.vx += settleImpulse * nx * invMassB;
            metaB.vy += settleImpulse * ny * invMassB;
            continue;
          }

          const speedFactor = THREE.MathUtils.clamp(
            (Math.abs(normalVelocity) - minBounceSpeed) / 1.8,
            0,
            1
          );
          const restitution = settings.collisionBounce * speedFactor;
          const impulse = (-(1 + restitution) * normalVelocity) / invMassTotal;
          metaA.vx -= impulse * nx * invMassA;
          metaA.vy -= impulse * ny * invMassA;
          metaB.vx += impulse * nx * invMassB;
          metaB.vy += impulse * ny * invMassB;

          const tx = -ny;
          const ty = nx;
          const tangentVelocity = relativeVx * tx + relativeVy * ty;
          let frictionImpulse = (-tangentVelocity * tangentFriction) / invMassTotal;
          const maxFriction = Math.abs(impulse) * 0.45;
          frictionImpulse = THREE.MathUtils.clamp(frictionImpulse, -maxFriction, maxFriction);
          metaA.vx -= frictionImpulse * tx * invMassA;
          metaA.vy -= frictionImpulse * ty * invMassA;
          metaB.vx += frictionImpulse * tx * invMassB;
          metaB.vy += frictionImpulse * ty * invMassB;
          clampMetaSpeed(metaA, dynamicMaxSpeed);
          clampMetaSpeed(metaB, dynamicMaxSpeed);
        }
      }
    }
  }

  function addCaseImage(group, preset, alpha) {
    if (!preset.caseImage) {
      return false;
    }

    const texture = loadCaseTexture(preset.caseImage);
    if (!texture) {
      return false;
    }

    const material = new THREE.MeshBasicMaterial({
      map: texture,
      transparent: true,
      opacity: THREE.MathUtils.clamp(preset.caseOpacity ?? alpha, 0.2, 1),
      depthTest: true,
      depthWrite: true,
      side: THREE.DoubleSide,
    });
    disposables.materials.add(material);

    const planeSize = resolveImagePlaneSize(
      texture,
      preset.caseImageWidth || 1.9,
      preset.caseImageHeight || 1.9,
      preset.caseImageAspect
    );
    const width = planeSize.width;
    const height = planeSize.height;
    const geometry = new THREE.PlaneGeometry(width, height);
    disposables.geometries.add(geometry);

    const mesh = new THREE.Mesh(geometry, material);
    const pivotX = THREE.MathUtils.clamp(preset.caseImageAnchorX ?? 0.5, 0.05, 0.95);
    const pivotY = THREE.MathUtils.clamp(preset.caseImageAnchorY ?? 0.5, 0.05, 0.95);
    mesh.position.set(
      width * (0.5 - pivotX) + (preset.caseImageOffsetX || 0),
      height * (pivotY - 0.5) + (preset.caseImageOffsetY || 0),
      0
    );
    group.add(mesh);

    return true;
  }

  function loadCaseTexture(src) {
    if (caseTextureCache.has(src)) {
      return caseTextureCache.get(src);
    }

    const texture = caseTextureLoader.load(
      src,
      () => {
        texture.needsUpdate = true;
      },
      undefined,
      () => {
        caseTextureCache.set(src, null);
      }
    );

    if (!texture) {
      caseTextureCache.set(src, null);
      return null;
    }

    texture.minFilter = THREE.LinearFilter;
    texture.magFilter = THREE.LinearFilter;
    texture.generateMipmaps = false;
    texture.colorSpace = THREE.SRGBColorSpace;

    caseTextureCache.set(src, texture);
    disposables.textures.add(texture);
    return texture;
  }

  function playIntro() {
    const canvasTween = gsap.fromTo(
      canvas,
      { autoAlpha: 0 },
      { autoAlpha: parseFloat(getComputedStyle(canvas).opacity || '1'), duration: 1.1, ease: 'power2.out' }
    );
    disposables.tweens.push(canvasTween);

    layerGroups.forEach((layer, index) => {
      const tween = gsap.fromTo(
        layer.scale,
        { x: 1.08, y: 1.08, z: 1 },
        { x: 1, y: 1, z: 1, duration: 1.2 + index * 0.15, ease: 'power3.out' }
      );
      disposables.tweens.push(tween);
    });

    if (!settings.reducedMotion) {
      const worldTween = gsap.to(world.rotation, {
        z: 0.018,
        duration: 20,
        ease: 'sine.inOut',
        yoyo: true,
        repeat: -1,
      });
      disposables.tweens.push(worldTween);
    }
  }

  function pulseDigitalClock(clock) {
    if (settings.reducedMotion || !clock.textMesh) {
      return;
    }

    if (clock.pulseTween) {
      clock.pulseTween.kill();
    }

    const textMat = clock.textMesh.material;
    const fromAlpha = textMat.opacity;
    clock.pulseTween = gsap.fromTo(
      clock.textMesh.scale,
      { x: 1.03, y: 0.98, z: 1 },
      {
        x: 1,
        y: 1,
        z: 1,
        duration: 0.22,
        ease: 'power2.out',
      }
    );

    clock.alphaTween = gsap.fromTo(
      textMat,
      { opacity: Math.min(fromAlpha * 1.08, 0.72) },
      {
        opacity: fromAlpha,
        duration: 0.24,
        ease: 'power2.out',
      }
    );
  }

  function createLineMaterial(color, opacity) {
    const material = new THREE.LineBasicMaterial({
      color,
      transparent: true,
      opacity: THREE.MathUtils.clamp(opacity, 0.2, 1),
      depthTest: true,
      depthWrite: true,
    });
    disposables.materials.add(material);
    return material;
  }

  function createFillMaterial(color, opacity) {
    const material = new THREE.MeshBasicMaterial({
      color,
      transparent: true,
      opacity: THREE.MathUtils.clamp(opacity, 0.12, 1),
      depthTest: true,
      depthWrite: true,
      side: THREE.DoubleSide,
    });
    disposables.materials.add(material);
    return material;
  }

  function addCaseShape(group, preset, strokeMaterial, fillMaterial) {
    switch (preset.caseShape) {
      case 'rect':
        addRectOutline(group, preset.width, preset.height, strokeMaterial);
        addFillRect(group, preset.width * 0.96, preset.height * 0.96, fillMaterial);
        break;
      case 'roundedRect':
        addRoundedRectOutline(group, preset.width, preset.height, preset.cornerRadius || 0.16, strokeMaterial);
        addRoundedRectFill(
          group,
          preset.width * 0.96,
          preset.height * 0.96,
          Math.max((preset.cornerRadius || 0.16) - 0.03, 0.06),
          fillMaterial
        );
        break;
      case 'octagon':
        addPolygonOutline(group, 8, Math.min(preset.width, preset.height) * 0.52, strokeMaterial);
        addFillEllipse(group, preset.width * 0.44, preset.height * 0.44, fillMaterial);
        break;
      case 'oval':
        addEllipseOutline(group, preset.width * 0.5, preset.height * 0.5, strokeMaterial, 56);
        addFillEllipse(group, preset.width * 0.48, preset.height * 0.48, fillMaterial);
        break;
      default:
        addEllipseOutline(group, preset.width * 0.5, preset.height * 0.5, strokeMaterial, 56);
        addFillEllipse(group, preset.width * 0.48, preset.height * 0.48, fillMaterial);
        break;
    }

    if (preset.doubleRing) {
      addEllipseOutline(group, preset.width * 0.41, preset.height * 0.41, strokeMaterial, 48);
    }
  }

  function addTickMarks(group, preset, material) {
    if (preset.markers === 0) {
      return;
    }

    const positions = [];
    const count = preset.markers;
    const outer = Math.min(preset.width, preset.height) * 0.45;
    const inner = outer - preset.markerDepth;
    for (let i = 0; i < count; i++) {
      const angle = (i / count) * TAU;
      const cos = Math.cos(angle);
      const sin = Math.sin(angle);
      positions.push(inner * cos, inner * sin, 0);
      positions.push(outer * cos, outer * sin, 0);
    }
    addLineSegments(group, positions, material);
  }

  function addAnalogExtras(group, preset, material) {
    if (preset.extra === 'alarm') {
      addEllipseOutline(group, 0.18, 0.12, material, 24, -0.34, 0.56);
      addEllipseOutline(group, 0.18, 0.12, material, 24, 0.34, 0.56);
      addLeg(group, -0.28, -0.56, 0.16, material);
      addLeg(group, 0.28, -0.56, 0.16, material);
    }

    if (preset.extra === 'pocket') {
      addEllipseOutline(group, 0.14, 0.14, material, 26, 0, 0.62);
      addLineSegments(group, [0, 0.48, 0, 0, 0.35, 0], material);
    }

    if (preset.extra === 'desk') {
      addLineSegments(group, [-0.3, -0.52, 0, -0.12, -0.66, 0], material);
      addLineSegments(group, [0.3, -0.52, 0, 0.12, -0.66, 0], material);
    }

    if (preset.extra === 'stopwatch') {
      addRectOutline(group, 0.18, 0.1, material, 0, 0.6);
      addEllipseOutline(group, 0.12, 0.1, material, 22, 0, 0.48);
    }

    if (preset.extra === 'industrial') {
      addRectOutline(group, 0.14, 0.08, material, 0.56, 0);
      addRectOutline(group, 0.14, 0.08, material, -0.56, 0);
    }

    if (preset.extra === 'wrist') {
      addWatchStrap(group, preset.width * 0.34, preset.height * 0.92, material, preset.strapTicks || 5);
      if (preset.lugs) {
        addRectOutline(group, 0.18, 0.06, material, 0, preset.height * 0.5 - 0.06);
        addRectOutline(group, 0.18, 0.06, material, 0, -preset.height * 0.5 + 0.06);
      }
    }

    if (preset.extra === 'wrist-square') {
      addWatchStrap(group, preset.width * 0.36, preset.height * 0.94, material, preset.strapTicks || 4);
      addRectOutline(group, 0.12, 0.08, material, preset.width * 0.56, 0.05);
    }

    if (preset.extra === 'side-crown') {
      addEllipseOutline(group, 0.08, 0.06, material, 18, preset.width * 0.56, 0.06);
      addEllipseOutline(group, 0.06, 0.045, material, 16, -preset.width * 0.56, -0.05);
    }

    if (preset.extra === 'ring-vibrate') {
      addArcStroke(group, preset.width * 0.62, 0.28, 0.88, material, 0.16);
      addArcStroke(group, preset.width * 0.62, 2.26, 2.84, material, 0.16);
    }

    if (preset.extra === 'fifth-avenue') {
      addFifthAvenueFrame(group, preset, material);
      addRomanStyleTicks(group, Math.min(preset.width, preset.height) * 0.39, material);
    }
  }

  function createClockHand(preset, part, ink, accent, alpha) {
    if (preset.handStyle === 'image' || preset.handStyle === 'fifth-avenue-image' || preset.handStyle === 'bigben-image') {
      if (part === 'hour') {
        const opacityMul = preset.hourHandOpacityMul ?? 1.45;
        const opacityMax = preset.hourHandOpacityMax ?? 1;
        return createImageHandMesh(
          preset.hourHandImage,
          preset.hourHandImageWidth || 0.14,
          preset.hourHandImageHeight || 0.62,
          preset.hourHandImageAnchorX ?? 0.5,
          preset.hourHandImageAnchorY ?? 0.2,
          Math.min(alpha * opacityMul, opacityMax),
          ink,
          preset.hourHandImageAspect
        );
      }

      if (part === 'minute') {
        const opacityMul = preset.minuteHandOpacityMul ?? 1.5;
        const opacityMax = preset.minuteHandOpacityMax ?? 1;
        return createImageHandMesh(
          preset.minuteHandImage,
          preset.minuteHandImageWidth || 0.11,
          preset.minuteHandImageHeight || 0.74,
          preset.minuteHandImageAnchorX ?? 0.5,
          preset.minuteHandImageAnchorY ?? 0.16,
          Math.min(alpha * opacityMul, opacityMax),
          ink,
          preset.minuteHandImageAspect
        );
      }

      const minuteDims = resolveImagePlaneSize(
        null,
        preset.minuteHandImageWidth || 0.11,
        preset.minuteHandImageHeight || 0.74,
        preset.minuteHandImageAspect
      );
      const secondLength =
        preset.secondMatchMinute && minuteDims.height
          ? minuteDims.height * THREE.MathUtils.clamp(preset.minuteHandImageAnchorY ?? 0.86, 0.5, 0.95)
          : preset.secondLength;
      const secondWidth =
        preset.secondMatchMinuteWidth && minuteDims.width
          ? minuteDims.width
          : preset.secondWidth || 0.005;
      const secondMinWidth = preset.secondMinWidth ?? 0.0045;
      const secondOpacity = THREE.MathUtils.clamp(
        preset.secondOpacity ?? Math.min(alpha * 1.1, 1),
        0,
        1
      );
      return createHandMesh(
        Math.max(secondWidth * 0.78, secondMinWidth),
        secondLength,
        accent,
        secondOpacity
      );
    }

    if (preset.handStyle === 'fifth-avenue') {
      if (part === 'hour') {
        return createOrnateHandMesh(
          {
            length: preset.hourLength,
            neckWidth: Math.max(preset.hourWidth * 1.8, 0.09),
            bellyWidth: Math.max(preset.hourWidth * 0.95, 0.05),
            tipWidth: Math.max(preset.hourWidth * 0.2, 0.012),
            baseOuter: Math.max(preset.hourWidth * 1.5, 0.07),
            baseInner: Math.max(preset.hourWidth * 0.55, 0.028),
          },
          ink,
          Math.min(alpha * 1.1, 1)
        );
      }

      if (part === 'minute') {
        return createOrnateHandMesh(
          {
            length: preset.minuteLength,
            neckWidth: Math.max(preset.minuteWidth * 1.4, 0.06),
            bellyWidth: Math.max(preset.minuteWidth * 0.8, 0.036),
            tipWidth: Math.max(preset.minuteWidth * 0.24, 0.009),
            baseOuter: Math.max(preset.minuteWidth * 1.45, 0.06),
            baseInner: Math.max(preset.minuteWidth * 0.5, 0.024),
          },
          ink,
          Math.min(alpha * 1.1, 1)
        );
      }

      return createHandMesh(
        Math.max(preset.secondWidth * 0.92, 0.006),
        preset.secondLength,
        accent,
        Math.min(alpha * 1.1, 1)
      );
    }

    if (part === 'hour') {
      return createHandMesh(preset.hourWidth, preset.hourLength, ink, Math.min(alpha * 1.05, 1));
    }
    if (part === 'minute') {
      return createHandMesh(preset.minuteWidth, preset.minuteLength, ink, Math.min(alpha * 1.05, 1));
    }

    return createHandMesh(preset.secondWidth, preset.secondLength, accent, Math.min(alpha * 1.05, 1));
  }

  function createImageHandMesh(src, width, height, anchorX, anchorY, opacity, fallbackColor, aspectHint) {
    if (!src) {
      return createHandMesh(Math.max(width * 0.2, 0.01), height, fallbackColor, opacity);
    }

    const texture = loadCaseTexture(src);
    if (!texture) {
      return createHandMesh(Math.max(width * 0.2, 0.01), height, fallbackColor, opacity);
    }

    const material = new THREE.MeshBasicMaterial({
      map: texture,
      transparent: true,
      opacity,
      depthTest: true,
      depthWrite: true,
      side: THREE.DoubleSide,
    });
    disposables.materials.add(material);

    const planeSize = resolveImagePlaneSize(texture, width, height, aspectHint);
    const geometry = new THREE.PlaneGeometry(planeSize.width, planeSize.height);
    disposables.geometries.add(geometry);
    const mesh = new THREE.Mesh(geometry, material);
    const pivotX = THREE.MathUtils.clamp(anchorX, 0.05, 0.95);
    const pivotY = THREE.MathUtils.clamp(anchorY, 0.5, 0.95);
    mesh.position.x = planeSize.width * (0.5 - pivotX);
    mesh.position.y = planeSize.height * (pivotY - 0.5);
    return mesh;
  }

  function resolveImagePlaneSize(texture, desiredWidth, desiredHeight, aspectHint) {
    const width = parsePositiveNumber(desiredWidth);
    const height = parsePositiveNumber(desiredHeight);
    const fallbackAspect = resolveFallbackAspect(width, height, aspectHint);
    const aspect = resolveTextureAspect(texture, fallbackAspect);

    if (width && height) {
      const heightFromWidth = width / aspect;
      const widthFromHeight = height * aspect;
      const deltaUsingWidth = Math.abs(heightFromWidth - height) / height;
      const deltaUsingHeight = Math.abs(widthFromHeight - width) / width;

      if (deltaUsingWidth <= deltaUsingHeight) {
        return { width, height: heightFromWidth };
      }
      return { width: widthFromHeight, height };
    }

    if (width) {
      return { width, height: width / aspect };
    }

    if (height) {
      return { width: height * aspect, height };
    }

    return { width: aspect, height: 1 };
  }

  function resolveTextureAspect(texture, fallbackAspect) {
    const image = texture?.image || texture?.source?.data || null;
    const imageWidth = image?.naturalWidth || image?.videoWidth || image?.width || 0;
    const imageHeight = image?.naturalHeight || image?.videoHeight || image?.height || 0;

    if (imageWidth > 0 && imageHeight > 0) {
      return imageWidth / imageHeight;
    }

    return fallbackAspect;
  }

  function resolveFallbackAspect(width, height, aspectHint) {
    const hintedAspect = parsePositiveNumber(aspectHint);
    if (hintedAspect) {
      return hintedAspect;
    }

    if (width && height) {
      return width / height;
    }

    if (width) {
      return width;
    }

    return 1;
  }

  function parsePositiveNumber(value) {
    const number = Number(value);
    return Number.isFinite(number) && number > 0 ? number : 0;
  }

  function createOrnateHandMesh(spec, color, opacity) {
    const group = new THREE.Group();
    const fillMaterial = createFillMaterial(color, opacity);
    const edgeMaterial = createLineMaterial(color, Math.min(opacity * 1.1, 0.52));

    const length = spec.length;
    const neckWidth = spec.neckWidth;
    const bellyWidth = spec.bellyWidth;
    const tipWidth = spec.tipWidth;
    const baseOuter = spec.baseOuter;
    const baseInner = spec.baseInner;
    const shoulderY = length * 0.22;
    const neckY = length * 0.52;
    const tipY = length;

    const shape = new THREE.Shape();
    shape.moveTo(-neckWidth * 0.5, baseOuter * 0.45);
    shape.bezierCurveTo(
      -neckWidth * 0.8,
      shoulderY * 0.4,
      -bellyWidth * 1.2,
      shoulderY * 0.88,
      -bellyWidth * 0.5,
      shoulderY
    );
    shape.lineTo(-tipWidth * 0.65, neckY);
    shape.lineTo(-tipWidth * 0.35, tipY * 0.88);
    shape.lineTo(0, tipY);
    shape.lineTo(tipWidth * 0.35, tipY * 0.88);
    shape.lineTo(tipWidth * 0.65, neckY);
    shape.lineTo(bellyWidth * 0.5, shoulderY);
    shape.bezierCurveTo(
      bellyWidth * 1.2,
      shoulderY * 0.88,
      neckWidth * 0.8,
      shoulderY * 0.4,
      neckWidth * 0.5,
      baseOuter * 0.45
    );
    shape.closePath();

    const hole = new THREE.Path();
    hole.moveTo(-neckWidth * 0.16, baseOuter * 0.62);
    hole.bezierCurveTo(
      -neckWidth * 0.16,
      shoulderY * 0.48,
      -tipWidth * 0.45,
      shoulderY * 0.96,
      -tipWidth * 0.14,
      neckY * 0.86
    );
    hole.lineTo(0, tipY * 0.72);
    hole.lineTo(tipWidth * 0.14, neckY * 0.86);
    hole.bezierCurveTo(
      tipWidth * 0.45,
      shoulderY * 0.96,
      neckWidth * 0.16,
      shoulderY * 0.48,
      neckWidth * 0.16,
      baseOuter * 0.62
    );
    hole.closePath();
    shape.holes.push(hole);

    const bladeGeometry = new THREE.ShapeGeometry(shape);
    disposables.geometries.add(bladeGeometry);
    group.add(new THREE.Mesh(bladeGeometry, fillMaterial));

    addRingMesh(group, baseOuter, baseInner, fillMaterial);

    addEllipseOutline(group, baseOuter * 1.08, baseOuter * 1.08, edgeMaterial, 28);
    addLineSegments(group, [0, baseOuter * 1.2, 0, 0, baseOuter * 0.1, 0], edgeMaterial);

    return group;
  }

  function addRingMesh(group, outerRadius, innerRadius, material, offsetX = 0, offsetY = 0) {
    const shape = new THREE.Shape();
    shape.absarc(offsetX, offsetY, outerRadius, 0, TAU, false);
    const hole = new THREE.Path();
    hole.absarc(offsetX, offsetY, innerRadius, 0, TAU, true);
    shape.holes.push(hole);
    const geometry = new THREE.ShapeGeometry(shape);
    disposables.geometries.add(geometry);
    group.add(new THREE.Mesh(geometry, material));
  }

  function addFifthAvenueFrame(group, preset, material) {
    const frameRadius = Math.min(preset.width, preset.height) * 0.58;
    addEllipseOutline(group, frameRadius, frameRadius, material, 80);
    addEllipseOutline(group, frameRadius * 0.92, frameRadius * 0.92, material, 76);
    addEllipseOutline(group, frameRadius * 0.82, frameRadius * 0.82, material, 68);
    addEllipseOutline(group, frameRadius * 0.72, frameRadius * 0.72, material, 64);
    addEllipseOutline(group, frameRadius * 0.46, frameRadius * 0.46, material, 56);

    addArcStroke(group, frameRadius * 1.08, 1.3, 1.86, material, 0.05);
    addArcStroke(group, frameRadius * 1.08, 4.45, 5.0, material, 0.05);

    addEllipseOutline(group, 0.11, 0.085, material, 24, 0, frameRadius * 1.05);
    addEllipseOutline(group, 0.08, 0.065, material, 22, -frameRadius * 0.95, 0);
    addEllipseOutline(group, 0.08, 0.065, material, 22, frameRadius * 0.95, 0);
    addEllipseOutline(group, 0.1, 0.08, material, 24, -0.16, -frameRadius * 0.96);
    addEllipseOutline(group, 0.1, 0.08, material, 24, 0.16, -frameRadius * 0.96);

    addLineSegments(group, [0, frameRadius * 1.13, 0, 0, frameRadius * 0.95, 0], material);
    addLineSegments(group, [-0.05, -frameRadius * 1.15, 0, 0, -frameRadius * 1.02, 0], material);
    addLineSegments(group, [0.05, -frameRadius * 1.15, 0, 0, -frameRadius * 1.02, 0], material);

    addLineSegments(group, [-0.25, 0, 0, 0.25, 0, 0], material);
    addEllipseOutline(group, 0.035, 0.035, material, 20, 0, 0);
  }

  function addRomanStyleTicks(group, radiusOuter, material) {
    const positions = [];
    const radiusInner = radiusOuter - 0.11;
    for (let i = 0; i < 12; i++) {
      const angle = (i / 12) * TAU;
      const cos = Math.cos(angle);
      const sin = Math.sin(angle);
      const tangentX = -sin;
      const tangentY = cos;
      const major = i % 3 === 0;
      const strokes = major ? 3 : 2;
      const spread = major ? 0.018 : 0.012;

      for (let stroke = 0; stroke < strokes; stroke++) {
        const offset = (stroke - (strokes - 1) * 0.5) * spread;
        positions.push(radiusInner * cos + tangentX * offset, radiusInner * sin + tangentY * offset, 0);
        positions.push(radiusOuter * cos + tangentX * offset, radiusOuter * sin + tangentY * offset, 0);
      }
    }
    addLineSegments(group, positions, material);
  }

  function createHandMesh(width, length, color, opacity) {
    const geometry = new THREE.PlaneGeometry(width, length);
    const material = createFillMaterial(color, opacity);
    disposables.geometries.add(geometry);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.position.y = length * 0.5;
    return mesh;
  }

  function createCenterDot(color, opacity, radius) {
    const geometry = new THREE.CircleGeometry(radius, 18);
    const material = createFillMaterial(color, opacity);
    disposables.geometries.add(geometry);
    return new THREE.Mesh(geometry, material);
  }

  function addEllipseOutline(group, radiusX, radiusY, material, segments = 48, offsetX = 0, offsetY = 0) {
    const points = [];
    for (let i = 0; i <= segments; i++) {
      const angle = (i / segments) * TAU;
      points.push(
        new THREE.Vector3(offsetX + Math.cos(angle) * radiusX, offsetY + Math.sin(angle) * radiusY, 0)
      );
    }
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    disposables.geometries.add(geometry);
    group.add(new THREE.LineLoop(geometry, material));
  }

  function addFillEllipse(group, radiusX, radiusY, material) {
    const geometry = new THREE.CircleGeometry(0.5, 40);
    disposables.geometries.add(geometry);
    const mesh = new THREE.Mesh(geometry, material);
    mesh.scale.set(radiusX * 2, radiusY * 2, 1);
    group.add(mesh);
  }

  function addRectOutline(group, width, height, material, offsetX = 0, offsetY = 0) {
    const hw = width * 0.5;
    const hh = height * 0.5;
    const points = [
      new THREE.Vector3(offsetX - hw, offsetY - hh, 0),
      new THREE.Vector3(offsetX + hw, offsetY - hh, 0),
      new THREE.Vector3(offsetX + hw, offsetY + hh, 0),
      new THREE.Vector3(offsetX - hw, offsetY + hh, 0),
    ];
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    disposables.geometries.add(geometry);
    group.add(new THREE.LineLoop(geometry, material));
  }

  function addRoundedRectOutline(group, width, height, radius, material, offsetX = 0, offsetY = 0) {
    const points = buildRoundedRectPoints(width, height, radius, offsetX, offsetY, 12);
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    disposables.geometries.add(geometry);
    group.add(new THREE.LineLoop(geometry, material));
  }

  function addRoundedRectFill(group, width, height, radius, material, offsetX = 0, offsetY = 0) {
    const shape = new THREE.Shape();
    const hw = width * 0.5;
    const hh = height * 0.5;
    const r = Math.max(0.01, Math.min(radius, hw, hh));

    shape.moveTo(offsetX - hw + r, offsetY - hh);
    shape.lineTo(offsetX + hw - r, offsetY - hh);
    shape.quadraticCurveTo(offsetX + hw, offsetY - hh, offsetX + hw, offsetY - hh + r);
    shape.lineTo(offsetX + hw, offsetY + hh - r);
    shape.quadraticCurveTo(offsetX + hw, offsetY + hh, offsetX + hw - r, offsetY + hh);
    shape.lineTo(offsetX - hw + r, offsetY + hh);
    shape.quadraticCurveTo(offsetX - hw, offsetY + hh, offsetX - hw, offsetY + hh - r);
    shape.lineTo(offsetX - hw, offsetY - hh + r);
    shape.quadraticCurveTo(offsetX - hw, offsetY - hh, offsetX - hw + r, offsetY - hh);

    const geometry = new THREE.ShapeGeometry(shape);
    disposables.geometries.add(geometry);
    group.add(new THREE.Mesh(geometry, material));
  }

  function addPolygonOutline(group, sides, radius, material, offsetX = 0, offsetY = 0) {
    const points = [];
    const count = Math.max(3, sides);
    for (let i = 0; i <= count; i++) {
      const angle = (i / count) * TAU;
      points.push(new THREE.Vector3(offsetX + Math.cos(angle) * radius, offsetY + Math.sin(angle) * radius, 0));
    }
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    disposables.geometries.add(geometry);
    group.add(new THREE.LineLoop(geometry, material));
  }

  function addWatchStrap(group, width, height, material, ticks = 5) {
    addRoundedRectOutline(group, width, height, width * 0.26, material);
    addLineSegments(group, [0, height * 0.15, 0, 0, height * 0.5, 0], material);
    addLineSegments(group, [0, -height * 0.15, 0, 0, -height * 0.5, 0], material);

    if (ticks > 0) {
      const start = -height * 0.34;
      const step = (height * 0.68) / (ticks + 1);
      for (let i = 1; i <= ticks; i++) {
        const y = start + step * i;
        addLineSegments(group, [-width * 0.18, y, 0, width * 0.18, y, 0], material);
      }
    }
  }

  function addArcStroke(group, radius, startAngle, endAngle, material, thickness = 0.12) {
    const points = [];
    const steps = 18;
    for (let i = 0; i <= steps; i++) {
      const t = i / steps;
      const angle = startAngle + (endAngle - startAngle) * t;
      points.push(new THREE.Vector3(Math.cos(angle) * radius, Math.sin(angle) * radius, 0));
    }
    const geometry = new THREE.BufferGeometry().setFromPoints(points);
    disposables.geometries.add(geometry);
    group.add(new THREE.Line(geometry, material));

    if (thickness > 0) {
      const innerRadius = Math.max(radius - thickness, 0.02);
      const inner = [];
      for (let i = 0; i <= steps; i++) {
        const t = i / steps;
        const angle = startAngle + (endAngle - startAngle) * t;
        inner.push(new THREE.Vector3(Math.cos(angle) * innerRadius, Math.sin(angle) * innerRadius, 0));
      }
      const innerGeometry = new THREE.BufferGeometry().setFromPoints(inner);
      disposables.geometries.add(innerGeometry);
      group.add(new THREE.Line(innerGeometry, material));
    }
  }

  function addFillRect(group, width, height, material) {
    const geometry = new THREE.PlaneGeometry(width, height);
    disposables.geometries.add(geometry);
    group.add(new THREE.Mesh(geometry, material));
  }

  function addLeg(group, x, y, length, material) {
    addLineSegments(group, [x, y, 0, x + (x < 0 ? -0.05 : 0.05), y - length, 0], material);
  }

  function addLineSegments(group, positions, material) {
    const geometry = new THREE.BufferGeometry();
    geometry.setAttribute('position', new THREE.Float32BufferAttribute(positions, 3));
    disposables.geometries.add(geometry);
    group.add(new THREE.LineSegments(geometry, material));
  }

  function buildRoundedRectPoints(width, height, radius, offsetX = 0, offsetY = 0, segments = 10) {
    const hw = width * 0.5;
    const hh = height * 0.5;
    const r = Math.max(0.01, Math.min(radius, hw, hh));
    const points = [];

    const corners = [
      { cx: offsetX + hw - r, cy: offsetY + hh - r, start: 0, end: Math.PI * 0.5 },
      { cx: offsetX - hw + r, cy: offsetY + hh - r, start: Math.PI * 0.5, end: Math.PI },
      { cx: offsetX - hw + r, cy: offsetY - hh + r, start: Math.PI, end: Math.PI * 1.5 },
      { cx: offsetX + hw - r, cy: offsetY - hh + r, start: Math.PI * 1.5, end: TAU },
    ];

    corners.forEach((corner) => {
      for (let i = 0; i <= segments; i++) {
        const t = i / segments;
        const angle = corner.start + (corner.end - corner.start) * t;
        points.push(new THREE.Vector3(corner.cx + Math.cos(angle) * r, corner.cy + Math.sin(angle) * r, 0));
      }
    });

    return points;
  }

  function randomAnchor() {
    for (let attempt = 0; attempt < 16; attempt++) {
      const x = randomBetween(-0.72, 0.72);
      const y = randomBetween(-0.72, 0.72);
      const insideCenter = Math.abs(x) < 0.16 && Math.abs(y) < 0.14;
      if (!insideCenter) {
        return { x, y };
      }
      if (Math.random() > 0.78) {
        return { x, y };
      }
    }
    return { x: randomBetween(-0.72, 0.72), y: randomBetween(-0.72, 0.72) };
  }

  function pickLayerIndex() {
    const random = Math.random();
    if (random < 0.34) {
      return 0;
    }
    if (random < 0.68) {
      return 1;
    }
    return 2;
  }

function pickPresetWeighted(presets) {
    if (!presets || presets.length === 0) {
      return null;
    }

    let total = 0;
    presets.forEach((preset) => {
      total += preset.weight || 1;
    });

    let random = Math.random() * total;
    for (let i = 0; i < presets.length; i++) {
      random -= presets[i].weight || 1;
      if (random <= 0) {
        return presets[i];
      }
    }

  return presets[presets.length - 1];
}

function expandPresetVariants(presets, fallbackSizeVariants = [1]) {
  if (!Array.isArray(presets) || presets.length === 0) {
    return [];
  }

  const expanded = [];
  presets.forEach((preset) => {
    const sizeVariants =
      Array.isArray(preset.sizeVariants) && preset.sizeVariants.length > 0
        ? preset.sizeVariants
        : fallbackSizeVariants;
    const variantWeights = Array.isArray(preset.variantWeights) ? preset.variantWeights : [];

    sizeVariants.forEach((factor, index) => {
      expanded.push({
        ...preset,
        name: `${preset.name}-v${index + 1}`,
        family: preset.family || preset.name,
        sizeBoost: (preset.sizeBoost || 1) * factor,
        weight: (preset.weight || 1) * (variantWeights[index] || 1),
      });
    });
  });

  return expanded;
}

function buildBalancedPresetQueue(totalCount, presets) {
  if (!Array.isArray(presets) || presets.length === 0 || totalCount <= 0) {
    return [];
  }

  const familyMap = new Map();
  presets.forEach((preset) => {
    const key = preset.family || preset.name;
    if (!familyMap.has(key)) {
      familyMap.set(key, []);
    }
    familyMap.get(key).push(preset);
  });

  const families = Array.from(familyMap.keys());
  if (families.length === 0) {
    return [];
  }

  const baseCount = Math.floor(totalCount / families.length);
  let remainder = totalCount % families.length;
  const queue = [];

  families.forEach((family) => {
    const familyPresets = familyMap.get(family);
    const amount = baseCount + (remainder > 0 ? 1 : 0);
    remainder = Math.max(0, remainder - 1);

    for (let i = 0; i < amount; i++) {
      const selected = pickPresetWeighted(familyPresets);
      if (selected) {
        queue.push(selected);
      }
    }
  });

  shuffleArray(queue);
  return queue;
}

function shuffleArray(array) {
  for (let i = array.length - 1; i > 0; i--) {
    const j = Math.floor(Math.random() * (i + 1));
    [array[i], array[j]] = [array[j], array[i]];
  }
}
}

function drawDigitalTime(clock, now, blinkColon) {
  if (!clock.ctx) {
    return 'none';
  }

  if (clock.family === 'casio-classic') {
    return drawCasioClassicTime(clock, now, blinkColon);
  }
  if (clock.family === 'back-to-future') {
    return drawBackToFutureTime(clock, now, blinkColon);
  }

  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  const colon = blinkColon ? ':' : ' ';
  const value = clock.useSeconds ? `${hh}${colon}${mm}${colon}${ss}` : `${hh}${colon}${mm}`;
  const ctx = clock.ctx;
  const { width, height } = clock.canvas;

  if (clock.segmentDisplay) {
    const digitsFrame = clock.useSeconds ? `${hh}${mm}${ss}` : `${hh}${mm}`;
    if (digitsFrame === clock.lastDigitsFrame && blinkColon === clock.lastColonState) {
      return 'none';
    }

    const segmentOptions = {
      onColor: clock.segmentOnColor || clock.color,
      offColor: clock.segmentOffColor || 'rgba(190, 210, 235, 0.15)',
    };

    if (digitsFrame !== clock.lastDigitsFrame || !clock.segmentLayout) {
      ctx.clearRect(0, 0, width, height);
      clock.segmentLayout = drawSevenSegmentDisplay(ctx, value, width, height, segmentOptions);
      clock.lastDigitsFrame = digitsFrame;
      clock.lastColonState = blinkColon;
      clock.lastFrame = value;
      clock.texture.needsUpdate = true;
      return 'digits';
    } else {
      drawSevenSegmentColons(ctx, value, clock.segmentLayout, segmentOptions);
      clock.lastDigitsFrame = digitsFrame;
      clock.lastColonState = blinkColon;
      clock.lastFrame = value;
      clock.texture.needsUpdate = true;
      return 'colon';
    }
  } else {
    if (value === clock.lastFrame) {
      return 'none';
    }
    ctx.clearRect(0, 0, width, height);
    ctx.fillStyle = clock.color;
    ctx.font = '700 78px "IBM Plex Mono", monospace';
    ctx.textAlign = 'center';
    ctx.textBaseline = 'middle';
    ctx.fillText(value, width * 0.5, height * 0.55);
    clock.lastFrame = value;
    clock.texture.needsUpdate = true;
    return 'digits';
  }
  return 'none';
}

function drawCasioClassicTime(clock, now, blinkColon) {
  const hh = String(now.getHours()).padStart(2, '0');
  const mm = String(now.getMinutes()).padStart(2, '0');
  const ss = String(now.getSeconds()).padStart(2, '0');
  const colon = clock.blinkMainColon && !blinkColon ? ' ' : ':';
  const timeValue = `${hh}${colon}${mm}:${ss}`;
  const weekday = (clock.dayLabels || CASIO_WEEKDAY_SHORT)[now.getDay()] || 'SU';
  const dateValue = `${now.getMonth() + 1}-${now.getDate()}`;
  const frame = `${weekday}|${dateValue}|${timeValue}`;

  if (frame === clock.lastFrame) {
    return 'none';
  }

  const ctx = clock.ctx;
  const { width, height } = clock.canvas;
  const layout = clock.casioLayout || CASIO_CLASSIC_DEFAULT_LAYOUT;

  const dayRect = normalizeRect(layout.dayRect, width, height, { x: 0.08, y: 0.09, width: 0.3, height: 0.2 });
  const dateRect = normalizeRect(layout.dateRect, width, height, {
    x: 0.56,
    y: 0.09,
    width: 0.36,
    height: 0.2,
  });
  const timeRect = normalizeRect(layout.timeRect, width, height, {
    x: 0.06,
    y: 0.36,
    width: 0.88,
    height: 0.56,
  });

  ctx.clearRect(0, 0, width, height);

  // Tinte suave para simular la pantalla LCD.
  fillRoundRect(
    ctx,
    width * 0.01,
    height * 0.02,
    width * 0.98,
    height * 0.96,
    Math.max(4, Math.round(height * 0.05)),
    'rgba(234, 241, 236, 0.12)'
  );

  ctx.fillStyle = clock.color;
  ctx.textBaseline = 'middle';
  ctx.font = `700 ${Math.round(height * 0.22)}px "IBM Plex Mono", monospace`;
  ctx.textAlign = 'left';
  ctx.fillText(weekday, dayRect.x, dayRect.y + dayRect.height * 0.56);
  ctx.textAlign = 'right';
  ctx.fillText(dateValue, dateRect.x + dateRect.width, dateRect.y + dateRect.height * 0.56);

  ctx.strokeStyle = 'rgba(0, 0, 0, 0.18)';
  ctx.lineWidth = Math.max(1, Math.round(height * 0.014));
  ctx.beginPath();
  ctx.moveTo(width * 0.05, height * 0.33);
  ctx.lineTo(width * 0.95, height * 0.33);
  ctx.stroke();

  ctx.save();
  ctx.translate(timeRect.x, timeRect.y);
  drawSevenSegmentDisplay(ctx, timeValue, timeRect.width, timeRect.height, {
    onColor: clock.segmentOnColor || clock.color,
    offColor: clock.segmentOffColor || 'rgba(0, 0, 0, 0.08)',
  });
  ctx.restore();

  clock.segmentLayout = null;
  clock.lastDigitsFrame = `${hh}${mm}${ss}`;
  clock.lastColonState = blinkColon;
  clock.lastFrame = frame;
  clock.texture.needsUpdate = true;
  return 'digits';
}

function drawBackToFutureTime(clock, now, blinkColon) {
  const present = formatBackToFutureParts(now);
  const future = formatBackToFutureStaticParts(BACK_TO_FUTURE_STATIC.future);
  const past = formatBackToFutureStaticParts(BACK_TO_FUTURE_STATIC.past);
  const frameDigits = `${future.frame}|${present.frame}|${past.frame}`;
  const ctx = clock.ctx;
  const { width, height } = clock.canvas;
  const layout = clock.btfLayout || BACK_TO_FUTURE_DEFAULT_LAYOUT;
  const rowsSource = Array.isArray(layout.rows) && layout.rows.length >= 3 ? layout.rows : BACK_TO_FUTURE_DEFAULT_LAYOUT.rows;
  const colsSource =
    layout.columns && typeof layout.columns === 'object' ? layout.columns : BACK_TO_FUTURE_DEFAULT_LAYOUT.columns;
  const rows = rowsSource.map((row, index) =>
    normalizeRect(row, width, height, BACK_TO_FUTURE_DEFAULT_LAYOUT.rows[index])
  );
  const periodDots = resolveBackToFuturePeriodDots(
    layout.periodDots || BACK_TO_FUTURE_DEFAULT_LAYOUT.periodDots,
    width,
    height
  );

  const segmentOptions = {
    onColor: clock.segmentOnColor || 'rgba(255, 255, 255, 0.96)',
    offColor: clock.segmentOffColor || 'rgba(255, 255, 255, 0.08)',
    fitWidthRatio: 0.96,
    digitWidthCapRatio: 0.24,
    digitHeightRatio: 0.8,
    digitGapRatio: 0.12,
  };

  if (frameDigits !== clock.lastDigitsFrame || !clock.btfRects) {
    ctx.clearRect(0, 0, width, height);
    drawBackToFutureRow(ctx, rows[0], future, colsSource, segmentOptions, true, false, null);
    const presentColonRect = drawBackToFutureRow(
      ctx,
      rows[1],
      present,
      colsSource,
      segmentOptions,
      blinkColon,
      true,
      periodDots
    );
    drawBackToFutureRow(ctx, rows[2], past, colsSource, segmentOptions, true, false, null);
    clock.btfRects = { presentColonRect };
    clock.lastDigitsFrame = frameDigits;
    clock.lastColonState = blinkColon;
    clock.lastFrame = `${frameDigits}|${blinkColon ? 1 : 0}`;
    clock.texture.needsUpdate = true;
    return 'digits';
  }

  if (clock.lastColonState !== blinkColon) {
    const clearPadding = 2;
    const activeColonRect =
      clock.btfRects?.presentColonRect ||
      resolveBackToFutureRowRect(rows[1], colsSource.colonRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.colonRect);
    ctx.clearRect(
      activeColonRect.x - clearPadding,
      activeColonRect.y - clearPadding,
      activeColonRect.width + clearPadding * 2,
      activeColonRect.height + clearPadding * 2
    );
    drawColonSegment(
      ctx,
      activeColonRect.x,
      activeColonRect.y,
      activeColonRect.width,
      activeColonRect.height,
      segmentOptions.onColor,
      segmentOptions.offColor,
      blinkColon
    );
    clock.lastColonState = blinkColon;
    clock.lastFrame = `${frameDigits}|${blinkColon ? 1 : 0}`;
    clock.texture.needsUpdate = true;
    return 'colon';
  }

  return 'none';
}

function drawBackToFutureRow(
  ctx,
  rowRect,
  parts,
  columns,
  segmentOptions,
  colonActive,
  showPeriodIndicator,
  periodDots
) {
  const cols = columns || BACK_TO_FUTURE_DEFAULT_LAYOUT.columns;
  const monthRect = resolveBackToFutureRowRect(rowRect, cols.monthRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.monthRect);
  const dayRect = resolveBackToFutureRowRect(rowRect, cols.dayRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.dayRect);
  const yearRect = resolveBackToFutureRowRect(rowRect, cols.yearRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.yearRect);
  const hourRect = resolveBackToFutureRowRect(rowRect, cols.hourRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.hourRect);
  const minuteRect = resolveBackToFutureRowRect(
    rowRect,
    cols.minuteRect,
    BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.minuteRect
  );
  const colonRect = resolveBackToFutureRowRect(rowRect, cols.colonRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.colonRect);
  const ampmRect = resolveBackToFutureRowRect(rowRect, cols.ampmRect, BACK_TO_FUTURE_DEFAULT_LAYOUT.columns.ampmRect);
  const monthDayOptions = {
    ...segmentOptions,
    fitWidthRatio: 0.985,
    digitWidthCapRatio: 0.3,
    digitGapRatio: 0.1,
  };
  const hourMinuteOptions = {
    ...segmentOptions,
    fitWidthRatio: 0.99,
    digitWidthCapRatio: 0.42,
    digitHeightRatio: 0.88,
    digitGapRatio: 0.08,
  };

  drawSevenSegmentDisplay(
    ctx,
    parts.month,
    monthRect.width,
    monthRect.height,
    monthDayOptions,
    monthRect.x,
    monthRect.y
  );
  drawSevenSegmentDisplay(
    ctx,
    parts.day,
    dayRect.width,
    dayRect.height,
    monthDayOptions,
    dayRect.x,
    dayRect.y
  );
  drawSevenSegmentDisplay(ctx, parts.year, yearRect.width, yearRect.height, segmentOptions, yearRect.x, yearRect.y);
  drawSevenSegmentDisplay(
    ctx,
    parts.hour,
    hourRect.width,
    hourRect.height,
    hourMinuteOptions,
    hourRect.x,
    hourRect.y
  );
  drawSevenSegmentDisplay(
    ctx,
    parts.minute,
    minuteRect.width,
    minuteRect.height,
    hourMinuteOptions,
    minuteRect.x,
    minuteRect.y
  );

  drawColonSegment(
    ctx,
    colonRect.x,
    colonRect.y,
    colonRect.width,
    colonRect.height,
    segmentOptions.onColor,
    segmentOptions.offColor,
    colonActive
  );

  drawBackToFutureAmPm(
    ctx,
    ampmRect,
    parts.isPm,
    segmentOptions.onColor,
    segmentOptions.offColor,
    Boolean(showPeriodIndicator),
    periodDots
  );

  return colonRect;
}

function drawBackToFutureAmPm(ctx, ampmRect, isPm, onColor, offColor, showIndicator = false, periodDots = null) {
  const amY = ampmRect.y + ampmRect.height * 0.29;
  const pmY = ampmRect.y + ampmRect.height * 0.74;

  if (!showIndicator) {
    return;
  }

  const fallbackDotX = ampmRect.x + ampmRect.width * 0.92;
  const fallbackAmY = amY;
  const fallbackPmY = pmY;
  const dotRadius = Math.max(2, ampmRect.width * 0.12);
  const amPoint = periodDots?.am || { x: fallbackDotX, y: fallbackAmY };
  const pmPoint = periodDots?.pm || { x: fallbackDotX, y: fallbackPmY };
  const activePoint = isPm ? pmPoint : amPoint;

  // Punto activo negro (solo en PRESENT TIME).
  ctx.fillStyle = '#000000';
  ctx.beginPath();
  ctx.arc(activePoint.x, activePoint.y, dotRadius, 0, TAU);
  ctx.fill();
}

function resolveBackToFuturePeriodDots(periodDots, width, height) {
  if (!periodDots || typeof periodDots !== 'object') {
    return null;
  }

  const toPoint = (point) => {
    if (!point || typeof point !== 'object') {
      return null;
    }
    const x = Number(point.x);
    const y = Number(point.y);
    if (!Number.isFinite(x) || !Number.isFinite(y)) {
      return null;
    }
    return {
      x: x * width,
      y: y * height,
    };
  };

  const am = toPoint(periodDots.am);
  const pm = toPoint(periodDots.pm);
  if (!am || !pm) {
    return null;
  }
  return { am, pm };
}

function resolveBackToFutureRowRect(rowRect, relativeRect, fallbackRelativeRect) {
  const source =
    relativeRect && typeof relativeRect === 'object' ? relativeRect : fallbackRelativeRect || { x: 0, y: 0, width: 1, height: 1 };
  const safe = {
    x: Number.isFinite(Number(source.x)) ? Number(source.x) : 0,
    y: Number.isFinite(Number(source.y)) ? Number(source.y) : 0,
    width: Number.isFinite(Number(source.width)) ? Number(source.width) : 1,
    height: Number.isFinite(Number(source.height)) ? Number(source.height) : 1,
  };

  return {
    x: rowRect.x + safe.x * rowRect.width,
    y: rowRect.y + safe.y * rowRect.height,
    width: Math.max(6, safe.width * rowRect.width),
    height: Math.max(6, safe.height * rowRect.height),
  };
}

function formatBackToFutureParts(dateObj) {
  const month = String(dateObj.getMonth() + 1).padStart(2, '0');
  const day = String(dateObj.getDate()).padStart(2, '0');
  const year = String(dateObj.getFullYear());
  const minute = String(dateObj.getMinutes()).padStart(2, '0');
  const hour24 = dateObj.getHours();
  const isPm = hour24 >= 12;
  const hour12 = hour24 % 12 || 12;
  const hour = String(hour12).padStart(2, '0');
  return {
    month,
    day,
    year,
    hour,
    minute,
    isPm,
    frame: `${month}${day}${year}${hour}${minute}${isPm ? 'P' : 'A'}`,
  };
}

function formatBackToFutureStaticParts(staticConfig) {
  const month = String(staticConfig.month).padStart(2, '0');
  const day = String(staticConfig.day).padStart(2, '0');
  const year = String(staticConfig.year);
  const minute = String(staticConfig.minute).padStart(2, '0');
  const hour24 = staticConfig.hour24;
  const isPm = hour24 >= 12;
  const hour12 = hour24 % 12 || 12;
  const hour = String(hour12).padStart(2, '0');
  return {
    month,
    day,
    year,
    hour,
    minute,
    isPm,
    frame: `${month}${day}${year}${hour}${minute}${isPm ? 'P' : 'A'}`,
  };
}

function normalizeRect(rect, width, height, fallback) {
  const source = rect && typeof rect === 'object' ? rect : fallback;
  const x = Number(source.x);
  const y = Number(source.y);
  const rectWidth = Number(source.width);
  const rectHeight = Number(source.height);
  if (![x, y, rectWidth, rectHeight].every(Number.isFinite)) {
    return normalizeRect(fallback, width, height, fallback);
  }
  return {
    x: x * width,
    y: y * height,
    width: Math.max(8, rectWidth * width),
    height: Math.max(6, rectHeight * height),
  };
}

function drawSevenSegmentDisplay(ctx, value, width, height, options = {}, offsetX = 0, offsetY = 0) {
  const layout = buildSevenSegmentLayout(value, width, height, offsetX, offsetY, options);
  layout.items.forEach((item) => {
    if (item.type === 'colon') {
      drawColonSegment(
        ctx,
        item.x,
        item.y,
        item.width,
        item.height,
        options.onColor,
        options.offColor,
        item.active
      );
      return;
    }

    drawSevenSegmentDigit(
      ctx,
      item.char,
      item.x,
      item.y,
      item.width,
      item.height,
      options.onColor,
      options.offColor
    );
  });
  return layout;
}

function drawSevenSegmentColons(ctx, value, layout, options = {}) {
  const currentLayout = layout || buildSevenSegmentLayout(value, ctx.canvas.width, ctx.canvas.height, 0, 0);
  const nextChars = value.split('');
  const clearPadding = 2;

  currentLayout.items.forEach((item, index) => {
    if (item.type !== 'colon') {
      return;
    }
    const char = nextChars[index] || ' ';
    const active = char === ':';
    ctx.clearRect(
      item.x - clearPadding,
      item.y - clearPadding,
      item.width + clearPadding * 2,
      item.height + clearPadding * 2
    );
    drawColonSegment(
      ctx,
      item.x,
      item.y,
      item.width,
      item.height,
      options.onColor,
      options.offColor,
      active
    );
    item.active = active;
  });
}

function buildSevenSegmentLayout(value, width, height, offsetX = 0, offsetY = 0, layoutOptions = {}) {
  const chars = value.split('');
  const colonCount = chars.reduce((acc, char) => (char === ':' || char === ' ' ? acc + 1 : acc), 0);
  const digitCount = chars.length - colonCount;

  // Ajuste de ancho para que combinaciones largas (ej. HH:MM:SS) no toquen bordes.
  const fitWidth = width * THREE.MathUtils.clamp(layoutOptions.fitWidthRatio ?? 0.92, 0.7, 1);
  const digitGapRatio = THREE.MathUtils.clamp(layoutOptions.digitGapRatio ?? 0.2, 0.05, 0.35);
  const colonWidthRatio = THREE.MathUtils.clamp(layoutOptions.colonWidthRatio ?? 0.38, 0.2, 0.55);
  const colonGapRatio = THREE.MathUtils.clamp(layoutOptions.colonGapRatio ?? 0.09, 0.02, 0.2);
  const digitHeightRatio = THREE.MathUtils.clamp(layoutOptions.digitHeightRatio ?? 0.74, 0.55, 0.92);
  const digitWidthCapRatio = THREE.MathUtils.clamp(layoutOptions.digitWidthCapRatio ?? 0.18, 0.12, 0.32);
  const widthFactor =
    digitCount +
    Math.max(0, digitCount - 1) * digitGapRatio +
    colonCount * (colonWidthRatio + colonGapRatio * 2);
  const fittedDigitWidth = fitWidth / Math.max(widthFactor, 1);
  const digitWidth = Math.max(8, Math.min(width * digitWidthCapRatio, fittedDigitWidth));
  const digitHeight = height * digitHeightRatio;
  const digitGap = digitWidth * digitGapRatio;
  const colonWidth = digitWidth * colonWidthRatio;
  const colonGap = digitWidth * colonGapRatio;

  const totalWidth =
    digitCount * digitWidth +
    Math.max(0, digitCount - 1) * digitGap +
    colonCount * (colonWidth + colonGap * 2);

  let cursorX = (width - totalWidth) * 0.5;
  const originY = (height - digitHeight) * 0.5;
  const items = [];

  chars.forEach((char) => {
    if (char === ':' || char === ' ') {
      items.push({
        type: 'colon',
        active: char === ':',
        x: offsetX + cursorX,
        y: offsetY + originY,
        width: colonWidth,
        height: digitHeight,
      });
      cursorX += colonWidth + colonGap * 2;
      return;
    }

    items.push({
      type: 'digit',
      char,
      x: offsetX + cursorX,
      y: offsetY + originY,
      width: digitWidth,
      height: digitHeight,
    });
    cursorX += digitWidth + digitGap;
  });

  return { items };
}

function drawSevenSegmentDigit(ctx, digit, x, y, width, height, onColor, offColor) {
  const segmentMap = {
    '0': ['a', 'b', 'c', 'd', 'e', 'f'],
    '1': ['b', 'c'],
    '2': ['a', 'b', 'g', 'e', 'd'],
    '3': ['a', 'b', 'g', 'c', 'd'],
    '4': ['f', 'g', 'b', 'c'],
    '5': ['a', 'f', 'g', 'c', 'd'],
    '6': ['a', 'f', 'g', 'e', 'c', 'd'],
    '7': ['a', 'b', 'c'],
    '8': ['a', 'b', 'c', 'd', 'e', 'f', 'g'],
    '9': ['a', 'b', 'c', 'd', 'f', 'g'],
  };
  const activeSegments = new Set(segmentMap[digit] || []);
  const thickness = Math.max(2, Math.round(Math.min(width, height) * 0.14));
  const half = height * 0.5;

  const segments = {
    a: [x + thickness, y, width - thickness * 2, thickness],
    d: [x + thickness, y + height - thickness, width - thickness * 2, thickness],
    g: [x + thickness, y + half - thickness * 0.5, width - thickness * 2, thickness],
    f: [x, y + thickness, thickness, half - thickness * 1.4],
    b: [x + width - thickness, y + thickness, thickness, half - thickness * 1.4],
    e: [x, y + half + thickness * 0.4, thickness, half - thickness * 1.4],
    c: [x + width - thickness, y + half + thickness * 0.4, thickness, half - thickness * 1.4],
  };

  Object.entries(segments).forEach(([key, rect]) => {
    const [rx, ry, rw, rh] = rect;
    fillRoundRect(ctx, rx, ry, rw, rh, thickness * 0.25, activeSegments.has(key) ? onColor : offColor);
  });
}

function drawColonSegment(ctx, x, y, width, height, onColor, offColor, active = true) {
  const radius = Math.max(2, Math.round(width * 0.38));
  const centerX = x + width * 0.5;
  const topY = y + height * 0.34;
  const bottomY = y + height * 0.66;
  ctx.fillStyle = active ? onColor : offColor;
  ctx.beginPath();
  ctx.arc(centerX, topY, radius, 0, TAU);
  ctx.arc(centerX, bottomY, radius, 0, TAU);
  ctx.fill();
}

function fillRoundRect(ctx, x, y, width, height, radius, color) {
  const r = Math.max(0, Math.min(radius, width * 0.5, height * 0.5));
  ctx.fillStyle = color;
  ctx.beginPath();
  ctx.moveTo(x + r, y);
  ctx.lineTo(x + width - r, y);
  ctx.quadraticCurveTo(x + width, y, x + width, y + r);
  ctx.lineTo(x + width, y + height - r);
  ctx.quadraticCurveTo(x + width, y + height, x + width - r, y + height);
  ctx.lineTo(x + r, y + height);
  ctx.quadraticCurveTo(x, y + height, x, y + height - r);
  ctx.lineTo(x, y + r);
  ctx.quadraticCurveTo(x, y, x + r, y);
  ctx.closePath();
  ctx.fill();
}

function resolveAniBackground01Config(config) {
  const globalConfig = window.aniBackground01Config || {};
  return { ...ANI_BACKGROUND01_DEFAULTS, ...globalConfig, ...config };
}

function ensureBackgroundCanvas(root) {
  let canvas = root.querySelector('.aniBackground01-canvas');
  if (canvas) {
    return canvas;
  }

  canvas = document.createElement('canvas');
  canvas.className = 'aniBackground01-canvas';
  canvas.setAttribute('aria-hidden', 'true');
  root.prepend(canvas);
  return canvas;
}

function buildSettings(tier, reducedMotion, config = ANI_BACKGROUND01_DEFAULTS) {
  const desktopCount = parseInt(String(config.countDesktop ?? ANI_BACKGROUND01_DEFAULTS.countDesktop), 10);
  const tabletCount = parseInt(String(config.countTablet ?? ANI_BACKGROUND01_DEFAULTS.countTablet), 10);
  const mobileCount = parseInt(String(config.countMobile ?? ANI_BACKGROUND01_DEFAULTS.countMobile), 10);
  const digitalRatio = parseFloat(String(config.digitalRatio ?? ANI_BACKGROUND01_DEFAULTS.digitalRatio));
  const opacityMin = parseFloat(String(config.opacityMin ?? ANI_BACKGROUND01_DEFAULTS.opacityMin));
  const opacityMax = parseFloat(String(config.opacityMax ?? ANI_BACKGROUND01_DEFAULTS.opacityMax));
  const sizeDesktop = parseFloat(String(config.sizeDesktop ?? ANI_BACKGROUND01_DEFAULTS.sizeDesktop));
  const sizeTablet = parseFloat(String(config.sizeTablet ?? ANI_BACKGROUND01_DEFAULTS.sizeTablet));
  const sizeMobile = parseFloat(String(config.sizeMobile ?? ANI_BACKGROUND01_DEFAULTS.sizeMobile));
  const sizeFactor = parseFloat(String(config.sizeFactor ?? ANI_BACKGROUND01_DEFAULTS.sizeFactor));
  const pointerRadiusFactor = parseFloat(
    String(config.pointerRadiusFactor ?? ANI_BACKGROUND01_DEFAULTS.pointerRadiusFactor)
  );
  const pointerForceFactor = parseFloat(
    String(config.pointerForceFactor ?? ANI_BACKGROUND01_DEFAULTS.pointerForceFactor)
  );
  const pointerLerp = parseFloat(String(config.pointerLerp ?? ANI_BACKGROUND01_DEFAULTS.pointerLerp));
  const hoverRepel = config.hoverRepel ?? ANI_BACKGROUND01_DEFAULTS.hoverRepel;
  const physicsSpring = parseFloat(String(config.physicsSpring ?? ANI_BACKGROUND01_DEFAULTS.physicsSpring));
  const physicsDamping = parseFloat(String(config.physicsDamping ?? ANI_BACKGROUND01_DEFAULTS.physicsDamping));
  const collisionBounce = parseFloat(String(config.collisionBounce ?? ANI_BACKGROUND01_DEFAULTS.collisionBounce));
  const collisionRadiusFactor = parseFloat(
    String(config.collisionRadiusFactor ?? ANI_BACKGROUND01_DEFAULTS.collisionRadiusFactor)
  );
  const maxVelocity = parseFloat(String(config.maxVelocity ?? ANI_BACKGROUND01_DEFAULTS.maxVelocity));
  const dragInertiaBoost = parseFloat(
    String(config.dragInertiaBoost ?? ANI_BACKGROUND01_DEFAULTS.dragInertiaBoost)
  );
  const enableDragThrow = config.enableDragThrow ?? ANI_BACKGROUND01_DEFAULTS.enableDragThrow;
  const throwFreeFlightSeconds = parseFloat(
    String(config.throwFreeFlightSeconds ?? ANI_BACKGROUND01_DEFAULTS.throwFreeFlightSeconds)
  );
  const throwDamping = parseFloat(String(config.throwDamping ?? ANI_BACKGROUND01_DEFAULTS.throwDamping));
  const dofEnabled = config.dofEnabled ?? ANI_BACKGROUND01_DEFAULTS.dofEnabled;
  const dofFocusZ = parseFloat(String(config.dofFocusZ ?? ANI_BACKGROUND01_DEFAULTS.dofFocusZ));
  const dofAperture = parseFloat(String(config.dofAperture ?? ANI_BACKGROUND01_DEFAULTS.dofAperture));
  const dofMaxBlur = parseFloat(String(config.dofMaxBlur ?? ANI_BACKGROUND01_DEFAULTS.dofMaxBlur));
  const tiltMaxDeg = parseFloat(String(config.tiltMaxDeg ?? ANI_BACKGROUND01_DEFAULTS.tiltMaxDeg));
  const maxClocks = parseInt(String(config.maxClocks ?? ANI_BACKGROUND01_DEFAULTS.maxClocks), 10);

  const rawCount = tier === 'mobile' ? mobileCount : tier === 'tablet' ? tabletCount : desktopCount;
  const count = reducedMotion ? Math.max(12, Math.round(rawCount * 0.65)) : rawCount;

  return {
    clockCount: Math.max(8, Math.min(count, Math.max(8, maxClocks))),
    digitalRatio: THREE.MathUtils.clamp(digitalRatio, 0, 0.45),
    opacityMin: THREE.MathUtils.clamp(opacityMin, 0.2, 1),
    opacityMax: THREE.MathUtils.clamp(Math.max(opacityMax, opacityMin), 0.2, 1),
    sizeMultiplier: THREE.MathUtils.clamp(
      THREE.MathUtils.clamp(tier === 'mobile' ? sizeMobile : tier === 'tablet' ? sizeTablet : sizeDesktop, 0.6, 2.4) *
        THREE.MathUtils.clamp(sizeFactor, 0.7, 1.35),
      0.6,
      2.8
    ),
    pointerRadiusFactor: THREE.MathUtils.clamp(pointerRadiusFactor, 0.6, 2.4),
    pointerForceFactor: THREE.MathUtils.clamp(pointerForceFactor, 0.4, 3.5),
    pointerLerp: THREE.MathUtils.clamp(pointerLerp, 0.08, 0.5),
    hoverRepel: Boolean(hoverRepel),
    physicsSpring: THREE.MathUtils.clamp(physicsSpring, 0.005, 0.12),
    physicsDamping: THREE.MathUtils.clamp(physicsDamping, 0.7, 0.98),
    collisionBounce: THREE.MathUtils.clamp(collisionBounce, 0, 1.4),
    collisionRadiusFactor: THREE.MathUtils.clamp(collisionRadiusFactor, 0.14, 0.42),
    maxVelocity: THREE.MathUtils.clamp(maxVelocity, 12, 120),
    dragInertiaBoost: THREE.MathUtils.clamp(dragInertiaBoost, 0.4, 2.4),
    enableDragThrow: Boolean(enableDragThrow),
    throwFreeFlightSeconds: THREE.MathUtils.clamp(throwFreeFlightSeconds, 0.2, 12),
    throwDamping: THREE.MathUtils.clamp(throwDamping, 0.88, 0.998),
    dofEnabled: Boolean(dofEnabled) && !reducedMotion && tier !== 'mobile',
    dofFocusZ: THREE.MathUtils.clamp(dofFocusZ, -300, 300),
    dofAperture: THREE.MathUtils.clamp(dofAperture, 0.000005, 0.002),
    dofMaxBlur: THREE.MathUtils.clamp(dofMaxBlur, 0.001, 0.02),
    tiltMaxRad: THREE.MathUtils.degToRad(THREE.MathUtils.clamp(tiltMaxDeg, 0, 40)),
    reducedMotion,
  };
}

function parseColorVar(element, cssVar, fallback) {
  const value = getComputedStyle(element).getPropertyValue(cssVar).trim() || fallback;
  return new THREE.Color(value);
}

function toCssColor(color) {
  return `rgb(${Math.round(color.r * 255)}, ${Math.round(color.g * 255)}, ${Math.round(color.b * 255)})`;
}

function getViewportTier(width) {
  if (width < MOBILE_BP) {
    return 'mobile';
  }
  if (width < TABLET_BP) {
    return 'tablet';
  }
  return 'desktop';
}

function randomBetween(min, max) {
  return min + Math.random() * (max - min);
}

function unwrapClockAngle(previousAngle, nextAngleWrapped) {
  const previousWrapped = ((previousAngle % TAU) + TAU) % TAU;
  const normalizedNext = ((nextAngleWrapped % TAU) + TAU) % TAU;

  let delta = normalizedNext - previousWrapped;
  if (delta > Math.PI) {
    delta -= TAU;
  } else if (delta < -Math.PI) {
    delta += TAU;
  }

  return previousAngle + delta;
}

function getAnalogPresets() {
  return [
    {
      name: 'fifth-avenue-ny',
      family: 'fifth-avenue',
      famous: true,
      caseShape: 'round',
      width: 1.18,
      height: 1.18,
      markers: 12,
      markerDepth: 0.1,
      doubleRing: true,
      extra: 'fifth-avenue',
      handStyle: 'fifth-avenue-image',
      hourWidth: 0.058,
      hourLength: 0.3,
      minuteWidth: 0.036,
      minuteLength: 0.43,
      secondWidth: 0.009,
      secondLength: 0.48,
      centerRadius: 0.028,
      hourEase: 0.28,
      minuteEase: 0.24,
      secondEase: 0.14,
      sizeBoost: 1.24,
      weight: 1,
      sizeVariants: [0.56, 0.78, 1, 1.3, 1.68],
      variantWeights: [0.75, 1, 1.1, 1, 0.78],
      caseImage: '/assets/img/resources/aniBackground01/clock-fifth-avenue.png',
      caseImageWidth: 2.1,
      caseImageHeight: 2.73,
      caseImageAspect: 979 / 1273,
      caseOpacity: 1,
      caseImageAnchorX: 484 / 978,
      caseImageAnchorY: 592 / 1272,
      caseImageOffsetY: 0,
      hourHandImage: '/assets/img/resources/aniBackground01/clock-fifth-avenue-hours.png',
      hourHandImageWidth: 0.145,
      hourHandImageHeight: 0.703,
      hourHandImageAspect: 45 / 218,
      hourHandImageAnchorX: 0.4889,
      hourHandImageAnchorY: 0.7844,
      minuteHandImage: '/assets/img/resources/aniBackground01/clock-fifth-avenue-minuts.png',
      minuteHandImageWidth: 0.1,
      minuteHandImageHeight: 0.684,
      minuteHandImageAspect: 31 / 212,
      minuteHandImageAnchorX: 0.4677,
      minuteHandImageAnchorY: 0.7925,
      secondHandColor: '#000000',
    },
    {
      name: 'bigben-london',
      family: 'bigben',
      famous: true,
      caseShape: 'round',
      width: 1.22,
      height: 1.22,
      markers: 12,
      markerDepth: 0.1,
      doubleRing: false,
      extra: 'none',
      handStyle: 'bigben-image',
      hourWidth: 0.03,
      hourLength: 0.26,
      minuteWidth: 0.022,
      minuteLength: 0.34,
      secondWidth: 0.0048,
      secondLength: 0.19,
      secondMinWidth: 0.0028,
      secondMatchMinute: true,
      centerRadius: 0.022,
      hourEase: 0.26,
      minuteEase: 0.22,
      secondEase: 0.13,
      sizeBoost: 1.16,
      weight: 1,
      sizeVariants: [0.58, 0.82, 1, 1.28, 1.62],
      variantWeights: [0.76, 1, 1.1, 1, 0.8],
      caseImage: '/assets/img/resources/aniBackground01/clock-bigben.png',
      caseImageWidth: 1.442,
      caseImageHeight: 1.35,
      caseImageAspect: 531 / 497,
      caseOpacity: 1,
      caseImageAnchorX: 0.4981,
      caseImageAnchorY: 0.5,
      caseImageOffsetY: 0,
      hourHandImage: '/assets/img/resources/aniBackground01/clock-bigben-hours.png',
      hourHandImageWidth: 0.0923,
      hourHandImageHeight: 0.4889,
      hourHandImageAspect: 34 / 180,
      hourHandImageAnchorX: 0.4853,
      hourHandImageAnchorY: 0.8139,
      hourHandOpacityMul: 6.5,
      hourHandOpacityMax: 1,
      minuteHandImage: '/assets/img/resources/aniBackground01/clock-bigben-minuts.png',
      minuteHandImageWidth: 0.0625,
      minuteHandImageHeight: 0.617,
      minuteHandImageAspect: 23 / 227,
      minuteHandImageAnchorX: 0.5,
      minuteHandImageAnchorY: 0.8656,
      minuteHandOpacityMul: 6.5,
      minuteHandOpacityMax: 1,
      secondHandColor: '#000000',
      secondOpacity: 1,
    },
    {
      name: 'cocina-classic',
      family: 'cocina',
      famous: true,
      caseShape: 'round',
      width: 1.16,
      height: 1.16,
      markers: 0,
      markerDepth: 0.08,
      doubleRing: false,
      extra: 'none',
      hourWidth: 0.088,
      hourLength: 0.23,
      minuteWidth: 0.054,
      minuteLength: 0.34,
      secondWidth: 0.013,
      secondLength: 0.39,
      centerRadius: 0.021,
      hourEase: 0.24,
      minuteEase: 0.2,
      secondEase: 0.12,
      sizeBoost: 1.08,
      weight: 1,
      sizeVariants: [0.6, 0.84, 1, 1.24, 1.56],
      variantWeights: [0.8, 1, 1.1, 1, 0.8],
      handColor: '#0a0a0a',
      secondHandColor: '#111111',
      caseImage: '/assets/img/resources/aniBackground01/clock-cocina.png',
      caseImageWidth: 1.65,
      caseImageHeight: 1.6,
      caseImageAspect: 712 / 691,
      caseOpacity: 1,
      caseImageAnchorX: 342 / 711,
      caseImageAnchorY: 326 / 690,
      caseImageOffsetY: 0,
    },
    {
      name: 'dali-melted',
      family: 'dali',
      famous: true,
      caseShape: 'round',
      width: 1.1,
      height: 1.66,
      markers: 0,
      markerDepth: 0.08,
      doubleRing: false,
      extra: 'none',
      hourWidth: 0.03,
      hourLength: 0.19,
      minuteWidth: 0.02,
      minuteLength: 0.29,
      secondWidth: 0.005,
      secondLength: 0.32,
      centerRadius: 0.016,
      hourEase: 0.24,
      minuteEase: 0.2,
      secondEase: 0.11,
      sizeBoost: 1.06,
      weight: 1,
      sizeVariants: [0.56, 0.8, 1, 1.24, 1.52],
      variantWeights: [0.78, 1, 1.1, 1, 0.8],
      handColor: '#0a0a0a',
      secondHandColor: '#000000',
      caseImage: '/assets/img/resources/aniBackground01/clock-dali.png',
      caseImageWidth: 1.86,
      caseImageHeight: 2.94,
      caseImageAspect: 762 / 1206,
      caseOpacity: 1,
      caseImageAnchorX: 405 / 761,
      caseImageAnchorY: 656 / 1205,
      caseImageOffsetY: 0,
    },
    {
      name: 'despertador-clasico',
      family: 'despertador-clasico',
      famous: true,
      caseShape: 'round',
      width: 1.08,
      height: 1.32,
      markers: 0,
      markerDepth: 0.08,
      doubleRing: false,
      extra: 'none',
      hourWidth: 0.09,
      hourLength: 0.24,
      minuteWidth: 0.056,
      minuteLength: 0.36,
      secondWidth: 0.012,
      secondLength: 0.38,
      centerRadius: 0.02,
      hourEase: 0.24,
      minuteEase: 0.2,
      secondEase: 0.12,
      sizeBoost: 1.1,
      weight: 1,
      sizeVariants: [0.56, 0.8, 1, 1.28, 1.64],
      variantWeights: [0.76, 1, 1.1, 1, 0.82],
      handColor: '#0a0a0a',
      secondHandColor: '#141414',
      caseImage: '/assets/img/resources/aniBackground01/clock-despertador-clasico.png',
      caseImageWidth: 1.7,
      caseImageHeight: 2.48,
      caseImageAspect: 564 / 824,
      caseOpacity: 1,
      caseImageAnchorX: 289 / 563,
      caseImageAnchorY: 481 / 823,
      caseImageOffsetY: 0,
    },
  ];
}

function getDigitalPresets() {
  return [
    {
      name: 'wake-up-neo',
      family: 'wake-up-neo',
      famous: true,
      bodyShape: 'rounded',
      width: 1.8,
      height: 0.92,
      cornerRadius: 0.18,
      showSeconds: false,
      segmentDisplay: true,
      segmentOnColor: 'rgba(255, 255, 255, 0.95)',
      segmentOffColor: 'rgba(255, 255, 255, 0.07)',
      textColor: '#ffffff',
      textCanvasWidth: 640,
      textCanvasHeight: 220,
      sizeBoost: 1.22,
      weight: 1,
      sizeVariants: [0.74, 1, 1.24],
      variantWeights: [0.9, 1.1, 0.9],
      caseImage: '/assets/img/resources/aniBackground01/clock-wake-up-neo.png',
      caseImageWidth: 2.96,
      caseImageHeight: 1.51,
      caseImageAspect: 1380 / 705,
      caseOpacity: 1,
      caseImageAnchorX: 0.5,
      caseImageAnchorY: 0.5,
      caseImageOffsetY: 0,
      caseImagePixelWidth: 1380,
      caseImagePixelHeight: 705,
      screenRect: {
        left: 313,
        right: 610,
        top: 450,
        bottom: 526,
      },
    },
    {
      name: 'casio-classic',
      family: 'casio-classic',
      famous: true,
      bodyShape: 'rounded',
      width: 1.18,
      height: 1.64,
      cornerRadius: 0.2,
      showSeconds: true,
      segmentDisplay: true,
      blinkMainColon: false,
      segmentOnColor: 'rgba(0, 0, 0, 0.92)',
      segmentOffColor: 'rgba(0, 0, 0, 0.08)',
      textColor: '#0b0b0b',
      textCanvasWidth: 720,
      textCanvasHeight: 390,
      sizeBoost: 1.08,
      weight: 1,
      sizeVariants: [0.58, 0.78, 1, 1.24, 1.56],
      variantWeights: [0.76, 1, 1.08, 1, 0.78],
      caseImage: '/assets/img/resources/aniBackground01/clock-casio-clasic.png',
      caseImageWidth: 1.72,
      caseImageHeight: 2.31,
      caseImageAspect: 567 / 762,
      caseOpacity: 1,
      caseImageAnchorX: 0.5,
      caseImageAnchorY: 0.5,
      caseImageOffsetY: 0,
      caseImagePixelWidth: 567,
      caseImagePixelHeight: 762,
      screenRect: {
        left: 157,
        right: 362,
        top: 295,
        bottom: 406,
      },
      casioLayout: {
        dayRect: { x: 0.08, y: 0.08, width: 0.3, height: 0.2 },
        dateRect: { x: 0.55, y: 0.08, width: 0.37, height: 0.2 },
        timeRect: { x: 0.05, y: 0.35, width: 0.9, height: 0.58 },
      },
    },
    {
      name: 'back-to-future',
      family: 'back-to-future',
      famous: true,
      bodyShape: 'rounded',
      width: 2.34,
      height: 1.56,
      cornerRadius: 0.14,
      showSeconds: false,
      segmentDisplay: true,
      pulseOnDigits: false,
      segmentOnColor: 'rgba(255, 255, 255, 0.96)',
      segmentOffColor: 'rgba(255, 255, 255, 0.08)',
      textColor: '#ffffff',
      textCanvasWidth: 1800,
      textCanvasHeight: 880,
      sizeBoost: 1.1,
      weight: 1,
      sizeVariants: [0.58, 0.82, 1, 1.2, 1.42],
      variantWeights: [0.72, 1, 1.1, 1, 0.76],
      caseImage: '/assets/img/resources/aniBackground01/clock-back-to-future.png',
      caseImageWidth: 2.76,
      caseImageHeight: 1.84,
      caseImageAspect: 1536 / 1024,
      caseOpacity: 1,
      caseImageAnchorX: 0.5,
      caseImageAnchorY: 0.5,
      caseImageOffsetY: 0,
      caseImagePixelWidth: 1536,
      caseImagePixelHeight: 1024,
      screenRect: {
        left: 209,
        right: 1373,
        top: 212,
        bottom: 813,
      },
      btfLayout: {
        rows: [
          { x: 0, y: 0, width: 1, height: 0.158 },
          { x: 0, y: 0.411, width: 1, height: 0.158 },
          { x: 0, y: 0.842, width: 1, height: 0.158 },
        ],
        columns: {
          monthRect: { x: 0.001, y: 0, width: 0.176, height: 1 },
          dayRect: { x: 0.216, y: 0, width: 0.112, height: 1 },
          yearRect: { x: 0.365, y: 0, width: 0.241, height: 1 },
          ampmRect: { x: 0.657, y: 0.03, width: 0.08, height: 0.94 },
          hourRect: { x: 0.771, y: 0, width: 0.111, height: 1 },
          minuteRect: { x: 0.905, y: 0, width: 0.095, height: 1 },
          colonRect: { x: 0.892, y: 0.2, width: 0.009, height: 0.6 },
        },
        periodDots: {
          am: { x: 0.7148, y: 0.4842 },
          pm: { x: 0.7148, y: 0.6007 },
        },
      },
    },
  ];
}
