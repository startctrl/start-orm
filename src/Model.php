<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2019 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------
declare (strict_types = 1);

namespace start;

use ArrayAccess;
use Closure;
use JsonSerializable;
use start\contract\Arrayable;
use start\contract\Jsonable;
use start\db\BaseQuery as Query;

/**
 * Class Model
 * @package think
 * @mixin Query
 * @method void onAfterRead(Model $model) static after_read事件定义
 * @method mixed onBeforeInsert(Model $model) static before_insert事件定义
 * @method void onAfterInsert(Model $model) static after_insert事件定义
 * @method mixed onBeforeUpdate(Model $model) static before_update事件定义
 * @method void onAfterUpdate(Model $model) static after_update事件定义
 * @method mixed onBeforeWrite(Model $model) static before_write事件定义
 * @method void onAfterWrite(Model $model) static after_write事件定义
 * @method mixed onBeforeDelete(Model $model) static before_write事件定义
 * @method void onAfterDelete(Model $model) static after_delete事件定义
 * @method void onBeforeRestore(Model $model) static before_restore事件定义
 * @method void onAfterRestore(Model $model) static after_restore事件定义
 */
abstract class Model implements JsonSerializable, ArrayAccess, Arrayable, Jsonable
{
    use model\concern\Attribute;
    use model\concern\RelationShip;
    use model\concern\ModelEvent;
    use model\concern\TimeStamp;
    use model\concern\Conversion;
    use model\concern\SoftDelete;

    /**
     * 数据是否存在
     * @var bool
     */
    private $exists = false;

    /**
     * 是否强制更新所有数据
     * @var bool
     */
    private $force = false;

    /**
     * 是否Replace
     * @var bool
     */
    private $replace = false;

    /**
     * 数据表后缀
     * @var string
     */
    protected $suffix;

    /**
     * 更新条件
     * @var array
     */
    private $updateWhere;

    /**
     * 数据库配置
     * @var string
     */
    protected $connection;

    /**
     * 模型名称
     * @var string
     */
    protected $name;

    /**
     * 主键值
     * @var string
     */
    protected $key;

    /**
     * 数据表名称
     * @var string
     */
    protected $table;

    /**
     * 初始化过的模型.
     * @var array
     */
    protected static $initialized = [];

    /**
     * 启用软删除
     *
     * @var boolean
     */
    protected $softDelete = false;

    /**
     * 软删除字段
     * @var string
     */
    protected $deleteTime = 'delete_time';

    /**
     * 软删除字段默认值
     * @var mixed
     */
    protected $defaultSoftDelete;

    /**
     * 使用全局查询
     * @var boolean
     */
    public $useScope = true;

    /**
     * 全局查询范围
     * @var array
     */
    protected $globalScope = [];

    /**
     * 延迟保存信息
     * @var bool
     */
    private $lazySave = false;

    /**
     * Db对象
     * @var Db
     */
    protected static $db;

    /**
     * 容器对象的依赖注入方法
     * @var callable
     */
    protected static $invoker;

    /**
     * 服务注入
     * @var Closure[]
     */
    protected static $maker = [];

    /**
     * 方法注入
     * @var Closure[][]
     */
    protected static $macro = [];

    /**
     * 自动关联
     * @var array
     */
    protected $with = [];
    
    

    /**
     * 设置服务注入
     * @access public
     * @param Closure $maker
     * @return void
     */
    public static function maker(Closure $maker)
    {
        static::$maker[] = $maker;
    }

    /**
     * 设置方法注入
     * @access public
     * @param string $method
     * @param Closure $closure
     * @return void
     */
    public static function macro(string $method, Closure $closure)
    {
        if (!isset(static::$macro[static::class])) {
            static::$macro[static::class] = [];
        }
        static::$macro[static::class][$method] = $closure;
    }

    /**
     * 设置Db对象
     * @access public
     * @param Db $db Db对象
     * @return void
     */
    public static function setDb(Db $db)
    {
        self::$db = $db;
    }

