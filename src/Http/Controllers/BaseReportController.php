<?php

namespace Sanjid29\StarterCore\Http\Controllers;

use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Csv;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * BaseReportController
 *
 * A self-contained report engine for building tabular reports with support
 * for filtering, column selection, pagination, sorting, and multiple output
 * formats (HTML, Excel, CSV, JSON, Print).
 *
 * ─── How to create a new report ──────────────────────────────────────────
 *
 *  1. Create a controller that extends BaseReportController.
 *  2. Set $modelClass to your Eloquent model.
 *  3. Set $viewPath to the report's view directory.
 *  4. Override selectedColumns(), aliasFor(), ghostColumns() as needed.
 *  5. Override filter() to add custom WHERE clauses.
 *  6. Override mutateResult() to post-process rows (humanise booleans, etc.).
 *  7. Add a single route: Route::get('...', YourReportController::class)
 *
 * ─── View structure ───────────────────────────────────────────────────────
 *
 *  App layout (shared with all pages):
 *    resources/views/report/report.blade.php
 *      └─ @extends('report.report') — base layout every report index extends
 *
 *  Shared report partials (defaults, override per-report via @section):
 *    resources/views/report/includes/cta.blade.php     — Run/export buttons
 *    resources/views/report/includes/fields.blade.php  — column picker
 *    resources/views/report/includes/result.blade.php  — results table
 *    resources/views/report/includes/print.blade.php   — print page base
 *
 *  Per-report views (one folder per report):
 *    resources/views/{module}/report/index.blade.php   — filter inputs  (required)
 *    resources/views/{module}/report/print.blade.php   — print override (optional)
 *
 *  Example for leave types:
 *    $viewPath = 'leave-types.report'
 *    → resources/views/leave-types/report/index.blade.php   (filter inputs)
 *    → resources/views/leave-types/report/print.blade.php   (optional)
 *
 * ─── Supported URL parameters ────────────────────────────────────────────
 *
 *  columns_csv        – comma-separated column names to display
 *  alias_columns_csv  – comma-separated column aliases/headers
 *  order_by           – e.g. "name ASC" or "created_at DESC"
 *  rows_per_page      – integer or "all"
 *  ret                – html (default) | excel | csv | json | print
 *  submit             – set to "Run" to execute the query
 *  search_key         – keyword search across $searchFields
 *  {field}            – any field in the model table is filtered automatically
 *  {field}_from       – date-range start  (e.g. created_at_from)
 *  {field}_to         – date-range end    (e.g. created_at_to)
 */
abstract class BaseReportController extends Controller
{
    /**
     * The fully-qualified Eloquent model class.
     * Must be set in the child class.
     *
     * Example: protected string $modelClass = Invoice::class;
     */
    protected string $modelClass;

    /**
     * Blade view directory for the report's filter + results page.
     * The engine renders {viewPath}.index.
     *
     * Follows the pattern: {module}.report
     * Example: protected string $viewPath = 'leave-types.report';
     * Maps to: resources/views/leave-types/report/index.blade.php
     */
    protected string $viewPath;

    /**
     * Blade view for the standalone print page.
     *
     * Defaults to the shared base: resources/views/report/includes/print.blade.php
     *
     * Override in a child to use a module-specific print view that extends the base:
     *   protected string $printView = 'leave-types.report.print';
     *   → resources/views/leave-types/report/print.blade.php
     */
    protected string $printView = 'report.includes.print';

    /**
     * Default rows per page.
     * Overridden by ?rows_per_page= URL parameter.
     */
    protected int $rowsPerPage = 50;

    /**
     * Fields searched by ?search_key= using LIKE matching.
     * Override in child to add module-specific searchable fields.
     *
     * @var array<int, string>
     */
    protected array $searchFields = ['name'];

    /**
     * Fields that always use LIKE (substring) matching in the default filter,
     * even when queried directly (e.g. ?name=John will do LIKE %John%).
     * Exact matching is used for all other string fields.
     *
     * @var array<int, string>
     */
    protected array $fullTextFields = ['name'];

    /**
     * The current incoming HTTP request.
     */
    protected Request $request;

