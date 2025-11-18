(function(){
  // Helpers
  function ajax(action, data){
    const fd = new FormData();
    fd.append('action', action);
    fd.append('_ajax_nonce', IWPV3.nonce);
    for (const k in (data||{})) fd.append(k, data[k]);
    return fetch(IWPV3.ajax, { method:'POST', credentials:'same-origin', body: fd }).then(r=>r.json());
  }
  const h = React.createElement;
  const { useEffect, useRef, useState } = React;

  // Pan & Zoom controller (mouse + touch)
  function usePanZoom(stageRef, stageWidth, stageHeight){
    const [state, setState] = useState(()=>{
      // Calculate initial centered position
      const containerWidth = window.innerWidth;
      const containerHeight = window.innerHeight - 100; // Account for toolbar and margins
      const tx = Math.max(0, (containerWidth - stageWidth) / 2);
      const ty = Math.max(0, (containerHeight - stageHeight) / 2);
      return {scale:1, tx, ty};
    });
    useEffect(()=>{
      const container = stageRef.current?.parentElement; if (!container) return;
      let dragging=false, sx=0, sy=0, stx=0, sty=0, pinch=false, sd=0, ss=1;
      const onWheel = (e)=>{
        e.preventDefault();
        const delta = -e.deltaY;
        let ns = state.scale * (delta>0?1.05:0.95);
        ns = Math.max(0.4, Math.min(ns, 3));
        setState(s=>({...s, scale: ns}));
      };
      const onDown = (e)=>{
        if (e.target.closest('.iwp-note') || e.target.closest('.iwp-editor') || e.target.closest('.iwp-toolbar')) return;
        if (e.touches && e.touches.length > 1) return; // Skip if multi-touch (for pinch)
        dragging=true; pinch=false;
        sx = (e.touches?e.touches[0].clientX:e.clientX);
        sy = (e.touches?e.touches[0].clientY:e.clientY);
        stx = state.tx; sty = state.ty;
        if (e.cancelable) e.preventDefault();
      };
      const onMove = (e)=>{
        if (e.touches && e.touches.length===2){ // pinch
          e.preventDefault();
          const [a,b] = e.touches;
          const d = Math.hypot(a.clientX-b.clientX, a.clientY-b.clientY);
          if (!pinch){ pinch=true; sd=d; ss=state.scale; dragging=false; }
          const ns = Math.max(0.4, Math.min( (d/sd)*ss , 3));
          setState(s=>({...s, scale: ns}));
          return;
        }
        if(!dragging) return;
        if (e.cancelable) e.preventDefault();
        const cx = (e.touches?e.touches[0].clientX:e.clientX);
        const cy = (e.touches?e.touches[0].clientY:e.clientY);
        setState(s=>({...s, tx: stx + (cx-sx), ty: sty + (cy-sy)}));
      };
      const onUp = ()=>{ dragging=false; pinch=false; };
      container.addEventListener('wheel', onWheel, {passive:false});
      container.addEventListener('mousedown', onDown);
      container.addEventListener('touchstart', onDown, {passive:false});
      window.addEventListener('mousemove', onMove);
      window.addEventListener('touchmove', onMove, {passive:false});
      window.addEventListener('mouseup', onUp);
      window.addEventListener('touchend', onUp);
      return ()=>{
        container.removeEventListener('wheel', onWheel);
        container.removeEventListener('mousedown', onDown);
        container.removeEventListener('touchstart', onDown);
        window.removeEventListener('mousemove', onMove);
        window.removeEventListener('touchmove', onMove);
        window.removeEventListener('mouseup', onUp);
        window.removeEventListener('touchend', onUp);
      };
    }, [stageRef, state.scale, state.tx, state.ty]);
    return state;
  }

  function Toolbar({theme, setTheme, color, setColor, font, setFont, size, setSize, style, setStyle}){
    return h('div', {className:'iwp-toolbar'},
      h('button', {className:'iwp-btn', title:'Tema', onClick:()=>setTheme(theme==='dark'?'light':(theme==='light'?'auto':'dark'))}, '☯️'),
      h('input', {type:'color', className:'iwp-color', value:color, onChange:e=>setColor(e.target.value), title:'Color nota'}),
      h('select', {className:'iwp-select', value:font, onChange:e=>setFont(e.target.value), title:'Fuente'},
        h('option',{value:'system-ui'},'Sistema'),
        h('option',{value:'Arial, Helvetica, sans-serif'},'Arial'),
        h('option',{value:'Georgia, serif'},'Georgia'),
        h('option',{value:'"Courier New", monospace'},'Courier'),
        h('option',{value:'Impact, sans-serif'},'Impact'),
      ),
      h('select', {className:'iwp-select', value:size, onChange:e=>setSize(parseInt(e.target.value,10)||25), title:'Tamaño'},
        [14,16,18,20,22,24,25,28,32].map(n=>h('option',{key:n,value:n}, n+'px'))
      ),
      h('select', {className:'iwp-select', value:style, onChange:e=>setStyle(e.target.value), title:'Estilo'},
        h('option',{value:'postit'},'Post-it'),
        h('option',{value:'minimal'},'Minimal (texto)'),
        h('option',{value:'bubble'},'Burbuja')
      ),
      h('button', {className:'iwp-btn', title:'Emoji', onClick:()=>{
        const e = prompt('Escribe un emoji (😀🔥👍✨❤️):');
        if (!e) return;
        const ed = document.querySelector('.iwp-editor[contenteditable="true"]');
        if (ed){ ed.textContent += e; ed.focus(); placeCaretEnd(ed); }
      }}, '😃')
    );
  }

  function placeCaretEnd(el) {
    el.focus();
    if (typeof window.getSelection != "undefined"
        && typeof document.createRange != "undefined") {
      var range = document.createRange();
      range.selectNodeContents(el);
      range.collapse(false);
      var sel = window.getSelection();
      sel.removeAllRanges();
      sel.addRange(range);
    }
  }

  function Note({item, isAdmin, onMove, onResize, onEdit, theme}){
    const ref = useRef(null);
    const [drag, setDrag] = useState(false);
    const [resizing, setResizing] = useState(false);

    useEffect(()=>{
      const el = ref.current; if (!el) return;
      el.style.left = item.x+'px'; el.style.top = item.y+'px';
      el.style.width = item.w+'px'; el.style.height = item.h+'px';
      el.style.setProperty('--iwp-size', item.size+'px');
      el.style.setProperty('--iwp-postit', item.color);
    }, [item]);

    const onDown = (e)=>{
      if (!isAdmin) return;
      if (e.target.classList.contains('iwp-resize')){
        setResizing(true);
      } else {
        setDrag(true);
      }
      e.preventDefault();
      const el = ref.current;
      el.dataset.sx = e.clientX; el.dataset.sy = e.clientY;
      el.dataset.x = parseInt(el.style.left||'0',10);
      el.dataset.y = parseInt(el.style.top||'0',10);
      el.dataset.w = parseInt(el.style.width||item.w,10);
      el.dataset.h = parseInt(el.style.height||item.h,10);
      document.body.style.userSelect='none';
    };
    const onMoveEvt = (e)=>{
      const el = ref.current; if (!el) return;
      if (!drag && !resizing) return;
      const dx = e.clientX - parseFloat(el.dataset.sx);
      const dy = e.clientY - parseFloat(el.dataset.sy);
      if (drag){
        el.style.left = (parseFloat(el.dataset.x) + dx) + 'px';
        el.style.top  = (parseFloat(el.dataset.y) + dy) + 'px';
      } else if (resizing){
        el.style.width  = Math.max(120, parseFloat(el.dataset.w) + dx) + 'px';
        el.style.height = Math.max(64,  parseFloat(el.dataset.h) + dy) + 'px';
      }
    };
    const onUp = ()=>{
      const el = ref.current; if (!el) return;
      if (drag){
        setDrag(false);
        onMove && onMove(item.id, parseInt(el.style.left), parseInt(el.style.top));
      }
      if (resizing){
        setResizing(false);
        onResize && onResize(item.id, parseInt(el.style.width), parseInt(el.style.height));
      }
      document.body.style.userSelect='';
    };
    useEffect(()=>{
      window.addEventListener('mousemove', onMoveEvt);
      window.addEventListener('mouseup', onUp);
      return ()=>{
        window.removeEventListener('mousemove', onMoveEvt);
        window.removeEventListener('mouseup', onUp);
      };
    });

    return h('div', {
      ref, className:`iwp-note ${item.style||'postit'} ${drag?'drag':''}`,
      onMouseDown: onDown,
      onDoubleClick: ()=>{ if (isAdmin){ const t=prompt('Editar texto:', item.text); if (t!=null){ onEdit(item.id,t); } } }
    },
      h('div', {className:'iwp-meta'}, `${item.author||'Anónimo'} · ${new Date(item.date*1000).toLocaleString()}`),
      h('div', {className:'iwp-body', style:{fontFamily:item.font}}, item.text),
      isAdmin && h('div', {className:'iwp-resize'})
    );
  }

  function App({root}){
    const wallId = parseInt(root.dataset.wall,10);
    const width = parseInt(root.dataset.width,10);
    const height= parseInt(root.dataset.height,10);
    const bgc = root.dataset.bgc || '#f5f7fb';
    const bgi = root.dataset.bgi || '';
    const [items, setItems] = useState([]);
    const [theme, setTheme] = useState(root.classList.contains('iwp-theme-dark')?'dark':(root.classList.contains('iwp-theme-light')?'light':'auto'));
    const [color, setColor] = useState('#ffffff');
    const [font, setFont] = useState('system-ui');
    const [size, setSize] = useState(25);
    const [style, setStyle] = useState('postit');
    const isAdmin = !!IWPV3.isAdmin;

    const containerRef = useRef(null);
    const stageRef = useRef(null);
    const pz = usePanZoom(stageRef, width, height);

    const load = ()=> ajax('iwp_v3_list', {wall: wallId}).then(res=>{ if(res && res.success) setItems(res.data.items||[]); });
    useEffect(load, [wallId]);

    // Polling each 10s
    useEffect(()=>{
      const t = setInterval(load, 10000);
      return ()=>clearInterval(t);
    }, [wallId]);

    useEffect(()=>{
      root.classList.remove('iwp-theme-auto','iwp-theme-light','iwp-theme-dark');
      root.classList.add('iwp-theme-'+theme);
    }, [theme]);

    // Click to create inline editor
    const editorRef = useRef(null);
    const onCanvasClick = (e)=>{
      if (e.target.closest('.iwp-note') || e.target.closest('.iwp-editor') || e.target.closest('.iwp-toolbar')) return;
      const bounds = stageRef.current.getBoundingClientRect();
      const x = (e.clientX - bounds.left - pz.tx) / pz.scale;
      const y = (e.clientY - bounds.top - pz.ty) / pz.scale;

      const ed = document.createElement('div');
      ed.className = 'iwp-editor';
      ed.setAttribute('contenteditable','true');
      ed.setAttribute('data-placeholder','Escribe aquí…');
      ed.style.left = x+'px'; ed.style.top = y+'px';
      ed.style.width = '240px'; ed.style.height='90px';
      stageRef.current.appendChild(ed);
      editorRef.current = ed;
      ed.focus();

      const commit = ()=>{
        const text = ed.textContent.trim();
        if (text){
          ajax('iwp_v3_add', {wall: wallId, text, x: Math.round(x), y: Math.round(y), w:240, h:90, color, font, size, style})
            .then(res=>{ if(res && res.success){ setItems([{id:res.data.id,text, x:Math.round(x), y:Math.round(y), w:240, h:90, color, font, size, style, author:'Anónimo', date:Math.floor(Date.now()/1000)} , ...items]); } });
        }
        ed.remove(); editorRef.current=null;
      };
      ed.addEventListener('keydown', (ev)=>{
        if (ev.key==='Enter' && !ev.shiftKey){ ev.preventDefault(); commit(); }
        if (ev.key==='Escape'){ ev.preventDefault(); ed.remove(); editorRef.current=null; }
      });
      ed.addEventListener('blur', commit, {once:true});
    };

    const styleCanvas = {
      backgroundColor: bgi ? 'transparent' : bgc,
      backgroundImage: bgi ? `url(${bgi})` : 'none',
    };

    return h(React.Fragment, null,
      h(Toolbar, {theme,setTheme,color,setColor,font,setFont,size,setSize,style,setStyle}),
      h('div', {className:'iwp-canvas', ref:containerRef, style:styleCanvas},
        h('div', {
            className:'iwp-stage',
            ref:stageRef,
            style:{ '--iwp-w': width+'px', '--iwp-h': height+'px', transform:`translate(${pz.tx}px, ${pz.ty}px) scale(${pz.scale})` },
            onClick:onCanvasClick
          },
          items.map(it => h(Note, {
            key: it.id, item: it, isAdmin,
            onMove: (id,x,y)=>ajax('iwp_v3_move',{id,x,y}),
            onResize: (id,w,h)=>ajax('iwp_v3_resize',{id,w,h}),
            onEdit: (id,text)=>ajax('iwp_v3_update_text',{id,text})
          }))
        )
      )
    );
  }

  function mount(){
    document.querySelectorAll('.iwp-root').forEach(root=>{
      const mountEl = root.querySelector('[id^="iwp-app-"]');
      ReactDOM.createRoot(mountEl).render(h(App, {root}));
    });
  }
  document.addEventListener('DOMContentLoaded', mount);
})();