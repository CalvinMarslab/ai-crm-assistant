export const GYM_BOUNDS={minX:0,maxX:24,minZ:7.8,maxZ:20.4};
export const GYM_CONNECTOR={minX:10.95,maxX:13.05,minZ:6.05,maxZ:8.3};
export const EXERCISES={run:'跑步机慢跑',weights:'哑铃弯举',stretch:'舒展拉伸'};
// One station per teammate, so both offices can take a break together.
export const GYM_STATIONS=Array.from({length:12},(_,index)=>{
  const kind=['run','weights','stretch'][index%3];
  return Object.freeze({index,kind,x:3+Math.floor(index/3)*6,z:{run:10.8,weights:14.6,stretch:18.2}[kind],angle:kind==='run'?Math.PI:0});
});
const point=(x,z)=>({x,z});
export function gymRoute(motion,index){
  const h=motion.home,station=GYM_STATIONS[index];
  const exit=index>=6
    ?[...motion.route.map(p=>({...p})),point(22.7,.15),point(22.7,5.5)]
    :h.z>2?[point(h.x,4.9),point(6.5,4.9),point(6.5,5.5)]
      :[point(h.x,.2),point(6.5,.2),point(6.5,5.5)];
  const lane=station.kind==='stretch'?16.4:12.6;
  return [h,...exit,point(12,5.5),point(12,8.8),point(12,lane),point(station.x,lane),point(station.x,station.z)]
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
