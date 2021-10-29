<?php
/**
 * Created by PhpStorm.
 * User: SuHuayao
 * Date: 2019/10/31
 * Time: 15:35
 */

namespace MediaServer\Utils;


define("IS_WIN", DIRECTORY_SEPARATOR === '\\');

use Exception;

trait ProcessTrait
{


    protected static $master_pid = 0;

    protected static $master_pid_file = __DIR__ . '/pid';

    protected $is_daemon = false;

    public function setMasterPidFile($file){
        self::$master_pid_file=$file;
    }

    /**
     * @throws Exception
     */
    public function daemon()
    {
        if (!$this->is_daemon || IS_WIN) {
            return;
        }
        \umask(0);
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception('fork fail');
        } elseif ($pid > 0) {
            exit(0);
        }
        if (-1 === \posix_setsid()) {
            throw new Exception("setsid fail");
        }
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = \pcntl_fork();
        if (-1 === $pid) {
            throw new Exception("fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }
    }


    protected $processes = [];

    protected $maxFork = 14;


    /**
     * @param $action \Closure
     * @param array $params
     * @param bool $autoRestart
     */
    public function fork($action, $params = [], $autoRestart = false)
    {
        $this->processes[] = [
            "action" => $action,
            "params" => $params,
            "autoRestart" => $autoRestart
        ];
    }

    protected $runningProcess = [];

    public function waitProcessRun()
    {
        while (count($this->runningProcess) > 0) {
            $mypid = pcntl_waitpid(-1, $status, WNOHANG);
            foreach ($this->runningProcess as $pid => $p ) {
                if ($mypid == $pid || $mypid == -1) {
                    echo "Process $pid exited.\n";
                    unset($this->runningProcess[$pid]);
                    if($p['autoRestart']){
                        $this->processes[] = $p;
                    }
                    //判断是否还有未fork进程
                    $this->runOne();
                }
            }
        }
    }

    public function runOne()
    {
        $process = array_shift($this->processes);
        if ($process) {
            $pid = pcntl_fork();
            if ($pid == -1) {
                die("could not fork");
            } elseif ($pid) {
                $this->runningProcess[$pid] = $process;
                echo "Create process: $pid \n";
            } else {
                //执行子进程
                call_user_func_array($process['action'], $process['params']);
                exit;// 一定要注意退出子进程,否则pcntl_fork() 会被子进程再fork,带来处理上的影响。
            }
        }
    }

    public function runProcess()
    {
        if (empty($this->processes)) {
            return;
        }

        for ($i = 0; $i < $this->maxFork; $i++) {
            $this->runOne();
        }

        $this->waitProcessRun();

    }

    /**
     * Set process name.
     *
     * @param string $title
     * @return void
     */
    public function setProcessTitle($title)
    {
        \set_error_handler(function(){});
        // >=php 5.5
        if (\function_exists('cli_set_process_title')) {
            \cli_set_process_title($title);
        } // Need proctitle when php<=5.5 .
        elseif (\extension_loaded('proctitle') && \function_exists('setproctitle')) {
            \setproctitle($title);
        }
        \restore_error_handler();
    }


    /**
     * Bind signal handler.
     *
     * @param $handler callable
     * @return void
     */
    public function bindSignal($handler)
    {
        if (IS_WIN) {
            return;
        }
        // interrupt 程序终止信号 ctrl+c
        \pcntl_signal(SIGINT, $handler, false);
        // terminate 程序结束，可被阻断 kill 的默认信号
        \pcntl_signal(SIGTERM, $handler, false);
        // status
        \pcntl_signal(SIGUSR2, $handler, false);
        // ignore
        \pcntl_signal(SIGPIPE, SIG_IGN, false);
    }


    public function unbindSignal(){
        if (IS_WIN) {
            return;
        }
        // interrupt 程序终止信号 ctrl+c
        \pcntl_signal(SIGINT, SIG_IGN, false);
        // terminate 程序结束，可被阻断 kill 的默认信号
        \pcntl_signal(SIGTERM, SIG_IGN, false);
        // status
        \pcntl_signal(SIGUSR2, SIG_IGN, false);
    }

}
