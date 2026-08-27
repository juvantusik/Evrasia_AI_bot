import { createHash } from "node:crypto";
import { logger } from "../lib/logger";
import {
  CORPORATE_COMMUNICATIONS_MODULE,
  hasModuleAccess,
  isSuperAdmin,
  listBotUsers,
  setModuleAccess,
  upsertBotUser,
} from "./bot-access-service";
import {
  findCorporatePhone,
  formatCorporateCard,
  formatProblemMessage,
  normalizeRussianPhone,
  operatorLabel,
} from "./corporate-communications-service";
import { resolveTelegramOperatorId } from "./operator-access";
import {
  TelegramApiError,
  TelegramBot,
  type TelegramBotClientPort,
  type TelegramKeyboard,
  type TelegramMessage,
} from "./telegram-bot";
import type { CorporatePhoneDirectoryRecord } from "@workspace/db";

type TelegramUser = {
  id: number;
  username?: string;
  first_name?: string;
  last_name?: string;
};

type TelegramChat = {
  id: number;
  type?: "private" | "group" | "supergroup" | "channel";
};

type PlatformMessage = TelegramMessage & {
  chat: TelegramChat;
  from?: TelegramUser;
};

type TelegramCallbackQuery = {
  id: string;
  from: TelegramUser;
  message?: PlatformMessage;
  data?: string;
};

type TelegramUpdate = {
  update_id: number;
  message?: PlatformMessage;
  callback_query?: TelegramCallbackQuery;
};

type TelegramResponse<T> = { ok: boolean; result: T; description?: string };

type ChatMember = {
  status: "creator" | "administrator" | "member" | "restricted" | "left" | "kicked";
};

type SessionStep =
  | "ROOT"
  | "SAMZABERU"
  | "CORPORATE_PHONE"
  | "CORPORATE_PROBLEM"
  | "ADMIN"
  | "ADMIN_GRANT"
  | "ADMIN_REVOKE";

type PlatformSession = {
  step: SessionStep;
  corporateRecord?: CorporatePhoneDirectoryRecord;
};

type TelegramPollingLease = {
  onLost: (listener: (error: Error) => void) => () => void;
  release: () => Promise<void>;
};

type PollingEndReason = "stopped" | "conflict";

const BOT_VERSION = "1.5";
const BACK_TO_ROOT = "⬅️ Главное меню";
const SAMZABERU_BUTTON = "🍱 СамЗаберу";
const CORPORATE_BUTTON = "📱 Корпоративная связь";
const ADMIN_BUTTON = "⚙️ Администрирование";
const ADMIN_USERS_BUTTON = "👥 Пользователи";
const ADMIN_GRANT_BUTTON = "➕ Выдать доступ";
const ADMIN_REVOKE_BUTTON = "🚫 Отозвать доступ";

const adminMenu: TelegramKeyboard = [
  [{ text: ADMIN_USERS_BUTTON }],
  [{ text: ADMIN_GRANT_BUTTON }, { text: ADMIN_REVOKE_BUTTON }],
  [{ text: BACK_TO_ROOT }],
];

const telegramId = (user: TelegramUser): string => String(user.id);

const displayName = (user: TelegramUser): string => {
  const fullName = [user.first_name, user.last_name].filter(Boolean).join(" ").trim();
  if (fullName) return fullName;
  if (user.username) return `@${user.username}`;
  return `Telegram ID ${user.id}`;
};

const isPrivateChat = (chat: TelegramChat): boolean =>
  !chat.type || chat.type === "private";

const parseTelegramId = (value: string): string | null => {
  const normalized = value.trim();
  return /^\d{5,20}$/.test(normalized) ? normalized : null;
};

class EvrasiaTelegramClient implements TelegramBotClientPort {
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

  private withSamzaberuBack(keyboard?: TelegramKeyboard): TelegramKeyboard | undefined {
    if (!keyboard) return undefined;
    const isSamzaberuMain = keyboard.some((row) =>
      row.some((button) => button.text === "⛔ Остановить СамЗаберу"),
    );
    if (!isSamzaberuMain) return keyboard;
    if (keyboard.some((row) => row.some((button) => button.text === BACK_TO_ROOT))) {
      return keyboard;
    }
    return [...keyboard, [{ text: BACK_TO_ROOT }]];
  }