    /**
     * 设置容器对象的依赖注入方法
     * @access public
     * @param callable $callable 依赖注入方法
     * @return void
     */
    public static function setInvoker(callable $callable): void
    {
        self::$invoker = $callable;
    }

    /**
     * 调用反射执行模型方法 支持参数绑定
     * @access public
     * @param mixed $method
     * @param array $vars 参数
     * @return mixed
     */
    public function invoke($method, array $vars = [])
    {
        if (self::$invoker) {
            $call = self::$invoker;
            return $call($method instanceof Closure ? $method : Closure::fromCallable([$this, $method]), $vars);
        }

        return call_user_func_array($method instanceof Closure ? $method : [$this, $method], $vars);
    }

    /**
     * 架构函数
     * @access public
     * @param array $data 数据
     */
    public function __construct(array $data = [])
    {
        $this->data = $data;

        if (!empty($this->data)) {
            // 废弃字段
            foreach ((array) $this->disuse as $key) {
                if (array_key_exists($key, $this->data)) {
                    unset($this->data[$key]);
                }
            }
        }

        // 记录原始数据
        $this->origin = $this->data;
        // 要解决TP关联查询alias别名问题，可自定义name
        if (empty($this->name)) {
            // 当前模型名
            $name       = str_replace('\\', '/', static::class);
            $this->name = basename($name);
        }
        
        if (empty($this->table)) {
            $this->table = $this->name;
        }

        if (!empty(static::$maker)) {
            foreach (static::$maker as $maker) {
                call_user_func($maker, $this);
            }
        }

        // 执行初始化操作
        $this->initialize();
    }

    /**
     * 获取当前模型名称
     * @access public
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * 创建新的模型实例
     * @access public
     * @param array $data       数据
     * @param mixed $where      更新条件
     * @param array $options    参数
     * @return Model
     */
    public function newInstance(array $data = [], $where = null, array $options = []): Model
    {
        $model = new static($data);

        if ($this->connection) {
            $model->setConnection($this->connection);
        }

        if ($this->suffix) {
            $model->setSuffix($this->suffix);
        }

        if (empty($data)) {
            return $model;
        }

        $model->exists(true);

        $model->setUpdateWhere($where);

        $model->trigger('AfterRead');

        return $model;
    }

    /**
     * 设置模型的更新条件
     * @access protected
     * @param mixed $where 更新条件
     * @return void
     */
    protected function setUpdateWhere($where): void
    {
        $this->updateWhere = $where;
    }

    /**
     * 设置当前模型的数据库连接
     * @access public
     * @param string $connection 数据表连接标识
     * @return $this
     */
    public function setConnection(string $connection)
    {
        $this->connection = $connection;
        return $this;
    }

    /**
     * 获取当前模型的数据库连接标识
     * @access public
     * @return string
     */
    public function getConnection(): string
    {
        return $this->connection ?: '';
    }

    /**
     * 设置当前模型数据表
     * @access public
     * @param  string $table 数据表
     * @return $this
     */
    public function setTable(string $table)
    {
        $this->table = $table;
        return $this;
    }

    /**
     * 设置当前模型数据表的后缀
     * @access public
     * @param string $suffix 数据表后缀
     * @return $this
     */
    public function setSuffix(string $suffix)
    {
        $this->suffix = $suffix;
        return $this;
    }

    /**
     * 获取当前模型的数据表后缀
     * @access public
     * @return string
     */
    public function getSuffix(): string
    {
        return $this->suffix ?: '';
    }

