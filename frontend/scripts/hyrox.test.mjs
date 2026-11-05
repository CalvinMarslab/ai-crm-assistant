import assert from 'node:assert/strict';
import * as T from 'three';
import {CharacterMotion} from '../office-source/motion.mjs';
import {GagaMotion} from '../office-source/gaga-motion.mjs';
import {GymMotion} from '../office-source/gym-motion.mjs';
import {GAGA_AGENTS} from '../office-source/gaga-agents.mjs';
import {GYM_STATIONS,GYM_BOUNDS,GYM_CONNECTOR,HYROX_ZONES,inGym} from '../office-source/gym-layout.mjs';
import {createCampus,createGagaOffice,createOfficeLink} from '../office-source/campus.mjs';
import {createGym,attachWorkoutProps} from '../office-source/gym.mjs';
import {CAMPUS_VIEWS,campusZoom} from '../office-source/campus-views.mjs';
import {makeCharacter,poseCharacter} from '../office-source/characters.mjs';

const tech=createCampus(),gaga=createGagaOffice(),bridge=createOfficeLink(),gym=createGym();
const obstacles=[...tech.userData.walkObstacles,...tech.userData.deskObstacles,...gaga.userData.movementObstacles.map(o=>({...o,minX:o.minX+20,maxX:o.maxX+20})),...gym.root.userData.obstacles];
const floors=[{minX:-10.8,maxX:10.8,minZ:-6.65,maxZ:6.55},{minX:13.2,maxX:26.8,minZ:-6.6,maxZ:6.6},bridge.userData.bounds,GYM_CONNECTOR,GYM_BOUNDS];
function controller(i){return new GymMotion(i<6?new CharacterMotion({x:(i%3-1)*4.4,z:i<3?-2:3.7},i):new GagaMotion(GAGA_AGENTS[i-6],i-6),i)}
function clear(m){
  const {x,z}=m.position,r=.45;
  for(const o of obstacles){const d=Math.hypot(x-Math.max(o.minX,Math.min(x,o.maxX)),z-Math.max(o.minZ,Math.min(z,o.maxZ)));assert.ok(d>r,`Agent ${m.gymIndex} (${m.trip?.stage}) hits ${o.name} at ${x.toFixed(2)}, ${z.toFixed(2)} / gap ${d.toFixed(2)}`)}
  for(const dx of[-r,r])for(const dz of[-r,r])assert.ok(floors.some(f=>x+dx>=f.minX&&x+dx<=f.maxX&&z+dz>=f.minZ&&z+dz<=f.maxZ),`Agent ${m.gymIndex} leaves connected floors at ${x}, ${z}`);
}
function tick(m,seconds){for(let f=0;f<seconds*60;f++){m.update(1/60);clear(m)}}
assert.equal(new Set(GYM_STATIONS.map(s=>s.x+':'+s.z)).size,12);
assert.equal(HYROX_ZONES.length,8);
assert.ok(GYM_BOUNDS.maxZ < -8, 'The arena must be behind both offices');
assert.equal(inGym({x:12,z:-21}),true);
assert.equal(inGym({x:12,z:14}),false, 'The old front gym location is now clear');
assert.deepEqual([...new Set(GYM_STATIONS.map(s=>s.kind))].sort(),['burpee','carry','lunge','pull','push','row','run','ski','wallball']);
for(let i=0;i<12;i++){
  const m=controller(i);m.command('gym');let seen=false;
  for(let f=0;f<260*60;f++){
    m.update(1/60);clear(m);
    if(m.state==='exercising'){seen=true;assert.equal(m.exercise,GYM_STATIONS[i].kind);assert.ok(Math.hypot(m.position.x-GYM_STATIONS[i].x,m.position.z-GYM_STATIONS[i].z)<.001)}
  }
  assert.ok(seen,`Agent ${i} must reach and use equipment`);assert.equal(m.state,'seated');assert.equal(m.trip,null);assert.deepEqual(m.position,m.home);
  assert.ok(Math.abs(Math.atan2(Math.sin(m.seatAngle-m.angle),Math.cos(m.seatAngle-m.angle)))<.04);
  for(const interruptedAt of[1,13,32,52]){
    const q=controller(i);q.command('walk');tick(q,2);q.command('gym');tick(q,interruptedAt);q.command('wave');tick(q,3);q.command('sit');tick(q,240);
    assert.equal(q.state,'seated');assert.equal(q.trip,null);assert.deepEqual(q.position,q.home);
  }
  const q=controller(i);q.command('gym');tick(q,6);const remaining=q.path.map(p=>({...p}));q.command('stand');tick(q,3);q.command('gym');assert.deepEqual(q.path,remaining);tick(q,260);assert.equal(q.trip,null);assert.equal(q.state,'seated');
}
// Verify actual articulated exercise poses, props, elevation and global pause.
for(let i=0;i<9;i++){
  const p=makeCharacter(i%6);p.index=i;p.motion=controller(i);attachWorkoutProps(p);p.motion.command('gym');let time=0;
  while(p.motion.state!=='exercising'&&time<150){time+=1/60;p.motion.update(1/60);poseCharacter(p,time,1/60)}
  const samples=[];
  for(let f=0;f<240;f++){time+=1/60;p.motion.update(1/60);poseCharacter(p,time,1/60);if(f%30===0)samples.push([p.arms[0].elbow.rotation.x,p.legs[0].upper.rotation.x,p.hips.rotation.z]);p.root.updateMatrixWorld(true);p.root.traverse(o=>assert.ok(o.matrixWorld.elements.every(Number.isFinite)))}
  assert.ok(new Set(samples.map(s=>s.join(','))).size>3,'Exercise must visibly animate joints');
  assert.equal(p.workoutProps.every(prop=>prop.visible),i===5,'Kettlebells appear only during farmer carry');
  assert.equal(p.sandbag.visible,i===6,'Sandbag appears only during lunges');
  assert.equal(p.wallBall.visible,i===7,'Medicine ball appears only during wall balls');
  assert.ok(Math.abs(p.root.position.y-0)<.001,'Athletes use the flat HYROX arena floor');
  const snapshot=()=>{const a=[];p.root.traverse(o=>a.push(...o.position.toArray(),...o.rotation.toArray().slice(0,3)));return a};
  const before=snapshot();poseCharacter(p,time,0);assert.deepEqual(snapshot(),before,'Paused exercise joints do not drift');
  p.motion.command('stand');tick(p.motion,1);const stopped=p.motion.trip.remaining;tick(p.motion,2);assert.equal(p.motion.trip.remaining,stopped);p.motion.command('gym');assert.equal(p.motion.state,'exercising');
}
const campus=new T.Group();gaga.position.x=20;campus.add(tech,gaga,bridge,gym.root);campus.updateMatrixWorld(true);
for(const [name,root]of[['overview',campus],['gym',gym.root]]){
  const b=name==='gym'?new T.Box3(new T.Vector3(GYM_BOUNDS.minX,-.4,GYM_BOUNDS.minZ),new T.Vector3(GYM_BOUNDS.maxX,4,GYM_BOUNDS.maxZ)):new T.Box3().setFromObject(root),view=CAMPUS_VIEWS[name];
  for(const aspect of[.7,.9,1.35,2,2.4]){
    const camera=new T.PerspectiveCamera(40,aspect,.1,160);camera.position.set(...view.position);camera.lookAt(...view.target);camera.zoom=campusZoom(aspect);camera.updateProjectionMatrix();camera.updateMatrixWorld(true);
    for(const x of[b.min.x,b.max.x])for(const y of[b.min.y,b.max.y])for(const z of[b.min.z,b.max.z]){const p=new T.Vector3(x,y,z).project(camera);assert.ok(Math.abs(p.x)<.97&&Math.abs(p.y)<.97&&Math.abs(p.z)<1,`${name} must fit at aspect ${aspect}: ${p.x}, ${p.y}`)}
  }
}
let meshes=0,triangles=0;gym.root.traverse(o=>{if(!o.isMesh)return;meshes++;triangles+=(o.geometry.index?.count??o.geometry.attributes.position.count)/3;for(const a of Object.values(o.geometry.attributes))assert.ok(a.array.every(Number.isFinite))});
assert.ok(meshes<110&&triangles<150000,'HYROX arena stays within the scenery budget');
console.log(`PASS: all 12 teammates reach unique exercise stations, animate, pause/resume and return safely; no furniture/floor collisions; responsive gym and campus views; ${meshes} gym meshes / ${triangles} triangles.`);
