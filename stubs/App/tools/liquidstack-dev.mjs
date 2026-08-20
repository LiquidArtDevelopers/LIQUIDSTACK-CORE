import { spawn } from "node:child_process";
import net from "node:net";
import { once } from "node:events";
import { fileURLToPath, pathToFileURL } from "node:url";
import { resolve } from "node:path";

const HOST = "localhost";
const DEFAULT_APP_PORT = 1309;
const DEFAULT_VITE_PORT = 5173;
const PHP_START_TIMEOUT_MS = 5_000;
const VITE_START_TIMEOUT_MS = 20_000;
const CHILD_STOP_TIMEOUT_MS = 2_000;

class PortUnavailableError extends Error {
  constructor(host, port, cause) {
    super(`El puerto ${host}:${port} no esta disponible.`);
    this.name = "PortUnavailableError";
    this.code = "LIQUIDSTACK_PORT_UNAVAILABLE";
    this.cause = cause;
  }
}

class PhpStartupError extends Error {
  constructor(message, { addressInUse = false } = {}) {
    super(message);
    this.name = "PhpStartupError";
    this.addressInUse = addressInUse;
  }
}

class ViteStartupError extends Error {
  constructor(message) {
    super(message);
    this.name = "ViteStartupError";
  }
}

class ShutdownRequestedError extends Error {
  constructor() {
    super("LiquidStack development shutdown requested.");
    this.name = "ShutdownRequestedError";
  }
}

function delay(milliseconds) {
  return new Promise((resolveDelay) => setTimeout(resolveDelay, milliseconds));
}

function errorMessage(error) {
  return error instanceof Error ? error.message : String(error);
}

export function attachStdinShutdown(
  input,
  onShutdown,
  { platform = process.platform } = {},
) {
  if (
    platform !== "win32"
    || !input
    || input.isTTY !== true
    || typeof input.on !== "function"
    || typeof input.off !== "function"
  ) {
    return () => {};
  }

  const previousFlowing = input.readableFlowing;
  const previousRaw = typeof input.isRaw === "boolean" ? input.isRaw : null;
  let rawChanged = false;
  let listenerAttached = false;
  let restored = false;
  const onData = (chunk) => {
    const containsEtx = Buffer.isBuffer(chunk)
      ? chunk.includes(0x03)
      : typeof chunk === "string" && chunk.includes("\x03");
    if (containsEtx) {
      void onShutdown(130);
    }
  };
  const restore = () => {
    if (restored) {
      return;
    }

    restored = true;
    if (listenerAttached) {
      try {
        input.off("data", onData);
      } catch {}
    }
    if (rawChanged) {
      try {
        input.setRawMode(previousRaw === true);
      } catch {}
    }

    try {
      if (previousFlowing === true) {
        input.resume?.();
      } else {
        // Node has no public transition back to readableFlowing=null. A TTY
        // that was not flowing is therefore restored to the equivalent safe
        // paused state so it cannot keep npm/cmd alive after cleanup.
        input.pause?.();
      }
    } catch {}
  };

  try {
    if (
      typeof input.setRawMode === "function"
      && previousRaw !== true
    ) {
      rawChanged = true;
      input.setRawMode(true);
    }
    listenerAttached = true;
    input.on("data", onData);
    input.resume?.();
  } catch {
    restore();
    return () => {};
  }

  return restore;
}

export function readPortOverride(environment, name, fallback) {
  const raw = environment[name];

  if (raw === undefined || raw === null || String(raw).trim() === "") {
    return { port: fallback, explicit: false };
  }

  const normalized = String(raw).trim();
  if (!/^\d+$/.test(normalized)) {
    throw new TypeError(`${name} debe ser un puerto entero entre 1 y 65535.`);
  }

  const port = Number(normalized);
  if (!Number.isSafeInteger(port) || port < 1 || port > 65_535) {
    throw new RangeError(`${name} debe ser un puerto entero entre 1 y 65535.`);
  }

  return { port, explicit: true };
}

function listen(server, host, port) {
  return new Promise((resolveListen, rejectListen) => {
    const onError = (error) => {
      server.off("listening", onListening);
      rejectListen(error);
    };
    const onListening = () => {
      server.off("error", onError);
      resolveListen();
    };

    server.once("error", onError);
    server.once("listening", onListening);
    server.listen({ host, port, exclusive: true });
  });
}

