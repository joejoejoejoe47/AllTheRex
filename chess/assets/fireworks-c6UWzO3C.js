import{A as e,An as t,I as n,In as r,K as i,Rn as a,_ as o,d as s,gn as c,h as l,k as u,un as d}from"./events-156d8d12.esm-CmOBparP.js";import{m as f}from"./index-ARig6tkw.js";var p=a(r(),1),m=o(),h=`/football/fireworks-53e482d4e1.bin`,g=null;async function _(e){let t=new Uint8Array(e,0,2);if(t[0]!==31||t[1]!==139)return e;let n=new Blob([e]).stream().pipeThrough(new DecompressionStream(`gzip`));return new Response(n).arrayBuffer()}function v(){return g??=fetch(f(h)).then(e=>{if(!e.ok)throw Error(`fireworks ${e.status}`);return e.arrayBuffer()}).then(_).then(e=>{let t=new DataView(e),n=t.getUint16(4,!0),r=t.getUint16(6,!0),i=t.getUint32(8,!0),a=new Uint16Array(i),o=new Uint16Array(i),s=new Uint32Array(i),c=0;for(let e=0;e<i;e++)a[e]=t.getUint16(12+e*4,!0),o[e]=t.getUint16(14+e*4,!0),s[e]=c,c+=o[e];let l=12+i*4,u=new Int16Array(e.slice(l,l+c*6)),d=new Uint8Array(e.slice(l+c*6,l+c*7)),f=new Float32Array(c*3);for(let e=0;e<i;e++){let t=0,n=0,r=0;for(let i=0;i<o[e];i++){let a=s[e]+i;t+=u[a*3],n+=u[a*3+1],r+=u[a*3+2],f[a*3]=t/400,f[a*3+1]=n/400,f[a*3+2]=r/400}}return{F:n,FPS:r,N:i,a0:a,len:o,off:s,pos:f,size:d}}).catch(e=>{throw g=null,e}),g}var y=`
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
}`;function x({track:r,shell:a,born:o,onDone:f}){let h=l(e=>e.size),g=(0,p.useMemo)(()=>{let n=new e;return n.setAttribute(`position`,new u(new Float32Array(r.N*3),3).setUsage(i)),n.setAttribute(`aAlpha`,new u(new Float32Array(r.N),1).setUsage(i)),n.boundingSphere=new c(new t(0,0,0),1e4),n},[r]),_=(0,p.useMemo)(()=>new d({vertexShader:y,fragmentShader:b,transparent:!0,depthWrite:!1,blending:2,uniforms:{uColor:{value:new n(a.color)},uSize:{value:.26},uViewH:{value:800},uHot:{value:1}}}),[a.color]);(0,p.useEffect)(()=>()=>{g.dispose(),_.dispose()},[g,_]);let v=(0,p.useRef)(!1);return s(({clock:e})=>{_.uniforms.uViewH.value=h.height;let t=((window.__fbFwT??e.elapsedTime-o)-a.delay)*2.6,n=g.attributes.position.array,i=g.attributes.aAlpha.array;if(t<0){i.fill(0),g.attributes.aAlpha.needsUpdate=!0;return}let s=t*r.FPS;if(s>=r.F-1){v.current||(v.current=!0,f());return}_.uniforms.uHot.value=Math.max(0,1-Math.max(0,t-2.5)/2);let c=Math.floor(s),l=s-c,u=a.scale,d=Math.cos(a.spin),p=Math.sin(a.spin);for(let e=0;e<r.N;e++){let t=c-r.a0[e],o=r.len[e];if(t<0||t>=o){i[e]=0;continue}let s=r.off[e]+t,f=t+1<o?s+1:s,m=r.pos[s*3]+(r.pos[f*3]-r.pos[s*3])*l,h=r.pos[s*3+1]+(r.pos[f*3+1]-r.pos[s*3+1])*l,g=r.pos[s*3+2]+(r.pos[f*3+2]-r.pos[s*3+2])*l,_=h*u;n[e*3]=a.x+(m*d-g*p)*u,n[e*3+1]=_,n[e*3+2]=a.z+(m*p+g*d)*u;let v=1-t/o,y=r.size[s]/100;i[e]=y*Math.min(1,v*4)*Math.min(1,Math.max(0,_-.15)*2)}g.attributes.position.needsUpdate=!0,g.attributes.aAlpha.needsUpdate=!0}),(0,m.jsx)(`points`,{geometry:g,material:_,frustumCulled:!1})}function S({burst:e,side:t}){let[n,r]=(0,p.useState)(null),i=l(e=>e.clock),[a,o]=(0,p.useState)([]);(0,p.useEffect)(()=>{let e=!0;return v().then(t=>e&&r(t)).catch(()=>void 0),()=>{e=!1}},[]);let s=(0,p.useRef)(null);return(0,p.useEffect)(()=>{if(!e||!n||s.current===e.id)return;s.current=e.id;let r=e.z,a=[{x:-t,z:r-.7,color:`#2ee6ff`,delay:0,scale:.125,spin:.3},{x:t,z:r+.7,color:`#ff2d7a`,delay:.25,scale:.095,spin:1.9},{x:-t,z:r+.9,color:`#ff2d7a`,delay:.6,scale:.11,spin:3.4},{x:t,z:r-.9,color:`#2ee6ff`,delay:.85,scale:.085,spin:4.8}];o(t=>[...t,{id:e.id,born:i.elapsedTime,shells:a,left:a.length}])},[e,n,t,i]),n?(0,m.jsx)(`group`,{children:a.map(e=>e.shells.map((t,r)=>(0,m.jsx)(x,{track:n,shell:t,born:e.born,onDone:()=>o(t=>t.flatMap(t=>t.id===e.id?t.left<=1?[]:[{...t,left:t.left-1}]:[t]))},`${e.id}:${r}`)))}):null}export{S as default,v as loadFireworks};