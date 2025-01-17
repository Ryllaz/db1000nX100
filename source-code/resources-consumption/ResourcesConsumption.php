<?php

class ResourcesConsumption
{
    const trackCliPhp = 'track.cli.php';

    private static
        $trackCliPhpProcess,
        $trackCliPhpProcessPGid,
        $trackCliPhpPipes,
        $statData,
        $trackingStartedAt,
        $trackingFinishedAt,
        $commonNetworkInterfaceStatsOnStart,
        $commonNetworkInterfaceStatsOnFinish,
        $debug,
        $tasksTimeTracking;

    public static function constructStatic()
    {
        static::$debug = SelfUpdate::isDevelopmentVersion();
    }

    public static function resetAndStartTracking()
    {
        global $COMMON_NETWORK_INTERFACE;

        static::$statData = [];
        static::$trackingStartedAt = hrtime(true);
        static::$trackingFinishedAt = 0;
        //static::$commonNetworkInterfaceStatsOnStart = calculateNetworkTrafficStat($COMMON_NETWORK_INTERFACE);
        //static::$commonNetworkInterfaceStatsOnFinish = null;

        //---

        $command = __DIR__ . '/' . static::trackCliPhp . '  --main_cli_php_pid ' . posix_getpid() . ' --time_interval 10';
        $descriptorSpec = array(
            0 => array("pipe", "r"),  // stdin
            1 => array("pipe", "w"),  // stdout
            2 => array("pipe", "a")   // stderr
        );
        $li = 0;
        do {
            $li++;
            if ($li > 10) {
                MainLog::log('Failed to run Resources Consumption Tracker', 1, 0, MainLog::LOG_GENERAL_ERROR);
            }
            static::$trackCliPhpProcess = proc_open($command, $descriptorSpec, static::$trackCliPhpPipes);
            static::$trackCliPhpProcessPGid = procChangePGid(static::$trackCliPhpProcess, $log);
        } while (!static::$trackCliPhpProcess || !static::$trackCliPhpProcessPGid);
    }

    public static function finishTracking()
    {
        global $COMMON_NETWORK_INTERFACE;
        if (static::$trackCliPhpProcess) {
            $stdOut = streamReadLines(static::$trackCliPhpPipes[1], 0);
            $stdOutLines = mbSplitLines($stdOut);
            foreach ($stdOutLines as $line) {
                //echo "$line\n";
                $lineArr = @json_decode($line, JSON_OBJECT_AS_ARRAY);
                if (is_array($lineArr)) {
                    static::$statData[] = $lineArr;
                }
            }

            $processStatus = proc_get_status(static::$trackCliPhpProcess);
            if ($processStatus['running']) {
                @posix_kill(0 - static::$trackCliPhpProcessPGid, SIGTERM);
            }
        }

        //---

        static::$trackingFinishedAt = hrtime(true);
        //static::$commonNetworkInterfaceStatsOnFinish = calculateNetworkTrafficStat($COMMON_NETWORK_INTERFACE);
        @proc_terminate(static::$trackCliPhpProcess);
    }

    public static function killTrackCliPhp()
    {
        $out = _shell_exec('ps -e -o pid=,cmd=');
        if (preg_match_all('#^\s+(\d+)(.*)$#mu', $out, $matches) > 0) {
            for ($i = 0; $i < count($matches[0]); $i++) {
                $pid = (int)$matches[1][$i];
                $cmd = mbTrim($matches[2][$i]);

                if (strpos($cmd, static::trackCliPhp) !== false) {
                    @posix_kill($pid, SIGTERM);
                    MainLog::log("Killed $cmd", 2, 1, MainLog::LOG_GENERAL_ERROR);
                }
            }
        }
    }

    //------------------------------------------------------------------------------------------------------------

    function getRAMCapacity()
    {
        $memoryStat = static::readMemoryStats();
        return $memoryStat['MemTotal'];
    }

