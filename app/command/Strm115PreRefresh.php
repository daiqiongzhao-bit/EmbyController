<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class Strm115PreRefresh extends Command
{
    protected function configure()
    {
        $this->setName('strm115:preRefresh')
            ->setDescription('Pre-refresh 115 access_token for enabled accounts (optional, controlled by config)');
    }

    private function cfg_get($key, $default = '')
    {
        try {
            $m = new \app\media\model\SysConfigModel();
            $row = $m->where('appName', 'strm115')->where('key', $key)->find();
            if ($row && isset($row['value']) && $row['value'] !== '') return (string)$row['value'];
        } catch (\Throwable $e) {
        }
        return $default;
    }

    private function cfg_upsert($key, $value)
    {
        $m = new \app\media\model\SysConfigModel();
        $row = $m->where(['appName' => 'strm115', 'key' => $key])->find();
        if ($row) {
            $row->save(['value' => (string)$value]);
        } else {
            $m->save(['appName' => 'strm115', 'key' => $key, 'value' => (string)$value, 'type' => 0, 'status' => 1]);
        }
    }

    private function http_post_form($url, $form, $timeoutSec = 25)
    {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'POST');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 8);
        curl_setopt($ch, CURLOPT_TIMEOUT, $timeoutSec);
        curl_setopt($ch, CURLOPT_HTTPHEADER, [
            'accept: application/json',
            'Content-Type: application/x-www-form-urlencoded',
        ]);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($form));
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

    public function execute(Input $input, Output $output)
    {
        $logDir = runtime_path() . 'strm115/logs/';
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }
        $logPath = $logDir . 'pre_refresh_' . date('Ymd') . '.log';
        $log = function ($line) use ($logPath, $output) {
            $s = '[' . date('Y-m-d H:i:s') . '] ' . $line;
            @file_put_contents($logPath, $s . PHP_EOL, FILE_APPEND);
            $output->writeln($s);
        };

        $enabled = (string)$this->cfg_get('pre_refresh_enabled', '0');
        $minLeftMin = (int)$this->cfg_get('pre_refresh_min_left_min', '10');
        if ($minLeftMin < 1) $minLeftMin = 1;
        $minLeftSec = $minLeftMin * 60;

        if ($enabled !== '1') {
            $log('skip: pre_refresh_enabled=0');
            return 0;
        }

        $now = time();
        $log('start: min_left=' . $minLeftMin . 'min');

        $m = new \app\media\model\Strm115AccountModel();
        $rows = $m->where('status', 1)->order('is_default', 'desc')->order('id', 'asc')->select();
        $countAll = 0;
        $countRefreshed = 0;
        $countSkip = 0;

        foreach ($rows as $r) {
            $a = $r->toArray();
            $countAll++;
            $id = (int)($a['id'] ?? 0);
            $name = (string)($a['name'] ?? '115');
            $expiresAt = (int)($a['expires_at'] ?? 0);
            $refresh = (string)($a['refresh_token'] ?? '');

            if ($refresh === '') {
                $log("acc#{$id} {$name}: skip (missing refresh_token)");
                $countSkip++;
                continue;
            }

            $need = false;
            if ($expiresAt <= 0) {
                $need = true; // unknown, refresh anyway
            } else {
                $left = $expiresAt - $now;
                if ($left <= $minLeftSec) $need = true;
            }

            if (!$need) {
                $countSkip++;
                continue;
            }

            [$ok, $raw, $http, $j] = $this->http_post_form(
                'https://passportapi.115.com/open/refreshToken',
                ['refresh_token' => $refresh],
                25
            );
            if (!$ok) {
                $log("acc#{$id} {$name}: refresh failed: {$raw}");
                continue;
            }
            if (!is_array($j) || !isset($j['data']['access_token'])) {
                $msg = is_array($j) ? (string)($j['message'] ?? $j['error'] ?? '') : '';
                $log("acc#{$id} {$name}: refresh bad response" . ($msg ? (':'.$msg) : '') . " http={$http}");
                continue;
            }

            $data = $j['data'];
            $expiresIn = (int)($data['expires_in'] ?? 0);
            $newExpiresAt = $expiresIn > 0 ? (time() + $expiresIn) : 0;

            $upd = [
                'access_token' => (string)$data['access_token'],
                'expires_in' => $expiresIn,
                'expires_at' => $newExpiresAt,
            ];
            if (isset($data['refresh_token']) && (string)$data['refresh_token'] !== '') {
                $upd['refresh_token'] = (string)$data['refresh_token'];
            }
            try {
                $m->where('id', $id)->update($upd);
                // keep legacy single-account keys in sync (for backward compatibility)
                $this->cfg_upsert('b2_access_token', (string)$data['access_token']);
                if (isset($data['refresh_token']) && (string)$data['refresh_token'] !== '') {
                    $this->cfg_upsert('b2_refresh_token', (string)$data['refresh_token']);
                }
                if ($expiresIn > 0) {
                    $this->cfg_upsert('b2_expires_in', (string)$expiresIn);
                    $this->cfg_upsert('b2_expires_at', (string)$newExpiresAt);
                }

                $countRefreshed++;
                $log("acc#{$id} {$name}: refreshed http={$http} expires_at=" . ($newExpiresAt ? date('Y-m-d H:i:s', $newExpiresAt) : '-'));
            } catch (\Throwable $e) {
                $log("acc#{$id} {$name}: db update failed: " . $e->getMessage());
            }
        }

        $log("done: accounts={$countAll} refreshed={$countRefreshed} skipped={$countSkip}");
        return 0;
    }
}
