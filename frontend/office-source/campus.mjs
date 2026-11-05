import * as T from 'three';
import {RoundedBoxGeometry} from 'three/addons/geometries/RoundedBoxGeometry.js';
import {mergeGeometries} from 'three/addons/utils/BufferGeometryUtils.js';

const C={blue:0x1a73e8,red:0xea4335,yellow:0xfbbc04,green:0x18a058,white:0xfafbf6,ink:0x263d45,wood:0xd5a366};
const materials=new Map(),geometries=new Map();
const sphere=new T.SphereGeometry(1,16,12),cube=new T.BoxGeometry(1,1,1);
function material(color,roughness=.72,metalness=0){const key=[color,roughness,metalness].join(':');if(!materials.has(key))materials.set(key,new T.MeshStandardMaterial({color,roughness,metalness}));return materials.get(key)}
function mesh(parent,geometry,mat,position=[0,0,0],scale=[1,1,1]){const m=new T.Mesh(geometry,mat);m.position.set(...position);m.scale.set(...scale);m.castShadow=true;m.receiveShadow=true;parent.add(m);return m}
function box(parent,color,p,s,r=0){if(!r)return mesh(parent,cube,material(color),p,s);const key=s.join(':')+':'+r;if(!geometries.has(key))geometries.set(key,new RoundedBoxGeometry(...s,2,r));return mesh(parent,geometries.get(key),material(color),p)}
function ball(parent,color,p,s){return mesh(parent,sphere,material(color),p,s)}
function cylinder(parent,color,p,top,bottom,height){const key=[top,bottom,height].join(':');if(!geometries.has(key))geometries.set(key,new T.CylinderGeometry(top,bottom,height,24));return mesh(parent,geometries.get(key),material(color),p)}
function group(parent,name,x=0,z=0){const g=new T.Group();g.name=name;g.position.set(x,0,z);parent.add(g);return g}
function rod(parent,color,a,b,r=.025){const start=new T.Vector3(...a),end=new T.Vector3(...b),delta=end.clone().sub(start);const m=cylinder(parent,color,start.clone().add(end).multiplyScalar(.5).toArray(),r,r,delta.length());m.quaternion.setFromUnitVectors(new T.Vector3(0,1,0),delta.normalize());return m}
function label(parent,text,p,width,height,color='#34434a',background='#f8f7f0'){
  if(typeof document==='undefined')return;
  const canvas=document.createElement('canvas');canvas.width=1024;canvas.height=Math.round(1024*height/width);
  const ctx=canvas.getContext('2d');if(!ctx)return;
  ctx.fillStyle=background;ctx.fillRect(0,0,canvas.width,canvas.height);ctx.fillStyle=color;ctx.textAlign='center';ctx.textBaseline='middle';ctx.font=`600 ${Math.floor(canvas.height*.55)}px Arial, sans-serif`;ctx.fillText(text,canvas.width/2,canvas.height/2,canvas.width*.92);
  const texture=new T.CanvasTexture(canvas);texture.colorSpace=T.SRGBColorSpace;
  const m=mesh(parent,new T.PlaneGeometry(width,height),new T.MeshBasicMaterial({map:texture}),p);m.castShadow=false;
}

