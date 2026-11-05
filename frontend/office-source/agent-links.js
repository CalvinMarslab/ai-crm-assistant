'use strict';
// Existing conversations only. Opening a link never sends a message.
const AGENT_CHATS = [
  [{label:['AI Sales Manager','AI Sales Manager'],url:'https://chatgpt.com/c/6aa9fa5d-8480-83ec-9311-e4918ada9c38'}],
  [{label:['Delivery Manager','交付管理'],url:'codex://threads/01a171c8-b50c-7942-a3d1-b12a9ed94c9f'}],
  [],
  [{label:['CEO / COO','CEO / COO 运营负责人'],url:'codex://threads/01a171d5-a0aa-7b10-be55-bf817f540410'}],
  [{label:['Financer & Estimator','财务与估算'],url:'codex://threads/01a17196-632a-7460-9ec4-167c9dfa87e1'},
   {label:['AI Account','AI 会计'],url:'codex://threads/01a17196-7112-7e23-b365-ad5ffd78ad80'}],
  []
];
function openAgentChat(index){
  if(typeof window.openOfficeChat==='function'){window.openOfficeChat(index);return;}
  const chat=AGENT_CHATS[index]?.[0];
  if(chat) window.location.href=chat.url;
}
function renderAgentChats(index){
  let box=document.querySelector('#agent-chat-links');
  if(!box){box=document.createElement('div');box.id='agent-chat-links';box.className='agent-chat-links';document.querySelector('.inspector-main').appendChild(box);}
  box.replaceChildren();
  if(index!==5){const button=document.createElement('button');button.type='button';button.className='chat-open-inline';button.textContent=tr(['Talk here / Assign work','在这里对话 / 安排工作']);button.onclick=()=>openAgentChat(index);box.appendChild(button);}
  const chats=AGENT_CHATS[index]||[];
  if(!chats.length){if(index===5)box.textContent=tr(['You approve decisions here.','由你在此审核决定。']);return;}
  chats.forEach(chat=>{const link=document.createElement('a');link.href=chat.url;link.textContent=tr(['Original chat: ','原聊天：'])+tr(chat.label)+' ↗';box.appendChild(link);});
  const note=document.createElement('small');note.textContent=tr(['Opens an existing chat. No message is sent.','打开已有聊天，不会自动发送消息。']);box.appendChild(note);
}
