<?php

namespace Sanjid29\StarterCore\Support;

/**
 * Fluent configuration object for the shared _datatable partial.
 *
 * Pass both arrays that BaseController::index() already provides:
 *
 *   $datatable = DatatableConfig::make(
 *       route:        'emergency-requests.datatable',
 *       tableColumns: $tableColumns,   // full definitions — has 'label', 'width', etc.
 *       dtColumns:    $dtColumns,      // stripped definitions — what DataTables JS needs
 *   )
 *   ->tableId('emergency-requests-table')
 *   ->order([[3, 'desc']])
 *   ->emptyLabel('emergency requests');
 */
class DatatableConfig
{
    // ── Required ──────────────────────────────────────────────────────────
    public string $route;

    /** Full column definitions from DataTableController::tableColumns() — used for <thead> */
    public array $tableColumns;

    /** Stripped column definitions built by BaseController::index() — used for DataTables JS */
    public array $dtColumns;

    // ── Optional with defaults ────────────────────────────────────────────
    public string $tableId = 'datatable';

    public int $pageLength = 10;

    public array $order = [[0, 'desc']];

    public bool $searching = true;

    public bool $lengthChange = true;

    public bool $info = true;

    public string $emptyLabel = 'records';

    public string $searchPlaceholder = 'Search…';

    /** Column `name` values to hide */
    public array $hidden = [];

    /** Extra params merged into the AJAX data() callback */
    public array $extraParams = [];

    // ─────────────────────────────────────────────────────────────────────

    public function __construct(string $route, array $tableColumns, array $dtColumns)
    {
        $this->route = $route;
        $this->tableColumns = $tableColumns;
        $this->dtColumns = $dtColumns;
    }

    public static function make(string $route, array $tableColumns, array $dtColumns): static
    {
        return new static($route, $tableColumns, $dtColumns);
    }

    // ── Fluent setters ────────────────────────────────────────────────────

    public function tableId(string $id): static
    {
        $this->tableId = $id;

        return $this;
    }

    public function pageLength(int $length): static
    {
        $this->pageLength = $length;

        return $this;
    }

    public function order(array $order): static
    {
        $this->order = $order;

        return $this;
    }

    public function searching(bool $value): static
    {
        $this->searching = $value;

        return $this;
    }

    public function lengthChange(bool $value): static
    {
        $this->lengthChange = $value;

        return $this;
    }

    public function info(bool $value): static
    {
        $this->info = $value;

        return $this;
    }

    public function emptyLabel(string $label): static
    {
        $this->emptyLabel = $label;

        return $this;
    }

    public function searchPlaceholder(string $placeholder): static
    {
        $this->searchPlaceholder = $placeholder;

        return $this;
    }

    /**
     * Column `name` values to hide.
     * Applies to both the <thead> and the DataTables JS column list.
     *
     * e.g. ->hide(['updated_by', 'updated_at'])
     */
    public function hide(array $columnNames): static
    {
        $this->hidden = $columnNames;

        return $this;
    }

    /**
     * Extra key/value pairs merged into the DataTables ajax.data() callback.
     * Useful for scoping a datatable to a parent record on a show page.
     *
     * e.g. ->params(['provider_id' => $provider->id])
     */
    public function params(array $params): static
    {
        $this->extraParams = $params;

        return $this;
    }

    /**
     * Compact dashboard / embedded widget preset.
     * Disables search bar, length picker, and info line. Sets pageLength to 5.
     */
    public function minimal(int $pageLength = 5): static
    {
        $this->pageLength = $pageLength;
        $this->searching = false;
        $this->lengthChange = false;
        $this->info = false;

        return $this;
    }

    // ── Derived ───────────────────────────────────────────────────────────

    /**
     * All tableColumns — used to render <thead>.
     * Hidden columns are included so DataTables index mapping stays correct;
     * DataTables hides them via visible:false in jsColumns().
     */
    public function headColumns(): array
    {
        return array_values($this->tableColumns);
    }

    /**
     * dtColumns with hidden columns marked visible=false — passed to DataTables JS.
     * Keeping them in the JS definition (rather than removing) preserves server-side
     * ordering on hidden columns if needed.
     */
    public function jsColumns(): array
    {
        return array_map(function (array $col) {
            if (in_array($col['name'], $this->hidden, true)) {
                $col['visible'] = false;
            }

            return $col;
        }, $this->dtColumns);
    }
}
