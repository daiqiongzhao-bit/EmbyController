<?php

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;

class StrmTask extends Command
{
    protected function configure()
    {
        $this->setName('strm:run')
            ->addArgument('taskId')
            ->setDescription('Run STRM generation task in background');
    }

    public function execute(Input $input, Output $output)
    {
        $taskId = (string)$input->getArgument('taskId');
        if ($taskId === '') {
            $output->writeln('missing taskId');
            return 1;
        }

        $taskDir = runtime_path() . 'strm/tasks/';
        $logDir = runtime_path() . 'strm/logs/';
        if (!is_dir($taskDir)) { @mkdir($taskDir, 0755, true); }
        if (!is_dir($logDir)) { @mkdir($logDir, 0755, true); }

        $taskPath = $taskDir . $taskId . '.json';
        if (!is_file($taskPath)) {
            $output->writeln('task not found: ' . $taskPath);
            return 2;
        }

        $task = json_decode((string)file_get_contents($taskPath), true) ?: [];

        $logFile = $task['logFile'] ?? ('strm_task_' . $taskId . '.log');
        $logPath = $logDir . basename($logFile);

        $writeLog = function ($line) use ($logPath) {
            @file_put_contents($logPath, '[' . date('Y-m-d H:i:s') . '] ' . $line . PHP_EOL, FILE_APPEND);
        };

        $stopFile = $taskDir . $taskId . '.stop';

        $task['status'] = 'running';
        $task['startedAt'] = date('Y-m-d H:i:s');
        $task['countStrm'] = 0;
        $task['countSkip'] = 0;
        $task['countDel'] = 0;
        $task['error'] = '';
        @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));

        $srcDir = (string)($task['srcDir'] ?? '');
        $outDir = (string)($task['outDir'] ?? '');
        $baseUrl = (string)($task['baseUrl'] ?? '');
        $exts = (string)($task['exts'] ?? 'mkv,mp4,avi,mov,m4v');
        $overwrite = (bool)($task['overwrite'] ?? false);
        $incremental = array_key_exists('incremental', $task) ? (bool)$task['incremental'] : true;
        $syncDelete = array_key_exists('syncDelete', $task) ? (bool)$task['syncDelete'] : false;

        $writeLog('taskId=' . $taskId);
        $writeLog('srcDir=' . $srcDir);
        $writeLog('outDir=' . $outDir);
        $writeLog('baseUrl=' . ($baseUrl !== '' ? $baseUrl : '(auto)'));
        $writeLog('exts=' . $exts);
        $writeLog('overwrite=' . ($overwrite ? '1' : '0'));
        $writeLog('incremental=' . ($incremental ? '1' : '0'));
        $writeLog('syncDelete=' . ($syncDelete ? '1' : '0'));

        $t0 = microtime(true);

        $srcRootReal = realpath($srcDir);
        if ($srcRootReal === false) {
            $task['status'] = 'failed';
            $task['finishedAt'] = date('Y-m-d H:i:s');
            $task['error'] = '无法解析源目录';
            @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));
            $writeLog('ERROR: 无法解析源目录');
            return 3;
        }

        // determine base url
        $base = $baseUrl !== '' ? rtrim($baseUrl, '/') : '';
        $playPrefix = ($base !== '' ? $base : '') . '/media/strm/play?path=';

        // if baseUrl empty, try infer from env if provided (optional)
        if ($base === '') {
            // leave as relative; Emby may not like it, but admin should fill baseUrl for background tasks.
            $playPrefix = '/media/strm/play?path=';
        }

        $extList = [];
        foreach (explode(',', $exts) as $e) {
            $e = strtolower(trim($e));
            if ($e !== '') $extList[] = $e;
        }
        if (!$extList) $extList = ['mkv','mp4','avi','mov','m4v'];

        $expected = [];

        $rii = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($srcRootReal, \FilesystemIterator::SKIP_DOTS));
        $i = 0;
        foreach ($rii as $file) {
            if (is_file($stopFile)) {
                $writeLog('STOP: stop file detected');
                $task['status'] = 'stopped';
                break;
            }

            /** @var \SplFileInfo $file */
            if (!$file->isFile()) continue;
            $ext = strtolower($file->getExtension());
            if (!in_array($ext, $extList, true)) continue;

            $absPath = $file->getRealPath();
            if (!$absPath) continue;

            $rel = ltrim(str_replace($srcRootReal, '', $absPath), DIRECTORY_SEPARATOR);
            $outPath = rtrim($outDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . preg_replace('/\.[^.]+$/', '', $rel) . '.strm';

            $expected[$outPath] = 1;

            $outDirPath = dirname($outPath);
            if (!is_dir($outDirPath)) {
                @mkdir($outDirPath, 0755, true);
            }

            // incremental: if file exists and content matches expected URL, skip
            if ($incremental && is_file($outPath)) {
                $cur = @file_get_contents($outPath);
                $exp = $playPrefix . rawurlencode($absPath);
                if ($cur !== false && trim($cur) === $exp) {
                    $task['countSkip']++;
                    continue;
                }
            }

            if (is_file($outPath) && !$overwrite) {
                $task['countSkip']++;
            } else {
                $url = $playPrefix . rawurlencode($absPath);
                if (@file_put_contents($outPath, $url) === false) {
                    $task['countSkip']++;
                } else {
                    $task['countStrm']++;
                }
            }

            $i++;
            if (($i % 200) === 0) {
                @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));
                $writeLog('progress: countStrm=' . $task['countStrm'] . ' countSkip=' . $task['countSkip'] . ' countDel=' . ($task['countDel'] ?? 0));
            }
        }

        // sync delete output extra strm
        if ($syncDelete) {
            $outRootReal = realpath($outDir);
            if ($outRootReal !== false) {
                $rii2 = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($outRootReal, \FilesystemIterator::SKIP_DOTS));
                foreach ($rii2 as $f2) {
                    if (is_file($stopFile)) {
                        $writeLog('STOP: stop file detected during delete');
                        $task['status'] = 'stopped';
                        break;
                    }
                    if (!$f2->isFile()) continue;
                    if (strtolower($f2->getExtension()) !== 'strm') continue;
                    $p2 = $f2->getRealPath();
                    if (!$p2) continue;
                    if (isset($expected[$p2])) continue;
                    $cur = @file_get_contents($p2);
                    if ($cur === false) continue;
                    if (strpos($cur, '/media/strm/play?path=') === false) continue;
                    @unlink($p2);
                    $task['countDel']++;
                }
            }
        }

        if (($task['status'] ?? '') === 'running') {
            $task['status'] = 'success';
        }

        $task['finishedAt'] = date('Y-m-d H:i:s');
        $task['costMs'] = (int)round((microtime(true) - $t0) * 1000);
        @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));
        $writeLog('done: status=' . $task['status'] . ' countStrm=' . $task['countStrm'] . ' countSkip=' . $task['countSkip'] . ' countDel=' . ($task['countDel'] ?? 0) . ' costMs=' . $task['costMs']);

        

        // auto run next queued
        try {
            $dir = runtime_path() . 'strm/tasks/';
            $files = glob($dir . 'task_*.json');
            rsort($files);
            $next = null;
            foreach ($files as $f) {
                $j = json_decode((string)file_get_contents($f), true) ?: [];
                if (($j['status'] ?? '') === 'queued') { $next = $j['id']; break; }
            }
            if ($next) {
                $cmd = 'cd ' . escapeshellarg((string)root_path()) . ' && ' . PHP_BINARY . ' think strm:run ' . escapeshellarg($next) . ' > /dev/null 2>&1 & echo $!';
                $pid = (int)trim((string)shell_exec($cmd));
                if ($pid > 0) {
                    $path = $dir . $next . '.json';
                    $t = json_decode((string)file_get_contents($path), true) ?: [];
                    $t['pid'] = $pid;
                    $t['status'] = 'running';
                    $t['startedAt'] = date('Y-m-d H:i:s');
                    file_put_contents($path, json_encode($t, JSON_UNESCAPED_UNICODE));
                    $writeLog('auto-run next queued task: ' . $next . ' pid=' . $pid);
                }
            }
        } catch (\Throwable $e) {
        }
return 0;
    }
}
