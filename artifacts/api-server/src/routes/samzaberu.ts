import { Router, type IRouter } from "express";
import {
  CompleteSamzaberuRequestManuallyBody,
  CompleteSamzaberuRequestManuallyParams,
  CompleteSamzaberuRequestManuallyResponse,
  CreateSamzaberuRequestBody,
  CreateSamzaberuRequestResponse,
  GetSamzaberuAccessResponse,
  GetSamzaberuSummaryQueryParams,
  GetSamzaberuSummaryResponse,
  ListRestaurantsQueryParams,
  ListRestaurantsResponse,
  ListSamzaberuRequestsQueryParams,
  ListSamzaberuRequestsResponse,
} from "@workspace/api-zod";
import { samzaberuService } from "../services/samzaberu-service";

const router: IRouter = Router();

function parseFailure(res: { status: (code: number) => { json: (body: unknown) => void } }, error: unknown): void {
  const message = error instanceof Error ? error.message : "Неизвестная ошибка";
  res.status(400).json({ error: message });
}

router.get("/samzaberu/restaurants", async (req, res): Promise<void> => {
  const query = ListRestaurantsQueryParams.safeParse(req.query);
  if (!query.success) {
    res.status(400).json({ error: query.error.message });
    return;
  }
  try {
    res.json(ListRestaurantsResponse.parse(await samzaberuService.listRestaurants(query.data.operatorId)));
  } catch (error) {
    req.log.warn({ error }, "Rejected restaurant access request");
    parseFailure(res, error);
  }
});

router.get("/samzaberu/access", (_req, res): void => {
  res.json(GetSamzaberuAccessResponse.parse({
    operators: samzaberuService.getAccessOverview(),
  }));
});

router.get("/samzaberu/summary", async (req, res): Promise<void> => {
  const query = GetSamzaberuSummaryQueryParams.safeParse(req.query);
  if (!query.success) {
    res.status(400).json({ error: query.error.message });
    return;
  }
  try {
    res.json(GetSamzaberuSummaryResponse.parse(await samzaberuService.getSummary(query.data.operatorId)));
  } catch (error) {
    req.log.warn({ error }, "Rejected SamZaberu summary request");
    parseFailure(res, error);
  }
});

router.get("/samzaberu/requests", async (req, res): Promise<void> => {
  const query = ListSamzaberuRequestsQueryParams.safeParse(req.query);
  if (!query.success) {
    res.status(400).json({ error: query.error.message });
    return;
  }
  try {
    res.json(ListSamzaberuRequestsResponse.parse(await samzaberuService.listRequests(query.data.operatorId)));
  } catch (error) {
    req.log.warn({ error }, "Rejected SamZaberu journal request");
    parseFailure(res, error);
  }
});

router.post("/samzaberu/requests", async (req, res): Promise<void> => {
  const body = CreateSamzaberuRequestBody.safeParse(req.body);
  if (!body.success) {
    res.status(400).json({ error: body.error.message });
    return;
  }
  try {
    const request = await samzaberuService.processChange(body.data);
    res.status(201).json(CreateSamzaberuRequestResponse.parse(request));
  } catch (error) {
    req.log.warn({ error }, "SamZaberu change request failed validation");
    parseFailure(res, error);
  }
});

router.post("/samzaberu/requests/:requestId/manual", async (req, res): Promise<void> => {
  const params = CompleteSamzaberuRequestManuallyParams.safeParse(req.params);
  const body = CompleteSamzaberuRequestManuallyBody.safeParse(req.body);
  if (!params.success) {
    res.status(400).json({ error: params.error.message });
    return;
  }
  if (!body.success) {
    res.status(400).json({ error: body.error.message });
    return;
  }
  try {
    const request = await samzaberuService.completeManually(
      params.data.requestId,
      body.data.operatorId,
    );
    res.json(CompleteSamzaberuRequestManuallyResponse.parse(request));
  } catch (error) {
    req.log.warn({ error }, "Manual completion was rejected");
    parseFailure(res, error);
  }
});

export default router;