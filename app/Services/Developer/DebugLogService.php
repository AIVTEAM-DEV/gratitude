<?php

namespace App\Services\Developer;

use Carbon\Carbon;
use Illuminate\Support\Facades\File;
use SplFileInfo;

class DebugLogService
{
    public function read(?string $fileName = null, int $lineCount = 300): array
    {
        $lineCount = min(2000, max(50, $lineCount));
        $files = $this->files();

        if ($files === []) {
            return [
                'files' => [],
                'file' => null,
                'line_count' => $lineCount,
                'lines_returned' => 0,
                'content' => '',
                'entries' => [],
            ];
        }

        $selectedFile = $this->selectedFile($files, $fileName);
        $path = $this->logPath($selectedFile);
        $lines = $this->tailLines($path, $lineCount);

        return [
            'files' => $files,
            'file' => $selectedFile,
            'line_count' => $lineCount,
            'lines_returned' => count($lines),
            'content' => implode("\n", $lines),
            'entries' => $this->parseEntries($lines),
        ];
    }

    public function files(): array
    {
        $directory = storage_path('logs');

        if (! File::isDirectory($directory)) {
            return [];
        }

        return collect(File::files($directory))
            ->filter(fn (SplFileInfo $file) => $file->getExtension() === 'log')
            ->map(fn (SplFileInfo $file) => [
                'name' => $file->getFilename(),
                'size' => $file->getSize(),
                'size_label' => $this->formatBytes($file->getSize()),
                'modified_at' => Carbon::createFromTimestamp($file->getMTime())->toDateTimeString(),
                'modified_label' => Carbon::createFromTimestamp($file->getMTime())->diffForHumans(),
            ])
            ->sortByDesc('modified_at')
            ->values()
            ->all();
    }

    public function clear(?string $fileName = null): ?array
    {
        $files = $this->files();

        if ($files === []) {
            return null;
        }

        $selectedFile = $this->selectedFile($files, $fileName);
        $path = $this->logPath($selectedFile);

        if (! is_writable($path)) {
            return null;
        }

        File::put($path, '');

        return [
            'file' => $selectedFile,
            'message' => "{$selectedFile} cleared.",
        ];
    }

    private function selectedFile(array $files, ?string $fileName): string
    {
        $fileName = $fileName ? basename($fileName) : null;
        $names = collect($files)->pluck('name');

        return $fileName && $names->contains($fileName)
            ? $fileName
            : (string) $names->first();
    }

    private function logPath(string $fileName): string
    {
        return storage_path('logs'.DIRECTORY_SEPARATOR.basename($fileName));
    }

    private function tailLines(string $path, int $lineCount): array
    {
        if (! is_readable($path)) {
            return [];
        }

        $file = new \SplFileObject($path, 'r');
        $file->seek(PHP_INT_MAX);

        $lastLine = $file->key();
        $startLine = max(0, $lastLine - $lineCount + 1);
        $file->seek($startLine);

        $lines = [];

        while (! $file->eof()) {
            $line = rtrim((string) $file->fgets(), "\r\n");

            if ($line === '' && $file->eof()) {
                continue;
            }

            $lines[] = $line;
        }

        return $lines;
    }

    private function parseEntries(array $lines): array
    {
        $entries = [];
        $entry = null;

        foreach ($lines as $line) {
            if (preg_match('/^\[(?<timestamp>\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\]\s+(?<environment>[^.]+)\.(?<level>[A-Z]+):\s*(?<message>.*)$/', $line, $matches)) {
                if ($entry !== null) {
                    $entries[] = $this->formatEntry($entry);
                }

                $entry = [
                    'timestamp' => $matches['timestamp'],
                    'environment' => $matches['environment'],
                    'level' => $matches['level'],
                    'message' => $matches['message'],
                    'lines' => [$line],
                ];

                continue;
            }

            if ($entry === null) {
                $entry = [
                    'timestamp' => null,
                    'environment' => null,
                    'level' => 'RAW',
                    'message' => $line,
                    'lines' => [$line],
                ];

                continue;
            }

            $entry['lines'][] = $line;
        }

        if ($entry !== null) {
            $entries[] = $this->formatEntry($entry);
        }

        return array_reverse(array_slice($entries, -250));
    }

    private function formatEntry(array $entry): array
    {
        $lines = $entry['lines'];

        return [
            'timestamp' => $entry['timestamp'],
            'environment' => $entry['environment'],
            'level' => $entry['level'],
            'message' => $entry['message'],
            'line_count' => count($lines),
            'trace' => implode("\n", array_slice($lines, 1)),
            'raw' => implode("\n", $lines),
        ];
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 1).' MB';
        }

        if ($bytes >= 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        return $bytes.' B';
    }
}
