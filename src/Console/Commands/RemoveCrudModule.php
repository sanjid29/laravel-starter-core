<?php

namespace Sanjid29\StarterCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class RemoveCrudModule extends Command
{
    protected $signature = 'remove:crud-module
                            {name : PascalCase module name (e.g. PostCategory)}
                            {--force : Skip confirmation prompt}';

    protected $description = 'Remove a CRUD module: deletes all generated files, drops the database table, and removes its permissions';

    /** @var array<string, string> Placeholder token → resolved value */
    private array $replacements = [];

    public function handle(): int
    {
        $name = $this->argument('name');

        if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Module name must be PascalCase with no spaces or special characters (e.g. PostCategory).');

            return self::FAILURE;
        }

        $this->buildReplacements($name);

        $inventory = $this->buildInventory();

        $this->printInventory($inventory);

        if (! $this->option('force') && $inventory['row_count'] > 0) {
            $table = $inventory['table'];
            $count = number_format($inventory['row_count']);

            $this->line("<fg=red;options=bold>  WARNING: The `{$table}` table contains {$count} row(s).</>");
            $this->line('  Dropping it will permanently destroy all stored data.');
            $this->newLine();

            $typed = $this->ask("  Type the table name <fg=yellow>{$table}</> to confirm data deletion (or leave blank to abort)");

            if ($typed !== $table) {
                $this->line('  Aborted — table name did not match.');

                return self::SUCCESS;
            }

            $this->newLine();
        }

        if (! $this->option('force') && ! $this->confirm('Proceed with removal? This cannot be undone.', false)) {
            $this->line('  Aborted.');

            return self::SUCCESS;
        }

        $this->newLine();

        $this->removeFiles($inventory['files']);
        $this->removeViewDirectory($inventory['view_dir']);
        $this->removeMigrations($inventory['migrations']);
        $this->dropTable($inventory['table']);
        $this->removePermissions($inventory['permissions']);

        $this->newLine();
        $this->printManualCleanup();

