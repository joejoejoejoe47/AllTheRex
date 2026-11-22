import{Bn as e,D as t,Dt as n,I as r,In as i,It as a,Mn as o,Nt as s,Ot as c,Pn as l,Pt as u,Rn as d,T as f,Zt as p,_ as m,d as h,fn as g,gn as _,h as v,in as y,rn as b,w as x}from"./events-156d8d12.esm-4Mw7bFKf.js";import{t as S}from"./Gltf-wk1ttV7t.js";import{D as C,E as w,S as T,T as E,w as D}from"./index-6FFN6FtW.js";function O(e,t,n={}){let r=new o,i=new b,a=new o,c=new s,l=new s,u=new s;n.preserveMatrix=n.preserveMatrix===void 0||n.preserveMatrix,n.preservePosition=n.preservePosition===void 0||n.preservePosition,n.preserveHipPosition=n.preserveHipPosition!==void 0&&n.preserveHipPosition,n.useTargetMatrix=n.useTargetMatrix!==void 0&&n.useTargetMatrix,n.hip=n.hip===void 0?`hip`:n.hip,n.names=n.names||{};let d=t.isObject3D?t.skeleton.bones:M(t),f=e.isObject3D?e.skeleton.bones:M(e),p,m,h,g,_;if(e.isObject3D?e.skeleton.pose():(n.useTargetMatrix=!0,n.preserveMatrix=!1),n.preservePosition){_=[];for(let e=0;e<f.length;e++)_.push(f[e].position.clone())}if(n.preserveMatrix){e.updateMatrixWorld(),e.matrixWorld.identity();for(let t=0;t<e.children.length;++t)e.children[t].updateMatrixWorld(!0)}if(n.offsets){p=[];for(let e=0;e<f.length;++e)m=f[e],h=n.names[m.name]||m.name,n.offsets[h]&&(m.matrix.multiply(n.offsets[h]),m.matrix.decompose(m.position,m.quaternion,m.scale),m.updateMatrixWorld()),p.push(m.matrixWorld.clone())}for(let t=0;t<f.length;++t){if(m=f[t],h=n.names[m.name]||m.name,g=j(h,d),u.copy(m.matrixWorld),g){if(g.updateMatrixWorld(),n.useTargetMatrix?l.copy(g.matrixWorld):(l.copy(e.matrixWorld).invert(),l.multiply(g.matrixWorld)),a.setFromMatrixScale(l),l.scale(a.set(1/a.x,1/a.y,1/a.z)),u.makeRotationFromQuaternion(i.setFromRotationMatrix(l)),e.isObject3D){let t=f.indexOf(m),n=p?p[t]:c.copy(e.skeleton.boneInverses[t]).invert();u.multiply(n)}u.copyPosition(l)}m.parent&&m.parent.isBone?(m.matrix.copy(m.parent.matrixWorld).invert(),m.matrix.multiply(u)):m.matrix.copy(u),n.preserveHipPosition&&h===n.hip&&m.matrix.setPosition(r.set(0,m.position.y,0)),m.matrix.decompose(m.position,m.quaternion,m.scale),m.updateMatrixWorld()}if(n.preservePosition)for(let e=0;e<f.length;++e)m=f[e],h=n.names[m.name]||m.name,h!==n.hip&&m.position.copy(_[e]);n.preserveMatrix&&e.updateMatrixWorld(!0)}function k(e,t,n,r={}){r.useFirstFramePosition=r.useFirstFramePosition!==void 0&&r.useFirstFramePosition,r.fps=r.fps===void 0?30:r.fps,r.names=r.names||[],t.isObject3D||(t=N(t));let i=Math.round(n.duration*(r.fps/1e3)*1e3),a=1/r.fps,o=[],s=new f(t),c=M(e.skeleton),u=[],d,p,m,h,g;s.clipAction(n).play(),s.update(0),t.updateMatrixWorld();for(let n=0;n<i;++n){let o=n*a;O(e,t,r);for(let e=0;e<c.length;++e)g=r.names[c[e].name]||c[e].name,m=j(g,t.skeleton),m&&(p=c[e],h=u[e]=u[e]||{bone:p},r.hip===g&&(h.pos||(h.pos={times:new Float32Array(i),values:new Float32Array(i*3)}),r.useFirstFramePosition&&(n===0&&(d=p.position.clone()),p.position.sub(d)),h.pos.times[n]=o,p.position.toArray(h.pos.values,n*3)),h.quat||(h.quat={times:new Float32Array(i),values:new Float32Array(i*4)}),h.quat.times[n]=o,p.quaternion.toArray(h.quat.values,n*4));s.update(a),t.updateMatrixWorld()}for(let e=0;e<u.length;++e)h=u[e],h&&(h.pos&&o.push(new l(`.bones[`+h.bone.name+`].position`,h.pos.times,h.pos.values)),o.push(new y(`.bones[`+h.bone.name+`].quaternion`,h.quat.times,h.quat.values)));return s.uncacheAction(n),new x(n.name,-1,o)}function A(e){let t=new Map,n=new Map,r=e.clone();return P(e,r,function(e,r){t.set(r,e),n.set(e,r)}),r.traverse(function(e){if(!e.isSkinnedMesh)return;let r=e,i=t.get(e),a=i.skeleton.bones;r.skeleton=i.skeleton.clone(),r.bindMatrix.copy(i.bindMatrix),r.skeleton.bones=a.map(function(e){return n.get(e)}),r.bind(r.skeleton,r.bindMatrix)}),r}function j(e,t){for(let n=0,r=M(t);n<r.length;n++)if(e===r[n].name)return r[n]}function M(e){return Array.isArray(e)?e:e.bones}function N(e){let t=new _(e.bones[0]);return t.skeleton=e,t}function P(e,t,n){n(e,t);for(let r=0;r<e.children.length;r++)P(e.children[r],t.children[r],n)}var F={retarget:O,retargetClip:k,clone:A},I={uniforms:{tDiffuse:{value:null},h:{value:1/512}},vertexShader:`
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
  `},R=e(d()),z=R.forwardRef(({scale:e=10,frames:t=1/0,opacity:n=1,width:o=1,height:s=1,blur:c=1,near:l=0,far:d=10,resolution:f=512,smooth:m=!0,color:_=`#000000`,depthWrite:y=!1,renderOrder:b,...x},S)=>{let w=R.useRef(null),T=v(e=>e.scene),E=v(e=>e.gl),D=R.useRef(null);o*=Array.isArray(e)?e[0]:e||1,s*=Array.isArray(e)?e[1]:e||1;let[O,k,A,j,M,N,P]=R.useMemo(()=>{let e=new i(f,f),t=new i(f,f);t.texture.generateMipmaps=e.texture.generateMipmaps=!1;let n=new p(o,s).rotateX(Math.PI/2),c=new u(n),l=new a;l.depthTest=l.depthWrite=!1,l.onBeforeCompile=e=>{e.uniforms={...e.uniforms,ucolor:{value:new r(_)}},e.fragmentShader=e.fragmentShader.replace(`void main() {`,`uniform vec3 ucolor;
           void main() {
          `),e.fragmentShader=e.fragmentShader.replace(`vec4( vec3( 1.0 - fragCoordZ ), opacity );`,`vec4( ucolor * fragCoordZ * 2.0, ( 1.0 - fragCoordZ ) * 1.0 );`)};let d=new g(I),m=new g(L);return m.depthTest=d.depthTest=!1,[e,n,l,c,d,m,t]},[f,o,s,e,_]),F=e=>{j.visible=!0,j.material=M,M.uniforms.tDiffuse.value=O.texture,M.uniforms.h.value=e*1/256,E.setRenderTarget(P),E.render(j,D.current),j.material=N,N.uniforms.tDiffuse.value=P.texture,N.uniforms.v.value=e*1/256,E.setRenderTarget(O),E.render(j,D.current),j.visible=!1},z=0,B,V;return h(()=>{D.current&&(t===1/0||z<t)&&(z++,B=T.background,V=T.overrideMaterial,w.current.visible=!1,T.background=null,T.overrideMaterial=A,E.setRenderTarget(O),E.render(T,D.current),F(c),m&&F(c*.4),E.setRenderTarget(null),w.current.visible=!0,T.overrideMaterial=V,T.background=B)}),R.useImperativeHandle(S,()=>w.current,[]),R.createElement(`group`,C({"rotation-x":Math.PI/2},x,{ref:w}),R.createElement(`mesh`,{renderOrder:b,geometry:k,scale:[1,-1,1],rotation:[-Math.PI/2,0,0]},R.createElement(`meshBasicMaterial`,{transparent:!0,map:O.texture,opacity:n,depthWrite:y})),R.createElement(`orthographicCamera`,{ref:D,args:[-o/2,o/2,s/2,-s/2,l,d]}))}),B=m(),V={cute:{},poddy:{idle:`pose 4 - warm welcome`,trick:`pose 2 - omfg`,talk:`pose 3 - hello`,"point-left":`pose 6 - presentation flipped`,"point-right":`pose 1 - presentation`,walk:`pose 4 - warm welcome`,fly:`pose 2 - omfg`}},H={cute:-Math.PI/2,poddy:0};function U({model:e,mood:r,trickEvery:i}){let a=T.find(t=>t.id===e)??T[0],{scene:s,animations:l}=S(D(a.url)),u=(0,R.useMemo)(()=>F.clone(s),[s]),d=(0,R.useMemo)(()=>{u.updateMatrixWorld(!0);let e=new t().setFromObject(u),n=e.getSize(new o),r=e.getCenter(new o),i=2/Math.max(n.y,.001);return{s:i,x:-r.x*i,y:-e.min.y*i,z:-r.z*i}},[u]),f=(0,R.useRef)(null),p=(0,R.useRef)(null),{actions:m,names:g}=E(l,f),_=(0,R.useRef)(performance.now()+i*1e3),v=(0,R.useRef)(0),y=(0,R.useRef)(null);function b(e,t){let r=e?m[e]:g[0]?m[g[0]]:void 0;r&&(y.current&&y.current!==r&&y.current.fadeOut(.25),r.reset(),r.setLoop(t?c:n,1/0),r.clampWhenFinished=!0,r.fadeIn(.25).play(),y.current=r)}let x=(0,R.useRef)(1/0);return(0,R.useEffect)(()=>{if(e===`cute`){y.current?.stop(),y.current=null,u.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)}),x.current=performance.now()+3e3+Math.random()*3e3;return}b(V[e][r]??V[e].idle,!1)},[r,e,m]),h((t,a)=>{let o=p.current;if(!o)return;let s=t.clock.elapsedTime,c=performance.now();if(e===`cute`&&c>=x.current){x.current=c+3e3+Math.random()*3e3;let e=g.find(e=>/anim|blink/i.test(e))??g[0],t=e?m[e]:void 0;t&&(t.reset(),t.setLoop(n,1),t.clampWhenFinished=!0,t.timeScale=1.25+Math.random()*.25,t.fadeIn(.02).play(),window.setTimeout(()=>{t.fadeOut(.04),u.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)})},380))}r===`idle`&&c>_.current&&(_.current=c+i*1e3+Math.random()*2500,v.current=1,V[e].trick&&b(V[e].trick,!1));let l=Math.sin(s*2.2)*.05,d=Math.sin(s*.7)*.12,f=0;if(v.current>0){v.current=Math.max(0,v.current-a/1.3);let t=1-v.current;d=t*Math.PI*2,l+=Math.sin(t*Math.PI)*.45,v.current===0&&V[e].idle&&b(V[e].idle,!1)}r===`walk`&&(l=Math.abs(Math.sin(s*9))*.12,f=Math.sin(s*9)*.08),r===`fly`&&(l=.2+Math.sin(s*6)*.08,f=.25),r===`point-left`&&(d=-.6),r===`point-right`&&(d=.6),r===`talk`&&(l+=Math.abs(Math.sin(s*8))*.04),o.position.y=l,o.rotation.y=H[e]+d,o.rotation.z=f}),(0,B.jsx)(`group`,{ref:p,children:(0,B.jsx)(`group`,{ref:f,position:[d.x,d.y,d.z],scale:d.s,children:(0,B.jsx)(`primitive`,{object:u})})})}function W({model:e,mood:t=`idle`,trickEvery:n=6,className:r,zoom:i=1}){let[a,o]=(0,R.useState)(0),s=()=>window.setTimeout(()=>o(e=>e+1),300);return(0,B.jsx)(`div`,{className:r,"data-robot-stage":e,children:(0,B.jsxs)(w,{dpr:[1,2],gl:{alpha:!0,antialias:!0,preserveDrawingBuffer:!0},camera:{position:[0,1.25,5/i],fov:38},onCreated:({camera:e,gl:t})=>{e.lookAt(0,1.25,0),t.domElement.addEventListener(`webglcontextlost`,e=>{e.preventDefault(),s()})},children:[(0,B.jsx)(`hemisphereLight`,{args:[`#fff6e0`,`#2a2030`,1.6]}),(0,B.jsx)(`ambientLight`,{intensity:.6}),(0,B.jsx)(`directionalLight`,{position:[3,5,4],intensity:1.6}),(0,B.jsx)(`directionalLight`,{position:[-4,2,-2],intensity:.5,color:`#9ecbff`}),(0,B.jsx)(R.Suspense,{fallback:null,children:(0,B.jsx)(U,{model:e,mood:t,trickEvery:n},e)}),(0,B.jsx)(z,{position:[0,0,0],opacity:.45,scale:4,blur:2.4,far:2})]},a)})}export{W as RobotStage,W as default};