    public static function readMemoryStats()
    {
        $stat = file_get_contents('/proc/meminfo');
        $memoryUsageRegExp = <<<PhpRegExp
                             #^(\w+):\s+(\d+)\s+kB$#m  
                             PhpRegExp;
        if (preg_match_all(trim($memoryUsageRegExp), $stat, $matches) < 1) {
            _die(__METHOD__ . ' failed');
        }

        $memoryUsageKb = array_combine($matches[1], $matches[2]);
        $memoryUsage = array_map(function ($value) {
            return ( (int) $value * 1024 );
        },
            $memoryUsageKb
        );
        $memoryUsage['pageSize'] = (int) _shell_exec('getconf PAGESIZE');
        return $memoryUsage;
    }

    public static function processesCalculatePeakMemoryUsage($stats,  $memoryStat)
    {
        $processesMemPages = array_sum(array_column($stats['process'], 'rss'));
        $processesMemBytes = $processesMemPages * $memoryStat['pageSize'];
        $processesMem      = roundLarge($processesMemBytes * 100 / $memoryStat['MemTotal']);
        return $processesMem;
    }

    public static function getProcessesPeakRAMUsageFromStartToFinish()
    {
        global $OS_RAM_CAPACITY, $MAX_RAM_USAGE;
        if (!count(static::$statData)) {
            return false;
        }

        $memColumn = array_column(static::$statData, 'processesMem');
        $peakMem = max($memColumn);
        if (static::$debug) {
            MainLog::log("processesPeakMem " . round($peakMem) . "% of system $OS_RAM_CAPACITY GiB", 1, 0, MainLog::LOG_DEBUG);
        }

        $peakMemGiB = $peakMem / 100 * $OS_RAM_CAPACITY;
        $ret = $peakMemGiB * 100 / $MAX_RAM_USAGE /* GiB */;
        return intRound($ret);
    }

    public static function getSystemAverageRAMUsageFromStartToFinish()
    {
        if (!count(static::$statData)) {
            return false;
        }
        $memColumn = array_column(static::$statData, 'systemMem');
        $averageMem = array_sum($memColumn) / count($memColumn);
        return intRound($averageMem);
    }

    //-------------------------- Linux CPU usage tracker --------------------------

    function getCPUQuantity()
    {
        $regExp = <<<PhpRegExp
              #CPU\(s\):\s+(\d+)#  
              PhpRegExp;

        $r = shell_exec('lscpu');
        if (preg_match(trim($regExp), $r, $matches) === 1) {
            return (int) $matches[1];
        }
        return $r;
    }

    public static function readCpuStats() : array
    {
        $stat = file_get_contents('/proc/stat');
        $cpuUsageRegExp = '#cpu' . str_repeat('\s+(\d+)', 10) . '#';
        if (preg_match($cpuUsageRegExp, $stat, $matches) !== 1) {
            _die(__METHOD__ . ' failed');
        }

        //https://man7.org/linux/man-pages/man5/proc.5.html
        $i = 0;
        return [
            'user'       => (int) $matches[++$i],
            'nice'       => (int) $matches[++$i],
            'system'     => (int) $matches[++$i],
            'idle'       => (int) $matches[++$i],
            'iowait'     => (int) $matches[++$i],
            'irq'        => (int) $matches[++$i],
            'softirq'    => (int) $matches[++$i],
            'steal'      => (int) $matches[++$i],
            'guest'      => (int) $matches[++$i],
            'guest_nice' => (int) $matches[++$i]
        ];
    }

    public static function cpuStatCalculateAverageCPUUsage($cpuStatsBegin, $cpuStatsEnd)
    {
        $cpuStatsDiff = [];
        foreach (array_keys($cpuStatsBegin) as $key) {
            $cpuStatsDiff[$key] = $cpuStatsEnd[$key] - $cpuStatsBegin[$key];
        }

        // https://rosettacode.org/wiki/Linux_CPU_utilization
        $idle = $cpuStatsDiff['idle'] / array_sum($cpuStatsDiff);
        $busyPercents = (1 - $idle) * 100;
        if ($busyPercents >= 99) {
            return round($busyPercents, 1);
        } else {
            return intRound($busyPercents);
        }
    }

    /**
     * @return false|int
     */
    public static function getSystemAverageCPUUsageFromStartToFinish()
    {
        if (!count(static::$statData)) {
            return false;
        }
        $cpuColumn = array_column(static::$statData, 'systemCpu');
        $averageCpu = array_sum($cpuColumn) / count($cpuColumn);
        return intRound($averageCpu);
    }