export async function reserveAvailablePort({ host, startPort, strict }) {
  for (let port = startPort; port <= 65_535; port += 1) {
    const reservation = net.createServer((socket) => socket.destroy());

    try {
      await listen(reservation, host, port);

      return { port, reservation };
    } catch (error) {
      reservation.close();

      const retryable = error?.code === "EADDRINUSE"
        || error?.code === "EACCES";
      if (strict || !retryable) {
        throw new PortUnavailableError(host, port, error);
      }
    }
  }

  throw new PortUnavailableError(host, startPort);
}

export async function closeReservation(reservation) {
  if (!reservation?.listening) {
    return;
  }

  await new Promise((resolveClose, rejectClose) => {
    reservation.close((error) => {
      if (error) {
        rejectClose(error);
        return;
      }

      resolveClose();
    });
  });
}

function listeningPort(httpServer) {
  const address = httpServer?.address?.();
  if (address && typeof address === "object" && Number.isInteger(address.port)) {
    return address.port;
  }

  throw new Error("Vite no ha publicado un puerto HTTP valido.");
}

function childHasExited(child) {
  return !child
    || child.exitCode !== null
    || child.signalCode !== null;
}

export function watchChildExit(child, callback) {
  let handled = false;
  const onExit = (code, signal) => {
    if (handled) {
      return;
    }

    handled = true;
    child.off("exit", onExit);
    callback(code, signal);
  };

  child.once("exit", onExit);
  if (childHasExited(child)) {
    onExit(child.exitCode, child.signalCode);
  }

  return () => {
    handled = true;
    child.off("exit", onExit);
  };
}

function spawnViteChild({ projectRoot, environment, appOrigin, port, strictPort }) {
  return spawn(
    process.execPath,
    [fileURLToPath(import.meta.url), "--vite-child"],
    {
      cwd: projectRoot,
      env: {
        ...environment,
        DEV_MODE: "1",
        RAIZ: appOrigin,
        LIQUIDSTACK_DEV_APP_PORT: new URL(appOrigin).port,
        LIQUIDSTACK_DEV_APP_ORIGIN: appOrigin,
        LIQUIDSTACK_DEV_VITE_PORT: String(port),
        LIQUIDSTACK_DEV_VITE_ORIGIN: `http://${HOST}:${port}`,
        LIQUIDSTACK_DEV_VITE_START_PORT: String(port),
        LIQUIDSTACK_DEV_VITE_STRICT: strictPort ? "1" : "0",
      },
      shell: false,
      windowsHide: true,
      stdio: ["ignore", "inherit", "inherit", "ipc"],
    },
  );
}

function waitForViteReady(child) {
  return new Promise((resolveReady, rejectReady) => {
    let settled = false;
    const timeout = setTimeout(() => {
      reject(new ViteStartupError(
        "Vite no confirmo su puerto antes del timeout de arranque.",
      ));
    }, VITE_START_TIMEOUT_MS);
    const cleanup = () => {
      clearTimeout(timeout);
      child.off("message", onMessage);
      child.off("error", onError);
      child.off("exit", onExit);
    };
    const reject = (error) => {
      if (settled) {
        return;
      }

      settled = true;
      cleanup();
      rejectReady(error);
    };
    const onMessage = (message) => {
      if (!message || typeof message !== "object") {
        return;
      }
      if (message.type === "vite-error") {
        reject(new ViteStartupError(
          typeof message.message === "string"
            ? message.message
            : "Vite no pudo arrancar.",
        ));
        return;
      }
      if (message.type !== "vite-ready") {
        return;
      }

      const port = Number(message.port);
      if (!Number.isInteger(port) || port < 1 || port > 65_535) {
        reject(new ViteStartupError(
          "El subproceso Vite devolvio un puerto invalido.",
        ));
        return;
      }

      settled = true;
      cleanup();
      resolveReady(port);
    };
    const onError = (error) => {
      reject(new ViteStartupError(
        `No se pudo ejecutar Vite: ${errorMessage(error)}`,
      ));
    };
    const onExit = (code, signal) => {
      reject(new ViteStartupError(
        `Vite termino antes de quedar disponible (${signal || code || 1}).`,
      ));
    };

    child.on("message", onMessage);
    child.once("error", onError);
    child.once("exit", onExit);

    // The child may have exited between spawn() and listener registration.
    if (childHasExited(child)) {
      onExit(child.exitCode, child.signalCode);
    }
  });
}

