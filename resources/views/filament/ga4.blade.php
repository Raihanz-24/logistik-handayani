@php($GA = config('services.ga.measurement_id'))

@if($GA)

<!-- Google tag (gtag.js) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-KXRMHQL4SD"></script>
<script>
  window.dataLayer = window.dataLayer || [];
  function gtag(){dataLayer.push(arguments);}
  gtag('js', new Date());

  gtag('config', 'G-KXRMHQL4SD');
</script>
@endif


