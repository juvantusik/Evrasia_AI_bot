import type { TelegramKeyboard, TelegramMessage } from "./telegram-bot";

export const CORPORATE_DIRECTORY_ADMIN_BUTTON = "🌐 Справочник номеров";

export type CorporateDirectoryAdminClient = {
  sendMessage(chatId: number, text: string, keyboard?: TelegramKeyboard): Promise<TelegramMessage>;
};

/**
 * v1.6.9: corporate phone directory mutations are web-only.
 *
 * The class is intentionally kept as a small compatibility adapter while the
 * Telegram shell still references the old admin entry point. It never opens a
 * mutation session and never writes to PostgreSQL. This makes it impossible to
 * add, edit, transfer or delete phone records from Telegram.
 */
export class CorporateDirectoryTelegramAdmin {
  constructor(private readonly client: CorporateDirectoryAdminClient) {}

  isActive(_chatId: number): boolean {
    return false;
  }

  cancel(_chatId: number): void {
    // No Telegram directory session exists in v1.6.9.
  }

  async start(
    chatId: number,
    _telegramUserId: string,
    adminReturnKeyboard: TelegramKeyboard,
  ): Promise<void> {
    await this.client.sendMessage(
      chatId,
      "🌐 Редактирование корпоративных номеров перенесено в веб-справочник.\n\nВ Telegram добавление, изменение, перенос и удаление номеров отключены.",
      adminReturnKeyboard,
    );
  }

  async handleMessage(
    _chatId: number,
    _telegramUserId: string,
    _text: string,
  ): Promise<boolean> {
    return false;
  }
}
