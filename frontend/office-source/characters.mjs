import * as T from 'three';
import {mergeGeometries} from 'three/addons/utils/BufferGeometryUtils.js';
import {CharacterMotion, smooth} from './motion.mjs';
import {paint,cloth,outlineCharacter} from './hand-drawn.mjs';
import {poseWorkout} from './workout-pose.mjs';

// All geometry and materials are local. Joint groups remain separate after batching.
const sphere = new T.SphereGeometry(1, 32, 24);
const mediumSphere = new T.SphereGeometry(1, 24, 16);
const smallSphere = new T.SphereGeometry(1, 16, 12);
const palettes = [
  {skin:0xf0c3a3, hair:0x493a2c, highlight:0x75604a, coat:0x587f9b, trim:0x7897a9, shirt:0xeee3c7, pants:0x4b5457, shoe:0x594a3c, iris:0x65523d, style:'blazer'},
  {skin:0xf1cbb2, hair:0x3d3535, highlight:0x6f5857, coat:0x9b8eac, trim:0xb5a8bb, shirt:0xf6e8ce, pants:0x57515f, shoe:0x655554, iris:0x535e4c, style:'bob'},
  {skin:0xdfaf8c, hair:0x514031, highlight:0x826448, coat:0xc5785c, trim:0xd99f7f, shirt:0x617568, pants:0x535e59, shoe:0xd4bf91, iris:0x6b5035, style:'bomber'},
  {skin:0xdcb08b, hair:0x3e4036, highlight:0x6e7158, coat:0x71865e, trim:0x97a37c, shirt:0xf0e4c8, pants:0x565a45, shoe:0x705a3e, iris:0x5d563d, style:'overshirt'},
  {skin:0xf0c9a6, hair:0x5b4530, highlight:0x887052, coat:0x5a7080, trim:0x8398a3, shirt:0xc2d3cd, pants:0x505e65, shoe:0x7c5b3f, iris:0x6e5b3f, style:'vest'},
  {skin:0xe9bc9c, hair:0x524639, highlight:0x887461, coat:0x747b72, trim:0x9b9e8e, shirt:0xf4e5c5, pants:0x5d6255, shoe:0x5a5146, iris:0x62664c, style:'suit'},
];

const material=paint;
const fabric=cloth;
const ivory=paint(0xfff6df,{texture:false});
const gold=paint(0xbea265);
const graphite=paint(0x484b43);
const pupil=paint(0x302f29,{texture:false});
const glint=paint(0xfff5de,{texture:false});

function group(parent,position=[0,0,0],name='') {
  const g=new T.Group();g.name=name;g.position.set(...position);parent.add(g);return g;
}
function mesh(parent,geometry,mat,position=[0,0,0],scale=[1,1,1]) {
  const m=new T.Mesh(geometry,mat);m.position.set(...position);m.scale.set(...scale);m.castShadow=true;m.receiveShadow=true;parent.add(m);return m;
}
const ellipsoid=(parent,mat,p,s)=>mesh(parent,Math.max(...s)<=.08?smallSphere:Math.max(...s)<=.23?mediumSphere:sphere,mat,p,s);
function line(parent,points,mat,radius=.008,segments=20) {
  const stroke=mesh(parent,new T.TubeGeometry(new T.CatmullRomCurve3(points.map(p=>new T.Vector3(...p))),segments,radius,6,false),mat);
  stroke.userData.noOutline=true;return stroke;
}
function shell(parent,profile,mat,position=[0,0,0],depth=.68) {
  const geometry=new T.LatheGeometry(profile.map(([y,r])=>new T.Vector2(r,y)),32);
  return mesh(parent,geometry,mat,position,[1,1,depth]);
}
function patch(parent,points,mat) {
  const shape=new T.Shape();points.forEach(([x,y],i)=>i?shape.lineTo(x,y):shape.moveTo(x,y));shape.closePath();
  const m=mesh(parent,new T.ExtrudeGeometry(shape,{depth:.016,bevelEnabled:true,bevelSize:.009,bevelThickness:.007,bevelSegments:2,steps:1}),mat);
  return m;
}

