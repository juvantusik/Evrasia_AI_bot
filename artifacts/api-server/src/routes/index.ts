import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";
import directoryPreviewRouter from "./directory-preview";

const router: IRouter = Router();

router.use(healthRouter);
router.use(samzaberuRouter);
router.use(directoryPreviewRouter);

export default router;
