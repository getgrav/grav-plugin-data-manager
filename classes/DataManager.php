<?php

namespace Grav\Plugin\DataManager;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Grav;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\File\JsonFile;

/**
 * Core data-access layer for the Data Manager plugin.
 *
 * Reads the flat-file "data" store (user/data) and exposes it as data types
 * (sub-folders), items (files) and item contents. Shared by both the classic
 * admin Twig UI and the admin-next / API integration so the two never drift.
 *
 * Every folder/file segment that arrives from a URL MUST be passed through
 * sanitizeSegment() before it touches the filesystem — see resolvePath().
 */
class DataManager
{
    /** @var Grav */
    protected $grav;

    public function __construct(Grav $grav)
    {
        $this->grav = $grav;
    }

    /**
     * Absolute path to the user data folder (user/data), or null if missing.
     */
    public function getDataPath(): ?string
    {
        $path = $this->grav['locator']->findResource('user-data://', true);

        return $path ?: null;
    }

    /**
     * Reject any segment that could escape the data folder. Returns the clean
     * segment, or null if the segment is unsafe (traversal, slash, hidden).
     */
    public function sanitizeSegment(?string $segment): ?string
    {
        if ($segment === null || $segment === '') {
            return null;
        }

        // basename() strips any directory portion; then reject anything that
        // still looks like traversal or a hidden/dot entry.
        $clean = basename($segment);
        if ($clean === '' || $clean === '.' || $clean === '..') {
            return null;
        }
        if ($clean[0] === '.' || strpbrk($clean, "/\\") !== false) {
            return null;
        }

        return $clean;
    }

    /**
     * Build a safe absolute path within the data folder from URL segments.
     * Any unsafe segment aborts with null.
     */
    protected function resolvePath(string ...$segments): ?string
    {
        $base = $this->getDataPath();
        if (!$base) {
            return null;
        }

        $parts = [$base];
        foreach ($segments as $segment) {
            $clean = $this->sanitizeSegment($segment);
            if ($clean === null) {
                return null;
            }
            $parts[] = $clean;
        }

        return implode('/', $parts);
    }

    /**
     * List of data types (sub-folders) with their item counts.
     *
     * @return array<int, array{type:string, name:string, count:int}>
     */
    public function getDataTypes(): array
    {
        $base = $this->getDataPath();
        if (!$base) {
            return [];
        }

        // Let other plugins exclude their private data folders (e.g. comments).
        $excluded = $this->getExcludedTypes();

        $types = [];
        $iterator = new \FilesystemIterator($base, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $name = $entry->getFilename();
            if ($name === '' || $name[0] === '.' || !$entry->isDir()) {
                continue;
            }
            if (\in_array($name, $excluded, true)) {
                continue;
            }

            $types[] = [
                'type'  => $name,
                'name'  => $this->getTypeLabel($name),
                'count' => $this->countItems($base . '/' . $name, $name),
            ];
        }

        usort($types, static fn($a, $b) => strnatcasecmp($a['name'], $b['name']));

        return $types;
    }

    /**
     * Number of matching data files inside a type folder.
     */
    protected function countItems(string $dir, string $type): int
    {
        $extension = $this->config("types.{$type}.file_extension");

        $count = 0;
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $file = $entry->getFilename();
            if ($file === '' || $file[0] === '.' || !$entry->isFile()) {
                continue;
            }
            if ($extension && !$this->matchesExtension($file, $extension)) {
                continue;
            }
            $count++;
        }

