# Image credits — Lucy Express // ሉሲ ኤክስፕሬስ

The interface uses **real bus photography** rather than illustrations. Both photographs come
from Wikimedia Commons and are shipped inside `assets/img/` so the whole application keeps
working offline (no hot-linking, no CDN calls).

---

## 1. `assets/img/bus-mark.jpg` and `assets/img/hero-bus.jpg`

**Source image:** [File:Bus addis abeba.jpg](https://commons.wikimedia.org/wiki/File:Bus_addis_abeba.jpg)
**Original:** 2,592 × 1,944 px, 354 KB · photographed 14 April 2008 in Addis Ababa, Ethiopia
**Author:** Shagada (Wikimedia Commons user)
**Description:** Anbessa bus with DAF chassis, Addis Ababa, Ethiopia.
**Licence:** **Public domain** — released by the copyright holder (*PD-self*), worldwide.

> I, the copyright holder of this work, release this work into the public domain. This applies
> worldwide. In some countries this may not be legally possible; if so: I grant anyone the right
> to use this work for any purpose, without any conditions, unless such conditions are required
> by law.

Derivative files created for this project:

| File | Size | Treatment |
|---|---|---|
| `bus-mark.jpg` | 360 × 360 | Square crop of the coach body (doors, windows, livery) → circular header emblem |
| `hero-bus.jpg` | 1600 × 675 | Wide crop showing the complete bus side profile → fleet card + admin photo band |

No attribution is legally required for public-domain works; it is provided here as good practice.

---

## 2. `assets/img/bus-coach.jpg`

**Source image:** [File:Selam bus Ethiopia.jpg](https://commons.wikimedia.org/wiki/File:Selam_bus_Ethiopia.jpg)
**Original:** 4,000 × 3,000 px, 1.32 MB · photographed 6 November 2012 in the Oromia Region, Ethiopia
**Author:** Radosław Botev — copyright holder: [Fundacja Nomos](http://www.nomos.org.pl/)
**Description:** An intercity coach on a highland road in the Oromia Region, Ethiopia.
**Licence:** [**Creative Commons Attribution 3.0 Poland**](https://creativecommons.org/licenses/by/3.0/pl/deed.en) (CC BY 3.0 PL)

Required attribution (must stay in the project):

> Photo: “Selam bus Ethiopia” by Radosław Botev / Fundacja Nomos, via Wikimedia Commons —
> licensed under CC BY 3.0 PL. Cropped and downscaled to 1000 × 750 for use as the site hero image.

This credit already appears in the site footer and on the ticket page renders alongside the
logo, so the attribution travels with any deployment of this project.

---

## Replacing these images with your own

Drop your own files into `assets/img/` using the same names and nothing else needs to change:

| File | Recommended size | Used for |
|---|---|---|
| `bus-mark.jpg` | 360 × 360 (square) | Header + footer logo emblem (circular crop via CSS) |
| `bus-coach.jpg` | 1000 × 750 (4:3) | Homepage hero photo band |
| `hero-bus.jpg` | 1600 × 675 (wide) | Fleet card thumbnail + admin console photo band |

If you use your own photography you may delete this file and the credit line in
`config/db.php` (`lucy_footer()`), plus the credit paragraph in `README.md`.
