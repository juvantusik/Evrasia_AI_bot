import { Router, type IRouter } from "express";
import healthRouter from "./health";
import insideSamzaberuRouter from "./inside-samzaberu";
import samzaberuRouter from "./samzaberu";

const router: IRouter = Router();

router.use(healthRouter);
router.use(insideSamzaberuRouter);

if (process.env.SAMZABERU_LEGACY_WEB_ENABLED === "true") {
  router.use(samzaberuRouter);
}

export default router;
