<?php

namespace App\Training;

use App\Services\ProtocolImport\ProtocolImporter;
use App\Training\Models\Lesson;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * How long a lesson should take, worked out from what's in it so nobody has
 * to guess. The rates are the usual ones for training material:
 *
 * - Reading (designer pages and text above any lesson): 200 words a minute, plus 12 seconds a picture.
 * - PDF: 2 minutes a page.
 * - PowerPoint: 1 minute a slide.
 * - Video and audio: their running time (read from the file) plus 10%, for pausing and replaying.
 * - YouTube, Vimeo and other links: 5 minutes; a file to download: 3 minutes.
 * - Quiz: 45 seconds a one-answer or true/false question, 75 seconds a select-all question.
 *
 * A lesson's minimum time, when set, wins when it's longer.
 */
class Estimate
{
    public const WORDS_PER_MINUTE = 200;

    /** @return array{seconds: int, basis: string} */
    public static function lesson(Lesson $lesson): array
    {
        $words = str_word_count(RichText::text($lesson->body));
        $pictures = substr_count((string) $lesson->body, '<img');
        $reading = (int) round($words * 60 / self::WORDS_PER_MINUTE) + $pictures * 12;
        $parts = [];
        if ($words) {
            $parts[] = number_format($words).' '.($words === 1 ? 'word' : 'words');
        }

        $path = $lesson->file_path && Storage::disk('local')->exists($lesson->file_path) ? Storage::disk('local')->path($lesson->file_path) : null;
        $content = 0;
        switch ($lesson->type) {
            case 'pdf':
                $pages = $path ? (ProtocolImporter::pdfPageCount($path) ?? 1) : 0;
                $content = $pages * 120;
                if ($pages) {
                    $parts[] = $pages.' '.($pages === 1 ? 'page' : 'pages');
                }
                break;
            case 'slides':
                $slides = $path ? self::slideCount($path, (int) $lesson->file_size) : 0;
                $content = $slides * 60;
                if ($slides) {
                    $parts[] = $slides.' '.($slides === 1 ? 'slide' : 'slides');
                }
                break;
            case 'video':
            case 'audio':
                $length = $path ? self::mediaSeconds($path, (int) $lesson->file_size) : 0;
                $content = (int) round($length * 1.1);
                if ($length) {
                    $parts[] = sprintf('%d:%02d %s', intdiv($length, 60), $length % 60, $lesson->type);
                }
                break;
            case 'link':
                $content = 300;
                $parts[] = 'video or web page';
                break;
            case 'file':
                $content = $lesson->file_path ? 180 : 0;
                break;
            case 'quiz':
                $types = $lesson->questions()->pluck('type');
                $content = $types->sum(fn ($t) => $t === 'multiple' ? 75 : 45);
                $parts[] = $types->count().' '.($types->count() === 1 ? 'question' : 'questions');
                break;
        }

        $seconds = $reading + $content;
        if ($lesson->min_seconds && $lesson->min_seconds > $seconds) {
            $seconds = $lesson->min_seconds;
            $parts[] = 'minimum time';
        }

        return ['seconds' => $seconds, 'basis' => implode(', ', $parts)];
    }

    /** Slides in a .pptx (counted inside the file); older .ppt files are guessed from their size. */
    public static function slideCount(string $path, int $size): int
    {
        if (class_exists(\ZipArchive::class)) {
            $zip = new \ZipArchive;
            if ($zip->open($path) === true) {
                $count = 0;
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if (preg_match('~^ppt/slides/slide\d+\.xml$~', (string) $zip->getNameIndex($i))) {
                        $count++;
                    }
                }
                $zip->close();
                if ($count) {
                    return $count;
                }
            }
        }

        return max(1, (int) round($size / 250_000));
    }

    /**
     * Running time of a video or audio file in seconds. MP4, MOV and M4A files
     * say it in their header; WAV is worked out from its format; anything else
     * is guessed from its size (about 1 MB a minute for audio, 10 for video).
     */
    public static function mediaSeconds(string $path, int $size): int
    {
        try {
            $fh = fopen($path, 'rb');
            if ($fh) {
                $seconds = self::mp4Seconds($fh, $size) ?? self::wavSeconds($fh);
                fclose($fh);
                if ($seconds) {
                    return $seconds;
                }
            }
        } catch (Throwable) {
            // Fall back to the size guess.
        }
        $isVideo = preg_match('/\.(mp4|m4v|mov|webm)$/i', $path);

        return max(30, (int) round($size / ($isVideo ? 10_000_000 : 1_000_000) * 60));
    }

    /** Reads the duration from an MP4/MOV "moov > mvhd" box. */
    private static function mp4Seconds($fh, int $size): ?int
    {
        $find = function (int $start, int $end, string $type) use ($fh): ?array {
            $pos = $start;
            while ($pos + 8 <= $end) {
                fseek($fh, $pos);
                $head = fread($fh, 16);
                if (strlen($head) < 8) {
                    return null;
                }
                $len = unpack('N', substr($head, 0, 4))[1];
                $name = substr($head, 4, 4);
                $headLen = 8;
                if ($len === 1) {
                    $len = (int) (unpack('J', substr($head, 8, 8))[1]);
                    $headLen = 16;
                } elseif ($len === 0) {
                    $len = $end - $pos;
                }
                if ($len < $headLen) {
                    return null;
                }
                if ($name === $type) {
                    return [$pos + $headLen, $pos + $len];
                }
                $pos += $len;
            }

            return null;
        };
        $moov = $find(0, $size ?: PHP_INT_MAX, 'moov');
        $mvhd = $moov ? $find($moov[0], $moov[1], 'mvhd') : null;
        if (! $mvhd) {
            return null;
        }
        fseek($fh, $mvhd[0]);
        $data = fread($fh, 32);
        $version = ord($data[0]);
        if ($version === 1) {
            $scale = unpack('N', substr($data, 20, 4))[1];
            $duration = unpack('J', substr($data, 24, 8))[1];
        } else {
            $scale = unpack('N', substr($data, 12, 4))[1];
            $duration = unpack('N', substr($data, 16, 4))[1];
        }

        return $scale ? (int) round($duration / $scale) : null;
    }

    private static function wavSeconds($fh): ?int
    {
        fseek($fh, 0);
        $head = fread($fh, 44);
        if (strlen($head) < 44 || substr($head, 0, 4) !== 'RIFF' || substr($head, 8, 4) !== 'WAVE') {
            return null;
        }
        $byteRate = unpack('V', substr($head, 28, 4))[1];
        $dataSize = unpack('V', substr($head, 40, 4))[1];

        return $byteRate ? (int) round($dataSize / $byteRate) : null;
    }

    /** 0 → "Under 1 min", 95 → "About 2 min", 4000 → "About 1 h 7 min". */
    public static function label(?int $seconds): string
    {
        $minutes = (int) round(($seconds ?? 0) / 60);
        if ($minutes < 1) {
            return 'Under 1 min';
        }

        return 'About '.($minutes < 60 ? $minutes.' min' : intdiv($minutes, 60).' h'.($minutes % 60 ? ' '.($minutes % 60).' min' : ''));
    }
}
