from PIL import Image, ImageDraw, ImageFont, ImageFilter
import numpy as np

NAVY_TOP = (8, 29, 50)
NAVY_BOTTOM = (17, 55, 88)
CORAL = (255, 107, 74)
WHITE = (255, 255, 255)

FONT_BOLD = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf"
FONT_REGULAR = "/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf"
FONT_ITALIC = "/usr/share/fonts/truetype/dejavu/DejaVuSans-Oblique.ttf"

def vgradient(size, top, bottom):
    w, h = size
    base = Image.new("RGB", (1, h))
    for y in range(h):
        t = y / max(h - 1, 1)
        r = int(top[0] + (bottom[0] - top[0]) * t)
        g = int(top[1] + (bottom[1] - top[1]) * t)
        b = int(top[2] + (bottom[2] - top[2]) * t)
        base.putpixel((0, y), (r, g, b))
    return base.resize((w, h))

def rounded_mask(size, radius):
    mask = Image.new("L", size, 0)
    d = ImageDraw.Draw(mask)
    d.rounded_rectangle([0, 0, size[0]-1, size[1]-1], radius=radius, fill=255)
    return mask

def draw_install_arrow_raw(draw, cx, cy, s, color):
    shaft_w = s * 0.16
    draw.rectangle([cx - shaft_w/2, cy - s*0.50, cx + shaft_w/2, cy + s*0.02], fill=color)
    head_w = s * 0.52
    draw.polygon([(cx - head_w/2, cy), (cx + head_w/2, cy), (cx, cy + s*0.40)], fill=color)
    tray_w = s * 0.86
    tray_bottom = cy + s * 0.60
    tray_top = tray_bottom - s * 0.26
    tray_thick = s * 0.13
    draw.rectangle([cx - tray_w/2, tray_top, cx - tray_w/2 + tray_thick, tray_bottom], fill=color)
    draw.rectangle([cx + tray_w/2 - tray_thick, tray_top, cx + tray_w/2, tray_bottom], fill=color)
    draw.rectangle([cx - tray_w/2, tray_bottom - tray_thick, cx + tray_w/2, tray_bottom], fill=color)

def draw_centered_icon(canvas_rgba, center_xy, scale, color, pad=4):
    work = max(int(scale * 3), 64)
    scratch = Image.new("RGBA", (work, work), (0, 0, 0, 0))
    sdraw = ImageDraw.Draw(scratch)
    draw_install_arrow_raw(sdraw, work/2, work/2, scale, color + (255,) if len(color) == 3 else color)
    bbox = scratch.getbbox()
    if bbox is None:
        return
    bbox = (bbox[0]-pad, bbox[1]-pad, bbox[2]+pad, bbox[3]+pad)
    cropped = scratch.crop(bbox)
    bw, bh = cropped.size
    cx, cy = center_xy
    paste_x = int(round(cx - bw/2))
    paste_y = int(round(cy - bh/2))
    canvas_rgba.alpha_composite(cropped, (paste_x, paste_y))

def line_height(font, dummy_draw, text="Ag"):
    """Real ink height of a line of text in this font, via actual glyph bbox."""
    l, t, r, b = dummy_draw.textbbox((0, 0), text, font=font)
    return b - t, t  # (height, top_offset_from_baseline_origin)

# ============================================================
# LOGO (unchanged from the fixed version - confirmed centered)
# ============================================================
SIZE = 512
logo = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))
tile_rgb = vgradient((SIZE, SIZE), NAVY_TOP, NAVY_BOTTOM)
mask = rounded_mask((SIZE, SIZE), radius=int(SIZE * 0.22))
mask = mask.point(lambda p: 255 if p >= 128 else 0)
logo.paste(tile_rgb, (0, 0), mask)
draw_centered_icon(logo, (SIZE/2, SIZE/2), SIZE*0.44, CORAL)
logo.save("/home/claude/brand/logo.png")
print("logo.png saved", logo.size)