function plant(parent,x,z,size=1,potColor=C.white){
  const g=group(parent,'planter',x,z);
  cylinder(g,potColor,[0,.24*size,0],.29*size,.23*size,.48*size);
  cylinder(g,0x564a38,[0,.48*size,0],.25*size,.25*size,.015);
  for(let i=0;i<9;i++){
    const a=i*2.4,h=(.8+(i%3)*.22)*size,r=(.20+(i%2)*.1)*size;
    rod(g,0x4d7950,[0,.45*size,0],[Math.sin(a)*r,h,Math.cos(a)*r],.012*size);
    const leaf=ball(g,[0x187c43,0x2d9c4c,0x70b545][i%3],[Math.sin(a)*r,h,Math.cos(a)*r],[.12*size,.28*size,.045*size]);
    leaf.rotation.set(Math.cos(a)*.55,a,Math.sin(a)*-.55);
  }
  return g;
}
function tree(parent,x,z,size=1){
  const g=group(parent,'garden-tree',x,z);
  cylinder(g,0x98775b,[0,.95*size,0],.07*size,.10*size,1.9*size);
  for(let i=0;i<4;i++)ball(g,[0x61a756,0x2e884b,0x82bf65,0x439f56][i],[Math.sin(i*2.4)*.33*size,(1.6+i*.20)*size,Math.cos(i*2.4)*.26*size],[.60*size,.63*size,.50*size]);
}
function mug(parent,x,y,z,color){cylinder(parent,color,[x,y+.07,z],.065,.06,.14);cylinder(parent,0x67503d,[x,y+.142,z],.051,.051,.004);const handle=mesh(parent,new T.TorusGeometry(.044,.012,6,12),material(color),[x+.065,y+.075,z]);handle.rotation.y=Math.PI/2}
function taskChair(parent,x,z,color){
  const g=group(parent,'task-chair',x,z);
  box(g,color,[0,.55,0],[.72,.13,.65],.06);
  const back=box(g,color,[0,.98,.30],[.70,.77,.12],.055);back.rotation.x=.08;
  box(g,0xdee2e0,[0,.95,.375],[.13,.65,.025],.012);
  cylinder(g,0x74838a,[0,.27,0],.047,.047,.49);
  for(let i=0;i<5;i++){
    const a=i*Math.PI*2/5,dx=Math.sin(a)*.36,dz=Math.cos(a)*.36;
    rod(g,0x7a878c,[0,.12,0],[dx,.08,dz],.026);ball(g,0x465159,[dx,.065,dz],[.064,.055,.06]);
  }
  for(const side of [-1,1]){rod(g,0x98a4a7,[side*.34,.56,.10],[side*.34,.79,.10],.02);box(g,0x54616a,[side*.34,.80,-.01],[.07,.045,.36],.02)}
  return g;
}
function desk(parent,index,{accent,chairColor}={}){
  const x=(index%3-1)*4.4,z=index<3?-3.15:2.55,g=group(parent,'workstation-'+index,x,z);
  box(g,C.wood,[0,1.05,0],[2.9,.12,1.05],.055);
  for(const side of [-1,1]){box(g,0xeeeeda,[side*1.24,.52,0],[.075,1.0,.77],.025);box(g,0xbac1bd,[side*1.24,.05,0],[.13,.045,.85],.02)}
  box(g,accent??[C.blue,C.green,C.red,C.green,C.blue,C.yellow][index],[0,1.20,-.44],[2.6,.37,.045],.015);
  box(g,0x4f5e63,[0,1.23,-.04],[.055,.30,.055],.015);box(g,0x78878b,[0,1.12,-.025],[.40,.025,.25],.01);
  box(g,0x3c4d57,[0,1.58,-.06],[1.05,.65,.075],.045);
  box(g,0xe7eff2,[0,1.58,-.016],[.95,.55,.012],.008);
  box(g,C.blue,[-.29,1.58,-.006],[.16,.43,.005]);
  for(let n=0;n<4;n++)box(g,n===0?0x91aec1:0xb8c8ce,[.09,1.74-n*.095,-.005],[.44-(n%2)*.1,.025,.004]);
  box(g,0x758991,[0,1.125,.29],[.65,.034,.22],.015);
  for(let n=0;n<3;n++)box(g,0xc9d4d4,[0,1.145,.23+n*.055],[.55,.005,.015]);
  ball(g,0xc9d4d4,[.53,1.135,.29],[.06,.028,.085]);
  box(g,accent??[C.blue,C.red,C.yellow,C.green,C.blue,C.yellow][index],[-.88,1.13,.13],[.34,.035,.44],.013);
  mug(g,.91,1.115,.23,accent??[C.blue,C.yellow,C.red,C.green,C.white,C.blue][index]);
  const sprout=plant(g,1.07,-.26,.27,0xe1e1d6);sprout.position.y=1.115;
  taskChair(parent,x,z+1.15,chairColor??[C.blue,C.green,C.red,C.green,C.blue,C.yellow][index]);
  return g;
}
function sofa(parent,name,x,z,color,rotation=0){
  const g=group(parent,name,x,z);g.rotation.y=rotation;
  for(const side of [-1,1])for(const end of [-1,1])cylinder(g,0x9d805e,[side*1.02,.14,end*.31],.045,.035,.24);
  box(g,color,[0,.42,0],[2.45,.43,.93],.15);box(g,color,[0,.93,-.37],[2.45,.79,.25],.12);
  for(const side of [-1,1]){box(g,color,[side*1.12,.75,.03],[.24,.60,.95],.11);box(g,color,[side*.52,.66,.06],[.95,.14,.73],.065)}
  for(const side of [-1,1]){const cushion=box(g,side<0?C.yellow:C.red,[side*.67,.98,-.13],[.42,.43,.13],.07);cushion.rotation.z=side*.14}
  return g;
}
function roundTable(parent,x,z,r=.7,y=.63){
  const top=cylinder(parent,C.wood,[x,y,z],r,r,.08);cylinder(parent,0xe7e6dc,[x,y/2,z],.10,.22,y-.04);return top;
}

