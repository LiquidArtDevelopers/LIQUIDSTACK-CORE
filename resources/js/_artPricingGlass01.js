import gsap from 'gsap';

const clamp = (value, min, max) => Math.min(max, Math.max(min, value));

const drawBackground = (section, canvas) => {
  const label = section.querySelector('[data-glass-title]');
  const text = label ? label.textContent.trim() : '';
  const rect = section.getBoundingClientRect();
  if (rect.width <= 1 || rect.height <= 1) return null;

  const dpr = Math.min(window.devicePixelRatio || 1, 2);
  const width = Math.max(1, Math.round(rect.width * dpr));
  const height = Math.max(1, Math.round(rect.height * dpr));

  canvas.width = width;
  canvas.height = height;
  canvas.style.width = `${rect.width}px`;
  canvas.style.height = `${rect.height}px`;

  const styles = getComputedStyle(section);
  const bgColor = styles.backgroundColor && styles.backgroundColor !== 'rgba(0, 0, 0, 0)'
    ? styles.backgroundColor
    : '#ffffff';
  const fontFamily = styles.getPropertyValue('--glass-title-font').trim() || 'antton';
  const fillColor = styles.getPropertyValue('--glass-title-color').trim() || '#1d1d1d';
  const scale = parseFloat(section.dataset.glassTextScale || '1') || 1;
  const baseSize = Math.min(rect.width * 0.78, rect.height * 0.7);
  let fontSize = clamp(baseSize * scale, 160, 820);

  const ctx = canvas.getContext('2d');
  if (!ctx) return null;
  ctx.setTransform(dpr, 0, 0, dpr, 0, 0);
  ctx.clearRect(0, 0, rect.width, rect.height);
  ctx.fillStyle = bgColor;
  ctx.fillRect(0, 0, rect.width, rect.height);
  const isCompact = window.innerWidth <= 1024;
  if (isCompact) {
    const sampleText = text || 'Matrix';
    ctx.font = `400 ${fontSize}px ${fontFamily}`;
    const metrics = ctx.measureText(sampleText);
    const measuredWidth = Math.max(metrics.width || 1, 1);
    const measuredHeight = Math.max(
      (metrics.actualBoundingBoxAscent ?? fontSize * 0.8) +
        (metrics.actualBoundingBoxDescent ?? fontSize * 0.2),
      1
    );
    const targetWidth = rect.width * 0.98;
    const targetHeight = rect.height * 0.7;
    const fitScale = Math.min(targetWidth / measuredWidth, targetHeight / measuredHeight);
    fontSize = clamp(fontSize * fitScale, 100, 900);
  }

  ctx.textAlign = 'center';
  ctx.textBaseline = 'middle';
  ctx.font = `400 ${fontSize}px ${fontFamily}`;
  ctx.fillStyle = fillColor;
  ctx.fillText(text || 'Matrix', rect.width * 0.5, rect.height * 0.26);

  const version = (canvas.dataset.glassVersion ? parseInt(canvas.dataset.glassVersion, 10) : 0) + 1;
  canvas.dataset.glassVersion = `${version}`;
  canvas.dataset.glassDpr = `${dpr}`;

  return { version, dpr };
};

const createShader = (gl, type, source) => {
  const shader = gl.createShader(type);
  if (!shader) return null;
  gl.shaderSource(shader, source);
  gl.compileShader(shader);
  if (!gl.getShaderParameter(shader, gl.COMPILE_STATUS)) {
    console.error('Glass shader error:', gl.getShaderInfoLog(shader));
    gl.deleteShader(shader);
    return null;
  }
  return shader;
};

const createProgram = (gl, vsSource, fsSource) => {
  const vs = createShader(gl, gl.VERTEX_SHADER, vsSource);
  const fs = createShader(gl, gl.FRAGMENT_SHADER, fsSource);
  if (!vs || !fs) return null;

  const program = gl.createProgram();
  if (!program) return null;
  gl.attachShader(program, vs);
  gl.attachShader(program, fs);
  gl.linkProgram(program);
  if (!gl.getProgramParameter(program, gl.LINK_STATUS)) {
    console.error('Glass program error:', gl.getProgramInfoLog(program));
    gl.deleteProgram(program);
    return null;
  }
  return program;
};

