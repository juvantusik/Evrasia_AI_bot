import { randomUUID } from "node:crypto";
import {
  db,
  samzaberuRequestsTable,
  samzaberuRulesTable,
  type SamzaberuRequestRecord,
  type SamzaberuRule,
} from "@workspace/db";
import { desc, eq, sql } from "drizzle-orm";
import { restaurantDirectory, type RestaurantDirectoryEntry } from "./restaurant-directory";
import { BitrixHttpGateway, type BitrixGateway } from "./bitrix-gateway";
import {
  getOperatorAccessOverview,
  isAllowedOperatorId,
  type OperatorAccessView,
} from "./operator-access";

export type RequestStatus =
  | "REQUESTED"
  | "PROCESSING"
  | "COMPLETED_AUTO"
  | "ESCALATED"
  | "COMPLETED_MANUAL"
  | "CANCELLED";
export type SamzaberuAction = "STOP" | "ENABLE";

type Rule = {
  id: string;
  restaurantId: string;
  active: boolean;
  startsAt: Date;
  endsAt: Date;
  notificationType: "Без уведомлений, заказ отключен";
};

export type RestaurantView = {
  id: string;
  name: string;
  shortName: string;
  address: string;
  operatorId: string;
  operatorName: string;
  isRunning: boolean;
  stopUntil: Date | null;
  ruleId: string | null;
  ruleActive: boolean;
  serviceLayer: string;
};

export type SamzaberuRequest = {
  id: string;
  status: RequestStatus;
  action: SamzaberuAction;
  restaurantId: string;
  restaurantName: string;
  operatorId: string;
  operatorName: string;
  requestedAt: Date;
  targetUntil: Date | null;
  reason?: string;
  message: string;
  retryCount: number;
  escalatedTo: string[];
  canCompleteManually: boolean;
};

export type ChangeRequest = {
  action: SamzaberuAction;
  restaurantId: string;
  operatorId: string;
  targetUntil: Date | null;
  confirmation: boolean;
  simulateFailure?: boolean;
};

export type EscalationPayload = {
  restaurantName: string;
  targetUntil: Date | null;
  operatorName: string;
  requestedAt: Date;
  reason: string;
  requestId: string;
};

export interface EscalationNotifier {
  notify(payload: EscalationPayload): Promise<string[]>;
  notifyManualCompletion(request: SamzaberuRequest): Promise<void>;
}

class EnvironmentTelegramNotifier implements EscalationNotifier {
  async notify(payload: EscalationPayload): Promise<string[]> {
    const personalChat = process.env.PERSONAL_CHAT_ID;
    const emergencyGroup = process.env.EMERGENCY_GROUP_CHAT_ID;
    const destinations = [
      personalChat ? "Личный чат ответственного" : "Личный чат ответственного (настройка ожидается)",
      emergencyGroup ? "Рабочая группа" : "Рабочая группа (настройка ожидается)",
    ];

    void payload;
    return destinations;
  }

  async notifyManualCompletion(_request: SamzaberuRequest): Promise<void> {
    // The future adapter notifies the source operator after a manual completion.
  }
}

const isStopActive = (rule: Rule | null, at = new Date()): boolean =>
  Boolean(rule?.active && rule.endsAt.getTime() > at.getTime());

class RequestValidationError extends Error {}

const toRule = (record: SamzaberuRule | undefined): Rule | null =>
  record
    ? {
        id: record.ruleId,
        restaurantId: record.restaurantId,
        active: record.active,
        startsAt: record.startsAt,
        endsAt: record.endsAt,
        notificationType: "Без уведомлений, заказ отключен",
      }
    : null;

const toRestaurantView = (
  restaurant: RestaurantDirectoryEntry,
  rule: Rule | null,
  serviceLayer: string,
): RestaurantView => {
  const stopped = isStopActive(rule);
  return {
    id: restaurant.id,
    name: restaurant.name,
    shortName: restaurant.name,
    address: restaurant.address,
    operatorId: restaurant.operatorId,
    operatorName: restaurant.operatorName,
    isRunning: !stopped,
    stopUntil: stopped ? rule?.endsAt ?? null : null,
    ruleId: rule?.id ?? null,
    ruleActive: rule?.active ?? false,
    serviceLayer,
  };
};

