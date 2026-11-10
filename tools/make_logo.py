"""Erzeugt das Logo und die LoxBerry-Plugin-Icons im Stil von Senec2Lox / Nut2Lox / Fronius2Lox.

Aufruf (aus dem Repo-Ordner):  python tools/make_logo.py
Benoetigt Pillow. Schrift: Arial Bold (Windows) bzw. DejaVu Sans Bold.

Ausgabe:
  docs/logo/bambulab2lox.png                     gruener, runder LoxBerry-Stil
  bambulab2lox/icons/icon_{64,128,256,512}.png    dasselbe als Plugin-Icons

Links der Bambu Lab H2C (eigene, schematische Darstellung nach dem Foto, ohne Herstellerlogo und Schrift: dunkles Gehaeuse, AMS 2 Pro mit vier Spulen, AMS HT, Display, Glastuer),
rechts der Miniserver (wie bei Senec2Lox), dazwischen ein Pfeil, darunter der Name "Bambulab2Lox".
"""
import math
import os
from PIL import Image, ImageDraw, ImageFont, ImageOps

ROOT = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
SS = 4  # Supersampling

G_LIGHT, G_DARK = (141, 198, 63), (63, 125, 16)        # Gruenverlauf
LABEL = "Bambulab2Lox"
LABEL_WIDTH = 310      # Breite der Beschriftung im 512er-Raster
LABEL_BASE = 352       # Grundlinie
OX, OY = -8, 29         # Verschiebung der ganzen Gruppe (Drucker, Miniserver, Name): Mittelpunkt der Gruppe (x 99..429, y 101..352) liegt im Kreismittelpunkt (256, 256)

PRINTER = (102, 104, 208, 272)             # x1, y1, x2, y2 (Bambu Lab H2C, Hochformat wie auf dem Foto)
MINISERVER = (270, 152, 426, 240)          # x1, y1, x2, y2 (gruenes Gehaeuse, Draufsicht)
LINK_Y = 196                               # Verbindungslinie (Mitte beider Geraete)
LINK_X = (214, 256)


def miniserver_shapes():
    """Miniserver als gruenes Gehaeuse (Draufsicht), identisch zu Senec2Lox.

    Formen: ("rrect", x1, y1, x2, y2, radius, rgba) | ("circle", cx, cy, r, rgba) | ("ellipse", cx, cy, rx, ry, rgba) | ("line", x1, y1, x2, y2, breite, rgba)
    """
    x1, y1, x2, y2 = MINISERVER
    white = (255, 255, 255, 255)
    green = (104, 190, 64, 255)
    dark = (38, 48, 38, 255)
    teal, orange, blue = (38, 172, 172, 255), (242, 140, 28, 255), (40, 120, 200, 255)
    red = (226, 42, 72, 255)
    sh = []
    sh.append(("rrect", x1 + 70, y1 - 15, x1 + 98, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 72, y1 - 13, x1 + 96, y1 + 6, 0, teal))
    sh.append(("rrect", x1 + 116, y1 - 15, x1 + 134, y1 + 6, 0, white))
    sh.append(("rrect", x1 + 118, y1 - 13, x1 + 132, y1 + 6, 0, orange))
    for i, c in enumerate((orange, blue, white)):
        tx = x1 + 12 + i * 15
        sh.append(("rrect", tx - 2, y2 - 4, tx + 10, y2 + 16, 0, white))
        sh.append(("rrect", tx, y2 - 4, tx + 8, y2 + 14, 0, c))
    sh.append(("rrect", x1 + 78, y2 - 6, x2 - 6, y2 + 12, 0, white))
    sh.append(("rrect", x1 + 80, y2 - 4, x2 - 8, y2 + 10, 0, red))
    for i in range(12):
        sh.append(("circle", x1 + 88 + i * 5.6, y2 + 3, 1.6, white))
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 2, white))
    sh.append(("rrect", x1, y1, x2, y2, 0, green))
    sh.append(("rrect", x1 + 52, y1 + 36, x2 - 14, y1 + 46, 0, dark))
    sh.append(("rrect", x1 + 60, y1 + 39, x1 + 92, y1 + 43, 0, (200, 210, 200, 255)))
    sh.append(("rrect", x2 - 50, y1 + 39, x2 - 22, y1 + 43, 0, (120, 135, 120, 255)))
    return sh


