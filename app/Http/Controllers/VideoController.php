<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Process\Process as SymfonyProcess;

class VideoController extends Controller
{
    /**
     * Search videos using yt-dlp.
     */
    public function search(Request $request)
    {
        $query = trim((string) $request->input('q', ''));
        $count = (int) $request->input('count', 10);
        $count = max(1, min($count, 25));

        if ($query === '') {
            $query = 'trending';
        }

        $searchTerm = "ytsearch{$count}:{$query}";
        $command = 'yt-dlp --dump-json --flat-playlist ' . escapeshellarg($searchTerm);
        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Search failed'], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($line) => json_decode($line), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /**
     * Extract metadata + all formats for a URL.
     */
    public function extractInfo(Request $request)
    {
        $url = trim((string) $request->input('url', ''));
        if ($url === '') {
            return response()->json(['error' => 'URL is required'], 400);
        }

        $data = $this->fetchVideoData($url);
        if (!$data) {
            return response()->json(['error' => 'Could not extract video info'], 400);
        }

        return response()->json($this->buildInfoResponse($data));
    }

    /**
     * Backward-compatible info endpoint.
     */
    public function getVideoInfo(Request $request)
    {
        return $this->extractInfo($request);
    }

    /**
     * Stream a download so the browser saves the file.
     */
    public function downloadFile(Request $request)
    {
        $url = trim((string) $request->input('url', ''));
        $formatId = trim((string) $request->input('format_id', ''));

        if ($url === '') {
            return response()->json(['error' => 'URL is required'], 400);
        }

        $metadata = $this->fetchVideoData($url) ?? (object) [];
        $title = $metadata->title ?? 'video';
        $ext = $metadata->ext ?? 'mp4';
        $filename = $this->safeFilename($title, $ext);

        $formatSelector = $formatId !== '' ? escapeshellarg($formatId) : 'bestvideo+bestaudio/best';
        $command = 'yt-dlp --no-progress -o - -f ' . $formatSelector . ' ' . escapeshellarg($url);

        $process = SymfonyProcess::fromShellCommandline($command);
        $process->setTimeout(null);
        $process->start();

        return response()->streamDownload(function () use ($process) {
            foreach ($process as $type => $data) {
                if ($type === SymfonyProcess::OUT) {
                    echo $data;
                    flush();
                }
            }
            $process->wait();
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Get Shorts with pagination.
     */
    public function getShorts(Request $request)
    {
        $page = (int) $request->input('page', 1);
        $perPage = (int) $request->input('per_page', 5);

        $page = max(1, $page);
        $perPage = max(1, min($perPage, 20));

        $maxLimit = 200;
        $totalNeeded = min($page * $perPage, $maxLimit);

        $searchTerm = "ytsearch{$totalNeeded}:shorts";
        $command = 'yt-dlp --dump-json --flat-playlist ' . escapeshellarg($searchTerm);
        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Could not fetch shorts'], 500);
        }

        $lines = array_filter(explode("
", trim($result->output())));
        $videos = array_map(fn($line) => json_decode($line), $lines);
        $formatted = $this->formatVideoList($videos);

        $offset = ($page - 1) * $perPage;
        $items = $formatted->slice($offset, $perPage)->values();
        $count = $formatted->count();
        $hasMore = $count > ($offset + $perPage) || ($count >= $totalNeeded && $totalNeeded < $maxLimit);

        return response()->json([
            'page' => $page,
            'per_page' => $perPage,
            'has_more' => $hasMore,
            'items' => $items,
            'total_fetched' => $count,
            'max_limit' => $maxLimit,
        ]);
    }

    /**
     * Get "Famous" or Trending videos like Vidmate.
     */
    public function getTrendingVideos()
    {
        $url = 'https://www.youtube.com';
        $command = 'yt-dlp --dump-json --flat-playlist --playlist-end 12 ' . escapeshellarg($url);

        $result = Process::run($command);
        if ($result->failed()) {
            return response()->json(['error' => 'Could not fetch trending videos'], 500);
        }

        $lines = array_filter(explode("\n", trim($result->output())));
        $videos = array_map(fn($line) => json_decode($line), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    private function fetchVideoData(string $url)
    {
        $command = 'yt-dlp --dump-json --no-playlist ' . escapeshellarg($url);
        $result = Process::run($command);

        if ($result->failed()) {
            return null;
        }

        return json_decode($result->output());
    }

    private function buildInfoResponse($data): array
    {
        $formats = collect($data->formats ?? []);

        $bestAv = $formats
            ->where('vcodec', '!=', 'none')
            ->where('acodec', '!=', 'none')
            ->sortBy('tbr')
            ->last();

        $bestVideo = $formats
            ->where('vcodec', '!=', 'none')
            ->sortBy('height')
            ->last();

        $bestFormat = $bestAv ?? $bestVideo ?? $formats->last();

        return [
            'id' => $data->id ?? null,
            'title' => $data->title ?? 'No Title',
            'thumbnail' => $data->thumbnail ?? '',
            'duration' => $data->duration_string ?? '0:00',
            'uploader' => $data->uploader ?? 'Unknown',
            'best_format_id' => $bestFormat->format_id ?? null,
            'formats' => $formats->map(function ($f) {
                $filesize = $f->filesize ?? $f->filesize_approx ?? 0;

                return [
                    'format_id' => $f->format_id ?? null,
                    'quality' => $f->format_note ?? $f->resolution ?? 'Unknown',
                    'ext' => $f->ext ?? 'mp4',
                    'filesize' => $this->formatBytes($filesize),
                    'vcodec' => $f->vcodec ?? 'none',
                    'acodec' => $f->acodec ?? 'none',
                    'fps' => $f->fps ?? null,
                    'url' => $f->url ?? null,
                ];
            })->values(),
        ];
    }

    private function formatVideoList(array $videos)
    {
        return collect($videos)->map(function ($v) {
            $id = $v->id ?? null;

            return [
                'id' => $id,
                'title' => $v->title ?? 'Untitled',
                'thumbnail' => $v->thumbnail
                    ?? ($id ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : ''),
                'url' => $v->url
                    ?? ($id ? "https://www.youtube.com/watch?v={$id}" : ''),
                'views' => isset($v->view_count) ? number_format($v->view_count) : null,
            ];
        })->values();
    }

    private function formatBytes($bytes): string
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) {
            return 'Unknown';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        $value = $bytes / pow(1024, $pow);

        return number_format($value, 2) . ' ' . $units[$pow];
    }

    private function safeFilename(string $title, string $ext): string
    {
        $cleanTitle = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
        $cleanTitle = trim($cleanTitle, '_');

        if ($cleanTitle === '') {
            $cleanTitle = 'video';
        }

        return $cleanTitle . '.' . $ext;
    }
}
