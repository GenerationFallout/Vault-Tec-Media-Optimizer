-- Main optimization tracking table (SQLite).
CREATE TABLE /*_*/vtmo_image_optimization (
  io_img_name BLOB NOT NULL,
  io_status BLOB DEFAULT 'pending' NOT NULL,
  io_original_size INTEGER UNSIGNED DEFAULT NULL,
  io_optimized_size INTEGER UNSIGNED DEFAULT NULL,
  io_webp_size INTEGER UNSIGNED DEFAULT NULL,
  io_processed_at BLOB DEFAULT NULL,
  io_backend BLOB DEFAULT NULL,
  io_error BLOB DEFAULT NULL,
  io_png_zopfli_size INTEGER UNSIGNED DEFAULT NULL,
  PRIMARY KEY(io_img_name)
);

CREATE INDEX io_status ON /*_*/vtmo_image_optimization (io_status);

CREATE INDEX io_processed_at ON /*_*/vtmo_image_optimization (io_processed_at);
