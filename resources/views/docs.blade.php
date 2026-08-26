<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }} — справочник API</title>
    <link rel="icon" href="data:,">
    <link rel="stylesheet" href="{{ $assets }}/swagger-ui.css">
    <style>
        body { margin: 0; background: #fafafa; }
        .topbar { display: none; }
        .swagger-ui .info { margin: 32px 0; }
    </style>
</head>
<body>
    <div id="swagger-ui"></div>

    <script src="{{ $assets }}/swagger-ui-bundle.js" crossorigin></script>
    <script src="{{ $assets }}/swagger-ui-standalone-preset.js" crossorigin></script>
    <script>
        window.ui = SwaggerUIBundle({
            url: @json($specUrl),
            dom_id: '#swagger-ui',
            deepLinking: true,
            // Keeps the bearer token across reloads, so you authorise once and
            // can then exercise every private route from this page.
            persistAuthorization: true,
            tryItOutEnabled: true,
            displayRequestDuration: true,
            defaultModelsExpandDepth: 1,
            presets: [SwaggerUIBundle.presets.apis, SwaggerUIStandalonePreset],
            plugins: [SwaggerUIBundle.plugins.DownloadUrl],
            layout: 'StandaloneLayout',
        });
    </script>
</body>
</html>
