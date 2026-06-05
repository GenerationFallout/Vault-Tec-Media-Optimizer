#!/usr/bin/env python3
"""
Vault-Tec Media Optimizer — honest cost/benefit model + ALL benchmark charts.

Recomputes everything from EXPLICIT, stated assumptions and from the REAL
measurements taken on the test environment, and renders every figure used in
the Performance guide.  Run:  python3 docs/benchmarks/model.py

Two families of charts:
  (A) Real-world COST/BENEFIT model of the Fallout wiki (bandwidth & disk).
      * Pages serve THUMBNAILS, not full originals -> bandwidth is modelled at
        the thumbnail level, only as a COLD-CACHE UPPER BOUND (real traffic is
        far lower thanks to browser + CDN caching).
      * WebP files are stored IN ADDITION to the originals -> enabling WebP
        makes total disk go UP; optimising originals only reclaims a little.
  (B) MEASURED facts on the test box (PHP 8.4, libvips 8.15.1, ImageMagick
      6.9.12, gifsicle 1.94, zopflipng, PHP-GD; single core): per-file WebP/AVIF
      compression, engine speed & memory, GIF/PNG passes, throughput.

Every absolute number is reproducible here; ratios are what matter.
"""
import os
import matplotlib
matplotlib.use("Agg")
import matplotlib.pyplot as plt
import numpy as np
from matplotlib.patches import FancyArrowPatch

OUT = os.path.dirname(os.path.abspath(__file__))
C = dict(gray="#8c8c8c", blue="#1f4e79", gold="#c8881a", green="#2e7d32",
         red="#b3261e", teal="#2e8b8b", purple="#6a51a3", ink="#1a1a1a")
plt.rcParams.update({"font.size": 12, "font.family": "DejaVu Sans",
                     "axes.edgecolor": "#cccccc", "axes.grid": True,
                     "grid.color": "#ececec", "axes.axisbelow": True})

def caption(fig, txt):
    fig.text(0.5, 0.015, txt, ha="center", va="bottom",
             fontsize=9.3, color="#666", style="italic")

def save(fig, name):
    fig.tight_layout(rect=(0, 0.045, 1, 1))
    fig.savefig(f"{OUT}/{name}", dpi=150)
    plt.close(fig)

# ============================================================================
# (A) ASSUMPTIONS — decimal units (1 GB = 1000 MB, 1 TB = 1000 GB); 365 d/yr
# ============================================================================
ORIGINALS_GB    = 52.62
PAGEVIEWS_DAY   = 100_000
THUMBS_PER_PAGE = 10
SERVES_DAY      = PAGEVIEWS_DAY * THUMBS_PER_PAGE
RAW_THUMB_KB        = 55.3
THUMB_LOSSLESS_SAVE = 0.137
WEBP_THUMB_SAVE_OPT = 0.342
ORIG_RECOMP  = {"Base\n(already optimized)": 0.072, "Median": 0.160, "High": 0.238}
WEBP_OF_ORIG = 0.70
DAYS = 365

def gb_day(kb):   return SERVES_DAY * kb / 1e6
def tb_year(gbd): return gbd * DAYS / 1000.0

raw_kb  = RAW_THUMB_KB
opt_kb  = raw_kb * (1 - THUMB_LOSSLESS_SAVE)
webp_kb = opt_kb * (1 - WEBP_THUMB_SAVE_OPT)
bw = {"Before\n(raw thumbnails)": gb_day(raw_kb),
      "Optimize,\nno WebP":       gb_day(opt_kb),
      "With WebP":                gb_day(webp_kb)}
bw_saved_day = bw["Before\n(raw thumbnails)"] - bw["With WebP"]
bw_saved_yr  = tb_year(bw_saved_day)

scen        = list(ORIG_RECOMP.keys())
opt_disk    = {s: ORIGINALS_GB * (1 - ORIG_RECOMP[s]) for s in scen}
webp_disk   = {s: opt_disk[s] * (1 + WEBP_OF_ORIG)    for s in scen}
opt_change  = {s: opt_disk[s]  - ORIGINALS_GB for s in scen}
webp_change = {s: webp_disk[s] - ORIGINALS_GB for s in scen}

