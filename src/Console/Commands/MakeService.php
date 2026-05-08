<?php

namespace Sanjid29\StarterCore\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Str;

class MakeService extends Command
{
    protected $signature = 'make:service
                            {name : Model name in PascalCase (e.g. LeaveRequest)}
                            {--force : Overwrite if the file already exists}';

    protected $description = 'Generate a service class for a model';

    public function handle(): int
    {
        $name = $this->argument('name');

        if (! preg_match('/^[A-Z][a-zA-Z0-9]*$/', $name)) {
            $this->error('Name must be PascalCase with no spaces or special characters (e.g. LeaveRequest).');

            return self::FAILURE;
        }

        $destination = "app/Services/{$name}Service.php";
        $fullPath = base_path($destination);

        if (file_exists($fullPath) && ! $this->option('force')) {
            $this->warn("  SKIPPED  {$destination} (already exists, use --force to overwrite)");

            return self::SUCCESS;
        }

        $appStubPath = base_path('stubs/service.stub');
        $packageStubPath = dirname(__DIR__, 3).'/stubs/service.stub';
        $stubPath = file_exists($appStubPath) ? $appStubPath : $packageStubPath;

        $content = str_replace(
            ['{ModuleName}', '{module_name}'],
            [$name, Str::snake($name)],
            file_get_contents($stubPath)
        );

        $directory = dirname($fullPath);

        if (! is_dir($directory)) {
            mkdir($directory, 0755, true);
        }

        file_put_contents($fullPath, $content);

        $this->info("  CREATED  {$destination}");

        return self::SUCCESS;
    }
}