function sculptedFace(skinColor) {
  const geo=new T.SphereGeometry(1,40,32),pos=geo.attributes.position;
  const skin=new T.Color(skinColor),warm=new T.Color(0xda8576),shaded=new T.Color(skinColor).multiplyScalar(.92),colors=[];
  for(let i=0;i<pos.count;i++){
    const nx=pos.getX(i),ny=pos.getY(i),nz=pos.getZ(i);
    const jaw=ny<-.15?1-.19*Math.min(1,(-ny-.15)/.7):1;
    const cheek=Math.exp(-(((ny+.22)/.26)**2))*Math.max(0,nz);
    const x=nx*.373*jaw,y=ny*.432,z=nz*.332+cheek*.018;
    pos.setXYZ(i,x,y,z);
    const blush=Math.exp(-(((Math.abs(nx)-.54)/.23)**2+((ny+.19)/.26)**2))*Math.max(0,nz);
    const c=skin.clone().lerp(warm,blush*.36).lerp(shaded,Math.max(0,-ny)*.08);
    colors.push(c.r,c.g,c.b);
  }
  geo.setAttribute('color',new T.Float32BufferAttribute(colors,3));geo.computeVertexNormals();
  return geo;
}

function hairCap(parent,mat,isBob=false,short=false) {
  const points=[],uv=[],indices=[],rows=18,cols=48;
  for(let row=0;row<=rows;row++)for(let col=0;col<=cols;col++){
    const a=col/cols*Math.PI*2,front=Math.max(0,Math.cos(a));
    const boundary=1.97-front*(short?.78:.92)+Math.sin(a)*.06;
    const b=.002+row/rows*(boundary-.002);
    const wave=Math.sin(a*8+b*5)*.003;
    points.push(Math.sin(b)*Math.sin(a)*(.383+wave),.035+Math.cos(b)*.445,Math.sin(b)*Math.cos(a)*.344-.014);
    uv.push(col/cols,row/rows);
    if(row<rows&&col<cols){const n=row*(cols+1)+col;indices.push(n,n+cols+1,n+1,n+1,n+cols+1,n+cols+2)}
  }
  const g=new T.BufferGeometry();g.setAttribute('position',new T.Float32BufferAttribute(points,3));g.setAttribute('uv',new T.Float32BufferAttribute(uv,2));g.setIndex(indices);g.computeVertexNormals();mesh(parent,g,mat);
  if(isBob){
    // Rear hair stays behind the face; side locks frame the jaw.
    ellipsoid(parent,mat,[0,-.045,-.205],[.40,.46,.21]);
    for(const side of [-1,1]){
      const lock=ellipsoid(parent,mat,[side*.335,-.12,-.015],[.105,.32,.20]);lock.rotation.z=side*-.12;
    }
  }
}

function lock(parent,points,width,mat) {
  const curve=new T.CatmullRomCurve3(points.map(p=>new T.Vector3(...p))),steps=22,sides=8;
  const frames=curve.computeFrenetFrames(steps,false),vertices=[],uv=[],indices=[];
  for(let i=0;i<=steps;i++){
    const t=i/steps,center=curve.getPointAt(t),r=width*(.16+.84*Math.pow(Math.sin(Math.PI*t),.6));
    for(let j=0;j<=sides;j++){
      const a=j/sides*Math.PI*2;
      const v=center.clone().addScaledVector(frames.normals[i],Math.cos(a)*r).addScaledVector(frames.binormals[i],Math.sin(a)*r*.46);
      vertices.push(v.x,v.y,v.z);uv.push(t,j/sides);
      if(i<steps&&j<sides){const n=i*(sides+1)+j;indices.push(n,n+1,n+sides+1,n+1,n+sides+2,n+sides+1)}
    }
  }
  const g=new T.BufferGeometry();g.setAttribute('position',new T.Float32BufferAttribute(vertices,3));g.setAttribute('uv',new T.Float32BufferAttribute(uv,2));g.setIndex(indices);g.computeVertexNormals();return mesh(parent,g,mat);
}

