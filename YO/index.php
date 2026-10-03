#!/usr/bin/env python3
"""
Realistic boat on water — Tkinter Canvas scene.

A cabin cruiser sits on animated waves with a sky gradient,
boat reflection, wake, and a gentle bob. Run with:

    python3 boat_on_water.py
"""

from __future__ import annotations

import math
import random
import tkinter as tk


WIDTH, HEIGHT = 1100, 680
HORIZON = 250
WATER_TOP = HORIZON


class BoatScene:
    def __init__(self, root: tk.Tk) -> None:
        self.root = root
        self.root.title("Boat on Water")
        self.root.resizable(False, False)

        self.canvas = tk.Canvas(
            root,
            width=WIDTH,
            height=HEIGHT,
            highlightthickness=0,
            bg="#87ceeb",
        )
        self.canvas.pack()

        self.t = 0.0
        self.bob = 0.0
        self.wave_phase = 0.0

        self._build_static()
        self._draw_frame()
        self._tick()

    # ------------------------------------------------------------------
    # Static background (drawn once)
    # ------------------------------------------------------------------
    def _build_static(self) -> None:
        # Sky gradient — pale dawn blue into warmer horizon
        steps = 40
        for i in range(steps):
            y0 = int(i * HORIZON / steps)
            y1 = int((i + 1) * HORIZON / steps)
            r = int(135 + i * 1.6)
            g = int(190 + i * 0.55)
            b = int(235 - i * 1.4)
            color = f"#{r:02x}{g:02x}{b:02x}"
            self.canvas.create_rectangle(0, y0, WIDTH, y1, fill=color, outline="")

        # Soft sun
        sx, sy, sr = 860, 95, 42
        self.canvas.create_oval(
            sx - sr - 18, sy - sr - 18, sx + sr + 18, sy + sr + 18,
            fill="#ffe9b0", outline="",
        )
        self.canvas.create_oval(
            sx - sr, sy - sr, sx + sr, sy + sr,
            fill="#fff4c4", outline="",
        )

        # Distant haze / land suggestion
        self.canvas.create_polygon(
            0, HORIZON,
            80, HORIZON - 8,
            180, HORIZON - 4,
            320, HORIZON - 14,
            460, HORIZON - 6,
            620, HORIZON - 18,
            780, HORIZON - 7,
            940, HORIZON - 12,
            WIDTH, HORIZON - 5,
            WIDTH, HORIZON,
            fill="#6d8aa0",
            outline="",
        )
        self.canvas.create_polygon(
            0, HORIZON,
            140, HORIZON - 3,
            300, HORIZON - 9,
            500, HORIZON - 2,
            710, HORIZON - 11,
            WIDTH, HORIZON - 3,
            WIDTH, HORIZON,
            fill="#7a96ab",
            outline="",
        )

        # Horizon line
        self.canvas.create_line(0, HORIZON, WIDTH, HORIZON, fill="#5a7a90", width=1)

    # ------------------------------------------------------------------
    # Per-frame drawing
    # ------------------------------------------------------------------
    def _draw_frame(self) -> None:
        self.canvas.delete("dyn")

        self._draw_water()
        self._draw_wake_and_reflection()
        self._draw_boat()
        self._draw_sparkles()

    def _wave_y(self, x: float, layer: int) -> float:
        """Layered sine waves so the surface isn't a single frequency."""
        p = self.wave_phase
        if layer == 0:
            return (
                6.0 * math.sin(x * 0.012 + p)
                + 3.0 * math.sin(x * 0.031 + p * 1.7)
            )
        if layer == 1:
            return (
                4.5 * math.sin(x * 0.018 + p * 1.3 + 1.2)
                + 2.2 * math.sin(x * 0.045 + p * 2.1)
            )
        return (
            3.0 * math.sin(x * 0.028 + p * 1.8 + 0.6)
            + 1.6 * math.sin(x * 0.06 + p * 2.4)
        )

    def _draw_water(self) -> None:
        # Deep water body
        self.canvas.create_rectangle(
            0, WATER_TOP, WIDTH, HEIGHT,
            fill="#1a4d6e", outline="", tags="dyn",
        )

        # Color bands for depth
        bands = [
            (WATER_TOP, WATER_TOP + 70, "#2b6f96"),
            (WATER_TOP + 70, WATER_TOP + 160, "#1f5a7c"),
            (WATER_TOP + 160, HEIGHT, "#154460"),
        ]
        for y0, y1, color in bands:
            self.canvas.create_rectangle(0, y0, WIDTH, y1, fill=color, outline="", tags="dyn")

        # Surface ripple bands
        step = 8
        xs = list(range(0, WIDTH + step, step))

        # Near-surface lighter sheet following wave 0
        pts = [(0, HEIGHT), (0, WATER_TOP)]
        for x in xs:
            pts.append((x, WATER_TOP + 18 + self._wave_y(x, 0)))
        pts.append((WIDTH, WATER_TOP + 18))
        pts.append((WIDTH, HEIGHT))
        self.canvas.create_polygon(pts, fill="#2e7aa3", outline="", tags="dyn", smooth=True)

        # Mid ripple sheet
        pts = []
        for x in xs:
            pts.append((x, WATER_TOP + 55 + self._wave_y(x, 1)))
        for x in reversed(xs):
            pts.append((x, WATER_TOP + 95 + self._wave_y(x, 1) * 0.6))
        self.canvas.create_polygon(pts, fill="#24688d", outline="", tags="dyn", smooth=True)

        # Highlight ridges
        for layer, color, width, y_off in (
            (0, "#8ec8e0", 1.6, 8),
            (1, "#5aa0c0", 1.2, 28),
            (2, "#3d7fa0", 1.0, 48),
            (0, "#c5e6f3", 1.0, 4),
        ):
            pts = []
            for x in range(0, WIDTH + 6, 6):
                pts.extend((x, WATER_TOP + y_off + self._wave_y(x, layer)))
            self.canvas.create_line(pts, fill=color, width=width, tags="dyn", smooth=True)

        # Farther dark troughs
        for y_off in (120, 180, 250, 330):
            pts = []
            for x in range(0, WIDTH + 10, 10):
                pts.extend((x, WATER_TOP + y_off + self._wave_y(x, 2) * 1.4))
            self.canvas.create_line(pts, fill="#0f3348", width=1.2, tags="dyn", smooth=True)

    def _boat_anchor(self) -> tuple[float, float]:
        """Center of the hull on the waterline, including bob."""
        cx = WIDTH * 0.46
        cy = WATER_TOP + 38 + self.bob + self._wave_y(cx, 0) * 0.35
        return cx, cy

    def _draw_wake_and_reflection(self) -> None:
        cx, cy = self._boat_anchor()

        # Soft reflection blob under the hull
        self.canvas.create_oval(
            cx - 210, cy + 8,
            cx + 230, cy + 58,
            fill="#1c3f55", outline="", tags="dyn",
        )
        self.canvas.create_oval(
            cx - 160, cy + 14,
            cx + 175, cy + 46,
            fill="#16384d", outline="", tags="dyn",
        )

        # Stretched, rippled reflection of hull color
        refl_pts = [
            cx - 175, cy + 22,
            cx - 40, cy + 18,
            cx + 50, cy + 16,
            cx + 190, cy + 24,
            cx + 175, cy + 42,
            cx + 40, cy + 38,
            cx - 50, cy + 40,
            cx - 160, cy + 38,
        ]
        # Wiggle reflection with phase
        wiggled = []
        for i in range(0, len(refl_pts), 2):
            x, y = refl_pts[i], refl_pts[i + 1]
            y += 2.5 * math.sin(x * 0.04 + self.wave_phase * 2)
            wiggled.extend((x, y))
        self.canvas.create_polygon(
            wiggled, fill="#3a2a1c", outline="", tags="dyn", smooth=True,
        )
        self.canvas.create_polygon(
            [
                cx - 70, cy + 20 + 2 * math.sin(self.wave_phase),
                cx + 55, cy + 18,
                cx + 48, cy + 34,
                cx - 62, cy + 36,
            ],
            fill="#5a4030", outline="", tags="dyn",
        )

        # Wake / foam trailing to the right (boat facing left-ish / slightly right bow)
        foam_phase = self.wave_phase
        for i, (ox, oy, s) in enumerate((
            (200, 18, 38),
            (250, 26, 50),
            (310, 34, 62),
            (380, 42, 70),
            (460, 52, 78),
        )):
            wobble = 6 * math.sin(foam_phase * 1.4 + i)
            self.canvas.create_oval(
                cx + ox - s, cy + oy + wobble - s * 0.28,
                cx + ox + s, cy + oy + wobble + s * 0.28,
                fill="#d7eef6", outline="", tags="dyn",
            )
            self.canvas.create_oval(
                cx + ox - s * 0.55, cy + oy + wobble - s * 0.14,
                cx + ox + s * 0.7, cy + oy + wobble + s * 0.16,
                fill="#9ec9dc", outline="", tags="dyn",
            )

        # Small bow splash
        splash_x = cx - 188
        splash_y = cy + 6
        for k in range(7):
            a = foam_phase * 3 + k * 0.9
            px = splash_x - 8 - k * 7 + 4 * math.sin(a)
            py = splash_y - 4 - k * 2 + 3 * math.cos(a * 1.3)
            r = 3.5 + (k % 3)
            self.canvas.create_oval(
                px - r, py - r * 0.55, px + r, py + r * 0.55,
                fill="#eaf6fb", outline="", tags="dyn",
            )

    def _draw_boat(self) -> None:
        cx, cy = self._boat_anchor()
        # Slight pitch from waves
        pitch = 0.035 * math.sin(self.wave_phase * 0.9 + 0.4)
        self.pitch = pitch

        def tx(x: float, y: float) -> tuple[float, float]:
            """Local boat coords: +x toward stern, +y down. Rotate by pitch."""
            c, s = math.cos(pitch), math.sin(pitch)
            return cx + x * c - y * s, cy + x * s + y * c

        def poly(pts: list[tuple[float, float]], **kw) -> None:
            flat = []
            for x, y in pts:
                px, py = tx(x, y)
                flat.extend((px, py))
            self.canvas.create_polygon(flat, tags="dyn", **kw)

        def oval(x0, y0, x1, y1, **kw) -> None:
            # Approximate rotated oval as polygon
            pts = []
            mx, my = (x0 + x1) / 2, (y0 + y1) / 2
            rx, ry = abs(x1 - x0) / 2, abs(y1 - y0) / 2
            for i in range(24):
                a = i / 24 * math.tau
                pts.append((mx + rx * math.cos(a), my + ry * math.sin(a)))
            poly(pts, **kw)

        # ---- shadow on water (tight, under hull) ----
        poly(
            [(-195, 16), (205, 18), (190, 28), (-175, 26)],
            fill="#0d2433", outline="",
        )

        # ---- hull underside / keel hint ----
        poly(
            [(-200, 4), (210, 6), (195, 18), (-185, 16)],
            fill="#1a1410", outline="",
        )

        # ---- main hull (varnished teak / cream hull with dark sheer) ----
        # Lower hull
        poly(
            [(-205, -6), (-170, 10), (200, 12), (218, -2), (200, -14), (-190, -16)],
            fill="#c9b08a", outline="",
        )
        # Hull shadow band
        poly(
            [(-200, 2), (-165, 12), (198, 14), (210, 2), (190, -2), (-180, -2)],
            fill="#8a6e4a", outline="",
        )
        # Waterline stripe
        poly(
            [(-198, -2), (208, 0), (206, -5), (-196, -7)],
            fill="#1e2a38", outline="",
        )
        # Upper hull / freeboard
        poly(
            [(-192, -28), (-205, -6), (218, -2), (205, -26), (40, -32), (-80, -34)],
            fill="#f3efe6", outline="",
        )
        # Sheer shadow
        poly(
            [(-190, -18), (-200, -6), (214, -2), (204, -14)],
            fill="#d8d0c2", outline="",
        )

        # Gold cove stripe
        poly(
            [(-188, -22), (202, -18), (201, -21), (-187, -25)],
            fill="#c4a35a", outline="",
        )

        # ---- bow stem highlight ----
        poly(
            [(-205, -6), (-192, -28), (-186, -26), (-198, -4)],
            fill="#ffffff", outline="",
        )

        # ---- deck ----
        poly(
            [(-175, -30), (195, -26), (188, -38), (-160, -42)],
            fill="#d7c4a3", outline="",
        )
        # Deck shadow near cabin
        poly(
            [(-40, -32), (90, -30), (88, -38), (-35, -40)],
            fill="#c0ab88", outline="",
        )

        # ---- cabin structure ----
        # Cabin sides
        poly(
            [(-55, -32), (-50, -78), (78, -74), (88, -30)],
            fill="#efeae0", outline="",
        )
        # Cabin roof
        poly(
            [(-54, -78), (-40, -92), (70, -88), (80, -74)],
            fill="#f7f3ec", outline="",
        )
        # Roof edge shadow
        poly(
            [(-50, -78), (78, -74), (76, -78), (-48, -82)],
            fill="#cfc8bc", outline="",
        )

        # Windshield (tinted glass)
        poly(
            [(-48, -78), (-38, -90), (12, -88), (8, -76)],
            fill="#6a9bb8", outline="",
        )
        poly(
            [(-44, -80), (-36, -88), (6, -86), (4, -78)],
            fill="#b7d4e6", outline="",
        )
        # Side windows
        poly(
            [(18, -72), (18, -52), (72, -50), (70, -70)],
            fill="#5e8eaa", outline="",
        )
        poly(
            [(22, -68), (22, -56), (68, -54), (66, -66)],
            fill="#9ec4d8", outline="",
        )
        # Window mullions
        self._line_local(-48, -78, 8, -76, "#e8e4dc", 1.5)
        self._line_local(18, -72, 18, -52, "#e8e4dc", 1.5)
        self._line_local(45, -71, 45, -51, "#e8e4dc", 1.5)

        # Cabin door hint
        poly(
            [(-28, -32), (-26, -58), (-8, -58), (-10, -32)],
            fill="#cfc6b6", outline="#b0a898",
        )

        # ---- flybridge / radar arch ----
        self._line_local(-20, -92, -20, -118, "#4a4a4a", 3)
        self._line_local(55, -88, 55, -114, "#4a4a4a", 3)
        self._line_local(-22, -118, 58, -114, "#3a3a3a", 3)
        # Radar dome
        oval(12, -128, 32, -114, fill="#d0d0d0", outline="#9a9a9a")
        # Antenna
        self._line_local(22, -128, 22, -148, "#666666", 1.5)

        # ---- railing ----
        rail_y = -40
        posts_x = [-160, -120, -80, -40, 20, 70, 120, 165]
        for px in posts_x:
            self._line_local(px, -32, px, rail_y - 10, "#cfd6dc", 2)
        self._line_local(-165, rail_y - 10, 175, rail_y - 6, "#e8eef2", 2.5)
        self._line_local(-163, rail_y + 2, 172, rail_y + 5, "#c5ced4", 1.5)

        # ---- windshield frame / hardtop supports ----
        self._line_local(-50, -78, -55, -32, "#ddd6c8", 2)
        self._line_local(78, -74, 88, -30, "#ddd6c8", 2)

        # ---- bow pulpit / anchor roller ----
        self._line_local(-192, -28, -220, -34, "#cfd6dc", 2.5)
        self._line_local(-220, -34, -212, -18, "#cfd6dc", 2)
        oval(-226, -38, -214, -30, fill="#8a8a8a", outline="#666")

        # ---- name plate ----
        poly(
            [(130, -18), (178, -16), (177, -8), (129, -10)],
            fill="#1e2a38", outline="",
        )

        # ---- cockpit seating hint ----
        poly(
            [(95, -32), (175, -28), (172, -38), (98, -40)],
            fill="#6b3a32", outline="",
        )

        # ---- stern ----
        poly(
            [(195, -26), (218, -2), (210, -14), (188, -38)],
            fill="#e7e1d6", outline="",
        )
        # Swim platform
        poly(
            [(210, 4), (238, 6), (232, 14), (200, 12)],
            fill="#cfc4b0", outline="",
        )

        # ---- hull highlight streak ----
        poly(
            [(-170, -12), (160, -8), (158, -11), (-168, -15)],
            fill="#fffaf0", outline="",
        )

        # Lettering (simple)
        x1, y1 = tx(138, -14)
        self.canvas.create_text(
            x1, y1,
            text="LIBERTAS",
            fill="#e8d5a3",
            font=("Helvetica", 8, "bold"),
            tags="dyn",
            anchor="w",
        )

    def _line_local(self, x0, y0, x1, y1, fill, width) -> None:
        cx, cy = self._boat_anchor()
        pitch = self.pitch
        c, s = math.cos(pitch), math.sin(pitch)

        def t(x, y):
            return cx + x * c - y * s, cy + x * s + y * c

        ax, ay = t(x0, y0)
        bx, by = t(x1, y1)
        self.canvas.create_line(ax, ay, bx, by, fill=fill, width=width, tags="dyn", capstyle=tk.ROUND)

    def _draw_sparkles(self) -> None:
        rng = random.Random(int(self.t * 3) )
        for _ in range(18):
            x = rng.randint(20, WIDTH - 20)
            base = WATER_TOP + 10 + (x * 17) % 90
            y = base + self._wave_y(x, 0) * 0.4
            if rng.random() < 0.55:
                self.canvas.create_line(
                    x - 3, y, x + 3, y, fill="#e8f6ff", width=1, tags="dyn",
                )
                self.canvas.create_line(
                    x, y - 2, x, y + 2, fill="#e8f6ff", width=1, tags="dyn",
                )

    # ------------------------------------------------------------------
    def _tick(self) -> None:
        self.t += 0.05
        self.wave_phase += 0.055
        self.bob = 2.8 * math.sin(self.t * 1.15) + 1.2 * math.sin(self.t * 2.3 + 0.7)
        self._draw_frame()
        self.root.after(33, self._tick)


def main() -> None:
    root = tk.Tk()
    BoatScene(root)
    root.mainloop()


if __name__ == "__main__":
    main()