# ---- FIG 01 — bandwidth per day --------------------------------------------
fig, ax = plt.subplots(figsize=(7.4, 4.5))
labels, vals = list(bw.keys()), list(bw.values())
bars = ax.bar(labels, vals, color=[C["gray"], C["blue"], C["gold"]], width=0.62, edgecolor="white")
ax.set_ylim(0, 70); ax.set_ylabel("Bandwidth per day (GB)")
ax.set_title("Bandwidth served per day — 100,000 visits/day\n(10 thumbnails per page)", fontweight="bold", fontsize=13.5)
for b, v in zip(bars, vals):
    ax.text(b.get_x()+b.get_width()/2, v+0.8, f"{v:.1f} GB/day\n≈ {tb_year(v):.1f} TB/year", ha="center", va="bottom", fontsize=10)
ax.add_patch(FancyArrowPatch((0, vals[0]+3.5), (2, vals[2]+3.5), connectionstyle="arc3,rad=-0.35",
            arrowstyle="-|>", mutation_scale=18, lw=2.2, color=C["green"]))
ax.text(1, max(vals)+9, f"WebP saves -{bw_saved_day:.0f} GB/day  (-{bw_saved_yr:.1f} TB/year)", ha="center",
        color=C["green"], fontweight="bold", fontsize=11)
caption(fig, "Upper bound (cold cache). Browser and CDN caching strongly reduce real traffic.")
save(fig, "01-bandwidth.png")

# ---- FIG 02 — disk change vs today -----------------------------------------
x = np.arange(len(scen)); w = 0.36
fig, ax = plt.subplots(figsize=(7.4, 4.5))
o  = [opt_change[s]  for s in scen]; ww = [webp_change[s] for s in scen]
b1 = ax.bar(x-w/2, o,  w, label="Optimize, no WebP", color=C["blue"], edgecolor="white")
b2 = ax.bar(x+w/2, ww, w, label="With WebP",         color=C["gold"], edgecolor="white")
ax.axhline(0, color="#444", lw=1); ax.set_xticks(x); ax.set_xticklabels(scen)
ax.set_ylabel("Change in GB vs current state")
ax.set_title("Disk-space change vs today\n(negative = space reclaimed, positive = space added)", fontweight="bold", fontsize=13.5)
for b, v in zip(b1, o):  ax.text(b.get_x()+b.get_width()/2, v-0.6, f"{v:+.1f}", ha="center", va="top", fontsize=10, color=C["blue"])
for b, v in zip(b2, ww): ax.text(b.get_x()+b.get_width()/2, v+0.6, f"{v:+.1f}", ha="center", va="bottom", fontsize=10, color="#9a6a00")
ax.legend(loc="upper right", framealpha=0.9)
caption(fig, "Optimizing originals frees a little disk; generating WebP costs much more - the point of WebP is bandwidth, not disk.")
save(fig, "02-disk-change.png")

# ---- FIG 03 — disk occupied ------------------------------------------------
fig, ax = plt.subplots(figsize=(7.4, 4.5)); w = 0.26
before = [ORIGINALS_GB]*len(scen); optv = [opt_disk[s] for s in scen]; webpv = [webp_disk[s] for s in scen]
ax.bar(x-w, before, w, label="Before (current)",  color=C["gray"], edgecolor="white")
ax.bar(x,   optv,   w, label="Optimize, no WebP", color=C["blue"], edgecolor="white")
ax.bar(x+w, webpv,  w, label="With WebP",         color=C["gold"], edgecolor="white")
ax.set_ylim(0, 100); ax.set_xticks(x); ax.set_xticklabels(scen); ax.set_ylabel("Gigabytes on disk (GB)")
ax.set_title(f"Disk space occupied — PNG + JPEG [+ WebP]\nFallout wiki ({ORIGINALS_GB:.2f} GB of originals today)", fontweight="bold", fontsize=13.5)
for xi, (a, bb, c) in zip(x, zip(before, optv, webpv)):
    ax.text(xi-w, a+1.2, f"{a:.1f}", ha="center", fontsize=9.5)
    ax.text(xi,   bb+1.2, f"{bb:.1f}", ha="center", fontsize=9.5)
    ax.text(xi+w, c+1.2, f"{c:.1f}", ha="center", fontsize=9.5)
ax.legend(loc="upper center", ncol=3, framealpha=0.9, fontsize=10)
caption(fig, "WebP files are stored in addition to the originals, so they increase disk usage.")
save(fig, "03-disk-occupied.png")

