import { Router, type IRouter, type Request, type Response } from "express";
import {
  addDirectoryPhone,
  deleteDirectoryPhone,
  listDirectoryAudit,
  listDirectoryPhones,
  listDirectoryRestaurants,
  updateDirectoryPhone,
  upsertDirectoryRestaurant,
} from "../services/directory-web-service";

const router: IRouter = Router();

const writeToken = (): string => (process.env.PHONEBOOK_WEB_WRITE_TOKEN ?? "").trim();

const requireEditor = (req: Request, res: Response): { actor: string } | null => {
  const configuredToken = writeToken();
  if (!configuredToken) {
    res.status(503).json({ error: "Редактирование веб-справочника пока не настроено." });
    return null;
  }
  const suppliedToken = String(req.header("x-phonebook-write-token") ?? "").trim();
  if (!suppliedToken || suppliedToken !== configuredToken) {
    res.status(401).json({ error: "Для изменения справочника требуется авторизация редактора." });
    return null;
  }
  const actor = String(req.header("x-phonebook-actor") ?? "").trim();
  if (actor.length < 2) {
    res.status(400).json({ error: "Укажите имя редактора." });
    return null;
  }
  return { actor: actor.slice(0, 160) };
};

const errorMessage = (error: unknown): string =>
  error instanceof Error ? error.message : "Не удалось выполнить операцию со справочником.";

router.get("/phonebook/phones", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listDirectoryPhones() });
  } catch (error) {
    req.log.error({ error }, "Failed to list phonebook phones");
    res.status(500).json({ error: errorMessage(error) });
  }
});

router.post("/phonebook/phones", async (req, res): Promise<void> => {
  const editor = requireEditor(req, res);
  if (!editor) return;
  try {
    const record = await addDirectoryPhone(editor.actor, req.body ?? {});
    res.status(201).json({ record });
  } catch (error) {
    req.log.warn({ error }, "Failed to add phonebook phone");
    res.status(400).json({ error: errorMessage(error) });
  }
});

router.put("/phonebook/phones/:id", async (req, res): Promise<void> => {
  const editor = requireEditor(req, res);
  if (!editor) return;
  try {
    res.json({ record: await updateDirectoryPhone(editor.actor, req.params.id, req.body ?? {}) });
  } catch (error) {
    req.log.warn({ error }, "Failed to update phonebook phone");
    res.status(400).json({ error: errorMessage(error) });
  }
});

router.delete("/phonebook/phones/:id", async (req, res): Promise<void> => {
  const editor = requireEditor(req, res);
  if (!editor) return;
  try {
    res.json({ record: await deleteDirectoryPhone(editor.actor, req.params.id) });
  } catch (error) {
    req.log.warn({ error }, "Failed to delete phonebook phone");
    res.status(400).json({ error: errorMessage(error) });
  }
});

router.get("/phonebook/restaurants", async (req, res): Promise<void> => {
  try {
    res.json({ records: await listDirectoryRestaurants() });
  } catch (error) {
    req.log.error({ error }, "Failed to list phonebook restaurants");
    res.status(500).json({ error: errorMessage(error) });
  }
});

router.put("/phonebook/restaurants/:id", async (req, res): Promise<void> => {
  const editor = requireEditor(req, res);
  if (!editor) return;
  try {
    const body = { ...(req.body ?? {}), id: req.params.id };
    res.json({ record: await upsertDirectoryRestaurant(editor.actor, body) });
  } catch (error) {
    req.log.warn({ error }, "Failed to update phonebook restaurant");
    res.status(400).json({ error: errorMessage(error) });
  }
});

router.get("/phonebook/audit", async (req, res): Promise<void> => {
  try {
    const limit = Number(req.query.limit ?? 100);
    res.json({ records: await listDirectoryAudit(limit) });
  } catch (error) {
    req.log.error({ error }, "Failed to list phonebook audit");
    res.status(500).json({ error: errorMessage(error) });
  }
});

export default router;
