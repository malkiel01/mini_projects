# vendor — ספריות צד שלישי (מועתקות, לא CDN)

| קובץ | מה | רישיון |
| --- | --- | --- |
| `tiptap.js` | TipTap 3.31.3 + ProseMirror, ארוז לקובץ ESM אחד (esbuild) | MIT |
| `fonts/*.woff2` | David Libre, Frank Ruhl Libre, Miriam Libre, Heebo, Assistant, Rubik, Alef (עברית + לטינית, 400/700, מ-@fontsource) | OFL-1.1 |
| `fonts/pdf/*.woff` | אותם גופנים ב-WOFF 1 (zlib) — ש-PHP פותח בלי הרחבה, להטמעה ב-PDF מהשרת (`lib/pdf/fontset.php`, נקרא ב-`lib/pdf-core/font.php`) | OFL-1.1 |

(prosemirror-collab — לעריכה המשותפת, `assets/js/collab.js`.) רק החבילות החינמיות של TipTap: `core`, `pm`, `starter-kit`, `extension-text-style`, `text-align`,
`highlight`, `subscript`, `superscript`, `table`. **ההרחבות בתשלום אסורות** (embed.md, פרק 0) — העימוד,
מספור העמודים וגלישת התמונות הם קוד שלנו ב-`assets/js/`.

## בנייה מחדש

```sh
npm i @tiptap/core@3.31.3 @tiptap/pm@3.31.3 @tiptap/starter-kit@3.31.3 \
  @tiptap/extension-text-style@3.31.3 @tiptap/extension-text-align@3.31.3 \
  @tiptap/extension-highlight@3.31.3 @tiptap/extension-subscript@3.31.3 \
  @tiptap/extension-superscript@3.31.3 @tiptap/extension-table@3.31.3 @tiptap/extension-list@3.31.3 \
  prosemirror-collab@1.3.1 esbuild
npx esbuild entry.js --bundle --format=esm --minify --legal-comments=none --outfile=tiptap.js
```

`entry.js`:

```js
export { Editor, Node, Mark, Extension, mergeAttributes, getHTMLFromFragment, generateJSON, generateHTML } from '@tiptap/core';
export { default as StarterKit } from '@tiptap/starter-kit';
export { TextStyle, Color, FontFamily, FontSize, LineHeight, BackgroundColor } from '@tiptap/extension-text-style';
export { default as TextAlign } from '@tiptap/extension-text-align';
export { default as Highlight } from '@tiptap/extension-highlight';
export { default as Subscript } from '@tiptap/extension-subscript';
export { default as Superscript } from '@tiptap/extension-superscript';
export { Table, TableRow, TableCell, TableHeader } from '@tiptap/extension-table';
export { Plugin, PluginKey, TextSelection, NodeSelection } from '@tiptap/pm/state';
export { Decoration, DecorationSet } from '@tiptap/pm/view';
export { DOMParser as PMDOMParser, Fragment, Slice } from '@tiptap/pm/model';
export { OrderedList, BulletList, ListItem } from '@tiptap/extension-list';
export { collab, sendableSteps, receiveTransaction, getVersion } from 'prosemirror-collab';
export { Step } from '@tiptap/pm/transform';
```

## רישיונות

**MIT** (TipTap, ProseMirror, linkifyjs, orderedmap, rope-sequence, w3c-keyname):
Copyright (c) 2015–2025 Marijn Haverbeke and others; Copyright (c) 2025 Tiptap GmbH.
Permission is hereby granted, free of charge, to any person obtaining a copy of this software and associated
documentation files (the "Software"), to deal in the Software without restriction, including without limitation the
rights to use, copy, modify, merge, publish, distribute, sublicense, and/or sell copies of the Software, and to permit
persons to whom the Software is furnished to do so, subject to the following conditions: The above copyright notice
and this permission notice shall be included in all copies or substantial portions of the Software. THE SOFTWARE IS
PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND.

**SIL Open Font License 1.1** — הגופנים. https://openfontlicense.org
