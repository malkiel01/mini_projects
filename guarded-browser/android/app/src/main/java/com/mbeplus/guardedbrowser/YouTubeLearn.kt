package com.mbeplus.guardedbrowser

/**
 * מצב למידה ביוטיוב (שלב 12 ה) — מה שקורה *בדף*: הסתרת ההמלצות ומסכי הסיום, כיבוי הניגון האוטומטי, ועצירה
 * בסוף סרטון. יוטיוב יכול לשנות את הדף בכל רגע, ולכן הסקריפט בודק בחזרה מה באמת הצליח ומדווח (MgYt.learnReport)
 * — ההורה רואה "אומת במכשיר" רק על סמך זה (lib/ytlearn.php).
 *
 * הגבול שלא תלוי בדף — במנוע (PolicyEngine.youTubeVerdict, mode=learn) ובשמירה מניגון אוטומטי
 * (YouTubeLearnMath.blocksAutoAdvance): גם כשהסקריפט נכשל, סרטון שאינו בתור לא נפתח.
 */
object YouTubeLearn {

    /** ההמלצות, מסכי הסיום ו"הבא בתור" — נייד ומחשב. */
    private const val HIDE =
        "#related,#secondary,ytd-watch-next-secondary-results-renderer,ytd-reel-shelf-renderer," +
        "ytm-single-column-watch-next-results-renderer ytm-item-section-renderer,ytm-reel-shelf-renderer," +
        "ytm-shorts-lockup-view-model,ytm-shorts-lockup-view-model-v2,ytm-pivot-bar-renderer," +
        ".ytp-endscreen-content,.ytp-ce-element,.ytp-videowall-still,.ytp-upnext,.ytp-autonav-endscreen," +
        ".ytp-autonav-endscreen-countdown-overlay,.ytp-pause-overlay,.ytp-suggested-action," +
        "ytd-searchbox,#search,#search-form,ytm-searchbox,.mobile-topbar-header-endpoint"

    /** מתג הניגון האוטומטי כשהוא דלוק (נייד / מחשב). */
    private const val AUTOPLAY_ON =
        ".ytm-autonav-toggle-button-container[aria-pressed=true],.ytp-autonav-toggle-button[aria-checked=true]"
    private const val AUTOPLAY_ANY = ".ytm-autonav-toggle-button-container,.ytp-autonav-toggle-button"

    fun script(): String = """
(function(){
 if (window.__mgLearn) { window.__mgLearn.run(); return; }
 var CSS='$HIDE{display:none!important}', sent='', t=0;
 function put(){var s=document.getElementById('mg-learn');
  if(!s){s=document.createElement('style');s.id='mg-learn';(document.head||document.documentElement).appendChild(s);}
  if(s.textContent!==CSS)s.textContent=CSS;}
 function related(){
  // אומת = יש בדף רכיב המלצות, וכולם מוסתרים בפועל (getComputedStyle), לא רק "הזרקנו CSS".
  var els=document.querySelectorAll('$HIDE'), seen=0;
  for(var i=0;i<els.length;i++){ if(getComputedStyle(els[i]).display!=='none') return false; seen++; }
  return seen ? true : null;
 }
 function autoplay(){
  var on=document.querySelector('$AUTOPLAY_ON');
  if(on){ try{ on.click(); }catch(e){} }
  if(!document.querySelector('$AUTOPLAY_ANY')) return null;
  return !document.querySelector('$AUTOPLAY_ON');
 }
 function hook(){
  var v=document.querySelector('video'); if(!v) return null;
  if(!v.__mgEnd){ v.__mgEnd=1; v.addEventListener('ended', function(){
   try{ v.pause(); }catch(e){} try{ MgYt.learnEnded(location.href); }catch(e){} }, true); }
  return !!v.__mgEnd;
 }
 function run(){
  put();
  var r={related:related(), autoplay:autoplay(), end_stop:hook()}, k=JSON.stringify(r);
  if(k!==sent && (r.related!==null || r.autoplay!==null || r.end_stop!==null)){ sent=k; try{ MgYt.learnReport(k); }catch(e){} }
 }
 new MutationObserver(function(){ if(!t) t=setTimeout(function(){ t=0; run(); }, 400); })
  .observe(document.documentElement, {childList:true, subtree:true});
 window.__mgLearn={run:run};
 run();
})();
"""
}