function lockViteRestartPort(vite, port) {
  const inlineServer = vite?.config?.inlineConfig?.server;
  const resolvedServer = vite?.config?.server;
  if (
    !inlineServer
    || typeof inlineServer !== "object"
    || !resolvedServer
    || typeof resolvedServer !== "object"
  ) {
    throw new Error("Vite no expone una configuracion de servidor mutable.");
  }

  inlineServer.port = port;
  inlineServer.strictPort = true;
  resolvedServer.port = port;
  resolvedServer.strictPort = true;
  vite._configServerPort = port;
  vite._currentServerPort = port;

  if (
    inlineServer.port !== port
    || inlineServer.strictPort !== true
    || resolvedServer.port !== port
    || resolvedServer.strictPort !== true
  ) {
    throw new Error("No se pudo fijar el puerto de Vite para sus reinicios.");
  }
}

async function sendIpc(message) {
  if (!process.connected || typeof process.send !== "function") {
    return;
  }

  await new Promise((resolveSend) => {
    try {
      process.send(message, () => resolveSend());
    } catch {
      resolveSend();
    }
  });
}

async function runViteChild() {
  const startPort = readPortOverride(
    process.env,
    "LIQUIDSTACK_DEV_VITE_START_PORT",
    DEFAULT_VITE_PORT,
  ).port;
  const strictPort = process.env.LIQUIDSTACK_DEV_VITE_STRICT === "1";
  const appOrigin = process.env.LIQUIDSTACK_DEV_APP_ORIGIN;
  if (!appOrigin) {
    throw new Error("Falta LIQUIDSTACK_DEV_APP_ORIGIN para arrancar Vite.");
  }

  let vite = null;
  let stopping = false;
  let cleanupPromise = null;
  const shutdown = (exitCode = 0) => {
    stopping = true;
    process.exitCode = exitCode;
    if (!cleanupPromise) {
      cleanupPromise = (async () => {
        await vite?.close?.().catch(() => {});
        if (process.connected) {
          process.disconnect();
        }
      })();
    }

    return cleanupPromise;
  };
  const onMessage = (message) => {
    if (message?.type === "shutdown") {
      void shutdown(0);
    }
  };
  const onDisconnect = () => void shutdown(0);

  process.on("message", onMessage);
  process.once("disconnect", onDisconnect);

  try {
    const { createServer } = await import("vite");
    if (stopping) {
      return;
    }

    vite = await createServer({
      root: process.cwd(),
      clearScreen: false,
      server: {
        host: HOST,
        port: startPort,
        strictPort,
        origin: appOrigin,
      },
    });
    if (stopping) {
      await vite.close().catch(() => {});
      return;
    }

    await vite.listen();
    if (stopping) {
      await shutdown(process.exitCode ?? 0);
      return;
    }
    if (!vite.httpServer?.listening) {
      throw new Error("Vite cerro su servidor HTTP durante el arranque.");
    }

    const port = listeningPort(vite.httpServer);
    process.env.LIQUIDSTACK_DEV_VITE_PORT = String(port);
    process.env.LIQUIDSTACK_DEV_VITE_ORIGIN = `http://${HOST}:${port}`;
    lockViteRestartPort(vite, port);
    if (!vite.httpServer.listening) {
      throw new Error("Vite termino antes de confirmar su puerto.");
    }

    await sendIpc({ type: "vite-ready", port });
  } catch (error) {
    await sendIpc({ type: "vite-error", message: errorMessage(error) });
    await shutdown(1);
  } finally {
    if (stopping) {
      process.off("message", onMessage);
      process.off("disconnect", onDisconnect);
    }
  }
}

function canConnect(host, port) {
  return new Promise((resolveConnection) => {
    const socket = net.createConnection({ host, port });
    let settled = false;

    const finish = (connected) => {
      if (settled) {
        return;
      }

      settled = true;
      socket.destroy();
      resolveConnection(connected);
    };

    socket.setTimeout(150);
    socket.once("connect", () => finish(true));
    socket.once("timeout", () => finish(false));
    socket.once("error", () => finish(false));
  });
}

function phpAddressIsInUse(stderr) {
  return /Failed to listen|Address already in use|Only one usage|EADDRINUSE/i
    .test(stderr);
}

