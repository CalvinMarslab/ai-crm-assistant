export const GAGA_ORIGIN={x:20,z:0};
export const CAMPUS_VIEWS={
  overview:{label:'Techweiser + Gaga · 后场 HYROX 训练园区',position:[42,37,37],target:[9,.85,-11],minDistance:2.2,maxDistance:110},
  techweiser:{label:'Techweiser · AI 团队办公室',position:[16,13.5,20],target:[0,.85,-.35],minDistance:2.2,maxDistance:40},
  gaga:{label:'Gaga · 办公 / 会议 / 会客',position:[31,11,17],target:[20,.85,-.35],minDistance:2.2,maxDistance:32},
  gym:{label:'HYROX 后场 · 8 个训练区 + 环形跑道',position:[46,38,10],target:[12,.85,-21],minDistance:2.2,maxDistance:75},
};
export function campusZoom(aspect){return .94*Math.min(1,aspect/1.35)}