    /**
     * 获取当前模型的数据库查询对象
     * @access public
     * @param array $scope 设置不使用的全局查询范围
     * @return Query
     */
    public function db($scope = []): Query
    {
        /** @var Query $query */
        $query = self::$db->connect($this->connection)
            ->name($this->name . $this->suffix)
            ->pk($this->pk);

        if (!empty($this->table)) {
            $query->table($this->table . $this->suffix);
        }

        $query->model($this)
            ->json($this->json, $this->jsonAssoc)
            ->setFieldType(array_merge($this->schema, $this->jsonType));

        // 根据数据表字段自动软删除
        $fields = $query->getTableFields();
        $deleteFiled = $this->getDeleteTimeField();
        if (in_array($deleteFiled, $fields)){
            $this->softDelete = true;
        }
        if( $this->softDelete && (!property_exists($this, 'withTrashed') || !$this->withTrashed)) {
            $this->withNoTrashed($query);
        }

        // 全局作用域
        $request = request();
        if ($this->useScope) {
            // 模型全局查询
            if(is_array($scope)){
                $globalScope = array_diff($this->globalScope, $scope);
                $query->scope($globalScope);
            }
            // 请求全局查询
            $globalQuery = $request->globalQuery ?? [];
            if(!empty($globalQuery) && $request->useScope){
                foreach ($globalQuery as $name => $callback) {
                    if(is_array($scope) && in_array($name, $scope)){
                        continue;
                    }
                    if($callback instanceof Closure ){
                        call_user_func($callback, $query);
                    }
                }
            }
        }

        // 返回当前模型的数据库查询对象
        return $query;
    }

    /**
     *  初始化模型
     * @access private
     * @return void
     */
    private function initialize(): void
    {
        if (!isset(static::$initialized[static::class])) {
            static::$initialized[static::class] = true;
            static::init();
        }
    }

    /**
     * 初始化处理
     * @access protected
     * @return void
     */
    protected static function init()
    {
    }

    protected function checkData(): void
    {
    }

    protected function checkResult($result): void
    {
    }

    /**
     * 更新是否强制写入数据 而不做比较（亦可用于软删除的强制删除）
     * @access public
     * @param bool $force
     * @return $this
     */
    public function force(bool $force = true)
    {
        $this->force = $force;
        return $this;
    }

    /**
     * 判断force
     * @access public
     * @return bool
     */
    public function isForce(): bool
    {
        return $this->force;
    }

    /**
     * 新增数据是否使用Replace
     * @access public
     * @param bool $replace
     * @return $this
     */
    public function replace(bool $replace = true)
    {
        $this->replace = $replace;
        return $this;
    }

    /**
     * 刷新模型数据
     * @access public
     * @param bool $relation 是否刷新关联数据
     * @return $this
     */
    public function refresh(bool $relation = false)
    {
        if ($this->exists) {
            $this->data   = $this->db()->find($this->getKey())->getData();
            $this->origin = $this->data;
            $this->get    = [];

            if ($relation) {
                $this->relation = [];
            }
        }

        return $this;
    }

    /**
     * 设置数据是否存在
     * @access public
     * @param bool $exists
     * @return $this
     */
    public function exists(bool $exists = true)
    {
        $this->exists = $exists;
        return $this;
    }

    /**
     * 判断数据是否存在数据库
     * @access public
     * @return bool
     */
    public function isExists(): bool
    {
        return $this->exists;
    }

    /**
     * 判断模型是否为空
     * @access public
     * @return bool
     */
    public function isEmpty(): bool
    {
        return empty($this->data);
    }

    /**
     * 延迟保存当前数据对象
     * @access public
     * @param array|bool $data 数据
     * @return void
     */
    public function lazySave($data = []): void
    {
        if (false === $data) {
            $this->lazySave = false;
        } else {
            if (is_array($data)) {
                $this->setAttrs($data);
            }

            $this->lazySave = true;
        }
    }

    /**
     * 保存当前数据对象
     * @access public
     * @param array  $data     数据
     * @param string $sequence 自增序列名
     * @return bool
     */
    public function save(array $data = [], string $sequence = null): bool
    {
        // 数据对象赋值
        $this->setAttrs($data);

        if ($this->isEmpty() || false === $this->trigger('BeforeWrite')) {
            return false;
        }

        $result = $this->exists ? $this->updateData() : $this->insertData($sequence);

        if (false === $result) {
            return false;
        }

        // 写入回调
        $this->trigger('AfterWrite');

        // 重新记录原始数据
        $this->origin   = $this->data;
        $this->get      = [];
        $this->lazySave = false;

        return true;
    }

