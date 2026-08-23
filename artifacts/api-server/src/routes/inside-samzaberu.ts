import { Router, type IRouter, type Request } from "express";
import {
  InsideAccessError,
  resolveInsideSamzaberuSession,
  type InsideSamzaberuSession,
} from "../services/inside-auth";
import {
  samzaberuService,
  type SamzaberuAction,
} from "../services/samzaberu-service";

const router: IRouter = Router();

const sessionFor = (req: Request): Promise<InsideSamzaberuSession> =>
  resolveInsideSamzaberuSession(req.get("authorization"));

const sendFailure = (
  req: Request,
  res: {
    status: (code: number) => {
      json: (body: unknown) => void;
    };
  },
  error: unknown,
): void => {
  if (error instanceof InsideAccessError) {
    req.log.warn(
      { code: error.code, statusCode: error.statusCode },
      "Inside access request rejected",
    );
    res.status(error.statusCode).json({
      error: error.code,
      message: error.message,
    });
    return;
  }

  const message = error instanceof Error ? error.message : "Неизвестная ошибка";
  req.log.warn({ error }, "Inside SamZaberu request rejected");
  res.status(400).json({ error: "samzaberu_request_rejected", message });
};

type ParsedChangeRequest = {
  action: SamzaberuAction;
  restaurantId: string;
  targetUntil: Date | null;
  confirmation: true;
};

const parseChangeRequest = (value: unknown): ParsedChangeRequest => {
  if (!value || typeof value !== "object") {
    throw new Error("Тело запроса должно быть объектом");
  }
  const body = value as Record<string, unknown>;
  if (body.action !== "STOP" && body.action !== "ENABLE") {
    throw new Error("Действие должно быть STOP или ENABLE");
  }
  const restaurantId =
    typeof body.restaurantId === "number"
      ? String(body.restaurantId)
      : typeof body.restaurantId === "string"
        ? body.restaurantId.trim()
        : "";
  if (!/^\d+$/.test(restaurantId)) {
    throw new Error("Некорректный ID ресторана");
  }
  if (body.confirmation !== true) {
    throw new Error("Изменение должно быть подтверждено");
  }

  if (body.action === "ENABLE") {
    return {
      action: body.action,
      restaurantId,
      targetUntil: null,
      confirmation: true,
    };
  }

  if (typeof body.targetUntil !== "string" || !/(?:Z|[+-]\d{2}:\d{2})$/.test(body.targetUntil)) {
    throw new Error("Для остановки укажите дату и время с часовым поясом");
  }
  const targetUntil = new Date(body.targetUntil);
  if (Number.isNaN(targetUntil.getTime())) {
    throw new Error("Некорректная дата окончания");
  }

  return {
    action: body.action,
    restaurantId,
    targetUntil,
    confirmation: true,
  };
};

router.get("/inside/samzaberu/session", async (req, res): Promise<void> => {
  try {
    const session = await sessionFor(req);
    const restaurants = await samzaberuService.listRestaurants(
      session.accessContext.operatorId,
      session.accessContext,
    );
    res.json({
      user: session.user,
      accessMode: session.accessMode,
      restaurantCount: restaurants.length,
    });
  } catch (error) {
    sendFailure(req, res, error);
  }
});

router.get("/inside/samzaberu/restaurants", async (req, res): Promise<void> => {
  try {
    const session = await sessionFor(req);
    res.json(
      await samzaberuService.listRestaurants(
        session.accessContext.operatorId,
        session.accessContext,
      ),
    );
  } catch (error) {
    sendFailure(req, res, error);
  }
});

router.get("/inside/samzaberu/summary", async (req, res): Promise<void> => {
  try {
    const session = await sessionFor(req);
    const summary = await samzaberuService.getSummary(
      session.accessContext.operatorId,
      session.accessContext,
    );
    res.json({
      ...summary,
      accessMode: session.accessMode,
      insideUser: session.user,
    });
  } catch (error) {
    sendFailure(req, res, error);
  }
});

router.get("/inside/samzaberu/requests", async (req, res): Promise<void> => {
  try {
    const session = await sessionFor(req);
    res.json(
      await samzaberuService.listRequests(
        session.accessContext.operatorId,
        session.accessContext,
      ),
    );
  } catch (error) {
    sendFailure(req, res, error);
  }
});

router.post("/inside/samzaberu/requests", async (req, res): Promise<void> => {
  try {
    const session = await sessionFor(req);
    const body = parseChangeRequest(req.body);
    const request = await samzaberuService.processChange(
      {
        ...body,
        operatorId: session.accessContext.operatorId,
      },
      session.accessContext,
    );
    res.status(201).json(request);
  } catch (error) {
    sendFailure(req, res, error);
  }
});

router.post(
  "/inside/samzaberu/requests/:requestId/manual",
  async (req, res): Promise<void> => {
    try {
      const session = await sessionFor(req);
      const requestId = req.params.requestId?.trim();
      if (!requestId || requestId.length > 128) {
        throw new Error("Некорректный ID запроса");
      }
      const request = await samzaberuService.completeManually(
        requestId,
        session.accessContext.operatorId,
        session.accessContext,
      );
      res.json(request);
    } catch (error) {
      sendFailure(req, res, error);
    }
  },
);

export default router;