function makeHair(head,p,index) {
  const base=material(p.hair);
  const detail=material(p.highlight);
  hairCap(head,base,index===1,index===3);
  if(index===1){
    for(let i=0;i<5;i++)lock(head,[[.10,.44,.02],[-.08-i*.033,.38,.23],[-.25-i*.018,.12-i*.035,.17]],.05,base);
    for(let i=0;i<3;i++)line(head,[[.1+i*.02,.45,.03],[-.12-i*.035,.39,.23],[-.28-i*.018,.10,.18]],detail,.0035);
  }else if(index===3){
    for(let i=0;i<5;i++)lock(head,[[-.27+i*.105,.28,.22],[-.22+i*.10,.43,.12],[-.18+i*.09,.40,-.12]],.064,base);
  }else{
    const lift=index===2?.085:index===5?-.015:.02;
    for(let i=0;i<5;i++){
      const x=-.28+i*.1;
      lock(head,[[x,.24+i*.012,.245],[x+.045,.43+lift,.19],[x+.17,.40+lift,-.08]],.075-i*.005,base);
      line(head,[[x+.005,.29+i*.01,.264],[x+.07,.475+lift,.17],[x+.16,.43+lift,-.045]],detail,.003,18);
    }
  }
  for(const side of [-1,1])ellipsoid(head,base,[side*.351,.04,-.015],[.032,.11,.07]);
}

function makeEyes(head,p,skin) {
  const eyes=[],iris=paint(p.iris,{texture:false});
  const lash=paint(0x443c32,{texture:false});
  const brow=material(p.hair);
  for(const side of [-1,1]){
    const eye=group(head,[side*.143,.035,.305],'eye');
    ellipsoid(eye,ivory,[0,0,.012],[.100,.071,.025]);
    ellipsoid(eye,iris,[-side*.007,-.002,.033],[.041,.055,.010]);
    ellipsoid(eye,pupil,[-side*.007,-.004,.041],[.023,.041,.005]);
    ellipsoid(eye,glint,[-.020,.020,.046],[.009,.012,.003]);
    line(eye,[[-.095,.008,.023],[-.064,.055,.032],[0,.070,.032],[.067,.045,.031],[.097,.003,.022]],lash,.0075);
    line(eye,[[-.085,-.027,.022],[0,-.064,.032],[.083,-.025,.024]],lash,.0035);
    eyes.push(eye);
    line(head,[[side*.143-.075,.171,.292],[side*.143,.191,.314],[side*.143+.074,.176,.291]],brow,.010);
  }
  return eyes;
}

