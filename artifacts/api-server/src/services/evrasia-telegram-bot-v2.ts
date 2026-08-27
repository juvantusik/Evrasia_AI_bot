import { createHash } from "node:crypto";
import { pool } from "@workspace/db";
import { logger } from "../lib/logger";
import {
  CORPORATE_COMMUNICATIONS_MODULE,
  canEditCorporateDirectory,
  hasModuleAccess,
  isSuperAdmin,
  listBotUsers,
  setModuleAccess,
  upsertBotUser,
} from "./bot-access-service";
import { BOT_SETTING_KEYS, getBotSetting, setBotSetting } from "./bot-settings-service";
import {
  CORPORATE_DIRECTORY_ADMIN_BUTTON,
  CorporateDirectoryTelegramAdmin,
} from "./corporate-directory-telegram-admin";
import {
  DIRECTORY_STATS,
  findCorporatePhones,
  formatChoiceLabel,
  formatCorporateCard,
  formatPhone,
  formatProblemMessage,
  normalizeRussianPhone,
  type CorporatePhoneRecord,
} from "./corporate-communications-v2";
import { resolveTelegramOperatorId } from "./operator-access";
import {
  TelegramApiError,
  TelegramBot,
  type TelegramBotClientPort,
  type TelegramKeyboard,
  type TelegramMessage,
} from "./telegram-bot";

const BOT_VERSION = "1.6.0";
const BACK_TO_ROOT = "⬅️ Главное меню";
const SAMZABERU_BUTTON = "🍱 СамЗаберу";
const CORPORATE_BUTTON = "📱 Корпоративная связь";
const ADMIN_BUTTON = "⚙️ Администрирование";
const ADMIN_USERS_BUTTON = "👥 Пользователи";
const ADMIN_GRANT_BUTTON = "➕ Выдать доступ";
const ADMIN_REVOKE_BUTTON = "🚫 Отозвать доступ";
const ADMIN_MEGAFON_BUTTON = "📡 Группа МегаФона";

const MEGAFON_MANAGER_TELEGRAM_ID = 254113583;
const MEGAFON_MANAGER_NAME = "Вячеслав Сперанский";
const MEGAFON_GROUP_INVITE_URL = "https://t.me/+ZyirzE_Pth0zMmFi";
const GROUP_CLARIFICATION_TTL_MS = 30 * 60 * 1000;

const T2_MANAGER_NAME = "Ольга Антышева";
const T2_MANAGER_PHONE = "+79013011016";
const T2_MANAGER_EMAIL = "olga.antysheva@t2.ru";

type TelegramUser = {
  id: number;
  username?: string;
  first_name?: string;
  last_name?: string;
};