    /**
     * Cached total count for the current filter (avoids a second COUNT query).
     */
    protected ?int $total = null;

    /**
     * Cached paginated result set.
     *
     * @var LengthAwarePaginator|Collection|null
     */
    protected mixed $result = null;

    // ─────────────────────────────────────────────────────────────────
    // Boot
    // ─────────────────────────────────────────────────────────────────

    public function __construct()
    {
        $this->request = request();
    }

    // ─────────────────────────────────────────────────────────────────
    // Entry point
    // ─────────────────────────────────────────────────────────────────

    /**
     * Generate and return the report output.
     * Routes to the correct output method based on ?ret= parameter.
     *
     * @return View|JsonResponse|StreamedResponse
     */
    public function output(): mixed
    {
        return match ($this->outputType()) {
            'json' => $this->toJson(),
            'excel' => $this->toExcel(),
            'csv' => $this->toCsv(),
            'pdf' => $this->toPdf(),
            'print' => $this->toHtml('print'),
            default => $this->shouldRun() ? $this->toHtml() : $this->toHtml('blank'),
        };
    }

    /**
     * Determine whether to execute the query and show results.
     * Returns true if ?submit=Run is in the request.
     * Call enableAutoRun() in the child constructor to always run.
     */
    protected function shouldRun(): bool
    {
        return $this->request->input('submit') === 'Run';
    }

    /**
     * Force the report to always run without requiring ?submit=Run.
     * Call this in the child constructor when results should always be shown.
     */
    public function enableAutoRun(): static
    {
        $this->request->merge(['submit' => 'Run']);

        return $this;
    }

    /**
     * Determine the output type from the request.
     *
     * @return string html | excel | csv | json | print
     */
    protected function outputType(): string
    {
        if ($this->request->expectsJson()) {
            return 'json';
        }

        return $this->request->input('ret', 'html');
    }

    // ─────────────────────────────────────────────────────────────────
    // Column configuration — override in child
    // ─────────────────────────────────────────────────────────────────

    /**
     * Columns that exist in the database table but should be hidden from
     * the column picker (e.g. file paths, internal tokens, passwords).
     * Override in the child to customise the available options.
     *
     * @return array<int, string>
     */
    protected function excludedColumns(): array
    {
        return [];
    }

    /**
     * Ghost columns are computed fields that do not exist in the database.
     * Their values must be assigned inside mutateResult().
     * Users can still select them from the column picker.
     *
     * Common examples: 'serial', 'full_name', 'computed_total'
     *
     * @return array<int, string>
     */
    protected function ghostColumns(): array
    {
        return ['serial'];
    }

    /**
     * All columns available in the column picker.
     * = (real table columns − excluded) + ghost columns
     *
     * @return array<int, string>
     */
    public function columnOptions(): array
    {
        $visible = array_diff($this->tableColumns(), $this->excludedColumns());

        return array_values(array_unique(array_merge($visible, $this->ghostColumns())));
    }

    /**
     * Columns always included in the SQL SELECT even if not chosen by the user.
     * Required so relationships and URL generation work correctly.
     * Override to add module-specific always-needed columns.
     *
     * @return array<int, string>
     */
    protected function defaultColumns(): array
    {
        return ['id'];
    }

    /**
     * Columns shown by default when no ?columns_csv= is provided.
     * Override in the child to change the default column set.
     *
     * @return array<int, string>
     */
    public function selectedColumns(): array
    {
        if ($this->request->filled('columns_csv')) {
            return $this->csvToArray($this->request->input('columns_csv'));
        }

        return $this->columnOptions();
    }

    /**
     * Human-readable alias for a given column key.
     * Override in the child to provide custom labels.
     */
    public function aliasFor(string $key): string
    {
        return Str::title(str_replace('_', ' ', $key));
    }

