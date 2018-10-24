<?php

namespace app\log\service;

use app\common\helpers\Common;
use Mll\Common\Amqp;
use Mll\Cache;
use Mll\Db\Mongo;
use Mll\Mll;
use MongoDB\BSON\UTCDateTime;

class LogService
{
    /**
     * 从文件取出日志并存储
     *
     * @param int $num 日志条数
     * @return array|bool
     */
    public static function pullLogByFile($num = 1000)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(240);
        $num = min(5000, $num);
        $path = Mll::app()->config->params('service_log_path') . '/' . date('Ym') . '/' . date('d') . '.log';
        $logs = [];
        if (file_exists($path)) {
            $logs = self::readFile($path, $num);
        }
        //分析日志并存储
        if (!empty($logs)) {
            $logArr = [];
            foreach ($logs as $log) {
                $log = json_decode($log, true);
                if (!empty($log)) {
                    $orig_date = new \DateTime($log['time']);
                    $log['date'] = new UTCDateTime(($orig_date->getTimestamp() + 8 * 3600) * 1000);
                    $logArr[] = $log;
                }
            }
            unset($logs);
            $mongo = new Mongo();
            $mongo->selectCollection('log')->batchInsert($logArr);
        }
        return true;
    }

    /**
     * 从MQ取出日志并存储
     *
     * @param int $num 日志条数
     * @return array|bool
     */
    public static function pullLogByMq($num = 10000)
    {
        ini_set('memory_limit', '512M');
        set_time_limit(240);
        $num = min(50000, $num);
        static $mq;
        $mongoConfig = Mll::app()->config->get('db.mongo');
        if ($mq === null) {
            $mq = new Amqp(Mll::app()->config->get('mq.rabbit'));
            //判断是否能正常链接
            new Mongo();
        }
        while ($num--) {
            $msg = $mq->getMessage('QUEUE_PHP_LOG');
            if (empty($msg)) {
                break;
            }
            $logs[] = $msg;
        }
        //分析日志并存储
        $insertNum = $updateNum = $cacheUpdateNum = 0;
        if (!empty($logs)) {
            $logArr = self::countLogByHour($logs);
            unset($logs);
            foreach ($logArr['log'] as $date => $_log) {
                $db = 'system_log_' . $date;
                $mongo = new Mongo();
                $insertNum += $mongo->setDBName($db)->selectCollection('log')->batchInsert($_log);
                if ($insertNum == 0) {
                    Mll::app()->log->warning(
                        date('Y-m-d H:i:s') . 'mongo 插入失败' . count($_log) . '条！'
                    );
                }
            }

            if (!empty($logArr['countHour'])) {
                foreach ($logArr['countHour'] as $date => $_log) {
                    $db = 'system_log';
                    $mongo = new Mongo();
                    $mongo->setDBName($db)->selectCollection('log_count_hour');
                    foreach ($_log as $k => $v) {
                        $where = explode('#', $k);
                        $updateNum += $mongo->update(
                            ['date' => $where[0], 'hour' => $where[1], 'project' => $where[2], 'server' => $where[3], 'type' => $where[4]],
                            [
                                '$inc' => $v['inc'],
                                '$set' => $v['set']
                            ],
                            ['upsert' => true]
                        );
                    }
                }
            }

            if (!empty($logArr['countCache'])) {
                foreach ($logArr['countCache'] as $date => $_log) {
                    $db = 'system_log_' . $date;
                    $mongo = new Mongo();
                    $mongo->setDBName($db)->selectCollection('log_count_cache');
                    foreach ($_log as $k => $v) {
                        $where = explode('#', $k);
                        $cacheUpdateNum += $mongo->update(
                            ['date' => $where[0], 'key' => $where[1], 'host' => $where[2]],
                            [
                                '$inc' => $v['inc'],
                                '$set' => $v['set']
                            ],
                            ['upsert' => true]
                        );
                    }
                }
            }
        }
        Mll::app()->log->debug('inc:' . $insertNum . ', update:' . $updateNum. ', cache_update:' . $cacheUpdateNum);
        return 'inc:' . $insertNum . ', update:' . $updateNum . ', cache_update:' . $cacheUpdateNum;
    }

    public static function countLogByHour($logs)
    {
        $logArr = $countHourArr = $countHour = $countCache = [];
        foreach ($logs as $log) {
            $log = json_decode($log, true);
            if (!empty($log)) {
                if (!isset($log['content']['url'])) {
                    $log['content']['url'] = '?';
                }
                $log['content']['url'] = strlen($log['content']['url']) > 1000 ?
                    substr($log['content']['url'], 0, 1000) : $log['content']['url'];
                $log['createTime'] = time();

                $log['content']['isAjax'] = isset($log['content']['server']['HTTP_X_REQUESTED_WITH'])
                && $log['content']['server']['HTTP_X_REQUESTED_WITH'] === 'XMLHttpRequest' ? 1 : 0;
                $log['content']['requestSource'] = isset($log['content']['server']['HTTP_USER_AGENT'])
                    ? self::checkRequestSource($log['content']['server']['HTTP_USER_AGENT']) : 'other';
                $date = date('Y-m-d', intval($log['microtime']));
                $hour = date('H', intval($log['microtime']));

                if ($log['type'] == 'REQUEST' && $log['content']['isAjax'] == 0
                    && $log['content']['requestSource'] == 'pc'
                ) {
                    //Mll::app()->log->debug('用户请求！', $log);
                    $log['type'] = 'USER';
                }
                if (empty($log['server'])) {
                    $log['server'] = 'unknown';
                }
                //判断是否为50服务器
                $server = isset($log['server']) && $log['server'] == 'server50' ? 'server50' : 'other';
                $key = "{$date}#{$hour}#{$log['project']}#{$server}#{$log['type']}";

                $countHour[$key]['set'] = [
                    'date' => $date,
                    'hour' => $hour,
                    'server' => $server,
                    'project' => $log['project'],
                    'type' => $log['type']
                ];

                if (!isset($countHour[$key]['inc'])) {
                    $countHour[$key]['inc'] = [
                        'count' => 0,
                        'execTime' => 0,
                    ];
                }

                $countHour[$key]['inc']['count'] += 1;
                $countHour[$key]['inc']['execTime'] += $log['content']['execTime'];
                $countHour[$key]['inc']['requestSource_' . $log['content']['requestSource']] =
                    isset($countHour[$key]['inc']['requestSource_' . $log['content']['requestSource']])
                        ? $countHour[$key]['inc']['requestSource_' . $log['content']['requestSource']] + 1 : 1;
                $countHour[$key]['inc']['level_' . $log['level']] =
                    isset($countHour[$key]['inc']['level_' . $log['level']]) ? $countHour[$key]['inc']['level_' . $log['level']] + 1 : 1;
                if (isset($log['content']['responseCode'])) {
                    if (is_numeric($log['content']['responseCode']) && $log['content']['responseCode'] == 0) {
                        $countHour[$key]['inc']['httpCode_0'] =
                            isset($countHour[$key]['inc']['httpCode_0']) ? $countHour[$key]['inc']['httpCode_0'] + 1 : 1;
                    } elseif ($log['content']['responseCode'] >= 200 && $log['content']['responseCode'] < 300) {
                        $countHour[$key]['inc']['httpCode_200'] =
                            isset($countHour[$key]['inc']['httpCode_200']) ? $countHour[$key]['inc']['httpCode_200'] + 1 : 1;
                    } elseif ($log['content']['responseCode'] >= 300 && $log['content']['responseCode'] < 400) {
                        $countHour[$key]['inc']['httpCode_300'] =
                            isset($countHour[$key]['inc']['httpCode_300']) ? $countHour[$key]['inc']['httpCode_300'] + 1 : 1;
                    } elseif ($log['content']['responseCode'] >= 400 && $log['content']['responseCode'] < 500) {
                        $countHour[$key]['inc']['httpCode_400'] =
                            isset($countHour[$key]['inc']['httpCode_400']) ? $countHour[$key]['inc']['httpCode_400'] + 1 : 1;
                    } elseif ($log['content']['responseCode'] >= 500 && $log['content']['responseCode'] < 600) {
                        $countHour[$key]['inc']['httpCode_500'] =
                            isset($countHour[$key]['inc']['httpCode_500']) ? $countHour[$key]['inc']['httpCode_500'] + 1 : 1;
                    }
                }

                if (isset($log['content']['execTime'])) {
                    if ($log['content']['execTime'] <= 0.2) {
                        $countHour[$key]['inc']['execTime_200'] =
                            isset($countHour[$key]['inc']['execTime_200']) ? $countHour[$key]['inc']['execTime_200'] + 1 : 1;
                    } elseif ($log['content']['execTime'] > 0.2 && $log['content']['execTime'] <= 0.5) {
                        $countHour[$key]['inc']['execTime_500'] =
                            isset($countHour[$key]['inc']['execTime_500']) ? $countHour[$key]['inc']['execTime_500'] + 1 : 1;
                    } elseif ($log['content']['execTime'] > 0.5 && $log['content']['execTime'] <= 1) {
                        $countHour[$key]['inc']['execTime_1000'] =
                            isset($countHour[$key]['inc']['execTime_1000']) ? $countHour[$key]['inc']['execTime_1000'] + 1 : 1;
                    } elseif ($log['content']['execTime'] > 1 && $log['content']['execTime'] <= 5) {
                        $countHour[$key]['inc']['execTime_5000'] =
                            isset($countHour[$key]['inc']['execTime_5000']) ? $countHour[$key]['inc']['execTime_5000'] + 1 : 1;;
                    } else {
                        $countHour[$key]['inc']['execTime_5000+'] =
                            isset($countHour[$key]['inc']['execTime_5000+']) ? $countHour[$key]['inc']['execTime_5000+'] + 1 : 1;
                    }
                }

                if ($log['type'] == 'REMARK' && (!empty($log['content']['cache']) || !empty($log['content']['cache_get']))) {
                    self::countCacheLog($log, $countCache);
                }

                $db_key = date('m_d', intval($log['microtime']));
                $countHourArr[$db_key] = $countHour;
                $logArr[$db_key][] = $log;
                $countCacheArr[$db_key] = $countCache;
            }
        }
        return ['log' => $logArr, 'countHour' => $countHourArr, 'countCache' => $countCacheArr];
    }

    public static function countCacheLog($log, &$countCache) {
        $date = date('Y-m-d', intval($log['microtime']));
        if (!empty($log['content']['cache'])) {
            foreach ($log['content']['cache'] as $cache) {
                $cache_key = "{$date}#{$cache['key']}#{$cache['host']}";
                $countCache[$cache_key]['set'] = [
                    'date' => $date,
                    'key' => $cache['key'],
                    'host' => $cache['host'],
                    'request_uri' => $log['content']['request_uri'],
                    'php' => $log['content']['php'],
                    'length' => $cache['length'],
                    'expire' => $cache['expire']
                ];

                if (!isset($countCache[$cache_key]['inc'])) {
                    $countCache[$cache_key]['inc'] = [
                        'count' => 0,
                        'get' => 0,
                        'set' => 0,
                        'set_fail' => 0,
                        'get_fail' => 0
                    ];
                }
                $countCache[$cache_key]['inc']['count'] += 1;
                $countCache[$cache_key]['inc']['set'] += 1;
                if (isset($cache['rs']) && !$cache['rs']) {
                    $countCache[$cache_key]['inc']['set_fail'] += 1;
                }
            }
        }

        if (!empty($log['content']['cache_get'])) {
            foreach ($log['content']['cache_get'] as $cache) {
                $cache_key = "{$date}#{$cache['key']}#{$cache['host']}";
                if (!isset($countCache[$cache_key]['set'])) {
                    $countCache[$cache_key]['set'] = [
                        'date' => $date,
                        'key' => $cache['key'],
                        'host' => $cache['host'],
                        'request_uri' => $log['content']['request_uri'],
                        'php' => $log['content']['php'],
                        'length' => $cache['length']
                    ];
                }

                if (!isset($countCache[$cache_key]['inc'])) {
                    $countCache[$cache_key]['inc'] = [
                        'count' => 0,
                        'get' => 0,
                        'set' => 0,
                        'set_fail' => 0,
                        'get_fail' => 0
                    ];
                }
                $countCache[$cache_key]['inc']['count'] += 1;
                $countCache[$cache_key]['inc']['get'] += 1;
                if (isset($cache['rs']) && !$cache['rs']) {
                    $countCache[$cache_key]['inc']['get_fail'] += 1;
                }
            }
        }
    }

    /**
     * 判断当天DB，创建索引和删除几天前的日志DB
     *
     */
    public static function checkCurrDb()
    {
        Cache::cut('file');
        $cacheKey = 'check_curr_db_' . date('d');
        $is_checked = Cache::get($cacheKey);
        if ($is_checked === false) {
            $mongo = new Mongo();
            $mongo->setDBName('system_log_' . date('m_d'));
            $mongo->executeCommand([
                'createIndexes' => 'log',
                'indexes' => [
                    [
                        'key' => ['time' => -1, 'type' => 1, 'level' => 1, 'project' => 1],
                        'name' => 'time_-1_type_1_level_1_project_1'
                    ],
                    [
                        'key' => ['requestId' => 1],
                        'name' => 'requestId_1'
                    ],
                    [
                        'key' => ['content.responseCode' => -1],
                        'name' => 'content.responseCode_1'
                    ],
                    [
                        'key' => ['content.url' => 1],
                        'name' => 'content.url_1'
                    ],
                ]
            ]);
            /*$mongo->setDBName('system_log');
            $mongo->executeCommand([
                'createIndexes' => 'log_count_hour',
                'indexes' => [
                    [
                        'key' => ['date' => -1, 'project' => 1, 'type' => 1],
                        'name' => 'date_-1_project_1_type_1'
                    ],
                ]
            ]);*/
            $mongoConfig = Mll::app()->config->get('db.mongo');
            $mongoConfig['database'] = 'admin';
            $mongoConfig['username'] = 'root';
            $mongoConfig['password'] = 'dsj4wKI*FWLsdf4';
            $mongo = new Mongo($mongoConfig);
            $mongo->setDBName('system_log_' . date('m_d', strtotime('-4 day')));
            $mongo->executeCommand(['dropDatabase' => 1]);
            Cache::set($cacheKey, 1, 86400);
        }
    }

    /**
     * 跟踪日志按版本排序
     *
     * @param array $traceLog 跟踪日志
     * @return array|bool
     */
    public static function traceLogVersionSort($traceLog)
    {
        if (empty($traceLog)) {
            return false;
        }
        $traceIds = [];

        foreach ($traceLog as $v) {
            if (isset($v['content']['traceId'])) {
                $traceIds[$v['content']['traceId']] = $v['content']['traceId'];
            }
        }
        $traceIds = array_values($traceIds);
        $version_sort = array_flip(Common::version_sort($traceIds));
        foreach ($traceLog as &$v) {
            if (isset($version_sort[$v['content']['traceId']])) {
                $order[] = $version_sort[$v['content']['traceId']];
            }
        }
        array_multisort($order, SORT_ASC, $traceLog);

        return $traceLog;
    }

    /**
     * 获取日志文件
     *
     * @param $filename
     * @param $line
     * @return array|bool
     */
    public static function readFile($filename, $line)
    {
        if (!$fp = fopen($filename, 'r')) {
            return false;
        }
        $lines = array();
        // 获取文件读取位置
        Cache::cut('file');
        $today = date('d');
        $readLocation = Cache::get('readLocation_' . $today, 0);
        fseek($fp, $readLocation);
        while ($line > 0 && ($buffer = fgets($fp, 40960)) !== false) {
            $lines[] = $buffer;
            $line--;
        }

        // 记录日志文件读取位置
        Cache::set('readLocation_' . $today, ftell($fp), 3600 * 24);
        fclose($fp);
        return $lines;
    }

    /**
     * 判断是否为蜘蛛请求
     *
     * @param $userAgent
     * @return bool
     */
    public static function checkRequestSource($userAgent)
    {
        static $kw_spiders = array('bot', 'crawl', 'spider', 'slurp', 'sohu-search', 'lycos', 'robozilla');
        static $kw_browsers = array('mozilla', 'msie', 'netscape', 'opera', 'konqueror');
        static $kw_mobile = array ('android', 'ios', 'wap');
        $userAgent = strtolower($userAgent);
        //并排除了手机端
        if (strpos($userAgent, 'mobile') !== false || self::dstrpos($userAgent, $kw_mobile)) {
            return 'mobile';
        }
        if (strpos($userAgent, 'http://') === false && self::dstrpos($userAgent, $kw_browsers)) {
            return 'pc';
        }
        if (self::dstrpos($userAgent, $kw_spiders)) {
            return 'robot';
        }
        return 'other';
    }

    public static function dstrpos($string, $arr, $returnValue = false)
    {
        if (empty($string)) return false;
        foreach ((array)$arr as $v) {
            if (strpos($string, $v) !== false) {
                $return = $returnValue ? $v : true;
                return $return;
            }
        }
        return false;
    }
}