// Bake static opaque furnishings into shared material batches. Glass and label
// planes keep their own draw order and shadow settings.
function batchScene(root){
  root.updateMatrixWorld(true);const rootInverse=root.matrixWorld.clone().invert(),bins=new Map();
  root.traverse(o=>{if(o.isMesh&&!o.material.transparent){const key=o.material.uuid+':'+o.castShadow+':'+o.receiveShadow;if(!bins.has(key))bins.set(key,[]);bins.get(key).push(o)}});
  for(const parts of bins.values()){
    if(parts.length<2)continue;
    const copies=parts.map(o=>{o.updateWorldMatrix(true,false);let g=o.geometry.clone().applyMatrix4(rootInverse.clone().multiply(o.matrixWorld));if(g.index){const non=g.toNonIndexed();g.dispose();g=non}return g});
    const geometry=mergeGeometries(copies,false);copies.forEach(g=>g.dispose());if(!geometry)continue;
    const m=mesh(root,geometry,parts[0].material);m.castShadow=parts[0].castShadow;m.receiveShadow=parts[0].receiveShadow;parts.forEach(p=>p.removeFromParent());
  }
}

export function createCampus(){
  const root=new T.Group();root.name='Techweiser daylight campus';
  const obstacles=[],deskObstacles=[];
  function obstruction(g){root.updateMatrixWorld(true);const b=new T.Box3().setFromObject(g);obstacles.push({name:g.name,minX:b.min.x,maxX:b.max.x,minZ:b.min.z,maxZ:b.max.z});return g}
  box(root,0xd7dfdf,[0,-.19,-.05],[21.6,.36,13.2],.13);
  box(root,0xf7f5ed,[0,-.015,-.05],[21.3,.04,12.95]);
  // Pale oak border and a generous central circulation lane.
  box(root,0xe5bd77,[0,.012,.25],[13.6,.018,2.05]);
  for(let x=-6.6;x<=6.6;x+=.55)box(root,0xc79552,[x,.023,.25],[.007,.002,2.02]);
  for(const z of [-2.6,3.0])box(root,z<0?0xcbdcf1:0xcce6d5,[0,.014,z],[12.65,.022,3.4],.01);
  for(const z of [-4.32,4.72])box(root,z<0?C.blue:C.green,[0,.03,z],[12.65,.01,.025]);
  // Window garden: view and greenery remain visible through a light glass tint.
  box(root,0x8fbd72,[0,-.02,-7.12],[21.1,.12,1.3]);
  for(const [x,s]of[[-9,1.1],[-6.7,.8],[-3.8,1.05],[3.8,.9],[6.8,1.2],[9.3,.85]])tree(root,x,-7.25,s);
  box(root,0xf6f5ee,[0,.27,-6.34],[21.35,.55,.16]);
  box(root,0xf4f2e7,[0,3.95,-6.34],[21.35,.22,.26],.05);
  const glass=new T.MeshPhysicalMaterial({color:0xd4e9ec,roughness:.12,metalness:.05,transparent:true,opacity:.16,depthWrite:false,side:T.DoubleSide});
  for(let x=-10.5;x<=10.5;x+=3.5){box(root,0xf6f5ef,[x,2.15,-6.34],[.07,3.65,.14]);if(x<10.5){const pane=mesh(root,new T.PlaneGeometry(3.43,3.45),glass,[x+1.75,2.15,-6.34]);pane.castShadow=false;pane.receiveShadow=false}}
  // A central brand wall and collaboration table anchor the back of the room.
  box(root,0xf8f7f0,[0,2.12,-6.23],[5.4,3.2,.20],.08);
  label(root,'TECHWEISER',[0,2.98,-6.115],4.4,.52);
  label(root,'AI CAMPUS',[0,2.40,-6.112],2.5,.28,'#718079');
  [C.blue,C.red,C.yellow,C.green].forEach((c,i)=>box(root,c,[-.57+i*.38,2.03,-6.10],[.28,.055,.035],.02));
  const collaboration=group(root,'collaboration nook',0,-5.17);
  box(collaboration,0x62bd9c,[0,.018,0],[4.8,.025,1.75],.01);
  box(collaboration,C.wood,[0,.81,0],[2.9,.10,.83],.11);
  for(const x of[-1.1,1.1])box(collaboration,0xe7e8de,[x,.39,0],[.10,.76,.55],.025);
  for(const [i,x]of[-1.75,1.75].entries()){cylinder(collaboration,i?C.red:C.blue,[x,.42,0],.29,.29,.20);cylinder(collaboration,0xf2f0e3,[x,.18,0],.045,.045,.32)}
  box(collaboration,0x6c8591,[.35,.875,.02],[.51,.025,.34],.012);mug(collaboration,-.75,.865,.05,C.yellow);
  obstruction(collaboration);
  for(let i=0;i<6;i++){const station=desk(root,i);root.updateMatrixWorld(true);const b=new T.Box3().setFromObject(station);deskObstacles.push({name:station.name,minX:b.min.x,maxX:b.max.x,minZ:b.min.z,maxZ:b.max.z})}
  // Soft lounge, surrounded by an acoustic screen and tall plants.
  const lounge=group(root,'lounge',-8.33,.25);
  box(lounge,0xf2d58b,[0,.022,0],[3.2,.025,4.6],.01);
  sofa(lounge,'blue sofa',0,-1.35,C.blue);
  roundTable(lounge,0,.02,.57,.55);box(lounge,C.red,[.10,.61,.02],[.32,.035,.24],.01);
  for(const [x,color]of[[-.82,C.yellow],[.76,C.green]]){const puff=ball(lounge,color,[x,.34,1.45],[.54,.34,.56]);puff.rotation.y=.2;}
  for(let i=0;i<7;i++)box(lounge,0xc9b28e,[-1.53,.94,-2.0+i*.30],[.06,1.9,.08],.02);
  obstruction(lounge);
  obstruction(plant(root,-9.65,3.58,1.23,C.white));
  obstruction(plant(root,-6.92,-4.95,1.1,0xe9ded0));
  obstruction(plant(root,6.92,-5.20,1.05,0xe9ded0));
  // Whiteboard studio with a colorful acoustic wall, left of the main desks.
  const ideas=group(root,'whiteboard studio',-8.4,-4.8);
  box(ideas,0xf4f2e9,[0,1.75,-.37],[3.0,2.65,.14],.07);
  for(let i=0;i<4;i++){const disc=cylinder(ideas,[C.blue,C.red,C.yellow,C.green][i],[-1.02+i*.68,2.56,-.27],.22,.22,.055);disc.rotation.x=Math.PI/2}
  box(ideas,0xffffff,[0,1.54,-.265],[2.60,1.35,.045],.04);
  for(let i=0;i<8;i++)box(ideas,[C.blue,C.yellow,C.red,C.green][i%4],[-.86+(i%4)*.56,1.87-Math.floor(i/4)*.46,-.232],[.32,.27,.012]);
  for(const x of[-1.0,1.0])rod(ideas,0x8f9b9e,[x,.06,.12],[x,1.26,-.32],.035);
  obstruction(ideas);
  // Café counter and stools create a distinct destination on the right.
  const cafe=group(root,'coffee bar',8.50,-4.7);
  box(cafe,0xf4f2e9,[0,1.75,-.57],[3.35,2.95,.15],.08);
  label(cafe,'THE DAILY BREW',[0,2.76,-.475],2.7,.31,'#556b5f');
  box(cafe,C.green,[0,.65,0],[3.15,1.25,.74],.055);box(cafe,C.wood,[0,1.30,0],[3.3,.10,.88],.045);
  for(const x of[-1.0,0,1.0])box(cafe,0x086b3b,[x,.72,.378],[.015,.95,.006]);
  box(cafe,0x4a575d,[-.64,1.64,0],[.63,.57,.45],.04);box(cafe,0xaeb9b9,[-.64,1.66,.24],[.49,.34,.018],.025);cylinder(cafe,0x40372f,[-.64,1.99,-.02],.14,.12,.14);
  mug(cafe,-.71,1.36,.30,C.white);mug(cafe,.5,1.35,.15,C.yellow);mug(cafe,.81,1.35,.15,C.red);
  box(cafe,C.wood,[0,2.19,-.40],[2.85,.06,.32],.02);
  for(let i=0;i<5;i++)cylinder(cafe,[C.blue,C.red,C.yellow,C.green,C.white][i],[-.9+i*.45,2.32,-.38],.075,.075,.20);
  obstruction(cafe);
  const counter=group(root,'cafe high table',8.55,-2.65);
  box(counter,C.wood,[0,1.04,0],[2.55,.09,.65],.06);
  for(const x of[-.95,.95]){rod(counter,C.white,[x,.06,0],[x,1.0,0],.045);cylinder(counter,0xc4cdcb,[x,.03,0],.28,.28,.035);cylinder(counter,C.yellow,[x,.69,.68],.24,.24,.11);for(const dx of[-.13,.13])for(const dz of[-.13,.13])rod(counter,0x9b825f,[x+dx,.05,.68+dz],[x+dx*.6,.65,.68+dz*.6],.025)}
  obstruction(counter);
  const booths=group(root,'focus booths',8.65,1.4);
  for(const [i,z]of[-.78,1.05].entries()){
    const pod=group(booths,'focus pod '+i,0,z);
    box(pod,i?C.green:C.blue,[.55,1.1,0],[.19,2.2,1.52],.08);
    for(const end of[-1,1])box(pod,i?C.green:C.blue,[0,1.1,end*.69],[1.2,2.2,.15],.065);
    box(pod,C.yellow,[.05,.52,0],[1.0,.20,1.20],.08);
    box(pod,C.white,[-.29,.97,0],[.40,.055,.76],.035);
  }
  obstruction(booths);
  obstruction(plant(root,9.62,4.15,1.3,C.white));
  // A partial ceiling frame and pendant lamps leave all characters unobstructed.
  for(const x of[-8.45,0,8.5]){
    box(root,0xe1dacb,[x,4.06,-5.05],[3.55,.10,2.65],.035);
    for(const dx of[-.65,.65]){rod(root,0xa6aaa1,[x+dx,4.0,-5.1],[x+dx,3.36,-5.1],.012);cylinder(root,dx<0?C.yellow:C.white,[x+dx,3.31,-5.1],.16,.32,.27);cylinder(root,0xfff1c6,[x+dx,3.17,-5.1],.26,.26,.015)}
  }
  root.userData.walkObstacles=obstacles;root.userData.deskObstacles=deskObstacles;root.userData.workstations=6;
  batchScene(root);
  return root;
}

