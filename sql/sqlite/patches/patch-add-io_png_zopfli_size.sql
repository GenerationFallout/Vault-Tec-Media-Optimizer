-- Add second-pass zopflipng recompression size column to existing installs.
ALTER TABLE /*_*/vtmo_image_optimization
  ADD io_png_zopfli_size INTEGER UNSIGNED DEFAULT NULL;