    /**
     * 检查数据是否允许写入
     * @access protected
     * @return array
     */
    protected function checkAllowFields(): array
    {
        // 检测字段
        if (empty($this->field)) {
            if (!empty($this->schema)) {
                $this->field = array_keys(array_merge($this->schema, $this->jsonType));
            } else {
                $query = $this->db();
                $table = $this->table ? $this->table . $this->suffix : $query->getTable();

                $this->field = $query->getConnection()->getTableFields($table);
            }

            return $this->field;
        }

        $field = $this->field;

        if ($this->autoWriteTimestamp) {
            array_push($field, $this->createTime, $this->updateTime);
        }

        if (!empty($this->disuse)) {
            // 废弃字段
            $field = array_diff($field, $this->disuse);
        }

        return $field;
    }

    /**
     * 保存写入数据
     * @access protected
     * @return bool
     */
    protected function updateData(): bool
    {
        // 事件回调
        if (false === $this->trigger('BeforeUpdate')) {
            return false;
        }

        $this->checkData();

        // 获取有更新的数据
        $data = $this->getChangedData();

        if (empty($data)) {
            // 关联更新
            if (!empty($this->relationWrite)) {
                $this->autoRelationUpdate();
            }

            return true;
        }

        if ($this->autoWriteTimestamp && $this->updateTime) {
            // 自动写入更新时间
            $data[$this->updateTime]       = $this->autoWriteTimestamp();
            $this->data[$this->updateTime] = $data[$this->updateTime];
        }

        // 检查允许字段
        $allowFields = $this->checkAllowFields();

        foreach ($this->relationWrite as $name => $val) {
            if (!is_array($val)) {
                continue;
            }

            foreach ($val as $key) {
                if (isset($data[$key])) {
                    unset($data[$key]);
                }
            }
        }

        // 模型更新
        $db = $this->withoutScope()->db();

        $db->transaction(function () use ($data, $allowFields, $db) {
            $this->key = null;
            $where     = $this->getWhere();

            $result = $db->where($where)
                ->strict(false)
                ->cache(true)
                ->setOption('key', $this->key)
                ->field($allowFields)
                ->update($data);

            $this->checkResult($result);

            // 关联更新
            if (!empty($this->relationWrite)) {
                $this->autoRelationUpdate();
            }
        });

        // 更新回调
        $this->trigger('AfterUpdate');

        return true;
    }

    /**
     * 新增写入数据
     * @access protected
     * @param string $sequence 自增名
     * @return bool
     */
    protected function insertData(string $sequence = null): bool
    {
        if (false === $this->trigger('BeforeInsert')) {
            return false;
        }

        $this->checkData();
        $data = $this->data;

        // 时间戳自动写入
        if ($this->autoWriteTimestamp) {
            if ($this->createTime && !isset($data[$this->createTime])) {
                $data[$this->createTime]       = $this->autoWriteTimestamp();
                $this->data[$this->createTime] = $data[$this->createTime];
            }

            if ($this->updateTime && !isset($data[$this->updateTime])) {
                $data[$this->updateTime]       = $this->autoWriteTimestamp();
                $this->data[$this->updateTime] = $data[$this->updateTime];
            }
        }

        // 检查允许字段
        $allowFields = $this->checkAllowFields();

        $db = $this->db();

        $db->transaction(function () use ($data, $sequence, $allowFields, $db) {
            $result = $db->strict(false)
                ->field($allowFields)
                ->replace($this->replace)
                ->sequence($sequence)
                ->insert($data, true);

            // 获取自动增长主键
            if ($result) {
                $pk = $this->getPk();

                if (is_string($pk) && (!isset($this->data[$pk]) || '' == $this->data[$pk])) {
                    unset($this->get[$pk]);
                    $this->data[$pk] = $result;
                }
            }

            // 关联写入
            if (!empty($this->relationWrite)) {
                $this->autoRelationInsert();
            }
        });

        // 标记数据已经存在
        $this->exists = true;
        $this->origin = $this->data;

        // 新增回调
        $this->trigger('AfterInsert');

        return true;
    }