        return $count;
    }

    /**
     * All items (files) inside a data type, each with parsed content.
     *
     * @return array<int, array{name:string, file:string, content:mixed, size:int, modified:int}>
     */
    public function getDataType(string $type): array
    {
        $dir = $this->resolvePath($type);
        if (!$dir || !is_dir($dir)) {
            return [];
        }

        $extension = $this->config("types.{$type}.file_extension");

        $items = [];
        $iterator = new \FilesystemIterator($dir, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $entry) {
            $file = $entry->getFilename();
            if ($file === '' || $file[0] === '.' || !$entry->isFile()) {
                continue;
            }
            if ($extension && !$this->matchesExtension($file, $extension)) {
                continue;
            }

            $dot = strrpos($file, '.');
            $items[] = [
                'name'     => $dot !== false ? substr($file, 0, $dot) : $file,
                'file'     => $file,
                'content'  => $this->getFileContent($type, $file),
                'size'     => $entry->getSize() ?: 0,
                'modified' => $entry->getMTime() ?: 0,
            ];
        }

        usort($items, static fn($a, $b) => strnatcasecmp((string) $b['name'], (string) $a['name']));

        return $items;
    }

    /**
     * Parsed content of a single data file, or null if it doesn't exist.
     *
     * @return array|string|null
     */
    public function getFileContent(string $type, string $filename)
    {
        $path = $this->resolvePath($type, $filename);
        if (!$path) {
            return null;
        }

        $extension = $this->getExtension($type, $filename);

        switch ($extension) {
            case '.txt':
            case '.yaml':
                $file = CompiledYamlFile::instance($path);
                break;
            case '.json':
                $file = JsonFile::instance($path);
                break;
            default:
                $file = File::instance($path);
        }

        if (!$file->exists()) {
            return null;
        }

        try {
            // JsonFile decodes nested structures to stdClass; normalize the
            // whole tree to arrays so every consumer (columns, CSV, detail)
            // deals with one shape.
            return $this->toArray($file->content());
        } catch (\Throwable $e) {
            return $file->raw();
        }
    }

    /**
     * Recursively convert stdClass objects to associative arrays.
     *
     * @param mixed $value
     * @return mixed
     */
    protected function toArray($value)
    {
        if (is_object($value)) {
            $value = get_object_vars($value);
        }
        if (is_array($value)) {
            foreach ($value as $key => $inner) {
                $value[$key] = $this->toArray($inner);
            }
        }

        return $value;
    }

    /**
     * Raw (unparsed) contents of a single data file, or null if missing.
     */
    public function getRawContent(string $type, string $filename): ?string
    {
        $path = $this->resolvePath($type, $filename);
        if (!$path || !is_file($path)) {
            return null;
        }

        return File::instance($path)->raw();
    }

    /**
     * Delete a single data file. Returns true on success.
     */
    public function deleteItem(string $type, string $filename): bool
    {
        $path = $this->resolvePath($type, $filename);
        if (!$path || !is_file($path)) {
            return false;
        }

        return @unlink($path);
    }

    /**
     * The configured extension for a type, otherwise inferred from the filename.
     */
    public function getExtension(string $type, ?string $filename = null): ?string
    {
        $extension = $this->config("types.{$type}.file_extension");
        if ($extension) {
            return $extension[0] === '.' ? $extension : '.' . $extension;
        }

        if ($filename) {
            $pos = strrpos($filename, '.');
            if ($pos !== false) {
                return substr($filename, $pos);
            }
        }

        return null;
    }

    protected function matchesExtension(string $file, string $extension): bool
    {
        $extension = $extension[0] === '.' ? $extension : '.' . $extension;

        return (bool) preg_match('/' . preg_quote($extension, '/') . '$/', $file);
    }

    /**
     * Human label for a type: the configured name, else a humanized folder name.
     */
    public function getTypeLabel(string $type): string
    {
        $name = $this->config("types.{$type}.name");
        if (is_string($name) && $name !== '') {
            return $name;
        }

        return ucwords(str_replace(['_', '-'], ' ', $type));
    }

    /**
     * Column definitions for a type's list view.
     *
     * Prefers explicit `types.<type>.list.columns` config; otherwise derives a
     * useful set of columns from the most common top-level scalar keys across
     * the items so any data type still gets a real tabular view.
     *
     * @param array $items Result of getDataType()
     * @return array<int, array{key:string, label:string, field:string|array}>
     */
    public function getColumns(string $type, array $items): array
    {
        $configured = $this->config("types.{$type}.list.columns");
        if (is_array($configured) && $configured) {
            $columns = [];
            foreach ($configured as $i => $column) {
                $field = $column['field'] ?? $i;
                $key = is_array($field) ? implode('.', $field) : (string) $field;
                $columns[] = [
                    'key'   => $key,
                    'label' => $column['label'] ?? ucwords(str_replace(['_', '.'], ' ', $key)),
                    'field' => $field,
                ];
            }
            return $columns;
        }

        return $this->deriveColumns($items);
    }

    /**
     * Derive columns from the union of top-level scalar keys in the items.
     */
    protected function deriveColumns(array $items, int $max = 6): array
    {
        $frequency = [];
        foreach ($items as $item) {
            $content = $item['content'] ?? null;
            // Form submissions nest the real payload under `content`.
            if (is_array($content) && ($content['_data_type'] ?? null) === 'form' && isset($content['content'])) {
                $content = $content['content'];
            }
            if (!is_array($content)) {
                continue;
            }
            foreach ($content as $key => $value) {
                if (is_string($key) && $key !== '' && $key[0] !== '_') {
                    $frequency[$key] = ($frequency[$key] ?? 0) + 1;
                }
            }
        }

        arsort($frequency);
        $keys = array_slice(array_keys($frequency), 0, $max);

        $columns = [];
        foreach ($keys as $key) {
            $columns[] = [
                'key'   => $key,
                'label' => ucwords(str_replace(['_', '-'], ' ', $key)),
                'field' => $key,
            ];
        }

        return $columns;
    }

    /**
     * Resolve a column's display value from an item's content.
     *
     * @param string|array $field A single key, dotted key, or array path.
     * @param array|string|null $content
     * @return string
     */
    public function resolveColumnValue($field, $content): string
    {
        if (is_array($content) && ($content['_data_type'] ?? null) === 'form' && isset($content['content'])) {
            $content = $content['content'];
        }

        $path = is_array($field) ? $field : explode('.', (string) $field);
        $value = $content;
        foreach ($path as $segment) {
            if (is_array($value) && array_key_exists($segment, $value)) {
                $value = $value[$segment];
            } else {
                return '';
            }
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        return (string) ($value ?? '');
    }

    // ── Excluded types (other plugins hiding their private data) ────────────

    /**
     * @return string[]
     */
    protected function getExcludedTypes(): array
    {
        // Give plugins a chance to register exclusions for this request.
        $this->grav->fireEvent('onDataTypeExcludeFromDataManagerPluginHook');

        $admin = $this->grav['admin'] ?? null;
        if ($admin && isset($admin->dataTypesExcludedFromDataManagerPlugin)
            && is_array($admin->dataTypesExcludedFromDataManagerPlugin)) {
            return $admin->dataTypesExcludedFromDataManagerPlugin;
        }

        return [];
    }

    // ── CSV export ──────────────────────────────────────────────────────────

    /**
     * Build a CSV string from a type's items.
     */
    public function buildCsv(string $type): ?string
    {
        $items = $this->getDataType($type);
        $data = array_column($items, 'content');

        return $this->arrayToCsv($data);
    }

    protected function arrayToCsv(array $array): ?string
    {
        $rows = [];
        foreach ($array as $row) {
            $row = $this->csvFlatten($row);
            if ($row) {
                $rows[] = $row;
            }
        }

        if (count($rows) === 0) {
            return null;
        }

        $fields = array_map(static fn() => '', array_replace(...$rows));
        $values = [];
        foreach ($rows as $row) {
            $values[] = array_merge($fields, $row);
        }

        ob_start();
        $df = fopen('php://output', 'wb');
        fputcsv($df, array_keys($fields));
        foreach ($values as $value) {
            fputcsv($df, $value);
        }
        fclose($df);

        return ob_get_clean();
    }

    protected function csvFlatten($row): array
    {
        if (!is_array($row)) {
            return [];
        }
        if (isset($row['_data_type'], $row['content']) && is_array($row['content'])) {
            return ['timestamp' => $row['timestamp'] ?? ''] + $this->arrayFlatten($row['content']);
        }

        $flat = [];
        foreach ($row as $key => $item) {
            $flat[$key] = is_array($item) ? json_encode($item) : $item;
        }

        return $flat;
    }

    protected function arrayFlatten(array $array, string $prefix = ''): array
    {
        $flatten = [];
        foreach ($array as $key => $inner) {
            if (is_array($inner)) {
                $flatten += $this->arrayFlatten($inner, $prefix . $key . '.');
            } else {
                $flatten[$prefix . $key] = $inner;
            }
        }

        return $flatten;
    }

    // ── Helpers ───────────────────────────────────────────────────────────

    /**
     * Read a plugins.data-manager.* config value.
     */
    protected function config(string $key)
    {
        return $this->grav['config']->get("plugins.data-manager.{$key}");
    }
}