const toRequest = (record: SamzaberuRequestRecord): SamzaberuRequest => ({
  id: record.id,
  status: record.status as RequestStatus,
  action: record.action as SamzaberuAction,
  restaurantId: record.restaurantId,
  restaurantName: record.restaurantName,
  operatorId: record.operatorId,
  operatorName: record.operatorName,
  requestedAt: record.requestedAt,
  targetUntil: record.targetUntil,
  reason: record.reason ?? undefined,
  message: record.message,
  retryCount: record.retryCount,
  escalatedTo: record.escalatedTo,
  canCompleteManually: record.canCompleteManually,
});

export class SamzaberuService {
  private readonly gateway: BitrixGateway;
  private readonly notifier: EscalationNotifier;

  constructor(
    gateway: BitrixGateway = new BitrixHttpGateway(),
    notifier: EscalationNotifier = new EnvironmentTelegramNotifier(),
  ) {
    this.gateway = gateway;
    this.notifier = notifier;
  }

  async listRestaurants(operatorId: string): Promise<RestaurantView[]> {
    this.assertAllowed(operatorId);
    const rules = await this.getRulesByRestaurantId();
    const hasDirectAssignment = restaurantDirectory.some(
      (restaurant) => restaurant.operatorId === operatorId,
    );
    return restaurantDirectory
      .filter(
        (restaurant) =>
          !hasDirectAssignment || restaurant.operatorId === operatorId,
      )
      .map((restaurant) =>
        toRestaurantView(
          restaurant,
          toRule(rules.get(restaurant.id)),
          this.gateway.serviceLayer,
        ),
      );
  }

  async findRestaurantForMatching(value: string): Promise<RestaurantView | undefined> {
    const normalized = value.trim().toLocaleLowerCase("ru-RU");
    const restaurant = restaurantDirectory.find((item) =>
      [item.name, item.address, ...(item.aliases ?? [])].some(
        (candidate) => candidate.toLocaleLowerCase("ru-RU") === normalized,
      ),
    );
    if (!restaurant) return undefined;
    const rule = await this.getRule(restaurant.id);
    return toRestaurantView(restaurant, rule, this.gateway.serviceLayer);
  }

  hasDirectRestaurantAssignment(operatorId: string): boolean {
    this.assertAllowed(operatorId);
    return restaurantDirectory.some((restaurant) => restaurant.operatorId === operatorId);
  }

  getAccessOverview(): OperatorAccessView[] {
    return getOperatorAccessOverview();
  }

  async getSummary(operatorId: string): Promise<{
    operatorId: string;
    operatorName: string;
    totalRestaurants: number;
    runningCount: number;
    stoppedCount: number;
    pendingCount: number;
    restaurants: RestaurantView[];
    recentRequests: SamzaberuRequest[];
  }> {
    const [restaurants, requests] = await Promise.all([
      this.listRestaurants(operatorId),
      this.listRequests(operatorId),
    ]);
    const operatorName = restaurants[0]?.operatorName ?? "Операционный управляющий";
    const recentRequests = requests.slice(0, 5);

    return {
      operatorId,
      operatorName,
      totalRestaurants: restaurants.length,
      runningCount: restaurants.filter((restaurant) => restaurant.isRunning).length,
      stoppedCount: restaurants.filter((restaurant) => !restaurant.isRunning).length,
      pendingCount: recentRequests.filter((request) =>
        ["REQUESTED", "PROCESSING", "ESCALATED"].includes(request.status),
      ).length,
      restaurants,
      recentRequests,
    };
  }

  async listRequests(operatorId: string): Promise<SamzaberuRequest[]> {
    this.assertAllowed(operatorId);
    const requests = await db
      .select()
      .from(samzaberuRequestsTable)
      .where(eq(samzaberuRequestsTable.operatorId, operatorId))
      .orderBy(desc(samzaberuRequestsTable.requestedAt));
    return requests.map(toRequest);
  }

