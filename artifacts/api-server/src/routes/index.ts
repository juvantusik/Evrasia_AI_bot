import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";
import phonebookRouter from "./phonebook-web";

const router: IRouter = Router();

router.use(healthRouter);
router.use(samzaberuRouter);
router.use(phonebookRouter);

export default router;
