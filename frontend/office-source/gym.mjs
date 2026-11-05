import * as T from 'three';
import {box,cylinder,group,rod,label,plant,batchScene} from './campus.mjs';
import {GYM_STATIONS,GYM_BOUNDS,GYM_CONNECTOR} from './gym-layout.mjs';

const C={ink:0x204b50,teal:0x13a69b,lime:0xc9ed58,orange:0xff8756,white:0xfffcf3,metal:0x899e9e};
function dumbbell(parent,x,y,z,size=1){
  const g=group(parent,'dumbbell',x,z);g.position.y=y;g.scale.setScalar(size);
  rod(g,C.metal,[-.22,0,0],[.22,0,0],.033);
  for(const side of[-1,1]){const plate=cylinder(g,C.ink,[side*.19,0,0],.115,.115,.09);plate.rotation.z=Math.PI/2;const cap=cylinder(g,C.lime,[side*.245,0,0],.065,.065,.015);cap.rotation.z=Math.PI/2}
  return g;
}

export function createGym(){
  const root=new T.Group();root.name='Shared wellness club';
  const obstacles=[],belts=[];
  function obstacle(g,name=g.name){root.updateMatrixWorld(true);const b=new T.Box3().setFromObject(g);obstacles.push({name,minX:b.min.x,maxX:b.max.x,minZ:b.min.z,maxZ:b.max.z});return g}
  box(root,0xc4d7d1,[12,-.19,14.1],[24,.36,12.6],.12);
  box(root,0xf4eedb,[12,-.015,14.1],[23.8,.04,12.4]);
  for(let x=.4;x<24;x+=.6)box(root,0xe1d5b6,[x,.009,14.1],[.009,.002,12.2]);
  box(root,0xe1ecd5,[12,.017,14.1],[2.0,.026,12.1]);
  for(const z of[12.6,16.4])box(root,0xe1ecd5,[12,.017,z],[23.2,.026,1.05]);
  // Open on every side: branding sits flat on the floor, preserving office sightlines.
  const floorBrand=group(root,'wellness floor lettering',12,19.92);
  floorBrand.position.y=.025;floorBrand.rotation.x=-Math.PI/2;
  label(floorBrand,'THE WELLNESS CLUB',[0,0,0],8.3,.42,'#087f79','#f4eedb');
  const bridge=group(root,'gym access walkway');
  box(bridge,0xc4d7d1,[12,-.19,7.15],[2.1,.36,2.2],.06);
  box(bridge,C.lime,[12,.014,7.15],[2.05,.028,2.2]);
  for(const x of[10.98,13.02]){
    rod(bridge,C.metal,[x,.65,6.35],[x,.65,7.75],.022);
    for(const z of[6.45,7.65])rod(bridge,C.metal,[x,.025,z],[x,.65,z],.018);
  }
  for(const station of GYM_STATIONS){
    const {x,z,kind,index}=station;
    const g=group(root,kind+' station '+(index+1),x,z);
    if(kind==='run'){
      box(g,0x486367,[0,.08,0],[1.65,.16,2.05],.08);
      box(g,0x263b40,[0,.167,.05],[1.25,.018,1.74],.015);
      for(const side of[-1,1]){
        obstacle(box(g,C.lime,[side*.75,.18,0],[.13,.12,2.05],.04),'treadmill side');
        obstacle(rod(g,C.metal,[side*.72,.24,-.72],[side*.72,1.28,-.78],.045),'treadmill upright');
        rod(g,C.metal,[side*.72,1.27,-.78],[side*.72,1.17,-.15],.038);
      }
      obstacle(box(g,C.ink,[0,1.25,-.84],[1.45,.21,.30],.045),'treadmill console');
      const screen=box(g,0xa5f6d6,[0,1.365,-.85],[.46,.008,.17],.005);screen.rotation.x=-.12;
      const stripes=new T.Group();stripes.name='moving belt';g.add(stripes);
      for(let i=0;i<6;i++)box(stripes,0x587073,[0,.18,-.65+i*.27],[1.1,.006,.025]);
      belts.push({station:index,stripes});
    }else{
      box(g,kind==='weights'?0xffbe91:0x65c8bc,[0,.037,0],[2.2,.055,2.7],.04);
      box(g,kind==='weights'?C.orange:C.teal,[0,.067,1.2],[2.05,.006,.04]);
      if(kind==='weights'){
        const holder=group(g,'weight stand',-1.7,.15);
        box(holder,C.ink,[0,.31,0],[.55,.10,.63],.035);
        for(const side of[-1,1])rod(holder,C.metal,[side*.20,.04,0],[side*.20,.30,0],.035);
        dumbbell(holder,0,.45,-.14,.8);dumbbell(holder,0,.45,.16,.8);obstacle(holder);
      }else{
        box(g,C.white,[-.66,.13,.95],[.38,.14,.40],.04);
        const bottle=cylinder(g,C.orange,[.76,.25,.95],.085,.085,.38);obstacle(bottle,'water bottle');
      }
    }
  }
  obstacle(plant(root,.95,8.95,.85,C.orange));obstacle(plant(root,23.05,8.95,.85,C.lime));
  // A compact refreshment shelf sits outside the exercise and circulation lanes.
  const refill=group(root,'water and towel station',23.0,15.0);
  box(refill,C.white,[0,.65,0],[.7,1.30,1.5],.04);
  for(let i=0;i<4;i++)box(refill,[C.teal,C.orange,C.lime,C.white][i],[0,1.34+i*.055,.30],[.46,.05,.50],.02);
  cylinder(refill,0x9bd8eb,[0,1.67,-.36],.19,.19,.58);cylinder(refill,C.ink,[0,1.36,-.36],.22,.22,.06);obstacle(refill);
  // Belt marks stay independent so running animates the machine as well as the person.
  belts.forEach(({stripes})=>stripes.removeFromParent());
  batchScene(root);
  belts.forEach(({station,stripes})=>{const s=GYM_STATIONS[station];stripes.position.set(s.x,0,s.z);root.add(stripes)});
  root.userData={bounds:GYM_BOUNDS,connector:GYM_CONNECTOR,stations:GYM_STATIONS,obstacles};
  return{root,update(motions){for(const {station,stripes}of belts){const m=motions[station];stripes.children.forEach((stripe,i)=>{stripe.position.z=-.7+((i*.27+(m.exerciseTime||0)*1.4)%1.62)})}}};
}

export function attachWorkoutProps(person){
  person.workoutProps=person.arms.map(arm=>{
    const weight=dumbbell(arm.hand,0,-.06,.025,.95);weight.visible=false;
    weight.traverse(o=>{if(o.isMesh)o.userData.agent=person.index});return weight;
  });
}