    /**
     * Resolved alias columns, one-to-one with selectedColumns().
     * Respects ?alias_columns_csv= override.
     *
     * @return array<int, string>
     */
    public function aliasColumns(): array
    {
        $selected = $this->selectedColumns();

        // Use user-supplied aliases only when they are non-empty.
        // An empty submission (user cleared all tags) falls back to defaults.
        $raw = $this->request->input('alias_columns_csv', '');
        if ($raw !== '' && $this->request->filled('alias_columns_csv')) {
            $aliases = $this->csvToArray($raw);
        } else {
            $aliases = [];
        }

        // Fill any missing positions with aliasFor() defaults so the
        // alias count always matches the selected column count exactly.
        foreach ($selected as $i => $col) {
            if (empty($aliases[$i])) {
                $aliases[$i] = $this->aliasFor($col);
            }
        }

        return array_slice($aliases, 0, count($selected));
    }

    // ─────────────────────────────────────────────────────────────────
    // Query building
    // ─────────────────────────────────────────────────────────────────

    /**
     * Base query used for both the result and the total count.
     * Override to add eager loads, global scopes, default ordering, or joins.
     */
    protected function baseQuery(): Builder
    {
        return ($this->modelClass)::query();
    }

    /**
     * Build the full result query: select + filter + order.
     */
    protected function resultQuery(): Builder
    {
        $query = clone $this->baseQuery();

        // SELECT — ghost columns are excluded because they don't exist in the DB
        $selectColumns = array_values(array_diff(
            array_unique(array_merge($this->selectedColumns(), $this->defaultColumns())),
            $this->ghostColumns()
        ));

        if (! empty($selectColumns)) {
            $query->select($selectColumns);
        }

        $query = $this->filter($query);
        $query = $this->applyOrderBy($query);

        return $query;
    }

    /**
     * Execute the result query and return a paginated result.
     * Cached for the lifetime of the request.
     *
     * @return LengthAwarePaginator|Collection
     */
    public function result(): mixed
    {
        if ($this->result !== null) {
            return $this->result;
        }

        $this->result = $this->resultQuery()->toBase()->paginate($this->resolveRowsPerPage());

        return $this->result;
    }

    /**
     * Return the total number of rows matching the current filters.
     * Cached to avoid a second COUNT query.
     */
    public function total(): int
    {
        if ($this->total !== null) {
            return $this->total;
        }

        $query = clone $this->baseQuery();
        $this->total = $this->filter($query)->count();

        return $this->total;
    }

    /**
     * Resolve rows per page from the request or the default.
     */
    protected function resolveRowsPerPage(): int
    {
        $value = $this->request->input('rows_per_page', $this->rowsPerPage);

        if (strtolower((string) $value) === 'all') {
            return max($this->total(), 1);
        }

        return (int) $value;
    }

    // ─────────────────────────────────────────────────────────────────
    // Filtering — override filter() in child for custom logic
    // ─────────────────────────────────────────────────────────────────

    /**
     * Apply all filters to the query.
     *
     * The base implementation automatically handles:
     *  – Exact match      for integer / boolean fields
     *  – LIKE match       for fields listed in $fullTextFields
     *  – Array params     ?field[]=1&field[]=2
     *  – CSV params       ?field=1,2,3
     *  – Date-range       ?field_from= / ?field_to=
     *  – Keyword search   ?search_key= across $searchFields
     *
     * Override in the child to add extra WHERE clauses.
     * Always call parent::filter($query) first.
     */
    public function filter(Builder $query): Builder
    {
        $tableColumns = $this->tableColumns();

        foreach ($this->request->all() as $field => $value) {
            if ($value === null || $value === '') {
                continue;
            }

            if ($this->isFromRange($field)) {
                $query = $this->applyFromRange($query, $field, $value);

                continue;
            }

            if ($this->isToRange($field)) {
                $query = $this->applyToRange($query, $field, $value);

                continue;
            }

            if (! in_array($field, $tableColumns)) {
                continue;
            }

            // Array param: ?field[]=1&field[]=2
            if (is_array($value)) {
                $clean = array_filter($value, fn ($v) => $v !== null && $v !== '');
                if (! empty($clean)) {
                    $query->whereIn($field, array_values($clean));
                }

                continue;
            }

            $value = (string) $value;

            // CSV param: ?field=1,2,3
            if (str_contains($value, ',')) {
                $query->whereIn($field, array_map('trim', explode(',', $value)));

                continue;
            }

            if ($value === 'null') {
                $query->whereNull($field);

                continue;
            }

            if (in_array($field, $this->fullTextFields)) {
                $query->where($field, 'LIKE', "%{$value}%");

                continue;
            }

            $query->where($field, $value);
        }

        // Keyword search across $searchFields
        if ($key = $this->request->input('search_key')) {
            $query->where(function (Builder $q) use ($key) {
                foreach ($this->searchFields as $field) {
                    if (! in_array($field, $this->tableColumns())) {
                        continue;
                    }
                    $q->orWhere($field, 'LIKE', "%{$key}%");
                }
            });
        }

        return $query;
    }

