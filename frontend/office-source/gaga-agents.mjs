// Verified against the user's existing Gaga Website chats on 2026-10-27.
// These URLs open the original conversations; they do not create or send tasks.
export const GAGA_AGENTS=[
  {id:'ceo',title:'Gaga CEO',role:'经营决策',threadId:'01a1764e-8234-7b51-b666-10459fe2c8bc',appearance:5,seat:[4.62,-4.4],seatAngle:0,route:[[4.62,-4.95],[2.7,-4.95],[2.7,.15],[1.7,.15]]},
  {id:'marketing',title:'Gaga Marketing Manager',role:'市场营销',threadId:'01a17653-b621-78f2-a027-7099ab3d4d96',appearance:1,seat:[4.62,-1.6],seatAngle:Math.PI,route:[[4.62,-.65],[2.7,-.65],[2.7,.15],[1.7,.15]]},
  {id:'creative',title:'Gaga Content & Creative',role:'内容与创意',threadId:'01a17653-b9c9-76a0-b09c-6a6168243c21',appearance:2,seat:[-3.3,-1.25],seatAngle:Math.PI,route:[[-3.3,.15],[-1.5,.15]]},
  {id:'sales',title:'Gaga Sales Recruitment & Enablement',role:'销售招募与培训',threadId:'01a17653-cbf3-78d3-bb9f-655f5fee664e',appearance:0,seat:[.7,-1.25],seatAngle:Math.PI,route:[[.7,.15],[1.7,.15]]},
  {id:'delivery',title:'Gaga Delivery & Client Success',role:'交付与客户成功',threadId:'01a17653-dd13-7432-abaf-84928ab98cb3',appearance:3,seat:[-3.3,2.5],seatAngle:Math.PI,route:[[-3.3,4.6],[2.7,4.6],[2.7,.15],[1.7,.15]]},
  {id:'finance',title:'Gaga Finance & Operations',role:'财务与运营',threadId:'01a17653-fd26-7831-9fc0-d8a89f90fdd2',appearance:4,seat:[.7,2.5],seatAngle:Math.PI,route:[[.7,4.6],[2.7,4.6],[2.7,.15],[1.7,.15]]},
].map(agent=>Object.freeze({...agent,url:'codex://threads/'+agent.threadId}));

export function getGagaAgent(index){return Number.isInteger(index)?GAGA_AGENTS[index]??null:null}