    /**
     * 获取当前的更新条件
     * @access public
     * @return mixed
     */
    public function getWhere()
    {
        $pk = $this->getPk();

        if (is_string($pk) && isset($this->origin[$pk])) {
            $where     = [[$pk, '=', $this->origin[$pk]]];
            $this->key = $this->origin[$pk];
        } elseif (is_array($pk)) {
            foreach ($pk as $field) {
                if (isset($this->origin[$field])) {
                    $where[] = [$field, '=', $this->origin[$field]];
                }
            }
        }

        if (empty($where)) {
            $where = empty($this->updateWhere) ? null : $this->updateWhere;
        }

        return $where;
    }

    /**
     * 保存多个数据到当前数据对象
     * @access public
     * @param iterable $dataSet 数据
     * @param boolean  $replace 是否自动识别更新和写入
     * @return Collection
     * @throws \Exception
     */
    public function saveAll(iterable $dataSet, bool $replace = true): Collection
    {
        $db = $this->withoutScope()->db();

        $result = $db->transaction(function () use ($replace, $dataSet) {
            $pk = $this->getPk();

            $result = [];
            $suffix = $this->getSuffix();

            foreach ($dataSet as $key => $data) {
                if ($replace) {
                    $exists = true;
                    foreach ((array) $pk as $field) {
                        if (!isset($data[$field])) {
                            $exists = false;
                        }
                    }
                }

                if ($replace && !empty($exists)) {
                    $result[$key] = static::update($data, [], [], $suffix);
                } else {
                    $result[$key] = static::create($data, $this->field, $this->replace, $suffix);
                }
            }

            return $result;
        });

        return $this->toCollection($result);
    }

    /**
     * 删除当前的记录
     * @access public
     * @return bool
     */
    public function delete(): bool
    {
        if (!$this->isExists() || $this->isEmpty() || false === $this->trigger('BeforeDelete')) {
            return false;
        }

        $db = $this->db();
        $force = $this->isForce();
        if ($this->softDelete && !$force) {
            $name  = $this->getDeleteTimeField();
            $db->transaction(function () use ($name) {
                // 软删除数据
                $this->set($name, $this->autoWriteTimestamp());
                $this->exists()->withEvent(false)->save();
                $this->withEvent(true);
                // 关联删除
                if (!empty($this->relationWrite)) {
                    $this->autoRelationDelete();
                }
            });

            
        } else {
            // 读取更新条件
            $where = $this->getWhere();
            $db->transaction(function () use ($where, $db) {
                // 删除当前模型数据
                $db->where($where)->removeOption('soft_delete')->delete();
    
                // 关联删除
                if (!empty($this->relationWrite)) {
                    $this->autoRelationDelete();
                }
            });
        }

        // 关联删除
        if (!empty($this->relationWrite)) {
            $this->autoRelationDelete($force);
        }

        $this->trigger('AfterDelete');
        $this->exists   = false;
        $this->lazySave = false;

        return true;
    }

    /**
     * 写入数据
     * @access public
     * @param array  $data       数据数组
     * @param array  $allowField 允许字段
     * @param bool   $replace    使用Replace
     * @param string $suffix     数据表后缀
     * @return static
     */
    public static function create(array $data, array $allowField = [], bool $replace = false, string $suffix = ''): Model
    {
        $model = new static();

        if (!empty($allowField)) {
            $model->allowField($allowField);
        }

        if (!empty($suffix)) {
            $model->setSuffix($suffix);
        }

        $model->replace($replace)->save($data);

        return $model;
    }

