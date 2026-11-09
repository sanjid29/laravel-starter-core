<?php

namespace Sanjid29\StarterCore\Http\Controllers;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;
use Sanjid29\StarterCore\Services\BaseService;
use Sanjid29\StarterCore\Support\DatatableConfig;
use Spatie\MediaLibrary\HasMedia;

abstract class BaseController extends Controller
{
    use AuthorizesRequests;

    protected string $routePrefix;

    protected string $viewPrefix;

    protected string $resourceName;

    /** @var class-string<Model> */
    protected string $model;

    protected string $selectSearchColumn = 'name';

    protected string $selectLabelColumn = 'name';

    protected int $selectLimit = 20;

    protected ?BaseDataTableController $dataTableController = null;

    /**
     * Optional service — only set if the child controller needs one.
     * If null, BaseController handles create/update directly.
     */
    protected ?BaseService $service = null;

    // ─────────────────────────────────────────────
    // Core Helpers
    // ─────────────────────────────────────────────

    protected function findRecord(int|string $id): Model
    {
        return ($this->model)::findOrFail($id);
    }

    protected function findTrashedRecord(int|string $id): Model
    {
        return ($this->model)::withTrashed()->findOrFail($id);
    }

    protected function authorizeAction(string $ability, ?Model $record = null): void
    {
        $this->authorize($ability, $record ?? $this->model);
    }

    protected function successRedirect(string $action): RedirectResponse
    {
        return to_route("{$this->routePrefix}.index")
            ->with('status', $this->resolveMessage($action));
    }

    private function resolveMessage(string $action): string
    {
        $defaults = [
            'created' => "{$this->resourceName} created successfully.",
            'updated' => "{$this->resourceName} updated successfully.",
            'deleted' => "{$this->resourceName} deleted successfully.",
        ];

        return array_merge($defaults, $this->messages())[$action]
            ?? 'Action completed successfully.';
    }

    private function resolveRequest(Request $request): FormRequest|Request
    {
        return $this->requestClass()
            ? app($this->requestClass())
            : $request;
    }

    // ─────────────────────────────────────────────
    // Overridable Hooks
    // ─────────────────────────────────────────────

    protected function requestClass(): ?string
    {
        return null;
    }

    protected function messages(): array
    {
        return [];
    }

    protected function fileFields(): array
    {
        return [];
    }

    protected function mediaFields(): array
    {
        return [];
    }

    protected function createViewData(): array
    {
        return [];
    }

    protected function editViewData(Model $record): array
    {
        return [];
    }

    protected function transformStoreData(array $data): array
    {
        return $data;
    }

    protected function transformUpdateData(array $data, Model $record): array
    {
        return $data;
    }

    protected function redirectAfterStore(Model $record): RedirectResponse
    {
        return $this->successRedirect('created');
    }

    protected function redirectAfterUpdate(Model $record): RedirectResponse
    {
        return $this->successRedirect('updated');
    }

    /**
     * Called after a record is created (no service wired).
     * Override to sync associations, dispatch jobs, or handle post-create logic.
     */
    protected function afterStore(Model $record, array $data): void {}

    /**
     * Called after a record is updated (no service wired).
     * Override to sync associations, dispatch jobs, or handle post-update logic.
     */
    protected function afterUpdate(Model $record, array $data): void {}

    protected function beforeDestroy(Model $record): void {}

    protected function afterDestroy(Model $record): void
    {
        foreach (array_keys($this->fileFields()) as $field) {
            if ($record->$field) {
                Storage::disk('public')->delete($record->$field);
            }
        }

        if ($record instanceof HasMedia && $this->mediaFields()) {
            $collections = array_unique(
                array_column($this->mediaFields(), 'collection')
            );

            foreach ($collections as $collection) {
                $record->clearMediaCollection($collection);
            }
        }
    }

    // ─────────────────────────────────────────────
    // File Handling — Storage disk
    // ─────────────────────────────────────────────

    protected function handleFileUploads(FormRequest|Request $request, array $data, ?Model $existing = null): array
    {
        foreach ($this->fileFields() as $field => $storagePath) {
            if ($request->hasFile($field)) {
                if ($existing?->$field) {
                    Storage::disk('public')->delete($existing->$field);
                }
                $data[$field] = $request->file($field)->store($storagePath, 'public');
            } else {
                unset($data[$field]);
            }
        }

        return $data;
    }

    // ─────────────────────────────────────────────
    // File Handling — Spatie MediaLibrary
    // ─────────────────────────────────────────────

