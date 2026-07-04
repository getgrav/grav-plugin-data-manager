<?php
namespace Grav\Plugin;

use Composer\Autoload\ClassLoader;
use Grav\Common\File\CompiledYamlFile;
use Grav\Common\Filesystem\Folder;

use Grav\Common\Plugin;
use Grav\Common\Twig\Twig;
use Grav\Common\Uri;
use Grav\Common\Utils;
use Grav\Plugin\Admin\Admin;
use Grav\Plugin\DataManager\Api\DataManagerApiController;
use RocketTheme\Toolbox\File\File;
use RocketTheme\Toolbox\File\JsonFile;
use RocketTheme\Toolbox\Event\Event;

class DataManagerPlugin extends Plugin
{
    protected $route = 'data-manager';

    /**
     * @return array
     */
    public static function getSubscribedEvents()
    {
        return [
            'onPluginsInitialized' => [
                ['autoload', 100001],
                ['onPluginsInitialized', 0],
            ],
            // Admin-next / API integration (Grav 2.0). These events only fire
            // during API requests, so they are registered unconditionally —
            // never gate them on isAdmin() (the AdminProxy isn't registered
            // yet at onPluginsInitialized time on the API path).
            'onApiRegisterRoutes' => ['onApiRegisterRoutes', 0],
            'onApiSidebarItems'   => ['onApiSidebarItems', 0],
            'onApiPluginPageInfo' => ['onApiPluginPageInfo', 0],
        ];
    }

    /**
     * Composer autoloader for the plugin's own classes.
     */
    public function autoload(): ClassLoader
    {
        return require __DIR__ . '/vendor/autoload.php';
    }

    /**
     * Enable classic-admin (Grav 1.7) integration only when needed.
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

    // ── Admin-next / API integration ────────────────────────────────────────

    /**
     * Register the REST routes consumed by the admin-next UI.
     */
    public function onApiRegisterRoutes(Event $event): void
    {
        $routes = $event['routes'];
        $controller = DataManagerApiController::class;

        // Static routes first, then more-specific parameterized routes before
        // the catch-all {type} route (FastRoute matching order).
        $routes->get('/data-manager/config', [$controller, 'config']);
        $routes->get('/data-manager/types', [$controller, 'index']);
        $routes->get('/data-manager/types/{type}/export', [$controller, 'export']);
        $routes->get('/data-manager/types/{type}/items/{item}', [$controller, 'show']);
        $routes->delete('/data-manager/types/{type}/items/{item}', [$controller, 'delete']);
        $routes->get('/data-manager/types/{type}', [$controller, 'items']);
    }

    /**
     * Add the Data Manager entry to the admin-next sidebar.
     */
    public function onApiSidebarItems(Event $event): void
    {
        $items = $event['items'] ?? [];
        $items[] = [
            'id'        => 'data-manager',
            'plugin'    => 'data-manager',
            'label'     => 'Data Manager',
            'icon'      => 'fa-database',
            'route'     => '/plugin/data-manager',
            'priority'  => 2,
            'authorize' => 'api.system.read',
        ];
        $event['items'] = $items;
    }

    /**
     * Describe the admin-next plugin page (custom tabular web component).
     */
    public function onApiPluginPageInfo(Event $event): void
    {
        if ($event['plugin'] !== 'data-manager') {
            return;
        }

        $event['definition'] = [
            'id'        => 'data-manager',
            'plugin'    => 'data-manager',
            'title'     => 'Data Manager',
            'icon'      => 'fa-database',
            'page_type' => 'component',
        ];
    }

    /**
     * @throws \Exception
     */
    public function onPagesInitialized()
    {
        /** @var Uri $uri */
        $uri = $this->grav['uri'];

        // Get data path
        $locator = $this->grav['locator'];
        $path = $locator->findResource('user-data://', true);


        if (strpos((string) $uri->path(), $this->config->get('plugins.admin.route') . '/' . $this->route) === false) {
            return;
        }

        /** @var Twig $twig */
        $twig = $this->grav['twig'];
        $pathParts = $uri->paths();
        $extension = '.' . $uri->extension();
        $csv = false;

        if (isset($pathParts[1]) && $pathParts[1] === $this->route) {
            $type = isset($pathParts[2]) ? $pathParts[2] : null;
            if (preg_match( '/\.csv$/', (string) $type)) {
                $type = basename((string) $type, '.csv');
                $file = null;
                $csv = true;
            } else {
                $file = isset($pathParts[3]) ? $uri->basename() : null;
                $ext = $this->getExtension($type, $file);
                if ($extension && $ext !== $extension) {
                    $filename = $file . $extension;
                } else {
                    $filename = $file;
                }
            }

            if ($file && !$csv) {
                // handle delete
                if ($uri->query('delete') !== null) {
                    $fileObj = new \Grav\Framework\File\File(
                        sprintf('%s/%s/%s', $path, $type, $filename)
                    );

                    if ($fileObj) {
                        $paths = $uri->paths();
                        array_pop($paths);

                        $fileObj->delete();
                        $this->grav->redirect(implode('/', $paths), 301);
                    }
                }

                // Individual data entry.
                $twig->itemData = $this->getFileContent($type, $filename, $path);
            } elseif ($type) {
                // List of data entries.
                $twig->items = $this->getDataType($type, $path);
            } else {
                // List of data types.
                $twig->types = $this->getDataTypes($path);
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
            $pos = strrpos((string) $filename, '.');
            $extension = $pos ? substr((string) $filename, $pos) : null;
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
    private function getFileContent($type, $filename, $path)
    {
        $extension = $this->getExtension($type, $filename);

        switch ($extension) {
            case '.txt':
            case '.yaml':
                $file = CompiledYamlFile::instance($path . '/' . $type . '/' . $filename);
                break;
            case '.json':
                $file = JsonFile::instance($path . '/' . $type . '/' . $filename);
                break;
            case '.html':
            default:
                $file = File::instance($path . '/' . $type . '/' . $filename);
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
    protected function getDataType($type, $path)
    {
        $extension = $this->config->get("plugins.data-manager.types.{$type}.file_extension");

        $items = [];
        $fileIterator = new \FilesystemIterator($path . '/' . $type, \FilesystemIterator::SKIP_DOTS);
        /** @var \FilesystemIterator $entry */
        foreach ($fileIterator as $entry) {
            $file = $entry->getFilename();
            if (!$entry->isFile() || ($extension && !preg_match('/' . preg_quote((string) $extension, '/') . '$/', $file))) {
                // Is not file or file extension does not match.
                continue;
            }

            $name = substr($file, 0, strrpos($file, '.'));
            $items[] = [
                'name' => $name,
                'route' => $file,
                'content' => $this->getFileContent($type, $file, $path)
            ];
        }

        return $this->sortArrayByKey($items, 'name', SORT_DESC, SORT_NATURAL);
    }

    /**
     * @return array
     */
    protected function getDataTypes($path)
    {
        $types = [];
        $entry = null;

        //Find data types excluded by plugins
        $this->grav->fireEvent('onDataTypeExcludeFromDataManagerPluginHook');

        $typesIterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($typesIterator as $type) {
            $typeName = $type->getFilename();
            if ($typeName[0] === '.') {
                continue;
            }

            if (!is_dir($path . '/' . $typeName)) {
                continue;
            }

            $iterator = new \FilesystemIterator($path . '/' . $typeName, \FilesystemIterator::SKIP_DOTS);
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

        $csv_file = File::instance($tmp);
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
