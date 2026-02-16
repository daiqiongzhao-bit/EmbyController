<?php

namespace app\media\controller;

use app\BaseController;
use app\media\model\SysConfigModel;
use think\facade\Request;

class Strm115 extends BaseController
{
    private function cfg_get($key, $default = '')
    {
        try {
            $m = new SysConfigModel();
            $row = $m->where('appName', 'strm115')->where('key', $key)->find();
            if ($row && isset($row['value']) && $row['value'] !== '') {
                return (string)$row['value'];
            }
        } catch (\Throwable $e) {
        }
        return $default;
    }

    private function http_get_json($url, $timeoutSec = 20)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return [false, 'curl_error: ' . $err, 0, null];
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $json = json_decode($resp, true);
        return [true, $resp, $code, $json];
    }

    // GET /media/strm115/redirect?fileId=...&pickcode=...&token=...
    // Returns 302 to direct link
    public function redirect()
    {
        $secret = $this->cfg_get('play_secret', '');
        $got = (string)input('token', '');

        // allow admin session bypass (manual testing)
        $isAdmin = (session('r_user') != null && (int)session('r_user')['authority'] === 0);
        if (!$isAdmin) {
            if ($secret === '' || $got === '' || !hash_equals($secret, $got)) {
                return response('forbidden', 403);
            }
        }

        $fileId = (string)input('fileId', '');
        $pickcode = (string)input('pickcode', '');
        if ($fileId === '' && $pickcode === '') {
            return response('bad request', 400);
        }

        $token = $this->cfg_get('b2_access_token', '');
        if ($token === '') {
            return response('115 token missing', 500);
        }

        // NOTE: endpoint may need adjustment; keep base configurable
        $apiBase = rtrim($this->cfg_get('openapi_base', 'https://proapi.115.com'), '/');
        $url = $apiBase . '/open/ufile/download';

        $qs = [];
        if ($fileId !== '') $qs['file_id'] = $fileId;
        if ($pickcode !== '') $qs['pickcode'] = $pickcode;

        // Most open apis accept bearer token; some accept access_token query. We'll try bearer first.
        $url .= '?' . http_build_query($qs);

        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'GET');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, 25);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'authorization: Bearer ' . $token,
            'user-agent: Mozilla/5.0',
        ]);
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
        $resp = curl_exec($ch);
        if ($resp === false) {
            $err = curl_error($ch);
            curl_close($ch);
            return response('upstream error: ' . $err, 502);
        }
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode($resp, true);

        // normalize url field
        $direct = '';
        if (is_array($j)) {
            if (isset($j['data']['url'])) $direct = (string)$j['data']['url'];
            if ($direct === '' && isset($j['data']['download_url'])) $direct = (string)$j['data']['download_url'];
            if ($direct === '' && isset($j['data']['link'])) $direct = (string)$j['data']['link'];
        }

        if ($direct === '') {
            return response('no direct url (http=' . $code . '): ' . $resp, 502);
        }

        return redirect($direct, 302);
    }
}
