import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { pool } from "@workspace/db";

type ReviewStatus = "OK" | "NEEDS_REVIEW";

type CanonicalLegalEntity = {
  sourceName: string;
  name: string;
  inn: string | null;
  kpp: string | null;
  ogrn: string | null;
  generalDirector: string | null;
  status: ReviewStatus;
};

type ExistingLegalEntity = {
  id: string;
  name: string;
  inn: string | null;
  kpp: string | null;
  ogrn: string | null;
  generalDirector: string | null;
  verificationStatus: string;
  active: boolean;
};

type Action = "MATCH" | "CREATE" | "UPDATE" | "SKIP" | "CONFLICT";

type PlanItem = {
  action: Action;
  canonical: CanonicalLegalEntity;
  existing?: ExistingLegalEntity;
  reason: string;
  changes?: string[];
};

const SOURCE_LABEL = "Реквизиты новые.zip + Списки дир-ов от 06.04.26.xlsx";
const DEFAULT_REVIEW_PATH = "docs/LEGAL_ENTITY_IMPORT_REVIEW_2026-09-13.md";
const APPLY_ENV = "LEGAL_ENTITY_IMPORT_APPLY";
const CONFIRM_ENV = "LEGAL_ENTITY_IMPORT_CONFIRM";
const CONFIRM_VALUE = "KAN-77";

const args = new Set(process.argv.slice(2));
const apply = args.has("--apply");
const sourceArgIndex = process.argv.indexOf("--source");
const sourcePath = resolve(
  process.cwd(),
  sourceArgIndex >= 0 && process.argv[sourceArgIndex + 1]
    ? process.argv[sourceArgIndex + 1]!
    : DEFAULT_REVIEW_PATH,
);

const normalizeCell = (value: string): string | null => {
  const cleaned = value.trim().replace(/\*+$/u, "").trim();
  if (!cleaned || cleaned === "—" || cleaned === "-") return null;
  return cleaned;
};

const canonicalStoredName = (sourceName: string): string => {
  // В review-файле суффикс нужен только для визуального различения двух одноимённых ЮЛ.
  // В master обе записи сохраняют своё реальное юридическое название и различаются по ИНН.
  return sourceName.replace(/\s+\(М\.222\)$/u, "").trim();
};

const parseCanonicalReview = (markdown: string): CanonicalLegalEntity[] => {
  const start = markdown.indexOf("## Canonical identity set");
  const end = markdown.indexOf("## Требуют review", start);
  if (start < 0 || end < 0) {
    throw new Error("Canonical identity table not found in review document.");
  }

  const rows = markdown
    .slice(start, end)
    .split(/\r?\n/u)
    .filter((line) => line.startsWith("|") && !line.includes("---"));

  const result: CanonicalLegalEntity[] = [];
  for (const row of rows) {
    const cells = row.split("|").slice(1, -1).map((cell) => cell.trim());
    if (cells.length !== 6 || cells[0] === "ЮЛ") continue;
    const status = cells[5] as ReviewStatus;
    if (status !== "OK" && status !== "NEEDS_REVIEW") continue;
    const sourceName = cells[0]!;
    result.push({
      sourceName,
      name: canonicalStoredName(sourceName),
      inn: normalizeCell(cells[1]!),
      kpp: normalizeCell(cells[2]!),
      ogrn: normalizeCell(cells[3]!),
      generalDirector: normalizeCell(cells[4]!),
      status,
    });
  }

  if (result.length !== 62) {
    throw new Error(`Expected 62 canonical legal entities, parsed ${result.length}.`);
  }
  return result;
};

const assertSchema = async (): Promise<void> => {
  const result = await pool.query<{ present: string }>(`
    SELECT count(*)::text AS present
    FROM information_schema.columns
    WHERE table_schema = 'public'
      AND table_name = 'corporate_legal_entities'
      AND column_name IN ('id','name','inn','kpp','ogrn','general_director','source','verification_status','active','updated_at')
  `);
  if (Number(result.rows[0]?.present ?? 0) !== 10) {
    throw new Error("Required corporate_legal_entities v1.8 schema is not present. Migration 0022 must be applied first.");
  }
};

