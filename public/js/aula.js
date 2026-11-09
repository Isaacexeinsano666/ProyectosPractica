(() => {
    'use strict';

    const config = window.classroomConfig;
    const fluids = config.physics.fluids;
    const objects = config.physics.objects;
    let state = { ...config.state };
    let revision = config.revision;
    let active = config.active;
    let pending = null;
    let sending = false;
    let debounceTimer;
    let retryTimer;
    let polling = false;
    const controls = [...document.querySelectorAll('.experiment-control')];
    const tabs = [...document.querySelectorAll('.activity-tab')];
    const panels = [...document.querySelectorAll('[data-activity-panel]')];
    const status = document.getElementById('connection-status');
    const dot = document.getElementById('connection-dot');
    const formatter = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = value => formatter.format(value);

    function connection(message, kind = '') {
        status.closest('.connection-block').hidden = kind === '';
        status.textContent = message;
        dot.className = 'live-dot ' + kind;
    }

    function finish() {
        active = false;
        pending = null;
        clearTimeout(retryTimer);
        clearTimeout(debounceTimer);
        controls.forEach(control => { control.disabled = true; });
        document.getElementById('session-banner').hidden = false;
        const end = document.querySelector('.end-session');
        if (end) end.hidden = true;
        connection('La sesión ha finalizado', 'ended');
    }

    function renderTabs() {
        tabs.forEach(tab => {
            const selected = tab.dataset.activity === state.activity;
            tab.classList.toggle('is-active', selected);
            tab.setAttribute('aria-selected', String(selected));
        });
        panels.forEach(panel => {
            panel.hidden = panel.dataset.activityPanel !== state.activity;
        });
    }

    function renderArchimedes() {
        for (const i of [1, 2]) {
            const fluid = fluids[state['arquimedes_fluido' + i]];
            const object = objects[state['objeto' + i]];
            const floating = object.densidad < fluid.densidad;
            const neutral = object.densidad === fluid.densidad;
            const displacedVolume = floating
                ? object.volumen * object.densidad / fluid.densidad
                : object.volumen;
            const buoyantForce = fluid.densidad * config.physics.gravity * displacedVolume;
            const submergedPart = displacedVolume / object.volumen;

            document.getElementById('arquimedesLiquido' + i).value = state['arquimedes_fluido' + i];
            document.getElementById('selectObjeto' + i).value = state['objeto' + i];
            document.getElementById('arquimedesFill' + i).style.height = '72%';
            document.getElementById('arquimedesFill' + i).style.backgroundColor = fluid.color;

            const ball = document.getElementById('ball' + i);
            ball.style.backgroundColor = object.color;
            ball.style.borderColor = object.densidad > fluid.densidad ? '#4a5961' : '#79503a';
            ball.style.bottom = floating
                ? 'calc(72% - ' + (submergedPart * 42) + 'px)'
                : neutral ? 'calc(36% - 21px)' : '0px';

            document.getElementById('empuje' + i).textContent = number(buoyantForce) + ' N';
            document.getElementById('estado' + i).textContent = floating ? 'Flota' : neutral ? 'Queda suspendido' : 'Se hunde';
            document.getElementById('desplazado' + i).textContent = 'Líquido desplazado: ' + number(displacedVolume * 1000) + ' L';
        }
    }

    function renderHydrostatic() {
        for (const i of [1, 2]) {
            const fluid = fluids[state['hidrostatica_fluido' + i]];
            const height = Number(state['altura' + i]);
            const pressure = fluid.densidad * config.physics.gravity * height;

            document.getElementById('hidrostaticaLiquido' + i).value = state['hidrostatica_fluido' + i];
            document.getElementById('alturaLiquido' + i).value = height;
            document.getElementById('valorAltura' + i).textContent = number(height) + ' m';
            document.getElementById('hidrostaticaFill' + i).style.height = (height / 2 * 100) + '%';
            document.getElementById('hidrostaticaFill' + i).style.backgroundColor = fluid.color;
            document.getElementById('presion' + i).textContent = number(pressure) + ' Pa';
            document.getElementById('kilopascales' + i).textContent = number(pressure / 1000) + ' kPa';
        }
    }

    function render() {
        renderTabs();
        renderArchimedes();
        renderHydrostatic();
    }

    async function request(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            cache: 'no-store',
            signal: AbortSignal.timeout(10000),
            ...options,
            headers: {
                'Accept': 'application/json',
                'Content-Type': 'application/json',
                'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                ...options.headers,
            },
        });
        if (!response.ok) {
            const error = new Error('HTTP ' + response.status);
            error.status = response.status;
            throw error;
        }
        return response.json();
    }

    function failed(error) {
        if (error.status === 410) {
            finish();
        } else if ([403, 419, 404].includes(error.status)) {
            active = false;
            pending = null;
            controls.forEach(control => { control.disabled = true; });
            connection('Acceso perdido. Vuelve al inicio e ingresa el código.', 'warning');
        } else {
            connection(config.isOwner ? 'Sin conexión. Los cambios están pendientes.' : 'Conexión interrumpida. Reconectando…', 'warning');
        }
    }

    async function publish() {
        if (!active || sending || !pending) return;
        const outgoing = pending;
        pending = null;
        sending = true;
        connection('Compartiendo cambios…');
        try {
            const snapshot = await request(config.updateUrl, { method: 'PUT', body: JSON.stringify(outgoing) });
            revision = snapshot.revision;
            if (!snapshot.active) finish();
            else connection(pending ? 'Compartiendo cambios…' : 'En vivo · Cambios compartidos');
        } catch (error) {
            if (!pending) pending = outgoing;
            failed(error);
            clearTimeout(retryTimer);
            if (active) retryTimer = setTimeout(publish, 2000);
            return;
        } finally {
            sending = false;
        }
        if (pending && active) publish();
    }

    function queueChanges() {
        pending = { ...state };
        connection('Compartiendo cambios…');
        clearTimeout(retryTimer);
        if (!debounceTimer) {
            debounceTimer = setTimeout(() => {
                debounceTimer = null;
                publish();
            }, 120);
        }
    }

    function changed() {
        if (!config.isOwner || !active) return;
        state = {
            activity: state.activity,
            arquimedes_fluido1: document.getElementById('arquimedesLiquido1').value,
            arquimedes_fluido2: document.getElementById('arquimedesLiquido2').value,
            objeto1: document.getElementById('selectObjeto1').value,
            objeto2: document.getElementById('selectObjeto2').value,
            hidrostatica_fluido1: document.getElementById('hidrostaticaLiquido1').value,
            hidrostatica_fluido2: document.getElementById('hidrostaticaLiquido2').value,
            altura1: Number(document.getElementById('alturaLiquido1').value),
            altura2: Number(document.getElementById('alturaLiquido2').value),
        };
        render();
        queueChanges();
    }

    function selectActivity(activity) {
        if (!config.isOwner || !active || state.activity === activity) return;
        state.activity = activity;
        render();
        queueChanges();
    }

    async function poll() {
        if (!active || polling) return;
        if (config.isOwner && (sending || pending)) {
            setTimeout(poll, 1000);
            return;
        }
        polling = true;
        try {
            const snapshot = await request(config.stateUrl);
            if (!(config.isOwner && (sending || pending))) {
                if (snapshot.revision > revision) {
                    state = snapshot.state;
                    revision = snapshot.revision;
                    render();
                }
                connection(config.isOwner ? 'En vivo · Cambios compartidos' : 'En vivo · Siguiendo al profesor');
            }
            if (!snapshot.active) finish();
        } catch (error) {
            failed(error);
        } finally {
            polling = false;
            if (active) setTimeout(poll, 1000);
        }
    }

    controls.filter(control => !control.classList.contains('activity-tab')).forEach(control => {
        control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', changed);
    });
    tabs.forEach(tab => tab.addEventListener('click', () => selectActivity(tab.dataset.activity)));

    const copy = document.getElementById('copy-code');
    if (copy) copy.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(config.code);
            copy.textContent = 'Código copiado ✓';
            setTimeout(() => { copy.textContent = 'Copiar código ⧉'; }, 2200);
        } catch {
            const selection = window.getSelection();
            const range = document.createRange();
            range.selectNodeContents(document.getElementById('room-code'));
            selection.removeAllRanges();
            selection.addRange(range);
            copy.textContent = 'Código seleccionado · Ctrl+C';
        }
    });

    window.addEventListener('beforeunload', () => {
        if (config.isOwner && active && pending && !sending) {
            fetch(config.updateUrl, {
                method: 'PUT',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content,
                },
                body: JSON.stringify(pending),
            }).catch(() => {});
        }
    });

    render();
    if (active) poll(); else finish();
})();
