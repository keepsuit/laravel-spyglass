<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Spyglass</title>
    @foreach ($styles as $style)
        <link rel="stylesheet" href="{{ $style }}"@if ($nonce) nonce="{{ $nonce }}"@endif>
    @endforeach
    <script type="module" src="{{ $script }}"@if ($nonce) nonce="{{ $nonce }}"@endif></script>
</head>
<body>
    <div id="spyglass" data-base="{{ $base }}" data-csrf="{{ $csrf }}"></div>
</body>
</html>
