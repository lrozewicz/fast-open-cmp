<?php
/**
 * Streams a file download in chunks, optionally gzip-compressed on the fly, so
 * exports of millions of rows never sit in memory.
 */

if (!defined('ABSPATH')) {
    exit;
}

class FOCMP_Download_Stream {

    /** @var resource|\DeflateContext|null */
    private $deflate = null;
    private $buffer = '';

    /**
     * Sends the download headers. Gzip only when zlib supports incremental
     * compression; the file name gets ".gz" then.
     */
    public function __construct($filename, $content_type, $gzip = false) {
        $gzip = $gzip && function_exists('deflate_init');
        if ($gzip) {
            $this->deflate = deflate_init(ZLIB_ENCODING_GZIP, array('level' => 6));
            $filename .= '.gz';
            $content_type = 'application/gzip';
        }
        // Long exports: no time limit, no output buffering in between.
        if (function_exists('set_time_limit')) {
            @set_time_limit(0); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        nocache_headers();
        header('Content-Type: ' . $content_type);
        // Not sanitize_file_name(): it rewrites double extensions (".jsonl.gz").
        header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $filename) . '"');
        header('X-Content-Type-Options: nosniff');
    }

    public static function gzip_available() {
        return function_exists('deflate_init');
    }

    public function write($data) {
        $this->buffer .= $data;
        if (strlen($this->buffer) >= 65536) {
            $this->flush_buffer(false);
        }
    }

    public function close() {
        $this->flush_buffer(true);
    }

    private function flush_buffer($final) {
        $data = $this->buffer;
        $this->buffer = '';
        if ($this->deflate) {
            $data = deflate_add($this->deflate, $data, $final ? ZLIB_FINISH : ZLIB_NO_FLUSH);
        }
        echo $data; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- file download body
        flush();
    }
}
