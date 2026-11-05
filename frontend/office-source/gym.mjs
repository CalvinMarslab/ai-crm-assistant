import * as T from 'three';
import {box,ball,cylinder,group,rod,label,batchScene} from './campus.mjs';
import {GYM_STATIONS,HYROX_ZONES,GYM_BOUNDS,GYM_CONNECTOR} from './gym-layout.mjs';

const C={ink:0x151b20,rubber:0x2c343a,steel:0x71818a,yellow:0xffd52a,lime:0xd9f343,white:0xf3f0df,turf:0x243c28};
function floorLabel(parent,text,x,z,width,height,color='#ffd52a',background='#2c343a'){
  const g=group(parent,'floor sign '+text,x,z);g.position.y=.075;g.rotation.x=-Math.PI/2;
  label(g,text,[0,0,0],width,height,color,background);
}
function kettlebell(parent,x,y,z,size=1){
  const g=group(parent,'kettlebell',x,z);g.position.y=y;g.scale.setScalar(size);
  ball(g,C.ink,[0,0,0],[.15,.18,.15]);
  const handle=new T.Mesh(new T.TorusGeometry(.105,.026,8,16),new T.MeshStandardMaterial({color:C.yellow}));handle.position.y=.19;g.add(handle);
  return g;
}
function sled(parent,z){
  const g=group(parent,'loaded sled',0,z);
  for(const x of[-.52,.52])box(g,C.steel,[x,.11,0],[.12,.16,1.25],.04);
  box(g,C.ink,[0,.24,0],[1.20,.18,.95],.04);
  cylinder(g,C.steel,[0,.64,0],.045,.045,.85);
  for(let i=0;i<4;i++)cylinder(g,i===3?C.yellow:C.ink,[0,.36+i*.085,0],.34,.34,.075);
  for(const x of[-.47,.47])rod(g,C.yellow,[x,.25,-.42],[x,1.48,-.42],.035);
  return g;
}

