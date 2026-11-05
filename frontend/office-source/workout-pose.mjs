import {smooth} from './motion.mjs';

export function poseWorkout(p,dt){
  const m=p.motion;
  p.workoutWeight=smooth(p.workoutWeight||0,m.state==='exercising'?1:0,8,dt);
  if(m.exercise)p.workoutKind=m.exercise;
  const w=p.workoutWeight,t=m.exerciseTime||0,kind=p.workoutKind;
  p.workoutProps?.forEach(prop=>prop.visible=kind==='carry'&&w>.08);
  if(p.sandbag)p.sandbag.visible=kind==='lunge'&&w>.08;
  if(p.wallBall)p.wallBall.visible=kind==='wallball'&&w>.08;
  p.hips.rotation.x=0;
  if(w<.0001)return;
  const mix=(a,b)=>a+(b-a)*w,cycle=.5-.5*Math.cos(t*3),stride=Math.sin(t*8);
  function arms(upper,elbow,spread=.1){p.arms.forEach((arm,i)=>{arm.upper.rotation.x=mix(arm.upper.rotation.x,upper);arm.elbow.rotation.x=mix(arm.elbow.rotation.x,elbow);arm.upper.rotation.z=mix(arm.upper.rotation.z,(i?1:-1)*spread)})}
  function legs(left,right,knee=.15){p.legs.forEach((leg,i)=>{leg.upper.rotation.x=mix(leg.upper.rotation.x,i?right:left);leg.knee.rotation.x=mix(leg.knee.rotation.x,knee)})}
  if(kind==='run'||kind==='carry'){
    p.hips.position.y=mix(p.hips.position.y,1.06+Math.abs(Math.cos(t*8))*.045);
    p.legs.forEach((leg,i)=>{const side=i?1:-1;leg.upper.rotation.x=mix(leg.upper.rotation.x,stride*.52*side);leg.knee.rotation.x=mix(leg.knee.rotation.x,.15+Math.max(0,-stride*side)*.8)});
    if(kind==='carry')arms(-.08,-.15,.14);
    else p.arms.forEach((arm,i)=>{arm.upper.rotation.x=mix(arm.upper.rotation.x,-stride*.5*(i?1:-1));arm.elbow.rotation.x=mix(arm.elbow.rotation.x,-1.15)});
  }else if(kind==='ski'){
    p.hips.rotation.x=.28*cycle*w;p.hips.position.y=mix(p.hips.position.y,1.06-.12*cycle);
    arms(-2.7+2.3*cycle,-.18-.6*cycle,.2);legs(-.2*cycle,-.2*cycle,.4*cycle);
  }else if(kind==='push'){
    p.hips.rotation.x=.24*w;arms(-1.1,-.15,.08);
    legs(stride*.27,-stride*.27,.28);p.hips.position.y=mix(p.hips.position.y,.99);
  }else if(kind==='pull'){
    p.hips.rotation.x=(-.09+cycle*.12)*w;arms(-1.0+cycle*.70,-.25-cycle*1.2,.10);
    legs(-.25,.2,.34);p.hips.position.y=mix(p.hips.position.y,1.01-.04*cycle);
  }else if(kind==='burpee'){
    const phase=(t%3)/3,fold=Math.max(0,Math.sin(phase*Math.PI*2)),jump=phase>.55?Math.sin((phase-.55)/.45*Math.PI):0;
    p.hips.position.y=mix(p.hips.position.y,1.06-.48*fold+.22*jump);p.hips.rotation.x=1.08*fold*w;
    legs(-1.15*fold,-1.15*fold,1.65*fold);arms(-1.4*fold-2.3*jump,-.25-fold*.25,.15);
  }else if(kind==='row'){
    p.hips.position.y=mix(p.hips.position.y,.67);p.hips.rotation.x=(.16-.29*cycle)*w;
    legs(-1.48+cycle*.1,-1.48+cycle*.1,1.5-cycle*.3);arms(-1.25+cycle*.75,-.15-cycle*1.25,.1);
  }else if(kind==='lunge'){
    const lead=Math.sin(t*2),dip=Math.abs(lead);
    p.hips.position.y=mix(p.hips.position.y,1.06-.26*dip);
    p.legs.forEach((leg,i)=>{const side=i?1:-1;leg.upper.rotation.x=mix(leg.upper.rotation.x,-.2-lead*side*.65);leg.knee.rotation.x=mix(leg.knee.rotation.x,.25+dip*.85)});
    arms(-.25,-2.35,.65);
  }else if(kind==='wallball'){
    const phase=(t%2.6)/2.6,squat=Math.max(0,Math.sin(phase*Math.PI*2)),flight=phase>.5?Math.sin((phase-.5)*Math.PI*2):0;
    p.hips.position.y=mix(p.hips.position.y,1.06-.28*squat);legs(-.65*squat,-.65*squat,1.3*squat);
    arms(phase<.5?-.6:-2.5,-.85,.08);
    if(p.wallBall)p.wallBall.position.set(0,1.5-.15*squat+flight*1.8,.5+flight*1.6);
  }
  // Account for the soles while bending and keep the lower foot above the mat.
  const hx=p.hips.rotation.x;
  const lowest=Math.min(...p.legs.map(leg=>{
    const thigh=hx+leg.upper.rotation.x,shin=thigh+leg.knee.rotation.x;
    return p.hips.position.y-.015*Math.cos(hx)-.43*Math.cos(thigh)-.542*Math.cos(shin)-.069*Math.sin(shin)-.044*Math.abs(Math.cos(shin))-.215*Math.abs(Math.sin(shin));
  }));
  p.hips.position.y+=Math.max(0,.075-lowest)*w;
}
