import { canEditCorporateDirectory } from "./bot-access-service";
import {
  addCorporateDirectoryRecord,
  deleteCorporateDirectoryRecord,
  findCorporateDirectoryRecords,
  listCorporateLegalEntities,
  listKnownAccountNumbers,
  updateCorporateDirectoryRecord,
  type CorporateDirectoryAdminRecord,
  type CorporatePhoneMutationInput,
} from "./corporate-directory-admin-service";
import {
  formatPhone,
  normalizeRussianPhone,
  operatorLabel,
} from "./corporate-communications-v2";
import type { TelegramKeyboard, TelegramMessage } from "./telegram-bot";
import type { CorporateLegalEntity } from "@workspace/db";

const ADD_BUTTON = "➕ Добавить номер";
const FIND_BUTTON = "🔎 Найти номер";
const EDIT_BUTTON = "✏️ Изменить данные";
const DELETE_BUTTON = "🗑 Удалить номер";
const BACK_TO_ADMIN = "⬅️ Администрирование";
const CANCEL_BUTTON = "❌ Отмена";
const SAVE_BUTTON = "✅ Сохранить";
const CONFIRM_DELETE_BUTTON = "🗑 Да, удалить";
const NO_VALUE_BUTTON = "➖ Нет / очистить";
const MANUAL_VALUE_BUTTON = "✍️ Ввести другой";
const MEGAFON_BUTTON = "МегаФон";
const T2_BUTTON = "T2";

const EDIT_PHONE_BUTTON = "📱 Номер";
const EDIT_OPERATOR_BUTTON = "📶 Оператор";
const EDIT_ENTITY_BUTTON = "🏢 ЮЛ";
const EDIT_ACCOUNT_BUTTON = "💳 Лицевой счёт";
const EDIT_SUBSCRIBER_BUTTON = "👤 ФИО";
const EDIT_RESTAURANT_BUTTON = "🏪 Ресторан / подразделение";
const EDIT_LINE_TYPE_BUTTON = "🏷 Назначение";
const EDIT_REVIEW_BUTTON = "✅ Проверить и сохранить";

export type CorporateDirectoryAdminClient = {
  sendMessage(chatId: number, text: string, keyboard?: TelegramKeyboard): Promise<TelegramMessage>;
};

type FindAction = "SEARCH" | "EDIT" | "DELETE";

type Step =
  | "MENU"
  | "FIND_QUERY"
  | "FIND_SELECT"
  | "ADD_PHONE"
  | "ADD_OPERATOR"
  | "ADD_ENTITY"
  | "ADD_ACCOUNT"
  | "ADD_ACCOUNT_MANUAL"
  | "ADD_SUBSCRIBER"
  | "ADD_RESTAURANT"
  | "ADD_LINE_TYPE"
  | "ADD_CONFIRM"
  | "EDIT_MENU"
  | "EDIT_PHONE"
  | "EDIT_OPERATOR"
  | "EDIT_ENTITY"
  | "EDIT_ACCOUNT"
  | "EDIT_ACCOUNT_MANUAL"
  | "EDIT_SUBSCRIBER"
  | "EDIT_RESTAURANT"
  | "EDIT_LINE_TYPE"
  | "EDIT_CONFIRM"
  | "DELETE_CONFIRM";

type AdminSession = {
  step: Step;
  adminReturnKeyboard: TelegramKeyboard;
  findAction?: FindAction;
  candidates?: CorporateDirectoryAdminRecord[];
  original?: CorporateDirectoryAdminRecord;
  draft?: Partial<CorporatePhoneMutationInput>;
  legalEntities?: CorporateLegalEntity[];
  accountChoices?: string[];
};

const phoneMenu: TelegramKeyboard = [
  [{ text: ADD_BUTTON }, { text: FIND_BUTTON }],
  [{ text: EDIT_BUTTON }, { text: DELETE_BUTTON }],
  [{ text: BACK_TO_ADMIN }],
];

const operatorKeyboard: TelegramKeyboard = [
  [{ text: MEGAFON_BUTTON }, { text: T2_BUTTON }],
  [{ text: CANCEL_BUTTON }],
];