export function createGym(){
  const root=new T.Group();root.name='HYROX rear training arena';
  const obstacles=[],fans=[];
  function obstacle(g,name=g.name){root.updateMatrixWorld(true);const b=new T.Box3().setFromObject(g);obstacles.push({name,minX:b.min.x,maxX:b.max.x,minZ:b.min.z,maxZ:b.max.z});return g}
  // Open-air arena behind the offices: no enclosing walls or entrance arch.
  box(root,C.ink,[12,-.19,-21],[36,.36,24],.12);
  box(root,C.yellow,[12,.008,-21],[35.8,.026,23.8],.03);
  box(root,C.rubber,[12,.025,-21],[35.25,.025,23.25],.03);
  box(root,0x414b50,[12,.04,-20.8],[30.5,.022,18.5],.03);
  for(const x of[-5.1,-3.3,27.3,29.1])box(root,C.yellow,[x,.058,-21],[.055,.008,22.1]);
  for(const z of[-31.9,-30.1,-11.9,-10.1])box(root,C.yellow,[12,.058,z],[34.2,.008,.055]);
  for(const x of[-4.2,28.2])for(const z of[-12.6,-18.6,-24.6,-29.5]){
    const arrow=group(root,'run direction',x,z);arrow.rotation.y=x<0?0:Math.PI;
    rod(arrow,C.white,[-.24,.067,-.25],[0,.067,.05],.025);rod(arrow,C.white,[0,.067,.05],[.24,.067,-.25],.025);
  }
  for(let i=0;i<8;i++)for(let j=0;j<2;j++)box(root,(i+j)%2?C.white:C.ink,[-4.9+i*.20,.063,-20+j*.2],[.20,.008,.20]);
  floorLabel(root,'HYROX',12,-31,10,1.1,'#151b20','#ffd52a');
  floorLabel(root,'TRAINING CLUB',12,-29.5,8,.52);
  floorLabel(root,'RUN LOOP',-4.2,-21.6,1.8,.32);
  floorLabel(root,'RUN LOOP',28.2,-21.6,1.8,.32);
  floorLabel(root,'ROXZONE  /  IN + OUT',12,-10.35,8,.55);
  // The spine occupies the existing gap between the two offices.
  const passage=group(root,'rear arena access');
  box(passage,0xc9d1d7,[12,-.19,-1.9],[2.1,.36,15.4],.04);
  box(passage,C.yellow,[12,.015,-1.9],[2.05,.028,15.4]);
  for(const x of[11.15,12.85])box(passage,C.ink,[x,.035,-1.9],[.04,.012,15.3]);
  for(const z of[-7,-4,-1,2]){
    rod(passage,C.ink,[11.7,.045,z+.15],[12,.045,z-.15],.025);
    rod(passage,C.ink,[12,.045,z-.15],[12.3,.045,z+.15],.025);
  }
  for(const station of HYROX_ZONES){
    const {x,z,kind,index,angle}=station;
    const bay=group(root,station.label+' training bay',x,z);bay.rotation.y=angle;
    box(bay,kind==='push'||kind==='pull'?C.turf:C.ink,[0,.055,0],[5.7,.018,6.6],.02);
    for(const side of[-1,1])box(bay,C.yellow,[side*2.72,.069,0],[.05,.008,6.3]);
    floorLabel(root,String(index+1).padStart(2,'0')+'  '+station.label,x,z+3.1,5.3,.46,'#151b20','#ffd52a');
    if(['push','pull','burpee','carry','lunge'].includes(kind)){
      for(let d=-2.6;d<=2.6;d+=.65)for(const side of[-1,1])box(bay,C.white,[side*2.35,.075,d],[.35,.006,.035]);
      box(bay,C.yellow,[0,.075,0],[.04,.005,5.4]);
    }
    if(kind==='ski'){
      const machine=group(bay,'SkiErg frame',0,1.2);
      box(machine,C.steel,[0,.07,0],[1.15,.12,.6],.04);
      for(const side of[-1,1])rod(machine,C.ink,[side*.48,.1,0],[side*.48,2.75,0],.055);
      rod(machine,C.yellow,[-.62,2.78,0],[.62,2.78,0],.05);
      for(const side of[-1,1]){rod(machine,C.steel,[side*.45,2.72,0],[side*.30,1.70,-.60],.014);rod(machine,C.yellow,[side*.30-.09,1.70,-.60],[side*.30+.09,1.70,-.60],.025)}
      box(machine,C.ink,[0,1.45,.04],[.40,.32,.18],.03);obstacle(machine,'SkiErg frame');
      const wheel=cylinder(machine,C.steel,[0,.6,.1],.28,.28,.18);wheel.rotation.x=Math.PI/2;
    }else if(kind==='push'||kind==='pull'){
      obstacle(sled(bay,kind==='push'?1.30:2.35),station.label);
      if(kind==='pull'){
        rod(bay,0xd3b27a,[0,.32,1.85],[0,.75,.38],.027);
        for(let i=0;i<4;i++)rod(bay,0xd3b27a,[-.25+(i%2)*.5,.08,-.7-i*.25],[.25-(i%2)*.5,.08,-.9-i*.25],.025);
      }
    }else if(kind==='row'){
      // Seat and rail are intentional contact surfaces, not walking obstacles.
      box(bay,C.steel,[0,.31,.35],[.13,.13,2.4],.025);
      box(bay,C.ink,[0,.58,-.10],[.50,.10,.43],.035);
      const machine=group(bay,'rower flywheel',0,1.65);
      const housing=cylinder(machine,C.ink,[0,.63,0],.40,.40,.30);housing.rotation.x=Math.PI/2;
      const disc=cylinder(machine,C.steel,[0,.63,-.17],.33,.33,.02);disc.rotation.x=Math.PI/2;
      const spokes=new T.Group();spokes.position.set(0,.63,-.19);machine.add(spokes);
      for(let i=0;i<8;i++){const a=i*Math.PI/4;rod(spokes,C.yellow,[0,0,0],[Math.cos(a)*.29,Math.sin(a)*.29,0],.015)}
      fans.push({index,spokes});
      rod(machine,C.steel,[0,.75,0],[0,1.28,-.23],.035);box(machine,C.ink,[0,1.3,-.23],[.30,.22,.06],.025);
      obstacle(machine,'rower fan');rod(bay,C.steel,[0,.63,1.4],[0,.96,.4],.012);
      for(const side of[-1,1])box(bay,C.ink,[side*.28,.26,.65],[.22,.06,.34],.02);
    }else if(kind==='carry'){
      for(const side of[-1,1]){const weight=kettlebell(bay,side*1.7,.22,2.1,1.35);obstacle(weight,'kettlebell rack')}
    }else if(kind==='lunge'){
      const rack=group(bay,'sandbag rack',-1.9,1.5);
      box(rack,C.steel,[0,.23,0],[.55,.40,1.4],.04);
      for(let i=0;i<3;i++){box(rack,0xbca769,[0,.53,-.45+i*.45],[.42,.25,.35],.10);box(rack,C.ink,[0,.66,-.45+i*.45],[.28,.025,.09])}
      obstacle(rack);
    }else if(kind==='wallball'){
      const target=group(bay,'wall ball target rig',0,2.55);
      for(const side of[-1,1])rod(target,C.steel,[side*.72,.02,0],[side*.72,3.65,0],.045);
      box(target,C.yellow,[0,3.30,0],[1.65,1.0,.10],.04);
      const face=group(target,'wall ball target');face.rotation.y=Math.PI;label(face,'08',[0,3.3,.063],.7,.65,'#151b20','#ffd52a');
      const ring=new T.Mesh(new T.TorusGeometry(.34,.035,8,32),new T.MeshStandardMaterial({color:C.ink}));ring.position.set(0,3.3,-.065);target.add(ring);obstacle(target);
      for(const side of[-1,1])ball(bay,C.yellow,[side*1.7,.25,2.0],[.23,.23,.23]);
    }
  }
  for(const item of fans){item.spokes.updateWorldMatrix(true,false);item.matrix=item.spokes.matrixWorld.clone();item.spokes.removeFromParent()}
  batchScene(root);
  for(const item of fans){item.spokes.matrix.copy(item.matrix);item.spokes.matrix.decompose(item.spokes.position,item.spokes.quaternion,item.spokes.scale);root.add(item.spokes);item.base=item.spokes.quaternion.clone()}
  root.userData={bounds:GYM_BOUNDS,connector:GYM_CONNECTOR,stations:GYM_STATIONS,obstacles};
  return{root,update(motions){for(const {index,spokes,base}of fans){spokes.quaternion.copy(base);spokes.rotateZ((motions[index].exerciseTime||0)*4)}}};
}

export function attachWorkoutProps(person){
  person.workoutProps=person.arms.map(arm=>kettlebell(arm.hand,0,-.16,0,.9));
  person.sandbag=group(person.hips,'carried sandbag');person.sandbag.position.y=.73;
  box(person.sandbag,0xbca769,[0,0,-.10],[.90,.24,.32],.10);box(person.sandbag,C.ink,[0,.13,-.10],[.12,.025,.30]);
  person.wallBall=ball(person.root,C.yellow,[0,1.7,.45],[.22,.22,.22]);
  for(const prop of [...person.workoutProps,person.sandbag,person.wallBall]){
    prop.visible=false;prop.traverse(o=>{if(o.isMesh)o.userData.agent=person.index});
  }
}
