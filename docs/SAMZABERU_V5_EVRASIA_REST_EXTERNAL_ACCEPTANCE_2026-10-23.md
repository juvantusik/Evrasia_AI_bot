# СамЗаберу API V5 — подтверждённый публичный evrasia.rest

**Принятый основной мобильный API:** `https://evrasia.rest/api/v5`. `evrasia.spb.ru` — лишь технический origin Bitrix; его успешная проверка не равна внешней доступности главного домена. **Проверка извне наконец подтверждена 23 октября 2026.**

## 1. Что действительно установлено в production

Оператор выполнил root-установщик `upgrade-twofile-public-registration-v5.sh`, pin commit `3c9fe2f3696c5192b44b8d8d2d4042771937db40`, Git blob `18cfd9fc96f21e6c76f603b409ec41094eca11d8`. Реальный итог:

```text
FINAL_STATUS=PASS
FINAL_RC=0
WRAPPER_RC=0
WRAPPER_FINAL_STATUS=PASS
ROLLBACK_STATUS=NOT_NEEDED
LOCAL_V5_ROUTER_SWITCHED=YES
LOCAL_V5_LIBRARIES_ADDED=1
ORIGIN_HTTPS_ACCEPTED=YES
V5_AUTH_ON_ORIGIN=NO
PUBLIC_V5_SHIM_CHANGED=NO
V4_CHANGED=NO
DATABASE_WRITE=NO
ACCOUNT_WRITE=NO
SMS_SENT_BY_SCRIPT=NO
LEGACY_INSTALLED_MANIFEST_WRITE=NO
```

На origin `evrasia.spb.ru` через HTTPS: `GET /api/v5/health` **200**; `GET /api/v5/legal/consents` **200**; `GET /api/v5/legal/registration-documents` **200**; `POST /api/v5/legal/consents/validate` **200** c `valid:true` и кодами текущей версии; `POST /api/v5/auth/signin` **503** (авторизация OFF).

Приватная резервная копия установки: `/root/ev-v5-checkpoints/v5-legal-twofile-20261023.Dfdererr`. **Не удалять.** Исторический `installed.sha256` сохранён без изменений с одной документированной устаревшей записью публичного shim; наличие этого отклонения не использовать как разрешение игнорировать SHA других файлов.

## 2. Настоящая независимая проверка `evrasia.rest`

Чтобы не полагаться на origin или hairpin/NAT внутри сети, добавлен отдельный безучётный GitHub Actions workflow [`.github/workflows/v5-public-external-smoke.yml`](../.github/workflows/v5-public-external-smoke.yml), источник commit [`fbd3d56d`](https://github.com/juvantusik/Evrasia_AI_bot/commit/fbd3d56dbaf55eeb9c3fb17a27feebfc05bb1bd7). **Его GitHub-hosted runner находится вне production сети**, обращается к реальному `https://evrasia.rest` по PUBLIC DNS, со стандартной проверкой TLS-сертификата, без `--resolve`, без IP override, без HTTP proxy, cookies, JWT, телефонов или пользовательских данных. Запрашиваются только публичные GET.

[**Внешняя GitHub Actions-проверка #39999984588**](https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/39999984588), job `real-public-https` #120573261682, **COMPLETED SUCCESS** в 18:02 UTC 23 октября 2026. Лог:

```text
TARGET=https://evrasia.rest/api/v5
ORIGIN_IP_OVERRIDE=NO
PUBLIC_DNS_IPV4_RECORDS=1
CHECK=GET /api/v5/health
HTTP_STATUS=200
HEALTH_STATUS=online
V5_AUTH_READY=false
CHECK=GET /api/v5/legal/consents
HTTP_STATUS=200
CHECK=GET /api/v5/legal/registration-documents
HTTP_STATUS=200
DOCUMENT_COUNT=3
REQUIRED_DOCUMENT_COUNT=2
DOCUMENT_TYPES=offer,personal_data,marketing
EXTERNAL_EVRASIA_REST_ACCEPTANCE=YES
FINAL_STATUS=PASS
```

В workflow фактически проверены: public DNS, отсутствие подмены соединения на частный IP, HTTPS с проверкой сертификата, отсутствие HTTP-редиректов, HTTP 200, `application/json`, `Cache-Control: no-store`, отсутствие cookies/CORS/Location, корректный `health` и **идентичные JSON-документы в обеих юридических GET-ручках**. Ни Bitrix CLI, ни production DB, ни OAuth/JWT-права не использовались.

**Точная граница доказательства:** Три анонимных GET на `evrasia.rest` сейчас доступны как минимум из независимой внешней сети GitHub; эта проверка НЕ доказывает мобильную доступность во всех операторах связи/странах, работу POST с юридическими кодами на публичном домене (оператор проверил его на origin), личный кабинет, корзину, лояльность, заказ или готовность V5 AUTH. Не объявлять всё API V5 завершённым.

## 3. Правила следующих этапов

1. Всегда считать `https://evrasia.rest/api/v5` единственным публичным адресом для «СамЗаберу». В мобильный SDK не передавать `evrasia.spb.ru`, `192.168.103.141` или localhost.
2. Для диагностики со стороны Bitrix использовать `curl --resolve evrasia.rest:443:192.168.103.141 https://evrasia.rest/api/v5/...`, сохраняя **Host и TLS SNI главного домена**. Это внутренний connectivity test, не независимая внешняя проверка.
3. Для внешней приёмки запускать `V5 canonical public API external HTTPS acceptance` через GitHub Actions или отдельную реально внешнюю сеть; **не считать локальный hairpin отказ публичным инцидентом**.
4. Пока `V5_AUTH_READY=false`, не активировать на production защищённые методы, регистрацию аккаунтов, SMS или PIN; допускать только принятые публичные legal/health операции.
5. После завершения V5 бизнес-паритета и миграции мобильных/интеграционных потребителей V4 планово отключается по правилам [sole API cutover](SAMZABERU_V5_SOLE_API_CUTOVER_2026-10-23.md). V4 не отключён сейчас.

**Статус:** `PUBLIC_V5_LEGAL_GET_EXTERNAL_ACCEPTED=YES`; `PRODUCTION_V5_AUTH_READY=NO`; `V4_DECOMMISSIONED=NO`; `NEXT=IMPLEMENT_NATIVE_V5_BUSINESS_AND_AUTH_GATES`.
