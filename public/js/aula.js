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
    let pollTimer;
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
        dot.dataset.kind = kind;
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
            tab.setAttribute('aria-selected', String(selected));
        });
        panels.forEach(panel => {
            panel.hidden = panel.dataset.activityPanel !== state.activity;
        });
    }

    // Las flechas nacen en el centro de la pelota (radio 21 px), así que el largo
    // visible empieza en su borde. La mayor de las dos fuerzas sobresale ARROW_MAX px
    // y la otra en proporción, para comparar empuje y peso a simple vista.
    const BALL_RADIUS = 21;
    const ARROW_MAX = 32;
    const ARROW_MIN = 4;

    function renderArchimedes() {
        const g = config.physics.gravity;
        for (const i of [1, 2]) {
            const fluid = fluids[state['arquimedes_fluido' + i]];
            const object = objects[state['objeto' + i]];
            const mass = Number(state['masa' + i]);
            // El material fija la densidad y la masa fija el volumen: V = m / ρ.
            const volume = mass / object.densidad;
            const weight = mass * g;
            const floating = object.densidad < fluid.densidad;
            const neutral = object.densidad === fluid.densidad;
            // Si flota, se sumerge solo lo necesario para que el empuje iguale al peso.
            const displacedVolume = floating ? volume * object.densidad / fluid.densidad : volume;
            const buoyantForce = fluid.densidad * g * displacedVolume;
            const submergedPart = displacedVolume / volume;

            document.getElementById('arquimedesLiquido' + i).value = state['arquimedes_fluido' + i];
            document.getElementById('selectObjeto' + i).value = state['objeto' + i];
            const massInput = document.getElementById('masa' + i);
            // No se pisa lo que el profesor está escribiendo.
            if (document.activeElement !== massInput) {
                massInput.value = mass;
                massInput.removeAttribute('aria-invalid');
            }
            document.getElementById('arquimedesFill' + i).style.height = '72%';
            document.getElementById('arquimedesFill' + i).style.backgroundColor = fluid.color;

            const ball = document.getElementById('ball' + i);
            ball.style.backgroundColor = object.color;
            ball.style.borderColor = object.densidad > fluid.densidad ? '#4a5961' : '#79503a';
            ball.style.bottom = floating
                ? 'calc(72% - ' + (submergedPart * 42) + 'px)'
                : neutral ? 'calc(36% - 21px)' : '0px';

            const strongest = Math.max(buoyantForce, weight);
            const arrowLength = force => BALL_RADIUS + Math.max(ARROW_MIN, ARROW_MAX * force / strongest) + 'px';
            document.getElementById('flechaEmpuje' + i).style.height = arrowLength(buoyantForce);
            document.getElementById('flechaPeso' + i).style.height = arrowLength(weight);

            document.getElementById('empuje' + i).textContent = number(buoyantForce) + ' N';
            document.getElementById('peso' + i).textContent = number(weight) + ' N';
            document.getElementById('estado' + i).textContent = floating
                ? 'Flota: el empuje iguala al peso'
                : neutral ? 'Queda suspendido: el empuje iguala al peso' : 'Se hunde: el peso es mayor que el empuje';
            document.getElementById('volumen' + i).textContent = 'Volumen del sólido: ' + number(volume * 1000)
                + ' L · sumergido: ' + number(displacedVolume * 1000) + ' L';
        }
    }

    // Devuelve la masa escrita si es válida; si no, marca el campo y mantiene la anterior.
    function massValue(i) {
        const input = document.getElementById('masa' + i);
        const valid = input.value !== '' && input.checkValidity();
        input.setAttribute('aria-invalid', String(!valid));
        return valid ? input.valueAsNumber : state['masa' + i];
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
            // La pelota se sitúa dentro de la parte ocupada por el líquido. Una mayor
            // densidad la acerca a la superficie y una mayor altura aumenta la zona
            // disponible para que se desplace.
            const originalPosition = 85 - (fluid.densidad / 13546) * 80;
            const liquidLevel = height / 2 * 100;
            const ballHeight = 14; // 26 px de los 184 px de alto del vaso, aproximadamente.
            const desiredBottom = liquidLevel * (1 - originalPosition / 100) - 7;
            const ballBottom = Math.max(0, Math.min(desiredBottom, liquidLevel - ballHeight));
            document.getElementById('hidroBall' + i).style.bottom = ballBottom + '%';
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
        } else if (error.status === 422) {
            connection('No se pudo compartir el cambio: datos no válidos.', 'warning');
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
            failed(error);
            // Un 422 no se arregla reintentando el mismo envío: se descarta.
            if (active && error.status !== 422) {
                if (!pending) pending = outgoing;
                clearTimeout(retryTimer);
                retryTimer = setTimeout(publish, 2000);
                return;
            }
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
            masa1: massValue(1),
            masa2: massValue(2),
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
        // Con la pestaña oculta no se consulta; visibilitychange lo reanuda.
        if (document.hidden) return;
        if (config.isOwner && (sending || pending)) {
            pollTimer = setTimeout(poll, 1000);
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
            if (active) pollTimer = setTimeout(poll, 1000);
        }
    }

    document.addEventListener('visibilitychange', () => {
        if (document.hidden || !active) return;
        clearTimeout(pollTimer);
        poll();
    });

    controls.filter(control => !control.classList.contains('activity-tab')).forEach(control => {
        control.addEventListener(control.tagName === 'SELECT' ? 'change' : 'input', changed);
    });
    tabs.forEach(tab => tab.addEventListener('click', () => selectActivity(tab.dataset.activity)));
    // Al salir de un campo de masa inválido se restaura el último valor válido.
    for (const i of [1, 2]) {
        document.getElementById('masa' + i).addEventListener('blur', () => renderArchimedes());
    }

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