def printer_shapes():
    """Bambu Lab H2C schematisch nach dem Foto (ohne Herstellerlogo und ohne Schrift): oben die beiden Filamentspeicher nebeneinander,
    links das schmale AMS HT, rechts das breite AMS 2 Pro mit vier farbigen Spulen (weiss, blau, gruen, rot) auf vier Halterungen;
    darunter der dunkle Drucker mit Display links oben, grosser Glastuer, Werkzeugkopf an der Querachse und Druckbett unten."""
    x1, y1, x2, y2 = PRINTER
    w, h = x2 - x1, y2 - y1
    white = (255, 255, 255, 255)
    body = (46, 48, 52, 255)
    body_hi = (92, 96, 102, 255)
    glass = (18, 20, 24, 255)
    glass_hi = (40, 44, 50, 255)
    metal = (176, 180, 186, 255)
    sh = []
    sh.append(("rrect", x1 - 3, y1 - 3, x2 + 3, y2 + 3, 8, white))                       # weisser Rahmen wie bei den anderen Logos
    sh.append(("rrect", x1, y1, x2, y2, 6, (214, 218, 222, 255)))                         # Hintergrund der Speicherreihe
    top = y1 + h * 0.285                                                                  # Oberkante des Druckers
    # Drucker (dunkles Gehaeuse) unten
    sh.append(("rrect", x1, top, x2, y2, 6, body))
    sh.append(("rrect", x1, top, x2, top + 8, 0, body))
    sh.append(("rrect", x1, top, x1 + 5, y2 - 4, 2, body_hi))                            # linke Saeule
    sh.append(("rrect", x2 - 5, top, x2, y2 - 4, 2, body_hi))                            # rechte Saeule
    # Display links oben (dunkel, mit hellem Bildschirm und Tastenreihe)
    sh.append(("rrect", x1 + 8, top + 4, x1 + w * 0.34, top + h * 0.095, 2, (14, 16, 20, 255)))
    sh.append(("rrect", x1 + 10, top + 6, x1 + w * 0.34 - 2, top + h * 0.075, 1, (96, 150, 204, 255)))
    # Glastuer
    gx1, gx2, gy1, gy2 = x1 + 8, x2 - 8, top + h * 0.115, y2 - 6
    sh.append(("rrect", gx1, gy1, gx2, gy2, 3, glass))
    sh.append(("rrect", gx1 + 1.5, gy1 + 1.5, gx2 - 1.5, gy1 + 5, 1, glass_hi))
    sh.append(("line", gx1 + 10, gy2 - 3, gx1 + 34, gy1 + 8, 1.6, (36, 40, 46, 255)))   # Spiegelung im Glas
    sh.append(("line", gx1 + 22, gy2 - 3, gx1 + 46, gy1 + 8, 1.6, (30, 34, 40, 255)))
    # Querachse mit Werkzeugkopf (orange Duese)
    by = gy1 + (gy2 - gy1) * 0.25
    sh.append(("rrect", gx1 + 2, by, gx2 - 2, by + 4, 1, metal))
    cx = x1 + w * 0.5
    sh.append(("rrect", cx - 8, by - 2, cx + 8, by + 14, 2, (214, 218, 224, 255)))
    sh.append(("rrect", cx - 3, by + 14, cx + 3, by + 19, 1, (238, 96, 40, 255)))
    # Druckbett unten
    sh.append(("rrect", gx1 + 3, gy2 - 17, gx2 - 3, gy2 - 11, 1.5, metal))
    sh.append(("rrect", gx1 + 8, gy2 - 11, gx2 - 8, gy2 - 5, 1, (100, 104, 110, 255)))
    # AMS HT links: schmale dunkle Einheit mit hellem Deckel und weisser Spule, Schriftfeld als Balken
    hx1, hx2, hy1, hy2 = x1 + 3, x1 + w * 0.215, y1 + 3, top - 2
    sh.append(("rrect", hx1, hy1, hx2, hy2, 3, (34, 36, 40, 255)))
    sh.append(("rrect", hx1 + 1, hy1 + 1, hx2 - 1, hy1 + h * 0.13, 2, (178, 184, 190, 255)))
    sh.append(("rrect", hx1 + 3, hy1 + 3, hx2 - 3, hy1 + h * 0.11, 1.5, (240, 240, 240, 255)))
    sh.append(("rrect", hx1 + 3, hy2 - 12, hx2 - 3, hy2 - 9, 1, (150, 156, 162, 255)))
    sh.append(("rrect", hx1 + 3, hy2 - 7, hx2 - 7, hy2 - 4.5, 1, (110, 116, 122, 255)))
    # AMS 2 Pro rechts: breite Einheit, vier Spulen oben, vier Halterungen unten
    ax1, ax2, ay1, ay2 = x1 + w * 0.255, x2 - 3, y1 + 2, top - 2
    sh.append(("rrect", ax1, ay1, ax2, ay2, 3, (236, 239, 242, 255)))
    cols = ((250, 250, 250, 255), (46, 92, 204, 255), (78, 180, 82, 255), (226, 60, 44, 255))
    sw = (ax2 - ax1 - 6) / 4
    for i, c in enumerate(cols):
        sx = ax1 + 3 + i * sw
        sh.append(("rrect", sx + 0.5, ay1 + 2, sx + sw - 0.5, ay1 + h * 0.13, 2, (120, 124, 130, 255)))   # Spulenrand
        sh.append(("rrect", sx + 1.5, ay1 + 3.5, sx + sw - 1.5, ay1 + h * 0.13 - 1.5, 1.5, c))            # Filament
        sh.append(("rrect", sx + sw / 2 - 2.5, ay1 + h * 0.15, sx + sw / 2 + 2.5, ay2 - 2, 1.5, (66, 70, 76, 255)))  # Halterung
    sh.append(("rrect", x1 + 4, y2 - 4, x1 + 14, y2 + 3, 1.5, body_hi))                   # Fuesse
    sh.append(("rrect", x2 - 14, y2 - 4, x2 - 4, y2 + 3, 1.5, body_hi))
    return sh