const loadExisting = async (): Promise<ExistingLegalEntity[]> => {
  const result = await pool.query<ExistingLegalEntity>(`
    SELECT id,
           name,
           inn,
           kpp,
           ogrn,
           general_director AS "generalDirector",
           verification_status AS "verificationStatus",
           active
    FROM corporate_legal_entities
    ORDER BY inn NULLS LAST, name, id
  `);
  return result.rows;
};

const normalized = (value: string | null): string => (value ?? "").trim();

const diffFields = (
  existing: ExistingLegalEntity,
  canonical: CanonicalLegalEntity,
): string[] => {
  const changes: string[] = [];
  if (normalized(existing.name) !== normalized(canonical.name)) changes.push("name");
  if (normalized(existing.kpp) !== normalized(canonical.kpp)) changes.push("kpp");
  if (normalized(existing.ogrn) !== normalized(canonical.ogrn)) changes.push("ogrn");
  if (normalized(existing.generalDirector) !== normalized(canonical.generalDirector)) changes.push("general_director");
  if (existing.verificationStatus !== "VERIFIED") changes.push("verification_status");
  return changes;
};

const buildPlan = (
  canonicalRows: CanonicalLegalEntity[],
  existingRows: ExistingLegalEntity[],
): PlanItem[] => {
  const byInn = new Map<string, ExistingLegalEntity[]>();
  for (const row of existingRows) {
    if (!row.inn) continue;
    const bucket = byInn.get(row.inn) ?? [];
    bucket.push(row);
    byInn.set(row.inn, bucket);
  }

  return canonicalRows.map((canonical): PlanItem => {
    if (!canonical.inn) {
      return {
        action: "SKIP",
        canonical,
        reason: "MISSING_INN_REQUIRES_MANUAL_REVIEW",
      };
    }

    const matches = byInn.get(canonical.inn) ?? [];
    if (matches.length > 1) {
      return {
        action: "CONFLICT",
        canonical,
        reason: `DUPLICATE_EXISTING_INN:${matches.length}`,
      };
    }

    const existing = matches[0];
    if (existing && !existing.active) {
      return {
        action: "CONFLICT",
        canonical,
        existing,
        reason: "INACTIVE_EXISTING_INN_MATCH",
      };
    }

    if (canonical.status === "NEEDS_REVIEW") {
      return {
        action: "SKIP",
        canonical,
        existing,
        reason: "CANONICAL_STATUS_NEEDS_REVIEW",
      };
    }

    if (!existing) {
      return {
        action: "CREATE",
        canonical,
        reason: "VERIFIED_INN_NOT_FOUND",
      };
    }

    const changes = diffFields(existing, canonical);
    if (changes.length === 0) {
      return {
        action: "MATCH",
        canonical,
        existing,
        reason: "ALREADY_CURRENT",
      };
    }

    return {
      action: "UPDATE",
      canonical,
      existing,
      reason: "VERIFIED_FIELDS_DIFFER",
      changes,
    };
  });
};

