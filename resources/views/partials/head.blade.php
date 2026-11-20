<meta charset="utf-8" />
<meta name="viewport" content="width=device-width, initial-scale=1.0" />

<title>
    {{ filled($title ?? null) ? $title.' - AGOS' : 'AGOS — Financial clarity for growing businesses' }}
</title>

<link rel="icon" href="/favicon.ico?v=agos" sizes="any">
<link rel="icon" href="/brand/favicon.png" sizes="64x64" type="image/png">
<link rel="apple-touch-icon" href="/apple-touch-icon.png?v=agos">

@fonts

@vite(['resources/css/app.css', 'resources/js/app.js'])
@fluxAppearance
