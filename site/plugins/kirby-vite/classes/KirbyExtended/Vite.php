<?php

namespace KirbyExtended;

use Exception;
use Kirby\Data\Data;
use Kirby\Http\Url;
use Kirby\Filesystem\F;
use Kirby\Toolkit\Html;

class Vite
{
    protected static \KirbyExtended\Vite $instance;
    protected static string $apiLocation;
    protected static array $site;
    protected static array $manifest;

    /**
     * Checks for development mode by either `KIRBY_MODE` env var or
     * if a `.lock` file in `/src` exists
     *
     * @return bool
     */
    protected function isDev(): bool
    {
        if (env('KIRBY_MODE') === 'development') {
            return true;
        }

        $lockFile = kirby()->root('base') . '/src/.lock';
        return F::exists($lockFile);
    }

    /**
     * Gets the content api path
     *
     * @return string
     */
    public function useApiLocation(): string
    {
        return static::$apiLocation ??= Url::path(env('CONTENT_API_SLUG'), true);
    }

    /**
     * Gets the site data
     *
     * @return array
     */
    public function useSite(): array
    {
        return static::$site ??= require kirby()->root('config') . '/app-site.php';
    }

    /**
     * Reads and parses the manifest file created by Vite
     *
     * @return array|null
     * @throws Exception
     */
    protected function useManifest(): ?array
    {
        if (isset(static::$manifest)) {
            return static::$manifest;
        }

        $manifestFile = kirby()->root('index') . '/' . option('johannschopplich.kirby-vite.outDir', 'dist') . '/manifest.json';

        if (!F::exists($manifestFile)) {
            if (option('debug')) {
                throw new Exception('manifest.json not found. Run `npm run build` first.');
            }

            return [];
        }

        return static::$manifest = Data::read($manifestFile);
    }

    /**
     * Gets a value of a manifest property for a specific entry
     *
     * @param string $entry
     * @param string $key
     * @return string|void
     * @throws Exception
     */
    protected function getManifestProperty(string $entry, $key = 'file')
    {
        $manifestEntry = $this->useManifest()[$entry] ?? null;
        if (!$manifestEntry) {
            if (option('debug')) {
                throw new Exception("{$entry} is not a manifest entry");
            }

            return;
        }

        $value = $manifestEntry[$key] ?? null;
        if (!$value) {
            if (option('debug')) {
                throw new Exception("{$key} not found in manifest entry {$entry}");
            }

            return;
        }

        return $value;
    }

    /**
     * Gets the URL for the specified file in development mode
     *
     * @param string $file
     * @return string
     */
    protected function assetDev(string $file): string
    {
        return option('johannschopplich.kirby-vite.devServer', 'http://localhost:3000') . '/' . $file;
    }

    /**
     * Gets the URL for the specified file in production mode
     *
     * @param string $file
     * @return string
     */
    protected function assetProd(string $file): string
    {
        return '/' . option('johannschopplich.kirby-vite.outDir', 'dist') . '/' . $file;
    }

    /**
     * Includes the CSS file for the specified entry in production mode
     *
     * @param string|null $entry
     * @param array|null $options
     * @return string|null
     * @throws Exception
     */
    public function css(string $entry = null, array $options = []): ?string
    {
        if ($this->isDev()) return null;

        $entry ??= option('johannschopplich.kirby-vite.entry', 'index.js');
        $attr = array_merge($options, [
            'href' => $this->assetProd($this->getManifestProperty($entry, 'css')[0]),
            'rel'  => 'stylesheet'
        ]);

        return '<link ' . attr($attr) . '>';
    }

    /**
     * Includes the JS file for the specified entry and
     * Vite's client in development mode as well
     *
     * @param string|null $entry
     * @param array $options
     * @return string|null
     * @throws Exception
     */
    public function js(string $entry = null, array $options = []): ?string
    {
        $entry ??= option('johannschopplich.kirby-vite.entry', 'index.js');

        $client = $this->isDev() ? js($this->assetDev('@vite/client'), ['type' => 'module']) : '';
        $file = $this->isDev()
            ? $this->assetDev($entry)
            : $this->assetProd($this->getManifestProperty($entry, 'file'));

        $attr = array_merge($options, [
            'type' => 'module',
            'src' => $file
        ]);

        return $client . '<script ' . attr($attr) . '></script>';
    }

    /**
     * Preloads the JSON-encoded page data for a given page
     *
     * @param string $name Page id
     * @return string
     */
    public function preloadJson(string $name): string
    {
        $base = kirby()->multilang() ? '/' . kirby()->languageCode() : '';

        return Html::tag('link', '', [
            'rel' => 'preload',
            'href' => $base . $this->useApiLocation() . '/' . $name . '.json',
            'as' => 'fetch',
            'type' => 'application/json',
            'crossorigin' => 'anonymous'
        ]);
    }

    /**
     * Preloads the view module for a given page, e.g. `Home.e701bdef.js`
     *
     * @param string $name Page template name or other module name
     * @return string|null
     */
    public function preloadModule(string $name): ?string
    {
        if ($this->isDev()) return null;

        $match = array_filter(
            $this->useManifest(),
            fn ($i) => str_ends_with($i, ucfirst($name) . '.vue'),
            ARRAY_FILTER_USE_KEY
        );

        return !empty($match)
            ? Html::tag('link', '', [
                'rel' => 'modulepreload',
                'href' => '/' . option('johannschopplich.kirby-vite.outDir', 'dist') . '/' . array_values($match)[0]['file']
            ])
            : null;
    }

    /**
     * Converts an array to an encoded JSON string
     *
     * @param array $data
     * @return string
     */
    public function json(array $data): string
    {
        return json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    /**
     * Gets the instance via lazy initialization
     *
     * @return \KirbyExtended\Vite
     */
    public static function getInstance(): \KirbyExtended\Vite
    {
        return static::$instance ??= new static;
    }
}
