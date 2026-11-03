# СамЗаберу / API V5 — Mobile ID + доверенное устройство

Дата: 03.11.2026. Статус: POSITIVE_REAL_E2E_PASS на production HTTPS origin.

## Подтверждённый контракт

Базовый URL: https://evrasia.rest/api/v5

- **Первый вход**: POST /signin/mobileid, application/x-www-form-urlencoded: phone, device_id, platform. Ответ содержит id попытки. Далее POST /signin/mobileid/status с attempt_id, device_id и platform; при NEED_SMS_CODE — POST /signin/mobileid/code с attempt_id, code. При AUTH выдается V5 access_token и refresh_token.
- **Регистрация доверия**: POST /auth/trust/enroll, application/json и Authorization: Bearer <V5 JWT>: device_id, platform, trust_token. Успешно: HTTP 200, enrolled=true.
- **Повторный вход**: POST /signin/mobileid, application/x-www-form-urlencoded: phone, device_id, platform, необязательный trust_token. При действительном доверии: HTTP 200, status=AUTH, V5 access_token и refresh_token без новой попытки Mobile ID. Если доверие отсутствует либо не подтверждено, выполняется обычный Mobile ID.
- **Проверка доверия**: POST /auth/trust/validate, JSON, Bearer V5 JWT, device_id, platform, trust_token. HTTP 200, trusted=true.
- **Отзыв доверия**: POST /auth/trust/revoke, JSON, Bearer V5 JWT, device_id, platform. HTTP 200, revoked=true.

device_id имеет формат sz_ + 64 lowercase hex и остаётся неизменным для установки; platform — ios/android. trust_token — 32 случайных байта в 64 lowercase hex, генерируется и безопасно хранится в приложении, не генерируется и не возвращается API. Не использовать V4 JWT.

## Реальный E2E, 03.11.2026

На реальном аккаунте подтверждены: старт Mobile ID HTTP 200; последовательность NEW → SENT → NEED_SMS_CODE → AUTH, /code HTTP 200, настоящий V5 JWT; enroll HTTP 200 enrolled=true; validate HTTP 200 trusted=true; повторный POST /signin/mobileid с trust_token HTTP 200 AUTH + новый V5 JWT, без новой попытки (SECOND_MOBILEID_ATTEMPT=FALSE); validate с новым JWT HTTP 200 trusted=true; revoke HTTP 200 revoked=true. FINAL_STATUS=PASS, FINAL_RC=0.

## Установленные исправления V5

- EV_V5_MOBILEID_TRUST_SIGNIN_20261103 — отдельная выдача trusted-сессии с проверкой устройства в транзакции, подключённая к старту Mobile ID. Backup: v5-mobileid-trust.1CR4VC4A.
- EV_V5_REVOKED_MOBILEID_REENROLL_20261103 — повторный enroll ранее отозванного своего Mobile ID устройства (STATUS=2, CREATED_METHOD=4). Backup: v5-trust-reenroll.m7w66z6k.

API V4 и ядро Bitrix не менялись. Тест выполнен скриптовым клиентом через production HTTPS origin; мобильное приложение и внешняя доступность из пользовательских сетей отдельно не проверялись. Тестовый trust после E2E отозван.

Основные документы: SAMZABERU_API_V5_FULL_SPECIFICATION_2026-11-02.txt и SAMZABERU_V5_PRODUCTION_STATUS_2026-11-02.md. Эта запись обновляет промежуточные статусы Mobile ID trust в них.
