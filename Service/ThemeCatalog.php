<?php

declare(strict_types=1);

namespace MauticPlugin\AiEmailSectionsBundle\Service;

/**
 * The theme token files the plugin can offer.
 *
 * One place rather than two: the configuration form lists these, the builder
 * panel lists these, and the controller validates against these. When themes
 * one day also come from the database, this is the only class that changes.
 */
class ThemeCatalog
{
    public const DEFAULT_THEME = 'default';

    /**
     * The directory is a constructor argument only so tests can point at a
     * fixture; autowiring leaves it null and the shipped themes are used.
     */
    public function __construct(private readonly ?string $themesDir = null)
    {
    }

    /**
     * @return array<string, string> id => label
     */
    public function all(): array
    {
        $themes = [];

        foreach (glob($this->directory().'/*.yaml') ?: [] as $file) {
            $id            = basename($file, '.yaml');
            $themes[$id]   = $this->label($file) ?? $id;
        }

        ksort($themes);

        return $themes ?: [self::DEFAULT_THEME => self::DEFAULT_THEME];
    }

    public function has(string $id): bool
    {
        // The id reaches a filesystem path and arrives from a request payload,
        // so it is matched against the listing rather than tested on disk.
        return array_key_exists($id, $this->all());
    }

    /**
     * The theme to actually use: what was asked for, else what is configured,
     * else the one that always exists.
     */
    public function resolve(?string $requested, string $configured): string
    {
        if (null !== $requested) {
            if ($this->has($requested)) {
                return $requested;
            }

            // Mautic themes come in variants that share one visual identity
            // (mytheme-pt, mytheme-en). Peel dash-separated segments off
            // the end until a known family remains, so a language variant
            // still lands on its token file.
            $base = $requested;
            while (false !== ($cut = strrpos($base, '-'))) {
                $base = substr($base, 0, $cut);

                if ($this->has($base)) {
                    return $base;
                }
            }
        }

        return $this->has($configured) ? $configured : self::DEFAULT_THEME;
    }

    /**
     * Read from the file's `name:` line rather than by parsing the YAML: nothing
     * else in the plugin parses these files, and a malformed one should still be
     * listed under its file name instead of taking the whole panel down.
     */
    private function label(string $file): ?string
    {
        $handle = fopen($file, 'r');

        if (false === $handle) {
            return null;
        }

        try {
            while (false !== ($line = fgets($handle))) {
                if (1 === preg_match('~^name:\s*(.+?)\s*$~', $line, $matches)) {
                    return trim($matches[1], '"\'') ?: null;
                }
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function directory(): string
    {
        return $this->themesDir ?? __DIR__.'/../Resources/prompts/themes';
    }
}
