<?php

namespace RFPL;

/**
 * Respond First, Process Later Class
 */
class Cache
{
    protected $path;
    protected $ttl;
    protected $url;
    protected $filter = null;

    public function __construct(array $options = [])
    {
        $defaults = [
            'path' => 'cache',
            'ttl' => 300 // seconds to pass request
        ];

        // Merge options with defaults
        $options = array_replace($defaults, $options);
        extract($options);

        if (realpath($path) && !is_dir(realpath($path))) {
            throw new \Exception("Cache path must be a directory", 500);
        }

        // Try to create
        @mkdir($path, 0755, true);

        if (!realpath($path)) {
            throw new \Exception("Unable to create cache directory", 500);
        }

        $this->path = realpath($path);

        if ($ttl >= 0) {
            $this->ttl = (int) $ttl;
        }

        $this->url = $_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI'];
    }

    /**
     * Serve cache of current request
     *
     * @param callable $filter Filter to apply to content string
     * @return void
     *
     */
    public function serve(callable $filter = null)
    {
        // Handle only GET requests
        if (strtolower($_SERVER['REQUEST_METHOD']) !== 'get') {
            throw new \Exception("Only GET requests can be cached", 301);
        }

        // Get cache file path
        if ($file = realpath($this->cacheFile())) {
            $content = file_get_contents($file);

            if ($filter && is_callable($filter)) {
                $content = $filter($content);
            }

            $encoding = array_map('trim', explode(',', $_SERVER['HTTP_ACCEPT_ENCODING']));

            if (in_array('gzip', $encoding)) {
                $content = gzencode($content);
                header('Content-Encoding: gzip');
            }

            $contentLength = strlen($content);
            header('Content-Length: '.$contentLength);
            header('Connection: close');

            ob_start();
            echo $content;
            ob_end_flush();
            ob_flush();
            flush();

            // Cache is still fresh:
            if (filemtime($file) + $this->ttl >= time()) {
                exit;
            }
        } else {
            // Store filter and apply it after store procedure
            $this->filter = $filter;
        }

        // Cache served was old, continue with processing
        // store output but don't output anything:
        ob_start([$this, 'store']);
    }

    /**
     * Stores response to cache
     *
     * @param string $content Buffered output string
     * @return string Empty string or buffer
     * @todo Check HTTP status code
     *
     */
    public function store($content)
    {
        $file = $this->cacheFile();
        $dir  = dirname($file);

        // Create dir if not exists
        @mkdir($dir, 0755, true);

        if (!realpath($dir)) {
            throw new \Exception("Unable to create cache file directory", 500);
        }

        if (!file_put_contents($file, $content)) {
            throw new \Exception("Failed to store cache for $url", 500);
        }

        // Filter was stored, apply it to content and send response
        if ($this->filter !== null && is_callable($this->filter)) {
            $filter = $this->filter;

            return $filter($content);
        }

        // No response, cache already sent
        return '';
    }

    protected function cacheFile()
    {
        return $this->path.'/'.$this->hash($this->url);
    }

    protected function hash($str)
    {
        $str = hash('sha256', $str);

        return substr($str, 0, 2).'/'.substr($str, 2);
    }
}