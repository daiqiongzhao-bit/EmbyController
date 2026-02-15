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
        $task['error'] = '';
        @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));

        $srcDir = (string)($task['srcDir'] ?? '');
        $outDir = (string)($task['outDir'] ?? '');
        $baseUrl = (string)($task['baseUrl'] ?? '');
        $exts = (string)($task['exts'] ?? 'mkv,mp4,avi,mov,m4v');
        $overwrite = (bool)($task['overwrite'] ?? false);

        $writeLog('taskId=' . $taskId);
        $writeLog('srcDir=' . $srcDir);
        $writeLog('outDir=' . $outDir);
        $writeLog('baseUrl=' . ($baseUrl !== '' ? $baseUrl : '(auto)'));
        $writeLog('exts=' . $exts);
        $writeLog('overwrite=' . ($overwrite ? '1' : '0'));

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

            $outDirPath = dirname($outPath);
            if (!is_dir($outDirPath)) {
                @mkdir($outDirPath, 0755, true);
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
                $writeLog('progress: countStrm=' . $task['countStrm'] . ' countSkip=' . $task['countSkip']);
            }
        }

        if (($task['status'] ?? '') === 'running') {
            $task['status'] = 'success';
        }

        $task['finishedAt'] = date('Y-m-d H:i:s');
        $task['costMs'] = (int)round((microtime(true) - $t0) * 1000);
        @file_put_contents($taskPath, json_encode($task, JSON_UNESCAPED_UNICODE));
        $writeLog('done: status=' . $task['status'] . ' countStrm=' . $task['countStrm'] . ' countSkip=' . $task['countSkip'] . ' costMs=' . $task['costMs']);

        return 0;
    }
}
