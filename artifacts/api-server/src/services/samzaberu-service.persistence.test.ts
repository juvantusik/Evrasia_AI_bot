import assert from "node:assert/strict";
import test from "node:test";
import type { BitrixGateway } from "./bitrix-gateway";

const isolatedDatabaseUrl = process.env.SAMZABERU_TEST_DATABASE_URL;
const restaurantId = "142183";
const operatorId = "2103479066";

const fakeBitrixGateway: BitrixGateway = {
  serviceLayer: "FakeBitrixGateway",
  async applyStop(restaurant, _until, simulateFailure) {
    if (simulateFailure) throw new Error("Simulated Bitrix failure");
    return { ruleId: `bitrix-${restaurant.id}` };
  },
  async applyEnable(_restaurant, simulateFailure) {
    if (simulateFailure) throw new Error("Simulated Bitrix failure");
  },
};

if (!isolatedDatabaseUrl) {
  test(
    "persistent SamZaberu status stays consistent across services and Telegram",
    { skip: "Set SAMZABERU_TEST_DATABASE_URL to run this stateful integration test." },
    () => {},
  );
} else {
  test("persistent SamZaberu status stays consistent across services and Telegram", async () => {
    const originalDatabaseUrl = process.env.DATABASE_URL;
    const previousAllowedIds = process.env.ALLOWED_TELEGRAM_USER_IDS;
    process.env.DATABASE_URL = isolatedDatabaseUrl;
    process.env.ALLOWED_TELEGRAM_USER_IDS = operatorId;

    const [{ db, samzaberuRequestsTable, samzaberuRulesTable }, { eq }, { TelegramBot }, { SamzaberuService }] =
      await Promise.all([
        import("@workspace/db"),
        import("drizzle-orm"),
        import("./telegram-bot"),
        import("./samzaberu-service"),
      ]);

    class FakeTelegramClient {
      messages: string[] = [];

      async sendMessage(_chatId: number, text: string) {
        this.messages.push(text);
        return { message_id: this.messages.length, chat: { id: 1 } };
      }

      async sendInlineMessage(_chatId: number, text: string) {
        this.messages.push(text);
        return { message_id: this.messages.length, chat: { id: 1 } };
      }

      async answerCallbackQuery() {
        return true;
      }

      async getUpdates() {
        return [];
      }
    }

    const statusForRestaurant = async (): Promise<string> => {
      const client = new FakeTelegramClient();
      const bot = new TelegramBot(client);
      await bot.handleUpdate({
        update_id: 1,
        message: {
          message_id: 1,
          chat: { id: Number(operatorId) },
          from: { id: Number(operatorId) },
          text: "📋 Текущий статус",
        },
      });
      const lines = (client.messages.at(-1) ?? "").split("\n");
      const restaurantIndex = lines.findIndex((line) => line.includes("Брантовская дорога 3"));
      return lines.slice(restaurantIndex + 1).find((line) => line.trim()) ?? "";
    };

    const [originalRule] = await db
      .select()
      .from(samzaberuRulesTable)
      .where(eq(samzaberuRulesTable.restaurantId, restaurantId));
    const createdRequestIds: string[] = [];

    try {
      await db
        .delete(samzaberuRulesTable)
        .where(eq(samzaberuRulesTable.restaurantId, restaurantId));

      const firstApiInstance = new SamzaberuService(fakeBitrixGateway);
      const cleanRestaurant = (await firstApiInstance.listRestaurants(operatorId)).find(
        (restaurant) => restaurant.id === restaurantId,
      );
      assert.equal(cleanRestaurant?.isRunning, true);
      assert.equal(cleanRestaurant?.stopUntil, null);
      assert.match(await statusForRestaurant(), /Работает/);

      const stopUntil = new Date(Date.now() + 60 * 60 * 1000);
      const stopStartedAt = Date.now();
      const stopRequest = await firstApiInstance.processChange({
        action: "STOP",
        restaurantId,
        operatorId,
        targetUntil: stopUntil,
        confirmation: true,
      });
      createdRequestIds.push(stopRequest.id);
      assert.equal(stopRequest.status, "COMPLETED_AUTO");
      const [createdRule] = await db
        .select()
        .from(samzaberuRulesTable)
        .where(eq(samzaberuRulesTable.restaurantId, restaurantId));
      assert.ok(createdRule);
      assert.ok(createdRule.startsAt.getTime() >= stopStartedAt - 1000);
      assert.ok(createdRule.startsAt.getTime() <= Date.now() + 1000);
      const originalStartsAt = createdRule.startsAt.getTime();

      const secondApiInstance = new SamzaberuService(fakeBitrixGateway);
      const stoppedRestaurant = (await secondApiInstance.listRestaurants(operatorId)).find(
        (restaurant) => restaurant.id === restaurantId,
      );
      assert.equal(stoppedRestaurant?.isRunning, false);
      assert.equal(stoppedRestaurant?.stopUntil?.getTime(), stopUntil.getTime());
      assert.match(await statusForRestaurant(), /Остановлен до/);

      const firstConcurrentUntil = new Date(Date.now() + 90 * 60 * 1000);
      const secondConcurrentUntil = new Date(Date.now() + 120 * 60 * 1000);
      const [firstConcurrentRequest, secondConcurrentRequest] = await Promise.all([
        new SamzaberuService(fakeBitrixGateway).processChange({
          action: "STOP",
          restaurantId,
          operatorId,
          targetUntil: firstConcurrentUntil,
          confirmation: true,
        }),
        new SamzaberuService(fakeBitrixGateway).processChange({
          action: "STOP",
          restaurantId,
          operatorId,
          targetUntil: secondConcurrentUntil,
          confirmation: true,
        }),
      ]);
      createdRequestIds.push(firstConcurrentRequest.id, secondConcurrentRequest.id);
      assert.equal(firstConcurrentRequest.status, "COMPLETED_AUTO");
      assert.equal(secondConcurrentRequest.status, "COMPLETED_AUTO");
      const serializedRestaurant = (await new SamzaberuService(fakeBitrixGateway).listRestaurants(operatorId)).find(
        (restaurant) => restaurant.id === restaurantId,
      );
      assert.ok(
        [firstConcurrentUntil.getTime(), secondConcurrentUntil.getTime()].includes(
          serializedRestaurant?.stopUntil?.getTime() ?? 0,
        ),
      );
      const [repeatedRule] = await db
        .select()
        .from(samzaberuRulesTable)
        .where(eq(samzaberuRulesTable.restaurantId, restaurantId));
      assert.equal(repeatedRule?.startsAt.getTime(), originalStartsAt);
      assert.ok(
        [firstConcurrentUntil.getTime(), secondConcurrentUntil.getTime()].includes(
          repeatedRule?.endsAt.getTime() ?? 0,
        ),
      );

      const enableRequest = await secondApiInstance.processChange({
        action: "ENABLE",
        restaurantId,
        operatorId,
        targetUntil: null,
        confirmation: true,
      });
      createdRequestIds.push(enableRequest.id);
      assert.equal(enableRequest.status, "COMPLETED_AUTO");

      const thirdApiInstance = new SamzaberuService(fakeBitrixGateway);
      const enabledRestaurant = (await thirdApiInstance.listRestaurants(operatorId)).find(
        (restaurant) => restaurant.id === restaurantId,
      );
      assert.equal(enabledRestaurant?.isRunning, true);
      assert.equal(enabledRestaurant?.stopUntil, null);
      assert.match(await statusForRestaurant(), /Работает/);
    } finally {
      for (const requestId of createdRequestIds) {
        await db.delete(samzaberuRequestsTable).where(eq(samzaberuRequestsTable.id, requestId));
      }
      await db
        .delete(samzaberuRulesTable)
        .where(eq(samzaberuRulesTable.restaurantId, restaurantId));
      if (originalRule) {
        await db.insert(samzaberuRulesTable).values(originalRule);
      }
      if (originalDatabaseUrl === undefined) delete process.env.DATABASE_URL;
      else process.env.DATABASE_URL = originalDatabaseUrl;
      if (previousAllowedIds === undefined) delete process.env.ALLOWED_TELEGRAM_USER_IDS;
      else process.env.ALLOWED_TELEGRAM_USER_IDS = previousAllowedIds;
    }
  });
}