const editMenuKeyboard: TelegramKeyboard = [
  [{ text: EDIT_OPERATOR_BUTTON }, { text: EDIT_ENTITY_BUTTON }],
  [{ text: EDIT_ACCOUNT_BUTTON }, { text: EDIT_SUBSCRIBER_BUTTON }],
  [{ text: EDIT_RESTAURANT_BUTTON }],
  [{ text: EDIT_LINE_TYPE_BUTTON }, { text: EDIT_PHONE_BUTTON }],
  [{ text: EDIT_REVIEW_BUTTON }],
  [{ text: CANCEL_BUTTON }],
];

const rowsOf = (buttons: string[], width = 2): TelegramKeyboard => {
  const rows: TelegramKeyboard = [];
  for (let index = 0; index < buttons.length; index += width) {
    rows.push(buttons.slice(index, index + width).map((text) => ({ text })));
  }
  return rows;
};

const valueOrDash = (value: string | null | undefined): string =>
  value?.trim() ? value.trim() : "—";

const formatRecord = (record: CorporateDirectoryAdminRecord | CorporatePhoneMutationInput): string => {
  const lines = [
    `📱 ${formatPhone(record.phone)}`,
    `Оператор: ${operatorLabel(record.operator)}`,
    `ЮЛ: ${record.legalEntity}`,
    `ИНН: ${valueOrDash(record.inn)}`,
    `Лицевой счёт: ${valueOrDash(record.accountNumber)}`,
  ];
  if (record.subscriberName) lines.push(`ФИО: ${record.subscriberName}`);
  if (record.restaurantName) lines.push(`Ресторан / подразделение: ${record.restaurantName}`);
  if (record.lineType) lines.push(`Назначение: ${record.lineType}`);
  return lines.join("\n");
};

const entityLabel = (entity: CorporateLegalEntity, duplicates: Map<string, number>): string => {
  const sameNameCount = duplicates.get(entity.name) ?? 0;
  if (sameNameCount <= 1) return entity.name;
  return `${entity.name} · ${entity.inn ?? "без ИНН"}`;
};

const legalEntityLabels = (entities: CorporateLegalEntity[]): Array<{ label: string; entity: CorporateLegalEntity }> => {
  const duplicates = new Map<string, number>();
  for (const entity of entities) duplicates.set(entity.name, (duplicates.get(entity.name) ?? 0) + 1);
  return entities.map((entity) => ({ label: entityLabel(entity, duplicates), entity }));
};

const mutationFromRecord = (record: CorporateDirectoryAdminRecord): CorporatePhoneMutationInput => ({
  phone: record.phone,
  operator: record.operator,
  legalEntity: record.legalEntity,
  inn: record.inn,
  accountNumber: record.accountNumber,
  restaurantName: record.restaurantName,
  lineType: record.lineType,
  subscriberName: record.subscriberName,
});

const completeDraft = (draft: Partial<CorporatePhoneMutationInput> | undefined): CorporatePhoneMutationInput | null => {
  if (!draft?.phone || !draft.operator || !draft.legalEntity) return null;
  return {
    phone: draft.phone,
    operator: draft.operator,
    legalEntity: draft.legalEntity,
    inn: draft.inn ?? null,
    accountNumber: draft.accountNumber ?? null,
    restaurantName: draft.restaurantName ?? null,
    lineType: draft.lineType ?? null,
    subscriberName: draft.subscriberName ?? null,
  };
};

export class CorporateDirectoryTelegramAdmin {
  private readonly sessions = new Map<number, AdminSession>();

  constructor(private readonly client: CorporateDirectoryAdminClient) {}

  isActive(chatId: number): boolean {
    return this.sessions.has(chatId);
  }

  cancel(chatId: number): void {
    this.sessions.delete(chatId);
  }

  async start(chatId: number, telegramUserId: string, adminReturnKeyboard: TelegramKeyboard): Promise<void> {
    if (!canEditCorporateDirectory(telegramUserId)) {
      await this.client.sendMessage(chatId, "У вас нет права редактировать справочник корпоративных номеров.");
      return;
    }
    this.sessions.set(chatId, { step: "MENU", adminReturnKeyboard });
    await this.client.sendMessage(
      chatId,
      "📱 Управление корпоративными номерами\n\nИзменения сразу сохраняются в PostgreSQL и попадают в журнал аудита. При переносе номера меняйте существующую карточку — новую создавать не нужно.",
      phoneMenu,
    );
  }

