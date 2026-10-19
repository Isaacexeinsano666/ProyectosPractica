@extends('layouts.app')
@section('title', $room->title.' · Aula')
@section('header')<span class="role-chip">{{ $isOwner ? 'Profesor' : 'Alumno' }}</span>@endsection

@section('content')
<main class="classroom">
    <aside class="room-sidebar" aria-label="Sesión">
        <a class="back-link" href="{{ route('home') }}">← Volver al inicio</a>
        <div class="share-code"><span>CÓDIGO DE LA SESIÓN</span><strong id="room-code">{{ $room->displayCode() }}</strong>
            @if($isOwner)<button class="button copy-button" type="button" id="copy-code">Copiar código <span aria-hidden="true">⧉</span></button>@endif
        </div>
        <div class="connection-block" @if($active) hidden @endif><span class="live-dot" id="connection-dot"></span><span id="connection-status" role="status" aria-live="polite">{{ $active ? '' : 'La sesión ha finalizado' }}</span></div>
        @if($isOwner && $active)
        <details class="end-session"><summary>Finalizar sesión</summary><form action="{{ route('classroom.end', $room->code) }}" method="POST">@csrf<button type="submit" class="button danger" id="end-session">Sí, finalizar clase</button></form></details>
        @endif
    </aside>
    <section class="experiment" aria-labelledby="experiment-heading">
        <div id="session-banner" class="notice" role="status" @if($active) hidden @endif>Esta clase ha finalizado. Puedes revisar el último estado o volver al inicio.</div>
        <header class="experiment-heading"><div><h2 id="experiment-heading">Presión hidrostática</h2></div></header>
        <div class="formula-panel"><strong>P = ρ · g · h</strong><div><span><b>ρ</b> Densidad del fluido</span><span><b>g</b> Gravedad</span><span><b>h</b> Profundidad</span></div></div>
        <div class="vessels">
        @for($i = 1; $i <= 2; $i++)
            <article class="vessel-card">
                <header><h3>Vaso {{ $i }}</h3></header>
                <label for="selectLiquido{{ $i }}">Fluido</label>
                <select id="selectLiquido{{ $i }}" class="experiment-control" @disabled(!$isOwner || !$active)>
                    @foreach($physics['fluids'] as $key => $fluid)<option value="{{ $key }}" @selected($room->state['fluido'.$i] === $key)>{{ $fluid['nombre'] }} · {{ $fluid['densidad'] }} kg/m³</option>@endforeach
                </select>
                <div class="vessel-scene" aria-hidden="true"><div class="cup"><div id="fill{{ $i }}" class="fill"><div class="fluid-surface"></div></div><div class="cup-lines"></div><div id="ball{{ $i }}" class="ball"></div></div></div>
                <div class="height-heading"><label for="alturaLiquido{{ $i }}">Profundidad</label><output id="valorAltura{{ $i }}" for="alturaLiquido{{ $i }}">1,00 m</output></div>
                <input class="experiment-control height-range" type="range" id="alturaLiquido{{ $i }}" min="0.1" max="2" step="0.01" value="{{ $room->state['altura'.$i] }}" @disabled(!$isOwner || !$active)>
                <div class="range-limits"><span>0,10 m</span><span>2,00 m</span></div>
                <div class="pressure-result"><strong id="presion{{ $i }}">— Pa</strong><span id="kilopascales{{ $i }}">— kPa</span></div>
            </article>
        @endfor
        </div>
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
<script src="{{ asset('js/aula.js') }}" defer></script>
@endpush