function spawnPhp({ projectRoot, port, environment }) {
  const phpBinary = environment.LIQUIDSTACK_DEV_PHP_BINARY?.trim() || "php";
  const child = spawn(
    phpBinary,
    [
      "-S",
      `${HOST}:${port}`,
      "-t",
      "public",
      "App/tools/php-dev-router.php",
    ],
    {
      cwd: projectRoot,
      env: environment,
      shell: false,
      windowsHide: true,
      stdio: ["ignore", "inherit", "pipe"],
    },
  );
  let stderr = "";

  child.stderr?.on("data", (chunk) => {
    const text = String(chunk);
    stderr = (stderr + text).slice(-16_384);
    process.stderr.write(chunk);
  });

  return { child, stderr: () => stderr };
}

async function waitForPhp({ child, stderr }, port) {
  let spawnError = null;
  let exited = false;

  const onError = (error) => {
    spawnError = error;
  };
  const onExit = () => {
    exited = true;
  };

  child.once("error", onError);
  child.once("exit", onExit);

  try {
    const deadline = Date.now() + PHP_START_TIMEOUT_MS;
    while (Date.now() < deadline) {
      if (spawnError) {
        throw new PhpStartupError(
          `No se pudo ejecutar PHP: ${errorMessage(spawnError)}`,
        );
      }
      if (exited || child.exitCode !== null || child.signalCode !== null) {
        const details = stderr().trim();
        throw new PhpStartupError(
          details || "El servidor PHP termino antes de quedar disponible.",
          { addressInUse: phpAddressIsInUse(details) },
        );
      }
      if (await canConnect(HOST, port)) {
        await delay(40);
        if (!exited && child.exitCode === null && child.signalCode === null) {
          return;
        }
      }

      await delay(25);
    }

    throw new PhpStartupError(
      `El servidor PHP no quedo disponible en ${HOST}:${port}.`,
    );
  } finally {
    child.off("error", onError);
    child.off("exit", onExit);
  }
}

async function stopChild(child) {
  if (childHasExited(child)) {
    return;
  }

  const exited = once(child, "exit").catch(() => []);
  child.kill("SIGTERM");

  if (await Promise.race([
    exited.then(() => true),
    delay(CHILD_STOP_TIMEOUT_MS).then(() => false),
  ])) {
    return;
  }

  if (child.exitCode === null && child.signalCode === null) {
    child.kill("SIGKILL");
    await Promise.race([exited, delay(CHILD_STOP_TIMEOUT_MS)]);
  }
}

async function stopViteChild(child) {
  if (childHasExited(child)) {
    return;
  }

  const exited = once(child, "exit").catch(() => []);
  if (child.connected) {
    await new Promise((resolveSend) => {
      try {
        child.send({ type: "shutdown" }, () => resolveSend());
      } catch {
        resolveSend();
      }
    });
  }

  if (await Promise.race([
    exited.then(() => true),
    delay(CHILD_STOP_TIMEOUT_MS).then(() => false),
  ])) {
    return;
  }

  if (!childHasExited(child)) {
    child.kill("SIGTERM");
  }
  if (await Promise.race([
    exited.then(() => true),
    delay(CHILD_STOP_TIMEOUT_MS).then(() => false),
  ])) {
    return;
  }

  if (!childHasExited(child)) {
    child.kill("SIGKILL");
    await Promise.race([exited, delay(CHILD_STOP_TIMEOUT_MS)]);
  }
}

function runtimeEnvironment(baseEnvironment, appPort, vitePort) {
  const appOrigin = `http://${HOST}:${appPort}`;
  const viteOrigin = `http://${HOST}:${vitePort}`;

  return {
    ...baseEnvironment,
    DEV_MODE: "1",
    RAIZ: appOrigin,
    LIQUIDSTACK_DEV_APP_PORT: String(appPort),
    LIQUIDSTACK_DEV_APP_ORIGIN: appOrigin,
    LIQUIDSTACK_DEV_VITE_PORT: String(vitePort),
    LIQUIDSTACK_DEV_VITE_ORIGIN: viteOrigin,
  };
}