    /**
     * Apply a date-range "from" filter.
     * Anchors to start of day when only a date (no time) is provided.
     *
     * @param  string  $field  e.g. "created_at_from"
     */
    protected function applyFromRange(Builder $query, string $field, string $value): Builder
    {
        $dateTime = Carbon::parse($value);

        if (strlen($value) <= 10) {
            $dateTime->startOfDay();
        }

        return $query->where($this->stripRangeSuffix($field), '>=', $dateTime);
    }

    /**
     * Apply a date-range "to" filter.
     * Anchors to end of day when only a date (no time) is provided.
     *
     * @param  string  $field  e.g. "created_at_to"
     */
    protected function applyToRange(Builder $query, string $field, string $value): Builder
    {
        $dateTime = Carbon::parse($value);

        if (strlen($value) <= 10) {
            $dateTime->endOfDay();
        }

        return $query->where($this->stripRangeSuffix($field), '<=', $dateTime);
    }

    /**
     * Determine whether a request key is a "from" range parameter.
     */
    protected function isFromRange(string $field): bool
    {
        return Str::endsWith($field, ['_from', '_start', '_min']);
    }

    /**
     * Determine whether a request key is a "to" range parameter.
     */
    protected function isToRange(string $field): bool
    {
        return Str::endsWith($field, ['_to', '_till', '_end', '_max']);
    }

    /**
     * Strip the range suffix from a field name to get the actual column name.
     * e.g. "created_at_from" → "created_at"
     */
    protected function stripRangeSuffix(string $field): string
    {
        foreach (['_from', '_start', '_min', '_to', '_till', '_end', '_max'] as $suffix) {
            if (Str::endsWith($field, $suffix)) {
                return Str::beforeLast($field, $suffix);
            }
        }

        return $field;
    }

    // ─────────────────────────────────────────────────────────────────
    // Sorting
    // ─────────────────────────────────────────────────────────────────

    /**
     * Apply ORDER BY from the ?order_by= request parameter.
     * Column is validated against actual table columns to prevent SQL injection.
     *
     * Accepts: "column_name ASC" or "column_name DESC"
     */
    protected function applyOrderBy(Builder $query): Builder
    {
        $orderBy = trim((string) $this->request->input('order_by', ''));

        if (empty($orderBy)) {
            return $query;
        }

        $parts = preg_split('/\s+/', $orderBy, 2);
        $column = $parts[0] ?? '';
        $direction = strtoupper($parts[1] ?? 'ASC');

        if (! in_array($direction, ['ASC', 'DESC'])) {
            $direction = 'ASC';
        }

        if (in_array($column, $this->tableColumns())) {
            $query->orderBy($column, $direction);
        }

        return $query;
    }

    // ─────────────────────────────────────────────────────────────────
    // Result mutation — override in child
    // ─────────────────────────────────────────────────────────────────

    /**
     * Post-process each row of the result before it reaches the view or spreadsheet.
     *
     * Common uses:
     *  – Serial number:    $row->serial = $serial++;
     *  – Booleans:         $row->is_active = $row->is_active ? 'Yes' : 'No';
     *  – Date formatting:  $row->created_at = Carbon::parse($row->created_at)->format('M d, Y');
     *  – Null display:     $row->limit = $row->limit ?? '—';
     *
     * Always return $result at the end.
     *
     * @return LengthAwarePaginator|Collection
     */
    public function mutateResult(): mixed
    {
        $result = $this->result();
        $serial = 1;

        foreach ($result as $row) {
            $row->serial = $serial++;
        }

        return $result;
    }

