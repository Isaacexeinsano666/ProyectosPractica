<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Aula · Física en vivo')</title>
    @vite('resources/css/app.css')
    @stack('styles')
</head>
<body class="bg-paper font-sans text-ink">
@unless(request()->routeIs('home'))
<header class="mx-auto flex h-[102px] max-w-[1440px] items-center justify-end gap-7 border-b border-line px-[5%] max-tablet:h-[83px] max-tablet:px-[6%]">
    @yield('header')
</header>
@endunless
@yield('content')
@stack('scripts')
</body>
</html>
