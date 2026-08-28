export type MegafonDirectoryRecord = {
  phone: string;
  operator: string;
  legalEntity: string;
  inn?: string | null;
  accountNumber?: string | null;
};

export type MegafonLegalEntityMatch =
  | { status: "NONE" }
  | { status: "AMBIGUOUS"; legalEntities: string[] }
  | { status: "FOUND"; legalEntity: string; record: MegafonDirectoryRecord };

const normalizeText = (value: string): string =>
  value
    .toLocaleLowerCase("ru-RU")
    .replace(/ё/g, "е")
    .replace(/[«»“”"'().,;:!?/\\\-_–—]/g, " ")
    .replace(/\s+/g, " ")
    .trim();

const LEGAL_FORMS = new Set(["ооо", "зао", "пао", "оао", "ао"]);

export const normalizeLegalEntityName = (value: string): string =>
  normalizeText(value)
    .split(" ")
    .filter(Boolean)
    .filter((token) => !LEGAL_FORMS.has(token))
    .join(" ");

export const isSameLegalEntity = (left: string, right: string): boolean => {
  const a = normalizeLegalEntityName(left);
  const b = normalizeLegalEntityName(right);
  return Boolean(a && b && a === b);
};

const legalEntityAliases = (legalEntity: string): string[] => {
  const canonical = normalizeLegalEntityName(legalEntity);
  if (!canonical) return [];
  const aliases = new Set<string>([canonical]);
  if (canonical.startsWith("евразия ")) {
    const tail = canonical.slice("евразия ".length).trim();
    if (tail.length >= 5) aliases.add(tail);
  }
  return [...aliases];
};

const chooseRepresentative = (records: MegafonDirectoryRecord[]): MegafonDirectoryRecord =>
  [...records].sort((a, b) => {
    const score = (record: MegafonDirectoryRecord): number =>
      (record.accountNumber?.trim() ? 2 : 0) + (record.inn?.trim() ? 1 : 0);
    return score(b) - score(a);
  })[0]!;

export const findMegafonLegalEntityInText = (
  text: string,
  records: MegafonDirectoryRecord[],
): MegafonLegalEntityMatch => {
  const normalizedText = normalizeText(text);
  if (!normalizedText) return { status: "NONE" };

  const byEntity = new Map<string, MegafonDirectoryRecord[]>();
  for (const record of records) {
    if (record.operator !== "MEGAFON") continue;
    const key = normalizeLegalEntityName(record.legalEntity);
    if (!key) continue;
    const bucket = byEntity.get(key) ?? [];
    bucket.push(record);
    byEntity.set(key, bucket);
  }

  const hits: Array<{ key: string; score: number; records: MegafonDirectoryRecord[] }> = [];
  for (const [key, entityRecords] of byEntity) {
    let bestScore = 0;
    for (const alias of legalEntityAliases(entityRecords[0]!.legalEntity)) {
      if (alias.length < 5) continue;
      if (normalizedText.includes(alias)) {
        const exactBonus = alias === key ? 1000 : 0;
        bestScore = Math.max(bestScore, exactBonus + alias.length);
      }
    }
    if (bestScore > 0) hits.push({ key, score: bestScore, records: entityRecords });
  }

  if (!hits.length) return { status: "NONE" };
  hits.sort((a, b) => b.score - a.score);
  const topScore = hits[0]!.score;
  const top = hits.filter((hit) => hit.score === topScore);
  if (top.length > 1) {
    return {
      status: "AMBIGUOUS",
      legalEntities: top.map((hit) => chooseRepresentative(hit.records).legalEntity),
    };
  }

  const winner = top[0]!;
  const record = chooseRepresentative(winner.records);
  return { status: "FOUND", legalEntity: record.legalEntity, record };
};

export const isLegalEntityLevelRequest = (text: string): boolean => {
  const value = normalizeText(text);
  const mentionsNumber = /номер/.test(value);
  const newOrAdditionalNumber =
    mentionsNumber &&
    /(?:добав|добавлен|дополн|нов|еще|ещё|подключ|оформ)[а-я]*/.test(value);
  const entityDocuments =
    /(?:договор|реквизит|лицев)[а-я]*/.test(value) ||
    /список\s+номер/.test(value) ||
    /акт\s+сверк/.test(value);
  return newOrAdditionalNumber || entityDocuments;
};

const REQUEST_STOP_WORDS = new Set([
  "ооо",
  "зао",
  "пао",
  "оао",
  "ао",
  "добрый",
  "день",
  "здравствуйте",
  "привет",
  "пожалуйста",
  "спасибо",
  "заранее",
  "благодарю",
]);

export const hasUsefulRequestText = (text: string, legalEntity: string): boolean => {
  const entityTokens = new Set(normalizeLegalEntityName(legalEntity).split(" ").filter(Boolean));
  const remaining = normalizeText(text)
    .split(" ")
    .filter(Boolean)
    .filter((token) => !REQUEST_STOP_WORDS.has(token))
    .filter((token) => !entityTokens.has(token));
  return remaining.join(" ").length >= 5;
};
