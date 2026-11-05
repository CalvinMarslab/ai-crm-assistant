import {CharacterMotion} from './motion.mjs';
import {GAGA_ORIGIN} from './campus-views.mjs';

const point=([x,z])=>({x:x+GAGA_ORIGIN.x,z:z+GAGA_ORIGIN.z});
const distance=(a,b)=>Math.hypot(a.x-b.x,a.z-b.z);

export class GagaMotion extends CharacterMotion{
  constructor(agent,index){
    super(point(agent.seat),index);
    this.route=agent.route.map(point);this.seatAngle=agent.seatAngle;
    this.pose=1;this.angle=this.seatAngle;this.state='seated';this.speed=1.1;
  }
  excursion(){return[...this.route,...this.route.slice(0,-1).reverse(),this.home].map(p=>({...p}))}
  routeHome(){
    // A manual sit/walk can interrupt an excursion anywhere along a segment.
    // Return through that segment's preceding waypoints instead of crossing desks.
    const nodes=[this.home,...this.route];let best=0,error=Infinity,bestLength=Infinity,total=0;
    for(let i=0;i<nodes.length-1;i++){
      const a=nodes[i],b=nodes[i+1],dx=b.x-a.x,dz=b.z-a.z,length=Math.hypot(dx,dz);
      const t=Math.max(0,Math.min(1,((this.position.x-a.x)*dx+(this.position.z-a.z)*dz)/(length*length)));
      const projected={x:a.x+dx*t,z:a.z+dz*t},d=distance(this.position,projected),homeLength=total+t*length;
      if(d<error-1e-5||(Math.abs(d-error)<1e-5&&homeLength<bestLength)){best=i;error=d;bestLength=homeLength}
      total+=length;
    }
    return nodes.slice(0,best+1).reverse().filter(p=>distance(p,this.position)>.02).map(p=>({...p}));
  }
}