    // ─────────────────────────────────────────────────────────────────
    // Output methods
    // ─────────────────────────────────────────────────────────────────

    /**
     * Render the report as an HTML view.
     *
     * Routes:
     *  null   → {viewPath}.index        filter form + results
     *  blank  → {viewPath}.index        filter form only (no results section rendered)
     *  print  → $printView              standalone printable page
     *           default: report.includes.print
     *           override: set $printView = '{module}.report.print' in child
     *
     * @param  string|null  $type  null | 'blank' | 'print'
     */
    public function toHtml(?string $type = null): View
    {
        $vars = $this->viewVars($type);
        $blade = $type === 'print'
            ? $this->printView
            : "{$this->viewPath}.index";

        return view($blade, $vars);
    }

    /**
     * Return the report data as a JSON response.
     */
    public function toJson(): JsonResponse
    {
        $result = $this->mutateResult();
        $payload = method_exists($result, 'toArray') ? $result->toArray() : (array) $result;

        return response()->json([
            'success' => true,
            'total' => $this->total(),
            'data' => $payload['data'] ?? $payload,
            'meta' => [
                'current_page' => method_exists($result, 'currentPage') ? $result->currentPage() : 1,
                'last_page' => method_exists($result, 'lastPage') ? $result->lastPage() : 1,
                'per_page' => method_exists($result, 'perPage') ? $result->perPage() : count($payload),
            ],
        ]);
    }

    /**
     * Stream an Excel (.xlsx) file download.
     *
     * @return StreamedResponse
     */
    public function toExcel(): mixed
    {
        return $this->streamSpreadsheet(false);
    }

    /**
     * Stream a CSV file download.
     *
     * @return StreamedResponse
     */
    public function toCsv(): mixed
    {
        return $this->streamSpreadsheet(true);
    }

    /**
     * Stream a PDF file download using the print blade view.
     *
     * @return Response
     */
    public function toPdf(): mixed
    {
        $vars = $this->viewVars('print');
        $blade = $this->printView;
        $html = view($blade, $vars)->render();
        $filename = 'Report-'.now()->format('Y-m-d-His').'.pdf';

        return Pdf::loadHTML($html)
            ->setPaper('a4', 'landscape')
            ->download($filename);
    }

