<?php

namespace Sanjid29\StarterCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Yajra\DataTables\EloquentDataTable;
use Yajra\DataTables\Facades\DataTables;

abstract class BaseDataTableController extends Controller
{
    use AuthorizesRequests;

    /** @var class-string<Model> */
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
     * Default / maximum page size for the clean JSON API endpoint.
     */
    protected int $jsonDefaultPerPage = 15;

    protected int $jsonMaxPerPage = 100;

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

    // ─────────────────────────────────────────────
    // Clean REST JSON API — decoupled from DataTables
    // ─────────────────────────────────────────────

    /**
     * Attribute whitelist returned by the JSON API. This is the security
     * boundary — only these keys are serialized. Defaults to the non-HTML
     * columns declared in tableColumns(). Supports dot-paths for eager-loaded
     * relations (e.g. 'category.name') resolved via data_get().
     *
     * @return array<int, string>
     */
    protected function jsonFields(): array
    {
        return collect($this->tableColumns())
            ->pluck('data')
            ->reject(fn ($field) => in_array($field, ['DT_RowIndex', 'action'], true))
            ->values()
            ->all();
    }

    /**
     * Columns searched (OR, LIKE) against the `q` term. Dot-path relation
     * columns are excluded by default — override applyApiFilters() to search
     * relations via whereHas().
     *
     * @return array<int, string>
     */
    protected function jsonSearchColumns(): array
    {
        return collect($this->tableColumns())
            ->filter(fn ($col) => ($col['searchable'] ?? true)
                && ! in_array($col['data'], ['DT_RowIndex', 'action'], true)
                && ! str_contains((string) $col['name'], '.'))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Columns the client may sort by via ?sort=&direction=.
     *
     * @return array<int, string>
     */
    protected function jsonSortableColumns(): array
    {
        return collect($this->tableColumns())
            ->filter(fn ($col) => ($col['orderable'] ?? true)
                && ! in_array($col['data'], ['DT_RowIndex', 'action'], true)
                && ! str_contains((string) $col['name'], '.'))
            ->pluck('name')
            ->values()
            ->all();
    }

    /**
     * Shape a single record for the JSON response. Override for custom
     * serialization (casts, computed values, nested resources).
     */
    protected function jsonResource(mixed $record): array
    {
        return collect($this->jsonFields())
            ->mapWithKeys(fn ($field) => [$field => data_get($record, $field)])
            ->all();
    }

    /**
     * Builder-level filters shared with the public API path. Override to add
     * status/date-range filters or relation search.
     */
    protected function applyApiFilters(Builder $query, Request $request): Builder
    {
        return $query;
    }

    /**
     * Clean, paginated JSON — { data, meta, links }. Reuses indexQuery() so the
     * public API and the admin grid can never drift apart. Intended for public
     * pages or external API consumers.
     */
    public function json(Request $request): JsonResponse
    {
        $this->authorizeDataTable();

        $perPage = min($this->jsonMaxPerPage, max(1, $request->integer('per_page', $this->jsonDefaultPerPage)));
        $term = $request->string('q')->trim()->value();

        $query = $this->indexQuery();

        if ($term !== '' && ($searchColumns = $this->jsonSearchColumns())) {
            $query->where(function (Builder $sub) use ($term, $searchColumns): void {
                foreach ($searchColumns as $column) {
                    $sub->orWhere($column, 'like', "%{$term}%");
                }
            });
        }

        $sort = $request->string('sort')->value();
        if ($sort !== '' && in_array($sort, $this->jsonSortableColumns(), true)) {
            $direction = strtolower($request->string('direction')->value()) === 'desc' ? 'desc' : 'asc';
            $query->orderBy($sort, $direction);
        }

        $query = $this->applyApiFilters($query, $request);

        $paginator = $query->paginate($perPage)->withQueryString();
        $paginator->getCollection()->transform(fn ($record) => $this->jsonResource($record));

        return response()->json([
            'data' => $paginator->items(),
            'meta' => [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
            'links' => [
                'first' => $paginator->url(1),
                'last' => $paginator->url($paginator->lastPage()),
                'prev' => $paginator->previousPageUrl(),
                'next' => $paginator->nextPageUrl(),
            ],
        ]);
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
