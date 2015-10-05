<?php

/**
 * 说明
 * 1. 原有类内部方法的调用与属性读写无法触发添加的advice，只能在类的client端触发
 * 2. 每个切面可以绑定多个advice，顺次触发
 * 3. 不支持静态方法的aop，不支持private方法的aop
 *
 * 2015-10-04 preg_match修改为fnmatch，移除ProxyClass除_之外所有属性
 * 2015-10-05 通过SplObjectStorage移除ProxyClass_属性，移除ProxyClass构造函数
 *
 * TODO：通过参数控制支持私有变量与方法的AOP（是否需要？）
 * 通配符情况下，Advice的参数有问题(参数类型与数量不匹配)
 * 新建一个AopPoint类，统一Advice传入的参数
 *
 * @authre xiaofeng
 */
class AopClass {
	const TYPE_BEFORE 	= 1;		// 方法或属性读写钱
	const TYPE_AFTER 	= 2;		// 方法或属性读写后
	const TYPE_AROUND 	= 3;		// 替换原方法
	const TYPE_EXCEPTION= 4;		// 方法执行触发异常

	const R = 0b100; // public属性写
	const W = 0b10;  // public属性写
	const X = 0b1;   // public方法执行

	public $className;	// 原对象名或实例
	public $refClass;	// Aop类的反射对象
	public $refObject;	// Aop类的实例
	public $events;		// Aop事件列表
	public $classProxy;	// Aop类的代理类

	// 全局代理类对象容器
	// [ProxyClass => AopClass]
	private static $proxyObjects;
	public static function getAop(ClassProxy $proxy) {
		if(self::$proxyObjects === null) {
			throw new LogicException("proxyObjects is null");
		}
		if(!isset(self::$proxyObjects[$proxy])) {
			throw new LogicException("proxyObjects do not contains proxy");
		}
		return self::$proxyObjects[$proxy];
	}
	public static function setAop(ClassProxy $proxy, AopClass $aop) {
		if(self::$proxyObjects === null) {
			self::$proxyObjects = new SplObjectStorage();
		}
		if(isset(self::$proxyObjects[$proxy])) {
			throw new LogicException("proxy has been added to proxyObjects");
		}
		self::$proxyObjects[$proxy] = $aop;
	}

	/**
	 * @param string|object $className 类名or对象
	 * @throws ReflectionException
	 */
	public function __construct($className) {
		$this->calssName = $className;
		$this->refClass = new ReflectionClass($className);
		$this->classProxy = new ClassProxy;
	}

	/**
	 * 通过invoke得到传入类的代理类
	 * 如果通过已经实例化的类构造的代理，则返回clone的对象的代理对象
	 * 否则，实例化新对象的代理类
	 * @param 原有类构造函数参数
	 * @return Object
	 */
	public function __invoke() {
		if(is_object($this->className)) {
			// clone对象的spl_object_hash与原对象的spl_object_hash不同
			$this->refObject = clone $className;
		} else {
			$this->refObject = $this->refClass->newInstanceArgs(func_get_args());
		}
		self::setAop($this->classProxy, $this);
		return $this->classProxy;
	}

	/**
	 * 方法名与属性名匹配
	 * @param  string $pattern 模式
	 * @param  string $name    属性名与方法名
	 * @return bool
	 */
	protected function match($pattern, $name) {
		// return preg_match($pattern, $name);
		// 正则替换成通配符的方式
		return fnmatch($pattern, $name);
	}

	/**
	 * 添加Advice
	 * @param int   	$type    Aop类型
	 * @param int   	$rwx     RWX（RW属性读写，X方法执行）
	 * @param string   	$pattern 方法或属性名称（支持fnmatch）
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
			if($this->match($pattern, $name)) {
				$ret = array_merge($ret, $advices);
			}
		}
		return $ret;
	}

	/**
	 * 包装原方法,用于around
	 * @param  string $name 方法名
	 * @param  array  $args 方法参数数组
	 * @return closure
	 */
	public function methodWrapper($name, array $args) {
		if($this->refObject === null) {
			throw new BadMethodCallException("must be invoke to get obj");
		}
		return function() use($args, $name){
			return call_user_func_array([$this->refObject, $name], $args);
		};
	}

	/**
	 * 正常流程与around流程异常处理
	 * @param  Exception $e
	 * @param  string    $name 方法名
	 * @param  &mixed    &$ret 返回值
	 * @throws Exception
	 */
	public function exceptionHandler(Exception $e, $name, &$ret) {
		$advices = $this->getAdvices(AopClass::TYPE_EXCEPTION, AopClass::X, $name);
		if($advices) {
			// 参数通用引用传递，发生异常时可以修改返回值
			// advice方法签名参数必须为引用
			array_walk($advices, function($advice) use($e, &$ret) {
				$advice($e, $ret);
			});
		} else {
			// 异常类型信息丢失
			throw $e;
		}
	}

}

/**
 * 代理类
 * [避免冲突]禁止定义属性与正常方法
 * 理论上可以代理所有魔术方法
 * 细节实现放在AopClass中
 */
final class ClassProxy {

	/**
	 * 代理具体类的方法调用
	 * @param  string 	$name
	 * @param  array 	$args
	 * @return mixed
	 */
	public function __call($name, $args) {
		// 不负责检测方法调用是否正确
		// 默认调用方只调用原对象方法
		$aop = AopClass::getAop($this);

		// BEFORE
		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::X, $name);
		// 参数通用引用传递，请求前可以修改参数
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$args) {
			$advice($args);
		});

		// call 返回值
		$ret = null;

		$advices = $aop->getAdvices(AopClass::TYPE_AROUND, AopClass::X, $name);
		if($advices) {
			// AROUND
			try {
				// advice 接受三个参数
				// 1.原方法传入参数，可修改, 2.旧方法closure, 3.around返回值carry
				array_walk($advices, function($advice) use(&$args, &$ret, $aop, $name) {
					$ret = $advice($args, $aop->methodWrapper($name, $args), $ret);
				});
			} catch(Exception $e) {
				$aop->exceptionHandler($e, $name, $ret);
			}
		} else {
			// EXCEPTION
			try  {
				$ret = call_user_func_array([$aop->refObject, $name], $args);
			} catch(Exception $e) {
				$aop->exceptionHandler($e, $name, $ret);
			}
		}

		// AFTER
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
		$aop = AopClass::getAop($this);

		// BEFORE
		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::W, $name);
		// 参数通用引用传递，赋值前可修改值
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$value) {
			// FIXME class line file info
			$advice($value);
		});

		// SET
		$aop->refObject->$name = $value;

		// AFTER
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
		$aop = AopClass::getAop($this);

		// BEFORE
		$advices = $aop->getAdvices(AopClass::TYPE_BEFORE, AopClass::R, $name);
		array_walk($advices, function($advice) {
			$advice();
		});

		// GET
		$ret = $aop->refObject->$name;

		// AFTER
		$advices = $aop->getAdvices(AopClass::TYPE_AFTER, AopClass::R, $name);
		// 参数通用引用传递，读取之后可修改返回值
		// advice方法签名参数必须为引用
		array_walk($advices, function($advice) use(&$ret) {
			$advice($ret);
		});

		return $ret;
	}
}