  sendMessage(chatId: number, text: string, keyboard?: TelegramKeyboard): Promise<TelegramMessage> {
    const resultKeyboard = this.withSamzaberuBack(keyboard);
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text,
      reply_markup: resultKeyboard
        ? { keyboard: resultKeyboard, resize_keyboard: true, one_time_keyboard: false }
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
    return this.call<TelegramUpdate[]>(
      "getUpdates",
      {
        offset,
        timeout: 25,
        allowed_updates: ["message", "callback_query"],
      },
      signal,
    );
  }

  getChatMember(chatId: number, userId: number): Promise<ChatMember> {
    return this.call<ChatMember>("getChatMember", {
      chat_id: chatId,
      user_id: userId,
    });
  }
}

export class EvrasiaTelegramBot {
  private readonly sessions = new Map<number, PlatformSession>();
  private readonly samzaberuBot: TelegramBot;
  private polling = false;
  private abortController: AbortController | null = null;

  constructor(private readonly client: EvrasiaTelegramClient) {
    this.samzaberuBot = new TelegramBot(client);
  }

  private async registerUser(user: TelegramUser): Promise<void> {
    await upsertBotUser({
      id: telegramId(user),
      username: user.username ?? null,
      firstName: user.first_name ?? null,
      lastName: user.last_name ?? null,
    });
  }

  private async rootMenu(user: TelegramUser): Promise<TelegramKeyboard> {
    const id = telegramId(user);
    const menu: TelegramKeyboard = [];
    if (resolveTelegramOperatorId(id)) menu.push([{ text: SAMZABERU_BUTTON }]);
    if (await hasModuleAccess(id, CORPORATE_COMMUNICATIONS_MODULE)) {
      menu.push([{ text: CORPORATE_BUTTON }]);
    }
    if (isSuperAdmin(id)) menu.push([{ text: ADMIN_BUTTON }]);
    return menu;
  }

  private async showRoot(chatId: number, user: TelegramUser): Promise<void> {
    this.sessions.set(chatId, { step: "ROOT" });
    const menu = await this.rootMenu(user);
    const modules = menu.length
      ? "Выберите нужный раздел. Я покажу только те функции, к которым у вас есть доступ."
      : `Для вашего Telegram ID пока не выданы доступы.\n\nВаш ID: ${user.id}\nПередайте его администратору.`;
    await this.client.sendMessage(
      chatId,
      `Evrasia AI Bot · v${BOT_VERSION}\n\nЗдравствуйте, ${displayName(user)}!\n${modules}`,
      menu.length ? menu : undefined,
    );
  }

