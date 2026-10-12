-- STAGING TEST SCHEMA ONLY. NEVER RUN ON PRODUCTION.
-- Pre-create on a dedicated, verified evrasia_v5_test_* schema;
-- no main Bitrix/user tables are changed by this DDL.
CREATE TABLE ev_v5_native_tx_probe (
    MARKER CHAR(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    ORIGIN VARCHAR(8) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
    PRIMARY KEY (MARKER)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
