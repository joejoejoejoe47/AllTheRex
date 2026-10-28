import{a as e,c as t,d as n,f as r,i,l as a,n as o,o as s,p as c,r as l,s as u,t as d,u as f}from"./index-CN0wXEJM.js";var p=c(r(),1),m=i(),h=`/football/fireworks-53e482d4e1.bin`,g=null;async function _(e){let t=new Uint8Array(e,0,2);if(t[0]!==31||t[1]!==139)return e;let n=new Blob([e]).stream().pipeThrough(new DecompressionStream(`gzip`));return new Response(n).arrayBuffer()}function v(){return g??=fetch(d(h)).then(e=>{if(!e.ok)throw Error(`fireworks ${e.status}`);return e.arrayBuffer()}).then(_).then(e=>{let t=new DataView(e),n=t.getUint16(4,!0),r=t.getUint16(6,!0),i=t.getUint32(8,!0),a=new Uint16Array(i),o=new Uint16Array(i),s=new Uint32Array(i),c=0;for(let e=0;e<i;e++)a[e]=t.getUint16(12+e*4,!0),o[e]=t.getUint16(14+e*4,!0),s[e]=c,c+=o[e];let l=12+i*4,u=new Int16Array(e.slice(l,l+c*6)),d=new Uint8Array(e.slice(l+c*6,l+c*7)),f=new Float32Array(c*3);for(let e=0;e<i;e++){let t=0,n=0,r=0;for(let i=0;i<o[e];i++){let a=s[e]+i;t+=u[a*3],n+=u[a*3+1],r+=u[a*3+2],f[a*3]=t/400,f[a*3+1]=n/400,f[a*3+2]=r/400}}return{F:n,FPS:r,N:i,a0:a,len:o,off:s,pos:f,size:d}}).catch(e=>{throw g=null,e}),g}var y=`
attribute float aAlpha;
uniform float uSize;
uniform float uViewH;
varying float vAlpha;
void main() {
  vec4 mv = modelViewMatrix * vec4(position, 1.0);
  gl_Position = projectionMatrix * mv;
  gl_PointSize = clamp(uSize * aAlpha * projectionMatrix[1][1] * uViewH * 0.5 / max(0.1, -mv.z), 0.0, 30.0);
  vAlpha = aAlpha;
}`,b=`
uniform vec3 uColor;
uniform float uHot;
varying float vAlpha;
void main() {
  vec2 p = gl_PointCoord * 2.0 - 1.0;
  float r = dot(p, p);
  if (r > 1.0) discard;
  float core = exp(-r * 7.0);
  float glow = exp(-r * 2.2) * 0.55;
  vec3 col = mix(uColor, vec3(1.0), core * (0.12 + uHot * 0.22));
  gl_FragColor = vec4(col * (core * 0.8 + glow) * min(1.0, vAlpha * 1.3) * 0.75, 1.0);
}`;function x({track:r,shell:i,born:c,onDone:d}){let h=l(e=>e.size),g=(0,p.useMemo)(()=>{let i=new s;return i.setAttribute(`position`,new e(new Float32Array(r.N*3),3).setUsage(t)),i.setAttribute(`aAlpha`,new e(new Float32Array(r.N),1).setUsage(t)),i.boundingSphere=new f(new n(0,0,0),1e4),i},[r]),_=(0,p.useMemo)(()=>new a({vertexShader:y,fragmentShader:b,transparent:!0,depthWrite:!1,blending:2,uniforms:{uColor:{value:new u(i.color)},uSize:{value:.26},uViewH:{value:800},uHot:{value:1}}}),[i.color]);(0,p.useEffect)(()=>()=>{g.dispose(),_.dispose()},[g,_]);let v=(0,p.useRef)(!1);return o(({clock:e})=>{_.uniforms.uViewH.value=h.height;let t=((window.__fbFwT??e.elapsedTime-c)-i.delay)*1.35,n=g.attributes.position.array,a=g.attributes.aAlpha.array;if(t<0){a.fill(0),g.attributes.aAlpha.needsUpdate=!0;return}let o=t*r.FPS;if(o>=r.F-1){v.current||(v.current=!0,d());return}_.uniforms.uHot.value=Math.max(0,1-Math.max(0,t-2.5)/2);let s=Math.floor(o),l=o-s,u=i.scale,f=Math.cos(i.spin),p=Math.sin(i.spin);for(let e=0;e<r.N;e++){let t=s-r.a0[e],o=r.len[e];if(t<0||t>=o){a[e]=0;continue}let c=r.off[e]+t,d=t+1<o?c+1:c,m=r.pos[c*3]+(r.pos[d*3]-r.pos[c*3])*l,h=r.pos[c*3+1]+(r.pos[d*3+1]-r.pos[c*3+1])*l,g=r.pos[c*3+2]+(r.pos[d*3+2]-r.pos[c*3+2])*l,_=h*u;n[e*3]=i.x+(m*f-g*p)*u,n[e*3+1]=_,n[e*3+2]=i.z+(m*p+g*f)*u;let v=1-t/o,y=r.size[c]/100;a[e]=y*Math.min(1,v*4)*Math.min(1,Math.max(0,_-.15)*2)}g.attributes.position.needsUpdate=!0,g.attributes.aAlpha.needsUpdate=!0}),(0,m.jsx)(`points`,{geometry:g,material:_,frustumCulled:!1})}function S({burst:e,side:t}){let[n,r]=(0,p.useState)(null),i=l(e=>e.clock),[a,o]=(0,p.useState)([]);(0,p.useEffect)(()=>{let e=!0;return v().then(t=>e&&r(t)).catch(()=>void 0),()=>{e=!1}},[]);let s=(0,p.useRef)(null);return(0,p.useEffect)(()=>{if(!e||!n||s.current===e.id)return;s.current=e.id;let r=e.z,a=[{x:-t,z:r-.7,color:`#2ee6ff`,delay:0,scale:.125,spin:.3},{x:t,z:r+.7,color:`#ff2d7a`,delay:.25,scale:.095,spin:1.9},{x:-t,z:r+.9,color:`#ff2d7a`,delay:.6,scale:.11,spin:3.4},{x:t,z:r-.9,color:`#2ee6ff`,delay:.85,scale:.085,spin:4.8}];o(t=>[...t,{id:e.id,born:i.elapsedTime,shells:a,left:a.length}])},[e,n,t,i]),n?(0,m.jsx)(`group`,{children:a.map(e=>e.shells.map((t,r)=>(0,m.jsx)(x,{track:n,shell:t,born:e.born,onDone:()=>o(t=>t.flatMap(t=>t.id===e.id?t.left<=1?[]:[{...t,left:t.left-1}]:[t]))},`${e.id}:${r}`)))}):null}export{S as default,v as loadFireworks};