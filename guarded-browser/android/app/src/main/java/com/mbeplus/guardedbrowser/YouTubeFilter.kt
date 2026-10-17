package com.mbeplus.guardedbrowser

import org.json.JSONArray

/**
 * סינון יוטיוב בתוך הדף, במצב "רק מה שאישרתי" (שלב 5).
 *
 * עד עכשיו ההסתרה נכתבה לרכיבים של יוטיוב למחשב (ytd-*), ובטלפון נטען
 * m.youtube.com (ytm-*) — ולכן הצעות מערוצים זרים נשארו על המסך. כאן לא
 * מסתירים "סוג רכיב" אלא כל פריט לפי מה שהוא מקשר אליו:
 *
 *   • קישור לסרטון — מוצג רק אם ידוע שהוא מאושר. לא ידוע → מוסתר, והמזהה
 *     נשלח לאפליקציה (MgYt.ask), שבודקת מקומית ובשרת ומחזירה תשובה.
 *   • קישור לערוץ — מוצג רק אם הערוץ אושר.
 *
 * ולחיצה על קישור שאינו מאושר נעצרת לפני שיוטיוב מנווט (האזנה בשלב
 * ה-capture). כך אין "קפיצה" חזרה — לא הייתה יציאה מלכתחילה.
 *
 * זו שכבת נוחות: האכיפה עצמה נשארת ב-enforceCurrent ובשרת. דף שעוקף את
 * הסקריפט עדיין לא יפתח סרטון לא מאושר.
 */
object YouTubeFilter {

    /** רכיבי פריט ביוטיוב (נייד ומחשב) — מה שמוסתר כשהקישור שבו אינו מאושר. */
    private const val ITEM =
        "ytm-video-with-context-renderer,ytm-compact-video-renderer,ytm-reel-item-renderer," +
        "ytm-rich-item-renderer,ytm-shorts-lockup-view-model,ytm-shorts-lockup-view-model-v2," +
        "ytm-playlist-video-renderer,ytm-compact-playlist-renderer,ytm-radio-renderer," +
        "ytm-compact-radio-renderer,ytm-video-card-renderer,ytm-media-item,ytm-compact-channel-renderer," +
        "ytm-channel-renderer,ytm-endscreen-element-renderer,ytd-rich-item-renderer,ytd-video-renderer," +
        "ytd-compact-video-renderer,ytd-grid-video-renderer,ytd-reel-item-renderer," +
        ".ytp-videowall-still,.ytp-ce-element"

    /**
     * הסקריפט. בטוח להזרקה חוזרת — בפעם השנייה הוא רק סורק מחדש.
     *
     * @param handles כינויים מאושרים (בלי @, באותיות קטנות)
     * @param channels מזהי ערוצים מאושרים (UC…)
     * @param known הכרעות שכבר ידועות: מזהה סרטון → מותר?
     */
    fun script(handles: Collection<String>, channels: Collection<String>, known: Map<String, Boolean>): String {
        val h = JSONArray(handles.map { it.lowercase() }).toString()
        val c = JSONArray(channels.toList()).toString()
        val k = org.json.JSONObject(known as Map<*, *>).toString()
        return """
(function(){
 if (window.__mgYt) { window.__mgYt.scan(); return; }
 var H=$h, C=$c, V={}, K=$k, ask=[], timer=0;
 for (var id in K) V[id] = K[id] ? 'allow' : 'deny';
 var ITEM='$ITEM';
 var VIDEO=/[?&]v=([A-Za-z0-9_-]{6,15})|\/shorts\/([A-Za-z0-9_-]{6,15})|youtu\.be\/([A-Za-z0-9_-]{6,15})/;
 function vid(href){ var m=href.match(VIDEO); return m ? (m[1]||m[2]||m[3]) : ''; }
 function chan(href){
  var m=href.match(/\/@([A-Za-z0-9._-]+)/); if(m) return {h:m[1].toLowerCase()};
  m=href.match(/\/channel\/(UC[A-Za-z0-9_-]{10,})/); return m ? {c:m[1]} : null;
 }
 function chanOk(x){ return x.h ? H.indexOf(x.h)>=0 : C.indexOf(x.c)>=0; }
 function hide(el,on){
  if(on){ if(!el.dataset.mgHidden){ el.dataset.mgHidden='1'; el.style.setProperty('display','none','important'); } }
  else if(el.dataset.mgHidden){ el.style.removeProperty('display'); delete el.dataset.mgHidden; }
 }
 function scan(){
  var links=document.querySelectorAll('a[href]');
  for(var i=0;i<links.length;i++){
   var a=links[i], href=a.getAttribute('href')||'', id=vid(href), box=a.closest(ITEM)||a;
   if(id){
    if(!V[id]){ V[id]='wait'; ask.push(id); }
    hide(box, V[id]!=='allow');
   } else {
    var x=chan(href);
    // קישור לערוץ בתוך פריט של סרטון (שם הערוץ מתחת לסרטון) — הסרטון מכריע.
    if(x && !(box!==a && box.querySelector('a[href*="watch?v="],a[href*="/shorts/"]'))) hide(box, !chanOk(x));
   }
  }
  while(ask.length){ try{ MgYt.ask(JSON.stringify(ask.splice(0,30))); }catch(e){ ask=[]; } }
 }
 function later(){ if(!timer) timer=setTimeout(function(){ timer=0; scan(); }, 120); }
 document.addEventListener('click', function(e){
  var a=e.target && e.target.closest ? e.target.closest('a[href]') : null; if(!a) return;
  var href=a.getAttribute('href')||'', id=vid(href), bad=false;
  if(id) bad = V[id]!=='allow'; else { var x=chan(href); if(x) bad=!chanOk(x); }
  if(bad){ e.preventDefault(); e.stopImmediatePropagation(); try{ MgYt.blocked(); }catch(err){} }
 }, true);
 new MutationObserver(later).observe(document.documentElement, {childList:true, subtree:true});
 window.__mgYt = { scan: scan, verdicts: function(m){ for(var id in m) V[id] = m[id] ? 'allow' : 'deny'; scan(); } };
 scan();
})();
"""
    }
}
