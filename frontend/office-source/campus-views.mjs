export const GAGA_ORIGIN={x:20,z:0};
export const CAMPUS_VIEWS={
  overview:{label:'Techweiser + Gaga · 办公与运动园区',position:[40,36,55],target:[8,.85,5.5],minDistance:2.2,maxDistance:90},
  techweiser:{label:'Techweiser · AI 团队办公室',position:[16,13.5,20],target:[0,.85,-.35],minDistance:2.2,maxDistance:40},
  gaga:{label:'Gaga · 办公 / 会议 / 会客',position:[31,11,17],target:[20,.85,-.35],minDistance:2.2,maxDistance:32},
  gym:{label:'共享健身房 · 跑步 / 哑铃 / 拉伸',position:[29,19,37],target:[12,.85,14],minDistance:2.2,maxDistance:50},
};
export function campusZoom(aspect){return .94*Math.min(1,aspect/1.35)}
