# СамЗаберу API V5 — готовая сборка серверных компонентов checkout

Дата: 2026-10-25  
Ветка: `feature/v5-production-native-preflight-20261025`  
Статус: **FEATURE ONLY — production OFF, V4 без изменений**

## Реализация

Создан `site-integration/samzaberu-v5/src/V5NativePickupCheckoutComposition.php` с фабрикой `V5NativePickupCheckoutComposition::buildForStrictHttp(...)`. Это **реальный V5-only объектный граф**, а не очередной анализатор:

1. `V5BitrixLockedCartFuserOwner`: подтверждённый USER_ID → FUSER_ID, строго один FUSER, блокировка.
2. `V5BitrixNativePickupMaterializer`: текущая серверная корзина, актуальные данные каталога и цены, новый Bitrix Sale Basket.
3. `V5BitrixCheckoutBusinessRules`: активный ресторан, допустимое время, серверный способ оплаты при получении.
4. `V5NativePickupSaleD7Saver` → `V5BitrixSaleD7OrderBuilder`: создание неоплаченного заказа D7, без перехода в оплаченный/завершённый статус.
5. `V5BitrixNativePickupSavedOrderProof`: состояние реальной строки `b_sale_order` с тем же владельцем, номером, статусом N/P, PAYED=N, CANCELED=N.
6. **Предоставляемый сайтом** `V5PickupLocalCallCenterHandoffProofPort`: подтверждение принятия заказа имеющимся локальным механизмом Bitrix. Никакой связи с серверами колл-центра со стороны V5.
7. `V5BitrixNativePickupCartCleanup`: два подтверждённых удаления только из `B_API_BASKET WHERE FUSER_ID=?` и `BASKET_CUSTOM_GIFTS WHERE UF_FUSER_ID=?`, в единой транзакции.
8. `V5BitrixLockedPickupOrderWriter`: серверный `request_id`, идемпотентное оформление и получение прежнего результата без повторного заказа.

Публичный V5 HTTP-маршрут **уже существовал**: `POST /api/v5/cart/order`. Через обязательный параметр `checkoutWriter` в `V5MySqlPublicComposition::buildEmailFirst(...)` (или `build(...)`) готовый writer можно подключить к `V5MySqlOrderPlace` и `V5AuthHttpHandler` **без добавления нового маршрута и без вмешательства в API V4**. В данном изменении ничего в public site factory не устанавливается и маршрут на production не включается.

## Обязательное допущение к включению

`V5NativePickupCheckoutComposition::buildForStrictHttp()` намеренно отказывает, если:

- это не доверенный **FPM HTTP** с настоящим `V5BitrixPdoCompatibilityBridge`, strict ingress и отсутствующей на момент сборки чужой активной транзакцией;
- не передана `V5NativePickupSiteConfiguration` с явными **действительными, проверенными на сайте** `payOnPickupSystemId`, `dispatchTypes`, `timeFailureKey`, `basketNotes`, `source`, `happytime`;
- отсутствует независимая реализация `V5NativePickupSiteReleaseEvidencePort`, подтверждающая локальную готовность. Значение `false`, `null` и исключение запрещают выпуск checkout;
- нет подтверждённого `V5PickupLocalCallCenterHandoffProofPort`.

**Ни одна реализация положительного site-release evidence или call-center handoff-proof в production не установлена.** Таким образом, сборка создана, но настоящая отправка всё ещё **запрещена**. Нельзя считать прохождение CI доказательством атомарности `Sale\Order::save()` и V5 native bridge: она требует отдельной реальной проверки. Последняя read-only диагностика Bitrix показала `Order::save() → parent::save()`, `OrderBase::save() → $VAR->save()` с присвоением через `$this->ID()`; скрытое имя метода НЕ установлено, но по просьбе владельца бесконечное исследование внутренних вызовов не продолжаем.

## Автономная проверка

GitHub Actions: https://github.com/juvantusik/Evrasia_AI_bot/actions/runs/40214572165 — **SUCCESS**.

- Checkout HTTP — 16
- Sale D7 builder — 24
- Business rules — 18
- Locked writer — 39
- Native materializer — 23
- Fresh basket — 24
- Pinned property policy — 12
- Native SQL bridge — 195
- **Новая сборка native checkout — 24**

Всего **375/375 mock-only**. Объектный граф проверен через Reflection без вызова сервера, Bitrix bootstrap, базы, оплаты или отправки в колл-центр. CLI не может пройти strict HTTP gate даже с подставным доказательством готовности.

## Граница ответственности

**СамЗаберу → API V5 → Bitrix → существующий локальный канал передачи в колл-центр.** После этой границы заказ идёт в колл-центр, затем в ресторан. Ни доступов, ни исследования серверов колл-центра не требуется.

За пределами этого изменения: установка схемы `ev_v5_pickup_checkout_requests`, разрешение native-транзакции, проверка Side Effects `Sale::save`, присоединение положительных site-release proof, явное разрешение пользователя на тест реальным аккаунтом и реальным заказом. До этого V5 AUTH OFF и никаких production writes.
