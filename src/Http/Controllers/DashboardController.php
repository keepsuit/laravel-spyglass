<?php

namespace Keepsuit\Spyglass\Http\Controllers;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Serves the single page application built in `dist/`.
 *
 * @internal
 */
class DashboardController
{
    /**
     * Built by CI on main. Tests point it to a fixture.
     */
    public static string $dist = __DIR__.'/../../../dist';

    protected const string ENTRY = 'resources/js/main.tsx';

    protected const array TYPES = ['js' => 'text/javascript; charset=utf-8', 'css' => 'text/css; charset=utf-8'];

    public function index(Request $request): View
    {
        $entry = $this->manifest()[self::ENTRY];
        // The path only: the host the browser used may differ from the one Laravel would put in a URL.
        $base = rtrim((string) parse_url(route('spyglass.index'), PHP_URL_PATH), '/');

        return view('spyglass::dashboard', [
            'base' => $base,
            'styles' => array_map(fn (string $file) => "{$base}/{$file}", $entry['css'] ?? []),
            'script' => "{$base}/{$entry['file']}",
            'csrf' => $request->hasSession() ? csrf_token() : '',
            // The nonce the application gives its own Vite tags, for a Content Security Policy.
            'nonce' => Vite::cspNonce(),
        ]);
    }

    public function asset(string $file): BinaryFileResponse
    {
        abort_unless(in_array("assets/{$file}", $this->files(), true), 404);

        return response()->file(self::$dist."/assets/{$file}", [
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
        $manifest = self::$dist.'/.vite/manifest.json';

        if (! is_file($manifest)) {
            throw new RuntimeException('The Spyglass dashboard is not built: run `npm ci && npm run build` in the package.');
        }

        return json_decode((string) file_get_contents($manifest), true, flags: JSON_THROW_ON_ERROR);
    }
}
