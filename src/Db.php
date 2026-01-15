<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2021 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace start;

use think\Log;
use think\Cache;
use think\Event;
use think\Config;
use start\db\DbManager;

/**
 * 数据库管理类
 * @package think
 * @property Config $config
 */
class Db extends DbManager
{
    /**
     * @param Event  $event
     * @param Config $config
     * @param Log    $log
     * @param Cache  $cache
     * @return Db
     * @codeCoverageIgnore
     */
    public static function __make(Event $event, Config $config, Log $log, Cache $cache)
    {
        $db = new static();
        $db->setConfig($config);
        $db->setEvent($event);
        $db->setLog($log);

        $store = $db->getConfig('cache_store');
        $db->setCache($cache->store($store));
        $db->triggerSql();

        return $db;
    }

    /**
     * 注入模型对象
     * @access public
     * @return void
     */
    protected function modelMaker()
    {
        Model::setDb($this);

        if (is_object($this->event)) {
            Model::setEvent($this->event);
        }

        Model::maker(function (Model $model) {
            $isAutoWriteTimestamp = $model->getAutoWriteTimestamp();

            if (is_null($isAutoWriteTimestamp)) {
                // 自动写入时间戳
                $model->isAutoWriteTimestamp($this->getConfig('auto_timestamp', true));
            }

            $dateFormat = $model->getDateFormat();

            if (is_null($dateFormat)) {
                // 设置时间戳格式
                $model->setDateFormat($this->getConfig('datetime_format', 'Y-m-d H:i:s'));
            }
        });
    }

    /**
     * 设置配置对象
     * @access public
     * @param Config $config 配置对象
     * @return void
     */
    public function setConfig($config): void
    {
        $this->config = $config;
    }

    /**
     * 获取配置参数
     * @access public
     * @param string $name    配置参数
     * @param mixed  $default 默认值
     * @return mixed
     */
    public function getConfig(string $name = '', $default = null)
    {
        if ('' !== $name) {
            return $this->config->get('database.' . $name, $default);
        }

        return $this->config->get('database', []);
    }

    /**
     * 设置Event对象
     * @param Event $event
     */
    public function setEvent(Event $event): void
    {
        $this->event = $event;
    }

    /**
     * 注册回调方法
     * @access public
     * @param string   $event    事件名
     * @param callable $callback 回调方法
     * @return void
     */
    public function event(string $event, callable $callback): void
    {
        if ($this->event) {
            $this->event->listen('db.' . $event, $callback);
        }
    }

    /**
     * 触发事件
     * @access public
     * @param string $event  事件名
     * @param mixed  $params 传入参数
     * @param bool   $once
     * @return mixed
     */
    public function trigger(string $event, $params = null, bool $once = false)
    {
        if ($this->event) {
            return $this->event->trigger('db.' . $event, $params, $once);
        }
    }
}