  async processChange(input: ChangeRequest): Promise<SamzaberuRequest> {
    this.assertAllowed(input.operatorId);
    if (!input.confirmation) {
      throw new Error("Изменение должно быть подтверждено");
    }
    if (input.action === "STOP" && !input.targetUntil) {
      throw new Error("Для остановки требуется дата и время окончания");
    }
    if (input.targetUntil && input.targetUntil.getTime() <= Date.now()) {
      throw new Error("Дата окончания должна быть в будущем");
    }

    const restaurant = this.getAllowedRestaurant(input.operatorId, input.restaurantId);
    if (!restaurant) {
      throw new Error("Ресторан недоступен этому операционному управляющему");
    }

    const requestId = `req-${randomUUID()}`;
    const requestedAt = new Date();
    await db.insert(samzaberuRequestsTable).values({
      id: requestId,
      status: "REQUESTED",
      action: input.action,
      restaurantId: restaurant.id,
      restaurantName: restaurant.name,
      operatorId: input.operatorId,
      operatorName: restaurant.operatorName,
      requestedAt,
      targetUntil: input.action === "STOP" ? input.targetUntil : null,
      message: "Запрос зарегистрирован",
      retryCount: 0,
      escalatedTo: [],
      canCompleteManually: false,
    });
    let errorReason: string | null = null;
    try {
      errorReason = await db.transaction(async (tx) => {
        await tx.execute(
          sql`select pg_advisory_xact_lock(hashtext(${"samzaberu:" + restaurant.id}))`,
        );
        const [ruleRecord] = await tx
          .select()
          .from(samzaberuRulesTable)
          .where(eq(samzaberuRulesTable.restaurantId, restaurant.id));
        const currentRule = toRule(ruleRecord);
        if (input.action === "ENABLE" && !isStopActive(currentRule)) {
          throw new RequestValidationError("Для ресторана нет действующей остановки СамЗаберу");
        }

        await tx
          .update(samzaberuRequestsTable)
          .set({
            status: "PROCESSING",
            message: "Проверяем и применяем изменение через сервисный слой",
          })
          .where(eq(samzaberuRequestsTable.id, requestId));

        let lastError = "Не удалось подтвердить фактическое состояние после повторных попыток";
        for (let attempt = 1; attempt <= 3; attempt += 1) {
          await tx
            .update(samzaberuRequestsTable)
            .set({ retryCount: attempt })
            .where(eq(samzaberuRequestsTable.id, requestId));
          try {
            if (input.action === "STOP" && input.targetUntil) {
              const bitrixResult = await this.gateway.applyStop(
                restaurant,
                input.targetUntil,
                Boolean(input.simulateFailure),
              );
              const now = new Date();
              const startsAt = currentRule?.startsAt ?? now;
              await tx
                .insert(samzaberuRulesTable)
                .values({
                  restaurantId: restaurant.id,
                  ruleId: bitrixResult.ruleId,
                  active: true,
                  startsAt,
                  endsAt: input.targetUntil,
                  notificationType: "Без уведомлений, заказ отключен",
                  updatedAt: now,
                })
                .onConflictDoUpdate({
                  target: samzaberuRulesTable.restaurantId,
                  set: {
                    ruleId: bitrixResult.ruleId,
                    active: true,
                    startsAt,
                    endsAt: input.targetUntil,
                    notificationType: "Без уведомлений, заказ отключен",
                    updatedAt: now,
                  },
                });
            } else {
              await this.gateway.applyEnable(
                restaurant,
                Boolean(input.simulateFailure),
              );
              await tx
                .update(samzaberuRulesTable)
                .set({ active: false, updatedAt: new Date() })
                .where(eq(samzaberuRulesTable.restaurantId, restaurant.id));
            }

            const [persistedRecord] = await tx
              .select()
              .from(samzaberuRulesTable)
              .where(eq(samzaberuRulesTable.restaurantId, restaurant.id));
            const persistedRule = toRule(persistedRecord);
            const verified =
              input.action === "ENABLE"
                ? !isStopActive(persistedRule)
                : Boolean(
                    persistedRule?.active &&
                      persistedRule.endsAt.getTime() === input.targetUntil?.getTime() &&
                      isStopActive(persistedRule),
                  );
            if (verified) {
              await tx
                .update(samzaberuRequestsTable)
                .set({
                  status: "COMPLETED_AUTO",
                  message:
                    input.action === "STOP"
                      ? "СамЗаберу остановлен, состояние подтверждено"
                      : "СамЗаберу включен, состояние подтверждено",
                })
                .where(eq(samzaberuRequestsTable.id, requestId));
              return null;
            }
            lastError = "Сервис ответил, но фактический результат не подтвердился";
          } catch (error) {
            lastError =
              error instanceof Error ? error.message : "Неизвестная ошибка сервисного слоя";
          }
        }
        return lastError;
      });
    } catch (error) {
      if (error instanceof RequestValidationError) {
        await this.updateRequest(requestId, {
          status: "CANCELLED",
          reason: error.message,
          message: error.message,
        });
        return this.getRequestOrThrow(requestId);
      }
      errorReason = error instanceof Error ? error.message : "Ошибка транзакции PostgreSQL";
    }

    if (errorReason === null) {
      return this.getRequestOrThrow(requestId);
    }

    const request = await this.getRequestOrThrow(requestId);
    let escalatedTo: string[] = [];
    try {
      escalatedTo = await this.notifier.notify({
        restaurantName: request.restaurantName,
        targetUntil: request.targetUntil,
        operatorName: request.operatorName,
        requestedAt: request.requestedAt,
        reason: errorReason,
        requestId,
      });
    } catch (error) {
      errorReason = `${errorReason}; уведомление ответственного не отправлено: ${
        error instanceof Error ? error.message : "неизвестная ошибка"
      }`;
    }
    await this.updateRequest(requestId, {
      status: "ESCALATED",
      reason: errorReason,
      message: "Автоматическое выполнение не удалось. Задача передана ответственному.",
      canCompleteManually: true,
      escalatedTo,
    });
    return this.getRequestOrThrow(requestId);
  }

