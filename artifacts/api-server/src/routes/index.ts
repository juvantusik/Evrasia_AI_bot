import { Router, type IRouter } from "express";
import healthRouter from "./health";
import samzaberuRouter from "./samzaberu";
import phonebookRouter from "./phonebook-web";
import antiFraudRouter from "./anti-fraud-web";

const router: IRouter = Router();

router.use(healthRouter);
router.use(samzaberuRouter);
router.use(phonebookRouter);
// Добавлено 03.09.2026 ИТ Директор Евразии
router.use(antiFraudRouter);

export default router;
