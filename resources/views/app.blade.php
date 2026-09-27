<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#0b0d14">
    <link rel="icon" href="/favicon.svg" type="image/svg+xml">
    <title inertia>{{ config('app.name', 'Delvebound') }}</title>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.tsx'])
    @inertiaHead
</head>
<body class="h-full antialiased">
    @inertia
</body>
</html>
