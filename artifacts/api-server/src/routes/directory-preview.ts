import { Router, type IRouter } from "express";
import { corporatePhoneDirectoryTable, db } from "@workspace/db";
import { asc, eq } from "drizzle-orm";

const router: IRouter = Router();

router.get("/directory/phones", async (req, res): Promise<void> => {
  try {
    const rows = await db
      .select({
        id: corporatePhoneDirectoryTable.id,
        phone: corporatePhoneDirectoryTable.phone,
        operator: corporatePhoneDirectoryTable.operator,
        legalEntity: corporatePhoneDirectoryTable.legalEntity,
        inn: corporatePhoneDirectoryTable.inn,
        accountNumber: corporatePhoneDirectoryTable.accountNumber,
        restaurantName: corporatePhoneDirectoryTable.restaurantName,
        lineType: corporatePhoneDirectoryTable.lineType,
        subscriberName: corporatePhoneDirectoryTable.subscriberName,
      })
      .from(corporatePhoneDirectoryTable)
      .where(eq(corporatePhoneDirectoryTable.active, true))
      .orderBy(asc(corporatePhoneDirectoryTable.operator), asc(corporatePhoneDirectoryTable.phone));

    res.json({ records: rows });
  } catch (error) {
    req.log.error({ error }, "Failed to load corporate directory for web preview");
    res.status(500).json({ error: "Не удалось загрузить корпоративный справочник." });
  }
});

export default router;
