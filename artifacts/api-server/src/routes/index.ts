import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";
import directoryPreviewRouter from "./directory-preview";

const router: IRouter = Router();
const previewMode = ["1", "true", "enabled"].includes(
  (process.env.DIRECTORY_PREVIEW_MODE ?? "").trim().toLowerCase(),
);

router.use(healthRouter);

if (previewMode) {
  router.use("/samzaberu", (req, res, next): void => {
    if (req.method !== "GET" && req.method !== "HEAD") {
      res.status(403).json({ error: "Действия СамЗаберу отключены в preview v1.6.9." });
      return;
    }
    next();
  });
}

router.use(samzaberuRouter);
router.use(directoryPreviewRouter);

export default router;
