import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";
import directoryRouter from "./directory-web";

const router: IRouter = Router();

router.use(healthRouter);
router.use(samzaberuRouter);
router.use(directoryRouter);

export default router;