        return self::SUCCESS;
    }

    private function buildReplacements(string $name): void
    {
        $singular = $name;
        $plural = Str::plural($singular);

        $this->replacements = [
            '{module_names}' => Str::snake($plural),
            '{module names}' => strtolower($this->titleCaseWords($plural)),
            '{Module Names}' => $this->titleCaseWords($plural),
            '{module-names}' => Str::kebab($plural),
            '{module_name}' => Str::snake($singular),
            '{module name}' => strtolower($this->titleCaseWords($singular)),
            '{Module Name}' => $this->titleCaseWords($singular),
            '{ModuleName}' => $singular,
            '{moduleName}' => Str::camel($singular),
        ];
    }

    private function titleCaseWords(string $pascal): string
    {
        return trim(preg_replace('/(?<=[a-z])(?=[A-Z])/', ' ', $pascal));
    }

    /**
     * Build a complete inventory of everything this module owns.
     *
     * @return array{
     *   files: array<string, string>,
     *   view_dir: string,
     *   migrations: array<string>,
     *   table: string,
     *   row_count: int,
     *   permissions: array<string>
     * }
     */
    private function buildInventory(): array
    {
        $module = $this->replacements['{ModuleName}'];
        $kebab = $this->replacements['{module-names}'];
        $snake = $this->replacements['{module_names}'];

        $files = [
            "app/Models/{$module}.php" => base_path("app/Models/{$module}.php"),
            "app/Observers/{$module}Observer.php" => base_path("app/Observers/{$module}Observer.php"),
            "app/Policies/{$module}Policy.php" => base_path("app/Policies/{$module}Policy.php"),
            "app/Http/Requests/{$module}Request.php" => base_path("app/Http/Requests/{$module}Request.php"),
            "app/Http/Controllers/CrudController/{$module}Controller.php" => base_path("app/Http/Controllers/CrudController/{$module}Controller.php"),
            "app/Http/Controllers/DataTableController/{$module}DataTableController.php" => base_path("app/Http/Controllers/DataTableController/{$module}DataTableController.php"),
            "app/Http/Controllers/ReportController/{$module}ReportController.php" => base_path("app/Http/Controllers/ReportController/{$module}ReportController.php"),
        ];

        $viewDir = base_path("resources/views/{$kebab}");

        $migrations = glob(base_path("database/migrations/*_create_{$snake}_table.php")) ?: [];

        $permissions = [
            "{$kebab}.view",
            "{$kebab}.create",
            "{$kebab}.update",
            "{$kebab}.delete",
            "{$kebab}.report",
        ];

        $rowCount = Schema::hasTable($snake) ? (int) DB::table($snake)->count() : 0;

        return [
            'files' => $files,
            'view_dir' => $viewDir,
            'migrations' => $migrations,
            'table' => $snake,
            'row_count' => $rowCount,
            'permissions' => $permissions,
        ];
    }

    /**
     * Print a summary of everything that will be removed.
     *
     * @param  array<string, mixed>  $inventory
     */
    private function printInventory(array $inventory): void
    {
        $this->newLine();
        $this->line('<fg=yellow;options=bold>The following will be permanently removed:</>');
        $this->newLine();

        $this->line('  <fg=cyan>PHP Files:</>');
        foreach ($inventory['files'] as $relative => $absolute) {
            $exists = file_exists($absolute);
            $tag = $exists ? '<fg=red>DELETE</>' : '<fg=gray>SKIP  </>';
            $this->line("    [{$tag}]  {$relative}");
        }

        $viewDir = $inventory['view_dir'];
        $viewRel = 'resources/views/'.basename($viewDir);
        $viewTag = is_dir($viewDir) ? '<fg=red>DELETE</>' : '<fg=gray>SKIP  </>';
        $this->newLine();
        $this->line('  <fg=cyan>View Directory:</>');
        $this->line("    [{$viewTag}]  {$viewRel}/");

        $this->newLine();
        $this->line('  <fg=cyan>Migration Files:</>');
        if (empty($inventory['migrations'])) {
            $this->line('    [<fg=gray>SKIP  </>]  no migration files found');
        } else {
            foreach ($inventory['migrations'] as $path) {
                $this->line('    [<fg=red>DELETE</>]  database/migrations/'.basename($path));
            }
        }

        $this->newLine();
        $this->line('  <fg=cyan>Database Table:</>');
        $tableExists = Schema::hasTable($inventory['table']);
        $tableTag = $tableExists ? '<fg=red>DROP  </>' : '<fg=gray>SKIP  </>';
        $rowCount = $inventory['row_count'];
        $rowSuffix = $tableExists
            ? ($rowCount > 0
                ? " — <fg=red;options=bold>{$rowCount} rows of data will be permanently lost</>"
                : ' — <fg=green>empty</>')
            : '';
        $this->line("    [{$tableTag}]  {$inventory['table']}{$rowSuffix}");

        $this->newLine();
        $this->line('  <fg=cyan>Permissions:</>');
        foreach ($inventory['permissions'] as $permission) {
            $exists = Permission::where('name', $permission)->exists();
            $tag = $exists ? '<fg=red>DELETE</>' : '<fg=gray>SKIP  </>';
            $this->line("    [{$tag}]  {$permission}");
        }

        $this->newLine();
    }

    /**
     * Delete individual PHP files, skipping those that do not exist.
     *
     * @param  array<string, string>  $files
     */
    private function removeFiles(array $files): void
    {
        foreach ($files as $relative => $absolute) {
            if (! file_exists($absolute)) {
                $this->line("  <fg=gray>SKIP   </> {$relative}");

                continue;
            }

            unlink($absolute);
            $this->line("  <fg=red>DELETED</> {$relative}");
        }
    }

    private function removeViewDirectory(string $viewDir): void
    {
        $relative = 'resources/views/'.basename($viewDir);

        if (! is_dir($viewDir)) {
            $this->line("  <fg=gray>SKIP   </> {$relative}/");

            return;
        }

        $this->deleteDirectory($viewDir);
        $this->line("  <fg=red>DELETED</> {$relative}/");
    }

    /** @param  array<string>  $migrations */
    private function removeMigrations(array $migrations): void
    {
        if (empty($migrations)) {
            $this->line('  <fg=gray>SKIP   </> no migration files found');

            return;
        }

        foreach ($migrations as $path) {
            unlink($path);
            $this->line('  <fg=red>DELETED</> database/migrations/'.basename($path));
        }
    }

    private function dropTable(string $table): void
    {
        if (! Schema::hasTable($table)) {
            $this->line("  <fg=gray>SKIP   </> table `{$table}` does not exist");

            return;
        }

        Schema::dropIfExists($table);
        $this->line("  <fg=red>DROPPED</> table `{$table}`");
    }

    /** @param  array<string>  $permissions */
    private function removePermissions(array $permissions): void
    {
        app()[PermissionRegistrar::class]->forgetCachedPermissions();

        foreach ($permissions as $name) {
            $deleted = Permission::where('name', $name)->delete();
            if ($deleted) {
                $this->line("  <fg=red>DELETED</> permission \"{$name}\"");
            } else {
                $this->line("  <fg=gray>SKIP   </> permission \"{$name}\" not found");
            }
        }
    }

    private function printManualCleanup(): void
    {
        $module = $this->replacements['{ModuleName}'];
        $kebab = $this->replacements['{module-names}'];

        $this->line('<fg=yellow;options=bold>Manual cleanup required:</>');
        $this->newLine();

        $this->line('  <fg=cyan>1. Remove the route from routes/web.php:</>');
        $this->line("     Route::crudModule('{$kebab}', {$module}Controller::class, {$module}DataTableController::class, function () {");
        $this->line("         Route::get('/report', {$module}ReportController::class)");
        $this->line("             ->name('report')");
        $this->line("             ->middleware('permission:{$kebab}.report');");
        $this->line('     });');
        $this->newLine();

        $this->line('  <fg=cyan>2. Remove the use imports from routes/web.php:</>');
        $this->line("     use App\\Http\\Controllers\\CrudController\\{$module}Controller;");
        $this->line("     use App\\Http\\Controllers\\DataTableController\\{$module}DataTableController;");
        $this->line("     use App\\Http\\Controllers\\ReportController\\{$module}ReportController;");
        $this->newLine();

        $this->line('  <fg=cyan>3. Remove the sidebar link from resources/views/layouts/dashboard/partials/sidebar.blade.php</>');
        $this->newLine();
    }

    private function deleteDirectory(string $dir): void
    {
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$item;

            if (is_dir($path)) {
                $this->deleteDirectory($path);
            } else {
                unlink($path);
            }
        }

        rmdir($dir);
    }
}
