<?php

/**
 * 说明
 * 1. 原有类内部方法的调用与属性读写无法触发添加的advice，只能在类的client端触发
 * 2. 每个切面可以绑定多个advice，顺次触发
 * 3. 不支持静态方法的aop，不支持private方法的aop
 *
 * TODO：通过参数控制支持私有变量与方法的AOP（是否需要？）
 *
 * @authre xiaofeng
 */
class AopClass {
	const TYPE_BEFORE = 1;		// 方法或属性读写钱
	const TYPE_AFTER = 2;		// 方法或属性读写后
	const TYPE_EXCEPTION = 3;	// 方法执行触发异常

	const R = 0b100; // public属性写
	const W = 0b10;  // public属性写
	const X = 0b1;   // public方法执行

	public $refClass;	// Aop类的反射对象
	public $refObject;	// Aop类的实例
	public $events;		// Aop事件列表
	public $classProxy;	// Aop类的代理类

	public function __construct($className) {
		$this->refClass = new ReflectionClass($className);
		$this->classProxy = new ClassProxy($this);
	}

	/**
	 * 通过invoke得到传入类的代理类
	 * @param 原有类构造函数参数
	 * @return Object
	 */
	public function __invoke() {
		$this->refObject = $this->refClass->newInstanceArgs(func_get_args());
		return $this->classProxy;
	}

	/**
	 * 添加Advice
	 * @param int   	$type    Aop类型
	 * @param int   	$rwx     RWX（RW属性读写，X方法执行）
	 * @param string   	$pattern 方法或属性名称（支持通配符*）
	 * @param callable 	$advice  Advice
	 */
	public function addAdvice($type, $rwx, $pattern, callable $advice) {
		if(!$type || !$rwx || !$pattern) {
			return false;
		}
		if(!isset($this->events[$type])) {
			$this->events[$type] = [];
		}
		if(!isset($this->events[$type][$rwx])) {
			$this->events[$type][$rwx] = [];
		}
		if(!isset($this->events[$type][$rwx][$pattern])) {
			$this->events[$type][$rwx][$pattern] = [];
		}
		$this->events[$type][$rwx][$pattern][] = $advice;
	}

	/**
	 * 获取Advice列表
	 * @param  int 		$type Aop类型
	 * @param  int 		$rwx  RWX（RW属性读写，X方法执行）
	 * @param  string 	$name 具体方法或属性名称
	 * @return array
	 */
	public function getAdvices($type, $rwx, $name) {
		if(!$type || !$rwx || !$name) {
			return [];
		}
		if(!isset($this->events[$type])) {
			return [];
		}
		if(!isset($this->events[$type][$rwx])) {
			return [];
		}
		$ret = [];
		$patterns = $this->events[$type][$rwx];
		foreach($patterns as $pattern => $advices) {
			// fixme 不用preg_match用通配符的方式
			if(preg_match($pattern, $name)) {
				$ret = array_merge($ret, $advices);
			}
		}
		return $ret;
	}

}

/**
 * 代理类
 * 避免冲突 不定义属性与正常方法（$_属性与代理方法（魔术方法）例外）
 * 理论上可以代理所有魔术方法
 */
class ClassProxy {
	private $_;

	public function __construct(AopClass $aopClass) {
		if(!$aopClass->refClass->hasProperty('_')) {
			trigger_error('$_ property has been used');
		}
		$this->_ = $aopClass;
	}

	/**
	 * 代理具体类的方法调用
	 * @param  string 	$name
	 * @param  array 	$args
	 * @return mixed
	 */
	public function __call($name, $args) {
		// 不负责检测方法调用是否正确
		// 默认调用方只调用原对象方法
		$aop = $this->_;

		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::X, $name);
		// 参数通用引用传递，请求前可以修改参数
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$args) {
			$advice($args);
		});

		// private 问题
		$ret = null;
		try  {
			$ret = call_user_func_array([$aop->refObject, $name], $args);
		} catch(Exception $e) {
			$advices = $aop->getAdvices(AopClass::TYPE_EXCEPTION, AopClass::X, $name);
			// 参数通用引用传递，发生异常时可以修改返回值
			// advice方法签名参数必须为引用
			array_walk($advices, function($advice) use($e, &$ret) {
				$advice($e, $ret);
			});
		}

		$advices = $aop->getAdvices(AopClass::TYPE_AFTER, AopClass::X, $name);
		// 参数通用引用传递，请求后可以修改返回值
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$ret) {
			$advice($ret);
		});

		return $ret;
	}

	/**
	 * 代理属性写
	 * @param string 	$name
	 * @param mixed 	$value
	 */
	public function __set($name, $value) {
		$aop = $this->_;

		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::W, $name);
		// 参数通用引用传递，赋值前可修改值
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$value) {
			// FIXME class line file info
			$advice($value);
		});

		// private 问题
		$aop->refObject->$name = $value;

		$advices = $aop->getAdvices(AopClass::TYPE_AFTER, AopClass::W, $name);
		array_walk($advices, function($advice) use($value) {
			// FIXME class line file info
			$advice($value);
		});

	}

	/**
	 * 代理属性写
	 * @param  string $name
	 * @return mixed
	 */
	public function __get($name) {
		$aop = $this->_;

		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::R, $name);
		array_walk($advices, function($advice) {
			$advice();
		});

		// private 问题
		$ret = $aop->refObject->$name;

		$advices = $aop->getAdvices(AopClass::TYPE_AFTER, AopClass::R, $name);
		// 参数通用引用传递，读取之后可修改返回值
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$ret) {
			$advice($ret);
		});

		return $ret;
	}
}