function makeClothes(hips,p,index,m) {
  shell(hips,[[.02,.21],[.07,.25],[.25,.265],[.48,.30],[.59,.30],[.67,.225],[.69,.14],[.70,.095]],m.coat);
  // The shirt insert and shaped lapels follow the torso's front surface.
  const shirt=patch(hips,[[-.105,.65],[.105,.65],[.105,.15],[-.105,.15]],m.shirt);shirt.position.z=.19;
  if(p.style==='blazer'||p.style==='bob'||p.style==='suit'||p.style==='vest'){
    for(const side of [-1,1]){
      const lapel=patch(hips,[[side*.10,.65],[side*.215,.59],[side*.15,.43],[side*.20,.41],[side*.02,.21]],m.lapel);lapel.position.z=.203;
    }
    for(let i=0;i<2;i++)ellipsoid(hips,index===5?gold:graphite,[.014,.14+i*.115,.211],[.018,.018,.01]);
    line(hips,[[-.22,.22,.143],[-.13,.215,.197]],m.seam,.005);
    line(hips,[[.13,.215,.197],[.22,.22,.143]],m.seam,.005);
  }else{
    line(hips,[[0,.08,.185],[0,.37,.207],[0,.64,.165]],p.style==='bomber'?gold:m.seam,.009);
    shell(hips,[[.03,.22],[.045,.25],[.10,.25]],m.lapel);
    for(const side of [-1,1])line(hips,[[side*.15,.16,.177],[side*.23,.27,.12]],m.seam,.006);
  }
  for(const side of [-1,1]){
    const collar=patch(hips,[[side*.015,.68],[side*.105,.68],[side*.16,.59],[side*.075,.54]],m.shirt);collar.position.z=.19;
  }
  if(index===4||index===5){
    const tie=patch(hips,[[-.032,.57],[.032,.57],[.047,.31],[0,.25],[-.047,.31]],index===5?m.goldFabric:m.lapel);tie.position.z=.222;
    ellipsoid(hips,index===5?m.goldFabric:m.lapel,[0,.60,.226],[.04,.038,.015]);
  }
  // A small enamel brand pin and a tailored chest pocket.
  ellipsoid(hips,gold,[-.193,.52,.176],[.019,.025,.01]);
  line(hips,[[.14,.42,.19],[.235,.42,.138]],m.seam,.005);
  if(index===0||index===5){const pocket=patch(hips,[[.15,.425],[.17,.464],[.19,.442],[.215,.466],[.23,.425]],m.shirt);pocket.position.z=.179;}
  if(index===3){
    for(const side of [-1,1]){line(hips,[[side*.12,.39,.19],[side*.23,.39,.14]],m.lapel,.012);ellipsoid(hips,gold,[side*.18,.37,.173],[.008,.008,.008])}
  }
}

function makeHand(elbow,skin,side) {
  const hand=group(elbow,[0,-.303,0],'hand');
  ellipsoid(hand,skin,[0,-.027,.002],[.072,.09,.047]);
  for(let i=0;i<4;i++){
    const finger=ellipsoid(hand,skin,[-.046+i*.029,-.10+Math.abs(i-1.5)*.009,.008],[.017,.047-Math.abs(i-1.5)*.005,.024]);finger.rotation.z=(i-1.5)*-.06;
  }
  const thumb=ellipsoid(hand,skin,[-side*.069,-.025,.032],[.027,.054,.028]);thumb.rotation.z=side*.5;
  return hand;
}

// Merge only static siblings, never across a joint or eye. This keeps the detail
// count from turning into hundreds of draw calls per character.
function batchParts(root,index) {
  for(const child of [...root.children])if(child.isGroup)batchParts(child,index);
  const groups=new Map();
  for(const child of root.children){
    if(!child.isMesh)continue;
    const key=child.material.uuid+':'+Object.keys(child.geometry.attributes).sort().join(',');
    if(!groups.has(key))groups.set(key,[]);groups.get(key).push(child);
  }
  for(const parts of groups.values()){
    if(parts.length<2)continue;
    const copies=parts.map(part=>{part.updateMatrix();const g=part.geometry.clone().applyMatrix4(part.matrix);return g.index?g.toNonIndexed():g});
    const combined=mergeGeometries(copies,false);copies.forEach(g=>g.dispose());
    if(!combined)continue;
    const m=mesh(root,combined,parts[0].material);m.userData.agent=index;parts.forEach(p=>root.remove(p));
  }
}