# ============================================================
# BANNER — each column (badge+caption / title+features) is
# measured for real, then EACH is independently vertically
# centered on the canvas - no shared guessed height.
# ============================================================
BW, BH = 1200, 525
banner = vgradient((BW, BH), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")

overlay = Image.new("RGBA", (BW, BH), (0, 0, 0, 0))
odraw = ImageDraw.Draw(overlay)
odraw.ellipse([BW-420, -180, BW+120, 320], fill=(255, 107, 74, 55))
odraw.ellipse([-200, BH-320, 260, BH+180], fill=(255, 255, 255, 26))
odraw.ellipse([BW*0.35, BH*0.55, BW*0.35+520, BH*0.55+420], fill=(30, 80, 120, 60))
overlay = overlay.filter(ImageFilter.GaussianBlur(55))
banner = Image.alpha_composite(banner, overlay)

sheen = Image.new("RGBA", (BW, BH), (0, 0, 0, 0))
sdraw = ImageDraw.Draw(sheen)
sdraw.polygon([(BW*0.55, 0), (BW*0.78, 0), (BW*0.45, BH), (BW*0.22, BH)], fill=(255, 255, 255, 10))
sheen = sheen.filter(ImageFilter.GaussianBlur(30))
banner = Image.alpha_composite(banner, sheen)

meas = ImageDraw.Draw(Image.new("RGB", (10, 10)))  # dummy for measuring only

cap_font = ImageFont.truetype(FONT_BOLD, 22)
fg_font = ImageFont.truetype(FONT_BOLD, 58)
tagline_font = ImageFont.truetype(FONT_ITALIC, 25)
head_font = ImageFont.truetype(FONT_BOLD, 25)
desc_font = ImageFont.truetype(FONT_REGULAR, 20)

badge_size = 210
GAP_BADGE_CAPTION = 20
cap_h, cap_top = line_height(cap_font, meas, "Component")

# --- LEFT COLUMN: real total height = badge + gap + caption ink height ---
left_col_h = badge_size + GAP_BADGE_CAPTION + cap_h
badge_x = 90
badge_y = (BH - left_col_h) // 2

# drop shadow under the badge
shadow = Image.new("RGBA", (BW, BH), (0, 0, 0, 0))
shdraw = ImageDraw.Draw(shadow)
shdraw.rounded_rectangle(
    [badge_x + 10, badge_y + 16, badge_x + badge_size + 10, badge_y + badge_size + 16],
    radius=int(badge_size*0.24), fill=(0, 0, 0, 130)
)
shadow = shadow.filter(ImageFilter.GaussianBlur(18))
banner = Image.alpha_composite(banner, shadow)

badge_tile = vgradient((badge_size, badge_size), (18, 45, 72), (30, 70, 105)).convert("RGBA")
bmask = rounded_mask((badge_size, badge_size), radius=int(badge_size*0.24))
bmask = bmask.point(lambda p: 255 if p >= 128 else 0)
banner.paste(badge_tile, (badge_x, badge_y), bmask)
draw_centered_icon(banner, (badge_x + badge_size/2, badge_y + badge_size/2), badge_size*0.5, CORAL)

draw = ImageDraw.Draw(banner)
cw = draw.textlength("Component", font=cap_font)
cap_y = badge_y + badge_size + GAP_BADGE_CAPTION - cap_top
draw.text((badge_x + badge_size/2 - cw/2, cap_y), "Component", font=cap_font, fill=(190, 205, 220, 255))

# --- RIGHT COLUMN: measure every line's real ink height, stack, center as one block ---
title_x = 350
GAP_TITLE_TAGLINE = 22
GAP_TAGLINE_DIVIDER = 24
DIVIDER_H = 3
GAP_DIVIDER_FEATURES = 30
GAP_FEATURE_ROWS = 22
GAP_HEAD_DESC = 8

title_h, title_top = line_height(fg_font, meas, "FG Extension Manager")
tagline_h, tagline_top = line_height(tagline_font, meas, "Discover, install and update your GitHub extensions automatically")

features = [
    ("GitHub topic discovery", "No manual list to maintain - tag a repo and it appears"),
    ("Native Joomla installer", "Install / Update / Uninstall via Joomla's own core APIs"),
    ("Smart compatibility checks", "Matches Joomla version, PHP version, and subfolder builds"),
]
head_h, head_top = line_height(head_font, meas, "Ag")
desc_h, desc_top = line_height(desc_font, meas, "Ag")
feature_row_h = head_h + GAP_HEAD_DESC + desc_h
features_total_h = feature_row_h * len(features) + GAP_FEATURE_ROWS * (len(features) - 1)

right_col_h = (title_h + GAP_TITLE_TAGLINE + tagline_h + GAP_TAGLINE_DIVIDER
               + DIVIDER_H + GAP_DIVIDER_FEATURES + features_total_h)

cursor_y = (BH - right_col_h) // 2

# title
ty = cursor_y - title_top
fg_w = draw.textlength("FG ", font=fg_font)
draw.text((title_x, ty), "FG", font=fg_font, fill=CORAL)
draw.text((title_x + fg_w, ty), "Extension Manager", font=fg_font, fill=WHITE)
cursor_y += title_h + GAP_TITLE_TAGLINE

# tagline
tgy = cursor_y - tagline_top
draw.text((title_x, tgy), "Discover, install and update your GitHub extensions automatically", font=tagline_font, fill=(210, 220, 232, 255))
cursor_y += tagline_h + GAP_TAGLINE_DIVIDER

# divider
draw.rectangle([title_x, cursor_y, title_x + 580, cursor_y + DIVIDER_H], fill=CORAL)
cursor_y += DIVIDER_H + GAP_DIVIDER_FEATURES

# features
for i, (head, desc) in enumerate(features):
    hy = cursor_y - head_top
    draw.ellipse([title_x, cursor_y + head_h/2 - 5, title_x + 11, cursor_y + head_h/2 + 6], fill=CORAL)
    draw.text((title_x + 26, hy), head, font=head_font, fill=WHITE)
    cursor_y += head_h + GAP_HEAD_DESC
    dy = cursor_y - desc_top
    draw.text((title_x + 26, dy), desc, font=desc_font, fill=(190, 205, 220, 255))
    cursor_y += desc_h
    if i < len(features) - 1:
        cursor_y += GAP_FEATURE_ROWS

banner_final = banner.convert("RGB")
banner_final.save("/home/claude/brand/banner.png")
print("banner.png saved", banner_final.size)
print(f"left col: y[{badge_y}..{badge_y+left_col_h}] h={left_col_h} center={badge_y+left_col_h/2:.1f} (canvas center={BH/2})")
print(f"right col: y[{(BH-right_col_h)//2}..{(BH-right_col_h)//2+right_col_h}] h={right_col_h} center={(BH-right_col_h)//2+right_col_h/2:.1f} (canvas center={BH/2})")

# ---- verify programmatically: find actual ink bounding box per column ----
arr = np.array(banner_final)
# left column region x in [60,320], right column region x in [340,1150]
def ink_bbox(region_arr, x_offset):
    # "ink" = pixels that differ noticeably from the navy background gradient
    bg = np.array(vgradient((1, BH), NAVY_TOP, NAVY_BOTTOM).convert("RGB"))[:,0,:]
    diff = np.abs(region_arr.astype(int) - bg[:, None, :].repeat(region_arr.shape[1], axis=1).astype(int)).sum(axis=2)
    mask = diff > 40
    ys, xs = np.where(mask)
    if len(ys) == 0:
        return None
    return ys.min(), ys.max()

left_region = arr[:, 60:340, :]
right_region = arr[:, 340:1180, :]
ly = ink_bbox(left_region, 60)
ry = ink_bbox(right_region, 340)
print(f"MEASURED left ink y-range: {ly}, center={(ly[0]+ly[1])/2 if ly else None}")
print(f"MEASURED right ink y-range: {ry}, center={(ry[0]+ry[1])/2 if ry else None}")
print(f"canvas center = {BH/2}")
