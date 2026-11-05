const distance=(a,b)=>Math.hypot(a.x-b.x,a.z-b.z);
export const smooth=(a,b,k,dt)=>a+(b-a)*(1-Math.exp(-k*dt));
export class CharacterMotion{
 constructor(home,index=0){this.home={...home};this.position={...home};this.index=index;this.seatAngle=Math.PI;this.pose=index%3===0?0:1;this.angle=this.pose?this.seatAngle:0;this.state=this.pose?'seated':'idle';this.path=[];this.phase=0;this.auto=false;this.timer=4+index*2;this.afterWalk='sit';this.wave=0;this.speed=1.25;}
 routeHome(){const p=this.position,h=this.home;const path=[];if(p.z>2){path.push({x:p.x,z:4.9},{x:6.5,z:4.9},{x:6.5,z:.2})}else path.push({x:p.x,z:.2});if(h.z>2)path.push({x:6.5,z:.2},{x:6.5,z:4.9},{x:h.x,z:4.9});else path.push({x:h.x,z:.2});path.push({...h});return path}
 excursion(){const h=this.home;const out=h.z>2?[{x:h.x,z:4.9},{x:6.5,z:4.9},{x:6.5,z:.2},{x:0,z:.2}]:[{x:h.x,z:.2},{x:h.x*.45,z:.2}];return [...out,...out.slice(0,-1).reverse(),{...h}]}
 command(action,{automatic=false}={}){if(!automatic)this.auto=false;this.timer=5+this.index;
  if(action==='stand'){this.path=[];this.state='standing';this.afterWalk='idle';return}
  if(action==='wave'){this.path=[];this.state='waving';this.wave=0;return}
  if(action==='sit'){this.afterWalk='sit';if(distance(this.position,this.home)<.02){this.state='sitting'}else{this.path=this.routeHome();this.state='standing'}return}
  if(action==='walk'){this.afterWalk='sit';this.path=distance(this.position,this.home)<.02?this.excursion():this.routeHome();this.state='standing'}
 }
 update(dt){dt=Math.min(.05,Math.max(0,dt));this.phase+=dt;
  if(this.state==='standing'){this.pose=smooth(this.pose,0,8,dt);if(this.pose<.01){this.pose=0;this.state=this.path.length?'walking':'idle'}}
  else if(this.state==='walking'){
   const next=this.path[0];if(!next){this.state=this.afterWalk==='sit'?'sitting':'idle';return}
   const d=distance(this.position,next);const wanted=Math.atan2(next.x-this.position.x,next.z-this.position.z);const delta=Math.atan2(Math.sin(wanted-this.angle),Math.cos(wanted-this.angle));this.angle+=delta*Math.min(1,dt*10);
   const step=Math.min(d,this.speed*dt);if(d>0){this.position.x+=(next.x-this.position.x)/d*step;this.position.z+=(next.z-this.position.z)/d*step}
   if(d<.04){this.position={...next};this.path.shift()}
  }else if(this.state==='sitting'){
   const delta=Math.atan2(Math.sin(this.seatAngle-this.angle),Math.cos(this.seatAngle-this.angle));this.angle+=delta*Math.min(1,dt*10);if(Math.abs(delta)<.04){this.pose=smooth(this.pose,1,6,dt);if(this.pose>.995){this.pose=1;this.state='seated'}}
  }else if(this.state==='waving'){this.pose=smooth(this.pose,0,8,dt);this.wave+=dt;if(this.wave>2.5){this.state='idle';this.wave=0}}
  if(this.auto&&['idle','seated'].includes(this.state)){this.timer-=dt;if(this.timer<=0)this.command('walk',{automatic:true})}
 }
}
