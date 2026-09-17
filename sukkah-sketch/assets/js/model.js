/* המודל של הסוכה — גאומטריה בלבד, בלי three.js ובלי DOM.
   מקור האמת היחיד למידות: השרטוט התלת־ממדי (index.html) ומצגת החלקים
   (parts.html) בונים שניהם מכאן, כדי שלעולם לא יהיו שני מספרים לאותה קורה.

   מערכת צירים (ס״מ): x מהפינה הדרומית צפונה, y מפני הקיר מזרחה, z גובה.
   כיוונים: +x צפון, -x דרום, +y מזרח, -y מערב (הקיר). */
window.SukkahModel=(function(){
'use strict';

/* ---------- נתוני שטח (ס"מ) ---------- */
var K={
  WID:248, WLEN:742, WALL:490, WALL_T:30,
  WALL_H:300,          // הקיר גבוה מהסוכה (מעל Z_TOP); הגובה עצמו לא נמדד
  S:167, N:68, DS:150.5, DN:243, EAST:452,
  GAP:1, TH:4,         // מרווח מהגבול, עובי פרופיל
  Z_TOP:280, Z_LOW:70,
  PLATE:.6, PLATE_W:10,   // פלטת הקיר: עובי ורוחב; קורה שנכנסת לקיר נגמרת על הפלטה
  SLEEVE_LEN:12,       // השרוול: חתיכת 40×40 אנכית לצד העמוד — אורכו טרם הוכרע
  TONGUE:3.5, TONGUE_LEN:10,   // השן: 3.5×3.5 מתחת לקורה, יורדת לתוך השרוול — טרם הוכרע
  POST_FROM_WALL:93    // עמוד באזור ללא קיר
};

function area(p){var s=0;for(var i=0;i<p.length;i++){var q=p[(i+1)%p.length];s+=p[i][0]*q[1]-q[0]*p[i][1];}return s/2;}
function offset(P,d){var n=P.length,sg=area(P)>0?1:-1,L=[],out=[];
  for(var i=0;i<n;i++){var p=P[i],q=P[(i+1)%n],dx=q[0]-p[0],dy=q[1]-p[1],l=Math.hypot(dx,dy);
    L.push([[p[0]-dy/l*sg*d,p[1]+dx/l*sg*d],[dx,dy]]);}
  for(i=0;i<n;i++){var A=L[(i+n-1)%n],B=L[i],den=A[1][0]*B[1][1]-A[1][1]*B[1][0];
    var t=((B[0][0]-A[0][0])*B[1][1]-(B[0][1]-A[0][1])*B[1][0])/den;
    out.push([A[0][0]+A[1][0]*t,A[0][1]+A[1][1]*t]);}
  return out;}
function dist(p,q){return Math.hypot(p[0]-q[0],p[1]-q[1]);}
function rectPts(cx,cy,w,h){return [[cx-w/2,cy-h/2],[cx+w/2,cy-h/2],[cx+w/2,cy+h/2],[cx-w/2,cy+h/2]];}
function sq(c,d,h){   // ריבוע ברוחב 2h סביב c, מסובב לכיוון d
  var n=[-d[1],d[0]];
  return [[c[0]+d[0]*h+n[0]*h,c[1]+d[1]*h+n[1]*h],[c[0]+d[0]*h-n[0]*h,c[1]+d[1]*h-n[1]*h],
          [c[0]-d[0]*h-n[0]*h,c[1]-d[1]*h-n[1]*h],[c[0]-d[0]*h+n[0]*h,c[1]-d[1]*h+n[1]*h]];}
function dirName(u){return u[0]>0?'צפון':u[0]<0?'דרום':u[1]>0?'מזרח':'מערב (הקיר)';}

function build(){
  var WID=K.WID,WLEN=K.WLEN,WALL=K.WALL,WALL_T=K.WALL_T,WALL_H=K.WALL_H,S=K.S,N=K.N,DS=K.DS,EAST=K.EAST,
      GAP=K.GAP,TH=K.TH,Z_TOP=K.Z_TOP,Z_LOW=K.Z_LOW,PLATE=K.PLATE,PLATE_W=K.PLATE_W,
      SLEEVE_LEN=K.SLEEVE_LEN,TONGUE=K.TONGUE,TONGUE_LEN=K.TONGUE_LEN,POST_FROM_WALL=K.POST_FROM_WALL;

  var a=Math.sqrt(DS*DS-(WID-S)*(WID-S)), b=a+EAST;
  // גבול חיצוני. באזור ללא קיר: כך שמרכז הקורה על אמצע עובי הקיר (y=-15)
  var P=[[0,0],[0,S],[a,WID],[b,WID],[WLEN,N],[WLEN,-18],[WALL,-18],[WALL,0]];
  var O=offset(P,GAP), I=offset(P,GAP+TH), C=offset(P,GAP+TH/2);
  function cornerAngle(i){var p=P[(i+P.length-1)%P.length],q=P[i],r=P[(i+1)%P.length];
    var v1=[p[0]-q[0],p[1]-q[1]],v2=[r[0]-q[0],r[1]-q[1]];
    return Math.acos((v1[0]*v2[0]+v1[1]*v2[1])/(Math.hypot(v1[0],v1[1])*Math.hypot(v2[0],v2[1])))*180/Math.PI;}

  var M={K:K,P:P,O:O,I:I,C:C,beams:[],posts:[],joints:[],labels:[]};
  M.floor={pts:P};
  M.wall={pts:[[0,-WALL_T],[WALL,-WALL_T],[WALL,0],[0,0]],z0:0,z1:WALL_H,name:'קיר מערבי',
    rows:['אורך '+WALL+' · עובי '+WALL_T,'גובה בהדמיה '+WALL_H+' — גבוה מהסוכה, לא נמדד','בלי עמודים לאורכו: השרוולים על פלטות מעוגנות ישירות בקיר']};

  /* ---------- עמודים ---------- */
  var x1=I[2][0], x2=x1+225, x3=I[3][0], yE=WID-GAP-TH, xp=WALL+POST_FROM_WALL;
  // [מפתח, שם, x מדרום, y מפני הקיר]. בצד הקיר אין עמודים — השרוולים שם על פלטות בקיר
  [['s1','פינה דרום / אלכסון',C[1][0],C[1][1]],
   ['e1','פינה אלכסון דרומי / מזרח (ק1)',x1,WID-GAP-TH/2],['e2','מזרח · ק2',x2,WID-GAP-TH/2],
   ['e3','פינה מזרח / אלכסון צפוני (ק3)',x3,WID-GAP-TH/2],['n1','פינה אלכסון צפוני / צפון',C[4][0],C[4][1]],
   ['nw','פינה צפון‑מערב',C[5][0],-15],['w3','מערב · תחת ק3',x3,-15],['wp','מערב · '+POST_FROM_WALL+' מהקיר',xp,-15]
  ].forEach(function(p){
    M.posts.push({key:p[0],name:p[1],x:p[2],y:p[3],h:Z_TOP-TH,pts:rectPts(p[2],p[3],TH,TH),joints:[],
      rows:['חתך '+TH+'×'+TH+' · גובה '+(Z_TOP-TH),'מרכז: '+p[2].toFixed(1)+' מדרום · '+p[3].toFixed(1)+' מפני הקיר']});
  });
  var PC={}; M.posts.forEach(function(p){PC[p.key]=p;});
  // מארח: מפתח עמוד; '@x' = נקודה על פני הקיר (y=0), '#y' = נקודה על קצה הקיר המזרחי (x=WALL). שניהם מעבר לפלטה
  function hostKind(key){var k=key.charAt(0);return k==='@'?'wallS':k==='#'?'wallE':'post';}
  function hostAt(key){var k=hostKind(key);return k==='wallS'?[+key.slice(1),PLATE]:k==='wallE'?[WALL+PLATE,+key.slice(1)]:[PC[key].x,PC[key].y];}
  function hostName(key){var k=hostKind(key);return k==='post'?'עמוד '+PC[key].name:k==='wallS'?'פלטה על פני הקיר':'פלטה על קצה הקיר';}

  /* ---------- מסגרת עליונה: הקורות נפגשות זו בזו מעל העמוד, חיתוך מיטר ---------- */
  var names=['דרום','אלכסון דרומי','מזרח','אלכסון צפוני','צפון','מערב (ללא קיר)'];
  var quads=[];
  for(var i=0;i<6;i++){
    var o1=O[i],o2=O[i+1],i1=I[i],i2=I[i+1];
    if(i===0)i1=[I[0][0],O[0][1]];
    if(i===5)i2=[O[6][0],I[6][1]];
    quads.push([o1,o2,i2,i1]);
    var m1=(i===0)?0:90-cornerAngle(i)/2, m2=(i===5)?0:90-cornerAngle(i+1)/2;
    var t='ע'+(i+1);   // שם קצר: ע = מסגרת עליונה. אותו שם על הקורה ועל השרוולים שלה
    M.beams.push({id:t,level:'top',kind:names[i],name:'מסגרת עליונה · '+names[i]+' ('+t+')',
      pts:quads[i],z0:Z_TOP-TH,z1:Z_TOP,mat:i%2?'frame2':'frame',
      len:{outer:dist(o1,o2),inner:dist(i1,i2)},
      cuts:[{deg:m1,kind:i===0?'wall':'miter'},{deg:m2,kind:i===5?'wall':'miter'}],
      axis:[i===0?[C[0][0],O[0][1]]:C[i], i===5?[O[6][0],C[6][1]]:C[i+1]], trim:[0,0],
      joints:[],tongues:[],
      rows:['אורך חוץ '+dist(o1,o2).toFixed(1)+' · פנים '+dist(i1,i2).toFixed(1),
            'חיתוך: קצה א׳ '+(m1?m1.toFixed(1)+'°':'ישר')+' · קצה ב׳ '+(m2?m2.toFixed(1)+'°':'ישר'),
            'פרופיל '+TH+'×'+TH,
            'מחברים: '+t+'·א, '+t+'·ב'+(i===2?' + '+t+'·ג (עמוד ביניים)':i===5?' + '+t+'·ג, '+t+'·ד (עמודי ביניים)':'')]});
  }

  /* ---------- קורות תומכות ----------
     הקצה המזרחי יושב על המסגרת מבפנים. ק1 ו-ק3 מגיעות בדיוק לפינה שבין קורת
     המזרח לאלכסון: חצי מרוחבן פוגש את המזרח (חיתוך ישר) וחצי את האלכסון, שנסוג
     בזווית — ולכן הקצה מפוצל ועוקב אחרי פני המסגרת. ק2 באמצע המזרח: ישר. */
  function innerYAt(x,ci){   // y על פני המסגרת הפנימיים ליד הפינה ci: דרומית לפינה — הקטע שלפניה, צפונית — שאחריה
    var e=x<I[ci][0]?[I[ci-1],I[ci]]:[I[ci],I[ci+1]];
    var t=(x-e[0][0])/(e[1][0]-e[0][0]); return e[0][1]+t*(e[1][1]-e[0][1]);}
  function edgeDeg(e){return Math.abs(Math.atan2(e[1][1]-e[0][1],e[1][0]-e[0][0])*180/Math.PI);}   // סטיית הקטע מהניצב לקורה התומכת
  [[x1,PLATE,'ק1',true,2],[x2,PLATE,'ק2',true,0],[x3,-13,'ק3',false,3]].forEach(function(c){   // [x, y התחלה, שם, נכנסת לקיר, פינת המסגרת שהקצה פוגש]
    var xc=c[0],y0=c[1],ci=c[4],pts,cutE,lenRow,cutRow;
    if(ci){var yS=innerYAt(xc-TH/2,ci),yN=innerYAt(xc+TH/2,ci),dS=edgeDeg([I[ci-1],I[ci]]),dN=edgeDeg([I[ci],I[ci+1]]);
      pts=[[xc-TH/2,y0],[xc+TH/2,y0],[xc+TH/2,yN],[I[ci][0],I[ci][1]],[xc-TH/2,yS]];
      var f=function(v){return v<.5?'ישר':v.toFixed(1)+'°';};
      cutE={deg:Math.max(dS,dN),kind:'notch',parts:[{side:'דרום',deg:dS,len:yS-y0},{side:'צפון',deg:dN,len:yN-y0}],
            label:'מפוצל — צד דרום '+f(dS)+' · צד צפון '+f(dN),short:'מפוצל '+f(dS)+' / '+f(dN)};
      lenRow='אורך על הציר '+(yE-y0).toFixed(1)+' · צד דרום '+(yS-y0).toFixed(1)+' · צד צפון '+(yN-y0).toFixed(1);
      var vs=function(v){return v<.5?'מול קורת המזרח':'מול האלכסון';};
      cutRow='חיתוך מזרח מפוצל: צד דרום '+f(dS)+' ('+vs(dS)+') · צד צפון '+f(dN)+' ('+vs(dN)+')';
    } else {pts=[[xc-TH/2,y0],[xc+TH/2,y0],[xc+TH/2,yE],[xc-TH/2,yE]];cutE={deg:0,kind:'face'};
      lenRow='אורך '+(yE-y0).toFixed(0)+' · חיתוך ישר';cutRow=null;}
    var rows=[lenRow];if(cutRow)rows.push(cutRow);
    rows.push('מיקום: '+xc.toFixed(1)+' מהפינה הדרומית','פרופיל '+TH+'×'+TH,'מחברים: '+c[2]+'·א (עמוד מזרח), '+c[2]+'·ב ('+(c[3]?'שרוול על הקיר':'עמוד מערב')+')');
    M.beams.push({id:c[2],level:'cross',kind:'תומכת',name:'קורה תומכת '+c[2],
      pts:pts,z0:Z_TOP-TH,z1:Z_TOP,mat:'cross',
      len:{outer:yE-y0,inner:ci?Math.min(innerYAt(xc-TH/2,ci),innerYAt(xc+TH/2,ci))-y0:yE-y0},
      cuts:[cutE,{deg:0,kind:c[3]?'wall':'face'}],   // קצה א׳ = המזרחי, על המסגרת; קצה ב׳ = המערבי
      axis:[[xc,yE],[xc,y0]],trim:[0,0],x:xc,joints:[],tongues:[],rows:rows});
  });
  function beamById(id){for(var j=0;j<M.beams.length;j++)if(M.beams[j].id===id)return M.beams[j];}

  /* ---------- מחברים: שרוול ושן, אנכיים ----------
     לצד העמוד מרותך שרוול — חתיכה קצרה מאותו פרופיל 40×40, אנכית, פתוחה למעלה,
     וראשה בגובה תחתית הקורה. בקצה הקורה, מתחתיה, מרותכת שן מפרופיל קטן יותר
     שיורדת לתוך השרוול. הקורה מונחת מלמעלה — חיבור קל, בלי ברגים.
     השרוול מרותך מסובב לכיוון הקורה, ולכן אותו מחבר משמש גם בפינות האלכסון:
     השן יורדת אנכית בכל זווית. כל שרוול נושא את שם הקורה שלו. */
  function joint(id,beam,key,toward,z,mid){
    var kind=hostKind(key), from=hostAt(key), wall=kind!=='post';
    var dx=toward[0]-from[0],dy=toward[1]-from[1],l=Math.hypot(dx,dy),d=[dx/l,dy/l];
    var ang=Math.atan2(d[1],d[0])*180/Math.PI, dev=((ang%90)+90)%90; dev=Math.min(dev,90-dev);
    // מרכז השרוול: צמוד לפני העמוד (או לפלטה) גם כשהוא מסובב לכיוון הקורה
    var r=dev*Math.PI/180, t=wall?TH/2:TH/2*(1+Math.cos(r)+Math.sin(r))/Math.cos(r);
    var c=[from[0]+d[0]*t,from[1]+d[1]*t], zb=z-TH;   // zb = תחתית הקורה = ראש השרוול
    var bn=(beam.base||beam.id)+(beam.level==='cross'?'':' · '+beam.kind);   // שם הקורה על השרוול, כמו על הקורה
    var J={id:id,beamId:beam.id,beamName:bn,host:{key:key,kind:kind,pos:from,name:hostName(key)},
      level:z===Z_TOP?'top':'low',c:c,d:d,dev:dev,t:t,zb:zb,mid:!!mid,
      sleevePts:sq(c,d,TH/2),tonguePts:sq(c,d,TONGUE/2),plate:null,
      rows:['קורה '+bn+' · '+(wall?(kind==='wallE'?'שרוול על פלטה בקצה הקיר':'שרוול על פלטה מעוגנת לקיר'):mid?'שרוול לצד עמוד ביניים — הקורה רצופה, השן מתחתיה':'שרוול מרותך לצד העמוד'),
            'שרוול '+TH+'×'+TH+' · אורך '+SLEEVE_LEN+' · ראשו בגובה '+zb.toFixed(1),
            'שן '+TONGUE+'×'+TONGUE+' מתחת לקורה · נכנסת '+TONGUE_LEN,
            'סיבוב השרוול: '+(dev<.5?(wall?'ישר על פני הקיר':'ישר על פני העמוד'):dev.toFixed(1)+'° — לכיוון הקורה')]};
    if(wall){var pw=PLATE_W,ph=SLEEVE_LEN+4,x=from[0],y=from[1];
      J.plate={pts:kind==='wallS'?[[x-pw/2,0],[x+pw/2,0],[x+pw/2,PLATE],[x-pw/2,PLATE]]:[[WALL,y-pw/2],[WALL+PLATE,y-pw/2],[WALL+PLATE,y+pw/2],[WALL,y+pw/2]],
        z0:zb-SLEEVE_LEN-2,z1:zb+2,w:pw,h:ph,name:'פלטת קיר · '+id,
        rows:[pw+'×'+ph+' · עובי 6 מ״מ · 4 ברגי עיגון (טרם הוכרע)','השרוול מרותך לפלטה; השן שבקצה הקורה יורדת לתוכו']};}
    else{var u=[Math.abs(d[0])>=Math.abs(d[1])?Math.sign(d[0]):0, Math.abs(d[0])>=Math.abs(d[1])?0:Math.sign(d[1])];
      var v=[-u[1],u[0]];   // לאורך הפאה
      J.face={u:u,name:dirName(u),fromFace:(c[0]-from[0])*u[0]+(c[1]-from[1])*u[1]-TH/2,
              lateral:(c[0]-from[0])*v[0]+(c[1]-from[1])*v[1],lateralName:dirName(((c[0]-from[0])*v[0]+(c[1]-from[1])*v[1])>=0?v:[-v[0],-v[1]])};
      PC[key].joints.push(J);}
    // מיקום השן על הקורה: מרחק מרכזה מתחילת ציר הקורה
    var ax=beam.axis[0],bd=[beam.axis[1][0]-ax[0],beam.axis[1][1]-ax[1]],bl=Math.hypot(bd[0],bd[1]);
    J.s=((c[0]-ax[0])*bd[0]+(c[1]-ax[1])*bd[1])/bl;
    beam.joints.push(id); beam.tongues.push(J);
    M.joints.push(J);
    return J;
  }
  /* קורה בין שני מארחים (עמוד, או '@x' על הקיר). עמוד ביניים מקבל שרוול אחד */
  function bar(beam,a,b,z,mids){
    var A=hostAt(a),B=hostAt(b);
    joint(beam.id+'·א',beam,a,B,z); joint(beam.id+'·ב',beam,b,A,z);
    (mids||[]).forEach(function(m,k){joint(beam.id+'·'+String.fromCharCode(0x5d2+k),beam,m,B,z,true);});   // ג, ד, ...
    beam.hosts=[a].concat(mids||[],[b]);
  }
  [['ע1','@'+C[0][0],'s1'],['ע2','s1','e1'],['ע3','e1','e3',['e2']],['ע4','e3','n1'],['ע5','n1','nw'],['ע6','nw','#-15',['w3','wp']]
  ].forEach(function(t){bar(beamById(t[0]),t[1],t[2],Z_TOP,t[3]);});

  /* ---------- מפלס 70 ----------
     העמוד עובר דרך הגובה הזה, ולכן הקורה לא יכולה לעבור דרכו: היא נגמרת על פני
     העמוד, כשהקצה חתוך במקביל לפניו — בפינת אלכסון זו חיתוך בזווית. עמוד ביניים
     מפצל את הקורה לשני קטעים, ולכל קטע שרוול משלו. במסגרת העליונה זה לא נדרש:
     העמוד נגמר מתחת לקורה, והקורה עוברת מעליו. */
  function faceAxis(d){return Math.abs(d[0])>=Math.abs(d[1])?[Math.sign(d[0]),0]:[0,Math.sign(d[1])];}   // הפאה שהקורה פוגשת
  function trimS(d,n,h,key){   // מרחק לאורך הציר, ממרכז המארח עד פני העמוד, בצד ±h של הקורה
    if(hostKind(key)!=='post')return 0;
    var u=faceAxis(d); return (TH/2-h*(n[0]*u[0]+n[1]*u[1]))/(d[0]*u[0]+d[1]*u[1]);}
  function cutDeg(d,key){if(hostKind(key)!=='post')return 0;
    var u=faceAxis(d); return Math.acos(Math.abs(d[0]*u[0]+d[1]*u[1]))*180/Math.PI;}
  function lowBeam(id,kind,seg,segN,ka,kb){
    var A=hostAt(ka),B=hostAt(kb),dx=B[0]-A[0],dy=B[1]-A[1],l=Math.hypot(dx,dy),d=[dx/l,dy/l],n=[-d[1],d[0]],h=TH/2,e=[-d[0],-d[1]];
    var a1=trimS(d,n,h,ka),a2=trimS(d,n,-h,ka),b1=trimS(e,n,h,kb),b2=trimS(e,n,-h,kb);
    var pts=[[A[0]+d[0]*a1+n[0]*h,A[1]+d[1]*a1+n[1]*h],[B[0]-d[0]*b1+n[0]*h,B[1]-d[1]*b1+n[1]*h],
             [B[0]-d[0]*b2-n[0]*h,B[1]-d[1]*b2-n[1]*h],[A[0]+d[0]*a2-n[0]*h,A[1]+d[1]*a2-n[1]*h]];
    var L1=l-a1-b1,L2=l-a2-b2,ca=cutDeg(d,ka),cb=cutDeg(e,kb);
    var beam={id:id+(segN>1?'.'+seg:''),base:id,level:'low',kind:kind,seg:segN>1?seg:0,
      name:'מסגרת מפלס 70 · '+kind+' ('+id+')'+(segN>1?' · קטע '+seg:''),
      pts:pts,z0:Z_LOW-TH,z1:Z_LOW,mat:'low',
      len:{outer:Math.max(L1,L2),inner:Math.min(L1,L2)},
      cuts:[{deg:ca,kind:hostKind(ka)==='post'?'face':'wall'},{deg:cb,kind:hostKind(kb)==='post'?'face':'wall'}],
      axis:[A,B],trim:[(a1+a2)/2,(b1+b2)/2],hosts:[ka,kb],joints:[],tongues:[],
      rows:['אורך '+Math.max(L1,L2).toFixed(1)+(Math.abs(L1-L2)>.05?' · הצד הקצר '+Math.min(L1,L2).toFixed(1):''),
            'חיתוך: קצה א׳ '+(ca<.5?'ישר':ca.toFixed(1)+'°')+' · קצה ב׳ '+(cb<.5?'ישר':cb.toFixed(1)+'°')+' — במקביל לפני העמוד',
            'פרופיל '+TH+'×'+TH+' · נגמרת על פני העמוד, לא עוברת דרכו']};
    M.beams.push(beam);
    return beam;
  }
  function lowBar(id,kind,hosts){   // קורה דרך רצף מארחים: קטע בין כל שניים, ושרוול בכל קצה קטע
    var L='אבגדהו',li=0,segN=hosts.length-1;
    for(var k=0;k<segN;k++){
      var ka=hosts[k],kb=hosts[k+1],ja=id+'·'+L.charAt(li++),jb=id+'·'+L.charAt(li++);
      var beam=lowBeam(id,kind,k+1,segN,ka,kb);
      beam.rows.push('מחברים: '+ja+', '+jb);
      joint(ja,beam,ka,hostAt(kb),Z_LOW); joint(jb,beam,kb,hostAt(ka),Z_LOW);
    }
  }
  [['ת1','דרום',['@'+C[0][0],'s1']],['ת2','אלכסון דרומי',['s1','e1']],['ת3','מזרח',['e1','e2','e3']],
   ['ת4','אלכסון צפוני',['e3','n1']],['ת5','צפון',['n1','nw']],['ת6','קטע ליד הקיר',['#-15','wp']]
  ].forEach(function(t){lowBar(t[0],t[1],t[2]);});
  /* קורות תומכות: הקצה המזרחי על העמוד; הקצה המערבי על פלטה בקיר (ק1, ק2) או על העמוד (ק3) */
  bar(beamById('ק1'),'e1','@'+x1,Z_TOP); bar(beamById('ק2'),'e2','@'+x2,Z_TOP); bar(beamById('ק3'),'e3','w3',Z_TOP);

  /* ---------- תוויות מידה בשרטוט ---------- */
  for(i=0;i<6;i++){var q=quads[i],mx=(q[0][0]+q[1][0])/2,my=(q[0][1]+q[1][1])/2;
    M.labels.push({txt:dist(q[0],q[1]).toFixed(1)+' / '+dist(q[2],q[3]).toFixed(1),x:mx,y:my,z:Z_TOP+14,size:11});}
  M.labels.push({txt:'ק1 · '+(yE-PLATE).toFixed(0),x:x1,y:110,z:Z_TOP+14,size:10});
  M.labels.push({txt:'ק2 · '+(yE-PLATE).toFixed(0),x:x2,y:110,z:Z_TOP+14,size:10});
  M.labels.push({txt:'ק3 · '+(yE+13).toFixed(0),x:x3,y:110,z:Z_TOP+14,size:10});

  M.dirName=dirName; M.dist=dist;
  return M;
}
return {K:K,build:build};
})();