const printPlan = (plan: PlanItem[]): void => {
  const counts = new Map<Action, number>([
    ["MATCH", 0],
    ["CREATE", 0],
    ["UPDATE", 0],
    ["SKIP", 0],
    ["CONFLICT", 0],
  ]);

  console.log("=== 1. MODE / GUARDS ===");
  console.log(`MODE=${apply ? "WRITE" : "DRY_RUN"}`);
  console.log(`DATABASE_WRITE=${apply ? "YES" : "NO"}`);
  console.log(`SOURCE=${sourcePath}`);
  console.log(`CANONICAL_ROWS=${plan.length}`);
  console.log("MATCH_KEY=INN");
  console.log("NAME_ONLY_MERGE=NO");
  console.log("REVIEW_ROWS_AUTO_WRITE=NO");
  console.log("SECRET_VALUES_PRINTED=NO");

  console.log("\n=== 2. PLAN ===");
  for (const item of plan) {
    counts.set(item.action, (counts.get(item.action) ?? 0) + 1);
    const inn = item.canonical.inn ?? "NULL";
    const changes = item.changes?.length ? ` CHANGES=${item.changes.join(",")}` : "";
    console.log(`${item.action}: INN=${inn} NAME=${item.canonical.name} REASON=${item.reason}${changes}`);
  }

  console.log("\n=== 3. COUNTS ===");
  for (const action of ["MATCH", "CREATE", "UPDATE", "SKIP", "CONFLICT"] as const) {
    console.log(`${action}_COUNT=${counts.get(action) ?? 0}`);
  }
};

const applyPlan = async (plan: PlanItem[]): Promise<void> => {
  if (process.env[APPLY_ENV] !== "YES" || process.env[CONFIRM_ENV] !== CONFIRM_VALUE) {
    throw new Error(
      `Write mode requires ${APPLY_ENV}=YES and ${CONFIRM_ENV}=${CONFIRM_VALUE}. No write was performed.`,
    );
  }

  if (plan.some((item) => item.action === "CONFLICT")) {
    throw new Error("Write mode blocked because the plan contains CONFLICT rows.");
  }

  const client = await pool.connect();
  try {
    await client.query("BEGIN");
    for (const item of plan) {
      if (item.action === "CREATE") {
        const c = item.canonical;
        await client.query(
          `INSERT INTO corporate_legal_entities
             (id, name, inn, kpp, ogrn, general_director, source, verification_status, active)
           VALUES ($1,$2,$3,$4,$5,$6,$7,'VERIFIED',true)`,
          [randomUUID(), c.name, c.inn, c.kpp, c.ogrn, c.generalDirector, SOURCE_LABEL],
        );
      } else if (item.action === "UPDATE" && item.existing) {
        const c = item.canonical;
        await client.query(
          `UPDATE corporate_legal_entities
           SET name = $2,
               kpp = $3,
               ogrn = $4,
               general_director = $5,
               source = $6,
               verification_status = 'VERIFIED',
               updated_at = now()
           WHERE id = $1 AND active = true`,
          [item.existing.id, c.name, c.kpp, c.ogrn, c.generalDirector, SOURCE_LABEL],
        );
      }
    }
    await client.query("COMMIT");
  } catch (error) {
    await client.query("ROLLBACK");
    throw error;
  } finally {
    client.release();
  }
};

const main = async (): Promise<void> => {
  try {
    const markdown = readFileSync(sourcePath, "utf8");
    const canonicalRows = parseCanonicalReview(markdown);

    await assertSchema();
    const existingRows = await loadExisting();
    const plan = buildPlan(canonicalRows, existingRows);
    printPlan(plan);

    console.log("\n=== 4. ACTION ===");
    if (!apply) {
      console.log("DRY_RUN_COMPLETE=YES");
      console.log("DATABASE_WRITE=NO");
    } else {
      await applyPlan(plan);
      console.log("APPLY_COMPLETE=YES");
      console.log("DATABASE_WRITE=YES");
    }

    console.log("BANK_ACCOUNT_IMPORT=DEFERRED_UNTIL_STRUCTURED_CANONICAL_BANK_DATA");
    console.log("OPERATOR_ACCOUNT_IMPORT=EXISTING_PHONEBOOK_SYNC_ONLY_AT_THIS_STAGE");
    console.log("FINAL_STATUS=PASS");
    console.log("FINAL_RC=0");
  } catch (error) {
    console.error(`FAIL: ${error instanceof Error ? error.message : String(error)}`);
    console.error("FINAL_STATUS=FAIL");
    console.error("FINAL_RC=1");
    process.exitCode = 1;
  } finally {
    await pool.end();
  }
};

await main();
