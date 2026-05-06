<?php

namespace Sanjid29\StarterCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Facades\DataTables;

abstract class BaseDataTableController extends Controller
{
    use AuthorizesRequests;

    protected string $model;

    protected string $routePrefix;

    protected array $withRelations = [];

    /**
     * Raw HTML columns defined by child. Merged with base defaults — never override base.
     */
    protected array $rawColumns = [];

    /**
     * Columns that are always raw regardless of child config.
     */
    private array $baseRawColumns = ['action'];

    /**
     * Override to gate the datatable endpoint.
     */
    protected function authorizeDataTable(): void {}

    /**
     * Base query. Override to add ordering, scopes, etc.
     */
    protected function indexQuery(): Builder
    {
        return $this->model::query()->with($this->withRelations);
    }

    /**
     * Recycle bin query — only trashed records.
     */
    protected function recycleBinQuery(): Builder
    {
        return $this->model::onlyTrashed()->with($this->withRelations);
    }

    /**
     * Custom columns: ['columnName' => fn($record) => string]
     */
    protected function dataTableColumns(): array
    {
        return [];
    }

    /**
     * Override to apply additional filters (search, date range, status, etc.)
     */
    protected function applyFilters(EloquentDataTable $dt, Request $request): EloquentDataTable
    {
        return $dt;
    }

    public function tableColumns(): array
    {
        return [
            [
                'data' => 'DT_RowIndex',
                'name' => 'DT_RowIndex',
                'label' => '#',
                'width' => '50',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-center',
            ],
            [
                'data' => 'id',
                'name' => 'id',
                'label' => 'ID',
                'width' => '60',
                'className' => 'text-center',
            ],
            [
                'data' => 'name',
                'name' => 'name',
                'label' => 'Name',
            ],
            [
                'data' => 'is_active',
                'name' => 'is_active',
                'label' => 'Status',
                'orderable' => true,
                'searchable' => false,
                'className' => 'text-center',
            ],
            [
                'data' => 'action',
                'name' => 'action',
                'label' => 'Actions',
                'width' => '180',
                'orderable' => false,
                'searchable' => false,
                'className' => 'text-center',
            ],
        ];
    }

    /**
     * Action buttons column. Override for modules that need view/extra buttons.
     */
    protected function actionColumn(mixed $record): string
    {
        $editUrl = route("{$this->routePrefix}.edit", $record);
        $deleteUrl = route("{$this->routePrefix}.destroy", $record);

        return '
            <div class="btn-group btn-group-sm">
                <a href="'.$editUrl.'" class="btn btn-primary" title="Edit">
                    <i class="bi bi-pencil"></i>
                </a>
                <button type="button" class="btn btn-danger btn-delete"
                    data-url="'.$deleteUrl.'"
                    data-name="'.e($this->getRecordName($record)).'" title="Delete">
                    <i class="bi bi-trash"></i>
                </button>
            </div>
        ';
    }

    protected function getRecordName(mixed $record): string
    {
        return $record->name ?? $record->title ?? (string) $record->id;
    }

    /**
     * Action buttons for recycle bin rows — Restore + Force Delete.
     */
    protected function recycleBinActionColumn(mixed $record): string
    {
        $restoreUrl = route("{$this->routePrefix}.restore", $record->getKey());
        $forceDeleteUrl = route("{$this->routePrefix}.force-delete", $record->getKey());

        return '
            <div class="btn-group btn-group-sm">
                <button type="button" class="btn btn-success btn-restore"
                    data-url="'.$restoreUrl.'"
                    data-name="'.e($this->getRecordName($record)).'" title="Restore">
                    <i class="bi bi-arrow-counterclockwise"></i>
                </button>
                <button type="button" class="btn btn-danger btn-force-delete"
                    data-url="'.$forceDeleteUrl.'"
                    data-name="'.e($this->getRecordName($record)).'" title="Delete Permanently">
                    <i class="bi bi-x-octagon"></i>
                </button>
            </div>
        ';
    }

    public function recycleBinDatatable(Request $request): JsonResponse
    {
        abort_unless($request->ajax(), 403);

        $this->authorizeDataTable();

        /** @var EloquentDataTable $dt */
        $dt = DataTables::of($this->recycleBinQuery())->addIndexColumn();

        foreach ($this->dataTableColumns() as $column => $callback) {
            $dt->addColumn($column, $callback);
        }

        $dt->addColumn('action', fn ($record) => $this->recycleBinActionColumn($record));

        $dt = $this->applyFilters($dt, $request);

        $dt->rawColumns(array_unique(array_merge($this->baseRawColumns, $this->rawColumns)));

        return $dt->make(true);
    }

    public function datatable(Request $request): JsonResponse
    {
        abort_unless($request->ajax(), 403);

        $this->authorizeDataTable();

        /** @var EloquentDataTable $dt */
        $dt = DataTables::of($this->indexQuery())->addIndexColumn();

        foreach ($this->dataTableColumns() as $column => $callback) {
            $dt->addColumn($column, $callback);
        }

        $dt->addColumn('action', fn ($record) => $this->actionColumn($record));

        $dt = $this->applyFilters($dt, $request);

        $dt->rawColumns(array_unique(array_merge($this->baseRawColumns, $this->rawColumns)));

        return $dt->make(true);
    }
}
