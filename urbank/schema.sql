-- UMU (Ur Monetary Unit) commodity basket tracker
-- Run this once in phpMyAdmin against your database.

CREATE TABLE IF NOT EXISTS commodities (
  id                 INT AUTO_INCREMENT PRIMARY KEY,
  slug               VARCHAR(50)   NOT NULL UNIQUE,      -- 'gold','silver','water','crude_oil','soybeans','lumber','corn'
  display_name       VARCHAR(100)  NOT NULL,
  basket_quantity    DECIMAL(14,6) NOT NULL,              -- amount of this commodity in 1 UMU
  basket_unit        VARCHAR(30)   NOT NULL,              -- unit basket_quantity is expressed in: g, gallon, barrel, bushel, board_foot
  api_source         ENUM('api_ninjas','manual') NOT NULL DEFAULT 'api_ninjas',
  api_name           VARCHAR(50)   NULL,                  -- 'name' param sent to API Ninjas (e.g. 'gold')
  api_unit           VARCHAR(30)   NULL,                  -- 'unit' param/response from API (e.g. 'troy_ounce')
  unit_divisor       DECIMAL(20,10) NOT NULL DEFAULT 1,   -- divide raw API price by this to convert api_unit -> basket_unit
  price_in_cents     TINYINT(1)    NOT NULL DEFAULT 0,    -- 1 if API returns USX (cents) instead of USD, e.g. grains
  manual_price_usd   DECIMAL(14,6) NULL,                  -- used when api_source = 'manual', price per basket_unit
  active             TINYINT(1)    NOT NULL DEFAULT 1,
  sort_order         INT           NOT NULL DEFAULT 0,
  updated_at         TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS commodity_prices (
  id                    BIGINT AUTO_INCREMENT PRIMARY KEY,
  commodity_id          INT NOT NULL,
  price_per_basket_unit DECIMAL(14,6) NOT NULL,  -- USD per basket_unit, after all conversions
  raw_price             DECIMAL(14,6) NULL,       -- as returned by the source, for auditing
  raw_unit              VARCHAR(30)   NULL,
  source                VARCHAR(20)   NOT NULL,   -- 'api_ninjas' or 'manual'
  fetched_at            DATETIME      NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_commodity FOREIGN KEY (commodity_id) REFERENCES commodities(id),
  INDEX idx_commodity_time (commodity_id, fetched_at)
) ENGINE=InnoDB;

CREATE TABLE IF NOT EXISTS umu_value_history (
  id            BIGINT AUTO_INCREMENT PRIMARY KEY,
  value_usd     DECIMAL(14,6) NOT NULL,
  detail_json   TEXT NOT NULL,   -- snapshot: per-commodity contribution at this computation
  computed_at   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  INDEX idx_computed_at (computed_at)
) ENGINE=InnoDB;

-- Seed the basket exactly as specified:
-- 1 UMU = 0.001g gold + 0.01g silver + 1 gal water + 0.01 bbl crude
--         + 0.01 bu soybeans + 0.11 board-ft lumber + 0.01 bu corn

INSERT INTO commodities (slug, display_name, basket_quantity, basket_unit, api_source, api_name, api_unit, unit_divisor, price_in_cents, manual_price_usd, sort_order) VALUES
('gold',      'Gold',              0.001, 'g',         'api_ninjas', 'gold',        'troy_ounce', 31.1034768, 0, NULL,  10),
('silver',    'Silver',            0.01,  'g',         'api_ninjas', 'silver',      'troy_ounce', 31.1034768, 0, NULL,  20),
('water',     'Potable Water',     1,     'gallon',    'manual',     NULL,          NULL,         1,          0, 0.004, 30),
('crude_oil', 'Light Crude Oil',   0.01,  'barrel',    'api_ninjas', 'crude_oil',   'barrel',     1,          0, NULL,  40),
('soybeans',  'Soybeans',          0.01,  'bushel',    'api_ninjas', 'soybean',     'bushel',     1,          1, NULL,  50),
('lumber',    'Lumber',            0.11,  'board_foot','api_ninjas', 'lumber',      'board_feet', 1,          0, NULL,  60),
('corn',      'Corn',              0.01,  'bushel',    'api_ninjas', 'corn',        'bushel',     1,          1, NULL,  70)
ON DUPLICATE KEY UPDATE display_name = VALUES(display_name);
