import assert from "node:assert/strict";
import test from "node:test";
import {
  InsideAccessError,
  resolveInsideSamzaberuSession,
} from "./inside-auth";

const envKeys = [
  "INSIDE_API_BASE_URL",
  "INSIDE_API_TIMEOUT_MS",
  "INSIDE_SAMZABERU_FULL_ACCESS_JOB_IDS",
  "INSIDE_SAMZABERU_ASSIGNED_JOB_IDS",
  "INSIDE_SAMZABERU_FULL_ACCESS_USER_IDS",
  "INSIDE_SAMZABERU_ASSIGNED_USER_IDS",
] as const;

const withCleanEnvironment = async (run: () => Promise<void>): Promise<void> => {
  const previous = new Map(envKeys.map((key) => [key, process.env[key]]));
  for (const key of envKeys) delete process.env[key];
  try {
    await run();
  } finally {
    for (const key of envKeys) {
      const value = previous.get(key);
      if (value === undefined) delete process.env[key];
      else process.env[key] = value;
    }
  }
};

test("assigned Inside employee receives only personal restaurant IDs", async () => {
  await withCleanEnvironment(async () => {
    process.env.INSIDE_API_BASE_URL = "https://inside.example/api/";
    process.env.INSIDE_SAMZABERU_ASSIGNED_JOB_IDS = "46";

    const requestedPaths: string[] = [];
    const fetchImplementation: typeof fetch = async (input) => {
      const url = new URL(input instanceof Request ? input.url : String(input));
      requestedPaths.push(url.pathname);
      if (url.pathname.endsWith("/auth")) {
        return Response.json({
          id: 1001,
          name: "Операционный управляющий",
          login: "ou",
          job_id: 46,
          fact_job_id: 46,
          job_name: "ОУ",
          token: "refreshed-token",
        });
      }
      return Response.json([{ id: 307, name: "пр. Славы, 43" }]);
    };

    const session = await resolveInsideSamzaberuSession(
      "Bearer inside-token",
      fetchImplementation,
    );

    assert.equal(session.accessMode, "assigned");
    assert.equal(session.accessContext.operatorId, "inside:1001");
    assert.deepEqual(
      [...(session.accessContext.allowedRestaurantIds ?? [])],
      ["307"],
    );
    assert.deepEqual(requestedPaths, ["/api/auth", "/api/rests/personal"]);
  });
});

test("full-access Inside employee does not request personal restaurants", async () => {
  await withCleanEnvironment(async () => {
    process.env.INSIDE_API_BASE_URL = "https://inside.example/api/";
    process.env.INSIDE_SAMZABERU_FULL_ACCESS_USER_IDS = "7";
    let requestCount = 0;
    const fetchImplementation: typeof fetch = async () => {
      requestCount += 1;
      return Response.json({
        id: 7,
        name: "ИТ",
        login: "it",
        job_id: 2,
        fact_job_id: 2,
        job_name: "ИТ",
        token: "refreshed-token",
      });
    };

    const session = await resolveInsideSamzaberuSession(
      "Bearer inside-token",
      fetchImplementation,
    );

    assert.equal(session.accessMode, "full");
    assert.equal(session.accessContext.allowedRestaurantIds, null);
    assert.equal(requestCount, 1);
  });
});

test("missing bearer token is rejected before calling Inside", async () => {
  await withCleanEnvironment(async () => {
    let called = false;
    const fetchImplementation: typeof fetch = async () => {
      called = true;
      return Response.json({});
    };

    await assert.rejects(
      () => resolveInsideSamzaberuSession(undefined, fetchImplementation),
      (error) =>
        error instanceof InsideAccessError &&
        error.statusCode === 401 &&
        error.code === "inside_token_missing",
    );
    assert.equal(called, false);
  });
});
