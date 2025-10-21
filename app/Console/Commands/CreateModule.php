<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;


class CreateModule extends Command
{
    protected $signature = 'make:module {name}';
    protected $description = 'Create a new module with Services, Repositories, Enums, Requests, and Resources';

    public function handle()
    {
        $moduleName = $this->argument('name');

        // Create directories for the module
        $paths = [
            app_path("Modules/{$moduleName}/Services"),
            app_path("Modules/{$moduleName}/Repositories"),
            app_path("Modules/{$moduleName}/Enums"),
            app_path("Modules/{$moduleName}/Requests"),
            app_path("Modules/{$moduleName}/Resources")
        ];

        foreach ($paths as $path) {
            if (!File::exists($path)) {
                File::makeDirectory($path, 0755, true);
                $this->info("Directory created: {$path}");
            }
        }

        // You can also create base files for each of these components
        $this->createService($moduleName);
        $this->createRepository($moduleName);
        $this->createEnum($moduleName);
        $this->createRequest($moduleName);
        $this->createResource($moduleName);

        $this->info("Module {$moduleName} created successfully!");
    }

    protected function createService($moduleName)
    {
        $servicePath = app_path("Modules/{$moduleName}/Services/{$moduleName}Service.php");
        $stub = "<?php\n\nnamespace App\\Modules\\{$moduleName}\\Services;\n\nclass {$moduleName}Service {\n    // Service logic here\n}\n";
        File::put($servicePath, $stub);
    }

    protected function createRepository($moduleName)
    {
        $repositoryPath = app_path("Modules/{$moduleName}/Repositories/{$moduleName}Repository.php");
        $stub = "<?php\n\nnamespace App\\Modules\\{$moduleName}\\Repositories;\n\nclass {$moduleName}Repository {\n    // Repository logic here\n}\n";
        File::put($repositoryPath, $stub);
    }

    protected function createEnum($moduleName)
    {
        $enumPath = app_path("Modules/{$moduleName}/Enums/{$moduleName}Enum.php");
        $stub = "<?php\n\nnamespace App\\Modules\\{$moduleName}\\Enums;\n\nclass {$moduleName}Enum {\n    // Enum values here\n}\n";
        File::put($enumPath, $stub);
    }

    protected function createRequest($moduleName)
    {
        $requestPath = app_path("Modules/{$moduleName}/Http/Requests/{$moduleName}Request.php");
        $stub = "<?php\n\nnamespace App\\Modules\\{$moduleName}\\Requests;\n\nclass {$moduleName}Request {\n    // Request validation logic\n}\n";
        File::put($requestPath, $stub);
    }

    protected function createResource($moduleName)
    {
        $resourcePath = app_path("Modules/{$moduleName}/Resources/{$moduleName}Resource.php");
        $stub = "<?php\n\nnamespace App\\Modules\\{$moduleName}\\Resources;\n\nclass {$moduleName}Resource {\n    // Resource transformation logic\n}\n";
        File::put($resourcePath, $stub);
    }
}