    //------------- Linux per process CPU usage tracker --------------

    public static function readProcessStats($pid) : ?array
    {
        $stats = @file_get_contents("/proc/$pid/stat");
        if (!$stats) {
            return null;
        }
        $statsValues = explode(' ', $stats);
        $statsValues = mbRemoveEmptyLinesFromArray($statsValues);

        //https://man7.org/linux/man-pages/man5/proc.5.html
        $statsKeys = [
            'pid',
            'comm',
            'state',
            'ppid',
            'pgrp',
            'session',
            'tty_nr',
            'tpgid',
            'flags',
            'minflt',
            'cminflt',
            'majflt',
            'cmajflt',
            'utime',
            'stime',
            'cutime',
            'cstime',
            'priority',
            'nice',
            'num_threads',
            'itrealvalue',
            'starttime',
            'vsize',
            'rss',
            'rsslim',
            'startcode',
            'endcode',
            'startstack',
            'kstkesp',
            'kstkeip',
            'signal',
            'blocked',
            'sigignore',
            'sigcatch',
            'wchan',
            'nswap',
            'cnswap',
            'exit_signal',
            'processor',
            'rt_priority',
            'policy',
            'delayacct_blkio_ticks',
            'guest_time',
            'cguest_time',
            'start_data',
            'end_data',
            'start_brk',
            'arg_start',
            'arg_end',
            'env_start',
            'env_end',
            'exit_code'
        ];

        return array_combine($statsKeys, $statsValues);
    }

    public static function getProcessesStats($parentPid)
    {
        $pidsList = [];
        getProcessPidWithChildrenPids($parentPid, true, $pidsList);
        foreach ($pidsList as $pid) {
            $command = @file_get_contents("/proc/$pid/cmdline");
            $processStats = static::readProcessStats($pid);
            if ($processStats) {
                $ret['process'][$pid] = $processStats;
                $ret['process'][$pid]['command'] = $command;
            }
        }
        $ret['ticksSinceReboot'] = posix_times()['ticks'];      // getconf CLK_TCK
        return $ret;
    }

    public static function processesCalculateAverageCPUUsage($statsOnStart, $statsOnEnd, $onlyForParticularPid = false)
    {
        //https://www.baeldung.com/linux/total-process-cpu-usage
        $durationTicks = $statsOnEnd['ticksSinceReboot'] - $statsOnStart['ticksSinceReboot'];
        if (is_int($onlyForParticularPid)) {
            $cpuTimeOnStart = $statsOnStart['process'][$onlyForParticularPid]['utime']
                            + $statsOnStart['process'][$onlyForParticularPid]['stime'];

            $cpuTimeOnEnd = $statsOnEnd['process'][$onlyForParticularPid]['utime']
                          + $statsOnEnd['process'][$onlyForParticularPid]['stime'];

            $cpuTimeSum = $cpuTimeOnEnd - $cpuTimeOnStart;
        } else {
            $cpuTimeSum = 0;
            foreach (array_keys($statsOnEnd['process']) as $endPid)
            {
                $cpuTimeOnEnd = $statsOnEnd['process'][$endPid]['utime']
                              + $statsOnEnd['process'][$endPid]['stime'];

                if (!isset($statsOnStart['process'][$endPid])) {
                    // Process was created after $statsOnStart were collected
                    $cpuTimeSum += $cpuTimeOnEnd;
                } else {
                    $cpuTimeOnStart = $statsOnStart['process'][$endPid]['utime']
                                    + $statsOnStart['process'][$endPid]['stime'];

                    $cpuTimeSum += $cpuTimeOnEnd - $cpuTimeOnStart;
                }
            }
        }
        return roundLarge($cpuTimeSum * 100 / $durationTicks);
    }

