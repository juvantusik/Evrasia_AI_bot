import assert from "node:assert/strict";
import test from "node:test";
import {
  findMegafonLegalEntityInText,
  hasUsefulRequestText,
  isLegalEntityLevelRequest,
  isSameLegalEntity,
  type MegafonDirectoryRecord,
} from "./megafon-group-routing";

const records: MegafonDirectoryRecord[] = [
  {
    phone: "79990000001",
    operator: "MEGAFON",
    legalEntity: "ООО «Евразия Мурино»",
    inn: "7800000001",
    accountNumber: "10001",
  },
  {
    phone: "79990000002",
    operator: "MEGAFON",
    legalEntity: "ООО «Евразия Мурино»",
    inn: "7800000001",
    accountNumber: "10001",
  },
  {
    phone: "79990000003",
    operator: "MEGAFON",
    legalEntity: "ООО «Евразия Парнас»",
    inn: "7800000002",
    accountNumber: "10002",
  },
  {
    phone: "79990000004",
    operator: "T2",
    legalEntity: "ООО «Евразия Мурино»",
    inn: "7800000001",
    accountNumber: null,
  },
];

test("finds MegaFon legal entity in natural Russian text", () => {
  const result = findMegafonLegalEntityInText(
    "Прошу прислать заявление на добавление номера к ООО «Евразия Мурино»",
    records,
  );
  assert.equal(result.status, "FOUND");
  if (result.status === "FOUND") {
    assert.equal(result.legalEntity, "ООО «Евразия Мурино»");
    assert.equal(result.record.accountNumber, "10001");
  }
});

test("supports short Evrasia alias such as Murino", () => {
  const result = findMegafonLegalEntityInText("Нужно по Мурино добавить еще один номер", records);
  assert.equal(result.status, "FOUND");
  if (result.status === "FOUND") assert.equal(result.legalEntity, "ООО «Евразия Мурино»");
});

test("does not use T2 records for MegaFon group routing", () => {
  const result = findMegafonLegalEntityInText("ООО Евразия Мурино", records);
  assert.equal(result.status, "FOUND");
  if (result.status === "FOUND") assert.equal(result.record.operator, "MEGAFON");
});

test("classifies new-number paperwork as entity-level request", () => {
  assert.equal(
    isLegalEntityLevelRequest("Прошу прислать заявление на добавление еще одного номера телефона"),
    true,
  );
  assert.equal(isLegalEntityLevelRequest("Не работает интернет на номере"), false);
});

test("detects when a message contains only the legal entity", () => {
  assert.equal(hasUsefulRequestText("ООО «Евразия Мурино»", "ООО «Евразия Мурино»"), false);
  assert.equal(
    hasUsefulRequestText("ООО «Евразия Мурино», пришлите заявление", "ООО «Евразия Мурино»"),
    true,
  );
});

test("compares legal entities without quotes and legal form", () => {
  assert.equal(isSameLegalEntity("ООО «Евразия Мурино»", "Евразия Мурино"), true);
  assert.equal(isSameLegalEntity("ООО «Евразия Мурино»", "ООО «Евразия Парнас»"), false);
});