async function superviseDevelopmentServers({
  projectRoot = process.cwd(),
  environment = process.env,
  input = process.stdin,
} = {}) {
  const appChoice = readPortOverride(
    environment,
    "LIQUIDSTACK_DEV_APP_PORT",
    DEFAULT_APP_PORT,
  );
  const viteChoice = readPortOverride(
    environment,
    "LIQUIDSTACK_DEV_VITE_PORT",
    DEFAULT_VITE_PORT,
  );
  if (
    appChoice.explicit
    && viteChoice.explicit
    && appChoice.port === viteChoice.port
  ) {
    throw new RangeError(
      "LIQUIDSTACK_DEV_APP_PORT y LIQUIDSTACK_DEV_VITE_PORT deben ser distintos.",
    );
  }
  let nextAppPort = appChoice.port;
  let stopping = false;
  let requestedExitCode = 0;
  let cleanupPromise = null;
  let activeAttempt = null;
  let resolveShutdownRequested;
  const shutdownRequested = new Promise((resolveShutdown) => {
    resolveShutdownRequested = resolveShutdown;
  });

  const disposeAttempt = (attempt) => {
    if (!attempt) {
      return Promise.resolve();
    }
    if (attempt.disposalPromise) {
      return attempt.disposalPromise;
    }

    attempt.disposalPromise = (async () => {
      attempt.detachPhp?.();
      attempt.detachVite?.();
      attempt.detachPhp = null;
      attempt.detachVite = null;

      const reservation = attempt.reservation;
      const phpChild = attempt.php?.child;
      const viteChild = attempt.viteChild;
      attempt.reservation = null;
      attempt.php = null;
      attempt.viteChild = null;

      await closeReservation(reservation).catch(() => {});
      await Promise.all([
        stopChild(phpChild),
        stopViteChild(viteChild),
      ]);
    })();

    return attempt.disposalPromise;
  };
  const requestShutdown = (exitCode) => {
    if (!stopping) {
      stopping = true;
      requestedExitCode = exitCode;
      process.exitCode = exitCode;
      resolveShutdownRequested();
    }
    if (!cleanupPromise) {
      cleanupPromise = disposeAttempt(activeAttempt);
    }

    return cleanupPromise;
  };
  const onSigint = () => void requestShutdown(130);
  const onSigterm = () => void requestShutdown(143);
  const onSighup = () => void requestShutdown(129);

  // Install lifecycle ownership before the first asynchronous resource is
  // created, so a signal cannot bypass cleanup during startup.
  process.once("SIGINT", onSigint);
  process.once("SIGTERM", onSigterm);
  if (process.platform !== "win32") {
    process.once("SIGHUP", onSighup);
  }
  let restoreStdin = () => {};

  try {
    restoreStdin = attachStdinShutdown(input, requestShutdown);
    while (nextAppPort <= 65_535) {
      const attempt = {
        reservation: null,
        viteChild: null,
        php: null,
        phpReady: false,
        phpStartupExit: null,
        detachVite: null,
        detachPhp: null,
        disposalPromise: null,
      };
      activeAttempt = attempt;
      let appPort = nextAppPort;

      try {
        const selected = await reserveAvailablePort({
          host: HOST,
          startPort: nextAppPort,
          strict: appChoice.explicit,
        });
        appPort = selected.port;
        attempt.reservation = selected.reservation;
        if (stopping) {
          await closeReservation(attempt.reservation).catch(() => {});
          attempt.reservation = null;
          throw new ShutdownRequestedError();
        }
        if (
          !appChoice.explicit
          && viteChoice.explicit
          && appPort === viteChoice.port
        ) {
          await closeReservation(attempt.reservation);
          attempt.reservation = null;
          activeAttempt = null;
          nextAppPort = appPort + 1;
          continue;
        }

        const appOrigin = `http://${HOST}:${appPort}`;
        process.env.RAIZ = appOrigin;
        process.env.DEV_MODE = "1";
        process.env.LIQUIDSTACK_DEV_APP_PORT = String(appPort);
        process.env.LIQUIDSTACK_DEV_APP_ORIGIN = appOrigin;

        attempt.viteChild = spawnViteChild({
          projectRoot,
          environment,
          appOrigin,
          port: viteChoice.port,
          strictPort: viteChoice.explicit,
        });
        attempt.detachVite = watchChildExit(
          attempt.viteChild,
          (code, signal) => {
            if (!stopping) {
              console.error(
                `[LiquidStack] Vite termino inesperadamente (${signal || code || 1}).`,
              );
              void requestShutdown(
                typeof code === "number" && code > 0 ? code : 1,
              );
            }
          },
        );
        if (stopping || childHasExited(attempt.viteChild)) {
          await requestShutdown(requestedExitCode || 1);
          throw new ShutdownRequestedError();
        }

        const vitePort = await waitForViteReady(attempt.viteChild);
        if (stopping || childHasExited(attempt.viteChild)) {
          await requestShutdown(requestedExitCode || 1);
          throw new ShutdownRequestedError();
        }

        const childEnvironment = runtimeEnvironment(
          environment,
          appPort,
          vitePort,
        );
        process.env.LIQUIDSTACK_DEV_VITE_PORT = String(vitePort);
        process.env.LIQUIDSTACK_DEV_VITE_ORIGIN =
          childEnvironment.LIQUIDSTACK_DEV_VITE_ORIGIN;

        await closeReservation(attempt.reservation);
        attempt.reservation = null;
        if (stopping) {
          throw new ShutdownRequestedError();
        }

        attempt.php = spawnPhp({
          projectRoot,
          port: appPort,
          environment: childEnvironment,
        });
        attempt.detachPhp = watchChildExit(
          attempt.php.child,
          (code, signal) => {
            if (!attempt.phpReady) {
              attempt.phpStartupExit = { code, signal };
              return;
            }
            if (!stopping) {
              console.error(
                `[LiquidStack] PHP termino inesperadamente (${signal || code || 1}).`,
              );
              void requestShutdown(
                typeof code === "number" && code > 0 ? code : 1,
              );
            }
          },
        );
        if (stopping) {
          throw new ShutdownRequestedError();
        }

        await waitForPhp(attempt.php, appPort);
        attempt.phpReady = true;
        if (
          stopping
          || attempt.phpStartupExit
          || childHasExited(attempt.php.child)
          || childHasExited(attempt.viteChild)
        ) {
          if (!stopping) {
            const exit = attempt.phpStartupExit;
            const child = childHasExited(attempt.php.child)
              ? attempt.php.child
              : attempt.viteChild;
            void requestShutdown(
              typeof (exit?.code ?? child?.exitCode) === "number"
                && (exit?.code ?? child?.exitCode) > 0
                ? (exit?.code ?? child?.exitCode)
                : 1,
            );
          }
          await requestShutdown(requestedExitCode || 1);
          throw new ShutdownRequestedError();
        }

        console.log(`[LiquidStack] Aplicacion: ${appOrigin}`);
        console.log(
          `[LiquidStack] Vite: ${childEnvironment.LIQUIDSTACK_DEV_VITE_ORIGIN}`,
        );

        await shutdownRequested;
        await requestShutdown(requestedExitCode);
        return;
      } catch (error) {
        if (stopping || error instanceof ShutdownRequestedError) {
          await requestShutdown(requestedExitCode || process.exitCode || 1);
          return;
        }

        const retry = !appChoice.explicit
          && error instanceof PhpStartupError
          && error.addressInUse;
        await disposeAttempt(attempt);
        if (retry) {
          activeAttempt = null;
          nextAppPort = appPort + 1;
          continue;
        }

        throw error;
      }
    }

    throw new PortUnavailableError(HOST, appChoice.port);
  } finally {
    restoreStdin();
    process.off("SIGINT", onSigint);
    process.off("SIGTERM", onSigterm);
    if (process.platform !== "win32") {
      process.off("SIGHUP", onSighup);
    }
    await disposeAttempt(activeAttempt);
  }
}

export async function runDevelopmentServers(options = {}) {
  const keys = [
    "DEV_MODE",
    "RAIZ",
    "LIQUIDSTACK_DEV_APP_PORT",
    "LIQUIDSTACK_DEV_APP_ORIGIN",
    "LIQUIDSTACK_DEV_VITE_PORT",
    "LIQUIDSTACK_DEV_VITE_ORIGIN",
  ];
  const previousEnvironment = new Map(
    keys.map((key) => [
      key,
      Object.prototype.hasOwnProperty.call(process.env, key)
        ? process.env[key]
        : undefined,
    ]),
  );

  try {
    await superviseDevelopmentServers(options);
  } finally {
    for (const [key, value] of previousEnvironment) {
      if (value === undefined) {
        delete process.env[key];
      } else {
        process.env[key] = value;
      }
    }
  }
}

const invokedPath = process.argv[1]
  ? pathToFileURL(resolve(process.argv[1])).href
  : null;

if (invokedPath === import.meta.url) {
  const main = process.argv.includes("--vite-child")
    ? runViteChild
    : runDevelopmentServers;
  main().catch((error) => {
    console.error(`[LiquidStack] ${errorMessage(error)}`);
    process.exitCode = 1;
  });
}
