<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\SysConfigModel;
use think\facade\Request;

class Strm extends BaseController
{
    private function getConfig(string $key, $default = null)
    {
        try {
            $model = new SysConfigModel();
            $row = $model->where('appName', 'strm')->where('key', $key)->find();
            if ($row && isset($row['value'])) {
                return $row['value'];
            }
        } catch (\Throwable $e) {
            // ignore
        }
        return $default;
    }

    private function getAllowedRoots(): array
    {
        $roots = $this->getConfig('allowed_roots', '');
        $list = [];
        foreach (explode("\n", (string)$roots) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $list[] = rtrim($line, '/');
            }
        }
        return $list;
    }

    private function isPathAllowed(string $path): bool
    {
        $real = realpath($path);
        if ($real === false) return false;

        foreach ($this->getAllowedRoots() as $root) {
            $rootReal = realpath($root);
            if ($rootReal === false) continue;
            if (str_starts_with($real, rtrim($rootReal, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR) || $real === $rootReal) {
                return true;
            }
        }
        return false;
    }

    // GET /media/strm/play?path=/abs/path/to/file.mkv
    public function play()
    {
        $path = (string)input('path', '');
        if ($path === '') {
            return response('missing path', 400);
        }

        // security: only allow within configured roots
        if (!$this->isPathAllowed($path)) {
            return response('forbidden', 403);
        }

        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            return response('not found', 404);
        }

        $size = filesize($real);
        if ($size === false) {
            return response('cannot read file', 500);
        }

        $fh = fopen($real, 'rb');
        if (!$fh) {
            return response('cannot open file', 500);
        }

        $start = 0;
        $end = $size - 1;
        $status = 200;

        $range = Request::header('range');
        if ($range && preg_match('/bytes=(\d*)-(\d*)/i', $range, $m)) {
            $status = 206;
            if ($m[1] !== '') $start = (int)$m[1];
            if ($m[2] !== '') $end = (int)$m[2];
            if ($m[1] !== '' && $m[2] === '') {
                $end = $size - 1;
            }
            if ($m[1] === '' && $m[2] !== '') {
                // suffix bytes
                $suffix = (int)$m[2];
                $start = max(0, $size - $suffix);
                $end = $size - 1;
            }
            if ($start > $end || $start >= $size) {
                fclose($fh);
                header('Content-Range: bytes */' . $size);
                return response('range not satisfiable', 416);
            }
        }

        $length = $end - $start + 1;
        $ext = strtolower(pathinfo($real, PATHINFO_EXTENSION));
        $contentType = 'application/octet-stream';
        if (in_array($ext, ['mp4'])) $contentType = 'video/mp4';
        if (in_array($ext, ['mkv'])) $contentType = 'video/x-matroska';
        if (in_array($ext, ['avi'])) $contentType = 'video/x-msvideo';
        if (in_array($ext, ['mov'])) $contentType = 'video/quicktime';
        if (in_array($ext, ['m4v'])) $contentType = 'video/x-m4v';

        // send headers
        header('Content-Type: ' . $contentType);
        header('Accept-Ranges: bytes');
        header('Content-Length: ' . $length);
        header('Content-Disposition: inline; filename="' . basename($real) . '"');
        header('Cache-Control: no-cache');
        if ($status === 206) {
            header('Content-Range: bytes ' . $start . '-' . $end . '/' . $size);
            http_response_code(206);
        } else {
            http_response_code(200);
        }

        // stream
        fseek($fh, $start);
        $chunk = 1024 * 1024;
        $remaining = $length;
        while ($remaining > 0 && !feof($fh)) {
            $read = ($remaining > $chunk) ? $chunk : $remaining;
            $buf = fread($fh, $read);
            if ($buf === false) break;
            echo $buf;
            $remaining -= strlen($buf);
            if (function_exists('fastcgi_finish_request')) {
                // do not finish early; keep streaming
            }
        }
        fclose($fh);
        exit;
    }


    private function loadStrmCfg(): array
    {
        try {
            $cfg = new \app\media\model\SysConfigModel();
            $rows = $cfg->where('appName','strm')->select();
            $m = [];
            foreach ($rows as $r) {
                $m[$r['key']] = $r['value'];
            }
            return $m;
        } catch (\Throwable $e) {
            return [];
        }
    }

    // GET /media/strm/redirect?path=...
    public function redirect()
    {
        $cfg = $this->loadStrmCfg();
        $enabled = isset($cfg['redirect_enabled']) && (string)$cfg['redirect_enabled'] === '1';
        if (!$enabled) {
            return response('disabled', 403);
        }
        $base = trim((string)($cfg['redirect_base'] ?? ''));
        if ($base === '' || !(str_starts_with($base, 'http://') || str_starts_with($base, 'https://'))) {
            return response('bad config', 500);
        }
        $path = (string)input('path','');
        if ($path === '') {
            return response('bad request', 400);
        }
        $real = realpath($path);
        if ($real === false) {
            return response('not found', 404);
        }

        $allowed = (string)($cfg['allowed_roots'] ?? '');
        $roots = array_values(array_filter(array_map('trim', preg_split('/\r?\n/', $allowed))));
        $matched = null;
        foreach ($roots as $r) {
            $rr = realpath($r);
            if ($rr && str_starts_with($real, rtrim($rr, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR)) {
                $matched = $rr;
                break;
            }
        }
        if (!$matched) {
            return response('forbidden', 403);
        }

        $rel = ltrim(str_replace(rtrim($matched, DIRECTORY_SEPARATOR), '', $real), DIRECTORY_SEPARATOR);
        $parts = array_map('rawurlencode', preg_split('#[/\\\\]+#', $rel));
        $url = rtrim($base, '/') . '/' . implode('/', $parts);

        return redirect($url, 302);
    }

    // POST /media/strm/cloudDrive2Webhook
    public function cloudDrive2Webhook()
    {
        $cfg = $this->loadStrmCfg();
        $enabled = isset($cfg['clouddrive2_webhook_enabled']) && (string)$cfg['clouddrive2_webhook_enabled'] === '1';
        if (!$enabled) {
            return json(['code'=>403,'message'=>'webhook disabled']);
        }
        $body = (string)Request::getContent();
        $payload = json_decode($body, true);
        if (!is_array($payload)) {
            $payload = ['raw' => $body];
        }

        $dir = runtime_path() . 'strm/events/';
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }
        $line = json_encode([
            'time' => date('Y-m-d H:i:s'),
            'ip' => Request::ip(),
            'payload' => $payload,
        ], JSON_UNESCAPED_UNICODE);
        @file_put_contents($dir . 'clouddrive2_' . date('Ymd') . '.jsonl', $line . "\n", FILE_APPEND);

        return json(['code'=>200,'data'=>['ok'=>1]]);
    }

}