<?php

namespace Hananils\Plugins;

use Kirby\Cms\App as Kirby;
use Kirby\Data\Data;
use Kirby\Data\Json;

class PluginManager
{
    private string $id;
    private string $root;
    private string $manifest;
    private array $info;
    private string $version = '';
    private string $name = '';
    private array $configuration = [];

    public function __construct(string $id, string $root)
    {
        $this->id = $id;
        $this->root = $root;
        $this->manifest = $this->root . '/composer.json';
        $this->info = Data::read($this->manifest, fail: false);

        // Initialize plugin
        Kirby::plugin(
            name: $this->name(),
            extends: $this->configuration(),
            root: $root,
            version: $this->version()
        );
    }

    public static function autoload(string $id, string $root)
    {
        return new self($id, $root);
    }

    public function name()
    {
        if ($this->name === '') {
            $this->name = $this->id;

            if (!str_contains($this->name, '/')) {
                $this->name = 'hananils/' . $this->id;
            }
        }

        return $this->name;
    }

    public function version()
    {
        if ($this->version === '') {
            $this->version = $this->info['version'] ?? '0.0.0dev';
        }

        return $this->version;
    }

    public function configuration()
    {
        // Discover plugin configuration
        $this->discoverTranslations();
        $this->discoverReferences('blueprints');
        $this->discoverReferences('snippets');
        $this->discoverDefinitions('config');
        $this->discoverDefinitions('methods', 'Methods');

        return array_filter($this->configuration);
    }

    private function discoverTranslations(): void
    {
        $path = $this->root . '/translations';
        $directory = new Discovery($path);

        $this->ensureGroup('translations');

        foreach ($directory as $file) {
            $name = $this->getName($file);
            $pathname = $file->getPathname();

            if ($file->getExtension() === 'json') {
                $translations = Json::read($pathname);
            } else {
                $translations = require $pathname;
            }

            $this->configuration['translations'][$name] = $translations;
        }
    }

    private function discoverReferences(string $group)
    {
        $path = $this->root . "/$group";
        $directory = new Discovery($path);
        $id = $this->id;

        $this->ensureGroup($group);

        foreach ($directory as $file) {
            if ($file->isDir()) {
                $subdirectory = new Discovery($file->getPathname());
                $subfolder = $file->getFilename();

                foreach ($subdirectory as $subfile) {
                    $name = $this->getName($subfile);

                    // It's important to list the subfolder first to comply
                    // with Kirby's naming conventions. For instance, file
                    // blueprints are expected to be located at `/files`
                    // without any prefix
                    $this->configuration[$group][
                        "$subfolder/$id/$name"
                    ] = $subfile->getPathname();
                }

                continue;
            }

            $name = $this->getName($file);

            $this->configuration[$group]["$id/$name"] = $file->getPathname();
        }
    }

    private function discoverDefinitions(
        string $group,
        string $suffix = ''
    ): void {
        $path = $this->root . "/$group";
        $directory = new Discovery($path);

        foreach ($directory as $file) {
            $name = $this->getName($file);

            if ($suffix !== '') {
                $name .= $suffix;
            }

            $this->configuration[$name] = require $file->getPathname();
        }
    }

    private function ensureGroup($group)
    {
        if (!isset($this->configuration[$group])) {
            $this->configuration[$group] = [];
        }
    }

    private function getName($file)
    {
        return $file->getBasename('.' . $file->getExtension());
    }
}
