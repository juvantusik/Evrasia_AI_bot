import { createHash } from "node:crypto";
import { logger } from "../lib/logger";
import { samzaberuService, type RestaurantView } from "./samzaberu-service";
import {
  hasConfiguredTelegramAccess,
  resolveTelegramOperatorId,
} from "./operator-access";

type TelegramUser = { id: number; first_name?: string; last_name?: string };
type TelegramChat = { id: number };
export type TelegramMessage = {
  message_id: number;
  chat: TelegramChat;
  from?: TelegramUser;
  text?: string;
};
type TelegramCallbackQuery = {
  id: string;
  from: TelegramUser;
  message?: TelegramMessage;
  data?: string;
};
type TelegramUpdate = {
  update_id: number;
  message?: TelegramMessage;
  callback_query?: TelegramCallbackQuery;
};

export type TelegramButton = { text: string; callback_data?: string };
export type TelegramKeyboard = TelegramButton[][];

type BotSession = {
  step:
    | "STOP_RESTAURANT"
    | "STOP_DURATION"
    | "CUSTOM_CALENDAR"
    | "CUSTOM_HOUR"
    | "CUSTOM_MINUTE"
    | "CUSTOM_CONFIRM";
  restaurantId?: string;
  calendarMonth?: string;
  selectedDate?: string;
  selectedHour?: number;
  selectedMinute?: number;
};

type TelegramResponse<T> = { ok: boolean; result: T; description?: string };

export type TelegramPollingLease = {
  onLost: (listener: (error: Error) => void) => () => void;
  release: () => Promise<void>;
};

type PollingEndReason = "stopped" | "conflict";

const mainMenu: TelegramKeyboard = [
  [{ text: "⛔ Остановить СамЗаберу" }],
  [{ text: "✅ Включить СамЗаберу" }],
  [{ text: "📋 Текущий статус" }],
];

const durationMenu = (restaurantId: string): TelegramKeyboard => [
  [
    { text: "30 минут", callback_data: `duration:${restaurantId}:30m` },
    { text: "1 час", callback_data: `duration:${restaurantId}:1h` },
  ],
  [
    { text: "2 часа", callback_data: `duration:${restaurantId}:2h` },
    { text: "До 22:00 (МСК)", callback_data: `duration:${restaurantId}:eod` },
  ],
  [{ text: "📅 Выбрать дату и время", callback_data: `duration:${restaurantId}:custom` }],
];

const restaurantMenu = (
  restaurants: RestaurantView[],
  callbackPrefix: "restaurant" | "enable",
): TelegramKeyboard =>
  restaurants.reduce<TelegramKeyboard>((rows, restaurant, index) => {
    if (index % 2 === 0) rows.push([]);
    rows[rows.length - 1]?.push({
      text: restaurant.shortName,
      callback_data: `${callbackPrefix}:${restaurant.id}`,
    });
    return rows;
  }, []);

const telegramUserIdFrom = (user?: TelegramUser): string => String(user?.id ?? "");

const operatorIdFrom = (user?: TelegramUser): string | null =>
  resolveTelegramOperatorId(telegramUserIdFrom(user));

const formatMoscow = (date: Date | null): string => {
  if (!date) return "не задано";
  const parts = new Intl.DateTimeFormat("ru-RU", {
    timeZone: "Europe/Moscow",
    day: "2-digit",
    month: "2-digit",
    year: "numeric",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(date);
  const value = (type: string): string =>
    parts.find((part) => part.type === type)?.value ?? "";
  return `${value("day")}.${value("month")}.${value("year")} ${value("hour")}:${value("minute")}`;
};

const quarterMinutes = [0, 15, 30, 45];
const weekdays = ["Пн", "Вт", "Ср", "Чт", "Пт", "Сб", "Вс"];

const moscowParts = (date = new Date()): {
  year: number;
  month: number;
  day: number;
  hour: number;
  minute: number;
} => {
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Moscow",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
    hour: "2-digit",
    minute: "2-digit",
    hour12: false,
  }).formatToParts(date);
  const value = (type: string): number =>
    Number(parts.find((part) => part.type === type)?.value);
  return {
    year: value("year"),
    month: value("month"),
    day: value("day"),
    hour: value("hour"),
    minute: value("minute"),
  };
};

const dateKey = (year: number, month: number, day: number): string =>
  `${year}-${String(month).padStart(2, "0")}-${String(day).padStart(2, "0")}`;

const monthKey = (year: number, month: number): string =>
  `${year}-${String(month).padStart(2, "0")}`;

const isValidMonthKey = (value: string): boolean => {
  const match = /^(\d{4})-(\d{2})$/.exec(value);
  if (!match) return false;
  const [, year, month] = match.map(Number);
  return Number.isInteger(year) && month >= 1 && month <= 12;
};

const isValidDateKey = (value: string): boolean => {
  const match = /^(\d{4})-(\d{2})-(\d{2})$/.exec(value);
  if (!match) return false;
  const [, year, month, day] = match.map(Number);
  if (month < 1 || month > 12 || day < 1) return false;
  const verified = new Date(Date.UTC(year, month - 1, day));
  return (
    verified.getUTCFullYear() === year &&
    verified.getUTCMonth() === month - 1 &&
    verified.getUTCDate() === day
  );
};