# ---- FIG 04 — the trade-off (median) ---------------------------------------
disk_one_time = webp_change["Median"]
fig, ax = plt.subplots(figsize=(7.4, 4.5)); ax2 = ax.twinx()
ax.bar([0], [disk_one_time], 0.5, color=C["red"], edgecolor="white")
ax2.bar([1], [bw_saved_yr], 0.5, color=C["green"], edgecolor="white")
ax.set_xticks([0, 1]); ax.set_xticklabels(["Disk space\n(one-time)", "Bandwidth\n(per year)"])
ax.set_ylim(0, 30); ax2.set_ylim(0, 12)
ax.set_ylabel("Disk cost - GB added (one-time)", color=C["red"]); ax2.set_ylabel("Bandwidth saved - TB per year", color=C["green"])
ax.tick_params(axis="y", colors=C["red"]); ax2.tick_params(axis="y", colors=C["green"])
ax.text(0, disk_one_time+0.5, f"+{disk_one_time:.1f} GB", ha="center", color=C["red"], fontweight="bold", fontsize=12)
ax2.text(1, bw_saved_yr+0.2, f"-{bw_saved_yr:.1f} TB/year", ha="center", color=C["green"], fontweight="bold", fontsize=12)
ax.set_title("The WebP trade-off (median range)\nA one-time disk cost vs a recurring bandwidth saving", fontweight="bold", fontsize=13.5)
caption(fig, f"Enabling WebP adds ~{disk_one_time:.0f} GB on disk once, and saves ~{bw_saved_yr:.1f} TB of visitor bandwidth every year (before caching).")
save(fig, "04-tradeoff.png")

# ---- FIG 05 — cache reality (the upper-bound caveat, illustrative) ----------
hit = np.array([0, 50, 85, 95]); real = bw_saved_yr * (1 - hit/100)
fig, ax = plt.subplots(figsize=(7.4, 4.5))
bars = ax.bar([f"{h}%" for h in hit], real, color=[C["red"], C["gold"], C["teal"], C["green"]], width=0.6, edgecolor="white")
ax.set_ylabel("Bandwidth actually saved (TB/year)"); ax.set_xlabel("Assumed browser + CDN cache-hit ratio")
ax.set_title("Why the TB/year figure is an UPPER BOUND\nReal saving scales down with caching (the -43% ratio holds)", fontweight="bold", fontsize=13)
for b, v in zip(bars, real): ax.text(b.get_x()+b.get_width()/2, v+0.12, f"{v:.1f} TB/yr", ha="center", va="bottom", fontsize=10)
caption(fig, "Illustrative: images are highly cacheable, so real egress is a fraction of the cold-cache figure. The PER-IMAGE saving is unchanged.")
save(fig, "05-cache-reality.png")

# ============================================================================
# (B) MEASURED on the test box (this session). Values are real measurements.
# ============================================================================
CAT = {"JPEG photo\n-> WebP q85":          (595.0, 198.9, 66.6),
       "PNG UI/diagram\n-> WebP lossless":  (26.2,   4.2, 84.2),
       "Large PNG photo\n-> WebP lossless": (24603.1, 6753.2, 72.6),
       "Animated GIF\n-> animated WebP":    (18.1,   7.7, 57.5)}
# ---- FIG 06 — measured WebP saving by category -----------------------------
fig, ax = plt.subplots(figsize=(7.6, 4.5))
names = list(CAT.keys()); save_pct = [CAT[k][2] for k in names]
bars = ax.bar(names, save_pct, color=[C["blue"], C["teal"], C["purple"], C["gold"]], width=0.6, edgecolor="white")
ax.set_ylim(0, 100); ax.set_ylabel("Size reduction vs the file itself (%)")
ax.set_title("Per-file WebP compression - MEASURED\n(this is per-file size, NOT served bandwidth - see the model charts)", fontweight="bold", fontsize=12.5)
for b, v in zip(bars, save_pct): ax.text(b.get_x()+b.get_width()/2, v+1, f"-{v:.0f}%", ha="center", va="bottom", fontsize=11, fontweight="bold", color=C["green"])
caption(fig, "Big % on a 24 MB original is real but rarely served; pages embed thumbnails. Bandwidth is modelled separately (Figs 1-5).")
save(fig, "06-webp-by-category.png")

