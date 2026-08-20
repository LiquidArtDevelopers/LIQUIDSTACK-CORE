import assert from "node:assert/strict";
import { createServer as createNetServer } from "node:net";
import { mkdtemp, mkdir, readFile, rm, writeFile, copyFile } from "node:fs/promises";
import { tmpdir } from "node:os";
import { dirname, join } from "node:path";
import { fileURLToPath, pathToFileURL } from "node:url";
import http from "node:http";
import { EventEmitter } from "node:events";

const [launcherSource, routerSource, phpBinary] = process.argv.slice(2);
assert.ok(launcherSource, "Falta la ruta del supervisor.");
assert.ok(routerSource, "Falta la ruta del router PHP.");
assert.ok(phpBinary, "Falta el binario PHP.");

const fixture = await mkdtemp(join(tmpdir(), "liquidstack-dev-launcher-"));
let runningPromise = null;

function listen(server, host, port) {
  return new Promise((resolveListen, rejectListen) => {
    server.once("error", rejectListen);
    server.listen({ host, port, exclusive: true }, resolveListen);
  });
}

async function close(server) {
  if (!server?.listening) return;
  await new Promise((resolveClose) => server.close(resolveClose));
}

async function freePort() {
  const server = createNetServer();
  await listen(server, "localhost", 0);
  const address = server.address();
  assert.ok(address && typeof address === "object");
  const port = address.port;
  await close(server);
  return port;
}

async function waitUntil(predicate, timeout = 8_000) {
  const deadline = Date.now() + timeout;
  while (Date.now() < deadline) {
    if (await predicate()) return;
    await new Promise((resolveDelay) => setTimeout(resolveDelay, 25));
  }
  assert.fail("La condicion esperada no se cumplio a tiempo.");
}

function requestJson(port) {
  return new Promise((resolveRequest, rejectRequest) => {
    const request = http.get(`http://localhost:${port}/`, (response) => {
      let body = "";
      response.setEncoding("utf8");
      response.on("data", (chunk) => { body += chunk; });
      response.on("end", () => {
        try {
          resolveRequest(JSON.parse(body));
        } catch (error) {
          rejectRequest(error);
        }
      });
    });
    request.once("error", rejectRequest);
  });
}

