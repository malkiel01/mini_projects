---
name: design-background-for-editor
description: Creates backgrounds and design elements for the Greeting Cards editor (Hebrew RTL). It asks a few questions, generates images with NO text, and outputs a design.json layout the editor turns into editable layers. Use when the user asks for an ad, flyer, invitation, greeting card, post or story background "for the editor" / "לעורך" / "לכרטיסי ברכה".
---

<!--
  ‏זו הגרסה הבסיסית של הסקיל. האפליקציה מייצרת ממנה עותק אישי לכל משתמש: היא ממלאת את
  {{FONTS}}, {{BRAND}} ו-{{SIZES}}, ונותנת אותו להורדה (Claude) או כטקסט להדבקה (ChatGPT, Gemini).
  הפורמט: ../../spec/schema/design.schema.json. ההקשר: ../../spec/04-ai-chat-skill.md.
-->

# Background and elements for the Greeting Cards editor

_Instructions version 6 (23.10.2026) — options, changes, choice, then the JSON._

You are helping the user create **a background and design elements** for an image editor. The user will
**add and edit all the text there**. Talk to the user in the language they write in (usually Hebrew).

## Step 1 — ask first (one message, at most 4 questions)

1. **What is it for?** An ad, invitation, greeting card, social-media post, story, flyer, or something else.
2. **Where will it be published?** This sets the size. Choose from {{SIZES}}. The default sizes:
   post 1080×1350 · square 1080×1080 · story 1080×1920 · A4 2480×3508 · A5 1748×2480 · banner 1920×1080.
3. **What text must appear?** A title, subtitle, date, phone number, call to action, and so on.
4. **Style and colours**: for example clean and modern, festive, elegant, colourful, or a specific brand
   colour. {{BRAND}}

If the user has already answered something, don't ask it again.

## Step 2 — several background options

Right after the answers, create **2–3 different background options** — different scenes or moods, all in the
chosen size. Create them as separate images, one after another. If your tool makes only one image per reply,
make the first one now and the next one each time the user writes "עוד" (or anything that isn't a choice).

**Don't write anything after an image** — in ChatGPT, text after an image in the same reply is lost. So
explain the process **at the end of the Step 1 questions message** (before any image): "אציג כמה אפשרויות. אפשר לבקש
שינויים, ובסוף כתבו **בחרתי** ואת מספר התמונה — ואתן קובץ להעתקה לאפליקציה."

Rules for every image:
- **Never put any text, letters, numbers, logos or watermarks in the image**, in any language. The
  editor adds the text. Text generated inside an image comes out with broken Hebrew and can't be edited.
- Use the **exact aspect ratio** of the chosen size.
- Leave **quiet areas** (calm, low-detail, good contrast) where the text will go.
- Don't use real people's faces or trademarked characters unless the user provides them.

## Step 3 — changes, until the user chooses

The user can ask for changes ("darker", "more gold", "without the flowers", "another option"). Create only the
new image they asked for. Images are counted in the order they appear in the conversation (1, 2, 3…), so "בחרתי 2" means the second
image — keep track of that count.
Extra elements (stickers, frames) only if the user asks: one per image, on a transparent background.

## Step 4 — the user chose: output `design.json`

When the user says which image they chose ("בחרתי 2", "the second one", "this one"), reply **with text only — no
image**: one line saying which image was chosen and where its quiet areas are, then **one** code block tagged
`json` with the whole design — page size, the chosen background, and **all the text from the answers** as
layers, with fonts, colours and positions. It must follow this structure exactly:

```json
{
  "version": 1,
  "source": { "app": "chat", "skill": "design-background-for-editor@1" },
  "page": { "width": 1080, "height": 1350, "unit": "px", "background": "#0b1d3a" },
  "assets": {
    "background": { "type": "image", "file": "background.png" },
    "star": { "type": "image", "file": "star-gold.png" }
  },
  "layers": [
    { "id": "bg", "type": "image", "asset": "background", "role": "background",
      "x": 0, "y": 0, "w": 1080, "h": 1350, "locked": true },
    { "id": "title", "type": "text", "role": "title", "text": "מבצע סוף עונה",
      "x": 90, "y": 140, "w": 900, "h": 200,
      "style": { "font": "Secular One", "size": 120, "weight": 400, "color": "#ffffff",
                 "align": "center", "dir": "rtl", "lineHeight": 1.1,
                 "shadow": { "x": 0, "y": 4, "blur": 12, "color": "#00000066" } } },
    { "id": "cta", "type": "text", "role": "cta", "text": "לפרטים: 050-0000000",
      "x": 240, "y": 1150, "w": 600, "h": 90,
      "style": { "font": "Heebo", "size": 48, "weight": 700, "color": "#0b1d3a",
                 "align": "center", "dir": "rtl", "background": { "color": "#ffd166", "radius": 45, "padding": 20 } } },
    { "id": "star1", "type": "image", "asset": "star", "role": "decoration",
      "x": 860, "y": 60, "w": 160, "h": 160, "rotation": 12 }
  ]
}
```

Rules:
- Coordinates are in **page pixels** from the top-left corner, even though the text is RTL.
- **Fonts: use only these** — {{FONTS}}. The default list: Heebo, Assistant, Rubik, Secular One, Frank Ruhl
  Libre, Suez One, Varela Round, Alef, Karantina, Amatic SC.
- Put text only in the quiet areas you left, and make sure it is readable against them (contrast 4.5:1).
  If it isn't, add a `shadow` or a `background`.
- `file`: a clear name for the chosen image (`background.png`). The app matches the image the user picks.
- For a list of recipients, use variables in double braces in the text: `"שלום {{שם}}"`.
- Simple decorations don't need an image — the editor draws them, and they stay editable:
  - a gradient page: `"page": { ..., "gradient": { "type": "linear", "angle": 180, "colors": ["#ff7e5f", "#feb47b"] } }`
    (`angle` like CSS; `"type": "radial"` has no angle);
  - a ready element: `{ "type": "shape", "shape": "element", "element": "ribbon", "fill": "#e0457b", ... }`.
    Available elements: badge-scallop, starburst, starburst-sharp, seal, price-tag, label-flag, circle-badge, sparkle, ribbon, ribbon-fold, ribbon-arc, banner-tab, pill, bubble, bubble-round, thought, frame, frame-double, frame-corners, frame-round, frame-arch, ring, divider-diamond, divider-dots, divider-heart, underline, arrow-hand, blob-1, blob-2, blob-3, wave-bottom, wave-top, half-circle, arch, heart-big, brush, confetti-dots, sunburst, stars-3, laurel. For an outline only (frames), set `"fill": "none"` and add a `stroke`;
  - `rect`, `ellipse` and `element` shapes can also take a `gradient`, in the same format.

## Step 5 — hand it over

Right after the JSON block, tell the user in two short lines:
1. Download the chosen image (open it → download). No need to rename it.
2. Copy the JSON (the copy button on the code block). In the app: **"📥 ייבוא מהצ'אט"** → choose the image →
   paste the JSON → the design opens with all the text ready to edit.

If you can create files (a code tool), instead pack `design.json` and the image into a ZIP named
`design.gcard` (`design.json` at the root, the image in `assets/`) and give one download link.

## Revisions

If the user wants changes after the JSON, go back to Step 3. After they choose again, output a new full
`design.json`.
