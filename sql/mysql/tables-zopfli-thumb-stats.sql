-- Aggregate stats table for thumbnail zopflipng savings.
-- Dedicated single-table file so addExtensionTable() can create it in
-- isolation, without re-running any other table definition.
CREATE TABLE /*_*/vtmo_zopfli_thumb_stats (
  zts_id INT UNSIGNED NOT NULL,
  zts_thumb_count INT UNSIGNED DEFAULT 0 NOT NULL,
  zts_bytes_saved BIGINT UNSIGNED DEFAULT 0 NOT NULL,
  zts_updated_at BINARY(14) DEFAULT NULL,
  PRIMARY KEY(zts_id)
) /*$wgDBTableOptions*/;