type TelegramChat = {
  id: number;
  type?: "private" | "group" | "supergroup" | "channel";
  title?: string;
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
  | "CORPORATE_SELECT"
  | "CORPORATE_PROBLEM"
  | "ADMIN"
  | "ADMIN_GRANT"
  | "ADMIN_REVOKE";

type PlatformSession = {
  step: SessionStep;
  corporateRecord?: CorporatePhoneRecord;
  corporateCandidates?: CorporatePhoneRecord[];
};

type GroupClarification = {
  problem?: string;
  phone?: string;
  createdAt: number;
};

type ExtractedPhone = {
  normalized: string;
  raw: string;
};

const adminMenu: TelegramKeyboard = [
  [{ text: ADMIN_USERS_BUTTON }],
  [{ text: ADMIN_GRANT_BUTTON }, { text: ADMIN_REVOKE_BUTTON }],
  [{ text: CORPORATE_DIRECTORY_ADMIN_BUTTON }],
  [{ text: ADMIN_MEGAFON_BUTTON }],
  [{ text: BACK_TO_ROOT }],
];

const directoryEditorAdminMenu: TelegramKeyboard = [
  [{ text: CORPORATE_DIRECTORY_ADMIN_BUTTON }],
  [{ text: BACK_TO_ROOT }],
];

const telegramId = (user: TelegramUser): string => String(user.id);
const isPrivateChat = (chat: TelegramChat): boolean => !chat.type || chat.type === "private";

const displayName = (user: TelegramUser): string => {
  const fullName = [user.first_name, user.last_name].filter(Boolean).join(" ").trim();
  return fullName || (user.username ? `@${user.username}` : `Telegram ID ${user.id}`);
};

const parseTelegramId = (value: string): string | null => {
  const normalized = value.trim();
  return /^\d{5,20}$/.test(normalized) ? normalized : null;
};

const escapeHtml = (value: string): string =>
  value.replace(/&/g, "&amp;").replace(/</g, "&lt;").replace(/>/g, "&gt;");

const extractRussianPhoneFromText = (text: string): ExtractedPhone | null => {
  const candidates = text.match(/\+?\d[\d\s().-]{8,24}\d/g) ?? [];
  for (const candidate of candidates) {
    const normalized = normalizeRussianPhone(candidate);
    if (normalized) return { normalized, raw: candidate };
  }
  return null;
};

const problemWithoutPhone = (text: string, extracted: ExtractedPhone | null): string => {
  if (!extracted) return text.trim();
  return text
    .replace(extracted.raw, " ")
    .replace(/\s+/g, " ")
    .replace(/^[\s,;:.\-–—]+|[\s,;:.\-–—]+$/g, "")
    .trim();
};

class EvrasiaTelegramClientV2 implements TelegramBotClientPort {
  constructor(private readonly token: string) {}

  private async call<T>(method: string, body: Record<string, unknown>, signal?: AbortSignal): Promise<T> {
    const response = await fetch(`https://api.telegram.org/bot${this.token}/${method}`, {
      method: "POST",
      headers: { "content-type": "application/json" },
      body: JSON.stringify(body),
      signal,
    });
    const payload = (await response.json()) as TelegramResponse<T>;
    if (!response.ok || !payload.ok) {
      throw new TelegramApiError(payload.description ?? `Telegram API error ${response.status}`, response.status);
    }
    return payload.result;
  }

  private withSamzaberuBack(keyboard?: TelegramKeyboard): TelegramKeyboard | undefined {
    if (!keyboard) return undefined;
    const isSamzaberuMain = keyboard.some((row) =>
      row.some((button) => button.text === "⛔ Остановить СамЗаберу"),
    );
    if (!isSamzaberuMain) return keyboard;
    if (keyboard.some((row) => row.some((button) => button.text === BACK_TO_ROOT))) return keyboard;
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

  sendReply(chatId: number, text: string, replyToMessageId: number): Promise<TelegramMessage> {
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text,
      reply_parameters: {
        message_id: replyToMessageId,
        allow_sending_without_reply: false,
      },
    });
  }

  sendHtmlMessage(chatId: number, html: string): Promise<TelegramMessage> {
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text: html,
      parse_mode: "HTML",
      disable_web_page_preview: true,
    });
  }

  sendInlineMessage(chatId: number, text: string, keyboard: TelegramKeyboard): Promise<TelegramMessage> {
    return this.call<TelegramMessage>("sendMessage", {
      chat_id: chatId,
      text,
      reply_markup: { inline_keyboard: keyboard },
    });
  }

  answerCallbackQuery(callbackQueryId: string): Promise<boolean> {
    return this.call<boolean>("answerCallbackQuery", { callback_query_id: callbackQueryId });
  }

  getUpdates(offset: number, signal: AbortSignal): Promise<any[]> {
    return this.call<any[]>("getUpdates", {
      offset,
      timeout: 25,
      allowed_updates: ["message", "callback_query"],
    }, signal);
  }

  getChatMember(chatId: number, userId: number): Promise<ChatMember> {
    return this.call<ChatMember>("getChatMember", { chat_id: chatId, user_id: userId });
  }
}

export class EvrasiaTelegramBotV2 {
  private readonly sessions = new Map<number, PlatformSession>();
  private readonly groupClarifications = new Map<string, GroupClarification>();
  private readonly samzaberuBot: TelegramBot;
  private readonly corporateDirectoryAdmin: CorporateDirectoryTelegramAdmin;
  private polling = false;
  private abortController: AbortController | null = null;

  constructor(private readonly client: EvrasiaTelegramClientV2) {
    this.samzaberuBot = new TelegramBot(client);
    this.corporateDirectoryAdmin = new CorporateDirectoryTelegramAdmin(client);
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
    if (await hasModuleAccess(id, CORPORATE_COMMUNICATIONS_MODULE)) menu.push([{ text: CORPORATE_BUTTON }]);
    if (isSuperAdmin(id) || canEditCorporateDirectory(id)) menu.push([{ text: ADMIN_BUTTON }]);
    return menu;
  }

