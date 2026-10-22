# webadb.js — איך נבנה

הקובץ `../webadb.js` הוא חבילה (bundle) של Tango — ספריית ADB בדפדפן (WebUSB), רישיון MIT.
היא בתוך הריפו ולא נטענת מ-CDN: הדף מדבר עם הטלפון של הילד, והקוד שלו לא תלוי בצד שלישי בזמן ריצה.

```
npm install @yume-chan/adb@2.6.4 @yume-chan/adb-daemon-webusb@2.3.2 @yume-chan/adb-credential-web@2.1.0 esbuild
npx esbuild entry.js --bundle --format=esm --minify --platform=browser --target=es2022 --outfile=webadb.js
```

ואז להוסיף בראש הקובץ את שורת הרישיון (כמו בקובץ הנוכחי).