  private async backToAdmin(chatId: number, session: AdminSession): Promise<void> {
    this.sessions.delete(chatId);
    await this.client.sendMessage(chatId, "⚙️ Администрирование", session.adminReturnKeyboard);
  }

  private async backToPhoneMenu(chatId: number, session: AdminSession, message = "Выберите действие со справочником."): Promise<void> {
    this.sessions.set(chatId, { step: "MENU", adminReturnKeyboard: session.adminReturnKeyboard });
    await this.client.sendMessage(chatId, message, phoneMenu);
  }

  private async ensureEditor(chatId: number, telegramUserId: string, session: AdminSession): Promise<boolean> {
    if (canEditCorporateDirectory(telegramUserId)) return true;
    this.sessions.delete(chatId);
    await this.client.sendMessage(chatId, "Право редактирования справочника отсутствует.", session.adminReturnKeyboard);
    return false;
  }

  async handleMessage(chatId: number, telegramUserId: string, text: string): Promise<boolean> {
    const session = this.sessions.get(chatId);
    if (!session) return false;
    if (!(await this.ensureEditor(chatId, telegramUserId, session))) return true;

    const value = text.trim();
    if (value === BACK_TO_ADMIN) {
      await this.backToAdmin(chatId, session);
      return true;
    }
    if (value === CANCEL_BUTTON) {
      await this.backToPhoneMenu(chatId, session, "Действие отменено.");
      return true;
    }

    try {
      switch (session.step) {
        case "MENU":
          await this.handleMenu(chatId, session, value);
          break;
        case "FIND_QUERY":
          await this.handleFindQuery(chatId, session, value);
          break;
        case "FIND_SELECT":
          await this.handleFindSelect(chatId, session, value);
          break;
        case "ADD_PHONE":
          await this.handleAddPhone(chatId, session, value);
          break;
        case "ADD_OPERATOR":
          await this.handleAddOperator(chatId, session, value);
          break;
        case "ADD_ENTITY":
          await this.handleAddEntity(chatId, session, value);
          break;
        case "ADD_ACCOUNT":
          await this.handleAddAccount(chatId, session, value);
          break;
        case "ADD_ACCOUNT_MANUAL":
          session.draft!.accountNumber = value === NO_VALUE_BUTTON ? null : value;
          session.step = "ADD_SUBSCRIBER";
          await this.client.sendMessage(chatId, "Укажите ФИО сотрудника / владельца номера или выберите «Нет».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
          break;
        case "ADD_SUBSCRIBER":
          session.draft!.subscriberName = value === NO_VALUE_BUTTON ? null : value;
          session.step = "ADD_RESTAURANT";
          await this.client.sendMessage(chatId, "Укажите ресторан / подразделение или выберите «Нет».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
          break;
        case "ADD_RESTAURANT":
          session.draft!.restaurantName = value === NO_VALUE_BUTTON ? null : value;
          session.step = "ADD_LINE_TYPE";
          await this.client.sendMessage(chatId, "Укажите назначение номера (например «Телефон директора», «Входящий номер ресторана», «POS») или выберите «Нет».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
          break;
        case "ADD_LINE_TYPE":
          session.draft!.lineType = value === NO_VALUE_BUTTON ? null : value;
          await this.showAddConfirmation(chatId, session);
          break;
        case "ADD_CONFIRM":
          await this.handleAddConfirm(chatId, telegramUserId, session, value);
          break;
        case "EDIT_MENU":
          await this.handleEditMenu(chatId, session, value);
          break;
        case "EDIT_PHONE":
          await this.handleEditPhone(chatId, session, value);
          break;
        case "EDIT_OPERATOR":
          await this.handleEditOperator(chatId, session, value);
          break;
        case "EDIT_ENTITY":
          await this.handleEditEntity(chatId, session, value);
          break;
        case "EDIT_ACCOUNT":
          await this.handleEditAccount(chatId, session, value);
          break;
        case "EDIT_ACCOUNT_MANUAL":
          session.draft!.accountNumber = value === NO_VALUE_BUTTON ? null : value;
          await this.showEditMenu(chatId, session);
          break;
        case "EDIT_SUBSCRIBER":
          session.draft!.subscriberName = value === NO_VALUE_BUTTON ? null : value;
          await this.showEditMenu(chatId, session);
          break;
        case "EDIT_RESTAURANT":
          session.draft!.restaurantName = value === NO_VALUE_BUTTON ? null : value;
          await this.showEditMenu(chatId, session);
          break;
        case "EDIT_LINE_TYPE":
          session.draft!.lineType = value === NO_VALUE_BUTTON ? null : value;
          await this.showEditMenu(chatId, session);
          break;
        case "EDIT_CONFIRM":
          await this.handleEditConfirm(chatId, telegramUserId, session, value);
          break;
        case "DELETE_CONFIRM":
          await this.handleDeleteConfirm(chatId, telegramUserId, session, value);
          break;
      }
    } catch (error) {
      const message = error instanceof Error ? error.message : "Не удалось выполнить действие со справочником.";
      await this.backToPhoneMenu(chatId, session, `⚠️ ${message}`);
    }
    return true;
  }

  private async handleMenu(chatId: number, session: AdminSession, value: string): Promise<void> {
    if (value === ADD_BUTTON) {
      this.sessions.set(chatId, { ...session, step: "ADD_PHONE", draft: {} });
      await this.client.sendMessage(chatId, "Отправьте новый корпоративный номер телефона.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === FIND_BUTTON) {
      await this.beginFind(chatId, session, "SEARCH");
      return;
    }
    if (value === EDIT_BUTTON) {
      await this.beginFind(chatId, session, "EDIT");
      return;
    }
    if (value === DELETE_BUTTON) {
      await this.beginFind(chatId, session, "DELETE");
      return;
    }
    await this.client.sendMessage(chatId, "Выберите действие кнопкой ниже.", phoneMenu);
  }

  private async beginFind(chatId: number, session: AdminSession, action: FindAction): Promise<void> {
    this.sessions.set(chatId, { ...session, step: "FIND_QUERY", findAction: action, candidates: undefined });
    await this.client.sendMessage(
      chatId,
      action === "SEARCH"
        ? "Введите номер телефона, ФИО, ЮЛ или ресторан для поиска."
        : action === "EDIT"
          ? "Введите номер телефона или ФИО записи, которую нужно изменить / перенести."
          : "Введите номер телефона или ФИО записи, которую нужно удалить.",
      [[{ text: CANCEL_BUTTON }]],
    );
  }

  private async handleFindQuery(chatId: number, session: AdminSession, value: string): Promise<void> {
    const matches = await findCorporateDirectoryRecords(value);
    if (matches.length === 0) {
      await this.client.sendMessage(chatId, "Ничего не найдено. Проверьте запрос и попробуйте ещё раз.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (matches.length === 1) {
      await this.acceptFoundRecord(chatId, session, matches[0]!);
      return;
    }
    session.candidates = matches;
    session.step = "FIND_SELECT";
    const lines = matches.map((record, index) => `${index + 1}. ${formatPhone(record.phone)} · ${record.legalEntity} · ${operatorLabel(record.operator)}${record.subscriberName ? ` · ${record.subscriberName}` : ""}`);
    const numberButtons = rowsOf(matches.map((_, index) => String(index + 1)), 4);
    await this.client.sendMessage(
      chatId,
      `Найдено несколько записей. Выберите нужную:\n\n${lines.join("\n")}`,
      [...numberButtons, [{ text: CANCEL_BUTTON }]],
    );
  }

  private async handleFindSelect(chatId: number, session: AdminSession, value: string): Promise<void> {
    const candidates = session.candidates ?? [];
    const index = Number(value) - 1;
    if (!Number.isInteger(index) || index < 0 || index >= candidates.length) {
      await this.client.sendMessage(chatId, `Выберите номер от 1 до ${candidates.length}.`);
      return;
    }
    await this.acceptFoundRecord(chatId, session, candidates[index]!);
  }

  private async acceptFoundRecord(chatId: number, session: AdminSession, record: CorporateDirectoryAdminRecord): Promise<void> {
    if (session.findAction === "SEARCH") {
      await this.backToPhoneMenu(chatId, session, `🔎 Найденная запись\n\n${formatRecord(record)}`);
      return;
    }
    if (session.findAction === "DELETE") {
      session.original = record;
      session.step = "DELETE_CONFIRM";
      await this.client.sendMessage(
        chatId,
        `Удалить эту запись физически из рабочего справочника?\n\n${formatRecord(record)}\n\nИстория удаления останется только в аудите.`,
        [[{ text: CONFIRM_DELETE_BUTTON }], [{ text: CANCEL_BUTTON }]],
      );
      return;
    }
    session.original = record;
    session.draft = mutationFromRecord(record);
    await this.showEditMenu(chatId, session);
  }

  private async handleAddPhone(chatId: number, session: AdminSession, value: string): Promise<void> {
    const phone = normalizeRussianPhone(value);
    if (!phone) {
      await this.client.sendMessage(chatId, "Не удалось распознать номер. Например: +7 921 123-45-67.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    const existing = await findCorporateDirectoryRecords(phone);
    if (existing.length > 0) {
      await this.backToPhoneMenu(chatId, session, `⚠️ Номер ${formatPhone(phone)} уже есть в справочнике. Для переноса между оператором или ЮЛ используйте «Изменить данные».`);
      return;
    }
    session.draft = { phone };
    session.step = "ADD_OPERATOR";
    await this.client.sendMessage(chatId, "Выберите текущего оператора номера.", operatorKeyboard);
  }

  private operatorFromButton(value: string): "MEGAFON" | "T2" | null {
    if (value === MEGAFON_BUTTON) return "MEGAFON";
    if (value === T2_BUTTON) return "T2";
    return null;
  }

  private async handleAddOperator(chatId: number, session: AdminSession, value: string): Promise<void> {
    const operator = this.operatorFromButton(value);
    if (!operator) {
      await this.client.sendMessage(chatId, "Выберите оператора кнопкой.", operatorKeyboard);
      return;
    }
    session.draft!.operator = operator;
    await this.askLegalEntity(chatId, session, "ADD_ENTITY");
  }

  private async askLegalEntity(chatId: number, session: AdminSession, step: "ADD_ENTITY" | "EDIT_ENTITY"): Promise<void> {
    const entities = await listCorporateLegalEntities();
    if (entities.length === 0) throw new Error("Каталог юридических лиц пуст.");
    session.legalEntities = entities;
    session.step = step;
    const labels = legalEntityLabels(entities).map(({ label }) => label);
    await this.client.sendMessage(
      chatId,
      "Выберите юридическое лицо. ИНН будет подставлен автоматически.",
      [...rowsOf(labels, 2), [{ text: CANCEL_BUTTON }]],
    );
  }

  private selectLegalEntity(session: AdminSession, value: string): CorporateLegalEntity | null {
    const pairs = legalEntityLabels(session.legalEntities ?? []);
    return pairs.find(({ label }) => label === value)?.entity ?? null;
  }

  private async handleAddEntity(chatId: number, session: AdminSession, value: string): Promise<void> {
    const entity = this.selectLegalEntity(session, value);
    if (!entity) {
      await this.client.sendMessage(chatId, "Выберите юридическое лицо одной из кнопок.");
      return;
    }
    session.draft!.legalEntity = entity.name;
    session.draft!.inn = entity.inn;
    await this.askAccount(chatId, session, "ADD_ACCOUNT");
  }

  private async askAccount(chatId: number, session: AdminSession, step: "ADD_ACCOUNT" | "EDIT_ACCOUNT"): Promise<void> {
    const draft = session.draft;
    if (!draft?.operator || !draft.legalEntity) throw new Error("Сначала выберите оператора и юридическое лицо.");
    const accounts = await listKnownAccountNumbers(draft.operator, draft.legalEntity);
    session.accountChoices = accounts;
    session.step = step;
    const buttons = accounts.length > 0
      ? [...rowsOf(accounts, 2), [{ text: MANUAL_VALUE_BUTTON }], [{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]
      : [[{ text: MANUAL_VALUE_BUTTON }], [{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]];
    await this.client.sendMessage(
      chatId,
      accounts.length > 0
        ? "Выберите известный лицевой счёт для этой пары оператор + ЮЛ либо введите другой."
        : "Для этой пары оператор + ЮЛ известного лицевого счёта нет. Выберите ручной ввод или оставьте поле пустым.",
      buttons,
    );
  }

  private async handleAddAccount(chatId: number, session: AdminSession, value: string): Promise<void> {
    if (value === MANUAL_VALUE_BUTTON) {
      session.step = "ADD_ACCOUNT_MANUAL";
      await this.client.sendMessage(chatId, "Введите лицевой счёт обычным текстом или выберите «Нет».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === NO_VALUE_BUTTON) {
      session.draft!.accountNumber = null;
    } else if ((session.accountChoices ?? []).includes(value)) {
      session.draft!.accountNumber = value;
    } else {
      await this.client.sendMessage(chatId, "Выберите лицевой счёт кнопкой или нажмите «Ввести другой».");
      return;
    }
    session.step = "ADD_SUBSCRIBER";
    await this.client.sendMessage(chatId, "Укажите ФИО сотрудника / владельца номера или выберите «Нет».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
  }

  private async showAddConfirmation(chatId: number, session: AdminSession): Promise<void> {
    const draft = completeDraft(session.draft);
    if (!draft) throw new Error("Карточка заполнена не полностью.");
    session.step = "ADD_CONFIRM";
    await this.client.sendMessage(
      chatId,
      `Проверьте новую карточку:\n\n${formatRecord(draft)}`,
      [[{ text: SAVE_BUTTON }], [{ text: CANCEL_BUTTON }]],
    );
  }

  private async handleAddConfirm(chatId: number, telegramUserId: string, session: AdminSession, value: string): Promise<void> {
    if (value !== SAVE_BUTTON) {
      await this.client.sendMessage(chatId, "Для сохранения нажмите кнопку «Сохранить».");
      return;
    }
    const draft = completeDraft(session.draft);
    if (!draft) throw new Error("Карточка заполнена не полностью.");
    const saved = await addCorporateDirectoryRecord(telegramUserId, draft);
    await this.backToPhoneMenu(chatId, session, `✅ Номер добавлен в справочник.\n\n${formatRecord(saved)}`);
  }

  private async showEditMenu(chatId: number, session: AdminSession): Promise<void> {
    const draft = completeDraft(session.draft);
    if (!draft || !session.original) throw new Error("Не удалось открыть карточку для редактирования.");
    session.step = "EDIT_MENU";
    await this.client.sendMessage(
      chatId,
      `✏️ Редактирование\n\nТекущий черновик:\n${formatRecord(draft)}\n\nПри переносе номера смените оператор и ЮЛ. ИНН обновится автоматически, а лицевой счёт будет предложен для новой пары.`,
      editMenuKeyboard,
    );
  }

  private async handleEditMenu(chatId: number, session: AdminSession, value: string): Promise<void> {
    if (value === EDIT_PHONE_BUTTON) {
      session.step = "EDIT_PHONE";
      await this.client.sendMessage(chatId, "Введите исправленный номер телефона.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === EDIT_OPERATOR_BUTTON) {
      session.step = "EDIT_OPERATOR";
      await this.client.sendMessage(chatId, "Выберите нового оператора.", operatorKeyboard);
      return;
    }
    if (value === EDIT_ENTITY_BUTTON) {
      await this.askLegalEntity(chatId, session, "EDIT_ENTITY");
      return;
    }
    if (value === EDIT_ACCOUNT_BUTTON) {
      await this.askAccount(chatId, session, "EDIT_ACCOUNT");
      return;
    }
    if (value === EDIT_SUBSCRIBER_BUTTON) {
      session.step = "EDIT_SUBSCRIBER";
      await this.client.sendMessage(chatId, "Введите ФИО или выберите «Нет / очистить».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === EDIT_RESTAURANT_BUTTON) {
      session.step = "EDIT_RESTAURANT";
      await this.client.sendMessage(chatId, "Введите ресторан / подразделение или выберите «Нет / очистить».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === EDIT_LINE_TYPE_BUTTON) {
      session.step = "EDIT_LINE_TYPE";
      await this.client.sendMessage(chatId, "Введите назначение номера или выберите «Нет / очистить».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === EDIT_REVIEW_BUTTON) {
      const draft = completeDraft(session.draft);
      if (!draft || !session.original) throw new Error("Карточка заполнена не полностью.");
      session.step = "EDIT_CONFIRM";
      await this.client.sendMessage(
        chatId,
        `Проверьте изменение.\n\nБЫЛО:\n${formatRecord(session.original)}\n\nСТАНЕТ:\n${formatRecord(draft)}`,
        [[{ text: SAVE_BUTTON }], [{ text: CANCEL_BUTTON }]],
      );
      return;
    }
    await this.client.sendMessage(chatId, "Выберите поле для изменения кнопкой.", editMenuKeyboard);
  }

  private async handleEditPhone(chatId: number, session: AdminSession, value: string): Promise<void> {
    const phone = normalizeRussianPhone(value);
    if (!phone) {
      await this.client.sendMessage(chatId, "Не удалось распознать номер.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    const duplicates = await findCorporateDirectoryRecords(phone);
    if (duplicates.some((record) => record.id !== session.original?.id)) {
      await this.client.sendMessage(chatId, "Этот номер уже используется другой карточкой. Введите другой номер или отмените действие.", [[{ text: CANCEL_BUTTON }]]);
      return;
    }
    session.draft!.phone = phone;
    await this.showEditMenu(chatId, session);
  }

  private async handleEditOperator(chatId: number, session: AdminSession, value: string): Promise<void> {
    const operator = this.operatorFromButton(value);
    if (!operator) {
      await this.client.sendMessage(chatId, "Выберите оператора кнопкой.", operatorKeyboard);
      return;
    }
    session.draft!.operator = operator;
    session.draft!.accountNumber = null;
    await this.askAccount(chatId, session, "EDIT_ACCOUNT");
  }

  private async handleEditEntity(chatId: number, session: AdminSession, value: string): Promise<void> {
    const entity = this.selectLegalEntity(session, value);
    if (!entity) {
      await this.client.sendMessage(chatId, "Выберите юридическое лицо одной из кнопок.");
      return;
    }
    session.draft!.legalEntity = entity.name;
    session.draft!.inn = entity.inn;
    session.draft!.accountNumber = null;
    await this.askAccount(chatId, session, "EDIT_ACCOUNT");
  }

  private async handleEditAccount(chatId: number, session: AdminSession, value: string): Promise<void> {
    if (value === MANUAL_VALUE_BUTTON) {
      session.step = "EDIT_ACCOUNT_MANUAL";
      await this.client.sendMessage(chatId, "Введите новый лицевой счёт или выберите «Нет / очистить».", [[{ text: NO_VALUE_BUTTON }], [{ text: CANCEL_BUTTON }]]);
      return;
    }
    if (value === NO_VALUE_BUTTON) {
      session.draft!.accountNumber = null;
    } else if ((session.accountChoices ?? []).includes(value)) {
      session.draft!.accountNumber = value;
    } else {
      await this.client.sendMessage(chatId, "Выберите лицевой счёт кнопкой или нажмите «Ввести другой».");
      return;
    }
    await this.showEditMenu(chatId, session);
  }

  private async handleEditConfirm(chatId: number, telegramUserId: string, session: AdminSession, value: string): Promise<void> {
    if (value !== SAVE_BUTTON) {
      await this.client.sendMessage(chatId, "Для сохранения нажмите кнопку «Сохранить».");
      return;
    }
    const draft = completeDraft(session.draft);
    if (!draft || !session.original) throw new Error("Карточка заполнена не полностью.");
    const saved = await updateCorporateDirectoryRecord(telegramUserId, session.original.id, draft);
    await this.backToPhoneMenu(chatId, session, `✅ Данные обновлены. Предыдущее состояние сохранено в аудите.\n\n${formatRecord(saved)}`);
  }

  private async handleDeleteConfirm(chatId: number, telegramUserId: string, session: AdminSession, value: string): Promise<void> {
    if (value !== CONFIRM_DELETE_BUTTON) {
      await this.client.sendMessage(chatId, "Для удаления используйте кнопку подтверждения либо отмените действие.");
      return;
    }
    if (!session.original) throw new Error("Запись для удаления не выбрана.");
    const deleted = await deleteCorporateDirectoryRecord(telegramUserId, session.original.id);
    await this.backToPhoneMenu(chatId, session, `✅ Номер ${formatPhone(deleted.phone)} удалён из рабочего справочника. Снимок удалённой карточки сохранён в аудите.`);
  }
}

export const CORPORATE_DIRECTORY_ADMIN_BUTTON = "📱 Управление номерами";
