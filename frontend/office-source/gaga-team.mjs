import {GAGA_AGENTS,getGagaAgent} from './gaga-agents.mjs';

const panel=document.createElement('section');panel.id='gaga-team';panel.className='gaga-team';panel.setAttribute('aria-labelledby','gaga-team-title');
const heading=document.createElement('div');heading.className='gaga-team-heading';
const title=document.createElement('h3');title.id='gaga-team-title';title.textContent='Gaga · 6 个已链接的 agent';
const note=document.createElement('p');note.textContent='点击人物展开操作台，使用「打开原聊天」继续工作。这里链接到现有 Codex 聊天，不会自动发送消息。';
heading.append(title,note);panel.appendChild(heading);
const grid=document.createElement('div');grid.className='gaga-team-grid';panel.appendChild(grid);
const cards=GAGA_AGENTS.map((agent,index)=>{
  const card=document.createElement('article');card.className='gaga-agent-card';card.dataset.agent=agent.id;
  const avatar=document.createElement('span');avatar.className='character-avatar';avatar.setAttribute('aria-hidden','true');avatar.style.backgroundPosition=`${(agent.appearance%3)*50}% ${Math.floor(agent.appearance/3)*100}%`;
  const body=document.createElement('div');body.className='gaga-agent-card-body';
  const name=document.createElement('h4');name.textContent=agent.title;
  const role=document.createElement('p');role.textContent=agent.role;
  const actions=document.createElement('div');actions.className='gaga-agent-actions';
  const locate=document.createElement('button');locate.type='button';locate.textContent='定位人物';locate.disabled=true;locate.setAttribute('aria-label','定位 '+agent.title);locate.setAttribute('aria-pressed','false');
  locate.onclick=()=>window.office3D?.selectAgent(index+6);
  const chat=document.createElement('a');chat.href=agent.url;chat.textContent='打开原聊天 ↗';chat.setAttribute('aria-label','打开 '+agent.title+' 原聊天');
  chat.onclick=()=>{window.office3D?.selectAgent(index+6);markSelected(index)};
  actions.append(locate,chat);body.append(name,role,actions);card.append(avatar,body);grid.appendChild(card);
  return{card,locate};
});
document.querySelector('.office-wrap').insertBefore(panel,document.querySelector('.campus-zones'));

function markSelected(index){cards.forEach((item,i)=>{item.card.classList.toggle('is-selected',i===index);item.locate.setAttribute('aria-pressed',String(i===index))})}
window.openGagaAgentChat=index=>{
  const agent=getGagaAgent(index);if(!agent)return false;
  markSelected(index);window.location.href=agent.url;return true;
};
document.addEventListener('office-gaga-selected',e=>markSelected(e.detail.index));
document.addEventListener('office-agent-selected',()=>markSelected(-1));
document.addEventListener('office-3d-ready',()=>cards.forEach(item=>item.locate.disabled=false));
document.addEventListener('office-campus-view',e=>{
  const {view,office}=e.detail;panel.hidden=view==='gym'||view==='techweiser'||(view==='portrait'&&office==='techweiser');
  const showTechweiser=view!=='gym'&&view!=='gaga'&&!(view==='portrait'&&office==='gaga');
  document.querySelector('.agent-dock').hidden=!showTechweiser;
  document.querySelector('.agent-inspector').hidden=!showTechweiser||office==='gaga';
});
