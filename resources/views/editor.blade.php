@extends('weathermap::layout')

@section('weathermap-content')
    @foreach($assets['css'] as $cssUrl)
        <link rel="stylesheet" href="{{ $cssUrl }}">
    @endforeach

    <div id="weathermap-editor-root" data-map-id="{{ $map->id }}"></div>

    @foreach($assets['js'] as $jsUrl)
        <script type="module" src="{{ $jsUrl }}"></script>
    @endforeach
@endsection