try {
  const tools = join(fixture, "App", "tools");
  const publicRoot = join(fixture, "public");
  const vitePackage = join(fixture, "node_modules", "vite");
  await mkdir(tools, { recursive: true });
  await mkdir(publicRoot, { recursive: true });
  await mkdir(vitePackage, { recursive: true });

  const launcher = join(tools, "liquidstack-dev.mjs");
  await copyFile(launcherSource, launcher);
  await copyFile(routerSource, join(tools, "php-dev-router.php"));
  await writeFile(
    join(publicRoot, "index.php"),
    `<?php\nheader('Content-Type: application/json');\necho json_encode([\n`
      + `  'pid' => getmypid(),\n`
      + `  'stdinTty' => (defined('STDIN') && function_exists('stream_isatty')) ? stream_isatty(constant('STDIN')) : false,\n`
      + `  'devMode' => getenv('DEV_MODE'),\n`
      + `  'raiz' => getenv('RAIZ'),\n`
      + `  'appPort' => getenv('LIQUIDSTACK_DEV_APP_PORT'),\n`
      + `  'appOrigin' => getenv('LIQUIDSTACK_DEV_APP_ORIGIN'),\n`
      + `  'vitePort' => getenv('LIQUIDSTACK_DEV_VITE_PORT'),\n`
      + `  'viteOrigin' => getenv('LIQUIDSTACK_DEV_VITE_ORIGIN'),\n]);\n`,
  );
  await writeFile(
    join(vitePackage, "package.json"),
    JSON.stringify({
      name: "vite",
      version: "0.0.0-test",
      type: "module",
      exports: "./index.mjs",
    }),
  );
  await copyFile(
    join(dirname(fileURLToPath(import.meta.url)), "fake-vite.mjs"),
    join(vitePackage, "index.mjs"),
  );

  const module = await import(`${pathToFileURL(launcher).href}?test=${Date.now()}`);

  assert.deepEqual(
    module.readPortOverride({}, "LIQUIDSTACK_DEV_APP_PORT", 1309),
    { port: 1309, explicit: false },
  );
  assert.deepEqual(
    module.readPortOverride(
      { LIQUIDSTACK_DEV_APP_PORT: " 23109 " },
      "LIQUIDSTACK_DEV_APP_PORT",
      1309,
    ),
    { port: 23109, explicit: true },
  );
  assert.throws(
    () => module.readPortOverride(
      { LIQUIDSTACK_DEV_APP_PORT: "0" },
      "LIQUIDSTACK_DEV_APP_PORT",
      1309,
    ),
    /entre 1 y 65535/,
  );

  const occupiedPort = await freePort();
  const blocker = createNetServer();
  await listen(blocker, "localhost", occupiedPort);
  const incremental = await module.reserveAvailablePort({
    host: "localhost",
    startPort: occupiedPort,
    strict: false,
  });
  assert.ok(incremental.port > occupiedPort);
  await module.closeReservation(incremental.reservation);
  await assert.rejects(
    module.reserveAvailablePort({
      host: "localhost",
      startPort: occupiedPort,
      strict: true,
    }),
    (error) => error?.code === "LIQUIDSTACK_PORT_UNAVAILABLE",
  );
  await close(blocker);

  const processEnvironmentBeforeRun = Object.fromEntries(
    [
      "DEV_MODE",
      "RAIZ",
      "LIQUIDSTACK_DEV_APP_PORT",
      "LIQUIDSTACK_DEV_APP_ORIGIN",
      "LIQUIDSTACK_DEV_VITE_PORT",
      "LIQUIDSTACK_DEV_VITE_ORIGIN",
    ].map((key) => [key, process.env[key]]),
  );
  const nextPorts = async () => {
    const appPort = await freePort();
    let vitePort = await freePort();
    while (vitePort === appPort) vitePort = await freePort();
    return { appPort, vitePort };
  };
  const environmentFor = ({ appPort, vitePort }) => ({
    ...process.env,
    LIQUIDSTACK_DEV_APP_PORT: String(appPort),
    LIQUIDSTACK_DEV_VITE_PORT: String(vitePort),
    LIQUIDSTACK_DEV_PHP_BINARY: phpBinary,
  });
  const assertPortsFree = async ({ appPort, vitePort }) => {
    for (const port of [appPort, vitePort]) {
      const probe = createNetServer();
      await listen(probe, "localhost", port);
      await close(probe);
    }
  };
  const assertEnvironmentRestored = () => {
    for (const [key, value] of Object.entries(processEnvironmentBeforeRun)) {
      assert.equal(process.env[key], value, `${key} no fue restaurada`);
    }
  };
  const waitForJsonFile = async (name, getError = () => null) => {
    let value = null;
    await waitUntil(async () => {
      if (getError()) throw getError();
      try {
        value = JSON.parse(await readFile(join(fixture, name), "utf8"));
        return true;
      } catch {
        return false;
      }
    });
    return value;
  };
  const clearRunFiles = async (...names) => {
    await Promise.all(names.map((name) => rm(
      join(fixture, name),
      { force: true },
    )));
  };

  const exitedChild = new EventEmitter();
  exitedChild.exitCode = 17;
  exitedChild.signalCode = null;
  let observedImmediateExits = 0;
  const detachExitedChild = module.watchChildExit(exitedChild, (code) => {
    observedImmediateExits += 1;
    assert.equal(code, 17);
  });
  exitedChild.emit("exit", 17, null);
  detachExitedChild();
  assert.equal(observedImmediateExits, 1);

  class FakeInput extends EventEmitter {
    constructor() {
      super();
      this.isTTY = true;
      this.isRaw = false;
      this.readableFlowing = false;
      this.rawTransitions = [];
    }

    setRawMode(value) {
      this.isRaw = value;
      this.rawTransitions.push(value);
    }

    resume() {
      this.readableFlowing = true;
    }

    pause() {
      this.readableFlowing = false;
    }
  }
  const fakeInput = new FakeInput();
  const existingInputListener = () => {};
  fakeInput.on("data", existingInputListener);
  const stdinShutdownCodes = [];
  const restoreFakeInput = module.attachStdinShutdown(
    fakeInput,
    (code) => stdinShutdownCodes.push(code),
    { platform: "win32" },
  );
  assert.equal(fakeInput.listenerCount("data"), 2);
  assert.equal(fakeInput.isRaw, true);
  fakeInput.emit("data", Buffer.from("ordinary input"));
  assert.deepEqual(stdinShutdownCodes, []);
  fakeInput.emit("data", Buffer.from([0x03]));
  assert.deepEqual(stdinShutdownCodes, [130]);
  restoreFakeInput();
  restoreFakeInput();
  assert.equal(fakeInput.listenerCount("data"), 1);
  assert.equal(fakeInput.listeners("data")[0], existingInputListener);
  assert.equal(fakeInput.isRaw, false);
  assert.equal(fakeInput.readableFlowing, false);
  assert.deepEqual(fakeInput.rawTransitions, [true, false]);

  for (const [input, platform] of [
    [Object.assign(new FakeInput(), { isTTY: false }), "win32"],
    [new FakeInput(), "linux"],
  ]) {
    const shutdownCodes = [];
    const restoreIgnoredInput = module.attachStdinShutdown(
      input,
      (code) => shutdownCodes.push(code),
      { platform },
    );
    input.emit("data", Buffer.from([0x03]));
    restoreIgnoredInput();
    assert.deepEqual(shutdownCodes, []);
    assert.deepEqual(input.rawTransitions, []);
    assert.equal(input.listenerCount("data"), 0);
    assert.equal(input.readableFlowing, false);
  }

  class ThrowingInput extends FakeInput {
    constructor(failure) {
      super();
      this.failure = failure;
    }

    on(event, listener) {
      if (this.failure === "on" && event === "data") {
        throw new Error("on failed");
      }
      return super.on(event, listener);
    }

    resume() {
      if (this.failure === "resume") {
        throw new Error("resume failed");
      }
      super.resume();
    }
  }
  for (const failure of ["on", "resume"]) {
    const throwingInput = new ThrowingInput(failure);
    assert.doesNotThrow(() => {
      const restore = module.attachStdinShutdown(
        throwingInput,
        () => assert.fail("No debe conservar un listener parcial."),
        { platform: "win32" },
      );
      restore();
    });
    assert.equal(throwingInput.listenerCount("data"), 0);
    assert.equal(throwingInput.isRaw, false);
    assert.equal(throwingInput.readableFlowing, false);
    assert.deepEqual(throwingInput.rawTransitions, [true, false]);
  }

  const equalPort = await freePort();
  await assert.rejects(
    module.runDevelopmentServers({
      projectRoot: fixture,
      environment: {
        ...process.env,
        LIQUIDSTACK_DEV_APP_PORT: String(equalPort),
        LIQUIDSTACK_DEV_VITE_PORT: String(equalPort),
        LIQUIDSTACK_DEV_PHP_BINARY: phpBinary,
      },
    }),
    /deben ser distintos/,
  );
  await assertPortsFree({ appPort: equalPort, vitePort: equalPort });

  let exactVitePort = null;
  for (let port = 1309; port < 1409; port += 1) {
    const probe = createNetServer();
    try {
      await listen(probe, "localhost", port);
      exactVitePort = port;
      await close(probe);
      break;
    } catch {
      await close(probe);
    }
  }
  assert.ok(Number.isInteger(exactVitePort));
  await clearRunFiles("vite-create-count.txt");
  const exactViteEnvironment = {
    ...process.env,
    LIQUIDSTACK_DEV_VITE_PORT: String(exactVitePort),
    LIQUIDSTACK_DEV_PHP_BINARY: phpBinary,
  };
  delete exactViteEnvironment.LIQUIDSTACK_DEV_APP_PORT;
  let exactViteError = null;
  let exactViteResponse = null;
  runningPromise = module.runDevelopmentServers({
    projectRoot: fixture,
    environment: exactViteEnvironment,
  }).catch((error) => {
    exactViteError = error;
  });
  await waitUntil(async () => {
    if (exactViteError) throw exactViteError;
    const selectedAppPort = Number(process.env.LIQUIDSTACK_DEV_APP_PORT);
    if (!Number.isInteger(selectedAppPort) || selectedAppPort <= exactVitePort) {
      return false;
    }
    try {
      exactViteResponse = await requestJson(selectedAppPort);
      return true;
    } catch {
      return false;
    }
  });
  assert.ok(Number(exactViteResponse.appPort) > exactVitePort);
  assert.equal(exactViteResponse.vitePort, String(exactVitePort));
  assert.equal(
    await readFile(join(fixture, "vite-create-count.txt"), "utf8"),
    "1",
  );
  process.emit("SIGTERM");
  await runningPromise;
  runningPromise = null;
  assert.equal(exactViteError, null);
  assert.equal(process.exitCode, 143);
  process.exitCode = 0;
  await assertPortsFree({
    appPort: Number(exactViteResponse.appPort),
    vitePort: exactVitePort,
  });
  assertEnvironmentRestored();
  await clearRunFiles(
    "vite-create-count.txt",
    "vite-observed.json",
    "vite-closed.txt",
  );

  const startupPorts = await nextPorts();
  await writeFile(join(fixture, "vite-pause-startup"), "pause");
  let startupError = null;
  runningPromise = module.runDevelopmentServers({
    projectRoot: fixture,
    environment: environmentFor(startupPorts),
  }).catch((error) => {
    startupError = error;
  });
  await waitUntil(async () => {
    if (startupError) throw startupError;
    try {
      await readFile(join(fixture, "vite-startup-paused"));
      return true;
    } catch {
      return false;
    }
  });
  process.emit("SIGTERM");
  await runningPromise;
  runningPromise = null;
  assert.equal(startupError, null);
  assert.equal(process.exitCode, 143);
  process.exitCode = 0;
  await assertPortsFree(startupPorts);
  assertEnvironmentRestored();
  await clearRunFiles(
    "vite-pause-startup",
    "vite-startup-paused",
    "vite-observed.json",
    "vite-closed.txt",
  );

  const restartPorts = await nextPorts();
  await writeFile(join(fixture, "vite-restart"), "restart");
  let restartError = null;
  let restartResponse = null;
  const restartInput = new FakeInput();
  runningPromise = module.runDevelopmentServers({
    projectRoot: fixture,
    environment: environmentFor(restartPorts),
    input: restartInput,
  }).catch((error) => {
    restartError = error;
  });
  await waitUntil(async () => {
    if (restartError) throw restartError;
    try {
      restartResponse = await requestJson(restartPorts.appPort);
      return true;
    } catch {
      return false;
    }
  });
  assert.deepEqual(restartResponse, {
    pid: restartResponse.pid,
    stdinTty: false,
    devMode: "1",
    raiz: `http://localhost:${restartPorts.appPort}`,
    appPort: String(restartPorts.appPort),
    appOrigin: `http://localhost:${restartPorts.appPort}`,
    vitePort: String(restartPorts.vitePort),
    viteOrigin: `http://localhost:${restartPorts.vitePort}`,
  });
  assert.ok(Number.isInteger(restartResponse.pid));
  const initialVite = await waitForJsonFile(
    "vite-observed.json",
    () => restartError,
  );
  assert.equal(initialVite.ci, process.env.CI);
  assert.equal(initialVite.stdinIsTty, false);
  const restarted = await waitForJsonFile(
    "vite-restarted.json",
    () => restartError,
  );
  assert.deepEqual(restarted, {
    port: restartPorts.vitePort,
    strictPort: true,
    configuredPort: restartPorts.vitePort,
    environmentPort: String(restartPorts.vitePort),
    environmentOrigin: `http://localhost:${restartPorts.vitePort}`,
  });
  const responseAfterRestart = await requestJson(restartPorts.appPort);
  assert.equal(responseAfterRestart.pid, restartResponse.pid);
  const restartExitCode = process.platform === "win32" ? 130 : 143;
  if (process.platform === "win32") {
    restartInput.emit("data", Buffer.from([0x03]));
  } else {
    process.emit("SIGTERM");
  }
  await runningPromise;
  runningPromise = null;
  assert.equal(restartError, null);
  assert.equal(process.exitCode, restartExitCode);
  process.exitCode = 0;
  assert.equal(restartInput.listenerCount("data"), 0);
  assert.equal(restartInput.isRaw, false);
  assert.equal(restartInput.readableFlowing, false);
  await assertPortsFree(restartPorts);
  assertEnvironmentRestored();
  await clearRunFiles(
    "vite-restart",
    "vite-restarted.json",
    "vite-observed.json",
    "vite-closed.txt",
  );

  const viteExitPorts = await nextPorts();
  await writeFile(join(fixture, "vite-exit-after-ready"), "exit");
  let viteExitError = null;
  let viteExitResponse = null;
  runningPromise = module.runDevelopmentServers({
    projectRoot: fixture,
    environment: environmentFor(viteExitPorts),
  }).catch((error) => {
    viteExitError = error;
  });
  await waitUntil(async () => {
    if (viteExitError) throw viteExitError;
    try {
      viteExitResponse = await requestJson(viteExitPorts.appPort);
      return true;
    } catch {
      return false;
    }
  });
  assert.ok(Number.isInteger(viteExitResponse.pid));
  await runningPromise;
  runningPromise = null;
  assert.equal(viteExitError, null);
  assert.equal(process.exitCode, 23);
  process.exitCode = 0;
  await assertPortsFree(viteExitPorts);
  assertEnvironmentRestored();
  await clearRunFiles(
    "vite-exit-after-ready",
    "vite-observed.json",
    "vite-closed.txt",
  );

  const phpExitPorts = await nextPorts();
  let phpExitError = null;
  let phpExitResponse = null;
  runningPromise = module.runDevelopmentServers({
    projectRoot: fixture,
    environment: environmentFor(phpExitPorts),
  }).catch((error) => {
    phpExitError = error;
  });
  await waitUntil(async () => {
    if (phpExitError) throw phpExitError;
    try {
      phpExitResponse = await requestJson(phpExitPorts.appPort);
      return true;
    } catch {
      return false;
    }
  });
  const phpObserved = await waitForJsonFile(
    "vite-observed.json",
    () => phpExitError,
  );
  assert.ok(Number.isInteger(phpExitResponse.pid));
  assert.ok(Number.isInteger(phpObserved.pid));
  await new Promise((resolveDelay) => setTimeout(resolveDelay, 200));
  process.kill(phpExitResponse.pid, "SIGTERM");
  await runningPromise;
  runningPromise = null;
  assert.equal(phpExitError, null);
  assert.equal(process.exitCode, 1);
  process.exitCode = 0;
  await assertPortsFree(phpExitPorts);
  assert.throws(() => process.kill(phpObserved.pid, 0));
  assertEnvironmentRestored();
  await clearRunFiles(
    "vite-observed.json",
    "vite-closed.txt",
  );

  console.log("liquidstack-dev launcher harness: OK");
} finally {
  if (runningPromise) {
    process.emit("SIGTERM");
    await runningPromise.catch(() => {});
    process.exitCode = 0;
  }
  await rm(fixture, { recursive: true, force: true });
}
