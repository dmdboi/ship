<?php

namespace Dmdboi\Ship\Publishers;

use Illuminate\Filesystem\Filesystem;

/**
 * Handles publishing files with overwrite logic.
 */
class FilePublisher
{
    public function __construct(
        protected Filesystem $files,
        protected string $basePath,
        protected bool $force = false,
        protected bool $defaults = false,
    ) {}

    /**
     * Publish content to a file.
     *
     * @param  callable|null  $confirmCallback  Callback to ask for confirmation (returns bool)
     * @return bool True if file was written, false if skipped
     */
    public function publish(string $path, string $content, ?callable $confirmCallback = null): bool
    {
        $fullPath = $this->resolvePath($path);

        if ($this->files->exists($fullPath) && ! $this->force && ! $this->defaults) {
            if ($confirmCallback && ! $confirmCallback($path)) {
                return false;
            }
        }

        // Ensure directory exists
        $directory = dirname($fullPath);
        if (! $this->files->isDirectory($directory)) {
            $this->files->makeDirectory($directory, 0755, true);
        }

        $this->files->put($fullPath, $content);

        if (str_ends_with($fullPath, '.sh')) {
            $this->files->chmod($fullPath, 0755);
        }

        return true;
    }

    /**
     * Publish a stub file with replacements.
     *
     * @param  array<string, string>  $replacements
     * @return bool True if file was written, false if skipped
     */
    public function publishStub(string $stubName, string $destinationPath, array $replacements = [], ?callable $confirmCallback = null): bool
    {
        $content = $this->getStub($stubName);
        $content = $this->applyReplacements($content, $replacements);

        return $this->publish($destinationPath, $content, $confirmCallback);
    }

    /**
     * Ensure directories exist.
     *
     * @param  array<int, string>  $directories
     */
    public function ensureDirectoriesExist(array $directories): void
    {
        foreach ($directories as $directory) {
            $fullPath = $this->resolvePath($directory);
            if (! $this->files->isDirectory($fullPath)) {
                $this->files->makeDirectory($fullPath, 0755, true);
            }
        }
    }

    /**
     * Get stub content by name.
     */
    public function getStub(string $name): string
    {
        $path = __DIR__.'/../../stubs/'.$name.'.stub';

        if (! $this->files->exists($path)) {
            throw new \RuntimeException("Stub file not found: {$name}");
        }

        return $this->files->get($path);
    }

    /**
     * Apply replacements to content.
     *
     * @param  array<string, string>  $replacements
     */
    protected function applyReplacements(string $content, array $replacements): string
    {
        foreach ($replacements as $placeholder => $value) {
            $content = str_replace($placeholder, $value, $content);
        }

        return $content;
    }

    /**
     * Resolve a path relative to base path.
     */
    protected function resolvePath(string $path): string
    {
        if (str_starts_with($path, '/')) {
            return $path;
        }

        return $this->basePath.'/'.$path;
    }

    /**
     * Create a new instance with updated flags.
     */
    public function withFlags(bool $force, bool $defaults): self
    {
        return new self($this->files, $this->basePath, $force, $defaults);
    }
}