export function makeCharacter(index) {
  const p=palettes[index],root=new T.Group();root.name='teammate-'+index;
  const hips=group(root,[0,1.06,0],'hips');
  const skin=material(p.skin);
  const m={skin,coat:fabric(p.coat),lapel:fabric(p.trim),shirt:fabric(p.shirt),trousers:fabric(p.pants),seam:fabric(new T.Color(p.coat).multiplyScalar(.7)),goldFabric:fabric(0xbca26b)};
  shell(hips,[[-.08,.19],[-.035,.235],[.07,.238],[.10,.22]],m.trousers);
  makeClothes(hips,p,index,m);
  ellipsoid(hips,skin,[0,.735,0],[.105,.145,.097]);
  const head=group(hips,[0,1.055,0],'head');
  mesh(head,sculptedFace(p.skin),material(0xffffff,{vertexColors:true}));
  const innerEar=material(new T.Color(p.skin).lerp(new T.Color(0xba7469),.32));
  for(const side of [-1,1]){
    ellipsoid(head,skin,[side*.366,-.012,-.003],[.069,.105,.067]);
    ellipsoid(head,innerEar,[side*.398,-.01,.045],[.032,.063,.017]);
    ellipsoid(head,skin,[side*.389,-.019,.059],[.018,.032,.01]);
    if(index===1)ellipsoid(head,gold,[side*.399,-.093,.026],[.019,.03,.018]);
  }
  const eyes=makeEyes(head,p,skin);
  ellipsoid(head,skin,[0,-.022,.323],[.030,.065,.028]);
  ellipsoid(head,skin,[0,-.071,.343],[.042,.034,.035]);
  line(head,[[.023,-.066,.369],[.032,-.087,.361],[.009,-.095,.359]],innerEar,.0045,10);
  const mouth=paint(0x815442,{texture:false});
  line(head,[[-.091,-.171,.306],[-.025,-.193,.318],[.033,-.192,.317],[.092,-.166,.305]],mouth,.0065);
  line(head,[[-.026,-.218,.299],[.014,-.221,.300],[.042,-.211,.296]],innerEar,.0035,10);
  makeHair(head,p,index);
  if(index===4){
    for(const side of [-1,1]){
      const rim=mesh(head,new T.TorusGeometry(.122,.009,8,32),graphite,[side*.144,.035,.375]);rim.scale.y=1.06;
      line(head,[[side*.262,.07,.365],[side*.356,.075,.16],[side*.389,.01,.06]],graphite,.008);
      ellipsoid(head,gold,[side*.267,.067,.364],[.012,.012,.012]);
    }
    line(head,[[-.023,.055,.378],[0,.065,.385],[.023,.055,.378]],graphite,.008);
  }
  const legs=[],shoeMat=material(p.shoe);
  const sole=material(index===2?0xeee6d4:0x393c34);
  for(const side of [-1,1]){
    const upper=group(hips,[side*.14,-.015,0],'thigh');
    shell(upper,[[-.43,.088],[-.39,.105],[-.19,.123],[-.035,.132],[.025,.105]],m.trousers,[0,0,0],.95);
    const knee=group(upper,[0,-.43,0],'knee');
    shell(knee,[[-.50,.084],[-.47,.088],[-.23,.095],[-.025,.105],[.04,.092]],m.trousers,[0,0,0],.96);
    line(knee,[[0,-.04,.102],[0,-.24,.096],[0,-.45,.087]],m.seam,.0035);
    ellipsoid(knee,sole,[0,-.542,.069],[.126,.044,.215]);
    ellipsoid(knee,shoeMat,[0,-.506,.071],[.121,.069,.208]);
    ellipsoid(knee,shoeMat,[0,-.465,-.031],[.10,.077,.107]);
    for(let i=0;i<3;i++)line(knee,[[-.042,-.453-i*.006,.045+i*.027],[.042,-.453-i*.006,.045+i*.027]],index===2?ivory:m.seam,.0045,6);
    if(index!==2)line(knee,[[-.086,-.501,.17],[0,-.457,.195],[.086,-.501,.17]],m.seam,.0035);
    legs.push({upper,knee});
  }
  const arms=[];
  for(const side of [-1,1]){
    const upper=group(hips,[side*.305,.60,0],'shoulder');upper.rotation.z=side*.10;
    const sleeve=index===4?m.shirt:m.coat;
    shell(upper,[[-.32,.077],[-.27,.097],[-.10,.115],[.005,.115],[.075,.055]],sleeve,[0,0,0],1);
    const elbow=group(upper,[0,-.31,0],'elbow');
    shell(elbow,[[-.265,.067],[-.24,.077],[-.09,.086],[.015,.082]],sleeve,[0,0,0],1);
    shell(elbow,[[-.29,.064],[-.27,.074],[-.235,.074]],index===2?m.lapel:m.shirt,[0,0,0],1);
    ellipsoid(elbow,gold,[side*.07,-.254,.016],[.008,.011,.01]);
    const hand=makeHand(elbow,skin,side);
    if(side===-1){
      shell(elbow,[[-.301,.07],[-.28,.07]],graphite,[0,0,0],1);
      ellipsoid(elbow,index===5?gold:graphite,[-.004,-.288,.073],[.042,.039,.014]);
      ellipsoid(elbow,pupil,[-.004,-.288,.087],[.032,.029,.005]);
      line(elbow,[[-.004,-.27,.095],[-.004,-.288,.095],[.012,-.292,.095]],ivory,.002,4);
    }
    arms.push({upper,elbow,hand});
  }
  outlineCharacter(root,index);
  batchParts(root,index);
  root.traverse(o=>{if(o.isMesh){o.userData.agent=index;o.geometry.computeBoundingSphere()}});
  const motion=new CharacterMotion({x:(index%3-1)*4.4,z:index<3?-2:3.7},index);
  root.position.set(motion.position.x,0,motion.position.z);
  return{root,hips,head,eyes,legs,arms,motion,index,gait:0,waveWeight:0};
}

