#!/usr/bin/env node

import { randomUUID } from "node:crypto";
import { readFileSync } from "node:fs";
import { resolve } from "node:path";
import { spawnSync } from "node:child_process";

const SOURCE_LABEL = "Реквизиты новые.zip + Списки дир-ов от 06.04.26.xlsx";
const DEFAULT_REVIEW_PATH = "docs/LEGAL_ENTITY_IMPORT_REVIEW_2026-09-13.md";
const APPLY_ENV = "LEGAL_ENTITY_IMPORT_APPLY";
const CONFIRM_ENV = "LEGAL_ENTITY_IMPORT_CONFIRM";
const CONFIRM_VALUE = "KAN-77";

const argv = process.argv.slice(2);
const apply = argv.includes("--apply");
const sourceIndex = argv.indexOf("--source");
const sourcePath = resolve(
  process.cwd(),
  sourceIndex >= 0 && argv[sourceIndex + 1] ? argv[sourceIndex + 1] : DEFAULT_REVIEW_PATH,
);

const databaseUrl = process.env.DATABASE_URL ?? "";
if (!databaseUrl) {
  console.error("FAIL: DATABASE_URL is not set");
  console.error("FINAL_STATUS=FAIL");
  console.error("FINAL_RC=1");
  process.exit(1);
}

