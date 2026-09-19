/* מצגת הזוויות — רק החיתוכים שאינם ישרים. נבנית כולה מ-model.js, כמו מצגת החלקים,
   ולכן כל מעלה וכל סנטימטר כאן זהים לשרטוט. לכל זווית: כמה מעלות, איזה צד של
   הקורה מתקצר ובכמה ס״מ ביחס לצד השני, ואיך מסמנים את זה על הפרופיל. */
(function(){
'use strict';
var M=SukkahModel.build(),K=M.K,TH=K.TH,R=Math.PI/180;
var f1=function(v){return (Math.round(v*10)/10).toFixed(1);};
var f2=function(v){return (Math.round(v*100)/100).toFixed(2);};
var f3=function(v){return v.toFixed(3);};
var dg=function(v){return f1(v)+'°';};
var tanS=function(w,d){return w*Math.tan(d*R);};      // הנסיגה: כמה צד אחד מתקצר ביחס לשני על רוחב w
var cutL=function(w,d){return w/Math.cos(d*R);};      // אורך קו החיתוך על פני הקורה ברוחב w
var PC={};M.posts.forEach(function(p){PC[p.key]=p;});
var topB=M.beams.filter(function(b){return b.level==='top';}),lowB=M.beams.filter(function(b){return b.level==='low';}),crossB=M.beams.filter(function(b){return b.level==='cross';});
var END=['א׳','ב׳'];

/* ---------- SVG ---------- */
var NS='http://www.w3.org/2000/svg';
function el(tag,attrs,kids){var e=document.createElementNS(NS,tag);for(var k in attrs)e.setAttribute(k,attrs[k]);(kids||[]).forEach(function(c){e.appendChild(c);});return e;}
function txt(x,y,s,cls,anchor,fs){var t=el('text',{x:x,y:y,'class':cls||'lbl','text-anchor':anchor||'middle','dominant-baseline':'middle','font-size':fs||8});t.textContent=s;return t;}
function poly(pts,cls){return el('polygon',{points:pts.map(function(p){return f2(p[0])+','+f2(p[1]);}).join(' '),'class':cls});}
function line(x1,y1,x2,y2,cls){return el('line',{x1:x1,y1:y1,x2:x2,y2:y2,'class':cls||'dim'});}
function svg(x,y,w,h){return el('svg',{viewBox:x+' '+y+' '+w+' '+h,preserveAspectRatio:'xMidYMid meet'});}
function hdim(x1,x2,y,label,fs,below){var g=el('g'),t=fs*.5;
  g.appendChild(line(x1,y-t,x1,y+t,'dim'));g.appendChild(line(x2,y-t,x2,y+t,'dim'));g.appendChild(line(x1,y,x2,y,'dim'));
  g.appendChild(txt((x1+x2)/2,y+(below?fs*.85:-fs*.75),label,'dimt','middle',fs));return g;}
function vdim(x,y1,y2,label,fs){var g=el('g'),t=fs*.5;
  g.appendChild(line(x-t,y1,x+t,y1,'dim'));g.appendChild(line(x-t,y2,x+t,y2,'dim'));g.appendChild(line(x,y1,x,y2,'dim'));
  var tx=txt(x-fs*.75,(y1+y2)/2,label,'dimt','middle',fs);tx.setAttribute('transform','rotate(-90 '+f2(x-fs*.75)+' '+f2((y1+y2)/2)+')');g.appendChild(tx);return g;}
// קשת זווית: במערכת המקומית (c, r, זוויות ברדיאנים), ממופה למסך דרך mp
function arc(mp,c,r,a0,a1,cls){var pts=[];for(var i=0;i<=20;i++){var a=a0+(a1-a0)*i/20,p=mp([c[0]+r*Math.cos(a),c[1]+r*Math.sin(a)]);pts.push(f2(p[0])+','+f2(p[1]));}return el('polyline',{points:pts.join(' '),'class':cls||'arc'});}
function arcLabel(mp,c,r,a0,a1,s,fs){var a=(a0+a1)/2,p=mp([c[0]+r*Math.cos(a),c[1]+r*Math.sin(a)]);return txt(p[0],p[1],s,'lbl-b','middle',fs);}
function centroid(pts){var x=0,y=0;pts.forEach(function(p){x+=p[0];y+=p[1];});return [x/pts.length,y/pts.length];}
function compass(v){var s=[];if(v[0]>.3)s.push('צפון');if(v[0]<-.3)s.push('דרום');if(v[1]>.3)s.push('מזרח');if(v[1]<-.3)s.push('מערב');return s.join('־');}

/* קורה במערכת מקומית: u לאורך הציר מקצה א׳, v ניצב — v חיובי = הצלע החיצונית (הפונה החוצה מהסוכה) */
function localPts(b){var A=b.axis[0],B=b.axis[1],dx=B[0]-A[0],dy=B[1]-A[1],l=Math.hypot(dx,dy),d=[dx/l,dy/l],n=[-d[1],d[0]];
  var loc=function(p){return [(p[0]-A[0])*d[0]+(p[1]-A[1])*d[1],(p[0]-A[0])*n[0]+(p[1]-A[1])*n[1]];};
  return {L:l,d:d,n:n,loc:loc,pts:b.pts.map(loc)};}
var faceAdj={'צפון':'הצפונית','דרום':'הדרומית','מזרח':'המזרחית','מערב (הקיר)':'המערבית (הפונה לקיר)'};
function sideName(b,outer){return b.level==='cross'?(outer?'צפון':'דרום'):(outer?'החיצונית':'הפנימית');}
/* קצה של קורה־מלבן (מסגרת עליונה או מפלס 70): מי משתי הצלעות מגיעה רחוק יותר, ובכמה */
function endInfo(b,end){var lp=localPts(b),isA=end===0,p=lp.pts,o=isA?p[0]:p[1],i=isA?p[3]:p[2];
  var s=Math.abs(o[0]-i[0]),outerAhead=isA?o[0]<i[0]:o[0]>i[0];
  return {lp:lp,isA:isA,o:o,i:i,s:s,outerAhead:outerAhead,deg:b.cuts[end].deg,e:isA?b.trim[0]:lp.L-b.trim[1],
    ahead:sideName(b,outerAhead),behind:sideName(b,!outerAhead)};}

/* ---------- ציורים ---------- */
/* העיקרון: קורה ברוחב 4 חתוכה בזווית θ — צד אחד מתקצר ב-4·tanθ */
function principleSVG(){
  var th=30,s=tanS(TH,th),W=30,S=svg(-6,-9,W+12,20);
  var mp=function(p){return [W-p[0],-p[1]];};   // הקצה החתוך משמאל, הקורה נמשכת ימינה
  S.appendChild(poly([[0,-TH/2],[W,-TH/2],[W,TH/2],[s,TH/2]].map(mp),'b-frame'));
  S.appendChild(line(mp([0,0])[0],mp([0,TH/2+3])[1],mp([0,0])[0],mp([0,-TH/2-3])[1],'sq'));
  S.appendChild(txt(mp([0,0])[0]+.2,mp([0,TH/2+3.6])[1],'חיתוך ישר (90°)','lbl-s','start',1));
  S.appendChild(hdim(mp([0,0])[0],mp([s,0])[0],mp([0,TH/2+1.5])[1],'הנסיגה = 4 × tan θ',1.05,false));
  S.appendChild(txt(mp([W/2+5,0])[0],mp([0,-TH/2-1.6])[1],'הצלע הארוכה','lbl','middle',1.1));
  S.appendChild(txt(mp([W/2+3,0])[0],mp([0,TH/2+1.5])[1],'הצלע הקצרה','lbl','middle',1.1));
  S.appendChild(arc(mp,[0,-TH/2],3,Math.PI/2,Math.atan2(TH,s)));
  S.appendChild(arcLabel(mp,[0,-TH/2],4.3,Math.PI/2,Math.atan2(TH,s),'θ',1.3));
  S.appendChild(txt(mp([1,0])[0],mp([0,-TH/2-1.6])[1],'קו החיתוך = 4 ÷ cos θ','lbl-s','start',.95));
  S.appendChild(vdim(mp([W-2,0])[0],mp([0,TH/2])[1],mp([0,-TH/2])[1],f1(TH),1));
  S.appendChild(txt(mp([W/2,0])[0],mp([0,-TH/2-4.6])[1],'הזווית θ נמדדת מהחיתוך הישר. ככל שהיא גדולה — הצלע הקצרה מתקצרת יותר, וקו החיתוך מתארך','lbl-s','middle',.85));
  return S;}

/* פרט קצה מוגדל: הקצה ב-0, הקורה נמשכת ימינה. הקו המקווקו — איפה היה חיתוך ישר;
   המידה — הנסיגה של הצלע שנסוגה; הקשת — הזווית מהחיתוך הישר */
function cutDetail(b,end){
  var E=endInfo(b,end),lp=E.lp,isA=E.isA,e=E.e;
  var X=function(u){return isA?u-e:e-u;},Y=function(v){return isA?-v:v;},mp=function(p){return [X(p[0]),Y(p[1])];};
  var W=24,s=svg(-10.5,-9.8,W+10.5,19.6);
  var Pp=isA?(E.o[0]<E.i[0]?E.o:E.i):(E.o[0]>E.i[0]?E.o:E.i),Pr=Pp===E.o?E.i:E.o;   // הבולט והנסוג
  // הקשר: שאר הקורות באותה קומה (אפור), והעמוד
  M.beams.filter(function(x){return x.level===b.level&&x!==b;}).forEach(function(x){s.appendChild(poly(x.pts.map(lp.loc).map(mp),'ghost'));});
  var host=b.hosts?b.hosts[isA?0:b.hosts.length-1]:null,post=host&&PC[host];
  if(post&&b.level==='low')s.appendChild(poly(post.pts.map(lp.loc).map(mp),'post'));
  s.appendChild(poly(lp.pts.map(mp),'b-'+b.mat));
  if(post&&b.level!=='low')s.appendChild(poly(post.pts.map(lp.loc).map(mp),'post-ghost'));
  if(post){var pc=mp(lp.loc([post.x,post.y]));s.appendChild(txt(pc[0]-3.2,pc[1],'עמוד '+post.key,'lbl-s','start',.95));}
  // קו החיתוך הישר דרך הקודקוד הבולט
  s.appendChild(line(X(Pp[0]),Y(-TH/2-2.8),X(Pp[0]),Y(TH/2+2.8),'sq'));
  s.appendChild(txt(X(Pp[0])-.3,Y(-Math.sign(Pr[1])*(TH/2+3.3)),'חיתוך ישר','lbl-s','start',.85));
  // הנסיגה על הצלע הנסוגה
  var yd=Y(Pr[1]+Math.sign(Pr[1])*2.1);
  s.appendChild(hdim(X(Pp[0]),X(Pr[0]),yd,'נסיגה '+f1(E.s),.95,yd>0));
  // הקשת: מהכיוון הישר אל קו החיתוך, בקודקוד הבולט
  var sv=Math.sign(Pr[1]-Pp[1]),a0=Math.atan2(sv,0),a1=Math.atan2(Pr[1]-Pp[1],Pr[0]-Pp[0]);
  s.appendChild(arc(mp,Pp,2.6,a0,a1));s.appendChild(arcLabel(mp,Pp,4.7,a0,a1,dg(E.deg),1.1));
  // שמות הצלעות
  var lo=Y(TH/2+1.4),li=Y(-TH/2-1.4);
  s.appendChild(txt(X(e+13),lo,'הצלע '+sideName(b,true)+(E.outerAhead?' — מגיעה רחוק יותר':' — נסוגה'),'lbl','middle',.95));
  s.appendChild(txt(X(e+13),li,'הצלע '+sideName(b,false)+(E.outerAhead?' — נסוגה':' — מגיעה רחוק יותר'),'lbl','middle',.95));
  s.appendChild(txt(X(e+7),Y(-8.6),'קצה '+END[end]+' של '+b.id+' — מבט־על, מוגדל. הקורה נמשכת ימינה','lbl-s','middle',.85));
  return s;}

/* פינה של המסגרת העליונה: שתי הקורות נפגשות במיטר מעל העמוד; הזווית הפנימית ביניהן */
function cornerPlan(i){
  var c=M.C[i],W=40,mp=function(p){return [-(p[0]-c[0]),-(p[1]-c[1])];};   // צפון משמאל, הקיר למטה, הפינה במרכז
  var s=svg(-W/2,-W/2,W,W),a=topB[i-1],b=topB[i];
  s.appendChild(poly(M.P.map(mp),'outline'));
  s.appendChild(poly(a.pts.map(mp),'b-'+a.mat));s.appendChild(poly(b.pts.map(mp),'b-'+b.mat));
  var post=PC[b.hosts[0]];s.appendChild(poly(post.pts.map(mp),'post-ghost'));
  var O=M.O[i],I=M.I[i],o=mp(O),q=mp(I);s.appendChild(line(o[0],o[1],q[0],q[1],'miter'));
  var d1=[M.I[i-1][0]-I[0],M.I[i-1][1]-I[1]],d2=[M.I[i+1][0]-I[0],M.I[i+1][1]-I[1]];
  var a1=Math.atan2(d1[1],d1[0]),a2=Math.atan2(d2[1],d2[0]);if(a2<a1)a2+=2*Math.PI;if(a2-a1>Math.PI){var t=a1;a1=a2;a2=t+2*Math.PI;}
  var inner=180-2*b.cuts[0].deg;
  s.appendChild(arc(mp,I,7,a1,a2));s.appendChild(arcLabel(mp,I,11.5,a1,a2,dg(inner),2.1));
  var mid=function(x,k){var cc=centroid(x.pts),d=[cc[0]-c[0],cc[1]-c[1]],l=Math.hypot(d[0],d[1]);return mp([c[0]+d[0]/l*15,c[1]+d[1]/l*15]);};
  var pa=mid(a),pb=mid(b);s.appendChild(txt(pa[0],pa[1],a.id,'lbl-b','middle',2.4));s.appendChild(txt(pb[0],pb[1],b.id,'lbl-b','middle',2.4));
  var om=mp([(O[0]+I[0])/2,(O[1]+I[1])/2]);
  s.appendChild(txt(om[0],om[1]-3.2,'קו המיטר','lbl-s','middle',1.4));
  s.appendChild(txt(0,W/2-2.4,'עמוד '+post.key+' · פנייה '+dg(2*b.cuts[0].deg)+' · זווית פנימית '+dg(inner),'lbl-s','middle',1.9));
  s.appendChild(txt(-W/2+6,-W/2+2.5,'← צפון','lbl-s','middle',1.6));
  return s;}

/* מפת הזוויות: התוכנית עם הזוויות ליד כל פינה וכל קצה משופע */
function overviewSVG(level){
  var W=K.WLEN,H=K.WID,sx=function(x){return W-x;},sy=function(y){return H-y;},mp=function(p){return [sx(p[0]),sy(p[1])];};
  var s=svg(-70,-40,W+140,H+K.WALL_T+90),cc=centroid(M.P);
  s.appendChild(poly(M.wall.pts.map(mp),'wall'));s.appendChild(poly(M.P.map(mp),'outline'));
  var mine=M.beams.filter(function(b){return level==='low'?b.level==='low':b.level!=='low';});
  mine.forEach(function(b){s.appendChild(poly(b.pts.map(mp),'b-'+b.mat));});
  M.posts.forEach(function(p){s.appendChild(poly(p.pts.map(mp),'post'));});
  mine.forEach(function(b){var c=centroid(b.pts),d=[c[0]-cc[0],c[1]-cc[1]],l=Math.hypot(d[0],d[1]),p=mp([c[0]+d[0]/l*14,c[1]+d[1]/l*14]);
    if(b.level==='cross')p=mp([c[0]+8,c[1]-40]);s.appendChild(txt(p[0],p[1],b.id,'lbl-b','middle',11));});
  var lab=function(pt,str,cls,fs){var p=mp(pt);var t=txt(p[0],p[1],str,cls||'lbl-b','middle',fs||12);t.setAttribute('class',(cls||'lbl-b')+' ang');s.appendChild(t);};
  if(level!=='low'){
    for(var i=1;i<=5;i++){var m=topB[i].cuts[0].deg,c=M.C[i],d=[cc[0]-c[0],cc[1]-c[1]],l=Math.hypot(d[0],d[1]);
      lab([c[0]+d[0]/l*48,c[1]+d[1]/l*48],'פינה '+i+' · '+dg(180-2*m),'lbl-b',13);
      [topB[i-1],topB[i]].forEach(function(b){var e=centroid(b.pts),u=[e[0]-c[0],e[1]-c[1]],ul=Math.hypot(u[0],u[1]),n=[-u[1]/ul,u[0]/ul];
        if((n[0]*(c[0]-cc[0])+n[1]*(c[1]-cc[1]))<0)n=[-n[0],-n[1]];
        var dd=Math.min(50,b.len.outer*.33);lab([c[0]+u[0]/ul*dd+n[0]*13,c[1]+u[1]/ul*dd+n[1]*13],'מיטר '+dg(m),'lbl',10);});}
    crossB.forEach(function(b){if(b.cuts[0].kind!=='notch')return;var q=b.cuts[0].parts.filter(function(p){return p.deg>=.5;})[0];
      lab([b.x+(q.side==='דרום'?-34:34),K.WID-66],b.id+' צד '+q.side+' '+dg(q.deg),'lbl',10);});
  } else {
    lowB.forEach(function(b){b.cuts.forEach(function(c,k){if(c.deg<.5)return;var A=b.axis[k],B=b.axis[1-k],u=[B[0]-A[0],B[1]-A[1]],l=Math.hypot(u[0],u[1]);
      var d=[cc[0]-A[0],cc[1]-A[1]],dl=Math.hypot(d[0],d[1]);lab([A[0]+u[0]/l*26+d[0]/dl*22,A[1]+u[1]/l*26+d[1]/dl*22],b.id+' '+END[k]+' '+dg(c.deg),'lbl',10);});});
  }
  s.appendChild(txt(sx(K.WALL/2),sy(-K.WALL_T)+10,'הקיר המערבי','lbl-s','middle',9));
  s.appendChild(txt(sx(K.WLEN)-10,-28,'← צפון','lbl-s','middle',10));s.appendChild(txt(sx(0)+10,-28,'דרום →','lbl-s','middle',10));
  return s;}

/* מאתר: תוכנית קטנה, הקורות המבוקשות בכתום וטבעת סביב הפינה */
function locator(hlIds,ring,level){
  var W=K.WLEN,H=K.WID,sx=function(x){return W-x;},sy=function(y){return H-y;},mp=function(p){return [sx(p[0]),sy(p[1])];};
  var s=svg(-60,-50,W+120,H+K.WALL_T+90);
  s.appendChild(poly(M.wall.pts.map(mp),'wall'));s.appendChild(poly(M.P.map(mp),'outline'));
  M.beams.filter(function(b){return level==='low'?b.level==='low':b.level!=='low';}).forEach(function(b){s.appendChild(poly(b.pts.map(mp),hlIds.indexOf(b.id)>=0?'hl':'iso-dim'));});
  M.posts.forEach(function(p){s.appendChild(poly(p.pts.map(mp),'post'));});
  if(ring){var r=mp(ring);s.appendChild(el('circle',{cx:r[0],cy:r[1],r:22,'class':'hl-ring'}));}
  s.appendChild(txt(sx(K.WALL/2),sy(-K.WALL_T)+14,'הקיר המערבי','lbl-s','middle',13));
  s.appendChild(txt(sx(K.WLEN)-20,-34,'← צפון','lbl','middle',15));s.appendChild(txt(sx(0)+20,-34,'דרום →','lbl','middle',15));
  s.appendChild(txt(sx(K.WLEN/2),-34,'מזרח ↑ (הצד הפתוח)','lbl-s','middle',13));
  return s;}

/* קורה באורך מלא, קנה מידה אמיתי: שתי הצלעות ואורכיהן */
function beamSVG(b){
  var lp=localPts(b),L=lp.L,u=Math.max(L/60,1.2),mg=8*u;
  var X=function(x){return L-x;},Y=function(v){return -v;};
  var s=svg(-mg,-mg-4*u,L+2*mg,2*mg+TH+10*u),p=lp.pts;
  s.appendChild(poly(p.map(function(q){return [X(q[0]),Y(q[1])];}),'b-'+b.mat));
  s.appendChild(hdim(X(p[0][0]),X(p[1][0]),Y(TH/2)-2*u,'הצלע '+sideName(b,true)+' '+f1(Math.abs(p[1][0]-p[0][0])),2.2*u));
  s.appendChild(hdim(X(p[3][0]),X(p[2][0]),Y(-TH/2)+4.5*u,'הצלע '+sideName(b,false)+' '+f1(Math.abs(p[2][0]-p[3][0])),2.2*u,true));
  s.appendChild(txt(X(b.trim[0])+1.5*u,Y(0),'א׳','lbl','end',2.6*u));s.appendChild(txt(X(L-b.trim[1])-1.5*u,Y(0),'ב׳','lbl','start',2.6*u));
  return s;}

/* פרט קצה מפוצל (קורה תומכת) — כמו במצגת החלקים, עם הדגשת הנסיגה על חצי הרוחב */
function notchDetail(b,end){
  var c=b.cuts[end],lp=localPts(b),L=lp.L,isA=end===0,e=isA?b.trim[0]:L-b.trim[1];
  var X=function(u){return isA?u-e:e-u;},Y=function(v){return isA?-v:v;};
  var s=svg(-9,-8,27,16),cp=lp.loc(c.corner);
  c.edges.forEach(function(ed,k){var far=lp.loc(k===0?ed[0]:ed[1]),dx=(far[0]-cp[0]),dy=(far[1]-cp[1]),l=Math.hypot(dx,dy),ux=dx/l,uy=dy/l;
    var st=c.parts[k].deg<.5,ln=st?5.5:7;   // הקטע הישר קצר יותר, ותוויתו תמיד משמאל לקו
    s.appendChild(line(X(cp[0]),Y(cp[1]),X(cp[0]+ux*ln),Y(cp[1]+uy*ln),'axis'));
    var lx=st?cp[0]-1.6:cp[0]+ux*8.6-uy*1.2,ly=st?cp[1]+uy*6.8:cp[1]+uy*8.6+ux*1.2;
    s.appendChild(txt(X(lx),Y(ly),c.parts[k].deg<.5?'קורת המזרח':'האלכסון','lbl-s','middle',.85));});
  s.appendChild(poly(lp.pts.map(function(p){return [X(p[0]),Y(p[1])];}),'b-'+b.mat));
  s.appendChild(line(X(0),Y(0),X(16),Y(0),'axis'));
  s.appendChild(line(X(0),Y(-TH/2-2.2),X(0),Y(TH/2+2.2),'sq'));
  s.appendChild(vdim(X(14),Y(TH/2),Y(0),f1(TH/2),.8));s.appendChild(vdim(X(14),Y(0),Y(-TH/2),f1(TH/2),.8));
  c.parts.forEach(function(q,k){var sv=k===0?-1:1;
    s.appendChild(txt(X(11),Y(sv*(TH/2+1.1)),'צד '+q.side+(q.deg<.5?' — ישר, על קורת המזרח':' — נסוג, על האלכסון'),'lbl','middle',.9));
    if(q.deg<.5)return;
    s.appendChild(hdim(X(0),X(q.setback),Y(sv*(TH/2+3.9)),'נסיגה '+f1(q.setback),.85,sv<0));
    var a0=Math.atan2(sv,0),a1=Math.atan2(sv*TH/2,q.setback),mp=function(p){return [X(p[0]),Y(p[1])];};
    s.appendChild(arc(mp,[0,0],2.8,a0,a1));s.appendChild(arcLabel(mp,[0,0],3.9,a0,a1,dg(q.deg),.95));});
  var svS=c.parts[0].deg<.5?-1:1;   // הכיתוב בצד הישר, הרחק מהאלכסון ומהמידה
  s.appendChild(txt(X(17.4),Y(svS*6.4),'קצה '+END[end]+' של '+b.id+' — מבט־על, מוגדל. הקורה נמשכת ימינה','lbl-s','start',.75));
  return s;}

/* סיבוב השרוול: מבט־על על העמוד, שרוול מסובב, הזווית בינו לפאה */
function sleeveSVG(post,J){
  var W=36,mp=function(p){return [-(p[0]-post.x),-(p[1]-post.y)];},s=svg(-W/2,-W/2,W,W);
  post.joints.forEach(function(j){if(j.dev<.5)s.appendChild(poly(j.sleevePts.map(mp),'ghost'));});
  s.appendChild(poly(J.sleevePts.map(mp),'sleeve'));s.appendChild(poly(J.tonguePts.map(mp),'tongue'));
  s.appendChild(poly(post.pts.map(mp),'post'));
  var u=J.face.u,v=[-u[1],u[0]],fp=[post.x+u[0]*TH/2,post.y+u[1]*TH/2];   // הפאה
  var L=function(a,b,cls){var p=mp(a),q=mp(b);return line(p[0],p[1],q[0],q[1],cls);};
  s.appendChild(L([fp[0]-v[0]*13,fp[1]-v[1]*13],[fp[0]+v[0]*13,fp[1]+v[1]*13],'sq'));
  // ציר הקורה מהמרכז החוצה, הניצב לפאה, והקשת ביניהם
  var c0=[post.x,post.y],d=J.d,a0=Math.atan2(u[1],u[0]),a1=Math.atan2(d[1],d[0]);if(a1-a0>Math.PI)a1-=2*Math.PI;if(a0-a1>Math.PI)a1+=2*Math.PI;
  s.appendChild(L(c0,[c0[0]+d[0]*13,c0[1]+d[1]*13],'axis'));s.appendChild(L(c0,[c0[0]+u[0]*9,c0[1]+u[1]*9],'axis'));
  s.appendChild(arc(mp,c0,11,a0,a1));s.appendChild(arcLabel(mp,c0,13.4,a0,a1,dg(J.dev),1.7));
  var e=mp([c0[0]+d[0]*15,c0[1]+d[1]*15]);s.appendChild(txt(e[0],e[1],'לכיוון '+J.beamName.split(' ')[0],'lbl-s','middle',1.25));
  var fq=mp([c0[0]+u[0]*10.6,c0[1]+u[1]*10.6]);s.appendChild(txt(fq[0],fq[1],'ניצב לפאה','lbl-s','middle',1.15));
  var fl=mp([fp[0]+v[0]*10,fp[1]+v[1]*10]);s.appendChild(txt(fl[0],fl[1]+(u[0]||u[1]?1.6:0),'הפאה '+faceAdj[J.face.name].split(' ')[0],'lbl-s','middle',1.1));
  s.appendChild(txt(0,W/2-1.6,'שרוול '+J.id+' על עמוד '+post.key+' · מסובב '+dg(J.dev),'lbl','middle',1.5));
  s.appendChild(txt(-W/2+4,-W/2+2,'← צפון','lbl-s','middle',1.3));
  return s;}

/* ---------- השקופיות ---------- */
var deck=document.getElementById('deck'),slides=[];
function slide(title,sub,build){var sec=document.createElement('section');sec.className='slide';
  var h=document.createElement('h2');h.textContent=title;if(sub){var sm=document.createElement('small');sm.textContent=sub;h.appendChild(sm);}
  sec.appendChild(h);build(sec);deck.appendChild(sec);slides.push({title:title,el:sec});return sec;}
function html(sec,s){var d=document.createElement('div');d.innerHTML=s;while(d.firstChild)sec.appendChild(d.firstChild);}
function fig(sec,svgEl,cap){var f=document.createElement('figure');f.className='fig';f.appendChild(svgEl);if(cap){var c=document.createElement('figcaption');c.textContent=cap;f.appendChild(c);}sec.appendChild(f);return f;}
function grid(sec){var g=document.createElement('div');g.className='grid';sec.appendChild(g);return g;}
function tag(sec,cls,text){var t=document.createElement('div');t.className='lvl '+cls;t.textContent=text;sec.querySelector('h2').appendChild(t);}
function esc(s){return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;');}
var lvlText={top:'קומה עליונה · מסגרת עליונה · גובה '+f1(K.Z_TOP-TH)+'–'+f1(K.Z_TOP),cross:'קומה עליונה · קורה תומכת · גובה '+f1(K.Z_TOP-TH)+'–'+f1(K.Z_TOP),low:'קומה תחתונה · מפלס 70 · גובה '+f1(K.Z_LOW-TH)+'–'+f1(K.Z_LOW)};

/* כל הקצוות המשופעים, לטבלאות ולספירה */
var angled=[];
topB.forEach(function(b){[0,1].forEach(function(k){if(b.cuts[k].deg>=.5)angled.push({b:b,end:k,kind:'מיטר',deg:b.cuts[k].deg,w:TH,E:endInfo(b,k)});});});
lowB.forEach(function(b){[0,1].forEach(function(k){if(b.cuts[k].deg>=.5)angled.push({b:b,end:k,kind:'על פני העמוד',deg:b.cuts[k].deg,w:TH,E:endInfo(b,k)});});});
crossB.forEach(function(b){if(b.cuts[0].kind!=='notch')return;b.cuts[0].parts.forEach(function(q){if(q.deg>=.5)angled.push({b:b,end:0,kind:'מפוצל · צד '+q.side,deg:q.deg,w:TH/2,part:q});});});
var straightN=M.beams.reduce(function(a,b){return a+b.cuts.filter(function(c){return c.deg<.5;}).length;},0);
var rotated=M.joints.filter(function(j){return j.dev>=.5;});
var distinct=[];angled.forEach(function(a){var k=f1(a.deg);if(!distinct.some(function(d){return d.k===k;}))distinct.push({k:k,deg:a.deg,where:[]});distinct.filter(function(d){return d.k===k;})[0].where.push(a.b.id+' '+END[a.end]);});
distinct.sort(function(a,b){return a.deg-b.deg;});

/* 1. שער */
slide('הזוויות של הסוכה','רק החיתוכים המשופעים — כמה מעלות, ומי מתקצר בכמה',function(sec){
  html(sec,'<p class="lead">בשלד יש <b>'+angled.length+' חיתוכים משופעים</b> (ו-'+straightN+' קצוות ישרים שלא מופיעים כאן), ב-'+distinct.length+' זוויות שונות, ועוד '+rotated.length+' שרוולים מרותכים מסובבים. לכל חיתוך משופע יש צד ארוך וצד קצר: השיפוע "אוכל" מצד אחד של הפרופיל יותר מאשר מהצד השני. הקובץ הזה מסביר כל זווית: כמה מעלות, איזה צד נסוג, בכמה סנטימטרים, ואיך מסמנים את זה על הפרופיל בלי מד־זווית — במידה אחת של נסיגה.</p>'+
   '<div class="totals">'+distinct.map(function(d){return '<div class="card"><div class="n">'+dg(d.deg)+'</div><div class="l">'+d.where.join(' · ')+'<br>נסיגה על 4 ס״מ: <b>'+f1(tanS(TH,d.deg))+'</b></div></div>';}).join('')+'</div>'+
   '<div class="card" style="margin-top:14px"><h3>שלושה סוגי שיפוע</h3><span class="tag t-top">מיטר</span> במסגרת העליונה — שתי הקורות נפגשות מעל העמוד וכל אחת לוקחת חצי מהפנייה. הצלע החיצונית ארוכה מהפנימית. '+
   '<span class="tag t-low">על פני העמוד</span> במפלס 70 — הקורה האלכסונית נעצרת על פאה ישרה של העמוד; שני החיתוכים מקבילים והקורה מקבילית. '+
   '<span class="tag t-cross">מפוצל</span> בקורות התומכות ק1 ו-ק3 — חצי מהרוחב ישר מול קורת המזרח, חצי משופע מול האלכסון.</div>');
});

/* 2. העיקרון */
slide('איך קוראים זווית חיתוך','הנסיגה = רוחב × tan θ',function(sec){
  var g=grid(sec);
  fig(g,principleSVG(),'קורה ברוחב '+TH+' חתוכה בזווית θ מהחיתוך הישר. הצד שהחיתוך "נכנס" אליו מתקצר ב-4 × tan θ ביחס לצד השני.').classList.add('notch');
  var side=document.createElement('div');
  side.innerHTML='<div class="card"><h3>הכלל</h3>חיתוך ישר עובר על הרוחב בניצב. חיתוך משופע ב-θ° מגיע לצלע השנייה מאוחר יותר — ב-<b>רוחב × tan θ</b> ס״מ. זו "הנסיגה": ההפרש בין הצלע הארוכה לקצרה באותו קצה. קו החיתוך עצמו ארוך מהרוחב: <b>רוחב ÷ cos θ</b>.<br>ולהפך: אם מודדים על הפרופיל נסיגה של X על רוחב 4 — הזווית היא arctan(X ÷ 4).</div>'+
   '<div class="card"><h3>הזוויות שבסוכה</h3><table><tr><th class="num">θ</th><th class="num">tan θ</th><th class="num">נסיגה על 4</th><th class="num">נסיגה על 2</th><th class="num">קו החיתוך על 4</th><th>איפה</th></tr>'+
   distinct.map(function(d){return '<tr><td class="num">'+dg(d.deg)+'</td><td class="num">'+f3(Math.tan(d.deg*R))+'</td><td class="num">'+f2(tanS(TH,d.deg))+'</td><td class="num">'+f2(tanS(TH/2,d.deg))+'</td><td class="num">'+f2(cutL(TH,d.deg))+'</td><td>'+d.where.join(', ')+'</td></tr>';}).join('')+
   '</table><div style="font-size:12.5px;color:var(--mute);margin-top:6px">"נסיגה על 2" — לקצה המפוצל של ק1 ו-ק3, שבו רק חצי מהרוחב משופע.</div></div>'+
   '<div class="card"><h3>לסמן בלי מד־זווית</h3>מסמנים על הצלע שנסוגה את הנסיגה מהקצה, מותחים קו ישר מהפינה של הצלע השנייה אל הסימון, וחותכים על הקו. הזווית יוצאת בדיוק. במסור גרונג מכוונים ל-θ° (הסקאלה במסור מודדת מהחיתוך הישר, כמו כאן).</div>';
  g.appendChild(side);
});

/* 3. מפת הזוויות */
slide('מפת הזוויות','איפה כל זווית יושבת',function(sec){
  var two=document.createElement('div');two.className='two maps';
  fig(two,overviewSVG('top'),'מסגרת עליונה וקורות תומכות: בכל פינה הזווית הפנימית, ולכל קורה המיטר שלה (חצי מהפנייה). ק1 ו-ק3 — הקצה המפוצל.');
  fig(two,overviewSVG('low'),'מפלס 70: רק שתי הקורות האלכסוניות משופעות — בשני קצותיהן, במקביל לפאת העמוד.');
  sec.appendChild(two);
});

/* 4–8. פינות המסגרת העליונה */
function miterRecipe(b,end){var E=endInfo(b,end),m=b.cuts[end].deg;
  return '<b>'+b.id+' קצה '+END[end]+'</b>: על הצלע <b>'+E.behind+'</b> מסמנים <b>'+f1(E.s)+'</b> מהקצה; קו ישר מפינת הצלע '+E.ahead+' אל הסימון; חותכים. במסור: '+dg(m)+'. קו החיתוך '+f1(cutL(TH,m))+'.';}
for(var ci=1;ci<=5;ci++)(function(i){
  var a=topB[i-1],b=topB[i],m=b.cuts[0].deg,inner=180-2*m,post=PC[b.hosts[0]],s=tanS(TH,m);
  var Ea=endInfo(a,1),Eb=endInfo(b,0);
  slide('פינה '+i+' — '+a.kind+' ↔ '+b.kind,'מיטר '+dg(m)+' בכל קורה · עמוד '+post.key,function(sec){
    tag(sec,'lvl-top',lvlText.top);
    var row=document.createElement('div');row.className='locator';
    fig(row,locator([a.id,b.id],M.C[i],'top'),'איפה: הפינה בטבעת, שתי הקורות בכתום.');
    fig(row,cornerPlan(i),'הפינה מוגדלת, מבט־על: שתי הקורות נפגשות בקו המיטר מעל מרכז העמוד. הקשת — הזווית הפנימית ביניהן.');
    sec.appendChild(row);
    var g=grid(sec),left=document.createElement('div'),two=document.createElement('div');two.className='two';
    fig(two,cutDetail(a,1),'קצה ב׳ של '+a.id+': הצלע החיצונית מגיעה '+f1(Ea.s)+' רחוק יותר מהפנימית.');
    fig(two,cutDetail(b,0),'קצה א׳ של '+b.id+': הצלע החיצונית מגיעה '+f1(Eb.s)+' רחוק יותר מהפנימית.');
    left.appendChild(two);g.appendChild(left);
    var side=document.createElement('div');
    side.innerHTML='<div class="card"><h3>הזווית</h3>המסגרת פונה כאן ב-<b>'+dg(2*m)+'</b>, כלומר הזווית הפנימית בין '+a.id+' ל-'+b.id+' היא <b>'+dg(inner)+'</b>. במיטר כל קורה לוקחת בדיוק חצי מהפנייה — <b>'+dg(m)+'</b> מהחיתוך הישר — וכך שני החיתוכים נפגשים בקו אחד שעובר מעל מרכז העמוד '+post.key+', מהפינה החיצונית לפינה הפנימית.'+(i===5?' כאן הפנייה 90°, ולכן זה מיטר של 45° — הקלאסי.':'')+'</div>'+
      '<div class="card"><h3>מה אוכל השיפוע</h3>ברוחב '+TH+' ובזווית '+dg(m)+': <b>'+TH+' × tan '+dg(m)+' = '+f1(s)+'</b> ס״מ. בכל אחד משני הקצוות הצלע החיצונית ('+compass(localPts(b).n)+' ב-'+b.id+') ארוכה מהפנימית ב-'+f1(s)+'. קו החיתוך על הפרופיל: '+f1(cutL(TH,m))+'.'+
      '<table style="margin-top:6px"><tr><th>קורה</th><th class="num">חוץ</th><th class="num">פנים</th><th class="num">הפרש כולל</th><th>ממנו בפינה הזאת</th></tr>'+
      [a,b].map(function(x){return '<tr><td>'+x.id+'</td><td class="num">'+f1(x.len.outer)+'</td><td class="num">'+f1(x.len.inner)+'</td><td class="num">'+f1(x.len.outer-x.len.inner)+'</td><td class="num">'+f1(s)+(x.len.outer-x.len.inner-s>.05?' + '+f1(x.len.outer-x.len.inner-s)+' בקצה השני':'')+'</td></tr>';}).join('')+'</table></div>'+
      '<div class="card"><h3>איך מסמנים</h3>חותכים כל קורה ישר לאורך הצלע החיצונית שלה ('+a.id+' '+f1(a.len.outer)+', '+b.id+' '+f1(b.len.outer)+'), ואז:<br>'+miterRecipe(a,1)+'<br>'+miterRecipe(b,0)+'<br>בדיקה: הצלעות הפנימיות יוצאות '+f1(a.len.inner)+' ו-'+f1(b.len.inner)+'.</div>';
    g.appendChild(side);
  });
})(ci);

/* 9–10. מפלס 70 — האלכסונים */
lowB.filter(function(b){return b.cuts[0].deg>=.5;}).forEach(function(b){
  var m=b.cuts[0].deg,s=tanS(TH,m),E0=endInfo(b,0),E1=endInfo(b,1),pA=PC[b.hosts[0]],pB=PC[b.hosts[1]],jA=b.tongues[0],jB=b.tongues[1];
  var top=topB.filter(function(t){return t.kind===b.kind;})[0],ti=topB.indexOf(top);
  slide(b.name,'שני הקצוות '+dg(m)+' — במקביל לפאת העמוד',function(sec){
    tag(sec,'lvl-low',lvlText.low);
    var row=document.createElement('div');row.className='locator';
    fig(row,locator([b.id],null,'low'),'איפה: הקורה בכתום, בין העמודים '+pA.key+' ו-'+pB.key+'.');
    fig(row,beamSVG(b),'הקורה כולה: מקבילית — שתי הצלעות באורך זהה, החיתוכים מקבילים ומוזזים זה מזה ב-'+f1(s)+'.');
    sec.appendChild(row);
    var g=grid(sec),left=document.createElement('div'),two=document.createElement('div');two.className='two';
    fig(two,cutDetail(b,0),'קצה א׳ על הפאה '+faceAdj[jA.face.name]+' של '+pA.key+': הצלע '+E0.ahead+' מגיעה '+f1(s)+' רחוק יותר.');
    fig(two,cutDetail(b,1),'קצה ב׳ על הפאה '+faceAdj[jB.face.name]+' של '+pB.key+': הצלע '+E1.ahead+' מגיעה '+f1(s)+' רחוק יותר — הפוך מקצה א׳.');
    left.appendChild(two);g.appendChild(left);
    var side=document.createElement('div');
    side.innerHTML='<div class="card"><h3>הזווית</h3>'+b.id+' יוצאת מהפאה '+faceAdj[jA.face.name]+' של עמוד '+pA.key+' ב-<b>'+dg(m)+'</b> מהניצב לפאה, ונכנסת לפאה '+faceAdj[jB.face.name]+' של '+pB.key+' באותה זווית. החיתוך <b>מקביל לפאה</b>, ולכן שני החיתוכים מקבילים זה לזה: הקורה מקבילית ולא טרפז.<br>שימו לב — זה לא המיטר של המסגרת העליונה באותן פינות ('+dg(top.cuts[0].deg)+' ו-'+dg(top.cuts[1].deg)+'): שם כל קורה לוקחת חצי מהפנייה; כאן הקורה נעצרת על פאה ישרה ולוקחת את כל סטיית האלכסון מציר העמודים.</div>'+
      '<div class="card"><h3>מה אוכל השיפוע</h3><b>'+TH+' × tan '+dg(m)+' = '+f1(s)+'</b> ס״מ בכל קצה. בקצה א׳ הצלע <b>'+E0.behind+'</b> נסוגה ב-'+f1(s)+' והצלע '+E0.ahead+' מגיעה עד העמוד; בקצה ב׳ להפך — הצלע <b>'+E1.behind+'</b> נסוגה. לכן שתי הצלעות באורך זהה: <b>'+f1(b.len.outer)+'</b>. קו החיתוך על הפרופיל: '+f1(cutL(TH,m))+'.</div>'+
      '<div class="card"><h3>איך מסמנים</h3>1. חותכים ישר <b>'+f1(b.len.outer+s)+'</b> ('+f1(b.len.outer)+' + '+f1(s)+').<br>2. קצה א׳: על הצלע <b>'+E0.behind+'</b> מסמנים '+f1(s)+' מהקצה, קו ישר לפינת הצלע '+E0.ahead+', חותכים.<br>3. קצה ב׳: על הצלע <b>'+E1.behind+'</b> מסמנים '+f1(s)+' מהקצה, קו לפינת הצלע '+E1.ahead+', חותכים.<br>4. שני החיתוכים באותו כיוון — <b>מקבילים</b>. אם אחד מסומן הפוך יוצא טרפז, והקורה לא תיכנס בין העמודים. בדיקה: שתי הצלעות '+f1(b.len.outer)+'. במסור: '+dg(m)+'.</div>';
    g.appendChild(side);
  });
});

/* 11–12. הקצה המפוצל של ק1 ו-ק3 */
crossB.filter(function(b){return b.cuts[0].kind==='notch';}).forEach(function(b){
  var c=b.cuts[0],st=c.parts.filter(function(q){return q.deg<.5;})[0],an=c.parts.filter(function(q){return q.deg>=.5;})[0];
  var diag=topB.filter(function(t){return t.kind===(an.side==='דרום'?'אלכסון דרומי':'אלכסון צפוני');})[0];
  slide(b.name+' — הקצה המפוצל','צד '+an.side+' '+dg(an.deg)+' · צד '+st.side+' ישר',function(sec){
    tag(sec,'lvl-top',lvlText.cross);
    var row=document.createElement('div');row.className='locator';
    fig(row,locator([b.id],c.corner,'top'),'איפה: הקורה בכתום, הקצה המזרחי בטבעת — בפינה שבין קורת המזרח ל'+diag.kind+' ('+diag.id+').');
    sec.appendChild(row);
    var g=grid(sec);
    fig(g,notchDetail(b,0),'הקצה מוגדל, מבט־על. המקווקו — פני שתי הקורות שהקצה יושב עליהן; הנסיגה נמדדת על הדופן החיצונית מהקצה; הקשת — הזווית מהחיתוך הישר.').classList.add('notch');
    var side=document.createElement('div');
    side.innerHTML='<div class="card"><h3>הזווית</h3>הקצה המזרחי של '+b.id+' מגיע בדיוק לפינה הפנימית שבין קורת המזרח ל'+diag.kind+'. חצי מהרוחב (צד '+st.side+') יושב על קורת המזרח, שניצבת לקורה — חיתוך ישר. החצי השני (צד '+an.side+') יושב על '+diag.id+', שסוטה מהניצב ב-<b>'+dg(an.deg)+'</b> — זו זווית האלכסון עצמו בתוכנית, לא המיטר שלו ('+dg(diag.cuts[an.side==='דרום'?1:0].deg)+').</div>'+
      '<div class="card"><h3>מה אוכל השיפוע</h3>השיפוע חל רק על חצי הרוחב, '+f1(TH/2)+' ס״מ: <b>'+f1(TH/2)+' × tan '+dg(an.deg)+' = '+f1(an.setback)+'</b>. הדופן ה'+an.side+'ית של הקורה קצרה מהדופן ה'+st.side+'ית ב-'+f1(an.setback)+': צד '+st.side+' '+f1(st.len)+', צד '+an.side+' '+f1(an.len)+'. קו החיתוך המשופע: '+f1(cutL(TH/2,an.deg))+'.</div>'+
      '<div class="card"><h3>איך מסמנים</h3>1. חותכים ישר לאורך המלא, '+f1(b.len.outer)+'.<br>2. מסמנים בקצה את אמצע הרוחב ('+f1(TH/2)+' מכל דופן).<br>3. על הדופן ה'+an.side+'ית מסמנים <b>'+f1(an.setback)+'</b> מהקצה.<br>4. קו ישר מנקודת האמצע שבקצה אל הסימון — וחותכים רק את המשולש הזה. צד '+st.side+' נשאר ישר.<br>5. בדיקה: הקצה נוגע בשתי הקורות בלי מרווח — הישר בקורת המזרח, המשופע ב'+diag.id+'.</div>';
    g.appendChild(side);
  });
});

/* 13. סיבוב השרוולים */
slide('סיבוב השרוולים','זוויות שאינן חיתוך — '+rotated.length+' שרוולים מסובבים',function(sec){
  var two=document.createElement('div');two.className='two';
  var shown={};rotated.forEach(function(j){if(j.level!=='top'||shown[f1(j.dev)])return;shown[f1(j.dev)]=1;
    fig(two,sleeveSVG(PC[j.host.key],j),'עמוד '+j.host.key+', מבט־על: השרוול (חום) מסובב '+dg(j.dev)+' לכיוון הקורה, פינתו נוגעת בפאה. השן (אדום) יורדת לתוכו אנכית.');});
  sec.appendChild(two);
  html(sec,'<div class="grid" style="margin-top:12px"><div class="card"><h3>הזווית</h3>השרוול לצד העמוד מרותך <b>מסובב באותה זווית שבה הקורה סוטה מהניצב לפאה</b> — כך השן, שיורדת מהקורה אנכית, נכנסת אליו ישר. בפינות האלכסון זו הזווית של מפלס 70 באותה פינה ('+distinct.filter(function(d){return rotated.some(function(j){return f1(j.dev)===d.k;});}).map(function(d){return dg(d.deg);}).join(' ו-')+'), ולא המיטר של המסגרת העליונה. ריבוע '+TH+'×'+TH+' מסובב ב-θ תופס על הפאה רוחב '+TH+' × (cos θ + sin θ): '+rotated.filter(function(j,k,arr){return arr.map(function(x){return f1(x.dev);}).indexOf(f1(j.dev))===k;}).map(function(j){return dg(j.dev)+' → '+f1(TH*(Math.cos(j.dev*R)+Math.sin(j.dev*R)));}).join(' · ')+' — ולכן מרכזו רחוק מהפאה יותר מ-2 ס״מ, ומוזז הצידה.</div>'+
   '<div class="card"><h3>כל השרוולים המסובבים</h3><table><tr><th>שרוול</th><th>עמוד</th><th>פאה</th><th class="num">סיבוב</th><th class="num">מרכזו מהפאה</th><th class="num">הצידה</th></tr>'+
   rotated.map(function(j){return '<tr><td>'+j.id+'</td><td>'+j.host.key+'</td><td>'+j.face.name+'</td><td class="num">'+dg(j.dev)+'</td><td class="num">'+f1(j.face.fromFace)+'</td><td class="num">'+f1(Math.abs(j.face.lateral))+' ל'+j.face.lateralName+'</td></tr>';}).join('')+
   '</table><div style="font-size:12.5px;color:var(--mute);margin-top:6px">"מהפאה" — מרחק מרכז השרוול מפני הפאה; בשרוול ישר זה 2. "הצידה" — הזזת המרכז ממרכז הפאה לאורכה. המיקום המלא של כל שרוול — במצגת החלקים.</div></div></div>');
});

/* 14. טבלה מסכמת */
slide('כל החיתוכים המשופעים','טבלה אחת לסדנה',function(sec){
  html(sec,'<table><tr><th>קורה</th><th>קצה</th><th>סוג</th><th class="num">זווית</th><th class="num">על רוחב</th><th class="num">נסיגה</th><th class="num">קו החיתוך</th><th>הצלע שנסוגה</th><th>הצלע שמגיעה רחוק יותר</th></tr>'+
   angled.map(function(a){var E=a.E;return '<tr><td><span class="tag t-'+a.b.level+'">'+a.b.id+'</span>'+esc(a.b.kind)+'</td><td>'+END[a.end]+'</td><td>'+a.kind+'</td><td class="num">'+dg(a.deg)+'</td><td class="num">'+f1(a.w)+'</td><td class="num"><b>'+f1(a.part?a.part.setback:E.s)+'</b></td><td class="num">'+f1(cutL(a.w,a.deg))+'</td><td>'+(E?E.behind:'הדופן ה'+a.part.side+'ית')+'</td><td>'+(E?E.ahead:'הדופן ה'+(a.part.side==='דרום'?'צפונ':'דרומ')+'ית')+'</td></tr>';}).join('')+
   '</table><p class="lead" style="margin-top:12px">"נסיגה" — בכמה הצלע שנסוגה קצרה מהצלע השנייה באותו קצה: רוחב × tan הזווית. מסמנים אותה על הצלע שנסוגה ומותחים קו לפינה של הצלע השנייה. כל שאר '+straightN+' הקצוות בשלד — ישרים.</p>');
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