export function poseCharacter(p,elapsed,dt) {
  const m=p.motion,pose=m.pose;
  p.gait=smooth(p.gait,m.state==='walking'?1:0,9,dt);
  p.waveWeight=smooth(p.waveWeight,m.state==='waving'?1:0,10,dt);
  const stride=Math.sin(m.phase*9)*p.gait;
  p.root.position.set(m.position.x,m.elevation||0,m.position.z);p.root.rotation.y=m.angle;
  p.hips.position.y=1.06-.43*pose+Math.abs(stride)*.022+Math.sin(elapsed*2+p.index)*.006*(1-p.gait);
  p.hips.rotation.z=stride*.025*(1-pose);
  p.head.rotation.z=Math.sin(elapsed*1.15+p.index)*.016-stride*.017;
  p.head.rotation.x=pose*.055;
  p.legs.forEach((leg,i)=>{
    const side=i?1:-1;
    leg.upper.rotation.x=-Math.PI/2*pose+stride*.45*side*(1-pose);
    leg.knee.rotation.x=Math.PI/2*pose+Math.max(0,-stride*side)*.54*(1-pose);
  });
  p.arms.forEach((arm,i)=>{
    const side=i?1:-1,weight=i===0?p.waveWeight:0;
    arm.upper.rotation.x=(pose*-.92-stride*.30*side*(1-pose))*(1-weight);
    arm.upper.rotation.z=side*(.10+Math.sin(elapsed*1.6+i)*.012)*(1-weight)-2.20*weight;
    arm.elbow.rotation.x=(pose*(-.66+Math.sin(elapsed*7+i)*.022)-.08*(1-pose))*(1-weight)-.30*weight;
    arm.elbow.rotation.z=Math.sin(m.wave*9)*.25*weight;
    arm.hand.rotation.z=Math.sin(m.wave*9+.8)*.11*weight;
  });
  poseWorkout(p,dt);
  const blinkCycle=(elapsed+p.index*.71)%4.8,blink=blinkCycle<.14?1-Math.sin(blinkCycle/.14*Math.PI)*.92:1;
  p.eyes.forEach(eye=>eye.scale.y=blink);
}