  private async showRoot(chatId: number, user: TelegramUser): Promise<void> {
    this.sessions.set(chatId, { step: "ROOT" });
    const menu = await this.rootMenu(user);
    const intro = menu.length
      ? "Выберите нужный раздел. Я покажу только доступные вам функции."
      : `Для вашего Telegram ID пока не выданы доступы.\n\nВаш ID: ${user.id}\nПередайте его администратору.`;
    await this.client.sendMessage(
      chatId,
      `Evrasia AI Bot · v${BOT_VERSION}\n\nЗдравствуйте, ${displayName(user)}!\n${intro}`,
      menu.length ? menu : undefined,
    );
  }

  private buildMegafonGroupMessage(record: CorporatePhoneRecord, problem: string, user: TelegramUser): string {
    const mention = `<a href="tg://user?id=${MEGAFON_MANAGER_TELEGRAM_ID}">${escapeHtml(MEGAFON_MANAGER_NAME)}</a>`;
    const author = escapeHtml(displayName(user));
    return [
      `${mention}, добрый день.`,
      "",
      `ООО: ${escapeHtml(record.legalEntity || "—")}`,
      `ИНН: ${escapeHtml(record.inn || "—")}`,
      `Лицевой счёт: ${escapeHtml(record.accountNumber || "—")}`,
      `Номер телефона: ${escapeHtml(formatPhone(record.phone))}`,
      "",
      `Проблема: ${escapeHtml(problem.trim())}`,
      "",
      "Прошу проверить причину.",
      "",
      `Обращение от: ${author}`,
    ].join("\n");
  }

  private clarificationKey(chatId: number, userId: number): string {
    return `${chatId}:${userId}`;
  }

  private getPendingClarification(chatId: number, userId: number): GroupClarification | undefined {
    const key = this.clarificationKey(chatId, userId);
    const pending = this.groupClarifications.get(key);
    if (!pending) return undefined;
    if (Date.now() - pending.createdAt > GROUP_CLARIFICATION_TTL_MS) {
      this.groupClarifications.delete(key);
      return undefined;
    }
    return pending;
  }

  private async handleGroupMessage(message: PlatformMessage): Promise<void> {
    if (!message.from || !message.text) return;
    await this.registerUser(message.from);
    const id = telegramId(message.from);
    const text = message.text.trim();

    if (text.startsWith("/bind_megafon_group")) {
      if (!isSuperAdmin(id)) {
        await this.client.sendMessage(message.chat.id, "Эту команду может выполнить только Super Admin.");
        return;
      }

      await setBotSetting(BOT_SETTING_KEYS.megafonGroupChatId, String(message.chat.id));
      await this.client.sendMessage(
        message.chat.id,
        `✅ Группа «${message.chat.title ?? "Евразия Мегафон"}» привязана к модулю корпоративной связи.\n\nСделайте бота администратором группы, чтобы он мог видеть обычные сообщения сотрудников и проверять участие в группе.`,
      );
      return;
    }

    const boundGroupId = await getBotSetting(BOT_SETTING_KEYS.megafonGroupChatId);
    if (!boundGroupId || String(message.chat.id) !== boundGroupId) return;
    if (message.from.id === MEGAFON_MANAGER_TELEGRAM_ID) return;
    if (text.startsWith("/")) return;

    const clarificationKey = this.clarificationKey(message.chat.id, message.from.id);
    const pending = this.getPendingClarification(message.chat.id, message.from.id);
    const extracted = extractRussianPhoneFromText(text);
    const normalized = extracted?.normalized ?? pending?.phone ?? null;
    const currentProblem = problemWithoutPhone(text, extracted);
    const currentProblemIsUseful = currentProblem.length >= 3;
    const problem = pending?.problem ?? (currentProblemIsUseful ? currentProblem : undefined);

    if (!normalized) {
      this.groupClarifications.set(clarificationKey, {
        problem: problem ?? text,
        createdAt: Date.now(),
      });
      await this.client.sendReply(
        message.chat.id,
        "Укажите, пожалуйста, номер телефона, по которому возникла проблема. Например: +7 921 123-45-67.",
        message.message_id,
      );
      return;
    }

    const matches = findCorporatePhones(normalized).filter((record) => record.operator === "MEGAFON");
    if (matches.length === 0) {
      this.groupClarifications.set(clarificationKey, {
        problem,
        createdAt: Date.now(),
      });
      await this.client.sendReply(
        message.chat.id,
        `Номер ${formatPhone(normalized)} не найден в справочнике МегаФона. Проверьте, пожалуйста, номер. Если он указан верно — сообщите администратору, чтобы добавить его в базу.`,
        message.message_id,
      );
      return;
    }

    if (matches.length > 1) {
      this.groupClarifications.delete(clarificationKey);
      await this.client.sendReply(
        message.chat.id,
        `Номер ${formatPhone(normalized)} найден в нескольких записях справочника. Обратитесь, пожалуйста, к администратору для проверки привязки номера — бот не будет выбирать юридическое лицо автоматически.`,
        message.message_id,
      );
      return;
    }

    if (!problem) {
      this.groupClarifications.set(clarificationKey, {
        phone: normalized,
        createdAt: Date.now(),
      });
      await this.client.sendReply(
        message.chat.id,
        `Номер ${formatPhone(normalized)} найден. Опишите, пожалуйста, какая проблема возникла с этим номером.`,
        message.message_id,
      );
      return;
    }

    this.groupClarifications.delete(clarificationKey);
    await this.client.sendHtmlMessage(
      message.chat.id,
      this.buildMegafonGroupMessage(matches[0]!, problem, message.from),
    );
  }

