#!/usr/bin/env python3
"""בדיקת הסכימה — יוצר את SCHEMA.sql במסד בזיכרון, מכניס נתוני בדיקה,
ומריץ את השאילתות שהאפיון מבטיח. הרצה: python3 recipes-app/tools/schema-check.py

הבדיקה כאן אינה על התחביר בלבד. היא מוכיחה חמישה דברים שהאפיון מתחייב להם,
והחשוב בהם הוא השני: איחוד רשימת קניות מחבר "2 כוסות" עם "כוס וחצי" לחצי
קילו, מפני שהאיחוד נעשה על השכבה המחושבת ולא על הטקסט החופשי.
"""
import io, os, sqlite3, sys

HERE = os.path.dirname(os.path.abspath(__file__))
SCHEMA = os.path.join(HERE, '..', 'SCHEMA.sql')
N = '2026-01-01T00:00:00Z'
fail = []


def check(label, got, want):
    ok = got == want
    print(('  ✅ ' if ok else '  ❌ ') + f'{label}: {got}' + ('' if ok else f'  (צפוי {want})'))
    if not ok:
        fail.append(label)


def main():
    db = sqlite3.connect(':memory:')
    db.execute('PRAGMA foreign_keys = ON')
    db.executescript(io.open(SCHEMA, encoding='utf-8').read())
    c = db.cursor()

    c.execute("INSERT INTO users(username,email,password_hash,display_name,created_at)"
              " VALUES('david','d@x.com','h','דוד',?)", (N,))
    c.execute("INSERT INTO users(username,email,password_hash,display_name,created_at)"
              " VALUES('mali','m@x.com','h','מלי',?)", (N,))
    david, mali = 1, 2
    for name, grams in [('קמח', 150), ('גבינה 5%', None), ('ביצים', None), ('קינמון', None)]:
        c.execute("INSERT INTO products(name,name_norm,grams_per_cup,created_at) VALUES(?,?,?,?)",
                  (name, name, grams, N))

    # מתכון מורכב: שני חלקים, רכיב בשתי שכבות, טווח, ורכיב בלי כמות
    c.execute("INSERT INTO recipes(owner_id,title,visibility,servings,difficulty,work_minutes,"
              "wait_minutes,search_text,created_at,updated_at)"
              " VALUES(?,'עוגת גבינה','public',8,'medium',20,70,'עוגת גבינה',?,?)", (david, N, N))
    cake = c.lastrowid
    c.execute("INSERT INTO sections(recipe_id,name,position) VALUES(?,'בצק',1)", (cake,))
    dough = c.lastrowid
    c.execute("INSERT INTO sections(recipe_id,name,position) VALUES(?,'מלית',2)", (cake,))
    filling = c.lastrowid
    c.execute("INSERT INTO ingredients(section_id,free_text,amount_min,unit,product_id,position)"
              " VALUES(?,'2 כוסות',300,'gram',1,1)", (dough,))
    c.execute("INSERT INTO ingredients(section_id,free_text,amount_min,amount_max,unit,product_id,"
              "position) VALUES(?,'2-3 ביצים',2,3,'unit',3,2)", (dough,))
    c.execute("INSERT INTO ingredients(section_id,free_text,unit,product_id,optional,position)"
              " VALUES(?,'קורט קינמון','pinch',4,1,3)", (dough,))
    c.execute("INSERT INTO ingredients(section_id,free_text,amount_min,unit,product_id,position)"
              " VALUES(?,'750 גרם',750,'gram',2,1)", (filling,))
    c.execute("INSERT INTO steps(section_id,position,text) VALUES(?,1,'לפורר')", (dough,))
    c.execute("INSERT INTO steps(section_id,position,text) VALUES(?,1,'להקציף')", (filling,))

    # מתכון פשוט: חלק אחד בלי שם, ואותו מוצר ביחידה זהה — כדי שהאיחוד יוכיח את עצמו
    c.execute("INSERT INTO recipes(owner_id,title,visibility,servings,search_text,created_at,"
              "updated_at) VALUES(?,'עוגיות שוקולד','public',24,'עוגיות שוקולד',?,?)", (mali, N, N))
    cookies = c.lastrowid
    c.execute("INSERT INTO sections(recipe_id,name,position) VALUES(?,NULL,1)", (cookies,))
    c.execute("INSERT INTO ingredients(section_id,free_text,amount_min,unit,product_id,position)"
              " VALUES(?,'כוס וחצי',200,'gram',1,1)", (c.lastrowid,))

    c.execute("INSERT INTO favorites(user_id,recipe_id,title_snapshot,created_at)"
              " VALUES(?,?,'עוגת גבינה',?)", (mali, cake, N))
    c.execute("INSERT INTO comments(recipe_id,user_id,visibility,text,created_at)"
              " VALUES(?,?,'private_note','אופה 5 דקות פחות',?)", (cake, mali, N))
    c.execute("INSERT INTO comments(recipe_id,user_id,visibility,text,created_at)"
              " VALUES(?,?,'public','יצא מושלם',?)", (cake, mali, N))
    c.execute("INSERT INTO comments(recipe_id,user_id,parent_id,visibility,text,created_at)"
              " VALUES(?,?,?,'public','תודה!',?)", (cake, david, c.lastrowid, N))
    db.commit()

    print('\n1. המרת מנות (3.3) — מ-8 ל-12 מנות')
    rows = dict((ft, (a, mx)) for ft, a, mx in c.execute(
        "SELECT i.free_text,i.amount_min,i.amount_max FROM ingredients i"
        " JOIN sections s ON s.id=i.section_id WHERE s.recipe_id=?", (cake,)))
    check('300 גרם × 1.5', rows['2 כוסות'][0] * 1.5, 450.0)
    check('טווח 2-3 × 1.5', (rows['2-3 ביצים'][0] * 1.5, rows['2-3 ביצים'][1] * 1.5), (3.0, 4.5))
    check('רכיב בלי כמות אינו מומר', rows['קורט קינמון'][0], None)

    print('\n2. רשימת קניות (8) — האיחוד על השכבה המחושבת')
    merged = dict(((n, u), t) for n, u, t in c.execute(
        "SELECT p.name,i.unit,SUM(i.amount_min) FROM ingredients i"
        " JOIN sections s ON s.id=i.section_id JOIN products p ON p.id=i.product_id"
        " WHERE s.recipe_id IN (?,?) AND i.amount_min IS NOT NULL"
        " GROUP BY i.product_id,i.unit", (cake, cookies)))
    check('"2 כוסות" + "כוס וחצי" = 500 גרם', merged[('קמח', 'gram')], 500.0)
    check('גבינה לא אוחדה עם קמח', merged[('גבינה 5%', 'gram')], 750.0)
    check('שורות מאוחדות בסך הכול', len(merged), 3)

    print('\n3. חיפוש (5) — לפי רכיב, בלי פרטיים של אחרים')
    found = [t for t, in c.execute(
        "SELECT DISTINCT rc.title FROM recipes rc JOIN sections s ON s.recipe_id=rc.id"
        " JOIN ingredients i ON i.section_id=s.id JOIN products p ON p.id=i.product_id"
        " WHERE p.name_norm LIKE '%קמח%' AND (rc.visibility='public' OR rc.owner_id=?)", (mali,))]
    check('"קמח" מחזיר שני מתכונים', len(found), 2)

    c.execute("UPDATE recipes SET visibility='private' WHERE id=?", (cookies,))
    hidden = [t for t, in c.execute(
        "SELECT rc.title FROM recipes rc WHERE rc.visibility='public' OR rc.owner_id=?", (david,))]
    check('מתכון פרטי של מלי מוסתר מדוד', 'עוגיות שוקולד' in hidden, False)
    c.execute("UPDATE recipes SET visibility='public' WHERE id=?", (cookies,))

    print('\n4. פתק פרטי (7) — רק כותבו רואה')
    seen = {}
    for who, uid in (('mali', mali), ('david', david)):
        seen[who] = c.execute(
            "SELECT COUNT(*) FROM comments WHERE recipe_id=? AND (visibility='public'"
            " OR (visibility='private_note' AND user_id=?))", (cake, uid)).fetchone()[0]
    check('מלי רואה 2 תגובות + הפתק שלה', seen['mali'], 3)
    check('דוד רואה 2 תגובות בלבד', seen['david'], 2)

    print('\n5. מחיקת מתכון (6) — cascade לתגובות, המועדף נשאר עם הכותרת')
    c.execute("DELETE FROM recipes WHERE id=?", (cake,))
    db.commit()
    check('תגובות נמחקו', c.execute("SELECT COUNT(*) FROM comments").fetchone()[0], 0)
    check('חלקים נמחקו', c.execute("SELECT COUNT(*) FROM sections WHERE recipe_id=?",
                                   (cake,)).fetchone()[0], 0)
    check('המועדף נשאר', list(c.execute("SELECT title_snapshot,recipe_id FROM favorites")),
          [('עוגת גבינה', None)])

    print()
    if fail:
        print('❌ נכשלו %d בדיקות: %s' % (len(fail), ', '.join(fail)))
        return 1
    print('✅ כל הבדיקות עברו')
    return 0


if __name__ == '__main__':
    sys.exit(main())