const buildConfig = (section) => ({
  strength: clamp(parseFloat(section.dataset.glassScale || '18'), 0, 80),
  blur: clamp(parseFloat(section.dataset.glassBlur || '2'), 0, 12),
  chroma: clamp(parseFloat(section.dataset.glassChroma || '0.8'), 0, 3),
  alpha: clamp(parseFloat(section.dataset.glassAlpha || '0.35'), 0, 1),
  noise: clamp(parseFloat(section.dataset.glassNoise || '0.008'), 0.001, 0.08),
  speed: clamp(parseFloat(section.dataset.glassSpeed || '0.6'), 0.05, 2),
});

class GlassCardGL {
  constructor(card, section, backgroundCanvas) {
    this.card = card;
    this.section = section;
    this.backgroundCanvas = backgroundCanvas;
    this.canvas = document.createElement('canvas');
    this.gl = null;
    this.program = null;
    this.uniforms = {};
    this.texture = null;
    this.lastVersion = -1;
    this.lastRect = null;
    this.dpr = 1;

    const container = card.querySelector('.artPricingGlass01-glass');
    if (container) {
      container.innerHTML = '';
      container.appendChild(this.canvas);
    }
    this.initGL();
  }

  initGL() {
    const gl = this.canvas.getContext('webgl', {
      alpha: true,
      antialias: true,
      premultipliedAlpha: false,
    });
    if (!gl) return;

    const vsSource = `
      attribute vec2 a_position;
      attribute vec2 a_texcoord;
      varying vec2 v_uv;
      void main() {
        v_uv = a_texcoord;
        gl_Position = vec4(a_position, 0.0, 1.0);
      }
    `;

    const fsSource = `
      precision mediump float;
      uniform sampler2D u_image;
      uniform vec2 u_textureSize;
      uniform vec2 u_cardSize;
      uniform vec2 u_cardPos;
      uniform float u_time;
      uniform float u_strength;
      uniform float u_blur;
      uniform float u_chroma;
      uniform float u_alpha;
      uniform float u_noise;
      uniform float u_speed;
      varying vec2 v_uv;

      float hash(vec2 p) {
        return fract(sin(dot(p, vec2(127.1, 311.7))) * 43758.5453123);
      }

      float noise(vec2 p) {
        vec2 i = floor(p);
        vec2 f = fract(p);
        float a = hash(i);
        float b = hash(i + vec2(1.0, 0.0));
        float c = hash(i + vec2(0.0, 1.0));
        float d = hash(i + vec2(1.0, 1.0));
        vec2 u = f * f * (3.0 - 2.0 * f);
        return mix(a, b, u.x) + (c - a) * u.y * (1.0 - u.x) + (d - b) * u.x * u.y;
      }

      void main() {
        vec2 uv = vec2(v_uv.x, 1.0 - v_uv.y);
        vec2 cardPixel = uv * u_cardSize;
        vec2 baseCoord = (u_cardPos + cardPixel) / u_textureSize;

        float t = u_time * u_speed;
        float n1 = noise(cardPixel * u_noise + vec2(t, t * 0.7));
        float n2 = noise(cardPixel * (u_noise * 1.3) + vec2(-t * 0.6, t));
        vec2 warp = vec2(n1 - 0.5, n2 - 0.5);

        float dist = distance(uv, vec2(0.5));
        float edge = smoothstep(0.2, 0.75, dist);
        float strength = u_strength * 0.0009 * mix(0.6, 1.4, edge);
        warp *= strength;

        vec2 coord = clamp(baseCoord + warp, vec2(0.0), vec2(1.0));

        vec2 texel = 1.0 / u_textureSize;
        float blurPx = u_blur * 1.6;
        vec2 blurStep = texel * blurPx;

        vec3 col = texture2D(u_image, coord).rgb;
        col += texture2D(u_image, coord + vec2(blurStep.x, 0.0)).rgb;
        col += texture2D(u_image, coord - vec2(blurStep.x, 0.0)).rgb;
        col += texture2D(u_image, coord + vec2(0.0, blurStep.y)).rgb;
        col += texture2D(u_image, coord - vec2(0.0, blurStep.y)).rgb;
        col *= 0.2;

        vec2 chromaOffset = texel * u_chroma * 0.9;
        float r = texture2D(u_image, coord + chromaOffset).r;
        float g = texture2D(u_image, coord - chromaOffset).g;
        vec3 chromaCol = vec3(r, g, col.b);

        vec3 finalCol = mix(vec3(1.0), chromaCol, u_alpha);
        gl_FragColor = vec4(finalCol, 1.0);
      }
    `;

    const program = createProgram(gl, vsSource, fsSource);
    if (!program) return;

    gl.useProgram(program);

    const positionBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
    gl.bufferData(
      gl.ARRAY_BUFFER,
      new Float32Array([
        -1, -1,
        1, -1,
        -1, 1,
        -1, 1,
        1, -1,
        1, 1,
      ]),
      gl.STATIC_DRAW
    );

    const texcoordBuffer = gl.createBuffer();
    gl.bindBuffer(gl.ARRAY_BUFFER, texcoordBuffer);
    gl.bufferData(
      gl.ARRAY_BUFFER,
      new Float32Array([
        0, 0,
        1, 0,
        0, 1,
        0, 1,
        1, 0,
        1, 1,
      ]),
      gl.STATIC_DRAW
    );

    const positionLoc = gl.getAttribLocation(program, 'a_position');
    const texcoordLoc = gl.getAttribLocation(program, 'a_texcoord');

    gl.bindBuffer(gl.ARRAY_BUFFER, positionBuffer);
    gl.enableVertexAttribArray(positionLoc);
    gl.vertexAttribPointer(positionLoc, 2, gl.FLOAT, false, 0, 0);

    gl.bindBuffer(gl.ARRAY_BUFFER, texcoordBuffer);
    gl.enableVertexAttribArray(texcoordLoc);
    gl.vertexAttribPointer(texcoordLoc, 2, gl.FLOAT, false, 0, 0);

    this.uniforms = {
      texture: gl.getUniformLocation(program, 'u_image'),
      textureSize: gl.getUniformLocation(program, 'u_textureSize'),
      cardSize: gl.getUniformLocation(program, 'u_cardSize'),
      cardPos: gl.getUniformLocation(program, 'u_cardPos'),
      time: gl.getUniformLocation(program, 'u_time'),
      strength: gl.getUniformLocation(program, 'u_strength'),
      blur: gl.getUniformLocation(program, 'u_blur'),
      chroma: gl.getUniformLocation(program, 'u_chroma'),
      alpha: gl.getUniformLocation(program, 'u_alpha'),
      noise: gl.getUniformLocation(program, 'u_noise'),
      speed: gl.getUniformLocation(program, 'u_speed'),
    };

    const texture = gl.createTexture();
    gl.bindTexture(gl.TEXTURE_2D, texture);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MIN_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_MAG_FILTER, gl.LINEAR);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_S, gl.CLAMP_TO_EDGE);
    gl.texParameteri(gl.TEXTURE_2D, gl.TEXTURE_WRAP_T, gl.CLAMP_TO_EDGE);
    gl.uniform1i(this.uniforms.texture, 0);

    gl.clearColor(0, 0, 0, 0);
    gl.enable(gl.BLEND);
    gl.blendFunc(gl.SRC_ALPHA, gl.ONE_MINUS_SRC_ALPHA);

    this.gl = gl;
    this.program = program;
    this.texture = texture;
  }

  updateSize() {
    const rect = this.card.getBoundingClientRect();
    const dpr = this.getDpr();
    const width = Math.max(1, Math.round(rect.width * dpr));
    const height = Math.max(1, Math.round(rect.height * dpr));

    if (this.lastRect && this.lastRect.width === width && this.lastRect.height === height) {
      return { rect, dpr };
    }

    this.canvas.width = width;
    this.canvas.height = height;
    this.canvas.style.width = `${rect.width}px`;
    this.canvas.style.height = `${rect.height}px`;
    if (this.gl) {
      this.gl.viewport(0, 0, width, height);
    }

    this.lastRect = { width, height };
    return { rect, dpr };
  }

  getDpr() {
    const dpr = this.backgroundCanvas?.dataset?.glassDpr;
    return dpr ? parseFloat(dpr) || 1 : 1;
  }

  updateTexture() {
    if (!this.gl || !this.backgroundCanvas) return;
    this.gl.activeTexture(this.gl.TEXTURE0);
    this.gl.bindTexture(this.gl.TEXTURE_2D, this.texture);
    this.gl.texImage2D(
      this.gl.TEXTURE_2D,
      0,
      this.gl.RGBA,
      this.gl.RGBA,
      this.gl.UNSIGNED_BYTE,
      this.backgroundCanvas
    );
  }

  render(time, sectionRect) {
    if (!this.gl || !this.backgroundCanvas) return;
    const { rect, dpr } = this.updateSize();
    if (!rect) return;

    const version = this.backgroundCanvas.dataset.glassVersion
      ? parseInt(this.backgroundCanvas.dataset.glassVersion, 10)
      : 0;
    if (version !== this.lastVersion) {
      this.updateTexture();
      this.lastVersion = version;
    }

    const config = buildConfig(this.section);

    const cardPosX = (rect.left - sectionRect.left) * dpr;
    const cardPosY = (rect.top - sectionRect.top) * dpr;
    const cardSizeX = rect.width * dpr;
    const cardSizeY = rect.height * dpr;

    const gl = this.gl;
    gl.useProgram(this.program);
    gl.uniform2f(this.uniforms.textureSize, this.backgroundCanvas.width, this.backgroundCanvas.height);
    gl.uniform2f(this.uniforms.cardSize, cardSizeX, cardSizeY);
    gl.uniform2f(this.uniforms.cardPos, cardPosX, cardPosY);
    gl.uniform1f(this.uniforms.time, 0.0);
    gl.uniform1f(this.uniforms.strength, config.strength);
    gl.uniform1f(this.uniforms.blur, config.blur);
    gl.uniform1f(this.uniforms.chroma, config.chroma);
    gl.uniform1f(this.uniforms.alpha, config.alpha);
    gl.uniform1f(this.uniforms.noise, config.noise);
    gl.uniform1f(this.uniforms.speed, config.speed);

    gl.clear(gl.COLOR_BUFFER_BIT);
    gl.drawArrays(gl.TRIANGLES, 0, 6);
  }
}