  async handleUpdate(update: TelegramUpdate): Promise<void> {
    if (update.callback_query) {
      const query = update.callback_query;
      await this.registerUser(query.from);
      if (!query.message || !isPrivateChat(query.message.chat)) return;
      const session = this.sessions.get(query.message.chat.id);
      if (session?.step !== "SAMZABERU") {
        await this.client.answerCallbackQuery(query.id);
        await this.client.sendMessage(query.message.chat.id, "Этот сценарий уже завершён. Откройте нужный раздел заново.");
        return;
      }
      await (this.samzaberuBot as any).handleUpdate(update);
      return;
    }

    const message = update.message;
    if (!message?.from || !message.text) return;
    if (!isPrivateChat(message.chat)) {
      await this.handleGroupMessage(message);
      return;
    }

    const user = message.from;
    const id = telegramId(user);
    const chatId = message.chat.id;
    const text = message.text.trim();
    await this.registerUser(user);

    if (text === "/id") {
      await this.client.sendMessage(chatId, `Ваш Telegram user ID: ${id}\n\nЭтот ID используется для выдачи прав в Evrasia AI Bot.`);
      return;
    }
    if (text === "/start" || text === BACK_TO_ROOT) {
      this.corporateDirectoryAdmin.cancel(chatId);
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
        "📱 Корпоративная связь\n\nОтправьте номер телефона, по которому возникла проблема.\n\nМожно написать: +7 921..., 8 921..., со скобками или пробелами.",
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }

    if (text === ADMIN_BUTTON) {
      if (!isSuperAdmin(id) && !canEditCorporateDirectory(id)) {
        await this.client.sendMessage(chatId, "У вас нет доступа к разделу администрирования.", await this.rootMenu(user));
        return;
      }
      this.sessions.set(chatId, { step: "ADMIN" });
      const keyboard = isSuperAdmin(id) ? adminMenu : directoryEditorAdminMenu;
      await this.client.sendMessage(
        chatId,
        isSuperAdmin(id)
          ? `⚙️ Администрирование\n\nСправочник связи: ${DIRECTORY_STATS.megafon} номеров МегаФона + ${DIRECTORY_STATS.t2} записей T2.\nНеоднозначных номеров: ${DIRECTORY_STATS.ambiguousPhones}.\n\nЗдесь можно управлять доступами и корпоративным справочником.`
          : `⚙️ Администрирование\n\nВам доступно управление корпоративным справочником номеров.\nСправочник: ${DIRECTORY_STATS.total} записей.`,
        keyboard,
      );
      return;
    }

    if (this.corporateDirectoryAdmin.isActive(chatId)) {
      await this.corporateDirectoryAdmin.handleMessage(chatId, id, text);
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
    if (session.step === "CORPORATE_SELECT") {
      await this.handleCorporateSelection(chatId, user, text, session);
      return;
    }
    if (session.step === "CORPORATE_PROBLEM") {
      await this.handleCorporateProblem(chatId, user, text, session);
      return;
    }

    if (canEditCorporateDirectory(id) && text === CORPORATE_DIRECTORY_ADMIN_BUTTON) {
      await this.corporateDirectoryAdmin.start(
        chatId,
        id,
        isSuperAdmin(id) ? adminMenu : directoryEditorAdminMenu,
      );
      return;
    }

    if (isSuperAdmin(id) && text === ADMIN_USERS_BUTTON) {
      await this.showUsers(chatId);
      return;
    }
    if (isSuperAdmin(id) && text === ADMIN_GRANT_BUTTON) {
      this.sessions.set(chatId, { step: "ADMIN_GRANT" });
      await this.client.sendMessage(chatId, "Отправьте Telegram ID сотрудника, которому нужно открыть «Корпоративную связь».\n\nСотрудник может узнать его командой /id.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (isSuperAdmin(id) && text === ADMIN_REVOKE_BUTTON) {
      this.sessions.set(chatId, { step: "ADMIN_REVOKE" });
      await this.client.sendMessage(chatId, "Отправьте Telegram ID сотрудника, у которого нужно закрыть «Корпоративную связь».", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (isSuperAdmin(id) && text === ADMIN_MEGAFON_BUTTON) {
      await this.showMegafonBinding(chatId);
      return;
    }
    if (session.step === "ADMIN_GRANT" || session.step === "ADMIN_REVOKE") {
      await this.handleAdminAccess(chatId, user, text, session.step === "ADMIN_GRANT");
      return;
    }

    await this.showRoot(chatId, user);
  }

  private async handleCorporatePhone(chatId: number, user: TelegramUser, text: string): Promise<void> {
    if (!(await hasModuleAccess(telegramId(user), CORPORATE_COMMUNICATIONS_MODULE))) {
      await this.showRoot(chatId, user);
      return;
    }
    const normalized = normalizeRussianPhone(text);
    if (!normalized) {
      await this.client.sendMessage(chatId, "Не удалось распознать номер. Отправьте российский номер из 10–11 цифр, например +7 921 123-45-67.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    const matches = findCorporatePhones(normalized);
    if (matches.length === 0) {
      await this.client.sendMessage(chatId, `Номер ${formatPhone(normalized)} не найден в справочнике.\n\nПроверьте номер. Если он указан верно, сообщите администратору — справочник нужно обновить.`, [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (matches.length > 1) {
      this.sessions.set(chatId, { step: "CORPORATE_SELECT", corporateCandidates: matches });
      await this.client.sendMessage(
        chatId,
        `Этот номер встречается в справочнике несколько раз. Выберите нужную запись, отправив её номер:\n\n${matches.map(formatChoiceLabel).join("\n")}`,
        [[{ text: BACK_TO_ROOT }]],
      );
      return;
    }
    await this.askProblem(chatId, matches[0]!);
  }

  private async handleCorporateSelection(chatId: number, user: TelegramUser, text: string, session: PlatformSession): Promise<void> {
    const candidates = session.corporateCandidates ?? [];
    const choice = Number(text);
    if (!Number.isInteger(choice) || choice < 1 || choice > candidates.length) {
      await this.client.sendMessage(chatId, `Отправьте число от 1 до ${candidates.length}.`, [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (!(await hasModuleAccess(telegramId(user), CORPORATE_COMMUNICATIONS_MODULE))) {
      await this.showRoot(chatId, user);
      return;
    }
    await this.askProblem(chatId, candidates[choice - 1]!);
  }

  private async askProblem(chatId: number, record: CorporatePhoneRecord): Promise<void> {
    this.sessions.set(chatId, { step: "CORPORATE_PROBLEM", corporateRecord: record });
    await this.client.sendMessage(chatId, `${formatCorporateCard(record)}\n\nКакая проблема возникла с этим номером? Опишите обычным текстом.`, [[{ text: BACK_TO_ROOT }]]);
  }

  private async handleCorporateProblem(chatId: number, user: TelegramUser, problem: string, session: PlatformSession): Promise<void> {
    const record = session.corporateRecord;
    if (!record) {
      this.sessions.set(chatId, { step: "CORPORATE_PHONE" });
      await this.client.sendMessage(chatId, "Введите номер телефона заново.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (problem.trim().length < 3) {
      await this.client.sendMessage(chatId, "Опишите проблему чуть подробнее.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    if (record.operator === "MEGAFON") {
      await this.handleMegafonProblem(chatId, user, record, problem);
      return;
    }
    await this.handleT2Problem(chatId, user, record, problem);
  }

  private async handleMegafonProblem(chatId: number, user: TelegramUser, record: CorporatePhoneRecord, problem: string): Promise<void> {
    const groupIdText = await getBotSetting(BOT_SETTING_KEYS.megafonGroupChatId);
    const groupChatId = groupIdText ? Number(groupIdText) : NaN;
    if (!Number.isSafeInteger(groupChatId)) {
      this.sessions.set(chatId, { step: "ROOT" });
      await this.client.sendMessage(
        chatId,
        "Группа «Евразия Мегафон» ещё не привязана к боту. Super Admin должен добавить бота в группу и отправить там команду /bind_megafon_group.",
        await this.rootMenu(user),
      );
      return;
    }

    let member: ChatMember;
    try {
      member = await this.client.getChatMember(groupChatId, user.id);
    } catch (error) {
      logger.warn({ err: error, userId: user.id }, "Could not verify MegaFon group membership");
      await this.client.sendMessage(
        chatId,
        "Не удалось проверить ваше участие в группе «Евразия Мегафон». Убедитесь, что бот добавлен в группу как администратор, и повторите обращение.",
        await this.rootMenu(user),
      );
      this.sessions.set(chatId, { step: "ROOT" });
      return;
    }

    if (["left", "kicked"].includes(member.status)) {
      this.sessions.set(chatId, { step: "ROOT" });
      await this.client.sendMessage(
        chatId,
        `Для отправки обращения нужно состоять в группе «Евразия Мегафон».\n\nПрисоединитесь по ссылке и повторите обращение:\n${MEGAFON_GROUP_INVITE_URL}`,
        await this.rootMenu(user),
      );
      return;
    }

    await this.client.sendHtmlMessage(groupChatId, this.buildMegafonGroupMessage(record, problem, user));
    this.sessions.set(chatId, { step: "ROOT" });
    await this.client.sendMessage(chatId, `✅ Обращение отправлено в группу «Евразия Мегафон» и адресовано менеджеру ${MEGAFON_MANAGER_NAME}.`, await this.rootMenu(user));
  }

  private async handleT2Problem(chatId: number, user: TelegramUser, record: CorporatePhoneRecord, problem: string): Promise<void> {
    this.sessions.set(chatId, { step: "ROOT" });
    await this.client.sendMessage(
      chatId,
      `📨 Готовое обращение для T2\n\n${formatProblemMessage(record, problem)}\n\nМенеджер T2: ${T2_MANAGER_NAME}\nТелефон: ${T2_MANAGER_PHONE}\nEmail: ${T2_MANAGER_EMAIL}\n\nТекст выше можно скопировать и отправить по почте либо использовать данные при звонке менеджеру.`,
      await this.rootMenu(user),
    );
  }

  private async handleAdminAccess(chatId: number, admin: TelegramUser, value: string, enabled: boolean): Promise<void> {
    const targetId = parseTelegramId(value);
    if (!targetId) {
      await this.client.sendMessage(chatId, "Telegram ID должен состоять только из цифр. Попросите сотрудника выполнить команду /id.", [[{ text: BACK_TO_ROOT }]]);
      return;
    }
    await setModuleAccess({
      adminTelegramUserId: telegramId(admin),
      targetTelegramUserId: targetId,
      moduleKey: CORPORATE_COMMUNICATIONS_MODULE,
      enabled,
    });
    this.sessions.set(chatId, { step: "ADMIN" });
    await this.client.sendMessage(chatId, enabled ? `✅ Доступ к «Корпоративной связи» выдан пользователю ${targetId}.` : `✅ Доступ к «Корпоративной связи» отозван у пользователя ${targetId}.`, adminMenu);
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
      return `• ${name}${username}\n  ID: ${user.telegramUserId} · ${user.corporateCommunications ? "✅ связь" : "— связь"}`;
    });
    await this.client.sendMessage(chatId, `👥 Последние пользователи\n\n${lines.join("\n\n")}`, adminMenu);
  }

  private async showMegafonBinding(chatId: number): Promise<void> {
    const groupId = await getBotSetting(BOT_SETTING_KEYS.megafonGroupChatId);
    await this.client.sendMessage(
      chatId,
      groupId
        ? `📡 Группа МегаФона уже привязана.\nChat ID: ${groupId}\n\nБот обрабатывает обычные сообщения только в этой группе. Чтобы перепривязать другую группу, добавьте туда бота и отправьте /bind_megafon_group от имени Super Admin.`
        : `📡 Группа МегаФона пока не привязана.\n\n1. Добавьте этого бота в группу «Евразия Мегафон».\n2. Сделайте его администратором, чтобы он видел обычные сообщения сотрудников.\n3. Отправьте в группе команду /bind_megafon_group.\n\nChat ID сохранится автоматически — на сервер заходить не нужно.`,
      adminMenu,
    );
  }

  async startPolling(): Promise<"stopped" | "conflict"> {
    if (this.polling) return "stopped";
    this.polling = true;
    this.abortController = new AbortController();
    let offset = 0;
    let result: "stopped" | "conflict" = "stopped";
    logger.info({ version: BOT_VERSION }, "Evrasia AI Telegram bot v2 polling started");
    while (this.polling) {
      try {
        const updates = (await this.client.getUpdates(offset, this.abortController.signal)) as TelegramUpdate[];
        for (const update of updates) {
          offset = update.update_id + 1;
          await this.handleUpdate(update);
        }
      } catch (error) {
        if (!this.polling) break;
        if (error instanceof TelegramApiError && error.status === 409) {
          logger.error("Telegram getUpdates conflict: another consumer is using this bot token");
          result = "conflict";
          this.stop();
          break;
        }
        logger.error({ err: error }, "Evrasia AI Telegram bot v2 polling failed");
        await new Promise((resolve) => setTimeout(resolve, 5000));
      }
    }
    this.polling = false;
    this.abortController = null;
    return result;
  }

  stop(): void {
    this.polling = false;
    this.abortController?.abort();
  }
}

type PollingLease = { release: () => Promise<void> };

const isPollingEnabled = (): boolean => {
  const value = process.env.TELEGRAM_BOT_POLLING?.trim().toLowerCase();
  return value === "enabled" || value === "true" || value === "1";
};

const acquirePollingLease = async (token: string): Promise<PollingLease | null> => {
  const digest = createHash("sha256").update(`telegram-updates:${token}`).digest();
  const namespaceId = digest.readInt32BE(0);
  const tokenId = digest.readInt32BE(4);
  const client = await pool.connect();
  const result = await client.query<{ locked: boolean }>(
    "SELECT pg_try_advisory_lock($1::integer, $2::integer) AS locked",
    [namespaceId, tokenId],
  );
  if (!result.rows[0]?.locked) {
    client.release();
    return null;
  }
  return {
    async release() {
      try {
        await client.query("SELECT pg_advisory_unlock($1::integer, $2::integer)", [namespaceId, tokenId]);
      } finally {
        client.release();
      }
    },
  };
};

let activeBot: EvrasiaTelegramBotV2 | null = null;
let activeTask: Promise<void> | null = null;
let retryTimer: NodeJS.Timeout | null = null;
let shuttingDown = false;

const scheduleRetry = (): void => {
  if (shuttingDown || retryTimer) return;
  retryTimer = setTimeout(() => {
    retryTimer = null;
    startEvrasiaTelegramBotV2();
  }, 10_000);
  retryTimer.unref();
};

export const startEvrasiaTelegramBotV2 = (): void => {
  if (shuttingDown || activeTask) return;
  if (!isPollingEnabled()) {
    logger.info("Telegram bot polling is disabled");
    return;
  }
  const token = process.env.BOT_TOKEN;
  if (!token) {
    logger.warn("BOT_TOKEN is not configured; Telegram polling is disabled");
    return;
  }

  activeTask = (async () => {
    let lease: PollingLease | null = null;
    try {
      lease = await acquirePollingLease(token);
      if (!lease) {
        logger.warn("Telegram polling lease is held by another instance; retrying later");
        return;
      }
      activeBot = new EvrasiaTelegramBotV2(new EvrasiaTelegramClientV2(token));
      const endReason = await activeBot.startPolling();
      if (endReason !== "conflict") scheduleRetry();
    } catch (error) {
      logger.error({ err: error }, "Evrasia AI Telegram bot v2 startup failed");
      scheduleRetry();
    } finally {
      activeBot = null;
      if (lease) await lease.release().catch((error) => logger.error({ err: error }, "Could not release Telegram polling lease"));
      activeTask = null;
      if (!shuttingDown) scheduleRetry();
    }
  })();
};

export const stopEvrasiaTelegramBotV2 = async (): Promise<void> => {
  shuttingDown = true;
  if (retryTimer) {
    clearTimeout(retryTimer);
    retryTimer = null;
  }
  activeBot?.stop();
  await activeTask;
};