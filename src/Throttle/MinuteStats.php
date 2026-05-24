<?php

namespace Throttle;

class MinuteStats
{
    const PREFIX = 'throttle:stats:minute:';
    const RETENTION = 604800;

    public static function increment($redis, $metric, $value = 1, $timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $bucket = (string)(floor($timestamp / 60) * 60);
        $redis->hIncrBy(self::PREFIX . $metric, $bucket, (int)$value);
    }

    public static function cleanup($redis, array $metrics, $timestamp = null)
    {
        if ($timestamp === null) {
            $timestamp = time();
        }

        $cutoff = floor(($timestamp - self::RETENTION) / 60) * 60;

        foreach ($metrics as $metric) {
            $key = self::PREFIX . $metric;
            $data = $redis->hGetAll($key);
            $old = array();

            foreach ($data as $bucket => $value) {
                if ((int)$bucket < $cutoff) {
                    $old[] = $bucket;
                }
            }

            if (!empty($old)) {
                call_user_func_array(array($redis, 'hDel'), array_merge(array($key), $old));
            }
        }
    }
}