const db = new URL(databaseUrl);
const pgEnv = {
  ...process.env,
  PGHOST: db.hostname,
  PGPORT: db.port || "5432",
  PGUSER: decodeURIComponent(db.username),
  PGPASSWORD: decodeURIComponent(db.password),
  PGDATABASE: db.pathname.replace(/^\//u, ""),
};
if (db.searchParams.get("sslmode")) pgEnv.PGSSLMODE = db.searchParams.get("sslmode");

const runPsql = (sql, { tuples = true } = {}) => {
  const args = ["-X", "-v", "ON_ERROR_STOP=1"];
  if (tuples) args.push("-At", "-F", "\t");
  args.push("-c", sql);
  const result = spawnSync("psql", args, {
    env: pgEnv,
    encoding: "utf8",
    stdio: ["ignore", "pipe", "pipe"],
  });
  if (result.error) throw result.error;
  if (result.status !== 0) {
    const message = String(result.stderr || result.stdout || "psql failed").trim();
    throw new Error(message);
  }
  return String(result.stdout ?? "").trim();
};

const normalizeCell = (value) => {
  const cleaned = String(value ?? "").trim().replace(/\*+$/u, "").trim();
  if (!cleaned || cleaned === "—" || cleaned === "-") return null;
  return cleaned;
};

const storedName = (sourceName) =>
  sourceName.replace(/\s+\(М\.222\)$/u, "").trim();

const parseCanonicalReview = (markdown) => {
  const start = markdown.indexOf("## Canonical identity set");
  const end = markdown.indexOf("## Требуют review", start);
  if (start < 0 || end < 0) throw new Error("Canonical identity table not found in review document");

  const rows = markdown
    .slice(start, end)
    .split(/\r?\n/u)
    .filter((line) => line.startsWith("|") && !line.includes("---"));

  const result = [];
  for (const row of rows) {
    const cells = row.split("|").slice(1, -1).map((cell) => cell.trim());
    if (cells.length !== 6 || cells[0] === "ЮЛ") continue;
    if (cells[5] !== "OK" && cells[5] !== "NEEDS_REVIEW") continue;
    result.push({
      sourceName: cells[0],
      name: storedName(cells[0]),
      inn: normalizeCell(cells[1]),
      kpp: normalizeCell(cells[2]),
      ogrn: normalizeCell(cells[3]),
      generalDirector: normalizeCell(cells[4]),
      status: cells[5],
    });
  }
  if (result.length !== 62) throw new Error(`Expected 62 canonical rows, parsed ${result.length}`);
  return result;
};

const parseExisting = (text) => {
  if (!text) return [];
  return text.split(/\r?\n/u).filter(Boolean).map((line) => {
    const [id, name, inn, kpp, ogrn, director, verificationStatus, active] = line.split("\t");
    return {
      id,
      name,
      inn: inn || null,
      kpp: kpp || null,
      ogrn: ogrn || null,
      generalDirector: director || null,
      verificationStatus: verificationStatus || "UNVERIFIED",
      active: active === "t",
    };
  });
};

const normalized = (value) => String(value ?? "").trim();
const normalizedNameKey = (value) => normalized(value).toLocaleLowerCase("ru-RU").replace(/\s+/gu, " ");

const diffFields = (existing, canonical) => {
  const changes = [];
  if (normalized(existing.name) !== normalized(canonical.name)) changes.push("name");
  if (normalized(existing.kpp) !== normalized(canonical.kpp)) changes.push("kpp");
  if (normalized(existing.ogrn) !== normalized(canonical.ogrn)) changes.push("ogrn");
  if (normalized(existing.generalDirector) !== normalized(canonical.generalDirector)) changes.push("general_director");
  if (existing.verificationStatus !== "VERIFIED") changes.push("verification_status");
  return changes;
};

const buildPlan = (canonicalRows, existingRows) => {
  const byInn = new Map();
  const activeByName = new Map();

  for (const row of existingRows) {
    if (row.inn) {
      const bucket = byInn.get(row.inn) ?? [];
      bucket.push(row);
      byInn.set(row.inn, bucket);
    }

    if (row.active) {
      const key = normalizedNameKey(row.name);
      const bucket = activeByName.get(key) ?? [];
      bucket.push(row);
      activeByName.set(key, bucket);
    }
  }

  return canonicalRows.map((canonical) => {
    if (!canonical.inn) return { action: "SKIP", canonical, reason: "MISSING_INN_REQUIRES_MANUAL_REVIEW" };

    const matches = byInn.get(canonical.inn) ?? [];
    if (matches.length > 1) return { action: "CONFLICT", canonical, reason: `DUPLICATE_EXISTING_INN:${matches.length}` };

    const existing = matches[0];
    if (existing && !existing.active) return { action: "CONFLICT", canonical, existing, reason: "INACTIVE_EXISTING_INN_MATCH" };
    if (canonical.status === "NEEDS_REVIEW") return { action: "SKIP", canonical, existing, reason: "CANONICAL_STATUS_NEEDS_REVIEW" };

    if (!existing) {
      const sameNameActive = activeByName.get(normalizedNameKey(canonical.name)) ?? [];
      if (sameNameActive.length > 0) {
        const nullInnMatches = sameNameActive.filter((row) => !row.inn);
        const differentInnMatches = sameNameActive.filter((row) => row.inn && row.inn !== canonical.inn);
        const detail = nullInnMatches.length > 0
          ? `ACTIVE_SAME_NAME_MISSING_INN:${nullInnMatches.length}`
          : `ACTIVE_SAME_NAME_DIFFERENT_INN:${differentInnMatches.length}`;
        return {
          action: "CONFLICT",
          canonical,
          existingCandidates: sameNameActive,
          reason: detail,
        };
      }

      return { action: "CREATE", canonical, reason: "VERIFIED_INN_NOT_FOUND" };
    }

    const changes = diffFields(existing, canonical);
    if (!changes.length) return { action: "MATCH", canonical, existing, reason: "ALREADY_CURRENT" };
    return { action: "UPDATE", canonical, existing, reason: "VERIFIED_FIELDS_DIFFER", changes };
  });
};

const sqlLiteral = (value) => value == null ? "NULL" : `'${String(value).replaceAll("'", "''")}'`;

const buildApplySql = (plan) => {
  const statements = ["BEGIN;"];
  for (const item of plan) {
    if (item.action === "CREATE") {
      const c = item.canonical;
      statements.push(`INSERT INTO corporate_legal_entities (id,name,inn,kpp,ogrn,general_director,source,verification_status,active) VALUES (${sqlLiteral(randomUUID())},${sqlLiteral(c.name)},${sqlLiteral(c.inn)},${sqlLiteral(c.kpp)},${sqlLiteral(c.ogrn)},${sqlLiteral(c.generalDirector)},${sqlLiteral(SOURCE_LABEL)},'VERIFIED',true);`);
    }
    if (item.action === "UPDATE") {
      const c = item.canonical;
      statements.push(`UPDATE corporate_legal_entities SET name=${sqlLiteral(c.name)},kpp=${sqlLiteral(c.kpp)},ogrn=${sqlLiteral(c.ogrn)},general_director=${sqlLiteral(c.generalDirector)},source=${sqlLiteral(SOURCE_LABEL)},verification_status='VERIFIED',updated_at=now() WHERE id=${sqlLiteral(item.existing.id)} AND active=true;`);
    }
  }
  statements.push("COMMIT;");
  return statements.join("\n");
};

const main = () => {
  console.log("=== 1. MODE / ENVIRONMENT ===");
  console.log(`MODE=${apply ? "WRITE" : "DRY_RUN"}`);
  console.log(`DATABASE_WRITE=${apply ? "YES" : "NO"}`);
  console.log(`SOURCE=${sourcePath}`);
  console.log("MATCH_KEY=INN");
  console.log("NAME_ONLY_MERGE=NO");
  console.log("SAME_NAME_WITHOUT_INN_MATCH=CONFLICT");
  console.log("NEEDS_REVIEW_AUTO_WRITE=NO");
  console.log("SECRET_VALUES_PRINTED=NO");

  const psqlVersion = spawnSync("psql", ["--version"], { encoding: "utf8" });
  if (psqlVersion.status !== 0) throw new Error("psql is not available");
  console.log("PASS: psql available");

  const schemaCount = Number(runPsql(`SELECT count(*) FROM information_schema.columns WHERE table_schema='public' AND table_name='corporate_legal_entities' AND column_name IN ('id','name','inn','kpp','ogrn','general_director','source','verification_status','active','updated_at');`));
  if (schemaCount !== 10) throw new Error("Required v1.8 master schema is absent; migration 0022 must be applied first");
  console.log("PASS: required master schema present");

  const duplicateInnCount = Number(runPsql(`SELECT count(*) FROM (SELECT inn FROM corporate_legal_entities WHERE inn IS NOT NULL AND trim(inn) <> '' GROUP BY inn HAVING count(*) > 1) d;`));
  console.log(`EXISTING_DUPLICATE_INN_GROUPS=${duplicateInnCount}`);
  if (apply && duplicateInnCount > 0) throw new Error("Write mode blocked because duplicate INN groups remain in master");

  const canonical = parseCanonicalReview(readFileSync(sourcePath, "utf8"));
  console.log(`CANONICAL_ROWS=${canonical.length}`);
  console.log(`CANONICAL_OK_ROWS=${canonical.filter((row) => row.status === "OK").length}`);
  console.log(`CANONICAL_REVIEW_ROWS=${canonical.filter((row) => row.status === "NEEDS_REVIEW").length}`);

  const existing = parseExisting(runPsql(`SELECT id,name,coalesce(inn,''),coalesce(kpp,''),coalesce(ogrn,''),coalesce(general_director,''),verification_status,active FROM corporate_legal_entities ORDER BY inn NULLS LAST,name,id;`));
  const plan = buildPlan(canonical, existing);

  console.log("\n=== 2. PLAN ===");
  const counts = { MATCH: 0, CREATE: 0, UPDATE: 0, SKIP: 0, CONFLICT: 0 };
  for (const item of plan) {
    counts[item.action] += 1;
    const changes = item.changes?.length ? ` CHANGES=${item.changes.join(",")}` : "";
    console.log(`${item.action}: INN=${item.canonical.inn ?? "NULL"} NAME=${item.canonical.name} REASON=${item.reason}${changes}`);
  }

  console.log("\n=== 3. COUNTS ===");
  for (const key of Object.keys(counts)) console.log(`${key}_COUNT=${counts[key]}`);

  console.log("\n=== 4. ACTION ===");
  if (!apply) {
    console.log("DRY_RUN_COMPLETE=YES");
    console.log("DATABASE_WRITE=NO");
  } else {
    if (process.env[APPLY_ENV] !== "YES" || process.env[CONFIRM_ENV] !== CONFIRM_VALUE) {
      throw new Error(`Write mode requires ${APPLY_ENV}=YES and ${CONFIRM_ENV}=${CONFIRM_VALUE}`);
    }
    if (counts.CONFLICT > 0) throw new Error("Write mode blocked because CONFLICT_COUNT > 0");
    runPsql(buildApplySql(plan), { tuples: false });
    console.log("APPLY_COMPLETE=YES");
    console.log("DATABASE_WRITE=YES");
  }

  console.log("BANK_ACCOUNT_IMPORT=DEFERRED_UNTIL_STRUCTURED_CANONICAL_BANK_DATA");
  console.log("OPERATOR_ACCOUNT_IMPORT=EXISTING_PHONEBOOK_SYNC_ONLY_AT_THIS_STAGE");
  console.log("FINAL_STATUS=PASS");
  console.log("FINAL_RC=0");
};

try {
  main();
} catch (error) {
  console.error(`FAIL: ${error instanceof Error ? error.message : String(error)}`);
  console.error("FINAL_STATUS=FAIL");
  console.error("FINAL_RC=1");
  process.exitCode = 1;
}