def _font(px: int) -> ImageFont.FreeTypeFont:
    for path in (r"C:\Windows\Fonts\arialbd.ttf", "/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf",
                 "/Library/Fonts/Arial Bold.ttf"):
        if os.path.exists(path):
            return ImageFont.truetype(path, px)
    return ImageFont.load_default()


def label_px() -> float:
    """Schriftgroesse, bei der LABEL genau LABEL_WIDTH breit ist."""
    f = _font(200)
    try:
        w = ImageDraw.Draw(Image.new("L", (10, 10))).textlength(LABEL, font=f)
    except Exception:
        return 60.0
    return 200 * LABEL_WIDTH / w if w else 60.0


def render(size: int) -> Image.Image:
    k = size * SS / 512.0
    S = size * SS
    D = int(480 * k)
    off = int(16 * k)

    # Kreis mit Radialverlauf (Lichtpunkt oben links)
    g = Image.radial_gradient("L").resize((int(1.8 * D), int(1.8 * D)), Image.BICUBIC)
    left, top = int(0.9 * D - 0.40 * D), int(0.9 * D - 0.34 * D)
    g = g.crop((left, top, left + D, top + D))
    disc = ImageOps.colorize(g, G_LIGHT, G_DARK).convert("RGBA")
    mask = Image.new("L", (D, D), 0)
    ImageDraw.Draw(mask).ellipse((0, 0, D - 1, D - 1), fill=255)
    img = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    img.paste(disc, (off, off), mask)

    ov = Image.new("RGBA", (S, S), (0, 0, 0, 0))
    d = ImageDraw.Draw(ov)

    def r(x1, y1, x2, y2):
        return [x1 * k, y1 * k, x2 * k, y2 * k]

    # dezenter Glanz oben und feiner Innenring
    d.ellipse(r(60, 24, 452, 250), fill=(255, 255, 255, 34))
    d.ellipse(r(34, 34, 478, 478), outline=(255, 255, 255, 70), width=max(1, int(3 * k)))

    def draw(shapes):
        for sh in shapes:
            if sh[0] == "rrect":
                _, x1, y1, x2, y2, rad, c = sh
                d.rounded_rectangle(r(x1 + OX, y1 + OY, x2 + OX, y2 + OY), radius=rad * k, fill=c)
            elif sh[0] == "circle":
                _, cx, cy, rr, c = sh
                d.ellipse(r(cx - rr + OX, cy - rr + OY, cx + rr + OX, cy + rr + OY), fill=c)
            elif sh[0] == "ellipse":
                _, cx, cy, rx, ry, c = sh
                d.ellipse(r(cx - rx + OX, cy - ry + OY, cx + rx + OX, cy + ry + OY), fill=c)
            else:
                _, x1, y1, x2, y2, w, c = sh
                d.line([((x1 + OX) * k, (y1 + OY) * k), ((x2 + OX) * k, (y2 + OY) * k)], fill=c, width=max(1, int(w * k)))

    draw(printer_shapes())
    draw(miniserver_shapes())
    # Verbindung mit Pfeilspitze (Drucker -> Miniserver)
    ly = (LINK_Y + OY) * k
    d.line([((LINK_X[0] + OX) * k, ly), ((LINK_X[1] - 4 + OX) * k, ly)], fill="white", width=int(7 * k))
    d.polygon([((LINK_X[1] + 8 + OX) * k, ly), ((LINK_X[1] - 6 + OX) * k, ly - 11 * k),
               ((LINK_X[1] - 6 + OX) * k, ly + 11 * k)], fill="white")
    # Beschriftung: Name in einer Zeile
    font = _font(int(label_px() * k))
    d.text((256 * k, (LABEL_BASE + OY) * k), LABEL, font=font, fill="white", anchor="ms")
    img = Image.alpha_composite(img, ov)
    return img.resize((size, size), Image.LANCZOS)


if __name__ == "__main__":
    logo_dir = os.path.join(ROOT, "docs", "logo")
    icon_dir = os.path.join(ROOT, "bambulab2lox", "icons")
    os.makedirs(logo_dir, exist_ok=True)
    os.makedirs(icon_dir, exist_ok=True)
    render(512).save(os.path.join(logo_dir, "bambulab2lox.png"))
    for s in (64, 128, 256, 512):
        render(s).save(os.path.join(icon_dir, "icon_%d.png" % s))
    print("Logo und Icons geschrieben")
