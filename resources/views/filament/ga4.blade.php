@php($GA = config('services.ga.measurement_id'))

@if($GA)
    <script async src="https://www.googletagmanager.com/gtag/js?id={{ $GA }}"></script>
    <script>
        window.dataLayer = window.dataLayer || [];
        function gtag(){dataLayer.push(arguments);}
        gtag('js', new Date());
        gtag('config', '{{ $GA }}');
    </script>
@endif
