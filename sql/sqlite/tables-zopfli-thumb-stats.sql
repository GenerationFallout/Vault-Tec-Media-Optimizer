-- Aggregate stats table for thumbnail zopflipng savings (SQLite).
CREATE TABLE /*_*/vtmo_zopfli_thumb_stats (
  zts_id INTEGER UNSIGNED NOT NULL,
  zts_thumb_count INTEGER UNSIGNED DEFAULT 0 NOT NULL,
  zts_bytes_saved BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  zts_updated_at BLOB DEFAULT NULL,
  PRIMARY KEY(zts_id)
);
