(() => {
    'use strict';
    const config = window.classroomConfig;
    const fluids = config.physics.fluids;
    let state = { ...config.state };
    let revision = config.revision;
    let active = config.active;
    let pending = null;
    let sending = false;
    let debounceTimer;
    let retryTimer;
    let polling = false;
    const controls = [...document.querySelectorAll('.experiment-control')];
    const status = document.getElementById('connection-status');
    const dot = document.getElementById('connection-dot');
    const formatter = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = value => formatter.format(value);

    function connection(message, kind = '') {
        status.closest('.connection-block').hidden = kind === '';
        status.textContent = message;
        dot.className = `live-dot ${kind}`;
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

    function render() {
        for (const i of [1, 2]) {
            const fluid = fluids[state[`fluido${i}`]];
            const height = Number(state[`altura${i}`]);
            const pressure = fluid.densidad * config.physics.gravity * height;
            document.getElementById(`selectLiquido${i}`).value = state[`fluido${i}`];
            document.getElementById(`alturaLiquido${i}`).value = height;
            document.getElementById(`valorAltura${i}`).textContent = `${number(height)} m`;
            document.getElementById(`fill${i}`).style.height = `${height / 2 * 100}%`;
            document.getElementById(`fill${i}`).style.backgroundColor = fluid.color;
            // Recover the original density-based movement within the liquid.
            const position = 85 - (fluid.densidad / 13546) * 80;
            const rise = 1 - position / 100;
            document.getElementById(`ball${i}`).style.bottom = `max(0px, calc(${height / 2 * 100 * rise}% - ${26 * rise}px))`;
            document.getElementById(`presion${i}`).textContent = `${number(pressure)} Pa`;
            document.getElementById(`kilopascales${i}`).textContent = `${number(pressure / 1000)} kPa`;
        }
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
            const error = new Error(`HTTP ${response.status}`);
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

    function changed() {
        if (!config.isOwner || !active) return;
        state = {
            fluido1: document.getElementById('selectLiquido1').value,
            fluido2: document.getElementById('selectLiquido2').value,
            altura1: Number(document.getElementById('alturaLiquido1').value),
            altura2: Number(document.getElementById('alturaLiquido2').value),
        };
        render();
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

    async function poll() {
        if (!active || polling) return;
        // A teacher must not overwrite unsent local edits with an older snapshot.
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

    controls.forEach(control => control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', changed));
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
    // Best effort only: the normal write queue remains the source of truth.
    window.addEventListener('beforeunload', () => {
        if (config.isOwner && active && pending && !sending) {
            fetch(config.updateUrl, {
                method: 'PUT', credentials: 'same-origin', keepalive: true,
                headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content },
                body: JSON.stringify(pending),
            }).catch(() => {});
        }
    });
    render();
    if (active) poll(); else finish();
})();
