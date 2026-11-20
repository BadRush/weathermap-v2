<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Weathermap')</title>
</head>
<body style="margin: 0; background: #0b1120; color: #f1f5f9;">
    @yield('content')
</body>
</html>
