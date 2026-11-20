<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ $title ?? 'Weathermap NOC Kiosk' }}</title>
    @foreach($assets['css'] as $cssUrl)
        <link rel="stylesheet" href="{{ $cssUrl }}">
    @endforeach
</head>
<body style="margin: 0; background: #020617; color: #f1f5f9; overflow: hidden;">
    <div
        id="weathermap-kiosk-root"
        data-maps='@json($maps)'
        data-rotate-seconds="{{ $rotateSeconds }}"
    ></div>

    @foreach($assets['js'] as $jsUrl)
        <script type="module" src="{{ $jsUrl }}"></script>
    @endforeach
</body>
</html>
