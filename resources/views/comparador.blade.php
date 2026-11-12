@extends('layouts.app')
@section('title', $room->title.' · Aula')
@section('header')<span class="rounded-[20px] border border-[#ccdad4] bg-white px-[15px] py-2 text-[12px] font-[650]">{{ $isOwner ? 'Profesor' : 'Alumno' }}</span>@endsection

@php
    // Clases repetidas en los dos vasos de cada actividad.
    $label = 'mb-2 block text-[12px] font-[650]';
    $select = 'experiment-control bg-[#fafcf8] p-3 text-[12px] disabled:text-[#455c60] disabled:opacity-100';
    $card = 'min-w-0 rounded-[17px] border border-line bg-white p-6 max-laptop:p-[18px] max-phone:p-[22px]';
    $scene = 'flex h-[226px] items-end justify-center gap-2.5 pt-6 pb-4';
    // En Arquímedes hay más espacio abajo para que la flecha del peso pueda salir del vaso.
    $sceneArq = 'flex h-[260px] items-end justify-center gap-2.5 pt-8 pb-11';
    $cup = 'relative h-[184px] w-[130px] rounded-b-[18px] border-2 border-t-0 border-[#7e9793] bg-[linear-gradient(90deg,#f5f9f8,#fff_50%,#f0f5f4)] shadow-[0_8px_0_-5px_#dce5df]';
    // Recorta el líquido a la forma del vaso sin recortar la pelota ni sus flechas.
    $cupClip = 'absolute inset-0 overflow-hidden rounded-b-[16px]';
    $arrow = 'pointer-events-none absolute left-1/2 h-0 w-1 -translate-x-1/2 drop-shadow-[0_0_1px_#1f2a2e] transition-[height] duration-300 ease-[ease]';
    $arrowHead = 'absolute left-1/2 h-2.5 w-3 -translate-x-1/2 bg-inherit';
    $empujeColor = 'bg-[#f5c400]';
    $pesoColor = 'bg-[#d93025]';
    $forceLabel = 'flex items-center gap-1.5 text-[11px] font-[650] text-muted';
    $fill = 'absolute bottom-0 left-0 w-full opacity-85 transition-[height,background-color] duration-[180ms] ease-[ease]';
    $surface = 'absolute -top-1 h-2 w-full rounded-[50%] bg-[#ffffff50]';
    $lines = 'pointer-events-none absolute inset-0 bg-[repeating-linear-gradient(0deg,transparent,transparent_43px,#9db1a13d_44px,transparent_45px)]';
    $tab = 'activity-tab experiment-control appearance-none border-0 border-b-[3px] border-transparent bg-transparent px-1 py-[11px] text-[12px] font-[650] text-muted aria-selected:border-teal aria-selected:text-teal disabled:opacity-100 max-phone:flex-none';
    $heading = 'mt-3 mb-[25px] flex items-center justify-between gap-5';
    $title = 'mt-2.5 mb-2 text-[29px] font-semibold tracking-[-1px] max-laptop:text-[27px] max-tablet:text-[26px]';
    $formula = 'mb-6 flex items-center gap-[30px] rounded-[13px] border border-line bg-[#eff3ec] px-[25px] py-5 max-laptop:gap-5 max-laptop:p-[18px] max-phone:flex-col max-phone:items-start max-phone:gap-3.5';
    $formulaMain = 'font-serif text-[30px] font-normal whitespace-nowrap';
    $formulaVars = 'flex flex-wrap gap-x-5 gap-y-2.5 border-l border-[#cdd9cc] pl-[25px] max-laptop:gap-x-[15px] max-laptop:gap-y-2 max-laptop:pl-[18px] max-tablet:gap-x-3 max-tablet:gap-y-[7px] max-phone:border-l-0 max-phone:pl-0';
    $formulaVar = 'text-[10px] text-muted';
    $symbol = 'mr-[5px] font-serif text-[14px] text-ink';
    $result = 'text-[27px] font-[550] tracking-[-1px] tabular-nums';
@endphp