# ---- FIG 07 — AVIF vs WebP (photo 2000x1500) -------------------------------
fig, ax = plt.subplots(figsize=(6.6, 4.5))
v = [201.0, 44.0]; bars = ax.bar(["WebP q85", "AVIF q50"], v, color=[C["gold"], C["green"]], width=0.5, edgecolor="white")
ax.set_ylabel("File size (KB)"); ax.set_ylim(0, 230)
ax.set_title("AVIF vs WebP (experimental) - same photo 2000x1500", fontweight="bold", fontsize=13)
for b, val in zip(bars, v): ax.text(b.get_x()+b.get_width()/2, val+3, f"{val:.0f} KB", ha="center", va="bottom", fontsize=11, fontweight="bold")
ax.text(1, 70, "-78% vs WebP", ha="center", color=C["green"], fontweight="bold", fontsize=12)
caption(fig, "AVIF is much smaller but slower; on the test box vips lacked AV1 -> fell back to WebP (the extension probes AV1 first).")
save(fig, "07-avif-vs-webp.png")

# ---- FIG 08 — WebP lossless quality by engine (flat UI capture) ------------
fig, ax = plt.subplots(figsize=(6.6, 4.5))
v = [6.9, 6.9, 19.0]; bars = ax.bar(["libvips", "Imagick", "GD"], v, color=[C["green"], C["teal"], C["red"]], width=0.55, edgecolor="white")
ax.set_ylabel("Lossless WebP size (KB)"); ax.set_ylim(0, 22)
ax.set_title("Lossless WebP - the ENGINE matters\nSame flat-UI capture, true lossless vs GD's near-lossless", fontweight="bold", fontsize=12.5)
for b, val in zip(bars, v): ax.text(b.get_x()+b.get_width()/2, val+0.3, f"{val:.1f} KB", ha="center", va="bottom", fontsize=11, fontweight="bold")
ax.text(2, 20.2, "~2.7x larger", ha="center", color=C["red"], fontweight="bold", fontsize=11)
caption(fig, "GD has no true lossless mode (quality 100 = near-lossless); prefer Imagick/libvips for crisp PNG/UI.")
save(fig, "08-webp-lossless-engine.png")

# ---- FIG 09 — GIF lossless (gifsicle) --------------------------------------
fig, ax = plt.subplots(figsize=(6.6, 4.5))
v = [26.8, 10.8]; bars = ax.bar(["Before", "gifsicle -O3"], v, color=[C["gray"], C["gold"]], width=0.5, edgecolor="white")
ax.set_ylabel("GIF size (KB)"); ax.set_ylim(0, 30)
ax.set_title("Lossless GIF optimization (gifsicle -O3)\nAnimation, frames, timing & loop preserved", fontweight="bold", fontsize=12.5)
for b, val in zip(bars, v): ax.text(b.get_x()+b.get_width()/2, val+0.4, f"{val:.1f} KB", ha="center", va="bottom", fontsize=11, fontweight="bold")
ax.text(1, 14, "-60%  (~0.02 s)", ha="center", color=C["green"], fontweight="bold", fontsize=12)
caption(fig, "Strictly lossless and animation-safe; near-free CPU. Corpus average across GIFs: -66%.")
save(fig, "09-gif-gifsicle.png")

# ---- FIG 10 — PNG original passes ------------------------------------------
fig, ax = plt.subplots(figsize=(7.0, 4.5))
stages = ["Original", "1st pass\n(lossless)", "+ 2nd pass\nzopflipng x15"]; v = [41.9, 32.5, 28.6]
bars = ax.bar(stages, v, color=[C["gray"], C["blue"], C["green"]], width=0.55, edgecolor="white")
ax.set_ylabel("PNG size (KB)"); ax.set_ylim(0, 48)
ax.set_title("Original PNG - first & second lossless passes (disk only)", fontweight="bold", fontsize=13)
for b, val in zip(bars, v): ax.text(b.get_x()+b.get_width()/2, val+0.5, f"{val:.1f} KB", ha="center", va="bottom", fontsize=11)
ax.annotate("-22%", (1, 35), ha="center", color=C["blue"], fontweight="bold")
ax.annotate("-12% more\n(but ~13.7 s!)", (2, 31), ha="center", color=C["red"], fontweight="bold", fontsize=9.5)
caption(fig, "Second pass (zopflipng) is slow for a marginal disk-only gain - prefer oxipng, run off-traffic.")
save(fig, "10-png-passes.png")

