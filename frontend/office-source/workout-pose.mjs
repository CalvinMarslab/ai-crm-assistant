import {smooth} from './motion.mjs';

export function poseWorkout(p,dt){
  const m=p.motion;
  p.workoutWeight=smooth(p.workoutWeight||0,m.state==='exercising'?1:0,8,dt);
  if(m.exercise)p.workoutKind=m.exercise;
  const w=p.workoutWeight,t=m.exerciseTime||0,kind=p.workoutKind;
  p.workoutProps?.forEach(prop=>prop.visible=kind==='weights'&&w>.08);
  if(w<.0001)return;
  const mix=(a,b)=>a+(b-a)*w;
  if(kind==='run'){
    const stride=Math.sin(t*11);
    p.hips.position.y=mix(p.hips.position.y,1.06+Math.abs(Math.cos(t*11))*.055);
    p.hips.rotation.z=mix(p.hips.rotation.z,stride*.035);
    p.legs.forEach((leg,i)=>{const side=i?1:-1;leg.upper.rotation.x=mix(leg.upper.rotation.x,stride*.65*side);leg.knee.rotation.x=mix(leg.knee.rotation.x,.15+Math.max(0,-stride*side)*.95)});
    p.arms.forEach((arm,i)=>{const side=i?1:-1;arm.upper.rotation.x=mix(arm.upper.rotation.x,-stride*.45*side);arm.elbow.rotation.x=mix(arm.elbow.rotation.x,-1.20)});
  }else if(kind==='weights'){
    const curl=.5-.5*Math.cos(t*3.0);
    p.arms.forEach((arm,i)=>{arm.upper.rotation.x=mix(arm.upper.rotation.x,-.12);arm.upper.rotation.z=mix(arm.upper.rotation.z,(i?1:-1)*.17);arm.elbow.rotation.x=mix(arm.elbow.rotation.x,-.20-curl*1.45)});
    p.head.rotation.x=mix(p.head.rotation.x,-curl*.04);
  }else if(kind==='stretch'){
    const bend=Math.sin(t*1.35);
    p.hips.rotation.z=mix(p.hips.rotation.z,bend*.15);
    p.head.rotation.z=mix(p.head.rotation.z,-bend*.06);
    p.arms.forEach((arm,i)=>{arm.upper.rotation.x=mix(arm.upper.rotation.x,-.12);arm.upper.rotation.z=mix(arm.upper.rotation.z,(i?1:-1)*2.70-bend*.12);arm.elbow.rotation.x=mix(arm.elbow.rotation.x,-.25)});
  }
}