export function createGagaOffice(){
  const root=new T.Group();root.name='Gaga office';
  const G={purple:0x8556d8,rose:0xf16a98,amber:0xffbe39,teal:0x20a99b,wall:0xfff7ef};
  const obstacles=[],movementObstacles=[],meetingSeats=[];
  function footprint(g,name=g.name){root.updateMatrixWorld(true);const b=new T.Box3().setFromObject(g);return{name,minX:b.min.x,maxX:b.max.x,minZ:b.min.z,maxZ:b.max.z}}
  function solid(g,name){movementObstacles.push(footprint(g,name));return g}
  function obstruction(g){const bounds=footprint(g);obstacles.push(bounds);if(['planter','Gaga storage','Gaga idea board'].includes(g.name))movementObstacles.push(bounds);return g}
  box(root,0xdcd6e7,[0,-.19,0],[13.6,.36,13.2],.13);
  box(root,G.wall,[0,-.015,0],[13.35,.04,12.95]);
  box(root,0xeacb92,[0,.012,4.95],[13.35,.018,1.82]);
  for(let x=-6.3;x<=6.3;x+=.55)box(root,0xc29d66,[x,.024,4.95],[.007,.002,1.8]);
  box(root,0xe3d8f0,[-1.3,.018,-.25],[8.6,.024,7.1],.01);
  for(const z of[-3.79,3.29])box(root,G.purple,[-1.3,.035,z],[8.6,.01,.035]);
  // Glazed rear elevation with a separately branded center wall.
  box(root,0x91be79,[0,-.025,-7.1],[13.25,.11,1.25]);
  for(const [x,s]of[[-5.6,.95],[-3.8,.8],[3.9,.9],[5.6,1.05]])tree(root,x,-7.2,s);
  box(root,G.wall,[0,.27,-6.34],[13.4,.55,.16]);
  box(root,G.wall,[0,3.95,-6.34],[13.4,.22,.26],.05);
  const glass=new T.MeshPhysicalMaterial({color:0xebdef7,transparent:true,opacity:.15,roughness:.12,depthWrite:false,side:T.DoubleSide});
  for(let x=-6.6;x<=6.6;x+=3.3){
    box(root,0xf9f2e9,[x,2.1,-6.34],[.07,3.6,.14]);
    if(x<6.5){const pane=mesh(root,new T.PlaneGeometry(3.23,3.45),glass,[x+1.65,2.12,-6.34]);pane.castShadow=false;pane.receiveShadow=false}
  }
  box(root,0xeee0f4,[0,2.13,-6.20],[5.4,3.25,.20],.08);
  label(root,'GAGA',[0,2.94,-6.088],3.7,.68,'#7543bd','#eee0f4');
  label(root,'OFFICE',[0,2.31,-6.087],2.4,.29,'#875a89','#eee0f4');
  for(const [i,c]of[G.purple,G.rose,G.amber,G.teal].entries())box(root,c,[-.63+i*.42,1.92,-6.07],[.32,.065,.04],.025);
  // Low side boundary leaves the front opening clear for the shared walkway.
  box(root,G.wall,[-6.65,.30,-1.50],[.12,.60,8.6],.04);
  box(root,G.purple,[-6.65,.63,-1.50],[.16,.045,8.6],.02);
  const workstationCenters=[];
  for(let i=0;i<4;i++){
    const x=i%2===0?-3.3:.7,z=i<2?-2.40:1.35;
    const template=[0,1,3,4][i],defaultX=(template%3-1)*4.4,defaultZ=template<3?-3.15:2.55;
    const station=group(root,'Gaga workstation '+(i+1),x-defaultX,z-defaultZ);
    const accent=[G.purple,G.rose,G.teal,G.amber][i];
    solid(desk(station,template,{accent,chairColor:accent}),'Gaga desk '+(i+1));obstruction(station);
    workstationCenters.push({x,z,chairZ:z+1.15});
  }
  // Round meeting table and an idea board beside the desks.
  const meeting=group(root,'Gaga meeting area',4.62,-3.0);
  box(meeting,0xf3d49d,[0,.018,0],[3.35,.025,3.65],.01);
  solid(roundTable(meeting,0,0,.82,.82),'Gaga meeting table');
  for(const i of[0,2]){
    const angle=i*Math.PI/2,x=Math.sin(angle)*1.4,z=Math.cos(angle)*1.4;
    cylinder(meeting,[G.rose,G.purple,G.teal,G.amber][i],[x,.47,z],.27,.27,.18);
    cylinder(meeting,0xd8d2c8,[x,.21,z],.045,.045,.36);
    meetingSeats.push({x:4.62+x,z:-3+z});
  }
  box(meeting,G.purple,[.05,.88,.02],[.48,.025,.32],.015);mug(meeting,-.38,.86,.2,G.rose);
  obstruction(meeting);
  const board=group(root,'Gaga idea board',4.65,-5.65);
  box(board,0xd7c0ef,[0,1.9,0],[2.75,2.4,.12],.07);
  box(board,0xfffcf2,[0,1.80,.075],[2.48,1.65,.035],.035);
  label(board,'IDEAS WELCOME',[0,2.80,.078],2.4,.22,'#7543bd','#e4d3f4');
  for(let i=0;i<6;i++)box(board,[G.purple,G.rose,G.amber][i%3],[-.74+(i%3)*.72,2.22-Math.floor(i/3)*.61,.10],[.40,.35,.012]);
  obstruction(board);
  const lounge=group(root,'Gaga guest lounge',4.6,1.7);
  box(lounge,0xf4d5df,[0,.021,.65],[3.45,.025,4.0],.01);
  solid(sofa(lounge,'Gaga coral sofa',0,-.60,G.rose));
  solid(roundTable(lounge,0,.85,.59,.56),'Gaga lounge table');
  box(lounge,G.purple,[.10,.62,.85],[.34,.028,.25],.012);
  for(const [x,c]of[[-.80,G.amber],[.83,G.purple]])solid(ball(lounge,c,[x,.32,2.0],[.43,.32,.43]),'Gaga lounge seat');
  obstruction(lounge);
  // Storage, refreshments and greenery make the new office ready to furnish with a team.
  const cabinet=group(root,'Gaga storage',-3.95,-5.45);
  box(cabinet,0xe0c48f,[0,.54,0],[3.2,1.05,.63],.035);
  box(cabinet,G.purple,[-.8,.55,.325],[1.48,.90,.025],.02);
  box(cabinet,G.rose,[.8,.55,.325],[1.48,.90,.025],.02);
  for(const x of[-.24,.24])box(cabinet,0xf6edcf,[x,.58,.35],[.035,.19,.02],.01);
  for(let i=0;i<4;i++)box(cabinet,[G.purple,G.amber,G.teal,G.rose][i],[-1.12+i*.23,1.22,-.05],[.15,.31,.27],.01);
  mug(cabinet,.73,1.08,.04,G.purple);mug(cabinet,1.02,1.08,.04,G.rose);
  obstruction(cabinet);
  obstruction(plant(root,-5.85,-4.1,1.03,G.amber));
  obstruction(plant(root,-5.85,2.90,1.0,G.purple));
  obstruction(plant(root,5.95,5.40,1.07,C.white));
  // Canopy and hanging lamps stop above the rear wall, keeping the room open to view.
  for(const x of[-3.7,3.7]){
    box(root,0xeee1cf,[x,4.06,-5.05],[4.9,.10,2.3],.035);
    for(const dx of[-.7,.7]){rod(root,0xa4a0ac,[x+dx,4.0,-5.1],[x+dx,3.4,-5.1],.012);cylinder(root,dx<0?G.purple:G.amber,[x+dx,3.35,-5.1],.15,.28,.25)}
  }
  root.userData={brand:'Gaga',workstations:4,workstationCenters,meetingSeats,walkObstacles:obstacles,movementObstacles,entrance:{x:-6.65,z:5.5}};
  batchScene(root);return root;
}

export function createOfficeLink(){
  const root=new T.Group();root.name='shared office walkway';
  // Main floor ends at x=10.8; Gaga starts at x=13.2. Overlap both edges.
  box(root,0xc9d1d7,[12,-.19,5.5],[2.9,.36,1.70],.08);
  box(root,0xf1dbab,[12,.014,5.5],[2.9,.028,1.65]);
  for(let x=10.65;x<13.4;x+=.25)box(root,0xd1b987,[x,.031,5.5],[.012,.003,1.62]);
  // The south edge is open where the new gym walkway branches off.
  for(const z of[4.70]){
    rod(root,0xbccbd1,[10.7,.60,z],[13.3,.60,z],.022);
    for(const x of[10.7,12,13.3])rod(root,0xbccbd1,[x,.03,z],[x,.60,z],.018);
  }
  root.userData={bounds:{minX:10.55,maxX:13.45,minZ:4.675,maxZ:6.325},center:{x:12,z:5.5}};
  batchScene(root);return root;
}

export {box,ball,cylinder,group,rod,label,plant,batchScene};
