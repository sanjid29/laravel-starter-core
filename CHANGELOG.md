# Changelog

All notable changes to `laravel-starter-core` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added
- Configurable Select2 / AJAX search endpoint (`selectSearchColumns()`, `selectFields()`,
  `selectLabel()`, `selectQuery()`) returning Select2's native paginated shape.
- Clean REST JSON feed on `BaseDataTableController` (`json()` + `jsonFields()`,
  `jsonSearchColumns()`, `jsonSortableColumns()`, `jsonResource()`, `applyApiFilters()`),
  decoupled from the DataTables envelope.
- `public: true` flag on `Route::crudModule()` to expose the `/select` and `/json`
  read feeds behind `throttle:api` without permission checks (writes stay guarded).
- Package infrastructure: PHPUnit + Orchestra Testbench suite, Pint, Larastan,
  GitHub Actions (tests + quality), `LICENSE`, `.gitignore`, `.gitattributes`,
  `.editorconfig`, and this changelog.

### Changed
- Added **Laravel 13** support alongside Laravel 12. Widened constraints:
  `laravel/framework ^12.0 || ^13.0`, `spatie/laravel-permission ^7.0 || ^8.0`,
  `spatie/laravel-activitylog ^4.0 || ^5.0`, `yajra/...-oracle ^12.0 || ^13.0`,
  `orchestra/testbench ^10.0 || ^11.0`. CI now runs a Laravel 12 + 13 matrix.
- `make:crud-module` model stub now whitelists only business columns in `$fillable`
  (removed `id`, `uuid`, audit fields and timestamps) to prevent mass-assignment spoofing.
- `barryvdh/laravel-dompdf` and `phpoffice/phpspreadsheet` moved from `require` to
  `suggest` (only needed for `BaseReportController` exports).

### Fixed
- DataTable stub no longer marks plain-text columns as raw (unescaped) HTML.
- `make:crud-module` next-steps output now lists all eight generated permissions.
