-- PROPOSED ONLY: DO NOT APPLY TO PRODUCTION AUTOMATICALLY.
-- This ledger is used by the independent V5 checkout on the SAME physical
-- Bitrix MySQL transaction after strong V5 owner/epoch verification.
-- A UNIQUE (USER_ID,REQUEST_ID) prevents two orders for one retry identity.
-- Do not enable V5 checkout until native Sale D7 transaction continuity and
-- side effects have been tested in the current installed Bitrix environment.

CREATE TABLE ev_v5_pickup_checkout_requests (
  USER_ID INT UNSIGNED NOT NULL,
  REQUEST_ID BINARY(16) NOT NULL,
  INPUT_SHA256 BINARY(32) NOT NULL,
  STATE VARCHAR(12) NOT NULL,
  ORDER_ID INT UNSIGNED NULL,
  ORDER_NUMBER VARCHAR(64) NULL,
  ORDER_STATUS VARCHAR(16) NULL,
  CREATED_AT DATETIME NOT NULL,
  COMPLETED_AT DATETIME NULL,
  PRIMARY KEY (USER_ID, REQUEST_ID),
  KEY idx_checkout_order (ORDER_ID),
  CONSTRAINT chk_pickup_checkout_state CHECK (STATE IN ('pending','saved'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
