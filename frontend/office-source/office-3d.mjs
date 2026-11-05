import * as T from 'three';
import {OrbitControls} from 'three/addons/controls/OrbitControls.js';
import {makeCharacter,poseCharacter} from './characters.mjs';
import {RoomEnvironment} from 'three/addons/environments/RoomEnvironment.js';
import {createCampus,createGagaOffice,createOfficeLink} from './campus.mjs';
import {CAMPUS_VIEWS,GAGA_ORIGIN,campusZoom} from './campus-views.mjs';
import {GAGA_AGENTS} from './gaga-agents.mjs';
import {GagaMotion} from './gaga-motion.mjs';
import {GymMotion} from './gym-motion.mjs';
import {createGym,attachWorkoutProps} from './gym.mjs';
import {EXERCISES} from './gym-layout.mjs';
const host=document.querySelector('#office-3d');
const status=document.querySelector('#motion-status');
const motionPanel=document.querySelector('#motion-controls');
const names=['Kai · 销售','Nova · 交付规划','Pixel · 设计','Atlas · 运营','Ledger · 财务','You · 负责人',...GAGA_AGENTS.map(a=>a.title)];
const mats=new Map();
const mat=(c,metal=0,emissive=false)=>{const key=c+':'+metal+':'+emissive;if(!mats.has(key))mats.set(key,new T.MeshStandardMaterial({color:c,roughness:metal?.36:.65,metalness:metal,emissive:emissive?c:0,emissiveIntensity:emissive?.7:0}));return mats.get(key)};
function mesh(parent,geometry,color,pos=[0,0,0],scale=[1,1,1],metal=0,glow=false){const m=new T.Mesh(geometry,mat(color,metal,glow));m.position.set(...pos);m.scale.set(...scale);m.castShadow=true;m.receiveShadow=true;parent.add(m);return m}
try{
 const scene=new T.Scene();scene.background=new T.Color(0xe4edf5);scene.fog=new T.Fog(0xe4edf5,85,150);
 const initialView=CAMPUS_VIEWS.overview;
 const camera=new T.PerspectiveCamera(40,1,.1,160);camera.position.set(...initialView.position);
 const renderer=new T.WebGLRenderer({antialias:true,alpha:false});renderer.setPixelRatio(Math.min(devicePixelRatio,1.75));renderer.shadowMap.enabled=true;renderer.shadowMap.type=T.PCFSoftShadowMap;renderer.outputColorSpace=T.SRGBColorSpace;renderer.toneMapping=T.ACESFilmicToneMapping;renderer.toneMappingExposure=1.35;host.appendChild(renderer.domElement);renderer.domElement.setAttribute('aria-label','3D 办公室：拖动旋转，右键拖动平移，指向位置滚轮缩放；点击角色展开操作台');
 const controls=new OrbitControls(camera,renderer.domElement);controls.target.set(...initialView.target);controls.enableDamping=true;controls.enablePan=true;controls.screenSpacePanning=false;controls.zoomToCursor=true;controls.zoomSpeed=1.2;controls.minDistance=initialView.minDistance;controls.maxDistance=initialView.maxDistance;controls.minPolarAngle=.2;controls.maxPolarAngle=Math.PI*.46;controls.maxAzimuthAngle=Math.PI*.62;controls.minAzimuthAngle=-Math.PI*.62;
 const environment=new RoomEnvironment(),pmrem=new T.PMREMGenerator(renderer);
 scene.environment=pmrem.fromScene(environment,.04).texture;scene.environmentIntensity=.55;environment.dispose();pmrem.dispose();renderer.toneMappingExposure=1.0;
 scene.add(new T.HemisphereLight(0xf0f8ff,0xc0bca5,1.45));const sun=new T.DirectionalLight(0xfff4dd,3.0);sun.position.set(-4,22,12);sun.target.position.set(8,0,0);sun.castShadow=true;sun.shadow.mapSize.set(2048,2048);sun.shadow.camera.left=-30;sun.shadow.camera.right=30;sun.shadow.camera.top=24;sun.shadow.camera.bottom=-24;sun.shadow.camera.far=85;sun.shadow.bias=-.0003;sun.shadow.normalBias=.02;scene.add(sun,sun.target);const rim=new T.DirectionalLight(0xe6f2ff,1.2);rim.position.set(-8,8,-6);scene.add(rim);
 const room=new T.Group(),techweiser=createCampus(),gaga=createGagaOffice(),gym=createGym();gaga.position.set(GAGA_ORIGIN.x,0,GAGA_ORIGIN.z);room.add(techweiser,gaga,createOfficeLink(),gym.root);scene.add(room);
 const stage=mesh(scene,new T.CylinderGeometry(1.35,1.38,.06,64),0xe1e6df,[0,0,0],[1,1,1],.1);stage.visible=false;
 const people=Array.from({length:6},(_,i)=>makeCharacter(i));
 GAGA_AGENTS.forEach((agent,index)=>{const p=makeCharacter(agent.appearance);p.index=index+6;p.motion=new GagaMotion(agent,index);p.root.name=agent.title;p.root.traverse(o=>{if(o.isMesh)o.userData.agent=p.index});poseCharacter(p,0,0);people.push(p)});
 people.forEach(p=>{p.motion=new GymMotion(p.motion,p.index);attachWorkoutProps(p);scene.add(p.root)});
 const ring=mesh(scene,new T.TorusGeometry(.54,.025,8,48),0x4285f4,[0,.04,0],[1,1,1],0,false);ring.rotation.x=Math.PI/2;
 ring.visible=false;renderer.domElement.tabIndex=0;
 let panelOpener=null;
 function showMotionPanel(){const opening=motionPanel.hidden;if(opening)panelOpener=document.activeElement===document.body?renderer.domElement:document.activeElement;motionPanel.hidden=false;ring.visible=true;updateStatus();if(opening){document.querySelector('#motion-name').focus({preventScroll:true});motionPanel.scrollIntoView({block:'nearest',behavior:matchMedia('(prefers-reduced-motion: reduce)').matches?'instant':'smooth'})}}
 function hideMotionPanel(){const restoreFocus=motionPanel.contains(document.activeElement);motionPanel.hidden=true;ring.visible=false;if(restoreFocus)(panelOpener?.isConnected?panelOpener:renderer.domElement).focus({preventScroll:true})}
 document.querySelector('#motion-close').onclick=hideMotionPanel;
 motionPanel.addEventListener('keydown',event=>{if(event.key==='Escape'){event.preventDefault();hideMotionPanel()}});
 const select=document.querySelector('#motion-agent');['Techweiser','Gaga'].forEach((office,groupIndex)=>{const group=document.createElement('optgroup');group.label=office;names.slice(groupIndex*6,groupIndex*6+6).forEach((name,i)=>{const o=document.createElement('option');o.value=groupIndex*6+i;o.textContent=name;group.appendChild(o)});select.appendChild(group)});
 let selected=0,campusView='overview',portrait=false,focusTransition=false,followGym=false,navigating=false,paused=matchMedia('(prefers-reduced-motion: reduce)').matches,auto=!paused;people.forEach(p=>{p.motion.auto=auto;p.motion.timer=3+p.index*6});
 const focusOffset=new T.Vector3(),focusPoint=new T.Vector3(),focusCamera=new T.Vector3(),followDelta=new T.Vector3(),up=new T.Vector3(0,1,0);
 function framePortrait(){focusOffset.set(2.3,.85,4.3).applyAxisAngle(up,people[selected].motion.angle);focusTransition=true;people.forEach(p=>p.root.visible=p.index===selected)}
 function personView(index){const m=people[index].motion;return m.trip?(m.position.z>8?'gym':'overview'):index>=6?'gaga':'techweiser'}
 function choose(index){if(!Number.isInteger(index)||!people[index])return;const changed=selected!==index;selected=index;select.value=index;document.querySelector('#motion-name').textContent=names[index];const talk=document.querySelector('#motion-talk');talk.disabled=index===5;talk.textContent=index===5?'由你担任负责人':index>=6?'打开原聊天 ↗':'与 TA 对话 ↗';if(portrait&&changed)framePortrait();if(!navigating&&!portrait&&campusView!=='overview'&&campusView!=='gym'&&campusView!==personView(index))setCampusView(personView(index));if(index>=6)document.dispatchEvent(new CustomEvent('office-gaga-selected',{detail:{index:index-6}}));updateNavigation()}
 function selectPerson(index){if(!Number.isInteger(index)||!people[index])return;choose(index);if(index<6)window.selectAgent?.(index)}
 function openPersonChat(index){if(index>=6)return window.openGagaAgentChat?.(index-6);return window.openOfficeChat?.(index)}
 select.onchange=()=>selectPerson(+select.value);document.addEventListener('office-agent-selected',e=>choose(e.detail.index));
 function resumeAnimation(){if(paused){paused=false;pauseButton.textContent='暂停动作';pauseButton.setAttribute('aria-pressed','false')}}
 document.querySelectorAll('[data-motion]').forEach(button=>button.onclick=()=>{const m=people[selected].motion,view=personView(selected);if(!portrait&&campusView!=='overview'&&campusView!==view)setCampusView(view);m.command(button.dataset.motion);if(m.trip?.stage==='returning'){followGym=false;setCampusView('overview')}resumeAnimation();updateStatus()});
 const pauseButton=document.querySelector('#motion-pause');pauseButton.textContent=paused?'继续动作':'暂停动作';pauseButton.onclick=()=>{paused=!paused;pauseButton.textContent=paused?'继续动作':'暂停动作';pauseButton.setAttribute('aria-pressed',String(paused))};
 const autoButton=document.querySelector('#motion-auto');autoButton.textContent=auto?'关闭自动走动':'自动走动';autoButton.setAttribute('aria-pressed',String(auto));autoButton.onclick=()=>{auto=!auto;people.forEach(p=>{p.motion.auto=auto;p.motion.timer=1+p.index*3});autoButton.textContent=auto?'关闭自动走动':'自动走动';autoButton.setAttribute('aria-pressed',String(auto));if(auto){paused=false;pauseButton.textContent='暂停动作';pauseButton.setAttribute('aria-pressed','false')}};
 document.querySelector('#motion-talk').onclick=()=>openPersonChat(selected);
 function startGym(indices){indices.forEach(i=>people[i].motion.command('gym'));followGym=true;setCampusView(people[selected].motion.position.z>8?'gym':'overview');resumeAnimation();updateStatus()}
 document.querySelector('#motion-gym').onclick=()=>startGym([selected]);
 document.querySelector('#gym-all').onclick=()=>startGym(people.map(p=>p.index));
 const portraitButton=document.querySelector('#motion-portrait');
 const navigation=document.querySelector('#campus-navigation'),viewCaption=document.querySelector('#campus-view-caption');
 function updateNavigation(){navigation.querySelectorAll('[data-campus-view]').forEach(button=>button.setAttribute('aria-pressed',String(!portrait&&button.dataset.campusView===campusView)));viewCaption.textContent=portrait?'人物特写 · 拖动环绕查看':campusView==='gaga'?'Gaga · 6 个 agent · 点击人物展开操作台':CAMPUS_VIEWS[campusView].label;document.dispatchEvent(new CustomEvent('office-campus-view',{detail:{view:portrait?'portrait':campusView,office:selected>=6?'gaga':'techweiser'}}))}
 function setPortrait(active){const view=CAMPUS_VIEWS[campusView];portrait=active;room.visible=!active;stage.visible=active;portraitButton.setAttribute('aria-pressed',String(active));portraitButton.textContent=active?'返回办公室':'人物特写';controls.enablePan=!active;controls.zoomToCursor=!active;controls.minDistance=active?2.4:view.minDistance;controls.maxDistance=active?8:view.maxDistance;controls.minPolarAngle=active?.25:.2;controls.maxAzimuthAngle=active?Infinity:Math.PI*.62;controls.minAzimuthAngle=active?-Infinity:-Math.PI*.62;
  if(active)framePortrait();else{focusTransition=false;people.forEach(p=>p.root.visible=true);camera.position.set(...view.position);controls.target.set(...view.target)}resize();controls.update();updateNavigation()}
 function setCampusView(key){if(!Object.hasOwn(CAMPUS_VIEWS,key))return false;campusView=key;setPortrait(false);navigating=true;try{if(key==='gaga'&&selected<6)choose(6);else if(key==='techweiser'&&selected>=6)selectPerson(0)}finally{navigating=false}return true}
 navigation.querySelectorAll('[data-campus-view]').forEach(button=>button.onclick=()=>{followGym=false;setCampusView(button.dataset.campusView)});
 portraitButton.onclick=()=>setPortrait(!portrait);
 controls.addEventListener('start',()=>{focusTransition=false;followGym=false});
 document.querySelector('#motion-view').onclick=()=>setCampusView('overview');
 function zoomCamera(factor){followGym=false;focusTransition=false;const offset=camera.position.clone().sub(controls.target),distance=T.MathUtils.clamp(offset.length()*factor,controls.minDistance,controls.maxDistance);offset.setLength(distance);camera.position.copy(controls.target).add(offset);controls.update()}
 document.querySelector('#camera-closer').onclick=()=>zoomCamera(.78);
 document.querySelector('#camera-farther').onclick=()=>zoomCamera(1/.78);
 document.querySelector('#camera-center').onclick=()=>{followGym=false;setCampusView(campusView)};
 function updateStatus(){const words={idle:'站立待机',seated:'坐着办公',walking:'行走中',standing:'起身中',sitting:'转身 / 坐下中',waving:'挥手中'},m=people[selected].motion,trip=m.trip;const activity=trip?.suspended?'健身行程已停留 · 可继续健身或回座位':trip?.stage==='exercising'?EXERCISES[m.exercise]+' · '+Math.ceil(trip.remaining)+' 秒后回座位':trip?.stage==='returning'?'健身结束 · 返回座位中':trip?'正在前往健身房':words[m.state];status.textContent=names[selected]+' · '+(paused?'动作已暂停':activity);const active=people.filter(p=>p.motion.state==='exercising').length,enRoute=people.filter(p=>p.motion.trip&&p.motion.trip.stage!=='exercising').length;document.querySelector('#gym-status').textContent=active+' 人运动中'+(enRoute?' · '+enRoute+' 人往返 / 停留中':' · 12 个专属运动位');document.querySelector('#motion-gym').textContent=trip?.suspended?'继续健身':trip?'健身行程中':'去健身';document.querySelector('#motion-gym').disabled=!!trip&&!trip.suspended;}
 let down=null;const ray=new T.Raycaster(),pointer=new T.Vector2();renderer.domElement.addEventListener('pointerdown',e=>{down=e.isPrimary&&e.button===0&&!e.ctrlKey&&!e.metaKey&&!e.shiftKey?{x:e.clientX,y:e.clientY}:null});renderer.domElement.addEventListener('pointercancel',()=>{down=null});renderer.domElement.addEventListener('pointerup',e=>{const origin=down;down=null;if(!origin||!e.isPrimary||e.button!==0||Math.hypot(e.clientX-origin.x,e.clientY-origin.y)>6)return;const r=renderer.domElement.getBoundingClientRect();pointer.set((e.clientX-r.left)/r.width*2-1,-(e.clientY-r.top)/r.height*2+1);ray.setFromCamera(pointer,camera);const hits=ray.intersectObjects(people.filter(p=>p.root.visible).map(p=>p.root),true);if(hits.length){const i=hits[0].object.userData.agent;selectPerson(i);showMotionPanel()}});
 function resize(){const w=Math.max(1,host.clientWidth),h=Math.max(1,host.clientHeight);renderer.setSize(w,h,false);camera.aspect=w/h;camera.zoom=portrait?Math.min(1,w/h/.7):campusZoom(w/h);camera.updateProjectionMatrix()}new ResizeObserver(resize).observe(host);resize();
 let previous=performance.now(),elapsed=0,lastStatus=0;
 function animate(now){requestAnimationFrame(animate);const dt=Math.min(.05,(now-previous)/1000);previous=now;if(document.hidden)return;if(!paused)elapsed+=dt;
  people.forEach(p=>{if(!paused)p.motion.update(dt);poseCharacter(p,elapsed,paused?0:dt)});gym.update(people.map(p=>p.motion));
  if(followGym&&people[selected].motion.state==='exercising'){followGym=false;setCampusView('gym')}
  if(portrait){const m=people[selected].motion;focusPoint.set(m.position.x,1.17-.22*m.pose,m.position.z);stage.position.set(m.position.x,-.03,m.position.z);
   if(focusTransition){const blend=paused?1:1-Math.exp(-6*dt);focusCamera.copy(focusPoint).add(focusOffset);camera.position.lerp(focusCamera,blend);controls.target.lerp(focusPoint,blend);if(camera.position.distanceTo(focusCamera)<.02)focusTransition=false}
   else{followDelta.copy(focusPoint).sub(controls.target);camera.position.add(followDelta);controls.target.copy(focusPoint)}
  }
  ring.position.x=people[selected].motion.position.x;ring.position.z=people[selected].motion.position.z;controls.update();renderer.render(scene,camera);if(now-lastStatus>250){updateStatus();lastStatus=now}
 }
 choose(0);setCampusView('overview');host.dataset.ready='true';document.querySelector('#office').hidden=true;motionPanel.hidden=true;navigation.hidden=false;requestAnimationFrame(animate);
 renderer.domElement.addEventListener('webglcontextlost',e=>{e.preventDefault();paused=true;status.textContent='3D 显示连接中断，请刷新页面恢复。'});
 window.office3D={command:(index,action)=>{if(!Number.isInteger(index)||!people[index])return false;if(action==='gym')startGym([index]);else people[index].motion.command(action);return true},getStates:()=>people.map(p=>({state:p.motion.state,pose:p.motion.pose,position:{...p.motion.position},gymStage:p.motion.trip?.stage??null,exercise:p.motion.exercise})),setView:setCampusView,getView:()=>portrait?'portrait':campusView,selectAgent:index=>{if(!Number.isInteger(index)||!people[index])return false;setCampusView(personView(index));selectPerson(index);showMotionPanel();return true}};
 document.dispatchEvent(new CustomEvent('office-3d-ready'));
}catch(error){host.hidden=true;document.querySelector('#office').hidden=false;document.querySelector('#motion-controls').hidden=true;document.querySelector('#campus-navigation').hidden=true;status.textContent='此设备未能启动 3D，请使用支持 WebGL 的浏览器。';const note=document.createElement('p');note.className='three-fallback';note.textContent=status.textContent;host.parentNode.insertBefore(note,host.nextSibling);console.warn('3D office unavailable:',error.message);if(typeof environment==='function'){environment();requestAnimationFrame(frame)}}
