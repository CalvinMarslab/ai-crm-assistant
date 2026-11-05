import {CharacterMotion,smooth} from './motion.mjs';
import {GYM_STATIONS,gymRoute,reverseRoute} from './gym-layout.mjs';
const distance=(a,b)=>Math.hypot(a.x-b.x,a.z-b.z);

// Keep each office's existing routing, while sharing a reversible gym journey.
export class GymMotion extends CharacterMotion{
  constructor(base,index){
    super(base.home,index);Object.assign(this,base);
    this.officeRouteHome=base.routeHome;this.officeExcursion=base.excursion;
    this.gymIndex=index;this.trip=null;this.exercise=null;this.exerciseTime=0;this.elevation=0;
  }
  excursion(){return this.officeExcursion.call(this)}
  routeHome(){return this.trip&&this.trip.stage!=='gathering'?reverseRoute(this.position,this.trip.route):this.officeRouteHome.call(this)}
  startOutbound(){this.trip.stage='outbound';this.path=this.trip.route.slice(1).map(p=>({...p}));this.afterWalk='idle';this.state='standing'}
  returnToOffice(){
    const path=this.routeHome();this.trip.stage='returning';this.trip.suspended=null;
    this.path=path;this.afterWalk='sit';this.state=path.length?'standing':'sitting';
  }
  command(action,options={}){
    if(action==='gym'){
      this.auto=false;
      if(this.trip){
        const resume=this.trip.suspended;if(!resume)return;
        this.trip.stage=resume.stage;this.path=resume.path;this.afterWalk=resume.afterWalk;this.trip.suspended=null;
        this.state=resume.stage==='exercising'?'exercising':'standing';return;
      }
      this.trip={stage:'gathering',route:gymRoute(this,this.gymIndex),station:GYM_STATIONS[this.gymIndex],remaining:24,suspended:null};
      this.exerciseTime=0;
      if(distance(this.position,this.home)<.02)this.startOutbound();
      else{this.path=this.officeRouteHome.call(this);this.state='standing';this.afterWalk='idle'}
      return;
    }
    if(this.trip){
      this.auto=false;
      if(action==='sit'||action==='walk'){this.returnToOffice();return}
      if(action==='stand'||action==='wave'){
        if(!this.trip.suspended)this.trip.suspended={stage:this.trip.stage,path:this.path.map(p=>({...p})),afterWalk:this.afterWalk};
        // Retain the stage for safe routing; suspend its arrival/timeout transitions.
        super.command(action,options);return;
      }
    }
    super.command(action,options);
  }
  update(dt){
    dt=Math.min(.05,Math.max(0,dt));if(this.trip)this.auto=false;
    super.update(dt);
    const trip=this.trip;
    if(!trip){this.elevation=smooth(this.elevation,0,12,dt);return}
    const station=trip.station;
    this.elevation=0;
    if(trip.suspended)return;
    if(trip.stage==='gathering'&&this.state==='idle'&&!this.path.length)this.startOutbound();
    else if(trip.stage==='outbound'&&this.state==='idle'&&!this.path.length){
      trip.stage='exercising';this.state='exercising';this.exercise=station.kind;this.pose=0;
    }else if(trip.stage==='exercising'){
      const delta=Math.atan2(Math.sin(station.angle-this.angle),Math.cos(station.angle-this.angle));
      this.angle+=delta*Math.min(1,dt*8);this.exerciseTime+=dt;trip.remaining-=dt;
      if(trip.remaining<=0)this.returnToOffice();
    }else if(trip.stage==='returning'&&this.state==='seated'){
      this.trip=null;this.exercise=null;this.elevation=0;
    }
  }
}
