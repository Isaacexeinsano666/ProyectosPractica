<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Aula · Física en vivo')</title>
    <link rel="stylesheet" href="{{ asset('css/aula.css') }}">
    @stack('styles')
</head>
<body>
@unless(request()->routeIs('home'))
<header class="site-header" style="justify-content: flex-end;">
    @yield('header')
</header>
@endunless
@yield('content')
@stack('scripts')
</body>
</html>
