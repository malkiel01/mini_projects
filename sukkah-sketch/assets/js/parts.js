/* מצגת החלקים — נבנית כולה מ-model.js. אין כאן אף מספר שמקורו לא במודל.
   כל שקופית: שרטוט (SVG בס״מ) + טקסט. הניווט: החלקה, כפתורים, חיצים, ורשימת קפיצה. */
(function(){
'use strict';
var M=SukkahModel.build(), K=M.K, TH=K.TH;
var f1=function(v){return (Math.round(v*10)/10).toFixed(1);};
var f0=function(v){return Math.round(v).toString();};
var deg=function(v){return v<.5?'ישר':f1(v)+'°';};
var cutKind={miter:'מיטר — נפגשת בקורה השנייה מעל העמוד',face:'על פני העמוד, במקביל לפאה',wall:'ישר, נגמרת על פלטת הקיר',notch:'קצה מפוצל — עוקב אחרי פני המסגרת בפינה: חצי מול קורת המזרח, חצי מול האלכסון'};
var cutText=function(c){return c.label||deg(c.deg);},cutShort=function(c){return c.short||deg(c.deg);};
var levelName={top:'מסגרת עליונה',low:'מפלס 70',cross:'קורה תומכת'};

/* ---------- SVG ---------- */
var NS='http://www.w3.org/2000/svg';
function el(tag,attrs,kids){var e=document.createElementNS(NS,tag);for(var k in attrs)e.setAttribute(k,attrs[k]);(kids||[]).forEach(function(c){e.appendChild(c);});return e;}
function txt(x,y,s,cls,anchor,fs){var t=el('text',{x:x,y:y,'class':cls||'lbl','text-anchor':anchor||'middle','dominant-baseline':'middle','font-size':fs||8});t.textContent=s;return t;}
// ב-RTL: anchor 'end' = הטקסט נמשך ימינה מ-x, 'start' = נמשך שמאלה
function poly(pts,cls){return el('polygon',{points:pts.map(function(p){return f1(p[0])+','+f1(p[1]);}).join(' '),'class':cls});}
function rect(x,y,w,h,cls){return el('rect',{x:x,y:y,width:w,height:h,'class':cls});}
function line(x1,y1,x2,y2,cls){return el('line',{x1:x1,y1:y1,x2:x2,y2:y2,'class':cls||'dim'});}
function svg(x,y,w,h){return el('svg',{viewBox:x+' '+y+' '+w+' '+h,preserveAspectRatio:'xMidYMid meet'});}
// קו מידה אופקי בין x1 ל-x2 בגובה y (התווית מעל), ואנכי בין y1 ל-y2 ב-x (התווית משמאל)
function hdim(x1,x2,y,label,fs,below){var g=el('g'),t=fs*.5;
  g.appendChild(line(x1,y-t,x1,y+t,'dim'));g.appendChild(line(x2,y-t,x2,y+t,'dim'));g.appendChild(line(x1,y,x2,y,'dim'));
  g.appendChild(txt((x1+x2)/2,y+(below?fs*.85:-fs*.75),label,'dimt','middle',fs));return g;}
function vdim(x,y1,y2,label,fs){var g=el('g'),t=fs*.5;
  g.appendChild(line(x-t,y1,x+t,y1,'dim'));g.appendChild(line(x-t,y2,x+t,y2,'dim'));g.appendChild(line(x,y1,x,y2,'dim'));
  var tx=txt(x-fs*.75,(y1+y2)/2,label,'dimt','middle',fs);tx.setAttribute('transform','rotate(-90 '+f1(x-fs*.75)+' '+f1((y1+y2)/2)+')');g.appendChild(tx);return g;}
function centroid(pts){var x=0,y=0;pts.forEach(function(p){x+=p[0];y+=p[1];});return [x/pts.length,y/pts.length];}

/* תוכנית: x מדרום → ימין לשמאל (צפון משמאל, כמו בשרטוט), y מהקיר → למעלה */
function planSVG(level,hl){
  var W=K.WLEN,H=K.WID; var sx=function(x){return W-x;}, sy=function(y){return H-y;};
  var tp=function(pts){return pts.map(function(p){return [sx(p[0]),sy(p[1])];});};
  var s=svg(-70,-40,W+140,H+K.WALL_T+90);
  s.appendChild(poly(tp(M.wall.pts),'wall'));
  s.appendChild(poly(tp(M.P),'outline'));
  M.beams.filter(function(b){return level==='low'?b.level==='low':b.level!=='low';}).forEach(function(b){
    var p=poly(tp(b.pts),(hl&&hl===b.id)?'hl':'b-'+b.mat);s.appendChild(p);});
  M.posts.forEach(function(p){s.appendChild(poly(tp(p.pts),'post'));
    s.appendChild(txt(sx(p.x),sy(p.y)-8,p.key,'lbl-s','middle',8));});
  M.beams.filter(function(b){return level==='low'?b.level==='low':b.level!=='low';}).forEach(function(b){
    var c=centroid(b.pts),ax=b.axis,dx=ax[1][0]-ax[0][0],dy=ax[1][1]-ax[0][1],l=Math.hypot(dx,dy),nx=-dy/l,ny=dx/l;
    var cc=centroid(M.P),out=((c[0]-cc[0])*nx+(c[1]-cc[1])*ny)>=0?1:-1;   // תווית מחוץ למסגרת
    if(b.level==='cross')out=-out;   // ובתומכות — לצד המערבי של הקורה, שלא תיפול על העמוד
    var lx=sx(c[0]+nx*out*16),ly=sy(c[1]+ny*out*16);
    s.appendChild(txt(lx,ly,b.id+' · '+f1(b.len.outer),'lbl-b','middle',11));});
  M.joints.filter(function(j){return (level==='low')===(j.level==='low');}).forEach(function(j){
    s.appendChild(poly(tp(j.sleevePts),'sleeve'));if(j.plate)s.appendChild(poly(tp(j.plate.pts),'plate'));});
  s.appendChild(txt(sx(K.WALL/2),sy(-K.WALL_T)+10,'הקיר המערבי · '+K.WALL+' ס״מ','lbl-s','middle',9));
  s.appendChild(txt(sx(K.WLEN)-10,-28,'← צפון','lbl-s','middle',10));s.appendChild(txt(sx(0)+10,-28,'דרום →','lbl-s','middle',10));
  return s;}

/* קורה במערכת מקומית: הציר אופקי, קצה א׳ מימין. u לאורך הציר, v ניצב */
function localPts(b){var A=b.axis[0],B=b.axis[1],dx=B[0]-A[0],dy=B[1]-A[1],l=Math.hypot(dx,dy),d=[dx/l,dy/l],n=[-d[1],d[0]];
  return {L:l,d:d,n:n,pts:b.pts.map(function(p){return [(p[0]-A[0])*d[0]+(p[1]-A[1])*d[1],(p[0]-A[0])*n[0]+(p[1]-A[1])*n[1]];})};}
function beamSVG(b){   // מבט מלא, קנה מידה אמיתי: האורכים והשמות. הפרטים — ב-endDetail
  var lp=localPts(b),L=lp.L,u=Math.max(L/60,1.2),mg=8*u;   // u = יחידת ציור, כך שהטקסט נשאר קריא בכל אורך
  var X=function(x){return L-x;},Y=function(v){return -v;};   // א׳ מימין; v חיובי למעלה
  var s=svg(-mg,-mg-4*u,L+2*mg,2*mg+TH+10*u);
  s.appendChild(poly(lp.pts.map(function(p){return [X(p[0]),Y(p[1])];}),'b-'+b.mat));
  // שתי הצלעות הארוכות ביותר של המצולע — אלה הצדדים; העליון לפי v
  var edges=lp.pts.map(function(p,i){return [p,lp.pts[(i+1)%lp.pts.length]];}).sort(function(a,b){return M.dist(b[0],b[1])-M.dist(a[0],a[1]);}).slice(0,2);
  var top=(edges[0][0][1]+edges[0][1][1])>(edges[1][0][1]+edges[1][1][1])?edges[0]:edges[1], bot=top===edges[0]?edges[1]:edges[0];
  s.appendChild(hdim(X(top[0][0]),X(top[1][0]),Y(TH/2)-2*u,f1(Math.abs(top[1][0]-top[0][0])),2.2*u));
  if(Math.abs(Math.abs(top[1][0]-top[0][0])-Math.abs(bot[1][0]-bot[0][0]))>.05)
    s.appendChild(hdim(X(bot[0][0]),X(bot[1][0]),Y(-TH/2)+4.5*u,f1(Math.abs(bot[1][0]-bot[0][0])),2.2*u));
  b.tongues.forEach(function(j){s.appendChild(rect(X(j.s)-K.TONGUE/2,Y(K.TONGUE/2),K.TONGUE,K.TONGUE,'tongue'));
    s.appendChild(txt(X(j.s),Y(-TH/2)+2.6*u,j.id,'lbl-s','middle',2*u));});
  var a0=b.trim[0],b0=L-b.trim[1];
  s.appendChild(txt(X(a0)+1.5*u,Y(0),'א׳','lbl','end',2.6*u));
  s.appendChild(txt(X(b0)-1.5*u,Y(0),'ב׳','lbl','start',2.6*u));
  return s;}
// פרט קצה: חלון של 36 ס״מ סביב הקצה, מבט־על ומבט צד, בקנה מידה קבוע
function endDetail(b,end){
  var lp=localPts(b),L=lp.L,a0=b.trim[0],b0=L-b.trim[1],isA=end===0,e=isA?a0:b0;
  var X=function(x){return isA?x-e:e-x;},Y=function(v){return isA?-v:v;};   // הקצה ב-0, הקורה נמשכת ימינה
  var W=36,s=svg(-8,-10,W+8,10+TH+K.TONGUE_LEN+18);
  s.appendChild(poly(lp.pts.map(function(p){return [X(p[0]),Y(p[1])];}),'b-'+b.mat));
  s.appendChild(line(0,-TH/2-1.5,0,TH/2+1.5,'axis'));
  var c=b.cuts[end];s.appendChild(txt(.5,-TH/2-4.2,(isA?'קצה א׳ · חיתוך ':'קצה ב׳ · חיתוך ')+cutShort(c),'lbl','end',2.4));
  var ey=TH+7;   // מבט צד
  s.appendChild(rect(0,ey,W+8,TH,'b-'+b.mat));
  b.tongues.forEach(function(j){var d=isA?j.s-a0:b0-j.s;if(d<0||d>W)return;
    s.appendChild(rect(d-K.TONGUE/2,-K.TONGUE/2,K.TONGUE,K.TONGUE,'tongue'));
    s.appendChild(rect(d-K.TONGUE/2,ey+TH,K.TONGUE,K.TONGUE_LEN,'tongue'));
    s.appendChild(txt(d,TH/2+1.8,j.id,'lbl-s','middle',1.8));
    s.appendChild(hdim(0,d,ey+TH+K.TONGUE_LEN+3.5,f1(d),2.2));});
  s.appendChild(txt(W/2,ey-2,'מבט צד — השן מתחת לקורה','lbl-s','middle',1.8));
  return s;}

// פרט חיתוך מוגדל לקצה מפוצל: הקצה בלבד, עם פני שתי הקורות שהוא פוגש, הנסיגה, הזווית וחצאי הרוחב
function notchDetail(b,end){
  var c=b.cuts[end],lp=localPts(b),L=lp.L,A=b.axis[0],d=lp.d,n=lp.n,isA=end===0,e=isA?b.trim[0]:L-b.trim[1];
  var loc=function(p){return [(p[0]-A[0])*d[0]+(p[1]-A[1])*d[1],(p[0]-A[0])*n[0]+(p[1]-A[1])*n[1]];};
  var X=function(u){return isA?u-e:e-u;},Y=function(v){return isA?-v:v;};   // הקצה ב-0, הקורה נמשכת ימינה; צפון למעלה
  var s=svg(-9,-8,27,16),cp=loc(c.corner);
  // פני המסגרת שהקצה פוגש — מקווקו, מהפינה החוצה, עם שם בקצה הרחוק
  c.edges.forEach(function(ed,k){var far=loc(k===0?ed[0]:ed[1]),dx=(far[0]-cp[0]),dy=(far[1]-cp[1]),l=Math.hypot(dx,dy),ux=dx/l,uy=dy/l;
    s.appendChild(line(X(cp[0]),Y(cp[1]),X(cp[0]+ux*6),Y(cp[1]+uy*6),'axis'));
    var lx=cp[0]+ux*5.2-uy*1.4,ly=cp[1]+uy*5.2+ux*1.4;   // מוזז ניצב לקו
    s.appendChild(txt(X(lx),Y(ly),c.parts[k].deg<.5?'קורת המזרח':'האלכסון','lbl-s','middle',.85));});
  s.appendChild(poly(lp.pts.map(function(p){return [X(p[0]),Y(p[1])];}),'b-'+b.mat));
  s.appendChild(line(X(0),Y(0),X(16),Y(0),'axis'));
  s.appendChild(vdim(X(14),Y(TH/2),Y(0),f1(TH/2),.8));s.appendChild(vdim(X(14),Y(0),Y(-TH/2),f1(TH/2),.8));
  c.parts.forEach(function(q,k){var sv=k===0?-1:1;   // דרום = v שלילי, צפון = v חיובי
    s.appendChild(txt(X(9),Y(sv*(TH/2+1.1)),'צד '+q.side+(q.deg<.5?' — ישר':''),'lbl','middle',.9));
    if(q.deg<.5)return;
    s.appendChild(hdim(X(0),X(q.setback),Y(sv*(TH/2+3.9)),'נסיגה '+f1(q.setback),.85,sv<0));   // בדרום התווית מתחת לקו, הרחק מהקשת
    var a0=Math.atan2(sv,0),a1=Math.atan2(sv*TH/2,q.setback),r=2.8,pts=[];
    for(var i=0;i<=12;i++){var a=a0+(a1-a0)*i/12;pts.push(f1(X(r*Math.cos(a)))+','+f1(Y(r*Math.sin(a))));}
    s.appendChild(el('polyline',{points:pts.join(' '),'class':'dim'}));
    var am=(a0+a1)/2;s.appendChild(txt(X((r+1.1)*Math.cos(am)),Y((r+1.1)*Math.sin(am)),f1(q.deg)+'°','lbl','middle',.95));});
  s.appendChild(txt(X(4),Y(7.2),'קצה '+(isA?'א׳':'ב׳')+' של '+b.id+' — מבט־על, מוגדל. הקורה נמשכת ימינה','lbl-s','middle',.8));
  return s;}
function notchHowTo(b,end){
  var c=b.cuts[end],st=c.parts.filter(function(q){return q.deg<.5;})[0],an=c.parts.filter(function(q){return q.deg>=.5;})[0];
  return '<div class="card"><h3>איך חותכים את קצה '+(end?'ב׳':'א׳')+' של '+b.id+'</h3><ol style="margin:0;padding-inline-start:18px">'+
    '<li>חותכים את הקורה ישר לאורך המלא — '+f1(b.len.outer)+' על הציר.</li>'+
    '<li>מסמנים בקצה את קו האמצע של הרוחב ('+f1(TH/2)+' מכל דופן).</li>'+
    '<li><b>צד '+st.side+'</b> נשאר ישר — הוא יושב על קורת המזרח.</li>'+
    '<li><b>צד '+an.side+'</b>: מודדים מהקצה לאורך הדופן החיצונית <b>'+f1(an.setback)+'</b> ס״מ ומסמנים. מחברים בקו ישר מנקודת האמצע שבקצה אל הסימון, וחותכים על הקו. זו זווית <b>'+f1(an.deg)+'°</b> מהחיתוך הישר — הצד הזה יושב על האלכסון.</li>'+
    '<li>בדיקה: הקצה נוגע בשתי הקורות בלי מרווח — הישר במזרח, המשופע באלכסון. השן נשארת במקומה: '+f1(b.tongues[end].s-(end?L0(b):b.trim[0]))+' מהקצה על הציר.</li></ol></div>';}
function L0(b){return localPts(b).L-b.trim[1];}

/* עמוד: תוכנית עם השרוולים סביבו, וחזית של ארבע הפאות */
function postPlanSVG(p){
  var s=svg(-22,-22,44,44),cx=p.x,cy=p.y;
  var tp=function(pts){return pts.map(function(q){return [-(q[0]-cx),-(q[1]-cy)];});};   // צפון משמאל, הקיר למטה
  p.joints.forEach(function(j){s.appendChild(poly(tp(j.sleevePts),'sleeve'));s.appendChild(poly(tp(j.tonguePts),'tongue'));});
  s.appendChild(poly(tp(p.pts),'post'));
  var groups={};   // שרוול עליון ותחתון באותו מקום בתוכנית — תווית אחת
  p.joints.forEach(function(j){var k=f1(j.c[0])+','+f1(j.c[1]);(groups[k]=groups[k]||{j:j,ids:[]}).ids.push(j.id+(j.level==='low'?'↓':''));});
  Object.keys(groups).forEach(function(k){var g=groups[k],j=g.j,c=[-(j.c[0]-cx),-(j.c[1]-cy)],d=[-j.d[0],-j.d[1]];
    s.appendChild(txt(c[0]+d[0]*10,c[1]+d[1]*10,g.ids.join(' / '),'lbl-s','middle',1.6));});
  s.appendChild(txt(0,-20,'↓ = גם במפלס 70, באותו מקום','lbl-s','middle',1.5));
  s.appendChild(txt(-16,-17,'← צפון','lbl-s','middle',1.7));
  s.appendChild(txt(0,20,'↓ הקיר','lbl-s','middle',1.7));
  return s;}
function postElevSVG(p){
  var faces=[[[-1,0],'דרום'],[[0,1],'מזרח'],[[1,0],'צפון'],[[0,-1],'מערב (הקיר)']];
  var colW=40,H=p.h,s=svg(0,-14,faces.length*colW,H+30);
  faces.forEach(function(f,i){var x0=i*colW+colW/2,u=f[0];
    s.appendChild(rect(x0-TH/2,0,TH,H,'post'));
    s.appendChild(txt(x0,-7,f[1],'lbl','middle',5));
    p.joints.filter(function(j){return j.face.u[0]===u[0]&&j.face.u[1]===u[1];}).forEach(function(j){
      // בחזית הפאה: השרוול מוזז הצידה ב-lateral (חיובי = לצד v=[-u1,u0]); על המסך פאה שמאלה = כיוון הצפייה
      var lat=-j.face.lateral;   // ימין המסך כשמביטים בפאה מבחוץ = [u1,-u0] = מינוס v של המודל
      s.appendChild(rect(x0+lat-TH/2,H-j.zb,TH,K.SLEEVE_LEN,'sleeve'));
      s.appendChild(rect(x0+lat-K.TONGUE/2,H-j.zb,K.TONGUE,K.TONGUE_LEN,'tongue'));
      s.appendChild(txt(x0+lat,H-j.zb+K.SLEEVE_LEN+5,j.id,'lbl-s','middle',4.5));
      s.appendChild(vdim(x0+colW/2-5,H-j.zb,H,f1(j.zb),4));});
  });
  s.appendChild(txt(faces.length*colW/2,H+12,'גובה העמוד '+f1(H)+' · ראש כל שרוול בגובה תחתית הקורה שלו','lbl-s','middle',4.5));
  return s;}

/* חתך גבהים כללי */
function heightsSVG(){
  var s=svg(-40,-20,280,K.Z_TOP+50),x=20;
  s.appendChild(rect(x,K.Z_TOP-(K.Z_TOP-TH),TH,K.Z_TOP-TH,'post'));   // העמוד 0..276
  var flip=function(z){return K.Z_TOP-z;};
  s.appendChild(rect(x-30,flip(K.Z_TOP),40,TH,'b-frame'));   // קורה עליונה 276..280
  s.appendChild(rect(x+TH+1,flip(K.Z_TOP-TH),TH,K.SLEEVE_LEN,'sleeve'));s.appendChild(rect(x+TH+1+(TH-K.TONGUE)/2,flip(K.Z_TOP-TH),K.TONGUE,K.TONGUE_LEN,'tongue'));
  s.appendChild(rect(x+TH+1,flip(K.Z_TOP-TH)-TH,26,TH,'b-frame'));
  s.appendChild(rect(x+TH+1,flip(K.Z_LOW-TH),TH,K.SLEEVE_LEN,'sleeve'));s.appendChild(rect(x+TH+1+(TH-K.TONGUE)/2,flip(K.Z_LOW-TH),K.TONGUE,K.TONGUE_LEN,'tongue'));
  s.appendChild(rect(x+TH+1,flip(K.Z_LOW),26,TH,'b-low'));
  var L=function(z,t){s.appendChild(line(x-34,flip(z),x+34,flip(z),'axis'));s.appendChild(txt(x+38,flip(z),f1(z)+' — '+t,'lbl','end',6.5));};
  L(K.Z_TOP,'ראש המסגרת');L(K.Z_TOP-TH-K.SLEEVE_LEN,'תחתית השרוול');L(K.Z_LOW,'ראש מפלס 70');L(K.Z_LOW-TH-K.SLEEVE_LEN,'תחתית השרוול');L(0,'רצפה');
  s.appendChild(txt(x+38,flip(K.Z_TOP-TH)+7,f1(K.Z_TOP-TH)+' — תחתית הקורה = ראש העמוד = ראש השרוול','lbl','end',6.5));
  s.appendChild(txt(x+38,flip(K.Z_LOW-TH)+7,f1(K.Z_LOW-TH)+' — תחתית הקורה = ראש השרוול','lbl','end',6.5));
  return s;}

/* ---------- השקופיות ---------- */
var deck=document.getElementById('deck'),slides=[];
function slide(title,sub,build){var sec=document.createElement('section');sec.className='slide';
  var h=document.createElement('h2');h.textContent=title;if(sub){var sm=document.createElement('small');sm.textContent=sub;h.appendChild(sm);}
  sec.appendChild(h);build(sec);deck.appendChild(sec);slides.push({title:title,el:sec});return sec;}
function html(sec,s){var d=document.createElement('div');d.innerHTML=s;while(d.firstChild)sec.appendChild(d.firstChild);}
function fig(sec,svgEl,cap){var f=document.createElement('figure');f.className='fig';f.appendChild(svgEl);if(cap){var c=document.createElement('figcaption');c.textContent=cap;f.appendChild(c);}sec.appendChild(f);return f;}
function grid(sec){var g=document.createElement('div');g.className='grid';sec.appendChild(g);return g;}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;');}

var topB=M.beams.filter(function(b){return b.level==='top';}),lowB=M.beams.filter(function(b){return b.level==='low';}),crossB=M.beams.filter(function(b){return b.level==='cross';});
var plates=M.joints.filter(function(j){return j.plate;});
var totalBeam=M.beams.reduce(function(a,b){return a+b.len.outer;},0), totalPost=M.posts.reduce(function(a,p){return a+p.h;},0), totalSleeve=M.joints.length*K.SLEEVE_LEN;

/* 1. שער */
slide('סוכה למרפסת — החלקים','שלד 1:1 · כל מספר כאן מגיע מהמודל של השרטוט',function(sec){
  html(sec,'<p class="lead">שני מפלסים, שלוש קורות תומכות, '+M.posts.length+' עמודים, ומחבר אחד לכל קצה קורה: שרוול 40×40 אנכי לצד העמוד (או על פלטה בקיר), ושן '+K.TONGUE+'×'+K.TONGUE+' מתחת לקורה שיורדת לתוכו. המסגרת העליונה יושבת <b>על</b> העמודים ונפגשת בפינות במיטר; מפלס 70 עובר <b>בין</b> העמודים ולכן קצר יותר ונגמר על פני העמוד.</p>'+
   '<div class="totals">'+
   '<div class="card"><div class="n">'+topB.length+'</div><div class="l">קורות מסגרת עליונה</div></div>'+
   '<div class="card"><div class="n">'+lowB.length+'</div><div class="l">קטעי מפלס 70</div></div>'+
   '<div class="card"><div class="n">'+crossB.length+'</div><div class="l">קורות תומכות</div></div>'+
   '<div class="card"><div class="n">'+M.posts.length+'</div><div class="l">עמודים · '+f0(M.posts[0].h)+' ס״מ</div></div>'+
   '<div class="card"><div class="n">'+M.joints.length+'</div><div class="l">שרוולים + '+M.joints.length+' שיניים</div></div>'+
   '<div class="card"><div class="n">'+plates.length+'</div><div class="l">פלטות קיר</div></div>'+
   '<div class="card"><div class="n">'+f1((totalBeam+totalPost+totalSleeve)/100)+' מ׳</div><div class="l">פרופיל '+TH*10+'×'+TH*10+' סה״כ (קורות '+f1(totalBeam/100)+' · עמודים '+f1(totalPost/100)+' · שרוולים '+f1(totalSleeve/100)+')</div></div>'+
   '<div class="card"><div class="n">'+f1(M.joints.length*K.TONGUE_LEN/100)+' מ׳</div><div class="l">פרופיל '+K.TONGUE*10+'×'+K.TONGUE*10+' לשיניים</div></div>'+
   '</div>');
});

/* 2. מוסכמות */
slide('איך לקרוא את המצגת','מוסכמות ומקרא',function(sec){
  html(sec,'<div class="grid"><div>'+
   '<div class="card"><h3>צירים</h3>“מדרום” = מרחק מהפינה הדרומית לאורך הקיר. “מפני הקיר” = מרחק מהקיר המערבי החוצה. גבהים מהרצפה. בתוכניות הצפון משמאל והקיר למטה — כמו בשרטוט התלת־ממדי.</div>'+
   '<div class="card"><h3>שמות</h3><span class="tag t-top">ע1–ע6</span> מסגרת עליונה, לפי הסדר דרום → אלכסון → מזרח → אלכסון → צפון → מערב פתוח. <span class="tag t-low">ת1–ת6</span> מפלס 70, אותו סדר; קורה שעוברת עמוד ביניים מתפצלת לקטעים (ת3.1, ת3.2). <span class="tag t-cross">ק1–ק3</span> תומכות. <span class="tag t-joint">·א ·ב ·ג</span> קצות הקורה — אותו שם על השרוול ועל השן.</div>'+
   '<div class="card"><h3>קצה א׳ וקצה ב׳</h3>בכל קורה קצה א׳ הוא הראשון בסדר ההיקף (במסגרות) או הקצה המזרחי (בתומכות). בשרטוט הקורה קצה א׳ מימין.</div>'+
   '</div><div>'+
   '<div class="card"><h3>שלושה סוגי קצה</h3><b>מיטר</b> — במסגרת העליונה: שתי הקורות נפגשות זו בזו מעל העמוד, כל אחת חתוכה בחצי זווית הפינה.<br><b>על פני העמוד</b> — במפלס 70: הקורה נעצרת על פאת העמוד, חתוכה במקביל לפאה; בפינת אלכסון זו זווית.<br><b>קיר</b> — חיתוך ישר, הקורה נגמרת על הפלטה (עובי '+K.PLATE*10+' מ״מ).</div>'+
   '<div class="card"><h3>המחבר</h3>שרוול '+TH+'×'+TH+' באורך '+K.SLEEVE_LEN+', מרותך אנכית לצד העמוד וראשו בגובה תחתית הקורה. שן '+K.TONGUE+'×'+K.TONGUE+' באורך '+K.TONGUE_LEN+' מרותכת מתחת לקורה, במרכז רוחבה, ויורדת לתוך השרוול. השרוול מסובב לכיוון הקורה; השן תמיד אנכית.</div>'+
   '</div></div>');
});

/* 3–4. תוכניות */
slide('תוכנית — מסגרת עליונה וקורות תומכות','גובה 276–280 · על העמודים',function(sec){
  fig(sec,planSVG('top'),'ליד כל קורה: שמה ואורך החוץ שלה. הריבועים הכחולים — עמודים; החומים — שרוולים; האפורים — פלטות בקיר.');
});
slide('תוכנית — מפלס 70','גובה 66–70 · בין העמודים',function(sec){
  fig(sec,planSVG('low'),'הקורות נגמרות על פני העמודים, ולכן קצרות מהמסגרת העליונה. עמוד ביניים מפצל קורה לשני קטעים, כל אחד עם שרוול משלו.');
});
slide('חתך גבהים','מה יושב על מה',function(sec){
  var g=grid(sec);fig(g,heightsSVG(),'העמוד נגמר בדיוק מתחת למסגרת העליונה. ראש כל שרוול בגובה תחתית הקורה שלו.').classList.add('tall');
  var t=document.createElement('div');t.innerHTML='<table><tr><th>גובה</th><th>מה</th></tr>'+
   [[K.Z_TOP,'ראש המסגרת העליונה והתומכות'],[K.Z_TOP-TH,'תחתית המסגרת העליונה = ראש העמוד = ראש השרוולים העליונים'],[K.Z_TOP-TH-K.TONGUE_LEN,'תחתית השן העליונה'],[K.Z_TOP-TH-K.SLEEVE_LEN,'תחתית השרוול העליון'],
    [K.Z_LOW,'ראש מפלס 70'],[K.Z_LOW-TH,'תחתית מפלס 70 = ראש השרוולים התחתונים'],[K.Z_LOW-TH-K.TONGUE_LEN,'תחתית השן התחתונה'],[K.Z_LOW-TH-K.SLEEVE_LEN,'תחתית השרוול התחתון'],[0,'רצפה']]
   .map(function(r){return '<tr><td class="num">'+f1(r[0])+'</td><td>'+r[1]+'</td></tr>';}).join('')+'</table>';g.appendChild(t);
});

/* 6. קורות — שקופית לכל קורה */
function beamSlide(b){
  slide(b.name,levelName[b.level]+' · כמות 1',function(sec){
    var g=grid(sec);
    var left=document.createElement('div');
    fig(left,beamSVG(b),'מבט־על בקנה מידה אמיתי, קצה א׳ מימין. אורכי שתי הצלעות כשהן שונות (מיטר).');
    var two=document.createElement('div');two.className='two';
    fig(two,endDetail(b,0),'פרט קצה א׳ — החיתוך, והשן במרחקה מהקצה על הציר');
    fig(two,endDetail(b,1),'פרט קצה ב׳ — החיתוך, והשן במרחקה מהקצה על הציר');
    left.appendChild(two);
    var howto='';
    [0,1].forEach(function(end){if(b.cuts[end].kind!=='notch')return;
      fig(left,notchDetail(b,end),'פרט חיתוך מוגדל של קצה '+(end?'ב׳':'א׳')+'. מקווקו — פני שתי הקורות שהקצה יושב עליהן; הנסיגה נמדדת על הדופן החיצונית.').classList.add('notch');
      howto+=notchHowTo(b,end);});
    g.appendChild(left);
    var side=document.createElement('div');
    var hostA=b.hosts?hostNameOf(b.hosts[0]):'',hostB=b.hosts?hostNameOf(b.hosts[b.hosts.length-1]):'';
    var rows='<div class="card"><h3>מידות</h3>'+
      (b.cuts[0].kind==='notch'?'אורך על הציר <b>'+f1(b.len.outer)+'</b>'+b.cuts[0].parts.map(function(q){return ' · צד '+q.side+' <b>'+f1(q.len)+'</b>';}).join(''):b.len.outer-b.len.inner>.05?'אורך חוץ <b>'+f1(b.len.outer)+'</b> · פנים <b>'+f1(b.len.inner)+'</b>':'אורך <b>'+f1(b.len.outer)+'</b>')+
      '<br>פרופיל '+TH+'×'+TH+' · גובה '+f1(b.z0)+'–'+f1(b.z1)+'</div>'+
      '<div class="card"><h3>קצוות</h3><table><tr><th></th><th>חיתוך</th><th>יושבת על</th></tr>'+
      '<tr><td>א׳</td><td>'+cutText(b.cuts[0])+' · '+cutKind[b.cuts[0].kind]+'</td><td>'+esc(hostA)+'</td></tr>'+
      '<tr><td>ב׳</td><td>'+cutText(b.cuts[1])+' · '+cutKind[b.cuts[1].kind]+'</td><td>'+esc(hostB)+'</td></tr></table></div>'+
      '<div class="card"><h3>שיניים מתחת לקורה — '+b.tongues.length+'</h3><table><tr><th>שן</th><th class="num">מקצה א׳</th><th class="num">מקצה ב׳</th><th>לשרוול על</th></tr>'+
      b.tongues.map(function(j){var a0=b.trim[0],b0=lp(b).L-b.trim[1];return '<tr><td>'+j.id+'</td><td class="num">'+f1(j.s-a0)+'</td><td class="num">'+f1(b0-j.s)+'</td><td>'+esc(j.host.name)+(j.dev>=.5?' · השרוול מסובב '+f1(j.dev)+'°':'')+'</td></tr>';}).join('')+
      '</table><div style="font-size:12.5px;color:var(--mute);margin-top:6px">המרחק נמדד על ציר הקורה, מהנקודה שבה החיתוך חוצה את הציר, עד מרכז השן. השן במרכז רוחב הקורה.</div></div>';
    side.innerHTML=(howto?howto:'')+rows;g.appendChild(side);
  });
}
function lp(b){return localPts(b);}
function hostNameOf(key){var k=key.charAt(0);if(k==='@')return 'פלטה על פני הקיר, '+f1(+key.slice(1))+' מדרום';if(k==='#')return 'פלטה על קצה הקיר';
  var p=M.posts.filter(function(q){return q.key===key;})[0];return 'עמוד '+p.key+' — '+p.name;}
topB.forEach(beamSlide);crossB.forEach(beamSlide);lowB.forEach(beamSlide);

/* 7. עמודים — שקופית לכל עמוד: איפה בדיוק מרתכים כל שרוול */
M.posts.forEach(function(p){
  slide('עמוד '+p.key+' — '+p.name,f1(p.x)+' מדרום · '+f1(p.y)+' מפני הקיר · גובה '+f1(p.h),function(sec){
    var g=grid(sec);
    var left=document.createElement('div');
    var f1el=fig(left,postPlanSVG(p),'מבט־על: העמוד באמצע, השרוולים סביבו. שרוול מסובב = פינת אלכסון.');
    fig(left,postElevSVG(p),'חזית של כל פאה: השרוולים על הפאה, בגובהם ובהזזה הצידה. הקו — גובה ראש השרוול.').classList.add('tall');
    g.appendChild(left);
    var side=document.createElement('div');
    side.innerHTML='<div class="card"><h3>שרוולים על העמוד — '+p.joints.length+'</h3><table><tr><th>שרוול</th><th>פאה</th><th class="num">ראש בגובה</th><th>סיבוב</th><th class="num">מהפאה</th><th class="num">הצידה</th></tr>'+
      p.joints.map(function(j){return '<tr><td>'+j.id+'<br><span style="color:var(--mute);font-size:12px">'+esc(j.beamName)+'</span></td><td>'+j.face.name+'</td><td class="num">'+f1(j.zb)+'</td><td>'+(j.dev<.5?'ישר':f1(j.dev)+'° לכיוון הקורה')+'</td><td class="num">'+f1(j.face.fromFace)+'</td><td class="num">'+(Math.abs(j.face.lateral)<.05?'0':f1(Math.abs(j.face.lateral))+' ל'+j.face.lateralName)+'</td></tr>';}).join('')+
      '</table><div style="font-size:12.5px;color:var(--mute);margin-top:6px">“מהפאה” — מרחק מרכז השרוול מפני הפאה (0 = צמוד). “הצידה” — הזזת מרכז השרוול ממרכז הפאה לאורכה. ראש השרוול = תחתית הקורה. השרוול פתוח למעלה; השן יורדת מהקורה לתוכו.</div></div>';
    g.appendChild(side);
  });
});

/* 8. הקיר */
slide('הקיר המערבי — פלטות ושרוולים','בלי עמודים לאורך הקיר',function(sec){
  var g=grid(sec);
  var W=K.WALL,H=K.Z_TOP+20,s=svg(-10,-20,W+40,H+30);
  var sx=function(x){return W-x;},flip=function(z){return K.Z_TOP-z;};
  s.appendChild(rect(sx(W),flip(K.Z_TOP),W,K.Z_TOP,'wall'));
  plates.forEach(function(j){var x=j.host.kind==='wallS'?j.host.pos[0]:W;
    s.appendChild(rect(sx(x)-K.PLATE_W/2,flip(j.plate.z1),K.PLATE_W,j.plate.z1-j.plate.z0,'plate'));
    s.appendChild(rect(sx(x)-TH/2,flip(j.zb),TH,K.SLEEVE_LEN,'sleeve'));
    s.appendChild(txt(sx(x),flip(j.zb)+K.SLEEVE_LEN+8,j.id,'lbl-s','middle',6));});
  s.appendChild(txt(sx(W/2),flip(-10),'חזית הקיר מבפנים · צפון משמאל · שתי הפלטות בקצה השמאלי יושבות על קצה הקיר','lbl-s','middle',7));
  fig(g,s,'חזית הקיר: פלטה '+K.PLATE_W+'×'+(K.SLEEVE_LEN+4)+' לכל שרוול. שתי הפלטות בקצה הקיר יושבות על פני הקצה, לא על החזית.');
  var side=document.createElement('div');
  side.innerHTML='<div class="card"><h3>פלטות — '+plates.length+'</h3><table><tr><th>שרוול</th><th>איפה</th><th class="num">מיקום</th><th class="num">ראש השרוול</th><th class="num">פלטה מ־</th><th class="num">עד</th></tr>'+
    plates.map(function(j){return '<tr><td>'+j.id+'<br><span style="color:var(--mute);font-size:12px">'+esc(j.beamName)+'</span></td><td>'+(j.host.kind==='wallS'?'פני הקיר':'קצה הקיר המזרחי')+'</td><td class="num">'+(j.host.kind==='wallS'?f1(j.host.pos[0])+' מדרום':f1(-j.host.pos[1])+' לתוך עובי הקיר')+'</td><td class="num">'+f1(j.zb)+'</td><td class="num">'+f1(j.plate.z0)+'</td><td class="num">'+f1(j.plate.z1)+'</td></tr>';}).join('')+
    '</table><div style="font-size:12.5px;color:var(--mute);margin-top:6px">פלטה '+K.PLATE_W+'×'+(K.SLEEVE_LEN+4)+', עובי 6 מ״מ, 4 ברגי עיגון — טרם הוכרע. השרוול מרותך למרכז הפלטה, ראשו '+2+' ס״מ מתחת לקצה העליון שלה.</div></div>';
  g.appendChild(side);
});

/* 9. רשימת חיתוך */
slide('רשימת חיתוך','כל הקורות, העמודים והמחברים',function(sec){
  var t='<table><tr><th>חלק</th><th>כמות</th><th class="num">אורך</th><th class="num">פנים</th><th>קצה א׳</th><th>קצה ב׳</th><th>שיניים</th></tr>';
  M.beams.forEach(function(b){t+='<tr><td><span class="tag t-'+b.level+'">'+b.id+'</span>'+esc(b.name)+'</td><td>1</td><td class="num">'+f1(b.len.outer)+'</td><td class="num">'+(b.len.outer-b.len.inner>.05?f1(b.len.inner):'—')+'</td><td>'+cutShort(b.cuts[0])+'</td><td>'+cutShort(b.cuts[1])+'</td><td>'+b.tongues.map(function(j){return j.id;}).join(', ')+'</td></tr>';});
  t+='<tr><td><span class="tag t-post">עמוד</span>'+TH+'×'+TH+'</td><td>'+M.posts.length+'</td><td class="num">'+f1(M.posts[0].h)+'</td><td class="num">—</td><td>ישר</td><td>ישר</td><td></td></tr>';
  t+='<tr><td><span class="tag t-joint">שרוול</span>'+TH+'×'+TH+'</td><td>'+M.joints.length+'</td><td class="num">'+f1(K.SLEEVE_LEN)+'</td><td class="num">—</td><td>ישר</td><td>ישר</td><td></td></tr>';
  t+='<tr><td><span class="tag t-joint">שן</span>'+K.TONGUE+'×'+K.TONGUE+'</td><td>'+M.joints.length+'</td><td class="num">'+f1(K.TONGUE_LEN)+'</td><td class="num">—</td><td>ישר</td><td>ישר</td><td></td></tr>';
  t+='<tr><td><span class="tag" style="background:var(--plate)">פלטה</span>'+K.PLATE_W+'×'+(K.SLEEVE_LEN+4)+' · 6 מ״מ</td><td>'+plates.length+'</td><td class="num">—</td><td class="num">—</td><td></td><td></td><td></td></tr>';
  t+='</table>';
  html(sec,t+'<p class="lead" style="margin-top:12px">סה״כ פרופיל '+TH*10+'×'+TH*10+': קורות '+f1(totalBeam/100)+' מ׳ + עמודים '+f1(totalPost/100)+' מ׳ + שרוולים '+f1(totalSleeve/100)+' מ׳ = <b>'+f1((totalBeam+totalPost+totalSleeve)/100)+' מ׳</b> (לפי אורך החוץ, בלי פחת חיתוך). פרופיל '+K.TONGUE*10+'×'+K.TONGUE*10+' לשיניים: <b>'+f1(M.joints.length*K.TONGUE_LEN/100)+' מ׳</b>.</p>');
});

/* ---------- ניווט ---------- */
var counter=document.getElementById('counter'),toc=document.getElementById('toc');
slides.forEach(function(s,i){var o=document.createElement('option');o.value=i;o.textContent=(i+1)+'. '+s.title;toc.appendChild(o);});
function cur(){return Math.round(deck.scrollLeft/deck.clientWidth);}
function go(i){i=Math.max(0,Math.min(slides.length-1,i));deck.scrollTo({left:i*deck.clientWidth,behavior:'smooth'});}
function sync(){var i=cur();counter.textContent=(i+1)+' / '+slides.length;toc.value=i;}
deck.addEventListener('scroll',function(){clearTimeout(sync._t);sync._t=setTimeout(sync,80);});
document.getElementById('prev').onclick=function(){go(cur()-1);};
document.getElementById('next').onclick=function(){go(cur()+1);};
toc.onchange=function(){go(+toc.value);};
document.addEventListener('keydown',function(e){if(e.key==='ArrowLeft'||e.key==='PageDown'||e.key===' ')go(cur()+1);else if(e.key==='ArrowRight'||e.key==='PageUp')go(cur()-1);});
window.addEventListener('resize',function(){go(cur());});
sync();
})();
