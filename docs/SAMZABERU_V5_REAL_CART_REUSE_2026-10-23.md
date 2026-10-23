# API V5: реальное чтение корзины через существующий V4 CartApiService

**23.10.2026. Статус: feature-code, до native site-acceptance и production deployment.** НЕ новый mock API и НЕ создание синтетических аккаунтов.

## Исходное фактическое состояние V4

Оператор проверил exact source-only readout на \`evrasia\`. \`FINAL_STATUS=PASS\`, \`WRAPPER_RC=0\`, no SQL/Bitrix/HTTP calls or changes.

- \`local/php_interface/Controllers/V4/Cart.php\`, SHA256 \`73989036542321bbac5ee76a1b104713ff932621ffaa0f4e2aa08ed28949a039\`.
- Конструктор \`V4\Cart\`: \`$this->userId=$this->getUserId()\`, при userId вызывает глобальное \`$USER->Authorize($this->userId)\`, принимает \`fUserId\` через \`$_POST\`/\`$_GET\` и создаёт \`new \Evrasia\Services\CartApiService($this->fUserId)\`. \`getUserId()\` возвращает V4 \`JwtAuthorization::getAuthUserId()\`. **Такой V4 controller НЕ вызывать из V5**, это побочные эффекты/legacy auth и user-chosen FUSER.
- V4 установленные флаги сервиса: \`useRedPrice(true)\`, \`useRedSku(false)\`, \`useGifts(false)\`.
- \`Cart::getCart()\` вызывает \`checkFUserId()\`, \`DiscountCouponsManager::init()\`, \`CartApiService::getCartItems()\` и \`Cart::calcTotal()\`; legacy DTO включает также extra data; нельзя утверждать, что \`getCartItems()\` уже возвращает полный итог корзины.
- \`local/php_interface/lib/Services/CartApiService.php\`, SHA256 \`61671c764f23a82ce66a990d5c9122ebf25fdac800ab51828c0805c8c9db18e0\`: класс найден, 27 деклараций методов; \`__construct\` строка 18, \`useRedPrice\` 22, \`useRedSku\` 27, \`useGifts\` 32, \`getCartItems\` 37, \`calcBasketTotal\` 141, \`getProductsForOrder\` 479 и прочие. **Были выведены только имена / начало V4 контроллера**, поэтому точный read/write contract метода \`getCartItems\`, \`checkFUserId\`, содержание/формат \`calcBasketTotal\`, FUSER ownership and same-connection ORM behavior пока НЕ аттестованы. Не выдумывать.
- \`V4\Restaurants::stopOrder()\` из \`Restaurants.php\` SHA256 \`c9560839aac8b9640ab65b778736deeed1366096a4a1a0393f4bf72462d80deb\`: читает через Bitrix \`CIBlockElement::GetList\`, \`ApiResponse::data\` вызывает legacy HTTP sender, не переносить напрямую.

## Реализовано отдельным feature-only кодом V5

- Новый **\`GET /api/v5/cart\`** с \`Authorization: Bearer <real_v5_access_jwt>\`, HTTPS, no body/cookie/query-supplied user/fuser. 200 \`{"items":[...service-line-items...]}\`, 401 \`{"error":"authentication_failed"}\`, 503 \`{"error":"v5_unavailable"}\` если cart-provider не подключён или \`{"error":"temporarily_unavailable"}\` при backend exception. Это **не полный V4 Cart DTO**: цена всей корзины, gifts and recommendations и \`FUSER_ID\` пока намеренно не возвращаются; должен быть проверен реальный read/total/ownership contract до DTO-parity.
- \`V5MySqlStrongSessionStore::readCartForAccess\` под **одной** V5 транзакцией: JWT signature, committed strong assurance, current \`b_user\` ACTIVE/BLOCKED/PASSWORD_EXPIRED lock, 10-path native epoch-coverage proof, \`AUTH_EPOCH\`, revocation, strong session, затем native cart read. Точно как protected PIN, не схема \`validate JWT\` → released lock → cart read.
- \`V5MySqlCartRead\` — production-shaped composition к этому session store.
- \`V5BitrixCartApiServiceReader\` — реальное повторное использование установленного класса по Reflection exact canonical file + SHA256 и соответствию пяти public methods. \`new \Evrasia\Services\CartApiService($fuserId)\` и три V4-флага, затем \`getCartItems()\`. Никаких V4 HTTP controllers, \`JwtAuthorization\`, \`CUser::Authorize\`, SMS, новых аккаунтов и новых бизнес-алгоритмов.
- Binding FUSER only from **mandatory trusted \`V5VerifiedLockedCartOwnerPort::verifiedFuserIdWithUserLock(PDO $db, int $userId)\`**, на **той же транзакции**, который обязан проверить владельца корзины и отсутствие side effects, никогда не принимать request FUSER ID. **Реального положительного native provider пока нет**; дефолт null → 503, даже при валидном JWT.
- \`V5HttpTransportBoundary\`, \`V5PhpHttpRequestFactory\` принимают только \`HTTP_AUTHORIZATION\` из доверенного CGI и отвергают неверный Bearer, query, X-Forwarded substitute, non-HTTPS, legacy V4 JWT. Bodyless GET, no-store; protected response size limited. \`V5MySqlPublicComposition\` и pilot composition используют новый reader только при **явной инъекции**. До приёмки source/owner/provider production продолжает AUTH OFF.

## Тестирование и следующий шаг

- Новый \`V5CartBusinessReuseTest.php\` проверяет HTTP bearer/GET/invalid/foreign/side effects/source-pin refusal на offline in-process contract stubs; старый \`V5MySqlStrongSessionStoreTest.php\` дополнен проверками под lock, deny, native exception, replay/revoke.
- Офлайн CI расширен новым test suite и PHP lint. **CI: first-factor/public #39976180823 SUCCESS, offline safety #39976180701 SUCCESS** after minimal HTTP GET entry fix commit `6e18b11e`; ephemeral MariaDB #39976180778 SUCCESS (disposable DB; NOT live Bitrix). Original feature commit `e17dc49a` passed first-factor and MariaDB; its offline suite detected a missing HTTP GET prebootstrap admission, which was corrected fail-closed without weakening V5 authentication. CI fake objects — не создание тестового аккаунта и не публичный mock API. Real Bitrix site test ещё не проведён.
- **NEXT:** выполнить точечную проверку только body \`CartApiService::__construct/getCartItems/calcBasketTotal\` + \`Cart::checkFUserId/getSiteId\` из существующих закреплённых source, убедиться в ownership/writes/Bitrix ORM/transaction coupling, реализовать реальный \`V5VerifiedLockedCartOwnerPort\` в site integration. Затем решить нужен ли GET /cart без авторизации и как реализовать временную гостевую корзину без IDOR (не предполагать). После native guarded integration добавить полное DTO price/total/discounts/extra, используя те же V4 business funcs. **Никакой activation V5 AUTH до native gates**.

Основной адрес мобильного API — **https://evrasia.rest/api/v5**. \`evrasia.spb.ru\` — Bitrix origin/filesystem; прежний public legal install-script с only .spb.ru HTTP smoke не считать выпущенным для .rest.

## Следующая точная производственная проверка (один раз, без повторной инвентаризации)

В \`tests/diagnostics/\` подготовлены \`V5CartFuserBusinessBoundary.php\` и \`v5_cart_fuser_business_boundary_readonly.sh\`. Скрипт source-only с фиксированными SHA из предыдущего production вывода печатает **ТОЛЬКО** \`V4\Cart::checkFUserId/getSiteId\` и \`CartApiService::__construct/getCartItems/calcBasketTotal\` с замаскированными строками/комментариями. Ни один сайт/SQL/Bitrix файл не исполняется и не изменяется. После stdout определить, какие методы способны возвращать подтверждённый FUSER_ID без генерации новой корзины, имеют ли побочные записи и какой полный результат расчёта. Не угадывать SQL-таблицы или DTO на глаз.