    protected function handleMediaUploads(FormRequest|Request $request, Model $model, bool $isUpdate = false): void
    {
        if (! $model instanceof HasMedia) {
            return;
        }

        foreach ($this->mediaFields() as $field => $config) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $collection = $config['collection'] ?? $field;
            $multiple = $config['multiple'] ?? false;
            $shouldClear = $config['clear_on_update'] ?? (! $multiple && $isUpdate);

            if ($shouldClear) {
                $model->clearMediaCollection($collection);
            }

            if ($multiple) {
                foreach ($request->file($field) as $file) {
                    $model->addMedia($file)->toMediaCollection($collection);
                }
            } else {
                $model->addMediaFromRequest($field)->toMediaCollection($collection);
            }
        }
    }

    // ─────────────────────────────────────────────
    // CRUD Actions
    // ─────────────────────────────────────────────

    public function index(Request $request): View
    {
        $this->authorizeAction('viewAny');

        $tableColumns = $this->dataTableController?->tableColumns() ?? [];
        $moduleName = $this->viewPrefix ?? null;
        $moduleTitle = $this->resourceName ?? null;

        $dtColumns = collect($tableColumns)->map(fn ($col) => [
            'data' => $col['data'],
            'name' => $col['name'],
            'orderable' => $col['orderable'] ?? true,
            'searchable' => $col['searchable'] ?? true,
            'className' => $col['className'] ?? '',
        ])->values()->all();

        $datatable = DatatableConfig::make(
            route: $moduleName.'.datatable',
            tableColumns: $tableColumns,
            dtColumns: $dtColumns,
        )
            ->tableId($moduleName.'-table')
            ->emptyLabel(strtolower($moduleTitle))
            ->searchPlaceholder('Search '.strtolower($moduleTitle).'…');

        $view = view()->exists("{$this->viewPrefix}.index")
            ? "{$this->viewPrefix}.index"
            : 'layouts.form.index';

        return view($view, compact('moduleTitle', 'moduleName', 'tableColumns', 'dtColumns', 'datatable'));
    }

    public function create(): View
    {
        $this->authorizeAction('create');

        return view("{$this->viewPrefix}.form", array_merge(
            [
                'editing' => false,
                'record' => null,
                'moduleTitle' => $this->resourceName,
                'moduleTitlePlural' => Str::plural($this->resourceName),
                'moduleName' => $this->routePrefix,
            ],
            $this->createViewData()
        ));
    }

    public function show(int|string $record): View|RedirectResponse
    {
        return to_route("{$this->routePrefix}.edit", $record);
    }

    public function edit(int|string $record): View|JsonResponse
    {
        $model = $this->findRecord($record);
        $this->authorizeAction('update', $model);

        return view("{$this->viewPrefix}.form", array_merge(
            [
                'editing' => true,
                'record' => $model,
                'moduleTitle' => $this->resourceName,
                'moduleTitlePlural' => Str::plural($this->resourceName),
                'moduleName' => $this->routePrefix,
            ],
            $this->editViewData($model)
        ));
    }

    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $this->authorizeAction('create');

        $formRequest = $this->resolveRequest($request);
        $data = $this->transformStoreData($this->handleFileUploads($formRequest, $formRequest->validated()));

        $record = $this->service
            ? $this->service->create($data)
            : ($this->model)::create($data);

        $this->handleMediaUploads($formRequest, $record, isUpdate: false);

        if (! $this->service) {
            $this->afterStore($record, $data);
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $this->resolveMessage('created'),
                'redirect' => $this->redirectAfterStore($record)->getTargetUrl(),
            ]);
        }

        return $this->redirectAfterStore($record);
    }

    public function update(Request $request, int|string $record): RedirectResponse|JsonResponse
    {
        $model = $this->findRecord($record);
        $this->authorizeAction('update', $model);

        $formRequest = $this->resolveRequest($request);
        $data = $this->transformUpdateData(
            $this->handleFileUploads($formRequest, $formRequest->validated(), $model),
            $model
        );

        $this->service
            ? $this->service->update($model, $data)
            : $model->update($data);

        $this->handleMediaUploads($formRequest, $model, isUpdate: true);

        if (! $this->service) {
            $this->afterUpdate($model, $data);
        }

        if ($request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => $this->resolveMessage('updated'),
                'redirect' => $this->redirectAfterUpdate($model)->getTargetUrl(),
            ]);
        }

        return $this->redirectAfterUpdate($model);
    }

    // ─────────────────────────────────────────────
    // Select2 / AJAX search — overridable hooks
    // ─────────────────────────────────────────────

    /**
     * Columns searched (OR) against the `q` term.
     * Defaults to the single $selectSearchColumn for backward compatibility.
     *
     * @return array<int, string>
     */
    protected function selectSearchColumns(): array
    {
        return [$this->selectSearchColumn];
    }

    /**
     * Attribute whitelist returned per result. This is the security boundary —
     * only these keys are serialized. `id` and `text` are always added.
     *
     * @return array<int, string>
     */
    protected function selectFields(): array
    {
        return ['id', $this->selectLabelColumn];
    }

    /**
     * Builds the Select2 `text` label for a result row.
     */
    protected function selectLabel(Model $record): string
    {
        return (string) $record->{$this->selectLabelColumn};
    }

    /**
     * Scope the base query (e.g. only active records, tenant filtering).
     */
    protected function selectQuery(Builder $query): Builder
    {
        return $query;
    }

    /**
     * Select2-native AJAX endpoint: { results: [...], pagination: { more } }.
     * Supports multi-column search, field whitelisting and infinite scroll.
     */
    public function selectOptions(Request $request): JsonResponse
    {
        $term = $request->string('q')->trim()->value();
        $perPage = min($this->selectLimit, max(1, $request->integer('per_page', $this->selectLimit)));

        $query = $this->selectQuery(($this->model)::query())
            ->when($term !== '', function (Builder $q) use ($term): void {
                $q->where(function (Builder $sub) use ($term): void {
                    foreach ($this->selectSearchColumns() as $column) {
                        $sub->orWhere($column, 'like', "%{$term}%");
                    }
                });
            });

        $paginator = $query->paginate($perPage);

        $results = $paginator->getCollection()->map(fn (Model $record): array => array_merge(
            $record->only($this->selectFields()),
            ['id' => $record->getKey(), 'text' => $this->selectLabel($record)],
        ))->all();

        return response()->json([
            'results' => $results,
            'pagination' => ['more' => $paginator->hasMorePages()],
        ]);
    }

    public function recycleBin(): View
    {
        $this->authorizeAction('recycleBin');

        $tableColumns = $this->dataTableController?->tableColumns() ?? [];
        $moduleName = $this->viewPrefix;
        $moduleTitle = $this->resourceName;

        $dtColumns = collect($tableColumns)->map(fn ($col) => [
            'data' => $col['data'],
            'name' => $col['name'],
            'orderable' => $col['orderable'] ?? true,
            'searchable' => $col['searchable'] ?? true,
            'className' => $col['className'] ?? '',
        ])->values()->all();

        $datatable = DatatableConfig::make(
            route: $moduleName.'.recycle-bin.datatable',
            tableColumns: $tableColumns,
            dtColumns: $dtColumns,
        )
            ->tableId($moduleName.'-recycle-bin-table')
            ->emptyLabel(strtolower($moduleTitle))
            ->searchPlaceholder('Search deleted '.strtolower($moduleTitle).'…');

        $view = view()->exists("{$this->viewPrefix}.recycle-bin")
            ? "{$this->viewPrefix}.recycle-bin"
            : 'layouts.form.recycle-bin';

        return view($view, compact('moduleTitle', 'moduleName', 'tableColumns', 'dtColumns', 'datatable'));
    }

    public function restore(int|string $record): JsonResponse|RedirectResponse
    {
        $model = $this->findTrashedRecord($record);
        $this->authorizeAction('restore', $model);

        $model->restore();

        if (request()->expectsJson()) {
            return response()->json(['message' => "{$this->resourceName} restored successfully."]);
        }

        return to_route("{$this->routePrefix}.recycle-bin")
            ->with('status', "{$this->resourceName} restored successfully.");
    }

    public function forceDelete(int|string $record): JsonResponse|RedirectResponse
    {
        $model = $this->findTrashedRecord($record);
        $this->authorizeAction('forceDelete', $model);

        $this->beforeDestroy($model);
        $model->forceDelete();
        $this->afterDestroy($model);

        if (request()->expectsJson()) {
            return response()->json(['message' => "{$this->resourceName} permanently deleted."]);
        }

        return to_route("{$this->routePrefix}.recycle-bin")
            ->with('status', "{$this->resourceName} permanently deleted.");
    }

    public function destroy(int|string $record): JsonResponse|RedirectResponse
    {
        $model = $this->findRecord($record);
        $this->authorizeAction('delete', $model);

        $this->beforeDestroy($model);
        $model->delete();
        $this->afterDestroy($model);

        if (request()->ajax()) {
            return response()->json(['message' => $this->resolveMessage('deleted')]);
        }

        return $this->successRedirect('deleted');
    }
}
