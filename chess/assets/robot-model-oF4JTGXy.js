import{D as e,Dt as t,Et as n,Fn as r,Ft as i,I as a,Ln as o,Mt as s,Nn as c,Nt as l,T as u,Xt as d,_ as f,d as p,dn as m,h,hn as g,jn as _,nn as v,rn as y,w as b,zn as x}from"./events-156d8d12.esm-B26WsrmC.js";import{t as S}from"./Gltf-CpPnk27D.js";import{D as C,E as w,S as T,T as E,w as D}from"./index-meR0StFr.js";function O(e,t,n={}){let r=new _,i=new v,a=new _,o=new s,c=new s,l=new s;n.preserveMatrix=n.preserveMatrix===void 0||n.preserveMatrix,n.preservePosition=n.preservePosition===void 0||n.preservePosition,n.preserveHipPosition=n.preserveHipPosition!==void 0&&n.preserveHipPosition,n.useTargetMatrix=n.useTargetMatrix!==void 0&&n.useTargetMatrix,n.hip=n.hip===void 0?`hip`:n.hip,n.names=n.names||{};let u=t.isObject3D?t.skeleton.bones:M(t),d=e.isObject3D?e.skeleton.bones:M(e),f,p,m,h,g;if(e.isObject3D?e.skeleton.pose():(n.useTargetMatrix=!0,n.preserveMatrix=!1),n.preservePosition){g=[];for(let e=0;e<d.length;e++)g.push(d[e].position.clone())}if(n.preserveMatrix){e.updateMatrixWorld(),e.matrixWorld.identity();for(let t=0;t<e.children.length;++t)e.children[t].updateMatrixWorld(!0)}if(n.offsets){f=[];for(let e=0;e<d.length;++e)p=d[e],m=n.names[p.name]||p.name,n.offsets[m]&&(p.matrix.multiply(n.offsets[m]),p.matrix.decompose(p.position,p.quaternion,p.scale),p.updateMatrixWorld()),f.push(p.matrixWorld.clone())}for(let t=0;t<d.length;++t){if(p=d[t],m=n.names[p.name]||p.name,h=j(m,u),l.copy(p.matrixWorld),h){if(h.updateMatrixWorld(),n.useTargetMatrix?c.copy(h.matrixWorld):(c.copy(e.matrixWorld).invert(),c.multiply(h.matrixWorld)),a.setFromMatrixScale(c),c.scale(a.set(1/a.x,1/a.y,1/a.z)),l.makeRotationFromQuaternion(i.setFromRotationMatrix(c)),e.isObject3D){let t=d.indexOf(p),n=f?f[t]:o.copy(e.skeleton.boneInverses[t]).invert();l.multiply(n)}l.copyPosition(c)}p.parent&&p.parent.isBone?(p.matrix.copy(p.parent.matrixWorld).invert(),p.matrix.multiply(l)):p.matrix.copy(l),n.preserveHipPosition&&m===n.hip&&p.matrix.setPosition(r.set(0,p.position.y,0)),p.matrix.decompose(p.position,p.quaternion,p.scale),p.updateMatrixWorld()}if(n.preservePosition)for(let e=0;e<d.length;++e)p=d[e],m=n.names[p.name]||p.name,m!==n.hip&&p.position.copy(g[e]);n.preserveMatrix&&e.updateMatrixWorld(!0)}function k(e,t,n,r={}){r.useFirstFramePosition=r.useFirstFramePosition!==void 0&&r.useFirstFramePosition,r.fps=r.fps===void 0?30:r.fps,r.names=r.names||[],t.isObject3D||(t=N(t));let i=Math.round(n.duration*(r.fps/1e3)*1e3),a=1/r.fps,o=[],s=new u(t),l=M(e.skeleton),d=[],f,p,m,h,g;s.clipAction(n).play(),s.update(0),t.updateMatrixWorld();for(let n=0;n<i;++n){let o=n*a;O(e,t,r);for(let e=0;e<l.length;++e)g=r.names[l[e].name]||l[e].name,m=j(g,t.skeleton),m&&(p=l[e],h=d[e]=d[e]||{bone:p},r.hip===g&&(h.pos||(h.pos={times:new Float32Array(i),values:new Float32Array(i*3)}),r.useFirstFramePosition&&(n===0&&(f=p.position.clone()),p.position.sub(f)),h.pos.times[n]=o,p.position.toArray(h.pos.values,n*3)),h.quat||(h.quat={times:new Float32Array(i),values:new Float32Array(i*4)}),h.quat.times[n]=o,p.quaternion.toArray(h.quat.values,n*4));s.update(a),t.updateMatrixWorld()}for(let e=0;e<d.length;++e)h=d[e],h&&(h.pos&&o.push(new c(`.bones[`+h.bone.name+`].position`,h.pos.times,h.pos.values)),o.push(new y(`.bones[`+h.bone.name+`].quaternion`,h.quat.times,h.quat.values)));return s.uncacheAction(n),new b(n.name,-1,o)}function A(e){let t=new Map,n=new Map,r=e.clone();return P(e,r,function(e,r){t.set(r,e),n.set(e,r)}),r.traverse(function(e){if(!e.isSkinnedMesh)return;let r=e,i=t.get(e),a=i.skeleton.bones;r.skeleton=i.skeleton.clone(),r.bindMatrix.copy(i.bindMatrix),r.skeleton.bones=a.map(function(e){return n.get(e)}),r.bind(r.skeleton,r.bindMatrix)}),r}function j(e,t){for(let n=0,r=M(t);n<r.length;n++)if(e===r[n].name)return r[n]}function M(e){return Array.isArray(e)?e:e.bones}function N(e){let t=new g(e.bones[0]);return t.skeleton=e,t}function P(e,t,n){n(e,t);for(let r=0;r<e.children.length;r++)P(e.children[r],t.children[r],n)}var F={retarget:O,retargetClip:k,clone:A},I={uniforms:{tDiffuse:{value:null},h:{value:1/512}},vertexShader:`
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
  `},R=x(o()),z=R.forwardRef(({scale:e=10,frames:t=1/0,opacity:n=1,width:o=1,height:s=1,blur:c=1,near:u=0,far:f=10,resolution:g=512,smooth:_=!0,color:v=`#000000`,depthWrite:y=!1,renderOrder:b,...x},S)=>{let w=R.useRef(null),T=h(e=>e.scene),E=h(e=>e.gl),D=R.useRef(null);o*=Array.isArray(e)?e[0]:e||1,s*=Array.isArray(e)?e[1]:e||1;let[O,k,A,j,M,N,P]=R.useMemo(()=>{let e=new r(g,g),t=new r(g,g);t.texture.generateMipmaps=e.texture.generateMipmaps=!1;let n=new d(o,s).rotateX(Math.PI/2),c=new l(n),u=new i;u.depthTest=u.depthWrite=!1,u.onBeforeCompile=e=>{e.uniforms={...e.uniforms,ucolor:{value:new a(v)}},e.fragmentShader=e.fragmentShader.replace(`void main() {`,`uniform vec3 ucolor;
           void main() {
          `),e.fragmentShader=e.fragmentShader.replace(`vec4( vec3( 1.0 - fragCoordZ ), opacity );`,`vec4( ucolor * fragCoordZ * 2.0, ( 1.0 - fragCoordZ ) * 1.0 );`)};let f=new m(I),p=new m(L);return p.depthTest=f.depthTest=!1,[e,n,u,c,f,p,t]},[g,o,s,e,v]),F=e=>{j.visible=!0,j.material=M,M.uniforms.tDiffuse.value=O.texture,M.uniforms.h.value=e*1/256,E.setRenderTarget(P),E.render(j,D.current),j.material=N,N.uniforms.tDiffuse.value=P.texture,N.uniforms.v.value=e*1/256,E.setRenderTarget(O),E.render(j,D.current),j.visible=!1},z=0,B,V;return p(()=>{D.current&&(t===1/0||z<t)&&(z++,B=T.background,V=T.overrideMaterial,w.current.visible=!1,T.background=null,T.overrideMaterial=A,E.setRenderTarget(O),E.render(T,D.current),F(c),_&&F(c*.4),E.setRenderTarget(null),w.current.visible=!0,T.overrideMaterial=V,T.background=B)}),R.useImperativeHandle(S,()=>w.current,[]),R.createElement(`group`,C({"rotation-x":Math.PI/2},x,{ref:w}),R.createElement(`mesh`,{renderOrder:b,geometry:k,scale:[1,-1,1],rotation:[-Math.PI/2,0,0]},R.createElement(`meshBasicMaterial`,{transparent:!0,map:O.texture,opacity:n,depthWrite:y})),R.createElement(`orthographicCamera`,{ref:D,args:[-o/2,o/2,s/2,-s/2,u,f]}))}),B=f(),V={cute:{},poddy:{idle:`pose 4 - warm welcome`,trick:`pose 2 - omfg`,talk:`pose 3 - hello`,"point-left":`pose 6 - presentation flipped`,"point-right":`pose 1 - presentation`,walk:`pose 4 - warm welcome`,fly:`pose 2 - omfg`}},H={cute:-Math.PI/2,poddy:0};function U({model:r,mood:i,trickEvery:a}){let o=T.find(e=>e.id===r)??T[0],{scene:s,animations:c}=S(D(o.url)),l=(0,R.useMemo)(()=>F.clone(s),[s]),u=(0,R.useMemo)(()=>{l.updateMatrixWorld(!0);let t=new e().setFromObject(l),n=t.getSize(new _),r=t.getCenter(new _),i=2/Math.max(n.y,.001);return{s:i,x:-r.x*i,y:-t.min.y*i,z:-r.z*i}},[l]),d=(0,R.useRef)(null),f=(0,R.useRef)(null),{actions:m,names:h}=E(c,d),g=(0,R.useRef)(performance.now()+a*1e3),v=(0,R.useRef)(0),y=(0,R.useRef)(null);function b(e,r){let i=e?m[e]:h[0]?m[h[0]]:void 0;i&&(y.current&&y.current!==i&&y.current.fadeOut(.25),i.reset(),i.setLoop(r?t:n,1/0),i.clampWhenFinished=!0,i.fadeIn(.25).play(),y.current=i)}let x=(0,R.useRef)(1/0);return(0,R.useEffect)(()=>{if(r===`cute`){y.current?.stop(),y.current=null,l.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)}),x.current=performance.now()+3e3+Math.random()*3e3;return}b(V[r][i]??V[r].idle,!1)},[i,r,m]),p((e,t)=>{let o=f.current;if(!o)return;let s=e.clock.elapsedTime,c=performance.now();if(r===`cute`&&c>=x.current){x.current=c+3e3+Math.random()*3e3;let e=h.find(e=>/anim|blink/i.test(e))??h[0],t=e?m[e]:void 0;t&&(t.reset(),t.setLoop(n,1),t.clampWhenFinished=!0,t.timeScale=1.25+Math.random()*.25,t.fadeIn(.02).play(),window.setTimeout(()=>{t.fadeOut(.04),l.traverse(e=>{let t=e;t.morphTargetInfluences&&t.morphTargetInfluences.fill(0)})},380))}i===`idle`&&c>g.current&&(g.current=c+a*1e3+Math.random()*2500,v.current=1,V[r].trick&&b(V[r].trick,!1));let u=Math.sin(s*2.2)*.05,d=Math.sin(s*.7)*.12,p=0;if(v.current>0){v.current=Math.max(0,v.current-t/1.3);let e=1-v.current;d=e*Math.PI*2,u+=Math.sin(e*Math.PI)*.45,v.current===0&&V[r].idle&&b(V[r].idle,!1)}i===`walk`&&(u=Math.abs(Math.sin(s*9))*.12,p=Math.sin(s*9)*.08),i===`fly`&&(u=.2+Math.sin(s*6)*.08,p=.25),i===`point-left`&&(d=-.6),i===`point-right`&&(d=.6),i===`talk`&&(u+=Math.abs(Math.sin(s*8))*.04),o.position.y=u,o.rotation.y=H[r]+d,o.rotation.z=p}),(0,B.jsx)(`group`,{ref:f,children:(0,B.jsx)(`group`,{ref:d,position:[u.x,u.y,u.z],scale:u.s,children:(0,B.jsx)(`primitive`,{object:l})})})}function W({model:e,mood:t=`idle`,trickEvery:n=6,className:r,zoom:i=1}){let[a,o]=(0,R.useState)(0),s=()=>window.setTimeout(()=>o(e=>e+1),300);return(0,B.jsx)(`div`,{className:r,"data-robot-stage":e,children:(0,B.jsxs)(w,{dpr:[1,2],gl:{alpha:!0,antialias:!0,preserveDrawingBuffer:!0},camera:{position:[0,1.25,5/i],fov:38},onCreated:({camera:e,gl:t})=>{e.lookAt(0,1.25,0),t.domElement.addEventListener(`webglcontextlost`,e=>{e.preventDefault(),s()})},children:[(0,B.jsx)(`hemisphereLight`,{args:[`#fff6e0`,`#2a2030`,1.6]}),(0,B.jsx)(`ambientLight`,{intensity:.6}),(0,B.jsx)(`directionalLight`,{position:[3,5,4],intensity:1.6}),(0,B.jsx)(`directionalLight`,{position:[-4,2,-2],intensity:.5,color:`#9ecbff`}),(0,B.jsx)(R.Suspense,{fallback:null,children:(0,B.jsx)(U,{model:e,mood:t,trickEvery:n},e)}),(0,B.jsx)(z,{position:[0,0,0],opacity:.45,scale:4,blur:2.4,far:2})]},a)})}export{W as RobotStage,W as default};