    /**
     * 更新数据
     * @access public
     * @param array  $data       数据数组
     * @param mixed  $where      更新条件
     * @param array  $allowField 允许字段
     * @param string $suffix     数据表后缀
     * @return static
     */
    public static function update(array $data, $where = [], array $allowField = [], string $suffix = '')
    {
        $model = new static($data);
        $pk    = $model->getPk();
        if (empty($where[$pk] ?? '') && empty($data[$pk] ?? '')) {
            throw_error("$pk can not empty");
        }
        $result = $model->find(isset($where[$pk]) ? $where[$pk] : $data[$pk]);
        if (empty($result)) {
            throw_error($model->name . 'data not found');
        }

        if (!empty($allowField)) {
            $result->allowField($allowField);
        }

        if (!empty($where)) {
            $result->setUpdateWhere($where);
        }

        if (!empty($suffix)) {
            $result->setSuffix($suffix);
        }
    
        $result->exists(true)->save($data);

        return $result;
    }

    /**
     * 删除记录
     * @access public
     * @param mixed $data  主键列表 支持闭包查询条件
     * @param bool  $force 是否强制删除
     * @return bool
     */
    public static function destroy($data, bool $force = false): bool
    {
        // 传入空值（包括空字符串和空数组）的时候不会做任何的数据删除操作，但传入0则是有效的
        if (empty($data) && 0 !== $data) {
            return false;
        }
        $model = (new static());

        $query = $model->db(false);

        // 仅当强制删除时包含软删除数据
        if ($force) {
            $query->removeOption('soft_delete');
        }

        if (is_array($data) && key($data) !== 0) {
            $query->where($data);
            $data = null;
        } elseif ($data instanceof \Closure) {
            call_user_func_array($data, [&$query]);
            $data = null;
        } elseif (is_null($data)) {
            return false;
        }

        $resultSet = $query->select($data);

        foreach ($resultSet as $result) {
            /** @var Model $result */
            $result->force($force)->delete();
        }

        return true;
    }

    /**
     * 自动删除数据
     * @return boolean
     */
    public function remove()
    {
        if(!$this->softDelete){
            return $this->force()->delete();
        }
        return $this->delete();
    }

    /**
     * 恢复被软删除记录
     * @access public
     * @param array $where 更新条件
     * @return bool
     */
    public function restore($where = []): bool
    {
        if (!$this->softDelete || false === $this->trigger('BeforeRestore')) {
            return false;
        }

        if (empty($where)) {
            $pk = $this->getPk();
            if (is_string($pk)) {
                $where[] = [$pk, '=', $this->getData($pk)];
            }
        }

        // 恢复删除
        $name = $this->getDeleteTimeField();
        $this->db(false)
            ->where($where)
            ->useSoftDelete($name, $this->getWithTrashedExp())
            ->update([$name => $this->defaultSoftDelete]);

        $this->trigger('AfterRestore');

        return true;
    }

    /**
     * 获取列表
     * @param     array                         $where   查询条件
     * @param     array                         $order    排序方式
     * @param     array                         $with     关联模型
     * @return    \start\model\Collection                       
     */
    function list($where = [], $order = [], $with = null)
    {
        $order = !empty($order) ? $order : [];
        $with  = is_array($with) ? $with : $this->with;
        return $this
            ->where($where)
            ->order($order)
            ->with($with)
            ->select();
    }

    /**
     * 获取分页
     * @param     array                         $where   查询条件
     * @param     array                         $order    排序方式
     * @param     array                         $with     关联模型
     * @param     array                         $paging   分页参数
     * @return    \start\model\Collection                       
     */
    public function page($where = [], $order = [], $with = null, $paging = [])
    {
        $order = !empty($order) ? $order : [];
        $with  = is_array($with) ? $with : $this->with; 
        if (!is_array($paging)) {
            $paging = ['page' => (int) $paging];
        }
        if (!isset($paging['page'])) {
            $paging['page'] = input('page', 1, 'trim');
        }
        if (!isset($paging['per_page'])) {
            $paging['per_page'] = input('per_page', 30, 'trim');
        }
        return $this
            ->where($where)
            ->order($order)
            ->with($with)
            ->paginate($paging, false);
    }

