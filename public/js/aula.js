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
    // Momento (reloj local) en que la clase se cerrará por inactividad. Se calcula a
    // partir de los segundos que informa el servidor, para no depender de la hora del PC.
    let deadline = Date.now() + config.expiresIn * 1000;
    const idleTimer = document.getElementById('idle-timer');
    const status = document.getElementById('connection-status');
    const dot = document.getElementById('connection-dot');
    const formatter = new Intl.NumberFormat('es-CL', { minimumFractionDigits: 2, maximumFractionDigits: 2 });
    const number = value => formatter.format(value);

    function connection(message, kind = '') {
        status.closest('.connection-block').hidden = kind === '';
        status.textContent = message;
        dot.dataset.kind = kind;
    }

    function renderIdleTimer() {
        if (!idleTimer) return;
        idleTimer.hidden = !active;
        const minutes = Math.max(0, Math.ceil((deadline - Date.now()) / 60000));
        const warning = minutes <= 5;
        idleTimer.toggleAttribute('data-warning', warning);
        idleTimer.textContent = warning
            ? 'La clase se cerrará por inactividad en ' + minutes + ' min. Haz un cambio para mantenerla abierta.'
            : 'Cierre automático por inactividad en ' + minutes + ' min';
    }

    function updateDeadline(snapshot) {
        deadline = Date.now() + snapshot.expires_in * 1000;
        renderIdleTimer();
        if (snapshot.students) renderStudents(snapshot.students);
        // La pizarra vuelve a pedir sus trazos solo si cambiaron.
        window.classroomBoard?.onRevision(snapshot.board_revision);
    }

    // Botón del panel lateral (solo profesor): lleva a toda la clase a la pizarra y de vuelta.
    const boardToggle = document.getElementById('board-toggle');
    let lastExperiment = state.activity === 'pizarra' ? 'arquimedes' : state.activity;

    // Solo existe para el profesor; el servidor no envía el conteo a los alumnos.
    const studentCount = document.getElementById('student-count');

    function renderStudents({ online, joined }) {
        if (!studentCount) return;
        document.getElementById('student-online').textContent =
            online === 1 ? '1 alumno conectado' : online + ' alumnos conectados';
        document.getElementById('student-joined').textContent = joined === 0
            ? 'Aún no se ha unido nadie. Comparte el código.'
            : joined === 1 ? '1 se unió en total' : joined + ' se unieron en total';
    }

    function finish() {
        active = false;
        if (idleTimer) idleTimer.hidden = true;
        if (studentCount) studentCount.hidden = true;
        if (boardToggle) boardToggle.hidden = true;
        window.classroomBoard?.finish();
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
        const onBoard = state.activity === 'pizarra';
        if (!onBoard) lastExperiment = state.activity;
        if (boardToggle) {
            boardToggle.setAttribute('aria-pressed', String(onBoard));
            document.getElementById('board-toggle-label').textContent =
                onBoard ? 'Volver al experimento' : 'Abrir pizarra para la clase';
        }
        window.classroomBoard?.setActive(onBoard);
    }

    // Las flechas nacen en el centro de la pelota; su longitud se escala contra el
    // peso máximo del rango de masa para que también cambie al variar la masa.
    const BALL_RADIUS = 21;
    const ARROW_MAX = 32;
    const MAX_MASS = 100;

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

            const maxForce = MAX_MASS * g;
            const arrowLength = force => BALL_RADIUS + ARROW_MAX * Math.min(force / maxForce, 1) + 'px';
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

    function renderPascal() {
        const values = [
            ['pascalArea1', 'pascal_area1'],
            ['pascalArea2', 'pascal_area2'],
            ['pascalForce1', 'pascal_force1'],
            ['pascalDistance1', 'pascal_distance1'],
        ];
        let complete = true;
        for (const [id, key] of values) {
            const input = document.getElementById(id);
            const value = state[key];
            if (document.activeElement !== input) {
                input.value = value ?? '';
                input.removeAttribute('aria-invalid');
            }
            if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) {
                complete = false;
            }
        }
        if (!complete) {
            document.getElementById('pascalPiston1').style.transform = 'translateY(0px)';
            document.getElementById('pascalPiston2').style.transform = 'translateY(0px)';
            document.getElementById('pascalInputForce').style.transform = 'translateY(0px)';
            document.getElementById('pascalOutputForce').style.transform = 'translateY(0px)';
            document.getElementById('pascalFluid1').setAttribute('y', '102');
            document.getElementById('pascalFluid1').setAttribute('height', '68');
            document.getElementById('pascalFluid2').setAttribute('y', '74');
            document.getElementById('pascalFluid2').setAttribute('height', '96');
            return;
        }

        const area1 = Number(state.pascal_area1);
        const area2 = Number(state.pascal_area2);
        const force1 = Number(state.pascal_force1);
        const distance1 = Number(state.pascal_distance1);
        const area1M2 = area1 / 10000;
        const area2M2 = area2 / 10000;
        const ratio = area2 / area1;
        const distance2 = distance1 / ratio;
        const piston1Offset = Math.min(distance1 * 0.8, 42);
        const piston2Offset = Math.min(distance2 * 0.8, 23);
        document.getElementById('pascalPiston1').style.transform = 'translateY(' + piston1Offset + 'px)';
        document.getElementById('pascalPiston2').style.transform = 'translateY(-' + piston2Offset + 'px)';
        document.getElementById('pascalInputForce').style.transform = 'translateY(' + piston1Offset + 'px)';
        document.getElementById('pascalOutputForce').style.transform = 'translateY(-' + piston2Offset + 'px)';
        const fluid1Y = 102 + piston1Offset;
        const fluid2Y = 74 - piston2Offset;
        document.getElementById('pascalFluid1').setAttribute('y', String(fluid1Y));
        document.getElementById('pascalFluid1').setAttribute('height', String(170 - fluid1Y));
        document.getElementById('pascalFluid2').setAttribute('y', String(fluid2Y));
        document.getElementById('pascalFluid2').setAttribute('height', String(170 - fluid2Y));
    }

    function pascalValue(id, key) {
        const input = document.getElementById(id);
        if (input.value === '') {
            input.removeAttribute('aria-invalid');
            return null;
        }
        const valid = input.checkValidity();
        input.setAttribute('aria-invalid', String(!valid));
        return valid ? input.valueAsNumber : null;
    }

    function render() {
        renderTabs();
        renderArchimedes();
        renderHydrostatic();
        renderPascal();
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
            updateDeadline(snapshot);
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
            pascal_area1: pascalValue('pascalArea1', 'pascal_area1'),
            pascal_area2: pascalValue('pascalArea2', 'pascal_area2'),
            pascal_force1: pascalValue('pascalForce1', 'pascal_force1'),
            pascal_distance1: pascalValue('pascalDistance1', 'pascal_distance1'),
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
            updateDeadline(snapshot);
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
    boardToggle?.addEventListener('click', () => {
        selectActivity(state.activity === 'pizarra' ? lastExperiment : 'pizarra');
    });
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
    renderIdleTimer();
    if (idleTimer) setInterval(renderIdleTimer, 15000);
    if (active) poll(); else finish();
})();
