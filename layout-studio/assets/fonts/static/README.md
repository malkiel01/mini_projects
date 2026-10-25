# מופעים סטטיים — למודגש ב-PDF

נוצרו מהגופנים המשתנים שבתיקייה שמעל (ברישיון SIL OFL 1.1 — ראו `../*.OFL.txt`; אין שמות שמורים), כי מנוע ה-PDF
מטמיע את קווי ברירת המחדל של גופן משתנה (משקל 400). **מזהי הגליפים זהים** לגופן המשתנה — לכן מה ש-HarfBuzz עיצב על
המסך (במשקל 700 / 900 מאותו קובץ) מתאים לקבצים האלה.

```python
from fontTools.ttLib import TTFont
from fontTools.varLib.instancer import instantiateVariableFont
f = TTFont('NotoSerifHebrew.ttf')                       # ‏Frank Ruhl Libre — בלי wdth
instantiateVariableFont(f, {'wght': 700, 'wdth': 100}, updateFontNames=False).save('static/NotoSerifHebrew-700.ttf')
```