# ---- FIG 11 — engine peak memory (libvips vs ImageMagick) ------------------
fig, ax = plt.subplots(figsize=(7.4, 4.5)); xx = np.arange(2); w = 0.36
vips_mem = [98, 31]; im_mem = [177, 162]
ax.bar(xx-w/2, vips_mem, w, label="libvips", color=C["green"], edgecolor="white")
ax.bar(xx+w/2, im_mem,  w, label="ImageMagick", color=C["red"], edgecolor="white")
ax.set_xticks(xx); ax.set_xticklabels(["Large image\n12 Mpx -> WebP", "Thumbnail 320 px\n(resize + WebP)"])
ax.set_ylabel("Peak memory (MB)"); ax.set_ylim(0, 200)
ax.set_title("Engine peak memory - libvips vs ImageMagick (MEASURED)", fontweight="bold", fontsize=13)
for i, (a, b) in enumerate(zip(vips_mem, im_mem)):
    ax.text(i-w/2, a+3, f"{a} MB", ha="center", fontsize=10, color=C["green"], fontweight="bold")
    ax.text(i+w/2, b+3, f"{b} MB", ha="center", fontsize=10, color=C["red"])
ax.legend(framealpha=0.9)
caption(fig, "~1.8x less on a large image, ~5x less on the thumbnail pipeline (the backfill / on-the-fly hot path).")
save(fig, "11-engine-memory.png")

# ---- FIG 12 — engine time (log scale, the thumbnail gap is huge) -----------
fig, ax = plt.subplots(figsize=(7.4, 4.5))
vips_t = [2.2, 0.03]; im_t = [1.9, 1.2]
ax.bar(xx-w/2, vips_t, w, label="libvips", color=C["green"], edgecolor="white")
ax.bar(xx+w/2, im_t,  w, label="ImageMagick", color=C["red"], edgecolor="white")
ax.set_xticks(xx); ax.set_xticklabels(["Large image\n12 Mpx -> WebP", "Thumbnail 320 px\n(resize + WebP)"])
ax.set_yscale("log"); ax.set_ylabel("Wall time (s, log)"); ax.set_ylim(0.02, 4)
ax.set_title("Engine wall time - libvips vs ImageMagick (MEASURED)", fontweight="bold", fontsize=13)
for i, (a, b) in enumerate(zip(vips_t, im_t)):
    ax.text(i-w/2, a*1.1, f"{a} s", ha="center", fontsize=10, color=C["green"], fontweight="bold")
    ax.text(i+w/2, b*1.1, f"{b} s", ha="center", fontsize=10, color=C["red"])
ax.legend(framealpha=0.9)
caption(fig, "Comparable on a single large image; libvips is ~40x faster on thumbnails (it need not decode the whole image).")
save(fig, "12-engine-time.png")

# ---- FIG 13 — throughput by wiki profile (single core) ---------------------
fig, ax = plt.subplots(figsize=(7.2, 4.5))
prof = ["Low avg\n(media-light)", "Medium\n(balanced)", "High avg\n(media-rich)"]; ipm = [245, 150, 65]
bars = ax.bar(prof, ipm, color=[C["green"], C["teal"], C["gold"]], width=0.55, edgecolor="white")
ax.set_ylabel("Throughput (images / minute, 1 core)"); ax.set_ylim(0, 280)
ax.set_title("Processing throughput by wiki profile - libvips, single core\n(bandwidth saving stays ~-71/-72% across all three)", fontweight="bold", fontsize=12.5)
for b, val in zip(bars, ipm): ax.text(b.get_x()+b.get_width()/2, val+4, f"~{val}/min", ha="center", va="bottom", fontsize=11, fontweight="bold")
caption(fig, "What varies with a wiki's media richness is throughput, not the bandwidth ratio. Parallelize the backfill to multiply.")
save(fig, "13-throughput-profile.png")

# ---- summary ---------------------------------------------------------------
print("FIGURES:", len([f for f in os.listdir(OUT) if f.endswith('.png')]))
print(f"BW/day raw={vals[0]:.1f} opt={vals[1]:.1f} webp={vals[2]:.1f} | saved {bw_saved_day:.1f} GB/day = {bw_saved_yr:.1f} TB/yr (WebP vs raw -{(1-webp_kb/raw_kb)*100:.0f}%)")
for s in scen:
    print(f"DISK {s.splitlines()[0]:10s} opt {opt_disk[s]:.1f} ({opt_change[s]:+.1f}) | webp {webp_disk[s]:.1f} ({webp_change[s]:+.1f})")