const currentMonthKey = (): string => {
  const now = moscowParts();
  return monthKey(now.year, now.month);
};

const shiftMonth = (value: string, delta: number): string => {
  const [year, month] = value.split("-").map(Number);
  const shifted = new Date(Date.UTC(year, month - 1 + delta, 1));
  return monthKey(shifted.getUTCFullYear(), shifted.getUTCMonth() + 1);
};

const monthLabel = (value: string): string => {
  const [year, month] = value.split("-").map(Number);
  return new Intl.DateTimeFormat("ru-RU", {
    month: "long",
    year: "numeric",
    timeZone: "UTC",
  }).format(new Date(Date.UTC(year, month - 1, 1)));
};

const isDatePast = (value: string): boolean => {
  const now = moscowParts();
  const [year, month, day] = value.split("-").map(Number);
  return (
    year < now.year ||
    (year === now.year && month < now.month) ||
    (year === now.year && month === now.month && day < now.day)
  );
};

const isToday = (value: string): boolean => {
  const now = moscowParts();
  return value === dateKey(now.year, now.month, now.day);
};

const availableHours = (value: string): number[] => {
  if (!isToday(value)) return Array.from({ length: 24 }, (_, hour) => hour);
  const now = moscowParts();
  return Array.from({ length: 24 }, (_, hour) => hour).filter(
    (hour) =>
      hour > now.hour ||
      (hour === now.hour && quarterMinutes.some((minute) => minute > now.minute)),
  );
};

const availableMinutes = (value: string, hour: number): number[] => {
  if (!isToday(value)) return quarterMinutes;
  const now = moscowParts();
  if (hour !== now.hour) return quarterMinutes;
  return quarterMinutes.filter((minute) => minute > now.minute);
};

const fromMoscowLocal = (value: string, hour: number, minute: number): Date => {
  const [year, month, day] = value.split("-").map(Number);
  // Europe/Moscow is UTC+03:00 and has no daylight-saving changes.
  return new Date(Date.UTC(year, month - 1, day, hour - 3, minute, 0));
};

const durationBetween = (until: Date): string => {
  const totalMinutes = Math.max(0, Math.floor((until.getTime() - Date.now()) / 60000));
  const days = Math.floor(totalMinutes / (24 * 60));
  const hours = Math.floor((totalMinutes % (24 * 60)) / 60);
  const minutes = totalMinutes % 60;
  const parts: string[] = [];
  if (days) parts.push(`${days} д.`);
  if (hours) parts.push(`${hours} ч.`);
  if (minutes || parts.length === 0) parts.push(`${minutes} мин.`);
  return parts.join(" ");
};

const nextMoscow2200 = (): Date => {
  const now = new Date();
  const parts = new Intl.DateTimeFormat("en-GB", {
    timeZone: "Europe/Moscow",
    year: "numeric",
    month: "2-digit",
    day: "2-digit",
  }).formatToParts(now);
  const part = (type: string): number =>
    Number(parts.find((item) => item.type === type)?.value);
  // Moscow has no daylight-saving offset; 22:00 Europe/Moscow is 19:00 UTC.
  const candidate = new Date(Date.UTC(part("year"), part("month") - 1, part("day"), 19, 0, 0));
  if (candidate <= now) candidate.setUTCDate(candidate.getUTCDate() + 1);
  return candidate;
};

const futureDateFor = (duration: string): Date | null => {
  const now = new Date();
  const durationMs: Record<string, number> = {
    "30m": 30 * 60 * 1000,
    "1h": 60 * 60 * 1000,
    "2h": 2 * 60 * 60 * 1000,
  };
  if (duration === "eod") return nextMoscow2200();
  return durationMs[duration] ? new Date(now.getTime() + durationMs[duration]) : null;
};

const calendarKeyboard = (value: string): TelegramKeyboard => {
  const [year, month] = value.split("-").map(Number);
  const firstDay = new Date(Date.UTC(year, month - 1, 1));
  const daysInMonth = new Date(Date.UTC(year, month, 0)).getUTCDate();
  const mondayOffset = (firstDay.getUTCDay() + 6) % 7;
  const rows: TelegramKeyboard = [
    weekdays.map((weekday) => ({ text: weekday, callback_data: "noop" })),
  ];
  let week: TelegramButton[] = [];
  for (let index = 0; index < mondayOffset; index += 1) {
    week.push({ text: "·", callback_data: "noop" });
  }
  for (let day = 1; day <= daysInMonth; day += 1) {
    const key = dateKey(year, month, day);
    week.push(
      isDatePast(key) || availableHours(key).length === 0
        ? { text: "·", callback_data: "noop" }
        : { text: String(day), callback_data: `custom_day:${key}` },
    );
    if (week.length === 7) {
      rows.push(week);
      week = [];
    }
  }
  if (week.length > 0) {
    while (week.length < 7) week.push({ text: "·", callback_data: "noop" });
    rows.push(week);
  }

  const navigation: TelegramButton[] = [
    value > currentMonthKey()
      ? {
          text: "‹ Предыдущий месяц",
          callback_data: `custom_month:${shiftMonth(value, -1)}`,
        }
      : { text: "‹ Предыдущий месяц", callback_data: "noop" },
  ];
  navigation.push({
    text: "Следующий месяц ›",
    callback_data: `custom_month:${shiftMonth(value, 1)}`,
  });
  rows.push(navigation);
  rows.push([{ text: "← Назад", callback_data: "custom_back_duration" }]);
  return rows;
};