  async completeManually(requestId: string, operatorId: string): Promise<SamzaberuRequest> {
    this.assertAllowed(operatorId);
    const request = await this.getRequestOrThrow(requestId);
    if (request.operatorId !== operatorId) {
      throw new Error("Запрос не найден");
    }
    if (request.status !== "ESCALATED") {
      throw new Error("Вручную можно завершить только эскалированный запрос");
    }

    await this.updateRequest(requestId, {
      status: "COMPLETED_MANUAL",
      canCompleteManually: false,
      message: "Задача выполнена вручную. ОУ уведомлён.",
    });
    const completed = await this.getRequestOrThrow(requestId);
    await this.notifier.notifyManualCompletion(completed);
    return completed;
  }

  private async getRulesByRestaurantId(): Promise<Map<string, SamzaberuRule>> {
    const records = await db.select().from(samzaberuRulesTable);
    return new Map(records.map((record) => [record.restaurantId, record]));
  }

  private async getRule(restaurantId: string): Promise<Rule | null> {
    const [record] = await db
      .select()
      .from(samzaberuRulesTable)
      .where(eq(samzaberuRulesTable.restaurantId, restaurantId));
    return toRule(record);
  }

  private getAllowedRestaurant(
    operatorId: string,
    restaurantId: string,
  ): RestaurantDirectoryEntry | undefined {
    const hasDirectAssignment = restaurantDirectory.some(
      (restaurant) => restaurant.operatorId === operatorId,
    );
    return restaurantDirectory.find(
      (restaurant) =>
        restaurant.id === restaurantId &&
        (!hasDirectAssignment || restaurant.operatorId === operatorId),
    );
  }

  private async updateRequest(
    requestId: string,
    values: Partial<{
      status: RequestStatus;
      reason: string;
      message: string;
      retryCount: number;
      escalatedTo: string[];
      canCompleteManually: boolean;
    }>,
  ): Promise<void> {
    await db
      .update(samzaberuRequestsTable)
      .set(values)
      .where(eq(samzaberuRequestsTable.id, requestId));
  }

  private async getRequestOrThrow(requestId: string): Promise<SamzaberuRequest> {
    const [record] = await db
      .select()
      .from(samzaberuRequestsTable)
      .where(eq(samzaberuRequestsTable.id, requestId));
    if (!record) {
      throw new Error("Запрос не найден");
    }
    return toRequest(record);
  }

  private assertAllowed(operatorId: string): void {
    if (!isAllowedOperatorId(operatorId)) {
      throw new Error("Доступ разрешён только для назначенных операционных управляющих");
    }
  }
}

export const samzaberuService = new SamzaberuService();