    /**
     * Build and stream a spreadsheet (Excel or CSV) to the browser.
     *
     * @param  bool  $csv  true = CSV output, false = XLSX output
     * @return StreamedResponse
     */
    protected function streamSpreadsheet(bool $csv = false): mixed
    {
        $selectedColumns = $this->selectedColumns();
        $aliasColumns = $this->aliasColumns();
        $rows = $this->mutateResult();

        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach ($aliasColumns as $colIndex => $alias) {
            $sheet->setCellValue($this->excelColumn($colIndex).'1', $alias);
        }

        $rowIndex = 2;
        foreach ($rows as $row) {
            foreach ($selectedColumns as $colIndex => $column) {
                $value = $row->$column ?? '';

                if (is_array($value) || is_object($value)) {
                    $value = json_encode($value);
                }

                $sheet->setCellValue(
                    $this->excelColumn($colIndex).$rowIndex,
                    strip_tags((string) $value)
                );
            }
            $rowIndex++;
        }

        $ext = $csv ? '.csv' : '.xlsx';
        $filename = 'Report-'.now()->format('Y-m-d-His').$ext;

        if ($csv) {
            $writer = new Csv($spreadsheet);
            $writer->setDelimiter(',');
            $writer->setEnclosure('"');
            $writer->setLineEnding("\r\n");
        } else {
            $writer = new Xlsx($spreadsheet);
        }

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => $csv
                ? 'text/csv'
                : 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Convert a zero-based column index to an Excel column letter.
     * e.g. 0 → 'A', 25 → 'Z', 26 → 'AA'
     *
     * @param  int  $index  0-based column index
     */
    protected function excelColumn(int $index): string
    {
        $column = '';
        $index++;
        while ($index > 0) {
            $index--;
            $column = chr(65 + ($index % 26)).$column;
            $index = intdiv($index, 26);
        }

        return $column;
    }

    // ─────────────────────────────────────────────────────────────────
    // View helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Variables passed to every report blade view.
     * Extend by overriding extraViewVars() in the child.
     *
     * @return array<string, mixed>
     */
    protected function viewVars(?string $type = null): array
    {
        // selectedColumns and aliasColumns are always needed because the
        // Fields tab (column picker) is visible even on the blank first load.
        // Only result and total are skipped until the report is actually run.
        $base = [
            'report' => $this,
            'columnOptions' => $this->columnOptions(),
            'selectedColumns' => $this->selectedColumns(),
            'aliasColumns' => $this->aliasColumns(),
            'isBlank' => ($type === 'blank'),
            'isPrint' => ($type === 'print'),
        ];

        if ($type !== 'blank') {
            $base = array_merge($base, [
                'result' => $this->mutateResult(),
                'total' => $this->total(),
            ]);
        }

        return array_merge($base, $this->extraViewVars());
    }

    /**
     * Extra variables injected into every view for this report.
     * Override in the child to pass filter dropdowns, year lists, etc.
     *
     * Example:
     *   protected function extraViewVars(): array
     *   {
     *       return ['statuses' => Status::active()->get()];
     *   }
     *
     * @return array<string, mixed>
     */
    protected function extraViewVars(): array
    {
        return [];
    }

    /**
     * Render a single table cell for the HTML result view.
     *
     * The base implementation returns the raw value as escaped plain text.
     * Override in the child to add links, badges, or any custom formatting
     * for specific columns without touching the shared result partial.
     *
     * Example:
     *   public function renderCell(string $column, object $row): string
     *   {
     *       if ($column === 'name') {
     *           return '<a href="' . route('leave-types.show', $row->id) . '">'
     *               . e($row->name) . '</a>';
     *       }
     *       return parent::renderCell($column, $row);
     *   }
     *
     * @return string HTML-safe string (may contain HTML tags)
     */
    public function renderCell(string $column, object $row): string
    {
        return e((string) ($row->$column ?? ''));
    }

    /**
     * URL for the Excel download of the current report + filters.
     */
    public function excelUrl(): string
    {
        return $this->buildUrl(['ret' => 'excel', 'submit' => 'Run']);
    }

    /**
     * URL for the CSV download of the current report + filters.
     */
    public function csvUrl(): string
    {
        return $this->buildUrl(['ret' => 'csv', 'submit' => 'Run']);
    }

    /**
     * URL for the printable version of the current report + filters.
     */
    public function printUrl(): string
    {
        return $this->buildUrl(['ret' => 'print', 'submit' => 'Run']);
    }

    /**
     * URL for the JSON output of the current report + filters.
     */
    public function jsonUrl(): string
    {
        return $this->buildUrl(['ret' => 'json', 'submit' => 'Run']);
    }

    /**
     * URL for the PDF download of the current report + filters.
     */
    public function pdfUrl(): string
    {
        return $this->buildUrl(['ret' => 'pdf', 'submit' => 'Run']);
    }

    /**
     * Build a URL for the current page with merged/overridden parameters.
     *
     * @param  array<string, mixed>  $params
     */
    public function buildUrl(array $params = []): string
    {
        return url()->current().'?'.http_build_query(
            array_merge($this->request->all(), $params)
        );
    }

    // ─────────────────────────────────────────────────────────────────
    // Internal helpers
    // ─────────────────────────────────────────────────────────────────

    /**
     * Get column names from the model's underlying database table.
     *
     * @return array<int, string>
     */
    protected function tableColumns(): array
    {
        /** @var Model $instance */
        $instance = new $this->modelClass;

        return $instance->getConnection()
            ->getSchemaBuilder()
            ->getColumnListing($instance->getTable());
    }

    /**
     * Convert a comma-separated string to a trimmed, filtered array.
     *
     * @return array<int, string>
     */
    protected function csvToArray(string $csv): array
    {
        return array_values(array_filter(
            array_map('trim', explode(',', $csv))
        ));
    }
}
