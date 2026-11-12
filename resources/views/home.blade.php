@extends('layouts.app')

@section('content')
<main class="mx-auto max-w-[960px] px-6 pt-[58px] pb-2 min-[1500px]:pt-[72px] max-tablet:pt-10 max-phone:px-[18px] max-phone:pt-[35px] max-phone:pb-[5px]">
    <section class="mx-auto max-w-[610px] overflow-hidden rounded-[20px] border border-line bg-white shadow-[0_12px_40px_#15392c07]" aria-label="Acceso a la clase">
        @if(session('notice'))<p class="mx-8 mb-5 rounded-[10px] border border-[#d4e4d0] bg-[#eef5ed] px-[17px] py-[13px] text-[13px] text-[#365e38]" role="status">{{ session('notice') }}</p>@endif
        <form action="{{ route('classroom.join') }}" method="POST" class="px-8 pt-7 pb-7 max-phone:px-[22px] max-phone:pb-6">
            @csrf
            <label for="code" class="mb-2 block text-[12px] font-[650]">Código de la sesión</label>
            <div class="flex gap-2.5 max-phone:flex-col">
                <input id="code" name="code" value="{{ old('code') }}" placeholder="ABCD-EFGH" maxlength="30" autocomplete="off" autocapitalize="characters" spellcheck="false" required
                    class="h-[58px] bg-[#fcfdfa] text-[21px] tracking-[3px] uppercase placeholder:tracking-[2px] placeholder:text-[#9aa7a3] aria-invalid:border-danger max-phone:text-center"
                    @error('code') aria-describedby="code-error" aria-invalid="true" @enderror>
                <button type="submit" class="inline-flex min-h-[46px] items-center justify-center gap-5 rounded-[10px] bg-teal px-[21px] py-3.5 text-[13px] font-[650] whitespace-nowrap text-white transition-[background,transform] duration-150 hover:-translate-y-px hover:bg-teal-dark max-phone:w-full">Unirme <span aria-hidden="true">→</span></button>
            </div>
            @error('code')<p class="mt-3 mb-0 text-[12px] text-danger" id="code-error" role="alert">{{ $message }}</p>@enderror
        </form>
        <div class="border-t border-line bg-[#f8faf7] px-8 py-[19px] max-phone:px-[22px] max-phone:py-[18px]">
            <details>
                <summary class="flex items-center justify-between gap-3 text-[11px] text-muted max-phone:flex-col max-phone:items-start max-phone:gap-2.5"><span><strong class="font-[650] text-ink">¿Eres profesor?</strong> Prepara tu laboratorio.</span><span class="text-[12px] font-[750] whitespace-nowrap text-teal">Crear sesión +</span></summary>
                <form action="{{ route('classroom.create') }}" method="POST" class="pt-[22px] pb-0.5">
                    @csrf
                    <button type="submit" class="mt-[18px] inline-flex min-h-[46px] w-full items-center justify-center gap-5 rounded-[10px] bg-ink px-[21px] py-3.5 text-[13px] font-[650] whitespace-nowrap text-white transition-[background,transform] duration-150 hover:-translate-y-px hover:bg-[#234650]">Crear y empezar la clase <span aria-hidden="true">→</span></button>
                </form>
            </details>
        </div>
    </section>
    @if($rooms->isNotEmpty())
    <section class="mx-auto my-6 max-w-[610px]" aria-label="Tus clases activas"><h2 class="mb-[0.83em] text-[13px] font-bold">Continuar tu clase</h2>
        @foreach($rooms as $room)<a class="mb-2 flex items-center justify-between gap-5 rounded-[10px] border border-line bg-white px-[18px] py-[15px] text-[12px] max-phone:flex-col max-phone:items-start max-phone:gap-2" href="{{ route('classroom.show', $room->code) }}"><span>{{ $room->title }}</span><strong class="whitespace-nowrap text-teal">{{ $room->displayCode() }} →</strong></a>@endforeach
    </section>
    @endif
</main>
@endsection
