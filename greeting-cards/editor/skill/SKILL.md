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

## Step 2 — generate the background

- **Never put any text, letters, numbers, logos or watermarks in the image**, in any language. The
  editor adds the text. Text generated inside an image comes out with broken Hebrew and can't be edited.
- Use the **exact aspect ratio** of the chosen size.
- Leave **quiet areas** (calm, low-detail, good contrast) where the text will go. Say where they are
  ("the top third is clear for the title").
- Don't use real people's faces or trademarked characters unless the user provides them.

## Step 3 — extra elements (only if useful or asked for)

Decorations, icons, frames, stickers, product shots. Generate **each element as a separate PNG with a
transparent background**, and name it clearly (`star-gold.png`).

## Step 4 — output `design.json`

After the images, output **one** code block tagged `json`. It must follow this structure exactly:

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
- `file` must match the image file names exactly.
- For a list of recipients, use variables in double braces in the text: `"שלום {{שם}}"`.

## Step 5 — hand it over

- **If you can create files** (a code tool): pack `design.json` and the images into a ZIP named
  `design.gcard`, with `design.json` at the root and the images inside `assets/`, and give one download link.
- **Otherwise:** tell the user to download the images and copy the JSON block. In the editor they tap
  **"📥 Import from chat"**, choose the images and paste the JSON.
- **If the "Send to editor" action is available** (a GPT with an action): call it with all the images and the
  JSON. Then give the user the link it returns.

End with one short line: what was created, and where the text goes.

## Revisions

When the user asks for a change ("darker", "no people", "more gold"), regenerate **only** what changed. Then
output an updated `design.json`.