@section('content')
<main class="mx-auto grid max-w-[1440px] grid-cols-[260px_minmax(0,1fr)] items-start gap-[38px] px-[5%] pt-8 pb-3 max-laptop:grid-cols-[230px_minmax(0,1fr)] max-laptop:gap-[22px] max-laptop:px-[3%] max-laptop:py-6 max-tablet:block max-tablet:px-[5%] max-tablet:py-5">
    <aside class="sticky top-6 rounded-[18px] bg-[#183b3c] px-[22px] py-[25px] text-[#e3ede6] max-laptop:px-[17px] max-laptop:py-[22px] max-tablet:static max-tablet:mb-7 max-tablet:p-[22px]" aria-label="Sesión">
        <a class="mb-[18px] block text-[11px] text-[#a9c2b9]" href="{{ route('home') }}">← Volver al inicio</a>
        <div class="rounded-xl border border-[#41615b] bg-[#214846] px-[13px] py-[18px] max-tablet:max-w-[340px]">
            <span class="block text-[8px] tracking-[1.5px] text-[#b0c7bb]">CÓDIGO DE LA SESIÓN</span>
            <strong id="room-code" class="mt-[11px] mb-[15px] block text-[24px] font-medium tracking-[2px] tabular-nums">{{ $room->displayCode() }}</strong>
            @if($isOwner)<button type="button" id="copy-code" class="inline-flex min-h-[38px] w-full items-center justify-center gap-3 rounded-[10px] bg-[#e6f2e8] px-3 py-[9px] text-[11px] font-[650] whitespace-nowrap text-[#21483d] transition-[background,transform] duration-150 hover:-translate-y-px">Copiar código <span aria-hidden="true">⧉</span></button>@endif
        </div>
        <div class="connection-block flex items-center gap-2 py-[19px] text-[11px] leading-[1.5] text-[#c3d7cb]" @if($active) hidden @endif>
            <span id="connection-dot" class="inline-block size-[7px] shrink-0 rounded-full bg-[#79c9a5] data-[kind=warning]:bg-[#e5b253] data-[kind=ended]:bg-[#94a49d]"></span>
            <span id="connection-status" role="status" aria-live="polite">{{ $active ? '' : 'La sesión ha finalizado' }}</span>
        </div>
        @if($isOwner && $active)
        <details class="end-session mt-[22px] border-t border-[#41615b] pt-[18px]">
            <summary class="text-[11px] text-[#f1bcb0]">Finalizar sesión</summary>
            <form class="mt-3.5" action="{{ route('classroom.end', $room->code) }}" method="POST">@csrf<button type="submit" id="end-session" class="inline-flex min-h-[46px] w-full items-center justify-center gap-5 rounded-[10px] bg-[#a43d37] p-[11px] text-[11px] font-[650] whitespace-nowrap text-white transition-[background,transform] duration-150 hover:-translate-y-px">Sí, finalizar clase</button></form>
        </details>
        @endif
    </aside>
    <section class="min-w-0" aria-label="Actividades de fluidos">
        <div id="session-banner" class="rounded-[10px] border border-[#d4e4d0] bg-[#eef5ed] px-[17px] py-[13px] text-[13px] text-[#365e38]" role="status" @if($active) hidden @endif>Esta clase ha finalizado. Puedes revisar el último estado o volver al inicio.</div>
        <div class="mt-3 mb-[22px] flex gap-2.5 border-b border-line max-phone:gap-4 max-phone:overflow-x-auto" role="tablist" aria-label="Actividades de fluidos">
            <button class="{{ $tab }}" type="button" data-activity="arquimedes" role="tab" aria-controls="arquimedes-panel" @disabled(!$isOwner || !$active)>Principio de Arquímedes</button>
            <button class="{{ $tab }}" type="button" data-activity="hidrostatica" role="tab" aria-controls="hidrostatica-panel" @disabled(!$isOwner || !$active)>Presión hidrostática</button>
        </div>

        <section id="arquimedes-panel" data-activity-panel="arquimedes" role="tabpanel">
            <header class="{{ $heading }}"><div><h2 class="{{ $title }}">Principio de Arquímedes</h2></div></header>
            <div class="mb-6 rounded-[13px] border border-line bg-[#eff3ec] px-[25px] py-5 text-center max-laptop:p-[18px]">
                <strong class="{{ $formulaMain }}">E = ρ · g · V</strong>
                <dl class="mx-auto mt-3.5 grid max-w-[560px] grid-cols-[auto_minmax(0,1fr)] items-baseline gap-x-3 gap-y-1.5 border-t border-[#cdd9cc] pt-3.5 text-left text-[12px] text-muted">
                    <dt class="font-serif text-[15px] font-bold text-ink">E</dt><dd>Empuje: fuerza hacia arriba que el líquido ejerce sobre el sólido (N)</dd>
                    <dt class="font-serif text-[15px] font-bold text-ink">ρ</dt><dd><span class="text-ink">(rho)</span> Densidad del líquido (kg/m³)</dd>
                    <dt class="font-serif text-[15px] font-bold text-ink">g</dt><dd>Aceleración de gravedad ({{ str_replace('.', ',', $physics['gravity']) }} m/s²)</dd>
                    <dt class="font-serif text-[15px] font-bold text-ink">V</dt><dd>Volumen del líquido desplazado o volumen del sólido sumergido (m³)</dd>
                </dl>
            </div>
            <div class="grid grid-cols-2 gap-5 max-phone:grid-cols-1">
            @for($i = 1; $i <= 2; $i++)
                <article class="{{ $card }}">
                    <header class="mb-6 flex items-center gap-[9px]"><h3 class="m-0 text-[15px] font-[650]">Vaso {{ $i }}</h3></header>
                    <label class="{{ $label }}" for="arquimedesLiquido{{ $i }}">Fluido</label>
                    <select id="arquimedesLiquido{{ $i }}" class="{{ $select }}" @disabled(!$isOwner || !$active)>
                        @foreach($physics['fluids'] as $key => $fluid)<option value="{{ $key }}" @selected($room->state['arquimedes_fluido'.$i] === $key)>{{ $fluid['nombre'] }} · {{ $fluid['densidad'] }} kg/m³</option>@endforeach
                    </select>
                    <div class="mt-[18px] grid grid-cols-[minmax(0,1fr)_112px] gap-2.5">
                        <div class="min-w-0">
                            <label class="{{ $label }}" for="selectObjeto{{ $i }}">Objeto</label>
                            <select id="selectObjeto{{ $i }}" class="{{ $select }}" @disabled(!$isOwner || !$active)>
                                @foreach($physics['objects'] as $key => $object)<option value="{{ $key }}" @selected($room->state['objeto'.$i] === $key)>{{ $object['nombre'] }} · {{ $object['densidad'] }} kg/m³</option>@endforeach
                            </select>
                        </div>
                        <div class="min-w-0">
                            <label class="{{ $label }}" for="masa{{ $i }}">Masa (kg)</label>
                            <input type="number" id="masa{{ $i }}" class="experiment-control bg-[#fafcf8] p-3 text-[12px] tabular-nums aria-invalid:border-danger disabled:text-[#455c60] disabled:opacity-100" min="0.01" max="100" step="0.01" inputmode="decimal" value="{{ $room->state['masa'.$i] }}" aria-describedby="masaAyuda{{ $i }}" @disabled(!$isOwner || !$active)>
                        </div>
                    </div>
                    <p id="masaAyuda{{ $i }}" class="mt-1.5 mb-0 text-[10px] text-muted">Masa entre 0,01 y 100 kg.</p>
                    <div class="{{ $sceneArq }}" aria-hidden="true">
                        <div class="{{ $cup }}">
                            <div class="{{ $cupClip }}"><div id="arquimedesFill{{ $i }}" class="{{ $fill }}"><div class="{{ $surface }}"></div></div><div class="{{ $lines }}"></div></div>
                            <div id="ball{{ $i }}" class="absolute bottom-0 left-1/2 z-[2] size-[42px] -translate-x-1/2 rounded-full border-[3px] border-[#79503a] bg-[#9b6849] transition-[bottom,background-color] duration-300 ease-[ease]">
                                {{-- Flechas desde el centro del sólido: empuje hacia arriba, peso hacia abajo. --}}
                                <span id="flechaEmpuje{{ $i }}" class="{{ $arrow }} {{ $empujeColor }} bottom-1/2"><span class="{{ $arrowHead }} -top-2 [clip-path:polygon(50%_0,100%_100%,0_100%)]"></span></span>
                                <span id="flechaPeso{{ $i }}" class="{{ $arrow }} {{ $pesoColor }} top-1/2"><span class="{{ $arrowHead }} -bottom-2 [clip-path:polygon(0_0,100%_0,50%_100%)]"></span></span>
                            </div>
                        </div>
                    </div>
                    <div class="flex flex-col gap-2 rounded-[10px] border border-[#e5ece1] bg-[#f3f7f0] px-[15px] py-[17px]">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <span class="{{ $forceLabel }}"><span class="h-3 w-1 {{ $empujeColor }}" aria-hidden="true"></span>Empuje</span>
                                <strong id="empuje{{ $i }}" class="{{ $result }} mt-1 block max-laptop:text-[22px]">— N</strong>
                            </div>
                            <div class="min-w-0 text-right">
                                <span class="{{ $forceLabel }} justify-end"><span class="h-3 w-1 {{ $pesoColor }}" aria-hidden="true"></span>Peso del sólido</span>
                                <strong id="peso{{ $i }}" class="{{ $result }} mt-1 block max-laptop:text-[22px]">— N</strong>
                            </div>
                        </div>
                        <span id="estado{{ $i }}" class="text-[11px] text-teal">—</span>
                        <span id="volumen{{ $i }}" class="text-[10px] text-muted">—</span>
                    </div>
                </article>
            @endfor
            </div>
        </section>

        <section id="hidrostatica-panel" data-activity-panel="hidrostatica" role="tabpanel" hidden>
            <header class="{{ $heading }}"><div><h2 class="{{ $title }}">Presión hidrostática</h2></div></header>
            <div class="{{ $formula }}"><strong class="{{ $formulaMain }}">P = ρ · g · h</strong><div class="{{ $formulaVars }}"><span class="{{ $formulaVar }}"><b class="{{ $symbol }}">ρ</b> Densidad del fluido</span><span class="{{ $formulaVar }}"><b class="{{ $symbol }}">g</b> Gravedad</span><span class="{{ $formulaVar }}"><b class="{{ $symbol }}">h</b> Profundidad</span></div></div>
            <div class="grid grid-cols-2 gap-5 max-phone:grid-cols-1">
            @for($i = 1; $i <= 2; $i++)
                <article class="{{ $card }}">
                    <header class="mb-6 flex items-center gap-[9px]"><h3 class="m-0 text-[15px] font-[650]">Vaso {{ $i }}</h3></header>
                    <label class="{{ $label }}" for="hidrostaticaLiquido{{ $i }}">Fluido</label>
                    <select id="hidrostaticaLiquido{{ $i }}" class="{{ $select }}" @disabled(!$isOwner || !$active)>
                        @foreach($physics['fluids'] as $key => $fluid)<option value="{{ $key }}" @selected($room->state['hidrostatica_fluido'.$i] === $key)>{{ $fluid['nombre'] }} · {{ $fluid['densidad'] }} kg/m³</option>@endforeach
                    </select>
                    <div class="{{ $scene }}" aria-hidden="true"><div class="{{ $cup }}"><div class="{{ $cupClip }}"><div id="hidrostaticaFill{{ $i }}" class="{{ $fill }}"><div class="{{ $surface }}"></div></div><div class="{{ $lines }}"></div></div><div id="hidroBall{{ $i }}" class="absolute bottom-0 left-1/2 z-[2] size-[26px] -translate-x-1/2 rounded-full border-2 border-[#a71d2a] bg-[#dc3545] transition-[bottom] duration-300 ease-[ease]"></div></div></div>
                    <div class="mb-[9px] flex items-center justify-between"><label class="m-0 block text-[11px] font-[650]" for="alturaLiquido{{ $i }}">Profundidad</label><output id="valorAltura{{ $i }}" for="alturaLiquido{{ $i }}" class="text-[13px] font-[650] tabular-nums">1,00 m</output></div>
                    <input class="experiment-control h-5 border-0 bg-transparent p-0 accent-teal shadow-none disabled:opacity-65" type="range" id="alturaLiquido{{ $i }}" min="0.1" max="2" step="0.01" value="{{ $room->state['altura'.$i] }}" @disabled(!$isOwner || !$active)>
                    <div class="mb-5 flex justify-between text-[9px] text-[#8c9c95]"><span>0,10 m</span><span>2,00 m</span></div>
                    <div class="flex flex-col items-start gap-1 tabular-nums"><strong id="presion{{ $i }}" class="{{ $result }} max-laptop:text-[24px]">— Pa</strong><span id="kilopascales{{ $i }}" class="text-[12px] text-muted">— kPa</span></div>
                </article>
            @endfor
            </div>
        </section>
    </section>
</main>
@endsection

@push('scripts')
<script>
    window.classroomConfig = {{ Illuminate\Support\Js::from([
        'isOwner' => $isOwner,
        'active' => $active,
        'code' => $room->displayCode(),
        'state' => $room->state,
        'revision' => $room->revision,
        'physics' => $physics,
        'stateUrl' => route('classroom.state', $room->code),
        'updateUrl' => route('classroom.update', $room->code),
    ]) }};
</script>
{{-- ?v= evita que el navegador use una versión antigua de aula.js en caché. --}}
<script src="{{ asset('js/aula.js') }}?v={{ filemtime(public_path('js/aula.js')) }}" defer></script>
@endpush
