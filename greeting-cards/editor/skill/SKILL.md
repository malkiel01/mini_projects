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

## How the conversation runs — a loop, not one answer

Most chat image tools make **one image per reply**. So don't try to deliver everything at once. Work in rounds:
the background first, then one element or variation per round, for as long as the user wants.

**⚠️ Write the text FIRST, create the image LAST.** In ChatGPT (and some other chats), nothing can be written
after an image is created in the same reply — anything planned for after the image is lost. So every reply that
creates an image has these three text parts **before** the image, in this order — never skip one — and the image
generation is the very last step of the reply:

1. **One line:** what the image below will be, and where its quiet areas are ("the top third is clear for the title").
2. **The full, updated `design.json`** (format below), in one code block tagged `json` — every round, even if the
   user didn't ask. It already includes the image this reply is about to create, plus **all** the images made
   before, so the latest block is the only one the user needs.
3. **"מה עכשיו?"** — a numbered menu of 3–6 options that fit *this* design. The user answers with a number.
   Mix from:
   - another background idea, described concretely ("same scene at night with gold lights");
   - the next element, named concretely ("gold balloons sticker", "pomegranate frame");
   - the same design for another platform (see the table below) — name the platforms that fit;
   - a style change (colours, mood), or title wording ideas;
   - **"סיימתי — איך מעבירים לעורך"**, always the last option.

Then create the image — and end the reply there.

If the user writes **"json"** or **"תפריט"**, reply with the latest `design.json` and the menu only — no image.

When the user picks "done", give the hand-over steps (below) and stop asking.

### Platforms — suggest the ones that fit

| Platform | Size |
| --- | --- |
| Instagram / Facebook post | 1080×1350 |
| Story: Instagram, Facebook, WhatsApp status | 1080×1920 |
| WhatsApp image, square post | 1080×1080 |
| Facebook cover | 1640×624 |
| YouTube / website banner | 1920×1080 |
| Printed invitation or greeting card | A5 1748×2480 · card 1500×1050 |
| Printed flyer or poster | A4 2480×3508 |

Another platform = a new background in that exact ratio (not a stretched one) and a **separate** `design.json`
with the new page size and the positions recalculated.

## Images — background and elements

- **Never put any text, letters, numbers, logos or watermarks in an image**, in any language. The editor adds
  the text. Text generated inside an image comes out with broken Hebrew and can't be edited.
- Background: the **exact aspect ratio** of the page, with **quiet areas** (calm, low-detail, good contrast)
  where the text will go.
- Elements (stickers, frames, decorations, product shots): **one per image, on a transparent background**
  (PNG). If transparency isn't possible, use a plain white background and say so.
- Don't use real people's faces or trademarked characters unless the user provides them.
- Chat tools don't let you name the downloaded file. Still give every image a clear `file` name in the JSON,
  and list the assets **in the order you made them**. The editor matches the images by shape and order —
  the user doesn't need to rename anything.

## The `design.json`

Output **one** code block tagged `json`, before the image (see above). It must follow this structure exactly:

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
- `file`: a clear name for each image, in the order you made them (see "Images" above).
- For a list of recipients, use variables in double braces in the text: `"שלום {{שם}}"`.
- Simple decorations don't need an image — the editor draws them, and they stay editable:
  - a gradient page: `"page": { ..., "gradient": { "type": "linear", "angle": 180, "colors": ["#ff7e5f", "#feb47b"] } }`
    (`angle` like CSS; `"type": "radial"` has no angle);
  - a ready element: `{ "type": "shape", "shape": "element", "element": "ribbon", "fill": "#e0457b", ... }`.
    Available elements: badge-scallop, starburst, starburst-sharp, seal, price-tag, label-flag, circle-badge, sparkle, ribbon, ribbon-fold, ribbon-arc, banner-tab, pill, bubble, bubble-round, thought, frame, frame-double, frame-corners, frame-round, frame-arch, ring, divider-diamond, divider-dots, divider-heart, underline, arrow-hand, blob-1, blob-2, blob-3, wave-bottom, wave-top, half-circle, arch, heart-big, brush, confetti-dots, sunburst, stars-3, laurel. For an outline only (frames), set `"fill": "none"` and add a `stroke`;
  - `rect`, `ellipse` and `element` shapes can also take a `gradient`, in the same format.

## Hand-over to the editor

- **If you can create files** (a code tool): pack `design.json` and the images into a ZIP named
  `design.gcard`, with `design.json` at the root and the images inside `assets/`, and give one download link.
- **Otherwise:** tell the user to download **all** the images (each one: open it → download), and copy the
  **last** JSON block (the copy button on the code block). In the editor they tap **"📥 Import from chat"**,
  choose all the images together and paste the JSON.
- More images later (a new background, another sticker) don't need a new import: in the editor, 🖼️ רקע →
  "תמונת רקע" replaces the background, and ➕ → תמונה adds an element.
- **If the "Send to editor" action is available** (a GPT with an action): call it with all the images and the
  JSON. Then give the user the link it returns.

## Revisions

When the user asks for a change ("darker", "no people", "more gold"), regenerate **only** what changed. Write the
reply like every round: one line, the full updated `design.json` and the "מה עכשיו?" menu first, and the new
image last.
