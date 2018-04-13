<?php
namespace Grav\Plugin;

use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;

use Grav\Common\Plugin;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Plugin\Admin\Admin;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\File\JsonFile;

class DataManagerPlugin extends Plugin
{
    protected $route = 'data-manager';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => ['onPluginsInitialized', 0],
        ];
    }

    /**
     * Enable only if url matches to the configuration.
     */
    public function onPluginsInitialized()
    {
        if (!$this->isAdmin()) {
            return;
        }

        $this->enable([
            'onPagesInitialized' => ['onPagesInitialized', 0],
            'onTwigTemplatePaths' => ['onTwigTemplatePaths', 0],
            'onAdminMenu' => ['onAdminMenu', 0],
        ]);
    }

    /**
     * @throws \Exception
     */
    public function onPagesInitialized()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        if (strpos($uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $pathParts = $uri->paths();
        $extension = '.' . $uri->extension();
        $csv = false;

        if (isset($pathParts[1]) && $pathParts[1] === $this->route) {
            $type = isset($pathParts[2]) ? $pathParts[2] : null;
            if (preg_match( '/\.csv$/', $type)) {
                $type = basename($type, '.csv');
                $file = null;
                $csv = true;
            } else {
                $file = isset($pathParts[3]) ? $pathParts[3] : null;
                $ext = $this->getExtension($type, $file);
                if ($extension && $ext !== $extension) {
                    $filename = $file . $extension;
                } else {
                    $filename = $file;
                }
            }

            if ($file && !$csv) {
                // Individual data entry.
                $twig->itemData = $this->getFileContent($type, $filename);
            } elseif ($type) {
                // List of data entries.
                $twig->items = $this->getDataType($type);
            } else {
                // List of data types.
                $twig->types = $this->getDataTypes();
            }
        }

        // Handle CSV call
        if (isset($twig->items) && $csv) {

            $data = array_column($twig->items, 'content');

            $this->downloadCSV($data);
        }
    }

    private function getExtension($type, $filename)
    {
        $extension = $this->config->get("plugins.data-manager.types.{$type}.file_extension");
        if (!$extension) {
            $pos = strrpos($filename, '.');
            $extension = $pos ? substr($filename, $pos) : null;
        }

        return $extension;
    }

    /**
     * Given a data file route, return the file content.
     *
     * @param string $type
     * @param string $filename
     * @return array|string|null
     */
    private function getFileContent($type, $filename)
    {
        $extension = $this->getExtension($type, $filename);

        switch ($extension) {
            case '.txt':
            case '.yaml':
                $file = CompiledYamlFile::instance(DATA_DIR . $type . '/' . $filename);
                break;
            case '.json':
                $file = JsonFile::instance(DATA_DIR . $type . '/' . $filename);
                break;
            case '.html':
            default:
                $file = File::instance(DATA_DIR . $type . '/' . $filename);
        }

        if (!$file->exists()) {
            return null;
        }

        try {
            return $file->content();
        } catch (\Exception $e) {
            return $file->raw();
        }
    }

    /**
     * @param string $type
     * @return array
     */
    protected function getDataType($type)
    {
        $extension = $this->config->get("plugins.data-manager.types.{$type}.file_extension");

        $items = [];
        $fileIterator = new \FilesystemIterator(DATA_DIR . $type, \FilesystemIterator::SKIP_DOTS);
        /** @var \FilesystemIterator $entry */
        foreach ($fileIterator as $entry) {
            $file = $entry->getFilename();
            if (!$entry->isFile() || ($extension && !preg_match('/' . preg_quote($extension, '/') . '$/', $file))) {
                // Is not file or file extension does not match.
                continue;
            }

            $name = substr($file, 0, strrpos($file, '.'));
            $items[] = [
                'name' => $name,
                'route' => $file,
                'content' => $this->getFileContent($type, $file)
            ];
        }

        return $this->sortArrayByKey($items, 'name', SORT_DESC, SORT_NATURAL);
    }

    /**
     * @return array
     */
    protected function getDataTypes()
    {
        $types = [];
        $entry = null;

        //Find data types excluded by plugins
        $this->grav->fireEvent('onDataTypeExcludeFromDataManagerPluginHook');

        $typesIterator = new \FilesystemIterator(DATA_DIR, \FilesystemIterator::SKIP_DOTS);
        foreach ($typesIterator as $type) {
            $typeName = $type->getFilename();
            if ($typeName[0] === '.') {
                continue;
            }

            if (!is_dir(DATA_DIR . $typeName)) {
                continue;
            }

            $iterator = new \FilesystemIterator(DATA_DIR . $typeName, \FilesystemIterator::SKIP_DOTS);
            $count = 0;
            foreach ($iterator as $fileinfo) {
                if ($fileinfo->getFilename()[0] === '.') {
                    continue;
                }
                $count++;
            }

            if (isset($this->grav['admin']->dataTypesExcludedFromDataManagerPlugin)) {
                if (!\in_array($typeName, $this->grav['admin']->dataTypesExcludedFromDataManagerPlugin, true)) {
                    $types[$typeName] = $count;
                }
            } else {
                $types[$typeName] = $count;
            }
        }

        return $types;
    }

    /**
     * @param array $data
     * @throws \Exception
     */
    protected function downloadCSV(array $data)
    {
        $csv_data = $this->arrayToCsv($data);

        /** @var File $csv_file */
        $tmp_dir  = Admin::getTempDir();
        $tmp_file = uniqid() . '.csv';
        $tmp      = $tmp_dir . '/data-manager/' . basename($tmp_file);

        Folder::create(dirname($tmp));

        $csv_file = File::instance($tmp_file);
        $csv_file->save($csv_data);
        Utils::download($csv_file->filename(), true);
        exit;
    }

    /**
     * Add plugin templates path
     */
    public function onTwigTemplatePaths()
    {
        $this->grav['twig']->twig_paths[] = __DIR__ . '/admin/templates';
    }

    /**
     * Add navigation item to the admin plugin
     */
    public function onAdminMenu()
    {
        $this->grav['twig']->plugins_hooked_nav['PLUGIN_DATA_MANAGER.DATA_MANAGER'] = ['route' => $this->route, 'icon' => 'fa-database'];
    }

    /**
     * sort a multidimensional array by a key
     * Local version until Grav 1.4.3 is released
     *
     * @param $array
     * @param $array_key
     * @param int $direction
     * @param int $sort_flags
     * @return array
     */
    public function sortArrayByKey($array, $array_key, $direction = SORT_DESC, $sort_flags = SORT_REGULAR)
    {
        $output = [];

        if (!is_array($array) || !$array) {
            return $output;
        }

        foreach ($array as $key => $row) {
            $output[$key] = $row[$array_key];
        }

        array_multisort($output, $direction, $sort_flags, $array);

        return $array;
    }

    /**
     *
     *
     * @param array $array
     * @return null|string
     */
    private function arrayToCsv(array $array)
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

        $fields = array_map(function() { return ''; }, call_user_func_array('array_replace', $rows));
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

    private function csvFlatten($row)
    {
        if (!is_array($row)) {
            return [];
        }
        if (isset($row['_data_type'], $row['content']) && is_array($row['content'])) {
            return ['timestamp' => $row['timestamp']] + $this->arrayFlatten($row['content']);
        }

        $flat_data = [];
        foreach ($row as $key => $item) {
            if (is_array($item)) {
                $flat_data[$key] = json_encode($item);
            } else {
                $flat_data[$key] = $item;
            }
        }

        return $flat_data;
    }

    /**
     * Flatten an array
     *
     * @param array $array
     * @param string $prefix
     * @return array
     */
    private function arrayFlatten($array, $prefix = '')
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
}
