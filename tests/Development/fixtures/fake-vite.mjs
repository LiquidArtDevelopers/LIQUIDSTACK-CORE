import { createServer as createNetServer } from "node:net";
import { access, readFile, writeFile } from "node:fs/promises";
import { join } from "node:path";

function marker(root, name) {
  return join(root, name);
}

async function exists(path) {
  try {
    await access(path);
    return true;
  } catch {
    return false;
  }
}

function listen(httpServer, options) {
  return new Promise((resolveListen, rejectListen) => {
    const onError = (error) => {
      httpServer.off("listening", onListening);
      rejectListen(error);
    };
    const onListening = () => {
      httpServer.off("error", onError);
      resolveListen();
    };

    httpServer.once("error", onError);
    httpServer.once("listening", onListening);
    httpServer.listen(options);
  });
}

async function listenIncrementally(vite) {
  let port = vite.config.server.port;

  while (true) {
    try {
      await listen(vite.httpServer, {
        host: vite.config.server.host,
        port,
        exclusive: true,
      });
      return;
    } catch (error) {
      if (vite.config.server.strictPort || error?.code !== "EADDRINUSE") {
        throw error;
      }

      port += 1;
    }
  }
}

export async function createServer(options) {
  let createCount = 0;
  try {
    createCount = Number(await readFile(
      marker(options.root, "vite-create-count.txt"),
      "utf8",
    )) || 0;
  } catch {}
  await writeFile(
    marker(options.root, "vite-create-count.txt"),
    String(createCount + 1),
  );

  let closed = false;
  let releaseStartup = null;
  const vite = {
    config: {
      inlineConfig: options,
      server: options.server,
    },
    httpServer: createNetServer(),
    _configServerPort: null,
    _currentServerPort: null,
    async listen() {
      await listenIncrementally(vite);
      const address = vite.httpServer.address();
      await writeFile(
        marker(options.root, "vite-observed.json"),
        JSON.stringify({
          ...options.server,
          actualPort: address?.port,
          pid: process.pid,
          ci: process.env.CI,
          stdinIsTty: Boolean(process.stdin.isTTY),
        }),
      );

      if (await exists(marker(options.root, "vite-pause-startup"))) {
        await writeFile(marker(options.root, "vite-startup-paused"), "paused");
        await new Promise((resolveStartup) => {
          releaseStartup = resolveStartup;
        });
      }

      if (closed) {
        return;
      }

      if (await exists(marker(options.root, "vite-restart"))) {
        setTimeout(async () => {
          if (closed) return;
          try {
            await new Promise((resolveClose) => {
              vite.httpServer.close(resolveClose);
            });
            vite.httpServer = createNetServer();
            await listenIncrementally(vite);
            const restartedAddress = vite.httpServer.address();
            await writeFile(
              marker(options.root, "vite-restarted.json"),
              JSON.stringify({
                port: restartedAddress?.port,
                strictPort: vite.config.server.strictPort,
                configuredPort: vite.config.server.port,
                environmentPort: process.env.LIQUIDSTACK_DEV_VITE_PORT,
                environmentOrigin: process.env.LIQUIDSTACK_DEV_VITE_ORIGIN,
              }),
            );
          } catch (error) {
            await writeFile(
              marker(options.root, "vite-restart-error.txt"),
              error instanceof Error ? error.message : String(error),
            );
          }
        }, 150);
      }

      if (await exists(marker(options.root, "vite-exit-after-ready"))) {
        setTimeout(() => process.exit(23), 500);
      }
    },
    async close() {
      closed = true;
      releaseStartup?.();
      if (vite.httpServer.listening) {
        await new Promise((resolveClose) => {
          vite.httpServer.close(resolveClose);
        });
      }
      await writeFile(marker(options.root, "vite-closed.txt"), "closed");
    },
  };

  return vite;
}
