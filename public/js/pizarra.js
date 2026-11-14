// Pizarra compartida de la clase.
// aula.js le avisa cuándo se muestra (setActive), cuándo cambió en el servidor
// (onRevision, con el número que llega en cada consulta de /estado) y cuándo terminó la clase (finish).
(() => {
    'use strict';

    const config = window.classroomConfig;
    const urls = config.board;
    const canvas = document.getElementById('board');
    if (!canvas) return;

    const ctx = canvas.getContext('2d');
    const toastEl = document.getElementById('board-toast');
    const chip = document.getElementById('board-chip');
    const handBtn = document.getElementById('board-hand');
    const undoBtn = document.getElementById('board-undo');
    const clearBtn = document.getElementById('board-clear');
    const toolBtns = [...document.querySelectorAll('.board-tool')];
    const colorBtns = [...document.querySelectorAll('.board-color')];
    const studentsList = document.getElementById('board-students');
    const openInput = document.getElementById('board-open');

    // Estado recibido del servidor.
    let revision = -1;
    let strokes = [];
    let me = { is_owner: config.isOwner, can_draw: config.isOwner, can_moderate: config.isOwner, hand_raised: false };
    let students = [];
    let open = false;

    // Estado local.
    let visible = false;
    let ended = !config.active;
    let fetching = false;
    let refetch = false;
    let tool = 'pen';
    let color = colorBtns[0]?.dataset.color || '#1f2a2e';
    let showAuthors = true;
    let current = null;            // trazo que se está dibujando
    const pending = [];            // trazos enviados que el servidor aún no confirma
    const hiding = new Set();      // trazos borrados que el servidor aún no confirma
    let erasing = false;
    let eraseWarned = false;
    let W = 0, H = 0, dpr = 1;

    const MAX_POINTS = 1500;
    const TINTS = ['#2563c9', '#1a8a5a', '#b4442f', '#7a3fc4', '#a86a00', '#0f7c8c', '#c2416b'];

    // ---------- Servidor ----------
    async function send(url, method = 'GET', body) {
        const response = await fetch(url, {
            method,
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(10000),
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
            },
            body: body === undefined ? undefined : JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            const error = new Error(data.message || 'HTTP ' + response.status);
            error.status = response.status;
            throw error;
        }
        return data;
    }

    function failed(error) {
        if (error.status === 410) { finish(); return; }
        if ([403, 422, 429].includes(error.status) && error.message && !error.message.startsWith('HTTP')) {
            toast(error.message);
        } else if (error.status === 429) {
            toast('Vas muy rápido. Espera un momento.');
        } else {
            toast('No se pudo guardar en la pizarra. Revisa la conexión.');
        }
    }

    async function load() {
        if (fetching) { refetch = true; return; }
        fetching = true;
        try {
            apply(await send(urls.url));
        } catch (error) {
            if (error.status === 410) finish();
        } finally {
            fetching = false;
            if (refetch) { refetch = false; load(); }
        }
    }

    function apply(snapshot) {
        revision = snapshot.revision;
        strokes = snapshot.strokes;
        me = snapshot.me;
        open = snapshot.open;
        if (snapshot.students) students = snapshot.students;
        // Lo que ya no existe en el servidor deja de estar "borrándose".
        for (const id of [...hiding]) if (!strokes.some(s => s.id === id)) hiding.delete(id);
        if (!snapshot.active) finish();
        renderControls();
        renderStudents();
        draw();
    }

    // ---------- Dibujo ----------
    function resize() {
        const rect = canvas.getBoundingClientRect();
        if (!rect.width) return;
        dpr = window.devicePixelRatio || 1;
        W = Math.round(rect.width * dpr);
        H = Math.round(rect.height * dpr);
        canvas.width = W;
        canvas.height = H;
        draw();
    }
    // Grosor proporcional al ancho: todos ven la pizarra igual en cualquier pantalla.
    const unit = () => W / 1000;

    function tintFor(name) {
        if (name === 'Profesor') return '#172e37';
        let hash = 0;
        for (const ch of name) hash = (hash * 31 + ch.codePointAt(0)) >>> 0;
        return TINTS[hash % TINTS.length];
    }

    function drawStroke(s) {
        const k = unit();
        const pts = s.points;
        ctx.strokeStyle = s.color;
        ctx.fillStyle = s.color;
        ctx.lineWidth = 4 * k;
        ctx.lineCap = 'round';
        ctx.lineJoin = 'round';
        if (pts.length === 1) {
            ctx.beginPath();
            ctx.arc(pts[0][0] * W, pts[0][1] * H, ctx.lineWidth / 2, 0, Math.PI * 2);
            ctx.fill();
            return;
        }
        ctx.beginPath();
        ctx.moveTo(pts[0][0] * W, pts[0][1] * H);
        for (let i = 1; i < pts.length; i++) ctx.lineTo(pts[i][0] * W, pts[i][1] * H);
        ctx.stroke();
        if (s.type === 'arrow') {
            const [a, b] = [pts[pts.length - 2], pts[pts.length - 1]];
            const ang = Math.atan2((b[1] - a[1]) * H, (b[0] - a[0]) * W);
            const len = 16 * k;
            ctx.beginPath();
            for (const side of [-.45, .45]) {
                ctx.moveTo(b[0] * W, b[1] * H);
                ctx.lineTo(b[0] * W - len * Math.cos(ang + side), b[1] * H - len * Math.sin(ang + side));
            }
            ctx.stroke();
        }
    }

    function drawLabel(s) {
        const last = s.points[s.points.length - 1];
        ctx.font = `600 ${10.5 * dpr}px Inter, "Segoe UI", sans-serif`;
        ctx.textBaseline = 'middle';
        const w = ctx.measureText(s.author).width + 10 * dpr;
        const h = 16 * dpr;
        const x = Math.min(Math.max(last[0] * W + 6 * dpr, 2), W - w - 2);
        const y = Math.min(Math.max(last[1] * H - 6 * dpr - h / 2, 2), H - h - 2);
        ctx.globalAlpha = .9;
        ctx.fillStyle = tintFor(s.author);
        ctx.beginPath();
        ctx.roundRect(x, y, w, h, 5 * dpr);
        ctx.fill();
        ctx.globalAlpha = 1;
        ctx.fillStyle = '#ffffff';
        ctx.fillText(s.author, x + 5 * dpr, y + h / 2 + .5);
    }

    function visibleStrokes() {
        return [...strokes.filter(s => !hiding.has(s.id)), ...pending, ...(current ? [current] : [])];
    }

    function draw() {
        if (!W) return;
        ctx.setTransform(1, 0, 0, 1, 0, 0);
        ctx.clearRect(0, 0, W, H);
        // Cuadrícula de puntos suave, como una pizarra de laboratorio.
        const step = W / 40;
        ctx.fillStyle = '#e1e8e4';
        for (let x = step; x < W; x += step) for (let y = step; y < H; y += step) ctx.fillRect(x - dpr, y - dpr, 2 * dpr, 2 * dpr);
        const all = visibleStrokes();
        all.forEach(drawStroke);
        if (showAuthors) all.forEach((s, i) => { if (!all[i + 1] || all[i + 1].author !== s.author) drawLabel(s); });
    }

    // ---------- Puntero ----------
    function pointFrom(e) {
        const r = canvas.getBoundingClientRect();
        return [
            Math.min(1, Math.max(0, (e.clientX - r.left) / r.width)),
            Math.min(1, Math.max(0, (e.clientY - r.top) / r.height)),
        ];
    }

    function distToSegment(p, a, b) {
        const dx = b[0] - a[0], dy = b[1] - a[1];
        const t = dx || dy ? Math.max(0, Math.min(1, ((p[0] - a[0]) * dx + (p[1] - a[1]) * dy) / (dx * dx + dy * dy))) : 0;
        return Math.hypot(p[0] - (a[0] + t * dx), p[1] - (a[1] + t * dy));
    }

    function hits(s, p) {
        const tol = 10 * dpr + 2 * unit();
        const px = [p[0] * W, p[1] * H];
        const pts = s.points.map(q => [q[0] * W, q[1] * H]);
        if (pts.length === 1) return Math.hypot(px[0] - pts[0][0], px[1] - pts[0][1]) <= tol;
        for (let i = 1; i < pts.length; i++) if (distToSegment(px, pts[i - 1], pts[i]) <= tol) return true;
        return false;
    }

    function eraseAt(p) {
        for (const s of strokes) {
            if (hiding.has(s.id) || !hits(s, p)) continue;
            if (s.mine || me.can_moderate) {
                removeStroke(s.id);
            } else if (!eraseWarned) {
                eraseWarned = true;
                toast(`Solo puedes borrar tus propios trazos. Este es de ${s.author}.`);
            }
        }
    }

    async function removeStroke(id) {
        hiding.add(id);
        draw();
        try {
            apply(await send(urls.strokeUrl.replace('__ID__', id), 'DELETE'));
        } catch (error) {
            hiding.delete(id);
            draw();
            if (error.status !== 404) failed(error);
        }
    }

    canvas.addEventListener('pointerdown', e => {
        if (ended) return;
        if (!me.can_draw) {
            toast(me.hand_raised
                ? 'Ya levantaste la mano. Espera a que el profesor te dé permiso.'
                : 'Aún no puedes dibujar. Levanta la mano para pedir permiso.');
            return;
        }
        canvas.setPointerCapture(e.pointerId);
        const p = pointFrom(e);
        if (tool === 'eraser') {
            erasing = true;
            eraseWarned = false;
            eraseAt(p);
            return;
        }
        current = { author: me.name || 'Tú', mine: true, type: tool, color, points: tool === 'arrow' ? [p, p] : [p] };
        draw();
    });

    canvas.addEventListener('pointermove', e => {
        if (erasing) { eraseAt(pointFrom(e)); return; }
        if (!current) return;
        const p = pointFrom(e);
        if (current.type === 'arrow') {
            current.points[1] = p;
        } else if (current.points.length < MAX_POINTS) {
            const last = current.points[current.points.length - 1];
            if (Math.hypot((p[0] - last[0]) * W, (p[1] - last[1]) * H) > 2 * dpr) current.points.push(p);
        }
        draw();
    });

    async function endPointer() {
        erasing = false;
        if (!current) return;
        const s = current;
        current = null;
        const [a, b] = [s.points[0], s.points[s.points.length - 1]];
        if (s.type === 'arrow' && Math.hypot((b[0] - a[0]) * W, (b[1] - a[1]) * H) < 12 * dpr) { draw(); return; }
        // Se muestra al instante y se confirma con el servidor.
        pending.push(s);
        draw();
        try {
            const snapshot = await send(urls.strokesUrl, 'POST', {
                type: s.type,
                color: s.color,
                points: s.points.map(([x, y]) => [Math.round(x * 1e4) / 1e4, Math.round(y * 1e4) / 1e4]),
            });
            pending.splice(pending.indexOf(s), 1);
            apply(snapshot);
        } catch (error) {
            pending.splice(pending.indexOf(s), 1);
            draw();
            failed(error);
        }
    }
    canvas.addEventListener('pointerup', endPointer);
    canvas.addEventListener('pointercancel', endPointer);

    // ---------- Controles ----------
    toolBtns.forEach(b => b.addEventListener('click', () => { tool = b.dataset.tool; renderControls(); }));
    colorBtns.forEach(b => b.addEventListener('click', () => {
        color = b.dataset.color;
        if (tool === 'eraser') tool = 'pen';
        renderControls();
    }));

    undoBtn.addEventListener('click', () => {
        const mine = strokes.filter(s => s.mine && !hiding.has(s.id));
        if (mine.length) removeStroke(mine[mine.length - 1].id);
    });

    let clearTimer;
    function disarmClear() {
        clearTimeout(clearTimer);
        delete clearBtn.dataset.armed;
        clearBtn.textContent = 'Borrar todo';
    }
    clearBtn.addEventListener('click', async () => {
        if (!('armed' in clearBtn.dataset)) {
            clearBtn.dataset.armed = '';
            clearBtn.textContent = '¿Seguro? Pulsa otra vez';
            clearTimer = setTimeout(disarmClear, 3000);
            return;
        }
        disarmClear();
        try {
            apply(await send(urls.url, 'DELETE'));
        } catch (error) {
            failed(error);
        }
    });

    handBtn?.addEventListener('click', async () => {
        try {
            apply(await send(urls.handUrl, 'POST', { raised: !me.hand_raised }));
        } catch (error) {
            failed(error);
        }
    });

    openInput?.addEventListener('change', async () => {
        try {
            apply(await send(urls.openUrl, 'PUT', { open: openInput.checked }));
        } catch (error) {
            openInput.checked = open;
            failed(error);
        }
    });

    document.getElementById('board-authors').addEventListener('change', e => { showAuthors = e.target.checked; draw(); });

    function renderControls() {
        const allowed = me.can_draw && !ended;
        toolBtns.forEach(b => {
            b.disabled = !allowed;
            b.setAttribute('aria-pressed', String(allowed && b.dataset.tool === tool));
        });
        colorBtns.forEach(b => {
            b.disabled = !allowed;
            b.setAttribute('aria-pressed', String(b.dataset.color === color && tool !== 'eraser'));
        });
        if (ended) {
            chip.textContent = 'Clase finalizada';
            delete chip.dataset.can;
        } else if (me.is_owner) {
            chip.textContent = 'Profesor: control total';
            chip.dataset.can = '';
        } else if (me.can_moderate) {
            chip.textContent = 'Puedes dibujar y borrar todo';
            chip.dataset.can = '';
        } else if (allowed) {
            chip.textContent = open ? 'Pizarra libre: puedes dibujar' : 'Puedes dibujar';
            chip.dataset.can = '';
        } else {
            chip.textContent = me.hand_raised ? 'Esperando permiso del profesor' : 'Solo lectura';
            delete chip.dataset.can;
        }
        if (handBtn) {
            handBtn.hidden = allowed || ended;
            handBtn.textContent = me.hand_raised ? 'Bajar la mano' : 'Levantar la mano para dibujar';
        }
        undoBtn.disabled = !allowed || !strokes.some(s => s.mine && !hiding.has(s.id));
        clearBtn.hidden = !me.can_moderate || ended;
        if (clearBtn.hidden) disarmClear();
        if (openInput) {
            openInput.checked = open;
            openInput.disabled = ended;
        }
        canvas.toggleAttribute('data-readonly', !allowed);
        canvas.toggleAttribute('data-eraser', allowed && tool === 'eraser');
    }

    function renderStudents() {
        if (!studentsList) return;
        // Ordena: primero quien pide permiso, luego los conectados.
        const sorted = [...students].sort((a, b) => (b.hand_raised - a.hand_raised) || (b.online - a.online) || a.id - b.id);
        studentsList.replaceChildren(...sorted.map(student => {
            const li = document.createElement('li');
            li.className = 'grid gap-2 rounded-[10px] border p-2.5 ' + (student.hand_raised ? 'border-[#e5b253] bg-[#fbf0d9]' : 'border-line');
            const top = document.createElement('div');
            top.className = 'flex min-w-0 items-center gap-2';
            const dot = document.createElement('span');
            dot.className = 'size-2 shrink-0 rounded-full ' + (student.online ? 'bg-[#3cad87]' : 'bg-[#c3cfca]');
            dot.title = student.online ? 'Conectado' : 'Desconectado';
            const name = document.createElement('span');
            name.className = 'min-w-0 truncate text-[13px] font-[650]';
            name.textContent = student.name;
            top.append(dot, name);
            if (student.hand_raised) {
                const hand = document.createElement('span');
                hand.className = 'ml-auto shrink-0 rounded-full bg-white px-2 py-0.5 text-[11px] font-bold text-[#9a6200]';
                hand.textContent = 'Pide permiso';
                top.append(hand);
            }
            const perms = document.createElement('div');
            perms.className = 'flex flex-wrap gap-x-4 gap-y-1';
            perms.append(
                permToggle(student, 'can_draw', 'Dibujar'),
                permToggle(student, 'can_moderate', 'Borrar todo'),
            );
            li.append(top, perms);
            return li;
        }));
    }

    function permToggle(student, field, text) {
        const label = document.createElement('label');
        label.className = 'm-0 inline-flex items-center gap-1.5 text-[12px] font-medium';
        const input = document.createElement('input');
        input.type = 'checkbox';
        input.className = 'size-[15px] accent-teal';
        input.id = `perm-${field}-${student.id}`;
        input.checked = student[field];
        input.disabled = ended;
        label.htmlFor = input.id;
        input.addEventListener('change', async () => {
            const next = { can_draw: student.can_draw, can_moderate: student.can_moderate, [field]: input.checked };
            if (field === 'can_draw' && !input.checked) next.can_moderate = false;
            try {
                apply(await send(urls.permissionsUrl.replace('__ID__', student.id), 'PUT', next));
            } catch (error) {
                input.checked = student[field];
                failed(error);
            }
        });
        label.append(input, document.createTextNode(text));
        return label;
    }

    let toastTimer;
    function toast(text) {
        toastEl.textContent = text;
        toastEl.hidden = false;
        clearTimeout(toastTimer);
        toastTimer = setTimeout(() => { toastEl.hidden = true; }, 3000);
    }

    function finish() {
        ended = true;
        current = null;
        erasing = false;
        renderControls();
        renderStudents();
    }

    new ResizeObserver(resize).observe(canvas);
    renderControls();

    window.classroomBoard = {
        setActive(on) {
            // Solo actúa al aparecer: aula.js la llama en cada renderizado.
            if (!on || visible) { visible = on; return; }
            visible = true;
            requestAnimationFrame(resize);
            load();
        },
        onRevision(next) {
            if (next !== revision && visible) load();
        },
        finish,
    };
})();
