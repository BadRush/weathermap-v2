@extendsFirst(['layouts.librenmsv1', 'weathermap::standalone'])

@section('title', $title ?? 'Weathermap')

@section('content')
    @yield('weathermap-content')
@endsection