  async handleUpdate(update: TelegramUpdate): Promise<void> {
    if (update.callback_query) {
      const query = update.callback_query;
      await this.registerUser(query.from);
      if (!query.message || !isPrivateChat(query.message.chat)) return;
      if (!resolveTelegramOperatorId(telegramId(query.from))) {
        await this.client.answerCallbackQuery(query.id);
        await this.client.sendMessage(query.message.chat.id, "Доступ к СамЗаберу не настроен.");
        return;
      }
      await (this.samzaberuBot as any).handleUpdate(update);
      return;
    }

    const message = update.message;
    if (!message?.from || !message.text || !isPrivateChat(message.chat)) return;
    const user = message.from;
    const id = telegramId(user);
    const chatId = message.chat.id;
    const text = message.text.trim();

    await this.registerUser(user);

    if (text === "/id") {
      await this.client.sendMessage(
        chatId,
        `Ваш Telegram user ID: ${id}\n\nЭтот ID используется для выдачи прав в Evrasia AI Bot.`,
      );
      return;
    }

    if (text === "/start" || text === BACK_TO_ROOT) {
      await this.showRoot(chatId, user);
      return;
    }

    if (text === SAMZABERU_BUTTON) {
      if (!resolveTelegramOperatorId(id)) {
        await this.client.sendMessage(chatId, "У вас нет доступа к разделу «СамЗаберу».", await this.rootMenu(user));
        return;
      }
      this.sessions.set(chatId, { step: "SAMZABERU" });
      await (this.samzaberuBot as any).handleUpdate({
        update_id: update.update_id,
        message: { ...message, text: "/start" },
      });
      return;
    }

    if (text === CORPORATE_BUTTON) {
      if (!(await hasModuleAccess(id, CORPORATE_COMMUNICATIONS_MODULE))) {
        await this.client.sendMessage(chatId, "У вас нет доступа к разделу «Корпоративная связь».", await this.rootMenu(user));
        return;
      }
      this.sessions.set(chatId, { step: "CORPORATE_PHONE" });
      await this.client.sendMessage(
        chatId,
        "📱 Корпоративная связь\n\nОтправьте номер телефона, по которому возникла проблема.\n\nМожно написать в любом привычном формате: +7 921..., 8 921..., со скобками или пробелами.",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    if (text === ADMIN_BUTTON) {
      if (!isSuperAdmin(id)) {
        await this.client.sendMessage(chatId, "Раздел доступен только администратору.", await this.rootMenu(user));
        return;
      }
      this.sessions.set(chatId, { step: "ADMIN" });
      await this.client.sendMessage(
        chatId,
        "⚙️ Администрирование\n\nЗдесь можно управлять доступом сотрудников к модулю «Корпоративная связь» без редактирования файлов на сервере.",
        adminMenu,
      );
      return;
    }

    const session = this.sessions.get(chatId) ?? { step: "ROOT" as const };

    if (session.step === "SAMZABERU") {
      await (this.samzaberuBot as any).handleUpdate(update);
      return;
    }

    if (session.step === "CORPORATE_PHONE") {
      await this.handleCorporatePhone(chatId, user, text);
      return;
    }

    if (session.step === "CORPORATE_PROBLEM") {
      await this.handleCorporateProblem(chatId, user, text, session);
      return;
    }

    if (isSuperAdmin(id) && text === ADMIN_USERS_BUTTON) {
      await this.showUsers(chatId);
      return;
    }
    if (isSuperAdmin(id) && text === ADMIN_GRANT_BUTTON) {
      this.sessions.set(chatId, { step: "ADMIN_GRANT" });
      await this.client.sendMessage(
        chatId,
        "Отправьте Telegram ID сотрудника, которому нужно открыть «Корпоративную связь».\n\nСотрудник может узнать его командой /id.",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }
    if (isSuperAdmin(id) && text === ADMIN_REVOKE_BUTTON) {
      this.sessions.set(chatId, { step: "ADMIN_REVOKE" });
      await this.client.sendMessage(
        chatId,
        "Отправьте Telegram ID сотрудника, у которого нужно закрыть «Корпоративную связь».",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    if (session.step === "ADMIN_GRANT" || session.step === "ADMIN_REVOKE") {
      await this.handleAdminAccess(chatId, user, text, session.step === "ADMIN_GRANT");
      return;
    }

    await this.showRoot(chatId, user);
  }

  private async handleCorporatePhone(
    chatId: number,
    user: TelegramUser,
    text: string,
  ): Promise<void> {
    if (!(await hasModuleAccess(telegramId(user), CORPORATE_COMMUNICATIONS_MODULE))) {
      await this.showRoot(chatId, user);
      return;
    }

    const normalized = normalizeRussianPhone(text);
    if (!normalized) {
      await this.client.sendMessage(
        chatId,
        "Не удалось распознать номер. Отправьте российский номер из 10–11 цифр, например +7 921 123-45-67.",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    const record = await findCorporatePhone(normalized);
    if (!record) {
      await this.client.sendMessage(
        chatId,
        `Номер +${normalized} не найден в справочнике корпоративной связи.\n\nПроверьте номер. Если он указан верно, сообщите администратору — возможно, справочник нужно обновить.`,
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    this.sessions.set(chatId, { step: "CORPORATE_PROBLEM", corporateRecord: record });
    await this.client.sendMessage(
      chatId,
      `${formatCorporateCard(record)}\n\nОпишите, пожалуйста, что произошло с этим номером. Можно написать обычным текстом.`,
      [[{ text: BACK_TO_ROOT }]],
    );
  }

  private async handleCorporateProblem(
    chatId: number,
    user: TelegramUser,
    problem: string,
    session: PlatformSession,
  ): Promise<void> {
    const record = session.corporateRecord;
    if (!record) {
      this.sessions.set(chatId, { step: "CORPORATE_PHONE" });
      await this.client.sendMessage(chatId, "Введите номер телефона заново.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (problem.length < 3) {
      await this.client.sendMessage(chatId, "Опишите проблему чуть подробнее.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }

    if (record.operator === "MEGAFON") {
      await this.handleMegafonProblem(chatId, user, record, problem);
      return;
    }
    if (record.operator === "T2") {
      await this.handleT2Problem(chatId, user, record, problem);
      return;
    }

    await this.client.sendMessage(
      chatId,
      `Для оператора «${operatorLabel(record.operator)}» автоматический сценарий пока не настроен.`,
      await this.rootMenu(user),
    );
    this.sessions.set(chatId, { step: "ROOT" });
  }

  private async handleMegafonProblem(
    chatId: number,
    user: TelegramUser,
    record: CorporatePhoneDirectoryRecord,
    problem: string,
  ): Promise<void> {
    const groupChatId = Number(process.env.MEGAFON_GROUP_CHAT_ID ?? "");
    const managerUsername = (process.env.MEGAFON_MANAGER_USERNAME ?? "").trim().replace(/^@/, "");
    const inviteUrl = (process.env.MEGAFON_GROUP_INVITE_URL ?? "").trim();

    if (!Number.isInteger(groupChatId) || !managerUsername) {
      await this.client.sendMessage(
        chatId,
        "Данные номера найдены, но интеграция с группой «Евразия Мегафон» ещё не настроена на сервере. Обратитесь к администратору бота.",
        await this.rootMenu(user),
      );
      this.sessions.set(chatId, { step: "ROOT" });
      return;
    }

    let isMember = false;
    try {
      const member = await this.client.getChatMember(groupChatId, user.id);
      isMember = !["left", "kicked"].includes(member.status);
    } catch (error) {
      logger.warn({ err: error, telegramUserId: user.id }, "Could not check MegaFon group membership");
    }

    if (!isMember) {
      const joinText = inviteUrl
        ? `\n\nПрисоединитесь к группе и повторите обращение:\n${inviteUrl}`
        : "\n\nСсылку на группу запросите у администратора.";
      await this.client.sendMessage(
        chatId,
        `Для отправки обращения необходимо состоять в группе «Евразия Мегафон».${joinText}`,
        await this.rootMenu(user),
      );
      this.sessions.set(chatId, { step: "ROOT" });
      return;
    }

    const author = user.username
      ? `${displayName(user)} (@${user.username})`
      : displayName(user);
    const groupMessage = [
      `@${managerUsername} добрый день.`,
      "",
      `ООО: ${record.legalEntity}`,
      `ИНН: ${record.inn}`,
      `Лицевой счёт: ${record.accountNumber}`,
      `Номер телефона: +${record.phone}`,
      "",
      `Проблема: ${problem.trim()}`,
      "",
      "Не работает, прошу проверить причину.",
      "",
      `Обращение от: ${author}`,
    ].join("\n");

    await this.client.sendMessage(groupChatId, groupMessage);
    this.sessions.set(chatId, { step: "ROOT" });
    await this.client.sendMessage(
      chatId,
      `✅ Обращение отправлено в группу «Евразия Мегафон» и адресовано @${managerUsername}.`,
      await this.rootMenu(user),
    );
  }

  private async handleT2Problem(
    chatId: number,
    user: TelegramUser,
    record: CorporatePhoneDirectoryRecord,
    problem: string,
  ): Promise<void> {
    const managerName = (process.env.T2_MANAGER_NAME ?? "").trim();
    const managerEmail = (process.env.T2_MANAGER_EMAIL ?? "").trim();
    const managerPhone = (process.env.T2_MANAGER_PHONE ?? "").trim();
    const contacts = [
      managerName ? `Менеджер T2: ${managerName}` : null,
      managerEmail ? `Email: ${managerEmail}` : null,
      managerPhone ? `Телефон: ${managerPhone}` : null,
    ].filter(Boolean);

    const contactBlock = contacts.length
      ? contacts.join("\n")
      : "Контакты менеджера T2 ещё не настроены администратором.";

    this.sessions.set(chatId, { step: "ROOT" });
    await this.client.sendMessage(
      chatId,
      `📨 Готовое обращение для T2\n\n${formatProblemMessage(record, problem)}\n\n${contactBlock}\n\nТекст выше можно скопировать и отправить менеджеру по почте или использовать данные при звонке.`,
      await this.rootMenu(user),
    );
  }

  private async handleAdminAccess(
    chatId: number,
    admin: TelegramUser,
    value: string,
    enabled: boolean,
  ): Promise<void> {
    const targetId = parseTelegramId(value);
    if (!targetId) {
      await this.client.sendMessage(
        chatId,
        "Telegram ID должен состоять только из цифр. Попросите сотрудника выполнить команду /id и перешлите число.",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    await setModuleAccess({
      adminTelegramUserId: telegramId(admin),
      targetTelegramUserId: targetId,
      moduleKey: CORPORATE_COMMUNICATIONS_MODULE,
      enabled,
    });
    this.sessions.set(chatId, { step: "ADMIN" });
    await this.client.sendMessage(
      chatId,
      enabled
        ? `✅ Доступ к «Корпоративной связи» выдан пользователю ${targetId}.`
        : `✅ Доступ к «Корпоративной связи» отозван у пользователя ${targetId}.`,
      adminMenu,
    );
  }

  private async showUsers(chatId: number): Promise<void> {
    const users = await listBotUsers(30);
    if (users.length === 0) {
      await this.client.sendMessage(chatId, "Пока ни один пользователь не запускал бота.", adminMenu);
      return;
    }
    const lines = users.map((user) => {
      const name = [user.firstName, user.lastName].filter(Boolean).join(" ") || user.username || "Без имени";
      const username = user.username ? ` @${user.username}` : "";
      const access = user.corporateCommunications ? "✅ связь" : "— связь";
      return `• ${name}${username}\n  ID: ${user.telegramUserId} · ${access}`;
    });
    await this.client.sendMessage(
      chatId,
      `👥 Последние пользователи\n\n${lines.join("\n\n")}`,
      adminMenu,
    );
  }

  async startPolling(): Promise<PollingEndReason> {
    if (this.polling) return "stopped";
    this.polling = true;
    this.abortController = new AbortController();
    let offset = 0;
    let endReason: PollingEndReason = "stopped";
    logger.info({ version: BOT_VERSION }, "Evrasia AI Telegram bot polling started");

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
          logger.error("Telegram getUpdates conflict: another consumer is using this bot token");
          endReason = "conflict";
          this.stop();
          break;
        }
        logger.error({ err: error }, "Evrasia AI Telegram bot polling failed");
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
  return value === "enabled" || value === "true" || value === "1";
};

const advisoryLockIds = (token: string): [number, number] => {
  const digest = createHash("sha256").update(`telegram-updates:${token}`).digest();
  return [digest.readInt32BE(0), digest.readInt32BE(4)];
};

const acquirePollingLease = async (token: string): Promise<TelegramPollingLease | null> => {
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
        await client.query("SELECT pg_advisory_unlock($1::integer, $2::integer)", [namespaceId, tokenId]);
      } finally {
        client.release();
      }
    },
  };
};

let activeBot: EvrasiaTelegramBot | null = null;
let pollingTask: Promise<void> | null = null;
let shutdownRequested = false;
let retryTimer: NodeJS.Timeout | null = null;

const scheduleRetry = (): void => {
  if (shutdownRequested || retryTimer) return;
  retryTimer = setTimeout(() => {
    retryTimer = null;
    startEvrasiaTelegramBot();
  }, 10_000);
  retryTimer.unref();
};

export const startEvrasiaTelegramBot = (): void => {
  if (shutdownRequested || activeBot || pollingTask) return;
  if (!isPollingEnabled()) {
    logger.info("Telegram polling is disabled");
    return;
  }
  const token = process.env.BOT_TOKEN;
  if (!token) {
    logger.warn("BOT_TOKEN is not configured; Telegram polling is disabled");
    return;
  }

  pollingTask = (async () => {
    let lease: TelegramPollingLease | null = null;
    let removeLostListener: (() => void) | null = null;
    let shouldRetry = false;
    try {
      lease = await acquirePollingLease(token);
      if (!lease) {
        shouldRetry = true;
        logger.warn("Telegram polling lease is held by another service instance");
        return;
      }
      const client = new EvrasiaTelegramClient(token);
      const bot = new EvrasiaTelegramBot(client);
      activeBot = bot;
      removeLostListener = lease.onLost((error) => {
        logger.error({ err: error }, "Telegram polling lease was lost");
        bot.stop();
      });
      const reason = await bot.startPolling();
      shouldRetry = reason !== "conflict";
    } catch (error) {
      shouldRetry = true;
      logger.error({ err: error }, "Evrasia AI Telegram bot startup failed");
    } finally {
      removeLostListener?.();
      activeBot = null;
      if (lease) await lease.release().catch((error) => logger.error({ err: error }, "Could not release Telegram lease"));
      pollingTask = null;
      if (shouldRetry) scheduleRetry();
    }
  })();
};

export const stopEvrasiaTelegramBot = async (): Promise<void> => {
  shutdownRequested = true;
  if (retryTimer) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  activeBot?.stop();
  await pollingTask;
};
