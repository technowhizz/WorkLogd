<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1, maximum-scale=1">

        <title inertia>{{ config('app.name', 'Laravel') }}</title>

        <!-- Favicons -->
        {{--
            mark-adaptive.svg carries its own prefers-color-scheme block, so one file serves
            both modes wherever CSS cannot reach. The PNGs are the fallback for user agents
            that will not take an SVG icon, and those do need an explicit pair.
        --}}
        <link rel="icon" type="image/svg+xml" href="/brand/svg/mark-adaptive.svg">
        <link rel="icon" type="image/png" sizes="32x32" href="/brand/png/light/favicon-32.png">
        <link rel="icon" type="image/png" sizes="32x32" href="/brand/png/dark/favicon-32.png" media="(prefers-color-scheme: dark)">
        {{-- Ink tile in both modes: iOS renders it on its own background --}}
        <link rel="apple-touch-icon" sizes="180x180" href="/brand/png/light/apple-touch-icon-180.png">
        <link rel="manifest" href="/brand/site.webmanifest">
        <link rel="shortcut icon" href="/brand/favicon.ico">
        <meta name="msapplication-TileColor" content="#201E1D">
        <meta name="msapplication-config" content="/brand/browserconfig.xml">
        <meta name="theme-color" content="#F3F2F2" media="(prefers-color-scheme: light)">
        <meta name="theme-color" content="#201E1D" media="(prefers-color-scheme: dark)">

        <!-- Archivo is the brand typeface, weights 400/700/800 -->
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Archivo:wght@400;700;800&display=swap" rel="stylesheet">

        {{--
            Apply the stored theme before the first paint. The Vue app only sets this class once
            its bundle has booted, and the colour tokens live entirely inside :root.dark and
            :root.light, so until then the background resolves to an undefined variable and the
            page paints white. Kept deliberately in sync with resources/js/utils/theme.ts.
        --}}
        <script>
            (function () {
                var theme = 'system';
                try {
                    theme = window.localStorage.getItem('theme') || 'system';
                } catch (e) {
                    // Storage can be unavailable, fall through to the media query
                }
                // useStorage writes the bare string, but tolerate a JSON quoted value
                theme = String(theme).replace(/^"|"$/g, '');
                if (theme !== 'light' && theme !== 'dark') {
                    // Matches theme.ts: only an explicit light preference gives light
                    theme = window.matchMedia('(prefers-color-scheme: light)').matches
                        ? 'light'
                        : 'dark';
                }
                document.documentElement.classList.add(theme);
            })();
        </script>

        <!-- Scripts -->
        @routes
        @vite(\Nwidart\Modules\Module::getAssets())
        @inertiaHead
    </head>
    <body class="font-sans antialiased">
        @inertia
    </body>
</html>
