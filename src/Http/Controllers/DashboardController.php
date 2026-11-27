<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the single page application built in `dist/`.
 *
 * @internal
 */
class DashboardController
{
    protected const string DIST = __DIR__.'/../../../dist';

    protected const string ENTRY = 'resources/js/main.tsx';

    protected const array TYPES = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8'];

    public function index(Request $request): Response
    {
        $entry = $this->manifest()[self::ENTRY];
        // The path only: the host the browser used may differ from the one Laravel would put in a URL.
        $base = rtrim((string) parse_url(route('spyglass.index'), PHP_URL_PATH), '/');
        $styles = implode('', array_map(fn (string $file) => '<link rel="stylesheet" href="'.e("{$base}/{$file}").'">', $entry['css'] ?? []));
        $script = e("{$base}/{$entry['file']}");
        $data = 'data-base="'.e($base).'" data-csrf="'.e($request->hasSession() ? csrf_token() : '').'"';

        return response(<<<HTML
            <!doctype html>
            <html lang="en">
            <head>
                <meta charset="utf-8">
                <meta name="viewport" content="width=device-width, initial-scale=1">
                <meta name="robots" content="noindex, nofollow">
                <title>Spyglass</title>
                {$styles}
                <script type="module" src="{$script}"></script>
            </head>
            <body>
                <div id="spyglass" {$data}></div>
            </body>
            </html>
            HTML);
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(in_array("assets/{$file}", $this->files(), true), 404);

        return response()->file(self::DIST."/assets/{$file}", [
            'Content-Type' => self::TYPES[pathinfo($file, PATHINFO_EXTENSION)] ?? 'application/octet-stream',
        ])
            // Private: the response may carry the session cookies of the `web` middleware.
            ->setCache(['private' => true, 'max_age' => 31536000, 'immutable' => true]);
    }

    /**
     * @return list<string>
     */
    protected function files(): array
    {
        $files = [];

        foreach ($this->manifest() as $chunk) {
            $files = [...$files, $chunk['file'], ...$chunk['css'] ?? []];
        }

        return $files;
    }

    /**
     * @return array<string, array{file: string, css?: list<string>}>
     */
    protected function manifest(): array
    {
        return json_decode((string) file_get_contents(self::DIST.'/.vite/manifest.json'), true, flags: JSON_THROW_ON_ERROR);
    }
}
