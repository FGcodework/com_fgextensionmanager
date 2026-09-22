from PIL import Image, ImageDraw, ImageFont, ImageFilter
import math

NAVY_TOP = (8, 29, 50)      # #081D32
NAVY_BOTTOM = (17, 55, 88)  # #113758
CORAL = (255, 107, 74)      # #FF6B4A
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

def draw_install_arrow(draw, cx, cy, scale, color):
    # Downward arrow into a tray - "install/download" pictograph
    s = scale
    # Arrow shaft
    shaft_w = s * 0.16
    draw.rectangle([cx - shaft_w/2, cy - s*0.55, cx + shaft_w/2, cy + s*0.05], fill=color)
    # Arrow head (triangle)
    head_w = s * 0.5
    draw.polygon([
        (cx - head_w/2, cy),
        (cx + head_w/2, cy),
        (cx, cy + s*0.42),
    ], fill=color)
    # Tray (open box) below
    tray_w = s * 0.85
    tray_y = cy + s * 0.62
    tray_thick = s * 0.13
    # left leg
    draw.polygon([
        (cx - tray_w/2, tray_y - s*0.28),
        (cx - tray_w/2 + tray_thick, tray_y - s*0.28),
        (cx - tray_w/2 + tray_thick, tray_y),
        (cx - tray_w/2, tray_y),
    ], fill=color)
    # right leg
    draw.polygon([
        (cx + tray_w/2 - tray_thick, tray_y - s*0.28),
        (cx + tray_w/2, tray_y - s*0.28),
        (cx + tray_w/2, tray_y),
        (cx + tray_w/2 - tray_thick, tray_y),
    ], fill=color)
    # bottom bar
    draw.rectangle([cx - tray_w/2, tray_y - tray_thick, cx + tray_w/2, tray_y], fill=color)

# ============================================================
# STANDALONE LOGO — 512x512, flat, no shadow, binary alpha
# ============================================================
SIZE = 512
logo = Image.new("RGBA", (SIZE, SIZE), (0, 0, 0, 0))
tile_rgb = vgradient((SIZE, SIZE), NAVY_TOP, NAVY_BOTTOM)
mask = rounded_mask((SIZE, SIZE), radius=int(SIZE * 0.22))
# force binary alpha (no soft edges/halo - lesson learned from fgofflineipwhitelist)
mask = mask.point(lambda p: 255 if p >= 128 else 0)
logo.paste(tile_rgb, (0, 0), mask)

draw = ImageDraw.Draw(logo)
draw_install_arrow(draw, SIZE/2, SIZE/2 + SIZE*0.03, SIZE*0.44, CORAL)

logo.save("/home/claude/brand/logo.png")
print("logo.png saved", logo.size)

# ============================================================
# BANNER — 1200x525, JED-style layout
# ============================================================
BW, BH = 1200, 525
banner = vgradient((BW, BH), NAVY_TOP, NAVY_BOTTOM).convert("RGBA")

# soft blurred decorative circles
overlay = Image.new("RGBA", (BW, BH), (0, 0, 0, 0))
odraw = ImageDraw.Draw(overlay)
odraw.ellipse([BW-260, -120, BW+140, 280], fill=(255, 107, 74, 40))
odraw.ellipse([-150, BH-260, 220, BH+150], fill=(255, 255, 255, 18))
overlay = overlay.filter(ImageFilter.GaussianBlur(40))
banner = Image.alpha_composite(banner, overlay)

draw = ImageDraw.Draw(banner)

# --- icon badge top-left ---
badge_size = 150
badge_x, badge_y = 70, 70
badge_tile = vgradient((badge_size, badge_size), (18, 45, 72), (30, 70, 105)).convert("RGBA")
bmask = rounded_mask((badge_size, badge_size), radius=int(badge_size*0.24))
bmask = bmask.point(lambda p: 255 if p >= 128 else 0)
banner.paste(badge_tile, (badge_x, badge_y), bmask)
bdraw = ImageDraw.Draw(banner)
draw_install_arrow(bdraw, badge_x + badge_size/2, badge_y + badge_size/2 + badge_size*0.03, badge_size*0.5, CORAL)

# caption under badge
cap_font = ImageFont.truetype(FONT_BOLD, 20)
caption = "Component"
cw = draw.textlength(caption, font=cap_font)
draw.text((badge_x + badge_size/2 - cw/2, badge_y + badge_size + 18), caption, font=cap_font, fill=(190, 205, 220, 255))

# --- title ---
title_x = 270
title_y = 96
fg_font = ImageFont.truetype(FONT_BOLD, 56)
rest_font = ImageFont.truetype(FONT_BOLD, 56)
draw.text((title_x, title_y), "FG", font=fg_font, fill=CORAL)
fg_w = draw.textlength("FG ", font=fg_font)
draw.text((title_x + fg_w, title_y), "Extension Manager", font=rest_font, fill=WHITE)

# --- tagline ---
tagline_font = ImageFont.truetype(FONT_ITALIC, 24)
draw.text((title_x, title_y + 74), "Discover, install and update your GitHub extensions automatically", font=tagline_font, fill=(210, 220, 232, 255))

# --- coral divider ---
div_y = title_y + 122
draw.rectangle([title_x, div_y, title_x + 560, div_y + 3], fill=CORAL)

# --- feature bullets ---
features = [
    ("GitHub topic discovery", "No manual list to maintain - tag a repo and it appears"),
    ("Native Joomla installer", "Install / Update / Uninstall via Joomla's own core APIs"),
    ("Smart compatibility checks", "Matches Joomla version, PHP version, and subfolder builds"),
]
fy = div_y + 34
head_font = ImageFont.truetype(FONT_BOLD, 24)
desc_font = ImageFont.truetype(FONT_REGULAR, 19)
for head, desc in features:
    draw.ellipse([title_x, fy + 10, title_x + 10, fy + 20], fill=CORAL)
    draw.text((title_x + 24, fy), head, font=head_font, fill=WHITE)
    draw.text((title_x + 24, fy + 32), desc, font=desc_font, fill=(190, 205, 220, 255))
    fy += 78

banner = banner.convert("RGB")
banner.save("/home/claude/brand/banner.png")
print("banner.png saved", banner.size)
