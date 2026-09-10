<?php

declare(strict_types=1);

namespace AgentsFullDuplex\RealtimeAgent\Console;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

final class InstallCommand extends Command
{
    protected $signature = 'realtime-agent:install {--force : Replace published configuration and assets}';

    protected $description = 'Publish the Realtime Agent configuration, timestamped migrations, and browser runtime.';

    public function handle(Filesystem $files): int
    {
        $force = (bool) $this->option('force');
        $this->call('vendor:publish', [
            '--tag' => 'realtime-agent-config',
            '--force' => $force,
        ]);

        $source = dirname(__DIR__, 2).'/database/migrations';
        $target = database_path('migrations');
        $files->ensureDirectoryExists($target);

        foreach ($files->files($source) as $index => $migration) {
            $suffix = preg_replace('/^\d{4}_\d{2}_\d{2}_\d{6}_/', '', $migration->getFilename());

            if (! is_string($suffix) || $suffix === '') {
                continue;
            }

            $installed = $files->glob($target.'/*_'.$suffix);

            if ($installed !== [] && ! $force) {
                $this->components->twoColumnDetail($suffix, '<fg=yellow>already installed</>');

                continue;
            }

            foreach ($installed as $existing) {
                $files->delete($existing);
            }

            $timestamp = now()->addSeconds($index)->format('Y_m_d_His');
            $files->copy($migration->getPathname(), $target.'/'.$timestamp.'_'.$suffix);
            $this->components->twoColumnDetail($suffix, '<fg=green>published</>');
        }

        $dist = dirname(__DIR__, 2).'/dist';

        if ($files->isDirectory($dist)) {
            $public = public_path('vendor/realtime-agent');

            if (! $files->isDirectory($public) || $force) {
                $files->ensureDirectoryExists($public);
                $files->copyDirectory($dist, $public);
                $this->components->info('Browser runtime published.');
            } else {
                $this->components->warn('Browser runtime already published; use --force to refresh it.');
            }
        }

        $this->newLine();
        $this->components->info('Realtime Agent installed. Run php artisan migrate next.');

        return self::SUCCESS;
    }
}
