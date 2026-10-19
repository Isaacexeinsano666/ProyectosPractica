@extends('layouts.app')

@section('content')
<main class="home">
    <section class="entry-card" aria-label="Acceso a la clase">
        @if(session('notice'))<p class="notice" role="status">{{ session('notice') }}</p>@endif
        <form action="{{ route('classroom.join') }}" method="POST" class="join-form" style="padding-top: 28px;">
            @csrf
            <label for="code">Código de la sesión</label>
            <div class="code-row">
                <input id="code" name="code" value="{{ old('code') }}" placeholder="ABCD-EFGH" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false" required @error('code') aria-describedby="code-error" @enderror @error('code') aria-invalid="true" @enderror>
                <button type="submit" class="button primary">Unirme <span aria-hidden="true">→</span></button>
            </div>
            @error('code')<p class="field-error" id="code-error" role="alert">{{ $message }}</p>@enderror
        </form>
        <div class="teacher-entry">
            <details @error('title') open @enderror>
                <summary><span><strong>¿Eres profesor?</strong> Prepara tu laboratorio.</span><span class="text-action">Crear sesión +</span></summary>
                <form action="{{ route('classroom.create') }}" method="POST" class="create-form">
                    @csrf
                    <label for="title">Nombre de la clase</label>
                    <input id="title" name="title" maxlength="100" value="{{ old('title', 'Presión hidrostática') }}" required>
                    @error('title')<p class="field-error" role="alert">{{ $message }}</p>@enderror
                    <p class="field-help">Recibirás un código para invitar a tus alumnos. Tú controlarás la actividad.</p>
                    <button type="submit" class="button dark">Crear y empezar la clase <span aria-hidden="true">→</span></button>
                </form>
            </details>
        </div>
    </section>
    @if($rooms->isNotEmpty())
    <section class="resume-rooms" aria-label="Tus clases activas"><h2>Continuar tu clase</h2>
        @foreach($rooms as $room)<a href="{{ route('classroom.show', $room->code) }}"><span>{{ $room->title }}</span><strong>{{ $room->displayCode() }} →</strong></a>@endforeach
    </section>
    @endif
</main>
@endsection
