import assert from "node:assert/strict";
import test from "node:test";
import {
  createTelegramPollingController,
  TelegramApiError,
  type TelegramBotClientPort,
  type TelegramPollingLease,
} from "./telegram-bot";

const delay = (milliseconds: number): Promise<void> =>
  new Promise((resolve) => setTimeout(resolve, milliseconds));

const waitFor = async (
  condition: () => boolean,
  description: string,
): Promise<void> => {
  const deadline = Date.now() + 1_000;
  while (!condition()) {
    if (Date.now() >= deadline) {
      assert.fail(`Timed out waiting for ${description}`);
    }
    await delay(5);
  }
};

const withPollingEnvironment = async (run: () => Promise<void>): Promise<void> => {
  const previousPolling = process.env.TELEGRAM_BOT_POLLING;
  const previousToken = process.env.BOT_TOKEN;
  process.env.TELEGRAM_BOT_POLLING = "enabled";
  process.env.BOT_TOKEN = "lifecycle-test-token";

  try {
    await run();
  } finally {
    if (previousPolling === undefined) delete process.env.TELEGRAM_BOT_POLLING;
    else process.env.TELEGRAM_BOT_POLLING = previousPolling;
    if (previousToken === undefined) delete process.env.BOT_TOKEN;
    else process.env.BOT_TOKEN = previousToken;
  }
};

const createLeaseManager = (beforeRelease?: () => Promise<void>) => {
  let held = false;
  let acquireAttempts = 0;
  let releaseCount = 0;

  return {
    acquire: async (_token: string): Promise<TelegramPollingLease | null> => {
      acquireAttempts += 1;
      if (held) return null;

      held = true;
      let released = false;
      let lostListener: ((error: Error) => void) | null = null;
      return {
        onLost(listener) {
          lostListener = listener;
          return () => {
            if (lostListener === listener) lostListener = null;
          };
        },
        async release() {
          if (released) return;
          released = true;
          await beforeRelease?.();
          held = false;
          releaseCount += 1;
        },
      };
    },
    get held(): boolean {
      return held;
    },
    get acquireAttempts(): number {
      return acquireAttempts;
    },
    get releaseCount(): number {
      return releaseCount;
    },
  };
};

class BlockingTelegram {
  private readonly callCounts = new Map<string, number>();
  inFlight = 0;
  maxConcurrentCalls = 0;
  cancelledCalls = 0;

  clientFor(replica: string): TelegramBotClientPort {
    return {
      sendMessage: async () => ({ message_id: 1, chat: { id: 1 } }),
      sendInlineMessage: async () => ({ message_id: 1, chat: { id: 1 } }),
      answerCallbackQuery: async () => true,
      getUpdates: async (_offset, signal) => {
        this.callCounts.set(replica, (this.callCounts.get(replica) ?? 0) + 1);
        this.inFlight += 1;
        this.maxConcurrentCalls = Math.max(this.maxConcurrentCalls, this.inFlight);

        await new Promise<void>((resolve) => {
          const cancel = () => {
            signal.removeEventListener("abort", cancel);
            this.inFlight -= 1;
            this.cancelledCalls += 1;
            resolve();
          };
          if (signal.aborted) cancel();
          else signal.addEventListener("abort", cancel, { once: true });
        });

        return [];
      },
    };
  }

  callsFor(replica: string): number {
    return this.callCounts.get(replica) ?? 0;
  }
}

test("a standby replica takes the lease after the holder stops without concurrent polling", async () => {
  await withPollingEnvironment(async () => {
    const leaseManager = createLeaseManager();
    const telegram = new BlockingTelegram();
    const leader = createTelegramPollingController({
      acquireLease: leaseManager.acquire,
      createClient: () => telegram.clientFor("leader"),
      retryDelayMs: 5,
    });
    const standby = createTelegramPollingController({
      acquireLease: leaseManager.acquire,
      createClient: () => telegram.clientFor("standby"),
      retryDelayMs: 5,
    });

    leader.start();
    await waitFor(() => telegram.callsFor("leader") === 1, "leader getUpdates call");

    standby.start();
    await waitFor(
      () => leaseManager.acquireAttempts >= 2,
      "standby to observe that the advisory lease is held",
    );
    assert.equal(
      telegram.callsFor("standby"),
      0,
      "a standby must not call Telegram before owning the lease",
    );

    await leader.stop();
    await waitFor(() => telegram.callsFor("standby") === 1, "standby lease handoff");
    assert.equal(
      telegram.maxConcurrentCalls,
      1,
      "only one replica may have an in-flight getUpdates call",
    );

    await standby.stop();
    assert.equal(leaseManager.held, false);
    assert.equal(leaseManager.releaseCount, 2);
  });
});

test("a Telegram 409 stops its consumer without retrying getUpdates", async () => {
  await withPollingEnvironment(async () => {
    const leaseManager = createLeaseManager();
    let getUpdatesCalls = 0;
    const controller = createTelegramPollingController({
      acquireLease: leaseManager.acquire,
      createClient: (): TelegramBotClientPort => ({
        sendMessage: async () => ({ message_id: 1, chat: { id: 1 } }),
        sendInlineMessage: async () => ({ message_id: 1, chat: { id: 1 } }),
        answerCallbackQuery: async () => true,
        getUpdates: async () => {
          getUpdatesCalls += 1;
          throw new TelegramApiError("another getUpdates request is active", 409);
        },
      }),
      retryDelayMs: 5,
    });

    controller.start();
    await waitFor(() => getUpdatesCalls === 1, "the first getUpdates call");
    await controller.stop();
    await delay(20);

    assert.equal(getUpdatesCalls, 1, "a 409 must not trigger another getUpdates call");
    assert.equal(leaseManager.releaseCount, 1, "the conflicted consumer releases its lease");
  });
});

test("shutdown waits for poller cancellation and lease release", async () => {
  await withPollingEnvironment(async () => {
    let releaseStarted = false;
    let allowRelease: (() => void) | undefined;
    const releaseGate = new Promise<void>((resolve) => {
      allowRelease = resolve;
    });
    const leaseManager = createLeaseManager(async () => {
      releaseStarted = true;
      await releaseGate;
    });
    const telegram = new BlockingTelegram();
    const controller = createTelegramPollingController({
      acquireLease: leaseManager.acquire,
      createClient: () => telegram.clientFor("consumer"),
      retryDelayMs: 5,
    });

    controller.start();
    await waitFor(() => telegram.callsFor("consumer") === 1, "consumer getUpdates call");

    let shutdownFinished = false;
    const shutdown = controller.stop().then(() => {
      shutdownFinished = true;
    });
    await waitFor(() => telegram.cancelledCalls === 1, "poller cancellation");
    await waitFor(() => releaseStarted, "lease release to begin");
    assert.equal(shutdownFinished, false, "shutdown waits for lease release");

    allowRelease?.();
    await shutdown;

    assert.equal(telegram.cancelledCalls, 1);
    assert.equal(leaseManager.releaseCount, 1);
    assert.equal(leaseManager.held, false);
  });
});