const hourKeyboard = (date: string): TelegramKeyboard => {
  const rows: TelegramKeyboard = [];
  let row: TelegramButton[] = [];
  for (const hour of availableHours(date)) {
    row.push({
      text: `${String(hour).padStart(2, "0")}:00`,
      callback_data: `custom_hour:${date}:${hour}`,
    });
    if (row.length === 4) {
      rows.push(row);
      row = [];
    }
  }
  if (row.length) rows.push(row);
  rows.push([{ text: "← Назад к календарю", callback_data: "custom_back_calendar" }]);
  return rows;
};

const minuteKeyboard = (date: string, hour: number): TelegramKeyboard => {
  const minutes = availableMinutes(date, hour);
  return [
    minutes.map((minute) => ({
      text: `${String(hour).padStart(2, "0")}:${String(minute).padStart(2, "0")}`,
      callback_data: `custom_minute:${date}:${hour}:${minute}`,
    })),
    [{ text: "← Назад к выбору часа", callback_data: "custom_back_hour" }],
  ];
};

export class TelegramApiError extends Error {
  constructor(
    message: string,
    readonly status: number,
  ) {
    super(message);
    this.name = "TelegramApiError";
  }
}

export type TelegramBotClientPort = {
  sendMessage: (
    chatId: number,
    text: string,
    keyboard?: TelegramKeyboard,
  ) => Promise<TelegramMessage>;
  sendInlineMessage: (
    chatId: number,
    text: string,
    keyboard: TelegramKeyboard,
  ) => Promise<TelegramMessage>;
  answerCallbackQuery: (callbackQueryId: string) => Promise<boolean>;
  getUpdates: (offset: number, signal: AbortSignal) => Promise<TelegramUpdate[]>;
};

class TelegramBotClient implements TelegramBotClientPort {
  constructor(private readonly token: string) {}

  private async call<T>(
    method: string,
    body: Record<string, unknown>,
    signal?: AbortSignal,
  ): Promise<T> {
    const response = await fetch(`https://api.telegram.org/bot${this.token}/${method}`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
      signal,
    });
    const payload = (await response.json()) as TelegramResponse<T>;
    if (!response.ok || !payload.ok) {
      throw new TelegramApiError(
        payload.description ?? `Telegram API error ${response.status}`,
        response.status,
      );
    }
    return payload.result;
  }

  sendMessage(chatId: number, text: string, keyboard?: TelegramKeyboard): Promise<TelegramMessage> {
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text,
      reply_markup: keyboard
        ? { keyboard, resize_keyboard: true, one_time_keyboard: false }
        : undefined,
    });
  }

  sendInlineMessage(
    chatId: number,
    text: string,
    keyboard: TelegramKeyboard,
  ): Promise<TelegramMessage> {
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text,
      reply_markup: { inline_keyboard: keyboard },
    });
  }

  answerCallbackQuery(callbackQueryId: string): Promise<boolean> {
    return this.call<boolean>("answerCallbackQuery", { callback_query_id: callbackQueryId });
  }

  getUpdates(offset: number, signal: AbortSignal): Promise<TelegramUpdate[]> {
    return this.call<TelegramUpdate[]>("getUpdates", {
      offset,
      timeout: 25,
      allowed_updates: ["message", "callback_query"],
    }, signal);
  }
}

export class TelegramBot {
  private readonly sessions = new Map<number, BotSession>();
  private polling = false;
  private abortController: AbortController | null = null;

  constructor(private readonly client: TelegramBotClientPort) {}

  async handleUpdate(update: TelegramUpdate): Promise<void> {
    if (update.callback_query) {
      await this.handleCallback(update.callback_query);
      return;
    }
    if (!update.message?.from || !update.message.text) return;

    const message = update.message;
    const telegramUserId = telegramUserIdFrom(message.from);
    const operatorId = operatorIdFrom(message.from);
    if (message.text === "/id") {
      await this.client.sendMessage(
        message.chat.id,
        `Ваш Telegram user ID: ${telegramUserId}\nПередайте этот ID администратору для привязки доступа.`,
      );
      return;
    }

    if (message.text === "/start") {
      this.sessions.delete(message.chat.id);
      if (!operatorId) {
        await this.client.sendMessage(
          message.chat.id,
          `Доступ к управлению пока не настроен для этого Telegram user ID.\nВаш ID: ${telegramUserId}`,
        );
        return;
      }
      await this.sendMenu(message.chat.id, operatorId);
      return;
    }

    if (!operatorId) {
      await this.client.sendMessage(
        message.chat.id,
        "Для управления СамЗаберу нужно использовать кнопки меню. Доступ ещё не настроен для этого пользователя.",
      );
      return;
    }

    if (message.text === "⛔ Остановить СамЗаберу") {
      await this.showStopRestaurants(message.chat.id, operatorId);
      return;
    }
    if (message.text === "✅ Включить СамЗаберу") {
      await this.showEnableRestaurants(message.chat.id, operatorId);
      return;
    }
    if (message.text === "📋 Текущий статус") {
      await this.showStatus(message.chat.id, operatorId);
      return;
    }

    await this.client.sendMessage(
      message.chat.id,
      "Для управления СамЗаберу нужно использовать кнопки меню.",
      mainMenu,
    );
  }

