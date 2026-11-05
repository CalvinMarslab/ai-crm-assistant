export const GYM_BOUNDS={minX:-6,maxX:30,minZ:-33,maxZ:-9};
export const GYM_CONNECTOR={minX:10.95,maxX:13.05,minZ:-9.6,maxZ:5.8};
export const EXERCISES={ski:'SkiErg 滑雪机',push:'雪橇推行',pull:'雪橇绳拉',burpee:'波比跳',row:'划船机',carry:'农夫走',lunge:'沙袋弓步',wallball:'墙球训练',run:'外围跑道热身'};
export const HYROX_ZONES=[
  ['ski','SKIERG'],['push','SLED PUSH'],['pull','SLED PULL'],['burpee','BURPEE BROAD JUMP'],
  ['row','ROWING'],['carry','FARMERS CARRY'],['lunge','SANDBAG LUNGES'],['wallball','WALL BALLS'],
].map(([kind,label],index)=>({index,kind,label,x:(index%4)*8,z:index<4?-15:-25,angle:index<4?0:Math.PI}));
// Eight workout bays and four warm-up positions allow all 12 people to train.
export const GYM_STATIONS=[...HYROX_ZONES,...Array.from({length:4},(_,i)=>({
  index:i+8,kind:'run',label:'RUN LOOP',x:i%2?28.2:-4.2,z:i<2?-15:-25,angle:i%2?Math.PI:0,
}))].map(s=>Object.freeze(s));
export function inGym(position){return position.x>=GYM_BOUNDS.minX&&position.x<=GYM_BOUNDS.maxX&&position.z>=GYM_BOUNDS.minZ&&position.z<=GYM_BOUNDS.maxZ}
const point=(x,z)=>({x,z});
export function gymRoute(motion,index){
  const h=motion.home,station=GYM_STATIONS[index];
  const exit=index>=6
    ?[...motion.route.map(p=>({...p})),point(22.7,.15),point(22.7,5.5)]
    :h.z>2?[point(h.x,4.9),point(6.5,4.9),point(6.5,5.5)]
      :[point(h.x,.2),point(6.5,.2),point(6.5,5.5)];
  const lane=station.z>-20?-10.8:-20.2;
  const entryX=station.kind==='run'?station.x:station.x+2.9;
  return [h,...exit,point(12,5.5),point(12,-9.8),point(12,lane),point(entryX,lane),point(entryX,station.z),point(station.x,station.z)]
    .filter((p,i,a)=>!i||Math.hypot(p.x-a[i-1].x,p.z-a[i-1].z)>.01).map(p=>({...p}));
}

export function reverseRoute(position,nodes){
  let best=0,error=Infinity,homeLength=Infinity,total=0;
  for(let i=0;i<nodes.length-1;i++){
    const a=nodes[i],b=nodes[i+1],dx=b.x-a.x,dz=b.z-a.z,length=Math.hypot(dx,dz);
    if(!length)continue;
    const t=Math.max(0,Math.min(1,((position.x-a.x)*dx+(position.z-a.z)*dz)/(length*length)));
    const d=Math.hypot(position.x-a.x-dx*t,position.z-a.z-dz*t),travel=total+t*length;
    if(d<error-1e-5||(Math.abs(d-error)<1e-5&&travel<homeLength)){best=i;error=d;homeLength=travel}
    total+=length;
  }
  return nodes.slice(0,best+1).reverse().filter(p=>Math.hypot(p.x-position.x,p.z-position.z)>.015).map(p=>({...p}));
}
