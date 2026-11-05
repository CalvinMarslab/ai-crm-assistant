import * as T from 'three';
import {mergeGeometries} from 'three/addons/utils/BufferGeometryUtils.js';

// Local pigment grain and restrained cel bands; no photographs or remote assets.
function paperTexture(){
  const size=128,data=new Uint8Array(size*size*4);
  for(let y=0;y<size;y++)for(let x=0;x<size;x++){
    const n=Math.sin(x*12.9898+y*78.233)*43758.5453;
    const wash=Math.sin(x*.13+Math.sin(y*.09)*2)*2.2+Math.cos(y*.17+x*.025)*1.4;
    const v=Math.round(249+wash+((n-Math.floor(n))-.5)*5);
    data.set([Math.min(255,v),Math.min(255,v-1),Math.min(255,v-4),255],(y*size+x)*4);
  }
  const texture=new T.DataTexture(data,size,size,T.RGBAFormat);
  texture.colorSpace=T.SRGBColorSpace;texture.wrapS=texture.wrapT=T.RepeatWrapping;texture.repeat.set(3,3);
  texture.generateMipmaps=true;texture.minFilter=T.LinearMipmapLinearFilter;texture.magFilter=T.LinearFilter;texture.needsUpdate=true;
  return texture;
}
const paper=paperTexture();
const ramp=new T.DataTexture(new Uint8Array([84,139,187,229]),4,1,T.RedFormat);
ramp.minFilter=ramp.magFilter=T.NearestFilter;ramp.generateMipmaps=false;ramp.needsUpdate=true;

export function paint(color,{vertexColors=false,texture=true}={}){
  return new T.MeshToonMaterial({color,vertexColors,map:texture?paper:null,gradientMap:ramp});
}
export const cloth=color=>paint(color);

const ink=new T.MeshBasicMaterial({color:0x403b33,side:T.BackSide,toneMapped:false});
ink.name='warm hand-drawn contour';
ink.onBeforeCompile=shader=>{
  const marker='#include <project_vertex>';
  if(!shader.vertexShader.includes(marker))throw new Error('Character contour shader is incompatible with the current renderer');
  shader.vertexShader=shader.vertexShader.replace(marker,`${marker}
    // These models use articulated groups, so normals need only the normal matrix.
    vec3 inkNormal = normalize(normalMatrix * normal);
    float inkWidth = clamp(-mvPosition.z * 0.00105, 0.0065, 0.016);
    mvPosition.xyz += inkNormal * inkWidth;
    gl_Position = projectionMatrix * mvPosition;
  `);
};
ink.customProgramCacheKey=()=> 'office-hand-drawn-ink-v1';

export function outlineCharacter(root,index){
  const groups=[];root.traverse(o=>{if(o.isGroup&&o.name!=='eye')groups.push(o)});
  for(const parent of groups){
    const parts=parent.children.filter(o=>{
      if(!o.isMesh||o.userData.noOutline)return false;
      o.geometry.computeBoundingSphere();
      return o.geometry.boundingSphere.radius*Math.max(...o.scale.toArray())>.083;
    });
    if(!parts.length)continue;
    const copies=parts.map(part=>{
      part.updateMatrix();let geometry=part.geometry.clone().applyMatrix4(part.matrix);
      // Only the geometric silhouette is needed; paint UVs and cheek colors stay
      // on the original surface. Keep each contour under its original joint.
      for(const key of Object.keys(geometry.attributes))if(!['position','normal'].includes(key))geometry.deleteAttribute(key);
      if(geometry.index){const nonIndexed=geometry.toNonIndexed();geometry.dispose();geometry=nonIndexed}
      return geometry;
    });
    const geometry=mergeGeometries(copies,false);copies.forEach(g=>g.dispose());
    if(!geometry)continue;
    const contour=new T.Mesh(geometry,ink);contour.name='ink-contour';contour.userData={agent:index,isContour:true};
    contour.castShadow=false;contour.receiveShadow=false;contour.raycast=()=>{};
    geometry.computeBoundingSphere();parent.add(contour);
  }
}