    public static function getProcessesAverageCPUUsageFromStartToFinish()
    {
        global $MAX_CPU_CORES_USAGE;
        if (!count(static::$statData)) {
            return false;
        }
        $cpuColumn = array_column(static::$statData, 'processesCpu');
        $processesCpuUsage = roundLarge(array_sum($cpuColumn) / count($cpuColumn));

        /*
         * $coresUsed = $processesCpuUsage / 100;
         * $processesCpuUsageFromAllowed = $coresUsed * 100 / $MAX_CPU_CORES_USAGE;
         */
        $processesCpuUsageFromAllowed = intRound($processesCpuUsage / $MAX_CPU_CORES_USAGE);

        if (static::$debug) {
            MainLog::log("processesCpuUsage $processesCpuUsage% of 1 core", 1, 0, MainLog::LOG_DEBUG);
            $mainCliPhpCpuColumn = array_column(static::$statData, 'mainCliPhpCpu');
            $mainCliPhpCpuUsage = roundLarge(array_sum($mainCliPhpCpuColumn) / count($mainCliPhpCpuColumn));
            MainLog::log("mainCliPhpCpu $mainCliPhpCpuUsage% of 1 core", 1, 0, MainLog::LOG_DEBUG);
        }


        return $processesCpuUsageFromAllowed;
    }

    //------------------ functions to track time expanses for particular operation ------------------

    public static function resetTaskTimeTracking()
    {
        static::$tasksTimeTracking = [];
    }

    public static function startTaskTimeTracking($taskName)
    {
        global $SESSIONS_COUNT;
        if (!static::$debug) {
            return;
        }

        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName]  ??  [];
        $lastItem['startedAt'] = hrtime(true);
        $taskData[] = $lastItem;
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
    }

    public static function stopTaskTimeTracking($taskName) : bool
    {
        global $SESSIONS_COUNT;
        if (!static::$debug) {
            return false;
        }

        if (!count(static::$tasksTimeTracking)) {
            return false;
        }
        $taskData = static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName];
        if (!$taskData) {
            return false;
        }
        $lastItemKey = array_key_last($taskData);
        $lastItem = $taskData[$lastItemKey];
        $lastItem['duration']   = hrtime(true) - $lastItem['startedAt'];
        $taskData[$lastItemKey] = $lastItem;
        static::$tasksTimeTracking[$SESSIONS_COUNT][$taskName] = $taskData;
        return true;
    }

    public static function getTasksTimeTrackingResultsBadge($sessionId)
    {
        if (!static::$debug) {
            return;
        }

        //MainLog::log(print_r(static::$tasksTimeTracking, true));
        $tasksData =  static::$tasksTimeTracking[$sessionId];
        $ret = [];
        $sessionDuration = 1;
        foreach ($tasksData as $taskName => $taskData) {
            if ($taskName === 'session') {
                $sessionDuration = $taskData[0]['duration'];
            }

            $durationColumn = array_column($taskData, 'duration');
            $retItem['totalDuration'] = array_sum($durationColumn);
            $retItem['totalDurationSeconds'] = intdiv($retItem['totalDuration'], pow(10, 9));
            $retItem['percent'] = round($retItem['totalDuration'] * 100 / $sessionDuration);

            $retItem['count'] = count($durationColumn);
            $ret[$taskName] = $retItem;
        }
        MainLog::log("Debug:\n" . print_r($ret, true), 1, 0, MainLog::LOG_DEBUG);
    }

    //------------------------------------------------------------------------------------

    public static function getProcessesAverageNetworkUsageFromStartToFinish()
    {
        global $NETWORK_BANDWIDTH_LIMIT;

        $totalReceived    = static::$commonNetworkInterfaceStatsOnFinish->received    - static::$commonNetworkInterfaceStatsOnStart->received;
        $totalTransmitted = static::$commonNetworkInterfaceStatsOnFinish->transmitted - static::$commonNetworkInterfaceStatsOnStart->transmitted;
        $duration         = intdiv(static::$trackingFinishedAt - static::$trackingStartedAt, pow(10, 9));
        $averageBitsPerSecond = intdiv($totalReceived + $totalTransmitted, $duration) * 8;
        MainLog::log("averageMebibitsBitsPerSecond " . round($averageBitsPerSecond / 1024 / 1024), 1, 0, MainLog::LOG_DEBUG);

        $bitsLimit        = $NETWORK_BANDWIDTH_LIMIT * 1024 * 1024;
        $ret = $averageBitsPerSecond * 100 / $bitsLimit;
        return intRound($ret);
    }

}

ResourcesConsumption::constructStatic();