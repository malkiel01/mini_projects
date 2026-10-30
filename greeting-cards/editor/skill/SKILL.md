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

_Instructions version 9 (30.10.2026) — layers: background, separate element images, `svg` decorations, text with effects._

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

## Step 2 — the first background

Right after the answers, create **one** background. Then the user decides.

**Don't write anything after an image** — in ChatGPT, text after an image in the same reply is lost. So
explain the process **at the end of the Step 1 questions message** (before any image): "אחרי כל תמונה: מרוצים?
כתבו **מעולה** — ואכין את קובץ העיצוב. רוצים שינוי או אפשרות אחרת? כתבו מה לשנות."

Rules for every image:
- **Never put any text, letters, numbers, logos or watermarks in the image**, in any language. The
  editor adds the text. Text generated inside an image comes out with broken Hebrew and can't be edited.
- Use the **exact aspect ratio** of the chosen size.
- Leave **quiet areas** (calm, low-detail, good contrast) where the text will go.
- Don't use real people's faces or trademarked characters unless the user provides them.

## Step 3 — after each image: approval or change

Read the user's reply after an image like this — **approval is the default**:

| The user writes | Meaning | You do |
| --- | --- | --- |
| "מעולה", "יפה", "אהבתי", "מושלם", "תמשיך", "המשך", "סבבה", "כן", 👍, "זה", "בחרתי" | **approved the last image** | Step 4 — the design file. **No new image.** |
| "בחרתי 2", "הראשונה", "החזר את הקודמת" | approved that image | Step 4 with that image |
| "json", "ג'ייסון", "קובץ", "תעביר לאפליקציה", "סיימתי" | wants the file | Step 4. **Never create an image for this.** |
| "עוד אפשרות", "משהו אחר", "תראה לי עוד" | another option | a new, different background |
| a change: "כהה יותר", "בלי פרחים", "יותר זהב" | a change | a new image with only that change |
| "אלמנט: בלון", "תוסיף רימון בנפרד", "שכבה נפרדת ל…" | a separate element | **one** image of that element alone, on a **transparent background** (PNG) — it becomes its own movable layer |

When unsure, treat it as approval and go to Step 4 — making the file is cheap; an unwanted image annoys.
Images are counted in the order they appear (1, 2, 3…). Element images only when the user asks; decorations — as `svg` layers in the design file (below).

## Layers — everything stays editable

The editor works in layers, so prefer **separate parts** over one flat picture:
- **Background** — the scene only. Objects the user may want to move or swap (balloons, a pomegranate, a gift) are
  better as separate elements, made on request (Step 3).
- **Element images** — each alone on a transparent background, as its own entry in `assets` and its own `image`
  layer at its place on the page.
- **Decorations as code — `svg` layers** (frames, dividers, flourishes, stars, simple flowers): write them as SVG in
  the design file, **in text — no image needed**. Each shape becomes an editable vector layer (colour, size, position):
  `{ "id": "frame", "type": "svg", "name": "מסגרת זהב", "x": 60, "y": 60, "w": 960, "h": 1230,
     "svg": "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 128'>…</svg>" }`
  Use only `path`, `rect`, `circle`, `ellipse`, `line`, `polyline`, `polygon` and `g` (with `transform`), solid
  colours (`fill`, `stroke`, `stroke-width`, `opacity`). No text, images, gradients or filters inside the SVG.
  Add 1–4 decorations that fit the design, unless the user asked for none.
- **Text** — always as text layers, never inside an image.

## Step 4 — approved: the design file

Reply **with text and files only — never an image**. Write one line: which image was chosen and where its quiet
areas are. Then the design: page size, the chosen background, and **all the text from the answers** as
layers, with fonts, colours and positions, plus the `svg` decorations and any element images.

**Best — one file with everything:** if you can run code / create files, make **`design.gcard`**: a ZIP with
`design.json` at the root and the images inside `assets/` (with the same file name as in the JSON).
Give one download link. Also show the JSON block below it.

**Otherwise:** show **one** code block tagged `json`.

The `design.json` must follow this structure exactly:

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
  If not, add `shadow`, `glow` or `background`.
- Optional text effects in `style`: `gradient` {type, angle, colors}, `glow` {color, blur, strength 1–4},
  `stroke` {width, color}, `background.border` {width, color}. Gold title: gradient + thin dark stroke + soft glow.
- `file`: a clear name for the chosen image (`background.png`). The app matches the image the user picks.
- For a list of recipients, use variables in double braces in the text: `"שלום {{שם}}"`.
- Simple decorations don't need an image — the editor draws them, and they stay editable:
  - a gradient page: `"page": { ..., "gradient": { "type": "linear", "angle": 180, "colors": ["#ff7e5f", "#feb47b"] } }`
    (`angle` like CSS; `"type": "radial"` has no angle);
  - a ready element: `{ "type": "shape", "shape": "element", "element": "ribbon", "fill": "#e0457b", ... }`.
    Available elements: badge-scallop, starburst, starburst-sharp, seal, price-tag, label-flag, circle-badge, sparkle, ribbon, ribbon-fold, ribbon-arc, banner-tab, pill, bubble, bubble-round, thought, frame, frame-double, frame-corners, frame-round, frame-arch, ring, divider-diamond, divider-dots, divider-heart, underline, arrow-hand, blob-1, blob-2, blob-3, wave-bottom, wave-top, half-circle, arch, heart-big, brush, confetti-dots, sunburst, stars-3, laurel. For an outline only (frames), set `"fill": "none"` and add a `stroke`;
  - `rect`, `ellipse` and `element` shapes can also take a `gradient`, in the same format.

## Step 5 — hand it over

Right after the file, tell the user in short lines:
- **With `design.gcard`:** download it. In the app: **"📥 ייבוא מהצ'אט"** → choose the file → the design opens with
  the image and all the text ready to edit.
- **With a JSON block only:** download the chosen image (open it → download, no need to rename it), copy the JSON
  (the copy button on the code block). In the app: **"📥 ייבוא מהצ'אט"** → choose the image → paste the JSON.

## Revisions

If the user wants changes after the JSON, go back to Step 3. After they choose again, output a new full
`design.json`.