    /**
     * 获取详情
     * @param     array                         $where   查询条件
     * @param     array                         $with     关联模型
     * @return    \start\Model                            
     */
    public function info($where, $with = null)
    {
        $with  = is_array($with) ? $with : $this->with;
        if (!is_array($where)) {
            return $this->with($with)->find($where);
        } else {
            return $this->where($where)->with($with)->find();
        }
    }

    /**
     * 关闭全局查询
     *
     * @param array $scope
     * @return \start\Model
     */
    public function withoutScope(array $scope = null)
    {
        if (is_array($scope)) {
            $this->globalScope = array_diff($this->globalScope, $scope);
        }
        if (empty($this->globalScope) || $scope == null) {
            $this->useScope = false;
        }
        return $this;
    }

    /**
     * 解序列化后处理
     */
    public function __wakeup()
    {
        $this->initialize();
    }

    /**
     * 修改器 设置数据对象的值
     * @access public
     * @param string $name  名称
     * @param mixed  $value 值
     * @return void
     */
    public function __set(string $name, $value): void
    {
        $this->setAttr($name, $value);
    }

    /**
     * 获取器 获取数据对象的值
     * @access public
     * @param string $name 名称
     * @return mixed
     */
    public function __get(string $name)
    {
        return $this->getAttr($name);
    }

    /**
     * 检测数据对象的值
     * @access public
     * @param string $name 名称
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return !is_null($this->getAttr($name));
    }

    /**
     * 销毁数据对象的值
     * @access public
     * @param string $name 名称
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->data[$name],
            $this->get[$name],
            $this->relation[$name]);
    }

    // ArrayAccess
    #[\ReturnTypeWillChange]
    public function offsetSet($name, $value)
    {
        $this->setAttr($name, $value);
    }

    #[\ReturnTypeWillChange]
    public function offsetExists($name): bool
    {
        return $this->__isset($name);
    }

    #[\ReturnTypeWillChange]
    public function offsetUnset($name)
    {
        $this->__unset($name);
    }

    #[\ReturnTypeWillChange]
    public function offsetGet($name)
    {
        return $this->getAttr($name);
    }

    /**
     * 设置不使用的全局查询范围
     * @access public
     * @param array $scope 不启用的全局查询范围
     * @return Query
     */
    public static function withoutGlobalScope(array $scope = null)
    {
        $model = new static();

        return $model->db($scope);
    }

    /**
     * 切换后缀进行查询
     * @access public
     * @param string $suffix 切换的表后缀
     * @return Model
     */
    public static function suffix(string $suffix)
    {
        $model = new static();
        $model->setSuffix($suffix);

        return $model;
    }

    /**
     * 切换数据库连接进行查询
     * @access public
     * @param string $connection 数据库连接标识
     * @return Model
     */
    public static function connect(string $connection)
    {
        $model = new static();
        $model->setConnection($connection);

        return $model;
    }

    public function __call($method, $args)
    {
        if (isset(static::$macro[static::class][$method])) {
            return call_user_func_array(static::$macro[static::class][$method]->bindTo($this, static::class), $args);
        }

        return call_user_func_array([$this->db(), $method], $args);
    }

    public static function __callStatic($method, $args)
    {
        if (isset(static::$macro[static::class][$method])) {
            return call_user_func_array(static::$macro[static::class][$method]->bindTo(null, static::class), $args);
        }

        $model = new static();

        return call_user_func_array([$model->db(), $method], $args);
    }

    /**
     * 析构方法
     * @access public
     */
    public function __destruct()
    {
        if ($this->lazySave) {
            $this->save();
        }
    }
}
