<!doctype html>
<html lang="tr">
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex">
<title>{{ $title }} · {{ $domain }}</title>
<style>
body{margin:0;min-height:100vh;display:grid;place-items:center;font-family:system-ui,-apple-system,"Segoe UI",sans-serif;background:#f6f8f8;color:#11191c}
main{max-width:30rem;padding:2rem 1.5rem;text-align:center}
.code{font-size:3rem;font-weight:700;color:#0d7479;margin:0}
h1{font-size:1.4rem;margin:.25rem 0 .75rem}
p{margin:.25rem 0;line-height:1.55;color:#4b5b60}
a{color:#0d7479}
.en{margin-top:1.25rem;font-size:.9rem}
@media (prefers-color-scheme:dark){body{background:#0f1719;color:#e3ecec}p{color:#9fb1b4}.code,a{color:#34b8bc}}
</style>
<main>
<p class="code">{{ $code }}</p>
<h1>{{ $title }}</h1>
<p>{{ $text }}</p>
<p><a href="/">{{ $domain }} ana sayfasına dönün</a></p>
<p class="en">{{ $english }}</p>
</main>
</html>
