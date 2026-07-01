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
        $count = max(1, min((int) $request->input('count', 12), 25));

        if ($query === '') {
            $query = 'trending';
        }

        $searchTerm = "ytsearch{$count}:{$query}";
        $command = "yt-dlp --dump-json --flat-playlist " . escapeshellarg($searchTerm);

        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json([
                'error' => 'Search failed',
                'details' => $result->errorOutput() ?? $result->output()
            ], 500);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));

        $videos = array_map(function ($line) {
            return json_decode($line, true);
        }, $lines);

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

    public function getVideoInfo(Request $request)
    {
        return $this->extractInfo($request);
    }

    /**
     * Download stream.
     */
    public function downloadFile(Request $request)
    {
        $url = trim((string) $request->input('url', ''));
        $formatId = trim((string) $request->input('format_id', ''));

        if ($url === '') {
            return response()->json(['error' => 'URL is required'], 400);
        }

        $metadata = $this->fetchVideoData($url);
        $title = $metadata->title ?? 'video';
        $ext = $metadata->ext ?? 'mp4';

        $filename = $this->safeFilename($title, $ext);

        $formatSelector = $formatId !== ''
            ? escapeshellarg($formatId)
            : 'bestvideo+bestaudio/best';

        $command = "yt-dlp --no-progress -o - -f {$formatSelector} " . escapeshellarg($url);

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
        }, $filename, [
            'Content-Type' => 'application/octet-stream',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * Shorts pagination (FIXED completely).
     */
    public function getShorts(Request $request)
    {
        $page = max(1, (int) $request->input('page', 1));
        $perPage = max(1, min((int) $request->input('per_page', 5), 20));

        $maxLimit = 200;
        $totalNeeded = min($page * $perPage, $maxLimit);

        $searchTerm = "ytsearch{$totalNeeded}:shorts";
        $command = "yt-dlp --dump-json --flat-playlist " . escapeshellarg($searchTerm);

        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json([
                'error' => 'Could not fetch shorts',
                'details' => $result->errorOutput() ?? $result->output()
            ], 500);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));

        $videos = array_map(function ($line) {
            return json_decode($line, true);
        }, $lines);

        $formatted = collect($this->formatVideoList($videos));

        $offset = ($page - 1) * $perPage;
        $items = $formatted->slice($offset, $perPage)->values();

        $count = $formatted->count();

        $hasMore = $count > ($offset + $perPage) || ($totalNeeded < $maxLimit);

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
     * Playlist search.
     */
    public function searchPlaylists(Request $request)
    {
        $query = trim((string) $request->input('q', ''));

        if ($query === '') {
            return response()->json([]);
        }

        $count = max(1, min((int) $request->input('count', 12), 25));

        $queryEncoded = urlencode($query);
        $url = "https://www.youtube.com/results?search_query={$queryEncoded}&sp=EgIQAw%3D%3D";

        $command = "yt-dlp --dump-json --flat-playlist --playlist-end {$count} " . escapeshellarg($url);

        $result = Process::run($command);

        if ($result->failed() || trim($result->output()) === '') {
            return response()->json(['error' => 'Playlist search failed'], 500);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));

        $items = array_map(fn($line) => json_decode($line, true), $lines);

        $playlists = collect($items)
            ->filter(fn($item) => isset($item->_type) && ($item->_type === 'playlist' || isset($item->url)))
            ->map(function ($p) {
                $thumb = $p->thumbnail ?? '';

                if (!$thumb && isset($p->thumbnails) && is_array($p->thumbnails)) {
                    $last = end($p->thumbnails);
                    $thumb = $last->url ?? '';
                }

                return [
                    'id' => $p->id ?? null,
                    'title' => $p->title ?? 'Untitled playlist',
                    'thumbnail' => $thumb,
                    'uploader' => $p->uploader ?? 'Unknown',
                    'video_count' => $p->playlist_count ?? null,
                ];
            })
            ->values();

        return response()->json($playlists);
    }

    public function getPlaylistVideos(Request $request)
    {
        $playlistId = trim((string) $request->input('list', ''));

        if ($playlistId === '') {
            return response()->json(['error' => 'Playlist id is required'], 400);
        }

        $url = "https://www.youtube.com/playlist?list={$playlistId}";
        $command = "yt-dlp --dump-json --flat-playlist " . escapeshellarg($url);

        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Could not fetch playlist videos'], 500);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));

        $videos = array_map(fn($line) => json_decode($line, true), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    public function getTrendingVideos()
    {
        $url = "https://www.youtube.com";
        $command = "yt-dlp --dump-json --flat-playlist --playlist-end 12 " . escapeshellarg($url);

        $result = Process::run($command);

        if ($result->failed()) {
            return response()->json(['error' => 'Could not fetch trending videos'], 500);
        }

        $lines = array_values(array_filter(explode("\n", trim($result->output()))));

        $videos = array_map(fn($line) => json_decode($line, true), $lines);

        return response()->json($this->formatVideoList($videos));
    }

    /**
     * Helpers
     */
   private function fetchVideoData(string $url)
{
    // Wrap URL safely to prevent shell injection vulnerabilities
    $command = "yt-dlp --dump-json --no-playlist " . escapeshellarg($url);
    
    // Execute the process directly (Laravel handles standard output buffering automatically)
    // Optional: Add ->timeout(120) if large streams take a long time to download metadata
    $result = Process::run($command);

    if ($result->failed()) {
        // Log the background system error cleanly to your storage/logs/laravel.log
        logger()->error("yt-dlp core failed", [
            'error' => $result->errorOutput() ?: $result->output()
        ]);
        return null;
    }

    return json_decode($result->output());
}



    private function buildInfoResponse($data): array
    {
        $formats = collect($data->formats ?? []);

        $bestAv = $formats->where('vcodec', '!=', 'none')
            ->where('acodec', '!=', 'none')
            ->sortBy('tbr')
            ->last();

        $bestVideo = $formats->where('vcodec', '!=', 'none')
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
            'formats' => $formats->map(fn($f) => [
                'format_id' => $f->format_id ?? null,
                'quality' => $f->format_note ?? $f->resolution ?? 'Unknown',
                'ext' => $f->ext ?? 'mp4',
                'filesize' => $this->formatBytes($f->filesize ?? $f->filesize_approx ?? 0),
                'vcodec' => $f->vcodec ?? 'none',
                'acodec' => $f->acodec ?? 'none',
                'fps' => $f->fps ?? null,
                'url' => $f->url ?? null,
            ])->values(),
        ];
    }

    private function formatVideoList(array $videos)
    {
        return collect($videos)->map(function ($v) {
            $id = $v['id'] ?? null;

            return [
                'id' => $id,
                'title' => $v['title'] ?? 'Untitled',
                'thumbnail' => $v['thumbnail'] ?? ($id ? "https://i.ytimg.com/vi/{$id}/hqdefault.jpg" : ''),
                'url' => $v['url'] ?? ($id ? "https://www.youtube.com/watch?v={$id}" : ''),
                'views' => isset($v['view_count']) ? number_format($v['view_count']) : null,
            ];
        })->values();
    }

    private function formatBytes($bytes): string
    {
        $bytes = (float) $bytes;
        if ($bytes <= 0) return 'Unknown';

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(log($bytes, 1024));
        $pow = min($pow, count($units) - 1);

        return number_format($bytes / pow(1024, $pow), 2) . ' ' . $units[$pow];
    }

    private function safeFilename(string $title, string $ext): string
    {
        $clean = preg_replace('/[^A-Za-z0-9._-]+/', '_', $title);
        $clean = trim($clean, '_');

        return ($clean ?: 'video') . '.' . $ext;
    }
}