<?php
error_reporting(E_ALL);
require __DIR__ . '/AopClass.php';

$Aop = new AopClass('SplStack');
$Aop->addAdvice(AopClass::TYPE_BEFORE, AopClass::X, 'push',
	function(&$args) {
		$args[0] = 'hello';
	}
);
$Aop->addAdvice(AopClass::TYPE_AFTER, AopClass::X, 'pop',
	function(&$ret) {
		$ret = 'world';
	}
);
$stack = $Aop();
$stack->push(1);
var_dump($stack->pop());
