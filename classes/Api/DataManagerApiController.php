<?php

namespace Grav\Plugin\DataManager\Api;

use Grav\Plugin\Api\Controllers\AbstractApiController;
use Grav\Plugin\Api\Response\ApiResponse;
use Grav\Plugin\Api\Exceptions\NotFoundException;
use Grav\Plugin\DataManager\DataManager;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * Admin-next / API endpoints for the Data Manager plugin.
 *
 * Exposes the flat-file data store (user/data) as JSON so the admin-next
 * SvelteKit UI can browse data types, list items in a table, inspect a single
 * item, delete items and export a type as CSV.
 */
class DataManagerApiController extends AbstractApiController
{
    protected function manager(): DataManager
    {
        return new DataManager($this->grav);
    }

    /**
     * GET /data-manager/config
     * UI-relevant configuration for the admin-next page component.
     */
    public function config(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $user = $this->getUser($request);

        return ApiResponse::create([
            // Delete is gated on write access; the UI hides the control
            // otherwise. Super-admins pass requirePermission() unconditionally,
            // so mirror that here rather than only checking the explicit grant.
            'can_delete' => $this->isSuperAdmin($user) || $this->hasPermission($user, 'api.system.write'),
        ]);
    }

    /**
     * GET /data-manager/types
     * List of data types (folders) with item counts.
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        return ApiResponse::create([
            'types' => $this->manager()->getDataTypes(),
        ]);
    }

    /**
     * GET /data-manager/types/{type}
     * Items in a data type, with derived/configured columns for the table.
     */
    public function items(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $manager = $this->manager();
        $type = $manager->sanitizeSegment($this->getRouteParam($request, 'type'));
        if ($type === null) {
            throw new NotFoundException('Unknown data type');
        }

        $dataPath = $manager->getDataPath();
        if (!$dataPath || !is_dir($dataPath . '/' . $type)) {
            throw new NotFoundException("Data type '{$type}' not found");
        }

        $items = $manager->getDataType($type);
        $columns = $manager->getColumns($type, $items);

        // Pre-resolve each column value so the client renders a plain table.
        $rows = [];
        foreach ($items as $item) {
            $values = [];
            foreach ($columns as $column) {
                $values[$column['key']] = $manager->resolveColumnValue($column['field'], $item['content']);
            }
            $rows[] = [
                'name'     => $item['name'],
                'file'     => $item['file'],
                'size'     => $item['size'],
                'modified' => $item['modified'],
                'values'   => $values,
            ];
        }

        return ApiResponse::create([
            'type'    => $type,
            'name'    => $manager->getTypeLabel($type),
            'columns' => $columns,
            'items'   => $rows,
            'count'   => count($rows),
        ]);
    }

    /**
     * GET /data-manager/types/{type}/items/{item}
     * A single data item: parsed content plus raw source.
     */
    public function show(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $manager = $this->manager();
        $type = $manager->sanitizeSegment($this->getRouteParam($request, 'type'));
        $item = $manager->sanitizeSegment($this->getRouteParam($request, 'item'));
        if ($type === null || $item === null) {
            throw new NotFoundException('Item not found');
        }

        $content = $manager->getFileContent($type, $item);
        if ($content === null && $manager->getRawContent($type, $item) === null) {
            throw new NotFoundException('Item not found');
        }

        $dot = strrpos($item, '.');

        return ApiResponse::create([
            'type'      => $type,
            'name'      => $dot !== false ? substr($item, 0, $dot) : $item,
            'file'      => $item,
            'extension' => $manager->getExtension($type, $item),
            'content'   => $content,
            'raw'       => $manager->getRawContent($type, $item),
        ]);
    }

    /**
     * DELETE /data-manager/types/{type}/items/{item}
     * Remove a single data file.
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.write');

        $manager = $this->manager();
        $type = $manager->sanitizeSegment($this->getRouteParam($request, 'type'));
        $item = $manager->sanitizeSegment($this->getRouteParam($request, 'item'));
        if ($type === null || $item === null) {
            throw new NotFoundException('Item not found');
        }

        if (!$manager->deleteItem($type, $item)) {
            throw new NotFoundException('Item not found');
        }

        return ApiResponse::create([
            'deleted' => true,
            'file'    => $item,
            'toast'   => [
                'message' => "Deleted {$item}",
                'type'    => 'success',
            ],
        ]);
    }

    /**
     * GET /data-manager/types/{type}/export
     * Download a data type as CSV.
     */
    public function export(ServerRequestInterface $request): ResponseInterface
    {
        $this->requirePermission($request, 'api.system.read');

        $manager = $this->manager();
        $type = $manager->sanitizeSegment($this->getRouteParam($request, 'type'));
        if ($type === null) {
            throw new NotFoundException('Unknown data type');
        }

        $dataPath = $manager->getDataPath();
        if (!$dataPath || !is_dir($dataPath . '/' . $type)) {
            throw new NotFoundException("Data type '{$type}' not found");
        }

        $csv = $manager->buildCsv($type) ?? '';
        $filename = $type . '-' . date('Y-m-d') . '.csv';

        return new \Grav\Framework\Psr7\Response(200, [
            'Content-Type'        => 'text/csv; charset=utf-8',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ], $csv);
    }
}
