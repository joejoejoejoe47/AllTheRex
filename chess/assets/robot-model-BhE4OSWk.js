import{An as e,D as t,Dt as n,Et as r,Ft as i,I as a,In as o,Mn as s,Mt as c,Nt as l,Pn as u,Rn as d,T as f,Xt as p,_ as m,d as h,h as g,mn as _,nn as v,rn as y,un as b,w as x}from"./events-156d8d12.esm-CmOBparP.js";import{t as S}from"./Gltf-wH7dgQTS.js";import{_ as C,f as w,h as T,m as E,v as D}from"./index-BcxQjwpi.js";function O(t,n,r={}){let i=new e,a=new v,o=new e,s=new c,l=new c,u=new c;r.preserveMatrix=r.preserveMatrix===void 0||r.preserveMatrix,r.preservePosition=r.preservePosition===void 0||r.preservePosition,r.preserveHipPosition=r.preserveHipPosition!==void 0&&r.preserveHipPosition,r.useTargetMatrix=r.useTargetMatrix!==void 0&&r.useTargetMatrix,r.hip=r.hip===void 0?`hip`:r.hip,r.names=r.names||{};let d=n.isObject3D?n.skeleton.bones:M(n),f=t.isObject3D?t.skeleton.bones:M(t),p,m,h,g,_;if(t.isObject3D?t.skeleton.pose():(r.useTargetMatrix=!0,r.preserveMatrix=!1),r.preservePosition){_=[];for(let e=0;e<f.length;e++)_.push(f[e].position.clone())}if(r.preserveMatrix){t.updateMatrixWorld(),t.matrixWorld.identity();for(let e=0;e<t.children.length;++e)t.children[e].updateMatrixWorld(!0)}if(r.offsets){p=[];for(let e=0;e<f.length;++e)m=f[e],h=r.names[m.name]||m.name,r.offsets[h]&&(m.matrix.multiply(r.offsets[h]),m.matrix.decompose(m.position,m.quaternion,m.scale),m.updateMatrixWorld()),p.push(m.matrixWorld.clone())}for(let e=0;e<f.length;++e){if(m=f[e],h=r.names[m.name]||m.name,g=j(h,d),u.copy(m.matrixWorld),g){if(g.updateMatrixWorld(),r.useTargetMatrix?l.copy(g.matrixWorld):(l.copy(t.matrixWorld).invert(),l.multiply(g.matrixWorld)),o.setFromMatrixScale(l),l.scale(o.set(1/o.x,1/o.y,1/o.z)),u.makeRotationFromQuaternion(a.setFromRotationMatrix(l)),t.isObject3D){let e=f.indexOf(m),n=p?p[e]:s.copy(t.skeleton.boneInverses[e]).invert();u.multiply(n)}u.copyPosition(l)}m.parent&&m.parent.isBone?(m.matrix.copy(m.parent.matrixWorld).invert(),m.matrix.multiply(u)):m.matrix.copy(u),r.preserveHipPosition&&h===r.hip&&m.matrix.setPosition(i.set(0,m.position.y,0)),m.matrix.decompose(m.position,m.quaternion,m.scale),m.updateMatrixWorld()}if(r.preservePosition)for(let e=0;e<f.length;++e)m=f[e],h=r.names[m.name]||m.name,h!==r.hip&&m.position.copy(_[e]);r.preserveMatrix&&t.updateMatrixWorld(!0)}function k(e,t,n,r={}){r.useFirstFramePosition=r.useFirstFramePosition!==void 0&&r.useFirstFramePosition,r.fps=r.fps===void 0?30:r.fps,r.names=r.names||[],t.isObject3D||(t=N(t));let i=Math.round(n.duration*(r.fps/1e3)*1e3),a=1/r.fps,o=[],c=new f(t),l=M(e.skeleton),u=[],d,p,m,h,g;c.clipAction(n).play(),c.update(0),t.updateMatrixWorld();for(let n=0;n<i;++n){let o=n*a;O(e,t,r);for(let e=0;e<l.length;++e)g=r.names[l[e].name]||l[e].name,m=j(g,t.skeleton),m&&(p=l[e],h=u[e]=u[e]||{bone:p},r.hip===g&&(h.pos||(h.pos={times:new Float32Array(i),values:new Float32Array(i*3)}),r.useFirstFramePosition&&(n===0&&(d=p.position.clone()),p.position.sub(d)),h.pos.times[n]=o,p.position.toArray(h.pos.values,n*3)),h.quat||(h.quat={times:new Float32Array(i),values:new Float32Array(i*4)}),h.quat.times[n]=o,p.quaternion.toArray(h.quat.values,n*4));c.update(a),t.updateMatrixWorld()}for(let e=0;e<u.length;++e)h=u[e],h&&(h.pos&&o.push(new s(`.bones[`+h.bone.name+`].position`,h.pos.times,h.pos.values)),o.push(new y(`.bones[`+h.bone.name+`].quaternion`,h.quat.times,h.quat.values)));return c.uncacheAction(n),new x(n.name,-1,o)}function A(e){let t=new Map,n=new Map,r=e.clone();return P(e,r,function(e,r){t.set(r,e),n.set(e,r)}),r.traverse(function(e){if(!e.isSkinnedMesh)return;let r=e,i=t.get(e),a=i.skeleton.bones;r.skeleton=i.skeleton.clone(),r.bindMatrix.copy(i.bindMatrix),r.skeleton.bones=a.map(function(e){return n.get(e)}),r.bind(r.skeleton,r.bindMatrix)}),r}function j(e,t){for(let n=0,r=M(t);n<r.length;n++)if(e===r[n].name)return r[n]}function M(e){return Array.isArray(e)?e:e.bones}function N(e){let t=new _(e.bones[0]);return t.skeleton=e,t}function P(e,t,n){n(e,t);for(let r=0;r<e.children.length;r++)P(e.children[r],t.children[r],n)}var F={retarget:O,retargetClip:k,clone:A},I={uniforms:{tDiffuse:{value:null},h:{value:1/512}},vertexShader:`
      varying vec2 vUv;

      void main() {

        vUv = uv;
        gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );

      }
  `,fragmentShader:`
    uniform sampler2D tDiffuse;
    uniform float h;

    varying vec2 vUv;

    void main() {

    	vec4 sum = vec4( 0.0 );

    	sum += texture2D( tDiffuse, vec2( vUv.x - 4.0 * h, vUv.y ) ) * 0.051;
    	sum += texture2D( tDiffuse, vec2( vUv.x - 3.0 * h, vUv.y ) ) * 0.0918;
    	sum += texture2D( tDiffuse, vec2( vUv.x - 2.0 * h, vUv.y ) ) * 0.12245;
    	sum += texture2D( tDiffuse, vec2( vUv.x - 1.0 * h, vUv.y ) ) * 0.1531;
    	sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y ) ) * 0.1633;
    	sum += texture2D( tDiffuse, vec2( vUv.x + 1.0 * h, vUv.y ) ) * 0.1531;
    	sum += texture2D( tDiffuse, vec2( vUv.x + 2.0 * h, vUv.y ) ) * 0.12245;
    	sum += texture2D( tDiffuse, vec2( vUv.x + 3.0 * h, vUv.y ) ) * 0.0918;
    	sum += texture2D( tDiffuse, vec2( vUv.x + 4.0 * h, vUv.y ) ) * 0.051;

    	gl_FragColor = sum;

    }
  `},L={uniforms:{tDiffuse:{value:null},v:{value:1/512}},vertexShader:`
    varying vec2 vUv;

    void main() {

      vUv = uv;
      gl_Position = projectionMatrix * modelViewMatrix * vec4( position, 1.0 );

    }
  `,fragmentShader:`

  uniform sampler2D tDiffuse;
  uniform float v;

  varying vec2 vUv;

  void main() {

    vec4 sum = vec4( 0.0 );

    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y - 4.0 * v ) ) * 0.051;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y - 3.0 * v ) ) * 0.0918;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y - 2.0 * v ) ) * 0.12245;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y - 1.0 * v ) ) * 0.1531;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y ) ) * 0.1633;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y + 1.0 * v ) ) * 0.1531;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y + 2.0 * v ) ) * 0.12245;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y + 3.0 * v ) ) * 0.0918;
    sum += texture2D( tDiffuse, vec2( vUv.x, vUv.y + 4.0 * v ) ) * 0.051;

    gl_FragColor = sum;

  }
  `},R=d(o()),z=R.forwardRef(({scale:e=10,frames:t=1/0,opacity:n=1,width:r=1,height:o=1,blur:s=1,near:c=0,far:d=10,resolution:f=512,smooth:m=!0,color:_=`#000000`,depthWrite:v=!1,renderOrder:y,...x},S)=>{let C=R.useRef(null),w=g(e=>e.scene),T=g(e=>e.gl),E=R.useRef(null);r*=Array.isArray(e)?e[0]:e||1,o*=Array.isArray(e)?e[1]:e||1;let[O,k,A,j,M,N,P]=R.useMemo(()=>{let e=new u(f,f),t=new u(f,f);t.texture.generateMipmaps=e.texture.generateMipmaps=!1;let n=new p(r,o).rotateX(Math.PI/2),s=new l(n),c=new i;c.depthTest=c.depthWrite=!1,c.onBeforeCompile=e=>{e.uniforms={...e.uniforms,ucolor:{value:new a(_)}},e.fragmentShader=e.fragmentShader.replace(`void main() {`,`uniform vec3 ucolor;
           void main() {
          `),e.fragmentShader=e.fragmentShader.replace(`vec4( vec3( 1.0 - fragCoordZ ), opacity );`,`vec4( ucolor * fragCoordZ * 2.0, ( 1.0 - fragCoordZ ) * 1.0 );`)};let d=new b(I),m=new b(L);return m.depthTest=d.depthTest=!1,[e,n,c,s,d,m,t]},[f,r,o,e,_]),F=e=>{j.visible=!0,j.material=M,M.uniforms.tDiffuse.value=O.texture,M.uniforms.h.value=e*1/256,T.setRenderTarget(P),T.render(j,E.current),j.material=N,N.uniforms.tDiffuse.value=P.texture,N.uniforms.v.value=e*1/256,T.setRenderTarget(O),T.render(j,E.current),j.visible=!1},z=0,B,V;return h(()=>{E.current&&(t===1/0||z<t)&&(z++,B=w.background,V=w.overrideMaterial,C.current.visible=!1,w.background=null,w.overrideMaterial=A,T.setRenderTarget(O),T.render(w,E.current),F(s),m&&F(s*.4),T.setRenderTarget(null),C.current.visible=!0,w.overrideMaterial=V,w.background=B)}),R.useImperativeHandle(S,()=>C.current,[]),R.createElement(`group`,D({"rotation-x":Math.PI/2},x,{ref:C}),R.createElement(`mesh`,{renderOrder:y,geometry:k,scale:[1,-1,1],rotation:[-Math.PI/2,0,0]},R.createElement(`meshBasicMaterial`,{transparent:!0,map:O.texture,opacity:n,depthWrite:v})),R.createElement(`orthographicCamera`,{ref:E,args:[-r/2,r/2,o/2,-o/2,c,d]}))}),B=m(),V={cute:{},poddy:{idle:`pose 4 - warm welcome`,trick:`pose 2 - omfg`,talk:`pose 3 - hello`,"point-left":`pose 6 - presentation flipped`,"point-right":`pose 1 - presentation`,walk:`pose 4 - warm welcome`,fly:`pose 2 - omfg`}},H={cute:-Math.PI/2,poddy:0};function U({model:i,mood:a,trickEvery:o}){let s=w.find(e=>e.id===i)??w[0],{scene:c,animations:l}=S(E(s.url)),u=(0,R.useMemo)(()=>F.clone(c),[c]),d=(0,R.useMemo)(()=>{u.updateMatrixWorld(!0);let n=new t().setFromObject(u),r=n.getSize(new e),i=n.getCenter(new e),a=2/Math.max(r.y,.001);return{s:a,x:-i.x*a,y:-n.min.y*a,z:-i.z*a}},[u]),f=(0,R.useRef)(null),p=(0,R.useRef)(null),{actions:m,names:g}=T(l,f),_=(0,R.useRef)(performance.now()+o*1e3),v=(0,R.useRef)(0),y=(0,R.useRef)(null);function b(e,t){let i=e?m[e]:g[0]?m[g[0]]:void 0;i&&(y.current&&y.current!==i&&y.current.fadeOut(.25),i.reset(),i.setLoop(t?n:r,1/0),i.clampWhenFinished=!0,i.fadeIn(.25).play(),y.current=i)}let x=(0,R.useRef)(1/0);return(0,R.useEffect)(()=>{if(i===`cute`){y.current?.stop(),y.current=null,u.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)}),x.current=performance.now()+3e3+Math.random()*3e3;return}b(V[i][a]??V[i].idle,!1)},[a,i,m]),h((e,t)=>{let n=p.current;if(!n)return;let s=e.clock.elapsedTime,c=performance.now();if(i===`cute`&&c>=x.current){x.current=c+3e3+Math.random()*3e3;let e=g.find(e=>/anim|blink/i.test(e))??g[0],t=e?m[e]:void 0;t&&(t.reset(),t.setLoop(r,1),t.clampWhenFinished=!0,t.timeScale=1.25+Math.random()*.25,t.fadeIn(.02).play(),window.setTimeout(()=>{t.fadeOut(.04),u.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)})},380))}a===`idle`&&c>_.current&&(_.current=c+o*1e3+Math.random()*2500,v.current=1,V[i].trick&&b(V[i].trick,!1));let l=Math.sin(s*2.2)*.05,d=Math.sin(s*.7)*.12,f=0;if(v.current>0){v.current=Math.max(0,v.current-t/1.3);let e=1-v.current;d=e*Math.PI*2,l+=Math.sin(e*Math.PI)*.45,v.current===0&&V[i].idle&&b(V[i].idle,!1)}a===`walk`&&(l=Math.abs(Math.sin(s*9))*.12,f=Math.sin(s*9)*.08),a===`fly`&&(l=.2+Math.sin(s*6)*.08,f=.25),a===`point-left`&&(d=-.6),a===`point-right`&&(d=.6),a===`talk`&&(l+=Math.abs(Math.sin(s*8))*.04),n.position.y=l,n.rotation.y=H[i]+d,n.rotation.z=f}),(0,B.jsx)(`group`,{ref:p,children:(0,B.jsx)(`group`,{ref:f,position:[d.x,d.y,d.z],scale:d.s,children:(0,B.jsx)(`primitive`,{object:u})})})}function W({model:e,mood:t=`idle`,trickEvery:n=6,className:r,zoom:i=1}){let[a,o]=(0,R.useState)(0),s=()=>window.setTimeout(()=>o(e=>e+1),300);return(0,B.jsx)(`div`,{className:r,"data-robot-stage":e,children:(0,B.jsxs)(C,{dpr:[1,2],gl:{alpha:!0,antialias:!0,preserveDrawingBuffer:!0},camera:{position:[0,1.25,5/i],fov:38},onCreated:({camera:e,gl:t})=>{e.lookAt(0,1.25,0),t.domElement.addEventListener(`webglcontextlost`,e=>{e.preventDefault(),s()})},children:[(0,B.jsx)(`hemisphereLight`,{args:[`#fff6e0`,`#2a2030`,1.6]}),(0,B.jsx)(`ambientLight`,{intensity:.6}),(0,B.jsx)(`directionalLight`,{position:[3,5,4],intensity:1.6}),(0,B.jsx)(`directionalLight`,{position:[-4,2,-2],intensity:.5,color:`#9ecbff`}),(0,B.jsx)(R.Suspense,{fallback:null,children:(0,B.jsx)(U,{model:e,mood:t,trickEvery:n},e)}),(0,B.jsx)(z,{position:[0,0,0],opacity:.45,scale:4,blur:2.4,far:2})]},a)})}export{W as RobotStage,W as default};