  private async handleCallback(query: TelegramCallbackQuery): Promise<void> {
    const chatId = query.message?.chat.id;
    if (!chatId || !query.data) return;
    await this.client.answerCallbackQuery(query.id);
    const operatorId = operatorIdFrom(query.from);
    if (!operatorId) {
      await this.client.sendMessage(chatId, "Доступ к этому действию не настроен.");
      return;
    }

    const [kind, restaurantId, duration] = query.data.split(":");
    if (kind === "restaurant") {
      const restaurant = await this.getRestaurant(operatorId, restaurantId);
      if (!restaurant) {
        await this.client.sendMessage(chatId, "Этот ресторан недоступен вашему ОУ.", mainMenu);
        return;
      }
      this.sessions.set(chatId, { step: "STOP_DURATION", restaurantId: restaurant.id });
      await this.client.sendInlineMessage(
        chatId,
        "Выберите срок остановки для ресторана.",
        durationMenu(restaurant.id),
      );
      return;
    }

    if (kind === "duration" && duration) {
      const session = this.sessions.get(chatId);
      const restaurant = await this.getRestaurant(operatorId, restaurantId);
      if (!session || session.step !== "STOP_DURATION" || !restaurant) {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      if (duration === "custom") {
        const customSession: BotSession = {
          step: "CUSTOM_CALENDAR",
          restaurantId: restaurant.id,
          calendarMonth: currentMonthKey(),
        };
        this.sessions.set(chatId, customSession);
        await this.showCustomCalendar(chatId, customSession);
        return;
      }
      const endAt = futureDateFor(duration);
      if (!endAt) {
        await this.client.sendMessage(chatId, "Не удалось определить срок. Откройте сценарий заново.", mainMenu);
        return;
      }
      await this.client.sendInlineMessage(
        chatId,
        `Подтвердите тестовый сценарий:\n\nРесторан: ${restaurant.name}\nОстановить до: ${formatMoscow(endAt)} (МСК)`,
        [[{ text: "✅ Подтвердить тестовый сценарий", callback_data: `confirm:${restaurant.id}:${duration}` }]],
      );
      return;
    }

    if (kind === "custom_month") {
      const session = this.sessions.get(chatId);
      if (!session?.restaurantId || session.step !== "CUSTOM_CALENDAR") {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      const targetMonth = `${restaurantId ?? ""}`;
      if (!isValidMonthKey(targetMonth) || targetMonth < currentMonthKey()) return;
      session.step = "CUSTOM_CALENDAR";
      session.calendarMonth = targetMonth;
      await this.showCustomCalendar(chatId, session);
      return;
    }

    if (kind === "custom_day") {
      const session = this.sessions.get(chatId);
      const selectedDate = restaurantId ?? "";
      if (
        !session?.restaurantId ||
        session.step !== "CUSTOM_CALENDAR" ||
        !isValidDateKey(selectedDate) ||
        session.calendarMonth !== selectedDate.slice(0, 7) ||
        isDatePast(selectedDate) ||
        availableHours(selectedDate).length === 0
      ) {
        await this.client.sendMessage(chatId, "Эта дата уже недоступна. Выберите другую дату.", mainMenu);
        return;
      }
      session.selectedDate = selectedDate;
      session.selectedHour = undefined;
      session.selectedMinute = undefined;
      session.calendarMonth = selectedDate.slice(0, 7);
      session.step = "CUSTOM_HOUR";
      await this.showCustomHours(chatId, session);
      return;
    }

    if (kind === "custom_hour") {
      const session = this.sessions.get(chatId);
      const hour = Number(duration);
      if (
        !session?.restaurantId ||
        session.step !== "CUSTOM_HOUR" ||
        !session.selectedDate ||
        restaurantId !== session.selectedDate ||
        !Number.isInteger(hour) ||
        !availableHours(session.selectedDate).includes(hour)
      ) {
        await this.client.sendMessage(chatId, "Этот час уже недоступен. Выберите другой.", mainMenu);
        return;
      }
      session.selectedHour = hour;
      session.selectedMinute = undefined;
      session.step = "CUSTOM_MINUTE";
      await this.showCustomMinutes(chatId, session);
      return;
    }

    if (kind === "custom_minute") {
      const session = this.sessions.get(chatId);
      const hour = Number(duration);
      const minute = Number(query.data.split(":")[3]);
      if (
        !session?.restaurantId ||
        session.step !== "CUSTOM_MINUTE" ||
        !session.selectedDate ||
        restaurantId !== session.selectedDate ||
        !Number.isInteger(hour) ||
        !Number.isInteger(minute) ||
        session.selectedHour !== hour ||
        !availableMinutes(session.selectedDate, hour).includes(minute)
      ) {
        await this.client.sendMessage(chatId, "Эта минута уже недоступна. Выберите другую.", mainMenu);
        return;
      }
      const endAt = fromMoscowLocal(session.selectedDate, hour, minute);
      if (endAt.getTime() <= Date.now()) {
        await this.client.sendMessage(chatId, "Время уже прошло. Выберите будущее время.", mainMenu);
        return;
      }
      session.selectedMinute = minute;
      session.step = "CUSTOM_CONFIRM";
      await this.showCustomConfirmation(chatId, operatorId, session);
      return;
    }

    if (kind === "custom_back_duration") {
      const session = this.sessions.get(chatId);
      if (!session?.restaurantId || session.step !== "CUSTOM_CALENDAR") {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      session.step = "STOP_DURATION";
      session.calendarMonth = undefined;
      session.selectedDate = undefined;
      session.selectedHour = undefined;
      session.selectedMinute = undefined;
      await this.client.sendInlineMessage(chatId, "Выберите срок остановки для ресторана.", durationMenu(session.restaurantId));
      return;
    }

    if (kind === "custom_back_calendar") {
      const session = this.sessions.get(chatId);
      if (!session?.restaurantId || session.step !== "CUSTOM_HOUR" || !session.selectedDate) {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      session.step = "CUSTOM_CALENDAR";
      session.calendarMonth = session.selectedDate.slice(0, 7);
      await this.showCustomCalendar(chatId, session);
      return;
    }

    if (kind === "custom_back_hour") {
      const session = this.sessions.get(chatId);
      if (
        !session?.restaurantId ||
        session.step !== "CUSTOM_MINUTE" ||
        !session.selectedDate ||
        session.selectedHour === undefined
      ) {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      session.step = "CUSTOM_HOUR";
      await this.showCustomHours(chatId, session);
      return;
    }

    if (kind === "custom_edit") {
      const session = this.sessions.get(chatId);
      if (!session?.restaurantId || session.step !== "CUSTOM_CONFIRM") {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      session.step = "CUSTOM_CALENDAR";
      session.calendarMonth = session.selectedDate?.slice(0, 7) ?? currentMonthKey();
      await this.showCustomCalendar(chatId, session);
      return;
    }

    if (kind === "custom_cancel") {
      const session = this.sessions.get(chatId);
      if (!session || session.step !== "CUSTOM_CONFIRM") {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      this.sessions.delete(chatId);
      await this.client.sendMessage(chatId, "Тестовый сценарий отменён. Данные не изменены.", mainMenu);
      return;
    }

    if (kind === "custom_confirm") {
      const session = this.sessions.get(chatId);
      if (
        !session?.restaurantId ||
        session.step !== "CUSTOM_CONFIRM" ||
        !session.selectedDate ||
        session.selectedHour === undefined ||
        session.selectedMinute === undefined
      ) {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      const restaurant = await this.getRestaurant(operatorId, session.restaurantId);
      const endAt = fromMoscowLocal(session.selectedDate, session.selectedHour, session.selectedMinute);
      if (!restaurant || endAt.getTime() <= Date.now()) {
        await this.client.sendMessage(chatId, "Время окончания должно быть в будущем. Начните сценарий заново.", mainMenu);
        return;
      }
      try {
        const request = await samzaberuService.processChange({
          action: "STOP",
          restaurantId: restaurant.id,
          operatorId,
          targetUntil: endAt,
          confirmation: true,
        });
        this.sessions.delete(chatId);
        await this.client.sendMessage(
          chatId,
          `✅ Тестовое применение завершено.\n\nРесторан: ${request.restaurantName}\nОстановить до: ${formatMoscow(request.targetUntil)} (МСК)\nПродолжительность: ${durationBetween(endAt)}\n\nBitrix не вызывается — использован тестовый сервисный слой. Запрос ${request.id} создан, статус: ${request.status}.`,
          mainMenu,
        );
      } catch (error) {
        await this.client.sendMessage(
          chatId,
          `Не удалось завершить тестовый сценарий: ${error instanceof Error ? error.message : "ошибка сервисного слоя"}`,
          mainMenu,
        );
      }
      return;
    }

    if (kind === "confirm") {
      const restaurant = await this.getRestaurant(operatorId, restaurantId);
      const endAt = futureDateFor(duration ?? "");
      if (!restaurant || !endAt) {
        await this.client.sendMessage(chatId, "Сценарий устарел. Нажмите кнопку остановки заново.", mainMenu);
        return;
      }
      try {
        const request = await samzaberuService.processChange({
          action: "STOP",
          restaurantId: restaurant.id,
          operatorId,
          targetUntil: endAt,
          confirmation: true,
        });
        await this.client.sendMessage(
          chatId,
          `✅ Тестовая остановка сохранена.\n\nРесторан: ${request.restaurantName}\nОстановлен до: ${formatMoscow(request.targetUntil)} (МСК)\n\nBitrix не вызывается — состояние сохранено в общем тестовом сервисе. Запрос ${request.id}, статус: ${request.status}.`,
          mainMenu,
        );
      } catch (error) {
        await this.client.sendMessage(
          chatId,
          `Не удалось остановить ресторан: ${error instanceof Error ? error.message : "ошибка сервисного слоя"}`,
          mainMenu,
        );
      }
      return;
    }

    if (kind === "enable") {
      const restaurant = await this.getRestaurant(operatorId, restaurantId);
      if (!restaurant || restaurant.isRunning) {
        await this.client.sendMessage(chatId, "Для этого ресторана нет действующей остановки.", mainMenu);
        return;
      }
      await this.client.sendInlineMessage(
        chatId,
        `Подтвердите тестовое включение:\n\nРесторан: ${restaurant.name}\n\nПосле подтверждения тестовый сервис деактивирует действующую остановку.`,
        [[{ text: "✅ Подтвердить тестовое включение", callback_data: `enable_confirm:${restaurant.id}` }]],
      );
      return;
    }

    if (kind === "enable_confirm") {
      const restaurant = await this.getRestaurant(operatorId, restaurantId);
      if (!restaurant || restaurant.isRunning) {
        await this.client.sendMessage(chatId, "Для этого ресторана уже нет действующей остановки.", mainMenu);
        return;
      }
      try {
        const request = await samzaberuService.processChange({
          action: "ENABLE",
          restaurantId: restaurant.id,
          operatorId,
          targetUntil: null,
          confirmation: true,
        });
        await this.client.sendMessage(
          chatId,
          request.status === "COMPLETED_AUTO"
            ? `✅ Тестовое включение завершено.\n\nРесторан: ${request.restaurantName}\nСтатус: Работает\n\nBitrix не вызывается — использован тестовый сервисный слой. Запрос ${request.id} создан, статус: ${request.status}.`
            : `⚠️ Тестовое включение не подтверждено автоматически.\n\nРесторан: ${request.restaurantName}\nЗапрос ${request.id} создан, статус: ${request.status}.`,
          mainMenu,
        );
      } catch (error) {
        await this.client.sendMessage(
          chatId,
          `Не удалось включить ресторан: ${error instanceof Error ? error.message : "ошибка сервисного слоя"}`,
          mainMenu,
        );
      }
      return;
    }
  }

  private async showCustomCalendar(chatId: number, session: BotSession): Promise<void> {
    const month = session.calendarMonth ?? currentMonthKey();
    await this.client.sendInlineMessage(
      chatId,
      `Выберите дату окончания остановки по Москве.\n\n${monthLabel(month)}`,
      calendarKeyboard(month),
    );
  }

  private async showCustomHours(chatId: number, session: BotSession): Promise<void> {
    if (!session.selectedDate) return;
    await this.client.sendInlineMessage(
      chatId,
      `Дата окончания: ${session.selectedDate.split("-").reverse().join(".")}\n\nВыберите час (МСК):`,
      hourKeyboard(session.selectedDate),
    );
  }

  private async showCustomMinutes(chatId: number, session: BotSession): Promise<void> {
    if (!session.selectedDate || session.selectedHour === undefined) return;
    await this.client.sendInlineMessage(
      chatId,
      `Дата: ${session.selectedDate.split("-").reverse().join(".")}\nЧас: ${String(session.selectedHour).padStart(2, "0")}\n\nВыберите минуты (МСК):`,
      minuteKeyboard(session.selectedDate, session.selectedHour),
    );
  }

  private async showCustomConfirmation(
    chatId: number,
    operatorId: string,
    session: BotSession,
  ): Promise<void> {
    if (
      !session.restaurantId ||
      !session.selectedDate ||
      session.selectedHour === undefined ||
      session.selectedMinute === undefined
    ) return;
    const restaurant = await this.getRestaurant(operatorId, session.restaurantId);
    const endAt = fromMoscowLocal(session.selectedDate, session.selectedHour, session.selectedMinute);
    if (!restaurant) {
      await this.client.sendMessage(chatId, "Ресторан недоступен. Начните сценарий заново.", mainMenu);
      return;
    }
    await this.client.sendInlineMessage(
      chatId,
      `Проверьте тестовую остановку:\n\nРесторан: ${restaurant.name}\nОстановить до: ${formatMoscow(endAt)} МСК\nПродолжительность: ${durationBetween(endAt)}\n\nПодтверждение создаст тестовый запрос через сервисный слой. Bitrix не вызывается.`,
      [
        [{ text: "✅ Подтвердить", callback_data: "custom_confirm" }],
        [{ text: "✏️ Изменить дату и время", callback_data: "custom_edit" }],
        [{ text: "❌ Отмена", callback_data: "custom_cancel" }],
      ],
    );
  }

  private async sendMenu(chatId: number, operatorId: string): Promise<void> {
    const testAccessNotice = samzaberuService.hasDirectRestaurantAssignment(operatorId)
      ? ""
      : "\n\nТестовый доступ: временно доступен полный справочник из 59 ресторанов.";
    await this.client.sendMessage(
      chatId,
      `СамЗаберу · операционное управление${testAccessNotice}\n\nВыберите действие кнопкой ниже.`,
      mainMenu,
    );
  }

  private async showStopRestaurants(chatId: number, operatorId: string): Promise<void> {
    const restaurants = await samzaberuService.listRestaurants(operatorId);
    this.sessions.set(chatId, { step: "STOP_RESTAURANT" });
    await this.client.sendInlineMessage(
      chatId,
      "Тестовый экран выбора ресторана\n\nВыберите ресторан. Никаких изменений в данных не произойдёт.",
      restaurantMenu(restaurants, "restaurant"),
    );
  }

  private async showEnableRestaurants(chatId: number, operatorId: string): Promise<void> {
    const restaurants = (await samzaberuService
      .listRestaurants(operatorId)
    ).filter((restaurant) => !restaurant.isRunning);
    if (restaurants.length === 0) {
      await this.client.sendMessage(chatId, "Сейчас нет ресторанов с реально действующей остановкой.", mainMenu);
      return;
    }
    await this.client.sendInlineMessage(
      chatId,
      "Выберите ресторан для тестового включения. После подтверждения тестовый сервис деактивирует остановку.",
      restaurantMenu(restaurants, "enable"),
    );
  }

  private async showStatus(chatId: number, operatorId: string): Promise<void> {
    const restaurants: RestaurantView[] = await samzaberuService.listRestaurants(operatorId);
    const lines = restaurants.map((restaurant) =>
      restaurant.isRunning
        ? `• ${restaurant.name}\n  Работает`
        : `• ${restaurant.name}\n  Остановлен до ${formatMoscow(restaurant.stopUntil)} (МСК)`,
    );
    await this.client.sendMessage(chatId, `Текущий статус\n\n${lines.join("\n\n")}`, mainMenu);
  }

  private async getRestaurant(
    operatorId: string,
    restaurantId: string,
  ): Promise<RestaurantView | undefined> {
    return (await samzaberuService.listRestaurants(operatorId)).find(
      (restaurant) => restaurant.id === restaurantId,
    );
  }

  async startPolling(): Promise<PollingEndReason> {
    if (this.polling) return "stopped";
    this.polling = true;
    this.abortController = new AbortController();
    let offset = 0;
    let endReason: PollingEndReason = "stopped";
    logger.info("Telegram bot polling started");
    while (this.polling) {
      try {
        const updates = await this.client.getUpdates(offset, this.abortController.signal);
        for (const update of updates) {
          offset = update.update_id + 1;
          await this.handleUpdate(update);
        }
      } catch (error) {
        if (!this.polling) break;
        if (error instanceof TelegramApiError && error.status === 409) {
          logger.error(
            {
              telegramStatus: error.status,
              telegramDescription: error.message,
              strategy: "Polling stopped; this process will not retry getUpdates.",
              action:
                "Disable polling in the other process, then restart this service after it has stopped.",
            },
            "Telegram getUpdates conflict: another consumer is using this bot token",
          );
          endReason = "conflict";
          this.stop();
          break;
        }
        logger.error({ err: error }, "Telegram bot polling failed");
        await new Promise((resolve) => setTimeout(resolve, 5000));
      }
    }
    this.polling = false;
    this.abortController = null;
    return endReason;
  }

  stop(): void {
    this.polling = false;
    this.abortController?.abort();
  }
}

const isPollingEnabled = (): boolean => {
  const value = process.env.TELEGRAM_BOT_POLLING?.trim().toLowerCase();
  if (value === undefined || value === "" || value === "disabled" || value === "false" || value === "0") {
    return false;
  }
  if (value === "enabled" || value === "true" || value === "1") return true;

  logger.error(
    { telegramBotPolling: value },
    "Invalid TELEGRAM_BOT_POLLING value; Telegram polling is disabled for safety",
  );
  return false;
};

const advisoryLockIds = (token: string): [number, number] => {
  const digest = createHash("sha256")
    .update(`telegram-updates:${token}`)
    .digest();
  return [digest.readInt32BE(0), digest.readInt32BE(4)];
};

const acquireTelegramPollingLease = async (token: string): Promise<TelegramPollingLease | null> => {
  const [namespaceId, tokenId] = advisoryLockIds(token);
  const { pool } = await import("@workspace/db");
  const client = await pool.connect();
  let released = false;
  let lostListener: ((error: Error) => void) | null = null;

  try {
    const result = await client.query<{ locked: boolean }>(
      "SELECT pg_try_advisory_lock($1::integer, $2::integer) AS locked",
      [namespaceId, tokenId],
    );
    if (!result.rows[0]?.locked) {
      client.release();
      return null;
    }
  } catch (error) {
    client.release(error instanceof Error ? error : new Error("Could not acquire Telegram polling lease"));
    throw error;
  }

  const onConnectionError = (error: Error): void => {
    if (released) return;
    released = true;
    client.release(error);
    lostListener?.(error);
  };

  client.once("error", onConnectionError);

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
      client.off("error", onConnectionError);
      try {
        await client.query("SELECT pg_advisory_unlock($1::integer, $2::integer)", [
          namespaceId,
          tokenId,
        ]);
      } catch (error) {
        client.release(error instanceof Error ? error : new Error("Could not release Telegram polling lease"));
        throw error;
      }
      client.release();
    },
  };
};

export type TelegramPollingDependencies = {
  acquireLease?: (token: string) => Promise<TelegramPollingLease | null>;
  createClient?: (token: string) => TelegramBotClientPort;
  retryDelayMs?: number;
};

export type TelegramPollingController = {
  start: () => void;
  stop: () => Promise<void>;
};

class TelegramPollingControllerImpl implements TelegramPollingController {
  private activeBot: TelegramBot | null = null;
  private activePollingTask: Promise<void> | null = null;
  private pollingStartupInProgress = false;
  private pollingShutdownRequested = false;
  private pollingConflictDetected = false;
  private pollingRetryTimer: NodeJS.Timeout | null = null;

  constructor(private readonly dependencies: TelegramPollingDependencies = {}) {}

  private scheduleLeaseRetry(): void {
    if (
      this.pollingShutdownRequested ||
      this.pollingConflictDetected ||
      this.pollingStartupInProgress ||
      this.pollingRetryTimer
    ) {
      return;
    }
    const retryDelayMs = this.dependencies.retryDelayMs ?? 10_000;
    logger.info(
      { retryInMs: retryDelayMs },
      "Telegram polling standby will retry the advisory lease without calling getUpdates",
    );
    this.pollingRetryTimer = setTimeout(() => {
      this.pollingRetryTimer = null;
      this.start();
    }, retryDelayMs);
    this.pollingRetryTimer.unref();
  }

  start(): void {
    if (this.pollingShutdownRequested) return;
    if (!isPollingEnabled()) {
      logger.info(
        { nodeEnv: process.env.NODE_ENV ?? "unknown" },
        "Telegram bot polling is disabled; set TELEGRAM_BOT_POLLING=enabled only in the single production consumer",
      );
      return;
    }
    const token = process.env.BOT_TOKEN;
    if (!token) {
      logger.warn("BOT_TOKEN is not configured; Telegram bot polling is disabled");
      return;
    }
    if (
      this.pollingStartupInProgress ||
      this.activeBot ||
      this.pollingConflictDetected
    ) {
      logger.warn("Telegram bot polling was already requested in this process; duplicate startup skipped");
      return;
    }
    if (!hasConfiguredTelegramAccess()) {
      logger.warn("Telegram access is not configured; the bot will only answer /id until access is configured");
    }
    this.pollingStartupInProgress = true;

    this.activePollingTask = (async () => {
      let lease: TelegramPollingLease | null = null;
      let removeLeaseLossListener: (() => void) | null = null;
      let shouldRetryLease = false;
      try {
        lease = await (this.dependencies.acquireLease ?? acquireTelegramPollingLease)(token);
        if (this.pollingShutdownRequested) return;
        if (!lease) {
          shouldRetryLease = true;
          logger.warn(
            {
              strategy:
                "This instance will serve HTTP only and periodically retry the lease. It will never call getUpdates without the lease.",
            },
            "Telegram polling lease is already held by another service instance",
          );
          return;
        }

        const bot = new TelegramBot(
          (this.dependencies.createClient ?? ((botToken) => new TelegramBotClient(botToken)))(
            token,
          ),
        );
        this.activeBot = bot;
        removeLeaseLossListener = lease.onLost((error) => {
          logger.error(
            {
              err: error,
              strategy:
                "Polling stopped immediately because its PostgreSQL lease was lost; a different instance may safely become the consumer.",
            },
            "Telegram polling lease connection was lost",
          );
          bot.stop();
        });
        const endReason = await bot.startPolling();
        if (endReason === "conflict") {
          this.pollingConflictDetected = true;
        } else {
          shouldRetryLease = true;
        }
      } catch (error) {
        shouldRetryLease = true;
        logger.error(
          {
            err: error,
            strategy:
              "This instance remains HTTP-only and will retry the PostgreSQL lease. Investigate the lease or Telegram configuration if this persists.",
          },
          "Telegram bot polling startup failed",
        );
      } finally {
        removeLeaseLossListener?.();
        this.activeBot = null;
        this.pollingStartupInProgress = false;
        if (lease) {
          try {
            await lease.release();
            logger.info("Telegram bot polling lease released");
          } catch (error) {
            logger.error({ err: error }, "Telegram bot polling lease could not be released cleanly");
          }
        }
        this.activePollingTask = null;
        if (shouldRetryLease) this.scheduleLeaseRetry();
      }
    })();
  }

  async stop(): Promise<void> {
    this.pollingShutdownRequested = true;
    if (this.pollingRetryTimer) {
      clearTimeout(this.pollingRetryTimer);
      this.pollingRetryTimer = null;
    }
    this.activeBot?.stop();
    await this.activePollingTask;
  }
}

export const createTelegramPollingController = (
  dependencies: TelegramPollingDependencies = {},
): TelegramPollingController => new TelegramPollingControllerImpl(dependencies);

const defaultTelegramPollingController = createTelegramPollingController();

export const startTelegramBot = (): void => {
  defaultTelegramPollingController.start();
};

export const stopTelegramBot = async (): Promise<void> => {
  await defaultTelegramPollingController.stop();
};