import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";

const router: IRouter = Router();

router.use(healthRouter);
router.use(samzaberuRouter);

export default router;
