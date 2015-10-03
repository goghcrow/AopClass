<?php
error_reporting(E_ALL);
require __DIR__ . '/AopClass.php';

class A {
	public $property;

	public function __construct($x) {
		$this->x = $x;
	}

	public function func($arg) {
		echo 'called ' . $this->x . PHP_EOL;
		// $this->property = "world";
		return $arg;
	}

	public function throw() {
		throw new Exception("Error Processing Request", 1);
	}
}

$Aop = new AopClass('A');
// 请求前修改参数，参数必须写引用
$Aop->addAdvice(AopClass::TYPE_BEFORE, AopClass::X, '/func/', function(&$args) {
	$args[0] = 'im-return';
	echo "before call\n";
});
// 请求后修改返回值，参数必须写引用
$Aop->addAdvice(AopClass::TYPE_AFTER, AopClass::X, '/func/', function(&$ret) {
	$ret .= "_1";
	echo "after-call-1\n";
});
$Aop->addAdvice(AopClass::TYPE_AFTER, AopClass::X, '/func/', function(&$ret) {
	$ret .= "_2";
	echo "after-call-2\n";
});
// 执行异常时触发
$Aop->addAdvice(AopClass::TYPE_EXCEPTION, AopClass::X, '/throw/', function($e, &$ret) {
	echo 'exception: ' . $e->getMessage();
});
// 赋值前可修改，参数必须写引用
$Aop->addAdvice(AopClass::TYPE_BEFORE, AopClass::W, '/property/', function(&$val) {
	$val = 'BEFORE_' . $val;
	echo "set property $val\n";
});
$Aop->addAdvice(AopClass::TYPE_AFTER, AopClass::W, '/property/', function() {
	echo "set property finished\n";
});
$Aop->addAdvice(AopClass::TYPE_BEFORE, AopClass::R, '/property/', function() {
	echo "get property\n";
});
// 读取之后课修改返回值，参数必须写引用
$Aop->addAdvice(AopClass::TYPE_AFTER, AopClass::R, '/property/', function(&$val) {
	$val = 'Modified_' . $val;
	echo "get property finished\n";
});


$A = $Aop("hello");
echo $A->func('ret') . "\n";
$A->property = "world";
echo $A->property . "\n";
$A->throw();

// out
// before call
// called hello
// after-call-1
// after-call-2
// im-return_1_2
// set property BEFORE_world
// set property finished
// get property
// get property finished
// Modified_BEFORE_world
// exception: Error Processing Request
