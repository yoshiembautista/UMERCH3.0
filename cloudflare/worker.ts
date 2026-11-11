import { DurableObject } from "cloudflare:workers";

const PORT = 8080;
const INACTIVITY_TIMEOUT_MS = 5 * 60 * 1000;

interface Env {
  UMERCH_CONTAINER: DurableObjectNamespace<UmerchContainer>;
  [key: string]: unknown;
}

/**
 * Convert Worker vars/secrets into the environment used to start the
 * container. Empty values are skipped so optional secrets can be unset.
 */
function runtimeEnvironment(env: Env): Record<string, string> {
  const result: Record<string, string> = {};

  for (const [key, value] of Object.entries(env)) {
    if (key === "UMERCH_CONTAINER") continue;
    if (typeof value === "string" && value.length > 0) {
      result[key] = value;
    }
  }

  return result;
}

export class UmerchContainer extends DurableObject<Env> {
  private starting: Promise<void> | undefined;
  private monitoring: Promise<void> | undefined;

  constructor(ctx: DurableObjectState, env: Env) {
    super(ctx, env);

    const container = ctx.container;
    if (container?.running) {
      void ctx.blockConcurrencyWhile(() =>
        container.setInactivityTimeout(INACTIVITY_TIMEOUT_MS),
      );
      this.observeExit();
    }
  }

  async fetch(request: Request): Promise<Response> {
    this.starting ??= this.startAndWaitForPort().finally(() => {
      this.starting = undefined;
    });
    await this.starting;

    const url = new URL(request.url);
    const originalHost = url.host;

    url.protocol = "http:";
    url.host = "container";

    const forwarded = new Request(url, request);
    forwarded.headers.delete("host");
    forwarded.headers.set("X-Forwarded-Proto", "https");
    forwarded.headers.set("X-Forwarded-Host", originalHost);

    const clientIp = request.headers.get("CF-Connecting-IP");
    if (clientIp) {
      forwarded.headers.set("X-Forwarded-For", clientIp);
    }

    return this.ctx.container!.getTcpPort(PORT).fetch(forwarded);
  }

  private async startAndWaitForPort(): Promise<void> {
    const container = this.ctx.container!;

    if (!container.running) {
      container.start({
        image: container.images.app,
        instance: "basic",
        enableInternet: true,
        env: runtimeEnvironment(this.env),
      });
    }

    this.observeExit();
    await container.setInactivityTimeout(INACTIVITY_TIMEOUT_MS);

    // `running` only means startup was requested; wait for FrankenPHP to
    // answer Laravel's /up health route before forwarding traffic.
    const port = container.getTcpPort(PORT);
    let lastError: unknown;

    for (let attempt = 0; attempt < 300; attempt++) {
      try {
        const response = await port.fetch("http://container/up", {
          signal: AbortSignal.timeout(2000),
        });
        await response.body?.cancel();

        if (response.ok) return;
        lastError = new Error(`Health check returned ${response.status}`);
      } catch (error) {
        lastError = error;
      }

      await scheduler.wait(500);
    }

    throw new Error("Container did not become ready on port 8080", {
      cause: lastError,
    });
  }

  private observeExit(): void {
    if (this.monitoring) return;

    this.monitoring = this.ctx
      .container!.monitor()
      .then(() => console.log("UMerch container exited"))
      .catch((error: unknown) => console.error("UMerch container failed:", error))
      .finally(() => {
        this.monitoring = undefined;
      });

    this.ctx.waitUntil(this.monitoring);
  }
}

export default {
  fetch(request: Request, env: Env): Promise<Response> {
    // A single named instance keeps sessions and uploads consistent.
    return env.UMERCH_CONTAINER.getByName("singleton").fetch(request);
  },
} satisfies ExportedHandler<Env>;