const setupTilt = (section, cards) => {
  const canHover = window.matchMedia('(hover: hover) and (pointer: fine)').matches;
  const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
  if (!canHover || reduceMotion) return;

  const maxTilt = clamp(parseFloat(section.dataset.glassTilt || '10'), 0, 20);
  const maxLift = clamp(parseFloat(section.dataset.glassLift || '12'), 0, 40);

  cards.forEach((card) => {
    if (card._tiltBound) return;
    card._tiltBound = true;

    gsap.set(card, { transformPerspective: 1000, transformStyle: 'preserve-3d' });

    const rotateX = gsap.quickTo(card, 'rotationX', { duration: 0.35, ease: 'power2.out' });
    const rotateY = gsap.quickTo(card, 'rotationY', { duration: 0.35, ease: 'power2.out' });
    const lift = gsap.quickTo(card, 'translateZ', { duration: 0.45, ease: 'power2.out' });

    const handleMove = (event) => {
      const rect = card.getBoundingClientRect();
      const relX = (event.clientX - rect.left) / rect.width;
      const relY = (event.clientY - rect.top) / rect.height;
      const x = clamp(relX - 0.5, -0.5, 0.5);
      const y = clamp(relY - 0.5, -0.5, 0.5);

      rotateY(x * maxTilt * 2);
      rotateX(-y * maxTilt * 2);
      lift(maxLift);
      card._glassActive = true;
    };

    const handleLeave = () => {
      rotateX(0);
      rotateY(0);
      lift(0);
      card._glassActive = false;
    };

    card.addEventListener('mousemove', handleMove);
    card.addEventListener('mouseleave', handleLeave);
  });
};

export default function initArtPricingGlass01() {
  const sections = Array.from(document.querySelectorAll('[data-glass-pricing]'));
  if (sections.length === 0) return;

  sections.forEach((section) => {
    const bgCanvas = section.querySelector('[data-glass-bg]');
    const cards = Array.from(section.querySelectorAll('[data-glass-card]'));
    if (!bgCanvas || cards.length === 0) return;

    const updateBackground = () => drawBackground(section, bgCanvas);
    updateBackground();

    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(() => updateBackground());
    }

    const glCards = cards
      .map((card) => new GlassCardGL(card, section, bgCanvas))
      .filter((card) => card.gl);

    setupTilt(section, cards);

    const render = (time) => {
      const sectionRect = section.getBoundingClientRect();
      glCards.forEach((card) => card.render(time, sectionRect));
      requestAnimationFrame(render);
    };
    requestAnimationFrame(render);

    const onResize = () => {
      updateBackground();
    };

    if (typeof ResizeObserver !== 'undefined') {
      const observer = new ResizeObserver(onResize);
      observer.observe(section);
    } else {
      window.addEventListener('resize', onResize);
    }

    window.addEventListener('app:languagechange', updateBackground);
  });
}
