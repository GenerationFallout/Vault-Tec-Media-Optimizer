-- Main optimization tracking table.
-- Dedicated file containing ONLY this table, so addExtensionTable() can
-- create it in isolation without touching any other table.
CREATE TABLE /*_*/vtmo_image_optimization (
  io_img_name VARBINARY(255) NOT NULL,
  io_status VARBINARY(32) DEFAULT 'pending' NOT NULL,
  io_original_size INT UNSIGNED DEFAULT NULL,
  io_optimized_size INT UNSIGNED DEFAULT NULL,
  io_webp_size INT UNSIGNED DEFAULT NULL,
  io_processed_at BINARY(14) DEFAULT NULL,
  io_backend VARBINARY(16) DEFAULT NULL,
  io_error BLOB DEFAULT NULL,
  io_png_zopfli_size INT UNSIGNED DEFAULT NULL,
  INDEX io_status (io_status),
  INDEX io_processed_at (io_processed_at),
  PRIMARY KEY(io_img_name)
) /*$wgDBTableOptions*/;
